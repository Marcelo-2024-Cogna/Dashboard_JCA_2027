// Feature: score-performance-executivo
// Task 3.1: valida o modelo de dados mensal em data.js (MonthlyTeamRecord,
// Dataset esparso, API de consulta por mês/jornada/time) e a compatibilidade
// dos dados com o scoring-engine reconciliado (teamHealth 0..10).
// _Requirements: 8.1, 8.2, 8.6_

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const PEPData = require('../js/data.js');
const ScoringEngine = require('../js/scoring-engine.js');

const METRIC_KEYS = [
    'planejadoVsRealizado', 'cycleTimeDev', 'cycleTimeHomolog', 'cycleTimeImpl',
    'freqDeploy', 'okrs', 'disponibilidade', 'vulnerabilidade',
    'slaAlta', 'slaMedia', 'slaBaixa', 'teamHealth'
];

describe('Modelo mensal (data.js)', () => {
    it('expõe Dataset com year, jornadas, records e source (Requirement 8.6)', () => {
        const ds = PEPData.DATASET;
        expect(ds.year).toBe(2026);
        expect(Array.isArray(ds.jornadas)).toBe(true);
        expect(ds.jornadas.length).toBe(10); // 10 jornadas preservadas
        expect(Array.isArray(ds.records)).toBe(true);
        expect(ds.records.length).toBeGreaterThan(0);
        expect(ds.source).toEqual({
            current: 'planilha-manual',
            futurePlanned: ['ServiceNow', 'Datadog']
        });
    });

    it('cada registro é um MonthlyTeamRecord com as 12 métricas (Requirements 8.1, 8.2)', () => {
        for (const r of PEPData.DATASET.records) {
            expect(r.month).toBeGreaterThanOrEqual(1);
            expect(r.month).toBeLessThanOrEqual(12);
            expect(PEPData.MONTH_LABELS[r.month - 1]).toBe(r.monthLabel);
            expect(typeof r.jornada).toBe('string');
            expect(typeof r.teamId).toBe('string');
            expect(typeof r.teamName).toBe('string');
            for (const k of METRIC_KEYS) {
                expect(r.metrics).toHaveProperty(k);
            }
        }
    });

    it('cobre Jan–Set (realizado até set/2026) de forma esparsa', () => {
        expect(PEPData.getMonthsWithData()).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9]);
        // Esparso: Out–Dez não têm registros.
        expect(PEPData.getRecords(10).length).toBe(0);
        expect(PEPData.getRecords(12).length).toBe(0);
    });

    it('Team Health Check está em escala 0..10 (correção D6)', () => {
        for (const r of PEPData.DATASET.records) {
            expect(r.metrics.teamHealth).toBeGreaterThanOrEqual(0);
            expect(r.metrics.teamHealth).toBeLessThanOrEqual(10);
        }
        // Pelo menos um time com THC claramente acima de 1 (prova a conversão).
        const anyAboveOne = PEPData.DATASET.records.some(r => r.metrics.teamHealth > 1);
        expect(anyAboveOne).toBe(true);
    });

    it('API de consulta por mês/jornada/time funciona', () => {
        const m = PEPData.getLatestMonth();
        expect(m).toBe(9);
        const recs = PEPData.getRecords(m);
        expect(recs.length).toBe(PEPData.TEAMS.length);

        const one = recs[0];
        const byId = PEPData.getRecord(m, one.teamId);
        expect(byId.teamId).toBe(one.teamId);

        // Aceita rótulo de mês ('Set') além do número.
        expect(PEPData.getRecords('Set').length).toBe(recs.length);

        const acad = PEPData.getTeamsByJornada('Acadêmica');
        expect(acad.length).toBe(5);
        expect(acad.every(t => t.jornada === 'Acadêmica')).toBe(true);
        // formato legado id/name
        expect(acad[0]).toHaveProperty('id');
        expect(acad[0]).toHaveProperty('name');
    });

    it('mantém compatibilidade legada (TEAMS/getTeamById/JORNADAS/CURRENT_SPRINT)', () => {
        expect(Array.isArray(PEPData.TEAMS)).toBe(true);
        // Carga de dados reais (Score Set/26): 41 squads reais (todas as jornadas).
        expect(PEPData.TEAMS.length).toBe(41);
        const t = PEPData.TEAMS[0];
        expect(t).toHaveProperty('id');
        expect(t).toHaveProperty('name');
        expect(t).toHaveProperty('metrics');
        expect(PEPData.getTeamById(t.id).id).toBe(t.id);
        expect(typeof PEPData.CURRENT_SPRINT).toBe('string');
    });

    it('dados são compatíveis com evaluateMetric/calculateTeamScore do engine', () => {
        const VALID_BANDS = ['nao-atendeu', 'atendeu-parcialmente', 'atendeu-totalmente', 'superou'];
        for (const r of PEPData.DATASET.records) {
            for (const k of METRIC_KEYS) {
                const res = ScoringEngine.evaluateMetric(k, r.metrics[k]);
                // Todos os registros possuem dado → nunca "Sem Dados".
                expect(res.band).not.toBe('sem-dados');
                expect(VALID_BANDS).toContain(res.band);
                expect(res.score).toBeGreaterThanOrEqual(0);
                // A banda "Superou" acrescenta ~10% de bônus acima do peso-base
                // (Requirement 1.7), portanto o score pode exceder res.max.
            }
            const score = ScoringEngine.calculateTeamScore(r.metrics);
            expect(score.totalScore).toBeGreaterThanOrEqual(0);
            expect(score.totalScore).toBeLessThanOrEqual(ScoringEngine.MAX_TOTAL_SCORE);
        }
    });
});

// =============================================================================
// Task 3.2: Baseline dez/25 (Partida) por jornada — Requirement 7.1
// =============================================================================
describe('Baseline — Partida/Score por jornada (Task 3.2, Requirement 7.1)', () => {
    // A Partida é o score REALIZADO de dez/2025 por jornada (visão de Metas dos
    // PDFs reais). É um ponto fixo DISTINTO do realizado do mês corrente
    // (Set/26), sobre o qual Meta 2026 e as metas mensais progressivas são
    // calculadas. Isso permite que o Farol fique verde quando o realizado do
    // mês atinge a meta (realizado ÷ metaMes ≥ 99%).
    const EXPECTED_PARTIDA = {
        'Acadêmica': 916,
        'Saber': 953,
        'Financeira': 867,
        'Ingresso': 799,
        'Pós Graduação': 971,
        'Inteligência Artificial': 909,
        'Voomp': 818,
        'Atendimento e Serviços': 857,
        'Aprendizagem': 864,
        'Secretaria e Gestor de Unidades': 817
    };

    it('expõe BASELINE e o acessor getPartida na API pública', () => {
        expect(typeof PEPData.BASELINE).toBe('object');
        expect(typeof PEPData.getPartida).toBe('function');
    });

    it('todas as 10 jornadas de PEPData.JORNADAS têm Partida definida', () => {
        expect(PEPData.JORNADAS.length).toBe(10);
        for (const jornada of PEPData.JORNADAS) {
            const partida = PEPData.getPartida(jornada);
            expect(partida, `Partida ausente para jornada "${jornada}"`).toBeTypeOf('number');
            expect(partida).toBeGreaterThan(0);
        }
    });

    it('os valores de Partida batem com os de referência (visão de Metas)', () => {
        for (const [jornada, valor] of Object.entries(EXPECTED_PARTIDA)) {
            expect(PEPData.getPartida(jornada)).toBe(valor);
            expect(PEPData.BASELINE[jornada]).toBe(valor);
        }
    });

    it('as chaves de jornada do BASELINE correspondem exatamente a JORNADAS', () => {
        const baselineJornadas = Object.keys(PEPData.BASELINE)
            .filter(k => !k.startsWith('_'))
            .sort();
        expect(baselineJornadas).toEqual([...PEPData.JORNADAS].sort());
    });

    it('registra o total de Engenharia (863, dez/25) à parte, não como jornada', () => {
        // Total/média de Engenharia da Partida dez/25 (não é jornada).
        expect(PEPData.BASELINE._engenharia).toBe(863);
        expect(PEPData.getPartida('_engenharia')).toBe(863);
        // "Engenharia" não é uma jornada.
        expect(PEPData.JORNADAS).not.toContain('Engenharia');
        expect(PEPData.getPartida('Engenharia')).toBeUndefined();
    });

    it('getPartida retorna undefined para jornada desconhecida', () => {
        expect(PEPData.getPartida('Inexistente')).toBeUndefined();
    });
});
