// Feature: score-performance-executivo
// Testes de integração/UI do painel de extras de Retrospectiva de PI (Task 19):
// Carga de Trabalho, Alterações no Plano e Impedimentos. Cobre o Requirement 17
// verificando a estrutura das três seções a partir do dataset conhecido.
// Também cobre o requisito representativo da Task 19 de "render isolado" e
// "ausência de dado" (17.4). Padrão de stub de DOM/Chart dos demais testes.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPRetroData = require('../js/retro-data.js');
const RetroAggregation = require('../js/retro-aggregation.js');
// Carga de Trabalho agora vem do Jira (PI 4/26, visão de hoje), por jornada ×
// tipo. Alterações no Plano e Impedimentos seguem fiéis à planilha de
// Retrospectiva de Agosto (PI 3/26), pois não têm fonte Jira viável na coleta
// A1. Por isso os painéis são exercitados com o PI adequado a cada seção.
const PI_CARGA = 'PI 4/26';
const PI = 'PI 3/26';

function installGlobals() {
    if (typeof globalThis.window === 'undefined') globalThis.window = globalThis;
    globalThis.PEPRetroData = PEPRetroData;
    globalThis.RetroAggregation = RetroAggregation;
}
function makeContainer() {
    return {
        innerHTML: '',
        dataset: {},
        querySelector(sel) {
            const idm = /^#([\w-]+)$/.exec(sel);
            if (idm && this.innerHTML.includes(`id="${idm[1]}"`)) return { id: idm[1] };
            return null;
        }
    };
}
let chartInstances;
function installChartStub() {
    chartInstances = [];
    globalThis.Chart = class { constructor(c, cfg) { this.config = cfg; chartInstances.push(this); } destroy() {} };
}
function removeChartStub() { delete globalThis.Chart; chartInstances = []; }

function loadView() {
    installGlobals();
    const p = require.resolve('../js/views/retro-extras.js');
    delete require.cache[p];
    require('../js/views/retro-extras.js');
    return globalThis.window.PEPViews.retroExtras;
}

describe('Painel de Extras (retro-extras) — Task 19', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView(); });
    afterEach(() => removeChartStub());

    it('registra window.PEPViews.retroExtras.render', () => {
        expect(typeof view.render).toBe('function');
    });

    it('renderiza Carga (PI 4/26 — Jira) com colunas e tipos conhecidos (17.1)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI_CARGA });
        expect(c.innerHTML).toContain('🧩 Carga, Alterações e Impedimentos');
        // Carga de Trabalho (17.1): colunas e tipos conhecidos (Jira PI 4/26).
        expect(c.innerHTML).toContain('Carga Total de Trabalho');
        expect(c.innerHTML).toContain('Pontuação Média');
        expect(c.innerHTML).toContain('Incidente');
        expect(c.innerHTML).toContain('História');
        // Gráfico de rosca da carga desenhado (há dados de carga no PI 4/26).
        expect(chartInstances.length).toBe(1);
    });

    it('Alterações no Plano e Impedimentos: sem fonte Jira (A1) → "Sem dados" (17.2, 17.3)', () => {
        const c = makeContainer();
        view.render(c, { pi: PI_CARGA });
        // Os títulos das seções permanecem, mas o corpo indica ausência de dado.
        expect(c.innerHTML).toContain('Alterações no Plano');
        expect(c.innerHTML).toContain('Impedimentos');
        expect(c.innerHTML).toContain('Sem dado para o PI selecionado.');
        // Alterações + Impedimentos vazios = pelo menos 2 blocos de "sem dado".
        const vazios = (c.innerHTML.match(/rex-nodata-block/g) || []).length;
        expect(vazios).toBeGreaterThanOrEqual(2);
    });

    it('indica ausência de dado quando o PI não possui registros (17.4)', () => {
        const c = makeContainer();
        view.render(c, { pi: 'PI 9/99' }); // PI inexistente
        // Carga, Alterações e Impedimentos sem dado → 3 blocos de "sem dado".
        const vazios = (c.innerHTML.match(/rex-nodata-block/g) || []).length;
        expect(vazios).toBeGreaterThanOrEqual(3);
        expect(c.innerHTML).toContain('Sem dado para o PI selecionado.');
        // Sem dados de carga, nenhum gráfico é desenhado.
        expect(chartInstances.length).toBe(0);
    });

    it('render é defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});
