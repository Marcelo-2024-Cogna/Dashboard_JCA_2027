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

// ── Carrega config ──────────────────────────────────────────────────────────
$config = require BASE_DIR . '/config/jira.php';

// ── Roteamento ───────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Remove prefixo do subdiretório (ex: /dashboard/public → /)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($scriptDir && str_starts_with($uri, $scriptDir)) {
    $uri = substr($uri, strlen($scriptDir));
}
$uri = '/' . ltrim($uri ?: '/', '/');

switch (true) {

    // ── Extração Jira → JSON ─────────────────────────────────────────────
    case $uri === '/extract':
        $controller = new \App\Controllers\ExtractorController($config, DATA_DIR);
        $controller->handle();
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
