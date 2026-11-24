<?php
/**
 * bin/teste_legendas.php — TESTES estruturais das legendas "Como é calculado"
 * ─────────────────────────────────────────────────────────────────────────────
 * Sem banco. Confere MetodologiaService (done_statuses == config/baseline.php,
 * blocos completos nas telas PREENCHIDAS), uso de App.Legenda.botao(chave) nas
 * views, ausência de innerHTML nos trechos de legenda e a chave `metodologia`
 * nos endpoints (GraficosController::ARQUIVOS_VERSAO e código). Cobre também os 12 blocos
 * migrados da Visão Geral (valores de config via ctx) e confere que nenhum texto de legenda
 * existe fora do serviço (varredura de views, JS, app, bin e config).
 *
 * Uso: php bin/teste_legendas.php
 * Saída 0 = tudo passou; 1 = alguma falha. Compatível com PHP 7.4 e 8.x.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}
require_once __DIR__ . '/../app/compat.php';
define('BASE_DIR', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $file = BASE_DIR . '/' . str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    if (is_file($file)) require_once $file;
});

use App\Services\MetodologiaService as M;

/** Telas com texto já escrito (cada FEAT acrescenta a sua). */
const PREENCHIDAS = ['velocidade', 'previsibilidade', 'plan-real', 'lead-time', 'produtividade', 'overview', 'epicos', 'sustentacao'];

/** Tela => view que deve usar App.Legenda.botao(chave). */
const VIEWS = [
    'overview'        => 'vw_overview.php',
    'velocidade'      => 'vw_velocity.php',
    'previsibilidade' => 'vw_previsibilidade.php',
    'plan-real'       => 'vw_plan_real.php',
    'lead-time'       => 'vw_cycle_time.php',
    'produtividade'   => 'vw_produtividade.php',
    'epicos'          => 'vw_epicos.php',
    'sustentacao'     => 'vw_sustain.php',
];

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

function exige(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

$baseline = require BASE_DIR . '/config/baseline.php';

t('done_statuses do serviço == config/baseline.php', function () use ($baseline) {
    foreach (array_keys(M::INVENTARIO) as $tela) {
        exige(M::paraTela($tela)['done_statuses'] === array_values($baseline['done_statuses']), "tela {$tela}");
    }
});

t('paraTela devolve contrato e todas as chaves do inventário', function () {
    foreach (M::INVENTARIO as $tela => $chaves) {
        $r = M::paraTela($tela);
        foreach (['done_statuses', 'tipos_valor', 'tipos_sustentacao', 'blocos'] as $k) {
            exige(array_key_exists($k, $r), "{$tela}: falta {$k}");
        }
        exige(array_keys($r['blocos']) === $chaves, "{$tela}: blocos != INVENTARIO");
    }
    exige(M::paraTela('inexistente')['blocos'] === [], 'tela desconhecida deve ter blocos vazios');
});

t('validarBloco aponta campos faltantes', function () {
    exige(count(M::validarBloco([])) === count(M::CAMPOS_TEXTO) + 1, 'bloco vazio');
    $ok = array_fill_keys(M::CAMPOS_TEXTO, 'x') + ['limitacoes' => ['x']];
    exige(M::validarBloco($ok) === [], 'bloco completo');
});

foreach (PREENCHIDAS as $tela) {
    t("blocos completos: {$tela}", function () use ($tela) {
        foreach (M::paraTela($tela)['blocos'] as $chave => $b) {
            $f = M::validarBloco($b);
            exige($f === [], "{$chave}: faltam " . implode(',', $f));
            if (in_array($chave, ['velocidade_grafico', 'velocidade_media'], true)) exige(!empty($b['diferencas_jira']), "{$chave}: diferencas_jira");
        }
    });
    t("view usa App.Legenda.botao para cada chave: {$tela}", function () use ($tela) {
        $src = (string) file_get_contents(BASE_DIR . '/public/views/' . VIEWS[$tela]);
        $chaves = M::INVENTARIO[$tela];
        foreach ($chaves as $c) {
            exige(strpos($src, "App.Legenda.botao('{$c}'") !== false, "{$c} sem botão em " . VIEWS[$tela]);
        }
        preg_match_all("/App\\.Legenda\\.botao\\('([a-z_]+)'/", $src, $m);
        foreach (array_unique($m[1]) as $usada) {
            exige(in_array($usada, $chaves, true), "chave órfã {$usada} em " . VIEWS[$tela]);
        }
        foreach (preg_split('/\R/', $src) as $n => $linha) {
            if (strpos($linha, 'App.Legenda') !== false) {
                exige(stripos($linha, 'innerHTML') === false, 'innerHTML na linha ' . ($n + 1));
            }
        }
    });
}

t('app.js exporta App.Legenda sem innerHTML no trecho', function () {
    $js = (string) file_get_contents(BASE_DIR . '/public/assets/js/app.js');
    exige(strpos($js, 'Legenda') !== false && preg_match('/window\.App\s*=\s*\{[^}]*Legenda/', $js) === 1, 'Legenda não exportada');
    $ini = strpos($js, 'const Legenda');
    $fim = strpos($js, 'window.App =');
    exige($ini !== false && $fim !== false && stripos(substr($js, $ini, $fim - $ini), 'innerHTML') === false, 'innerHTML em Legenda');
});

t('ARQUIVOS_VERSAO contém MetodologiaService.php', function () {
    $src = (string) file_get_contents(BASE_DIR . '/app/Controllers/GraficosController.php');
    exige(strpos($src, "'app/Services/MetodologiaService.php'") !== false, 'ausente');
});

t('5 endpoints de gráfico devolvem metodologia', function () {
    $src = (string) file_get_contents(BASE_DIR . '/app/Controllers/GraficosController.php');
    foreach (['plan-real', 'lead-time', 'velocidade', 'produtividade', 'previsibilidade'] as $tela) {
        exige(strpos($src, "['metodologia' => MetodologiaService::paraTela('{$tela}')]") !== false, $tela);
    }
});

t('Visão Geral mantém chaves atuais de metodologia e acrescenta blocos', function () {
    $src = (string) file_get_contents(BASE_DIR . '/app/Repositories/VisaoGeralRepository.php');
    foreach (['done_statuses', 'tipos_valor', 'tipos_sustentacao', 'status', 'chamados', 'fator_carga', 'debt_load', 'blocos'] as $k) {
        exige(strpos($src, "'{$k}'") !== false, $k);
    }
});

/** Os 12 blocos que antes ficavam em LEGENDAS no JS de vw_overview.php. */
const BLOCOS_MIGRADOS = [
    'andamento', 'aceitos', 'sprint_anterior', 'cycle_time', 'team_velocity', 'burndown',
    'cfd', 'chamados', 'tempo_status', 'efficiency', 'fator_carga', 'debt_load',
];

t('os 12 blocos antigos da Visão Geral estão no serviço e têm botão na view', function () {
    $src = (string) file_get_contents(BASE_DIR . '/public/views/vw_overview.php');
    foreach (BLOCOS_MIGRADOS as $k) {
        exige(in_array($k, M::INVENTARIO['overview'], true), "{$k} fora do INVENTARIO");
        exige(isset(M::paraTela('overview')['blocos'][$k]), "{$k} sem bloco");
        exige(strpos($src, "App.Legenda.botao('{$k}'") !== false, "{$k} sem botão na view");
    }
});

t('nenhum marcador {x} fica sem troca em nenhuma tela', function () {
    foreach (array_keys(M::INVENTARIO) as $tela) {
        foreach (M::paraTela($tela)['blocos'] as $chave => $b) {
            exige(preg_match('/\{[a-z_]+\}/', json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== 1, "{$tela}/{$chave}: marcador sem troca");
        }
    }
});

t('Visão Geral: valores de config entram nos blocos migrados (ctx)', function () {
    $ctx = [
        'status' => ['cancelado' => ['XCanc'], 'bloqueado' => ['XBloq'], 'backlog' => ['XBack'], 'em_andamento' => ['XAnd1', 'XAnd2']],
        'tipos_sustentacao' => ['XSust'],
        'chamados' => ['janela_dias' => 45, 'limite_dias' => 3],
        'fator_carga' => ['disponibilidade_pct' => 75.5, 'incluir_sustentacao' => true, 'fonte_capacidade' => 'painel',
            'faixas' => ['subutilizacao_ate' => 70, 'recomendada_ate' => 90, 'limite' => 110]],
        'debt_load' => ['tipos' => ['XDivida'], 'disponibilidade_pct' => 60, 'fonte_capacidade' => 'painel',
            'efficiency_exclui_debito_tecnico' => true],
        'reconciliacao' => ['aceitos_local_sp' => 12.5, 'aceitos_velocidade_sp' => 1234.5, 'residuo_aceitos' => 0],
    ];
    $b = M::paraTela('overview', $ctx)['blocos'];
    $txt = static function (string $k) use ($b): string { return json_encode($b[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
    exige(strpos($txt('andamento'), 'XBack') !== false && strpos($txt('andamento'), 'XAnd1, XAnd2') !== false, 'status do andamento');
    exige(strpos($txt('chamados'), '45 dias') !== false && strpos($txt('chamados'), '3 dia(s)') !== false, 'chamados janela/limite');
    exige(strpos($txt('fator_carga'), '75,5%') !== false && strpos($txt('fator_carga'), 'entra na demanda') !== false, 'fator_carga disp/sust');
    exige(strpos($txt('fator_carga'), 'abaixo de 70%') !== false && strpos($txt('fator_carga'), 'até 110%') !== false, 'fator_carga faixas');
    exige(strpos($txt('fator_carga'), 'Painel de Controle') !== false, 'fonte painel');
    exige(strpos($txt('debt_load'), 'XDivida') !== false && strpos($txt('debt_load'), '60%') !== false, 'debt_load tipos/disp');
    $seed = M::paraTela('overview', ['fator_carga' => ['fonte_capacidade' => 'config/capacity.php'], 'debt_load' => ['fonte_capacidade' => 'config/capacity.php']])['blocos'];
    exige(strpos(json_encode($seed['debt_load'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'valores padrão de config/capacity.php') !== false, 'fonte seed');
    exige(strpos($txt('efficiency'), 'excluído do h/SP') !== false, 'efficiency exclui DT');
    exige(strpos($txt('aceitos'), 'cálculo local 12,5 SP · Velocidade 1.234,5 SP · resíduo 0 SP') !== false, 'conferência');
    exige(strpos($txt('cancelados'), 'XCanc') !== false, 'cancelado do ctx');
    $semRec = M::paraTela('overview')['blocos']['aceitos'];
    exige(strpos(json_encode($semRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Conferência') === false, 'sem reconciliação não há linha de conferência');
    exige(M::validarBloco($semRec) === [], 'aceitos sem ctx continua completo');
});

t('Chamados: legenda diz Incidente e Bug (regra do código), não os tipos de sustentação', function () {
    $b = M::paraTela('overview')['blocos']['chamados'];
    exige(strpos($b['fonte'], 'Incidente e Bug') !== false && strpos($b['entra'], 'Incidente e Bug') !== false, 'tipos');
    exige(strpos($b['fonte'] . $b['entra'], 'sustentação resolvidos') === false, 'texto antigo');
    $src = (string) file_get_contents(BASE_DIR . '/app/Services/VisaoGeralCalculator.php');
    exige(strpos($src, "\$tipos = ['Incidente', 'Bug'];") !== false, 'tipos fixos do código mudaram: revisar a legenda');
});

t('Visão Geral: repositório entrega os blocos pelo serviço (com ctx)', function () {
    $src = (string) file_get_contents(BASE_DIR . '/app/Repositories/VisaoGeralRepository.php');
    exige(preg_match("/MetodologiaService::paraTela\\(\\s*'overview'\\s*,/", $src) === 1, 'paraTela com ctx');
    exige(strpos($src, "'reconciliacao'") !== false, 'reconciliacao no ctx');
});

t('fonte única: nenhum texto de legenda fora de MetodologiaService', function () {
    $proibidos = ['/\bLEGENDAS\b/', '/\bnaoEntra\b/', '/\bobservacoes\b/', '/[\'"]nao_entra[\'"]\s*(=>|:)/', '/\bonde_no_codigo\b/',
        '/Regra de Done/', '/O que não entra/', '/Fonte no código/', '/Janela\/período/'];
    $arquivos = [];
    foreach (['/public/views', '/public/assets/js', '/app', '/bin', '/config'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_DIR . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array($f->getExtension(), ['php', 'js'], true)) $arquivos[] = $f->getPathname();
        }
    }
    $permitidos = ['MetodologiaService.php', 'app.js', 'teste_legendas.php'];
    $lidos = 0;
    foreach ($arquivos as $f) {
        if (in_array(basename($f), $permitidos, true)) continue;
        $src = (string) file_get_contents($f);
        $lidos++;
        foreach ($proibidos as $re) {
            exige(preg_match($re, $src) !== 1, basename($f) . ' contém texto/estrutura de legenda fora do serviço (' . $re . ')');
        }
    }
    exige($lidos > 20, 'varredura leu poucos arquivos: ' . $lidos);
    // O renderizador único (app.js) é o único lugar com os rótulos de campo.
    $js = (string) file_get_contents(BASE_DIR . '/public/assets/js/app.js');
    exige(strpos($js, 'Regra de Done') !== false, 'renderizador esperado em app.js');
});

echo "\n" . $resultados['ok'] . ' PASSOU, ' . $resultados['falha'] . " FALHOU\n";
exit($resultados['falha'] > 0 ? 1 : 0);
