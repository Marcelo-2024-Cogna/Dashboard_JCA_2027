<?php
/**
 * bin/import_baseline.php — Importador da BASELINE (v1.5): xlsx -> JSON -> banco
 * ────────────────────────────────────────────────────────────────────────────
 * Fonte : "Template Baseline 26-4.xlsx", aba "Baseline itens"
 *         A Tipo de item | B Chave | C Resumo | D Projeto | E Status |
 *         F Versões corrigidas | G Pai | H Story point estimate | I Story Points |
 *         J Sprint | K Tipo Sustentação
 *
 * Pipeline
 *   [1] lê a aba e normaliza para JSON AUDITÁVEL:
 *       data/import/baseline/baseline_<pi-slug>_<aaaammdd_hhmmss>.json
 *       (itens aceitos + rejeitados com motivo + metadados/contagens);
 *   [2] valida e imprime o RELATÓRIO (lidos, aceitos, rejeitados por motivo,
 *       distribuição por jornada/time, soma de story_point_estimate por time);
 *   [3] carrega na tabela `baseline` em UMA transação.
 *
 * COMO AS LINHAS DA PI ALVO SÃO IDENTIFICADAS
 *   A planilha traz a baseline "congelada" da PI, mas a coluna F (Versões
 *   corrigidas) está VAZIA em parte das linhas e o item que transborda de uma
 *   PI anterior carrega a fixVersion antiga (ex.: "[SGGS] PI 3/26;[SGGS] PI 4/26").
 *   Por isso uma linha pertence à PI alvo se, e somente se:
 *     (a) Versões corrigidas contém o token da PI (ex.: "PI 4/26"), OU
 *     (b) Sprint contém o token da PI no formato de sprint (ex.: "SP 26.4.1",
 *         "PI 26.4", "SP 26.4 - Planejamento", "BACKLOG PI 26.4").
 *   O critério usado fica gravado em cada item do JSON (fixversion|sprint|ambos).
 *   Linhas que não casam com nenhum dos dois são REJEITADAS (motivo "fora da PI
 *   alvo") e listadas no JSON — nada é descartado em silêncio.
 *
 * REGRAS DE VALIDAÇÃO (rejeição com motivo)
 *   cabeçalho esperado (aborta tudo) · campos obrigatórios (tipo, chave, projeto)
 *   · chave no formato XXXX-N · chave duplicada (mantém a 1ª) · projeto_key
 *   (prefixo da chave) sem time ATIVO no catálogo (times.projeto_key) ·
 *   story point não numérico · texto acima do tamanho da coluna.
 *
 * IMUTABILIDADE: a baseline é um snapshot imutável por PI. Se já existem linhas
 * da PI e --substituir não foi passado, ABORTA (exit 3) sem alterar nada. Com
 * --substituir apaga as linhas da PI e recarrega (mesma transação).
 *
 * Uso:
 *   php bin/import_baseline.php --pi="PI 4/26" [--arquivo=CAMINHO.xlsx] [--dry-run] [--substituir]
 *
 * Exit: 0 ok · 1 parâmetros/arquivo/PI · 2 cabeçalho divergente · 3 baseline já
 *       existente (sem --substituir) · 4 falha na carga (rollback).
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';
require __DIR__ . '/../app/Services/SprintRef.php';
require __DIR__ . '/lib/xlsx_reader.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;
use App\Services\SprintRef;

const CANAL = 'importacao';
const ABA   = 'Baseline itens';
const CABECALHO = [
    'A' => 'Tipo de item', 'B' => 'Chave', 'C' => 'Resumo', 'D' => 'Projeto', 'E' => 'Status',
    'F' => 'Versões corrigidas', 'G' => 'Pai', 'H' => 'Story point estimate', 'I' => 'Story Points',
    'J' => 'Sprint', 'K' => 'Tipo Sustentação',
];
// Tamanhos das colunas VARCHAR de `baseline` (db/schema.sql).
const LIMITES = ['tipo_item' => 100, 'chave' => 50, 'projeto' => 255, 'status' => 100, 'pai' => 50, 'tipo_sustentacao' => 255];
const ARQ_ORIGEM_PADRAO = 'Template Baseline 26-4.xlsx';

$ROOT = dirname(__DIR__);
$DIR  = $ROOT . '/data/import/baseline';

// ── Parâmetros ──────────────────────────────────────────────────────────────
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $a, $m))   $opt[$m[1]] = '1';
}
function falha(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERRO: $msg\n");
    exit($code);
}
$piDesc    = trim($opt['pi'] ?? '');
$dryRun    = isset($opt['dry-run']);
$substituir = isset($opt['substituir']);
if ($piDesc === '') falha("--pi é obrigatório (ex.: --pi=\"PI 4/26\").\nUso: php bin/import_baseline.php --pi=\"PI 4/26\" [--arquivo=ARQ.xlsx] [--dry-run] [--substituir]");
if (!preg_match('#^PI\s*(\d+)/(\d{2})$#i', $piDesc, $mPi)) falha("PI '$piDesc' fora do formato 'PI N/AA' (ex.: PI 4/26).");
$piNum   = (int)$mPi[1];
$piAno   = $mPi[2];
$piSlug  = 'pi' . $piNum . '-' . $piAno;
// Tokens que identificam a PI nos campos da planilha.
$reFix    = '#(?<![\w/])PI\s*' . $piNum . '/' . $piAno . '(?!\d)#i';                // "PI 4/26"
$reSprint = '#(?<![\d.])' . $piAno . '\.' . $piNum . '(?!\d)#';                      // "26.4" em "SP 26.4.1", "PI 26.4"...

function out(string $l = ''): void { echo $l . "\n"; }

try {
    $pdo = Database::pdo();

    // PI alvo
    $st = $pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = ?');
    $st->execute([$piDesc]);
    $piId = $st->fetchColumn();
    if ($piId === false) falha("PI '$piDesc' não encontrada em configuracao_pi.");
    $piId = (int)$piId;

    // Já existe baseline desta PI? (aborta cedo, antes de gerar artefatos)
    $st = $pdo->prepare('SELECT COUNT(*) FROM baseline WHERE configuracao_pi_id = ?');
    $st->execute([$piId]);
    $existentes = (int)$st->fetchColumn();
    if ($existentes > 0 && !$substituir && !$dryRun) {
        Logger::warning(CANAL, 'import_baseline: abortado, baseline da PI já existe (imutável)', ['pi' => $piDesc, 'linhas_existentes' => $existentes]);
        falha("Já existem $existentes linhas de baseline para '$piDesc'. A baseline é um snapshot imutável por PI.\n"
            . "Nada foi alterado. Para sobrescrever, rode novamente com --substituir.", 3);
    }

    // Arquivo de origem
    if (!is_dir($DIR) && !@mkdir($DIR, 0775, true)) falha("Não consegui criar $DIR");
    if (!empty($opt['arquivo'])) {
        $arq = $opt['arquivo'];
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $arq)) $arq = $ROOT . '/' . $arq;
    } else {
        $xlsx = array_filter(glob($DIR . '/*.xlsx') ?: [], fn($f) => !str_starts_with(basename($f), '~$'));
        if (!$xlsx) {
            $orig = $ROOT . '/' . ARQ_ORIGEM_PADRAO;
            if (!is_file($orig)) falha("Nenhum .xlsx em data/import/baseline/ e '" . ARQ_ORIGEM_PADRAO . "' não existe na raiz.");
            $dest = $DIR . '/' . ARQ_ORIGEM_PADRAO;
            if (!copy($orig, $dest)) falha("Falha ao copiar a planilha para data/import/baseline/.");
            out("Planilha copiada (cópia, original mantido): data/import/baseline/" . ARQ_ORIGEM_PADRAO);
            $xlsx = [$dest];
        }
        usort($xlsx, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $arq = $xlsx[0];
    }
    if (!is_file($arq)) falha("Arquivo não encontrado: $arq");

    // Catálogo: projeto_key -> time/jornada (apenas times ATIVOS com projeto_key)
    $catalogo = [];
    $q = $pdo->query("SELECT t.id_time, t.nome AS time, t.projeto_key, j.nome AS jornada
                      FROM times t JOIN jornada j ON j.id_jornada = t.jornada_id
                      WHERE t.ativo = 'S' AND t.projeto_key IS NOT NULL AND t.projeto_key <> ''
                      ORDER BY t.id_time");
    foreach ($q->fetchAll() as $r) {
        $k = strtoupper($r['projeto_key']);
        if (!isset($catalogo[$k])) $catalogo[$k] = ['jornada' => $r['jornada'], 'time' => $r['time'], 'id_time' => (int)$r['id_time']];
    }

    // ── [1] Leitura + normalização ──────────────────────────────────────────
    $rows = xlsxReadSheet($arq, ABA);
    $erros = [];
    foreach (CABECALHO as $col => $esperado) {
        $lido = trim(preg_replace('/\s+/u', ' ', (string)($rows[1][$col] ?? '')));
        if (mb_strtolower($lido) !== mb_strtolower($esperado)) {
            $erros[] = "coluna $col: esperado '$esperado', encontrado '" . ($lido === '' ? '(vazio)' : $lido) . "'";
        }
    }
    if ($erros) {
        Logger::error(CANAL, 'import_baseline: cabeçalho divergente', ['arquivo' => basename($arq), 'divergencias' => count($erros)]);
        falha("Cabeçalho da aba '" . ABA . "' diverge do esperado:\n  - " . implode("\n  - ", $erros), 2);
    }

    $txt = fn($v) => ($t = trim((string)$v)) === '' ? null : $t;
    $itens = []; $rejeitados = []; $vistas = []; $projetosPorKey = [];
    $lidas = 0;
    foreach ($rows as $n => $r) {
        if ($n === 1) continue;
        if (trim(implode('', array_map('strval', $r))) === '') continue;   // linha totalmente vazia
        $lidas++;

        $it = [
            'linha_planilha'       => $n,
            'tipo_item'            => $txt($r['A'] ?? ''),
            'chave'                => $txt($r['B'] ?? ''),
            'resumo'               => $txt($r['C'] ?? ''),
            'projeto'              => $txt($r['D'] ?? ''),
            'status'               => $txt($r['E'] ?? ''),
            'versoes_corrigidas'   => $txt($r['F'] ?? ''),
            'pai'                  => $txt($r['G'] ?? ''),
            'story_point_estimate' => null,
            'story_points'         => null,
            'sprint'               => $txt($r['J'] ?? ''),
            'tipo_sustentacao'     => $txt($r['K'] ?? ''),
        ];
        $rej = function (string $motivo, array $extra = []) use (&$rejeitados, $it) {
            $rejeitados[] = ['motivo' => $motivo, 'linha_planilha' => $it['linha_planilha'], 'chave' => $it['chave'],
                             'projeto' => $it['projeto'], 'projeto_key' => $extra['projeto_key'] ?? null,
                             'versoes_corrigidas' => $it['versoes_corrigidas'], 'sprint' => $it['sprint']];
        };

        // PI alvo (a/b)
        $porFix    = $it['versoes_corrigidas'] !== null && preg_match($reFix, $it['versoes_corrigidas']);
        $porSprint = $it['sprint'] !== null && preg_match($reSprint, $it['sprint']);
        $pk = ($it['chave'] !== null && preg_match('/^([A-Za-z0-9]+)-\d+$/', $it['chave'], $mk)) ? strtoupper($mk[1]) : null;

        if (!$porFix && !$porSprint)                                   { $rej('fora da PI alvo (sem token da PI em Versões corrigidas nem em Sprint)', ['projeto_key' => $pk]); continue; }
        foreach (['tipo_item', 'chave', 'projeto'] as $c) {
            if ($it[$c] === null)                                      { $rej("campo obrigatório vazio ($c)", ['projeto_key' => $pk]); continue 2; }
        }
        if ($pk === null)                                              { $rej('chave fora do formato XXXX-N'); continue; }
        if (isset($vistas[$it['chave']]))                              { $rej('chave duplicada na planilha (mantida a 1ª ocorrência)', ['projeto_key' => $pk]); continue; }
        foreach (['H' => 'story_point_estimate', 'I' => 'story_points'] as $col => $campo) {
            $v = trim((string)($r[$col] ?? ''));
            if ($v === '') continue;
            if (!is_numeric($v))                                       { $rej("$campo não numérico", ['projeto_key' => $pk]); continue 2; }
            $it[$campo] = round((float)$v, 2);
        }
        foreach (LIMITES as $c => $max) {
            if ($it[$c] !== null && mb_strlen($it[$c]) > $max)         { $rej("campo $c excede $max caracteres", ['projeto_key' => $pk]); continue 2; }
        }
        if (!isset($catalogo[$pk]))                                    { $rej('projeto sem time ATIVO no catálogo (times.projeto_key)', ['projeto_key' => $pk]); continue; }

        $vistas[$it['chave']] = true;
        $projetosPorKey[$pk][$it['projeto']] = true;
        $it['projeto_key']  = $pk;
        $it['criterio_pi']  = $porFix && $porSprint ? 'ambos' : ($porFix ? 'fixversion' : 'sprint');
        $itens[] = $it;
    }

    // ── JSON auditável ──────────────────────────────────────────────────────
    $porMotivo = [];
    foreach ($rejeitados as $r) {
        $porMotivo[$r['motivo']]['total'] = ($porMotivo[$r['motivo']]['total'] ?? 0) + 1;
        $k = $r['projeto_key'] ?? '(?)';
        $porMotivo[$r['motivo']]['projeto_key'][$k] = ($porMotivo[$r['motivo']]['projeto_key'][$k] ?? 0) + 1;
    }
    $porCriterio = [];
    foreach ($itens as $i) $porCriterio[$i['criterio_pi']] = ($porCriterio[$i['criterio_pi']] ?? 0) + 1;

    $agora = new DateTimeImmutable('now');
    $jsonPath = $DIR . '/baseline_' . $piSlug . '_' . $agora->format('Ymd_His') . '.json';
    $json = [
        'metadados' => [
            'arquivo_origem'     => basename($arq),
            'sha1_origem'        => sha1_file($arq),
            'aba'                => ABA,
            'pi'                 => $piDesc,
            'configuracao_pi_id' => $piId,
            'gerado_em'          => $agora->format('Y-m-d H:i:s'),
            'criterio_pi'        => 'Versões corrigidas contém o token da PI OU Sprint contém o token da PI (ex.: 26.4)',
            'contagens'          => [
                'lidas' => $lidas, 'aceitas' => count($itens), 'rejeitadas' => count($rejeitados),
                'aceitas_por_criterio' => $porCriterio,
                'rejeitadas_por_motivo' => array_map(fn($x) => $x['total'], $porMotivo),
            ],
        ],
        'itens'      => $itens,
        'rejeitados' => $rejeitados,
    ];
    if (file_put_contents($jsonPath, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Falha ao gravar o JSON: ' . $jsonPath);
    }
    $jsonRel = 'data/import/baseline/' . basename($jsonPath);

    // ── [2] Relatório ───────────────────────────────────────────────────────
    out(str_repeat('=', 78));
    out('  IMPORTAÇÃO DA BASELINE — ' . $piDesc . ($dryRun ? '  [DRY-RUN: nada será gravado]' : ''));
    out(str_repeat('=', 78));
    out('  Arquivo : ' . basename($arq) . " (aba '" . ABA . "')");
    out('  JSON    : ' . $jsonRel);
    out("  PI      : $piDesc (configuracao_pi_id=$piId) | tokens: fixVersion 'PI $piNum/$piAno' ou sprint '$piAno.$piNum'");
    out('');
    out("  Total lido     : $lidas");
    out('  Aceitos        : ' . count($itens) . '  (' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($porCriterio), $porCriterio)) . ')');
    out('  Rejeitados     : ' . count($rejeitados));
    foreach ($porMotivo as $motivo => $d) {
        out("     - {$d['total']} × $motivo");
        arsort($d['projeto_key']);
        out('         projeto_key: ' . implode(', ', array_map(fn($k, $v) => "$k($v)", array_keys($d['projeto_key']), $d['projeto_key'])));
    }
    $multi = array_filter($projetosPorKey, fn($s) => count($s) > 1);
    foreach ($multi as $k => $s) out("  ⚠ projeto_key $k aparece com mais de um 'Projeto' na planilha: " . implode(' | ', array_keys($s)));

    // distribuição por jornada/time (catálogo)
    $dist = [];
    foreach ($itens as $i) {
        $c = $catalogo[$i['projeto_key']];
        $d = &$dist[$c['jornada']][$c['time'] . ' [' . $i['projeto_key'] . ']'];
        $d['n'] = ($d['n'] ?? 0) + 1;
        $d['est'] = ($d['est'] ?? 0) + (float)$i['story_point_estimate'];
        $d['sp']  = ($d['sp'] ?? 0) + (float)$i['story_points'];
        unset($d);
    }
    ksort($dist);
    out('');
    out('  DISTRIBUIÇÃO POR JORNADA / TIME (catálogo)');
    out(sprintf('     %-48s %7s %12s %10s', 'Jornada / Time [projeto_key]', 'itens', 'Σ estimate', 'Σ SP'));
    $gn = 0; $ge = 0.0; $gs = 0.0;
    foreach ($dist as $jor => $times) {
        ksort($times);
        $jn = array_sum(array_column($times, 'n')); $je = array_sum(array_column($times, 'est')); $js = array_sum(array_column($times, 'sp'));
        out(sprintf('     %-48s %7d %12s %10s', mb_substr($jor, 0, 48), $jn, $je + 0, $js + 0));
        foreach ($times as $t => $d) out(sprintf('        %-45s %7d %12s %10s', mb_substr($t, 0, 45), $d['n'], $d['est'] + 0, $d['sp'] + 0));
        $gn += $jn; $ge += $je; $gs += $js;
    }
    out(sprintf('     %-48s %7d %12s %10s', 'TOTAL aceito', $gn, $ge + 0, $gs + 0));
    out('');

    $resumoLog = [
        'pi' => $piDesc, 'arquivo' => basename($arq), 'json' => $jsonRel, 'lidas' => $lidas,
        'aceitas' => count($itens), 'rejeitadas' => count($rejeitados), 'dry_run' => $dryRun,
    ];
    foreach ($porMotivo as $motivo => $d) {
        Logger::warning(CANAL, 'import_baseline: linhas rejeitadas', [
            'pi' => $piDesc, 'motivo' => $motivo, 'total' => $d['total'], 'projeto_key' => $d['projeto_key'],
        ]);
    }

    if ($dryRun) {
        out("  [DRY-RUN] Nenhuma gravação no banco. Linhas atuais da PI em baseline: $existentes"
            . ($existentes > 0 ? " (a carga real exigiria --substituir)." : '.'));
        Logger::info(CANAL, 'import_baseline: dry-run concluído', $resumoLog);
        exit(0);
    }

    // ── [3] Carga transacional ──────────────────────────────────────────────
    $dataCarga = $agora->format('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        // Recontagem dentro da transação (evita corrida entre duas execuções).
        $st = $pdo->prepare('SELECT COUNT(*) FROM baseline WHERE configuracao_pi_id = ? FOR UPDATE');
        $st->execute([$piId]);
        $atuais = (int)$st->fetchColumn();
        if ($atuais > 0 && !$substituir) {
            $pdo->rollBack();
            Logger::warning(CANAL, 'import_baseline: abortado, baseline da PI já existe (imutável)', ['pi' => $piDesc, 'linhas_existentes' => $atuais]);
            falha("Já existem $atuais linhas de baseline para '$piDesc'. Nada foi alterado. Use --substituir para sobrescrever.", 3);
        }
        $apagadas = 0;
        if ($atuais > 0) {
            $del = $pdo->prepare('DELETE FROM baseline WHERE configuracao_pi_id = ?');
            $del->execute([$piId]);
            $apagadas = $del->rowCount();
        }
        $ins = $pdo->prepare(
            'INSERT INTO baseline
                (configuracao_pi_id, tipo_item, chave, resumo, projeto, status, versoes_corrigidas, pai,
                 story_point_estimate, story_points, sprint, sprint_ref, tipo_sustentacao, jornada_dona_epico, data_carga, ativo)
             VALUES
                (:pi, :tipo, :chave, :resumo, :projeto, :status, :vc, :pai, :est, :sp, :sprint, :sprint_ref, :tsus, NULL, :carga, :ativo)'
        );
        $gravadas = 0;
        foreach ($itens as $i) {
            $ins->execute([
                ':pi' => $piId, ':tipo' => $i['tipo_item'], ':chave' => $i['chave'], ':resumo' => $i['resumo'],
                ':projeto' => $i['projeto'], ':status' => $i['status'], ':vc' => $i['versoes_corrigidas'], ':pai' => $i['pai'],
                ':est' => $i['story_point_estimate'], ':sp' => $i['story_points'], ':sprint' => $i['sprint'], ':sprint_ref' => SprintRef::fromText($i['sprint']),
                ':tsus' => $i['tipo_sustentacao'], ':carga' => $dataCarga, ':ativo' => 'S',
            ]);
            $gravadas++;
        }
        if ($gravadas !== count($itens)) throw new RuntimeException("Gravadas $gravadas de " . count($itens) . ' linhas.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    out("  CARGA CONCLUÍDA: $gravadas linhas gravadas em baseline para '$piDesc'" . ($apagadas ? " ($apagadas linhas anteriores substituídas)." : '.'));
    Logger::info(CANAL, 'import_baseline: carga concluída', $resumoLog + ['gravadas' => $gravadas, 'substituidas' => $apagadas]);
    exit(0);
} catch (Throwable $e) {
    Logger::exception(CANAL, 'import_baseline: falha, rollback executado', $e, ['pi' => $piDesc]);
    fwrite(STDERR, 'ERRO na importação (rollback executado se havia transação): ' . $e->getMessage() . "\n");
    exit(4);
}
