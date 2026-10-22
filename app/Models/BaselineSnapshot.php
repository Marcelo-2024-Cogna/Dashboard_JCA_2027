<?php

namespace App\Models;

/**
 * BaselineSnapshot
 * ────────────────
 * Representa a fotografia congelada ("Committed") de uma PI Planning
 * para um time específico.
 *
 * Um snapshot é IMUTÁVEL: depois de capturado, representa o compromisso
 * assumido no início da PI e não deve ser alterado.
 *
 * A PI e o Time são derivados do campo "Versões corrigidas" do Jira,
 * cujo formato é "[TIME] PI X/YY" (ex: "[SGGU] PI 3/26").
 */
class BaselineSnapshot
{
    public string $piId;          // "4/26"
    public string $team;          // "SGGU"
    public string $capturedAt;    // "2026-10-22 17:00:00"
    public string $sourceJql;
    /** @var array<int,array<string,mixed>> itens congelados */
    public array  $items;

    public function __construct(
        string $piId,
        string $team,
        array  $items,
        string $sourceJql = '',
        ?string $capturedAt = null
    ) {
        $this->piId       = $piId;
        $this->team       = $team;
        $this->items      = $items;
        $this->sourceJql  = $sourceJql;
        $this->capturedAt = $capturedAt ?? date('Y-m-d H:i:s');
    }

    // ── Derivação de PI e Time a partir de "Versões corrigidas" ───────────

    /**
     * Extrai [TIME, PI] do campo fix_version "[SGGU] PI 3/26".
     * Retorna [null, null] se não casar o padrão.
     *
     * @return array{0: ?string, 1: ?string}  [team, piId]
     */
    public static function parseFixVersion(?string $fixVersion): array
    {
        if (!$fixVersion) {
            return [null, null];
        }
        // Pode vir com múltiplos valores separados por "; " — usa o primeiro
        $first = trim(explode(';', $fixVersion)[0]);

        // Padrão: [TIME] PI X/YY
        if (preg_match('/^\[([^\]]+)\]\s*PI\s*([\d]+\/[\d]+)/i', $first, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [null, null];
    }

    // ── Totais ─────────────────────────────────────────────────────────────

    public function totalItems(): int
    {
        return count($this->items);
    }

    public function totalStoryPoints(): float
    {
        $sum = 0.0;
        foreach ($this->items as $item) {
            $sp = $item['story_points'] ?? null;
            if (is_numeric($sp)) {
                $sum += (float) $sp;
            }
        }
        return round($sum, 1);
    }

    /** Conjunto de issue_keys desta baseline (para comparação de escopo). */
    public function issueKeys(): array
    {
        return array_column($this->items, 'issue_key');
    }

    // ── Serialização ─────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'snapshot' => [
                'pi_id'       => $this->piId,
                'team'        => $this->team,
                'captured_at' => $this->capturedAt,
                'source_jql'  => $this->sourceJql,
                'total_items' => $this->totalItems(),
                'total_sp'    => $this->totalStoryPoints(),
                'is_frozen'   => true,
            ],
            'items' => $this->items,
        ];
    }

    public static function fromArray(array $data): self
    {
        $meta = $data['snapshot'] ?? [];
        return new self(
            $meta['pi_id']       ?? '',
            $meta['team']        ?? '',
            $data['items']       ?? [],
            $meta['source_jql']  ?? '',
            $meta['captured_at'] ?? null
        );
    }

    /**
     * Constrói itens de baseline a partir da saída do JiraModel::fetchAll
     * (que usa os nomes de campo do template). Normaliza para o formato
     * compacto do snapshot.
     *
     * @param array<int,array<string,mixed>> $jiraItems
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeItems(array $jiraItems): array
    {
        $out = [];
        foreach ($jiraItems as $it) {
            [$team, $pi] = self::parseFixVersion($it['versoes_corrigidas'] ?? null);
            $out[] = [
                'issue_key'         => $it['chave'] ?? null,
                'issue_type'        => $it['tipo_item'] ?? null,
                'summary'           => $it['resumo'] ?? null,
                'status'            => $it['status'] ?? null,
                'story_points'      => $it['story_points'] ?? null,
                'tempo_gasto_horas' => $it['tempo_gasto_horas'] ?? null,
                'sprint'            => $it['sprint'] ?? null,
                'epic_key'          => $it['pai'] ?? null,
                'project'           => $it['projeto'] ?? null,
                'fix_version'       => $it['versoes_corrigidas'] ?? null,
                'pi_id'             => $pi,
                'team'              => $team,
            ];
        }
        return $out;
    }
}
