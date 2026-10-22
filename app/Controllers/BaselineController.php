<?php

namespace App\Controllers;

use App\Models\JiraModel;
use App\Models\BaselineSnapshot;
use App\Models\ProgressSnapshot;
use App\Repositories\BaselineRepositoryInterface;
use App\Services\MetricsService;
use App\Services\JqlBuilder;

/**
 * BaselineController
 * ──────────────────
 * Rotas HTTP para captura de baseline, snapshots de progresso e métricas.
 *
 *   GET /baseline/capture    congela a baseline da PI (IMUTÁVEL; 1x por PI)
 *   GET /baseline/progress   grava um snapshot de evolução (acumula)
 *   GET /baseline/list       lista baselines + progressos armazenados
 *   GET /metrics             indicadores agregados para o frontend
 *
 * Todas as JQLs vêm do JqlBuilder (fonte única: config/pi.php).
 */
class BaselineController
{
    private array $jiraConfig;
    private array $baselineConfig;
    private BaselineRepositoryInterface $repo;
    private JqlBuilder $jqlBuilder;

    public function __construct(
        array $jiraConfig,
        array $baselineConfig,
        BaselineRepositoryInterface $repo,
        JqlBuilder $jqlBuilder
    ) {
        $this->jiraConfig     = $jiraConfig;
        $this->baselineConfig = $baselineConfig;
        $this->repo           = $repo;
        $this->jqlBuilder     = $jqlBuilder;
    }

    // ── GET /baseline/capture ─────────────────────────────────────────────

    /**
     * Captura a baseline (foto imutável do início da PI). Um snapshot por time.
     *
     * TRAVA DE IMUTABILIDADE: se já existir baseline para a PI, recusa a
     * operação — a menos que ?force=1 seja passado conscientemente.
     */
    public function capture(): void
    {
        $this->jsonHeaders();

        $piId  = $this->baselineConfig['current_pi'];
        $force = !empty($_GET['force']);

        try {
            $jql       = $this->jqlBuilder->forCurrentPi();
            $capturedAt = date('Y-m-d H:i:s');
            $byTeam    = $this->extractGroupedByTeam($jql);

            // ── Trava: impede sobrescrever o início da PI sem intenção ──────
            if (!$force) {
                $existing = [];
                foreach (array_keys($byTeam) as $team) {
                    if ($this->repo->baselineExists($piId, $team)) {
                        $existing[] = $team;
                    }
                }
                if ($existing) {
                    $this->respond([
                        'success' => false,
                        'blocked' => true,
                        'pi_id'   => $piId,
                        'message' => 'Já existe baseline congelada para esta PI nos times: '
                            . implode(', ', $existing)
                            . '. A baseline é imutável. Para recapturar conscientemente, '
                            . 'use ?force=1 (isto grava uma NOVA foto; a original mais antiga '
                            . 'continua sendo usada como início da PI).',
                        'existing_teams' => $existing,
                    ], 409);
                    return;
                }
            }

            $saved = [];
            foreach ($byTeam as $team => $teamItems) {
                $snapshot = new BaselineSnapshot($piId, $team, $teamItems, $jql, $capturedAt);
                $file = $this->repo->save($snapshot);
                $saved[] = [
                    'team'        => $team,
                    'file'        => $file,
                    'total_items' => $snapshot->totalItems(),
                    'total_sp'    => $snapshot->totalStoryPoints(),
                ];
            }

            $this->respond([
                'success'     => true,
                'pi_id'       => $piId,
                'captured_at' => $capturedAt,
                'forced'      => $force,
                'teams'       => count($saved),
                'snapshots'   => $saved,
            ]);

        } catch (\RuntimeException $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ── GET /baseline/progress ────────────────────────────────────────────

    /**
     * Grava um snapshot de PROGRESSO (estado atual da PI). Acumula — cada
     * chamada adiciona um ponto na curva de evolução (burn-up).
     *
     * Parâmetro opcional ?sprint=26.4.1 registra a sprint de referência
     * (até onde a PI avançou). Se omitido, usa a closing_sprint da config.
     */
    public function progress(): void
    {
        $this->jsonHeaders();

        $piId      = $this->baselineConfig['current_pi'];
        $refSprint = $_GET['sprint'] ?? ($this->baselineConfig['closing_sprint'] ?? null);

        try {
            // Estado atual até a sprint de referência (ou fechamento)
            $jql        = $this->jqlBuilder->forClosing();
            $capturedAt = date('Y-m-d H:i:s');
            $byTeam     = $this->extractGroupedByTeam($jql);

            $saved = [];
            foreach ($byTeam as $team => $teamItems) {
                $snapshot = new ProgressSnapshot(
                    $piId, $team, $teamItems, $refSprint, $jql, $capturedAt
                );
                $file = $this->repo->saveProgress($snapshot);
                $saved[] = [
                    'team'         => $team,
                    'file'         => $file,
                    'ref_sprint'   => $refSprint,
                    'total_items'  => $snapshot->totalItems(),
                    'total_sp'     => $snapshot->totalStoryPoints(),
                    'delivered_sp' => $snapshot->deliveredStoryPoints($this->baselineConfig['done_statuses']),
                ];
            }

            $this->respond([
                'success'     => true,
                'pi_id'       => $piId,
                'ref_sprint'  => $refSprint,
                'captured_at' => $capturedAt,
                'teams'       => count($saved),
                'snapshots'   => $saved,
            ]);

        } catch (\RuntimeException $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ── GET /baseline/list ────────────────────────────────────────────────

    public function list(): void
    {
        $this->jsonHeaders();

        $piId = $_GET['pi'] ?? $this->baselineConfig['current_pi'];

        $baselines = array_map(fn(BaselineSnapshot $s) => [
            'pi_id'       => $s->piId,
            'team'        => $s->team,
            'captured_at' => $s->capturedAt,
            'total_items' => $s->totalItems(),
            'total_sp'    => $s->totalStoryPoints(),
        ], $this->repo->findByPi($piId));

        $progress = array_map(fn(ProgressSnapshot $s) => [
            'pi_id'       => $s->piId,
            'team'        => $s->team,
            'ref_sprint'  => $s->refSprint,
            'captured_at' => $s->capturedAt,
            'total_sp'    => $s->totalStoryPoints(),
        ], $this->repo->findProgressByPi($piId));

        $this->respond([
            'success'           => true,
            'pi_id'             => $piId,
            'baseline_count'    => count($baselines),
            'progress_count'    => count($progress),
            'baselines'         => $baselines,
            'progress_snapshots' => $progress,
        ]);
    }

    // ── GET /metrics ──────────────────────────────────────────────────────

    /**
     * Consolida indicadores comparando a baseline (committed) com o estado
     * de fechamento (delivered), cruzando por issue_key. Inclui a curva de
     * evolução a partir dos snapshots de progresso.
     */
    public function metrics(): void
    {
        $this->jsonHeaders();

        try {
            $piId      = $_GET['pi'] ?? $this->baselineConfig['current_pi'];
            $baselines = $this->repo->findByPi($piId);

            if (!$baselines) {
                $this->respond([
                    'success' => true,
                    'pi_id'   => $piId,
                    'warning' => 'Nenhuma baseline capturada para esta PI. Rode /baseline/capture primeiro.',
                    'data'    => null,
                ]);
                return;
            }

            // Estado atual (fechamento): até a última sprint encerrada.
            $jql     = $this->jqlBuilder->forClosing();
            $current = $this->extractGroupedByTeam($jql);

            $svc  = new MetricsService($this->baselineConfig);
            $rows = [];
            foreach ($baselines as $baseline) {
                $teamCurrent = $current[$baseline->team] ?? [];
                $rows[] = $svc->consolidate($baseline, $teamCurrent);
            }

            $this->respond([
                'success'   => true,
                'pi_id'     => $piId,
                'data'      => $this->shapeForCharts($rows),
                'evolution' => $this->buildEvolution($piId),
            ]);

        } catch (\RuntimeException $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers internos
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extrai do Jira e agrupa os itens por time (derivado de fix_version).
     * @return array<string,array<int,array<string,mixed>>>  [team => items]
     */
    private function extractGroupedByTeam(string $jql): array
    {
        $model = new JiraModel($this->jiraConfig);
        $items = BaselineSnapshot::normalizeItems($model->fetchAll($jql, 100)['items']);

        $byTeam = [];
        foreach ($items as $it) {
            $team = $it['team'] ?? null;
            if ($team !== null) {
                $byTeam[$team][] = $it;
            }
        }
        return $byTeam;
    }

    /**
     * Monta a curva de evolução (burn-up) por time a partir dos snapshots
     * de progresso: cada ponto = (sprint/data, SP entregue acumulado).
     */
    private function buildEvolution(string $piId): array
    {
        $done     = $this->baselineConfig['done_statuses'];
        $progress = $this->repo->findProgressByPi($piId);

        $byTeam = [];
        foreach ($progress as $snap) {
            $byTeam[$snap->team][] = [
                'ref_sprint'   => $snap->refSprint,
                'captured_at'  => $snap->capturedAt,
                'total_sp'     => $snap->totalStoryPoints(),
                'delivered_sp' => $snap->deliveredStoryPoints($done),
            ];
        }
        return $byTeam;
    }

    // ── Formata as linhas consolidadas no formato dos gráficos ────────────

    private function shapeForCharts(array $rows): array
    {
        $labels = array_map(fn($r) => $r['team'], $rows);

        return [
            'committed_vs_delivered' => [
                'labels'    => $labels,
                'committed' => array_map(fn($r) => $r['committed_sp'], $rows),
                'delivered' => array_map(fn($r) => $r['delivered_sp'], $rows),
            ],
            'predictability' => [
                'labels' => $labels,
                'pct'    => array_map(fn($r) => $r['predictability_pct'], $rows),
                'meta'   => $rows[0]['predictability_target'] ?? 80,
            ],
            'scope_creep' => [
                'labels'        => $labels,
                'committed'     => array_map(fn($r) => $r['committed_sp'], $rows),
                'scope_add'     => array_map(fn($r) => $r['scope_add_sp'], $rows),
                'scope_drop'    => array_map(fn($r) => -abs($r['scope_drop_sp']), $rows),
                'variation_pct' => array_map(fn($r) => $r['scope_variation_pct'], $rows),
            ],
            'raw' => $rows,
        ];
    }

    // ── Helpers de resposta ───────────────────────────────────────────────

    private function jsonHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache');
        }
    }

    private function respond(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function respondError(string $message, int $status = 500): void
    {
        $this->respond(['success' => false, 'error' => $message], $status);
    }
}
