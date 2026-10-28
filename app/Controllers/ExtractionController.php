<?php

namespace App\Controllers;

use App\Repositories\CatalogRepository;
use App\Repositories\ExtractionQueueRepository;
use App\Services\Logger;
use Throwable;

/**
 * ExtractionController
 * ────────────────────
 * Endpoints JSON da fila de extração (v1.5, ETAPA 6b-1). Sem tela.
 *
 *   GET  /extracao/catalogo  → dados da cascata (jornadas, PIs, sprints, times)
 *   POST /extracao/agendar   → registra uma ordem (dispara em ~delay_minutes)
 *   GET  /extracao/fila      → últimas ordens com status e resumo do log
 *
 * A ordem NÃO executa na hora: o worker (bin/queue_worker.php) a processa.
 */
class ExtractionController
{
    private CatalogRepository $catalog;
    private ExtractionQueueRepository $queue;
    private array $cfg;
    private string $baseDir;

    public function __construct(CatalogRepository $catalog, ExtractionQueueRepository $queue, array $cfg, string $baseDir)
    {
        $this->catalog = $catalog;
        $this->queue   = $queue;
        $this->cfg     = $cfg;
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    // ── GET /extracao/catalogo ───────────────────────────────────────────
    public function catalogo(): void
    {
        if (!$this->exigeMetodo('GET')) return;
        try {
            $this->respond(200, $this->montarCatalogo());
        } catch (Throwable $e) {
            Logger::exception('extracao', 'GET /extracao/catalogo: exceção', $e);
            $this->respond(500, ['erro' => 'Falha ao ler o catálogo.']);
        }
    }

    // ── POST /extracao/agendar ───────────────────────────────────────────
    public function agendar(): void
    {
        if (!$this->exigeMetodo('POST')) return;

        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body) || $body === []) {
            $this->respond(400, ['erro' => 'Corpo JSON ausente ou inválido.']);
            return;
        }

        foreach (['jornadas', 'pis', 'sprints', 'times'] as $campo) {
            if (isset($body[$campo]) && !$this->listaDeStrings($body[$campo])) {
                $this->respond(400, ['erro' => "Campo '{$campo}' deve ser uma lista de textos."]);
                return;
            }
        }
        $jornadas = $this->normalizar($body['jornadas'] ?? []);
        $pis      = $this->normalizar($body['pis'] ?? []);
        $sprints  = $this->normalizar($body['sprints'] ?? []);
        $times    = $this->normalizar($body['times'] ?? []);
        $confirmar = ($body['confirmar'] ?? false) === true;

        $email = isset($body['email']) ? trim((string)$body['email']) : '';
        if ($email === '') {
            $email = (string)($this->cfg['email_destino_padrao'] ?? '');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $this->respond(400, ['erro' => 'E-mail inválido.']);
            return;
        }

        if ($jornadas === [] || $pis === []) {
            $this->respond(400, ['erro' => 'Informe ao menos 1 jornada e 1 PI.']);
            return;
        }

        try {
            $cat = $this->montarCatalogo();

            // ── Validação contra o catálogo ──────────────────────────────
            $invalidas = array_values(array_diff($jornadas, array_column($cat['jornadas'], 'nome')));
            if ($invalidas) {
                $this->respond(400, ['erro' => 'Jornada(s) inexistente(s) ou inativa(s).', 'invalidos' => $invalidas]);
                return;
            }
            $invalidos = array_values(array_diff($pis, array_column($cat['pis'], 'desc_pi')));
            if ($invalidos) {
                $this->respond(400, ['erro' => 'PI(s) inexistente(s) ou inativa(s).', 'invalidos' => $invalidos]);
                return;
            }

            // Sprints por PI selecionada.
            $sprintsDaPi = [];
            $todasSprints = [];
            foreach ($pis as $p) {
                $nomes = array_column($cat['sprints_por_pi'][$p] ?? [], 'sprint');
                $sprintsDaPi[$p] = $nomes;
                $todasSprints = array_merge($todasSprints, $nomes);
            }
            $invalidos = array_values(array_diff($sprints, $todasSprints));
            if ($invalidos) {
                $this->respond(400, ['erro' => 'Sprint(s) inexistente(s) nas PIs escolhidas.', 'invalidos' => $invalidos]);
                return;
            }

            // Times (projeto_key) por jornada escolhida.
            $projPorJornada = [];
            foreach ($jornadas as $j) {
                $projPorJornada[$j] = array_column($cat['times_por_jornada'][$j] ?? [], 'projeto_key');
            }
            $todosProj = array_merge(...array_values($projPorJornada));
            $invalidos = array_values(array_diff($times, $todosProj));
            if ($invalidos) {
                $this->respond(400, ['erro' => 'Time(s) (projeto_key) inexistente(s) nas jornadas escolhidas.', 'invalidos' => $invalidos]);
                return;
            }

            // ── Resolve lotes (jornada → projetos) e PIs (→ sprints) ─────
            $lotes = [];
            foreach ($jornadas as $j) {
                $proj = $times === [] ? $projPorJornada[$j] : array_values(array_intersect($projPorJornada[$j], $times));
                if ($proj !== []) {
                    $lotes[] = ['jornada' => $j, 'projetos' => array_values(array_unique($proj))];
                }
            }
            if ($lotes === []) {
                $this->respond(400, ['erro' => 'Nenhum time com projeto_key para as jornadas/times escolhidos.']);
                return;
            }
            $pisResolvidas = [];
            foreach ($pis as $p) {
                if ($sprints === []) {
                    $pisResolvidas[] = ['pi' => $p, 'sprints' => []];
                } else {
                    $sp = array_values(array_intersect($sprintsDaPi[$p], $sprints));
                    if ($sp !== []) $pisResolvidas[] = ['pi' => $p, 'sprints' => $sp];
                }
            }

            // ── Duplicidade (mesmo dia, mesmos parâmetros) ───────────────
            $hash = sha1(json_encode(
                ['jornadas' => $jornadas, 'pis' => $pis, 'sprints' => $sprints, 'times' => $times],
                JSON_UNESCAPED_UNICODE
            ));
            $existente = $this->queue->buscarPorHashHoje($hash);
            if ($existente !== null && !$confirmar) {
                $this->respond(409, [
                    'aviso' => 'Já existe uma extração com os mesmos parâmetros hoje. '
                             . 'Ao prosseguir, a extração anterior será apagada e substituída. '
                             . 'Reenvie com confirmar=true para confirmar.',
                    'ordem_existente' => $existente,
                ]);
                return;
            }

            // ── Grava ordem + arquivo JSON ───────────────────────────────
            $delay = (int)($this->cfg['delay_minutes'] ?? 10);
            $ordem = [
                'criterios' => ['jornadas' => $jornadas, 'pis' => $pis, 'sprints' => $sprints, 'times' => $times],
                'lotes'     => $lotes,
                'pis'       => $pisResolvidas,
                'email'     => $email,
            ];
            $json = json_encode($ordem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id   = $this->queue->criar($json, $hash, $email !== '' ? $email : null, $delay);

            $dirRel = trim((string)($this->cfg['dir_agendamentos'] ?? 'data/agendamentos'), '/\\');
            $dirAbs = $this->baseDir . '/' . $dirRel;
            if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true)) {
                $this->queue->atualizarStatus($id, 'erro');
                Logger::error('extracao', 'POST /extracao/agendar: não foi possível criar o diretório de agendamentos', ['ordem' => $id, 'dir' => $dirRel]);
                $this->respond(500, ['erro' => 'Não foi possível criar o diretório de agendamentos.']);
                return;
            }
            $nomeArq = sprintf('ordem_%d_%s.json', $id, date('Ymd_His'));
            $conteudo = json_encode(
                ['id' => $id] + $ordem,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if (@file_put_contents($dirAbs . '/' . $nomeArq, $conteudo, LOCK_EX) === false) {
                $this->queue->atualizarStatus($id, 'erro');
                Logger::error('extracao', 'POST /extracao/agendar: não foi possível gravar o JSON da ordem', ['ordem' => $id, 'arquivo' => $nomeArq]);
                $this->respond(500, ['erro' => 'Não foi possível gravar o JSON da ordem.']);
                return;
            }
            $this->queue->definirArquivo($id, $dirRel . '/' . $nomeArq);

            $row = $this->queue->buscar($id);
            $this->respond(200, [
                'id'            => $id,
                'agendado_para' => $row['agendado_para'] ?? null,
                'aviso'         => "será disparada em ~{$delay} minutos",
                'projetos'      => array_values(array_unique(array_merge(...array_column($lotes, 'projetos')))),
                'lotes'         => $lotes,
                'pis'           => $pisResolvidas,
                'arquivo'       => $dirRel . '/' . $nomeArq,
                'substitui'     => $existente,
            ]);
        } catch (Throwable $e) {
            Logger::exception('extracao', 'POST /extracao/agendar: exceção', $e);
            $this->respond(500, ['erro' => 'Falha ao agendar a extração.']);
        }
    }

    // ── GET /extracao/fila ───────────────────────────────────────────────
    public function fila(): void
    {
        if (!$this->exigeMetodo('GET')) return;
        try {
            $limite = (int)($this->cfg['fila_limite'] ?? 20);
            $this->respond(200, ['ordens' => $this->queue->listarFila($limite)]);
        } catch (Throwable $e) {
            Logger::exception('extracao', 'GET /extracao/fila: exceção', $e);
            $this->respond(500, ['erro' => 'Falha ao listar a fila.']);
        }
    }

    // ── Auxiliares ───────────────────────────────────────────────────────

    /** Catálogo no formato consumido pela cascata do modal. */
    private function montarCatalogo(): array
    {
        $jornadas = array_map(
            fn($j) => ['id' => (int)$j['id_jornada'], 'nome' => $j['nome']],
            $this->catalog->jornadas()
        );
        $pis = array_map(
            fn($p) => [
                'id'       => (int)$p['id_versao_corrigida'],
                'desc_pi'  => $p['desc_pi'],
                'data_ini' => $p['data_ini'] ?? null,
                'data_fim' => $p['data_fim'] ?? null,
            ],
            $this->catalog->pis()
        );

        // PI corrente: a que contém a data de hoje; senão a mais recente (maior data_fim).
        $hoje = date('Y-m-d');
        $piCorrente = null;
        $maisRecente = null;
        foreach ($pis as $p) {
            if ($p['data_ini'] && $p['data_fim'] && $p['data_ini'] <= $hoje && $hoje <= $p['data_fim']) {
                $piCorrente = $p['desc_pi'];
                break;
            }
            if ($maisRecente === null || (string)$p['data_fim'] > (string)$maisRecente['data_fim']) {
                $maisRecente = $p;
            }
        }
        $piCorrente ??= $maisRecente['desc_pi'] ?? null;

        $sprintsPorPi = [];
        foreach ($pis as $p) {
            $sprintsPorPi[$p['desc_pi']] = [];
        }
        foreach ($this->catalog->sprintsPorPi() as $s) {
            if (($s['ativo'] ?? 'S') !== 'S' || !isset($sprintsPorPi[$s['desc_pi']])) continue;
            $sprintsPorPi[$s['desc_pi']][] = ['sprint' => $s['sprint'], 'tipo' => $s['tipo']];
        }

        $timesPorJornada = [];
        foreach ($jornadas as $j) {
            $timesPorJornada[$j['nome']] = [];
        }
        foreach ($this->queue->timesAtivosComProjeto() as $t) {
            if (!isset($timesPorJornada[$t['jornada']])) continue;
            $timesPorJornada[$t['jornada']][] = ['time' => $t['time'], 'projeto_key' => $t['projeto_key']];
        }

        return [
            'jornadas'          => $jornadas,
            'pis'               => $pis,
            'pi_corrente'       => $piCorrente,
            'email_padrao'      => (string)($this->cfg['email_destino_padrao'] ?? ''),
            'sprints_por_pi'    => $sprintsPorPi,
            'times_por_jornada' => $timesPorJornada,
        ];
    }

    private function listaDeStrings(mixed $v): bool
    {
        if (!is_array($v)) return false;
        foreach ($v as $x) {
            if (!is_string($x)) return false;
        }
        return true;
    }

    /** Trim, remove vazios e duplicatas, ordena (base do hash). */
    private function normalizar(array $lista): array
    {
        $lista = array_unique(array_filter(array_map('trim', $lista), fn($x) => $x !== ''));
        sort($lista, SORT_STRING);
        return array_values($lista);
    }

    private function exigeMetodo(string $metodo): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === $metodo) return true;
        header('Allow: ' . $metodo);
        $this->respond(405, ['erro' => "Use o método {$metodo}."]);
        return false;
    }

    private function respond(int $status, array $payload): void
    {
        // 400/409 (validação/duplicidade) → WARNING; 500 com erro já logado na exceção.
        if ($status === 400 || $status === 409) {
            Logger::warning('extracao', "POST /extracao/agendar → {$status}: " . (string)($payload['erro'] ?? $payload['aviso'] ?? ''), array_diff_key($payload, ['erro' => 1, 'aviso' => 1]));
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
