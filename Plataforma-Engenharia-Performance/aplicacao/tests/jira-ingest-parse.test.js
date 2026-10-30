// Feature: score-performance-executivo
// Subtask 23.1 — Testes de exemplo do parser e validação da extração do Jira
// (parseJira CSV/JSON + validateItem). Requirements 21.1, 21.2, 21.4, 21.5.

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const JiraIngest = require('../js/jira-ingest.js');

describe('parseJira — CSV', () => {
    it('normaliza itens válidos (separador ";") para o shape JiraItem', () => {
        const csv = [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5'
        ].join('\n');
        const { items, errors } = JiraIngest.parseJira(csv, 'csv');
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0]).toMatchObject({
            chave: 'ASAT-1',
            tipoItem: 'História',
            status: 'Concluído',
            jornada: 'Atendimento e Serviços',
            squad: 'Atendimento',
            sprint: 'SP 26.3.1',
            pi: 'PI 3/26',
            storyPoints: 5
        });
    });

    it('aceita separador "," e detecta o formato automaticamente', () => {
        const csv = [
            'chave,tipoItem,status,jornada,squad,sprint,pi,storyPoints',
            'ASKB-2,Enabler,Em andamento,Atendimento e Serviços,Kbots,SP 26.3.2,PI 3/26,3'
        ].join('\n');
        const { items, errors } = JiraIngest.parseJira(csv);
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0].chave).toBe('ASKB-2');
        expect(items[0].storyPoints).toBe(3);
    });

    it('aceita cabeçalhos alternativos do Jira (key / issue type / story points)', () => {
        const csv = [
            'Issue key;Issue Type;Status;Jornada;Squad;Sprint;PI;Story Points',
            'ARTPT-3;Spike;Concluído;Atendimento e Serviços;Papa-léguas;SP 26.3.1;PI 3/26;2'
        ].join('\n');
        const { items, errors } = JiraIngest.parseJira(csv, 'csv');
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0].chave).toBe('ARTPT-3');
        expect(items[0].tipoItem).toBe('Spike');
    });

    it('interpreta decimais com vírgula e com ponto em storyPoints', () => {
        const csvVirgula = [
            'chave;tipoItem;sprint;pi;storyPoints',
            'ASAT-10;História;SP 26.3.1;PI 3/26;2,5'
        ].join('\n');
        const r1 = JiraIngest.parseJira(csvVirgula, 'csv');
        expect(r1.errors.length).toBe(0);
        expect(r1.items[0].storyPoints).toBe(2.5);

        const csvPonto = [
            'chave;tipoItem;sprint;pi;storyPoints',
            'ASAT-11;História;SP 26.3.1;PI 3/26;2.5'
        ].join('\n');
        const r2 = JiraIngest.parseJira(csvPonto, 'csv');
        expect(r2.errors.length).toBe(0);
        expect(r2.items[0].storyPoints).toBe(2.5);
    });

    it('trata BOM (UTF-8) no início do arquivo sem corromper o cabeçalho', () => {
        const csv = '\uFEFF' + [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASR6-4;Débito Técnico;Concluído;Atendimento e Serviços;Route 66;SP 26.3.3;PI 3/26;1'
        ].join('\n');
        const { items, errors } = JiraIngest.parseJira(csv, 'csv');
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0].chave).toBe('ASR6-4');
    });

    it('rejeita item incompleto com mensagem descritiva nomeando os campos ausentes e importa os válidos', () => {
        const csv = [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5', // válido
            'ASKB-2;Enabler;Concluído;Atendimento e Serviços;Kbots;SP 26.3.2;;3' // inválido (sem PI)
        ].join('\n');
        const { items, errors } = JiraIngest.parseJira(csv, 'csv');
        expect(items.length).toBe(1);
        expect(items[0].chave).toBe('ASAT-1');
        expect(errors.length).toBe(1);
        expect(errors[0].chave).toBe('ASKB-2');
        expect(errors[0].motivo).toMatch(/PI/);
        expect(errors[0].motivo).toMatch(/obrigat/i);
    });
});

describe('parseJira — JSON', () => {
    it('normaliza array de objetos para o shape JiraItem', () => {
        const json = JSON.stringify([
            { chave: 'ASAT-1', tipoItem: 'História', status: 'Concluído', jornada: 'Atendimento e Serviços', squad: 'Atendimento', sprint: 'SP 26.3.1', pi: 'PI 3/26', storyPoints: 8 }
        ]);
        const { items, errors } = JiraIngest.parseJira(json, 'json');
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0].storyPoints).toBe(8);
        expect(items[0].sprint).toBe('SP 26.3.1');
    });

    it('aceita objeto com propriedade "items" e detecta JSON automaticamente', () => {
        const json = JSON.stringify({ items: [
            { chave: 'ASKB-2', tipoItem: 'Enabler', sprint: 'SP 26.3.2', pi: 'PI 3/26', storyPoints: 3 }
        ]});
        const { items, errors } = JiraIngest.parseJira(json);
        expect(errors.length).toBe(0);
        expect(items.length).toBe(1);
        expect(items[0].chave).toBe('ASKB-2');
    });

    it('retorna erro descritivo para JSON inválido', () => {
        const { items, errors } = JiraIngest.parseJira('{ invalido', 'json');
        expect(items.length).toBe(0);
        expect(errors.length).toBe(1);
        expect(errors[0].motivo).toMatch(/JSON inválido/i);
    });
});

describe('validateItem — validação por campo (21.4/21.5)', () => {
    const base = { chave: 'ASAT-1', tipoItem: 'História', sprint: 'SP 26.3.1', pi: 'PI 3/26', storyPoints: 5 };

    it('aceita item com todos os campos mínimos', () => {
        const r = JiraIngest.validateItem(base);
        expect(r.valid).toBe(true);
        expect(r.errors).toEqual([]);
    });

    it('lista todos os campos obrigatórios ausentes com mensagem descritiva', () => {
        const r = JiraIngest.validateItem({ chave: '', tipoItem: '', sprint: '', pi: '' });
        expect(r.valid).toBe(false);
        expect(r.errors.length).toBe(4);
        expect(r.errors.join(' ')).toMatch(/chave do item/);
        expect(r.errors.join(' ')).toMatch(/tipo de item/);
        expect(r.errors.join(' ')).toMatch(/Sprint/);
        expect(r.errors.join(' ')).toMatch(/PI/);
    });

    it('rejeita storyPoints negativo e não numérico', () => {
        const neg = JiraIngest.validateItem({ ...base, storyPoints: -1 });
        expect(neg.valid).toBe(false);
        expect(neg.errors.join(' ')).toMatch(/negativo/i);

        const nan = JiraIngest.validateItem({ ...base, storyPoints: Number('abc') });
        expect(nan.valid).toBe(false);
        expect(nan.errors.join(' ')).toMatch(/número/i);
    });

    it('aceita storyPoints ausente (opcional)', () => {
        const r = JiraIngest.validateItem({ ...base, storyPoints: null });
        expect(r.valid).toBe(true);
    });
});

describe('sourceInfo — nota de origem (21.5)', () => {
    it('registra extração manual como fonte atual e coleta automática como futura', () => {
        const s = JiraIngest.sourceInfo();
        expect(s.current).toBe('extracao-jira-manual');
        expect(Array.isArray(s.futurePlanned)).toBe(true);
        expect(s.futurePlanned.join(' ')).toMatch(/backend/i);
    });
});
