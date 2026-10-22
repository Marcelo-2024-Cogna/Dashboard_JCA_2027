<?php

namespace App\Services;

use App\Models\BaselineSnapshot;

/**
 * MetricsService
 * ──────────────
 * Consolida os indicadores ágeis a partir de um snapshot de Baseline
 * ("Committed") e do estado atual/fechamento ("Delivered").
 *
 * Indicadores:
 *   1. Velocidade           — SP entregues por PI (linha-guia histórica)
 *   2. Committed vs Delivered
 *   3. Previsibilidade (%)  — entregue do escopo planejado / planejado
 *   4. Scope Creep          — itens adicionados (add) e removidos (drop)
 *
 * Regra de "Done" (decisão de negócio): configurável em config/baseline.php.
 * Padrão atual: Aceito, Em produção, Pendente de Produção.
 */
class MetricsService
{
    /** @var string[] status considerados entregues */
    private array $doneStatuses;
    private int   $predictabilityTarget;

    public function __construct(array $baselineConfig)
    {
        $this->doneStatuses         = $baselineConfig['done_statuses'] ?? ['Aceito'];
        $this->predictabilityTarget = (int)($baselineConfig['predictability_target'] ?? 80);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function isDone(?string $status): bool
    {
        return $status !== null && in_array($status, $this->doneStatuses, true);
    }

    private function sp($item): float
    {
        $v = $item['story_points'] ?? null;
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function sumSp(array $items): float
    {
        $s = 0.0;
        foreach ($items as $it) {
            $s += $this->sp($it);
        }
        return round($s, 1);
    }

    /** Indexa itens por issue_key. */
    private function byKey(array $items): array
    {
        $map = [];
        foreach ($items as $it) {
            if (!empty($it['issue_key'])) {
                $map[$it['issue_key']] = $it;
            }
        }
        return $map;
    }

    // ── 1. Velocidade ────────────────────────────────────────────────────

    /**
     * Velocidade média histórica: média dos SP entregues por PI.
     *
     * @param array<string,float> $deliveredByPi  [pi => delivered_sp]
     */
    public function averageVelocity(array $deliveredByPi): float
    {
        if (!$deliveredByPi) {
            return 0.0;
        }
        return round(array_sum($deliveredByPi) / count($deliveredByPi), 1);
    }

    // ── 2. Committed vs Delivered ──────────────────────────────────────────

    /**
     * SP comprometido (toda a baseline) vs SP entregue do escopo planejado.
     *
     * @return array{committed: float, delivered: float}
     */
    public function committedVsDelivered(BaselineSnapshot $baseline, array $currentItems): array
    {
        $committed    = $this->sumSp($baseline->items);
        $currentByKey = $this->byKey($currentItems);

        // "Delivered do escopo planejado": itens da baseline que, no estado
        // atual, estão em status Done. Usa o status ATUAL (não o congelado).
        $delivered = 0.0;
        foreach ($baseline->items as $it) {
            $key = $it['issue_key'] ?? null;
            if ($key === null) {
                continue;
            }
            $currentStatus = $currentByKey[$key]['status'] ?? ($it['status'] ?? null);
            if ($this->isDone($currentStatus)) {
                $delivered += $this->sp($it);
            }
        }

        return [
            'committed' => $committed,
            'delivered' => round($delivered, 1),
        ];
    }

    // ── 3. Previsibilidade (%) ────────────────────────────────────────────

    /**
     * Índice de Previsibilidade = (delivered_planejado / committed) × 100.
     */
    public function predictability(BaselineSnapshot $baseline, array $currentItems): float
    {
        $cvd = $this->committedVsDelivered($baseline, $currentItems);
        if ($cvd['committed'] <= 0) {
            return 0.0;
        }
        return round($cvd['delivered'] / $cvd['committed'] * 100, 1);
    }

    // ── 4. Scope Creep ─────────────────────────────────────────────────────

    /**
     * Identifica itens adicionados (add) e removidos (drop) na execução.
     *
     *   add  = presentes no atual, ausentes na baseline
     *   drop = presentes na baseline, ausentes no atual
     *
     * @return array{
     *   scope_add_count:int, scope_drop_count:int,
     *   scope_add_sp:float,  scope_drop_sp:float,
     *   added:array, dropped:array
     * }
     */
    public function scopeCreep(BaselineSnapshot $baseline, array $currentItems): array
    {
        $baselineByKey = $this->byKey($baseline->items);
        $currentByKey  = $this->byKey($currentItems);

        $baselineKeys = array_keys($baselineByKey);
        $currentKeys  = array_keys($currentByKey);

        $addedKeys   = array_diff($currentKeys, $baselineKeys);
        $droppedKeys = array_diff($baselineKeys, $currentKeys);

        $added   = array_map(fn($k) => $currentByKey[$k],  $addedKeys);
        $dropped = array_map(fn($k) => $baselineByKey[$k], $droppedKeys);

        return [
            'scope_add_count'  => count($added),
            'scope_drop_count' => count($dropped),
            'scope_add_sp'     => $this->sumSp($added),
            'scope_drop_sp'    => $this->sumSp($dropped),
            'added'            => array_values($added),
            'dropped'          => array_values($dropped),
        ];
    }

    // ── 5. Variação de Escopo (%) ─────────────────────────────────────────

    /**
     * Variação de Escopo (%) = (Pontos Adicionados Pós-Baseline /
     *                           Pontos Totais da Baseline) × 100
     *
     * Mede o quanto de trabalho NOVO (transbordo/scope add) entrou na PI
     * em relação ao que foi comprometido. Alto = muita mudança de plano.
     */
    public function scopeVariation(BaselineSnapshot $baseline, array $currentItems): float
    {
        $committed = $this->sumSp($baseline->items);
        if ($committed <= 0) {
            return 0.0;
        }
        $scope = $this->scopeCreep($baseline, $currentItems);
        return round($scope['scope_add_sp'] / $committed * 100, 1);
    }

    // ── Consolidação completa de uma PI/Time ──────────────────────────────

    /**
     * Gera o bloco completo de métricas de um snapshot vs estado atual.
     */
    public function consolidate(BaselineSnapshot $baseline, array $currentItems): array
    {
        $cvd   = $this->committedVsDelivered($baseline, $currentItems);
        $scope = $this->scopeCreep($baseline, $currentItems);
        $committed = $cvd['committed'];

        return [
            'pi_id'                  => $baseline->piId,
            'team'                   => $baseline->team,
            'committed_sp'           => $committed,
            'delivered_sp'           => $cvd['delivered'],
            'predictability_pct'     => $this->predictability($baseline, $currentItems),
            'predictability_target'  => $this->predictabilityTarget,
            'scope_add_sp'           => $scope['scope_add_sp'],
            'scope_drop_sp'          => $scope['scope_drop_sp'],
            'scope_add_count'        => $scope['scope_add_count'],
            'scope_drop_count'       => $scope['scope_drop_count'],
            // Variação de Escopo (%) = add / committed × 100
            'scope_variation_pct'    => $committed > 0
                ? round($scope['scope_add_sp'] / $committed * 100, 1)
                : 0.0,
        ];
    }
}
