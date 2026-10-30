// Feature: parametrização — telas de UI (cfg-parametrizacao, entrada-manual,
// comparativo). Testes de render defensivo + conteúdo-chave + wiring básico,
// com stub de DOM mínimo (sem jsdom). Reutiliza os módulos reais.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// localStorage em memória para os stores.
function installStorage() {
    const mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

// Elemento-stub com listeners, atributos e busca recursiva por classe/seletor.
function makeEl(tag, id) {
    const el = {
        tagName: (tag || 'div').toUpperCase(),
        id: id || '',
        value: '',
        textContent: '',
        className: '',
        _attrs: {},
        _children: [],
        _listeners: {},
        get innerHTML() { return this._html || ''; },
        set innerHTML(v) { this._html = String(v); },
        getAttribute(n) { return this._attrs[n] != null ? this._attrs[n] : null; },
        setAttribute(n, v) { this._attrs[n] = v; },
        addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); },
        dispatch(t, evt) { (this._listeners[t] || []).forEach((fn) => fn(evt || {})); },
        querySelector(sel) { return this._q[sel] || null; },
        querySelectorAll() { return []; },
        _q: {}
    };
    return el;
}

// Container cujo querySelector resolve ids (#x) retornando elementos-stub
// cacheados — permite wire() anexar listeners e os testes dispararem eventos.
function makeContainer() {
    const cache = {};
    return {
        innerHTML: '',
        querySelector(sel) {
            if (!cache[sel]) cache[sel] = makeEl('div', sel.replace(/^#/, ''));
            return cache[sel];
        },
        querySelectorAll() { return []; },
        _cache: cache
    };
}

function loadView(file, regKey) {
    if (typeof globalThis.window === 'undefined') globalThis.window = globalThis;
    const p = require.resolve(file);
    delete require.cache[p];
    require(file);
    return globalThis.window.PEPViews[regKey];
}

// Módulos reais usados pelas views.
const ScoringEngine = require('../js/scoring-engine.js');
const PEPData = require('../js/data.js');
const PEPRetroData = require('../js/retro-data.js');
const MetricConfig = require('../js/metric-config.js');
const ManualMetricsStore = require('../js/manual-metrics-store.js');
const Rbac = require('../js/rbac.js');
const AppState = require('../js/app-state.js');

beforeEach(() => {
    installStorage();
    globalThis.ScoringEngine = ScoringEngine;
    globalThis.PEPData = PEPData;
    globalThis.PEPRetroData = PEPRetroData;
    globalThis.MetricConfig = MetricConfig;
    globalThis.ManualMetricsStore = ManualMetricsStore;
    globalThis.Rbac = Rbac;
    globalThis.AppState = AppState;
    // Perfil admin (acesso total) — determinístico para as telas de gestão.
    try { AppState.setProfile('admin'); } catch (e) { /* ignore */ }
    MetricConfig._refreshStore(); MetricConfig.reset();
    ManualMetricsStore._refreshStore(); ManualMetricsStore.clear();
});

describe('Tela de Parametrização (cfgParametrizacao)', () => {
    it('render defensivo: container nulo não lança', () => {
        const view = loadView('../js/views/cfg-parametrizacao.js', 'cfgParametrizacao');
        expect(() => view.render(null, {})).not.toThrow();
    });

    it('renderiza origem por métrica (12) e sprint de fechamento por PI', () => {
        const view = loadView('../js/views/cfg-parametrizacao.js', 'cfgParametrizacao');
        const c = makeContainer();
        view.render(c, {});
        expect(c.innerHTML).toContain('Parametrização de Métricas');
        expect(c.innerHTML).toContain('Origem por métrica');
        expect(c.innerHTML).toContain('Sprint de fechamento por PI');
        // Uma linha por métrica (12) com select de origem.
        const selects = (c.innerHTML.match(/class="adm-input cfg-origem"/g) || []).length;
        expect(selects).toBe(12);
        // Uma linha por PI com select de sprint.
        const pis = (c.innerHTML.match(/class="adm-input cfg-sprint"/g) || []).length;
        expect(pis).toBe(PEPRetroData.getPIs().length);
    });
});

describe('Tela de Entrada Manual (entradaManual)', () => {
    it('render defensivo: container nulo não lança', () => {
        const view = loadView('../js/views/entrada-manual.js', 'entradaManual');
        expect(() => view.render(null, {})).not.toThrow();
    });

    it('lista as métricas manuais e uma linha por squad', () => {
        const view = loadView('../js/views/entrada-manual.js', 'entradaManual');
        const c = makeContainer();
        view.render(c, { month: 9 });
        expect(c.innerHTML).toContain('Entrada Manual de Métricas');
        // Métricas manuais no cabeçalho (ao menos Disponibilidade e THC).
        expect(c.innerHTML).toContain('Disponibilidade');
        expect(c.innerHTML).toContain('Team Health Check');
        // Inputs numéricos: 41 squads × 4 métricas manuais.
        const inputs = (c.innerHTML.match(/class="adm-input man-input"/g) || []).length;
        expect(inputs).toBe(PEPData.TEAMS.length * MetricConfig.metricasManuais().length);
    });
});


