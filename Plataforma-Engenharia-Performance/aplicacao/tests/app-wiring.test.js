// Feature: score-performance-executivo
// Testes de integração/UI do WIRING de `app.js` (Task 9.6).
//
// Foco: o cabeamento de integração em app.js — NÃO re-testamos o conteúdo de
// cada visão (já coberto por tests/view-*.test.js). Verificamos:
//   1. O Seletor de Mês é montado e fica acessível nas 5 visões executivas +
//      legado; trocar o mês re-renderiza a visão ativa (via AppState).         (2.1, 2.2, 2.4)
//   2. As abas das 6 visões são montadas e a troca de aba re-renderiza a visão
//      ativa mantendo o mês selecionado.                                        (2.4, 2.5)
//   3. Cada visão executiva, ao ficar ativa, recebe render(container,{month})
//      com um dataset conhecido (dispatch correto por PEPViews[viewId]).        (3,4,5,6,7)
//   4. O render de cada visão é isolado por try/catch: uma visão que lança no
//      render não derruba a navegação nem as demais (placeholder de erro).
//
// O app é estático e NÃO há jsdom; usamos um STUB mínimo de `document`/
// elementos (no mesmo espírito dos stubs de tests/view-geral.test.js), e um
// AppState/PEPViews stub para exercitar o wiring de app.js em Node (Vitest).
// app.js resolve AppState/PEPData/Dashboard/document/window como GLOBAIS em
// tempo de chamada, então instalamos esses globais antes de chamar init().

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');

const MONTH = PEPData.getLatestMonth();
const OTHER_MONTH = PEPData.getMonthsWithData()[0]; // outro mês com dados

// -----------------------------------------------------------------------------
// Stub mínimo de elemento DOM: cobre apenas o que app.js usa (id, innerHTML,
// hidden, className/classList, dataset, tabIndex, type, addEventListener,
// setAttribute, appendChild, querySelector/querySelectorAll).
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
        // `className` e `classList` são mantidos em sincronia, como no DOM real
        // (app.js define `btn.className = 'view-tab'` diretamente).
        get className() { return [...this._classSet].join(' '); },
        set className(v) {
            this._classSet = new Set(String(v).split(/\s+/).filter(Boolean));
        },
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
        addEventListener(type, fn) {
            (this._listeners[type] = this._listeners[type] || []).push(fn);
        },
        dispatch(type, evt) {
            (this._listeners[type] || []).forEach((fn) => fn(evt));
        },
        appendChild(child) { this._children.push(child); return child; },
        // querySelector/All resolvem RECURSIVAMENTE os descendentes por classe.
        // Com o accordion, as .view-tab ficam aninhadas em .view-tabs-group.
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
// Stub de `document`: registry de elementos por id (criados sob demanda) +
// createElement. app.js usa getElementById/createElement/addEventListener.
// -----------------------------------------------------------------------------
function makeDocument() {
    const byId = new Map();
    const doc = {
        _byId: byId,
        _listeners: {},
        getElementById(id) { return byId.get(id) || null; },
        createElement(tag) { return makeEl(tag); },
        addEventListener(type, fn) {
            (this._listeners[type] = this._listeners[type] || []).push(fn);
        }
    };
    // Pré-cria os elementos que o index.html forneceria.
    const ensure = (id, tag = 'div') => { const el = makeEl(tag, id); byId.set(id, el); return el; };
    ensure('month-selector', 'select');
    ensure('view-tabs', 'nav');
    ensure('sprint-info', 'span');
    ensure('bands-legend', 'div');
    // Containers das 6 visões (ordem de AppState.VIEWS).
    ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado']
        .forEach((v) => ensure(`view-${v}`, 'section'));
    return doc;
}

// -----------------------------------------------------------------------------
// Stub de AppState: implementação mínima e fiel ao contrato usado por app.js
// (VIEWS, get/setSelectedMonth, get/setActiveView, subscribe->notify). Mantemos
// o mês inalterado ao trocar de view (Requirement 2.5), como o AppState real.
// -----------------------------------------------------------------------------
function makeAppState(initialMonth) {
    let month = initialMonth;
    let view = 'geral';
    const subs = [];
    return {
        VIEWS: ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado'],
        getSelectedMonth() { return month; },
        setSelectedMonth(m) {
            month = m;
            subs.forEach((fn) => fn({ type: 'month', month, view }));
            return m;
        },
        getActiveView() { return view; },
        setActiveView(v) {
            view = v; // não altera o mês (2.5)
            subs.forEach((fn) => fn({ type: 'view', month, view }));
            return v;
        },
        subscribe(fn) { subs.push(fn); return () => {}; }
    };
}

// -----------------------------------------------------------------------------
// Stub de PEPViews: cada visão registra um render(container,{month}) que grava
// as chamadas recebidas; uma visão pode ser configurada para LANÇAR, exercendo
// o isolamento por try/catch em app.js.
// -----------------------------------------------------------------------------
function makePEPViews(throwOn = null) {
    const calls = {};
    const views = {};
    for (const v of ['geral', 'jornadas', 'metricas', 'evolucao', 'metas']) {
        calls[v] = [];
        views[v] = {
            render(container, opts) {
                calls[v].push({ container, month: opts && opts.month });
                if (v === throwOn) {
                    throw new Error(`falha simulada ao renderizar "${v}"`);
                }
                if (container) container.innerHTML = `<div class="view-${v}-ok">ok</div>`;
            }
        };
    }
    return { views, calls };
}

// -----------------------------------------------------------------------------
// Carrega app.js limpo, instalando os globais que ele resolve em runtime.
// -----------------------------------------------------------------------------
function loadApp({ throwOn = null } = {}) {
    const doc = makeDocument();
    const appState = makeAppState(MONTH);
    const { views, calls } = makePEPViews(throwOn);

    globalThis.document = doc;
    globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.AppState = appState;
    globalThis.PEPViews = views;
    // Dashboard legado opcional: registra chamadas de setMonth/init.
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
    delete globalThis.Dashboard;
    delete globalThis.ScoringEngine;
    delete globalThis.PEPApp;
    // window aponta para globalThis; não removemos para não afetar o runner.
}

describe('Wiring de app.js — integração das visões (Task 9.6)', () => {
    let ctx;
    afterEach(() => {
        vi.restoreAllMocks();
        cleanupGlobals();
    });

    it('expõe a API de wiring sob guarda de ambiente (sem alterar o browser)', () => {
        ctx = loadApp();
        const { PEPApp } = ctx;
        expect(PEPApp).toBeTruthy();
        for (const fn of ['init', 'buildMonthSelector', 'buildTabs',
            'renderActiveView', 'onStateChange', 'showActiveContainer']) {
            expect(typeof PEPApp[fn]).toBe('function');
        }
    });

    it('monta o Seletor de Mês com os 12 meses e reflete o mês atual (2.1)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const select = ctx.doc.getElementById('month-selector');
        expect(select._children.length).toBe(12); // Jan..Dez
        // Rótulos dos 12 meses presentes nas options.
        PEPData.MONTH_LABELS.forEach((label, i) => {
            const opt = select._children[i];
            expect(opt.value).toBe(String(i + 1));
            expect(opt.textContent).toContain(label);
        });
        // Reflete o mês selecionado atual.
        expect(select.value).toBe(String(MONTH));
    });

    it('o Seletor de Mês permanece acessível e único em todas as visões (2.4)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        const select = ctx.doc.getElementById('month-selector');

        // Navega por todas as visões executivas + legado; o seletor continua
        // sendo o mesmo elemento (toolbar global), não é recriado por visão.
        for (const v of ctx.appState.VIEWS) {
            ctx.appState.setActiveView(v);
            expect(ctx.doc.getElementById('month-selector')).toBe(select);
            expect(select._children.length).toBe(12);
        }
    });

    it('monta uma aba por visão (6) com os atributos de acessibilidade (2.4)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const nav = ctx.doc.getElementById('view-tabs');
        const tabs = nav.querySelectorAll('.view-tab');
        // 6 abas de Score + 1 aba agrupadora "Retrospectiva de PI" (tarefa 16).
        expect(tabs.length).toBe(ctx.appState.VIEWS.length + 1); // 6 + Retro
        ctx.appState.VIEWS.forEach((view, i) => {
            const tab = tabs[i];
            expect(tab.dataset.view).toBe(view);
            expect(tab.getAttribute('role')).toBe('tab');
            expect(tab.getAttribute('aria-controls')).toBe(`view-${view}`);
        });
        // A última aba é a agrupadora de Retro PI (não é uma view de Score).
        const retroTab = tabs[tabs.length - 1];
        expect(retroTab.dataset.view).toBe('retro');
        expect(retroTab.getAttribute('role')).toBe('tab');
    });

    it('aplica o estado inicial: aba "geral" ativa e visível, demais ocultas', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        // Container da visão ativa visível; os outros ocultos.
        expect(ctx.doc.getElementById('view-geral').hidden).toBe(false);
        for (const v of ['jornadas', 'metricas', 'evolucao', 'metas', 'legado']) {
            expect(ctx.doc.getElementById(`view-${v}`).hidden).toBe(true);
        }
        // A visão ativa foi renderizada uma vez com o mês atual.
        expect(ctx.calls.geral.length).toBe(1);
        expect(ctx.calls.geral[0].month).toBe(MONTH);
    });

    it('trocar o mês re-renderiza a visão ativa com o novo mês (2.2)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        expect(ctx.calls.geral.length).toBe(1);

        // Simula a troca de mês pelo usuário (via AppState — como o <select>).
        ctx.appState.setSelectedMonth(OTHER_MONTH);

        // A visão ativa (geral) foi re-renderizada com o novo mês.
        expect(ctx.calls.geral.length).toBe(2);
        expect(ctx.calls.geral[1].month).toBe(OTHER_MONTH);
        // O seletor de mês fica sincronizado com o novo mês.
        expect(ctx.doc.getElementById('month-selector').value).toBe(String(OTHER_MONTH));
    });

    it('o evento change do Seletor de Mês chama AppState.setSelectedMonth', () => {
        ctx = loadApp();
        const spy = vi.spyOn(ctx.appState, 'setSelectedMonth');
        ctx.PEPApp.init();

        const select = ctx.doc.getElementById('month-selector');
        select.value = String(OTHER_MONTH);
        select.dispatch('change', { target: select });

        expect(spy).toHaveBeenCalledWith(OTHER_MONTH);
    });

    it('trocar de aba re-renderiza a visão correspondente mantendo o mês (2.5, 3-7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        // Percorre as 4 visões executivas restantes e confere o dispatch.
        for (const v of ['jornadas', 'metricas', 'evolucao', 'metas']) {
            ctx.appState.setActiveView(v);
            // O container da nova visão fica visível; os demais ocultos.
            expect(ctx.doc.getElementById(`view-${v}`).hidden).toBe(false);
            // A visão recebeu render(container, { month: MONTH }) — mês inalterado.
            expect(ctx.calls[v].length).toBe(1);
            expect(ctx.calls[v][0].month).toBe(MONTH);
            expect(ctx.calls[v][0].container).toBe(ctx.doc.getElementById(`view-${v}`));
        }
        // O mês nunca mudou ao navegar (Requirement 2.5).
        expect(ctx.appState.getSelectedMonth()).toBe(MONTH);
    });

    it('as 5 visões executivas têm Seletor de Mês acessível e são renderizadas (2.4, 3-7)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        for (const v of ['geral', 'jornadas', 'metricas', 'evolucao', 'metas']) {
            ctx.appState.setActiveView(v);
            // Seletor acessível em cada visão.
            const select = ctx.doc.getElementById('month-selector');
            expect(select).toBeTruthy();
            expect(select._children.length).toBe(12);
            // A visão foi renderizada ao menos uma vez.
            expect(ctx.calls[v].length).toBeGreaterThanOrEqual(1);
        }
    });

    it('a aba "legado" aplica o mês ao Dashboard sem passar por PEPViews', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        expect(ctx.dashboardCalls.init).toBe(1); // init do legado no bootstrap

        ctx.appState.setActiveView('legado');
        expect(ctx.doc.getElementById('view-legado').hidden).toBe(false);
        // Dashboard.setMonth recebeu o mês atual.
        expect(ctx.dashboardCalls.setMonth).toContain(MONTH);
    });

    it('ISOLAMENTO: uma visão que lança no render não derruba a navegação (try/catch)', () => {
        // A visão "metricas" lança ao renderizar.
        ctx = loadApp({ throwOn: 'metricas' });
        ctx.PEPApp.init();

        // Ativar a visão que falha NÃO deve lançar.
        expect(() => ctx.appState.setActiveView('metricas')).not.toThrow();

        // O container exibe o placeholder de erro (fallback), não o conteúdo ok.
        const failed = ctx.doc.getElementById('view-metricas');
        expect(failed.innerHTML).toContain('view-placeholder');
        expect(failed.innerHTML).toContain('Não foi possível renderizar esta visão');

        // As demais visões continuam renderizando normalmente após a falha.
        ctx.appState.setActiveView('jornadas');
        const ok = ctx.doc.getElementById('view-jornadas');
        expect(ok.innerHTML).toContain('view-jornadas-ok');
        expect(ctx.calls.jornadas.length).toBe(1);
    });

    it('ISOLAMENTO: a falha de uma visão não impede o render das anteriores já montadas', () => {
        ctx = loadApp({ throwOn: 'evolucao' });
        ctx.PEPApp.init();

        // "geral" já renderizou no bootstrap sem problemas.
        expect(ctx.doc.getElementById('view-geral').innerHTML).toContain('view-geral-ok');

        // Navega para a visão que falha → placeholder de erro, sem throw.
        expect(() => ctx.appState.setActiveView('evolucao')).not.toThrow();
        expect(ctx.doc.getElementById('view-evolucao').innerHTML).toContain('view-placeholder');

        // E continua funcionando ao voltar para uma visão saudável.
        expect(() => ctx.appState.setActiveView('metas')).not.toThrow();
        expect(ctx.doc.getElementById('view-metas').innerHTML).toContain('view-metas-ok');
    });

    it('render é defensivo: visão sem renderer registrado mostra placeholder "em construção"', () => {
        ctx = loadApp();
        // Remove o renderer de "metas" para simular visão ainda não registrada.
        delete ctx.views.metas;
        ctx.PEPApp.init();

        ctx.appState.setActiveView('metas');
        const el = ctx.doc.getElementById('view-metas');
        expect(el.innerHTML).toContain('view-placeholder');
        expect(el.innerHTML).toContain('Em construção');
    });
});
