<?php

namespace App\Controllers;

use App\Models\JiraModel;

/**
 * ExtractorController
 * ───────────────────
 * Trata as requisições HTTP de extração de dados do Jira.
 * Gerencia o cache em data/items.json e devolve JSON ao cliente.
 *
 * Parâmetros GET aceitos:
 *   force=1        Ignora cache e reextrai do Jira
 *   jql=...        JQL customizada
 *   sprints=a,b,c  Atalho: monta JQL filtrando pelas sprints informadas
 *   max=100        Itens por página (máx 500)
 */
class ExtractorController
{
    private array  $config;
    private string $cacheFile;

    public function __construct(array $config, string $dataDir)
    {
        $this->config    = $config;
        $this->cacheFile = rtrim($dataDir, '/\\') . '/items.json';
    }

    // ── Ponto de entrada ─────────────────────────────────────────────────

    public function handle(): void
    {
        $this->sendHeaders();

        $force      = !empty($_GET['force']);
        $maxPerPage = min((int)($_GET['max'] ?? 100), 500);
        $jql        = $this->resolveJql();

        // Tenta servir do cache
        if (!$force && $this->isCacheValid()) {
            $cached = json_decode(file_get_contents($this->cacheFile), true);
            $cached['_cache']           = true;
            $cached['_cache_age_seconds'] = time() - filemtime($this->cacheFile);
            $this->respond($cached);
            return;
        }

        // Extrai do Jira
        try {
            $model  = new JiraModel($this->config);
            $result = $model->fetchAll($jql, $maxPerPage);

            $output = [
                'extracted_at' => date('Y-m-d H:i:s'),
                'jql'          => $jql,
                'total_items'  => count($result['items']),
                'pages'        => $result['pages'],
                '_cache'       => false,
                'logs'         => $result['logs'],
                'items'        => $result['items'],
            ];

            $this->saveCache($output);
            $this->respond($output);

        } catch (\RuntimeException $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ── JQL ──────────────────────────────────────────────────────────────

    private function resolveJql(): string
    {
        if (!empty($_GET['jql'])) {
            return trim($_GET['jql']);
        }

        if (!empty($_GET['sprints'])) {
            $sprints    = array_map('trim', explode(',', $_GET['sprints']));
            $sprintList = implode('","', $sprints);
            return 'project in (ADKB,ADMD,ADPL,ADR6,ADTH,ADTP,ADGP,ADSE,ASEC,ATEN,ASPL,SGGU,SGTB,SDIA)'
                . ' AND issuetype in ("Débito Técnico", Enabler, Story, Spike, Teste, Incidente,'
                . ' "Solicitação de Serviço", bug, Sustentação, Suporte)'
                . ' AND sprint in ("' . $sprintList . '")'
                . ' ORDER BY project ASC, created DESC';
        }

        return $this->config['default_jql'];
    }

    // ── Cache ─────────────────────────────────────────────────────────────

    private function isCacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }
        $age = time() - filemtime($this->cacheFile);
        return $age < $this->config['cache_ttl'];
    }

    private function saveCache(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->cacheFile,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    // ── Resposta ──────────────────────────────────────────────────────────

    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache');
    }

    private function respond(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function respondError(string $message, int $status = 500): void
    {
        $this->respond(['success' => false, 'error' => $message], $status);
    }
}
