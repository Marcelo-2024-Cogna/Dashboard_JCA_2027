<?php

namespace App\Controllers;

use App\Repositories\CapacityRepository;
use App\Repositories\BaselineRepositoryInterface;
use App\Services\CapacityCalculator;

/**
 * CapacityController
 * ──────────────────
 * Rotas HTTP para o cálculo de capacidade e velocidade dos times no
 * PI Planning.
 *
 *   GET  /capacity/plan?team=SGGU[&working_days=8&hours=6&devs=5&last_n=3]
 *        → calcula a capacidade projetada do time (usa defaults da config
 *          se os parâmetros forem omitidos)
 *
 *   POST /capacity/sprint   (via query ou body)
 *        → registra uma sprint no histórico MANUALMENTE
 *
 *   GET  /capacity/sync[?pi=4/26]
 *        → alimenta o histórico AUTOMATICAMENTE a partir dos snapshots
 *          de progresso (horas gastas + pontos entregues por time/sprint)
 *
 *   GET  /capacity/history?team=SGGU
 *        → retorna o histórico de sprints do time
 */
class CapacityController
{
    private CapacityRepository $repo;
    private CapacityCalculator $calc;
    private ?BaselineRepositoryInterface $baselineRepo;
    private array $config;          // config/baseline.php (regras + PI)
    private array $capacityConfig;  // config/capacity.php (calendário + devs)

    public function __construct(
        CapacityRepository $repo,
        CapacityCalculator $calc,
        ?BaselineRepositoryInterface $baselineRepo = null,
        array $config = [],
        array $capacityConfig = []
    ) {
        $this->repo           = $repo;
        $this->calc           = $calc;
        $this->baselineRepo   = $baselineRepo;
        $this->config         = $config;
        $this->capacityConfig = $capacityConfig;
    }

    // ── Resolução de parâmetros (calendário + devs, parametrizáveis) ──────

    /** Dias úteis de uma sprint: calendário da planilha → default da config. */
    private function workingDaysFor(?string $sprint): int
    {
        $cal = $this->capacityConfig['working_days_by_sprint'] ?? [];
        if ($sprint !== null && isset($cal[$sprint])) {
            return (int) $cal[$sprint];
        }
        $defaults = $this->config['capacity_defaults'] ?? [];
        return (int)($defaults['working_days'] ?? 8);
    }

    /** Qtde de devs de um time: config/capacity.php → default da config. */
    private function devCountFor(string $team): float
    {
        $map = $this->capacityConfig['dev_count_by_team'] ?? [];
        if (isset($map[$team])) {
            return (float) $map[$team];
        }
        $defaults = $this->config['capacity_defaults'] ?? [];
        return (float)($defaults['dev_count_default'] ?? 5);
    }

    /**
     * Horas produtivas por dev/dia de um time:
     * mapa por time (config/capacity.php) → padrão da capacity →
     * default de baseline → 7.
     */
    private function productiveHoursFor(string $team): float
    {
        $byTeam = $this->capacityConfig['productive_hours_by_team'] ?? [];
        if (isset($byTeam[$team])) {
            return (float) $byTeam[$team];
        }
        return (float)(
            $this->capacityConfig['productive_hours_per_dev']
            ?? ($this->config['capacity_defaults']['productive_hours_per_dev'] ?? 7)
        );
    }

    // ── GET /capacity/plan ────────────────────────────────────────────────

    public function plan(): void
    {
        $this->jsonHeaders();

        $team = trim($_GET['team'] ?? '');
        if ($team === '') {
            $this->respondError('Parâmetro "team" é obrigatório.', 400);
            return;
        }

        // Parâmetros: request > calendário/devs da config/capacity.php > defaults.
        // 'sprint' opcional permite pegar os dias úteis daquela sprint.
        $sprint = $_GET['sprint'] ?? null;

        $planParams = [
            'working_days' => isset($_GET['working_days'])
                ? (int) $_GET['working_days']
                : $this->workingDaysFor($sprint),
            'productive_hours_per_dev' => isset($_GET['hours'])
                ? (float) $_GET['hours']
                : $this->productiveHoursFor($team),
            'dev_count' => isset($_GET['devs'])
                ? (float) $_GET['devs']
                : $this->devCountFor($team),
        ];
        $lastN = isset($_GET['last_n'])
            ? (int)$_GET['last_n']
            : (int)($this->config['capacity_last_n'] ?? 3);

        $history = $this->repo->load($team);
        $result  = $this->calc->planForTeam($history['sprints'], $planParams, $lastN);

        // Fallback: sem histórico válido, usa a produtividade de referência
        // (seed) do config/capacity.php para ainda projetar a capacidade.
        if ($result['reference_productivity'] === null) {
            $seed = $this->capacityConfig['reference_productivity_by_team'][$team] ?? null;
            if ($seed !== null && $seed > 0) {
                $result['reference_productivity'] = (float) $seed;
                $result['productivity_source']    = 'seed:config';
                $result['calculated_capacity']    = $this->calc->capacity(
                    $planParams['working_days'],
                    $planParams['productive_hours_per_dev'],
                    $planParams['dev_count'],
                    (float) $seed
                );
                $result['warning'] = 'Sem histórico de sprints ainda. Capacidade '
                    . 'calculada com a produtividade de referência da config (seed). '
                    . 'Rode /capacity/sync após capturar progressos para usar dados reais.';
            } else {
                $result['warning'] = 'Sem histórico válido e sem produtividade de '
                    . 'referência para este time. Rode /capacity/sync ou registre '
                    . 'sprints via /capacity/sprint.';
            }
        } else {
            $result['productivity_source'] = 'history:avg';
        }

        $this->respond([
            'success' => true,
            'team'    => $team,
            'result'  => $result,
        ]);
    }

    // ── POST /capacity/sprint ─────────────────────────────────────────────

    public function addSprint(): void
    {
        $this->jsonHeaders();

        // Aceita tanto query string quanto JSON no corpo
        $in = $_POST;
        if (empty($in)) {
            $raw  = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $in = $json;
            } else {
                $in = $_GET; // fallback: permite teste via GET
            }
        }

        $team = trim($in['team'] ?? '');
        $name = trim($in['sprint_name'] ?? '');
        if ($team === '' || $name === '') {
            $this->respondError('Parâmetros "team" e "sprint_name" são obrigatórios.', 400);
            return;
        }

        $sprint = [
            'sprint_name'              => $name,
            'working_days'             => (int)($in['working_days'] ?? 0),
            'productive_hours_per_dev' => (float)($in['hours'] ?? 0),
            'dev_count'                => (int)($in['devs'] ?? 0),
            'total_hours_spent'        => (float)($in['hours_spent'] ?? 0),
            'total_points_delivered'   => (float)($in['points_delivered'] ?? 0),
        ];

        $data = $this->repo->addSprint($team, $sprint);

        $this->respond([
            'success'      => true,
            'team'         => $team,
            'sprint_added' => $name,
            'total_sprints' => count($data['sprints']),
            'history'      => $data,
        ]);
    }

    // ── GET /capacity/sync ────────────────────────────────────────────────

    /**
     * Alimenta o histórico de capacidade AUTOMATICAMENTE a partir dos
     * snapshots de progresso de uma PI.
     *
     * Para cada (time, sprint de referência) encontrado nos snapshots de
     * progresso, agrega horas gastas (todos os itens) e pontos entregues
     * (status Done) e registra/atualiza a sprint no histórico do time,
     * aplicando os parâmetros de capacidade (dias úteis, horas, devs).
     */
    public function sync(): void
    {
        $this->jsonHeaders();

        if ($this->baselineRepo === null) {
            $this->respondError('Repositório de baseline não disponível para sync.', 500);
            return;
        }

        $piId = $_GET['pi'] ?? ($this->config['current_pi'] ?? null);
        if (!$piId) {
            $this->respondError('Parâmetro "pi" é obrigatório (ou defina current_pi).', 400);
            return;
        }

        $done        = $this->config['done_statuses'] ?? ['Aceito', 'Em produção', 'Pendente de Produção'];
        $valueTypes  = $this->config['value_delivery_types'] ?? [];
        $sustTypes   = $this->config['sustentacao_types'] ?? [];

        $progress = $this->baselineRepo->findProgressByPi($piId);
        if (!$progress) {
            $this->respond([
                'success' => true,
                'pi_id'   => $piId,
                'warning' => 'Nenhum snapshot de progresso encontrado para esta PI. '
                    . 'Rode /baseline/progress primeiro.',
                'synced'  => 0,
            ]);
            return;
        }

        $synced = [];
        foreach ($progress as $snap) {
            $team       = $snap->team;
            $sprintName = $snap->refSprint ?? ($piId . '-progress');

            // Agrega SÓ entrega de valor para produtividade; sustentação à parte.
            $agg = $this->calc->aggregateFromItems($snap->items, $done, $valueTypes, $sustTypes);

            $sprint = [
                'sprint_name'              => $sprintName,
                'working_days'             => $this->workingDaysFor($sprintName),
                'productive_hours_per_dev' => $this->productiveHoursFor($team),
                'dev_count'                => $this->devCountFor($team),
                'total_hours_spent'        => $agg['total_hours_spent'],       // valor
                'total_points_delivered'   => $agg['total_points_delivered'],  // valor
                'sustentacao_hours'        => $agg['sustentacao_hours'],
                'sustentacao_points'       => $agg['sustentacao_points'],
                'source'                   => 'auto:progress',
                'captured_at'              => $snap->capturedAt,
            ];

            $this->repo->addSprint($team, $sprint);
            $synced[] = [
                'team'                   => $team,
                'sprint'                 => $sprintName,
                'working_days'           => $sprint['working_days'],
                'dev_count'              => $sprint['dev_count'],
                'total_hours_spent'      => $agg['total_hours_spent'],
                'total_points_delivered' => $agg['total_points_delivered'],
                'sustentacao_hours'      => $agg['sustentacao_hours'],
                'sustentacao_points'     => $agg['sustentacao_points'],
            ];
        }

        $this->respond([
            'success' => true,
            'pi_id'   => $piId,
            'synced'  => count($synced),
            'sprints' => $synced,
        ]);
    }

    // ── GET /capacity/history ─────────────────────────────────────────────

    public function history(): void
    {
        $this->jsonHeaders();

        $team = trim($_GET['team'] ?? '');
        if ($team === '') {
            // Sem time: lista os times com histórico
            $this->respond([
                'success' => true,
                'teams'   => $this->repo->allTeams(),
            ]);
            return;
        }

        $this->respond([
            'success' => true,
            'history' => $this->repo->load($team),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

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
