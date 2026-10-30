// Feature: score-performance-executivo
// Testes de integração/UI representativos do Dashboard Executivo por Squad
// (Task 30). Cobrem o Requirement 28 (28.1, 28.2, 28.3, 28.4), verificando que
// o render produz os elementos esperados a partir de um dataset conhecido,
// reutilizando os módulos já implementados (scoring-engine, identity, targets,
// data).
//
// O app é estático e NÃO há jsdom nas devDependencies; usamos um STUB mínimo de
// DOM (container com innerHTML + querySelector) e um stub de Chart.js. Isso
// valida a estrutura HTML e o cabeamento de dados (seletor de squad por
// identidade canônica, gauge do score, Faixa de Performance, detalhamento por
// categoria "X de Y pontos", Farol e indicação de ausência de dado), sem browser.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente).
const ScoringEngine = require('../js/scoring-engine.js');
const PEPIdentity = require('../js/identity.js');
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

// -----------------------------------------------------------------------------
// Stub mínimo de elemento: resolve o <select> do seletor (#es-squad-selector)
// e o canvas do gauge (#es-gauge). O <select> precisa de addEventListener e
// value para o cabeamento interno não lançar.
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            if (sel === '#es-squad-selector' && this.innerHTML.includes('id="es-squad-selector"')) {
                return { id: 'es-squad-selector', value: '', addEventListener() {} };
            }
            if (sel === '#es-gauge' && this.innerHTML.includes('id="es-gauge"')) {
                return { id: 'es-gauge', _canvas: true };
            }
            return null;
        }
    };
}

// Stub de Chart.js: registra as instâncias criadas e expõe destroy().
let chartInstances;
function installChartStub() {
    chartInstances = [];
    globalThis.Chart = class {
        constructor(canvas, config) {
            this.canvas = canvas;
            this.config = config;
            chartInstances.push(this);
        }
        destroy() { this.destroyed = true; }
    };
}
function removeChartStub() {
    delete globalThis.Chart;
    chartInstances = [];
}

// Carrega a view DEPOIS de preparar o ambiente (reseta o estado interno:
// gauge/selectedSquad), para isolamento entre testes.
let view;
function loadView() {
    const path = require.resolve('../js/views/view-exec-squad.js');
    delete require.cache[path];
    return require('../js/views/view-exec-squad.js');
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();

// Dataset conhecido: 1º squad canônico e sua jornada.
const FIRST_SQUAD = PEPIdentity.squadsOf(PEPIdentity.jornadas()[0])[0];
const FIRST_JORNADA = PEPIdentity.jornadaOf(FIRST_SQUAD);

// Oráculo: score do squad conhecido no mês = ScoringEngine sobre as métricas.
function squadScoreResult(squad, month) {
    const teams = PEPData.getTeamsByJornada(PEPIdentity.jornadaOf(squad), month);
    const team = teams.find((t) => PEPIdentity.sameSquad(t.name, squad));
    return ScoringEngine.calculateTeamScore(team.metrics);
}

describe('Dashboard Executivo por Squad (view-exec-squad) — render', () => {
    beforeEach(() => {
        installChartStub();
        view = loadView();
    });
    afterEach(() => {
        removeChartStub();
    });

    it('exibe o seletor de squad com os squads da identificação canônica (28.2)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('id="es-squad-selector"');
        expect(container.innerHTML).toContain('🧩 Executivo por Squad');

        // Todos os squads canônicos aparecem como opção, agrupados por jornada.
        const squads = view.allSquads();
        expect(squads.length).toBeGreaterThan(0);
        for (const s of squads) {
            expect(container.innerHTML).toContain(`value="${s}"`);
        }
        // Cada jornada com squads vira um <optgroup label="...">.
        for (const g of view.squadCatalog()) {
            expect(container.innerHTML).toContain(`label="${g.jornada}"`);
        }
    });

    it('exibe o score do squad com a régua de Faixas de Performance (28.1)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, squad: FIRST_SQUAD });

        const result = squadScoreResult(FIRST_SQUAD, MONTH);

        // Gauge desenhado (uma instância Chart). O score numérico e o "de N pts"
        // são pintados no canvas (afterDraw), não no HTML; validamos o dado que
        // alimenta o gauge: o valor do dataset = score (clamped) do motor.
        expect(container.innerHTML).toContain('id="es-gauge"');
        expect(chartInstances.length).toBe(1);
        const clamped = Math.max(0, Math.min(result.totalScore, ScoringEngine.BASE_MAX_SCORE));
        const data = chartInstances[0].config.data.datasets[0].data;
        expect(data[0]).toBeCloseTo(clamped, 2);
        expect(data[0] + data[1]).toBeCloseTo(ScoringEngine.BASE_MAX_SCORE, 2);

        // Rótulo da Faixa de Performance coerente com o motor (régua do Score).
        const band = ScoringEngine.getPerformanceBand(result.totalScore);
        expect(container.innerHTML).toContain(band.name);
        // O squad selecionado e sua jornada são exibidos.
        expect(container.innerHTML).toContain(FIRST_SQUAD);
        expect(container.innerHTML).toContain(`Jornada: ${FIRST_JORNADA}`);
    });

    it('exibe o detalhamento "X de Y pontos" para as 4 categorias (28.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, squad: FIRST_SQUAD });

        const result = squadScoreResult(FIRST_SQUAD, MONTH);
        const cats = ScoringEngine.CATEGORIES;
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(container.innerHTML).toContain(cats[id].name);
            // "X de Y pontos" com o valor do motor e o máximo da categoria.
            const val = result.categoryScores[id].toFixed(0);
            expect(container.innerHTML).toContain(`${val} de ${cats[id].maxScore} pontos`);
        }
        expect(container.innerHTML).toMatch(/\d+ de \d+ pontos/);
    });

    it('exibe o Farol do squad coerente com Targets (28.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, squad: FIRST_SQUAD });

        const result = squadScoreResult(FIRST_SQUAD, MONTH);
        const at = Targets.atingimento(FIRST_JORNADA, MONTH, result.totalScore);
        const farolId = Targets.farol(at);

        const label = { 'na-meta': 'Na meta', 'atencao': 'Atenção', 'critico': 'Crítico' }[farolId]
            || 'Indisponível';
        expect(container.innerHTML).toContain(label);
        // "Meta vs realizado" (atingimento) presente.
        expect(container.innerHTML).toContain('Meta vs realizado');
    });

    it('indica visualmente a ausência de dado do squad no período (28.4)', () => {
        // Mês sem dados (ex.: Dez=12 não está em MONTHS_WITH_DATA).
        const emptyMonth = 12;
        expect(PEPData.getMonthsWithData()).not.toContain(emptyMonth);

        const container = makeContainer();
        view.render(container, { month: emptyMonth, squad: FIRST_SQUAD });

        expect(container.innerHTML).toContain('es-panel-empty');
        expect(container.innerHTML).toContain('Sem dados');
        expect(container.innerHTML).toContain('no período selecionado');
        // Nenhum gauge desenhado quando não há dado.
        expect(chartInstances.length).toBe(0);
        // O seletor de squad permanece disponível mesmo sem dado (28.2).
        expect(container.innerHTML).toContain('id="es-squad-selector"');
    });

    it('registra o renderer em window.PEPViews.execSquad e expõe a API', () => {
        // No browser a view se registra em window.PEPViews.execSquad.
        const prevWindow = globalThis.window;
        globalThis.window = {};
        try {
            loadView();
            expect(globalThis.window.PEPViews).toBeTruthy();
            expect(typeof globalThis.window.PEPViews.execSquad.render).toBe('function');
        } finally {
            if (prevWindow === undefined) delete globalThis.window;
            else globalThis.window = prevWindow;
        }

        expect(typeof view.render).toBe('function');
        expect(typeof view.buildHTML).toBe('function');
        expect(typeof view.categoryBreakdown).toBe('function');
        expect(typeof view.squadCatalog).toBe('function');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
