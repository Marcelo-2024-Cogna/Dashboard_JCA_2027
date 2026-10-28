<?php
/**
 * bin/seed_sustentacao.php — Carga de sustentacao_historico a partir da Velocity
 * ────────────────────────────────────────────────────────────────────────────
 * Lê a aba "2026_Produtividade" da planilha Velocity e carrega, para CADA linha
 * (time + PI + sprint) que tenha a coluna J preenchida, um registro em
 * sustentacao_historico:
 *     times_id            = id_time do time (join pelo nome, coluna B)
 *     configuracao_pi_id  = id_versao_corrigida (join por desc_pi = coluna F)
 *     sprint_nome         = coluna G (ex.: 'SP 26.4.1')
 *     pts_sustentacao     = coluna J (Média dos Pts de Sustentação da PI passada)
 *
 * Colunas: A=Jornada B=Time C=Devs D=Produtividade E=Horas F=PI G=Sprint
 *          H=Dias I=Velocity J=Pts Sustentação K=Capacity
 *
 * IDEMPOTENTE: DELETE FROM sustentacao_historico antes de recarregar.
 * Pré-requisito: times já populada (rodar bin/seed_catalogo.php antes) e
 * configuracao_pi já populada.
 *
 * Uso: php bin/seed_sustentacao.php
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;

$ROOT  = __DIR__ . '/..';
$XLSX  = $ROOT . '/Template Velocity 26-4.xlsx';
$SHEET = '2026_Produtividade';

// ── Leitor xlsx mínimo (ZipArchive + sharedStrings) ─────────────────────────
function readSheet(string $file, string $sheetName): array
{
    $z = new ZipArchive();
    if ($z->open($file) !== true) {
        fwrite(STDERR, "Não consegui abrir: $file\n");
        exit(1);
    }
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) { $t .= (string)$r->t; }
            $shared[] = $t;
        }
    }
    $wb   = simplexml_load_string($z->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $rel) { $relMap[(string)$rel['Id']] = (string)$rel['Target']; }
    $target = null;
    foreach ($wb->sheets->sheet as $s) {
        if ((string)$s['name'] === $sheetName) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $relMap[$rid] ?? null;
            break;
        }
    }
    if (!$target) { fwrite(STDERR, "Aba '$sheetName' não encontrada\n"); exit(1); }
    $sheet = simplexml_load_string($z->getFromName('xl/' . ltrim($target, '/')));
    $z->close();

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)\d+$/', (string)$c['r'], $m);
            $col  = $m[1];
            $type = (string)$c['t'];
            $val  = (string)$c->v;
            if ($type === 's') $val = $shared[(int)$val] ?? $val;
            $rows[$r][$col] = $val;
        }
    }
    return $rows;
}

$rows = readSheet($XLSX, $SHEET);
$max  = max(array_keys($rows));

$pdo = Database::pdo();

// ── Mapas de resolução: nome do time → id_time; desc_pi → id ───────────────
$timeIdByNome = [];
foreach ($pdo->query('SELECT id_time, nome FROM times') as $t) {
    $timeIdByNome[$t['nome']] = (int)$t['id_time'];
}
$piIdByDesc = [];
foreach ($pdo->query('SELECT id_versao_corrigida, desc_pi FROM configuracao_pi') as $p) {
    $piIdByDesc[$p['desc_pi']] = (int)$p['id_versao_corrigida'];
}

// ── Carga transacional idempotente ──────────────────────────────────────────
$inseridos    = 0;
$semTime      = []; // nomes de time sem match em `times`
$semPi        = []; // desc_pi sem match em configuracao_pi
try {
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM sustentacao_historico');

    $ins = $pdo->prepare(
        'INSERT INTO sustentacao_historico
            (times_id, configuracao_pi_id, sprint_nome, pts_sustentacao, ativo)
         VALUES (:times_id, :pi_id, :sprint, :pts, :ativo)'
    );

    for ($r = 2; $r <= $max; $r++) {
        $nome   = trim($rows[$r]['B'] ?? '');
        $piDesc = trim($rows[$r]['F'] ?? '');
        $sprint = trim($rows[$r]['G'] ?? '');
        $jRaw   = $rows[$r]['J'] ?? '';

        if ($nome === '' || $jRaw === '' || $jRaw === null) continue; // só linhas com J preenchido
        if (!is_numeric($jRaw)) continue;

        if (!isset($piIdByDesc[$piDesc])) { $semPi[$piDesc] = true; continue; }
        $piId = $piIdByDesc[$piDesc];

        $timeId = $timeIdByNome[$nome] ?? null; // NULL permitido na tabela
        if ($timeId === null) $semTime[$nome] = true;

        $ins->execute([
            ':times_id' => $timeId,
            ':pi_id'    => $piId,
            ':sprint'   => $sprint,
            ':pts'      => (float)$jRaw,
            ':ativo'    => 'S',
        ]);
        $inseridos++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Logger::exception('importacao', 'seed_sustentacao: erro na carga, rollback executado', $e);
    fwrite(STDERR, "ERRO na carga, rollback executado: " . $e->getMessage() . "\n");
    exit(1);
}

Logger::info('importacao', 'seed_sustentacao: carga concluída', [
    'linhas_inseridas' => $inseridos,
    'times_sem_match'  => array_keys($semTime),
    'pis_sem_match'    => array_keys($semPi),
]);
if ($semTime) {
    Logger::warning('importacao', 'seed_sustentacao: times sem match em `times` (times_id NULL)', ['times' => array_keys($semTime)]);
}

// ── Relatório ───────────────────────────────────────────────────────────────
echo str_repeat('=', 70) . "\n";
echo "  SEED DE SUSTENTAÇÃO (Velocity · aba {$SHEET}) — concluído\n";
echo str_repeat('=', 70) . "\n";
echo "  Linhas inseridas: {$inseridos}\n";
if ($semTime) {
    echo "  Times SEM match em `times` (gravados com times_id NULL):\n";
    foreach (array_keys($semTime) as $n) echo "     - {$n}\n";
}
if ($semPi) {
    echo "  PIs SEM match em configuracao_pi (linhas ignoradas):\n";
    foreach (array_keys($semPi) as $p) echo "     - {$p}\n";
}

echo "\n  Conferência — 'Gestor de unidades' na PI 4/26:\n";
$stmt = $pdo->prepare(
    'SELECT sh.sprint_nome, sh.pts_sustentacao
       FROM sustentacao_historico sh
       JOIN times t            ON t.id_time = sh.times_id
       JOIN configuracao_pi p  ON p.id_versao_corrigida = sh.configuracao_pi_id
      WHERE t.nome = :nome AND p.desc_pi = :pi
      ORDER BY sh.sprint_nome'
);
$stmt->execute([':nome' => 'Gestor de unidades', ':pi' => 'PI 4/26']);
foreach ($stmt->fetchAll() as $row) {
    echo sprintf("     %-12s J=%s\n", $row['sprint_nome'], rtrim(rtrim($row['pts_sustentacao'], '0'), '.'));
}
echo "\nSeed de sustentação finalizado com sucesso.\n";
