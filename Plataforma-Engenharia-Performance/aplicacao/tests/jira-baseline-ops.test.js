// Feature: score-performance-executivo
// Testes da Fase 2 do módulo de Retro PI (Tasks 23, 24, 25): ingestão da
// extração do Jira com geração por Sprint, carga de baseline por PI e ponte com
// o Operacional/Times. Inclui testes de propriedade (fast-check) das Properties
// 27, 28, 29, 30, 31 e testes de exemplo.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';
import fc from 'fast-check';

const require = createRequire(import.meta.url);

const JiraIngest = require('../js/jira-ingest.js');
const JiraMapping = require('../js/jira-mapping.js');
const BaselineLoader = require('../js/baseline-loader.js');
const OpsBridge = require('../js/ops-bridge.js');
require('../js/data.js');
require('../js/identity.js');

// Gerador de item válido do Jira.
const genItem = fc.record({
    chave: fc.constantFrom('ASAT-1', 'ASKB-2', 'ARTPT-3', 'ASR6-4').map((c, i) => c),
    tipoItem: fc.constantFrom('História', 'Enabler', 'Incidente', 'Spike', 'Débito Técnico', 'Suporte'),
    status: fc.constantFrom('Concluído', 'Em andamento'),
    jornada: fc.constant('Atendimento e Serviços'),
    squad: fc.constantFrom('Atendimento', 'Kbots', 'Papa-léguas', 'Route 66'),
    sprint: fc.constantFrom('SP 26.3.1', 'SP 26.3.2', 'SP 26.3.3'),
    pi: fc.constantFrom('PI 3/26', 'PI 2/26'),
    storyPoints: fc.integer({ min: 0, max: 13 })
});

describe('Property 27: Geração por Sprint preserva os totais da extração', () => {
    it('soma de SP e contagem por recorte iguais à entrada', () => {
        fc.assert(fc.property(fc.array(genItem, { minLength: 1, maxLength: 60 }), (items) => {
            // chaves únicas para evitar colisão artificial.
            const uniq = items.map((it, i) => ({ ...it, chave: `${it.chave}-${i}` }));
            const ds = JiraIngest.buildRetroFromJira(uniq, null);

            // Soma total de SP preservada.
            const somaEntrada = uniq.reduce((a, it) => a + (Number(it.storyPoints) || 0), 0);
            const somaPT = ds.pontosTrabalhados.reduce((a, r) => a + r.total, 0);
            expect(Math.abs(somaPT - somaEntrada)).toBeLessThanOrEqual(0.01);

            // Contagem total de itens preservada na carga de trabalho.
            const qtdCarga = ds.cargaTrabalho.reduce((a, c) => a + c.qtdeItens, 0);
            expect(qtdCarga).toBe(uniq.length);
        }), { numRuns: 100 });
    });
});

describe('Property 28: Round-trip da Extração do Jira', () => {
    it('parseCSV(toCSV(items)) ≈ items', () => {
        fc.assert(fc.property(fc.array(genItem, { minLength: 1, maxLength: 40 }), (items) => {
            const uniq = items.map((it, i) => ({ ...it, chave: `${it.chave}-${i}` }));
            const csv = JiraIngest.toCSV(uniq);
            const back = JiraIngest.parseCSV(csv);
            expect(back.items.length).toBe(uniq.length);
            for (let i = 0; i < uniq.length; i++) {
                expect(back.items[i].chave).toBe(uniq[i].chave);
                expect(back.items[i].sprint).toBe(uniq[i].sprint);
                expect(back.items[i].pi).toBe(uniq[i].pi);
                expect(Number(back.items[i].storyPoints) || 0).toBe(Number(uniq[i].storyPoints) || 0);
            }
        }), { numRuns: 100 });
    });
});

describe('Property 29: Rejeição de itens inválidos preserva os válidos', () => {
    it('importa só os válidos e acumula erro por inválido', () => {
        const csv = [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5', // válido
            ';História;Concluído;;;;;', // inválido (sem chave/tipo/sprint/pi)
            'ASKB-2;Enabler;Concluído;Atendimento e Serviços;Kbots;SP 26.3.2;;3', // inválido (sem PI)
            'ARTPT-3;Spike;Concluído;Atendimento e Serviços;Papa-léguas;SP 26.3.1;PI 3/26;2' // válido
        ].join('\n');
        const { items, errors } = JiraIngest.parseCSV(csv);
        expect(items.length).toBe(2);
        expect(errors.length).toBe(2);
        expect(errors[0].motivo).toMatch(/obrigat/i);
    });
});

describe('Property 30: Baseline por PI é isolado e substituível', () => {
    beforeEach(() => BaselineLoader._reset());

    function baselineCSV(pi, chaves) {
        return ['pi;chave;jornada;squad;situacao']
            .concat(chaves.map(c => `${pi};${c};Atendimento e Serviços;Atendimento;em-dia`))
            .join('\n');
    }

    it('consulta sempre o último válido; inválido não altera; PIs independentes', () => {
        // Carrega PI A e PI B.
        const a1 = BaselineLoader.loadForPI('PI 1/26', baselineCSV('PI 1/26', ['E1', 'E2']), 'csv');
        const b1 = BaselineLoader.loadForPI('PI 2/26', baselineCSV('PI 2/26', ['E9']), 'csv');
        expect(a1.ok).toBe(true);
        expect(b1.ok).toBe(true);
        expect(BaselineLoader.getBaselineForPI('PI 1/26').registros.length).toBe(2);
        expect(BaselineLoader.getBaselineForPI('PI 2/26').registros.length).toBe(1);

        // Substitui PI A por novo válido.
        BaselineLoader.loadForPI('PI 1/26', baselineCSV('PI 1/26', ['E1', 'E2', 'E3']), 'csv');
        expect(BaselineLoader.getBaselineForPI('PI 1/26').registros.length).toBe(3);
        // PI B não foi afetado.
        expect(BaselineLoader.getBaselineForPI('PI 2/26').registros.length).toBe(1);

        // Carga inválida não altera o vigente.
        const inval = BaselineLoader.loadForPI('PI 1/26', 'cabecalho_invalido\n', 'csv');
        expect(inval.ok).toBe(false);
        expect(BaselineLoader.getBaselineForPI('PI 1/26').registros.length).toBe(3);
    });
});

describe('Property 31: Consistência de identidade de Squad entre áreas', () => {
    it('reconcileSquads separa emAmbos / soNoOperacional / soNoRetro', () => {
        // "Atendimento" (nome do Retro) resolve para o canônico do Operacional
        // "Athenas - Atendimento" via alias; "Squad Fantasma" não tem par.
        const r = OpsBridge.reconcileSquads(['Atendimento', 'Squad Fantasma']);
        expect(r.emAmbos).toContain('Athenas - Atendimento');
        expect(r.soNoRetro).toContain('Squad Fantasma');
        // Squads sem correspondência são sinalizados, não omitidos.
        expect(r.soNoRetro.length).toBeGreaterThan(0);
    });

    it('tolera variações de acento/caixa na reconciliação', () => {
        const r = OpsBridge.reconcileSquads(['atendimento']); // minúsculo → alias canônico
        expect(r.emAmbos.length).toBe(1);
        expect(r.soNoRetro.length).toBe(0);
    });
});

describe('JiraMapping — resolução de Jornada/Squad (21.7)', () => {
    it('resolve squad por nome e jornada pela identidade canônica', () => {
        // 'kbots' (minúsculo) resolve para o rótulo canônico do Operacional 'kBots'.
        const item = { chave: 'ASKB-10', squad: 'kbots', jornada: '' };
        expect(JiraMapping.resolveSquad(item)).toBe('kBots');
        expect(JiraMapping.resolveJornada(item)).toBe('Atendimento e Serviços');
    });

    it('resolve por tabela byProject quando configurada', () => {
        JiraMapping.setMapping({ byProject: { ZZZ: { jornada: 'Atendimento e Serviços', squad: 'Route 66' } } });
        const item = { chave: 'ZZZ-5', squad: 'desconhecido', jornada: '' };
        expect(JiraMapping.resolveSquad(item)).toBe('Route 66');
        JiraMapping.setMapping({}); // limpa para não afetar outros testes
    });
});

describe('OpsBridge — patch sugerido de Score (23.2, 23.3)', () => {
    it('mapeia cycle time derivado para métricas do Score sob o squad canônico', () => {
        // 'Atendimento' (rótulo do Retro) resolve para o canônico do Operacional
        // 'Athenas - Atendimento'; 'Kbots' → 'kBots' (Requirement 23.3).
        const patch = OpsBridge.suggestScorePatch([
            { time: 'Atendimento', desenvolvimento: 3.2, homologacao: 1.1, implantacao: 2.0 },
            { time: 'Kbots', desenvolvimento: 2.5 }
        ]);
        expect(patch['Athenas - Atendimento'].cycleTimeDev).toBe(3.2);
        expect(patch['Athenas - Atendimento'].cycleTimeHomolog).toBe(1.1);
        expect(patch['Athenas - Atendimento'].cycleTimeImpl).toBe(2.0);
        expect(patch['kBots'].cycleTimeDev).toBe(2.5);
        expect(patch['kBots'].cycleTimeHomolog).toBeUndefined();
    });

    it('mapeia Frequência de Deploy (freqDeploy) quando fornecida (23.3)', () => {
        const { patch } = OpsBridge.mapToScore([
            { time: 'Atendimento', freqDeploy: 3, desenvolvimento: 2.1 }
        ]);
        expect(patch['Athenas - Atendimento'].freqDeploy).toBe(3);
        expect(patch['Athenas - Atendimento'].cycleTimeDev).toBe(2.1);
    });

    it('só emite chaves que existem no motor de Score (não corrompe o modelo)', () => {
        const metricasReais = OpsBridge._knownScoreMetrics();
        const { patch } = OpsBridge.mapToScore([
            { time: 'Atendimento', desenvolvimento: 3.0, homologacao: 1.0, implantacao: 2.0, freqDeploy: 2 }
        ]);
        Object.values(patch).forEach(metrics => {
            Object.keys(metrics).forEach(k => expect(metricasReais.has(k)).toBe(true));
        });
    });

    it('ignora valores ausentes/NaN e linhas sem squad', () => {
        const { patch } = OpsBridge.mapToScore([
            { time: 'Atendimento', desenvolvimento: null, homologacao: NaN, implantacao: 2.0 },
            { time: '', desenvolvimento: 5 },      // sem squad → ignorada
            { desenvolvimento: 4 }                 // sem squad → ignorada
        ]);
        expect(patch['Athenas - Atendimento']).toEqual({ cycleTimeImpl: 2.0 });
        expect(Object.keys(patch).length).toBe(1);
    });

    it('sinaliza squad sem correspondência sem corromper dados (23.5)', () => {
        const { patch, divergences } = OpsBridge.mapToScore([
            { time: 'Atendimento', desenvolvimento: 3.0 },
            { time: 'Squad Fantasma', desenvolvimento: 4.0 }
        ]);
        // Divergência sinalizada para o squad desconhecido.
        expect(divergences.some(d => d.type === 'unknownSquad' && d.squad === 'Squad Fantasma')).toBe(true);
        // O dado do squad desconhecido não é descartado: fica sob o rótulo original.
        expect(patch['Squad Fantasma'].cycleTimeDev).toBe(4.0);
        // O squad reconhecido não gera divergência.
        expect(divergences.some(d => d.squad === 'Atendimento')).toBe(false);
    });
});

describe('OpsBridge — alimentação das visões de Score (23.2, 23.4)', () => {
    it('scoreByJornada deriva o resumo de Engenharia do Operacional/Times', () => {
        const data = require('../js/data.js');
        const month = data.getLatestMonth();
        const resumo = OpsBridge.scoreByJornada(month);
        expect(resumo).not.toBeNull();
        expect(resumo.hasData).toBe(true);
        expect(Array.isArray(resumo.journeys)).toBe(true);
        expect(resumo.journeys.length).toBeGreaterThan(0);
        expect(Number.isFinite(resumo.avgScore)).toBe(true);
    });

    it('retorna null quando o mês não tem dados (sem corromper)', () => {
        // Mês sem dados no dataset (fora dos meses com registro).
        const resumo = OpsBridge.scoreByJornada(99);
        expect(resumo).toBeNull();
    });
});
