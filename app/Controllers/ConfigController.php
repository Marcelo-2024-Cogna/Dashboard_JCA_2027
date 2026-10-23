<?php

namespace App\Controllers;

use App\Repositories\ConfigRepository;

/**
 * ConfigController
 * ────────────────
 * Endpoints de leitura/escrita dos parâmetros editáveis (Painel de Controle).
 *
 *   GET  /config            → lê PI + Capacidade (para exibir no painel)
 *   POST /config/pi         → grava data/config/pi.json
 *   POST /config/capacity   → grava data/config/capacity.json
 *
 * Aceita corpo JSON ou form-urlencoded nas escritas.
 */
class ConfigController
{
    private ConfigRepository $repo;

    public function __construct(ConfigRepository $repo)
    {
        $this->repo = $repo;
    }

    // ── GET /config ───────────────────────────────────────────────────────

    public function show(): void
    {
        $this->jsonHeaders();
        $this->respond([
            'success'  => true,
            'pi'       => $this->repo->getPi(),
            'capacity' => $this->repo->getCapacity(),
            'saved'    => [
                'pi'       => $this->repo->hasSaved('pi'),
                'capacity' => $this->repo->hasSaved('capacity'),
            ],
        ]);
    }

    // ── POST /config/pi ─────────────────────────────────────────────────────

    public function savePi(): void
    {
        $this->jsonHeaders();
        $in = $this->input();
        try {
            $saved = $this->repo->savePi($in);
            $this->respond(['success' => true, 'saved' => 'pi', 'pi' => $saved]);
        } catch (\Throwable $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ── POST /config/capacity ───────────────────────────────────────────────

    public function saveCapacity(): void
    {
        $this->jsonHeaders();
        $in = $this->input();
        try {
            $saved = $this->repo->saveCapacity($in);
            $this->respond(['success' => true, 'saved' => 'capacity', 'capacity' => $saved]);
        } catch (\Throwable $e) {
            $this->respondError($e->getMessage());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Lê o corpo da requisição: JSON, senão $_POST. */
    private function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return $_POST ?: [];
    }

    private function jsonHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache');
        }
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
