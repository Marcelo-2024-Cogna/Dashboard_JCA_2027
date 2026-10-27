<?php
/**
 * bin/seed_catalogo.php — SEED / reseed do CATÁLOGO a partir da Velocity (v1.5)
 * ────────────────────────────────────────────────────────────────────────────
 * Popula as tabelas de catálogo de TIMES:
 *   jornada · times · times_composicao
 *
 * NÃO toca em configuracao_pi nem em sprints (já populadas corretamente:
 * 4 PIs, 26 sprints). Também NÃO toca em extração/baseline.
 *
 * FONTE ÚNICA (catálogo de times, 46 times):
 *   "Template Velocity 26-4.xlsx", aba "2026_Produtividade".
 *     A=Jornada  B=Time  C=Qtde de Devs  D=Produtividade (H/P)
 *     E=Horas Produtivas/dia  F=PI  G=Sprint  H=Dias Úteis  I=Velocity
 *     J=Média Pts Sustentação  K=Capacity
 *   Os times aparecem repetidos (uma linha por sprint/PI). Para o catálogo
 *   usamos os valores da PI 4/26 (quando o time existe nela); se o time só
 *   existe em PIs anteriores (Sparta, Triforce — pré-fusão), usamos a PI
 *   mais recente disponível.
 *
 *   projeto_key vem de config/team_keys.php[nomeTime] (pode ser null).
 *
 * Mapeamento para times_composicao (confirmado):
 *   qtde_dev                = C (Qtde de Devs)
 *   horas_dev               = E (Horas Produtivas por dia e por Dev)
 *   media_produtividade_pi  = D (Produtividade H/P)
 *
 * IDEMPOTENTE e TRANSACIONAL: com FOREIGN_KEY_CHECKS=0 apaga, na ordem segura,
 * sustentacao_historico (FK->times, se existir), times_composicao, times,
 * jornada; reinsere tudo; FOREIGN_KEY_CHECKS=1 ao fim. Rodar N vezes não
 * duplica.
 *
 * Uso: php bin/seed_catalogo.php
 */

require __DIR__ . '/../app/Database.php';

use App\Database;

$ROOT      = __DIR__ . '/..';
$XLSX      = $ROOT . '/Template Velocity 26-4.xlsx';
$SHEET     = '2026_Produtividade';
$TEAM_KEYS = $ROOT . '/config/team_keys.php';
$PI_CATALOGO = 'PI 4/26'; // PI de referência para os valores de composição

// ── De-para nome do time → projeto_key (validado com o usuário) ────────────
if (!is_file($TEAM_KEYS)) {
    fwrite(STDERR, "Arquivo não encontrado: $TEAM_KEYS\n");
    exit(1);
}
$teamKeys = require $TEAM_KEYS;

// ── Normalização de jornada ────────────────────────────────────────────────
// A Velocity usa variações para a MESMA jornada (ex.: '& ' vs ' e ', singular
// vs plural). Unificamos para uma forma canônica (preferindo a forma com 'e').
function normalizaJornada(string $j): string
{
    $j = trim($j);
    $map = [
        // Secretaria & Gestor de Unidades  ≡  Secretaria e Gestor de Unidades
        'Secretaria & Gestor de Unidades' => 'Secretaria e Gestor de Unidades',
        'Secretaria e Gestor de Unidades' => 'Secretaria e Gestor de Unidades',
        // Atendimento & Serviço / Atendimento e Serviço  ≡  Atendimento e Serviços
        'Atendimento & Serviço'   => 'Atendimento e Serviços',
        'Atendimento & Serviços'  => 'Atendimento e Serviços',
        'Atendimento e Serviço'   => 'Atendimento e Serviços',
        'Atendimento e Serviços'  => 'Atendimento e Serviços',
    ];
    return $map[$j] ?? $j;
}

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

/** Extrai o número da PI (ex.: 'PI 4/26' → 4); null se não casar. */
function piNum(string $pi): ?int
{
    return preg_match('/PI\s+(\d+)\/\d+/', $pi, $m) ? (int)$m[1] : null;
}

// ── Lê a aba e consolida o catálogo de times ───────────────────────────────
$rows = readSheet($XLSX, $SHEET);
$max  = max(array_keys($rows));

// $teams[nome] = [
//   'jornada'  => jornada normalizada (1ª ocorrência),
//   'order'    => ordem de 1ª aparição,
//   'byPi'     => [ piNum => ['c'=>,'d'=>,'e'=>] ],  // valores por PI
// ]
$teams = [];
$order = 0;
for ($r = 2; $r <= $max; $r++) {
    $nome = trim($rows[$r]['B'] ?? '');
    if ($nome === '') continue;
    $jor  = normalizaJornada($rows[$r]['A'] ?? '');
    $pi   = piNum(trim($rows[$r]['F'] ?? ''));
    if ($pi === null) continue;

    if (!isset($teams[$nome])) {
        $teams[$nome] = ['jornada' => $jor, 'order' => $order++, 'byPi' => []];
    }
    $teams[$nome]['byPi'][$pi] = [
        'c' => $rows[$r]['C'] ?? '',
        'd' => $rows[$r]['D'] ?? '',
        'e' => $rows[$r]['E'] ?? '',
    ];
}

if (!$teams) {
    fwrite(STDERR, "Nenhum time encontrado na aba '$SHEET'.\n");
    exit(1);
}

$piRefNum = piNum($PI_CATALOGO); // 4

/** Converte célula numérica da planilha em float ou null. */
function numOrNull($v): ?float
{
    if ($v === '' || $v === null) return null;
    return is_numeric($v) ? (float)$v : null;
}

// Resolve C/D/E de cada time: usa a PI de referência; senão a PI mais recente.
foreach ($teams as $nome => &$info) {
    $byPi = $info['byPi'];
    if (isset($byPi[$piRefNum])) {
        $src = $byPi[$piRefNum];
    } else {
        krsort($byPi);                 // PI mais recente primeiro
        $src = reset($byPi);
    }
    $info['qtde_dev']  = numOrNull($src['c']);
    $info['horas_dev'] = numOrNull($src['e']);
    $info['media']     = numOrNull($src['d']);
}
unset($info);

// ── Carga transacional idempotente (via App\Database::pdo()) ────────────────
$pdo = Database::pdo();

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();

    // Limpa o catálogo de times na ordem segura. sustentacao_historico (se já
    // existir) referencia times por FK → apaga primeiro. NÃO apaga
    // configuracao_pi nem sprints.
    foreach (['sustentacao_historico', 'times_composicao', 'times', 'jornada'] as $t) {
        try {
            $pdo->exec("DELETE FROM {$t}");
        } catch (Throwable $e) {
            // sustentacao_historico pode ainda não existir (criada na tarefa B).
            if ($t !== 'sustentacao_historico') throw $e;
        }
    }

    $insJornada = $pdo->prepare(
        'INSERT INTO jornada (nome, projeto_baseline, ativo) VALUES (:nome, NULL, :ativo)'
    );
    $insTime = $pdo->prepare(
        'INSERT INTO times (jornada_id, nome, projeto_baseline, projeto_key, ativo)
         VALUES (:jornada_id, :nome, NULL, :projeto_key, :ativo)'
    );
    $insComp = $pdo->prepare(
        'INSERT INTO times_composicao
            (times_id, qtde_dev, horas_dev, media_produtividade_pi, ativo)
         VALUES (:times_id, :qtde_dev, :horas_dev, :media, :ativo)'
    );

    // 1) Jornadas distintas (ordem de 1ª aparição), ativo='S'.
    $jornadaId = [];
    foreach ($teams as $info) {
        $j = $info['jornada'];
        if (!isset($jornadaId[$j])) {
            $insJornada->execute([':nome' => $j, ':ativo' => 'S']);
            $jornadaId[$j] = (int)$pdo->lastInsertId();
        }
    }

    // 2) Times + 3) composição (uma linha por time).
    $totTimes = 0;
    $totComp  = 0;
    $semKey   = []; // times com projeto_key NULL
    foreach ($teams as $nome => $info) {
        $pk = array_key_exists($nome, $teamKeys) ? $teamKeys[$nome] : null;
        if ($pk === null) $semKey[] = $nome;

        $insTime->execute([
            ':jornada_id'  => $jornadaId[$info['jornada']],
            ':nome'        => $nome,
            ':projeto_key' => $pk,
            ':ativo'       => 'S',
        ]);
        $timeId = (int)$pdo->lastInsertId();
        $totTimes++;

        $insComp->execute([
            ':times_id'  => $timeId,
            ':qtde_dev'  => $info['qtde_dev'],
            ':horas_dev' => $info['horas_dev'],
            ':media'     => $info['media'],
            ':ativo'     => 'S',
        ]);
        $totComp++;
    }

    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "ERRO na carga, rollback executado: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Relatório ───────────────────────────────────────────────────────────────
echo str_repeat('=', 70) . "\n";
echo "  RESEED DO CATÁLOGO (Velocity · aba {$SHEET}) — concluído\n";
echo str_repeat('=', 70) . "\n";
echo "  Jornadas distintas inseridas: " . count($jornadaId) . "\n";
foreach ($jornadaId as $j => $id) echo "     - {$j}\n";
echo "  Times inseridos:              {$totTimes}\n";
echo "  Composições inseridas:        {$totComp}\n";
echo "  Times com projeto_key NULL:   " . count($semKey) . "\n";
foreach ($semKey as $n) echo "     - {$n}\n";
echo "\nSeed finalizado com sucesso.\n";
