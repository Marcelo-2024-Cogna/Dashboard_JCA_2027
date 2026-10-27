<?php

namespace App\Services;

/**
 * JqlBuilder
 * ──────────
 * Fonte única de montagem de JQL para toda a aplicação.
 *
 * Garante que a extração geral (/extract), a captura de baseline
 * (/baseline/capture) e o cálculo de métricas (/metrics) consultem
 * EXATAMENTE o mesmo universo de itens (mesmos projetos, sprints e
 * tipos), evitando divergência entre "planejado" e "realizado".
 *
 * Lê de config/baseline.php (fonte única de verdade).
 */
class JqlBuilder
{
    private array $cfg;

    public function __construct(array $baselineConfig)
    {
        $this->cfg = $baselineConfig;
    }

    /**
     * JQL completa da PI corrente: todos os projetos, todas as sprints
     * do ciclo, tipos padrão. Usada por /extract e /baseline/capture.
     */
    public function forCurrentPi(): string
    {
        return $this->build($this->cfg['sprints']);
    }

    /**
     * JQL de fechamento: considera apenas até a última sprint ENCERRADA
     * (config 'closing_sprint'). Se null, cai para todas as sprints.
     * Usada nas métricas de avaliação da PI.
     */
    public function forClosing(): string
    {
        $closing = $this->cfg['closing_sprint'] ?? null;

        if ($closing === null) {
            return $this->forCurrentPi();
        }

        // Inclui todas as sprints até a de fechamento (inclusive).
        $all   = $this->cfg['sprints'];
        $idx   = array_search($closing, $all, true);
        $upTo  = $idx === false ? $all : array_slice($all, 0, $idx + 1);

        return $this->build($upTo);
    }

    /**
     * JQL para um RECORTE arbitrário (usado pelo runner de extração CLI):
     * projeto(s) + uma PI (fixVersion) + sprint(s) opcionais.
     *
     * Diferente de build()/forCurrentPi() (que leem projetos/sprints de
     * config/pi.php), este método recebe TODOS os parâmetros por argumento,
     * sem depender do catálogo da PI corrente. Não altera o comportamento
     * dos métodos existentes.
     *
     * @param string[]      $projetos  chaves de projeto Jira (ex: ['SGGU'])
     * @param string        $pi        rótulo da fixVersion (ex: 'PI 4/26')
     * @param string[]|null $sprints   nomes de sprint (ex: ['SP 26.4.1']); null = todas
     */
    public function forSelection(array $projetos, string $pi, ?array $sprints = null): string
    {
        $projects = implode(', ', $projetos);
        $types    = $this->cfg['issue_types_jql'] ?? 'standardIssueTypes()';
        $orderBy  = $this->cfg['order_by'] ?? 'project';

        $jql = "project in ({$projects})"
            . " AND issuetype in {$types}"
            . ' AND fixVersion = "' . $pi . '"';

        if ($sprints !== null && $sprints !== []) {
            $sprintList = '"' . implode('", "', $sprints) . '"';
            $jql .= " AND Sprint in ({$sprintList})";
        }

        return $jql . " ORDER BY {$orderBy}";
    }

    /**
     * JQL com uma lista de sprints arbitrária.
     * @param string[] $sprints
     */
    public function build(array $sprints): string
    {
        $projects   = implode(', ', $this->cfg['projects']);
        $sprintList = '"' . implode('", "', $sprints) . '"';
        $types      = $this->cfg['issue_types_jql'] ?? 'standardIssueTypes()';
        $orderBy    = $this->cfg['order_by'] ?? 'project';

        return "project in ({$projects})"
            . " AND issuetype in {$types}"
            . " AND Sprint in ({$sprintList})"
            . " ORDER BY {$orderBy}";
    }
}
