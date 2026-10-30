// Feature: parametrização de métricas (fotos mensal/PI + origem por métrica)
// Valida o módulo MetricConfig: origem efetiva por métrica (default do
// scoring-engine + override persistido), config de fechamento por PI
// (sprintFechamento 5/6, parametrizável) e a definição das duas fotos.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const ScoringEngine = require('../js/scoring-engine.js');
const PEPRetroData = require('../js/retro-data.js');
const MetricConfig = require('../js/metric-config.js');

// Garante o acesso global dos módulos dependentes (browser-like).
globalThis.ScoringEngine = ScoringEngine;
globalThis.PEPRetroData = PEPRetroData;

describe('MetricConfig — origem por métrica mensal (Score)', () => {
    beforeEach(() => { MetricConfig._refreshStore(); MetricConfig.reset(); });

    it('lista as 12 métricas com origem efetiva derivada do scoring-engine', () => {
        const metricas = MetricConfig.listMetricas();
        expect(metricas.length).toBe(Object.keys(ScoringEngine.METRIC_RULES).length);
        expect(metricas.length).toBe(12);
        for (const m of metricas) {
            expect(m).toHaveProperty('id');
            expect(m).toHaveProperty('nome');
            expect(m).toHaveProperty('categoria');
            expect(m).toHaveProperty('peso');
            expect(['jira', 'manual', 'integracao-futura']).toContain(m.origem);
        }
    });

    it('aplica as origens validadas pelo negócio (default do engine)', () => {
        const byId = Object.fromEntries(MetricConfig.listMetricas().map((m) => [m.id, m]));
        // Jira (automatizável)
        for (const id of ['planejadoVsRealizado', 'cycleTimeDev', 'cycleTimeHomolog',
            'cycleTimeImpl', 'slaAlta', 'slaMedia', 'slaBaixa', 'okrs']) {
            expect(byId[id].origem, `${id} deveria ser jira`).toBe('jira');
        }
        // OKRs via Must Win Battles
        expect(byId.okrs.origemDetalhe).toBe('must-win-battles');
        // Manual agora, integração futura
        expect(byId.freqDeploy.origem).toBe('manual');
        expect(byId.freqDeploy.integracaoFutura).toBe('git');
        expect(byId.disponibilidade.origem).toBe('manual');
        expect(byId.vulnerabilidade.origem).toBe('manual');
        expect(byId.teamHealth.origem).toBe('manual');
        expect(byId.teamHealth.integracaoFutura).toBe('pesquisa-thc');
    });

    it('metricasManuais retorna só as de origem != jira (as 4 manuais)', () => {
        const manuais = MetricConfig.metricasManuais().map((m) => m.id).sort();
        expect(manuais).toEqual(['disponibilidade', 'freqDeploy', 'teamHealth', 'vulnerabilidade'].sort());
    });

    it('setOrigem sobrepõe o default e persiste; reset volta ao default', () => {
        expect(MetricConfig.getOrigem('freqDeploy')).toBe('manual');
        expect(MetricConfig.setOrigem('freqDeploy', 'integracao-futura')).toBe(true);
        expect(MetricConfig.getOrigem('freqDeploy')).toBe('integracao-futura');
        // Métrica inexistente ou origem inválida → não aplica.
        expect(MetricConfig.setOrigem('naoExiste', 'jira')).toBe(false);
        expect(MetricConfig.setOrigem('freqDeploy', 'invalida')).toBe(false);
        MetricConfig.reset();
        expect(MetricConfig.getOrigem('freqDeploy')).toBe('manual');
    });
});

describe('MetricConfig — fechamento por PI (sprint 5/6 parametrizável)', () => {
    beforeEach(() => { MetricConfig._refreshStore(); MetricConfig.reset(); });

    it('default do PI vem do retro-data (6)', () => {
        expect(MetricConfig.getSprintFechamento('PI 4/26')).toBe(6);
        expect(PEPRetroData.getSprintFechamento('PI 4/26')).toBe(6);
    });

    it('setSprintFechamento aceita 5 ou 6 e persiste; rejeita valores inválidos', () => {
        expect(MetricConfig.setSprintFechamento('PI 4/26', 5)).toBe(true);
        expect(MetricConfig.getSprintFechamento('PI 4/26')).toBe(5);
        expect(MetricConfig.setSprintFechamento('PI 4/26', 7)).toBe(false);
        expect(MetricConfig.setSprintFechamento('PI 4/26', 0)).toBe(false);
        // Continua 5 (o inválido não sobrescreveu).
        expect(MetricConfig.getSprintFechamento('PI 4/26')).toBe(5);
        MetricConfig.reset();
        expect(MetricConfig.getSprintFechamento('PI 4/26')).toBe(6);
    });

    it('listPIs expõe todos os PIs com a sprint de fechamento efetiva', () => {
        const pis = MetricConfig.listPIs();
        expect(pis.map((p) => p.pi)).toEqual(PEPRetroData.getPIs());
        expect(pis.every((p) => [5, 6].includes(p.sprintFechamento))).toBe(true);
    });
});

describe('MetricConfig — definição das fotos (snapshots)', () => {
    it('expõe as fotos mensal e de PI com o ritmo correto', () => {
        expect(MetricConfig.FOTOS.mensal.quando).toBe('ultimo-dia-util');
        expect(MetricConfig.FOTOS.mensal.escopo).toBe('metricas-score');
        expect(MetricConfig.FOTOS.pi.quando).toBe('sprint-fechamento');
        expect(MetricConfig.FOTOS.pi.escopo).toBe('metricas-retrospectiva');
    });
});
