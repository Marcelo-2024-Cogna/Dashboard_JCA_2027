<?php

namespace App\Controllers;

use App\Repositories\LeadTimeRepository;
use App\Repositories\PlanejadoRealizadoRepository;
use App\Repositories\PrevisibilidadeRepository;
use App\Repositories\ProdutividadeRepository;
use App\Repositories\VelocidadeRepository;
use App\Repositories\VisaoGeralRepository;
use App\Services\Logger;
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

    public function __construct(
        PrevisibilidadeRepository $previsibilidade,
        ?PlanejadoRealizadoRepository $planejadoRealizado = null,
        ?LeadTimeRepository $leadTime = null,
        ?ProdutividadeRepository $produtividade = null,
        ?VelocidadeRepository $velocidade = null,
        ?VisaoGeralRepository $visaoGeral = null
    ) {
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
            $repo = $this->visaoGeral ?? new VisaoGeralRepository($this->previsibilidade, $this->velocidade, $this->produtividade);
            $filtros = $this->lerFiltros(true);
            $this->respond(200, ['success' => true] + $repo->consultar($filtros));
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
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
