<?php
/**
 * server.php — Servidor MCP (Model Context Protocol)
 * ────────────────────────────────────────────────────
 * Comunicação via STDIO + JSON-RPC 2.0.
 * Executado como processo externo pelo Kiro (configurado em .kiro/settings/mcp.json).
 *
 * Ferramentas expostas:
 *   get_jira_items    — Busca itens via JQL com os 33 campos do Template Métricas Review
 *   get_sprint_metrics — Atalho: sprint ativa de um projeto
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('BASE_DIR', __DIR__);

// Autoload — mesmo mecanismo do front controller
spl_autoload_register(function (string $class): void {
    $relative = str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    $file = BASE_DIR . '/' . $relative;
    if (file_exists($file)) {
        require_once $file;
    }
});

$config = require BASE_DIR . '/config/jira.php';

// ── Helpers MCP ────────────────────────────────────────────────────────────

function send(array $data): void
{
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

function sendError(mixed $id, int $code, string $message): void
{
    send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

// ── Schemas das ferramentas ────────────────────────────────────────────────

const TOOLS = [
    [
        'name'        => 'get_jira_items',
        'description' => 'Busca itens do Jira via JQL e retorna os 33 campos do Template Métricas Review: '
            . 'tipo, resumo, projeto, chave, status, sprint, story points, ciclos '
            . '(ideação/desenvolvimento/homologação/implantação), lead time, customer lead time, '
            . 'tipo de sustentação, antecipação, ambiente e tempo bloqueado.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'jql' => [
                    'type'        => 'string',
                    'description' => 'JQL para filtrar os itens. Ex: project = ADPL AND sprint in openSprints()',
                ],
                'max_results' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de itens retornados por página (padrão: 100, máximo: 500)',
                    'default'     => 100,
                ],
            ],
            'required' => ['jql'],
        ],
    ],
    [
        'name'        => 'get_sprint_metrics',
        'description' => 'Atalho para get_jira_items: busca todos os itens da sprint ativa de um projeto.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'project' => [
                    'type'        => 'string',
                    'description' => 'Chave do projeto Jira (ex: ADPL). Omita para buscar em todos os projetos.',
                ],
                'max_results' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de itens (padrão: 100)',
                    'default'     => 100,
                ],
            ],
        ],
    ],
];

// ── Loop principal MCP (STDIO / JSON-RPC 2.0) ──────────────────────────────

$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, true);

while (!feof($stdin)) {
    $line = fgets($stdin);
    if ($line === false || trim($line) === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['jsonrpc'])) {
        continue;
    }

    $id     = $request['id']     ?? null;
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];

    // ── initialize ────────────────────────────────────────────────────────
    if ($method === 'initialize') {
        send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => ['tools' => (object)[]],
                'serverInfo'      => ['name' => 'jira-metrics-mcp', 'version' => '3.0.0'],
            ],
        ]);
        continue;
    }

    if ($method === 'notifications/initialized') {
        continue;
    }

    // ── tools/list ────────────────────────────────────────────────────────
    if ($method === 'tools/list') {
        send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => TOOLS]]);
        continue;
    }

    // ── tools/call ────────────────────────────────────────────────────────
    if ($method === 'tools/call') {
        $toolName = $params['name']      ?? '';
        $args     = $params['arguments'] ?? [];

        // Normaliza get_sprint_metrics → get_jira_items
        if ($toolName === 'get_sprint_metrics') {
            $project = trim($args['project'] ?? '');
            $jql = $project
                ? "project = {$project} AND sprint in openSprints()"
                  . " AND issuetype in (\"Débito Técnico\", Enabler, Story, Spike, Teste,"
                  . " Incidente, \"Solicitação de Serviço\", bug, Sustentação, Suporte)"
                  . " ORDER BY created DESC"
                : "sprint in openSprints() AND issuetype in (standardIssueTypes()) ORDER BY created DESC";

            $args     = ['jql' => $jql, 'max_results' => (int)($args['max_results'] ?? 100)];
            $toolName = 'get_jira_items';
        }

        if ($toolName === 'get_jira_items') {
            $jql        = trim($args['jql'] ?? '');
            $maxResults = min(max((int)($args['max_results'] ?? 100), 1), 500);

            if ($jql === '') {
                send(['jsonrpc'=>'2.0','id'=>$id,'result'=>['isError'=>true,'content'=>[['type'=>'text','text'=>'Parâmetro "jql" é obrigatório.']]]]);
                continue;
            }

            try {
                $model  = new \App\Models\JiraModel($config);
                $result = $model->fetchAll($jql, $maxResults);

                $payload = [
                    'returned' => count($result['items']),
                    'pages'    => $result['pages'],
                    'items'    => $result['items'],
                ];

                send([
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'content' => [[
                            'type' => 'text',
                            'text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]);

            } catch (\RuntimeException $e) {
                send(['jsonrpc'=>'2.0','id'=>$id,'result'=>['isError'=>true,'content'=>[['type'=>'text','text'=>'Erro: '.$e->getMessage()]]]]);
            }

            continue;
        }

        sendError($id, -32601, "Unknown tool: {$toolName}");
        continue;
    }

    if ($id !== null) {
        sendError($id, -32601, "Method not found: {$method}");
    }
}
