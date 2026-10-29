<?php
/**
 * bin/ajustar_baseline.php — AJUSTE FORMAL da baseline, com trilha de auditoria
 * ────────────────────────────────────────────────────────────────────────────
 * A baseline é um snapshot imutável por PI. Correções passam por aqui, com
 * motivo e responsável obrigatórios; o trigger trg_baseline_audit_upd grava a
 * trilha em baseline_ajuste (origem 'formal'). UPDATE direto em SQL não é
 * bloqueado, mas é DETECTADO (origem 'sql_direto').
 *
 * Uso:
 *   Ajustar : php bin/ajustar_baseline.php --pi="PI 4/26" --chave=APAP-123 --campo=story_point_estimate \
 *                 --valor=5 --motivo="Estimativa corrigida após refinamento" --usuario=fulano
 *   Listar  : php bin/ajustar_baseline.php --listar [--pi="PI 4/26"] [--chave=APAP-123] [--origem=formal|sql_direto|regularizado] [--campo=...]
 *   Reverter: php bin/ajustar_baseline.php --reverter=ID --motivo="Ajuste feito por engano" --usuario=fulano
 *
 * Campos: story_point_estimate, story_points, sprint, status, tipo_item, pai
 *   (sprint recalcula sprint_ref; --valor= vazio limpa sprint/pai).
 *
 * Exit: 0 ok (inclui 'sem alteração') · 1 uso incorreto · 2 falha de validação · 4 falha de execução (rollback).
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';
require __DIR__ . '/../app/Services/SprintRef.php';
require __DIR__ . '/../app/Services/BaselineAjusteService.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Services\BaselineAjusteService;

const AJUDA = <<<'TXT'
Uso:
  Ajustar : php bin/ajustar_baseline.php --pi="PI 4/26" --chave=APAP-123 --campo=CAMPO --valor=VALOR --motivo="..." --usuario=NOME
  Listar  : php bin/ajustar_baseline.php --listar [--pi="PI 4/26"] [--chave=APAP-123] [--origem=formal|sql_direto|regularizado] [--campo=CAMPO]
  Reverter: php bin/ajustar_baseline.php --reverter=ID --motivo="..." --usuario=NOME

Campos permitidos: story_point_estimate, story_points, sprint, status, tipo_item, pai
Regras: motivo com no mínimo 10 caracteres e usuario obrigatórios; numéricos >= 0;
        o valor deve diferir do atual (senão: 'sem alteração'). Nada é apagado da trilha.
TXT;

function uso(string $msg): never
{
    fwrite(STDERR, "ERRO: $msg\n\n" . AJUDA . "\n");
    exit(1);
}

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $a, $m))   $opt[$m[1]] = '1';
    else uso("argumento não reconhecido: $a");
}
$conhecidas = ['pi', 'chave', 'campo', 'valor', 'motivo', 'usuario', 'listar', 'reverter', 'origem', 'ajuda', 'help'];
foreach (array_keys($opt) as $k) {
    if (!in_array($k, $conhecidas, true)) uso("opção desconhecida: --$k");
}
if (isset($opt['ajuda']) || isset($opt['help'])) { echo AJUDA . "\n"; exit(0); }

/** Corta/preenche respeitando UTF-8. */
function col(?string $s, int $w): string
{
    $s = $s === null ? '∅' : preg_replace('/\s+/u', ' ', $s);
    if (mb_strlen($s) > $w) $s = mb_substr($s, 0, $w - 1) . '…';
    return $s . str_repeat(' ', $w - mb_strlen($s));
}

try {
    $svc = new BaselineAjusteService();

    // ── Listar ──────────────────────────────────────────────────────────
    if (isset($opt['listar'])) {
        $rows = $svc->listar([
            'pi' => $opt['pi'] ?? '', 'chave' => $opt['chave'] ?? '',
            'origem' => $opt['origem'] ?? '', 'campo' => $opt['campo'] ?? '',
        ]);
        $cols = [['ID', 5], ['Quando', 19], ['PI', 8], ['Chave', 11], ['Campo', 20], ['Anterior', 10], ['Novo', 10], ['Origem', 12], ['Usuário', 18], ['Motivo', 60]];
        echo implode(' | ', array_map(fn($c) => col($c[0], $c[1]), $cols)) . "\n";
        echo implode('-+-', array_map(fn($c) => str_repeat('-', $c[1]), $cols)) . "\n";
        foreach ($rows as $r) {
            echo implode(' | ', [
                col((string)$r['id_ajuste'], 5), col($r['criado_em'], 19), col($r['pi'], 8), col($r['chave'], 11),
                col($r['campo'], 20), col($r['valor_anterior'], 10), col($r['valor_novo'], 10),
                col($r['origem'], 12), col($r['usuario'], 18), col($r['motivo'], 60),
            ]) . "\n";
        }
        echo "\n" . count($rows) . " registro(s).\n";
        exit(0);
    }

    // ── Reverter ────────────────────────────────────────────────────────
    if (isset($opt['reverter'])) {
        if (!ctype_digit($opt['reverter']) || (int)$opt['reverter'] < 1) uso("--reverter exige o ID numérico do ajuste.");
        if (!isset($opt['motivo']) || !isset($opt['usuario'])) uso('--reverter exige --motivo e --usuario.');
        $res = $svc->reverter((int)$opt['reverter'], $opt['motivo'], $opt['usuario']);
    } else {
        // ── Ajustar ─────────────────────────────────────────────────────
        $faltam = array_values(array_filter(['pi', 'chave', 'campo', 'valor', 'motivo', 'usuario'], fn($k) => !isset($opt[$k])));
        if ($faltam) uso('parâmetros obrigatórios ausentes: --' . implode(', --', $faltam));
        $res = $svc->ajustar($opt['pi'], $opt['chave'], $opt['campo'], $opt['valor'], $opt['motivo'], $opt['usuario']);
    }

    $fmt = fn($v) => $v === null ? '(vazio)' : (string)$v;
    echo ($res['alterado'] ? 'OK' : 'SEM ALTERAÇÃO') . ": {$res['mensagem']}\n";
    echo "  {$res['pi']} · {$res['chave']} · {$res['campo']}: {$fmt($res['valor_anterior'])} -> {$fmt($res['valor_novo'])}\n";
    foreach ($res['ajustes'] as $a) {
        echo "  trilha #{$a['id_ajuste']} [{$a['origem']}] {$a['campo']}: {$fmt($a['valor_anterior'])} -> {$fmt($a['valor_novo'])} · {$a['usuario']} · {$a['criado_em']}\n";
    }
    exit(0);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'ERRO de validação: ' . $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO na execução (rollback executado se havia transação): ' . $e->getMessage() . "\n");
    exit(4);
}
