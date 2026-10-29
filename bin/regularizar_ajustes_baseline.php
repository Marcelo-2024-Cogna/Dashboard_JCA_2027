<?php
/**
 * bin/regularizar_ajustes_baseline.php — REGULARIZA na trilha os UPDATEs diretos já feitos
 * ────────────────────────────────────────────────────────────────────────────
 * Em 28/10/2026 ~21:12 (fase de testes) a baseline foi editada por UPDATE direto,
 * antes de existir o trigger de auditoria. Este script compara o JSON de auditoria
 * da carga original (fonte da verdade do valor ANTERIOR) com a baseline atual nos
 * campos auditados e, para cada diferença, grava em baseline_ajuste uma linha com
 * origem 'regularizado'.
 *
 * NÃO altera a tabela baseline: só INSERT em baseline_ajuste (INSERT não aciona o
 * trigger de UPDATE). Antes/depois confere um hash da baseline e aborta se mudou.
 * IDEMPOTENTE: não duplica se já existe linha 'regularizado' para PI + chave + campo.
 *
 * Uso:
 *   php bin/regularizar_ajustes_baseline.php [--pi="PI 4/26"] [--json=CAMINHO.json] [--dry-run]
 *   (padrão: o JSON mais recente de data/import/baseline/ para a PI)
 *
 * Exit: 0 ok · 1 parâmetros/arquivo · 4 falha (rollback) ou baseline alterada.
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';
require __DIR__ . '/../app/Services/SprintRef.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;
use App\Services\SprintRef;

const CANAL   = 'importacao';
const USUARIO = 'marcelo.doliveira';
const MOTIVO  = 'Ajuste manual direto via SQL em 28/10/2026 ~21:12 durante teste; regularizado na trilha de auditoria (informado pelo usuário)';
const QUANDO  = '2026-10-28 21:12:18';
// Campos auditados (iguais aos do trigger). 'num' compara como decimal(10,2).
const CAMPOS  = ['story_point_estimate' => 'num', 'story_points' => 'num', 'sprint' => 'txt', 'sprint_ref' => 'txt',
                 'tipo_item' => 'txt', 'status' => 'txt', 'pai' => 'txt', 'projeto' => 'txt', 'ativo' => 'txt'];

function falha(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERRO: $msg\n");
    exit($code);
}

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $a, $m))   $opt[$m[1]] = '1';
    else falha("argumento não reconhecido: $a\nUso: php bin/regularizar_ajustes_baseline.php [--pi=\"PI 4/26\"] [--json=ARQ.json] [--dry-run]");
}
$piDesc = trim($opt['pi'] ?? 'PI 4/26');
$dryRun = isset($opt['dry-run']);

if (!preg_match('#^PI\s*(\d+)/(\d{2})$#i', $piDesc, $mPi)) falha("PI '$piDesc' fora do formato 'PI N/AA'.");
$dir = dirname(__DIR__) . '/data/import/baseline';
if (!empty($opt['json'])) {
    $arq = $opt['json'];
    if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $arq)) $arq = dirname(__DIR__) . '/' . $arq;
} else {
    $cand = glob($dir . '/baseline_pi' . (int)$mPi[1] . '-' . $mPi[2] . '_*.json') ?: [];
    if (!$cand) falha("Nenhum JSON de auditoria da carga para '$piDesc' em data/import/baseline/.");
    sort($cand);           // nome contém aaaammdd_hhmmss: o último é o mais recente
    $arq = end($cand);
}
if (!is_file($arq)) falha("JSON não encontrado: $arq");
$json = json_decode((string)file_get_contents($arq), true);
if (!is_array($json) || !isset($json['itens'])) falha("JSON inválido (sem 'itens'): $arq");

/** Valor no formato do trigger (CAST ... AS CHAR): decimais com 2 casas. */
function canon(string $tipo, mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    return $tipo === 'num' ? number_format((float)$v, 2, '.', '') : (string)$v;
}

try {
    $pdo = Database::pdo();
    $st = $pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = ?');
    $st->execute([$piDesc]);
    $piId = $st->fetchColumn();
    if ($piId === false) falha("PI '$piDesc' não encontrada em configuracao_pi.");
    $piId = (int)$piId;

    $hashBaseline = function () use ($pdo): string {
        $pdo->exec('SET SESSION group_concat_max_len = 67108864');
        return (string)$pdo->query(
            "SELECT CONCAT(COUNT(*), ':', MD5(GROUP_CONCAT(CONCAT_WS('|', id_baseline, configuracao_pi_id, tipo_item, chave, projeto, status, pai,
                    story_point_estimate, story_points, sprint, sprint_ref, ativo, resumo) ORDER BY id_baseline SEPARATOR '#'))) FROM baseline"
        )->fetchColumn();
    };
    $naoRegularizados = fn() => (int)$pdo->query("SELECT COUNT(*) FROM baseline_ajuste WHERE origem <> 'regularizado'")->fetchColumn();
    $hashAntes = $hashBaseline();
    $outrosAntes = $naoRegularizados();

    // Estado atual da baseline da PI, indexado por chave.
    $st = $pdo->prepare('SELECT * FROM baseline WHERE configuracao_pi_id = ?');
    $st->execute([$piId]);
    $atual = [];
    foreach ($st->fetchAll() as $r) $atual[$r['chave']] = $r;

    $ja = $pdo->prepare("SELECT COUNT(*) FROM baseline_ajuste WHERE origem = 'regularizado' AND configuracao_pi_id = ? AND chave = ? AND campo = ?");
    $ins = $pdo->prepare(
        'INSERT INTO baseline_ajuste (baseline_id, configuracao_pi_id, chave, campo, valor_anterior, valor_novo, motivo, usuario, origem, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $diffs = []; $semItem = 0; $existentes = 0;
    foreach ($json['itens'] as $it) {
        $b = $atual[$it['chave'] ?? ''] ?? null;
        if ($b === null) { $semItem++; continue; }
        $orig = $it + ['sprint_ref' => SprintRef::fromText($it['sprint'] ?? null), 'ativo' => 'S'];
        foreach (CAMPOS as $campo => $tipo) {
            $ant = canon($tipo, $orig[$campo] ?? null);
            $novo = canon($tipo, $b[$campo]);
            if ($ant === $novo) continue;
            $ja->execute([$piId, $b['chave'], $campo]);
            if ((int)$ja->fetchColumn() > 0) { $existentes++; continue; }
            $diffs[] = [(int)$b['id_baseline'], $piId, $b['chave'], $campo, $ant, $novo];
        }
    }

    echo "JSON de auditoria : " . basename($arq) . "\n";
    echo "Itens no JSON     : " . count($json['itens']) . " (ausentes na baseline atual: $semItem)\n";
    echo "Diferenças novas  : " . count($diffs) . " | já regularizadas (ignoradas): $existentes" . ($dryRun ? "  [DRY-RUN]\n" : "\n");

    if (!$dryRun && $diffs) {
        $pdo->beginTransaction();
        try {
            foreach ($diffs as [$bid, $pid, $chave, $campo, $ant, $novo]) {
                $ins->execute([$bid, $pid, $chave, $campo, $ant, $novo, MOTIVO, USUARIO, 'regularizado', QUANDO]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    foreach ($diffs as [, , $chave, $campo, $ant, $novo]) {
        echo sprintf("  %-12s %-22s %s -> %s\n", $chave, $campo, $ant ?? '(vazio)', $novo ?? '(vazio)');
    }

    // Garantias: baseline intacta e nenhum trigger disparado (só linhas 'regularizado' novas).
    $hashDepois = $hashBaseline();
    if ($hashDepois !== $hashAntes) {
        Logger::error(CANAL, 'regularizar_ajustes_baseline: baseline foi alterada (inesperado)', ['antes' => $hashAntes, 'depois' => $hashDepois]);
        falha('a tabela baseline mudou durante a regularização (inesperado). Investigue.', 4);
    }
    if ($naoRegularizados() !== $outrosAntes) {
        Logger::error(CANAL, 'regularizar_ajustes_baseline: surgiram linhas de auditoria não regularizadas (trigger disparou?)');
        falha('surgiram linhas de auditoria que não são "regularizado" (trigger disparou?). Investigue.', 4);
    }
    echo "Baseline intacta ($hashDepois).\n";

    Logger::info(CANAL, 'regularizar_ajustes_baseline: concluído', [
        'pi' => $piDesc, 'json' => basename($arq), 'inseridas' => $dryRun ? 0 : count($diffs),
        'ignoradas_ja_regularizadas' => $existentes, 'dry_run' => $dryRun,
    ]);
    exit(0);
} catch (Throwable $e) {
    Logger::exception(CANAL, 'regularizar_ajustes_baseline: falha', $e, ['pi' => $piDesc]);
    fwrite(STDERR, 'ERRO na regularização: ' . $e->getMessage() . "\n");
    exit(4);
}
