// Feature: score-performance-executivo
// Testes do RBAC (Task 27). Properties 32, 33, 34 + exemplo da matriz validada.
// A matriz é a FONTE ÚNICA de permissões (nega por omissão). Os testes de
// exemplo usam a tabela validada com o usuário como oráculo.

import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';
import fc from 'fast-check';

const require = createRequire(import.meta.url);
const Rbac = require('../js/rbac.js');

// Oráculo: matriz validada (Perfil → telas permitidas).
const ORACLE = {
    admin: ['execGeral', 'execSquad', 'detJornada', 'detSquad', 'apresentacao', 'excecao', 'gestPerfis', 'gestTribosSquads', 'cfgParametrizacao', 'cfgIntegracao'],
    dm:    ['execGeral', 'execSquad', 'detJornada', 'detSquad', 'apresentacao', 'excecao', 'gestPerfis', 'gestTribosSquads', 'cfgParametrizacao', 'cfgIntegracao'],
    tm:    ['execGeral', 'detJornada', 'detSquad', 'apresentacao', 'excecao'],
    gpm:   ['execGeral', 'detJornada', 'detSquad', 'apresentacao'],
    tl:    ['execSquad', 'detJornada', 'detSquad']
};

describe('RBAC — matriz validada (exemplo/oráculo)', () => {
    it('Admin e DM têm permissões idênticas (acesso total)', () => {
        expect(Rbac.allowedScreens('admin').sort()).toEqual(Rbac.allowedScreens('dm').sort());
        expect(Rbac.allowedScreens('admin').sort()).toEqual([...Rbac.ALL_SCREENS].sort());
    });

    it('cada perfil vê exatamente o conjunto esperado de telas', () => {
        for (const profile of Rbac.PROFILES) {
            expect(Rbac.allowedScreens(profile).sort()).toEqual([...ORACLE[profile]].sort());
        }
    });

    it('exceção: TM/Admin/DM solicitam; só Admin/DM aprovam', () => {
        expect(Rbac.canAction('tm', 'excecao:solicitar')).toBe(true);
        expect(Rbac.canAction('admin', 'excecao:solicitar')).toBe(true);
        expect(Rbac.canAction('dm', 'excecao:solicitar')).toBe(true);
        expect(Rbac.canAction('gpm', 'excecao:solicitar')).toBe(false);
        expect(Rbac.canAction('tl', 'excecao:solicitar')).toBe(false);

        expect(Rbac.canAction('admin', 'excecao:aprovar')).toBe(true);
        expect(Rbac.canAction('dm', 'excecao:aprovar')).toBe(true);
        expect(Rbac.canAction('tm', 'excecao:aprovar')).toBe(false);
    });

    it('somente Admin e DM têm telas de gestão; TM, GPM e TL não têm gestão alguma', () => {
        // Gestão restante é exclusiva de Admin/DM (perfis e tribos/squads).
        expect(Rbac.can('admin', 'gestPerfis')).toBe(true);
        expect(Rbac.can('admin', 'gestTribosSquads')).toBe(true);
        ['tm', 'gpm', 'tl'].forEach((p) => {
            expect(Rbac.menuFor(p).gestao.length).toBe(0);
        });
    });

    it('home: Admin/DM/TM/GPM = execGeral; TL = execSquad', () => {
        expect(Rbac.homeView('admin')).toBe('execGeral');
        expect(Rbac.homeView('dm')).toBe('execGeral');
        expect(Rbac.homeView('tm')).toBe('execGeral');
        expect(Rbac.homeView('gpm')).toBe('execGeral');
        expect(Rbac.homeView('tl')).toBe('execSquad');
    });
});

// Geradores: perfis válidos e ids de tela (válidos e inválidos).
const genProfile = fc.constantFrom(...Rbac.PROFILES);
const genScreen = fc.constantFrom(...Rbac.ALL_SCREENS);
const genAnyId = fc.oneof(genScreen, fc.string());

describe('Property 32: Nega por omissão', () => {
    // Feature: score-performance-executivo, Property 32: Para qualquer perfil e qualquer screenId não listado como permitido na matriz, Rbac.can(perfil, screenId) retorna falso.
    it('qualquer tela não listada é negada', () => {
        fc.assert(fc.property(genProfile, genAnyId, (profile, screenId) => {
            const permitido = ORACLE[profile].includes(screenId);
            expect(Rbac.can(profile, screenId)).toBe(permitido);
        }), { numRuns: 200 });
    });
});

describe('Property 33: Menu reflete exatamente a matriz', () => {
    // Feature: score-performance-executivo, Property 33: Para qualquer perfil, o conjunto de telas listadas por menuFor (dashboards ∪ gestão) é exatamente o conjunto de telas em que can(perfil, tela) é verdadeiro.
    it('menuFor = telas permitidas', () => {
        fc.assert(fc.property(genProfile, (profile) => {
            const menu = Rbac.menuFor(profile);
            const noMenu = [...menu.dashboards, ...menu.gestao].map(e => e.id).sort();
            const permitidas = Rbac.allowedScreens(profile).sort();
            expect(noMenu).toEqual(permitidas);
        }), { numRuns: 100 });
    });
});

describe('Property 34: Tela inicial é sempre permitida ao perfil', () => {
    // Feature: score-performance-executivo, Property 34: Para qualquer perfil, homeView(perfil) retorna uma tela para a qual can(perfil, homeView(perfil)) é verdadeiro.
    it('home sempre acessível', () => {
        fc.assert(fc.property(genProfile, (profile) => {
            const home = Rbac.homeView(profile);
            expect(Rbac.can(profile, home)).toBe(true);
        }), { numRuns: 100 });
    });
});
