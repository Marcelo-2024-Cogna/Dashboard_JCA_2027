// Feature: score-performance-executivo
// Testes de propriedade do Motor de Cálculo (Task 2.4).
// Validam a reconciliação do `scoring-engine.js` com a tabela de regras
// oficial (Requirement 1) usando um ORÁCULO INDEPENDENTE, reimplementado
// diretamente a partir da tabela do requirements.md (seção Requirement 1.4),
// sem reutilizar METRIC_RULES/interpolate da própria implementação.
//
// Properties cobertas: 1 (equivalência com oráculo), 2 (interpolação na faixa),
// 3 (monotonicidade por direção), 4 (score total/limites), 5 (SLA sem Superou),
// 6 (faixas de performance exclusivas).
//
// _Requirements: 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.11, 1.12_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const ScoringEngine = require('../js/scoring-engine.js');

const NUM_RUNS = 100;
const TOL = 0.01; // tolerância de duas casas decimais (Requirement 1.12)

// =========================================================================
// ORÁCULO INDEPENDENTE — reimplementação mínima da tabela de regras do
// requirements.md (Requirement 1.4). NÃO depende de METRIC_RULES nem de
// ScoringEngine.interpolate. É a "fonte da verdade" para a Property 1.
//
// Fórmula de interpolação proporcional (Glossary / Requirement 1.5):
//   pontos = piso + (valor − início_faixa) ÷ (fim_faixa − início_faixa) × (topo − piso)
// =========================================================================
function lerp(value, from, to, floor, top) {
    const ratio = (value - from) / (to - from);
    return floor + ratio * (top - floor);
}

// Cada oráculo retorna { score, band } onde band ∈
// {sem-dados, nao-atendeu, atendeu-parcialmente, atendeu-totalmente, superou}
const oracle = {
    // Planejado vs Realizado | Processos | 150
    // <75% nao | ≥75% e <100% → 50..120 | =100% → 150 | >100% → 165
    planejadoVsRealizado(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.75) return { score: 0, band: 'nao-atendeu' };
        if (v < 1.00) return { score: lerp(v, 0.75, 1.00, 50, 120), band: 'atendeu-parcialmente' };
        if (v === 1.00) return { score: 150, band: 'atendeu-totalmente' };
        return { score: 165, band: 'superou' };
    },
    // Cycle Time Dev | Processos | 125 | menor melhor
    // ≥6 nao | ≥4 e <6 → 50..10 | ≥3 e <4 → 125..100 | <3 → 138
    cycleTimeDev(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 3) return { score: 138, band: 'superou' };
        if (v < 4) return { score: lerp(v, 3, 4, 125, 100), band: 'atendeu-totalmente' };
        if (v < 6) return { score: lerp(v, 4, 6, 50, 10), band: 'atendeu-parcialmente' };
        return { score: 0, band: 'nao-atendeu' };
    },
    // Cycle Time Homolog | 50 | menor melhor
    // ≥3 nao | ≥2 e <3 → 10..5 | ≥1 e <2 → 50..30 | <1 → 55
    cycleTimeHomolog(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 1) return { score: 55, band: 'superou' };
        if (v < 2) return { score: lerp(v, 1, 2, 50, 30), band: 'atendeu-totalmente' };
        if (v < 3) return { score: lerp(v, 2, 3, 10, 5), band: 'atendeu-parcialmente' };
        return { score: 0, band: 'nao-atendeu' };
    },
    // Cycle Time Impl | 50 | menor melhor
    // ≥5 nao | ≥3 e <5 → 10..5 | ≥2 e <3 → 50..30 | <2 → 55
    cycleTimeImpl(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 2) return { score: 55, band: 'superou' };
        if (v < 3) return { score: lerp(v, 2, 3, 50, 30), band: 'atendeu-totalmente' };
        if (v < 5) return { score: lerp(v, 3, 5, 10, 5), band: 'atendeu-parcialmente' };
        return { score: 0, band: 'nao-atendeu' };
    },
    // Frequência de Deploy | 75 | categoria
    // 0/1 nao | 2 → 30 | 3 → 75 | 4+ → 80
    freqDeploy(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        const n = Math.trunc(v);
        if (n <= 1) return { score: 0, band: 'nao-atendeu' };
        if (n === 2) return { score: 30, band: 'atendeu-parcialmente' };
        if (n === 3) return { score: 75, band: 'atendeu-totalmente' };
        return { score: 80, band: 'superou' };
    },
    // Disponibilidade | Práticas | 175
    // <97% nao | ≥97% e <99% → 10..50 | ≥99% e <99.5% → 100..175 | ≥99.5% → 193
    disponibilidade(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.97) return { score: 0, band: 'nao-atendeu' };
        if (v < 0.99) return { score: lerp(v, 0.97, 0.99, 10, 50), band: 'atendeu-parcialmente' };
        if (v < 0.995) return { score: lerp(v, 0.99, 0.995, 100, 175), band: 'atendeu-totalmente' };
        return { score: 193, band: 'superou' };
    },
    // Índice de Vulnerabilidade | 175
    // <75% nao | ≥75% e <100% → 50..120 | =100% → 175 | >100% → 193
    vulnerabilidade(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.75) return { score: 0, band: 'nao-atendeu' };
        if (v < 1.00) return { score: lerp(v, 0.75, 1.00, 50, 120), band: 'atendeu-parcialmente' };
        if (v === 1.00) return { score: 175, band: 'atendeu-totalmente' };
        return { score: 193, band: 'superou' };
    },
    // SLA Alta | 50 | sem Superou
    // <90% nao | ≥90% e <100% → 5..40 | =100% → 50
    slaAlta(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.90) return { score: 0, band: 'nao-atendeu' };
        if (v < 1.00) return { score: lerp(v, 0.90, 1.00, 5, 40), band: 'atendeu-parcialmente' };
        return { score: 50, band: 'atendeu-totalmente' };
    },
    // SLA Média | 30 | sem Superou
    slaMedia(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.90) return { score: 0, band: 'nao-atendeu' };
        if (v < 1.00) return { score: lerp(v, 0.90, 1.00, 3, 25), band: 'atendeu-parcialmente' };
        return { score: 30, band: 'atendeu-totalmente' };
    },
    // SLA Baixa | 20 | sem Superou
    slaBaixa(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.90) return { score: 0, band: 'nao-atendeu' };
        if (v < 1.00) return { score: lerp(v, 0.90, 1.00, 2, 15), band: 'atendeu-parcialmente' };
        return { score: 20, band: 'atendeu-totalmente' };
    },
    // Alinhamento com OKRs | Produto | 50
    // <60% nao | ≥60% e <80% → 10..25 | ≥80% e <100% → 40..50 | =100% → 55
    okrs(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 0.60) return { score: 0, band: 'nao-atendeu' };
        if (v < 0.80) return { score: lerp(v, 0.60, 0.80, 10, 25), band: 'atendeu-parcialmente' };
        if (v < 1.00) return { score: lerp(v, 0.80, 1.00, 40, 50), band: 'atendeu-totalmente' };
        return { score: 55, band: 'superou' };
    },
    // Team Health Check | Pessoas | 50 | escala 0-10
    // <7 nao | ≥7 e ≤8 → 10..20 | >8 e ≤9.5 → 40..50 | >9.5 → 55
    teamHealth(v) {
        if (v == null || Number.isNaN(v)) return { score: 0, band: 'sem-dados' };
        if (v < 7) return { score: 0, band: 'nao-atendeu' };
        if (v <= 8) return { score: lerp(v, 7, 8, 10, 20), band: 'atendeu-parcialmente' };
        if (v <= 9.5) return { score: lerp(v, 8, 9.5, 40, 50), band: 'atendeu-totalmente' };
        return { score: 55, band: 'superou' };
    }
};

// =========================================================================
// GERADORES (fast-check) — cobrem o domínio de cada métrica E os limites de
// faixa explícitos (0.75, 0.90, 0.97, 0.99, 0.995, 1.00; 2,3,4,5,6 dias;
// 7,8,9.5 THC) além do caso `null` (Sem Dados — Requirement 1.10).
// =========================================================================
// Limites de faixa por escala. Para 'ratio' há dois subdomínios: as métricas
// com banda "Superou" acima de 100% (Planejado vs Realizado, Vulnerabilidade)
// aceitam valores > 1.00; as demais métricas percentuais (Disponibilidade,
// SLA e OKRs) têm domínio definido na tabela de regras apenas até 100%
// (o requirements.md NÃO define comportamento acima de 100% para elas).
const EDGE = {
    ratioOver100: [0, 0.5, 0.6, 0.75, 0.80, 0.90, 0.97, 0.99, 0.995, 0.999, 1.00, 1.05, 1.2],
    ratioCapped:  [0, 0.5, 0.6, 0.75, 0.80, 0.90, 0.97, 0.99, 0.995, 0.999, 1.00],
    days:  [0, 1, 1.5, 2, 2.5, 3, 3.5, 4, 5, 5.5, 6, 7, 8, 12],
    thc:   [0, 5, 6.9, 7, 7.5, 8, 8.5, 9, 9.5, 9.6, 10],
    category: [0, 1, 2, 3, 4, 5]
};

function genForScale(scale) {
    const edges = EDGE[scale];
    const base = (() => {
        switch (scale) {
            case 'ratioOver100': return fc.double({ min: 0, max: 1.2, noNaN: true });
            case 'ratioCapped':  return fc.double({ min: 0, max: 1.0, noNaN: true });
            case 'days':         return fc.double({ min: 0, max: 12, noNaN: true });
            case 'thc':          return fc.double({ min: 0, max: 10, noNaN: true });
            case 'category':     return fc.integer({ min: 0, max: 6 });
            default:             return fc.double({ min: 0, max: 1, noNaN: true });
        }
    })();
    // Mistura: valores aleatórios do domínio + limites de faixa + null.
    return fc.oneof(
        base,
        fc.constantFrom(...edges),
        fc.constant(null)
    );
}

// Mapa metricId -> escala/domínio de geração (ver nota acima sobre ratio).
const METRIC_SCALE = {
    planejadoVsRealizado: 'ratioOver100',  // banda Superou acima de 100%
    cycleTimeDev: 'days',
    cycleTimeHomolog: 'days',
    cycleTimeImpl: 'days',
    freqDeploy: 'category',
    disponibilidade: 'ratioCapped',
    vulnerabilidade: 'ratioOver100',       // banda Superou acima de 100%
    slaAlta: 'ratioCapped',
    slaMedia: 'ratioCapped',
    slaBaixa: 'ratioCapped',
    okrs: 'ratioCapped',                    // tabela define Superou só em =100%
    teamHealth: 'thc'
};

const ALL_METRIC_IDS = Object.keys(METRIC_SCALE);
const SLA_METRIC_IDS = ['slaAlta', 'slaMedia', 'slaBaixa'];

// Gerador de um conjunto completo das 12 métricas
function genTeamMetrics() {
    const shape = {};
    for (const id of ALL_METRIC_IDS) shape[id] = genForScale(METRIC_SCALE[id]);
    return fc.record(shape);
}

// =========================================================================
// Property 1: Equivalência do motor com a planilha de Score (oráculo)
// =========================================================================
describe('Property 1: Equivalência do motor com o oráculo da planilha', () => {
    // Feature: score-performance-executivo, Property 1: Para qualquer conjunto de valores de entrada válidos das 12 métricas, a pontuação por métrica e o score total produzidos pelo Motor_de_Cálculo devem ser iguais aos da planilha de Score de Performance (oráculo de referência), dentro de uma tolerância de 0,01 (duas casas decimais).
    it('pontuação por métrica == oráculo (±0,01) para cada métrica', () => {
        for (const metricId of ALL_METRIC_IDS) {
            fc.assert(
                fc.property(genForScale(METRIC_SCALE[metricId]), (value) => {
                    const got = ScoringEngine.evaluateMetric(metricId, value);
                    const exp = oracle[metricId](value);
                    expect(got.band).toBe(exp.band);
                    expect(Math.abs(got.score - exp.score)).toBeLessThanOrEqual(TOL);
                }),
                { numRuns: NUM_RUNS }
            );
        }
    });

    // Feature: score-performance-executivo, Property 1: Para qualquer conjunto de valores de entrada válidos das 12 métricas, a pontuação por métrica e o score total produzidos pelo Motor_de_Cálculo devem ser iguais aos da planilha de Score de Performance (oráculo de referência), dentro de uma tolerância de 0,01 (duas casas decimais).
    it('score total == soma do oráculo das 12 métricas (±0,01)', () => {
        fc.assert(
            fc.property(genTeamMetrics(), (metrics) => {
                const result = ScoringEngine.calculateTeamScore(metrics);
                let oracleTotal = 0;
                for (const id of ALL_METRIC_IDS) oracleTotal += oracle[id](metrics[id]).score;
                expect(Math.abs(result.totalScore - oracleTotal)).toBeLessThanOrEqual(TOL);
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 2: Interpolação proporcional nunca sai da faixa
// =========================================================================
describe('Property 2: Interpolação parcial nunca sai da faixa [piso, topo]', () => {
    // Feature: score-performance-executivo, Property 2: Para qualquer métrica e qualquer valor que caia na faixa "Atendeu parcialmente", a pontuação resultante deve ser maior ou igual ao piso e menor ou igual ao topo definidos para a faixa parcial daquela métrica (independentemente de a direção ser maior-melhor ou menor-melhor).
    it('toda pontuação parcial fica entre min(piso,topo) e max(piso,topo)', () => {
        for (const metricId of ALL_METRIC_IDS) {
            const rule = ScoringEngine.METRIC_RULES[metricId];
            const p = rule.parcial;
            if (!p || p.floor === undefined || p.top === undefined) continue; // freqDeploy parcial é pontual
            const lo = Math.min(p.floor, p.top);
            const hi = Math.max(p.floor, p.top);
            fc.assert(
                fc.property(genForScale(METRIC_SCALE[metricId]), (value) => {
                    const got = ScoringEngine.evaluateMetric(metricId, value);
                    if (got.band !== 'atendeu-parcialmente') return true; // só interessa a faixa parcial
                    expect(got.score).toBeGreaterThanOrEqual(lo - TOL);
                    expect(got.score).toBeLessThanOrEqual(hi + TOL);
                    return true;
                }),
                { numRuns: NUM_RUNS }
            );
        }
    });
});

// =========================================================================
// Property 3: Monotonicidade por direção da métrica
// =========================================================================
describe('Property 3: Monotonicidade por direção', () => {
    // Feature: score-performance-executivo, Property 3: Para qualquer par de valores da mesma métrica, se a métrica é "maior melhor", um valor maior nunca produz pontuação menor; se é "menor melhor" (Cycle Time), um valor menor nunca produz pontuação menor.
    it('higher: valor maior => pontuação ≥; lower: valor menor => pontuação ≥', () => {
        for (const metricId of ALL_METRIC_IDS) {
            const rule = ScoringEngine.METRIC_RULES[metricId];
            if (rule.direction === 'category') continue; // categórica não é contínua
            const scale = METRIC_SCALE[metricId];
            const pair = fc.tuple(genForScale(scale), genForScale(scale));
            fc.assert(
                fc.property(pair, ([a, b]) => {
                    if (a == null || b == null || Number.isNaN(a) || Number.isNaN(b)) return true;
                    const sa = ScoringEngine.evaluateMetric(metricId, a).score;
                    const sb = ScoringEngine.evaluateMetric(metricId, b).score;
                    if (rule.direction === 'higher') {
                        if (a >= b) expect(sa).toBeGreaterThanOrEqual(sb - TOL);
                        else expect(sb).toBeGreaterThanOrEqual(sa - TOL);
                    } else { // 'lower'
                        if (a <= b) expect(sa).toBeGreaterThanOrEqual(sb - TOL);
                        else expect(sb).toBeGreaterThanOrEqual(sa - TOL);
                    }
                    return true;
                }),
                { numRuns: NUM_RUNS }
            );
        }
    });
});

// =========================================================================
// Property 4: Score total dentro dos limites e consistente com a soma
// =========================================================================
describe('Property 4: Score total = soma, em [0,1089], ≤1000 sem Superou', () => {
    // Feature: score-performance-executivo, Property 4: Para quaisquer valores válidos das 12 métricas, o score total deve ser igual à soma das pontuações das 12 métricas, estar no intervalo [0, 1089], e, quando nenhuma métrica estiver na banda "Superou", não exceder 1000.
    it('total == soma das 12 métricas, dentro de [0,1089], e ≤1000 sem Superou', () => {
        fc.assert(
            fc.property(genTeamMetrics(), (metrics) => {
                const result = ScoringEngine.calculateTeamScore(metrics);
                let sum = 0;
                let anySuperou = false;
                for (const id of ALL_METRIC_IDS) {
                    const r = result.metrics[id];
                    sum += r.score;
                    if (r.band === 'superou') anySuperou = true;
                }
                expect(Math.abs(result.totalScore - sum)).toBeLessThanOrEqual(TOL);
                expect(result.totalScore).toBeGreaterThanOrEqual(0);
                expect(result.totalScore).toBeLessThanOrEqual(ScoringEngine.MAX_TOTAL_SCORE + TOL);
                if (!anySuperou) {
                    expect(result.totalScore).toBeLessThanOrEqual(ScoringEngine.BASE_MAX_SCORE + TOL);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 5: SLA sem banda Superou
// =========================================================================
describe('Property 5: SLA nunca atinge "Superou" e respeita o teto', () => {
    // Feature: score-performance-executivo, Property 5: Para qualquer valor de entrada das métricas de SLA (Alta, Média e Baixa Criticidade), a banda retornada nunca é "Superou" e a pontuação nunca excede o peso máximo da métrica (50/30/20).
    it('para slaAlta/slaMedia/slaBaixa: band != superou e score ≤ max', () => {
        for (const metricId of SLA_METRIC_IDS) {
            const max = ScoringEngine.METRIC_RULES[metricId].max;
            fc.assert(
                fc.property(genForScale('ratioCapped'), (value) => {
                    const got = ScoringEngine.evaluateMetric(metricId, value);
                    expect(got.band).not.toBe('superou');
                    expect(got.score).toBeLessThanOrEqual(max + TOL);
                    return true;
                }),
                { numRuns: NUM_RUNS }
            );
        }
    });
});

// =========================================================================
// Property 6: Classificação de Faixa de Performance exclusiva e correta
// =========================================================================
describe('Property 6: Faixa de Performance exclusiva e correta', () => {
    // Feature: score-performance-executivo, Property 6: Para qualquer score no intervalo [0, 1089], a classificação retorna exatamente uma Faixa de Performance, e a faixa corresponde aos limites: Baixa (0–500), Média (501–839), Boa (840–1000), Ótima (acima de 1000).
    it('getPerformanceBand retorna exatamente uma faixa com limites corretos', () => {
        fc.assert(
            fc.property(fc.double({ min: 0, max: 1089, noNaN: true }), (score) => {
                const band = ScoringEngine.getPerformanceBand(score);
                expect(band).toBeTruthy();

                // Exatamente uma faixa cobre este score dentro de PERFORMANCE_BANDS.
                const matches = ScoringEngine.PERFORMANCE_BANDS.filter(
                    (b) => score >= b.min && score <= b.max
                );
                expect(matches.length).toBe(1);
                expect(band.id).toBe(matches[0].id);

                // Verifica correspondência explícita com os limites do requisito.
                let expectedId;
                if (score <= 500) expectedId = 'baixa';
                else if (score <= 839) expectedId = 'media';
                else if (score <= 1000) expectedId = 'boa';
                else expectedId = 'otima';
                expect(band.id).toBe(expectedId);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});
