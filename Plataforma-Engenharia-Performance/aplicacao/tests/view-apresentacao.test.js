// Feature: score-performance-executivo
// Testes de integração/UI representativos do Dashboard de Apresentação Executiva
// (Task 31). Cobrem o Requirement 29 (29.1, 29.2, 29.3, 29.4), verificando que o
// render produz os elementos esperados a partir de um dataset conhecido,
// reutilizando os módulos já implementados (aggregation, scoring-engine, targets,
// data) — SEM recalcular regras próprias (29.3).
//
// O app é estático e NÃO há jsdom nas devDependencies; usamos um STUB mínimo de
// DOM (container com innerHTML + querySelector/querySelectorAll) e um stub de
// Chart.js e document (para o cabeamento de teclado). Isso valida a estrutura
// HTML (slide da jornada: score/faixa/atingimento/destaques), a navegação
// sequencial (prev/next/dots + posição "X de Y") e a indicação de ausência de
// dado, sem browser.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente).
const Aggregation = require('../js/aggregation.js');
const ScoringEngine = require('../js/scoring-engine.js');
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

// -----------------------------------------------------------------------------
// Stub mínimo de elemento: resolve o canvas do gauge (#ap-gauge), os botões de
// navegação ([data-ap-nav]) e os dots ([data-ap-index]). Os elementos de
// navegação precisam de addEventListener para o cabeamento interno não lançar.
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            if (sel === '#ap-gauge' && this.innerHTML.includes('id="ap-gauge"')) {
                return { id: 'ap-gauge', _canvas: true };
            }
            if (sel === '[data-ap-nav="prev"]' && this.innerHTML.includes('data-ap-nav="prev"')) {
                return { addEventListener() {} };
            }
            if (sel === '[data-ap-nav="next"]' && this.innerHTML.includes('data-ap-nav="next"')) {
                return { addEventListener() {} };
            }
            return null;
        },
        querySelectorAll() { return []; }
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

// Stub mínimo de `document` para o cabeamento de teclado (addEventListener/
// removeEventListener). Guardamos o valor anterior para restaurar.
let prevDocument;
function installDocumentStub() {
    prevDocument = globalThis.document;
    globalThis.document = { addEventListener() {}, removeEventListener() {} };
}
function removeDocumentStub() {
    if (prevDocument === undefined) delete globalThis.document;
    else globalThis.document = prevDocument;
    prevDocument = undefined;
}

// Carrega a view DEPOIS de preparar o ambiente (reseta o estado interno:
// gauge/currentIndex/keyHandler), para isolamento entre testes.
let view;
function loadView() {
    const path = require.resolve('../js/views/view-apresentacao.js');
    delete require.cache[path];
    return require('../js/views/view-apresentacao.js');
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();
const JORNADAS = PEPData.JORNADAS;

// Índice da PRIMEIRA jornada COM dado no mês conhecido (oráculo determinístico).
function firstJourneyWithDataIndex(month) {
    for (let i = 0; i < JORNADAS.length; i++) {
        const js = Aggregation.journeyScore(month, JORNADAS[i]);
        if (js && js.hasData) return i;
    }
    return -1;
}

describe('Dashboard de Apresentação Executiva (view-apresentacao) — render', () => {
    beforeEach(() => {
        installChartStub();
        installDocumentStub();
        view = loadView();
    });
    afterEach(() => {
        removeDocumentStub();
        removeChartStub();
    });

    it('exibe o slide da jornada com score, faixa, atingimento e layout de apresentação (29.1)', () => {
        const idx = firstJourneyWithDataIndex(MONTH);
        expect(idx).toBeGreaterThanOrEqual(0);
        const journey = JORNADAS[idx];

        const container = makeContainer();
        view.render(container, { month: MONTH, index: idx });

        // Título e layout de apresentação (uma jornada por vez — o "slide").
        expect(container.innerHTML).toContain('🖥️ Apresentação Executiva');
        expect(container.innerHTML).toContain('class="ap-panel"');
        expect(container.innerHTML).toContain(journey);

        // Score reutiliza os dados do Operacional/Times via Aggregation (29.3):
        // o gauge é alimentado pelo avgScore da jornada (clamped).
        const js = Aggregation.journeyScore(MONTH, journey);
        expect(container.innerHTML).toContain('id="ap-gauge"');
        expect(chartInstances.length).toBe(1);
        const clamped = Math.max(0, Math.min(js.avgScore, ScoringEngine.BASE_MAX_SCORE));
        const data = chartInstances[0].config.data.datasets[0].data;
        expect(data[0]).toBeCloseTo(clamped, 2);
        expect(data[0] + data[1]).toBeCloseTo(ScoringEngine.BASE_MAX_SCORE, 2);

        // Rótulo da Faixa de Performance coerente com o motor (régua do Score).
        const band = js.performanceBand || ScoringEngine.getPerformanceBand(js.avgScore);
        expect(container.innerHTML).toContain(band.name);

        // Atingimento da meta (reutiliza Targets, fonte única — 29.3).
        const at = Targets.atingimento(journey, MONTH, js.avgScore);
        if (typeof at === 'number' && isFinite(at)) {
            expect(container.innerHTML).toContain(`${(at * 100).toFixed(1)}%`);
        }
        expect(container.innerHTML).toContain('Atingimento da meta');
    });

    it('exibe o detalhamento "X de Y pontos" para as 4 categorias (destaques consolidados)', () => {
        const idx = firstJourneyWithDataIndex(MONTH);
        const journey = JORNADAS[idx];

        const container = makeContainer();
        view.render(container, { month: MONTH, index: idx });

        const js = Aggregation.journeyScore(MONTH, journey);
        const cats = ScoringEngine.CATEGORIES;
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(container.innerHTML).toContain(cats[id].name);
            const val = js.categoryScores[id].toFixed(0);
            expect(container.innerHTML).toContain(`${val} de ${cats[id].maxScore} pontos`);
        }
        expect(container.innerHTML).toMatch(/\d+ de \d+ pontos/);
    });

    it('permite navegar sequencialmente entre as jornadas: posição, dots e botões (29.2)', () => {
        const container = makeContainer();

        // Primeiro slide: índice 0 → "Jornada 1 de N", prev desabilitado.
        view.render(container, { month: MONTH, index: 0 });
        expect(container.innerHTML).toContain(`Jornada 1 de ${JORNADAS.length}`);
        expect(container.innerHTML).toContain('data-ap-nav="prev"');
        expect(container.innerHTML).toContain('data-ap-nav="next"');
        // Botão "anterior" desabilitado no primeiro slide.
        expect(container.innerHTML).toMatch(/ap-navbtn-prev[^>]*disabled/);
        // Um dot por jornada, com o primeiro ativo.
        for (let i = 0; i < JORNADAS.length; i++) {
            expect(container.innerHTML).toContain(`data-ap-index="${i}"`);
        }
        expect(container.innerHTML).toContain('ap-dot-active');

        // Último slide: índice N-1 → "Jornada N de N", next desabilitado.
        const last = JORNADAS.length - 1;
        view.render(container, { month: MONTH, index: last });
        expect(container.innerHTML).toContain(`Jornada ${JORNADAS.length} de ${JORNADAS.length}`);
        expect(container.innerHTML).toMatch(/ap-navbtn-next[^>]*disabled/);
        expect(container.innerHTML).toContain(JORNADAS[last]);
    });

    it('faz clamp do índice fora do intervalo (navegação sequencial fechada)', () => {
        const container = makeContainer();

        // Índice acima do máximo é limitado à última jornada.
        view.render(container, { month: MONTH, index: 999 });
        expect(container.innerHTML).toContain(`Jornada ${JORNADAS.length} de ${JORNADAS.length}`);

        // Índice negativo é limitado à primeira jornada.
        view.render(container, { month: MONTH, index: -5 });
        expect(container.innerHTML).toContain(`Jornada 1 de ${JORNADAS.length}`);
    });

    it('indica visualmente a ausência de dado da jornada no período (29.1)', () => {
        // Mês sem dados (ex.: Dez=12 não está em MONTHS_WITH_DATA).
        const emptyMonth = 12;
        expect(PEPData.getMonthsWithData()).not.toContain(emptyMonth);

        const container = makeContainer();
        view.render(container, { month: emptyMonth, index: 0 });

        expect(container.innerHTML).toContain('ap-panel-empty');
        expect(container.innerHTML).toContain('Sem dados para esta jornada');
        // Nenhum gauge desenhado quando não há dado.
        expect(chartInstances.length).toBe(0);
        // A navegação sequencial permanece disponível mesmo sem dado (29.2).
        expect(container.innerHTML).toContain(`Jornada 1 de ${JORNADAS.length}`);
    });

    it('registra o renderer em window.PEPViews.apresentacao e expõe a API', () => {
        const prevWindow = globalThis.window;
        globalThis.window = {};
        try {
            loadView();
            expect(globalThis.window.PEPViews).toBeTruthy();
            expect(typeof globalThis.window.PEPViews.apresentacao.render).toBe('function');
        } finally {
            if (prevWindow === undefined) delete globalThis.window;
            else globalThis.window = prevWindow;
        }

        expect(typeof view.render).toBe('function');
        expect(typeof view.buildHTML).toBe('function');
        expect(typeof view.slidePanel).toBe('function');
        expect(typeof view.categoryBreakdown).toBe('function');
        expect(typeof view.journeyModel).toBe('function');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
