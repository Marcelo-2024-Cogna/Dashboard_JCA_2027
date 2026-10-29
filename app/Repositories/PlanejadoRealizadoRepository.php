<?php

namespace App\Repositories;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * PlanejadoRealizadoRepository
 * ────────────────────────────
 * Gráfico PLANEJADO x REALIZADO (SOMENTE LEITURA): 4 barras empilhadas.
 *
 *   velocidade       = vw_velocidade_sprint (Σ velocity | sustentação | capacity) na janela
 *   pi_planning      = baseline (SPE) de itens cujo sprint_ref da baseline está na janela
 *   sprint_planning  = extração (SPE) com sprint_ref atual na janela e status <> 'Cancelado'
 *   entregues        = extração (SP atuais) com sprint_ref atual na janela e status ENTREGUE
 *
 * Categorias das barras: valor_sem_epico/valor_com_epico vêm da view
 *   vw_planejado_realizado_item; 'sustentacao' é aplicada AQUI a partir de
 *   config/baseline.php → plan_real_sustentacao_types (Sustentação, Suporte).
 *   (+ capacidade, só na velocidade).
 *   Outros tipos (Incidente, Bug, ...) ficam fora; são reportados em diagnosticos.
 *
 * Filtros, PI, JANELA e times vêm de PrevisibilidadeRepository::resolverContexto()
 * (mesma resolução da Previsibilidade). Só entram times COM extração na PI;
 * os demais da seleção ficam fora de TODAS as barras e vão em times_sem_extracao.
 */
class PlanejadoRealizadoRepository
{
    public const CATEGORIAS = ['sustentacao', 'valor_sem_epico', 'valor_com_epico'];
    public const BARRAS     = ['velocidade', 'pi_planning', 'sprint_planning', 'entregues'];

    private PDO $pdo;
    private PrevisibilidadeRepository $base;

    /** Tipos de item tratados como sustentação (config/baseline.php → plan_real_sustentacao_types). */
    private array $tiposSustentacao;

    public function __construct(?PrevisibilidadeRepository $base = null, ?PDO $pdo = null, ?array $tiposSustentacao = null)
    {
        $this->pdo  = $pdo ?? Database::pdo();
        $this->base = $base ?? new PrevisibilidadeRepository($this->pdo);
        $this->tiposSustentacao = $tiposSustentacao
            ?? ((require dirname(__DIR__, 2) . '/config/baseline.php')['plan_real_sustentacao_types'] ?? ['Sustentação']);
    }

    /**
     * Fonte dos itens com a categoria final: tipos da config → 'sustentacao';
     * demais mantêm a categoria da view (valor com/sem épico ou NULL).
     * Usa o prefixo $p para placeholders únicos dentro da query.
     */
    private function fonte(string $p, array &$par): string
    {
        $in = $this->in($p, $this->tiposSustentacao ?: [''], $par);
        return "(SELECT v.origem, v.chave, v.projeto_key, v.jornada, v.time, v.pi, v.tipo_item, v.status,
                        v.sprint_ref, v.spe, v.sp, v.entregue,
                        CASE WHEN v.tipo_item IN ($in) THEN 'sustentacao' ELSE v.categoria END AS categoria
                   FROM vw_planejado_realizado_item v) x";
    }

    /**
     * @param array{jornada?:string,time?:string,versao?:string,sprint?:string} $filtros
     * @throws InvalidArgumentException filtro inexistente/inconsistente
     */
    public function consultar(array $filtros, ?string $hoje = null): array
    {
        $ctx = $this->base->resolverContexto($filtros, $hoje);
        $pi  = $ctx['pi'];
        $times = $ctx['times'];

        $avisos = [];
        if ($pi === null) {
            $avisos[] = 'Nenhuma PI cadastrada no catálogo.';
        } elseif ($ctx['sprints'] === []) {
            $avisos[] = 'Nenhuma sprint de valor fechada nesta PI ainda. Escolha uma sprint no filtro ou aguarde o fechamento.';
        }
        if ($pi !== null && $times === []) {
            $avisos[] = 'Nenhum time da seleção possui itens extraídos do Jira para ' . $pi['desc_pi'] . '.';
        }
        if ($ctx['times_sem_extracao']) {
            $nomes = array_column($ctx['times_sem_extracao'], 'time');
            $resto = count($nomes) - 8;
            $avisos[] = count($nomes) . ' time(s) sem extração na ' . ($pi['desc_pi'] ?? 'PI') . ' ficaram fora de todas as barras: '
                . implode(', ', array_slice($nomes, 0, 8)) . ($resto > 0 ? " e mais {$resto}" : '') . '.';
        }

        $calcula = $pi !== null && $ctx['sprints'] !== [] && $times !== [];
        $keys = array_column($times, 'projeto_key');
        $bruto = $calcula ? $this->agregar($pi['desc_pi'], $ctx['sprints'], $keys) : [];

        $porTime = [];
        $soma = $this->barrasVazias();
        foreach ($times as $t) {
            $b = $bruto[$t['projeto_key']] ?? $this->barrasVazias();
            foreach (self::BARRAS as $barra) {
                foreach ($b[$barra] as $campo => $v) $soma[$barra][$campo] += $v;
            }
            $porTime[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                          'tem_baseline' => $t['tem_baseline']] + $this->formatar($b);
        }
        $total = $this->formatar($soma);

        $diag = $this->diagnosticosVazios();
        if ($calcula) {
            $diag = $this->diagnosticos($pi['desc_pi'], $ctx['sprints'], $keys);
            $ajustados = $this->base->itensAjustados((int)$pi['id'], $pi['desc_pi'], $ctx['sprints'], $keys);
            if ($ajustados > 0) {
                $avisos[] = $ajustados . ' item(ns) da baseline desta seleção foram ajustados após a carga (ver trilha de auditoria).';
            }
            if ($total['barras']['pi_planning']['total'] == 0) {
                $avisos[] = 'Sem itens na baseline para a janela e a seleção; a barra PI Planning ficou zerada.';
            }
        }

        return [
            'janela' => [
                'origem'  => $ctx['origem'],
                'pi'      => $pi['desc_pi'] ?? null,
                'sprints' => $ctx['sprints'],
                'detalhe' => $ctx['sprints_detalhe'],
            ],
            'filtros'  => $ctx['filtros'],
            'tipos_sustentacao' => array_values($this->tiposSustentacao),
            'barras'   => $total['barras'],
            'deltas'   => $total['deltas'],
            'por_time' => $porTime,
            'avisos'   => $avisos,
            'times_sem_extracao' => $ctx['times_sem_extracao'],
            'diagnosticos' => $diag,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════

    private function r(float|int|string|null $v): float
    {
        return round((float)$v, 2);
    }

    /** Barras brutas (somáveis) zeradas. */
    private function barrasVazias(): array
    {
        $cat = array_fill_keys(self::CATEGORIAS, 0.0);
        return [
            'velocidade'      => ['sustentacao' => 0.0, 'capacidade' => 0.0, 'velocity' => 0.0],
            'pi_planning'     => $cat,
            'sprint_planning' => $cat,
            'entregues'       => $cat,
        ];
    }

    /** Barras brutas → resposta (totais = soma dos segmentos arredondados) + deltas. */
    private function formatar(array $b): array
    {
        $barras = [];
        $v = $b['velocidade'];
        $sust = $this->r($v['sustentacao']);
        $cap  = $this->r($v['capacidade']);
        $barras['velocidade'] = [
            'sustentacao' => $sust, 'valor_sem_epico' => 0.0, 'valor_com_epico' => 0.0,
            'capacidade'  => $cap,  'total' => $this->r($sust + $cap),
        ];
        foreach (['pi_planning', 'sprint_planning', 'entregues'] as $k) {
            $s = $this->r($b[$k]['sustentacao']);
            $se = $this->r($b[$k]['valor_sem_epico']);
            $ce = $this->r($b[$k]['valor_com_epico']);
            $barras[$k] = ['sustentacao' => $s, 'valor_sem_epico' => $se, 'valor_com_epico' => $ce,
                           'total' => $this->r($s + $se + $ce)];
        }
        $delta = function (array $de, array $para): array {
            $o = [];
            foreach (['sustentacao', 'valor_sem_epico', 'valor_com_epico', 'total'] as $c) {
                $o[$c] = $this->r($para[$c] - $de[$c]);
            }
            return $o;
        };
        return [
            'barras' => $barras,
            'deltas' => [
                'pi_planning_para_sprint_planning' => $delta($barras['pi_planning'], $barras['sprint_planning']),
                'sprint_planning_para_entregues'   => $delta($barras['sprint_planning'], $barras['entregues']),
            ],
        ];
    }

    /** Placeholders únicos (prepares nativos não repetem nomeados). */
    private function in(string $p, array $vals, array &$par): string
    {
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $ph[] = ":{$p}{$i}"; $par[":{$p}{$i}"] = $v; }
        return implode(',', $ph);
    }

    /** Agrega as 4 barras por projeto_key (valores brutos). */
    private function agregar(string $descPi, array $sprints, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) $out[$k] = $this->barrasVazias();

        // 1. VELOCIDADE (por sprint da janela, somada)
        $par = [':pi' => $descPi];
        $s = $this->in('vs', $sprints, $par); $k = $this->in('vk', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT projeto_key, SUM(velocity) AS velocity, SUM(sustentacao) AS sustentacao, SUM(capacity) AS capacity
               FROM vw_velocidade_sprint
              WHERE pi = :pi AND sprint IN ($s) AND projeto_key IN ($k)
              GROUP BY projeto_key"
        );
        $st->execute($par);
        foreach ($st->fetchAll() as $r) {
            $out[$r['projeto_key']]['velocidade'] = [
                'sustentacao' => (float)$r['sustentacao'], 'capacidade' => (float)$r['capacity'], 'velocity' => (float)$r['velocity'],
            ];
        }

        // 2-4. PI Planning (baseline/SPE) · Sprint Planning (extração/SPE, sem cancelados) · Entregues (extração/SP)
        $regras = [
            'pi_planning'     => ["origem = 'baseline'", 'spe'],
            'sprint_planning' => ["origem = 'extracao' AND COALESCE(status, '') <> 'Cancelado'", 'spe'],
            'entregues'       => ["origem = 'extracao' AND entregue = 1", 'sp'],
        ];
        foreach ($regras as $barra => [$where, $col]) {
            $par = [':pi' => $descPi];
            $s = $this->in('ps', $sprints, $par); $k = $this->in('pk', $keys, $par);
            $fonte = $this->fonte('pt', $par);
            $st = $this->pdo->prepare(
                "SELECT projeto_key, categoria, SUM(COALESCE($col, 0)) AS pts
                   FROM $fonte
                  WHERE pi = :pi AND $where AND categoria IS NOT NULL
                    AND sprint_ref IN ($s) AND projeto_key IN ($k)
                  GROUP BY projeto_key, categoria"
            );
            $st->execute($par);
            foreach ($st->fetchAll() as $r) {
                $out[$r['projeto_key']][$barra][$r['categoria']] = (float)$r['pts'];
            }
        }
        return $out;
    }

    // ── Diagnósticos ─────────────────────────────────────────────────────

    private function diagnosticosVazios(): array
    {
        return ['tipos_fora_das_barras' => [], 'status_sustentacao' => [], 'sustentacao_sp_nulo' => [
            'janela' => ['itens' => 0, 'spe' => 0.0], 'entregues' => ['itens' => 0, 'spe' => 0.0],
        ], 'valor_sp_nulo_entregues' => ['itens' => 0, 'spe' => 0.0]];
    }

    private function diagnosticos(string $descPi, array $sprints, array $keys): array
    {
        $d = $this->diagnosticosVazios();
        $base = function (string $extra, string $cols, string $group) use ($descPi, $sprints, $keys) {
            $par = [':pi' => $descPi];
            $s = $this->in('ds', $sprints, $par); $k = $this->in('dk', $keys, $par);
            $fonte = $this->fonte('dt', $par);
            $st = $this->pdo->prepare(
                "SELECT $cols FROM $fonte
                  WHERE pi = :pi AND sprint_ref IN ($s) AND projeto_key IN ($k) AND $extra $group"
            );
            $st->execute($par);
            return $st->fetchAll();
        };

        // Tipos fora das barras (categoria NULL), com os pontos que cada barra usaria.
        $fora = [
            ['pi_planning',     "origem = 'baseline'", 'spe'],
            ['sprint_planning', "origem = 'extracao' AND COALESCE(status, '') <> 'Cancelado'", 'spe'],
            ['entregues',       "origem = 'extracao' AND entregue = 1", 'sp'],
        ];
        foreach ($fora as [$barra, $where, $col]) {
            $rows = $base("$where AND categoria IS NULL", "COALESCE(tipo_item, '(sem tipo)') AS tipo, COUNT(*) AS itens, SUM(COALESCE($col, 0)) AS pts",
                          'GROUP BY tipo_item ORDER BY tipo_item');
            foreach ($rows as $r) {
                $d['tipos_fora_das_barras'][] = ['barra' => $barra, 'tipo_item' => $r['tipo'], 'itens' => (int)$r['itens'], 'pontos' => $this->r($r['pts'])];
            }
        }

        // Status dos itens de Sustentação (Sustentação + Suporte) na extração (existe outro "concluído" além dos 3?)
        foreach ($base("origem = 'extracao' AND categoria = 'sustentacao'",
                       "COALESCE(status, '(sem status)') AS status, MAX(entregue) AS entregue, COUNT(*) AS itens, SUM(COALESCE(spe, 0)) AS spe, SUM(COALESCE(sp, 0)) AS sp",
                       'GROUP BY status ORDER BY itens DESC') as $r) {
            $d['status_sustentacao'][] = ['status' => $r['status'], 'considerado_entregue' => (bool)$r['entregue'],
                'itens' => (int)$r['itens'], 'spe' => $this->r($r['spe']), 'sp' => $this->r($r['sp'])];
        }

        // Sustentação com SP nulo e SPE > 0 (SEM fallback aplicado)
        $r = $base("origem = 'extracao' AND categoria = 'sustentacao' AND sp IS NULL AND spe > 0",
                   'COUNT(*) AS itens, COALESCE(SUM(spe), 0) AS spe, COALESCE(SUM(entregue), 0) AS itens_ent, COALESCE(SUM(CASE WHEN entregue = 1 THEN spe ELSE 0 END), 0) AS spe_ent', '')[0];
        $d['sustentacao_sp_nulo'] = [
            'janela'    => ['itens' => (int)$r['itens'], 'spe' => $this->r($r['spe'])],
            'entregues' => ['itens' => (int)$r['itens_ent'], 'spe' => $this->r($r['spe_ent'])],
        ];

        // Mesmo diagnóstico para os itens de valor entregues
        $r = $base("origem = 'extracao' AND entregue = 1 AND categoria IN ('valor_sem_epico','valor_com_epico') AND sp IS NULL AND spe > 0",
                   'COUNT(*) AS itens, COALESCE(SUM(spe), 0) AS spe', '')[0];
        $d['valor_sp_nulo_entregues'] = ['itens' => (int)$r['itens'], 'spe' => $this->r($r['spe'])];
        return $d;
    }
}
