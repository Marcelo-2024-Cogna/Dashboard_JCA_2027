// Feature: score-performance-executivo
// Testes de integração/UI dos painéis de tempo e qualidade de Retrospectiva de
// PI (Task 18): Lead Time (18.2), Débito Técnico (18.3) e Sustentação (18.4).
// Cobrem os Requirements 14, 15 e 16 verificando a estrutura de cada render a
// partir do dataset conhecido (retro-data.js) e das agregações já implementadas.
// Padrão de stub de DOM/Chart idêntico aos demais testes de view.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const RetroAggregation = require('../js/retro-aggregation.js');
const PEPRetroData = require('../js/retro-data.js');

// Os painéis detalhados (Lead Time / Débito / Sustentação) seguem fiéis à
// planilha de Retrospectiva de Agosto, cujo PI de referência é "PI 3/26".
// Após a carga real de Velocity (PIs 1–4/26), o PI vigente passou a ser 4/26,
// mas os dados detalhados só existem até 3/26 — então fixamos o oráculo aqui.
const PI = 'PI 3/26';

function installGlobals() {
    if (typeof globalThis.window === 'undefined') globalThis.window = globalThis;
    globalThis.RetroAggregation = RetroAggregation;
    globalThis.PEPRetroData = PEPRetroData;
}

function makeContainer() {
    return {
        innerHTML: '',
        dataset: {},
        querySelector(sel) {
            const idm = /^#([\w-]+)$/.exec(sel);
            if (idm && this.innerHTML.includes(`id="${idm[1]}"`)) return { id: idm[1] };
            return null;
        },
        querySelectorAll() { return []; }
    };
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

describe('Painel de Lead Time (retro-lead-time) — Task 18.2', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-lead-time.js', 'retroLeadTime'); });
    afterEach(() => removeChartStub());

    it('sem fonte Jira na coleta A1 → exibe "Sem dados" e nenhum gráfico (14.*)', () => {
        // Lead Time não tem fonte viável no Jira (criado→resolvido item a item);
        // por decisão (só PI 4/26 importa), a coleção foi esvaziada.
        const c = makeContainer();
        view.render(c, { pi: 'PI 4/26' });
        expect(RetroAggregation.leadTimePorTipo('PI 4/26').porSquad.length).toBe(0);
        expect(c.innerHTML).toContain('Sem dados de Lead Time');
        expect(chartInstances.length).toBe(0);
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});

describe('Painel de Débito Técnico (retro-debito) — Task 18.3', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-debito.js', 'retroDebito'); });
    afterEach(() => removeChartStub());

    it('exibe backlog por time, gráfico PI e total da Jornada (15.1, 15.2, 15.3)', () => {
        // Débito Técnico agora vem do Jira (PI 4/26), por jornada. A1 coleta
        // quantidade (não Story Points), então validamos a quantidade total.
        const PI_DEB = 'PI 4/26';
        const c = makeContainer();
        view.render(c, { pi: PI_DEB });
        expect(c.innerHTML).toContain('🧰 Débito Técnico');
        expect(c.innerHTML).toContain('Total da Jornada');
        expect(c.innerHTML).toContain('Story Points');
        expect(c.innerHTML).toContain('Quantidade');
        // Dois gráficos: backlog + PI (planejado/real/etc).
        expect(chartInstances.length).toBe(2);
        // Os totais batem com a agregação (quantidade planejada = soma jornadas).
        const agg = RetroAggregation.debitoTecnico(PI_DEB);
        expect(agg.totalJornada.planejadoQtd).toBe(392);
        expect(c.innerHTML).toContain(`>${agg.totalJornada.planejadoQtd.toFixed(0)}<`);
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});

describe('Painel de Sustentação (retro-sustentacao) — Task 18.4', () => {
    let view;
    beforeEach(() => { installChartStub(); view = loadView('../js/views/retro-sustentacao.js', 'retroSustentacao'); });
    afterEach(() => removeChartStub());

    it('sem fonte Jira na coleta A1 → exibe "Sem dados" e nenhum gráfico (16.*)', () => {
        // Sustentação (Realizado vs Planejado) não tem fonte viável no Jira;
        // por decisão (só PI 4/26 importa), a coleção foi esvaziada.
        const c = makeContainer();
        view.render(c, { pi: 'PI 4/26' });
        expect(RetroAggregation.sustentacao('PI 4/26').porTime.length).toBe(0);
        expect(c.innerHTML).toContain('🛟 Sustentação');
        expect(c.innerHTML).toContain('Sem dados de Sustentação');
        expect(chartInstances.length).toBe(0);
    });

    it('render defensivo: container nulo não lança', () => {
        expect(() => view.render(null, { pi: PI })).not.toThrow();
    });
});
