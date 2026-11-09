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
    private string  $host;
    private string  $email;
    private string  $token;
    private array   $cf;         // custom_fields
    private array   $fieldList;  // field_list
    private bool    $sslVerify;  // verificar certificado SSL
    private ?string $caBundle;   // caminho para CA bundle customizado
    private int     $tentativas; // retry: total de tentativas por requisição
    private float   $esperaBase; // retry: espera base (s) do backoff exponencial
    private float   $esperaMax;  // retry: teto (s) da espera calculada
    private int     $timeout;    // timeout (s) de cada requisição
    /** @var callable|null transporte HTTP injetável (testes); null = cURL */
    private $transport;
    /** @var callable|null função de espera injetável (testes); null = usleep */
    private $sleeper;

    /**
     * @param array         $config    config/jira.php (+ chave opcional 'retry')
     * @param callable|null $transport function(string $url, array $headers, int $timeout):
     *                                 array{status:int, body:string, erro:?string, retry_after:?int}
     * @param callable|null $sleeper   function(float $segundos): void
     */
    public function __construct(array $config, ?callable $transport = null, ?callable $sleeper = null)
    {
        $this->host      = rtrim($config['host'], '/');
        $this->email     = $config['email'];
        $this->token     = $config['token'];
        $this->cf        = $config['custom_fields'];
        $this->fieldList = $config['field_list'];
        $this->sslVerify = $config['ssl_verify'] ?? true;
        $this->caBundle  = $config['ca_bundle']  ?? null;

        $retry            = (array)($config['retry'] ?? []);
        $this->tentativas = max(1, (int)($retry['tentativas'] ?? 4));
        $this->esperaBase = max(0.0, (float)($retry['espera_base_s'] ?? 2));
        $this->esperaMax  = max(0.0, (float)($retry['espera_max_s'] ?? 60));
        $this->timeout    = max(1, (int)($retry['timeout_s'] ?? 30));
        $this->transport  = $transport;
        $this->sleeper    = $sleeper;
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

            // Resposta parcial: o Jira diz que há mais páginas mas não manda o
            // cursor. Parar aqui entregaria um recorte incompleto como se fosse
            // completo (e o DELETE apagaria o resto), então falha.
            if (!$isLast && $nextPageToken === null) {
                throw new \RuntimeException("Resposta parcial do Jira: página {$page} sem nextPageToken e isLast=false");
            }

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

    /**
     * Mapeia uma lista de issues RAW (mesma estrutura do payload do Jira:
     * cada item com 'key' e 'fields') para os campos do template, reutilizando
     * EXATAMENTE a mesma lógica de mapIssue(). Usado pelo modo fixture do
     * runner de extração para validar o pipeline sem acesso de rede ao Jira.
     *
     * @param array[] $rawIssues
     * @return array[]
     */
    public function mapRawIssues(array $rawIssues): array
    {
        return array_map(fn(array $issue) => $this->mapIssue($issue), $rawIssues);
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

        $url = $this->host . '/rest/api/3/search/jql?' . http_build_query($params);
        return $this->requisitar($url);
    }

    /**
     * GET com retry e backoff exponencial (extração noturna sem supervisão).
     *   - TENTA DE NOVO: erro de rede/timeout, HTTP 429 (honra Retry-After),
     *     HTTP 5xx e JSON inválido/truncado;
     *   - NÃO tenta de novo: HTTP 4xx restantes (400/401/403/404...): repetir
     *     não adianta e arrisca bloquear a conta.
     * Cada nova tentativa é registrada no log (canal extracao), sem segredos.
     */
    private function requisitar(string $url): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode("{$this->email}:{$this->token}"),
            'Accept: application/json',
        ];
        $ultimo = 'falha desconhecida';

        for ($t = 1; $t <= $this->tentativas; $t++) {
            $r         = $this->enviar($url, $headers);
            $status    = (int)$r['status'];
            $erro      = (string)($r['erro'] ?? '');
            $retryAft  = isset($r['retry_after']) ? (int)$r['retry_after'] : 0;
            $retentar  = false;

            if ($erro !== '') {
                $ultimo   = "cURL: {$erro}";
                $retentar = true;
            } elseif ($status === 429 || $status >= 500) {
                $ultimo   = "Jira API HTTP {$status}";
                $retentar = true;
            } elseif ($status >= 400) {
                // Falha definitiva (credencial/consulta inválida).
                throw new \RuntimeException("Jira API HTTP {$status}: " . (string)$r['body']);
            } else {
                $decoded = json_decode((string)$r['body'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                $ultimo   = 'Jira retornou JSON inválido';
                $retentar = true;
            }

            if (!$retentar || $t >= $this->tentativas) {
                break;
            }

            $espera = min($this->esperaMax, $this->esperaBase * (2 ** ($t - 1)));
            if ($status === 429 && $retryAft > 0) {
                $espera = (float)min($retryAft, 300); // honra o Retry-After do Jira
            }
            \App\Services\Logger::warning('extracao', 'Jira: falha transitória, nova tentativa', [
                'tentativa' => $t, 'de' => $this->tentativas, 'motivo' => $ultimo,
                'espera_s' => $espera,
            ]);
            $this->dormir($espera);
        }

        throw new \RuntimeException($ultimo . " (após {$this->tentativas} tentativa(s))");
    }

    /** Uma requisição HTTP (transporte injetado ou cURL). */
    private function enviar(string $url, array $headers): array
    {
        if ($this->transport !== null) {
            return (array)call_user_func($this->transport, $url, $headers, $this->timeout);
        }

        $retryAfter = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($curl, string $linha) use (&$retryAfter): int {
                if (stripos($linha, 'Retry-After:') === 0) {
                    $retryAfter = (int)trim(substr($linha, 12));
                }
                return strlen($linha);
            },
        ]);

        // CA bundle customizado (certificado do proxy corporativo)
        if ($this->sslVerify && $this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return [
            'status'      => $httpCode,
            'body'        => is_string($response) ? $response : '',
            'erro'        => $curlErr !== '' ? $curlErr : null,
            'retry_after' => $retryAfter,
        ];
    }

    private function dormir(float $segundos): void
    {
        if ($segundos <= 0) return;
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, $segundos);
            return;
        }
        usleep((int)round($segundos * 1000000));
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
            // Valor BRUTO em SEGUNDOS de aggregatetimespent (aditivo; não
            // quebra consumidores existentes). O runner de extração grava este
            // valor em extracao_jira.soma_tempo_gasto (coluna em SEGUNDOS), para
            // que a view faça soma_tempo_gasto/3600 SEM dupla divisão.
            'tempo_gasto_segundos'           => $this->toSeconds($f['aggregatetimespent'] ?? null),
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

    private function fieldValue($v)
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

    private function toHours($v): ?float
    {
        return (is_numeric($v) && $v > 0) ? round((float) $v / 3600, 2) : null;
    }

    /** Valor bruto de aggregatetimespent em SEGUNDOS (null se vazio/zero). */
    private function toSeconds($v): ?int
    {
        return (is_numeric($v) && $v > 0) ? (int) $v : null;
    }
}
