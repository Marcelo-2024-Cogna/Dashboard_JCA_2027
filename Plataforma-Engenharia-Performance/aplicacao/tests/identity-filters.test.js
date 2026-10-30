// Feature: score-performance-executivo
// Testes das Tasks 21 e 22: identificação canônica de Jornada/Squad (identity.js)
// e store de filtros (retro-filters.js). Inclui as Properties 26 e 30 (fast-check).

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';
import fc from 'fast-check';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');
const PEPRetroData = require('../js/retro-data.js');

// Globais para os módulos resolverem dependências como no browser.
function installGlobals() {
    if (typeof globalThis.window === 'undefined') globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.PEPRetroData = PEPRetroData;
}

function loadIdentity() {
    installGlobals();
    const p = require.resolve('../js/identity.js');
    delete require.cache[p];
    const mod = require('../js/identity.js');
    globalThis.PEPIdentity = mod;
    mod.refresh();
    return mod;
}

function loadFilters() {
    installGlobals();
    loadIdentity();
    const p = require.resolve('../js/retro-filters.js');
    delete require.cache[p];
    return require('../js/retro-filters.js');
}

describe('Identificação canônica de Jornada/Squad (identity.js) — Task 21', () => {
    let id;
    beforeEach(() => { id = loadIdentity(); });

    it('resolve a Jornada canônica de um Squad tolerando acento/caixa/espaço', () => {
        // Squad conhecido do Score.
        expect(id.jornadaOf('Colaborar')).toBe('Acadêmica');
        // Tolerância: sem acento, caixa diferente, espaços extras.
        expect(id.jornadaOf('  colaborar ')).toBe('Acadêmica');
        expect(id.canonicalJornada('academica')).toBe('Acadêmica');
    });

    it('lista os squads de uma jornada e todas as jornadas', () => {
        const squads = id.squadsOf('Acadêmica');
        expect(squads).toContain('Colaborar');
        expect(squads.length).toBeGreaterThanOrEqual(2);
        expect(id.jornadas().length).toBe(PEPData.JORNADAS.length);
    });

    it('detecta divergências sem alterar dados (23.5)', () => {
        const div = id.detectDivergences([
            { squad: 'Colaborar', jornada: 'Acadêmica' },   // ok
            { squad: 'Colaborar', jornada: 'Saber' },       // jornadaMismatch
            { squad: 'Time Fantasma', jornada: 'Saber' }    // unknownSquad
        ]);
        const tipos = div.map(d => d.type);
        expect(tipos).toContain('jornadaMismatch');
        expect(tipos).toContain('unknownSquad');
        // O par correto não gera divergência.
        expect(div.length).toBe(2);
    });

    // Property 30: consistência de identificação entre módulos.
    // Feature: score-performance-executivo, Property 30: Para qualquer Squad presente nos dois módulos, a identificação de Jornada e Squad é idêntica entre Retro PI e Operacional/Times; divergências são sinalizadas sem alterar os dados existentes.
    it('Property 30: identificação idêntica para squads conhecidos', () => {
        const squads = [];
        PEPData.JORNADAS.forEach(j => id.squadsOf(j).forEach(s => squads.push({ squad: s, jornada: j })));
        fc.assert(fc.property(fc.constantFrom(...squads), (pair) => {
            // A jornada canônica do squad é exatamente a jornada de origem.
            expect(id.jornadaOf(pair.squad)).toBe(pair.jornada);
            // Sem divergência quando o par está correto.
            expect(id.detectDivergences([pair]).length).toBe(0);
        }), { numRuns: 100 });
    });
});

describe('Store de filtros (retro-filters.js) — Task 22', () => {
    let F;
    beforeEach(() => { F = loadFilters(); F.reset(); });

    it('inicia com todas as dimensões em "all" e toQuery vazio', () => {
        const s = F.getState();
        expect(s).toEqual({ pi: 'all', jornada: 'all', squad: 'all', sprint: 'all' });
        expect(F.toQuery()).toEqual({});
    });

    it('encadeia Jornada→Squad: selecionar jornada restringe os squads (20.2)', () => {
        F.setJornada('Acadêmica');
        const opts = F.squadOptions();
        expect(opts).toContain('Colaborar');
        // Um squad de outra jornada não está nas opções.
        expect(opts).not.toContain('Eagles'); // Financeira
    });

    it('encadeia PI→Sprint: selecionar PI restringe as sprints (20.3)', () => {
        F.setPI('PI 3/26');
        const opts = F.sprintOptions();
        // Só sprints do PI 3/26.
        expect(opts.every(s => s === 'all' || /26\.3\./.test(s))).toBe(true);
        expect(opts).toContain('SP 26.3.1');
    });

    it('reseta o squad quando a nova jornada não o contém (encadeamento)', () => {
        F.setJornada('Acadêmica');
        F.setSquad('Colaborar');
        expect(F.getState().squad).toBe('Colaborar');
        F.setJornada('Financeira'); // Colaborar não é de Financeira
        expect(F.getState().squad).toBe('all');
    });

    it('notifica subscribers ao mudar e toQuery omite "all" (20.5)', () => {
        let calls = 0;
        F.subscribe(() => { calls++; });
        F.setPI('PI 2/26');
        F.setJornada('Saber');
        expect(calls).toBe(2);
        expect(F.toQuery()).toEqual({ pi: 'PI 2/26', jornada: 'Saber' });
    });

    // Property 26: encadeamento e agregação dos filtros.
    // Feature: score-performance-executivo, Property 26: Para qualquer combinação de filtros, selecionar uma Jornada restringe os Squads aos daquela Jornada e selecionar um PI restringe as Sprints às daquele PI; "Todos" numa dimensão agrega exatamente o conjunto de todos os valores válidos daquela dimensão.
    it('Property 26: encadeamento e agregação consistentes', () => {
        const id = globalThis.PEPIdentity;
        fc.assert(fc.property(
            fc.constantFrom('all', ...id.jornadas()),
            fc.constantFrom('all', ...PEPRetroData.getPIs()),
            (jornada, pi) => {
                F.reset();
                F.setJornada(jornada);
                F.setPI(pi);
                // Squads: quando jornada != all, todos pertencem àquela jornada.
                const squads = F.squadOptions().filter(s => s !== 'all');
                if (jornada !== 'all') {
                    squads.forEach(s => expect(id.jornadaOf(s)).toBe(id.canonicalJornada(jornada)));
                }
                // Sprints: quando pi != all, todas pertencem ao PI.
                const sprints = F.sprintOptions().filter(s => s !== 'all');
                if (pi !== 'all') {
                    const regs = PEPRetroData.query('pontosTrabalhados', { pi });
                    const validas = new Set(regs.map(r => r.sprint));
                    sprints.forEach(s => expect(validas.has(s)).toBe(true));
                }
            }
        ), { numRuns: 100 });
    });
});
