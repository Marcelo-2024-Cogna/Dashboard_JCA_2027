<?php

namespace App\Controllers;

use App\Repositories\CatalogRepository;
use App\Services\Logger;
use Throwable;

/**
 * FiltrosController
 * ─────────────────
 * GET /filtros — catálogo que alimenta os filtros do header, lido do BANCO:
 *
 *   jornadas        [{nome}]                      jornadas ativas (nome do catálogo)
 *   times           [{jornada, time, projeto_key}] times ativos de jornadas ativas
 *   times_por_jornada {jornada: [{time, projeto_key}]}
 *   pis             [{desc_pi, data_ini, data_fim, corrente}]
 *   pi_corrente     a PI que contém hoje; senão a de maior data_fim
 *   sprints_por_pi  {desc_pi: [{nome, tipo, data_ini, data_fim, fechada}]}
 *                   fechada = data_fim < hoje
 *   hoje            'Y-m-d' usado no cálculo
 *
 * Substitui a montagem dos filtros a partir do /extract (7 MB).
 */
class FiltrosController
{
    private CatalogRepository $catalog;

    public function __construct(CatalogRepository $catalog)
    {
        $this->catalog = $catalog;
    }

    public function listar(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->respond(405, ['erro' => 'Use o método GET.']);
            return;
        }
        try {
            $this->respond(200, $this->montar(date('Y-m-d')));
        } catch (Throwable $e) {
            Logger::exception(Logger::CANAL_DASHBOARD, 'GET /filtros: exceção', $e);
            $this->respond(500, ['erro' => 'Falha ao ler o catálogo de filtros.']);
        }
    }

    public function montar(string $hoje): array
    {
        $jornadas = array_map(fn($j) => ['nome' => $j['nome']], $this->catalog->jornadas());

        $times = [];
        $porJornada = [];
        foreach ($jornadas as $j) $porJornada[$j['nome']] = [];
        foreach ($this->catalog->timesAtivos() as $t) {
            $times[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key']];
            $porJornada[$t['jornada']][] = ['time' => $t['time'], 'projeto_key' => $t['projeto_key']];
        }

        // PI corrente: contém hoje; senão a mais recente (maior data_fim).
        $pisRaw = $this->catalog->pis();
        $corrente = null;
        $recente = null;
        foreach ($pisRaw as $p) {
            if ($p['data_ini'] && $p['data_fim'] && $p['data_ini'] <= $hoje && $hoje <= $p['data_fim']) {
                $corrente = $p['desc_pi'];
                break;
            }
            if ($recente === null || (string)$p['data_fim'] > (string)$recente['data_fim']) $recente = $p;
        }
        $corrente ??= $recente['desc_pi'] ?? null;

        // Ordem cronológica das PIs (data_ini).
        usort($pisRaw, fn($a, $b) => strcmp((string)$a['data_ini'], (string)$b['data_ini']) ?: strcmp($a['desc_pi'], $b['desc_pi']));
        $pis = array_map(fn($p) => [
            'desc_pi'  => $p['desc_pi'],
            'data_ini' => $p['data_ini'],
            'data_fim' => $p['data_fim'],
            'corrente' => $p['desc_pi'] === $corrente,
        ], $pisRaw);

        $sprints = [];
        foreach ($pis as $p) $sprints[$p['desc_pi']] = [];
        $rows = $this->catalog->sprintsPorPi();
        usort($rows, fn($a, $b) => strcmp((string)$a['data_ini'], (string)$b['data_ini']) ?: strcmp($a['sprint'], $b['sprint']));
        foreach ($rows as $s) {
            if (($s['ativo'] ?? 'S') !== 'S' || !isset($sprints[$s['desc_pi']])) continue;
            $sprints[$s['desc_pi']][] = [
                'nome'     => $s['sprint'],
                'tipo'     => $s['tipo'],
                'data_ini' => $s['data_ini'],
                'data_fim' => $s['data_fim'],
                'fechada'  => $s['data_fim'] !== null && $s['data_fim'] < $hoje,
            ];
        }

        return [
            'hoje'              => $hoje,
            'jornadas'          => $jornadas,
            'times'             => $times,
            'times_por_jornada' => $porJornada,
            'pis'               => $pis,
            'pi_corrente'       => $corrente,
            'sprints_por_pi'    => $sprints,
        ];
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
