// Feature: score-performance-executivo
// Testes de REGRESSÃO do dashboard legado — aba "Operacional/Times" (Task 10).
//
// Cobrem Requirements 9.1–9.6 verificando que o dashboard, agora alimentado
// pelo MODELO MENSAL (data.js migrado) e pelas 4 CATEGORIAS corrigidas do
// scoring-engine, preserva TODAS as capacidades do MVP:
//   - Ranking de times ordenado por score desc.                       (9.1)
//   - Filtros por Jornada e por Time.                                  (9.2)
//   - Detalhamento por métrica (modal) com as 12 métricas.            (9.3)
//   - Entrada manual de métricas atualiza o score.                    (9.4)
//   - Exportação CSV com separador ';' e UTF-8 com BOM.               (9.5)
//   - Leitura do mês do AppState (Dashboard.setMonth/init).           (9.6)
//
// O app é estático e NÃO há jsdom; reutilizamos o padrão de STUB de DOM dos
// testes existentes (tests/app-wiring.test.js, tests/view-*.test.js): um
// `document` stub com registro de elementos por id + createElement, elementos
// com innerHTML/classList/addEventListener/dispatch, e um stub de Chart.js.
// O dashboard resolve ScoringEngine/PEPData/AppState/document/Chart como
// GLOBAIS em runtime; instalamos esses globais antes de carregar dashboard.js.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');
const ScoringEngine = require('../js/scoring-engine.js');

const MONTH = PEPData.getLatestMonth();
const OTHER_MONTH = PEPData.getMonthsWithData()[0]; // outro mês com dados

// -----------------------------------------------------------------------------
// Stub mínimo de elemento DOM.
// -----------------------------------------------------------------------------
function makeEl(tag = 'div', id = '') {
    const el = {
        tagName: String(tag).toUpperCase(),
        id,
        innerHTML: '',
        textContent: '',
        hidden: false,
        value: '',
        href: '',
        download: '',
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
        click() { this.dispatch('click', { target: this, currentTarget: this }); },
        appendChild(child) { this._children.push(child); return child; }
    };
    return el;
}

// -----------------------------------------------------------------------------
// Stub de `document`: cria sob demanda os elementos do index.html usados pelo
// dashboard. getElementById cria o elemento se o id for conhecido (lazy), para
// que o modal/form (criados via innerHTML) também sejam resolvíveis quando o
// teste os consultar explicitamente.
// -----------------------------------------------------------------------------
function makeDocument() {
    const byId = new Map();
    const ensure = (id, tag = 'div') => {
        if (!byId.has(id)) byId.set(id, makeEl(tag, id));
        return byId.get(id);
    };
    // Elementos do index.html que o dashboard consulta.
    [
        ['filter-jornada', 'select'], ['filter-team', 'select'],
        ['btn-export', 'button'], ['btn-input-data', 'button'],
        ['modal-close', 'button'], ['modal-overlay', 'div'],
        ['modal-title', 'h3'], ['modal-body', 'div'],
        ['kpi-cards', 'div'], ['gauge-chart', 'canvas'],
        ['dimension-chart', 'canvas'], ['journey-chart', 'canvas'],
        ['ranking-list', 'div'], ['metric-details', 'div'], ['toast', 'div'],
        // Campos do formulário de entrada manual (criados via innerHTML no
        // browser; aqui os pré-criamos para o teste poder setar valores).
        ['input-form', 'form'], ['input-team', 'select'],
        ['input-freq-deploy', 'select'], ['input-team-health', 'input'],
        ['input-planejado', 'input'], ['input-okrs', 'input'],
        ['input-ct-dev', 'input'], ['input-ct-homolog', 'input'],
        ['input-ct-impl', 'input'], ['input-disponibilidade', 'input'],
        ['input-vulnerabilidade', 'input'], ['input-sla-alta', 'input'],
        ['input-sla-media', 'input'], ['input-sla-baixa', 'input']
    ].forEach(([id, tag]) => ensure(id, tag));

    return {
        _byId: byId,
        getElementById(id) { return byId.get(id) || null; },
        createElement(tag) { return makeEl(tag); }
    };
}

// Stub de Chart.js: registra instâncias e expõe destroy().
let chartInstances;
function installChartStub() {
    chartInstances = [];
    globalThis.Chart = class {
        constructor(canvas, config) { this.canvas = canvas; this.config = config; chartInstances.push(this); }
        destroy() { this.destroyed = true; }
    };
}

// AppState stub mínimo (apenas getSelectedMonth, usado pelo dashboard).
function makeAppState(month) {
    let m = month;
    return {
        getSelectedMonth() { return m; },
        _set(v) { m = v; }
    };
}

function loadDashboard(initialMonth = MONTH) {
    const doc = makeDocument();
    const appState = makeAppState(initialMonth);

    globalThis.document = doc;
    globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.ScoringEngine = ScoringEngine;
    globalThis.AppState = appState;
    installChartStub();

    const path = require.resolve('../js/dashboard.js');
    delete require.cache[path];
    const Dashboard = require('../js/dashboard.js');
    return { Dashboard, doc, appState };
}

function cleanup() {
    delete globalThis.document;
    delete globalThis.PEPData;
    delete globalThis.ScoringEngine;
    delete globalThis.AppState;
    delete globalThis.Chart;
    delete globalThis.Dashboard;
}

describe('Dashboard legado — regressão da aba Operacional/Times (Task 10)', () => {
    let ctx;
    afterEach(() => {
        vi.restoreAllMocks();
        cleanup();
    });

    it('expõe a API esperada (init, setMonth, showTeamDetail, exportCSV)', () => {
        ctx = loadDashboard();
        for (const fn of ['init', 'setMonth', 'showTeamDetail', 'applyFilters', 'exportCSV']) {
            expect(typeof ctx.Dashboard[fn]).toBe('function');
        }
    });

    it('init monta o ranking ordenado por score desc (9.1)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const state = ctx.Dashboard._getState();
        // Lê o mês do AppState (9.6).
        expect(state.currentMonth).toBe(MONTH);
        // Todos os times do mês entraram no ranking.
        const expectedCount = PEPData.teamSnapshotFor(MONTH).length;
        expect(state.scoredTeams.length).toBe(expectedCount);
        // Ordenado por score desc.
        for (let i = 1; i < state.scoredTeams.length; i++) {
            expect(state.scoredTeams[i - 1].scoreResult.totalScore)
                .toBeGreaterThanOrEqual(state.scoredTeams[i].scoreResult.totalScore);
        }
        // O ranking-list foi renderizado com posições.
        const ranking = ctx.doc.getElementById('ranking-list');
        expect(ranking.innerHTML).toContain('ranking-item');
        expect(ranking.innerHTML).toContain('1º');
    });

    it('filtro por Jornada restringe os times exibidos (9.2)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const jornada = PEPData.JORNADAS[0];
        const sel = ctx.doc.getElementById('filter-jornada');
        sel.value = jornada;
        sel.dispatch('change', { target: sel });

        const state = ctx.Dashboard._getState();
        expect(state.filteredTeams.length).toBeGreaterThan(0);
        expect(state.filteredTeams.every(t => t.jornada === jornada)).toBe(true);
    });

    it('filtro por Time restringe a um único time (9.2)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const someTeam = PEPData.teamSnapshotFor(MONTH)[0];
        const teamSel = ctx.doc.getElementById('filter-team');
        teamSel.value = someTeam.id;
        teamSel.dispatch('change', { target: teamSel });

        const state = ctx.Dashboard._getState();
        expect(state.filteredTeams.length).toBe(1);
        expect(state.filteredTeams[0].id).toBe(someTeam.id);
    });

    it('modal de detalhamento exibe as 12 métricas e as 4 categorias (9.3)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const teamId = PEPData.teamSnapshotFor(MONTH)[0].id;
        ctx.Dashboard.showTeamDetail(teamId);

        const body = ctx.doc.getElementById('modal-body');
        // As 12 métricas presentes pelo nome.
        Object.values(ScoringEngine.METRICS).forEach(m => {
            expect(body.innerHTML).toContain(m.name);
        });
        // As 4 categorias corrigidas.
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(body.innerHTML).toContain(ScoringEngine.CATEGORIES[id].name);
        }
        // O modal foi aberto.
        expect(ctx.doc.getElementById('modal-overlay').classList.contains('active')).toBe(true);
    });

    it('entrada manual de métricas atualiza o score do time (9.4)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const target = PEPData.teamSnapshotFor(MONTH)[0];
        const before = ScoringEngine.calculateTeamScore(target.metrics).totalScore;

        // Abre o modal de entrada e preenche valores "ruins" para derrubar o score.
        ctx.Dashboard._openInputModal();
        ctx.doc.getElementById('input-team').value = target.id;
        ctx.doc.getElementById('input-planejado').value = '50';   // 50% → não atendeu
        ctx.doc.getElementById('input-ct-dev').value = '10';      // 10 dias → não atendeu
        ctx.doc.getElementById('input-team-health').value = '3';  // 3/10 → não atendeu (escala 0–10)

        ctx.Dashboard._handleFormSubmit({ preventDefault() {} });

        const state = ctx.Dashboard._getState();
        const updated = state.scoredTeams.find(t => t.id === target.id);
        expect(updated).toBeTruthy();
        expect(updated.scoreResult.totalScore).toBeLessThan(before);
    });

    it('entrada manual interpreta Team Health Check na escala 0–10 (não divide por 100)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const target = PEPData.teamSnapshotFor(MONTH)[0];
        ctx.Dashboard._openInputModal();
        ctx.doc.getElementById('input-team').value = target.id;
        ctx.doc.getElementById('input-team-health').value = '9.6'; // superou (>9,5)
        ctx.Dashboard._handleFormSubmit({ preventDefault() {} });

        const state = ctx.Dashboard._getState();
        const updated = state.scoredTeams.find(t => t.id === target.id);
        // teamHealth armazenado em 0–10 (9.6), não 0.096.
        expect(updated.metrics.teamHealth).toBe(9.6);
        expect(updated.scoreResult.metrics.teamHealth.band).toBe('superou');
    });

    it('exportação CSV usa separador ";" e inicia com BOM UTF-8 (9.5)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();

        const csv = ctx.Dashboard.exportCSV();
        expect(typeof csv).toBe('string');
        // BOM no início.
        expect(csv.charCodeAt(0)).toBe(0xFEFF);
        // Cabeçalho com separador ';'.
        const headerLine = csv.slice(1).split('\n')[0];
        expect(headerLine).toContain(';');
        expect(headerLine).toContain('Score Total');
        // As 4 categorias nas colunas.
        expect(headerLine).toContain('Processos');
        expect(headerLine).toContain('Práticas');
        expect(headerLine).toContain('Produto');
        expect(headerLine).toContain('Pessoas');
        // Uma linha de dados por time filtrado.
        const dataLines = csv.slice(1).trim().split('\n').slice(1);
        expect(dataLines.length).toBe(ctx.Dashboard._getState().filteredTeams.length);
    });

    it('Dashboard.setMonth troca o mês e reconstrói o ranking a partir do novo mês (9.6)', () => {
        ctx = loadDashboard();
        ctx.Dashboard.init();
        expect(ctx.Dashboard._getState().currentMonth).toBe(MONTH);

        ctx.Dashboard.setMonth(OTHER_MONTH);

        const state = ctx.Dashboard._getState();
        expect(state.currentMonth).toBe(OTHER_MONTH);
        // O ranking foi reconstruído a partir do snapshot do novo mês.
        const expectedCount = PEPData.teamSnapshotFor(OTHER_MONTH).length;
        expect(state.scoredTeams.length).toBe(expectedCount);
        // Ainda ordenado por score desc.
        for (let i = 1; i < state.scoredTeams.length; i++) {
            expect(state.scoredTeams[i - 1].scoreResult.totalScore)
                .toBeGreaterThanOrEqual(state.scoredTeams[i].scoreResult.totalScore);
        }
    });

    it('init lê o mês do AppState quando o mês não é informado (9.6)', () => {
        ctx = loadDashboard(OTHER_MONTH); // AppState aponta para OTHER_MONTH
        ctx.Dashboard.init();
        expect(ctx.Dashboard._getState().currentMonth).toBe(OTHER_MONTH);
    });

    it('smoke: carregamento estático não lança e expõe Dashboard como global', () => {
        ctx = loadDashboard();
        expect(globalThis.Dashboard).toBe(ctx.Dashboard);
        expect(() => ctx.Dashboard.init()).not.toThrow();
    });
});
