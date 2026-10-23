<?php
/**
 * config/capacity.php — PARÂMETROS DE CAPACIDADE (editável por PI)
 * ─────────────────────────────────────────────────────────────────
 * Calendário de dias úteis por sprint + parâmetros por time:
 * quantidade de devs, produtividade de referência (H/P) e horas
 * produtivas/dia.
 *
 * FONTES (PI 4/26):
 *   - "Template Baseline 26-4.xlsx": mapa ID do time (col F Versões
 *     Corrigidas) ↔ nome do time e jornada (col D Projeto).
 *   - "Template Velocity 26-4.xlsx", aba 2026_Produtividade:
 *     col B Time, col C Qtde de Devs, col D Produtividade (H/P),
 *     col E Horas Produtivas/dia.
 *
 * JORNADAS INCLUÍDAS: Ingresso, Acadêmica, Aprendizagem Digital,
 * Atendimento e Serviços, Secretaria e Gestor de Unidades, Saber.
 *
 * JORNADAS EXCLUÍDAS (usam os defaults de config/baseline.php —
 * 4 devs, 7h): Financeira, Pós Graduação, IA, Voomp, Digital Finance.
 *
 * TUDO PARAMETRIZÁVEL: a cada nova PI, atualize calendário e os mapas.
 */

return [
    // ── Calendário de dias úteis por sprint ───────────────────────────────
    // Fonte: aba "Apoio". Já desconta os ritos (Planning/Review/Retro).
    'working_days_by_sprint' => [
        '26.4.1' => 8,
        '26.4.2' => 7,
        '26.4.3' => 7,
        '26.4.4' => 7,
        '26.4.5' => 8,
        '26.4.6' => 8,
    ],

    // ── Datas de início/fim por sprint ────────────────────────────────────
    // Fonte: aba "Apoio" (Velocity 26-4). Usado para DETECTAR
    // automaticamente a última sprint ENCERRADA (fim < hoje), base do
    // filtro Planejado x Realizado. Atualize a cada PI.
    'sprint_dates' => [
        '26.4.1' => ['start' => '2026-09-28', 'end' => '2026-10-09'],
        '26.4.2' => ['start' => '2026-10-12', 'end' => '2026-10-23'],
        '26.4.3' => ['start' => '2026-10-26', 'end' => '2026-11-06'],
        '26.4.4' => ['start' => '2026-11-09', 'end' => '2026-11-20'],
        '26.4.5' => ['start' => '2026-11-23', 'end' => '2026-12-04'],
        '26.4.6' => ['start' => '2026-12-07', 'end' => '2026-12-18'],
    ],

    // ── Quantidade de devs por time ───────────────────────────────────────
    // CHAVE = ID do time em "Versões corrigidas" do Jira.
    // Times sem entrada usam 'dev_count_default' de config/baseline.php (4).
    'dev_count_by_team' => [
        // Jornada Secretaria e Gestor de Unidades
        'SGGU' => 9,     // Gestor de Unidades
        'SGSE' => 6,     // Secretaria - Enturmação (Velocity: Athenas Sec. Enturmação)
        'SGGS' => 11,    // Secretaria - Gestão Acadêmica (Velocity: Athenas Sec. Gestão Acad.)
        'SGTB' => 7,     // Thunderbolts

        // Jornada Ingresso
        'INAP' => 9,     // Aplicativos de Captação
        'INBI' => 5,     // Backoffice de Inscrição
        'INBO' => 5.5,   // Backoffice de Ofertas
        'INCA' => 8,     // Captar
        'INFU' => 5,     // Funis e Área do Candidato
        'INPV' => 3,     // Portais e Vitrines (Velocity: Portais e Vitrine)

        // Jornada Acadêmica
        'JACM' => 2,     // CMS (Velocity: CMS Adobe)
        'JACO' => 2,     // Colaborar
        'JADO' => 6.1,   // Docência
        'JAES' => 6,     // Estágio
        'JAMO' => 2,     // Moodle
        'JASA' => 5,     // SAG

        // Jornada Aprendizagem Digital
        'APAP' => 4,     // Aprendizagem
        'APAV' => 5.5,   // Avaliação
        'APCO' => 3,     // Comunicação
        'APIN' => 6.2,   // Interação
        'APPL' => 6.2,   // Plataforma
        'APSF' => 5,     // Secretaria e Financeiro

        // Jornada Atendimento e Serviços
        'ASAT' => 8.8,   // Atendimento (Velocity: Athenas Atendimento)
        'ASKB' => 4,     // KBots
        'ASPL' => 6,     // Papa-Léguas
        'ASR6' => 5,     // Route 66

        // Saber
        'SADV' => 7,     // Devengers
        'SAJO' => 6.5,   // Joker!
        'SATT' => 10,    // Team Tregamos
    ],

    // ── Produtividade de referência (H/P) por time ────────────────────────
    // Fonte: Velocity 26-4, col D. Seed do planejamento até o histórico
    // automático (/capacity/sync) acumular sprints reais.
    'reference_productivity_by_team' => [
        // Secretaria e Gestor de Unidades
        'SGGU' => 6,    'SGSE' => 7,    'SGGS' => 7,    'SGTB' => 7,
        // Ingresso
        'INAP' => 6.5,  'INBI' => 7,    'INBO' => 5.2,  'INCA' => 8.4,
        'INFU' => 4.8,  'INPV' => 5.8,
        // Acadêmica
        'JACM' => 5,    'JACO' => 4,    'JADO' => 4.7,  'JAES' => 5.4,
        'JAMO' => 4.8,  'JASA' => 5.7,
        // Aprendizagem Digital
        'APAP' => 4.1,  'APAV' => 4.5,  'APCO' => 4,    'APIN' => 5.2,
        'APPL' => 4.8,  'APSF' => 5.2,
        // Atendimento e Serviços
        'ASAT' => 4.7,  'ASKB' => 5.2,  'ASPL' => 5.1,  'ASR6' => 5,
        // Saber
        'SADV' => 4.8,  'SAJO' => 5,    'SATT' => 5.9,
    ],

    // ── Horas produtivas por dev/dia (por time) ───────────────────────────
    // Fonte: Velocity 26-4, col E. Varia por time (Secretaria/Atendimento
    // Athenas usam 6h; maioria 7h; Team Tregamos 8h).
    'productive_hours_by_team' => [
        // Secretaria e Gestor de Unidades (6h)
        'SGGU' => 6,    'SGSE' => 6,    'SGGS' => 6,    'SGTB' => 6,
        // Ingresso (7h)
        'INAP' => 7,    'INBI' => 7,    'INBO' => 7,    'INCA' => 7,
        'INFU' => 7,    'INPV' => 7,
        // Acadêmica (7h)
        'JACM' => 7,    'JACO' => 7,    'JADO' => 7,    'JAES' => 7,
        'JAMO' => 7,    'JASA' => 7,
        // Aprendizagem Digital (7h)
        'APAP' => 7,    'APAV' => 7,    'APCO' => 7,    'APIN' => 7,
        'APPL' => 7,    'APSF' => 7,
        // Atendimento e Serviços (Athenas Atendimento usa 6h; demais 7h)
        'ASAT' => 6,    'ASKB' => 7,    'ASPL' => 7,    'ASR6' => 7,
        // Saber (Team Tregamos 8h; demais 7h)
        'SADV' => 7,    'SAJO' => 7,    'SATT' => 8,
    ],

    // ── Horas produtivas padrão (fallback) ────────────────────────────────
    // Usado por times sem entrada em 'productive_hours_by_team'
    // (ex: jornadas excluídas). Default: 7h.
    'productive_hours_per_dev' => 7,
];
