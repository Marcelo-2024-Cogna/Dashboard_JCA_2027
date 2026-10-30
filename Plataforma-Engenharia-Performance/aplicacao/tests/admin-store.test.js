// Feature: score-performance-executivo
// Testes de EXEMPLO (unitários) do store de gestão `admin-store.js` (Subtask
// 33.1). Cobrem o CRUD client-side por coleção, a persistência defensiva, a
// defesa contra credenciais sensíveis (Req 31.7) e casos de borda do
// import/export reversível que complementam o teste de propriedade (Property 37).
//
// _Requirements: 31.2, 31.3, 31.7, 31.8_
// Nota: as coleções 'usuarios', 'kpis', 'okrs' e 'comunicacao' foram removidas
// do produto; os testes abaixo usam apenas as coleções remanescentes
// (perfis, tribosSquads, integracao).

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const AdminStore = require('../js/admin-store.js');

beforeEach(() => {
    AdminStore.clear();
});

describe('AdminStore — CRUD por coleção', () => {
    it('create gera id estável e list devolve o item (perfis)', () => {
        const u = AdminStore.create('perfis', { nome: 'Analista', permissoes: ['execGeral'] });
        expect(u.id).toBeTruthy();
        expect(u.nome).toBe('Analista');
        const all = AdminStore.list('perfis');
        expect(all.length).toBe(1);
        expect(all[0].id).toBe(u.id);
    });

    it('create preserva um id fornecido pelo chamador', () => {
        const u = AdminStore.create('perfis', { id: 'p-1', nome: 'Gestor' });
        expect(u.id).toBe('p-1');
        expect(AdminStore.get('perfis', 'p-1').nome).toBe('Gestor');
    });

    it('update mescla o patch mantendo o id e re-normaliza', () => {
        const t = AdminStore.create('tribosSquads', { tribo: 'Atendimento', squad: 'Kbots' });
        const up = AdminStore.update('tribosSquads', t.id, { squad: 'Route 66' });
        expect(up.id).toBe(t.id);
        expect(up.squad).toBe('Route 66');
        expect(up.tribo).toBe('Atendimento'); // campo não alterado preservado
    });

    it('update retorna null para id inexistente', () => {
        expect(AdminStore.update('perfis', 'nope', { nome: 'X' })).toBeNull();
    });

    it('remove exclui o item e retorna boolean', () => {
        const o = AdminStore.create('tribosSquads', { tribo: 'T', squad: 'S' });
        expect(AdminStore.remove('tribosSquads', o.id)).toBe(true);
        expect(AdminStore.list('tribosSquads').length).toBe(0);
        expect(AdminStore.remove('tribosSquads', o.id)).toBe(false);
    });

    it('coleção desconhecida lança erro descritivo', () => {
        expect(() => AdminStore.list('inexistente')).toThrow(/desconhecida/i);
    });
});

describe('AdminStore — defesa contra credenciais sensíveis (Req 31.7)', () => {
    it('não persiste token/senha/apiKey em integração', () => {
        const i = AdminStore.create('integracao', {
            sistema: 'Jira',
            projetoJira: 'ABC',
            jornada: 'Atendimento',
            squad: 'Kbots',
            token: 'segredo-123',
            senha: 'p@ss',
            apiKey: 'k-xyz'
        });
        expect(i.token).toBeUndefined();
        expect(i.senha).toBeUndefined();
        expect(i.apiKey).toBeUndefined();
        expect(i.projetoJira).toBe('ABC');

        // E também não aparece no CSV/JSON exportados.
        const csv = AdminStore.toCSV('integracao');
        expect(csv.toLowerCase()).not.toContain('segredo-123');
        expect(csv.toLowerCase()).not.toContain('token');
        const json = AdminStore.toJSON('integracao');
        expect(json.toLowerCase()).not.toContain('segredo-123');
    });

    it('update também remove chaves sensíveis', () => {
        const i = AdminStore.create('integracao', { sistema: 'Jira', projetoJira: 'ABC' });
        const up = AdminStore.update('integracao', i.id, { squad: 'Route 66', token: 'novo-segredo' });
        expect(up.token).toBeUndefined();
        expect(up.squad).toBe('Route 66');
    });
});

describe('AdminStore — import/export reversível (casos de borda)', () => {
    it('perfis: lista de permissões sobrevive ao round-trip CSV', () => {
        AdminStore.create('perfis', { nome: 'Admin', permissoes: ['execGeral', 'gestTribosSquads', 'gestPerfis'] });
        const csv = AdminStore.toCSV('perfis');
        const { imported, rejected, records } = AdminStore.fromCSV('perfis', csv);
        expect(rejected.length).toBe(0);
        expect(imported).toBe(1);
        expect(records[0].permissoes).toEqual(['execGeral', 'gestTribosSquads', 'gestPerfis']);
    });

    it('tribosSquads: texto sobrevive ao round-trip CSV', () => {
        AdminStore.create('tribosSquads', { tribo: 'Atendimento', squad: 'Kbots' });
        AdminStore.create('tribosSquads', { tribo: 'Financeira', squad: 'Pagamentos' });
        const back = AdminStore.fromCSV('tribosSquads', AdminStore.toCSV('tribosSquads')).records;
        expect(back.map(r => r.squad)).toEqual(['Kbots', 'Pagamentos']);
    });

    it('fromCSV com cabeçalho faltando colunas é rejeitado', () => {
        const csv = '\uFEFF' + ['id;tribo', 't1;Atendimento'].join('\r\n');
        const res = AdminStore.fromCSV('tribosSquads', csv);
        expect(res.imported).toBe(0);
        expect(res.rejected.length).toBe(1);
        expect(res.rejected[0].errors[0]).toMatch(/Cabeçalho/i);
    });

    it('fromJSON aceita array puro e { collection, records }', () => {
        const r1 = AdminStore.fromJSON('tribosSquads', JSON.stringify([{ id: 't1', tribo: 'Atendimento', squad: 'Kbots' }]));
        expect(r1.imported).toBe(1);
        const r2 = AdminStore.fromJSON('tribosSquads', JSON.stringify({ collection: 'tribosSquads', records: [{ id: 't2', tribo: 'Financeira' }] }));
        expect(r2.imported).toBe(1);
        expect(AdminStore.list('tribosSquads').length).toBe(1); // fromJSON substitui a coleção
    });

    it('toCSV sem itens produz apenas o cabeçalho canônico', () => {
        const csv = AdminStore.toCSV('tribosSquads');
        const linhas = csv.replace(/^\uFEFF/, '').split('\r\n');
        expect(linhas.length).toBe(1);
        expect(linhas[0]).toBe('id;tribo;squad');
    });
});
