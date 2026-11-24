<?php
/**
 * bin/teste_fator_carga.php — TESTES do Fator de Carga (Visão Geral)
 * ─────────────────────────────────────────────────────────────────────────────
 * Parte U  (sem banco): VisaoGeralCalculator::fatorCargaUnidade / faixaFatorCarga /
 *          fatorCarga com linhas sintéticas; ConfigRepository (seed x JSON, sem mkdir).
 * Parte I  (banco local, SOMENTE SELECT): gadgets.fator_carga contra SQL direta
 *          independente (capacity.json do Painel, times_composicao.media_produtividade_pi,
 *          sprints.dias_uteis, SPE da extração), demanda em SP contra a barra
 *          sprint_planning do Plan x Real, SEM cache == MISS == HIT e token de cache
 *          que muda com data/config/capacity.json.
 * Parte R  (--referencia=<pasta antes>): o corpo novo sem gadgets.fator_carga e sem
 *          metodologia.fator_carga == corpo capturado antes (visao-geral__*.json).
 *
 * Uso: php bin/teste_fator_carga.php [--referencia=.agents\tasks\fase2-antes] [--sem-banco]
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
use App\Repositories\ConfigRepository;
use App\Repositories\PlanejadoRealizadoRepository;
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
function lin(string $key, ?float $spe, array $extra = []): array
{
    return $extra + [
        'chave' => $key . '-' . mt_rand(1, 999999), 'projeto_key' => $key, 'tipo_item' => 'História',
        'status' => 'Em Andamento', 'categoria' => 'valor', 'entregue' => 0,
        'sprint' => 'SP 1', 'sprint_ref' => 'SP 1', 'story_points' => 0.0, 'spe' => $spe,
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
$faixas = ['subutilizacao_ate' => 85, 'recomendada_ate' => 95, 'limite' => 100];
$cfgBase = ['disponibilidade_pct' => 80, 'incluir_sustentacao' => false, 'faixas' => $faixas, 'cancelado' => ['Cancelado']];

$fc = function (array $rows, array $keys, array $refs = ['SP 1'], array $over = []) use ($sprints, $dias, $cap, $bases, $nomes, $cfgBase): array {
    $d = $over['dias'] ?? $dias;
    $c = $over['cap'] ?? $cap;
    $b = $over['bases'] ?? $bases;
    $cfg = ($over['cfg'] ?? []) + $cfgBase;
    return C::fatorCarga($rows, $refs, $sprints, $d, $c, $b, $nomes, $keys, $cfg, 'sprint', $refs[0] ?? null);
};

echo "\n== Parte U: cálculo (sem banco) ==\n";

t('exemplo de referência: 7 devs, 6 h, 8 dias, 80%, 35 SP, 7 h/SP => 336 / 268,8 / 245 / 91,1%', function () use ($fc) {
    $u = C::fatorCargaUnidade(7, 6, 8, 80, 35, 7);
    igual(336.0, $u['bruta_h']); igual(268.8, $u['liquida_h']); igual(245.0, $u['demanda_h']); igual(91.1, $u['fc_pct']);
    $r = $fc([lin('AAA', 35)], ['AAA']);
    igual('ok', $r['estado']); igual('exato', $r['classe']);
    igual(336.0, $r['bruta_h']); igual(268.8, $r['liquida_h']); igual(245.0, $r['demanda_h']); igual(91.1, $r['fc_pct']);
    igual('recomendada', $r['faixa']);
    igual('somente_valor', $r['regra_planejado']);
    igual(80.0, $r['disponibilidade_pct']);
    igual(false, $r['parcial']);
    igual(1, count($r['por_time'])); igual(91.1, $r['por_time'][0]['fc_pct']);
    igual(1, count($r['por_sprint'])); igual('SP 1', $r['por_sprint'][0]['sprint']); igual(8.0, $r['por_sprint'][0]['dias_uteis']);
});

t('faixas: 84,9 sub; 85,0 e 95,0 recomendada; 95,1 e 100,0 atenção; 100,1 acima; null => null', function () use ($faixas) {
    igual('subutilizacao', C::faixaFatorCarga(84.9, $faixas));
    igual('recomendada', C::faixaFatorCarga(85.0, $faixas));
    igual('recomendada', C::faixaFatorCarga(95.0, $faixas));
    igual('atencao', C::faixaFatorCarga(95.1, $faixas));
    igual('atencao', C::faixaFatorCarga(100.0, $faixas));
    igual('acima', C::faixaFatorCarga(100.1, $faixas));
    igual(null, C::faixaFatorCarga(null, $faixas));
    igual('recomendada', C::faixaFatorCarga(94.96, $faixas), 'classifica sobre o valor arredondado a 1 casa (95,0)');
});

t('consolidado soma horas; não é a média dos percentuais; time sem item entra com demanda 0', function () use ($fc) {
    // A: 245 h / 268,8 h = 91,1%. B: 50 h / 179,2 h = 27,9%. Soma: 295 / 448 = 65,8%; média simples = 59,5%.
    $r = $fc([lin('AAA', 35), lin('BBB', 10)], ['AAA', 'BBB']);
    igual(448.0, $r['liquida_h']); igual(295.0, $r['demanda_h']); igual(65.8, $r['fc_pct']);
    verdade($r['fc_pct'] !== round((91.1 + 27.9) / 2, 1), 'não é média de percentuais');
    // B sem item na sprint: demanda 0, fc 0, e entra no denominador.
    $r2 = $fc([lin('AAA', 35)], ['AAA', 'BBB']);
    igual(448.0, $r2['liquida_h']); igual(245.0, $r2['demanda_h']); igual(54.7, $r2['fc_pct']);
    igual(2, $r2['cobertura']['times_com_dados']);
    $b = null;
    foreach ($r2['por_time'] as $p) { if ($p['projeto_key'] === 'BBB') $b = $p; }
    igual(0.0, $b['demanda_h']); igual(0.0, $b['fc_pct']); igual('subutilizacao', $b['faixa']);
});

t('time sem Painel ou sem baseline fica fora e é listado, nunca com fc 0', function () use ($fc) {
    // CCC: sem Painel (6 SPE); DDD: com Painel e sem baseline (4 SPE); EEE: dev_count nulo (2 SPE).
    $r = $fc([lin('AAA', 35), lin('CCC', 6), lin('DDD', 4, ['categoria' => 'sustentacao']), lin('EEE', 2)], ['AAA', 'CCC', 'DDD', 'EEE']);
    igual('ok', $r['estado']);
    igual(268.8, $r['liquida_h'], 'só AAA no denominador'); igual(245.0, $r['demanda_h']); igual(91.1, $r['fc_pct']);
    igual(1, $r['cobertura']['times_com_dados']); igual(4, $r['cobertura']['times_total']);
    $m = [];
    foreach ($r['cobertura']['times_sem_dados'] as $s) { $m[$s['projeto_key']] = $s; }
    igual(['CCC', 'DDD', 'EEE'], array_keys($m));
    igual('sem_painel', $m['CCC']['motivo']); igual(6.0, $m['CCC']['sp_valor']);
    igual('sem_baseline', $m['DDD']['motivo']); igual(4.0, $m['DDD']['sp_sustentacao']);
    igual('sem_painel', $m['EEE']['motivo']);
    igual(['AAA'], array_column($r['por_time'], 'projeto_key'));
});

t('nenhum time com dados: estado vazio e fc_pct null (nunca 0)', function () use ($fc) {
    $r = $fc([lin('CCC', 6)], ['CCC']);
    igual('vazio', $r['estado']); igual(null, $r['fc_pct']); igual(null, $r['faixa']);
    verdade(is_string($r['motivo']) && $r['motivo'] !== '', 'motivo');
    igual(0, $r['cobertura']['times_com_dados']);
    igual('sem_painel', $r['cobertura']['times_sem_dados'][0]['motivo']);
    $r2 = $fc([], [], ['SP 1']);
    igual('vazio', $r2['estado']); igual(null, $r2['fc_pct']);
});

t('sprint de sustentação fica fora do cálculo', function () use ($fc) {
    $r = $fc([lin('AAA', 35), lin('AAA', 50, ['sprint_ref' => 'SPS'])], ['AAA'], ['SP 1', 'SPS']);
    igual(['SP 1'], $r['sprints']); igual(91.1, $r['fc_pct']);
    igual(['SPS'], $r['diagnosticos']['sprints_sustentacao_ignoradas']);
    $so = $fc([lin('AAA', 50, ['sprint_ref' => 'SPS'])], ['AAA'], ['SPS']);
    igual('vazio', $so['estado']); igual(null, $so['fc_pct']);
    verdade(strpos($so['motivo'], 'sustentação') !== false, 'motivo cita sustentação');
});

t('dias_uteis nulo ou 0: sprint fora e listada', function () use ($fc, $dias) {
    $rows = [lin('AAA', 35), lin('AAA', 70, ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2'])];
    $r = $fc($rows, ['AAA'], ['SP 1', 'SP 2'], ['dias' => ['SP 1' => null, 'SP 2' => 7]]);
    igual(['SP 1'], $r['diagnosticos']['sprints_sem_dias_uteis']); igual(['SP 2'], $r['sprints']);
    $r0 = $fc($rows, ['AAA'], ['SP 1'], ['dias' => ['SP 1' => 0]]);
    igual('vazio', $r0['estado']); igual(['SP 1'], $r0['diagnosticos']['sprints_sem_dias_uteis']);
});

t('sprint ativa marca parcial; fechada não', function () use ($fc) {
    $a = $fc([lin('AAA', 35, ['sprint_ref' => 'SP 2'])], ['AAA'], ['SP 2']);
    igual(true, $a['parcial']);
    igual(7.0, $a['por_sprint'][0]['dias_uteis'], 'dias úteis totais da sprint');
    igual('ativa', $a['por_sprint'][0]['status']);
    $f = $fc([lin('AAA', 35)], ['AAA'], ['SP 1']);
    igual(false, $f['parcial']);
});

t('incluir_sustentacao troca o fc_pct sem alterar a decomposição', function () use ($fc) {
    $rows = [lin('AAA', 35), lin('AAA', 7, ['categoria' => 'sustentacao', 'tipo_item' => 'Suporte'])];
    $v = $fc($rows, ['AAA']);
    $s = $fc($rows, ['AAA'], ['SP 1'], ['cfg' => ['incluir_sustentacao' => true]]);
    igual(91.1, $v['fc_pct']); igual('somente_valor', $v['regra_planejado']);
    igual(109.4, $s['fc_pct']); igual('valor_e_sustentacao', $s['regra_planejado']); igual('acima', $s['faixa']);
    igual($v['decomposicao'], $s['decomposicao']);
    igual(35.0, $v['decomposicao']['sp_valor']); igual(7.0, $v['decomposicao']['sp_sustentacao']);
    igual(245.0, $v['decomposicao']['demanda_valor_h']); igual(49.0, $v['decomposicao']['demanda_sustentacao_h']);
    igual(91.1, $v['decomposicao']['fc_somente_valor_pct']); igual(109.4, $v['decomposicao']['fc_com_sustentacao_pct']);
    igual(294.0, $s['demanda_h']);
});

t('Cancelado, SPE nulo, Incidente e item de outra sprint ficam fora', function () use ($fc) {
    $rows = [
        lin('AAA', 35),
        lin('AAA', 99, ['status' => 'Cancelado']),
        lin('AAA', null),
        lin('AAA', 99, ['categoria' => null, 'tipo_item' => 'Incidente']),
        lin('AAA', 99, ['sprint_ref' => 'SP 2', 'sprint' => 'SP 2']),
        lin('AAA', 99, ['sprint_ref' => null]),
    ];
    $r = $fc($rows, ['AAA']);
    igual(35.0, $r['decomposicao']['sp_valor']); igual(245.0, $r['demanda_h']); igual(91.1, $r['fc_pct']);
    igual(1, $r['diagnosticos']['itens_sem_spe']);
});

t('ConfigRepository: sem capacity.json usa o seed; com capacity.json usa o arquivo; criarDiretorio=false não cria pasta', function () {
    $seed = require BASE_DIR . '/config/capacity.php';
    $raiz = sys_get_temp_dir() . '/fc_cfg_' . getmypid() . '_' . bin2hex(random_bytes(3));
    try {
        // pasta inexistente + criarDiretorio=false
        $repo = new ConfigRepository($raiz . '/data/config', [], $seed, false);
        verdade(!is_dir($raiz . '/data/config'), 'não deve criar a pasta');
        verdade(!$repo->hasSaved('capacity'), 'sem arquivo salvo');
        $c = $repo->getCapacity();
        verdade(!is_dir($raiz . '/data/config'), 'getCapacity não cria a pasta');
        $esperado = array_keys($seed['dev_count_by_team'] ?? []);
        verdade($esperado !== [], 'seed com times');
        foreach ($esperado as $k) {
            verdade(isset($c['teams'][$k]), "seed deve conter {$k}");
            igual($seed['dev_count_by_team'][$k], $c['teams'][$k]['dev_count'], "dev_count {$k}");
        }
        // com arquivo
        mkdir($raiz . '/data/config', 0777, true);
        file_put_contents($raiz . '/data/config/capacity.json', json_encode([
            'teams' => ['ZZZ' => ['dev_count' => 3, 'productivity' => 5, 'productive_hours' => 6]],
        ]));
        $repo2 = new ConfigRepository($raiz . '/data/config', [], $seed, false);
        verdade($repo2->hasSaved('capacity'), 'com arquivo salvo');
        $c2 = $repo2->getCapacity();
        igual(['ZZZ'], array_keys($c2['teams']));
        igual(3, $c2['teams']['ZZZ']['dev_count']);
        // criarDiretorio padrão (true) cria a pasta
        $repo3 = new ConfigRepository($raiz . '/novo', [], $seed);
        verdade(is_dir($raiz . '/novo'), 'padrão true cria a pasta');
    } finally {
        removerPasta($raiz);
    }
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
    $tiposSust = (require BASE_DIR . '/config/baseline.php')['plan_real_sustentacao_types'] ?? ['Sustentação'];

    $inList = function (array $v, string $pre, array &$par): string {
        $o = [];
        foreach (array_values($v) as $i => $x) { $par[":$pre$i"] = $x; $o[] = ":$pre$i"; }
        return $o === [] ? "''" : implode(',', $o);
    };

    // SQL independente: devolve o FC e a cobertura recalculados sem o repositório.
    // $fonteCap: 'painel' (capacity.json) ou 'banco' (times_composicao, só para o relatório).
    $sqlDireta = function (array $p, string $fonteCap = 'painel') use ($pdo, $inList, $arqPainel, $tiposSust): array {
        $desc = $p['janela']['pi'];
        $keys = array_column($p['universo']['times'], 'projeto_key');
        $hoje = $p['janela']['hoje'];
        $par = [':d' => $desc];
        if (($p['filtros']['sprint'] ?? '') !== '') {
            $nomes = [(string) $p['filtros']['sprint']];
            $st = $pdo->prepare("SELECT nome, tipo, dias_uteis FROM sprints WHERE configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d) AND ativo = 'S' AND nome = :n");
            $st->execute([':d' => $desc, ':n' => $nomes[0]]);
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
        // baseline h/SP e (para o relatório) composição do banco
        $par2 = [];
        $k2 = $inList($keys, 'k', $par2);
        $sb = $pdo->prepare(
            "SELECT t.projeto_key, tc.media_produtividade_pi AS ref, tc.qtde_dev, tc.horas_dev
               FROM times t JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
              WHERE t.ativo = 'S' AND t.projeto_key IN ($k2) ORDER BY tc.id_time_composicao"
        );
        $sb->execute($par2);
        $base = []; $comp = [];
        foreach ($sb->fetchAll() as $r) {
            if ($r['ref'] !== null) $base[$r['projeto_key']] = (float) $r['ref'];
            $comp[$r['projeto_key']] = ['dev_count' => $r['qtde_dev'], 'productive_hours' => $r['horas_dev']];
        }
        $teams = [];
        if ($fonteCap === 'painel') {
            $j = json_decode((string) file_get_contents($arqPainel), true);
            $teams = $j['teams'] ?? [];
        } else {
            $teams = $comp;
        }
        // SPE da extração por (time, sprint, categoria)
        $par3 = [':d' => $desc];
        $s3 = $inList(array_keys($dias), 's', $par3);
        $k3 = $inList($keys, 'k', $par3);
        $t3 = $inList($tiposSust, 't', $par3);
        $sq = $pdo->prepare(
            "SELECT projeto_key, sprint_ref,
                    CASE WHEN tipo_item IN ($t3) THEN 'sust' WHEN categoria IS NOT NULL THEN 'valor' END AS cat,
                    SUM(COALESCE(spe, 0)) AS spe
               FROM vw_planejado_realizado_item
              WHERE pi = :d AND origem = 'extracao' AND COALESCE(status, '') <> 'Cancelado'
                AND sprint_ref IN ($s3) AND projeto_key IN ($k3)
              GROUP BY projeto_key, sprint_ref, cat"
        );
        $sq->execute($par3);
        $spe = [];
        foreach ($sq->fetchAll() as $r) {
            if ($r['cat'] === null) continue;
            $spe[$r['projeto_key']][$r['sprint_ref']][$r['cat']] = (float) $r['spe'];
        }
        $liq = 0.0; $bru = 0.0; $dv = 0.0; $ds = 0.0; $com = 0; $sem = [];
        $spvTot = 0.0; $spsTot = 0.0; $spvFora = 0.0; $spsFora = 0.0;
        foreach ($keys as $k) {
            $c = $teams[$k] ?? ($teams[strtoupper($k)] ?? null);
            $dev = is_array($c) ? ($c['dev_count'] ?? null) : null;
            $hh = is_array($c) ? ($c['productive_hours'] ?? null) : null;
            $b = $base[$k] ?? null;
            $v = 0.0; $s = 0.0;
            foreach ($spe[$k] ?? [] as $porCat) { $v += $porCat['valor'] ?? 0.0; $s += $porCat['sust'] ?? 0.0; }
            if ($dev === null || $hh === null || (float) $dev <= 0 || (float) $hh <= 0 || $b === null || (float) $b <= 0) {
                $sem[] = $k; $spvFora += $v; $spsFora += $s;
                continue;
            }
            $com++;
            $spvTot += $v; $spsTot += $s;
            foreach ($dias as $n => $d) {
                $br = (float) $dev * (float) $hh * $d;
                $bru += $br; $liq += $br * 0.8;
                $dv += ($spe[$k][$n]['valor'] ?? 0.0) * (float) $b;
                $ds += ($spe[$k][$n]['sust'] ?? 0.0) * (float) $b;
            }
        }
        return [
            'bruta' => $bru, 'liquida' => $liq, 'demanda' => $dv, 'demanda_sust' => $ds,
            'fc' => $liq > 0 ? $dv / $liq * 100 : null, 'fc_com_sust' => $liq > 0 ? ($dv + $ds) / $liq * 100 : null,
            'com' => $com, 'sem' => $sem, 'sprints' => array_keys($dias),
            'sp_valor_fora' => $spvFora, 'sp_sust_fora' => $spsFora, 'sp_valor' => $spvTot, 'sp_sust' => $spsTot,
        ];
    };

    // Recortes a partir da própria resposta (sem sprint nem PI fixos, para não quebrar na virada de PI):
    // PI inteira, a última sprint fechada (janela.sprint_anterior) e a sprint em foco (janela.sprint_foco).
    $base = $obter(['modo' => 'pi']);
    $sprintsCaso = [];
    foreach ([$base['janela']['sprint_anterior']['nome'] ?? null, $base['janela']['sprint_foco']['nome'] ?? null] as $sn) {
        if ($sn !== null && !in_array($sn, $sprintsCaso, true)) $sprintsCaso[] = $sn;
    }
    $recortes = [];
    foreach ($sprintsCaso as $sn) $recortes[] = [$sn, 'sprint'];
    $recortes[] = ['', 'pi'];
    $casos = [];
    foreach (['Secretaria e Gestor de Unidades', ''] as $j) {
        foreach ($recortes as $s) {
            $casos[] = [$j, $s[0], $s[1]];
        }
    }
    // Só o que depende do relógio entre duas consultas (número de defasagem_horas e do aviso) é normalizado;
    // a igualdade byte a byte do cache, com relógio injetado, é conferida em teste_cache_visao_geral.php.
    $normRelogio = function (string $s): string {
        $s = preg_replace('/"defasagem_horas":(-?[0-9.eE+\-]+|null)/', '"defasagem_horas":"<RELOGIO>"', $s);
        return preg_replace('/A carga mais antiga do Jira tem -?[0-9.eE+\-]+ h de defasagem\./', 'A carga mais antiga do Jira tem <RELOGIO> h de defasagem.', $s);
    };
    verdade(is_file($arqPainel), 'data/config/capacity.json local ausente (a SQL direta lê o Painel)');

    foreach ($casos as $c) {
        $rot = ($c[0] === '' ? '(consolidado)' : $c[0]) . ' ' . ($c[1] !== '' ? $c[1] : 'modo=' . $c[2]);
        t("{$rot}: gadgets.fator_carga == SQL direta", function () use ($c, $obter, $sqlDireta) {
            $p = $obter(['jornada' => $c[0], 'sprint' => $c[1], 'modo' => $c[2]]);
            $f = $p['gadgets']['fator_carga'];
            igual('exato', $f['classe']);
            igual('painel', $f['fonte_capacidade']);
            $s = $sqlDireta($p);
            igual($s['sprints'] === [] ? 'vazio' : ($s['com'] > 0 ? 'ok' : 'vazio'), $f['estado'], 'estado');
            if ($f['estado'] !== 'ok') {
                echo "          (estado {$f['estado']}: " . ($f['motivo'] ?? '') . ")\n";
                return;
            }
            $esp = $s['sprints'];
            sort($esp);
            $got = $f['sprints'];
            sort($got);
            igual($esp, $got, 'sprints do escopo');
            proximo($s['bruta'], $f['bruta_h'], 'bruta_h');
            proximo($s['liquida'], $f['liquida_h'], 'liquida_h');
            proximo($s['demanda'], $f['demanda_h'], 'demanda_h');
            proximo($s['fc'], $f['fc_pct'], 'fc_pct', 0.06);
            proximo($s['fc_com_sust'], $f['decomposicao']['fc_com_sustentacao_pct'], 'fc com sustentação', 0.06);
            proximo($s['demanda_sust'], $f['decomposicao']['demanda_sustentacao_h'], 'demanda sustentação h');
            igual($s['com'], $f['cobertura']['times_com_dados'], 'times com dados');
            $semGot = array_column($f['cobertura']['times_sem_dados'], 'projeto_key');
            sort($semGot);
            $semEsp = $s['sem'];
            sort($semEsp);
            igual($semEsp, $semGot, 'times sem dados');
            igual(count($p['universo']['times']), $f['cobertura']['times_total'], 'times_total');
            igual($c[2] === 'pi' && $c[1] === '' ? 'pi' : 'sprint', $f['escopo'], 'escopo');
            proximo($f['bruta_h'] * 0.8, $f['liquida_h'], 'liquida = bruta x 80%');
            echo '          ' . $f['demanda_h'] . ' h ÷ ' . $f['liquida_h'] . ' h = ' . $f['fc_pct'] . '% (' . $f['faixa'] . '), com dados '
                . $f['cobertura']['times_com_dados'] . '/' . $f['cobertura']['times_total'] . ', parcial ' . var_export($f['parcial'], true)
                . ', sem SPE ' . $f['diagnosticos']['itens_sem_spe'] . "\n";
        });
    }

    // Demanda em SPE == barra sprint_planning do Plan x Real, por sprint filtrada.
    foreach (['Secretaria e Gestor de Unidades', ''] as $j) {
        foreach ($sprintsCaso as $sp) {
            $rot = ($j === '' ? '(consolidado)' : $j) . ' ' . $sp;
            t("{$rot}: SPE do fator de carga == barra sprint_planning do Plan x Real", function () use ($j, $sp, $obter) {
                $p = $obter(['jornada' => $j, 'sprint' => $sp]);
                $f = $p['gadgets']['fator_carga'];
                $repo = new PlanejadoRealizadoRepository(new PrevisibilidadeRepository());
                $pr = $repo->consultar(['jornada' => $j, 'time' => '', 'versao' => '', 'sprint' => $sp]);
                $b = $pr['barras']['sprint_planning'];
                $valor = $b['valor_sem_epico'] + $b['valor_com_epico'];
                $foraV = 0.0; $foraS = 0.0;
                foreach ($f['cobertura']['times_sem_dados'] as $s) { $foraV += $s['sp_valor']; $foraS += $s['sp_sustentacao']; }
                proximo($valor, $f['decomposicao']['sp_valor'] + $foraV, 'valor (SPE)');
                proximo($b['sustentacao'], $f['decomposicao']['sp_sustentacao'] + $foraS, 'sustentação (SPE)');
            });
        }
    }

    t('SEM cache == MISS == HIT (sem o número do relógio) e corpo < 4 MiB', function () use ($normRelogio) {
        $dir = sys_get_temp_dir() . '/fc_teste_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
            igual($normRelogio($sem['corpo']), $normRelogio($miss['corpo']), 'SEM vs MISS');
            igual($normRelogio($miss['corpo']), $normRelogio($hit['corpo']), 'MISS vs HIT');
            verdade(strlen($hit['corpo']) < 4194304, 'tamanho');
            verdade(strpos($hit['corpo'], '"fator_carga"') !== false, 'fator_carga no corpo');
        } finally {
            putenv('VG_CACHE=0');
            removerPasta($dir);
        }
    });

    t('token de cache muda quando o JSON de capacidade muda e fica igual sem alteração', function () use ($arqPainel) {
        $tmp = sys_get_temp_dir() . '/fc_tok_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.json';
        $ctl = new class (new PrevisibilidadeRepository()) extends GraficosController {
            public $caminho = '';
            protected function caminhoCapacidadePainel(): string { return $this->caminho; }
        };
        $rm = new ReflectionMethod(GraficosController::class, 'marcadoresVisaoGeral');
        $rm->setAccessible(true);
        $token = function () use ($ctl, $rm): ?string {
            $res = null;
            $args = [['jornada' => '', 'time' => '', 'versao' => '', 'sprint' => ''], date('Y-m-d'), &$res];
            return $rm->invokeArgs($ctl, $args);
        };
        try {
            $ctl->caminho = $tmp;
            $semArquivo = $token();
            verdade(is_string($semArquivo) && $semArquivo !== '', 'token sem arquivo');
            igual($semArquivo, $token(), 'estável sem arquivo');
            file_put_contents($tmp, (string) file_get_contents($arqPainel));
            $a = $token();
            verdade($a !== $semArquivo, 'arquivo criado muda o token');
            igual($a, $token(), 'estável sem alteração');
            $j = json_decode((string) file_get_contents($tmp), true);
            $j['teams']['ZZZ_TESTE'] = ['dev_count' => 1, 'productivity' => 1, 'productive_hours' => 1];
            file_put_contents($tmp, json_encode($j));
            $b = $token();
            verdade($b !== $a, 'conteúdo alterado muda o token');
            igual($b, $token(), 'estável após a alteração');
        } finally {
            @unlink($tmp);
        }
    });

    t('relatório: FC da Secretaria com a composição de times_composicao (devs/horas do banco) x Painel', function () use ($obter, $sqlDireta, $sprintsCaso) {
        foreach ($sprintsCaso as $sp) {
            $p = $obter(['jornada' => 'Secretaria e Gestor de Unidades', 'sprint' => $sp]);
            $a = $sqlDireta($p, 'painel');
            $b = $sqlDireta($p, 'banco');
            echo '          ' . $sp . ': Painel ' . ($a['fc'] === null ? 'n/d' : round($a['fc'], 1) . '%')
                . ' | times_composicao ' . ($b['fc'] === null ? 'n/d' : round($b['fc'], 1) . '%') . "\n";
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
        foreach ($alvos as $a) {
            t("referência visao-geral__{$a[0]}: corpo novo sem fator_carga == antes", function () use ($a, $referencia, $ctrl, $norm) {
                $arq = $referencia . '/visao-geral__' . $a[0] . '.json';
                verdade(is_file($arq), 'referência ausente: ' . $arq);
                $f = $a[1] + ['jornada' => 'Secretaria e Gestor de Unidades', 'time' => '', 'versao' => 'PI 4/26', 'sprint' => '', 'modo' => ''];
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
                verdade(isset($p['gadgets']['fator_carga']) && isset($p['metodologia']['fator_carga']), 'blocos novos presentes');
                unset($p['gadgets']['fator_carga'], $p['metodologia']['fator_carga']);
                $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
                igual($norm(json_encode($ref, $flags)), $norm(json_encode($p, $flags)), 'corpo');
                verdade(strlen($novo) < 4194304, 'tamanho < 4 MiB');
            });
        }
    }
}

echo "\n" . $resultados['ok'] . ' PASSOU, ' . $resultados['falha'] . " FALHOU\n";
exit($resultados['falha'] > 0 ? 1 : 0);
