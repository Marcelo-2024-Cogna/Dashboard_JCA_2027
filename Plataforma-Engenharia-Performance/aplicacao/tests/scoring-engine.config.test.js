// Feature: score-performance-executivo
// Task 2.5 — Testes de exemplo da configuração do motor (unitários, não PBT).
// Verifica a reconciliação da configuração do motor com a tabela de regras
// oficial: soma de pesos por categoria (450/450/50/50), OKRs em Produto,
// teto 1089 / base 1000, e "Sem Dados" distinto de "Não Atendeu" por métrica.
// _Requirements: 1.1, 1.2, 1.3, 1.10_

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

// Módulo IIFE em CommonJS exposto via guarda de ambiente (module.exports).
const require = createRequire(import.meta.url);
const ScoringEngine = require('../js/scoring-engine.js');

const { METRIC_RULES, CATEGORIES, MAX_TOTAL_SCORE, BASE_MAX_SCORE, evaluateMetric } = ScoringEngine;

// Soma dos pesos máximos das métricas agrupados por categoria.
function sumMaxByCategory(rules) {
    const totals = {};
    for (const rule of Object.values(rules)) {
        totals[rule.category] = (totals[rule.category] || 0) + rule.max;
    }
    return totals;
}

describe('Configuração do motor — categorias e pesos (Requirements 1.1, 1.3)', () => {
    it('agrupa as 12 métricas em 4 categorias', () => {
        expect(Object.keys(METRIC_RULES)).toHaveLength(12);
        expect(Object.keys(CATEGORIES).sort()).toEqual(
            ['pessoas', 'praticas', 'processos', 'produto']
        );
    });

    it('soma dos pesos máximos por categoria = 450/450/50/50', () => {
        const totals = sumMaxByCategory(METRIC_RULES);
        expect(totals.processos).toBe(450);
        expect(totals.praticas).toBe(450);
        expect(totals.produto).toBe(50);
        expect(totals.pessoas).toBe(50);
    });

    it('o maxScore de cada CATEGORY bate com a soma dos pesos das suas métricas', () => {
        const totals = sumMaxByCategory(METRIC_RULES);
        for (const [id, cat] of Object.entries(CATEGORIES)) {
            expect(cat.maxScore).toBe(totals[id]);
        }
    });

    it('a soma de todos os pesos é a base de 1000 pontos', () => {
        const totals = sumMaxByCategory(METRIC_RULES);
        const sum = Object.values(totals).reduce((a, b) => a + b, 0);
        expect(sum).toBe(1000);
    });

    it('cada métrica tem exatamente os pesos máximos oficiais da Pílula', () => {
        const expectedMax = {
            planejadoVsRealizado: 150,
            cycleTimeDev: 125,
            cycleTimeHomolog: 50,
            cycleTimeImpl: 50,
            freqDeploy: 75,
            disponibilidade: 175,
            vulnerabilidade: 175,
            slaAlta: 50,
            slaMedia: 30,
            slaBaixa: 20,
            okrs: 50,
            teamHealth: 50
        };
        for (const [metricId, max] of Object.entries(expectedMax)) {
            expect(METRIC_RULES[metricId], `peso de ${metricId}`).toBeDefined();
            expect(METRIC_RULES[metricId].max, `peso de ${metricId}`).toBe(max);
        }
    });
});

describe('Configuração do motor — OKRs em Produto (Requirement 1.2)', () => {
    it('a métrica okrs pertence à categoria produto', () => {
        expect(METRIC_RULES.okrs.category).toBe('produto');
    });

    it('produto contém apenas a métrica okrs', () => {
        const produtoMetrics = Object.entries(METRIC_RULES)
            .filter(([, rule]) => rule.category === 'produto')
            .map(([id]) => id);
        expect(produtoMetrics).toEqual(['okrs']);
    });
});

describe('Configuração do motor — teto e base (Requirement 1.1/1.7)', () => {
    it('MAX_TOTAL_SCORE === 1089 e BASE_MAX_SCORE === 1000', () => {
        expect(MAX_TOTAL_SCORE).toBe(1089);
        expect(BASE_MAX_SCORE).toBe(1000);
    });
});

// Para cada métrica, um valor que cai abaixo do piso ("Não Atendeu"),
// cobrindo as três direções: 'higher' (abaixo do lt), 'lower' (>= gte) e
// 'category' (categoria 0/1).
const BELOW_FLOOR_VALUE = {
    planejadoVsRealizado: 0.50, // < 0.75
    cycleTimeDev: 7,            // >= 6 dias
    cycleTimeHomolog: 4,        // >= 3 dias
    cycleTimeImpl: 6,           // >= 5 dias
    freqDeploy: 1,              // categoria 0/1
    disponibilidade: 0.90,      // < 0.97
    vulnerabilidade: 0.50,      // < 0.75
    slaAlta: 0.80,              // < 0.90
    slaMedia: 0.80,             // < 0.90
    slaBaixa: 0.80,             // < 0.90
    okrs: 0.40,                 // < 0.60
    teamHealth: 5               // < 7 (escala 0-10)
};

describe('Sem Dados distinto de Não Atendeu por métrica (Requirement 1.10)', () => {
    const metricIds = Object.keys(METRIC_RULES);

    it('cobre as 12 métricas do motor', () => {
        expect(metricIds).toHaveLength(12);
        expect(Object.keys(BELOW_FLOOR_VALUE).sort()).toEqual([...metricIds].sort());
    });

    for (const metricId of metricIds) {
        it(`${metricId}: valor null retorna band 'sem-dados' com label 'Sem Dados'`, () => {
            const res = evaluateMetric(metricId, null);
            expect(res.band).toBe('sem-dados');
            expect(res.label).toBe('Sem Dados');
            expect(res.score).toBe(0);
        });

        it(`${metricId}: valor abaixo do piso retorna 'nao-atendeu' (distinto de 'sem-dados')`, () => {
            const res = evaluateMetric(metricId, BELOW_FLOOR_VALUE[metricId]);
            expect(res.band).toBe('nao-atendeu');
            expect(res.label).toBe('Não Atendeu');
            expect(res.score).toBe(0);
            // A distinção é o cerne do Requirement 1.10: mesmo com score 0 em
            // ambos os casos, a band/label precisa diferenciá-los.
            expect(res.band).not.toBe('sem-dados');
        });

        it(`${metricId}: undefined e NaN também caem em 'sem-dados' (distinto de 'nao-atendeu')`, () => {
            expect(evaluateMetric(metricId, undefined).band).toBe('sem-dados');
            expect(evaluateMetric(metricId, NaN).band).toBe('sem-dados');
        });
    }
});
