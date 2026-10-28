<?php

namespace App\Services;

/**
 * SprintRef
 * ─────────
 * Sprint de referência de um item: a de MAIOR numeração entre as sprints
 * listadas no campo `sprint` do Jira (que pode trazer várias, separadas por
 * ';', em ordem inconsistente).
 *
 * Compara a tupla numérica ano.pi.seq (ex.: 26.4.2 > 26.4.1 > 26.3.6) e
 * devolve no formato do catálogo ('SP 26.4.2'). Textos sem número no formato
 * N.N.N (ex.: 'SP 26.4 - Planejamento', 'BACKLOG PI 26.4') ou vazios → NULL.
 *
 * Usada na gravação (ExtractionService, bin/import_baseline.php) e no backfill
 * (bin/backfill_sprint_ref.php).
 */
class SprintRef
{
    public static function fromText(?string $sprint): ?string
    {
        if ($sprint === null || trim($sprint) === '') {
            return null;
        }
        if (!preg_match_all('/(?<![\d.])(\d+)\.(\d+)\.(\d+)(?![\d.]*\d)/', $sprint, $m, PREG_SET_ORDER)) {
            return null;
        }
        $best = null;
        foreach ($m as $x) {
            $t = [(int)$x[1], (int)$x[2], (int)$x[3]];
            if ($best === null || $t > $best) {
                $best = $t;
            }
        }
        return 'SP ' . implode('.', $best);
    }
}
