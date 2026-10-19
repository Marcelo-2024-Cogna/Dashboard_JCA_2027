<?php

namespace App\Controllers;

use App\Models\JiraModel;

/**
 * McpController
 * ─────────────
 * Implementa o protocolo MCP (Model Context Protocol) sobre JSON-RPC 2.0,
 * comunicando via STDIO. É o equivalente ao ExtractorController, mas para o
 * canal CLI/processo em vez de HTTP.
 *
 * Expõe as ferramentas:
 *   get_jira_items     — Busca itens via JQL (33 campos do Template)
 *   get_sprint_metrics — Atalho para a sprint ativa de um projeto
 */
class McpController
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'jira-metrics-mcp';
    private const SERVER_VERSION   = '3.0.0';

    /** Definição das ferramentas expostas pelo servidor. */
    private const TOOLS = [
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

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ── Loop principal (STDIO) ───────────────────────────────────────────

    /**
     * Lê requisições JSON-RPC do STDIN linha a linha e responde no STDOUT.
     * Bloqueia até o STDIN ser fechado (comportamento esperado de servidor MCP).
     */
    public function serve(): void
    {
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

            $this->dispatch($request);
        }

        fclose($stdin);
    }

    // ── Roteamento de métodos JSON-RPC ───────────────────────────────────

    private function dispatch(array $request): void
    {
        $id     = $request['id']     ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        switch ($method) {
            case 'initialize':
                $this->handleInitialize($id);
                break;

            case 'notifications/initialized':
                // Notificação, não exige resposta
                break;

            case 'tools/list':
                $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => self::TOOLS]]);
                break;

            case 'tools/call':
                $this->handleToolCall($id, $params);
                break;

            default:
                if ($id !== null) {
                    $this->sendError($id, -32601, "Method not found: {$method}");
                }
        }
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    private function handleInitialize(mixed $id): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities'    => ['tools' => (object)[]],
                'serverInfo'      => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            ],
        ]);
    }

    private function handleToolCall(mixed $id, array $params): void
    {
        $toolName = $params['name']      ?? '';
        $args     = $params['arguments'] ?? [];

        // Normaliza get_sprint_metrics → get_jira_items
        if ($toolName === 'get_sprint_metrics') {
            $args     = $this->buildSprintMetricsArgs($args);
            $toolName = 'get_jira_items';
        }

        if ($toolName === 'get_jira_items') {
            $this->handleGetJiraItems($id, $args);
            return;
        }

        $this->sendError($id, -32601, "Unknown tool: {$toolName}");
    }

    private function handleGetJiraItems(mixed $id, array $args): void
    {
        $jql        = trim($args['jql'] ?? '');
        $maxResults = min(max((int)($args['max_results'] ?? 100), 1), 500);

        if ($jql === '') {
            $this->sendToolError($id, 'Parâmetro "jql" é obrigatório.');
            return;
        }

        try {
            $model  = new JiraModel($this->config);
            $result = $model->fetchAll($jql, $maxResults);

            $payload = [
                'returned' => count($result['items']),
                'pages'    => $result['pages'],
                'items'    => $result['items'],
            ];

            $this->sendToolResult($id, $payload);

        } catch (\RuntimeException $e) {
            $this->sendToolError($id, 'Erro: ' . $e->getMessage());
        }
    }

    // ── Construção de JQL ──────────────────────────────────────────────────

    private function buildSprintMetricsArgs(array $args): array
    {
        $project = trim($args['project'] ?? '');

        $jql = $project !== ''
            ? "project = {$project} AND sprint in openSprints()"
              . " AND issuetype in (\"Débito Técnico\", Enabler, Story, Spike, Teste,"
              . " Incidente, \"Solicitação de Serviço\", bug, Sustentação, Suporte)"
              . " ORDER BY created DESC"
            : "sprint in openSprints() AND issuetype in (standardIssueTypes()) ORDER BY created DESC";

        return [
            'jql'         => $jql,
            'max_results' => (int)($args['max_results'] ?? 100),
        ];
    }

    // ── Emissão de mensagens ────────────────────────────────────────────────

    private function send(array $data): void
    {
        fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    private function sendToolResult(mixed $id, array $payload): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ]],
            ],
        ]);
    }

    private function sendToolError(mixed $id, string $message): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $message]],
            ],
        ]);
    }
}
