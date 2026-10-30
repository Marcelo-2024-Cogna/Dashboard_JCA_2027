// Feature: score-performance-executivo
// Testes de integração/UI do WIRING de Retrospectiva de PI em `app.js` (Task 16).
//
// Foco: o cabeamento de navegação dos painéis de Retro PI — NÃO re-testamos o
// conteúdo de cada painel (tarefas 17/18/19 ainda não criaram os renderizadores).
// Verificamos (Requirements 18.7, 19.3):
//   1. Presença e montagem do Seletor de PI (dropdown de PEPRetroData.getPIs())
//      e das 8 sub-abas (AppState.RETRO_VIEWS) sob a aba agrupadora.           (18.7)
//   2. Trocar o PI dispara o render do painel de Retro ATIVO com { pi }.        (18.7)
//   3. INDEPENDÊNCIA Seletor de PI × Seletor de Mês: trocar o PI não altera o
//      Mês e não re-renderiza as visões de Score; trocar o Mês não altera o PI
//      nem re-renderiza os painéis de Retro.                                    (19.3)
//   4. Dispatch DEFENSIVO: painel de Retro ainda não registrado em PEPViews
//      mostra placeholder "em construção"; um painel que lança no render não
//      derruba a navegação (isolamento por try/catch).                          (18.7)
//
// Mesmo espírito de tests/app-wiring.test.js: STUB mínimo de `document`/
// elementos (sem jsdom) e stubs de AppState/PEPViews/PEPRetroData instalados
// como GLOBAIS antes de carregar app.js (que os resolve em runtime).

import { describe, it, expect, afterEach, vi } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');
const AppStateReal = require('../js/app-state.js');

const MONTH = PEPData.getLatestMonth();
const OTHER_MONTH = PEPData.getMonthsWithData()[0];

const RETRO_VIEWS = AppStateReal.RETRO_VIEWS; // fonte da verdade (tarefa 15)
const PIS = ['PI 1/26', 'PI 2/26', 'PI 3/26'];

// -----------------------------------------------------------------------------
// Stub mínimo de elemento DOM (cobre o que app.js usa). Igual ao de
// tests/app-wiring.test.js, com querySelectorAll resolvendo também as classes
// de Retro (.retro-subtab).
// -----------------------------------------------------------------------------
function makeEl(tag = 'div', id = '') {
    const el = {
        tagName: String(tag).toUpperCase(),
        id,
        innerHTML: '',
        textContent: '',
        hidden: false,
        tabIndex: 0,
        type: '',
        value: '',
        disabled: false,
        dataset: {},
        _children: [],
        _attrs: {},
        _listeners: {},
        _classSet: new Set(),
        get className() { return [...this._classSet].join(' '); },
        set className(v) { this._classSet = new Set(String(v).split(/\s+/).filter(Boolean)); },
        classList: {
            add(c) { el._classSet.add(c); },
            remove(c) { el._classSet.delete(c); },
            contains(c) { return el._classSet.has(c); },
            toggle(c, on) {
                const want = on === undefined ? !el._classSet.has(c) : !!on;
                if (want) el._classSet.add(c); else el._classSet.delete(c);
                return want;
            }
        },
        setAttribute(k, v) { this._attrs[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
        addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); },
        dispatch(type, evt) { (this._listeners[type] || []).forEach((fn) => fn(evt)); },
        appendChild(child) { this._children.push(child); return child; },
        // Resolve RECURSIVAMENTE por classe (accordion: .view-tab dentro de
        // .view-tabs-group).
        querySelectorAll(sel) {
            const cls = String(sel).replace(/^\./, '');
            const out = [];
            const walk = (node) => {
                (node._children || []).forEach((c) => {
                    if (c._classSet && c._classSet.has(cls)) out.push(c);
                    walk(c);
                });
            };
            walk(this);
            return out;
        },
        querySelector(sel) {
            const all = this.querySelectorAll(sel);
            return all.length ? all[0] : null;
        }
    };
    return el;
}

// -----------------------------------------------------------------------------
// Stub de `document`: inclui os elementos adicionados pela tarefa 16
// (retro-toolbar, retro-subtabs, pi-selector, month-selector-wrap) + os 8
// containers de Retro.
// -----------------------------------------------------------------------------
function makeDocument() {
    const byId = new Map();
    const doc = {
        _byId: byId,
        _listeners: {},
        getElementById(id) { return byId.get(id) || null; },
        createElement(tag) { return makeEl(tag); },
        addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
    };
    const ensure = (id, tag = 'div') => { const el = makeEl(tag, id); byId.set(id, el); return el; };
    ensure('month-selector', 'select');
    ensure('month-selector-wrap', 'div');
    ensure('view-tabs', 'nav');
    ensure('sprint-info', 'span');
    ensure('bands-legend', 'div');
    // Toolbar de Retro + Seletor de PI + sub-abas.
    ensure('retro-toolbar', 'div');
    ensure('retro-subtabs', 'nav');
    ensure('pi-selector', 'select');
    // Containers das 6 visões de Score.
    ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado']
        .forEach((v) => ensure(`view-${v}`, 'section'));
    // Containers dos 8 painéis de Retro.
    RETRO_VIEWS.forEach((v) => ensure(`view-${v}`, 'section'));
    return doc;
}

// -----------------------------------------------------------------------------
// Stub de AppState fiel ao contrato usado por app.js, agora com o Seletor de PI
// (getSelectedPI/setSelectedPI) e RETRO_VIEWS. notify envia { type } para que o
// app.js distinga mudança de PI de mudança de mês (independência).
// -----------------------------------------------------------------------------
function makeAppState(initialMonth, initialPI) {
    let month = initialMonth;
    let view = 'geral';
    let pi = initialPI;
    const subs = [];
    const notify = (change) => subs.forEach((fn) => fn(change));
    return {
        VIEWS: ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado'],
        RETRO_VIEWS: [...RETRO_VIEWS],
        getSelectedMonth() { return month; },
        setSelectedMonth(m) { month = m; notify({ type: 'month', month, view }); return m; },
        getActiveView() { return view; },
        setActiveView(v) { view = v; notify({ type: 'view', month, view }); return v; },
        getSelectedPI() { return pi; },
        setSelectedPI(p) { pi = p; notify({ type: 'pi', pi, view }); return p; },
        subscribe(fn) { subs.push(fn); return () => {}; }
    };
}

// -----------------------------------------------------------------------------
// Stub de PEPViews: registra renderizadores para os painéis de Retro (grava as
// chamadas e o { pi } recebido). Também registra as visões de Score para
// detectar re-renders indevidos ao trocar o PI.
// -----------------------------------------------------------------------------
function makePEPViews({ retroThrowOn = null, skipRetro = [] } = {}) {
    const calls = {};
    const views = {};
    for (const v of ['geral', 'jornadas', 'metricas', 'evolucao', 'metas']) {
        calls[v] = [];
        views[v] = {
            render(container, opts) {
                calls[v].push({ container, month: opts && opts.month });
                if (container) container.innerHTML = `<div class="view-${v}-ok">ok</div>`;
            }
        };
    }
    for (const v of RETRO_VIEWS) {
        calls[v] = [];
        if (skipRetro.indexOf(v) !== -1) continue; // simula painel não registrado
        views[v] = {
            render(container, opts) {
                calls[v].push({ container, pi: opts && opts.pi });
                if (v === retroThrowOn) throw new Error(`falha simulada ao renderizar "${v}"`);
                if (container) container.innerHTML = `<div class="${v}-ok">ok</div>`;
            }
        };
    }
    return { views, calls };
}

function makePEPRetroData(pis) {
    return {
        getPIs() { return [...pis]; },
        getLatestPI() { return pis.length ? pis[pis.length - 1] : undefined; }
    };
}

function loadApp({ initialPI = PIS[PIS.length - 1], retroThrowOn = null, skipRetro = [] } = {}) {
    const doc = makeDocument();
    const appState = makeAppState(MONTH, initialPI);
    const { views, calls } = makePEPViews({ retroThrowOn, skipRetro });

    globalThis.document = doc;
    globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.AppState = appState;
    globalThis.PEPViews = views;
    globalThis.PEPRetroData = makePEPRetroData(PIS);
    const dashboardCalls = { init: 0, setMonth: [] };
    globalThis.Dashboard = {
        init() { dashboardCalls.init += 1; },
        setMonth(m) { dashboardCalls.setMonth.push(m); }
    };
    globalThis.ScoringEngine = require('../js/scoring-engine.js');

    const appPath = require.resolve('../js/app.js');
    delete require.cache[appPath];
    const PEPApp = require('../js/app.js');

    return { PEPApp, doc, appState, views, calls, dashboardCalls };
}

function cleanupGlobals() {
    delete globalThis.document;
    delete globalThis.PEPData;
    delete globalThis.AppState;
    delete globalThis.PEPViews;
    delete globalThis.PEPRetroData;
    delete globalThis.Dashboard;
    delete globalThis.ScoringEngine;
    delete globalThis.PEPApp;
}

describe('Wiring de Retrospectiva de PI em app.js (Task 16)', () => {
    let ctx;
    afterEach(() => {
        vi.restoreAllMocks();
        cleanupGlobals();
    });

    it('expõe a API de wiring de Retro (buildPISelector, buildRetroSubTabs, activateRetro, ...)', () => {
        ctx = loadApp();
        const { PEPApp } = ctx;
        for (const fn of ['buildPISelector', 'buildRetroSubTabs', 'activateRetro',
            'setActiveRetroView', 'renderActiveRetroView']) {
            expect(typeof PEPApp[fn]).toBe('function');
        }
    });

    it('monta o Seletor de PI com os PIs de PEPRetroData.getPIs() e reflete o PI vigente (18.7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const sel = ctx.doc.getElementById('pi-selector');
        expect(sel._children.length).toBe(PIS.length);
        PIS.forEach((pi, i) => {
            expect(sel._children[i].value).toBe(pi);
            expect(sel._children[i].textContent).toBe(pi);
        });
        // Default = PI vigente (último).
        expect(sel.value).toBe(PIS[PIS.length - 1]);
        expect(sel.disabled).toBe(false);
    });

    it('monta uma sub-aba por painel de Retro (8) com atributos de acessibilidade (18.7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const nav = ctx.doc.getElementById('retro-subtabs');
        const tabs = nav.querySelectorAll('.retro-subtab');
        expect(tabs.length).toBe(RETRO_VIEWS.length); // 8
        tabs.forEach((tab, i) => {
            const view = RETRO_VIEWS[i];
            expect(tab.dataset.view).toBe(view);
            expect(tab.getAttribute('role')).toBe('tab');
            expect(tab.getAttribute('aria-controls')).toBe(`view-${view}`);
        });
    });

    it('adiciona a aba agrupadora "Retrospectiva de PI" às abas de Score sem quebrá-las', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const nav = ctx.doc.getElementById('view-tabs');
        const tabs = nav.querySelectorAll('.view-tab');
        // 6 abas de Score + 1 agrupadora de Retro.
        expect(tabs.length).toBe(ctx.appState.VIEWS.length + 1);
        const group = tabs[tabs.length - 1];
        expect(group.dataset.view).toBe('retro');
        expect(group.dataset.group).toBe('retro');
    });

    it('a toolbar de Retro fica oculta no estado inicial (Score ativo) e visível ao ativar Retro', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const bar = ctx.doc.getElementById('retro-toolbar');
        expect(bar.hidden).toBe(true); // começa no Score (geral)
        expect(ctx.PEPApp.isRetroActive()).toBe(false);

        ctx.PEPApp.activateRetro();
        expect(bar.hidden).toBe(false);
        expect(ctx.PEPApp.isRetroActive()).toBe(true);
        // Container do primeiro painel visível; os de Score ocultos.
        expect(ctx.doc.getElementById(`view-${RETRO_VIEWS[0]}`).hidden).toBe(false);
        expect(ctx.doc.getElementById('view-geral').hidden).toBe(true);
    });

    it('ao ativar Retro, o painel ativo é renderizado com { pi } (18.7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        ctx.PEPApp.activateRetro();
        const first = RETRO_VIEWS[0];
        expect(ctx.calls[first].length).toBe(1);
        expect(ctx.calls[first][0].pi).toBe(PIS[PIS.length - 1]);
        expect(ctx.calls[first][0].container).toBe(ctx.doc.getElementById(`view-${first}`));
    });

    it('trocar de sub-aba re-renderiza o painel de Retro correspondente com { pi }', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        for (const v of RETRO_VIEWS.slice(1)) {
            ctx.PEPApp.setActiveRetroView(v);
            expect(ctx.doc.getElementById(`view-${v}`).hidden).toBe(false);
            expect(ctx.calls[v].length).toBe(1);
            expect(ctx.calls[v][0].pi).toBe(PIS[PIS.length - 1]);
        }
    });

    it('trocar o PI dispara render do painel de Retro ATIVO com o novo PI (18.7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();
        const active = RETRO_VIEWS[0];
        expect(ctx.calls[active].length).toBe(1);

        ctx.appState.setSelectedPI('PI 1/26');

        expect(ctx.calls[active].length).toBe(2);
        expect(ctx.calls[active][1].pi).toBe('PI 1/26');
        // O Seletor de PI reflete o novo PI.
        expect(ctx.doc.getElementById('pi-selector').value).toBe('PI 1/26');
    });

    it('o evento change do Seletor de PI chama AppState.setSelectedPI', () => {
        ctx = loadApp();
        const spy = vi.spyOn(ctx.appState, 'setSelectedPI');
        ctx.PEPApp.init();

        const sel = ctx.doc.getElementById('pi-selector');
        sel.value = 'PI 2/26';
        sel.dispatch('change', { target: sel });

        expect(spy).toHaveBeenCalledWith('PI 2/26');
    });

    // =========================================================================
    // INDEPENDÊNCIA Seletor de PI × Seletor de Mês (Requirement 19.3)
    // =========================================================================
    it('trocar o PI NÃO altera o Mês nem re-renderiza as visões de Score (19.3)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        const scoreCallsBefore = ctx.calls.geral.length;
        const monthBefore = ctx.appState.getSelectedMonth();

        ctx.appState.setSelectedPI('PI 1/26');

        // Mês inalterado e seletor de mês não muda de valor.
        expect(ctx.appState.getSelectedMonth()).toBe(monthBefore);
        expect(ctx.doc.getElementById('month-selector').value).toBe(String(monthBefore));
        // Nenhuma visão de Score re-renderizada por mudança de PI.
        expect(ctx.calls.geral.length).toBe(scoreCallsBefore);
    });

    it('trocar o Mês NÃO altera o PI nem re-renderiza os painéis de Retro (19.3)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        // Ativa Retro, renderiza o painel 1 uma vez, depois volta ao Score.
        ctx.PEPApp.activateRetro();
        const active = RETRO_VIEWS[0];
        const retroCallsBefore = ctx.calls[active].length;
        const piBefore = ctx.appState.getSelectedPI();

        // Volta ao Score e troca o mês.
        ctx.appState.setActiveView('geral');
        ctx.appState.setSelectedMonth(OTHER_MONTH);

        // PI inalterado e seletor de PI mantém o valor.
        expect(ctx.appState.getSelectedPI()).toBe(piBefore);
        expect(ctx.doc.getElementById('pi-selector').value).toBe(String(piBefore));
        // Nenhum painel de Retro re-renderizado por mudança de mês.
        expect(ctx.calls[active].length).toBe(retroCallsBefore);
    });

    it('trocar o Mês enquanto em Retro volta ao Score sem tocar o PI (19.3)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();
        const piBefore = ctx.appState.getSelectedPI();

        // Uma mudança de mês é um evento de Score → tira do modo Retro.
        ctx.appState.setSelectedMonth(OTHER_MONTH);
        expect(ctx.PEPApp.isRetroActive()).toBe(false);
        expect(ctx.appState.getSelectedPI()).toBe(piBefore);
    });

    // =========================================================================
    // DISPATCH DEFENSIVO (Requirement 18.7)
    // =========================================================================
    it('painel de Retro sem renderizador registrado mostra placeholder "em construção"', () => {
        const target = RETRO_VIEWS[2];
        ctx = loadApp({ skipRetro: [target] });
        ctx.PEPApp.init();

        ctx.PEPApp.setActiveRetroView(target);
        const el = ctx.doc.getElementById(`view-${target}`);
        expect(el.innerHTML).toContain('view-placeholder');
        expect(el.innerHTML).toContain('Em construção');
    });

    it('ISOLAMENTO: um painel de Retro que lança no render não derruba a navegação', () => {
        const bad = RETRO_VIEWS[1];
        ctx = loadApp({ retroThrowOn: bad });
        ctx.PEPApp.init();

        expect(() => ctx.PEPApp.setActiveRetroView(bad)).not.toThrow();
        const failed = ctx.doc.getElementById(`view-${bad}`);
        expect(failed.innerHTML).toContain('view-placeholder');
        expect(failed.innerHTML).toContain('Não foi possível renderizar esta visão');

        // Outro painel continua renderizando normalmente após a falha.
        const good = RETRO_VIEWS[0];
        ctx.PEPApp.setActiveRetroView(good);
        expect(ctx.doc.getElementById(`view-${good}`).innerHTML).toContain(`${good}-ok`);
    });

    it('setActiveRetroView rejeita painel inválido (fora de RETRO_VIEWS)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        expect(() => ctx.PEPApp.setActiveRetroView('naoExiste')).toThrow(/painel de Retro inválido/);
    });

    it('Seletor de PI sem PIs disponíveis fica desabilitado com opção informativa', () => {
        // Instala um PEPRetroData sem PIs antes de montar o seletor.
        ctx = loadApp();
        globalThis.PEPRetroData = { getPIs() { return []; }, getLatestPI() { return undefined; } };
        ctx.PEPApp.buildPISelector();

        const sel = ctx.doc.getElementById('pi-selector');
        expect(sel.disabled).toBe(true);
        expect(sel._children.length).toBe(1);
        expect(sel._children[0].textContent).toContain('sem PIs');
    });
});
