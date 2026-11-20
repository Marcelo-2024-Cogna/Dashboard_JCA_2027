<?php
/**
 * bin/teste_efficiency.php — TESTES do Efficiency Baseline Ratio (Visão Geral)
 * ─────────────────────────────────────────────────────────────────────────────
 * Parte U  (sem banco): VisaoGeralCalculator::efficiencyBaseline com linhas
 *          sintéticas e consistência de config/baseline.php x db/views.sql.
 * Parte I  (banco local, SOMENTE SELECT): gadgets.efficiency_baseline contra SQL
 *          direta independente e contra gadgets.andamento.produtividade_ate_agora;
 *          SEM cache == MISS == HIT; com --referencia, o corpo novo sem
 *          gadgets.efficiency_baseline e sem metodologia == corpo capturado antes.
 *
 * Uso: php bin/teste_efficiency.php [--referencia=<pasta antes com vg_1..vg_22.json>] [--sem-banco]
 *      (vg_N.json: 1 = consolidado sprint, 2 = consolidado pi, depois cada jornada
 *       por ordem de nome, sprint e pi)
 * Saída 0 = tudo passou; 1 = alguma falha. Não vai para produção (bin/teste_*.php).
 * Compatível com PHP 7.4 e 8.x.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}
require_once __DIR__ . '/../app/compat.php';
define('BASE_DIR', dirname(__DIR__));
date_default_timezone_set((require BASE_DIR . '/config/app.php')['timezone'] ?? 'America/Sao_Paulo');
spl_autoload_register(function (string $class): void {
    $file = BASE_DIR . '/' . str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    if (is_file($file)) require_once $file;
});

use App\Controllers\GraficosController;
use App\Database;
use App\Repositories\PrevisibilidadeRepository;
use App\Services\VisaoGeralCalculator as C;
use App\Srv\srvRespostaCache;

// ── Infra de teste ──────────────────────────────────────────────────────────
$resultados = ['ok' => 0, 'falha' => 0];

function t(string $nome, callable $fn): void
{
    global $resultados;
    try {
        $fn();
        $resultados['ok']++;
        echo "  PASSOU  {$nome}\n";
    } catch (Throwable $e) {
        $resultados['falha']++;
        echo "  FALHOU  {$nome}\n          " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function igual($esperado, $real, string $msg = ''): void
{
    if ($esperado !== $real) {
        $e = is_string($esperado) && strlen($esperado) > 200 ? 'string(' . strlen($esperado) . ')' : var_export($esperado, true);
        $r = is_string($real) && strlen($real) > 200 ? 'string(' . strlen($real) . ')' : var_export($real, true);
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . $e . ', obtido ' . $r);
    }
}

function verdade($cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function proximo($esperado, $real, string $msg, float $tol = 0.011): void
{
    if ($esperado === null || $real === null) {
        igual($esperado, $real, $msg);
        return;
    }
    if (abs((float) $esperado - (float) $real) > $tol) {
        throw new RuntimeException($msg . ': esperado ' . $esperado . ', obtido ' . $real);
    }
}

$opts = getopt('', ['referencia::', 'sem-banco']);
$referencia = isset($opts['referencia']) && $opts['referencia'] !== false ? rtrim((string) $opts['referencia'], '/\\') : '';
$semBanco = array_key_exists('sem-banco', $opts);

echo 'PHP ' . PHP_VERSION . "\n";
if (getenv('VG_CACHE') !== false) {
    echo "Rode sem a variável VG_CACHE no ambiente.\n";
    exit(1);
}

// ── Linhas sintéticas ───────────────────────────────────────────────────────
function linha(string $key, ?float $h, ?float $sp, array $extra = []): array
{
    return $extra + [
        'chave' => $key . '-' . mt_rand(1, 999999), 'projeto_key' => $key, 'tipo_item' => 'História',
        'status' => 'Aceito', 'categoria' => 'valor', 'entregue' => 1,
        'sprint' => 'SP 1', 'sprint_ref' => 'SP 1',
        'story_points' => $sp, 'tempo_gasto_horas' => $h,
        'fim_homologacao' => '2026-01-10 10:00:00', 'fim_fluxo' => null,
    ];
}
$sprints = [
    ['nome' => 'SP 1', 'tipo' => 'valor', 'data_ini' => '2026-01-01', 'data_fim' => '2026-01-14', 'status' => 'fechada'],
    ['nome' => 'SP 2', 'tipo' => 'valor', 'data_ini' => '2026-01-15', 'data_fim' => '2026-01-28', 'status' => 'ativa'],
];
$nomes = ['AAA' => 'Time A', 'BBB' => 'Time B', 'CCC' => 'Time C'];
$ef = function (array $rows, array $base, array $refs = ['SP 1']) use ($sprints, $nomes): array {
    return C::efficiencyBaseline($rows, $refs, $sprints, $base, $nomes, 'sprint', $refs[0] ?? null);
};

echo "\n== Parte U: cálculo (sem banco) ==\n";

t('um time com baseline: h/SP, baseline, razão e variação', function () use ($ef) {
    $r = $ef([linha('AAA', 30, 5), linha('AAA', 30, 5)], ['AAA' => 5.0]);
    igual('ok', $r['estado']);
    igual(60.0, $r['horas']); igual(10.0, $r['pontos']); igual(6.0, $r['h_por_sp']); igual(2, $r['itens']);
    igual(5.0, $r['baseline_h_por_sp']); igual(120.0, $r['razao_pct']); igual(20.0, $r['variacao_pct']);
    igual(false, $r['parcial']);
    igual(1, count($r['por_time']));
    igual(120.0, $r['por_time'][0]['razao_pct']);
});

t('dois times: baseline é média ponderada por SP, não simples', function () use ($ef) {
    // A: 10 SP, baseline 4 -> esperado 40 h; B: 2 SP, baseline 10 -> esperado 20 h. Média simples = 7; ponderada = 60/12 = 5.
    $r = $ef([linha('AAA', 40, 10), linha('BBB', 20, 2)], ['AAA' => 4.0, 'BBB' => 10.0]);
    igual(5.0, $r['baseline_h_por_sp']);
    igual(100.0, $r['razao_pct']);
    igual(0.0, $r['variacao_pct']);
    igual('AAA', $r['por_time'][0]['projeto_key'], 'ordem por pontos desc');
});

t('time sem baseline fica fora do numerador e do denominador e é listado', function () use ($ef) {
    $r = $ef([linha('AAA', 50, 10), linha('BBB', 100, 4), linha('CCC', 10, 1)], ['AAA' => 5.0, 'CCC' => 0.0]);
    igual(5.0, $r['baseline_h_por_sp']);
    igual(100.0, $r['razao_pct'], 'só AAA entra: 50 / (10 x 5)');
    igual(5.0, $r['cobertura_baseline']['pontos_sem_baseline']);
    igual(10.0, $r['cobertura_baseline']['pontos_com_baseline']);
    igual(['Time B', 'Time C'], $r['cobertura_baseline']['times_sem_baseline']);
    igual(160.0, $r['horas'], 'horas totais continuam completas');
    igual(null, $r['por_time'][1]['baseline'], 'time sem baseline sem valor');
});

t('nenhum time com baseline: baseline, razão e variação nulos, sem erro', function () use ($ef) {
    $r = $ef([linha('AAA', 30, 5)], []);
    igual('ok', $r['estado']);
    igual(6.0, $r['h_por_sp']);
    igual(null, $r['baseline_h_por_sp']); igual(null, $r['razao_pct']); igual(null, $r['variacao_pct']);
});

t('item entregue sem SP: horas entram, sem_sp e alternativa corretos', function () use ($ef) {
    $r = $ef([linha('AAA', 40, 8), linha('AAA', 10, null)], ['AAA' => 5.0]);
    igual(50.0, $r['horas']); igual(8.0, $r['pontos']); igual(6.25, $r['h_por_sp']);
    igual(1, $r['sem_sp']['itens']); igual(10.0, $r['sem_sp']['horas']);
    igual(5.0, $r['sem_sp']['h_por_sp_sem_itens_sem_sp']);
});

t('Débito Técnico fica dentro do h/SP e é destacado', function () use ($ef) {
    $r = $ef([linha('AAA', 20, 4), linha('AAA', 10, 2, ['tipo_item' => 'Débito Técnico'])], ['AAA' => 5.0]);
    igual(30.0, $r['horas']); igual(6.0, $r['pontos']);
    igual(1, $r['debito_tecnico']['itens']); igual(2.0, $r['debito_tecnico']['pontos']); igual(10.0, $r['debito_tecnico']['horas']);
});

t('Sustentação, Suporte, Bug, Incidente e status Concluído ficam fora', function () use ($ef) {
    $rows = [
        linha('AAA', 10, 2),
        linha('AAA', 99, 9, ['categoria' => 'sustentacao', 'tipo_item' => 'Suporte']),
        linha('AAA', 99, 9, ['categoria' => 'sustentacao', 'tipo_item' => 'Sustentação']),
        linha('AAA', 99, 9, ['categoria' => null, 'tipo_item' => 'Bug']),
        linha('AAA', 99, 9, ['categoria' => null, 'tipo_item' => 'Incidente']),
        linha('AAA', 99, 9, ['status' => 'Concluído', 'entregue' => 0]),
        linha('AAA', 99, 9, ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2']),
    ];
    $r = $ef($rows, ['AAA' => 5.0]);
    igual(10.0, $r['horas']); igual(2.0, $r['pontos']); igual(1, $r['itens']);
});

t('item com duas sprints entra em multissprint', function () use ($ef) {
    $r = $ef([linha('AAA', 10, 2), linha('AAA', 30, 6, ['sprint' => 'SP 26.4.1, SP 26.4.2', 'sprint_ref' => 'SP 2'])], ['AAA' => 5.0], ['SP 1', 'SP 2']);
    igual(1, $r['multissprint']['itens']); igual(30.0, $r['multissprint']['horas']); igual(6.0, $r['multissprint']['pontos']);
    igual(true, $r['parcial'], 'SP 2 está ativa');
});

t('conclusão depois do fim da sprint fechada e sem data de conclusão', function () use ($ef) {
    $r = $ef([
        linha('AAA', 10, 2),
        linha('AAA', 10, 3, ['fim_homologacao' => '2026-01-20 09:00:00']),
        linha('AAA', 10, 4, ['fim_homologacao' => null, 'fim_fluxo' => '2026-01-14 23:00:00']),
        linha('AAA', 10, 1, ['fim_homologacao' => null, 'fim_fluxo' => null]),
    ], ['AAA' => 5.0]);
    igual(1, $r['concluidos_apos_fim_sprint']['itens']); igual(3.0, $r['concluidos_apos_fim_sprint']['pontos']);
    igual(1, $r['sem_data_conclusao']['itens']);
});

t('sprint ativa não conta como "após o fim"', function () use ($ef) {
    $r = $ef([linha('AAA', 10, 2, ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2', 'fim_homologacao' => '2026-02-20 09:00:00'])], ['AAA' => 5.0], ['SP 2']);
    igual(0, $r['concluidos_apos_fim_sprint']['itens']);
    igual(0, $r['sem_data_conclusao']['itens']);
});

t('janela sem entregues: estado vazio com motivo', function () use ($ef) {
    $r = $ef([linha('AAA', 10, 2, ['entregue' => 0])], ['AAA' => 5.0]);
    igual('vazio', $r['estado']);
    verdade(is_string($r['motivo']) && $r['motivo'] !== '', 'motivo');
    $r2 = $ef([], []);
    igual('vazio', $r2['estado']);
});

t('SP zero e horas nulas não geram erro de divisão', function () use ($ef) {
    $r = $ef([linha('AAA', null, 0.0), linha('BBB', 12, 0.0)], ['AAA' => 5.0, 'BBB' => 5.0]);
    igual('ok', $r['estado']);
    igual(null, $r['h_por_sp']);
    igual(null, $r['razao_pct']);
    igual(null, $r['por_time'][0]['h_por_sp']);
    $r2 = $ef([linha('AAA', null, 4.0)], ['AAA' => 5.0]);
    igual(0.0, $r2['horas']); igual(0.0, $r2['h_por_sp']); igual(0.0, $r2['razao_pct']);
});

t('config/baseline.php done_statuses == IN (...) de entregue em db/views.sql; Concluído fora', function () {
    $cfg = (require BASE_DIR . '/config/baseline.php')['done_statuses'];
    $sql = (string) file_get_contents(BASE_DIR . '/db/views.sql');
    verdade(preg_match("/entregue[^;]*?status\\s+IN\\s*\\(([^)]*)\\)/is", $sql, $m) === 1
        || preg_match("/status\\s+IN\\s*\\(([^)]*Aceito[^)]*)\\)/is", $sql, $m) === 1, 'lista IN não encontrada em views.sql');
    preg_match_all("/'([^']+)'/u", $m[1], $mm);
    $a = $cfg; $b = $mm[1]; sort($a); sort($b);
    igual($a, $b, 'listas');
    verdade(!in_array('Concluído', $cfg, true) && !in_array('Concluído', $b, true), 'Concluído não é pronto');
});

// ═══════════════════════════════════════════════════════════════════════════
if (!$semBanco) {
    echo "\n== Parte I: integração read-only (banco local) ==\n";
    putenv('VG_CACHE=0');
    $ctrl = new GraficosController(new PrevisibilidadeRepository());
    $pdo = Database::pdo();
    $obter = function (array $f) use ($ctrl): array {
        return json_decode($ctrl->obterVisaoGeral($f + ['time' => '', 'versao' => '', 'sprint' => ''])['corpo'], true);
    };

    $sqlDireta = function (array $p) use ($pdo): array {
        $refs = $p['gadgets']['efficiency_baseline']['sprints'];
        $keys = array_column($p['universo']['times'], 'projeto_key');
        $in = function (array $v, string $pre, array &$par): string {
            $o = [];
            foreach (array_values($v) as $i => $x) { $par[":$pre$i"] = $x; $o[] = ":$pre$i"; }
            return implode(',', $o);
        };
        $par = [':d' => $p['janela']['pi']];
        $s = $in($refs, 's', $par); $k = $in($keys, 'k', $par);
        $st = $pdo->prepare(
            "SELECT projeto_key, SUM(soma_tempo_gasto) / 3600 AS h, SUM(story_points) AS p
               FROM vw_item
              WHERE configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d)
                AND is_entrega_valor = 1 AND entregue = 1 AND sprint_ref IN ($s) AND projeto_key IN ($k)
              GROUP BY projeto_key"
        );
        $st->execute($par);
        $linhas = $st->fetchAll();
        $par2 = [];
        $k2 = $in($keys, 'k', $par2);
        $sb = $pdo->prepare(
            "SELECT t.projeto_key, tc.media_produtividade_pi AS ref
               FROM times t JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
              WHERE t.ativo = 'S' AND t.projeto_key IN ($k2)"
        );
        $sb->execute($par2);
        $base = [];
        foreach ($sb->fetchAll() as $r) {
            if ($r['ref'] !== null && (float) $r['ref'] > 0) $base[$r['projeto_key']] = (float) $r['ref'];
        }
        $h = 0.0; $pt = 0.0; $hb = 0.0; $esp = 0.0; $pb = 0.0;
        foreach ($linhas as $l) {
            $h += (float) $l['h']; $pt += (float) $l['p'];
            if (isset($base[$l['projeto_key']])) {
                $hb += (float) $l['h']; $pb += (float) $l['p']; $esp += (float) $l['p'] * $base[$l['projeto_key']];
            }
        }
        return ['h' => $h, 'p' => $pt, 'razao' => $esp > 0 ? $hb / $esp * 100 : null, 'base' => $pb > 0 && $esp > 0 ? $esp / $pb : null];
    };

    $casos = [
        ['Secretaria e Gestor de Unidades', 'sprint'], ['Secretaria e Gestor de Unidades', 'pi'],
        ['', 'sprint'], ['', 'pi'],
    ];
    foreach ($casos as $c) {
        $rot = ($c[0] === '' ? '(consolidado)' : $c[0]) . ' ' . $c[1];
        t("{$rot}: efficiency_baseline == SQL direta e == produtividade_ate_agora", function () use ($c, $obter, $sqlDireta) {
            $p = $obter(['jornada' => $c[0], 'modo' => $c[1]]);
            $e = $p['gadgets']['efficiency_baseline'];
            igual('exato', $e['classe']);
            verdade(isset($p['metodologia']['done_statuses']), 'metodologia.done_statuses');
            igual(['Aceito', 'Em produção', 'Pendente de Produção'], $p['metodologia']['done_statuses']);
            proximo(10, $e['aviso_multissprint_pct'], 'aviso_multissprint_pct', 0.0001);
            if ($e['estado'] !== 'ok') {
                echo "          (estado {$e['estado']}: " . ($e['motivo'] ?? '') . ")\n";
                return;
            }
            $s = $sqlDireta($p);
            proximo($s['h'], $e['horas'], 'horas SQL');
            proximo($s['p'], $e['pontos'], 'pontos SQL');
            proximo($s['p'] > 0 ? $s['h'] / $s['p'] : null, $e['h_por_sp'], 'h/SP SQL');
            proximo($s['base'], $e['baseline_h_por_sp'], 'baseline ponderado SQL');
            proximo($s['razao'], $e['razao_pct'], 'razão SQL', 0.06);
            $a = $p['gadgets']['andamento']['produtividade_ate_agora'] ?? null;
            if ($a !== null) {
                igual($a['horas'], $e['horas'], 'horas == produtividade_ate_agora');
                igual($a['pontos'], $e['pontos'], 'pontos == produtividade_ate_agora');
                igual($a['h_por_sp'], $e['h_por_sp'], 'h/SP == produtividade_ate_agora');
            }
            echo '          ' . $e['horas'] . ' h, ' . $e['pontos'] . ' SP, ' . $e['h_por_sp'] . ' h/SP, baseline '
                . var_export($e['baseline_h_por_sp'], true) . ', razão ' . var_export($e['razao_pct'], true) . "%\n";
        });
    }

    t('SEM cache == MISS == HIT (byte a byte) e corpo < 4 MiB', function () {
        $dir = sys_get_temp_dir() . '/eff_teste_' . getmypid() . '_' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        try {
            $f = ['jornada' => 'Secretaria e Gestor de Unidades', 'time' => '', 'versao' => '', 'sprint' => '', 'modo' => 'sprint'];
            $mk = function () use ($dir): GraficosController {
                return new GraficosController(new PrevisibilidadeRepository(), null, null, null, null, null, new srvRespostaCache($dir));
            };
            putenv('VG_CACHE=0');
            $sem = (new GraficosController(new PrevisibilidadeRepository()))->obterVisaoGeral($f);
            putenv('VG_CACHE');
            $miss = $mk()->obterVisaoGeral($f);
            $hit = $mk()->obterVisaoGeral($f);
            igual('MISS', $miss['estado']); igual('HIT', $hit['estado']);
            igual($sem['corpo'], $miss['corpo'], 'SEM vs MISS');
            igual($miss['corpo'], $hit['corpo'], 'MISS vs HIT');
            verdade(strlen($hit['corpo']) < 4194304, 'tamanho');
        } finally {
            putenv('VG_CACHE=0');
            foreach (glob($dir . '/*') ?: [] as $x) @unlink($x);
            @rmdir($dir);
        }
    });

    if ($referencia !== '') {
        $alvos = [['', 'sprint'], ['', 'pi']];
        $js = $pdo->query("SELECT nome FROM jornada WHERE ativo = 'S' ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($js as $j) {
            $alvos[] = [(string) $j, 'sprint'];
            $alvos[] = [(string) $j, 'pi'];
        }
        $norm = function (string $s): string {
            $s = preg_replace('/"defasagem_horas":(-?[0-9.eE+\-]+|null)/', '"defasagem_horas":"<RELOGIO>"', $s);
            return preg_replace('/A carga mais antiga do Jira tem -?[0-9.eE+\-]+ h de defasagem\./', 'A carga mais antiga do Jira tem <RELOGIO> h de defasagem.', $s);
        };
        foreach ($alvos as $i => $a) {
            $n = $i + 1;
            $rot = ($a[0] === '' ? '(consolidado)' : $a[0]) . ' ' . $a[1];
            t("referência vg_{$n} ({$rot}): corpo novo sem efficiency_baseline e metodologia == antes", function () use ($a, $n, $referencia, $ctrl, $norm) {
                $arq = $referencia . '/vg_' . $n . '.json';
                verdade(is_file($arq), 'referência ausente: ' . $arq);
                $novo = $ctrl->obterVisaoGeral(['jornada' => $a[0], 'time' => '', 'versao' => '', 'sprint' => '', 'modo' => $a[1]])['corpo'];
                $p = json_decode($novo, true);
                verdade(isset($p['gadgets']['efficiency_baseline']) && isset($p['metodologia']), 'blocos novos presentes');
                unset($p['gadgets']['efficiency_baseline'], $p['metodologia']);
                $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
                $ref = json_decode((string) file_get_contents($arq), true);
                igual($norm(json_encode($ref, $flags)), $norm(json_encode($p, $flags)), 'corpo');
                verdade(strlen($novo) < 4194304, 'tamanho < 4 MiB');
            });
        }
    }
}

echo "\n" . $resultados['ok'] . ' PASSOU, ' . $resultados['falha'] . " FALHOU\n";
exit($resultados['falha'] > 0 ? 1 : 0);
