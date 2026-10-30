// Feature: score-performance-executivo
// Testes de integração/UI representativos da Visão Geral Executiva (Task 9.1).
// Cobrem o Requirement 3 (3.1–3.8) verificando que o render produz a estrutura
// HTML esperada a partir de um dataset conhecido, reutilizando os módulos já
// implementados (scoring-engine, aggregation, targets, data).
//
// O app é estático e NÃO há jsdom nas devDependencies; para manter o runner
// leve usamos um STUB mínimo de DOM (container com innerHTML + querySelector)
// e um stub de Chart.js, no mesmo padrão de tests/view-jornadas.test.js. Isso
// valida: estrutura HTML, gauge 0–1000, donut das 4 categorias, contagens por
// Farol, destaques de maior alta/queda e o estado de primeiro mês (variação
// indisponível + destaques vazios), sem exigir browser/build.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente). A view-geral
// resolve suas dependências via `dep()` (lê de window/globalThis). No browser
// os módulos são globais por serem carregados via <script>; em Node o
// require() nem sempre registra o módulo em globalThis (ex.: scoring-engine
// exporta só via module.exports). Para reproduzir o ambiente de browser de
// forma fiel, registramos explicitamente cada dependência em window/globalThis
// (ver installGlobals()).
const ScoringEngine = require('../js/scoring-engine.js');
const Aggregation = require('../js/aggregation.js');
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

// Expõe as dependências como globais (equivalente ao carregamento por <script>
// no browser), de onde a view-geral as resolve via `dep()`.
function installGlobals() {
    if (typeof globalThis.window === 'undefined') {
        globalThis.window = globalThis;
    }
    globalThis.ScoringEngine = ScoringEngine;
    globalThis.Aggregation = Aggregation;
    globalThis.Targets = Targets;
    globalThis.PEPData = PEPData;
}

// -----------------------------------------------------------------------------
// Stub mínimo de container: um "elemento" com innerHTML e querySelector capaz
// de localizar os canvases por id (#vg-gauge-canvas / #vg-donut-canvas).
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            const m = /^#(vg-gauge-canvas|vg-donut-canvas)$/.exec(sel);
            if (m && this.innerHTML.includes(`id="${m[1]}"`)) {
                return { id: m[1], _canvas: true };
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

// A view registra-se em `window.PEPViews.geral`. Em Node não há `window`;
// garantimos um `window` apontando para `globalThis` para que o bloco de
// registro execute e possamos recuperar o renderer de forma consistente.
function loadView() {
    installGlobals();
    const path = require.resolve('../js/views/view-geral.js');
    delete require.cache[path]; // recarrega limpo (charts internos resetados)
    require('../js/views/view-geral.js');
    return globalThis.window.PEPViews.geral;
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();
const FIRST_MONTH = PEPData.getMonthsWithData()[0];

describe('Visão Geral (view-geral) — render', () => {
    let view;

    beforeEach(() => {
        installChartStub();
        view = loadView();
    });
    afterEach(() => {
        removeChartStub();
    });

    it('registra o renderer window.PEPViews.geral com API esperada', () => {
        expect(view).toBeTruthy();
        expect(typeof view.render).toBe('function');
    });

    it('renderiza a estrutura HTML principal da Visão Geral (3.x)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('class="vg"');
        expect(container.innerHTML).toContain('📊 Visão Geral — Engenharia');
        // Rótulo do mês presente.
        expect(container.innerHTML).toContain(PEPData.MONTH_LABELS[MONTH - 1]);
        // Cards principais.
        expect(container.innerHTML).toContain('Score de Engenharia');
        expect(container.innerHTML).toContain('Jornadas por Farol');
        expect(container.innerHTML).toContain('Destaques vs mês anterior');
        expect(container.innerHTML).toContain('Distribuição por categoria');
    });

    it('exibe o gauge 0–1000 do score de Engenharia e o rótulo da Faixa (3.1, 3.2, 3.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Canvas do gauge presente no HTML.
        expect(container.innerHTML).toContain('id="vg-gauge-canvas"');

        // Rótulo da Faixa de Performance coerente com o motor para o score de Eng.
        const eng = Aggregation.engineeringScore(MONTH);
        const band = ScoringEngine.getPerformanceBand(eng.avgScore);
        expect(container.innerHTML).toContain(band.name);

        // O gauge usa Chart.js (duas instâncias: gauge + donut).
        expect(chartInstances.length).toBeGreaterThanOrEqual(1);

        // Gauge escala de 0 até BASE_MAX_SCORE (1000): soma do dataset = max.
        // (o texto "de 1000 pts" é desenhado no canvas via plugin afterDraw,
        // então validamos a escala pela config do Chart, não pelo innerHTML.)
        const gauge = chartInstances.find(c => c.config
            && c.config.options
            && c.config.options.circumference === 270);
        expect(gauge).toBeTruthy();
        const dataSum = gauge.config.data.datasets[0].data.reduce((a, b) => a + b, 0);
        expect(Math.round(dataSum)).toBe(ScoringEngine.BASE_MAX_SCORE);
        // A fatia preenchida corresponde ao score de Engenharia (clamped).
        expect(Math.round(gauge.config.data.datasets[0].data[0])).toBe(Math.round(eng.avgScore));
    });

    it('exibe o % de atingimento da meta do mês para Engenharia (3.4)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('Atingimento da meta do mês');
        // Percentual formatado (ex.: "97.3%") OU "Indisponível".
        expect(container.innerHTML).toMatch(/\d+\.\d%|Indisponível/);
    });

    it('exibe a contagem de jornadas por Farol somando às jornadas com dado (3.5)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Os três rótulos de Farol presentes.
        expect(container.innerHTML).toContain('Na meta');
        expect(container.innerHTML).toContain('Atenção');
        expect(container.innerHTML).toContain('Crítico');

        // A soma das contagens deve igualar o nº de jornadas com atingimento.
        const eng = Aggregation.engineeringScore(MONTH);
        let expected = 0;
        for (const j of eng.journeys) {
            const at = Targets.atingimento(j.journey, MONTH, j.avgScore);
            if (Targets.farol(at)) expected += 1;
        }
        expect(container.innerHTML).toContain(`${expected} jornada(s) com atingimento`);
    });

    it('desenha o donut com a distribuição das 4 categorias (3.7)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('id="vg-donut-canvas"');

        // Legenda com as 4 categorias e seus máximos.
        const cats = ScoringEngine.CATEGORIES;
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(container.innerHTML).toContain(cats[id].name);
            expect(container.innerHTML).toContain(`/ ${cats[id].maxScore} pts`);
        }

        // Dois charts (gauge + donut) foram instanciados.
        expect(chartInstances.length).toBe(2);
        // O donut recebe 4 fatias (4 categorias).
        const donut = chartInstances.find(c => c.config
            && c.config.data && c.config.data.datasets
            && c.config.data.datasets[0].data.length === 4);
        expect(donut).toBeTruthy();
    });

    it('exibe os destaques de maior alta e maior queda vs mês anterior (3.6)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('▲ Maior alta');
        expect(container.innerHTML).toContain('▼ Maior queda');

        // Coerência com Aggregation.highlights: a jornada de maior alta aparece.
        const hi = Aggregation.highlights(MONTH);
        if (hi.topRise) {
            expect(container.innerHTML).toContain(hi.topRise.journey);
        }
        if (hi.topFall) {
            expect(container.innerHTML).toContain(hi.topFall.journey);
        }
    });

    it('no primeiro mês com dados, a variação é indisponível e os destaques ficam vazios (3.8)', () => {
        const container = makeContainer();
        view.render(container, { month: FIRST_MONTH });

        // Variação numérica vs mês anterior exibida como indisponível.
        expect(container.innerHTML).toContain('Indisponível');
        expect(container.innerHTML).toContain('Primeiro mês com dados');

        // Destaques vazios: mensagem de ausência de mês anterior.
        expect(container.innerHTML).toContain('Sem destaques (sem mês anterior com dados).');

        // Confirma via agregação que não há mês anterior com dados.
        expect(Aggregation.monthDelta(FIRST_MONTH, null)).toBeNull();
        const hi = Aggregation.highlights(FIRST_MONTH);
        expect(hi.topRise).toBeNull();
        expect(hi.topFall).toBeNull();
    });

    it('indica estado neutro quando o mês selecionado não possui dados', () => {
        const emptyMonth = 12; // Dez não está em MONTHS_WITH_DATA.
        expect(PEPData.getMonthsWithData()).not.toContain(emptyMonth);

        const container = makeContainer();
        view.render(container, { month: emptyMonth });

        expect(container.innerHTML).toContain('Sem dados para o mês selecionado.');
        // Nenhum chart é desenhado sem dados.
        expect(chartInstances.length).toBe(0);
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
