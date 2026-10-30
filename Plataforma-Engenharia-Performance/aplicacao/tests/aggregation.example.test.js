// Feature: score-performance-executivo
// Example tests (Task 4.1): sanity checks for journeyScore / engineeringScore.
// Property-based tests for aggregation are Task 4.4.
// _Requirements: 3.1, 4.1_

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Aggregation = require('../js/aggregation.js');
const ScoringEngine = require('../js/scoring-engine.js');
const PEPData = require('../js/data.js');

describe('Aggregation.journeyScore', () => {
    const month = PEPData.getLatestMonth();

    it('journeyScore = média aritmética dos totalScore dos times com dado', () => {
        const jornada = PEPData.JORNADAS[0];
        const teams = PEPData.getTeamsByJornada(jornada, month);
        const expected = teams
            .map(t => ScoringEngine.calculateTeamScore(t.metrics).totalScore)
            .reduce((a, b) => a + b, 0) / teams.length;

        const result = Aggregation.journeyScore(month, jornada);
        expect(result.hasData).toBe(true);
        expect(result.teams.length).toBe(teams.length);
        expect(result.avgScore).toBeCloseTo(Math.round(expected * 100) / 100, 2);
        expect(result.performanceBand).toEqual(ScoringEngine.getPerformanceBand(result.avgScore));
    });

    it('retorna hasData=false para jornada inexistente', () => {
        const result = Aggregation.journeyScore(month, 'Jornada Inexistente');
        expect(result.hasData).toBe(false);
        expect(result.avgScore).toBeNull();
        expect(result.teams).toEqual([]);
    });
});

describe('Aggregation.engineeringScore', () => {
    const month = PEPData.getLatestMonth();

    it('engineeringScore = média aritmética dos avgScore das jornadas (não ponderada)', () => {
        const perJourney = PEPData.JORNADAS
            .map(j => Aggregation.journeyScore(month, j))
            .filter(j => j.hasData);
        const expected = perJourney
            .map(j => j.avgScore)
            .reduce((a, b) => a + b, 0) / perJourney.length;

        const result = Aggregation.engineeringScore(month);
        expect(result.hasData).toBe(true);
        expect(result.journeys.length).toBe(perJourney.length);
        expect(result.avgScore).toBeCloseTo(Math.round(expected * 100) / 100, 2);
        expect(result.performanceBand).toEqual(ScoringEngine.getPerformanceBand(result.avgScore));
    });

    it('consolida as 4 categorias em cada nível', () => {
        const result = Aggregation.engineeringScore(month);
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(typeof result.categoryScores[id]).toBe('number');
        }
    });
});

describe('Aggregation.monthDelta (Task 4.2)', () => {
    const months = PEPData.getMonthsWithData();
    const first = months[0];
    const last = PEPData.getLatestMonth();

    it('retorna null no primeiro mês com dados (sem mês anterior)', () => {
        expect(Aggregation.monthDelta(first, null)).toBeNull();
        expect(Aggregation.monthDelta(first, PEPData.JORNADAS[0])).toBeNull();
    });

    it('delta de Engenharia (journeyName null) = score atual − score do mês anterior', () => {
        const prev = months[months.indexOf(last) - 1];
        const current = Aggregation.engineeringScore(last).avgScore;
        const previous = Aggregation.engineeringScore(prev).avgScore;
        const expected = Math.round((current - previous) * 100) / 100;
        expect(Aggregation.monthDelta(last, null)).toBeCloseTo(expected, 2);
    });

    it('delta de uma jornada = score atual − score do mês anterior', () => {
        const jornada = PEPData.JORNADAS[0];
        const prev = months[months.indexOf(last) - 1];
        const current = Aggregation.journeyScore(last, jornada).avgScore;
        const previous = Aggregation.journeyScore(prev, jornada).avgScore;
        const expected = Math.round((current - previous) * 100) / 100;
        expect(Aggregation.monthDelta(last, jornada)).toBeCloseTo(expected, 2);
    });

    it('retorna null quando a entidade não tem dado em algum dos meses', () => {
        expect(Aggregation.monthDelta(last, 'Jornada Inexistente')).toBeNull();
    });
});

describe('Aggregation.highlights (Task 4.2)', () => {
    const months = PEPData.getMonthsWithData();
    const first = months[0];
    const last = PEPData.getLatestMonth();

    it('retorna { topRise:null, topFall:null } no primeiro mês com dados', () => {
        expect(Aggregation.highlights(first)).toEqual({ topRise: null, topFall: null });
    });

    it('topRise é o maior delta positivo e topFall o menor delta vs mês anterior', () => {
        const prev = months[months.indexOf(last) - 1];
        const deltas = PEPData.JORNADAS
            .map(j => {
                const cur = Aggregation.journeyScore(last, j);
                const pre = Aggregation.journeyScore(prev, j);
                if (!cur.hasData || !pre.hasData) return null;
                return { journey: j, delta: Math.round((cur.avgScore - pre.avgScore) * 100) / 100 };
            })
            .filter(Boolean);

        const { topRise, topFall } = Aggregation.highlights(last);

        const maxDelta = Math.max(...deltas.map(d => d.delta));
        const minDelta = Math.min(...deltas.map(d => d.delta));

        if (maxDelta > 0) {
            expect(topRise).not.toBeNull();
            expect(topRise.delta).toBeCloseTo(maxDelta, 2);
        } else {
            expect(topRise).toBeNull();
        }

        if (minDelta < 0) {
            expect(topFall).not.toBeNull();
            expect(topFall.delta).toBeCloseTo(minDelta, 2);
        } else {
            expect(topFall).toBeNull();
        }
    });
});

// -----------------------------------------------------------------------------
// Task 4.3: metricAverage / metricDistribution / journeyMetrics
// Example (sanity) tests. Property-based tests (Properties 11 e 12) são a 4.4.
// _Requirements: 5.3, 5.4_
// -----------------------------------------------------------------------------

describe('Aggregation.metricAverage (Task 4.3)', () => {
    const month = PEPData.getLatestMonth();
    const jornada = PEPData.JORNADAS[0];
    const metricId = Object.keys(ScoringEngine.METRIC_RULES)[0];

    it('média = média aritmética dos valores dos times com dado; count = nº considerado', () => {
        const teams = PEPData.getTeamsByJornada(jornada, month);
        const values = teams
            .map(t => t.metrics[metricId])
            .filter(v => v !== null && v !== undefined && !Number.isNaN(v));

        const result = Aggregation.metricAverage(month, jornada, metricId);
        expect(result.count).toBe(values.length);
        if (values.length === 0) {
            expect(result.avg).toBeNull();
        } else {
            const expected = values.reduce((a, b) => a + b, 0) / values.length;
            expect(result.avg).toBeCloseTo(Math.round(expected * 100) / 100, 2);
        }
    });

    it('ignora "Sem Dados": time com valor null não entra na média nem no count', () => {
        // Spy no getTeamsByJornada para injetar um cenário controlado.
        const original = PEPData.getTeamsByJornada;
        PEPData.getTeamsByJornada = () => ([
            { id: 't1', name: 'T1', jornada, metrics: { [metricId]: 0.80 } },
            { id: 't2', name: 'T2', jornada, metrics: { [metricId]: 1.00 } },
            { id: 't3', name: 'T3', jornada, metrics: { [metricId]: null } }
        ]);
        try {
            const result = Aggregation.metricAverage(month, jornada, metricId);
            expect(result.count).toBe(2);
            expect(result.avg).toBeCloseTo(0.9, 2);
        } finally {
            PEPData.getTeamsByJornada = original;
        }
    });

    it('avg null e count 0 quando nenhum time tem dado para a métrica', () => {
        const original = PEPData.getTeamsByJornada;
        PEPData.getTeamsByJornada = () => ([
            { id: 't1', name: 'T1', jornada, metrics: { [metricId]: null } },
            { id: 't2', name: 'T2', jornada, metrics: { [metricId]: undefined } }
        ]);
        try {
            const result = Aggregation.metricAverage(month, jornada, metricId);
            expect(result).toEqual({ avg: null, count: 0 });
        } finally {
            PEPData.getTeamsByJornada = original;
        }
    });
});

describe('Aggregation.metricDistribution (Task 4.3)', () => {
    const month = PEPData.getLatestMonth();
    const jornada = PEPData.JORNADAS[0];
    const metricId = Object.keys(ScoringEngine.METRIC_RULES)[0];

    it('a soma das 4 faixas = total = nº de times com dado (Property 12)', () => {
        const teams = PEPData.getTeamsByJornada(jornada, month);
        const withData = teams.filter(t => {
            const v = t.metrics[metricId];
            return v !== null && v !== undefined && !Number.isNaN(v);
        });

        const dist = Aggregation.metricDistribution(month, jornada, metricId);
        const sum = dist['nao-atendeu'] + dist['atendeu-parcialmente'] +
            dist['atendeu-totalmente'] + dist['superou'];
        expect(sum).toBe(dist.total);
        expect(dist.total).toBe(withData.length);
    });

    it('classifica cada time na faixa correspondente ao seu valor', () => {
        // planejadoVsRealizado: <0.75 não atendeu; [0.75,1) parcial; =1 total; >1 superou.
        const original = PEPData.getTeamsByJornada;
        PEPData.getTeamsByJornada = () => ([
            { id: 'a', name: 'A', jornada, metrics: { planejadoVsRealizado: 0.50 } }, // nao-atendeu
            { id: 'b', name: 'B', jornada, metrics: { planejadoVsRealizado: 0.85 } }, // parcial
            { id: 'c', name: 'C', jornada, metrics: { planejadoVsRealizado: 1.00 } }, // total
            { id: 'd', name: 'D', jornada, metrics: { planejadoVsRealizado: 1.10 } }, // superou
            { id: 'e', name: 'E', jornada, metrics: { planejadoVsRealizado: null } }  // sem dados (ignorado)
        ]);
        try {
            const dist = Aggregation.metricDistribution(month, jornada, 'planejadoVsRealizado');
            expect(dist).toEqual({
                'nao-atendeu': 1,
                'atendeu-parcialmente': 1,
                'atendeu-totalmente': 1,
                'superou': 1,
                total: 4
            });
        } finally {
            PEPData.getTeamsByJornada = original;
        }
    });
});

describe('Aggregation.journeyMetrics (Task 4.3)', () => {
    const month = PEPData.getLatestMonth();
    const jornada = PEPData.JORNADAS[0];

    it('retorna average e distribution para cada métrica conhecida', () => {
        const result = Aggregation.journeyMetrics(month, jornada);
        const ids = Object.keys(ScoringEngine.METRIC_RULES);
        expect(Object.keys(result).sort()).toEqual(ids.slice().sort());
        for (const id of ids) {
            expect(result[id]).toHaveProperty('average');
            expect(result[id]).toHaveProperty('distribution');
            expect(result[id].average).toHaveProperty('avg');
            expect(result[id].average).toHaveProperty('count');
            expect(result[id].distribution).toHaveProperty('total');
        }
    });
});
