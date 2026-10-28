<?php
/**
 * public/index.php — Front Controller
 * ─────────────────────────────────────
 * Único ponto de entrada HTTP do dashboard.
 * Roteia as requisições para os controllers apropriados.
 *
 * Rotas:
 *   GET  /          → serve public/index.html (SPA)
 *   GET  /extract   → ExtractorController::handle()
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');

// ── Autoload simples (sem Composer) ────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    // Converte "App\Controllers\Foo" → "BASE_DIR/app/Controllers/Foo.php"
    $relative = str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    $file = BASE_DIR . '/' . $relative;
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Carrega configs PHP (seed + partes não-editáveis) ───────────────────────
$config         = require BASE_DIR . '/config/jira.php';
$phpBaseline    = require BASE_DIR . '/config/baseline.php';
$phpCapacity    = require BASE_DIR . '/config/capacity.php';
$phpPi          = require BASE_DIR . '/config/pi.php';
$phpVersion     = require BASE_DIR . '/config/version.php';

// ── ConfigRepository: parâmetros EDITÁVEIS (JSON em data/config/ com
//    fallback para os config/*.php). É a fonte de verdade operacional. ───────
$configRepo = new \App\Repositories\ConfigRepository(
    DATA_DIR . '/config', $phpPi, $phpCapacity
);

// Parâmetros EFETIVOS (JSON se salvo pelo Painel; senão, seed dos PHP).
// baselineConfig = regras de negócio (PHP) + PI efetiva (JSON/seed).
$effectivePi    = $configRepo->getPi();
$baselineConfig = array_merge($phpBaseline, $effectivePi);
// capacityConfig no formato legado que os Services já consomem.
$capacityConfig = $configRepo->capacityAsLegacyMaps();

// ── Factory do repositório de baseline ──────────────────────────────────────
// Hoje: JSON. Futuro: trocar por PdoBaselineRepository (MySQL/PostgreSQL)
// implementando a mesma interface — nenhuma outra linha muda.
$makeBaselineRepo = function () use ($baselineConfig) {
    return new \App\Repositories\JsonBaselineRepository($baselineConfig['storage_dir']);
};

// ── JqlBuilder: fonte única de montagem de JQL ──────────────────────────────
// Usa a PI EFETIVA (pode ter sido editada pelo Painel).
$jqlBuilder = new \App\Services\JqlBuilder($baselineConfig);

// ── Factory do controller de baseline/métricas ──────────────────────────────
// Recebe o capacityConfig (sprint_dates) para a janela de sprints fechadas.
$makeBaselineController = function () use ($config, $baselineConfig, $capacityConfig, $makeBaselineRepo, $jqlBuilder) {
    return new \App\Controllers\BaselineController(
        $config, $baselineConfig, $makeBaselineRepo(), $jqlBuilder, $capacityConfig
    );
};

// ── Factory do controller de capacidade (histórico + cálculo) ────────────────
$makeCapacityController = function () use ($baselineConfig, $capacityConfig, $makeBaselineRepo) {
    $repo = new \App\Repositories\CapacityRepository($baselineConfig['capacity_dir']);
    $calc = new \App\Services\CapacityCalculator();
    return new \App\Controllers\CapacityController(
        $repo, $calc, $makeBaselineRepo(), $baselineConfig, $capacityConfig
    );
};

// ── Roteamento ───────────────────────────────────────────────────────────────
// A URL chega como /dashboard/extract, /dashboard/public/extract, /dashboard/ etc.
// Normalizamos removendo o prefixo do projeto (/dashboard e /dashboard/public)
// para obter a rota "limpa" (ex: /extract, /).
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Remove prefixos conhecidos do subdiretório, na ordem mais específica primeiro
foreach (['/dashboard/public', '/dashboard'] as $prefix) {
    if (str_starts_with($uri, $prefix)) {
        $uri = substr($uri, strlen($prefix));
        break;
    }
}

// Normaliza: garante uma barra inicial e remove barra final redundante
$uri = '/' . trim($uri, '/');

// Prefixo da app no servidor (ex: "/dashboard"), para montar URLs nas views.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '/dashboard';
if (preg_match('#^(/.*?)/public/index\.php$#', $scriptName, $m)) {
    $basePath = $m[1];
} elseif (preg_match('#^(/.*?)/index\.php$#', $scriptName, $m)) {
    $basePath = $m[1];
}

// Factory do controller de views (páginas PHP tradicionais)
$makeViewController = function () use ($basePath, $baselineConfig, $phpVersion) {
    return new \App\Controllers\ViewController(__DIR__ . '/views', $basePath, [
        'value_types' => $baselineConfig['value_delivery_types'] ?? [],
        'version'     => $phpVersion['label'] ?? '',
    ]);
};

switch (true) {

    // ── Extração Jira → JSON ─────────────────────────────────────────────
    case $uri === '/extract':
        $controller = new \App\Controllers\ExtractorController($config, DATA_DIR, $jqlBuilder);
        $controller->handle();
        break;

    // ── Captura da baseline (snapshot imutável da PI) ────────────────────
    case $uri === '/baseline/capture':
        $makeBaselineController()->capture();
        break;

    // ── Snapshot de progresso (evolução/burn-up — acumula) ───────────────
    case $uri === '/baseline/progress':
        $makeBaselineController()->progress();
        break;

    // ── Lista de snapshots armazenados ───────────────────────────────────
    case $uri === '/baseline/list':
        $makeBaselineController()->list();
        break;

    // ── Métricas consolidadas (Velocidade, Previsibilidade, Scope Creep) ─
    case $uri === '/metrics':
        $makeBaselineController()->metrics();
        break;

    // ── Capacidade e Velocidade dos times (PI Planning) ──────────────────
    case $uri === '/capacity/plan':
        $makeCapacityController()->plan();
        break;

    case $uri === '/capacity/sprint':
        $makeCapacityController()->addSprint();
        break;

    case $uri === '/capacity/sync':
        $makeCapacityController()->sync();
        break;

    case $uri === '/capacity/history':
        $makeCapacityController()->history();
        break;

    // ── Painel de Controle: parâmetros editáveis (PI + Capacidade) ───────
    // Rota /settings (não /config) para não colidir com a pasta física
    // config/ na raiz do projeto, que faz o Apache redirecionar (301).
    case $uri === '/settings':
        (new \App\Controllers\ConfigController($configRepo))->show();
        break;

    case $uri === '/settings/pi':
        (new \App\Controllers\ConfigController($configRepo))->savePi();
        break;

    case $uri === '/settings/capacity':
        (new \App\Controllers\ConfigController($configRepo))->saveCapacity();
        break;

    // ── Fila de extração (v1.5 / 6b-1): catálogo, agendar, fila ──────────
    case $uri === '/extracao/catalogo':
    case $uri === '/extracao/agendar':
    case $uri === '/extracao/fila':
        $extracaoCtl = new \App\Controllers\ExtractionController(
            new \App\Repositories\CatalogRepository(),
            new \App\Repositories\ExtractionQueueRepository(),
            require BASE_DIR . '/config/extracao.php',
            BASE_DIR
        );
        match ($uri) {
            '/extracao/catalogo' => $extracaoCtl->catalogo(),
            '/extracao/agendar'  => $extracaoCtl->agendar(),
            '/extracao/fila'     => $extracaoCtl->fila(),
        };
        break;

    // ── Home → Visão Geral ───────────────────────────────────────────────
    case $uri === '/' || $uri === '/index.html':
        $makeViewController()->render('overview');
        break;

    // ── Páginas de métricas: /metricas/{secao} ───────────────────────────
    case preg_match('#^/metricas/([a-z0-9\-]+)$#', $uri, $mv) === 1:
        $makeViewController()->render($mv[1]);
        break;

    // ── Painel de Controle ────────────────────────────────────────────────
    case $uri === '/painel':
        $makeViewController()->render('painel');
        break;

    // ── 404 ──────────────────────────────────────────────────────────────
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        break;
}
