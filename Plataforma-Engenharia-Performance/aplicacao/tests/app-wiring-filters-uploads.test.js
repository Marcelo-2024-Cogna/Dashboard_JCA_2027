// Feature: score-performance-executivo
// Testes de integração/UI do WIRING de FILTROS e UPLOADS de Retrospectiva de PI
// em `app.js` (Task 26). Requirements 20.4, 20.6, 22.1, 24.1, 24.2, 24.3, 24.4.
//
// Foco (sem jsdom — STUB mínimo de `document`/elementos + FileReader stub):
//   1. Os selects de filtro (Jornada/Squad/Sprint) são populados a partir do
//      RetroFilters, com opção "Todos" (ALL) rotulada como "Todos".          (20.1)
//   2. Encadeamento Jornada→Squad: trocar a Jornada repopula os Squads e
//      re-renderiza o painel de Retro ativo.                                  (20.2, 20.4)
//   3. Trocar qualquer filtro re-renderiza o painel ativo com o filtro atual. (20.4)
//   4. Upload da extração do Jira é processado NO NAVEGADOR (FileReader):
//      parseia, ingere em PEPRetroData, atualiza o status e re-renderiza.     (24.1, 24.2)
//   5. Upload do baseline (.xlsx/CSV) é processado no navegador e INDICA, por
//      PI, a presença de baseline carregado (status por PI).                 (22.1, 24.2)
//   6. Smoke estático: o wiring não dispara chamadas externas (fetch/XHR).   (24.1, 24.2)
//
// O conteúdo de cada painel NÃO é re-testado aqui (tarefas 17/18/19). Usamos um
// renderizador de Retro instrumentado para contar os re-renders e capturar o
// { filter } recebido.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');
const PEPRetroData = require('../js/retro-data.js');
const AppStateReal = require('../js/app-state.js');

const MONTH = PEPData.getLatestMonth();
const RETRO_VIEWS = AppStateReal.RETRO_VIEWS;
const PIS = PEPRetroData.getPIs();
const CURRENT_PI = PEPRetroData.getLatestPI();

// -----------------------------------------------------------------------------
// Stub mínimo de elemento DOM (cobre o que app.js usa), com querySelectorAll
// resolvendo as classes de abas de Score e sub-abas de Retro.
// -----------------------------------------------------------------------------
function makeEl(tag = 'div', id = '') {
    const el = {
        tagName: String(tag).toUpperCase(),
        id,
        _innerHTML: '',
        // `innerHTML = ''` deve zerar os filhos (como no DOM real). Sem isso o
        // stub acumularia <option>s entre re-fills de um mesmo <select>, fazendo
        // o teste "ver" opções obsoletas (ex.: squads de outra jornada). (bug do stub)
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) { this._innerHTML = String(v); if (v === '') this._children = []; },
        textContent: '',
        hidden: false,
        tabIndex: 0,
        type: '',
        value: '',
        selected: false,
        disabled: false,
        files: null,
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
    // Filtros (Task 26) + uploads + status.
    ensure('retro-filter-jornada', 'select');
    ensure('retro-filter-squad', 'select');
    ensure('retro-filter-sprint', 'select');
    ensure('retro-upload-jira', 'input');
    ensure('retro-upload-baseline', 'input');
    ensure('retro-upload-status', 'span');
    // Containers das visões de Score e dos painéis de Retro.
    ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado']
        .forEach((v) => ensure(`view-${v}`, 'section'));
    RETRO_VIEWS.forEach((v) => ensure(`view-${v}`, 'section'));
    return doc;
}

// Stub de AppState com Seletor de PI (independência Mês × PI).
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

// Renderizadores de Retro instrumentados (contam re-renders e capturam filtro).
function makePEPViews() {
    const calls = {};
    const views = {};
    for (const v of ['geral', 'jornadas', 'metricas', 'evolucao', 'metas']) {
        calls[v] = [];
        views[v] = { render(container, opts) { calls[v].push({ month: opts && opts.month }); } };
    }
    for (const v of RETRO_VIEWS) {
        calls[v] = [];
        views[v] = {
            render(container, opts) {
                calls[v].push({ pi: opts && opts.pi, filter: opts && opts.filter });
                if (container) container.innerHTML = `<div class="${v}-ok">ok</div>`;
            }
        };
    }
    return { views, calls };
}

// FileReader stub: dispara onload de forma síncrona com um resultado fixo,
// simulando a leitura client-side de arquivos (sem backend).
function installFileReaderStub(result) {
    class FR {
        readAsText() { this.result = result.text; queueMicrotask(() => this.onload && this.onload()); }
        readAsArrayBuffer() { this.result = result.buffer; queueMicrotask(() => this.onload && this.onload()); }
    }
    globalThis.FileReader = FR;
}

function loadApp({ initialPI = CURRENT_PI } = {}) {
    const doc = makeDocument();
    const appState = makeAppState(MONTH, initialPI);
    const { views, calls } = makePEPViews();

    globalThis.document = doc;
    globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.PEPRetroData = PEPRetroData;
    globalThis.AppState = appState;
    globalThis.PEPViews = views;
    globalThis.Dashboard = { init() {}, setMonth() {} };
    globalThis.ScoringEngine = require('../js/scoring-engine.js');

    // Dependências dos filtros/uploads (resolvidas como no browser).
    const PEPIdentity = require('../js/identity.js');
    PEPIdentity.refresh();
    globalThis.PEPIdentity = PEPIdentity;
    const RetroFilters = require('../js/retro-filters.js');
    RetroFilters.reset();
    globalThis.RetroFilters = RetroFilters;
    globalThis.JiraIngest = require('../js/jira-ingest.js');
    const BaselineLoader = require('../js/baseline-loader.js');
    BaselineLoader._reset();
    globalThis.BaselineLoader = BaselineLoader;

    const appPath = require.resolve('../js/app.js');
    delete require.cache[appPath];
    const PEPApp = require('../js/app.js');

    return { PEPApp, doc, appState, views, calls, RetroFilters, BaselineLoader, PEPIdentity };
}

function cleanupGlobals() {
    ['document', 'PEPData', 'PEPRetroData', 'AppState', 'PEPViews', 'Dashboard',
        'ScoringEngine', 'PEPIdentity', 'RetroFilters', 'JiraIngest', 'BaselineLoader',
        'PEPApp', 'FileReader'].forEach((k) => { delete globalThis[k]; });
}

describe('Wiring de filtros e uploads de Retro PI em app.js (Task 26)', () => {
    let ctx;

    // O upload do Jira chama PEPRetroData.ingestJira(), que MUTA o singleton
    // compartilhado (DATASET.pontosTrabalhados/cargaTrabalho/items/source + a
    // lista de PIs). Como o módulo é require-cached e reaproveitado por outros
    // arquivos de teste, tiramos um snapshot antes e restauramos depois para
    // não vazar estado entre suítes (isolamento de teste).
    let _retroSnapshot;
    beforeEach(() => {
        const ds = PEPRetroData.DATASET;
        _retroSnapshot = {
            pontosTrabalhados: ds.pontosTrabalhados,
            cargaTrabalho: ds.cargaTrabalho,
            items: ds.items,
            source: ds.source,
            pis: [...PEPRetroData.getPIs()]
        };
    });
    afterEach(() => {
        // Restaura as coleções que o ingestJira pode ter substituído.
        const ds = PEPRetroData.DATASET;
        ds.pontosTrabalhados = _retroSnapshot.pontosTrabalhados;
        ds.cargaTrabalho = _retroSnapshot.cargaTrabalho;
        ds.items = _retroSnapshot.items;
        ds.source = _retroSnapshot.source;
        // Repõe a lista de PIs ao estado original (ingestJira faz PIS.push).
        const pis = ds.pis;
        if (Array.isArray(pis)) { pis.length = 0; _retroSnapshot.pis.forEach((p) => pis.push(p)); }
        vi.restoreAllMocks();
        cleanupGlobals();
    });

    // =========================================================================
    // FILTROS (20.1, 20.2, 20.4)
    // =========================================================================
    it('popula os selects de filtro a partir do RetroFilters com "Todos" (20.1)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();

        const jor = ctx.doc.getElementById('retro-filter-jornada');
        const squad = ctx.doc.getElementById('retro-filter-squad');

        // O filtro de Sprint foi removido da UI (higienização): só Jornada e Squad.
        // Primeira opção de cada select é "Todos" (ALL).
        [jor, squad].forEach((sel) => {
            expect(sel._children.length).toBeGreaterThan(0);
            expect(sel._children[0].value).toBe(ctx.RetroFilters.ALL);
            expect(sel._children[0].textContent).toBe('Todos');
        });

        // As jornadas correspondem às opções do store.
        const esperadoJor = ctx.RetroFilters.jornadaOptions();
        expect(jor._children.map((o) => o.value)).toEqual(esperadoJor);
    });

    it('encadeia Jornada→Squad: trocar a Jornada repopula os Squads e re-renderiza (20.2, 20.4)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        const active = RETRO_VIEWS[0];
        const before = ctx.calls[active].length;

        const jor = ctx.doc.getElementById('retro-filter-jornada');
        const umaJornada = ctx.RetroFilters.jornadaOptions().find((j) => j !== ctx.RetroFilters.ALL);
        jor.value = umaJornada;
        jor.dispatch('change', { target: jor });

        // O store aplicou a jornada.
        expect(ctx.RetroFilters.getState().jornada).toBe(umaJornada);
        // Os squads foram restritos à jornada (todos pertencem a ela, exceto ALL).
        const squadSel = ctx.doc.getElementById('retro-filter-squad');
        const squads = squadSel._children.map((o) => o.value).filter((s) => s !== ctx.RetroFilters.ALL);
        squads.forEach((s) => expect(ctx.PEPIdentity.jornadaOf(s)).toBe(ctx.PEPIdentity.canonicalJornada(umaJornada)));
        // Re-renderizou o painel ativo.
        expect(ctx.calls[active].length).toBe(before + 1);
    });

    it('trocar o filtro de Squad re-renderiza o painel ativo com o filtro atual (20.4)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();
        const active = RETRO_VIEWS[0];

        // Seleciona uma jornada para ter squads específicos.
        const jor = ctx.doc.getElementById('retro-filter-jornada');
        const umaJornada = ctx.RetroFilters.jornadaOptions().find((j) => j !== ctx.RetroFilters.ALL);
        jor.value = umaJornada; jor.dispatch('change', { target: jor });

        const squadSel = ctx.doc.getElementById('retro-filter-squad');
        const umSquad = squadSel._children.map((o) => o.value).find((s) => s !== ctx.RetroFilters.ALL);
        const before = ctx.calls[active].length;
        squadSel.value = umSquad;
        squadSel.dispatch('change', { target: squadSel });

        expect(ctx.RetroFilters.getState().squad).toBe(umSquad);
        expect(ctx.calls[active].length).toBe(before + 1);
        // O painel recebeu o filtro atual (inclui o squad selecionado).
        const last = ctx.calls[active][ctx.calls[active].length - 1];
        expect(last.filter).toBeTruthy();
        expect(last.filter.squad).toBe(umSquad);
    });

    // Filtro de Sprint removido da UI (higienização) — teste correspondente
    // retirado. Jornada e Squad seguem cobertos acima.

    // =========================================================================
    // UPLOAD DA EXTRAÇÃO DO JIRA (24.1, 24.2)
    // =========================================================================
    it('processa a extração do Jira no navegador: ingere, atualiza status e re-renderiza (24.1, 24.2)', async () => {
        const csv = [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5',
            'ASKB-2;Enabler;Concluído;Atendimento e Serviços;Kbots;SP 26.3.2;PI 3/26;3'
        ].join('\n');
        installFileReaderStub({ text: csv });

        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();
        const active = RETRO_VIEWS[0];
        const before = ctx.calls[active].length;

        const input = ctx.doc.getElementById('retro-upload-jira');
        input.files = [{ name: 'extracao.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve(); // deixa o microtask do FileReader rodar

        const status = ctx.doc.getElementById('retro-upload-status');
        expect(status.textContent).toMatch(/Jira:\s*2 item/i);
        expect(status.classList.contains('retro-upload-error')).toBe(false);
        // Re-renderizou o painel ativo após a ingestão.
        expect(ctx.calls[active].length).toBeGreaterThan(before);
    });

    it('sinaliza erro no status quando a extração do Jira tem itens inválidos', async () => {
        const csv = [
            'chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5',
            ';;;;;;;' // inválido
        ].join('\n');
        installFileReaderStub({ text: csv });

        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        const input = ctx.doc.getElementById('retro-upload-jira');
        input.files = [{ name: 'extracao.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve();

        const status = ctx.doc.getElementById('retro-upload-status');
        expect(status.textContent).toMatch(/rejeitad/i);
        expect(status.classList.contains('retro-upload-error')).toBe(true);
    });

    // =========================================================================
    // UPLOAD DE BASELINE POR PI (22.1, 24.2)
    // =========================================================================
    it('carrega o baseline por CSV no navegador e indica a presença por PI (22.1, 24.2)', async () => {
        const pi = CURRENT_PI;
        const baselineCSV = ['pi;chave;jornada;squad;situacao']
            .concat(['E1', 'E2', 'E3'].map((c) => `${pi};${c};Atendimento e Serviços;Atendimento;em-dia`))
            .join('\n');
        installFileReaderStub({ text: baselineCSV });

        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        const input = ctx.doc.getElementById('retro-upload-baseline');
        input.files = [{ name: 'baseline.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve();

        const status = ctx.doc.getElementById('retro-upload-status');
        // Indica o PI e a quantidade de registros carregados.
        expect(status.textContent).toMatch(new RegExp(`Baseline do ${pi.replace('/', '\\/')}`, 'i'));
        expect(status.textContent).toMatch(/3 registros/);
        expect(status.classList.contains('retro-upload-error')).toBe(false);
        // O baseline ficou associado ao PI corrente.
        expect(ctx.BaselineLoader.hasBaseline(pi)).toBe(true);
    });

    it('baseline inválido é rejeitado com mensagem, mantendo o anterior (22.4)', async () => {
        const pi = CURRENT_PI;
        // Primeiro carrega um baseline válido.
        const ok = ['pi;chave;jornada;squad;situacao', `${pi};E1;Atendimento e Serviços;Atendimento;em-dia`].join('\n');
        installFileReaderStub({ text: ok });
        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        let input = ctx.doc.getElementById('retro-upload-baseline');
        input.files = [{ name: 'baseline.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve();
        expect(ctx.BaselineLoader.hasBaseline(pi)).toBe(true);

        // Agora um arquivo inválido (cabeçalho sem colunas mínimas).
        installFileReaderStub({ text: 'col_a;col_b\n1;2' });
        input = ctx.doc.getElementById('retro-upload-baseline');
        input.files = [{ name: 'invalido.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve();

        const status = ctx.doc.getElementById('retro-upload-status');
        expect(status.textContent).toMatch(/rejeitad/i);
        expect(status.classList.contains('retro-upload-error')).toBe(true);
        // O baseline anterior é mantido.
        expect(ctx.BaselineLoader.hasBaseline(pi)).toBe(true);
        expect(ctx.BaselineLoader.getBaselineForPI(pi).registros.length).toBe(1);
    });

    // =========================================================================
    // SMOKE ESTÁTICO (24.1, 24.2): sem chamadas externas (fetch/XHR)
    // =========================================================================
    it('o wiring de filtros/uploads não dispara chamadas de rede externas', async () => {
        const fetchSpy = vi.fn();
        globalThis.fetch = fetchSpy;
        const xhrOpen = vi.fn();
        globalThis.XMLHttpRequest = class { open(...a) { xhrOpen(...a); } send() {} setRequestHeader() {} };

        const csv = ['chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints',
            'ASAT-1;História;Concluído;Atendimento e Serviços;Atendimento;SP 26.3.1;PI 3/26;5'].join('\n');
        installFileReaderStub({ text: csv });

        ctx = loadApp();
        ctx.PEPApp.init();
        ctx.PEPApp.activateRetro();

        // Troca de filtro + upload.
        const jor = ctx.doc.getElementById('retro-filter-jornada');
        const umaJornada = ctx.RetroFilters.jornadaOptions().find((j) => j !== ctx.RetroFilters.ALL);
        jor.value = umaJornada; jor.dispatch('change', { target: jor });

        const input = ctx.doc.getElementById('retro-upload-jira');
        input.files = [{ name: 'extracao.csv' }];
        input.dispatch('change', { target: input });
        await Promise.resolve();

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(xhrOpen).not.toHaveBeenCalled();

        delete globalThis.fetch;
        delete globalThis.XMLHttpRequest;
    });
});
