/**
 * PEP - Plataforma de Engenharia de Performance
 * Scoring Engine - Motor de Cálculo de Pontuação
 * 
 * Implementa todas as regras de cálculo conforme planilha de referência.
 * Score base máximo: 1000 pontos; teto com bônus: 1089 pontos.
 */

const ScoringEngine = (() => {

    // =========================================================================
    // UTILITY: Linear Interpolation (LEGADO)
    // Semântica antiga `(value, minValue, maxValue, minScore, maxScore)` SEM
    // clamp. Mantida como helper interno enquanto as funções `calcXxx` legadas
    // ainda a utilizam (serão substituídas na Task 2.3). NÃO expor na API — a
    // API pública agora usa o novo `interpolate` proporcional abaixo.
    // =========================================================================
    function interpolateLegacy(value, minValue, maxValue, minScore, maxScore) {
        if (maxValue === minValue) return minScore;
        const ratio = (value - minValue) / (maxValue - minValue);
        return minScore + ratio * (maxScore - minScore);
    }

    // =========================================================================
    // UTILITY: Interpolação Proporcional (NOVA — Requirement 1.5, corrige D7)
    // Mapeia linearmente `value` dentro de `[from, to]` para `[floor, top]`,
    // SEM usar limites artificiais (ex.: 0.999). Resultado é clamped em
    // `[min(floor,top), max(floor,top)]` para nunca sair da faixa.
    //   pontos = floor + (value − from) / (to − from) × (top − floor)
    // Para `direction:'lower'` (Cycle Time), from/to/floor/top já expressam a
    // inclinação correta (ex.: CT Dev 3→125, 4→100).
    // Assinatura: interpolate(value, from, to, floor, top)
    // =========================================================================
    function interpolate(value, from, to, floor, top) {
        const lo = Math.min(floor, top);
        const hi = Math.max(floor, top);
        let score;
        if (to === from) {
            score = floor;
        } else {
            const ratio = (value - from) / (to - from);
            score = floor + ratio * (top - floor);
        }
        // Clamp em [min(floor,top), max(floor,top)] (Requirement 1.5)
        if (score < lo) return lo;
        if (score > hi) return hi;
        return score;
    }

    // =========================================================================
    // SCORING FUNCTIONS PER METRIC
    // =========================================================================

    /**
     * Planejado vs Realizado (max 150 pts)
     * @param {number} value - Ratio (e.g., 0.85 = 85%)
     */
    function calcPlanejadoVsRealizado(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.750) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.750, 0.999, 50, 120);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value === 1.000) {
            return { score: 150, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 165, band: 'superou', label: 'Superou' };
        }
    }

    /**
     * Cycle Time Desenvolvimento (max 125 pts, lower is better)
     * @param {number} value - Days
     */
    function calcCycleTimeDev(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 3) {
            return { score: 138, band: 'superou', label: 'Superou' };
        } else if (value < 4) {
            const score = interpolateLegacy(value, 3.000, 3.999, 125, 100);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else if (value < 6) {
            const score = interpolateLegacy(value, 4.000, 5.999, 50, 10);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        }
    }

    /**
     * Cycle Time Homologação (max 50 pts, lower is better)
     * @param {number} value - Days
     */
    function calcCycleTimeHomolog(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 1) {
            return { score: 55, band: 'superou', label: 'Superou' };
        } else if (value < 2) {
            const score = interpolateLegacy(value, 1.000, 1.999, 50, 30);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else if (value < 3) {
            const score = interpolateLegacy(value, 2.000, 2.999, 10, 5);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        }
    }

    /**
     * Cycle Time Implantação (max 50 pts, lower is better)
     * @param {number} value - Days
     */
    function calcCycleTimeImpl(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 5) {
            return { score: 55, band: 'superou', label: 'Superou' };
        } else if (value <= 6) {
            const score = interpolateLegacy(value, 5.000, 6.000, 50, 30);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else if (value <= 8) {
            const score = interpolateLegacy(value, 6.001, 8.000, 10, 5);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        }
    }

    /**
     * Disponibilidade de Sistema (max 175 pts)
     * @param {number} value - Ratio (e.g., 0.995 = 99.5%)
     */
    function calcDisponibilidade(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.970) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 0.990) {
            const score = interpolateLegacy(value, 0.970, 0.989, 10, 50);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value < 0.995) {
            const score = interpolateLegacy(value, 0.990, 0.994, 100, 175);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 193, band: 'superou', label: 'Superou' };
        }
    }

    /**
     * Índice de Vulnerabilidade (max 175 pts)
     * @param {number} value - Ratio
     */
    function calcVulnerabilidade(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.750) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.750, 0.999, 50, 120);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value === 1.000) {
            return { score: 175, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 193, band: 'superou', label: 'Superou' };
        }
    }

    /**
     * SLA Alta Criticidade (max 50 pts)
     * @param {number} value - Ratio
     */
    function calcSLAAlta(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.900) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.900, 0.999, 5, 40);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 50, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        }
    }

    /**
     * SLA Média Criticidade (max 30 pts)
     * @param {number} value - Ratio
     */
    function calcSLAMedia(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.900) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.900, 0.999, 3, 25);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 30, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        }
    }

    /**
     * SLA Baixa Criticidade (max 20 pts)
     * @param {number} value - Ratio
     */
    function calcSLABaixa(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.900) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.900, 0.999, 2, 15);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else {
            return { score: 20, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        }
    }

    /**
     * Alinhamento com OKRs (max 50 pts)
     * @param {number} value - Ratio
     */
    function calcOKRs(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.600) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value < 0.800) {
            const score = interpolateLegacy(value, 0.600, 0.799, 10, 25);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value < 1.000) {
            const score = interpolateLegacy(value, 0.800, 0.999, 40, 50);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 55, band: 'superou', label: 'Superou' };
        }
    }

    /**
     * Team Health Check (max 50 pts)
     * @param {number} value - Ratio (0 to 1)
     */
    function calcTeamHealth(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value < 0.700) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value <= 0.800) {
            const score = interpolateLegacy(value, 0.700, 0.800, 10, 20);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value <= 0.950) {
            const score = interpolateLegacy(value, 0.801, 0.950, 40, 50);
            return { score: Math.round(score * 100) / 100, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 55, band: 'superou', label: 'Superou' };
        }
    }

    /**
     * Frequência de Deploy (max 75 pts, categorical)
     * @param {number} value - Category: 0/1=none, 2=between monthly/weekly, 3=weekly, 4+=more than weekly
     */
    function calcFreqDeploy(value) {
        if (value === null || value === undefined) return { score: 0, band: 'sem-dados', label: 'Sem Dados' };
        if (value <= 1) {
            return { score: 0, band: 'nao-atendeu', label: 'Não Atendeu' };
        } else if (value === 2) {
            return { score: 30, band: 'atendeu-parcialmente', label: 'Atendeu Parcialmente' };
        } else if (value === 3) {
            return { score: 75, band: 'atendeu-totalmente', label: 'Atendeu Totalmente' };
        } else {
            return { score: 80, band: 'superou', label: 'Superou' };
        }
    }

    // =========================================================================
    // METRIC DEFINITIONS
    // =========================================================================
    const METRICS = {
        planejadoVsRealizado: {
            id: 'planejadoVsRealizado',
            name: 'Planejado vs Realizado',
            dimension: 'processos',
            maxScore: 150,
            unit: '%',
            calcFn: calcPlanejadoVsRealizado,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        cycleTimeDev: {
            id: 'cycleTimeDev',
            name: 'Cycle Time Desenvolvimento',
            dimension: 'processos',
            maxScore: 125,
            unit: 'dias',
            calcFn: calcCycleTimeDev,
            formatValue: (v) => `${v.toFixed(1)} dias`
        },
        cycleTimeHomolog: {
            id: 'cycleTimeHomolog',
            name: 'Cycle Time Homologação',
            dimension: 'processos',
            maxScore: 50,
            unit: 'dias',
            calcFn: calcCycleTimeHomolog,
            formatValue: (v) => `${v.toFixed(1)} dias`
        },
        cycleTimeImpl: {
            id: 'cycleTimeImpl',
            name: 'Cycle Time Implantação',
            dimension: 'processos',
            maxScore: 50,
            unit: 'dias',
            calcFn: calcCycleTimeImpl,
            formatValue: (v) => `${v.toFixed(1)} dias`
        },
        freqDeploy: {
            id: 'freqDeploy',
            name: 'Frequência de Deploy',
            dimension: 'processos',
            maxScore: 75,
            unit: 'categoria',
            calcFn: calcFreqDeploy,
            formatValue: (v) => {
                const labels = { 0: 'Nenhum', 1: 'Mensal', 2: 'Quinzenal', 3: 'Semanal', 4: 'Diário' };
                return labels[v] || `Nível ${v}`;
            }
        },
        okrs: {
            id: 'okrs',
            name: 'Alinhamento com OKRs',
            dimension: 'processos',
            maxScore: 50,
            unit: '%',
            calcFn: calcOKRs,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        disponibilidade: {
            id: 'disponibilidade',
            name: 'Disponibilidade',
            dimension: 'praticas',
            maxScore: 175,
            unit: '%',
            calcFn: calcDisponibilidade,
            formatValue: (v) => `${(v * 100).toFixed(2)}%`
        },
        vulnerabilidade: {
            id: 'vulnerabilidade',
            name: 'Índice de Vulnerabilidade',
            dimension: 'praticas',
            maxScore: 175,
            unit: '%',
            calcFn: calcVulnerabilidade,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        slaAlta: {
            id: 'slaAlta',
            name: 'SLA Alta Criticidade',
            dimension: 'praticas',
            maxScore: 50,
            unit: '%',
            calcFn: calcSLAAlta,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        slaMedia: {
            id: 'slaMedia',
            name: 'SLA Média Criticidade',
            dimension: 'praticas',
            maxScore: 30,
            unit: '%',
            calcFn: calcSLAMedia,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        slaBaixa: {
            id: 'slaBaixa',
            name: 'SLA Baixa Criticidade',
            dimension: 'praticas',
            maxScore: 20,
            unit: '%',
            calcFn: calcSLABaixa,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        },
        teamHealth: {
            id: 'teamHealth',
            name: 'Team Health Check',
            dimension: 'pessoas',
            maxScore: 50,
            unit: '%',
            calcFn: calcTeamHealth,
            formatValue: (v) => `${(v * 100).toFixed(1)}%`
        }
    };

    // =========================================================================
    // DIMENSION DEFINITIONS (LEGADO)
    // Mantido temporariamente para compatibilidade com calculateTeamScore
    // enquanto a reconciliação (Tasks 2.2/2.3) não migra para CATEGORIES.
    // =========================================================================
    const DIMENSIONS = {
        pessoas: { id: 'pessoas', name: 'Pessoas', maxScore: 50, icon: '👥' },
        processos: { id: 'processos', name: 'Processos', maxScore: 500, icon: '⚙️' },
        praticas: { id: 'praticas', name: 'Práticas', maxScore: 450, icon: '🛡️' }
    };

    // =========================================================================
    // CATEGORIES (4 Ps) — fonte da verdade (Requirement 1.1)
    // Substitui as DIMENSIONS legadas: Processos 450, Práticas 450,
    // Produto 50, Pessoas 50. OKRs passa a pertencer a 'produto' (D3/D4).
    // =========================================================================
    const CATEGORIES = {
        processos: { id: 'processos', name: 'Processos', maxScore: 450, icon: '⚙️' },
        praticas:  { id: 'praticas',  name: 'Práticas',  maxScore: 450, icon: '🛡️' },
        produto:   { id: 'produto',   name: 'Produto',   maxScore: 50,  icon: '📦' },
        pessoas:   { id: 'pessoas',   name: 'Pessoas',   maxScore: 50,  icon: '👥' }
    };

    // Teto do score (Requirement 1.7): base 1000 pts + bônus de métricas
    // superadas, com teto oficial de 1089 pts (corrige D1/D2: era 1092).
    const MAX_TOTAL_SCORE = 1089;   // corrige D1/D2
    const BASE_MAX_SCORE  = 1000;   // base sem bônus

    // =========================================================================
    // PERFORMANCE BANDS (Requirement 1.9)
    // Baixa 0–500, Média 501–839, Boa 840–1000, Ótima 1001–1089.
    // =========================================================================
    const PERFORMANCE_BANDS = [
        { id: 'baixa', name: 'Baixa Performance', min: 0, max: 500, color: '#e74c3c' },
        { id: 'media', name: 'Média Performance', min: 501, max: 839, color: '#f39c12' },
        { id: 'boa', name: 'Boa Performance', min: 840, max: 1000, color: '#27ae60' },
        { id: 'otima', name: 'Ótima Performance', min: 1001, max: MAX_TOTAL_SCORE, color: '#3498db' }
    ];

    // =========================================================================
    // METRIC_RULES — tabela de regras declarativa das 12 métricas
    // Fonte da verdade: Requirement 1.4 e bloco do design.md.
    // Direção: 'higher' (maior melhor), 'lower' (menor melhor, Cycle Time) ou
    // 'category' (Frequência de Deploy). Escalas: 'ratio' (0..n, 1.0 = 100%),
    // 'days', 'thc0a10' (0..10) e 'category'. Cada faixa define seus limites e,
    // para a faixa parcial, o piso (floor) e o topo (top) da interpolação.
    // =========================================================================
    const METRIC_RULES = {
        planejadoVsRealizado: {
            category: 'processos', max: 150, direction: 'higher', scale: 'ratio', origem: 'jira',
            naoAtendeu:  { lt: 0.75 },
            parcial:     { from: 0.75, to: 1.00, floor: 50,  top: 120 },
            totalmente:  { eq: 1.00, points: 150 },
            superou:     { gt: 1.00, points: 165 }
        },
        cycleTimeDev: {
            category: 'processos', max: 125, direction: 'lower', scale: 'days', origem: 'jira',
            superou:     { lt: 3, points: 138 },
            totalmente:  { from: 3, to: 4, floor: 125, top: 100 },
            parcial:     { from: 4, to: 6, floor: 50,  top: 10 },
            naoAtendeu:  { gte: 6 }
        },
        cycleTimeHomolog: {
            category: 'processos', max: 50, direction: 'lower', scale: 'days', origem: 'jira',
            superou:     { lt: 1, points: 55 },
            totalmente:  { from: 1, to: 2, floor: 50, top: 30 },
            parcial:     { from: 2, to: 3, floor: 10, top: 5 },
            naoAtendeu:  { gte: 3 }
        },
        cycleTimeImpl: {                                 // corrige D5
            category: 'processos', max: 50, direction: 'lower', scale: 'days', origem: 'jira',
            superou:     { lt: 2, points: 55 },
            totalmente:  { from: 2, to: 3, floor: 50, top: 30 },
            parcial:     { from: 3, to: 5, floor: 10, top: 5 },
            naoAtendeu:  { gte: 5 }
        },
        freqDeploy: {
            category: 'processos', max: 75, direction: 'category', scale: 'category', origem: 'manual', integracaoFutura: 'git',
            naoAtendeu:  { categoryIn: [0, 1] },
            parcial:     { categoryEq: 2, points: 30 },
            totalmente:  { categoryEq: 3, points: 75 },
            superou:     { categoryGte: 4, points: 80 }
        },
        disponibilidade: {
            category: 'praticas', max: 175, direction: 'higher', scale: 'ratio', origem: 'manual', integracaoFutura: 'monitoracao',
            naoAtendeu:  { lt: 0.97 },
            parcial:     { from: 0.97, to: 0.99, floor: 10,  top: 50 },
            totalmente:  { from: 0.99, to: 0.995, floor: 100, top: 175 },
            superou:     { gte: 0.995, points: 193 }
        },
        vulnerabilidade: {
            category: 'praticas', max: 175, direction: 'higher', scale: 'ratio', origem: 'manual', integracaoFutura: 'security-scan',
            naoAtendeu:  { lt: 0.75 },
            parcial:     { from: 0.75, to: 1.00, floor: 50, top: 120 },
            totalmente:  { eq: 1.00, points: 175 },
            superou:     { gt: 1.00, points: 193 }
        },
        slaAlta: {
            category: 'praticas', max: 50, direction: 'higher', scale: 'ratio', noSuperou: true, origem: 'jira',
            naoAtendeu:  { lt: 0.90 },
            parcial:     { from: 0.90, to: 1.00, floor: 5, top: 40 },
            totalmente:  { eq: 1.00, points: 50 }
        },
        slaMedia: {
            category: 'praticas', max: 30, direction: 'higher', scale: 'ratio', noSuperou: true, origem: 'jira',
            naoAtendeu:  { lt: 0.90 },
            parcial:     { from: 0.90, to: 1.00, floor: 3, top: 25 },
            totalmente:  { eq: 1.00, points: 30 }
        },
        slaBaixa: {
            category: 'praticas', max: 20, direction: 'higher', scale: 'ratio', noSuperou: true, origem: 'jira',
            naoAtendeu:  { lt: 0.90 },
            parcial:     { from: 0.90, to: 1.00, floor: 2, top: 15 },
            totalmente:  { eq: 1.00, points: 20 }
        },
        okrs: {                                          // corrige D3 (categoria Produto)
            category: 'produto', max: 50, direction: 'higher', scale: 'ratio', origem: 'jira', origemDetalhe: 'must-win-battles',
            naoAtendeu:  { lt: 0.60 },
            parcial:     { from: 0.60, to: 0.80, floor: 10, top: 25 },
            totalmente:  { from: 0.80, to: 1.00, floor: 40, top: 50 },
            superou:     { eq: 1.00, points: 55 }
        },
        teamHealth: {                                    // corrige D6 (escala 0-10)
            category: 'pessoas', max: 50, direction: 'higher', scale: 'thc0a10', origem: 'manual', integracaoFutura: 'pesquisa-thc',
            naoAtendeu:  { lt: 7 },
            parcial:     { from: 7, to: 8, floor: 10, top: 20, inclusiveTop: true },
            totalmente:  { from: 8, to: 9.5, floor: 40, top: 50, exclusiveFrom: true, inclusiveTop: true },
            superou:     { gt: 9.5, points: 55 }
        }
    };

    // =========================================================================
    // GENERIC METRIC EVALUATOR (Requirement 1.4, 1.5, 1.6, 1.8, 1.10)
    // Percorre as faixas de uma regra de `METRIC_RULES`, tratando as direções
    // 'higher' (maior melhor), 'lower' (menor melhor, Cycle Time) e 'category'
    // (Frequência de Deploy), além das escalas 'ratio', 'days', 'thc0a10' e
    // 'category'. A faixa parcial usa `interpolate` proporcional (floor→top).
    // Valor null/undefined/NaN → band 'sem-dados' (distinto de 'nao-atendeu').
    // Retorna: { score, band, label, max, valueFormatted }.
    // =========================================================================
    const BAND_LABELS = {
        'sem-dados':            'Sem Dados',
        'nao-atendeu':          'Não Atendeu',
        'atendeu-parcialmente': 'Atendeu Parcialmente',
        'atendeu-totalmente':   'Atendeu Totalmente',
        'superou':              'Superou'
    };

    const round2 = (n) => Math.round(n * 100) / 100;

    function formatMetricValue(rule, value) {
        switch (rule.scale) {
            case 'ratio':    return `${round2(value * 100)}%`;
            case 'days':     return `${value} dias`;
            case 'thc0a10':  return `${value}`;
            case 'category': return `Categoria ${value}`;
            default:         return `${value}`;
        }
    }

    /**
     * Avaliador genérico de uma métrica.
     * @param {string} metricId - chave em METRIC_RULES
     * @param {number|null} value - valor da métrica na escala da regra
     * @returns {{score:number, band:string, label:string, max:number, valueFormatted:string}}
     */
    function evaluateMetric(metricId, value) {
        const rule = METRIC_RULES[metricId];
        if (!rule) {
            throw new Error(`evaluateMetric: métrica desconhecida "${metricId}"`);
        }

        // Sem Dados (Requirement 1.10) — distinto de "Não Atendeu".
        if (value === null || value === undefined || (typeof value === 'number' && Number.isNaN(value))) {
            return { score: 0, band: 'sem-dados', label: BAND_LABELS['sem-dados'], max: rule.max, valueFormatted: 'Sem Dados' };
        }

        const valueFormatted = formatMetricValue(rule, value);
        const make = (score, band) => ({ score: round2(score), band, label: BAND_LABELS[band], max: rule.max, valueFormatted });

        // ---- Direção categórica (Frequência de Deploy) ----
        if (rule.direction === 'category') {
            const n = Math.trunc(value);
            if (rule.naoAtendeu && Array.isArray(rule.naoAtendeu.categoryIn) && rule.naoAtendeu.categoryIn.includes(n)) {
                return make(0, 'nao-atendeu');
            }
            if (rule.parcial && n === rule.parcial.categoryEq)    return make(rule.parcial.points, 'atendeu-parcialmente');
            if (rule.totalmente && n === rule.totalmente.categoryEq) return make(rule.totalmente.points, 'atendeu-totalmente');
            if (rule.superou && n >= rule.superou.categoryGte)    return make(rule.superou.points, 'superou');
            // Fallback defensivo: categoria não mapeada → Não Atendeu.
            return make(0, 'nao-atendeu');
        }

        // ---- Escala THC (0-10, limites inclusivos) — tratada antes de 'higher'
        // porque teamHealth é direction:'higher' mas usa bordas inclusivas
        // (inclusiveTop/exclusiveFrom) que o ramo genérico não respeita. ----
        if (rule.scale === 'thc0a10') {
            if (rule.naoAtendeu && value < rule.naoAtendeu.lt) {
                return make(0, 'nao-atendeu');
            }
            const p = rule.parcial;      // 7..8 (inclusiveTop)
            const t = rule.totalmente;   // >8..9,5 (exclusiveFrom, inclusiveTop)
            const s = rule.superou;      // >9,5

            if (p && value >= p.from && value <= p.to) {
                return make(interpolate(value, p.from, p.to, p.floor, p.top), 'atendeu-parcialmente');
            }
            if (t && value > t.from && value <= t.to) {
                return make(interpolate(value, t.from, t.to, t.floor, t.top), 'atendeu-totalmente');
            }
            if (s && value > s.gt) {
                return make(s.points, 'superou');
            }
            return make(0, 'nao-atendeu');
        }

        // ---- Direção 'higher' (maior melhor) ----
        if (rule.direction === 'higher') {
            if (rule.naoAtendeu && value < rule.naoAtendeu.lt) {
                return make(0, 'nao-atendeu');
            }
            // Avalia parcial e totalmente conforme suas faixas; a ordem respeita
            // os pontos de corte da tabela de regras.
            const p = rule.parcial;
            const t = rule.totalmente;

            // Parcial: [from, to) interpolando floor→top.
            if (p && value >= p.from && value < p.to) {
                return make(interpolate(value, p.from, p.to, p.floor, p.top), 'atendeu-parcialmente');
            }
            // Totalmente: pode ser ponto exato (eq) ou faixa interpolada [from,to).
            if (t) {
                if (t.eq !== undefined && value === t.eq) {
                    return make(t.points, 'atendeu-totalmente');
                }
                if (t.from !== undefined && value >= t.from && value < t.to) {
                    return make(interpolate(value, t.from, t.to, t.floor, t.top), 'atendeu-totalmente');
                }
            }
            // Superou (ausente em SLA — noSuperou).
            const s = rule.superou;
            if (!rule.noSuperou && s) {
                if (s.eq !== undefined && value === s.eq)  return make(s.points, 'superou');
                if (s.gt !== undefined && value > s.gt)    return make(s.points, 'superou');
                if (s.gte !== undefined && value >= s.gte) return make(s.points, 'superou');
            }
            // Caso o valor atinja/ultrapasse o topo das faixas sem superou (SLA
            // = 100% exato) ou fique no limite superior: trata atendeu totalmente.
            if (t && t.eq !== undefined && value >= t.eq)   return make(t.points, 'atendeu-totalmente');
            if (t && t.to !== undefined && value >= t.to)   return make(t.top, 'atendeu-totalmente');
            // Fallback: topo da parcial.
            if (p && value >= p.to) return make(p.top, 'atendeu-parcialmente');
            return make(0, 'nao-atendeu');
        }

        // ---- Direção 'lower' (menor melhor — Cycle Time) ----
        if (rule.direction === 'lower') {
            const s = rule.superou;
            const t = rule.totalmente;
            const p = rule.parcial;

            if (s && value < s.lt) {
                return make(s.points, 'superou');
            }
            // Totalmente: faixa [from, to) com floor→top (ex.: 3→125, 4→100).
            if (t && value >= t.from && value < t.to) {
                return make(interpolate(value, t.from, t.to, t.floor, t.top), 'atendeu-totalmente');
            }
            // Parcial: faixa [from, to).
            if (p && value >= p.from && value < p.to) {
                return make(interpolate(value, p.from, p.to, p.floor, p.top), 'atendeu-parcialmente');
            }
            // Não Atendeu: >= limite inferior da pior faixa.
            if (rule.naoAtendeu && value >= rule.naoAtendeu.gte) {
                return make(0, 'nao-atendeu');
            }
            return make(0, 'nao-atendeu');
        }

        return make(0, 'nao-atendeu');
    }

    // =========================================================================
    // MAIN CALCULATION
    // =========================================================================

    /**
     * Calcula o score de um time a partir das 12 métricas, usando o avaliador
     * genérico (`evaluateMetric`) sobre `METRIC_RULES`. Consolida as 4
     * categorias (Processos/Práticas/Produto/Pessoas) e classifica a Faixa de
     * Performance. (Requirement 1.7, 1.9)
     *
     * Mantém compatibilidade com o legado: além de `categoryScores` (4 Ps),
     * expõe `dimensionScores` ({pessoas, processos, praticas}) consumido pelo
     * dashboard atual (exportação CSV e KPIs). `baseScore` é a soma limitada à
     * base de 1000 (sem o bônus de métricas superadas).
     *
     * @param {object} teamMetrics - Objeto com valores das 12 métricas
     * @returns {object} Breakdown completo do score
     */
    function calculateTeamScore(teamMetrics) {
        const results = {};
        let totalScore = 0;
        const categoryScores = { processos: 0, praticas: 0, produto: 0, pessoas: 0 };

        for (const [metricId, rule] of Object.entries(METRIC_RULES)) {
            const value = (teamMetrics && metricId in teamMetrics) ? teamMetrics[metricId] : null;
            const evalResult = evaluateMetric(metricId, value);
            const metricDef = METRICS[metricId];
            results[metricId] = {
                score: evalResult.score,
                band: evalResult.band,
                label: evalResult.label,
                value: value,
                maxScore: rule.max,
                valueFormatted: evalResult.valueFormatted,
                percentage: rule.max > 0 ? (evalResult.score / rule.max * 100) : 0,
                name: metricDef ? metricDef.name : metricId,
                category: rule.category
            };
            totalScore += evalResult.score;
            categoryScores[rule.category] += evalResult.score;
        }

        totalScore = round2(totalScore);
        // Base sem bônus: não ultrapassa 1000 pontos (Requirement 1.7).
        const baseScore = Math.min(totalScore, BASE_MAX_SCORE);

        // Compatibilidade com o dashboard legado (3 dimensões). Produto é
        // consolidado em "processos" no agregado legado, pois OKRs residia ali
        // antes da reconciliação; o detalhe correto fica em `categoryScores`.
        const dimensionScores = {
            pessoas: round2(categoryScores.pessoas),
            processos: round2(categoryScores.processos + categoryScores.produto),
            praticas: round2(categoryScores.praticas)
        };

        const performanceBand = getPerformanceBand(totalScore);

        return {
            totalScore,
            baseScore: round2(baseScore),
            maxScore: MAX_TOTAL_SCORE,
            percentage: (totalScore / MAX_TOTAL_SCORE * 100).toFixed(1),
            performanceBand,
            categoryScores: {
                processos: round2(categoryScores.processos),
                praticas: round2(categoryScores.praticas),
                produto: round2(categoryScores.produto),
                pessoas: round2(categoryScores.pessoas)
            },
            dimensionScores,
            metrics: results
        };
    }

    /**
     * Classifica um score na Faixa de Performance (Requirement 1.9):
     * Baixa 0–500, Média 501–839, Boa 840–1000, Ótima acima de 1000 (teto 1089).
     * Retorna exatamente uma faixa; valores abaixo de 0 caem em Baixa e valores
     * acima do teto permanecem em Ótima.
     */
    function getPerformanceBand(score) {
        for (const band of PERFORMANCE_BANDS) {
            if (score >= band.min && score <= band.max) {
                return band;
            }
        }
        // Acima do teto definido na última faixa → mantém a faixa superior.
        const last = PERFORMANCE_BANDS[PERFORMANCE_BANDS.length - 1];
        if (score > last.max) return last;
        // Abaixo do mínimo da primeira faixa → mantém a faixa inferior.
        return PERFORMANCE_BANDS[0];
    }

    /**
     * Calculate scores for all teams
     * @param {Array} teams - Array of team objects from data.js
     * @returns {Array} Teams with calculated scores, sorted by total score desc
     */
    function calculateAllTeams(teams) {
        return teams.map(team => {
            const scoreResult = calculateTeamScore(team.metrics);
            return {
                ...team,
                scoreResult
            };
        }).sort((a, b) => b.scoreResult.totalScore - a.scoreResult.totalScore);
    }

    /**
     * Calculate average scores per journey
     */
    function calculateJourneyScores(scoredTeams) {
        const journeys = {};

        scoredTeams.forEach(team => {
            if (!journeys[team.jornada]) {
                journeys[team.jornada] = {
                    name: team.jornada,
                    teams: [],
                    totalScore: 0,
                    dimensionScores: { pessoas: 0, processos: 0, praticas: 0 },
                    categoryScores: { processos: 0, praticas: 0, produto: 0, pessoas: 0 }
                };
            }
            const j = journeys[team.jornada];
            j.teams.push(team);
            j.totalScore += team.scoreResult.totalScore;
            j.dimensionScores.pessoas += team.scoreResult.dimensionScores.pessoas;
            j.dimensionScores.processos += team.scoreResult.dimensionScores.processos;
            j.dimensionScores.praticas += team.scoreResult.dimensionScores.praticas;
            const cs = team.scoreResult.categoryScores;
            if (cs) {
                j.categoryScores.processos += cs.processos;
                j.categoryScores.praticas += cs.praticas;
                j.categoryScores.produto += cs.produto;
                j.categoryScores.pessoas += cs.pessoas;
            }
        });

        return Object.values(journeys).map(j => ({
            ...j,
            avgScore: j.totalScore / j.teams.length,
            avgDimensionScores: {
                pessoas: j.dimensionScores.pessoas / j.teams.length,
                processos: j.dimensionScores.processos / j.teams.length,
                praticas: j.dimensionScores.praticas / j.teams.length
            },
            avgCategoryScores: {
                processos: j.categoryScores.processos / j.teams.length,
                praticas: j.categoryScores.praticas / j.teams.length,
                produto: j.categoryScores.produto / j.teams.length,
                pessoas: j.categoryScores.pessoas / j.teams.length
            },
            performanceBand: getPerformanceBand(j.totalScore / j.teams.length)
        })).sort((a, b) => b.avgScore - a.avgScore);
    }

    // =========================================================================
    // EXCEÇÕES — recálculo do Score com tratamento por métrica
    // (Requisito: ao excetuar uma métrica de um time, ou REDISTRIBUIR o peso
    // dela entre as demais, ou SUBSTITUIR por outra métrica que assume o peso.)
    //
    // `exceptions`: lista de { indicador, tratamento, metricaSubstituta, valorSubstituto }
    //   - indicador: id ('teamHealth') OU nome ('Team Health Check') da métrica.
    //   - tratamento: 'redistribuir' (default) | 'substituir'.
    //   - metricaSubstituta: id/rótulo da métrica que assume o peso (substituir).
    //   - valorSubstituto (opcional 0..1): desempenho da substituta nesse período;
    //     ausente → assume 1.0 (atendeu totalmente) como neutro.
    //
    // Modelo de cálculo (mantém a base de 1000 e a mesma faixa de performance):
    //   - REDISTRIBUIR: a métrica sai; seu peso-base é diluído proporcionalmente
    //     ao peso das demais, preservando a razão (score/peso) de cada uma. Na
    //     prática, re-escalamos a soma dos scores das demais pelo fator
    //     base/(base − pesoExcetuada).
    //   - SUBSTITUIR: o score da métrica excetuada é trocado pela contribuição da
    //     substituta = valorSubstituto × pesoDaExcetuada (a substituta herda o
    //     peso). Demais métricas inalteradas.
    // Retorna o mesmo shape de calculateTeamScore, com `exceptionsApplied`.
    // =========================================================================
    function resolveMetricId(indicador) {
        if (!indicador) return null;
        if (METRIC_RULES[indicador]) return indicador;          // já é id
        // tenta por nome (METRICS[id].name)
        const byName = Object.keys(METRICS).find((id) => METRICS[id].name === indicador);
        return byName || null;
    }

    function calculateTeamScoreWithExceptions(teamMetrics, exceptions) {
        const base = calculateTeamScore(teamMetrics);
        const list = Array.isArray(exceptions) ? exceptions : [];
        if (!list.length) {
            return Object.assign({}, base, { exceptionsApplied: [] });
        }

        const applied = [];
        // Partimos da soma total e dos scores por métrica do cálculo base.
        let total = base.totalScore;
        const metricScores = {};
        Object.keys(base.metrics).forEach((id) => { metricScores[id] = base.metrics[id].score; });

        list.forEach((exc) => {
            const metricId = resolveMetricId(exc && exc.indicador);
            if (!metricId || !METRIC_RULES[metricId]) return;
            const rule = METRIC_RULES[metricId];
            const peso = rule.max;
            const scoreAtual = metricScores[metricId] || 0;
            const tratamento = (exc.tratamento === 'substituir') ? 'substituir' : 'redistribuir';

            if (tratamento === 'redistribuir') {
                // Remove a contribuição da métrica e re-escala as demais para
                // preservar a base de 1000 (o peso excetuado é diluído nas outras).
                const somaOutras = total - scoreAtual;
                const baseRestante = BASE_MAX_SCORE - peso;
                const fator = baseRestante > 0 ? (BASE_MAX_SCORE / baseRestante) : 1;
                total = somaOutras * fator;
                metricScores[metricId] = 0;
                applied.push({ indicador: metricId, tratamento, pesoRedistribuido: peso, fator: round2(fator) });
            } else {
                // Substitui: a substituta herda o peso; contribuição = valor × peso.
                const valor = (exc.valorSubstituto == null) ? 1 : Number(exc.valorSubstituto);
                const v = Number.isFinite(valor) ? Math.max(0, Math.min(1, valor)) : 1;
                const novaContrib = v * peso;
                total = total - scoreAtual + novaContrib;
                metricScores[metricId] = novaContrib;
                applied.push({
                    indicador: metricId, tratamento,
                    metricaSubstituta: (exc.metricaSubstituta || null),
                    valorSubstituto: v, contribuicao: round2(novaContrib)
                });
            }
        });

        total = round2(total);
        const performanceBand = getPerformanceBand(total);
        return Object.assign({}, base, {
            totalScore: total,
            baseScore: round2(Math.min(total, BASE_MAX_SCORE)),
            percentage: (total / MAX_TOTAL_SCORE * 100).toFixed(1),
            performanceBand,
            exceptionsApplied: applied
        });
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        METRICS,
        DIMENSIONS,
        CATEGORIES,
        METRIC_RULES,
        PERFORMANCE_BANDS,
        MAX_TOTAL_SCORE,
        BASE_MAX_SCORE,
        calculateTeamScore,
        calculateTeamScoreWithExceptions,
        resolveMetricId,
        calculateAllTeams,
        calculateJourneyScores,
        getPerformanceBand,
        evaluateMetric,
        // Expose individual calc functions for testing
        calcPlanejadoVsRealizado,
        calcCycleTimeDev,
        calcCycleTimeHomolog,
        calcCycleTimeImpl,
        calcDisponibilidade,
        calcVulnerabilidade,
        calcSLAAlta,
        calcSLAMedia,
        calcSLABaixa,
        calcOKRs,
        calcTeamHealth,
        calcFreqDeploy,
        interpolate
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ScoringEngine;
}

// Exposição global para o browser: como o módulo é declarado com `const`
// (que NÃO vira propriedade de `window`), registramos explicitamente em
// `globalThis` para que as views (view-geral, view-metricas, etc.) encontrem
// `ScoringEngine`. Sem isto, as telas exibem "Módulos de cálculo indisponíveis".
if (typeof globalThis !== 'undefined') {
    globalThis.ScoringEngine = ScoringEngine;
}
