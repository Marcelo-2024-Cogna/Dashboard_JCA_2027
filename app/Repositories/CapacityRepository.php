<?php

namespace App\Repositories;

/**
 * CapacityRepository
 * ──────────────────
 * Persistência do histórico de sprints (horas × pontos) por time,
 * usado para calcular produtividade/velocidade/capacidade.
 *
 * Armazenamento: um arquivo JSON por time em
 *   data/capacity/{team_id}.json
 *
 * Estrutura do arquivo:
 * {
 *   "team_id": "SGGU",
 *   "sprints": [
 *     {
 *       "sprint_name": "26.4.1",
 *       "working_days": 10,
 *       "productive_hours_per_dev": 6,
 *       "dev_count": 5,
 *       "total_hours_spent": 240,
 *       "total_points_delivered": 30,
 *       "calculated_productivity": 8.0
 *     }
 *   ]
 * }
 *
 * MIGRAÇÃO FUTURA: substituível por tabela relacional (sprint_metrics)
 * sem alterar o CapacityCalculator (que opera sobre arrays).
 */
class CapacityRepository
{
    private string $dir;

    public function __construct(string $storageDir)
    {
        $this->dir = rtrim($storageDir, '/\\');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    private function pathFor(string $teamId): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $teamId);
        return $this->dir . '/' . $slug . '.json';
    }

    /**
     * Carrega o histórico de um time. Retorna estrutura vazia se não existir.
     * @return array{team_id:string, sprints:array}
     */
    public function load(string $teamId): array
    {
        $path = $this->pathFor($teamId);
        if (!is_file($path)) {
            return ['team_id' => $teamId, 'sprints' => []];
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return ['team_id' => $teamId, 'sprints' => []];
        }
        $data['team_id'] = $data['team_id'] ?? $teamId;
        $data['sprints'] = $data['sprints'] ?? [];
        return $data;
    }

    /**
     * Salva o histórico completo de um time (sobrescreve).
     * @param array{team_id:string, sprints:array} $data
     */
    public function save(array $data): void
    {
        $teamId = $data['team_id'] ?? 'unknown';
        file_put_contents(
            $this->pathFor($teamId),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Adiciona (ou atualiza, se já existir o mesmo sprint_name) uma sprint
     * ao histórico do time. A produtividade é calculada e gravada junto.
     *
     * @param array{
     *   sprint_name:string, working_days:int, productive_hours_per_dev:float,
     *   dev_count:int, total_hours_spent:float, total_points_delivered:float
     * } $sprint
     */
    public function addSprint(string $teamId, array $sprint): array
    {
        $data = $this->load($teamId);

        // Calcula a produtividade da sprint (horas/ponto)
        $points = (float)($sprint['total_points_delivered'] ?? 0);
        $hours  = (float)($sprint['total_hours_spent'] ?? 0);
        $sprint['calculated_productivity'] = $points > 0
            ? round($hours / $points, 2)
            : null;

        // Substitui se já existe sprint com o mesmo nome; senão adiciona
        $replaced = false;
        foreach ($data['sprints'] as $i => $existing) {
            if (($existing['sprint_name'] ?? null) === ($sprint['sprint_name'] ?? null)) {
                $data['sprints'][$i] = $sprint;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $data['sprints'][] = $sprint;
        }

        $this->save($data);
        return $data;
    }

    /**
     * Lista os team_ids com histórico salvo.
     * @return string[]
     */
    public function allTeams(): array
    {
        $teams = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $teams[] = basename($file, '.json');
        }
        return $teams;
    }
}
