// Feature: exceções com tratamento (redistribuir × substituir).
// Valida (1) o store com os novos campos (tratamento/metricaSubstituta) e a
// validação condicional, e (2) o recálculo do Score no engine aplicando as
// exceções aprovadas (redistribuir o peso ou substituir a métrica).

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

function installStorage() {
    const mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

const ExcecoesStore = require('../js/excecoes-store.js');
const ScoringEngine = require('../js/scoring-engine.js');

describe('ExcecoesStore — tratamento (redistribuir × substituir)', () => {
    beforeEach(() => { installStorage(); ExcecoesStore.reload(); ExcecoesStore.clear(); });

    it('default do tratamento é "redistribuir" quando não informado', () => {
        const rec = ExcecoesStore.request({
            squad: 'Moodle', indicador: 'Team Health Check',
            justificativa: 'THC não aplicável neste ciclo', periodo: 'Set/2026',
            solicitante: 'admin'
        });
        expect(rec.tratamento).toBe('redistribuir');
        expect(rec.metricaSubstituta).toBeNull();
    });

    it('substituir exige métrica substituta (validação)', () => {
        const errors = ExcecoesStore.validate({
            squad: 'Moodle', indicador: 'Team Health Check',
            justificativa: 'x', periodo: 'Set/2026',
            tratamento: 'substituir', metricaSubstituta: ''
        });
        expect(errors.some((e) => e.field === 'metricaSubstituta')).toBe(true);
    });

    it('grava substituir com a métrica substituta escolhida', () => {
        const rec = ExcecoesStore.request({
            squad: 'Moodle', indicador: 'Frequência de Deploy',
            justificativa: 'Sem deploy no período; usar Lead Time', periodo: 'Set/2026',
            tratamento: 'substituir', metricaSubstituta: 'retro:leadTime',
            solicitante: 'admin'
        });
        expect(rec.tratamento).toBe('substituir');
        expect(rec.metricaSubstituta).toBe('retro:leadTime');
    });
});

describe('ScoringEngine — recálculo com exceções', () => {
    // Métricas de um time hipotético (todas plausíveis).
    const metrics = {
        planejadoVsRealizado: 1, cycleTimeDev: 3.5, cycleTimeHomolog: 1.1, cycleTimeImpl: 5.7,
        freqDeploy: 4, okrs: 1, disponibilidade: 0.999, vulnerabilidade: 1,
        slaAlta: 1, slaMedia: 1, slaBaixa: 1, teamHealth: 9.1
    };

    it('sem exceções: idêntico ao calculateTeamScore', () => {
        const base = ScoringEngine.calculateTeamScore(metrics);
        const comExc = ScoringEngine.calculateTeamScoreWithExceptions(metrics, []);
        expect(comExc.totalScore).toBe(base.totalScore);
        expect(comExc.exceptionsApplied).toEqual([]);
    });

    it('resolveMetricId aceita id e nome', () => {
        expect(ScoringEngine.resolveMetricId('teamHealth')).toBe('teamHealth');
        expect(ScoringEngine.resolveMetricId('Team Health Check')).toBe('teamHealth');
        expect(ScoringEngine.resolveMetricId('inexistente')).toBeNull();
    });

    it('redistribuir: remove a métrica e re-escala as demais (base preservada)', () => {
        const base = ScoringEngine.calculateTeamScore(metrics);
        const res = ScoringEngine.calculateTeamScoreWithExceptions(metrics, [
            { indicador: 'Team Health Check', tratamento: 'redistribuir' }
        ]);
        // A aplicação é registrada com o peso redistribuído (teamHealth = 50).
        expect(res.exceptionsApplied.length).toBe(1);
        expect(res.exceptionsApplied[0].tratamento).toBe('redistribuir');
        expect(res.exceptionsApplied[0].pesoRedistribuido).toBe(50);
        // Como o time tinha pontuação alta em teamHealth, ao redistribuir o total
        // muda, mas permanece dentro do teto e é um número finito > 0.
        expect(res.totalScore).toBeGreaterThan(0);
        expect(res.totalScore).toBeLessThanOrEqual(ScoringEngine.MAX_TOTAL_SCORE);
        expect(res.totalScore).not.toBe(base.totalScore);
    });

    it('redistribuir métrica com score 0 AUMENTA o total (peso diluído nas demais)', () => {
        // Time com freqDeploy ruim (0 pts) — ao excetuar, as demais sobem.
        const m2 = Object.assign({}, metrics, { freqDeploy: 0 });
        const base = ScoringEngine.calculateTeamScore(m2);
        const res = ScoringEngine.calculateTeamScoreWithExceptions(m2, [
            { indicador: 'freqDeploy', tratamento: 'redistribuir' }
        ]);
        expect(res.totalScore).toBeGreaterThan(base.totalScore);
    });

    it('substituir: a substituta herda o PESO-BASE da métrica (valor × peso)', () => {
        // A substituta assume o peso-BASE da métrica (freqDeploy.max = 75),
        // independentemente de a original ter bônus de superação. Validamos a
        // diferença relativa ao score atual da própria métrica.
        const base = ScoringEngine.calculateTeamScore(metrics);
        const scoreFreqAtual = base.metrics.freqDeploy.score; // 80 (superou, >peso)
        const pesoFreq = 75;

        const cheio = ScoringEngine.calculateTeamScoreWithExceptions(metrics, [
            { indicador: 'freqDeploy', tratamento: 'substituir', metricaSubstituta: 'retro:leadTime', valorSubstituto: 1 }
        ]);
        expect(cheio.exceptionsApplied[0].tratamento).toBe('substituir');
        expect(cheio.exceptionsApplied[0].metricaSubstituta).toBe('retro:leadTime');
        // Troca o score atual pela contribuição da substituta = 1.0 × 75.
        expect(cheio.totalScore).toBeCloseTo(base.totalScore - scoreFreqAtual + (1 * pesoFreq), 2);

        const meio = ScoringEngine.calculateTeamScoreWithExceptions(metrics, [
            { indicador: 'freqDeploy', tratamento: 'substituir', metricaSubstituta: 'retro:leadTime', valorSubstituto: 0.5 }
        ]);
        // Contribuição = 0.5 × 75 = 37.5 no lugar do score atual.
        expect(meio.totalScore).toBeCloseTo(base.totalScore - scoreFreqAtual + (0.5 * pesoFreq), 2);
    });

    it('exceção com indicador inexistente é ignorada', () => {
        const base = ScoringEngine.calculateTeamScore(metrics);
        const res = ScoringEngine.calculateTeamScoreWithExceptions(metrics, [
            { indicador: 'NaoExiste', tratamento: 'redistribuir' }
        ]);
        expect(res.totalScore).toBe(base.totalScore);
        expect(res.exceptionsApplied).toEqual([]);
    });
});
