<?php
/**
 * bin/teste_debt_load.php — TESTES do Debt Load Ratio (Visão Geral)
 * ─────────────────────────────────────────────────────────────────────────────
 * Parte U  (sem banco): VisaoGeralCalculator::debtLoadUnidade / debtLoad com linhas
 *          sintéticas e efficiencyBaseline nos dois modos (com e sem Débito Técnico).
 * Parte I  (banco local, SOMENTE SELECT): gadgets.debt_load contra SQL direta independente
 *          em vw_item (capacidade do Painel x dias úteis x 80% ÷ media_produtividade_pi),
 *          mesmo denominador do Fator de Carga, efficiency_comparativo x efficiency_baseline,
 *          repositório com excluir_debito_tecnico=true, SEM cache == MISS == HIT, e as
 *          tabelas antes x depois do Efficiency e do impacto no Fator de Carga.
 * Parte R  (--referencia=<pasta antes>): o corpo novo sem gadgets.debt_load e sem
 *          metodologia.debt_load == corpo capturado antes (visao-geral__*.json).
 *
 * Uso: php bin/teste_debt_load.php [--referencia=.agents\tasks\fase3-debt-load\antes] [--sem-banco]
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
use App\Repositories\VisaoGeralRepository;
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

function removerPasta(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $x) {
        is_dir($x) ? removerPasta($x) : @unlink($x);
    }
    @rmdir($dir);
}

$opts = getopt('', ['referencia::', 'sem-banco']);
$referencia = isset($opts['referencia']) && $opts['referencia'] !== false ? rtrim((string) $opts['referencia'], '/\\') : '';
$semBanco = array_key_exists('sem-banco', $opts);

echo 'PHP ' . PHP_VERSION . "\n";
if (getenv('VG_CACHE') !== false) {
    echo "Rode sem a variável VG_CACHE no ambiente.\n";
    exit(1);
}

// ── Dados sintéticos ────────────────────────────────────────────────────────
function lin(string $key, ?float $sp, string $tipo = 'Débito Técnico', array $extra = []): array
{
    return $extra + [
        'chave' => $key . '-' . mt_rand(1, 999999), 'projeto_key' => $key, 'tipo_item' => $tipo,
        'status' => 'Em Andamento', 'categoria' => $tipo === 'Débito Técnico' ? 'valor' : 'sustentacao', 'entregue' => 0,
        'sprint' => 'SP 1', 'sprint_ref' => 'SP 1', 'story_points' => $sp, 'spe' => $sp,
        'tempo_gasto_horas' => 0.0,
    ];
}
$sprints = [
    ['nome' => 'SP 1', 'tipo' => 'valor', 'data_ini' => '2026-01-01', 'data_fim' => '2026-01-14', 'status' => 'fechada'],
    ['nome' => 'SP 2', 'tipo' => 'valor', 'data_ini' => '2026-01-15', 'data_fim' => '2026-01-28', 'status' => 'ativa'],
    ['nome' => 'SPS', 'tipo' => 'sustentacao', 'data_ini' => '2026-01-29', 'data_fim' => '2026-02-04', 'status' => 'futura'],
];
$dias = ['SP 1' => 8, 'SP 2' => 7, 'SPS' => 6];
$cap = [
    'AAA' => ['dev_count' => 7, 'productive_hours' => 6, 'productivity' => 6],
    'BBB' => ['dev_count' => 4, 'productive_hours' => 7, 'productivity' => 7],
    'DDD' => ['dev_count' => 5, 'productive_hours' => 7, 'productivity' => 7],
    'EEE' => ['dev_count' => null, 'productive_hours' => 7, 'productivity' => 7],
];
$bases = ['AAA' => 7.0, 'BBB' => 5.0, 'CCC' => 6.0, 'EEE' => 6.0];
$nomes = ['AAA' => 'Time A', 'BBB' => 'Time B', 'CCC' => 'Time C', 'DDD' => 'Time D', 'EEE' => 'Time E'];
$cfgBase = ['disponibilidade_pct' => 80, 'tipos' => ['Débito Técnico', 'Suporte'], 'cancelado' => ['Cancelado']];

$dl = function (array $rows, array $keys, array $refs = ['SP 1'], array $over = []) use ($sprints, $dias, $cap, $bases, $nomes, $cfgBase): array {
    $d = $over['dias'] ?? $dias;
    $c = $over['cap'] ?? $cap;
    $b = $over['bases'] ?? $bases;
    $cfg = ($over['cfg'] ?? []) + $cfgBase;
    return C::debtLoad($rows, $refs, $sprints, $d, $c, $b, $nomes, $keys, $cfg, 'sprint', $refs[0] ?? null);
};

echo "\n== Parte U: cálculo (sem banco) ==\n";

t('exemplo de referência: 7 devs, 6 h, 8 dias, 80%, baseline 7, 5 SP DT + 3 SP Suporte => 268,8 h, 38,4 SP, 20,8%', function () use ($dl) {
    $u = C::debtLoadUnidade(7, 6, 8, 80, 8, 7);
    igual(336.0, $u['bruta_h']); igual(268.8, $u['liquida_h']); igual(38.4, $u['capacidade_sp']);
    igual(8.0, $u['sp_divida']); igual(20.8, $u['dl_pct']);
    igual(null, C::debtLoadUnidade(7, 6, 0, 80, 8, 7)['dl_pct'], 'líquida 0 => null');
    $r = $dl([lin('AAA', 5), lin('AAA', 3, 'Suporte')], ['AAA']);
    igual('ok', $r['estado']); igual('exato', $r['classe']);
    igual(268.8, $r['liquida_h']); igual(38.4, $r['capacidade_sp']); igual(8.0, $r['sp_divida']); igual(20.8, $r['dl_pct']);
    igual(null, $r['faixa'], 'faixa sempre null');
    igual(80.0, $r['disponibilidade_pct']); igual(['Débito Técnico', 'Suporte'], $r['tipos']);
    igual(false, $r['parcial']); igual(['SP 1'], $r['sprints']);
    igual(1, count($r['por_time']));
    $pt = $r['por_time'][0];
    igual(20.8, $pt['dl_pct']); igual(5.0, $pt['sp_debito_tecnico']); igual(3.0, $pt['sp_suporte']);
    igual(7.0, $pt['baseline_h_sp']); igual(7.0, $pt['devs']); igual(6.0, $pt['horas_dia']);
    igual(1, count($r['por_sprint'])); igual('SP 1', $r['por_sprint'][0]['sprint']); igual(8.0, $r['por_sprint'][0]['dias_uteis']);
    igual(20.8, $r['por_sprint'][0]['dl_pct']);
    igual([['tipo' => 'Débito Técnico', 'itens' => 1, 'sp' => 5.0, 'sp_entregue' => 0.0],
           ['tipo' => 'Suporte', 'itens' => 1, 'sp' => 3.0, 'sp_entregue' => 0.0]], $r['decomposicao']['por_tipo']);
    igual(8.0, $r['sp_spe']);
    igual(20.8, $r['dl_horas_pct'], 'SP x baseline ÷ líquida = mesma razão no nível do time');
});

t('consolidado soma SP e capacidade; não é a média dos percentuais', function () use ($dl) {
    // A: 8 SP / 38,4 = 20,8%. B: 4 SP / (179,2 h ÷ 5 = 35,84) = 11,2%. Soma: 12 / 74,24 = 16,2%; média simples = 16,0%.
    $r = $dl([lin('AAA', 8), lin('BBB', 4)], ['AAA', 'BBB']);
    igual(74.24, $r['capacidade_sp']); igual(12.0, $r['sp_divida']); igual(16.2, $r['dl_pct']);
    verdade($r['dl_pct'] !== round((20.8 + 11.2) / 2, 1), 'não é média de percentuais');
    igual(['AAA', 'BBB'], array_column($r['por_time'], 'projeto_key'), 'ordem por dl_pct desc');
    // time sem item entra no denominador com 0 SP
    $r2 = $dl([lin('AAA', 8)], ['AAA', 'BBB']);
    igual(74.24, $r2['capacidade_sp']); igual(10.8, $r2['dl_pct']); igual(2, $r2['cobertura']['times_com_dados']);
    // desempate por projeto_key
    $r3 = $dl([], ['BBB', 'AAA']);
    igual(['AAA', 'BBB'], array_column($r3['por_time'], 'projeto_key')); igual(0.0, $r3['dl_pct']);
});

t('regra de tipos: só Débito Técnico e Suporte; Cancelado, outra sprint e sprint nula ficam fora', function () use ($dl) {
    $rows = [
        lin('AAA', 5),
        lin('AAA', 3, 'Suporte'),
        lin('AAA', 9, 'Incidente', ['categoria' => null]),
        lin('AAA', 9, 'Bug', ['categoria' => null]),
        lin('AAA', 9, 'SS', ['categoria' => null]),
        lin('AAA', 9, 'História', ['categoria' => 'valor']),
        lin('AAA', 7, 'Sustentação', ['categoria' => 'sustentacao']),
        lin('AAA', 9, 'Débito Técnico', ['status' => 'Cancelado']),
        lin('AAA', 9, 'Suporte', ['status' => 'Cancelado']),
        lin('AAA', 9, 'Débito Técnico', ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2']),
        lin('AAA', 9, 'Suporte', ['sprint_ref' => null]),
        lin('AAA', 2, 'Suporte', ['categoria' => 'valor', 'status' => 'Concluído']),
    ];
    $r = $dl($rows, ['AAA']);
    igual(10.0, $r['sp_divida'], '5 DT + 3 Suporte + 2 Suporte (categoria valor; todo status não cancelado conta)');
    igual(26.0, $r['dl_pct']);
    igual([1, 2], array_column($r['decomposicao']['por_tipo'], 'itens'));
    igual([[ 'tipo' => 'Sustentação', 'itens' => 1, 'sp' => 7.0 ]], $r['fora_do_indicador'], 'só sustentação fora; Incidente/Bug/SS sem categoria não aparecem');
    // tipos vêm do cfg
    $so = $dl($rows, ['AAA'], ['SP 1'], ['cfg' => ['tipos' => ['Suporte']]]);
    igual(5.0, $so['sp_divida']); igual(['Suporte'], $so['tipos']);
});

t('item sem SP conta 0 e vai a diagnosticos.itens_sem_sp', function () use ($dl) {
    $r = $dl([lin('AAA', 8), lin('AAA', null, 'Suporte', ['tempo_gasto_horas' => 3.5]), lin('AAA', null, 'Suporte', ['tempo_gasto_horas' => 1.0])], ['AAA']);
    igual(8.0, $r['sp_divida']); igual(20.8, $r['dl_pct']);
    igual(['itens' => 2, 'horas' => 4.5], $r['diagnosticos']['itens_sem_sp']);
    igual(2, $r['decomposicao']['por_tipo'][1]['itens']);
});

t('time sem Painel ou sem baseline fica fora e é listado, nunca com dl 0', function () use ($dl) {
    // CCC: sem Painel (6 SP); DDD: com Painel e sem baseline (4 SP); EEE: dev_count nulo (2 SP).
    $r = $dl([lin('AAA', 8), lin('CCC', 6), lin('DDD', 4, 'Suporte'), lin('EEE', 2)], ['AAA', 'CCC', 'DDD', 'EEE']);
    igual('ok', $r['estado']);
    igual(38.4, $r['capacidade_sp'], 'só AAA no denominador'); igual(8.0, $r['sp_divida']); igual(20.8, $r['dl_pct']);
    igual(1, $r['cobertura']['times_com_dados']); igual(4, $r['cobertura']['times_total']);
    $m = [];
    foreach ($r['cobertura']['times_sem_dados'] as $s) { $m[$s['projeto_key']] = $s; }
    igual(['CCC', 'DDD', 'EEE'], array_keys($m));
    igual('sem_painel', $m['CCC']['motivo']); igual(6.0, $m['CCC']['sp_divida']);
    igual('sem_baseline', $m['DDD']['motivo']); igual(4.0, $m['DDD']['sp_divida']);
    igual('sem_painel', $m['EEE']['motivo']);
    igual(['AAA'], array_column($r['por_time'], 'projeto_key'));
});

t('nenhum time com dados: estado vazio e dl_pct null (nunca 0)', function () use ($dl) {
    $r = $dl([lin('CCC', 6)], ['CCC']);
    igual('vazio', $r['estado']); igual(null, $r['dl_pct']); igual(null, $r['faixa']);
    verdade(is_string($r['motivo']) && $r['motivo'] !== '', 'motivo');
    igual(0, $r['cobertura']['times_com_dados']);
    igual('sem_painel', $r['cobertura']['times_sem_dados'][0]['motivo']);
    $r2 = $dl([], [], ['SP 1']);
    igual('vazio', $r2['estado']); igual(null, $r2['dl_pct']);
});

t('sprint de sustentação e sprint sem dias úteis ficam fora', function () use ($dl) {
    $r = $dl([lin('AAA', 8), lin('AAA', 50, 'Suporte', ['sprint_ref' => 'SPS'])], ['AAA'], ['SP 1', 'SPS']);
    igual(['SP 1'], $r['sprints']); igual(20.8, $r['dl_pct']);
    igual(['SPS'], $r['diagnosticos']['sprints_sustentacao_ignoradas']);
    $so = $dl([lin('AAA', 50, 'Suporte', ['sprint_ref' => 'SPS'])], ['AAA'], ['SPS']);
    igual('vazio', $so['estado']); igual(null, $so['dl_pct']);
    verdade(strpos($so['motivo'], 'sustentação') !== false, 'motivo cita sustentação');

    $rows = [lin('AAA', 8), lin('AAA', 7, 'Suporte', ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2'])];
    $r1 = $dl($rows, ['AAA'], ['SP 1', 'SP 2'], ['dias' => ['SP 1' => null, 'SP 2' => 7]]);
    igual(['SP 1'], $r1['diagnosticos']['sprints_sem_dias_uteis']); igual(['SP 2'], $r1['sprints']);
    igual(7.0, $r1['sp_divida']);
    $r0 = $dl($rows, ['AAA'], ['SP 1'], ['dias' => ['SP 1' => 0]]);
    igual('vazio', $r0['estado']); igual(null, $r0['dl_pct']); igual(['SP 1'], $r0['diagnosticos']['sprints_sem_dias_uteis']);
});

t('sprint ativa marca parcial; fechada não', function () use ($dl) {
    $a = $dl([lin('AAA', 8, 'Suporte', ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2'])], ['AAA'], ['SP 2']);
    igual(true, $a['parcial']); igual('ativa', $a['por_sprint'][0]['status']); igual(7.0, $a['por_sprint'][0]['dias_uteis']);
    $f = $dl([lin('AAA', 8)], ['AAA'], ['SP 1']);
    igual(false, $f['parcial']); igual('fechada', $f['por_sprint'][0]['status']);
});

t('sp_entregue só com entregue=1; dl_entregue_pct sobre a mesma capacidade; sp_spe é o SPE', function () use ($dl) {
    $r = $dl([lin('AAA', 5, 'Débito Técnico', ['entregue' => 1, 'spe' => 8.0]), lin('AAA', 3, 'Suporte', ['entregue' => 0, 'spe' => 1.0])], ['AAA']);
    igual(5.0, $r['sp_entregue']); igual(13.0, $r['dl_entregue_pct']);
    igual(9.0, $r['sp_spe']); igual(5.0, $r['decomposicao']['por_tipo'][0]['sp_entregue']); igual(0.0, $r['decomposicao']['por_tipo'][1]['sp_entregue']);
    igual(5.0, $r['por_time'][0]['sp_entregue']);
});

t('dl_horas_pct pondera pelo baseline no agregado (diagnóstico) e difere do DL em SP', function () use ($dl) {
    // A: 8 SP x 7 = 56 h; B: 4 SP x 5 = 20 h; líquida 268,8 + 179,2 = 448 h => 17,0%. Em SP: 16,2%.
    $r = $dl([lin('AAA', 8), lin('BBB', 4)], ['AAA', 'BBB']);
    igual(17.0, $r['dl_horas_pct']); igual(16.2, $r['dl_pct']);
});

// Efficiency nos dois modos: DT 2 SP / 14 h, História 4 SP / 12 h, baseline 7 h/SP.
$eff = function (bool $excluir) use ($sprints, $bases, $nomes): array {
    $rows = [
        lin('AAA', 2.0, 'Débito Técnico', ['entregue' => 1, 'tempo_gasto_horas' => 14.0]),
        lin('AAA', 4.0, 'História', ['entregue' => 1, 'tempo_gasto_horas' => 12.0, 'categoria' => 'valor']),
    ];
    return C::efficiencyBaseline($rows, ['SP 1'], $sprints, $bases, $nomes, 'sprint', 'SP 1', $excluir);
};

t('Efficiency com Débito Técnico (padrão): 26 h ÷ 6 SP = 4,33; razão 61,9%', function () use ($eff, $sprints, $bases, $nomes) {
    $a = $eff(false);
    igual(26.0, $a['horas']); igual(6.0, $a['pontos']); igual(4.33, $a['h_por_sp']); igual(2, $a['itens']);
    igual(7.0, $a['baseline_h_por_sp']); igual(61.9, $a['razao_pct']);
    igual(['itens' => 1, 'pontos' => 2.0, 'horas' => 14.0], $a['debito_tecnico']);
    // omitir o parâmetro == false
    $rows = [lin('AAA', 2.0, 'Débito Técnico', ['entregue' => 1, 'tempo_gasto_horas' => 14.0])];
    igual(C::efficiencyBaseline($rows, ['SP 1'], $sprints, $bases, $nomes, 'sprint', 'SP 1'),
          C::efficiencyBaseline($rows, ['SP 1'], $sprints, $bases, $nomes, 'sprint', 'SP 1', false));
});

t('Efficiency sem Débito Técnico: 12 h ÷ 4 SP = 3,00; razão 42,9%; bloco debito_tecnico segue contado', function () use ($eff) {
    $a = $eff(true);
    igual(12.0, $a['horas']); igual(4.0, $a['pontos']); igual(3.0, $a['h_por_sp']); igual(1, $a['itens']);
    igual(7.0, $a['baseline_h_por_sp']); igual(42.9, $a['razao_pct']);
    igual(['itens' => 1, 'pontos' => 2.0, 'horas' => 14.0], $a['debito_tecnico']);
    igual(4.0, $a['por_time'][0]['pontos']); igual(1, $a['por_time'][0]['itens']);
});

t('Efficiency: só Débito Técnico entregue e excluir => vazio', function () use ($sprints, $bases, $nomes) {
    $rows = [lin('AAA', 2.0, 'Débito Técnico', ['entregue' => 1, 'tempo_gasto_horas' => 14.0])];
    $a = C::efficiencyBaseline($rows, ['SP 1'], $sprints, $bases, $nomes, 'sprint', 'SP 1', true);
    igual('vazio', $a['estado']);
});

// ═══════════════════════════════════════════════════════════════════════════
if (!$semBanco) {
    echo "\n== Parte I: integração read-only (banco local) ==\n";
    putenv('VG_CACHE=0');
    $ctrl = new GraficosController(new PrevisibilidadeRepository());
    $pdo = Database::pdo();
    $obter = function (array $f) use ($ctrl): array {
        return json_decode($ctrl->obterVisaoGeral($f + ['jornada' => '', 'time' => '', 'versao' => '', 'sprint' => ''])['corpo'], true);
    };
    $arqPainel = BASE_DIR . '/data/config/capacity.json';
    $tiposDivida = ['Débito Técnico', 'Suporte'];

    $inList = function (array $v, string $pre, array &$par): string {
        $o = [];
        foreach (array_values($v) as $i => $x) { $par[":$pre$i"] = $x; $o[] = ":$pre$i"; }
        return $o === [] ? "''" : implode(',', $o);
    };

    // SQL independente (vw_item): recalcula o Debt Load sem o repositório.
    $sqlDireta = function (array $p) use ($pdo, $inList, $arqPainel, $tiposDivida): array {
        $desc = $p['janela']['pi'];
        $keys = array_column($p['universo']['times'], 'projeto_key');
        $hoje = $p['janela']['hoje'];
        if (($p['filtros']['sprint'] ?? '') !== '') {
            $st = $pdo->prepare("SELECT nome, tipo, dias_uteis FROM sprints WHERE configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d) AND ativo = 'S' AND nome = :n");
            $st->execute([':d' => $desc, ':n' => (string) $p['filtros']['sprint']]);
        } else {
            $st = $pdo->prepare("SELECT nome, tipo, dias_uteis FROM sprints WHERE configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d) AND ativo = 'S' AND tipo = 'valor' AND data_ini <= :h");
            $st->execute([':d' => $desc, ':h' => $hoje]);
        }
        $dias = [];
        foreach ($st->fetchAll() as $s) {
            if ($s['tipo'] === 'valor' && $s['dias_uteis'] !== null && (float) $s['dias_uteis'] > 0) {
                $dias[$s['nome']] = (float) $s['dias_uteis'];
            }
        }
        $par2 = [];
        $k2 = $inList($keys, 'k', $par2);
        $sb = $pdo->prepare(
            "SELECT t.projeto_key, tc.media_produtividade_pi AS ref
               FROM times t JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
              WHERE t.ativo = 'S' AND t.projeto_key IN ($k2) ORDER BY tc.id_time_composicao"
        );
        $sb->execute($par2);
        $base = [];
        foreach ($sb->fetchAll() as $r) {
            if ($r['ref'] !== null) $base[$r['projeto_key']] = (float) $r['ref'];
        }
        $teams = (json_decode((string) file_get_contents($arqPainel), true))['teams'] ?? [];

        $par3 = [':d' => $desc];
        $s3 = $inList(array_keys($dias), 's', $par3);
        $k3 = $inList($keys, 'k', $par3);
        $t3 = $inList($tiposDivida, 't', $par3);
        $sq = $pdo->prepare(
            "SELECT i.projeto_key, i.sprint_ref, SUM(COALESCE(i.story_points, 0)) AS sp, COUNT(*) AS n,
                    SUM(CASE WHEN i.story_points IS NULL THEN 1 ELSE 0 END) AS n_sem_sp
               FROM vw_item i
              WHERE i.configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d)
                AND COALESCE(i.status, '') <> 'Cancelado' AND i.tipo_item IN ($t3)
                AND i.sprint_ref IN ($s3) AND i.projeto_key IN ($k3)
              GROUP BY i.projeto_key, i.sprint_ref"
        );
        $sq->execute($par3);
        $sp = [];
        $semSp = 0;
        foreach ($sq->fetchAll() as $r) {
            $sp[$r['projeto_key']][$r['sprint_ref']] = (float) $r['sp'];
            $semSp += (int) $r['n_sem_sp'];
        }
        $liq = 0.0; $capSp = 0.0; $spDiv = 0.0; $com = 0; $sem = [];
        foreach ($keys as $k) {
            $c = $teams[$k] ?? ($teams[strtoupper($k)] ?? null);
            $dev = is_array($c) ? ($c['dev_count'] ?? null) : null;
            $hh = is_array($c) ? ($c['productive_hours'] ?? null) : null;
            $b = $base[$k] ?? null;
            if ($dev === null || $hh === null || (float) $dev <= 0 || (float) $hh <= 0 || $b === null || (float) $b <= 0) {
                $sem[] = $k;
                continue;
            }
            $com++;
            foreach ($dias as $n => $d) {
                $l = (float) $dev * (float) $hh * $d * 0.8;
                $liq += $l; $capSp += $l / (float) $b;
                $spDiv += $sp[$k][$n] ?? 0.0;
            }
        }
        return [
            'liquida' => $liq, 'cap' => $capSp, 'sp' => $spDiv,
            'dl' => $capSp > 0 ? $spDiv / $capSp * 100 : null,
            'com' => $com, 'sem' => $sem, 'sprints' => array_keys($dias),
        ];
    };

    $casos = [];
    foreach (['Secretaria e Gestor de Unidades', ''] as $j) {
        foreach ([['SP 26.4.3', 'sprint'], ['SP 26.4.4', 'sprint'], ['', 'pi']] as $s) {
            $casos[] = [$j, $s[0], $s[1]];
        }
    }
    verdade(is_file($arqPainel), 'data/config/capacity.json local ausente (a SQL direta lê o Painel)');

    foreach ($casos as $c) {
        $rot = ($c[0] === '' ? '(consolidado)' : $c[0]) . ' ' . ($c[1] !== '' ? $c[1] : 'modo=' . $c[2]);
        t("{$rot}: gadgets.debt_load == SQL direta; mesmo denominador do Fator de Carga", function () use ($c, $obter, $sqlDireta) {
            $p = $obter(['jornada' => $c[0], 'sprint' => $c[1], 'modo' => $c[2]]);
            $d = $p['gadgets']['debt_load'];
            $f = $p['gadgets']['fator_carga'];
            igual('exato', $d['classe']);
            igual('painel', $d['fonte_capacidade']);
            $s = $sqlDireta($p);
            igual($s['sprints'] === [] ? 'vazio' : ($s['com'] > 0 ? 'ok' : 'vazio'), $d['estado'], 'estado');
            if ($d['estado'] !== 'ok') {
                echo "          (estado {$d['estado']}: " . ($d['motivo'] ?? '') . ")\n";
                return;
            }
            $esp = $s['sprints']; sort($esp);
            $got = $d['sprints']; sort($got);
            igual($esp, $got, 'sprints do escopo');
            proximo($s['liquida'], $d['liquida_h'], 'liquida_h');
            proximo($s['cap'], $d['capacidade_sp'], 'capacidade_sp');
            proximo($s['sp'], $d['sp_divida'], 'sp_divida');
            proximo($s['dl'], $d['dl_pct'], 'dl_pct', 0.06);
            igual($s['com'], $d['cobertura']['times_com_dados'], 'times com dados');
            $semGot = array_column($d['cobertura']['times_sem_dados'], 'projeto_key');
            sort($semGot);
            $semEsp = $s['sem']; sort($semEsp);
            igual($semEsp, $semGot, 'times sem dados');
            igual(count($p['universo']['times']), $d['cobertura']['times_total'], 'times_total');
            igual($c[2] === 'pi' && $c[1] === '' ? 'pi' : 'sprint', $d['escopo'], 'escopo');
            igual(null, $d['faixa'], 'sem faixa');
            // mesmo denominador do Fator de Carga
            igual($f['sprints'], $d['sprints'], 'mesmas sprints do Fator de Carga');
            igual($f['parcial'], $d['parcial'], 'mesmo parcial');
            proximo($f['liquida_h'], $d['liquida_h'], 'liquida_h == Fator de Carga');
            igual($f['cobertura']['times_com_dados'], $d['cobertura']['times_com_dados'], 'mesmos times com dados');
            igual(array_column($f['cobertura']['times_sem_dados'], 'projeto_key'), array_column($d['cobertura']['times_sem_dados'], 'projeto_key'), 'mesmos times sem dados');
            // decomposição fecha com o total
            $somaTipos = 0.0;
            foreach ($d['decomposicao']['por_tipo'] as $x) { $somaTipos += $x['sp']; }
            proximo($d['sp_divida'], $somaTipos, 'por_tipo soma o total');
            $somaTimes = 0.0;
            foreach ($d['por_time'] as $x) { $somaTimes += $x['sp_divida']; }
            proximo($d['sp_divida'], $somaTimes, 'por_time soma o total', 0.05);
            // impacto no Fator de Carga e comparativo do Efficiency presentes
            igual($f['fc_pct'], $d['impacto_fator_carga']['fc_pct_atual'], 'fc atual');
            echo '          ' . $d['sp_divida'] . ' SP ÷ ' . $d['capacidade_sp'] . ' SP = ' . $d['dl_pct'] . '%, com dados '
                . $d['cobertura']['times_com_dados'] . '/' . $d['cobertura']['times_total'] . ', parcial ' . var_export($d['parcial'], true)
                . ', sem SP ' . $d['diagnosticos']['itens_sem_sp']['itens'] . ' itens' . "\n";
        });
    }

    t('critérios de aceite: Secretaria SP 26.4.3 (6,2 / 10 / 161,72) e consolidado PI (5,2 / 223 / 26 de 40)', function () use ($obter) {
        $a = $obter(['jornada' => 'Secretaria e Gestor de Unidades', 'sprint' => 'SP 26.4.3'])['gadgets']['debt_load'];
        proximo(6.2, $a['dl_pct'], 'dl_pct', 0.001); proximo(10, $a['sp_divida'], 'sp_divida', 0.001); proximo(161.72, $a['capacidade_sp'], 'capacidade_sp', 0.001);
        $b = $obter(['modo' => 'pi'])['gadgets']['debt_load'];
        proximo(5.2, $b['dl_pct'], 'dl_pct pi', 0.001); proximo(223, $b['sp_divida'], 'sp_divida pi', 0.001);
        igual(26, $b['cobertura']['times_com_dados']); igual(40, $b['cobertura']['times_total']);
    });

    t('efficiency_baseline (padrão) == efficiency_comparativo.com_debito_tecnico; modo ativo = com', function () use ($obter, $casos) {
        foreach ($casos as $c) {
            $p = $obter(['jornada' => $c[0], 'sprint' => $c[1], 'modo' => $c[2]]);
            $e = $p['gadgets']['efficiency_baseline'];
            $cmp = $p['gadgets']['debt_load']['efficiency_comparativo'];
            igual('com_debito_tecnico', $cmp['modo_ativo']);
            foreach (['horas', 'pontos', 'h_por_sp', 'razao_pct', 'baseline_h_por_sp'] as $k) {
                igual($e[$k] ?? null, $cmp['com_debito_tecnico'][$k], 'com DT ' . $k);
            }
            igual(false, $p['metodologia']['debt_load']['efficiency_exclui_debito_tecnico']);
            igual(['Débito Técnico', 'Suporte'], $p['metodologia']['debt_load']['tipos']);
            proximo(80, $p['metodologia']['debt_load']['disponibilidade_pct'], 'disponibilidade', 0.001);
            igual('painel', $p['metodologia']['debt_load']['fonte_capacidade']);
        }
    });

    t('impacto_fator_carga: fc_pct_atual - fc_pct_sem_debito_tecnico = delta_pp; Fator de Carga não muda', function () use ($obter, $casos) {
        foreach ($casos as $c) {
            $d = $obter(['jornada' => $c[0], 'sprint' => $c[1], 'modo' => $c[2]])['gadgets']['debt_load'];
            $i = $d['impacto_fator_carga'];
            if ($i['fc_pct_atual'] === null || $i['fc_pct_sem_debito_tecnico'] === null) {
                igual(null, $i['delta_pp']);
                continue;
            }
            proximo($i['fc_pct_atual'] - $i['fc_pct_sem_debito_tecnico'], $i['delta_pp'], 'delta_pp', 0.06);
            verdade($i['fc_pct_sem_debito_tecnico'] <= $i['fc_pct_atual'], 'sem DT não pode ser maior');
        }
    });

    t('repositório com excluir_debito_tecnico=true: efficiency_baseline == comparativo.sem_debito_tecnico e só o Efficiency muda', function () use ($casos) {
        $cfg = require BASE_DIR . '/config/visao_geral.php';
        $cfgTrue = $cfg;
        $cfgTrue['efficiency']['excluir_debito_tecnico'] = true;
        $repoA = new VisaoGeralRepository(null, null, null, null, $cfg);
        $repoB = new VisaoGeralRepository(null, null, null, null, $cfgTrue);
        $hoje = date('Y-m-d');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $norm = function (array $p) use ($flags): string {
            unset($p['gadgets']['efficiency_baseline'], $p['gadgets']['debt_load']['efficiency_comparativo']['modo_ativo'],
                  $p['metodologia']['debt_load']['efficiency_exclui_debito_tecnico']);
            $s = json_encode($p, $flags);
            return preg_replace('/"defasagem_horas":(-?[0-9.eE+\-]+|null)/', '"defasagem_horas":"<RELOGIO>"', $s);
        };
        foreach ($casos as $c) {
            $f = ['jornada' => $c[0], 'time' => '', 'versao' => '', 'sprint' => $c[1], 'modo' => $c[2]];
            $a = $repoA->consultar($f, $hoje);
            $b = $repoB->consultar($f, $hoje);
            $e = $b['gadgets']['efficiency_baseline'];
            $cmp = $b['gadgets']['debt_load']['efficiency_comparativo'];
            igual('sem_debito_tecnico', $cmp['modo_ativo']);
            igual(true, $b['metodologia']['debt_load']['efficiency_exclui_debito_tecnico']);
            foreach (['horas', 'pontos', 'h_por_sp', 'razao_pct', 'baseline_h_por_sp'] as $k) {
                igual($e[$k] ?? null, $cmp['sem_debito_tecnico'][$k], 'sem DT ' . $k);
            }
            igual($a['gadgets']['debt_load']['efficiency_comparativo']['sem_debito_tecnico'], $cmp['sem_debito_tecnico'], 'comparativo igual nos dois modos');
            igual($norm($a), $norm($b), 'só o Efficiency muda');
        }
    });

    t('SEM cache == MISS == HIT (byte a byte) e corpo < 4 MiB', function () {
        $dir = sys_get_temp_dir() . '/dl_teste_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
            verdade(strpos($hit['corpo'], '"debt_load"') !== false, 'debt_load no corpo');
        } finally {
            putenv('VG_CACHE=0');
            removerPasta($dir);
        }
    });

    t('relatório: Efficiency antes x depois (padrão x sem Débito Técnico) e impacto no Fator de Carga', function () use ($obter, $casos) {
        $f = function ($v) { return $v === null ? 'n/d' : (string) $v; };
        echo "          caso | h/SP padrão -> sem DT | razão% padrão -> sem DT | baseline padrão -> sem DT | FC% atual -> sem DT (delta pp)\n";
        foreach ($casos as $c) {
            $p = $obter(['jornada' => $c[0], 'sprint' => $c[1], 'modo' => $c[2]]);
            $d = $p['gadgets']['debt_load'];
            $a = $d['efficiency_comparativo']['com_debito_tecnico'];
            $b = $d['efficiency_comparativo']['sem_debito_tecnico'];
            $i = $d['impacto_fator_carga'];
            echo '          ' . ($c[0] === '' ? 'consolidado' : 'Secretaria') . ' ' . ($c[1] !== '' ? $c[1] : 'PI')
                . ' | ' . $f($a['h_por_sp']) . ' -> ' . $f($b['h_por_sp'])
                . ' | ' . $f($a['razao_pct']) . ' -> ' . $f($b['razao_pct'])
                . ' | ' . $f($a['baseline_h_por_sp']) . ' -> ' . $f($b['baseline_h_por_sp'])
                . ' | ' . $f($i['fc_pct_atual']) . ' -> ' . $f($i['fc_pct_sem_debito_tecnico']) . ' (' . $f($i['delta_pp']) . ")\n";
        }
    });

    if ($referencia !== '') {
        echo "\n== Parte R: referência (" . $referencia . ") ==\n";
        $alvos = [
            ['sprint-fechada-26.4.3', ['sprint' => 'SP 26.4.3']],
            ['sprint-aberta-26.4.4', ['sprint' => 'SP 26.4.4']],
            ['pi-inteiro', ['modo' => 'pi']],
        ];
        $norm = function (string $s): string {
            $s = preg_replace('/"defasagem_horas":(-?[0-9.eE+\-]+|null)/', '"defasagem_horas":"<RELOGIO>"', $s);
            return preg_replace('/A carga mais antiga do Jira tem -?[0-9.eE+\-]+ h de defasagem\./', 'A carga mais antiga do Jira tem <RELOGIO> h de defasagem.', $s);
        };
        $escopos = ['secretaria' => 'Secretaria e Gestor de Unidades', 'consolidado' => ''];
        $combos = [];
        foreach ($escopos as $esc => $jor) {
            foreach ($alvos as $a) {
                $combos[] = [$esc, $jor, $a];
            }
        }
        foreach ($combos as $cb) {
            $a = $cb[2];
            t("referência visao-geral__{$cb[0]}__{$a[0]}: corpo novo sem debt_load == antes", function () use ($cb, $a, $referencia, $ctrl, $norm) {
                $arq = $referencia . '/visao-geral__' . $cb[0] . '__' . $a[0] . '.json';
                verdade(is_file($arq), 'referência ausente: ' . $arq);
                $f = $a[1] + ['jornada' => $cb[1], 'time' => '', 'versao' => 'PI 4/26', 'sprint' => '', 'modo' => ''];
                $novo = $ctrl->obterVisaoGeral($f)['corpo'];
                $p = json_decode($novo, true);
                $ref = json_decode((string) file_get_contents($arq), true);
                verdade(is_array($ref), 'referência não é JSON');
                $hojeAntes = $ref['janela']['hoje'] ?? null;
                if ($hojeAntes !== ($p['janela']['hoje'] ?? null)) {
                    echo "          AVISO: relógio mudou (antes {$hojeAntes}, agora " . ($p['janela']['hoje'] ?? '?')
                        . "); comparação pulada. Recapture o antes de 'git archive HEAD' (padrão da Fase 1).\n";
                    return;
                }
                verdade(isset($p['gadgets']['debt_load']) && isset($p['metodologia']['debt_load']), 'blocos novos presentes');
                unset($p['gadgets']['debt_load'], $p['metodologia']['debt_load']);
                $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
                igual($norm(json_encode($ref, $flags)), $norm(json_encode($p, $flags)), 'corpo');
                verdade(strlen($novo) < 4194304, 'tamanho < 4 MiB');
            });
        }
    }
}

echo "\n" . $resultados['ok'] . ' PASSOU, ' . $resultados['falha'] . " FALHOU\n";
exit($resultados['falha'] > 0 ? 1 : 0);
