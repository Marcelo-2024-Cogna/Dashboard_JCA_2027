<?php
/**
 * bin/backfill_sprint_ref.php — Preenche a coluna derivada `sprint_ref` (v1.5)
 * ────────────────────────────────────────────────────────────────────────────
 * sprint_ref = sprint de MAIOR numeração (tupla numérica ano.pi.seq) entre as
 * listadas no campo `sprint` (ex.: '[SGGU] SP 26.4.1;[SGGU] SP 26.3.6' → 'SP 26.4.1').
 * A ordem do texto é irrelevante. Sem sprint identificável → NULL.
 *
 * Idempotente: só atualiza linhas cujo sprint_ref difere do calculado; rodar
 * de novo não altera nada. NÃO mexe em nenhuma outra coluna (a baseline segue
 * imutável em seu conteúdo). Cria a coluna/índice se o banco ainda não os tem
 * (mesma migração de db/schema.sql, item 8c).
 *
 * Uso: php bin/backfill_sprint_ref.php [--exemplos=5] [--dry-run]
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';
require __DIR__ . '/../app/Services/SprintRef.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;
use App\Services\SprintRef;

const CANAL = 'importacao';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $a, $m))   $opt[$m[1]] = '1';
}
$dryRun   = isset($opt['dry-run']);
$exemplos = max(0, (int)($opt['exemplos'] ?? 5));

// tabela => [chave primária, prefixo do índice]
$tabelas = [
    'extracao_jira' => ['id_extracao', 'idx_extracao_jira_sprint_ref'],
    'baseline'      => ['id_baseline', 'idx_baseline_sprint_ref'],
];

try {
    $pdo = Database::pdo();
    $resumo = [];

    foreach ($tabelas as $tabela => [$pk, $idx]) {
        // Migração idempotente da coluna + índice.
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'sprint_ref'");
        $st->execute([$tabela]);
        if ((int)$st->fetchColumn() === 0) {
            if ($dryRun) {
                echo "[dry-run] $tabela: coluna sprint_ref ausente (seria criada).\n";
                continue;
            }
            $pdo->exec("ALTER TABLE $tabela ADD COLUMN sprint_ref VARCHAR(20) NULL AFTER sprint, ADD KEY $idx (sprint_ref)");
            echo "$tabela: coluna sprint_ref + índice criados.\n";
        }

        $total = 0; $atualizadas = 0;
        $upd = $pdo->prepare("UPDATE $tabela SET sprint_ref = :ref WHERE $pk = :id");
        $pdo->beginTransaction();
        foreach ($pdo->query("SELECT $pk AS id, sprint, sprint_ref FROM $tabela") as $r) {
            $total++;
            $novo = SprintRef::fromText($r['sprint']);
            if ($novo !== $r['sprint_ref']) {
                if (!$dryRun) $upd->execute([':ref' => $novo, ':id' => $r['id']]);
                $atualizadas++;
            }
        }
        $pdo->commit();

        $dist = $pdo->query("SELECT sprint_ref, COUNT(*) AS n FROM $tabela GROUP BY sprint_ref ORDER BY sprint_ref")->fetchAll();
        echo str_repeat('=', 70) . "\n$tabela: $total linhas | " . ($dryRun ? 'a atualizar' : 'atualizadas') . ": $atualizadas\n";
        $nulos = 0;
        foreach ($dist as $d) {
            if ($d['sprint_ref'] === null) { $nulos = (int)$d['n']; continue; }
            printf("   %-12s %6d\n", $d['sprint_ref'], $d['n']);
        }
        printf("   %-12s %6d\n", 'NULL', $nulos);

        if ($exemplos > 0) {
            // Itens com várias sprints: mostra descendente e ascendente no texto.
            echo "   Exemplos (itens com várias sprints):\n";
            $q = $pdo->query("SELECT chave, sprint, sprint_ref FROM $tabela WHERE sprint LIKE '%;%' ORDER BY $pk");
            $asc = []; $desc = [];
            foreach ($q as $r) {
                preg_match_all('/\d+\.\d+\.\d+/', $r['sprint'], $mm);
                $nums = $mm[0];
                if (count($nums) < 2) continue;
                $t = array_map(fn($n) => array_map('intval', explode('.', $n)), $nums);
                $ordenada = $t; usort($ordenada, fn($a, $b) => $a <=> $b);
                if ($t === $ordenada && count($asc) < $exemplos)                 $asc[]  = $r;
                elseif ($t === array_reverse($ordenada) && count($desc) < $exemplos) $desc[] = $r;
                if (count($asc) >= $exemplos && count($desc) >= $exemplos) break;
            }
            foreach (['texto ASCENDENTE' => $asc, 'texto DESCENDENTE' => $desc] as $rot => $lista) {
                foreach (array_slice($lista, 0, max(1, (int)ceil($exemplos / 2))) as $r) {
                    printf("     [%s] %s | sprint=\"%s\" => sprint_ref=%s\n", $rot, $r['chave'], mb_substr($r['sprint'], 0, 110), $r['sprint_ref'] ?? 'NULL');
                }
            }
        }
        $resumo[$tabela] = ['linhas' => $total, 'atualizadas' => $atualizadas, 'nulos' => $nulos, 'dry_run' => $dryRun];
    }

    Logger::info(CANAL, 'backfill_sprint_ref: concluído', $resumo);
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    Logger::exception(CANAL, 'backfill_sprint_ref: falha', $e);
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(4);
}
