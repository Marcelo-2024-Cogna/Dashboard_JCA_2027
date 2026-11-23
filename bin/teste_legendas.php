<?php
/**
 * bin/teste_legendas.php — TESTES estruturais das legendas "Como é calculado"
 * ─────────────────────────────────────────────────────────────────────────────
 * Sem banco. Confere MetodologiaService (done_statuses == config/baseline.php,
 * blocos completos nas telas PREENCHIDAS), uso de App.Legenda.botao(chave) nas
 * views, ausência de innerHTML nos trechos de legenda e a chave `metodologia`
 * nos endpoints (GraficosController::ARQUIVOS_VERSAO e código).
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

echo "\n" . $resultados['ok'] . ' PASSOU, ' . $resultados['falha'] . " FALHOU\n";
exit($resultados['falha'] > 0 ? 1 : 0);
