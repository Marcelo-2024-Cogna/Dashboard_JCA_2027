// Feature: score-performance-executivo
// Testa o Perfil no AppState (Task 28). Property 36: trocar o Perfil não altera
// o Mês Selecionado nem o PI Selecionado (independência total).

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';
import fc from 'fast-check';

const require = createRequire(import.meta.url);

// localStorage stub em memória (o AppState usa store defensivo).
function installStorage() {
    const mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

const PROFILES = ['admin', 'dm', 'tm', 'gpm', 'tl'];

function loadAppState() {
    const p = require.resolve('../js/app-state.js');
    delete require.cache[p];
    return require('../js/app-state.js');
}

describe('Perfil no AppState (Task 28)', () => {
    let AppState;
    beforeEach(() => {
        installStorage();
        AppState = loadAppState();
    });

    it('default do perfil é admin', () => {
        expect(AppState.getProfile()).toBe('admin');
    });

    it('setProfile persiste e valida; perfil inválido lança', () => {
        AppState.setProfile('tl');
        expect(AppState.getProfile()).toBe('tl');
        expect(() => AppState.setProfile('xpto')).toThrow();
    });
});

describe('Property 36: Perfil não altera Mês nem PI', () => {
    // Feature: score-performance-executivo, Property 36: Para qualquer sequência de trocas de Perfil, o Mês Selecionado e o PI Selecionado permanecem inalterados.
    it('qualquer sequência de setProfile mantém mês e PI', () => {
        fc.assert(fc.property(
            fc.integer({ min: 1, max: 12 }),
            fc.array(fc.constantFrom(...PROFILES), { minLength: 0, maxLength: 30 }),
            (mes, perfis) => {
                installStorage();
                const AppState = loadAppState();

                AppState.setSelectedMonth(mes);
                const piInicial = AppState.getSelectedPI();
                const mesInicial = AppState.getSelectedMonth();

                for (const p of perfis) {
                    AppState.setProfile(p);
                    // Mês e PI permanecem inalterados a cada troca de perfil.
                    expect(AppState.getSelectedMonth()).toBe(mesInicial);
                    expect(AppState.getSelectedPI()).toBe(piInicial);
                }
                // Perfil final reflete a última troca (quando houve).
                if (perfis.length) {
                    expect(AppState.getProfile()).toBe(perfis[perfis.length - 1]);
                }
            }
        ), { numRuns: 100 });
    });
});
