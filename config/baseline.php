<?php
/**
 * config/baseline.php — REGRAS DE NEGÓCIO + PARÂMETROS DA PI
 * ───────────────────────────────────────────────────────────
 * Este arquivo contém as REGRAS DE NEGÓCIO dos indicadores ágeis
 * (que raramente mudam) e MESCLA os parâmetros da PI corrente vindos
 * de config/pi.php (que você edita a cada ciclo).
 *
 * Para TROCAR DE PI → edite apenas config/pi.php.
 * Para mudar REGRA (ex: o que conta como "Done") → edite aqui.
 *
 * O resultado é um array único consumido por JqlBuilder, MetricsService,
 * BaselineController e ExtractorController.
 */

// Parâmetros da PI corrente (projetos, sprints, tipos, PI, fechamento)
$pi = require __DIR__ . '/pi.php';

// Regras de negócio (estáveis entre PIs)
$rules = [
    // Status considerados "Done" (item entregue).
    // Decisão: Aceito + Em produção + Pendente de Produção.
    'done_statuses' => [
        'Aceito',
        'Em produção',
        'Pendente de Produção',
    ],

    // ── Tipos para cálculo de PRODUTIVIDADE ───────────────────────────────
    // Decisão: a produtividade considera SOMENTE entrega de valor.
    // Horas gastas em Sustentação (Incidente, Solicitação de Serviço,
    // Suporte, Sustentação) e seus Story Points NÃO entram no cálculo.
    'value_delivery_types' => [
        'História',
        'Enabler',
        'Teste',
        'Spike',
        'Débito Técnico',
    ],
    // Tipos de sustentação (excluídos da produtividade; úteis depois para
    // medir o % de SP gasto em chamados).
    'sustentacao_types' => [
        'Incidente',
        'Solicitação de Serviço',
        'Suporte',
        'Sustentação',
    ],

    // Meta de previsibilidade (%) — linha de referência nos gráficos.
    'predictability_target' => 80,

    // Armazenamento dos snapshots (fase JSON).
    // Futuro: trocar JsonBaselineRepository por implementação de banco
    // (MySQL/PostgreSQL) sem alterar Services/Controllers.
    'storage_dir' => __DIR__ . '/../data/baselines',

    // Armazenamento do histórico de capacidade (sprint_metrics por time).
    'capacity_dir' => __DIR__ . '/../data/capacity',

    // Janela padrão de sprints para a média de produtividade/velocidade.
    'capacity_last_n' => 3,

    // ── Defaults de capacidade (fallback) ─────────────────────────────────
    // Os parâmetros detalhados por time/sprint ficam em config/capacity.php.
    // Estes defaults valem para times SEM mapeamento lá (ex: jornadas
    // excluídas: Financeira, Pós Graduação, IA, Voomp, Digital Finance).
    // Decisão: 4 devs, 8 dias úteis, 7 horas produtivas.
    'capacity_defaults' => [
        'working_days'             => 8,   // dias úteis (descontados os ritos)
        'productive_hours_per_dev' => 7,   // horas produtivas/dia (padrão)
        'dev_count_default'        => 4,   // devs (padrão times não mapeados)
    ],
];

// Mescla: parâmetros da PI + regras de negócio num único array.
return array_merge($pi, $rules);
