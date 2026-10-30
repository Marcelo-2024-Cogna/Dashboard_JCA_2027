// Feature: score-performance-executivo
// Testes de integração/UI representativos da Evolução Mensal (Task 9.4).
// Cobrem o Requirement 6 (6.1–6.5) verificando que o render produz a estrutura
// de tabela esperada a partir de um dataset conhecido, reutilizando os módulos
// já implementados (aggregation, targets, data).
//
// O app é estático e NÃO há jsdom nas devDependencies; para manter o runner
// leve usamos um STUB mínimo de DOM (container com innerHTML + querySelector),
// no mesmo padrão de tests/view-jornadas.test.js e tests/view-geral.test.js.
// Isso valida a estrutura HTML (tabela Jan–Dez × jornadas + Engenharia),
// a coloração por Farol das células, o estado "sem dado" (cinza) e a
// alternância de modo "Score realizado" / "% da meta", sem exigir browser/build.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Módulos reutilizados (IIFE expostos via guarda de ambiente).
const Aggregation = require('../js/aggregation.js');
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

// -----------------------------------------------------------------------------
// Stub mínimo de container: um "elemento" com innerHTML e querySelector capaz
// de localizar os botões de modo por id (#ve-mode-score / #ve-mode-meta). O
// render não desenha Chart.js nesta visão (é uma tabela), então não é preciso
// stub de Chart.
// -----------------------------------------------------------------------------
function makeContainer() {
    return {
        innerHTML: '',
        querySelector(sel) {
            const m = /^#(ve-mode-score|ve-mode-meta)$/.exec(sel);
            if (m && this.innerHTML.includes(`id="${m[1]}"`)) {
                return { id: m[1] }; // sem addEventListener → wire é defensivo
            }
            return null;
        }
    };
}

// Carrega a view DEPOIS de preparar o ambiente, recarregando limpa para resetar
// o estado interno (displayMode) entre casos.
function loadView() {
    const path = require.resolve('../js/views/view-evolucao.js');
    delete require.cache[path];
    return require('../js/views/view-evolucao.js');
}

// Meses de referência do dataset (cobre Jan..Ago = 1..8).
const MONTH = PEPData.getLatestMonth();
const EMPTY_MONTH = 12; // Dez não está em MONTHS_WITH_DATA.

describe('Evolução Mensal (view-evolucao) — render', () => {
    let view;

    beforeEach(() => {
        view = loadView();
        view._resetState();
    });

    it('registra o renderer e expõe a API esperada', () => {
        expect(typeof view.render).toBe('function');
        expect(typeof view.buildHTML).toBe('function');
        expect(typeof view.buildCell).toBe('function');
    });

    it('render é defensivo: container ausente não lança', () => {
        expect(() => view.render(null, { month: MONTH })).not.toThrow();
    });

    it('renderiza a tabela com Jan–Dez em colunas e jornadas + Engenharia em linhas (6.1)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        expect(container.innerHTML).toContain('📅 Evolução Mensal');

        // 12 colunas de mês (cabeçalho) + a coluna de rótulo de linha.
        const monthHeaders = (container.innerHTML.match(/ve-th-month/g) || []).length;
        expect(monthHeaders).toBe(12);
        for (const label of PEPData.MONTH_LABELS) {
            expect(container.innerHTML).toContain(label);
        }

        // Uma linha por jornada + a linha de Engenharia.
        const rowCount = (container.innerHTML.match(/class="ve-row(?:["\s])/g) || []).length;
        expect(rowCount).toBe(PEPData.JORNADAS.length + 1);
        expect(container.innerHTML).toContain('Engenharia (total)');
        // Cada jornada presente como rótulo de linha.
        for (const name of PEPData.JORNADAS) {
            expect(container.innerHTML).toContain(name);
        }
    });

    it('exibe o score realizado nas células com dado no modo "Score realizado" (6.2)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, mode: 'score' });

        // O score de uma jornada no mês de referência aparece na tabela.
        const first = PEPData.JORNADAS[0];
        const js = Aggregation.journeyScore(MONTH, first);
        expect(js.hasData).toBe(true);
        const expected = js.avgScore.toFixed(0);
        expect(container.innerHTML).toContain(`>${expected}</span>`);

        // O score de Engenharia (média das jornadas) também aparece.
        const eng = Aggregation.engineeringScore(MONTH);
        expect(container.innerHTML).toContain(`>${eng.avgScore.toFixed(0)}</span>`);
    });

    it('colore cada célula com dado pelo Farol realizado ÷ meta do mês (6.3)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, mode: 'score' });

        // Para a 1ª jornada no mês de referência, a classe de Farol da célula
        // deve bater com Targets.farol(atingimento).
        const first = PEPData.JORNADAS[0];
        const js = Aggregation.journeyScore(MONTH, first);
        const at = Targets.atingimento(first, MONTH, js.avgScore);
        const farolId = Targets.farol(at);
        expect(farolId).toBeTruthy();
        expect(container.innerHTML).toContain(`ve-farol-${farolId}`);

        // A legenda de Farol está presente.
        expect(container.innerHTML).toContain('Na meta');
        expect(container.innerHTML).toContain('Atenção');
        expect(container.innerHTML).toContain('Crítico');
    });

    it('exibe células sem dado em cinza para jornada/mês sem registro (6.4)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });

        // Dez (coluna 12) não tem dado para nenhuma jornada → células vazias.
        expect(PEPData.getMonthsWithData()).not.toContain(EMPTY_MONTH);
        const emptyCells = (container.innerHTML.match(/ve-cell-empty/g) || []).length;
        // Ao menos uma célula vazia por linha nos meses sem dado (Out..Dez = 3
        // meses × (jornadas + Engenharia)); legenda também usa a classe (+1).
        expect(emptyCells).toBeGreaterThanOrEqual(
            (PEPData.JORNADAS.length + 1) * 3
        );
    });

    it('alterna o modo de exibição para "% da meta" (6.5)', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH, mode: 'meta' });

        // No modo meta, o botão "% da meta" está ativo.
        expect(container.innerHTML).toContain('id="ve-mode-meta"');
        expect(container.innerHTML).toMatch(/ve-mode-meta"[^>]*aria-pressed="true"/);

        // As células com atingimento disponível mostram percentuais ("NN%").
        const first = PEPData.JORNADAS[0];
        const js = Aggregation.journeyScore(MONTH, first);
        const at = Targets.atingimento(first, MONTH, js.avgScore);
        if (typeof at === 'number' && isFinite(at)) {
            const expectedPct = `${(at * 100).toFixed(0)}%`;
            expect(container.innerHTML).toContain(`>${expectedPct}</span>`);
        }
    });

    it('destaca a coluna do mês selecionado', () => {
        const container = makeContainer();
        view.render(container, { month: MONTH });
        expect(container.innerHTML).toContain('ve-col-selected');
    });

    it('buildCell retorna hasData=false para jornada/mês sem dado (6.4)', () => {
        const cell = view.buildCell(Aggregation, Targets, PEPData, EMPTY_MONTH, PEPData.JORNADAS[0]);
        expect(cell.hasData).toBe(false);
        expect(cell.score).toBeNull();
        expect(cell.farolId).toBeNull();
    });

    it('entityScore computa o total de Engenharia como média das jornadas', () => {
        const score = view.entityScore(Aggregation, MONTH, '__engenharia__');
        const eng = Aggregation.engineeringScore(MONTH);
        expect(Math.round(score)).toBe(Math.round(eng.avgScore));
    });
});
