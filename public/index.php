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

// ── Carrega configs ───────────────────────────────────────────────────────
$config         = require BASE_DIR . '/config/jira.php';
$baselineConfig = require BASE_DIR . '/config/baseline.php';
$capacityConfig = require BASE_DIR . '/config/capacity.php';

// ── Factory do repositório de baseline ──────────────────────────────────────
// Hoje: JSON. Futuro: trocar por PdoBaselineRepository (MySQL/PostgreSQL)
// implementando a mesma interface — nenhuma outra linha muda.
$makeBaselineRepo = function () use ($baselineConfig) {
    return new \App\Repositories\JsonBaselineRepository($baselineConfig['storage_dir']);
};

// ── JqlBuilder: fonte única de montagem de JQL (lê config/baseline.php) ──────
// Usado por /extract, /baseline/capture e /metrics, garantindo que todos
// consultem o MESMO universo de itens (projetos + sprints + tipos).
$jqlBuilder = new \App\Services\JqlBuilder($baselineConfig);

// ── Factory do controller de capacidade (histórico + cálculo) ────────────────
$makeCapacityController = function () use ($baselineConfig, $capacityConfig, $makeBaselineRepo) {
    $repo = new \App\Repositories\CapacityRepository($baselineConfig['capacity_dir']);
    $calc = new \App\Services\CapacityCalculator();
    // Injeta: repo de baseline (p/ sync ler progressos), config de regras/PI
    // e config de capacidade (calendário de dias úteis + devs por time).
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

switch (true) {

    // ── Extração Jira → JSON ─────────────────────────────────────────────
    case $uri === '/extract':
        $controller = new \App\Controllers\ExtractorController($config, DATA_DIR, $jqlBuilder);
        $controller->handle();
        break;

    // ── Captura da baseline (snapshot imutável da PI) ────────────────────
    case $uri === '/baseline/capture':
        (new \App\Controllers\BaselineController($config, $baselineConfig, $makeBaselineRepo(), $jqlBuilder))->capture();
        break;

    // ── Snapshot de progresso (evolução/burn-up — acumula) ───────────────
    case $uri === '/baseline/progress':
        (new \App\Controllers\BaselineController($config, $baselineConfig, $makeBaselineRepo(), $jqlBuilder))->progress();
        break;

    // ── Lista de snapshots armazenados ───────────────────────────────────
    case $uri === '/baseline/list':
        (new \App\Controllers\BaselineController($config, $baselineConfig, $makeBaselineRepo(), $jqlBuilder))->list();
        break;

    // ── Métricas consolidadas (Velocidade, Previsibilidade, Scope Creep) ─
    case $uri === '/metrics':
        (new \App\Controllers\BaselineController($config, $baselineConfig, $makeBaselineRepo(), $jqlBuilder))->metrics();
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

    // ── Raiz → SPA ───────────────────────────────────────────────────────
    case $uri === '/' || $uri === '/index.html':
        $html = __DIR__ . '/index.html';
        if (file_exists($html)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($html);
        } else {
            http_response_code(404);
            echo 'index.html não encontrado.';
        }
        break;

    // ── 404 ──────────────────────────────────────────────────────────────
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found', 'uri' => $uri]);
        break;
}
