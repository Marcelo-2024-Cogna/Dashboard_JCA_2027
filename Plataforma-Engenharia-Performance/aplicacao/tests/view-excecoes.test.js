// Feature: score-performance-executivo
// Testes de integração/UI do Fluxo de Exceção (Task 32, Req 30), com stub de
// DOM (sem jsdom). Cobrem:
//   - solicitação cria pendência (30.1, 30.2);
//   - aprovação aplica efeito e rejeição não (30.4, 30.6);
//   - validação de campos obrigatórios (30.8);
//   - TM NÃO vê o botão de aprovar (30.3).
//
// Reutiliza os módulos reais (rbac, app-state, identity, scoring-engine, data) e
// o store de exceções. O perfil é controlado por AppState.setProfile.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// ---- localStorage em memória (store + app-state usam store defensivo) ----
function installStorage() {
    const mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

// -----------------------------------------------------------------------------
// Stub de DOM mínimo: um "elemento" com innerHTML, querySelector baseado em
// marcação de atributos/ids e suporte a addEventListener/dispatch para simular
// o submit do formulário e os cliques de aprovar/rejeitar.
//
// A view re-renderiza substituindo innerHTML do MESMO container e re-cabeando os
// listeners; então o stub resolve os elementos a partir do innerHTML corrente.
// -----------------------------------------------------------------------------
function makeElement(id) {
    const listeners = {};
    const el = {
        id,
        value: '',
        textContent: '',
        className: '',
        _attrs: {},
        getAttribute(name) { return this._attrs[name] != null ? this._attrs[name] : null; },
        setAttribute(name, v) { this._attrs[name] = v; },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        dispatch(type, evt) { (listeners[type] || []).forEach((fn) => fn(evt || {})); },
        _listeners: listeners
    };
    return el;
}

function makeContainer() {
    // Elementos "vivos" resolvidos sob demanda; mantemos uma cache por seletor
    // para preservar os listeners entre chamadas de querySelector no mesmo render.
    const container = {
        innerHTML: '',
        _cache: {},
        _reset() { this._cache = {}; },
        querySelector(sel) {
            // Resolve apenas os seletores que a view usa.
            const idSelectors = ['#exc-form', '#exc-fila', '#exc-squad', '#exc-indicador',
                '#exc-periodo', '#exc-justificativa', '#exc-form-msg'];
            if (idSelectors.includes(sel)) {
                const idAttr = `id="${sel.slice(1)}"`;
                if (!this.innerHTML.includes(idAttr)) return null;
                if (!this._cache[sel]) this._cache[sel] = makeElement(sel.slice(1));
                return this._cache[sel];
            }
            return null;
        }
    };
    return container;
}

// Carrega o store e a view (reset de estado interno/registro).
function loadStore() {
    const p = require.resolve('../js/excecoes-store.js');
    delete require.cache[p];
    return require('../js/excecoes-store.js');
}
function loadView() {
    const p = require.resolve('../js/views/view-excecoes.js');
    delete require.cache[p];
    return require('../js/views/view-excecoes.js');
}

const AppState = require('../js/app-state.js');
const PEPIdentity = require('../js/identity.js');

// Squad canônico conhecido para o teste.
const SQUAD = PEPIdentity.squadsOf(PEPIdentity.jornadas()[0])[0];

// Preenche o formulário no container (stub) com valores válidos.
function fillForm(container, over = {}) {
    const set = (sel, v) => { const el = container.querySelector(sel); if (el) el.value = v; };
    set('#exc-squad', over.squad != null ? over.squad : SQUAD);
    set('#exc-indicador', over.indicador != null ? over.indicador : 'Cycle Time Desenvolvimento');
    set('#exc-periodo', over.periodo != null ? over.periodo : 'Ago/2026');
    set('#exc-justificativa', over.justificativa != null ? over.justificativa : 'Motivo justificado.');
}

describe('view-excecoes — solicitação cria pendência (30.1, 30.2)', () => {
    let Store, view;
    beforeEach(() => {
        installStorage();
        Store = loadStore();
        Store.clear();
        AppState.setProfile('tm'); // TM pode solicitar
        view = loadView();
    });

    it('submit com campos válidos cria uma pendência e re-renderiza a fila', () => {
        const container = makeContainer();
        view.render(container, { month: 8 });

        // O formulário de solicitação está presente para TM (30.7/30.1).
        expect(container.innerHTML).toContain('id="exc-form"');
        expect(container.innerHTML).toContain('Nova solicitação de exceção');

        // Preenche e submete (os valores ficam na cache de elementos do stub,
        // que o handler lê via querySelector no mesmo render).
        fillForm(container);
        const form = container.querySelector('#exc-form');
        form.dispatch('submit', { preventDefault() {} });

        // Store recebeu a pendência.
        const pend = Store.pending();
        expect(pend).toHaveLength(1);
        expect(pend[0].status).toBe('pendente');
        expect(pend[0].squad).toBe(SQUAD);

        // A fila re-renderizada mostra a solicitação.
        expect(container.innerHTML).toContain('Pendente de aprovação');
        expect(container.innerHTML).toContain(SQUAD);
    });

    it('validação de campos obrigatórios bloqueia e mostra mensagem (30.8)', () => {
        const container = makeContainer();
        view.render(container, { month: 8 });

        // Submete sem preencher.
        const form = container.querySelector('#exc-form');
        form.dispatch('submit', { preventDefault() {} });

        // Nada foi criado e a mensagem de erro foi exibida.
        expect(Store.list()).toHaveLength(0);
        const msg = container.querySelector('#exc-form-msg');
        expect(msg.textContent).toMatch(/obrigatório/i);
        expect(msg.className).toContain('exc-msg-error');
    });
});

describe('view-excecoes — permissões por perfil (30.3)', () => {
    beforeEach(() => {
        installStorage();
        const Store = loadStore();
        Store.clear();
    });

    it('TM NÃO vê o botão de aprovar (apenas a fila em leitura)', () => {
        const Store = loadStore();
        // Cria uma pendência diretamente no store (como se outro TM solicitasse).
        Store.request({ squad: SQUAD, indicador: 'Disponibilidade', justificativa: 'x', periodo: 'Ago/2026', solicitante: 'tm' });

        AppState.setProfile('tm');
        const view = loadView();
        const container = makeContainer();
        view.render(container, { month: 8 });

        // Há item na fila, mas sem botão de aprovar/rejeitar para TM.
        expect(container.innerHTML).toContain('Fila de aprovação');
        expect(container.innerHTML).not.toContain('data-approve=');
        expect(container.innerHTML).not.toContain('data-reject=');
        expect(container.innerHTML).toContain('Somente aprovadores');
    });

    it('DM vê os botões de aprovar/rejeitar (30.3)', () => {
        const Store = loadStore();
        Store.request({ squad: SQUAD, indicador: 'Disponibilidade', justificativa: 'x', periodo: 'Ago/2026', solicitante: 'tm' });

        AppState.setProfile('dm');
        const view = loadView();
        const container = makeContainer();
        view.render(container, { month: 8 });

        expect(container.innerHTML).toContain('data-approve=');
        expect(container.innerHTML).toContain('data-reject=');
    });
});

describe('view-excecoes — aprovação aplica efeito e rejeição não (30.4, 30.6)', () => {
    let Store, view;
    beforeEach(() => {
        installStorage();
        Store = loadStore();
        Store.clear();
        AppState.setProfile('dm'); // DM pode aprovar/rejeitar
        view = loadView();
    });

    it('aprovar via UI marca "aprovada" e sinaliza o indicador (30.4)', () => {
        const rec = Store.request({ squad: SQUAD, indicador: 'Cycle Time Desenvolvimento', justificativa: 'x', periodo: 'Ago/2026', solicitante: 'tm' });

        const container = makeContainer();
        view.render(container, { month: 8 });

        // Dispara o clique de aprovar (delegação por data-approve na #exc-fila).
        const fila = container.querySelector('#exc-fila');
        expect(fila).toBeTruthy();
        fila.dispatch('click', { target: { getAttribute: (n) => (n === 'data-approve' ? rec.id : null) } });

        const approved = Store.find(rec.id);
        expect(approved.status).toBe('aprovada');
        expect(Store.hasApprovedException(SQUAD, 'Cycle Time Desenvolvimento')).toBe(true);
        // Re-render move para o histórico como "Aprovada".
        expect(container.innerHTML).toContain('Aprovada');
    });

    it('rejeitar via UI marca "rejeitada" e NÃO aplica efeito (30.6)', () => {
        // Stub de window.prompt para fornecer o motivo.
        const prevWindow = globalThis.window;
        globalThis.window = { prompt: () => 'Motivo da rejeição' };
        try {
            const rec = Store.request({ squad: SQUAD, indicador: 'Cycle Time Desenvolvimento', justificativa: 'x', periodo: 'Ago/2026', solicitante: 'tm' });

            const container = makeContainer();
            view.render(container, { month: 8 });

            const fila = container.querySelector('#exc-fila');
            fila.dispatch('click', { target: { getAttribute: (n) => (n === 'data-reject' ? rec.id : null) } });

            const rejected = Store.find(rec.id);
            expect(rejected.status).toBe('rejeitada');
            expect(rejected.motivoRejeicao).toBe('Motivo da rejeição');
            // Rejeição não sinaliza o indicador (30.6).
            expect(Store.hasApprovedException(SQUAD, 'Cycle Time Desenvolvimento')).toBe(false);
            expect(container.innerHTML).toContain('Rejeitada');
        } finally {
            if (prevWindow === undefined) delete globalThis.window; else globalThis.window = prevWindow;
        }
    });
});

describe('view-excecoes — registro e robustez', () => {
    beforeEach(() => {
        installStorage();
        loadStore().clear();
    });

    it('registra em window.PEPViews.excecao e expõe a API', () => {
        const prevWindow = globalThis.window;
        globalThis.window = {};
        try {
            loadView();
            expect(globalThis.window.PEPViews).toBeTruthy();
            expect(typeof globalThis.window.PEPViews.excecao.render).toBe('function');
        } finally {
            if (prevWindow === undefined) delete globalThis.window; else globalThis.window = prevWindow;
        }
    });

    it('render é defensivo: container ausente não lança', () => {
        const view = loadView();
        expect(() => view.render(null, { month: 8 })).not.toThrow();
    });
});
