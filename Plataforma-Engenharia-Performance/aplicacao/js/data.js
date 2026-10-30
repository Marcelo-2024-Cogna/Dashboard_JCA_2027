/**
 * PEP - Plataforma de Engenharia de Performance
 * Base de Dados - Modelo Mensal (Jan–Dez por jornada/time)
 *
 * Task 3.1 (spec score-performance-executivo): migra o snapshot único para o
 * modelo mensal `MonthlyTeamRecord (mês, jornada, time, métricas)` descrito no
 * design ("Data Models" → "Modelo mensal"). Requirements 8.1, 8.2, 8.6.
 *
 * Notas de reconciliação com o scoring-engine.js (já reconciliado):
 * - Team Health Check é expresso em escala 0–10 (o snapshot legado usava 0–1;
 *   a conversão multiplica por 10, ex.: 0.82 → 8.2).
 * - Métricas percentuais permanecem em escala 0..n (1.0 = 100%).
 * - Cycle Time em dias; Frequência de Deploy como categoria.
 *
 * O dataset é ESPARSO por design: nem todo par (mês, time) precisa existir.
 * Aqui geramos dados plausíveis para 2026 cobrindo Jan–Ago ("realizado até
 * ago/2026", como nos PDFs reais) a partir dos times/métricas de referência,
 * para alimentar as visões de evolução mensal.
 */

const PEPData = (() => {

    const YEAR = 2026;

    // Rótulos de mês (Jan=1 ... Dez=12).
    const MONTH_LABELS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun',
        'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    // Meses com dado realizado (carga real até set/2026 → Jan..Set = 1..9).
    // O último mês (Set) recebe os valores REAIS de `BASE_TEAMS` (PI26.3 /
    // Set/2026 da planilha de Score); os meses anteriores são derivados.
    const MONTHS_WITH_DATA = [1, 2, 3, 4, 5, 6, 7, 8, 9];
    const LAST_MONTH_WITH_DATA = MONTHS_WITH_DATA[MONTHS_WITH_DATA.length - 1];

    // Período de referência legado (mantido para compatibilidade com a UI).
    const CURRENT_SPRINT = `Realizado até ${MONTH_LABELS[LAST_MONTH_WITH_DATA - 1]}/${YEAR}`;

    // As 10 jornadas (preservadas).
    const JORNADAS = [
        'Acadêmica',
        'Saber',
        'Financeira',
        'Ingresso',
        'Pós Graduação',
        'Inteligência Artificial',
        'Voomp',
        'Atendimento e Serviços',
        'Aprendizagem',
        'Secretaria e Gestor de Unidades'
    ];

    // =========================================================================
    // TIMES DE REFERÊNCIA (base para gerar os registros mensais)
    // Métricas na escala do scoring-engine reconciliado: percentuais em 0..n,
    // Cycle Time em dias, freqDeploy como categoria e teamHealth em 0..10.
    // =========================================================================
    const BASE_TEAMS = [
        // === ACADÊMICA ===
        { id: 'acad-colaborar', name: 'Colaborar', jornada: 'Acadêmica',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 2.3, cycleTimeHomolog: 1.1, cycleTimeImpl: 5.7, freqDeploy: 2, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.974, teamHealth: 9.1 } },
        { id: 'acad-estagio', name: 'Estágio', jornada: 'Acadêmica',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.5, cycleTimeHomolog: 1, cycleTimeImpl: 6.3, freqDeploy: 2, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.985, teamHealth: 9.1 } },
        { id: 'acad-docencia', name: 'Docência', jornada: 'Acadêmica',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3, cycleTimeHomolog: 1.4, cycleTimeImpl: 8.1, freqDeploy: 2, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.6, slaBaixa: 0.912, teamHealth: 9.1 } },
        { id: 'acad-sag', name: 'SAG', jornada: 'Acadêmica',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.8, cycleTimeHomolog: 1.1, cycleTimeImpl: 9, freqDeploy: 2, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.1 } },
        { id: 'acad-moodle', name: 'Moodle', jornada: 'Acadêmica',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 2.1, cycleTimeHomolog: 2.3, cycleTimeImpl: 5, freqDeploy: 4, okrs: 1, disponibilidade: 0.996, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.938, slaBaixa: 0.933, teamHealth: 9.1 } },
        // === SABER ===
        { id: 'saber-devengers', name: 'Devengers', jornada: 'Saber',
          metrics: { planejadoVsRealizado: 0.893, cycleTimeDev: 3.7, cycleTimeHomolog: 1.6, cycleTimeImpl: 1, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.85 } },
        { id: 'saber-joker', name: 'Joker!', jornada: 'Saber',
          metrics: { planejadoVsRealizado: 0.807, cycleTimeDev: 3.5, cycleTimeHomolog: 1.8, cycleTimeImpl: 4.5, freqDeploy: 4, okrs: 1, disponibilidade: 0.991, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.85 } },
        { id: 'saber-teamtregamos', name: 'TeamTregamos', jornada: 'Saber',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.3, cycleTimeHomolog: 1.6, cycleTimeImpl: 5, freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.85 } },
        // === FINANCEIRA ===
        { id: 'fin-eagles', name: 'Eagles', jornada: 'Financeira',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 4.2, cycleTimeHomolog: 2, cycleTimeImpl: 1.3, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.905, teamHealth: 9.26 } },
        { id: 'fin-house-of-money', name: 'House of Money', jornada: 'Financeira',
          metrics: { planejadoVsRealizado: 0.945, cycleTimeDev: 3.2, cycleTimeHomolog: 1, cycleTimeImpl: 13, freqDeploy: 4, okrs: 1, disponibilidade: 0.998, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.26 } },
        { id: 'fin-triforce-sparta', name: 'Triforce_Sparta', jornada: 'Financeira',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 4.5, cycleTimeHomolog: 1.2, cycleTimeImpl: 11.1, freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.26 } },
        // === INGRESSO ===
        { id: 'ing-backoffice-de-ingresso', name: 'Backoffice de Ingresso', jornada: 'Ingresso',
          metrics: { planejadoVsRealizado: 0.98, cycleTimeDev: 3.945, cycleTimeHomolog: 1.356, cycleTimeImpl: 12.182, freqDeploy: 2, okrs: 1, disponibilidade: 1, vulnerabilidade: 1.1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.5, teamHealth: 8.83 } },
        { id: 'ing-captar', name: 'Captar', jornada: 'Ingresso',
          metrics: { planejadoVsRealizado: 0.986, cycleTimeDev: 4.92, cycleTimeHomolog: 2.12, cycleTimeImpl: 8.6, freqDeploy: 4, okrs: 1, disponibilidade: 0.997, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.2, slaBaixa: 0.5, teamHealth: 8.83 } },
        { id: 'ing-consultoria-educacao', name: 'Consultoria Educação', jornada: 'Ingresso',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 4.731, cycleTimeHomolog: 1.5, cycleTimeImpl: 12.057, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.83 } },
        { id: 'ing-customer-success', name: 'Customer Success', jornada: 'Ingresso',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3, cycleTimeHomolog: 1, cycleTimeImpl: 5, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.85, slaBaixa: 0.97, teamHealth: 8.83 } },
        { id: 'ing-ecomm', name: 'Ecomm', jornada: 'Ingresso',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.316, cycleTimeHomolog: 1.516, cycleTimeImpl: 5.878, freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1.1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.83 } },
        // === PÓS GRADUAÇÃO ===
        { id: 'pos-cmd', name: 'CMD', jornada: 'Pós Graduação',
          metrics: { planejadoVsRealizado: 1.15, cycleTimeDev: 3.2, cycleTimeHomolog: 2.4, cycleTimeImpl: 9.6, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.956, teamHealth: 9.2 } },
        { id: 'pos-externo', name: 'EXTERNO', jornada: 'Pós Graduação',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 4.7, cycleTimeHomolog: 1.2, cycleTimeImpl: 9, freqDeploy: 2, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.875, teamHealth: 9.2 } },
        { id: 'pos-pos', name: 'Pos', jornada: 'Pós Graduação',
          metrics: { planejadoVsRealizado: 1.117, cycleTimeDev: 3.5, cycleTimeHomolog: 1.7, cycleTimeImpl: 8.6, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.2 } },
        // === INTELIGÊNCIA ARTIFICIAL ===
        { id: 'ia-alex', name: 'Alex', jornada: 'Inteligência Artificial',
          metrics: { planejadoVsRealizado: 0.821, cycleTimeDev: 5.3, cycleTimeHomolog: 1, cycleTimeImpl: 0, freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1.1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.08 } },
        { id: 'ia-cogna-ia', name: 'COGNA IA', jornada: 'Inteligência Artificial',
          metrics: { planejadoVsRealizado: 1.01, cycleTimeDev: 4.6, cycleTimeHomolog: 1.2, cycleTimeImpl: 1.5, freqDeploy: 4, okrs: 1, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.08 } },
        // === VOOMP ===
        { id: 'voomp-admin', name: 'Admin', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 0.404, cycleTimeDev: 4.8, cycleTimeHomolog: 1.3, cycleTimeImpl: 13.7, freqDeploy: 2, okrs: 0.429, disponibilidade: 0.999, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        { id: 'voomp-backoffice-erp', name: 'Backoffice ERP', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 1.2, cycleTimeDev: 2.5, cycleTimeHomolog: 1.3, cycleTimeImpl: 1, freqDeploy: 4, okrs: 0.625, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        { id: 'voomp-gateway', name: 'Gateway', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 0.835, cycleTimeDev: 6.6, cycleTimeHomolog: 1.3, cycleTimeImpl: 1.4, freqDeploy: 4, okrs: 0.444, disponibilidade: 0.996, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        { id: 'voomp-gestao-financeira', name: 'Gestão Financeira', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 0.606, cycleTimeDev: 5.7, cycleTimeHomolog: 2.2, cycleTimeImpl: 1.5, freqDeploy: 2, okrs: 0.833, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        { id: 'voomp-meio-de-pagamento', name: 'Meio de Pagamento', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 0.712, cycleTimeDev: 5, cycleTimeHomolog: 1.4, cycleTimeImpl: 1.4, freqDeploy: 4, okrs: 0.615, disponibilidade: 1, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        { id: 'voomp-play', name: 'Play', jornada: 'Voomp',
          metrics: { planejadoVsRealizado: 0.368, cycleTimeDev: 1.6, cycleTimeHomolog: 1.1, cycleTimeImpl: 0, freqDeploy: 2, okrs: 1, disponibilidade: 0.996, vulnerabilidade: 0, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 0 } },
        // === ATENDIMENTO E SERVIÇOS ===
        { id: 'atend-athenas-atendimento', name: 'Athenas - Atendimento', jornada: 'Atendimento e Serviços',
          metrics: { planejadoVsRealizado: 0.988, cycleTimeDev: 4.4, cycleTimeHomolog: 3, cycleTimeImpl: 1, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.97, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.53, slaBaixa: 0.76, teamHealth: 8.6 } },
        { id: 'atend-kbots', name: 'kBots', jornada: 'Atendimento e Serviços',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.3, cycleTimeHomolog: 1.2, cycleTimeImpl: 0, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.935, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 8.6 } },
        { id: 'atend-papa-leguas', name: 'Papa-léguas', jornada: 'Atendimento e Serviços',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 2.7, cycleTimeHomolog: 1, cycleTimeImpl: 8, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.984, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.977, teamHealth: 8.6 } },
        { id: 'atend-route66', name: 'Route66', jornada: 'Atendimento e Serviços',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3.7, cycleTimeHomolog: 1, cycleTimeImpl: 19.7, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.929, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.976, teamHealth: 8.6 } },
        // === APRENDIZAGEM ===
        { id: 'aprend-aprendizagem', name: 'Aprendizagem', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 1.11, cycleTimeDev: 3.1, cycleTimeHomolog: 1, cycleTimeImpl: 5.1, freqDeploy: 4, okrs: 1, disponibilidade: 0.996, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.5 } },
        { id: 'aprend-avaliacao', name: 'Avaliação', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 0.914, cycleTimeDev: 4.3, cycleTimeHomolog: 1.3, cycleTimeImpl: 16.9, freqDeploy: 2, okrs: 1, disponibilidade: 0.991, vulnerabilidade: 1, slaAlta: 0, slaMedia: 0.74, slaBaixa: 0.8, teamHealth: 9.3 } },
        { id: 'aprend-comunicacao', name: 'Comunicação', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 0.8, cycleTimeDev: 6.4, cycleTimeHomolog: 1.4, cycleTimeImpl: 7.3, freqDeploy: 2, okrs: 1, disponibilidade: 0.995, vulnerabilidade: 0.5, slaAlta: 1, slaMedia: 1, slaBaixa: 0.8, teamHealth: 8.8 } },
        { id: 'aprend-interacao', name: 'Interação', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 1.04, cycleTimeDev: 3.8, cycleTimeHomolog: 1.3, cycleTimeImpl: 7.9, freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.844, teamHealth: 9.1 } },
        { id: 'aprend-secretaria-e-financeiro', name: 'Secretaria e Financeiro', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 0.894, cycleTimeDev: 4.7, cycleTimeHomolog: 1.3, cycleTimeImpl: 20, freqDeploy: 2, okrs: 1, disponibilidade: 0.991, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.1, slaBaixa: 0.841, teamHealth: 6.6 } },
        { id: 'aprend-plataforma', name: 'Plataforma', jornada: 'Aprendizagem',
          metrics: { planejadoVsRealizado: 1.11, cycleTimeDev: 3.4, cycleTimeHomolog: 1.6, cycleTimeImpl: 4.6, freqDeploy: 2, okrs: 1, disponibilidade: 0.996, vulnerabilidade: 1, slaAlta: 1, slaMedia: 1, slaBaixa: 0.86, teamHealth: 9.5 } },
        // === SECRETARIA E GESTOR DE UNIDADES ===
        { id: 'sec-gestao-enturmacao', name: 'Gestão Enturmação', jornada: 'Secretaria e Gestor de Unidades',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 4, cycleTimeHomolog: 1, cycleTimeImpl: 7, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.99, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.3, slaBaixa: 0.54, teamHealth: 9.1 } },
        { id: 'sec-gestao-academica', name: 'Gestão Acadêmica', jornada: 'Secretaria e Gestor de Unidades',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 5, cycleTimeHomolog: 1, cycleTimeImpl: 7, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.99, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.17, slaBaixa: 0.26, teamHealth: 9.1 } },
        { id: 'sec-thunderbolts', name: 'Thunderbolts', jornada: 'Secretaria e Gestor de Unidades',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3, cycleTimeHomolog: 2, cycleTimeImpl: 7, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.99, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.78, slaBaixa: 0.93, teamHealth: 9.1 } },
        { id: 'sec-gestor-de-unidades-e-polos', name: 'Gestor de Unidades e Polos', jornada: 'Secretaria e Gestor de Unidades',
          metrics: { planejadoVsRealizado: 1, cycleTimeDev: 3, cycleTimeHomolog: 1, cycleTimeImpl: 7, freqDeploy: 4, okrs: 0.99, disponibilidade: 0.99, vulnerabilidade: 1, slaAlta: 1, slaMedia: 0.64, slaBaixa: 0.85, teamHealth: 9.1 } },
    ];

    // =========================================================================
    // GERAÇÃO DOS REGISTROS MENSAIS
    // A partir dos times de referência (tratados como o mês mais recente com
    // dado), geramos uma trajetória mês a mês plausível e DETERMINÍSTICA (sem
    // Math.random, para reprodutibilidade), com leve tendência de melhoria ao
    // longo do ano e pequena variação por time. Os valores permanecem dentro
    // dos domínios esperados pelo scoring-engine (ratios 0..~1.1, dias >0,
    // THC 0..10, freqDeploy categoria inteira ≥0).
    // =========================================================================

    // PRNG determinístico simples (mulberry32) semeado por string.
    function seededUnit(seedStr) {
        let h = 1779033703 ^ seedStr.length;
        for (let i = 0; i < seedStr.length; i++) {
            h = Math.imul(h ^ seedStr.charCodeAt(i), 3432918353);
            h = (h << 13) | (h >>> 19);
        }
        let t = (h ^= h >>> 16) >>> 0;
        t += 0x6D2B79F5;
        let r = Math.imul(t ^ (t >>> 15), 1 | t);
        r ^= r + Math.imul(r ^ (r >>> 7), 61 | r);
        return ((r ^ (r >>> 14)) >>> 0) / 4294967296; // [0,1)
    }

    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
    const round2 = (v) => Math.round(v * 100) / 100;
    const round3 = (v) => Math.round(v * 1000) / 1000;

    // Fator de progresso: no último mês com dado atinge os valores de BASE_TEAMS;
    // meses anteriores ficam um pouco abaixo (métricas "melhoram" rumo ao fim).
    function progressFactor(month) {
        const span = LAST_MONTH_WITH_DATA - MONTHS_WITH_DATA[0]; // ex.: 7
        if (span <= 0) return 1;
        const idx = month - MONTHS_WITH_DATA[0];                 // 0..span
        // começa em ~0.90 e cresce até 1.0 no último mês
        return 0.90 + 0.10 * (idx / span);
    }

    // Deriva as métricas de um time para um mês específico.
    function deriveMetrics(team, month) {
        const base = team.metrics;
        const pf = progressFactor(month);
        // ruído determinístico por (time, mês), pequeno: ±2%
        const noise = (metricKey) => {
            const u = seededUnit(`${team.id}|${month}|${metricKey}`); // [0,1)
            return (u - 0.5) * 0.04; // ±0.02
        };

        // Percentuais (quanto maior melhor): escalam com progresso + ruído.
        const pct = (key, lo, hi) => {
            const target = base[key];
            // meses iniciais ligeiramente menores; usa (0.96..1.0) do alvo
            const scaled = target * (0.96 + 0.04 * ((pf - 0.90) / 0.10));
            return round3(clamp(scaled * (1 + noise(key)), lo, hi));
        };

        // Cycle Time (quanto menor melhor): meses iniciais um pouco maiores.
        const ct = (key) => {
            const target = base[key];
            const inflate = 1 + (1 - pf) * 0.25; // até +~2.5% nos meses iniciais
            const v = target * inflate * (1 + noise(key));
            return round2(clamp(v, 0.3, 20));
        };

        // freqDeploy: categoria inteira; pode cair 1 nível nos primeiros meses.
        const deploy = () => {
            const target = base.freqDeploy;
            const drop = pf < 0.95 && seededUnit(`${team.id}|${month}|dep`) < 0.35 ? 1 : 0;
            return Math.max(0, target - drop);
        };

        // teamHealth 0..10: escala com progresso + ruído.
        const thc = () => {
            const target = base.teamHealth;
            const scaled = target * (0.94 + 0.06 * ((pf - 0.90) / 0.10));
            return round2(clamp(scaled * (1 + noise('teamHealth') * 0.5), 0, 10));
        };

        return {
            planejadoVsRealizado: pct('planejadoVsRealizado', 0, 1.2),
            cycleTimeDev: ct('cycleTimeDev'),
            cycleTimeHomolog: ct('cycleTimeHomolog'),
            cycleTimeImpl: ct('cycleTimeImpl'),
            freqDeploy: deploy(),
            okrs: pct('okrs', 0, 1.1),
            disponibilidade: pct('disponibilidade', 0, 1),
            vulnerabilidade: pct('vulnerabilidade', 0, 1.2),
            slaAlta: pct('slaAlta', 0, 1),
            slaMedia: pct('slaMedia', 0, 1),
            slaBaixa: pct('slaBaixa', 0, 1),
            teamHealth: thc()
        };
    }

    // Monta os registros esparsos (um por (mês, time) nos meses com dado).
    const records = [];
    MONTHS_WITH_DATA.forEach((month) => {
        BASE_TEAMS.forEach((team) => {
            const metrics = (month === LAST_MONTH_WITH_DATA)
                ? { ...team.metrics } // mês mais recente = valores de referência
                : deriveMetrics(team, month);
            records.push({
                month,
                monthLabel: MONTH_LABELS[month - 1],
                jornada: team.jornada,
                teamId: team.id,
                teamName: team.name,
                metrics
            });
        });
    });

    // =========================================================================
    // BASELINE dez/25 (PARTIDA) POR JORNADA — Task 3.2
    // Score REALIZADO de dezembro/2025 por jornada, usado como "Partida" na
    // Visão de Metas (Requirement 7.1 / design "Data Models" → "Baseline dez/25
    // para Partida"). Os valores vêm da visão de Metas dos PDFs reais da spec.
    //
    // As chaves usam EXATAMENTE os nomes de PEPData.JORNADAS (ex.: 'Acadêmica',
    // 'Financeira', 'Atendimento e Serviços'). O total de Engenharia (média das
    // jornadas) NÃO é uma jornada; fica registrado em `_engenharia` à parte.
    // =========================================================================
    // Partida = score REALIZADO de dez/2025 por jornada (Requirement 7.1).
    // Deve ser DISTINTA do realizado do mês corrente (Set/26) — é o ponto de
    // partida fixo sobre o qual a Meta 2026 e as metas mensais são calculadas.
    // Valores da "visão de Metas" (dez/25) dos PDFs reais da spec.
    const BASELINE = {
        'Acadêmica': 916,
        'Saber': 953,
        'Financeira': 867,
        'Ingresso': 799,
        'Pós Graduação': 971,
        'Inteligência Artificial': 909,
        'Voomp': 818,
        'Atendimento e Serviços': 857,
        'Aprendizagem': 864,
        'Secretaria e Gestor de Unidades': 817,
        // Total/média de Engenharia (dez/25) — não é jornada.
        _engenharia: 863
    };

    // Acessor da Partida (score realizado dez/25) de uma jornada.
    // Retorna undefined para nome desconhecido; use '_engenharia' para o total.
    function getPartida(jornada) {
        return BASELINE[jornada];
    }

    // =========================================================================
    // DATASET (Requirement 8.1, 8.2, 8.6)
    // =========================================================================
    const DATASET = {
        year: YEAR,
        jornadas: JORNADAS,
        records,
        source: {
            current: 'planilha-manual',
            futurePlanned: ['ServiceNow', 'Datadog']
        }
    };

    // =========================================================================
    // API DE CONSULTA (mês / jornada / time)
    // =========================================================================

    // Normaliza um mês informado como número (1..12) ou rótulo ('Jan'..'Dez').
    function normalizeMonth(month) {
        if (typeof month === 'number') return month;
        if (typeof month === 'string') {
            const idx = MONTH_LABELS.findIndex(l => l.toLowerCase() === month.trim().toLowerCase());
            if (idx >= 0) return idx + 1;
            const n = parseInt(month, 10);
            if (!Number.isNaN(n)) return n;
        }
        return undefined;
    }

    // Lista de meses que efetivamente possuem registros (ordenada).
    function getMonthsWithData() {
        const set = new Set(records.map(r => r.month));
        return [...set].sort((a, b) => a - b);
    }

    // Último mês com dado (default das visões/legado).
    function getLatestMonth() {
        const months = getMonthsWithData();
        return months.length ? months[months.length - 1] : undefined;
    }

    // Todos os registros de um mês.
    function getRecords(month) {
        const m = normalizeMonth(month);
        return records.filter(r => r.month === m);
    }

    // Registro de um time em um mês.
    function getRecord(month, teamId) {
        const m = normalizeMonth(month);
        return records.find(r => r.month === m && r.teamId === teamId);
    }

    // Times de uma jornada em um mês (default = último mês com dados).
    // Retorna no formato de snapshot legado ({id,name,jornada,sprint,month,
    // monthLabel,metrics}) para manter compatibilidade com consumidores que
    // esperavam `id`/`name` (dashboard legado) e também expor o mês.
    function getTeamsByJornada(jornada, month) {
        const m = normalizeMonth(month) ?? getLatestMonth();
        return records
            .filter(r => r.month === m && r.jornada === jornada)
            .map(r => ({
                id: r.teamId,
                name: r.teamName,
                jornada: r.jornada,
                sprint: CURRENT_SPRINT,
                month: r.month,
                monthLabel: r.monthLabel,
                metrics: r.metrics
            }));
    }

    // =========================================================================
    // COMPATIBILIDADE COM O LEGADO (dashboard.js / app.js)
    // O dashboard consome TEAMS/getTeamById como um snapshot "{id,name,jornada,
    // metrics}". Expomos o snapshot do último mês com dados nesse formato para
    // não quebrar o carregamento por <script> nem a UI existente.
    // =========================================================================
    function teamSnapshotFor(month) {
        const m = normalizeMonth(month) ?? getLatestMonth();
        return getRecords(m).map(r => ({
            id: r.teamId,
            name: r.teamName,
            jornada: r.jornada,
            sprint: CURRENT_SPRINT,
            month: r.month,
            monthLabel: r.monthLabel,
            metrics: r.metrics
        }));
    }

    const TEAMS = teamSnapshotFor(LAST_MONTH_WITH_DATA);

    function getTeamById(id) {
        return TEAMS.find(t => t.id === id);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        // Modelo mensal (novo)
        YEAR,
        MONTH_LABELS,
        JORNADAS,
        DATASET,
        getRecords,
        getRecord,
        getMonthsWithData,
        getLatestMonth,
        getTeamsByJornada,
        teamSnapshotFor,

        // Baseline dez/25 (Partida) por jornada — Task 3.2 / Requirement 7.1
        BASELINE,
        getPartida,

        // Compatibilidade legada (snapshot do último mês com dados)
        CURRENT_SPRINT,
        TEAMS,
        getTeamById
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped and `PEPData` stays global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PEPData;
}

if (typeof globalThis !== 'undefined') {
    globalThis.PEPData = PEPData;
}
