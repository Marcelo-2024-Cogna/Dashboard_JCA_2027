// Feature: score-performance-executivo
// Testes do store do Fluxo de Exceção com aprovação (Task 32, Req 30).
// Cobrem: criação de pendência (30.1, 30.2), validação de campos obrigatórios
// (30.8), aprovação aplica efeito / rejeição não (30.4, 30.5, 30.6), histórico
// e persistência em localStorage (30.9, 30.10).
//
// O app é estático; aqui usamos um stub de localStorage em memória e recarregamos
// o módulo (IIFE exposto via guarda de ambiente) para isolar cada teste.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Stub de localStorage em memória COMPARTILHADO entre reloads do módulo (para
// validar a persistência: um reload lê o que o anterior gravou).
let mem;
function installStorage() {
    mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

function loadStore() {
    const p = require.resolve('../js/excecoes-store.js');
    delete require.cache[p];
    return require('../js/excecoes-store.js');
}

const validInput = (over = {}) => ({
    squad: 'Colaborar',
    indicador: 'Cycle Time Desenvolvimento',
    justificativa: 'Incidente externo impactou a entrega.',
    periodo: 'Ago/2026',
    solicitante: 'tm',
    ...over
});

describe('ExcecoesStore — criação e validação', () => {
    let Store;
    beforeEach(() => {
        installStorage();
        Store = loadStore();
        Store.clear();
    });

    it('solicitação válida cria pendência no estado "pendente" (30.1, 30.2)', () => {
        const rec = Store.request(validInput());
        expect(rec.status).toBe('pendente');
        expect(rec.squad).toBe('Colaborar');
        expect(rec.indicador).toBe('Cycle Time Desenvolvimento');
        expect(rec.id).toBeTruthy();

        expect(Store.pending()).toHaveLength(1);
        expect(Store.approved()).toHaveLength(0);
        // Histórico inicial registra o evento 'solicitada' (30.10).
        expect(rec.historico[0]).toMatchObject({ estado: 'pendente', acao: 'solicitada' });
    });

    it('rejeita quando faltam campos obrigatórios, com mensagem descritiva (30.8)', () => {
        const errs = Store.validate({ squad: '', indicador: '', justificativa: '', periodo: '' });
        expect(errs).toHaveLength(4);
        expect(errs.map((e) => e.field).sort()).toEqual(['indicador', 'justificativa', 'periodo', 'squad']);
        errs.forEach((e) => expect(e.message).toMatch(/obrigatório/i));

        // request() também barra e lança com mensagem; nada é criado.
        expect(() => Store.request(validInput({ justificativa: '   ' }))).toThrow(/Justificativa/i);
        expect(Store.list()).toHaveLength(0);
    });

    it('validação parcial aponta apenas os campos faltantes', () => {
        const errs = Store.validate(validInput({ periodo: '' }));
        expect(errs).toHaveLength(1);
        expect(errs[0].field).toBe('periodo');
    });
});

describe('ExcecoesStore — aprovação e rejeição', () => {
    let Store;
    beforeEach(() => {
        installStorage();
        Store = loadStore();
        Store.clear();
    });

    it('aprovar marca "aprovada" e aplica efeito (sinaliza indicador) (30.3, 30.4)', () => {
        const rec = Store.request(validInput());
        // Antes de aprovar: pendente NÃO aplica efeito (30.5).
        expect(Store.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento')).toBe(false);

        const approved = Store.approve(rec.id, 'dm');
        expect(approved.status).toBe('aprovada');
        expect(approved.decididoPor).toBe('dm');
        expect(approved.decididoEm).toBeTruthy();

        // Efeito aplicado SOMENTE quando aprovada (30.4).
        expect(Store.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento')).toBe(true);
        // Período casa quando fornecido.
        expect(Store.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento', 'Ago/2026')).toBe(true);
        expect(Store.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento', 'Jan/2026')).toBe(false);
        // Sai da fila de pendências.
        expect(Store.pending()).toHaveLength(0);
        // Histórico com transição 'aprovada' (30.10).
        expect(approved.historico.some((h) => h.acao === 'aprovada' && h.por === 'dm')).toBe(true);
    });

    it('rejeitar marca "rejeitada" com motivo e NÃO aplica efeito (30.6)', () => {
        const rec = Store.request(validInput());
        const rejected = Store.reject(rec.id, 'admin', 'Justificativa insuficiente.');
        expect(rejected.status).toBe('rejeitada');
        expect(rejected.motivoRejeicao).toBe('Justificativa insuficiente.');
        expect(rejected.decididoPor).toBe('admin');

        // Rejeição não sinaliza o indicador (30.6).
        expect(Store.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento')).toBe(false);
        expect(Store.approved()).toHaveLength(0);
        expect(Store.pending()).toHaveLength(0);
        expect(Store.rejected()).toHaveLength(1);
    });

    it('rejeição exige motivo (30.3)', () => {
        const rec = Store.request(validInput());
        expect(() => Store.reject(rec.id, 'dm', '  ')).toThrow(/motivo/i);
        // Permanece pendente após falha.
        expect(Store.pending()).toHaveLength(1);
    });

    it('somente pendentes transicionam (não re-aprova/re-rejeita)', () => {
        const rec = Store.request(validInput());
        Store.approve(rec.id, 'dm');
        expect(() => Store.approve(rec.id, 'dm')).toThrow();
        expect(() => Store.reject(rec.id, 'dm', 'x')).toThrow();
    });

    it('approvedFor lista as exceções aprovadas de um squad por período', () => {
        const a = Store.request(validInput({ indicador: 'Disponibilidade' }));
        const b = Store.request(validInput({ indicador: 'Team Health Check' }));
        Store.approve(a.id, 'dm');
        // b fica pendente → não entra em approvedFor.
        const list = Store.approvedFor('Colaborar', 'Ago/2026');
        expect(list).toHaveLength(1);
        expect(list[0].indicador).toBe('Disponibilidade');
    });
});

describe('ExcecoesStore — persistência e histórico (30.9, 30.10)', () => {
    let Store;
    beforeEach(() => {
        installStorage();
        Store = loadStore();
        Store.clear();
    });

    it('persiste em localStorage e sobrevive a um reload do módulo', () => {
        const rec = Store.request(validInput());
        Store.approve(rec.id, 'dm');

        // O storage em memória guarda os dados sob a chave do store.
        expect(mem.get(Store.STORAGE_KEY)).toBeTruthy();

        // Recarrega o módulo: deve reidratar do localStorage (sem clear()).
        const Reloaded = loadStore();
        const all = Reloaded.list();
        expect(all).toHaveLength(1);
        expect(all[0].status).toBe('aprovada');
        expect(Reloaded.hasApprovedException('Colaborar', 'Cycle Time Desenvolvimento')).toBe(true);
    });

    it('mantém o histórico completo (solicitante, decisor, estados, datas)', () => {
        const rec = Store.request(validInput());
        const rejected = Store.reject(rec.id, 'admin', 'motivo X');
        const h = rejected.historico;
        expect(h).toHaveLength(2);
        expect(h[0]).toMatchObject({ estado: 'pendente', acao: 'solicitada', por: 'tm' });
        expect(h[1]).toMatchObject({ estado: 'rejeitada', acao: 'rejeitada', por: 'admin', motivo: 'motivo X' });
        h.forEach((ev) => expect(typeof ev.em).toBe('string'));
    });

    it('expõe a API pública e os estados', () => {
        expect(Store.STATUS).toMatchObject({ PENDENTE: 'pendente', APROVADA: 'aprovada', REJEITADA: 'rejeitada' });
        ['validate', 'request', 'approve', 'reject', 'pending', 'approved', 'hasApprovedException', 'subscribe']
            .forEach((fn) => expect(typeof Store[fn]).toBe('function'));
    });
});
