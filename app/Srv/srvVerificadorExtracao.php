<?php

namespace App\Srv;

use App\Repositories\ExtractionQueueRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * srvVerificadorExtracao
 * ──────────────────────
 * Lógica do verificador das 23:30 (bin/extract_check.php). SOMENTE LEITURA no
 * banco; a única escrita é o arquivo de estado (contagem de itens da PI),
 * e só quando não é --dry-run e não há queda brusca.
 *
 * Alertas:
 *   - jornada sem dados da PI corrente, ou com MIN(data_carga) mais velho que
 *     checagem_horas_max (padrão 25 h);
 *   - o job noturno não enfileirou ordem hoje;
 *   - a última ordem do job está em erro / terminou com falhas / não terminou;
 *   - queda brusca: itens da PI < checagem_queda_pct do último valor
 *     registrado no arquivo de estado (apague o arquivo para "zerar").
 *   - ordem 'processando' (qualquer origem) sem atualização há mais de
 *     checagem_processando_min (padrão 30): possível travamento no banco/disco;
 *   - ordem 'pendente' do job vencida há mais de checagem_pendente_venc_min
 *     (padrão 15) além do agendado_para: nenhum worker a pegou;
 *   - disco: livre < disco.min_livre_mb OU livre% < disco.min_livre_pct em
 *     algum de disco.caminhos (caminho indisponível vira aviso, não alerta).
 *
 * Interação do alerta de 'pendente': a ordem do job nasce com agendado_para =
 * criado_em + 60 min. Às 23:30 (job 23:00) ela ainda está ~30 min no futuro,
 * logo "vencida" NÃO dispara; quem acusa é o alerta antigo ("ainda está
 * 'pendente'"). A partir de 00:15 os dois disparam para a mesma ordem
 * (mensagens diferentes, sem deduplicação). O alerta novo cobre verificações
 * tardias/manuais e ordens de dias anteriores esquecidas. Idades usam NOW()
 * do banco, nunca o relógio do PHP.
 *
 * Granularidade de data_carga: por JORNADA (todos os prefixos de chave dos
 * times ativos da jornada), porque a carga é feita por jornada.
 */
class srvVerificadorExtracao
{
    private PDO $pdo;
    private array $cfg;
    private string $estado;
    private ExtractionQueueRepository $queue;
    /** @var callable|null */
    private $discoProvider;

    public function __construct(PDO $pdo, array $cfg, string $arquivoEstado, ?callable $discoProvider = null)
    {
        $this->discoProvider = $discoProvider;
        $this->pdo    = $pdo;
        $this->cfg    = $cfg;
        $this->estado = $arquivoEstado;
        $this->queue  = new ExtractionQueueRepository($pdo);
    }

    /**
     * @return array{alertas:string[],ok:bool,pi:string,itens:int,jornadas:array,estado_gravado:bool,disco:array,avisos:string[]}
     * @throws RuntimeException code 2 se não há PI resolvível
     */
    public function verificar(DateTimeImmutable $agora, bool $dry, ?string $forcarPi = null): array
    {
        $alertas = [];
        $hoje    = $agora->format('Y-m-d');
        $horasMax = (float)($this->cfg['checagem_horas_max'] ?? 25);
        $quedaPct = (float)($this->cfg['checagem_queda_pct'] ?? 0.8);

        $res = (new srvResolvedorPi($this->pdo, (int)($this->cfg['periodo_graca_dias'] ?? 14)))->resolver($hoje, $forcarPi);
        $pi  = $res['corrente'];

        $st = $this->pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :pi LIMIT 1');
        $st->execute([':pi' => $pi]);
        $piId = (int)$st->fetchColumn();

        // ── Por jornada: MIN/MAX(data_carga) ─────────────────────────────
        $porJornada = [];
        foreach ($this->queue->timesAtivosComProjeto() as $t) {
            $k = strtoupper(trim((string)$t['projeto_key']));
            if ($k !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) $porJornada[(string)$t['jornada']][$k] = true;
        }
        $jornadas = [];
        foreach ($porJornada as $jornada => $keys) {
            $likes = [];
            $params = [$piId];
            foreach (array_keys($keys) as $k) {
                $likes[]  = "chave LIKE ? ESCAPE '\\\\'";
                $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $k) . '-%';
            }
            $q = $this->pdo->prepare(
                'SELECT COUNT(*) AS n, MIN(data_carga) AS minimo, MAX(data_carga) AS maximo
                   FROM extracao_jira WHERE configuracao_pi_id = ? AND (' . implode(' OR ', $likes) . ')'
            );
            $q->execute($params);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $n = (int)$r['n'];
            $info = ['jornada' => $jornada, 'itens' => $n, 'min' => $r['minimo'], 'max' => $r['maximo'], 'horas' => null];
            if ($n === 0 || $r['minimo'] === null) {
                $alertas[] = "Jornada {$jornada}: sem itens da {$pi} em extracao_jira.";
            } else {
                $horas = ($agora->getTimestamp() - (int)strtotime((string)$r['minimo'])) / 3600;
                $info['horas'] = round($horas, 1);
                if ($horas > $horasMax) {
                    $alertas[] = "Jornada {$jornada}: dados com {$info['horas']} h de defasagem (limite {$horasMax} h; MIN data_carga {$r['minimo']}).";
                }
            }
            $jornadas[] = $info;
        }

        // ── Última ordem do job noturno hoje ─────────────────────────────
        $ordem = $this->queue->ultimaOrdemDiaria($hoje);
        if ($ordem === null) {
            $alertas[] = "O job noturno (extract_diaria) não enfileirou nenhuma ordem hoje ({$hoje}).";
        } else {
            if ($ordem['status'] === 'erro') {
                $alertas[] = "A ordem #{$ordem['id']} do job noturno terminou em ERRO.";
            } elseif ($ordem['status'] !== 'concluido') {
                $alertas[] = "A ordem #{$ordem['id']} do job noturno ainda está '{$ordem['status']}'.";
            } elseif (strpos((string)($ordem['mensagem'] ?? ''), 'FALHAS:') !== false) {
                $alertas[] = "A ordem #{$ordem['id']} do job noturno terminou com jornadas em falha.";
            }
        }

        // ── Ordens travadas (processando parada / pendente vencida) ──────
        $limProc = (int)($this->cfg['checagem_processando_min'] ?? 30);
        foreach ($this->queue->processandoParadas($limProc) as $o) {
            $alertas[] = "Ordem #{$o['id']} processando há {$o['minutos']} min (possível travamento no banco ou no disco); atualizado_em " . ($o['atualizado_em'] ?? '-') . "; limite {$limProc} min.";
        }
        $limPend = (int)($this->cfg['checagem_pendente_venc_min'] ?? 15);
        foreach ($this->queue->pendentesJobVencidas($limPend) as $o) {
            $alertas[] = "Ordem #{$o['id']} do job noturno pendente e vencida há {$o['minutos']} min (agendado_para {$o['agendado_para']}); nenhum worker a pegou; limite {$limPend} min.";
        }

        // ── Disco ────────────────────────────────────────────────────────
        $disco = new srvDisco((array)($this->cfg['disco'] ?? []), $this->discoProvider);
        foreach ($disco->abaixoDeQualquerLimite() as $m) {
            $alertas[] = 'Disco: ' . $disco->descrever($m) . ' abaixo do limite (mín. ' . $disco->minLivreMb() . ' MB ou ' . $disco->minLivrePct() . '%).';
        }

        // ── Queda brusca de itens da PI ──────────────────────────────────
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM extracao_jira WHERE configuracao_pi_id = :id');
        $q->execute([':id' => $piId]);
        $itens = (int)$q->fetchColumn();

        $anterior = $this->lerEstado();
        $queda = false;
        if ($anterior !== null && ($anterior['pi'] ?? null) === $pi && (int)($anterior['itens'] ?? 0) > 0
            && $itens < $quedaPct * (int)$anterior['itens']) {
            $queda = true;
            $alertas[] = "Queda brusca: {$itens} itens da {$pi} contra {$anterior['itens']} no último registro ({$anterior['data']}); limite " . (int)round($quedaPct * 100) . '%.';
        }

        // ── Estado (não grava em dry-run nem sob queda brusca) ───────────
        $gravado = false;
        if (!$dry && !$queda) {
            $gravado = $this->gravarEstado(['pi' => $pi, 'itens' => $itens, 'data' => $agora->format('Y-m-d H:i:s')]);
        }

        return [
            'alertas' => $alertas, 'ok' => $alertas === [], 'pi' => $pi, 'itens' => $itens, 'jornadas' => $jornadas,
            'estado_gravado' => $gravado, 'disco' => $disco->medir(), 'avisos' => $disco->avisos(),
        ];
    }

    private function lerEstado(): ?array
    {
        if (!is_file($this->estado)) return null;
        $j = json_decode((string)@file_get_contents($this->estado), true);
        return is_array($j) ? $j : null;
    }

    private function gravarEstado(array $dados): bool
    {
        $dir = dirname($this->estado);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $tmp = $this->estado . '.tmp';
        if (@file_put_contents($tmp, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return false;
        if (!@rename($tmp, $this->estado)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
