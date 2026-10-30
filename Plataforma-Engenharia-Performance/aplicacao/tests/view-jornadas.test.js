// Feature: score-performance-executivo
// Testes de integração/UI representativos da Visão de Jornadas (Task 9.2).
// Cobrem os Requirements 2.3, 4.1, 4.2, 4.3, 4.4, 4.5 verificando que o render
// produz os elementos esperados a partir de um dataset conhecido, reutilizando
// os módulos já implementados (scoring-engine, aggregation, targets, data).
//
// O app é estático e NÃO há jsdom nas devDependencies; para manter o runner
// leve usamos um STUB mínimo de DOM (container com innerHTML + querySelector)
// e um stub de Chart.js. Isso valida a estrutura HTML e o cabeamento de dados
// (gauge canvas por jornada, faixa, meta vs realizado, Farol, delta e o
// detalhamento "X de Y pontos" por categoria), sem exigir browser/build.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente).
const ScoringEngine = require('../js/scoring-engine.js');
const Aggregation = require('../js/aggregation.js');
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

// -----------------------------------------------------------------------------
// Stub mínimo de container: um "elemento" com innerHTML e querySelector capaz
// de localizar canvases por id (#vj-gauge-N), suficiente para o render da view.
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            // Só precisamos resolver os canvases dos gauges (#vj-gauge-N).
            const m = /^#(vj-gauge-\d+)$/.exec(sel);
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

// Carrega a view DEPOIS de preparar o ambiente. A view resolve dependências via
// require() sob guarda; os globais acima garantem a resolução consistente.
let view;
function loadView() {
    const path = require.resolve('../js/views/view-jornadas.js');
    delete require.cache[path]; // recarrega limpo (gauges internos resetados)
    return require('../js/views/view-jornadas.js');
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();

describe('Visão de Jornadas (view-jornadas) — render', () => {
    beforeEach(() => {
        installChartStub();
        view = loadView();
    });
    afterEach(() => {
        removeChartStub();
    });

    it('renderiza um card por jornada de PEPData.JORNADAS', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Conta os wrappers de card (classe inicia com "vj-card" seguida de
        // aspas ou espaço — evita casar "vj-card-head"/"vj-card-empty-...").
        const cardCount = (container.innerHTML.match(/class="vj-card(?:["\s])/g) || []).length;
        expect(cardCount).toBe(PEPData.JORNADAS.length);
        // Cabeçalho da visão + rótulo do mês presentes.
        expect(container.innerHTML).toContain('🧭 Jornadas');
        expect(container.innerHTML).toContain(PEPData.MONTH_LABELS[MONTH - 1]);
    });

    it('exibe, para cada jornada com dado, gauge, Faixa de Performance e % meta vs realizado (4.1, 4.2)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Um canvas de gauge por jornada com dado (todas têm dado no mês atual).
        const gaugeCanvases = (container.innerHTML.match(/id="vj-gauge-\d+"/g) || []).length;
        expect(gaugeCanvases).toBe(PEPData.JORNADAS.length);
        // Os gauges foram efetivamente desenhados (uma instância Chart por jornada).
        expect(chartInstances.length).toBe(PEPData.JORNADAS.length);

        // Rótulo de Faixa de Performance: valida a 1ª jornada contra o motor.
        const first = PEPData.JORNADAS[0];
        const js = Aggregation.journeyScore(MONTH, first);
        const band = ScoringEngine.getPerformanceBand(js.avgScore);
        expect(container.innerHTML).toContain(band.name);

        // % de meta vs realizado (atingimento) formatado como percentual.
        expect(container.innerHTML).toContain('Meta vs realizado');
        expect(container.innerHTML).toMatch(/\d+\.\d%/);
    });

    it('exibe o Farol de cada jornada coerente com Targets.farol (4.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        const first = PEPData.JORNADAS[0];
        const js = Aggregation.journeyScore(MONTH, first);
        const at = Targets.atingimento(first, MONTH, js.avgScore);
        const farolId = Targets.farol(at);
        const expectedLabel = { 'na-meta': 'Na meta', 'atencao': 'Atenção', 'critico': 'Crítico' }[farolId];
        expect(expectedLabel).toBeTruthy();
        expect(container.innerHTML).toContain(expectedLabel);
    });

    it('exibe o detalhamento "X de Y pontos" para as 4 categorias (4.5)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        const cats = ScoringEngine.CATEGORIES;
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            // Nome da categoria presente e o padrão "de Y pontos" com o máximo.
            expect(container.innerHTML).toContain(cats[id].name);
            expect(container.innerHTML).toContain(`de ${cats[id].maxScore} pontos`);
        }
        // Padrão genérico "N de M pontos" presente.
        expect(container.innerHTML).toMatch(/\d+ de \d+ pontos/);
    });

    it('exibe o delta de score vs mês anterior (4.4)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });
        expect(container.innerHTML).toContain('Δ vs mês anterior');
    });

    it('no primeiro mês com dados, o delta é indisponível (4.4 / 3.8)', () => {
        const container = makeContainer();
        const firstMonth = PEPData.getMonthsWithData()[0];
        view.render(container, { month: firstMonth });
        // Sem mês anterior → monthDelta null → "Indisponível".
        expect(container.innerHTML).toContain('Indisponível');
    });

    it('indica visualmente a ausência de dado da jornada no mês (2.3)', () => {
        // Mês sem dados (ex.: Dez=12 não está em MONTHS_WITH_DATA).
        const emptyMonth = 12;
        expect(PEPData.getMonthsWithData()).not.toContain(emptyMonth);

        const container = makeContainer();
        view.render(container, { month: emptyMonth });

        // Todas as jornadas aparecem como "sem dado".
        const emptyCards = (container.innerHTML.match(/vj-card-empty/g) || []).length;
        expect(emptyCards).toBe(PEPData.JORNADAS.length);
        expect(container.innerHTML).toContain('Sem dado');
        expect(container.innerHTML).toContain('Sem dado para esta jornada no mês selecionado.');
        // Nenhum gauge desenhado quando não há dado.
        expect(chartInstances.length).toBe(0);
    });

    it('registra o renderer e expõe a API esperada', () => {
        expect(typeof view.render).toBe('function');
        expect(typeof view.buildHTML).toBe('function');
        expect(typeof view.categoryBreakdown).toBe('function');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
