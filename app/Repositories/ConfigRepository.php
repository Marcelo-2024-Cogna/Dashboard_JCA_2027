<?php

namespace App\Repositories;

/**
 * ConfigRepository
 * ────────────────
 * Fonte única de leitura/escrita dos parâmetros EDITÁVEIS do sistema
 * (PI e Capacidade), persistidos em JSON em data/config/.
 *
 * Estratégia de migração suave:
 *   1. Se o JSON existe em data/config/, ele é a fonte de verdade.
 *   2. Se NÃO existe, cai para os valores de config/*.php (seed) — assim
 *      nada quebra antes do primeiro salvamento pelo Painel de Controle.
 *
 * O que é editável (vai para JSON):
 *   - pi.json        → current_pi, sprints, closing_sprint, projects
 *   - capacity.json  → working_days_by_sprint, teams{dev_count,
 *                      productivity, productive_hours}, defaults
 *
 * O que NÃO é editável (permanece em config/*.php): credenciais do Jira,
 * campos customizados, regras de negócio (Done, tipos de valor/sustentação).
 */
class ConfigRepository
{
    private string $dir;
    private array  $phpPi;        // seed de config/pi.php
    private array  $phpCapacity;  // seed de config/capacity.php

    public function __construct(string $configDataDir, array $phpPi, array $phpCapacity)
    {
        $this->dir         = rtrim($configDataDir, '/\\');
        $this->phpPi       = $phpPi;
        $this->phpCapacity = $phpCapacity;

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PI
    // ═══════════════════════════════════════════════════════════════════════

    /** Parâmetros da PI (JSON se existir; senão, seed do config/pi.php). */
    public function getPi(): array
    {
        $json = $this->readJson('pi.json');
        if ($json === null) {
            return $this->seedPiFromPhp();
        }
        // Mescla sobre o seed para tolerar chaves faltando no JSON.
        return array_merge($this->seedPiFromPhp(), $json);
    }

    public function savePi(array $data): array
    {
        // Normaliza/valida os campos aceitos
        $current = $this->getPi();
        $clean = [
            'current_pi'       => trim((string)($data['current_pi'] ?? $current['current_pi'])),
            'sprints'          => $this->cleanList($data['sprints'] ?? $current['sprints']),
            'closing_sprint'   => $this->nullableString($data['closing_sprint'] ?? ($current['closing_sprint'] ?? null)),
            'projects'         => $this->cleanList($data['projects'] ?? $current['projects']),
            'issue_types_jql'  => $current['issue_types_jql'] ?? 'standardIssueTypes()',
            'order_by'         => $current['order_by'] ?? 'project',
        ];
        $this->writeJson('pi.json', $clean);
        return $clean;
    }

    /** Monta o seed de PI no formato canônico a partir do config/pi.php. */
    private function seedPiFromPhp(): array
    {
        return [
            'current_pi'      => $this->phpPi['current_pi'] ?? '',
            'sprints'         => $this->phpPi['sprints'] ?? [],
            'closing_sprint'  => $this->phpPi['closing_sprint'] ?? null,
            'projects'        => $this->phpPi['projects'] ?? [],
            'issue_types_jql' => $this->phpPi['issue_types_jql'] ?? 'standardIssueTypes()',
            'order_by'        => $this->phpPi['order_by'] ?? 'project',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  CAPACIDADE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Parâmetros de capacidade no formato AGRUPADO POR TIME:
     *   [
     *     'working_days_by_sprint' => [...],
     *     'teams' => ['SGGU' => ['dev_count'=>9,'productivity'=>6,'productive_hours'=>6], ...],
     *     'defaults' => ['dev_count'=>4,'productive_hours'=>7,'working_days'=>8],
     *   ]
     */
    public function getCapacity(): array
    {
        $json = $this->readJson('capacity.json');
        if ($json === null) {
            return $this->seedCapacityFromPhp();
        }
        $seed = $this->seedCapacityFromPhp();
        return [
            'working_days_by_sprint' => $json['working_days_by_sprint'] ?? $seed['working_days_by_sprint'],
            'teams'    => $json['teams']    ?? $seed['teams'],
            'defaults' => array_merge($seed['defaults'], $json['defaults'] ?? []),
        ];
    }

    public function saveCapacity(array $data): array
    {
        $current = $this->getCapacity();

        // Normaliza teams: cada time com dev_count, productivity, productive_hours
        $teams = [];
        foreach (($data['teams'] ?? $current['teams']) as $id => $t) {
            $id = strtoupper(trim((string)$id));
            if ($id === '') continue;
            $teams[$id] = [
                'dev_count'        => $this->num($t['dev_count'] ?? null),
                'productivity'     => $this->num($t['productivity'] ?? null),
                'productive_hours' => $this->num($t['productive_hours'] ?? null),
            ];
        }

        // Normaliza calendário de dias úteis
        $cal = [];
        foreach (($data['working_days_by_sprint'] ?? $current['working_days_by_sprint']) as $sp => $d) {
            $cal[(string)$sp] = (int)$d;
        }

        $clean = [
            'working_days_by_sprint' => $cal,
            'teams'    => $teams,
            'defaults' => [
                'dev_count'        => $this->num($data['defaults']['dev_count']        ?? $current['defaults']['dev_count']),
                'productive_hours' => $this->num($data['defaults']['productive_hours'] ?? $current['defaults']['productive_hours']),
                'working_days'     => (int)($data['defaults']['working_days']          ?? $current['defaults']['working_days']),
            ],
        ];
        $this->writeJson('capacity.json', $clean);
        return $clean;
    }

    /**
     * Converte o formato ANTIGO (4 mapas paralelos) do config/capacity.php
     * para o formato NOVO agrupado por time.
     */
    private function seedCapacityFromPhp(): array
    {
        $devs  = $this->phpCapacity['dev_count_by_team'] ?? [];
        $prod  = $this->phpCapacity['reference_productivity_by_team'] ?? [];
        $hours = $this->phpCapacity['productive_hours_by_team'] ?? [];

        // Une as chaves de todos os mapas
        $allTeams = array_unique(array_merge(
            array_keys($devs), array_keys($prod), array_keys($hours)
        ));

        $teams = [];
        foreach ($allTeams as $id) {
            $teams[$id] = [
                'dev_count'        => $devs[$id]  ?? null,
                'productivity'     => $prod[$id]  ?? null,
                'productive_hours' => $hours[$id] ?? null,
            ];
        }

        return [
            'working_days_by_sprint' => $this->phpCapacity['working_days_by_sprint'] ?? [],
            'teams'    => $teams,
            'defaults' => [
                'dev_count'        => 4,
                'productive_hours' => $this->phpCapacity['productive_hours_per_dev'] ?? 7,
                'working_days'     => 8,
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Adaptadores — devolvem os formatos que os Services JÁ esperam
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Converte o formato NOVO (agrupado por time) de volta para os mapas
     * paralelos que JqlBuilder/CapacityController consomem hoje, para não
     * exigir mudança nesses componentes.
     */
    public function capacityAsLegacyMaps(): array
    {
        $cap = $this->getCapacity();
        $devs = $prod = $hours = [];
        foreach ($cap['teams'] as $id => $t) {
            if ($t['dev_count']        !== null) $devs[$id]  = $t['dev_count'];
            if ($t['productivity']     !== null) $prod[$id]  = $t['productivity'];
            if ($t['productive_hours'] !== null) $hours[$id] = $t['productive_hours'];
        }
        return [
            'working_days_by_sprint'          => $cap['working_days_by_sprint'],
            'dev_count_by_team'               => $devs,
            'reference_productivity_by_team'  => $prod,
            'productive_hours_by_team'        => $hours,
            'productive_hours_per_dev'        => $cap['defaults']['productive_hours'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers de IO e normalização
    // ═══════════════════════════════════════════════════════════════════════

    private function readJson(string $file): ?array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $file, array $data): void
    {
        file_put_contents(
            $this->dir . '/' . $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function cleanList($list): array
    {
        if (is_string($list)) {
            $list = preg_split('/[,;\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $list), fn($v) => $v !== ''));
    }

    private function nullableString($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    /** Número que pode ser int ou float (devs/horas podem ser fracionários). */
    private function num($v)
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        $f = (float)$v;
        return ($f == (int)$f) ? (int)$f : $f;
    }

    /** Caminho absoluto do diretório de config (para o seed inicial). */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Indica se já existe JSON salvo (para o Painel saber se é seed ou editado). */
    public function hasSaved(string $which): bool
    {
        return is_file($this->dir . '/' . $which . '.json');
    }
}
