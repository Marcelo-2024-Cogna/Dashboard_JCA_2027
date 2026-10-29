<?php

namespace App\Controllers;

use App\Repositories\PlanejadoRealizadoRepository;
use App\Repositories\PrevisibilidadeRepository;
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
 *
 * Os próximos gráficos (Lead Time, Produtividade)
 * entram aqui como novos métodos, reaproveitando os mesmos filtros.
 *
 * Respostas: 200 JSON · 400 filtro inválido/inexistente · 405 método ·
 * 500 falha interna (detalhe só no log, canal 'dashboard').
 */
class GraficosController
{
    private const FILTROS = ['jornada', 'time', 'versao', 'sprint'];

    private PrevisibilidadeRepository $previsibilidade;
    private PlanejadoRealizadoRepository $planejadoRealizado;

    public function __construct(PrevisibilidadeRepository $previsibilidade, ?PlanejadoRealizadoRepository $planejadoRealizado = null)
    {
        $this->previsibilidade    = $previsibilidade;
        $this->planejadoRealizado = $planejadoRealizado ?? new PlanejadoRealizadoRepository($previsibilidade);
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

    /** @return array<string,string> */
    private function lerFiltros(): array
    {
        $out = [];
        foreach (self::FILTROS as $k) {
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
        return $out;
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
