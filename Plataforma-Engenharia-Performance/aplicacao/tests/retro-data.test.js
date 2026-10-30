// Feature: score-performance-executivo
// Task 12: valida o modelo de dados de Retrospectiva de PI (retro-data.js):
// RetroDataset por PI/Sprint/Time/Tipo de Item, coleções do design, origem
// (planilha-manual-jira / futuro Jira) e a API de consulta por PI/Time + a
// lista de PIs disponíveis. Os valores de exemplo são oráculo extraído da
// planilha "03 - Cogna - Template Métricas Review - Retro PI - Agosto".
// _Requirements: 18.1, 18.5, 18.7_

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Retro = require('../js/retro-data.js');

const COLLECTIONS = [
    'pontosTrabalhados', 'produtividade', 'epicos', 'cycleTime', 'leadTime',
    'debito', 'sustentacao', 'cargaTrabalho', 'alteracoesPlano', 'impedimentos'
];

// Coleções COM fonte real no PI 4/26 (Velocity/Jira): devem ter registros.
const COLLECTIONS_COM_DADOS = [
    'pontosTrabalhados', 'produtividade', 'epicos', 'debito', 'cargaTrabalho'
];
// Coleções SEM fonte viável no Jira para a coleta A1 (Cycle/Lead/Sustentação/
// Alterações/Impedimentos): por decisão do usuário (só PI 4/26 importa), os
// dados legados de PI 3/26 foram removidos e os painéis exibem "Sem dados".
const COLLECTIONS_VAZIAS = ['cycleTime', 'leadTime', 'sustentacao', 'alteracoesPlano', 'impedimentos'];

describe('RetroDataset (retro-data.js) — estrutura e origem', () => {
    it('expõe o DATASET com todas as coleções do design como arrays (Requirement 18.1)', () => {
        const ds = Retro.DATASET;
        expect(Array.isArray(ds.pis)).toBe(true);
        expect(Array.isArray(ds.jornadas)).toBe(true);
        for (const c of COLLECTIONS) {
            expect(Array.isArray(ds[c]), `coleção ausente ou não-array: ${c}`).toBe(true);
        }
        // As coleções com fonte real têm registros.
        for (const c of COLLECTIONS_COM_DADOS) {
            expect(ds[c].length, `coleção deveria ter dados: ${c}`).toBeGreaterThan(0);
        }
        // As coleções sem fonte Jira ficam vazias (painel "Sem dados").
        for (const c of COLLECTIONS_VAZIAS) {
            expect(ds[c].length, `coleção deveria estar vazia: ${c}`).toBe(0);
        }
    });

    it('registra pis, jornadas e source (planilha-manual-jira / futuro Jira) (Requirement 18.5)', () => {
        const ds = Retro.DATASET;
        // Carga real de Velocity: PIs 1–4/26.
        expect(ds.pis).toEqual(['PI 1/26', 'PI 2/26', 'PI 3/26', 'PI 4/26']);
        expect(ds.jornadas).toContain('Atendimento e Serviços');
        expect(ds.source).toEqual({
            current: 'planilha-manual-jira',
            futurePlanned: ['Jira']
        });
    });

    it('é independente do modelo mensal do Score (sem year/records/month)', () => {
        const ds = Retro.DATASET;
        expect(ds).not.toHaveProperty('year');
        expect(ds).not.toHaveProperty('records');
        // Nenhum registro de Retro usa a chave `month`.
        for (const c of COLLECTIONS) {
            for (const r of ds[c]) {
                expect(r).not.toHaveProperty('month');
            }
        }
    });
});

describe('RetroDataset — API de consulta por PI/Time e lista de PIs (Requirement 18.7)', () => {
    it('getPIs retorna a lista de PIs disponíveis e getLatestPI o vigente', () => {
        expect(Retro.getPIs()).toEqual(['PI 1/26', 'PI 2/26', 'PI 3/26', 'PI 4/26']);
        expect(Retro.getLatestPI()).toBe('PI 4/26');
        // getPIs retorna cópia (não permite mutar o dataset).
        const copy = Retro.getPIs();
        copy.push('PI X');
        expect(Retro.getPIs()).toEqual(['PI 1/26', 'PI 2/26', 'PI 3/26', 'PI 4/26']);
    });

    it('expõe os times detalhados (painéis Cycle/Lead/Débito/Sustentação) e as jornadas reais', () => {
        // Painéis detalhados seguem fiéis à planilha de Retro PI de Agosto.
        expect(Retro.getTimes()).toEqual(['Atendimento', 'Kbots', 'Papa-léguas', 'Route 66']);
        // getJornadas expõe as 10 jornadas canônicas (carga real de Velocity/Baselines).
        expect(Retro.getJornadas()).toContain('Atendimento e Serviços');
        expect(Retro.getJornadas().length).toBe(10);
    });

    it('query filtra por PI e por time', () => {
        // Velocity real: 10 jornadas × 6 sprints por PI = 60 linhas de pontosTrabalhados.
        const pt26_3 = Retro.query('pontosTrabalhados', { pi: 'PI 3/26' });
        expect(pt26_3.length).toBe(60);
        expect(pt26_3.every(r => r.pi === 'PI 3/26')).toBe(true);

        // Débito Técnico PI 4/26 (Jira), por jornada: filtro por time/jornada.
        const deb = Retro.query('debito', { pi: 'PI 4/26', time: 'Voomp' });
        expect(deb.length).toBe(1);
        expect(deb[0].time).toBe('Voomp');

        // PI inexistente → vazio.
        expect(Retro.query('debito', { pi: 'PI 9/99' })).toEqual([]);
        // Coleção sem fonte Jira (A1) → vazia em qualquer PI.
        expect(Retro.query('cycleTime', { pi: 'PI 3/26' })).toEqual([]);
        // Coleção inexistente → vazio (sem exceção).
        expect(Retro.query('naoExiste', { pi: 'PI 3/26' })).toEqual([]);
    });

    it('atalhos por painel: carga/débito PI 4/26 com dados; sem fonte Jira ficam vazios', () => {
        // Carga de Trabalho (Jira), por jornada × tipo: 10 × 9 = 90 registros.
        expect(Retro.getCargaTrabalho('PI 4/26').length).toBe(90);
        // Débito Técnico (Jira), por jornada: 10 jornadas.
        expect(Retro.getDebito('PI 4/26').length).toBe(10);
        // Épicos (Jira, visão de hoje): presentes no PI vigente.
        expect(Retro.getEpicos('PI 4/26').length).toBeGreaterThan(0);
        // Sem fonte Jira na coleta A1 → "Sem dados".
        expect(Retro.getCycleTime('PI 4/26').length).toBe(0);
        expect(Retro.getLeadTime('PI 4/26').length).toBe(0);
        expect(Retro.getSustentacao('PI 4/26').length).toBe(0);
        expect(Retro.getAlteracoesPlano('PI 4/26').length).toBe(0);
    });
});

describe('RetroDataset — fidelidade à planilha (oráculo)', () => {
    it('Pontos Trabalhados (Velocity real): Sustentação + Entrega de Valor = total (Sprint SP 26.3.1)', () => {
        const s = Retro.getPontosTrabalhados('PI 3/26', 'Atendimento e Serviços')
            .find(r => r.sprint === 'SP 26.3.1');
        expect(s).toBeTruthy();
        // Velocity 2026 (aba 2026_Produtividade), jornada Atendimento e Serviços, SP 26.3.1.
        expect(s.sustentacao).toBe(44);
        expect(s.entregaValor).toBe(155);
        expect(s.sustentacao + s.entregaValor).toBe(s.total); // 199
    });

    it('Produtividade (real): valores por PI batem com a planilha e a média da Jornada', () => {
        const atend = Retro.getProdutividade().find(p => p.time === 'Atendimento e Serviços');
        expect(atend.porPI['PI 1/26']).toBe(5.75);
        expect(atend.porPI['PI 2/26']).toBe(5.5);
        // Média da Jornada da planilha (linha JORNADA).
        expect(Retro.DATASET.produtividadeMediaJornada['PI 1/26']).toBe(5.7);
    });

    it('Situação dos Épicos: contagem Baseline derivada da lista real de épicos por PI', () => {
        // Épicos agora vêm do Jira (visão de hoje) no PI vigente 4/26.
        const contagem = Retro.getEpicosContagem('PI 4/26');
        const b = contagem.baseline;
        // Estrutura com as 6 situações; valores derivados dos épicos reais.
        expect(Object.keys(b).sort()).toEqual(
            ['atrasado', 'despriorizado', 'em-dia', 'em-risco', 'finalizado', 'replanejado'].sort()
        );
        const soma = Object.values(b).reduce((a, v) => a + v, 0);
        expect(soma).toBeGreaterThan(0);
        // Mudança de Escopo não possui épicos na carga atual.
        const somaME = Object.values(contagem['mudanca-escopo']).reduce((a, v) => a + v, 0);
        expect(somaME).toBe(0);
    });

    it('Cycle Time: sem fonte Jira na coleta A1 → coleção vazia ("Sem dados")', () => {
        expect(Retro.getCycleTime('PI 4/26')).toEqual([]);
        expect(Retro.DATASET.cycleTime).toEqual([]);
        expect(Retro.DATASET.cycleTimeMediaJornada).toEqual({});
    });

    it('Lead Time: sem fonte Jira na coleta A1 → coleção vazia ("Sem dados")', () => {
        expect(Retro.getLeadTime('PI 4/26')).toEqual([]);
        expect(Retro.DATASET.leadTime).toEqual([]);
        expect(Retro.DATASET.leadTimeMediaJornada).toEqual({});
    });

    it('Débito Técnico (PI 4/26 — Jira): backlog por jornada (quantidade)', () => {
        // A1: quantidade de itens "Débito Técnico" criados no PI, por jornada.
        // Sem Story Points (não lidos item a item) → planejadoSP é nulo.
        const voomp = Retro.getDebito('PI 4/26').find(r => r.jornada === 'Voomp');
        expect(voomp.backlogQtde).toBe(57);
        expect(voomp.planejadoQtd).toBe(57);
        expect(voomp.planejadoSP).toBeNull();
        const backlog = Retro.DATASET.debitoBacklogPorTime;
        // 9+9+27+75+78+24+19+57+13+81 = 392 itens de Débito Técnico no PI 4/26.
        const soma = backlog.reduce((a, r) => a + r.backlogQtde, 0);
        expect(soma).toBe(392);
    });

    it('Sustentação: sem fonte Jira na coleta A1 → coleção vazia ("Sem dados")', () => {
        expect(Retro.getSustentacao('PI 4/26')).toEqual([]);
        expect(Retro.DATASET.sustentacao).toEqual([]);
        expect(Retro.DATASET.sustentacaoHistorico).toEqual([]);
    });

    it('Carga de Trabalho (PI 4/26 — Jira): qtde por jornada × tipo', () => {
        // Coleta A1 (Jira, visão de hoje): quantidade real por tipo/jornada,
        // sem Story Points (pontos nulos).
        const voompHist = Retro.getCargaTrabalho('PI 4/26')
            .find(r => r.jornada === 'Voomp' && r.tipoItem === 'História');
        expect(voompHist.qtdeItens).toBe(211);
        expect(voompHist.pontos).toBeNull();
    });

    it('Alterações no Plano: sem fonte Jira na coleta A1 → coleção vazia ("Sem dados")', () => {
        expect(Retro.getAlteracoesPlano('PI 4/26')).toEqual([]);
        expect(Retro.DATASET.alteracoesPlano).toEqual([]);
    });

    it('Impedimentos: sem fonte Jira na coleta A1 → coleção vazia ("Sem dados")', () => {
        expect(Retro.getImpedimentos('PI 4/26')).toEqual([]);
        expect(Retro.DATASET.impedimentos).toEqual([]);
    });
});
