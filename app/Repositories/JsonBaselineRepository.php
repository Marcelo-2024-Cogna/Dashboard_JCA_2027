<?php

namespace App\Repositories;

use App\Models\BaselineSnapshot;
use App\Models\ProgressSnapshot;

/**
 * JsonBaselineRepository
 * ──────────────────────
 * Implementação de BaselineRepositoryInterface usando arquivos JSON.
 *
 * Layout em disco:
 *   data/baselines/{pi}_{team}_{timestamp}.json   (baseline — início da PI)
 *   data/progress/{pi}_{team}_{refSprint}_{ts}.json (progresso — evolução)
 *
 * MIGRAÇÃO FUTURA: criar PdoBaselineRepository implementando a mesma
 * interface e trocar a instanciação no bootstrap. Services/Controllers
 * não mudam.
 */
class JsonBaselineRepository implements BaselineRepositoryInterface
{
    private string $baselineDir;
    private string $progressDir;

    public function __construct(string $storageDir)
    {
        $base = rtrim($storageDir, '/\\');
        $this->baselineDir = $base;
        // progress fica em data/progress (irmão de data/baselines)
        $this->progressDir = dirname($base) . '/progress';

        foreach ([$this->baselineDir, $this->progressDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  BASELINE (imutável)
    // ═══════════════════════════════════════════════════════════════════════

    public function save(BaselineSnapshot $snapshot): string
    {
        $pi   = $this->slugPi($snapshot->piId);
        $team = $this->slugTeam($snapshot->team);
        $ts   = date('Ymd-His', strtotime($snapshot->capturedAt));

        $filename = "{$pi}_{$team}_{$ts}.json";
        file_put_contents(
            $this->baselineDir . '/' . $filename,
            json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $filename;
    }

    public function baselineExists(string $piId, string $team): bool
    {
        $pi   = $this->slugPi($piId);
        $team = $this->slugTeam($team);
        return (bool) glob($this->baselineDir . "/{$pi}_{$team}_*.json");
    }

    public function findBaseline(string $piId, string $team): ?BaselineSnapshot
    {
        $pi   = $this->slugPi($piId);
        $team = $this->slugTeam($team);

        $matches = glob($this->baselineDir . "/{$pi}_{$team}_*.json");
        if (!$matches) {
            return null;
        }
        // Nome termina em timestamp → ordem alfabética = cronológica.
        // Pega o MAIS ANTIGO (o verdadeiro início da PI).
        sort($matches);
        $oldest = $matches[0];

        $data = $this->loadJson($oldest);
        return $data ? BaselineSnapshot::fromArray($data) : null;
    }

    public function findByPi(string $piId): array
    {
        $pi      = $this->slugPi($piId);
        $matches = glob($this->baselineDir . "/{$pi}_*.json") ?: [];
        sort($matches);

        // Dedup por time: mantém o mais antigo de cada time.
        $byTeam = [];
        foreach ($matches as $file) {
            $data = $this->loadJson($file);
            if (!$data) {
                continue;
            }
            $snap = BaselineSnapshot::fromArray($data);
            // primeiro visto por time = mais antigo (matches já ordenado)
            if (!isset($byTeam[$snap->team])) {
                $byTeam[$snap->team] = $snap;
            }
        }
        return array_values($byTeam);
    }

    public function all(): array
    {
        $matches = glob($this->baselineDir . '/*.json') ?: [];
        $out = [];
        foreach ($matches as $file) {
            $data = $this->loadJson($file);
            if ($data) {
                $out[] = BaselineSnapshot::fromArray($data);
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PROGRESS (acumulativo)
    // ═══════════════════════════════════════════════════════════════════════

    public function saveProgress(ProgressSnapshot $snapshot): string
    {
        $pi     = $this->slugPi($snapshot->piId);
        $team   = $this->slugTeam($snapshot->team);
        $sprint = $snapshot->refSprint
            ? preg_replace('/[^A-Za-z0-9.]/', '', $snapshot->refSprint)
            : 'all';
        $ts     = date('Ymd-His', strtotime($snapshot->capturedAt));

        $filename = "{$pi}_{$team}_{$sprint}_{$ts}.json";
        file_put_contents(
            $this->progressDir . '/' . $filename,
            json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $filename;
    }

    public function findProgress(string $piId, string $team): array
    {
        $pi   = $this->slugPi($piId);
        $team = $this->slugTeam($team);

        $matches = glob($this->progressDir . "/{$pi}_{$team}_*.json") ?: [];
        sort($matches); // ordem cronológica (timestamp no fim do nome)

        $out = [];
        foreach ($matches as $file) {
            $data = $this->loadJson($file);
            if ($data) {
                $out[] = ProgressSnapshot::fromArray($data);
            }
        }
        return $out;
    }

    public function findProgressByPi(string $piId): array
    {
        $pi      = $this->slugPi($piId);
        $matches = glob($this->progressDir . "/{$pi}_*.json") ?: [];
        sort($matches);

        $out = [];
        foreach ($matches as $file) {
            $data = $this->loadJson($file);
            if ($data) {
                $out[] = ProgressSnapshot::fromArray($data);
            }
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function slugPi(string $piId): string
    {
        return str_replace('/', '-', $piId);
    }

    private function slugTeam(string $team): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $team);
    }

    private function loadJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
