<?php

namespace App\Controllers;

use App\Database;
use App\Repositories\LeadTimeRepository;
use App\Repositories\PlanejadoRealizadoRepository;
use App\Repositories\PrevisibilidadeRepository;
use App\Repositories\ProdutividadeRepository;
use App\Repositories\VelocidadeRepository;
use App\Repositories\VisaoGeralRepository;
use App\Services\Logger;
use App\Srv\srvRespostaCache;
use InvalidArgumentException;
use Throwable;

/**
 * GraficosController
 * ──────────────────
 * Endpoints JSON dos gráficos (v1.5), lendo do BANCO (views).
 *
 *   GET /graficos/previsibilidade?jornada=&time=&versao=&sprint=
 *   GET /graficos/plan-real?jornada=&time=&versao=&sprint=
 *   GET /graficos/lead-time?jornada=&time=&versao=&sprint=
 *   GET /graficos/produtividade?jornada=&time=&versao=&sprint=
 *   GET /graficos/velocidade?jornada=&time=&versao=&sprint=
 *
 * Respostas: 200 JSON · 400 filtro inválido/inexistente · 405 método ·
 * 500 falha interna (detalhe só no log, canal 'dashboard').
 */
class GraficosController
{
    private const FILTROS = ['jornada', 'time', 'versao', 'sprint'];

    private PrevisibilidadeRepository $previsibilidade;
    private PlanejadoRealizadoRepository $planejadoRealizado;
    private LeadTimeRepository $leadTime;
    private ProdutividadeRepository $produtividade;
    private VelocidadeRepository $velocidade;
    private ?VisaoGeralRepository $visaoGeral;
    private ?srvRespostaCache $cacheVisaoGeral;

    /** Mesmas flags de respond(): o corpo em cache tem de ser idêntico ao sem cache. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** Arquivos cujo conteúdo, se mudar (deploy), invalida todo o cache da Visão Geral. */
    private const ARQUIVOS_VERSAO = [
        'app/Repositories/VisaoGeralRepository.php',
        'app/Repositories/VelocidadeRepository.php',
        'app/Repositories/LeadTimeRepository.php',
        'app/Repositories/ProdutividadeRepository.php',
        'app/Repositories/PrevisibilidadeRepository.php',
        'app/Repositories/PlanejadoRealizadoRepository.php',
        'app/Repositories/ConfigRepository.php',
        'config/capacity.php',
        'app/Services/VisaoGeralCalculator.php',
        'app/Services/SprintRef.php',
        'app/Services/DiasUteis.php',
        'app/Controllers/GraficosController.php',
        'app/Srv/srvRespostaCache.php',
        'app/Database.php',
        'config/visao_geral.php',
        'config/baseline.php',
        'config/version.php',
        'config/app.php',
        'db/views.sql',
    ];

    public function __construct(
        PrevisibilidadeRepository $previsibilidade,
        ?PlanejadoRealizadoRepository $planejadoRealizado = null,
        ?LeadTimeRepository $leadTime = null,
        ?ProdutividadeRepository $produtividade = null,
        ?VelocidadeRepository $velocidade = null,
        ?VisaoGeralRepository $visaoGeral = null,
        ?srvRespostaCache $cacheVisaoGeral = null
    ) {
        $this->cacheVisaoGeral    = $cacheVisaoGeral;
        $this->velocidade         = $velocidade ?? new VelocidadeRepository($previsibilidade);
        $this->produtividade      = $produtividade ?? new ProdutividadeRepository($previsibilidade);
        $this->previsibilidade    = $previsibilidade;
        $this->planejadoRealizado = $planejadoRealizado ?? new PlanejadoRealizadoRepository($previsibilidade);
        $this->leadTime           = $leadTime ?? new LeadTimeRepository($previsibilidade);
        $this->visaoGeral         = $visaoGeral;
    }

    // ── GET /graficos/plan-real ──────────────────────────────────────────
    public function planReal(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $filtros = $this->lerFiltros();
            $this->respond(200, ['success' => true] + $this->planejadoRealizado->consultar($filtros));
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/plan-real → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/plan-real: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular o Planejado x Realizado.']);
        }
    }

    // ── GET /graficos/lead-time ──────────────────────────────────────────
    public function leadTime(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $filtros = $this->lerFiltros();
            $this->respond(200, ['success' => true] + $this->leadTime->consultar($filtros));
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/lead-time → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/lead-time: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular o Lead Time.']);
        }
    }

    // ── GET /graficos/velocidade ─────────────────────────────────────────
    public function velocidade(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $filtros = $this->lerFiltros();
            $this->respond(200, ['success' => true] + $this->velocidade->consultar($filtros));
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/velocidade → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/velocidade: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular a Velocidade.']);
        }
    }

    // ── GET /graficos/produtividade ──────────────────────────────────────
    public function produtividade(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $filtros = $this->lerFiltros();
            $this->respond(200, ['success' => true] + $this->produtividade->consultar($filtros));
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/produtividade → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/produtividade: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular a Produtividade.']);
        }
    }

    // ── GET /graficos/visao-geral ─────────────────────────────────────────
    public function visaoGeral(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $this->repoVisaoGeral();
            $filtros = $this->lerFiltros(true);
            $r = $this->obterVisaoGeral($filtros);

            header('X-Cache: ' . $r['estado']);
            if ($r['etag'] !== null) {
                header('ETag: ' . $r['etag']);
                header('Cache-Control: private, no-cache');
                $inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
                if ($inm !== '' && self::etagCorresponde($inm, $r['etag'])) {
                    http_response_code(304);
                    header_remove('Content-Type');
                    return;
                }
            }
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo $r['corpo'];
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/visao-geral → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/visao-geral: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular a Visão Geral.']);
        }
    }

    private function repoVisaoGeral(): VisaoGeralRepository
    {
        if ($this->visaoGeral === null) {
            $this->visaoGeral = new VisaoGeralRepository($this->previsibilidade, $this->velocidade, $this->produtividade);
        }
        return $this->visaoGeral;
    }

    /**
     * Corpo da Visão Geral (com cache de arquivo quando ativo). Usado pela rota e
     * pelo aquecedor (mesmo caminho de código, sem HTTP). Exceções propagam
     * (InvalidArgumentException = 400); nada de erro é gravado.
     *
     * @param array<string,string> $filtros jornada, time, versao, sprint, modo
     * @param bool $inspecionar true = só informa o estado da entrada (HIT/STALE/MISS), sem gerar nem gravar
     * @return array{status:int,corpo:string,estado:string,etag:?string,validade:?string,idade_s:?int}
     * @throws InvalidArgumentException filtro inválido
     */
    public function obterVisaoGeral(array $filtros, ?int $agora = null, bool $inspecionar = false): array
    {
        $agora = $agora ?? time();
        $hoje  = date('Y-m-d', $agora);
        $cfg   = $this->configCacheVisaoGeral();

        $resolvido = null;
        $gerar = function () use ($filtros, $hoje, $cfg, &$resolvido): array {
            $payload = ['success' => true] + $this->repoVisaoGeral()->consultar($filtros, $hoje);
            $t0 = time();
            $corpo = json_encode($payload, self::JSON_FLAGS);
            if ($corpo === false) {
                return ['status' => 200, 'corpo' => '', 'meta' => [], 'gravar' => false];
            }
            if (!$cfg['ativo']) {
                return ['status' => 200, 'corpo' => $corpo, 'meta' => [], 'gravar' => false];
            }
            $meta = [];
            $gravar = true;
            $piPayload = $payload['janela']['pi'] ?? null;
            if ($resolvido === null || $piPayload !== $resolvido['pi_desc']) {
                $this->logCache('warning', 'visao-geral: PI do corpo difere da dos marcadores; não gravado');
                $gravar = false;
            }
            $relogio = $this->montarRelogio($payload, $corpo, $t0, $resolvido['pi_id'] ?? null);
            if ($relogio === false) {
                $this->logCache('warning', 'visao-geral: campos do relógio inconsistentes; não gravado');
                $gravar = false;
            } elseif ($relogio !== null) {
                $meta['relogio'] = $relogio;
            }
            return ['status' => 200, 'corpo' => $corpo, 'meta' => $meta, 'gravar' => $gravar];
        };

        if (!$cfg['ativo']) {
            $resolvido = null;
            $r = $inspecionar ? ['corpo' => ''] : $gerar();
            return ['status' => 200, 'corpo' => $r['corpo'], 'estado' => srvRespostaCache::ESTADO_BYPASS,
                    'etag' => null, 'validade' => null, 'idade_s' => null];
        }

        $cache = $this->cacheVisaoGeral ?? new srvRespostaCache(
            $cfg['dir'],
            null,
            function (string $nivel, string $msg, array $ctx = []): void { $this->logCache($nivel, $msg, $ctx); },
            [
                'ttl_s'           => $cfg['ttl_s'],
                'max_bytes'       => $cfg['max_bytes'],
                'espera_trava_s'  => $cfg['espera_trava_s'],
                'servir_stale'    => $cfg['servir_stale'],
                'limpeza_idade_s' => $cfg['limpeza_idade_s'],
            ]
        );
        $chave = $this->chaveVisaoGeral($filtros);
        $resolvido = null;
        $marcadores = function () use ($filtros, $hoje, &$resolvido): ?string {
            return $this->marcadoresVisaoGeral($filtros, $hoje, $resolvido);
        };
        $validador = [self::class, 'relogioConsistente'];

        if ($inspecionar) {
            $validade = $marcadores();
            if ($validade === null) {
                return ['status' => 200, 'corpo' => '', 'estado' => srvRespostaCache::ESTADO_BYPASS,
                        'etag' => null, 'validade' => null, 'idade_s' => null];
            }
            $i = $cache->inspecionar($chave, $validade, $validador);
            return ['status' => 200, 'corpo' => '', 'estado' => $i['estado'], 'etag' => null,
                    'validade' => $validade, 'idade_s' => $i['idade_s']];
        }

        $r = $cache->obter($chave, $marcadores, $gerar, $validador);
        $corpo = $r['corpo'];
        $etag = null;
        if ($r['estado'] !== srvRespostaCache::ESTADO_BYPASS) {
            $sufixo = '';
            if (isset($r['meta']['relogio']) && is_array($r['meta']['relogio'])) {
                $corpo = self::ajustarRelogio($corpo, $r['meta'], $agora);
                $h = self::horasRelogio($r['meta'], $agora);
                $sufixo = json_encode($h) . '|' . ($h > $r['meta']['relogio']['limite'] ? '1' : '0');
            }
            $etag = '"vg-' . substr(sha1($chave . '|' . $r['validade'] . '|' . $sufixo), 0, 32) . '"';
        }
        return ['status' => 200, 'corpo' => $corpo, 'estado' => $r['estado'], 'etag' => $etag,
                'validade' => $r['validade'] !== '' ? $r['validade'] : null, 'idade_s' => $r['idade_s']];
    }

    /**
     * Lista de HTTP If-None-Match × ETag atual. Aceita lista, '*', prefixo W/ e o
     * sufixo -gzip/-br/-deflate que o mod_deflate acrescenta ao ETag.
     */
    public static function etagCorresponde(string $cabecalho, string $etag): bool
    {
        foreach (explode(',', $cabecalho) as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            if ($t === '*') {
                return true;
            }
            if (strncmp($t, 'W/', 2) === 0) {
                $t = substr($t, 2);
            }
            if (strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"') {
                $t = '"' . preg_replace('/-(gzip|br|deflate)$/', '', substr($t, 1, -1)) . '"';
            }
            if ($t === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recalcula, para o instante $agora, os dois trechos do corpo que dependem do relógio
     * (extracao.defasagem_horas e o aviso de defasagem), por substituição textual exata.
     * $meta = meta guardada na geração (chave 'relogio'); sem ela, devolve o corpo intacto.
     */
    public static function ajustarRelogio(string $corpo, array $meta, int $agora): string
    {
        $h = self::horasRelogio($meta, $agora);
        if ($h === null) {
            return $corpo;
        }
        $rel = $meta['relogio'];
        $corpo = str_replace($rel['campo_literal'], '"defasagem_horas":' . json_encode($h), $corpo);
        if (!empty($rel['aviso_presente']) && is_string($rel['aviso_literal'] ?? null)) {
            $corpo = str_replace(
                $rel['aviso_literal'],
                json_encode('A carga mais antiga do Jira tem ' . $h . ' h de defasagem.', self::JSON_FLAGS),
                $corpo
            );
        }
        return $corpo;
    }

    /**
     * false quando, no instante $agora, o aviso de defasagem apareceria/sumiria em
     * relação ao corpo guardado (a entrada precisa ser regenerada).
     */
    public static function relogioConsistente(array $meta, int $agora): bool
    {
        $h = self::horasRelogio($meta, $agora);
        return $h === null || (($h > $meta['relogio']['limite']) === (bool) $meta['relogio']['aviso_presente']);
    }

    /** Mesma expressão de VisaoGeralRepository::extracao(); null se a meta não tem relógio. */
    private static function horasRelogio(array $meta, int $agora): ?float
    {
        if (!isset($meta['relogio']['mais_antiga']) || !is_string($meta['relogio']['mais_antiga'])) {
            return null;
        }
        return round(($agora - strtotime($meta['relogio']['mais_antiga'])) / 3600, 1);
    }

    /**
     * Meta do relógio guardada junto do corpo. null = corpo sem campos de relógio;
     * false = inconsistente (não gravar).
     *
     * @return array<string,mixed>|null|false
     */
    private function montarRelogio(array $payload, string $corpo, int $t0, ?int $piId)
    {
        $ext = $payload['extracao'] ?? null;
        if (!is_array($ext) || !array_key_exists('defasagem_horas', $ext) || $ext['defasagem_horas'] === null) {
            return null;
        }
        $keys = array_values(array_unique(array_column($payload['universo']['times'] ?? [], 'projeto_key')));
        if ($piId === null || $keys === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = Database::pdo()->prepare(
            "SELECT MIN(data_carga) FROM extracao_jira
              WHERE ativo = 'S' AND configuracao_pi_id = ? AND SUBSTRING_INDEX(chave, '-', 1) IN ($in)"
        );
        $st->execute(array_merge([$piId], $keys));
        $maisAntiga = $st->fetchColumn();
        if (!is_string($maisAntiga) || $maisAntiga === '') {
            return false;
        }
        $limite = (int) ($ext['aviso_defasagem_horas'] ?? 25);
        $h = (float) $ext['defasagem_horas'];
        $campo = '"defasagem_horas":' . json_encode($ext['defasagem_horas']);
        if (substr_count($corpo, $campo) !== 1) {
            return false;
        }
        if (abs(round(($t0 - strtotime($maisAntiga)) / 3600, 1) - $h) > 0.2) {
            return false;
        }
        $presente = $h > $limite;
        $avisoLiteral = null;
        if ($presente) {
            $avisoLiteral = json_encode('A carga mais antiga do Jira tem ' . $ext['defasagem_horas'] . ' h de defasagem.', self::JSON_FLAGS);
            if (substr_count($corpo, $avisoLiteral) !== 1) {
                return false;
            }
        } elseif (strpos($corpo, 'A carga mais antiga do Jira tem') !== false) {
            return false;
        }
        return [
            'mais_antiga'    => $maisAntiga,
            'limite'         => $limite,
            'campo_literal'  => $campo,
            'aviso_literal'  => $avisoLiteral,
            'aviso_presente' => $presente,
        ];
    }

    /** Chave canônica do slot: filtros após trim, vazios omitidos, ordenados; modo vazio = sprint. */
    private function chaveVisaoGeral(array $filtros): string
    {
        $f = [];
        foreach (['jornada', 'time', 'versao', 'sprint', 'modo'] as $k) {
            $v = trim((string) ($filtros[$k] ?? ''));
            if ($k === 'modo' && $v === '') {
                $v = 'sprint';
            }
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        ksort($f);
        return (string) json_encode(['ns' => 'visao-geral', 'f' => $f], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{ativo:bool,ttl_s:int,dir:string,max_bytes:int,espera_trava_s:int,servir_stale:bool,limpeza_idade_s:int} */
    private function configCacheVisaoGeral(): array
    {
        $base = dirname(__DIR__, 2);
        $c = ((array) (require $base . '/config/visao_geral.php'))['cache'] ?? [];
        $dir = (string) ($c['dir'] ?? 'data/cache');
        if (!preg_match('~^([A-Za-z]:[\\\\/]|/)~', $dir)) {
            $dir = $base . '/' . $dir;
        }
        $env = getenv('VG_CACHE');
        $srv = $_SERVER['VG_CACHE'] ?? null;
        $desligadoEnv = ($env !== false && trim((string) $env) === '0') || (is_string($srv) && trim($srv) === '0');
        return [
            'ativo'           => !empty($c['ativo']) && !$desligadoEnv,
            'ttl_s'           => (int) ($c['ttl_s'] ?? 900),
            'dir'             => $dir,
            'max_bytes'       => (int) ($c['max_bytes'] ?? 4194304),
            'espera_trava_s'  => (int) ($c['espera_trava_s'] ?? 5),
            'servir_stale'    => (bool) ($c['servir_stale'] ?? true),
            'limpeza_idade_s' => (int) ($c['limpeza_idade_s'] ?? 86400),
        ];
    }

    private function logCache(string $nivel, string $msg, array $ctx = []): void
    {
        if ($nivel === 'warning') {
            Logger::warning(Logger::CANAL_DASHBOARD, $msg, $ctx);
        } else {
            Logger::info(Logger::CANAL_DASHBOARD, $msg, $ctx);
        }
    }

    /**
     * Token de validade do corpo (null = não cachear). Reúne tudo que muda a resposta:
     * data de hoje, versão do código, PI resolvida e marcadores do banco da PI e do catálogo.
     *
     * @param array{pi_id:?int,pi_desc:?string}|null $resolvido (saída) PI resolvida pelos marcadores
     */
    private function marcadoresVisaoGeral(array $filtros, string $hoje, ?array &$resolvido): ?string
    {
        $pdo = Database::pdo();
        $versao = trim((string) ($filtros['versao'] ?? ''));

        // Mesma resolução de PI de PrevisibilidadeRepository::resolverContexto().
        $pis = $pdo->query(
            "SELECT id_versao_corrigida AS id, desc_pi, data_ini, data_fim
               FROM configuracao_pi WHERE ativo = 'S' ORDER BY data_ini, desc_pi"
        )->fetchAll();
        $pi = null;
        if ($versao !== '') {
            foreach ($pis as $p) {
                if ($p['desc_pi'] === $versao) { $pi = $p; break; }
            }
            if ($pi === null) {
                return null; // versão inexistente: o repositório lança o 400
            }
        } elseif ($pis) {
            foreach ($pis as $p) {
                if ($p['data_ini'] && $p['data_fim'] && $p['data_ini'] <= $hoje && $hoje <= $p['data_fim']) { $pi = $p; break; }
            }
            if ($pi === null) {
                foreach ($pis as $p) {
                    if ($pi === null || (string) $p['data_fim'] > (string) $pi['data_fim']) $pi = $p;
                }
            }
        }
        $resolvido = ['pi_id' => $pi !== null ? (int) $pi['id'] : null, 'pi_desc' => $pi['desc_pi'] ?? null];

        $m = [];
        if ($pi !== null) {
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS n, MAX(id_extracao) AS mid, MAX(data_carga) AS mdc, SUM(ativo = 'S') AS ativos
                   FROM extracao_jira WHERE configuracao_pi_id = :pi"
            );
            $st->execute([':pi' => $pi['id']]);
            $m['extracao'] = $st->fetch();
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS n, MAX(id_baseline) AS mid, MAX(data_carga) AS mdc, SUM(ativo = 'S') AS ativos
                   FROM baseline WHERE configuracao_pi_id = :pi"
            );
            $st->execute([':pi' => $pi['id']]);
            $m['baseline'] = $st->fetch();
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS n, MAX(id_ajuste) AS mid, MAX(criado_em) AS mce
                   FROM baseline_ajuste WHERE configuracao_pi_id = :pi"
            );
            $st->execute([':pi' => $pi['id']]);
            $m['ajuste'] = $st->fetch();
        }
        $m['catalogo'] = $pdo->query(
            'CHECKSUM TABLE jornada, times, times_composicao, sprints, configuracao_pi, produtividade_historico, sustentacao_historico'
        )->fetchAll();

        // Capacidade editada no Painel (data/config/capacity.json): entra no corpo do Fator de Carga.
        $capPainel = $this->caminhoCapacidadePainel();
        $m['capacidade_painel'] = is_file($capPainel) ? md5_file($capPainel) : null;

        $token = json_encode([1, $hoje, self::versaoCodigo(), $resolvido, $m]);
        return $token === false ? null : sha1($token);
    }

    /** Caminho do JSON de capacidade gravado pelo Painel (sobrescrevível em teste). */
    protected function caminhoCapacidadePainel(): string
    {
        return dirname(__DIR__, 2) . '/data/config/capacity.json';
    }

    /** Hash do conteúdo dos arquivos que definem a resposta (um deploy invalida tudo). */
    private static function versaoCodigo(): string
    {
        static $v = null;
        if ($v === null) {
            $base = dirname(__DIR__, 2);
            $h = [PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION];
            foreach (self::ARQUIVOS_VERSAO as $f) {
                $md5 = @md5_file($base . '/' . $f);
                $h[] = $f . ':' . ($md5 === false ? 'x' : $md5);
            }
            $v = sha1(implode('|', $h));
        }
        return $v;
    }

    // ── GET /graficos/previsibilidade ────────────────────────────────────
    public function previsibilidade(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $filtros = $this->lerFiltros();
            $this->respond(200, ['success' => true] + $this->previsibilidade->consultar($filtros));
        } catch (InvalidArgumentException $e) {
            Logger::warning(Logger::CANAL_DASHBOARD, 'GET /graficos/previsibilidade → 400: ' . $e->getMessage(), [
                'query' => $_GET,
            ]);
            $this->respond(400, ['success' => false, 'erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /graficos/previsibilidade: exceção', $e, ['query' => $_GET]);
            $this->respond(500, ['success' => false, 'erro' => 'Falha ao calcular a previsibilidade.']);
        }
    }

    /**
     * @param bool $comModo quando true, lê também o parâmetro 'modo' (texto ≤ 255, padrão 'sprint').
     * @return array<string,string>
     */
    private function lerFiltros(bool $comModo = false): array
    {
        $chaves = self::FILTROS;
        if ($comModo) {
            $chaves = array_merge($chaves, ['modo']);
        }
        $out = [];
        foreach ($chaves as $k) {
            $v = $_GET[$k] ?? '';
            if (!is_string($v)) {
                throw new InvalidArgumentException("Parâmetro '{$k}' deve ser um texto.");
            }
            $v = trim($v);
            if (mb_strlen($v) > 255) {
                throw new InvalidArgumentException("Parâmetro '{$k}' excede 255 caracteres.");
            }
            $out[$k] = $v;
        }
        if ($comModo && ($out['modo'] ?? '') === '') {
            $out['modo'] = 'sprint';
        }
        return $out;
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, self::JSON_FLAGS);
    }
}
