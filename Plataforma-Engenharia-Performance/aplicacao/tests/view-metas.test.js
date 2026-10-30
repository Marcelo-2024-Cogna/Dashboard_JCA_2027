// Feature: score-performance-executivo
// Testes de integração/UI representativos da Visão de Metas (Task 9.5).
// Cobrem o Requirement 7 (7.1–7.7) verificando que o render produz a tabela
// esperada a partir de um dataset conhecido, reutilizando os módulos já
// implementados (targets, aggregation, data).
//
// O app é estático e NÃO há jsdom nas devDependencies; para manter o runner
// leve usamos um STUB mínimo de DOM (container com innerHTML), no mesmo padrão
// de tests/view-jornadas.test.js e tests/view-geral.test.js. Esta visão é uma
// TABELA (sem Chart.js), então validamos a estrutura HTML e o cabeamento de
// dados: colunas, metas Jan–Dez, Partida/Meta%/Meta2026/acréscimo e o Farol
// por jornada no mês selecionado — sem exigir browser/build.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente). A view-metas
// resolve dependências via `dep()` (lê de window/globalThis). Registramos as
// dependências como globais para reproduzir o carregamento por <script>.
const Targets = require('../js/targets.js');
const Aggregation = require('../js/aggregation.js');
const PEPData = require('../js/data.js');

function installGlobals() {
    if (typeof globalThis.window === 'undefined') {
        globalThis.window = globalThis;
    }
    globalThis.Targets = Targets;
    globalThis.Aggregation = Aggregation;
    globalThis.PEPData = PEPData;
}

// Stub mínimo de container (a visão é uma tabela: basta innerHTML).
function makeContainer() {
    return { innerHTML: '' };
}

// Carrega a view registrada em window.PEPViews.metas.
function loadView() {
    installGlobals();
    const path = require.resolve('../js/views/view-metas.js');
    delete require.cache[path];
    require('../js/views/view-metas.js');
    return globalThis.window.PEPViews.metas;
}

// Mês com dados conhecido (o dataset cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();

describe('Visão de Metas (view-metas) — render', () => {
    let view;

    beforeEach(() => {
        view = loadView();
    });

    it('registra o renderer window.PEPViews.metas com API esperada', () => {
        expect(view).toBeTruthy();
        expect(typeof view.render).toBe('function');
    });

    it('renderiza a estrutura e as colunas fixas esperadas (7.6)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('class="mt"');
        expect(container.innerHTML).toContain('🎯 Metas');
        expect(container.innerHTML).toContain(PEPData.MONTH_LABELS[MONTH - 1]);

        // Colunas fixas (7.6).
        expect(container.innerHTML).toContain('>Jornada<');
        expect(container.innerHTML).toContain('>Partida dez/25<');
        expect(container.innerHTML).toContain('>Meta %<');
        expect(container.innerHTML).toContain('>Meta 2026<');
        expect(container.innerHTML).toContain('>Acréscimo/mês<');
    });

    it('exibe os 12 cabeçalhos de mês Jan–Dez (7.6)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        for (const lbl of PEPData.MONTH_LABELS) {
            expect(container.innerHTML).toContain(`>${lbl}<`);
        }
        // Uma linha de dados por jornada.
        const rowCount = (container.innerHTML.match(/class="mt-row"/g) || []).length;
        expect(rowCount).toBe(PEPData.JORNADAS.length);
    });

    it('usa Targets para Partida, Meta 2026 e acréscimo/mês (7.1, 7.2, 7.6)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        const j = PEPData.JORNADAS[0];
        const partida = Targets.partida(j);
        const meta2026 = Targets.meta2026(j);
        const acr = Targets.acrescimoMensal(j);

        expect(container.innerHTML).toContain(partida.toFixed(0));
        expect(container.innerHTML).toContain(meta2026.toFixed(0));
        expect(container.innerHTML).toContain(acr.toFixed(1));
    });

    it('aplica Meta % específica por jornada: 14,5% para Secretaria/Gestor, 5% para as demais (7.7)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Secretaria e Gestor de Unidades = 14,5%; demais = 5,0%.
        expect(container.innerHTML).toContain('14.5%');
        expect(container.innerHTML).toContain('5.0%');

        // Coerência com Targets.metaPct.
        expect(Targets.metaPct('Secretaria e Gestor de Unidades')).toBeCloseTo(0.145, 6);
        expect(Targets.metaPct('Acadêmica')).toBeCloseTo(0.05, 6);
    });

    it('a Meta do Mês 12 (Dez) iguala a Meta 2026 — progressão linear (7.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Para cada jornada, Targets.metaMes(j, 12) === Targets.meta2026(j),
        // e esse valor (arredondado) deve constar na tabela.
        for (const j of PEPData.JORNADAS) {
            const metaDez = Targets.metaMes(j, 12);
            const meta2026 = Targets.meta2026(j);
            expect(metaDez).toBeCloseTo(meta2026, 6);
            expect(container.innerHTML).toContain(metaDez.toFixed(0));
        }
    });

    it('exibe o Farol de cada jornada coerente com Targets.farol no mês selecionado (7.4, 7.5)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        const j = PEPData.JORNADAS[0];
        const realizado = Aggregation.journeyScore(MONTH, j).avgScore;
        const at = Targets.atingimento(j, MONTH, realizado);
        const farolId = Targets.farol(at);
        const expectedLabel = { 'na-meta': 'Na meta', 'atencao': 'Atenção', 'critico': 'Crítico' }[farolId];

        expect(expectedLabel).toBeTruthy();
        expect(container.innerHTML).toContain(expectedLabel);
        // A legenda dos três faróis está presente.
        expect(container.innerHTML).toContain('Na meta ≥ 99%');
        expect(container.innerHTML).toContain('Crítico');
    });

    it('destaca a coluna do mês selecionado (mt-col-sel)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });
        expect(container.innerHTML).toContain('mt-col-sel');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });
});
