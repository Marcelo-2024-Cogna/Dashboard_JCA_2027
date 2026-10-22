<?php

namespace App\Services;

/**
 * CapacityCalculator
 * ──────────────────
 * Motor de cálculo de Produtividade, Velocidade e Capacidade de Carga
 * dos times para o PI Planning.
 *
 * Fórmulas (decisão de negócio):
 *
 *   A) Produtividade (horas por ponto)
 *      produtividade = total_horas_gastas / total_pontos_entregues
 *
 *   B) Capacidade de Carga (em Story Points)
 *      capacidade = (dias_uteis × horas_produtivas_por_dev × qtd_devs)
 *                   ───────────────────────────────────────────────────
 *                                    produtividade
 *
 * A produtividade de referência para o planejamento é a MÉDIA das sprints
 * concluídas válidas (ignora sprints sem pontos ou sem horas, que
 * distorceriam a média).
 */
class CapacityCalculator
{
    /**
     * A) Produtividade de UMA sprint: horas gastas por ponto entregue.
     * Retorna null se a sprint não tem pontos entregues (indefinido).
     */
    public function sprintProductivity(float $totalHoursSpent, float $totalPointsDelivered): ?float
    {
        if ($totalPointsDelivered <= 0) {
            return null; // divisão por zero — sprint sem entrega pontuável
        }
        return round($totalHoursSpent / $totalPointsDelivered, 2);
    }

    /**
     * Agrega horas gastas e pontos entregues de um conjunto de itens do Jira,
     * SEPARANDO entrega de valor de sustentação.
     *
     * REGRA DE PRODUTIVIDADE (decisão de negócio): a produtividade considera
     * SOMENTE entrega de valor. Horas e pontos de itens de Sustentação
     * (Incidente, Solicitação de Serviço, Suporte, Sustentação) NÃO entram
     * em total_hours_spent / total_points_delivered — por isso ficam em
     * campos separados (sustentacao_*), úteis depois para medir o % de
     * esforço gasto em chamados.
     *
     * @param array<int,array<string,mixed>> $items        itens normalizados
     * @param string[] $doneStatuses       status que contam como entregue
     * @param string[] $valueTypes         tipos de entrega de valor
     * @param string[] $sustentacaoTypes   tipos de sustentação (excluídos)
     * @return array{
     *   total_hours_spent: float, total_points_delivered: float,
     *   sustentacao_hours: float, sustentacao_points: float
     * }
     */
    public function aggregateFromItems(
        array $items,
        array $doneStatuses,
        array $valueTypes = [],
        array $sustentacaoTypes = []
    ): array {
        $valueHours   = 0.0;
        $valuePoints  = 0.0;
        $sustHours    = 0.0;
        $sustPoints   = 0.0;

        foreach ($items as $it) {
            $type   = $it['issue_type'] ?? null;
            $status = $it['status'] ?? null;
            $h      = $it['tempo_gasto_horas'] ?? ($it['total_hours_spent'] ?? null);
            $sp     = $it['story_points'] ?? null;
            $hours  = is_numeric($h)  ? (float) $h  : 0.0;
            $points = is_numeric($sp) ? (float) $sp : 0.0;
            $isDone = $status !== null && in_array($status, $doneStatuses, true);

            // Classifica o item: sustentação tem precedência explícita.
            $isSustentacao = $type !== null && in_array($type, $sustentacaoTypes, true);
            // Entrega de valor: está na lista de valor; se a lista estiver
            // vazia, considera tudo que NÃO é sustentação (retrocompat).
            $isValue = !empty($valueTypes)
                ? ($type !== null && in_array($type, $valueTypes, true))
                : !$isSustentacao;

            if ($isSustentacao) {
                $sustHours += $hours;
                if ($isDone) {
                    $sustPoints += $points;
                }
            } elseif ($isValue) {
                $valueHours += $hours;              // horas de valor
                if ($isDone) {
                    $valuePoints += $points;        // pontos de valor entregues
                }
            }
            // Itens de tipo desconhecido (nem valor nem sustentação) são
            // ignorados no cálculo de produtividade.
        }

        return [
            'total_hours_spent'      => round($valueHours, 2),   // só valor
            'total_points_delivered' => round($valuePoints, 1),  // só valor
            'sustentacao_hours'      => round($sustHours, 2),
            'sustentacao_points'     => round($sustPoints, 1),
        ];
    }

    /**
     * Produtividade MÉDIA de referência, a partir do histórico de sprints.
     *
     * Considera apenas sprints VÁLIDAS: com pontos > 0 e horas > 0.
     * Opcionalmente limita às N sprints mais recentes.
     *
     * @param array<int,array<string,mixed>> $sprints  histórico
     * @param int|null $lastN  usar só as N últimas sprints válidas (null = todas)
     */
    public function averageProductivity(array $sprints, ?int $lastN = null): ?float
    {
        $valid = [];
        foreach ($sprints as $s) {
            $hours  = (float)($s['total_hours_spent'] ?? 0);
            $points = (float)($s['total_points_delivered'] ?? 0);
            if ($points > 0 && $hours > 0) {
                $valid[] = $hours / $points;
            }
        }

        if (!$valid) {
            return null;
        }

        // Mais recentes primeiro, se lastN definido
        if ($lastN !== null && $lastN > 0) {
            $valid = array_slice($valid, -$lastN);
        }

        return round(array_sum($valid) / count($valid), 2);
    }

    /**
     * Velocidade MÉDIA de referência: pontos entregues por sprint.
     * Linha-guia simples para comparar com a capacidade calculada.
     *
     * @param array<int,array<string,mixed>> $sprints
     */
    public function averageVelocity(array $sprints, ?int $lastN = null): ?float
    {
        $points = [];
        foreach ($sprints as $s) {
            $p = (float)($s['total_points_delivered'] ?? 0);
            if ($p > 0) {
                $points[] = $p;
            }
        }
        if (!$points) {
            return null;
        }
        if ($lastN !== null && $lastN > 0) {
            $points = array_slice($points, -$lastN);
        }
        return round(array_sum($points) / count($points), 1);
    }

    /**
     * B) Capacidade de Carga (em Story Points) para a próxima PI/sprint.
     *
     * @param int   $workingDays           dias úteis da sprint
     * @param float $productiveHoursPerDev horas produtivas por dev/dia
     * @param float $devCount              quantidade de devs no time (pode
     *                                     ser fracionária, ex: 7.5)
     * @param float $productivity          horas por ponto (referência)
     *
     * @return float|null  capacidade em pontos; null se produtividade inválida
     */
    public function capacity(
        int   $workingDays,
        float $productiveHoursPerDev,
        float $devCount,
        float $productivity
    ): ?float {
        if ($productivity <= 0) {
            return null;
        }
        $totalProductiveHours = $workingDays * $productiveHoursPerDev * $devCount;
        return round($totalProductiveHours / $productivity, 1);
    }

    /**
     * Cálculo completo para o PI Planning de um time: produtividade e
     * velocidade de referência (do histórico) + capacidade projetada
     * (com os parâmetros da nova sprint).
     *
     * @param array<int,array<string,mixed>> $sprints  histórico do time
     * @param array{working_days:int, productive_hours_per_dev:float, dev_count:int} $planParams
     * @param int|null $lastN  janela de sprints para a média
     */
    public function planForTeam(array $sprints, array $planParams, ?int $lastN = 3): array
    {
        $productivity = $this->averageProductivity($sprints, $lastN);
        $velocity     = $this->averageVelocity($sprints, $lastN);

        $capacity = null;
        if ($productivity !== null) {
            $capacity = $this->capacity(
                (int)($planParams['working_days'] ?? 0),
                (float)($planParams['productive_hours_per_dev'] ?? 0),
                (float)($planParams['dev_count'] ?? 0),   // devs pode ser fracionário
                $productivity
            );
        }

        return [
            'reference_productivity' => $productivity,   // horas/ponto
            'reference_velocity'     => $velocity,        // pontos/sprint (histórico)
            'plan_params'            => [
                'working_days'             => (int)($planParams['working_days'] ?? 0),
                'productive_hours_per_dev' => (float)($planParams['productive_hours_per_dev'] ?? 0),
                'dev_count'                => (float)($planParams['dev_count'] ?? 0),
            ],
            'calculated_capacity'    => $capacity,        // pontos que o time pode assumir
            'sprints_considered'     => $lastN,
        ];
    }
}
