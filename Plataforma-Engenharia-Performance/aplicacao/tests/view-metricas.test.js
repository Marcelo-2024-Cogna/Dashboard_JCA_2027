// Feature: score-performance-executivo
// Testes de integração/UI representativos da Visão de Métricas por Jornada
// (Task 9.3). Cobrem os Requirements 5.1, 5.2, 5.3, 5.4 verificando que o
// render produz os elementos esperados a partir de um dataset conhecido,
// reutilizando os módulos já implementados (scoring-engine, aggregation, data).
//
// Seguindo o padrão de view-jornadas.test.js: o app é estático e NÃO há jsdom
// nas devDependencies; usamos um STUB mínimo de DOM (container com innerHTML +
// querySelector) e um stub de Chart.js. Isso valida a estrutura HTML e o
// cabeamento de dados (seletor de jornada, 12 métricas em 4 categorias, gauge
// por métrica, "X de Y pontos", rótulo de faixa, média dos times e distribuição
// por faixa), sem exigir browser/build.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente).
const ScoringEngine = require('../js/scoring-engine.js');
const Aggregation = require('../js/aggregation.js');
const PEPData = require('../js/data.js');

// -----------------------------------------------------------------------------
// Stub mínimo de container: innerHTML + querySelector capaz de localizar os
// canvases dos gauges (#vm-gauge-N) e o <select> de jornada (#vm-journey-select,
// cujo addEventListener não existe no stub — a view trata isso defensivamente).
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            const g = /^#(vm-gauge-\d+)$/.exec(sel);
            if (g && this.innerHTML.includes(`id="${g[1]}"`)) {
                return { id: g[1], _canvas: true };
            }
            // Seletor de jornada: devolvemos um objeto SEM addEventListener para
            // garantir que a view é defensiva em ambientes sem DOM real.
            if (sel === '#vm-journey-select' && this.innerHTML.includes('id="vm-journey-select"')) {
                return { id: 'vm-journey-select' };
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

// Carrega a view DEPOIS de preparar o ambiente, recarregando limpo para
// resetar o estado interno (jornada selecionada / gauges).
let view;
function loadView() {
    const path = require.resolve('../js/views/view-metricas.js');
    delete require.cache[path];
    return require('../js/views/view-metricas.js');
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();
const FIRST_JOURNEY = PEPData.JORNADAS[0];
const METRIC_IDS = Object.keys(ScoringEngine.METRIC_RULES);

describe('Visão de Métricas por Jornada (view-metricas) — render', () => {
    beforeEach(() => {
        installChartStub();
        view = loadView();
        view._resetState();
    });
    afterEach(() => {
        removeChartStub();
    });

    it('renderiza o seletor de jornada com todas as jornadas (5.1)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('📈 Métricas por Jornada');
        expect(container.innerHTML).toContain('id="vm-journey-select"');
        for (const name of PEPData.JORNADAS) {
            expect(container.innerHTML).toContain(name);
        }
        // Rótulo do mês presente.
        expect(container.innerHTML).toContain(PEPData.MONTH_LABELS[MONTH - 1]);
    });

    it('exibe as 12 métricas agrupadas pelas 4 categorias (5.1)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // As 4 categorias presentes como seções.
        const cats = ScoringEngine.CATEGORIES;
        for (const id of ['processos', 'praticas', 'produto', 'pessoas']) {
            expect(container.innerHTML).toContain(cats[id].name);
            expect(container.innerHTML).toContain(`${cats[id].maxScore} pts`);
        }
        // Uma seção .vm-category por categoria (4).
        const sections = (container.innerHTML.match(/class="vm-category"/g) || []).length;
        expect(sections).toBe(4);

        // Os nomes das 12 métricas presentes.
        for (const id of METRIC_IDS) {
            const name = ScoringEngine.METRICS[id] ? ScoringEngine.METRICS[id].name : id;
            expect(container.innerHTML).toContain(name);
        }

        // 12 cards de métrica (não vazios) com dado no mês atual.
        const cards = (container.innerHTML.match(/class="vm-card"/g) || []).length;
        expect(cards).toBe(METRIC_IDS.length);
    });

    it('exibe gauge, "X de Y pontos" e rótulo de faixa por métrica (5.2)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Um canvas de gauge por métrica com dado (todas têm dado no mês atual).
        const gaugeCanvases = (container.innerHTML.match(/id="vm-gauge-\d+"/g) || []).length;
        expect(gaugeCanvases).toBe(METRIC_IDS.length);
        expect(chartInstances.length).toBe(METRIC_IDS.length);

        // Formato "N de M pontos" (pontuação de máximo).
        expect(container.innerHTML).toMatch(/\d+(?:\.\d+)? de \d+/);
        expect(container.innerHTML).toContain('pontos');

        // Rótulo de faixa de atendimento: valida uma métrica contra o motor.
        const metricId = METRIC_IDS[0];
        const avg = Aggregation.metricAverage(MONTH, FIRST_JOURNEY, metricId);
        const evalResult = ScoringEngine.evaluateMetric(metricId, avg.avg);
        expect(container.innerHTML).toContain(evalResult.label);
    });

    it('exibe a média dos times da jornada por métrica (5.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('Média dos times');
        // "N time(s)" (contagem de times com dado) presente.
        expect(container.innerHTML).toMatch(/\d+ time\(s\)/);
    });

    it('exibe a distribuição de times por faixa de atendimento (5.4)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Formato de distribuição "N não atendeu · N parcial · N atendeu · N superou".
        expect(container.innerHTML).toMatch(/\d+ não atendeu · \d+ parcial · \d+ atendeu · \d+ superou/);

        // Coerência: a soma das 4 faixas da distribuição textual de uma métrica
        // conhecida é igual ao total retornado por Aggregation.metricDistribution.
        const metricId = METRIC_IDS[0];
        const dist = Aggregation.metricDistribution(MONTH, FIRST_JOURNEY, metricId);
        const expected = `${dist['nao-atendeu']} não atendeu · ${dist['atendeu-parcialmente']} parcial · ` +
            `${dist['atendeu-totalmente']} atendeu · ${dist['superou']} superou`;
        expect(container.innerHTML).toContain(expected);
        // A soma das faixas é o total (invariante de completude).
        const sum = dist['nao-atendeu'] + dist['atendeu-parcialmente'] +
            dist['atendeu-totalmente'] + dist['superou'];
        expect(sum).toBe(dist.total);
    });

    it('troca a jornada via opts.journey e re-renderiza para a jornada escolhida', () => {
        const container = makeContainer();
        const target = PEPData.JORNADAS[1];
        view.render(container, { month: MONTH, journey: target });

        // A opção da jornada escolhida fica "selected".
        expect(container.innerHTML).toContain(`value="${target}" selected`);
    });

    it('indica visualmente a ausência de dado da jornada no mês (2.3 / 5.*)', () => {
        // Mês sem dados (ex.: Dez=12 não está em MONTHS_WITH_DATA).
        const emptyMonth = 12;
        expect(PEPData.getMonthsWithData()).not.toContain(emptyMonth);

        const container = makeContainer();
        view.render(container, { month: emptyMonth });

        expect(container.innerHTML).toContain('vm-empty-journey');
        expect(container.innerHTML).toContain('Sem dados para a jornada');
        // Nenhum gauge desenhado quando não há dado.
        expect(chartInstances.length).toBe(0);
        // O seletor de jornada permanece acessível mesmo sem dado.
        expect(container.innerHTML).toContain('id="vm-journey-select"');
    });

    it('registra o renderer e expõe a API esperada', () => {
        expect(typeof view.render).toBe('function');
        expect(typeof view.buildHTML).toBe('function');
        expect(typeof view.buildMetricModel).toBe('function');
        expect(typeof view.distributionText).toBe('function');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
