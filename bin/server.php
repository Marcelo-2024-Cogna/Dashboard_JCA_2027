<?php
/**
 * bin/server.php — Entry point MCP (Model Context Protocol)
 * ───────────────────────────────────────────────────────────
 * Ponto de entrada para o canal CLI/STDIO, invocado pelo Kiro
 * (configurado em .kiro/settings/mcp.json).
 *
 * É apenas um bootstrap: carrega autoload + config e delega todo
 * o protocolo JSON-RPC ao McpController.
 *
 * Uso: php bin/server.php   (lê JSON-RPC do STDIN, responde no STDOUT)
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('BASE_DIR', dirname(__DIR__));

// Autoload — mesmo mecanismo do front controller (public/index.php)
spl_autoload_register(function (string $class): void {
    $relative = str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    $file = BASE_DIR . '/' . $relative;
    if (file_exists($file)) {
        require_once $file;
    }
});

$config = require BASE_DIR . '/config/jira.php';

$controller = new \App\Controllers\McpController($config);
$controller->serve();
