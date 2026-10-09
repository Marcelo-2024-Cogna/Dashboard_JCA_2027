<?php

namespace App\Models;

/**
 * JiraModel
 * ─────────
 * Responsável por se comunicar com a API REST do Jira e mapear
 * os issues para os 33 campos do Template Métricas Review.
 *
 * Não conhece HTTP de entrada nem saída — só fala com o Jira.
 */
class JiraModel
{
    private string $host;
    private string $email;
    private string $token;
    private array  $cf;         // custom_fields
    private array  $fieldList;  // field_list

    public function __construct(array $config)
    {
        $this->host      = rtrim($config['host'], '/');
        $this->email     = $config['email'];
        $this->token     = $config['token'];
        $this->cf        = $config['custom_fields'];
        $this->fieldList = $config['field_list'];
    }

    // ── Busca paginada completa ───────────────────────────────────────────

    /**
     * Busca todos os itens que satisfazem a JQL, paginando automaticamente.
     * Retorna [ 'items' => [...], 'pages' => int, 'logs' => [...] ]
     */
    public function fetchAll(string $jql, int $maxPerPage = 100): array
    {
        $allItems      = [];
        $nextPageToken = null;
        $page          = 0;
        $logs          = [];

        do {
            $page++;
            $data    = $this->fetchPage($jql, $maxPerPage, $nextPageToken);
            $batch   = $data['issues'] ?? [];
            $isLast  = (bool)($data['isLast'] ?? true);
            $nextPageToken = $data['nextPageToken'] ?? null;

            foreach ($batch as $issue) {
                $allItems[] = $this->mapIssue($issue);
            }

            $logs[] = "Página {$page}: " . count($batch) . ' itens';

            if (empty($batch)) {
                break;
            }
        } while (!$isLast && $nextPageToken !== null);

        return [
            'items' => $allItems,
            'pages' => $page,
            'logs'  => $logs,
        ];
    }

    // ── Busca uma página ─────────────────────────────────────────────────

    /**
     * Chama GET /rest/api/3/search/jql com cursor-based pagination.
     */
    private function fetchPage(string $jql, int $maxResults, ?string $nextPageToken): array
    {
        $params = [
            'jql'        => $jql,
            'maxResults' => $maxResults,
            'fields'     => implode(',', $this->fieldList),
        ];

        if ($nextPageToken !== null) {
            $params['nextPageToken'] = $nextPageToken;
        }

        $url  = $this->host . '/rest/api/3/search/jql?' . http_build_query($params);
        $auth = base64_encode("{$this->email}:{$this->token}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $auth,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("cURL: {$curlErr}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Jira API HTTP {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new \RuntimeException('Jira retornou JSON inválido');
        }

        return $decoded;
    }

    // ── Mapeamento dos 33 campos ─────────────────────────────────────────

    /**
     * Transforma um issue raw do Jira nos 33 campos do template.
     */
    private function mapIssue(array $issue): array
    {
        $f  = $issue['fields'];
        $cf = fn(string $alias) => $this->fieldValue($f[$this->cf[$alias]] ?? null);

        // Sprint: array de objetos com ->name
        $sprintRaw = $f[$this->cf['sprint']] ?? null;
        $sprintVal = (is_array($sprintRaw) && $sprintRaw)
            ? implode('; ', array_map(fn($s) => $s['name'] ?? '', $sprintRaw))
            : null;

        // Versões corrigidas
        $fixVersions = !empty($f['fixVersions'])
            ? implode('; ', array_map(fn($v) => $v['name'] ?? '', $f['fixVersions']))
            : null;

        return [
            /* 1  */ 'tipo_item'             => $this->fieldValue($f['issuetype'] ?? null),
            /* 2  */ 'resumo'                => $f['summary'] ?? null,
            /* 3  */ 'projeto'               => $this->fieldValue($f['project'] ?? null),
            /* 4  */ 'chave'                 => $issue['key'],
            /* 5  */ 'status'                => $this->fieldValue($f['status'] ?? null),
            /* 6  */ 'versoes_corrigidas'    => $fixVersions,
            /* 7  */ 'sprint'                => $sprintVal,
            /* 8  */ 'story_points'          => $f[$this->cf['story_points']]         ?? null,
            /* 9  */ 'story_point_estimate'  => $f[$this->cf['story_point_estimate']] ?? null,
            /* 10 */ 'tempo_gasto_horas'     => $this->toHours($f['aggregatetimespent'] ?? null),
            /* 11 */ 'tipo'                  => $cf('tipo'),
            /* 12 */ 'categoria_status'      => $f['status']['statusCategory']['name'] ?? null,
            /* 13 */ 'pai'                   => $f['parent']['key'] ?? null,
            /* 14 */ 'alteracao_escopo'      => $cf('alteracao_escopo'),
            /* 15 */ 'resolucao'             => $this->fieldValue($f['resolution'] ?? null),
            /* 16 */ 'criado'                => $f['created'] ?? null,
            /* 17 */ 'ponto_compromisso'     => $cf('ponto_compromisso'),
            /* 18 */ 'inicio_fluxo'          => $cf('inicio_fluxo'),
            /* 19 */ 'fim_fluxo'             => $cf('fim_fluxo'),
            /* 20 */ 'inicio_homologacao'    => $cf('inicio_homologacao'),
            /* 21 */ 'fim_homologacao'       => $cf('fim_homologacao'),
            /* 22 */ 'inicio_implantacao'    => $cf('inicio_implantacao'),
            /* 23 */ 'fim_implantacao'       => $cf('fim_implantacao'),
            /* 24 */ 'ciclo_ideacao'         => $cf('ciclo_ideacao'),
            /* 25 */ 'ciclo_desenvolvimento' => $cf('ciclo_desenvolvimento'),
            /* 26 */ 'ciclo_homologacao'     => $cf('ciclo_homologacao'),
            /* 27 */ 'ciclo_implantacao'     => $cf('ciclo_implantacao'),
            /* 28 */ 'lead_time'             => $cf('lead_time'),
            /* 29 */ 'customer_lead_time'    => $cf('customer_lead_time'),
            /* 30 */ 'tipo_sustentacao'      => $cf('tipo_sustentacao'),
            /* 31 */ 'antecipacao'           => $cf('antecipacao'),
            /* 32 */ 'ambiente'              => $cf('ambiente'),
            /* 33 */ 'tempo_total_bloqueado' => $cf('tempo_total_bloqueado'),
        ];
    }

    // ── Helpers de valor ─────────────────────────────────────────────────

    private function fieldValue(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            foreach (['name', 'value', 'displayName', 'key'] as $k) {
                if (isset($v[$k])) {
                    return $v[$k];
                }
            }
            if (array_is_list($v)) {
                $parts = array_filter(array_map([$this, 'fieldValue'], $v));
                return $parts ? implode('; ', $parts) : null;
            }
            return null;
        }
        return $v;
    }

    private function toHours(mixed $v): ?float
    {
        return (is_numeric($v) && $v > 0) ? round((float) $v / 3600, 2) : null;
    }
}
