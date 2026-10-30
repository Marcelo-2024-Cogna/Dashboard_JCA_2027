// Feature: score-performance-executivo
// Testes de integração/UI dos painéis de fluxo e esforço de Retrospectiva de PI
// (Task 17): Pontos Trabalhados (17.1), Produtividade (17.2) e Situação dos
// Épicos (17.3). Cobrem os Requirements 10, 11 e 12 verificando que cada render
// produz a estrutura esperada a partir do dataset conhecido (retro-data.js),
// reutilizando as agregações já implementadas (retro-aggregation.js).
//
// App estático, sem jsdom: usamos um STUB mínimo de DOM (container com
// innerHTML + querySelector/querySelectorAll + dataset) e um stub de Chart.js,
// no mesmo padrão de tests/view-*.test.js.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const RetroAggregation = require('../js/retro-aggregation.js');
const PEPRetroData = require('../js/retro-data.js');

const PI = PEPRetroData.getLatestPI(); // PI vigente (4/26) — Pontos/Produtividade/Épicos têm dados reais

// Expõe as dependências como globais (equivalente ao carregamento por <script>).
function installGlobals() {
    if (typeof globalThis.window === 'undefined') globalThis.window = globalThis;
    globalThis.RetroAggregation = RetroAggregation;
    globalThis.PEPRetroData = PEPRetroData;
}

// Stub de container: innerHTML + querySelector(All) resolvendo canvases e
// botões/cards por id/classe usados pelos painéis.
function makeContainer() {
    const el = {
        innerHTML: '',
        dataset: {},
        querySelector(sel) {
            const idm = /^#([\w-]+)$/.exec(sel);
            if (idm && this.innerHTML.includes(`id="${idm[1]}"`)) {
                return makeChild(idm[1]);
            }
            return null;
        },
        querySelectorAll(sel) {
            if (sel === '.rep-card') {
                const n = (this.innerHTML.match(/class="rep-card(?:["\s])/g) || []).length;
                return Array.from({ length: n }, () => makeChild('rep-card'));
            }
            return [];
        }
    };
    return el;
}
function makeChild(id) {
    return { id, value: '', dataset: {}, addEventListener() {} };
}

let chartInstances;
function installChartStub() {
    chartInstances = [];
    globalThis.Chart = class {
        constructor(canvas, config) { this.canvas = canvas; this.config = config; chartInstances.push(this); }
        destroy() { this.destroyed = true; }
    };
}
function removeChartStub() { delete globalThis.Chart; chartInstances = []; }

function loadView(file, regKey) {
    installGlobals();
    const p = require.resolve(file);
    delete require.cache[p];
    require(file);
    return globalThis.window.PEPViews[regKey];
}

describe('Painel de Pontos Trabalhados (retro-pontos) — Task 17.1', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-pontos.js', 'retroPontos'); });
    afterEach(() => removeChartStub());

    it('registra window.PEPViews.retroPontos.render', () => {
        expect(typeof view.render).toBe('function');
    });

    it('renderiza cabeçalho, KPIs e tabela por Sprint (10.2, 10.3)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI });
        expect(c.innerHTML).toContain('📦 Pontos Trabalhados');
        // KPIs (10.3).
        expect(c.innerHTML).toContain('Planejado');
        expect(c.innerHTML).toContain('Velocidade');
        // Uma linha por Sprint do PI com dado.
        const linhas = RetroAggregation.pontosPorSprint(PI);
        const rows = (c.innerHTML.match(/rpt-td-left/g) || []).length;
        expect(rows).toBe(linhas.length);
        // O gráfico de barras foi desenhado (empilhado).
        expect(chartInstances.length).toBe(1);
        expect(chartInstances[0].config.options.scales.x.stacked).toBe(true);
    });

    it('a soma Entrega de Valor + Sustentação aparece como total da Sprint (10.1)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI });
        const l = RetroAggregation.pontosPorSprint(PI)[0];
        expect(l.entregaValor + l.sustentacao).toBe(l.total);
        expect(c.innerHTML).toContain(`>${l.total.toFixed(0)}<`);
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});

describe('Painel de Produtividade (retro-produtividade) — Task 17.2', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-produtividade.js', 'retroProdutividade'); });
    afterEach(() => removeChartStub());

    it('registra window.PEPViews.retroProdutividade.render', () => {
        expect(typeof view.render).toBe('function');
    });

    it('renderiza linha por Time + média da Jornada e o gráfico de linhas (11.1, 11.2)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI, mode: 'historico' });
        expect(c.innerHTML).toContain('📈 Produtividade');
        expect(c.innerHTML).toContain('Média da Jornada');
        const agg = RetroAggregation.produtividadePorPI({});
        // Uma linha de dado por Time (td-left) + a linha da média.
        const rows = (c.innerHTML.match(/rpd-td-left/g) || []).length;
        expect(rows).toBe(agg.porTime.length + 1);
        expect(chartInstances.length).toBe(1);
    });

    it('expõe os botões de alternância Histórico / PI vigente (11.3)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI });
        expect(c.innerHTML).toContain('id="rpd-mode-hist"');
        expect(c.innerHTML).toContain('id="rpd-mode-vig"');
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});

describe('Painel de Situação dos Épicos (retro-epicos) — Task 17.3', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-epicos.js', 'retroEpicos'); });
    afterEach(() => removeChartStub());

    it('registra window.PEPViews.retroEpicos.render', () => {
        expect(typeof view.render).toBe('function');
    });

    it('renderiza os 6 cartões de situação e o gráfico Baseline vs Mudança (12.1, 12.2)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI });
        expect(c.innerHTML).toContain('🗂️ Situação dos Épicos');
        for (const label of ['Atrasado', 'Replanejado', 'Em Risco', 'Em Dia', 'Finalizado', 'Despriorizado']) {
            expect(c.innerHTML).toContain(label);
        }
        // 6 cartões de situação.
        const cards = (c.innerHTML.match(/class="rep-card(?:["\s])/g) || []).length;
        expect(cards).toBe(6);
        expect(chartInstances.length).toBe(1);
    });

    it('as contagens de Baseline batem com a agregação (12.1)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI });
        const agg = RetroAggregation.situacaoEpicos(PI);
        // O total de finalizados do baseline aparece no cartão correspondente.
        expect(agg.baseline.finalizado).toBeGreaterThanOrEqual(0);
        expect(c.innerHTML).toContain(`>${agg.baseline.finalizado}<`);
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});
