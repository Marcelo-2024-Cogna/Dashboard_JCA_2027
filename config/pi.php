<?php
/**
 * config/pi.php — PARÂMETROS DA PI CORRENTE
 * ───────────────────────────────────────────
 * ESTE É O ÚNICO ARQUIVO QUE VOCÊ EDITA A CADA NOVA PI PLANNING.
 *
 * Centraliza tudo que muda de um ciclo para outro: identificação da PI,
 * times (projetos), sprints, sprint de fechamento e tipos de item.
 *
 * É consumido pelo App\Services\JqlBuilder, que monta as JQLs usadas em
 * TODO o projeto (/extract, /baseline/capture, /metrics). Assim, trocar
 * de PI = editar apenas este arquivo.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CHECKLIST ao virar a PI:                                            │
 * │   1. Atualize 'current_pi'      (ex: '4/26' → '1/27')                │
 * │   2. Atualize 'sprints'         (as 6 sprints do novo ciclo)         │
 * │   3. Reinicie 'closing_sprint'  para null (ou a 1ª a encerrar)       │
 * │   4. Ajuste 'projects' só se entrar/sair algum time                  │
 * └─────────────────────────────────────────────────────────────────────┘
 */

return [
    // ── Identificação da PI corrente ──────────────────────────────────────
    // Formato "X/YY" conforme aparece em "Versões corrigidas" (ex: PI 4/26).
    'current_pi' => '4/26',

    // ── Projetos (times) incluídos ────────────────────────────────────────
    // Chaves de projeto do Jira. Edite ao incluir/remover times da jornada.
    'projects' => [
        'APAP', 'APAV', 'APSF', 'APCO', 'APPL', 'APIN', 'ADPL', 'ADR6', 'ADKB',
        'SGGU', 'SGGS', 'SGSE', 'SGTB', 'ATEN', 'JIEC', 'JICA', 'JIBK', 'JIMF',
        'JIAE', 'JICE', 'JICS', 'JACO', 'JAMO', 'JAAM', 'JFHM', 'JFSP', 'JFTR',
        'JFEA', 'APDO', 'PGCM', 'PGEX', 'PGIA', 'PGAL', 'SAJO', 'SATT', 'SADV',
    ],

    // ── Sprints da PI corrente ────────────────────────────────────────────
    // Todas as sprints do ciclo, na ordem cronológica.
    'sprints' => ['26.4.1', '26.4.2', '26.4.3', '26.4.4', '26.4.5', '26.4.6'],

    // ── Sprint de fechamento (análise de evolução) ────────────────────────
    // Para avaliar a PI, olhamos até a ÚLTIMA sprint ENCERRADA (nunca a
    // corrente). Ex.: no meio de uma PI de 6 sprints, definir a última
    // concluída. null = considera TODAS as sprints de 'sprints'.
    'closing_sprint' => null,   // ex: '26.4.5'

    // ── Tipos de item (issue types) ───────────────────────────────────────
    // Usar standardIssueTypes() do Jira em TODAS as JQLs (inclui História,
    // Enabler, Débito Técnico, Spike, Teste, Incidente, Sustentação...).
    'issue_types_jql' => 'standardIssueTypes()',

    // ── Ordenação da extração ─────────────────────────────────────────────
    'order_by' => 'project',
];
