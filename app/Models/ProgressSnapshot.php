<?php

namespace App\Models;

/**
 * ProgressSnapshot
 * ────────────────
 * Representa o estado da PI de um time num MOMENTO da execução
 * (uma extração manual). Vários progress snapshots ao longo da PI
 * formam a curva de evolução (burn-up).
 *
 * Diferença para o BaselineSnapshot:
 *   - BaselineSnapshot: foto ÚNICA e IMUTÁVEL do início da PI (committed).
 *   - ProgressSnapshot: foto ACUMULATIVA, uma por extração, com a sprint
 *     de referência (até onde a PI avançou naquele momento).
 */
class ProgressSnapshot
{
    public string $piId;            // "4/26"
    public string $team;            // "SGGU"
    public string $capturedAt;      // "2026-10-22 17:00:00"
    public ?string $refSprint;      // sprint de referência (ex: "26.4.1") ou null
    public string $sourceJql;
    /** @var array<int,array<string,mixed>> itens no estado da extração */
    public array  $items;

    public function __construct(
        string $piId,
        string $team,
        array  $items,
        ?string $refSprint = null,
        string $sourceJql = '',
        ?string $capturedAt = null
    ) {
        $this->piId       = $piId;
        $this->team       = $team;
        $this->items      = $items;
        $this->refSprint  = $refSprint;
        $this->sourceJql  = $sourceJql;
        $this->capturedAt = $capturedAt ?? date('Y-m-d H:i:s');
    }

    // ── Totais ─────────────────────────────────────────────────────────────

    public function totalItems(): int
    {
        return count($this->items);
    }

    public function totalStoryPoints(): float
    {
        $sum = 0.0;
        foreach ($this->items as $it) {
            if (is_numeric($it['story_points'] ?? null)) {
                $sum += (float) $it['story_points'];
            }
        }
        return round($sum, 1);
    }

    /**
     * SP entregues (status em $doneStatuses) neste ponto da PI.
     */
    public function deliveredStoryPoints(array $doneStatuses): float
    {
        $sum = 0.0;
        foreach ($this->items as $it) {
            $status = $it['status'] ?? null;
            if ($status !== null && in_array($status, $doneStatuses, true)
                && is_numeric($it['story_points'] ?? null)) {
                $sum += (float) $it['story_points'];
            }
        }
        return round($sum, 1);
    }

    // ── Serialização ─────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'progress' => [
                'pi_id'       => $this->piId,
                'team'        => $this->team,
                'captured_at' => $this->capturedAt,
                'ref_sprint'  => $this->refSprint,
                'source_jql'  => $this->sourceJql,
                'total_items' => $this->totalItems(),
                'total_sp'    => $this->totalStoryPoints(),
            ],
            'items' => $this->items,
        ];
    }

    public static function fromArray(array $data): self
    {
        $meta = $data['progress'] ?? [];
        return new self(
            $meta['pi_id']       ?? '',
            $meta['team']        ?? '',
            $data['items']       ?? [],
            $meta['ref_sprint']  ?? null,
            $meta['source_jql']  ?? '',
            $meta['captured_at'] ?? null
        );
    }
}
