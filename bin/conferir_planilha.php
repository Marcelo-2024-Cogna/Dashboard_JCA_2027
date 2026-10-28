<?php
/**
 * bin/conferir_planilha.php — Conferência planilha x banco (v1.5) — SOMENTE LEITURA
 * ────────────────────────────────────────────────────────────────────────────
 * Compara a aba "Extração Jira - Itens" (planilha "Template Métricas Review")
 * com extracao_jira / vw_extracao_calculada, POR PROJETO e TOTAL:
 *   (1) contagem de itens planilha x banco;
 *   (2) chaves só na planilha / só no banco (com causa provável, agrupadas);
 *   (3) divergências campo a campo nos 14 campos brutos (até 3 exemplos);
 *   (4) colunas calculadas da planilha (AH,AK,AM,AO,AQ,AS) x vw_extracao_calculada,
 *       sinalizando valores NEGATIVOS da planilha;
 *   (5) totais (itens, SP, SP de valor entregue, story point estimate).
 *
 * Relatório: stdout + data/exports/conferencia_<aaaammdd_hhmmss>.txt
 * Resumo no Logger (canal 'importacao'). Não grava NADA no banco.
 *
 * Uso:
 *   php bin/conferir_planilha.php --projetos=SGGU,SGSE,SGGS,SGTB
 *       [--planilha="Template Métricas Review.xlsx"] [--aba="Extração Jira - Itens"]
 *       [--sprints=26.4.1,26.4.2]
 *
 * --sprints restringe o lado do BANCO aos itens cujo campo `sprint` contenha
 * alguma dessas sprints (a planilha é extraída por sprint; o banco, por fixVersion).
 * Aceita "26.4.1" ou "SP 26.4.1".
 *
 * Datas (criado, ponto_compromisso, início/fim de fluxo/homologação/implantação)
 * são comparadas em precisão de MINUTO: o serial do Excel não guarda segundos.
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';
require __DIR__ . '/lib/xlsx_reader.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;

const CANAL = 'importacao';

// ── Parâmetros ──────────────────────────────────────────────────────────────
$ROOT = dirname(__DIR__);
$opt  = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $a, $m))   $opt[$m[1]] = '1';
}
function uso(string $msg): never
{
    fwrite(STDERR, "ERRO: $msg\n\nUso: php bin/conferir_planilha.php --projetos=SGGU,SGSE [--planilha=ARQ.xlsx] [--aba=NOME] [--sprints=26.4.1,26.4.2]\n");
    exit(1);
}

$projetos = array_values(array_unique(array_filter(array_map(
    fn($p) => strtoupper(trim($p)), explode(',', $opt['projetos'] ?? '')
))));
if (!$projetos) uso('--projetos é obrigatório (ex.: --projetos=SGGU,SGSE,SGGS,SGTB).');

$planilha = $opt['planilha'] ?? 'Template Métricas Review.xlsx';
if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $planilha)) $planilha = $ROOT . '/' . $planilha;
$aba = $opt['aba'] ?? 'Extração Jira - Itens';

$sprintsFiltro = array_values(array_filter(array_map(
    fn($s) => preg_replace('/^SP\s*/i', '', trim($s)), explode(',', $opt['sprints'] ?? '')
)));

// ── Saída (stdout + buffer p/ arquivo) ──────────────────────────────────────
$OUT = [];
function out(string $l = ''): void { global $OUT; $OUT[] = $l; echo $l . "\n"; }
function titulo(string $t): void { out(str_repeat('=', 78)); out("  $t"); out(str_repeat('=', 78)); }

// ── Definições ──────────────────────────────────────────────────────────────
// Cabeçalho esperado (coluna => texto) — validado antes de qualquer análise.
const CABECALHO = [
    'A' => 'Tipo de item', 'B' => 'Chave', 'C' => 'Sprint', 'D' => 'Projeto', 'E' => 'Status',
    'F' => 'Versões corrigidas', 'G' => 'Story Points', 'H' => 'Resumo', 'I' => 'Story point estimate',
    'J' => 'Σ de Tempo Gasto', 'K' => 'Tipo', 'L' => 'Categoria do status', 'M' => 'Pai',
    'P' => 'Criado', 'Q' => 'Ponto de compromisso', 'R' => 'Inicio do fluxo', 'S' => 'Fim do fluxo',
    'T' => 'Início Homologação', 'U' => 'Fim Homologação', 'V' => 'Início Implantação', 'W' => 'Fim Implantação',
    'AH' => 'Tempo Gasto (horas)', 'AK' => 'Ideação', 'AM' => 'Desenvolvimento', 'AO' => 'Homologação',
    'AQ' => 'Implantação', 'AS' => 'Lead Time',
];
// campo bruto => [coluna planilha, coluna banco, tipo]
const BRUTOS = [
    'tipo_item'          => ['A', 'tipo_item', 's'],
    'status'             => ['E', 'status', 's'],
    'story_points'       => ['G', 'story_points', 'n'],
    'story_point_estimate' => ['I', 'story_point_estimate', 'n'],
    'soma_tempo_gasto'   => ['J', 'soma_tempo_gasto', 'n'],
    'pai'                => ['M', 'pai', 's'],
    'criado'             => ['P', 'criado', 'd'],
    'ponto_compromisso'  => ['Q', 'ponto_compromisso', 'd'],
    'inicio_fluxo'       => ['R', 'inicio_fluxo', 'd'],
    'fim_fluxo'          => ['S', 'fim_fluxo', 'd'],
    'inicio_homologacao' => ['T', 'inicio_homologacao', 'd'],
    'fim_homologacao'    => ['U', 'fim_homologacao', 'd'],
    'inicio_implantacao' => ['V', 'inicio_implantacao', 'd'],
    'fim_implantacao'    => ['W', 'fim_implantacao', 'd'],
];
// rótulo => [coluna planilha, coluna da view, tolerância]
const CALCULADAS = [
    'AK Ideação (dias_ideacao)'                 => ['AK', 'dias_ideacao', 0.5],
    'AM Desenvolvimento (dias_desenvolvimento)' => ['AM', 'dias_desenvolvimento', 0.5],
    'AO Homologação (dias_homologacao)'         => ['AO', 'dias_homologacao', 0.5],
    'AQ Implantação (dias_implantacao)'         => ['AQ', 'dias_implantacao', 0.5],
    'AS Lead Time (lead_time)'                  => ['AS', 'lead_time', 0.5],
    'AH Tempo Gasto h (tempo_gasto_horas)'      => ['AH', 'tempo_gasto_horas', 0.005],
];
const TIPOS_VALOR   = ['História', 'Enabler', 'Débito Técnico', 'Spike', 'Teste', 'Segurança'];
const STATUS_ENTREG = ['Aceito', 'Pendente de Produção', 'Em produção'];

// ── Normalização ────────────────────────────────────────────────────────────
function normTxt(?string $v): ?string
{
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}
function normNum($v): ?float
{
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (float)$v;
}
function normVal(string $tipo, $v): ?string
{
    return match ($tipo) {
        's' => normTxt($v),
        'n' => ($n = normNum($v)) === null ? null : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'),
        // Datas comparadas em precisão de MINUTO: a planilha (export Jira/Excel) não guarda segundos.
        'd' => $v === null || $v === '' ? null : (is_numeric($v) ? substr((string)xlsxSerialToDateTime((string)$v), 0, 16) : normTxt($v)),
    };
}
/** Valor do banco já em string (datas 'Y-m-d H:i:s'); normaliza p/ comparação. */
function normDb(string $tipo, $v): ?string
{
    if ($v === null) return null;
    return match ($tipo) {
        's' => normTxt((string)$v),
        'n' => normVal('n', $v),
        'd' => substr((string)$v, 0, 16),
    };
}
function fmt(?string $v): string { return $v === null ? '(vazio)' : $v; }
function prefixo(string $chave): string { return strtoupper(preg_replace('/-\d+$/', '', $chave)); }
function isValorEntregue(?string $tipo, ?string $status): bool
{
    return in_array($tipo, TIPOS_VALOR, true) && in_array($status, STATUS_ENTREG, true);
}
function colIndex(string $c): int { $n = 0; foreach (str_split($c) as $ch) $n = $n * 26 + ord($ch) - 64; return $n; }

// ── 1. Lê e valida a planilha ───────────────────────────────────────────────
try {
    $rows = xlsxReadSheet($planilha, $aba);
} catch (Throwable $e) {
    Logger::exception(CANAL, 'conferir_planilha: falha ao ler a planilha', $e, ['aba' => $aba]);
    uso($e->getMessage());
}
$cab  = $rows[1] ?? [];
$erros = [];
foreach (CABECALHO as $col => $esperado) {
    $lido = trim(preg_replace('/\s+/u', ' ', (string)($cab[$col] ?? '')));
    if (mb_strtolower($lido) !== mb_strtolower($esperado)) {
        $erros[] = "coluna $col: esperado '$esperado', encontrado '" . ($lido === '' ? '(vazio)' : $lido) . "'";
    }
}
if ($erros) {
    $msg = "Cabeçalho da aba '$aba' diverge do layout esperado:\n  - " . implode("\n  - ", $erros);
    Logger::error(CANAL, 'conferir_planilha: cabeçalho divergente', ['divergencias' => count($erros)]);
    fwrite(STDERR, "ERRO: $msg\nConferência abortada (nenhum dado comparado).\n");
    exit(2);
}

// Planilha indexada por chave (só projetos pedidos).
$plan = [];          // prefixo => [chave => linha]
$dupPlan = [];
$totalLinhas = 0;
foreach ($rows as $n => $r) {
    if ($n === 1) continue;
    $chave = trim($r['B'] ?? '');
    if ($chave === '') continue;
    $totalLinhas++;
    $p = prefixo($chave);
    if (!in_array($p, $projetos, true)) continue;
    if (isset($plan[$p][$chave])) { $dupPlan[$chave] = ($dupPlan[$chave] ?? 1) + 1; continue; }
    $r['_linha'] = $n;
    $plan[$p][$chave] = $r;
}

// ── 2. Lê o banco (somente SELECT) ──────────────────────────────────────────
$pdo = Database::pdo();
$in  = implode(',', array_fill(0, count($projetos), '?'));
$st  = $pdo->prepare("SELECT v.*, p.desc_pi FROM vw_extracao_calculada v
                      LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = v.configuracao_pi_id
                      WHERE SUBSTRING_INDEX(v.chave, '-', 1) IN ($in)");
$st->execute($projetos);
$dbTodos = [];   // prefixo => [chave => linha]  (ativo='S', sem filtro de sprint)
foreach ($st->fetchAll() as $r) $dbTodos[prefixo($r['chave'])][$r['chave']] = $r;

// Chaves inativas (ativo<>'S') — só para explicar "só na planilha".
$st = $pdo->prepare("SELECT chave, ativo FROM extracao_jira
                     WHERE ativo <> 'S' AND SUBSTRING_INDEX(chave, '-', 1) IN ($in)");
$st->execute($projetos);
$dbInativos = [];
foreach ($st->fetchAll() as $r) $dbInativos[$r['chave']] = $r['ativo'];

// PIs presentes no banco (para decidir carry-over).
$pisBanco = [];
foreach ($dbTodos as $itens) foreach ($itens as $r) if (!empty($r['desc_pi'])) $pisBanco[$r['desc_pi']] = true;
$pisBanco = array_keys($pisBanco);

// Filtro de sprint do lado do banco.
function sprintCasa(?string $campo, array $sprints): bool
{
    foreach ($sprints as $s) {
        if (preg_match('/(?<![\d.])' . preg_quote($s, '/') . '(?!\d)/', (string)$campo)) return true;
    }
    return false;
}
$db = [];        // prefixo => [chave => linha]  (após filtro de sprint)
$foraSprint = []; // chave => true
foreach ($dbTodos as $p => $itens) {
    foreach ($itens as $k => $r) {
        if ($sprintsFiltro && !sprintCasa($r['sprint'], $sprintsFiltro)) { $foraSprint[$k] = true; continue; }
        $db[$p][$k] = $r;
    }
}

// ── 3. Relatório ────────────────────────────────────────────────────────────
$resumoLog = [];

/** Analisa um conjunto de prefixos e imprime a seção. Devolve resumo p/ log. */
function relatorio(string $titulo, array $prefixos, array $plan, array $db, array $dbTodos, array $dbInativos, array $foraSprint, array $pisBanco): array
{
    $P = []; $D = [];
    foreach ($prefixos as $p) {
        foreach ($plan[$p] ?? [] as $k => $r) $P[$k] = $r;
        foreach ($db[$p] ?? [] as $k => $r)   $D[$k] = $r;
    }
    $comuns   = array_values(array_intersect(array_keys($P), array_keys($D)));
    $soPlan   = array_values(array_diff(array_keys($P), array_keys($D)));
    $soBanco  = array_values(array_diff(array_keys($D), array_keys($P)));

    titulo($titulo);
    out('');
    out('(1) CONTAGEM DE ITENS');
    out(sprintf('    Planilha: %d | Banco: %d | Em comum: %d | Só planilha: %d | Só banco: %d',
        count($P), count($D), count($comuns), count($soPlan), count($soBanco)));

    // (2) chaves exclusivas
    out('');
    out('(2) CHAVES SÓ NA PLANILHA — causa provável');
    if (!$soPlan) out('    (nenhuma)');
    $causas = [];
    foreach ($soPlan as $k) {
        $r = $P[$k];
        $f = normTxt($r['F'] ?? '');
        preg_match_all('/PI\s*\d+\/\d+/', (string)$f, $m);
        $pisItem = array_values(array_unique(array_map(fn($x) => preg_replace('/\s+/', ' ', $x), $m[0])));
        if (isset($dbInativos[$k])) {
            $causa = "existe no banco com ativo='{$dbInativos[$k]}' (inativo)";
        } elseif (isset($foraSprint[$k])) {
            $causa = 'existe no banco, mas fora do filtro --sprints';
        } elseif ($f === null) {
            $causa = 'sem fixVersion na planilha (JQL por fixVersion não traz)';
        } elseif ($pisItem && !array_intersect($pisItem, $pisBanco)) {
            $ordBanco = array_map('piOrdinal', $pisBanco);
            $ordItem  = array_map('piOrdinal', $pisItem);
            if ($ordBanco && max($ordItem) < min($ordBanco)) {
                $causa = 'carry-over de PI anterior (fixVersion ' . implode('/', $pisItem) . '); JQL por fixVersion do PI alvo não traz';
            } elseif ($ordBanco && min($ordItem) > max($ordBanco)) {
                $causa = 'fixVersion de PI POSTERIOR (' . implode('/', $pisItem) . ') — item em sprint do PI alvo, mas já reetiquetado; JQL por fixVersion do PI alvo não traz';
            } else {
                $causa = 'fixVersion de outro PI (' . implode('/', $pisItem) . '); JQL por fixVersion do PI alvo não traz';
            }
        } else {
            $causa = 'INVESTIGAR: fixVersion do PI alvo, mas ausente no banco';
        }
        $causas[$causa][] = $k;
    }
    foreach ($causas as $causa => $ks) {
        $tipos = []; $sp = 0.0; $st = [];
        foreach ($ks as $k) {
            $r = $P[$k];
            $t = $r['A'] ?? '?'; $tipos[$t] = ($tipos[$t] ?? 0) + 1;
            $s = $r['E'] ?? '?'; $st[$s] = ($st[$s] ?? 0) + 1;
            $sp += (float)($r['G'] ?? 0);
        }
        arsort($tipos); arsort($st);
        out(sprintf('    - %d item(ns): %s', count($ks), $causa));
        out('        tipos: ' . implode(', ', array_map(fn($t, $q) => "$t=$q", array_keys($tipos), $tipos)) . ' | SP total: ' . $sp);
        out('        status: ' . implode(', ', array_map(fn($t, $q) => "$t=$q", array_keys($st), $st)));
        foreach (array_slice($ks, 0, 3) as $k) {
            $r = $P[$k];
            out(sprintf('        ex.: %s | fixVersion=%s | tipo=%s | status=%s | SP=%s',
                $k, fmt(normTxt($r['F'] ?? '')), $r['A'] ?? '?', $r['E'] ?? '?', fmt(normTxt($r['G'] ?? ''))));
        }
    }
    out('');
    out('    CHAVES SÓ NO BANCO');
    if (!$soBanco) out('    (nenhuma)');
    else {
        $tipos = []; $sp = 0.0;
        foreach ($soBanco as $k) { $tipos[$D[$k]['tipo_item'] ?? '?'] = ($tipos[$D[$k]['tipo_item'] ?? '?'] ?? 0) + 1; $sp += (float)$D[$k]['story_points']; }
        arsort($tipos);
        out(sprintf('    - %d item(ns) | tipos: %s | SP total: %s', count($soBanco),
            implode(', ', array_map(fn($t, $q) => "$t=$q", array_keys($tipos), $tipos)), $sp));
        foreach (array_slice($soBanco, 0, 3) as $k) {
            $r = $D[$k];
            out(sprintf('        ex.: %s | fixVersion=%s | tipo=%s | status=%s | SP=%s | sprint=%s',
                $k, fmt(normTxt($r['versoes_corrigidas'])), $r['tipo_item'], $r['status'], fmt($r['story_points']),
                mb_substr((string)$r['sprint'], 0, 60)));
        }
    }

    // (3) campos brutos
    out('');
    out(sprintf('(3) DIVERGÊNCIAS NOS 14 CAMPOS BRUTOS (%d itens em comum)', count($comuns)));
    $totDiv = 0;
    foreach (BRUTOS as $campo => [$col, $colDb, $tp]) {
        $n = 0; $ex = [];
        foreach ($comuns as $k) {
            $a = normVal($tp, $P[$k][$col] ?? null);
            $b = normDb($tp, $D[$k][$colDb]);
            if ($a !== $b) {
                $n++;
                if (count($ex) < 3) $ex[] = sprintf('%s: planilha=%s | banco=%s', $k, fmt($a), fmt($b));
            }
        }
        $totDiv += $n;
        out(sprintf('    %-22s %s', $campo, $n === 0 ? 'OK (0 divergências)' : "$n divergência(s)"));
        foreach ($ex as $e) out("        ex.: $e");
    }
    out("    Total de divergências brutas: $totDiv");

    // (4) calculadas
    out('');
    out('(4) COLUNAS CALCULADAS (planilha, valor cacheado) x vw_extracao_calculada');
    $totCalc = 0; $negativos = [];
    foreach (CALCULADAS as $rotulo => [$col, $colV, $tol]) {
        $n = 0; $ex = []; $neg = [];
        foreach ($comuns as $k) {
            $a = normNum($P[$k][$col] ?? null);
            $b = normNum($D[$k][$colV]);
            if ($a !== null && $a < 0) $neg[] = $k;
            $dif = ($a === null) !== ($b === null) || ($a !== null && abs($a - $b) > $tol);
            if ($dif) {
                $n++;
                if (count($ex) < 3) $ex[] = sprintf('%s (%s, %s): planilha=%s | view=%s',
                    $k, $D[$k]['tipo_item'], $D[$k]['status'], a2s($a), a2s($b));
            }
        }
        $totCalc += $n;
        out(sprintf('    %-42s %s', $rotulo, $n === 0 ? 'OK (0 divergências)' : "$n divergência(s)"));
        foreach ($ex as $e) out("        ex.: $e");
        if ($neg) $negativos[$rotulo] = $neg;
    }
    out("    Total de divergências calculadas: $totCalc");
    if ($negativos) {
        out('    ⚠ VALORES NEGATIVOS NA PLANILHA (ex.: item criado depois do ponto de compromisso):');
        foreach ($negativos as $rotulo => $ks) {
            out(sprintf('        %s: %d item(ns)', $rotulo, count($ks)));
            foreach (array_slice($ks, 0, 3) as $k) {
                $col = CALCULADAS[$rotulo][0]; $colV = CALCULADAS[$rotulo][1];
                out(sprintf('            %s: planilha=%s | view=%s | criado=%s | ponto_compromisso=%s', $k,
                    $P[$k][$col], a2s(normNum($D[$k][$colV])), fmt($D[$k]['criado']), fmt($D[$k]['ponto_compromisso'])));
            }
        }
    } else {
        out('    Valores negativos na planilha: nenhum.');
    }

    // (5) totais
    out('');
    out('(5) TOTAIS');
    $agg = function (array $itens, callable $get): array {
        $n = 0; $sp = 0.0; $spv = 0.0; $est = 0.0;
        foreach ($itens as $k => $r) {
            [$tipo, $status, $s, $e] = $get($r);
            $n++; $sp += (float)$s; $est += (float)$e;
            if (isValorEntregue($tipo, $status)) $spv += (float)$s;
        }
        return [$n, $sp, $spv, $est];
    };
    $gp = fn($r) => [normTxt($r['A'] ?? ''), normTxt($r['E'] ?? ''), normNum($r['G'] ?? null), normNum($r['I'] ?? null)];
    $gd = fn($r) => [$r['tipo_item'], $r['status'], $r['story_points'], $r['story_point_estimate']];
    $pick = fn(array $src, array $ks) => array_intersect_key($src, array_flip($ks));
    $linhas = [
        'Planilha (todos)'         => $agg($P, $gp),
        'Banco (todos)'            => $agg($D, $gd),
        'Em comum — valores planilha' => $agg($pick($P, $comuns), $gp),
        'Em comum — valores banco'    => $agg($pick($D, $comuns), $gd),
    ];
    out(sprintf('    %-30s %8s %10s %14s %10s', '', 'itens', 'SP', 'SP val.entreg.', 'estimate'));
    foreach ($linhas as $rot => [$n, $sp, $spv, $est]) {
        out(sprintf('    %-30s %8d %10s %14s %10s', $rot, $n, $sp + 0, $spv + 0, $est + 0));
    }
    out('');

    return [
        'planilha' => count($P), 'banco' => count($D), 'comuns' => count($comuns),
        'so_planilha' => count($soPlan), 'so_banco' => count($soBanco),
        'div_brutos' => $totDiv, 'div_calculadas' => $totCalc,
        'sp_comum_planilha' => $linhas['Em comum — valores planilha'][1],
        'sp_valor_comum_planilha' => $linhas['Em comum — valores planilha'][2],
        'estimate_comum_planilha' => $linhas['Em comum — valores planilha'][3],
    ];
}
/** 'PI 4/26' -> 264 (ano*10 + número) para ordenar PIs. */
function piOrdinal(string $pi): int
{
    return preg_match('/(\d+)\s*\/\s*(\d+)/', $pi, $m) ? ((int)$m[2] * 10 + (int)$m[1]) : 0;
}
function a2s(?float $v): string { return $v === null ? '(vazio)' : (string)(round($v, 4) + 0); }

// Cabeçalho do relatório
titulo('CONFERÊNCIA PLANILHA x BANCO — ' . date('Y-m-d H:i:s'));
out('  Planilha : ' . basename($planilha) . " (aba '$aba', $totalLinhas linhas de dados)");
out('  Projetos : ' . implode(', ', $projetos));
out('  Sprints  : ' . ($sprintsFiltro ? implode(', ', $sprintsFiltro) . ' (filtro aplicado ao lado do BANCO)' : '(sem filtro)'));
out('  PIs no banco (itens dos projetos): ' . ($pisBanco ? implode(', ', $pisBanco) : '(nenhum)'));
if ($dupPlan) out('  ⚠ Chaves duplicadas na planilha (mantida a 1ª ocorrência): ' . implode(', ', array_keys($dupPlan)));
if ($sprintsFiltro) out('  Itens do banco fora do filtro de sprint: ' . count($foraSprint));
out('');

foreach ($projetos as $p) {
    $resumoLog[$p] = relatorio("PROJETO $p", [$p], $plan, $db, $dbTodos, $dbInativos, $foraSprint, $pisBanco);
}
if (count($projetos) > 1) {
    $resumoLog['TOTAL'] = relatorio('TOTAL (' . implode(', ', $projetos) . ')', $projetos, $plan, $db, $dbTodos, $dbInativos, $foraSprint, $pisBanco);
}

// ── 4. Grava arquivo + log ──────────────────────────────────────────────────
$dir = $ROOT . '/data/exports';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$arq = $dir . '/conferencia_' . date('Ymd_His') . '.txt';
file_put_contents($arq, implode("\n", $OUT) . "\n");
echo "Relatório salvo em: data/exports/" . basename($arq) . "\n";

Logger::info(CANAL, 'conferir_planilha: conferência concluída', [
    'planilha' => basename($planilha), 'aba' => $aba, 'projetos' => $projetos,
    'sprints' => $sprintsFiltro, 'resumo' => $resumoLog, 'relatorio' => 'data/exports/' . basename($arq),
]);
