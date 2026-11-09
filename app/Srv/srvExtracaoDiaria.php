<?php

namespace App\Srv;

use App\Repositories\ExtractionQueueRepository;
use App\Services\ExtractionService;
use App\Services\Logger;
use App\Services\Notifier;
use PDO;
use RuntimeException;
use Throwable;

/**
 * srvExtracaoDiaria
 * ─────────────────
 * Orquestra o job noturno (bin/extract_diaria.php, 23:00):
 *   1. trava de concorrência (flock não bloqueante): já rodando => saída 3;
 *   2. resolve a(s) PI(s) (srvResolvedorPi) e os lotes (uma jornada ativa com
 *      os projeto_key dos times ativos);
 *   3. enfileira UMA ordem com hash do dia em extracao_agendamento (a tela
 *      /extracao/fila mostra o resultado da noite) e a processa neste mesmo
 *      processo via srvProcessadorFila (claim atômico, extracao_log);
 *   4. grava a linha-resumo no log e notifica falhas (log + mail()).
 *
 * CÓDIGOS DE SAÍDA:
 *   0 ok (inclui "já executada hoje")        3 já em execução (sem erro)
 *   1 falha parcial (alguma jornada/PI)      4 erro fatal / Jira indisponível
 *   2 configuração/PI inválida                  (nenhuma jornada gravada)
 *
 * Política do hash do dia: ordem 'pendente' do dia é reaproveitada;
 * 'processando' => 3; 'concluido' => "já executada hoje" (0), salvo --refazer.
 */
class srvExtracaoDiaria
{
    public const EXIT_OK = 0;
    public const EXIT_PARCIAL = 1;
    public const EXIT_CONFIG = 2;
    public const EXIT_EM_EXECUCAO = 3;
    public const EXIT_FATAL = 4;

    /** Minutos que a ordem fica "agendada no futuro" para o worker de 1 min não pegá-la antes do claim local. */
    private const ATRASO_ORDEM_MIN = 60;

    private PDO $pdo;
    private array $cfg;
    private string $baseDir;
    private ExtractionService $service;
    private ExtractionQueueRepository $queue;
    private Notifier $filaNotifier;
    private srvNotificadorEmail $falhaNotifier;
    /** @var callable */
    private $out;

    public function __construct(
        PDO $pdo,
        array $cfg,
        string $baseDir,
        ExtractionService $service,
        Notifier $filaNotifier,
        srvNotificadorEmail $falhaNotifier,
        ?callable $out = null
    ) {
        $this->pdo           = $pdo;
        $this->cfg           = $cfg;
        $this->baseDir       = rtrim($baseDir, '/\\');
        $this->service       = $service;
        $this->queue         = new ExtractionQueueRepository($pdo);
        $this->filaNotifier  = $filaNotifier;
        $this->falhaNotifier = $falhaNotifier;
        $this->out           = $out ?? function (string $m): void {
            echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
        };
    }

    /**
     * @param array $opts dry_run, pi, forcar, sem_fallback, refazer, fonte ('jira'|'fixture'), hoje (Y-m-d)
     * @return int código de saída
     */
    public function executar(array $opts): int
    {
        $dry  = !empty($opts['dry_run']);
        $lock = null;

        if (!$dry) {
            $lock = new srvLock($this->lockDir() . '/extract_diaria.lock');
            if (!$lock->tentar()) {
                $this->say('Já existe uma execução do job noturno em andamento (lock). Saindo sem erro.');
                Logger::warning('extracao', 'extract_diaria: já em execução (lock), saindo com código 3');
                return self::EXIT_EM_EXECUCAO;
            }
        }

        $codigo = self::EXIT_FATAL;
        $pi = '?';
        try {
            $codigo = $this->rodar($opts, $dry, $pi);
        } catch (Throwable $e) {
            $this->say('ERRO FATAL: ' . Logger::sanitize($e->getMessage()));
            Logger::exception('extracao', 'extract_diaria: erro fatal', $e);
            $codigo = self::EXIT_FATAL;
            if (!$dry) {
                $this->falhaNotifier->notificarFalha($pi, [
                    'codigo_saida' => $codigo, 'erros' => ['Erro fatal: ' . $e->getMessage()], 'log' => 'data/logs/extracao.log',
                ]);
            }
        }

        if ($lock !== null) $lock->liberar();
        return $codigo;
    }

    private function rodar(array $opts, bool $dry, string &$piRotulo): int
    {
        $t0     = microtime(true);
        $hoje   = (string)($opts['hoje'] ?? date('Y-m-d'));
        $forcar = !empty($opts['forcar']);
        $fb     = empty($opts['sem_fallback']);
        $fonte  = (string)($opts['fonte'] ?? 'jira');

        // ── PI(s) ───────────────────────────────────────────────────────
        try {
            $resolvedor = new srvResolvedorPi($this->pdo, (int)($this->cfg['periodo_graca_dias'] ?? 14));
            $res = $resolvedor->resolver($hoje, isset($opts['pi']) ? (string)$opts['pi'] : null);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 2) throw $e;
            $this->say('CONFIGURAÇÃO/PI INVÁLIDA: ' . $e->getMessage());
            Logger::error('extracao', 'extract_diaria: configuração/PI inválida', ['erro' => $e->getMessage(), 'hoje' => $hoje]);
            if (!$dry) {
                $this->falhaNotifier->notificarFalha('?', [
                    'codigo_saida' => self::EXIT_CONFIG, 'erros' => [$e->getMessage()], 'log' => 'data/logs/extracao.log',
                ]);
            }
            return self::EXIT_CONFIG;
        }
        $piRotulo = $res['corrente'];

        // ── Lotes: uma jornada ativa com os projeto_key dos times ativos ──
        $lotes = $this->montarLotes();
        if ($lotes === []) {
            $msg = 'Nenhum time ativo com projeto_key em jornada ativa.';
            $this->say('CONFIGURAÇÃO INVÁLIDA: ' . $msg);
            Logger::error('extracao', 'extract_diaria: ' . $msg);
            if (!$dry) {
                $this->falhaNotifier->notificarFalha($piRotulo, [
                    'codigo_saida' => self::EXIT_CONFIG, 'erros' => [$msg], 'log' => 'data/logs/extracao.log',
                ]);
            }
            return self::EXIT_CONFIG;
        }

        $pisOrdem = [];
        foreach ($res['pis'] as $p) $pisOrdem[] = ['pi' => $p, 'sprints' => []];

        $this->say('PI corrente: ' . $res['corrente'] . ' | PIs a extrair: ' . implode(', ', $res['pis']) . ' | ' . $res['motivo']);
        $this->say('Lotes (jornadas): ' . count($lotes) . ' | fallback por sprint: ' . ($fb ? 'ligado' : 'DESLIGADO') . ' | forcar: ' . ($forcar ? 'sim' : 'não') . ' | fonte: ' . $fonte);

        // ── Hash do dia e ordem existente ───────────────────────────────
        $criterios = ['jornadas' => array_column($lotes, 'jornada'), 'pis' => $res['pis'], 'sprints' => [], 'times' => []];
        $hash = sha1((string)json_encode(
            ['origem' => 'extract_diaria', 'criterios' => $criterios, 'lotes' => $lotes, 'data' => $hoje, 'fallback' => $fb, 'forcar' => $forcar],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $existente = $this->queue->buscarPorHashHoje($hash);

        if ($dry) {
            $this->say('--dry-run: nada será gravado e o Jira não será consultado.');
            $this->say('Ordem do dia: ' . ($existente === null ? 'nenhuma (seria criada)' : "#{$existente['id']} ({$existente['status']})"));
            foreach ($lotes as $l) {
                $this->say("  Jornada {$l['jornada']}: " . implode(',', $l['projetos']));
                foreach ($res['pis'] as $p) {
                    $this->say("    JQL principal [{$p}]: " . $this->service->buildJql($l['projetos'], $p, null));
                    if ($fb) {
                        $sp = $this->service->sprintsDaPi($p);
                        $this->say($sp === []
                            ? "    JQL fallback  [{$p}]: (PI sem sprints cadastradas: fallback seria ignorado)"
                            : "    JQL fallback  [{$p}]: " . $this->service->buildFallbackJql($l['projetos'], $p, $sp));
                    }
                }
            }
            return self::EXIT_OK;
        }

        if ($existente !== null && $existente['status'] === 'processando') {
            $this->say("Ordem #{$existente['id']} do dia já está em 'processando'. Saindo sem erro.");
            return self::EXIT_EM_EXECUCAO;
        }
        if ($existente !== null && $existente['status'] === 'concluido' && empty($opts['refazer'])) {
            $this->say("Extração do dia já executada (ordem #{$existente['id']} concluída). Use --refazer para executar de novo.");
            Logger::info('extracao', 'extract_diaria: já executada hoje', ['ordem' => $existente['id']]);
            return self::EXIT_OK;
        }

        if ($existente !== null && $existente['status'] === 'pendente') {
            $ordemId = (int)$existente['id'];
            $this->say("Reaproveitando a ordem pendente #{$ordemId}.");
        } else {
            $ordem = [
                'origem'    => 'extract_diaria',
                'criterios' => $criterios,
                'lotes'     => $lotes,
                'pis'       => $pisOrdem,
                'email'     => '',
                'opcoes'    => ['forcar' => $forcar, 'fallback' => $fb],
            ];
            $json    = (string)json_encode($ordem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ordemId = $this->queue->criar($json, $hash, null, self::ATRASO_ORDEM_MIN);
            $this->say("Ordem #{$ordemId} criada na fila (job noturno).");
        }

        // ── Processa neste mesmo processo ────────────────────────────────
        $processador = new srvProcessadorFila($this->queue, $this->service, $this->filaNotifier, $this->cfg, $this->out, $this->baseDir);
        $r = $processador->processar([$ordemId], $fonte);

        if ($r['ordens'] === []) {
            $this->say("Ordem #{$ordemId}: claim perdido para outro processo. Saindo sem erro.");
            return self::EXIT_EM_EXECUCAO;
        }
        $o = $r['ordens'][0];

        $ok = 0;
        $erro = 0;
        $lidos = 0;
        foreach ($o['lotes'] as $l) {
            if ($l['ok']) {
                $ok++;
                $lidos += (int)($l['detalhe']['itens_lidos'] ?? 0);
            } else {
                $erro++;
            }
        }
        if ($o['ok_lotes'] === 0) {
            $codigo = self::EXIT_FATAL;
        } elseif ($o['falhas'] !== []) {
            $codigo = self::EXIT_PARCIAL;
        } else {
            $codigo = self::EXIT_OK;
        }

        $dur = round(microtime(true) - $t0, 1);
        $linha = 'RESUMO PI=' . $res['corrente'] . ' pis=' . count($res['pis']) . " ordem=#{$ordemId}"
            . " jornadas_ok={$ok} jornadas_erro={$erro} itens_lidos={$lidos} itens_inseridos={$o['itens']}"
            . " apagados={$o['apagados']} itens_fallback={$o['fallback']} duracao={$dur}s exit={$codigo}";
        $this->say($linha);
        if ($codigo === self::EXIT_OK) {
            Logger::info('extracao', $linha);
        } else {
            Logger::error('extracao', $linha);
            $this->falhaNotifier->notificarFalha($res['corrente'], [
                'codigo_saida' => $codigo, 'jornadas_ok' => $ok, 'jornadas_erro' => $erro,
                'erros' => $o['falhas'], 'log' => 'data/logs/extracao.log',
            ]);
        }

        return $codigo;
    }

    /** @return array<int,array{jornada:string,projetos:string[]}> */
    private function montarLotes(): array
    {
        $por = [];
        foreach ($this->queue->timesAtivosComProjeto() as $t) {
            $k = strtoupper(trim((string)$t['projeto_key']));
            if ($k === '') continue;
            $por[(string)$t['jornada']][$k] = true;
        }
        $lotes = [];
        foreach ($por as $jornada => $keys) {
            $lotes[] = ['jornada' => (string)$jornada, 'projetos' => array_keys($keys)];
        }
        return $lotes;
    }

    private function lockDir(): string
    {
        $dir = (string)($this->cfg['lock_dir'] ?? 'data/locks');
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
            return rtrim($dir, '/\\');
        }
        return $this->baseDir . '/' . trim($dir, '/\\');
    }

    private function say(string $msg): void
    {
        call_user_func($this->out, $msg);
    }
}
