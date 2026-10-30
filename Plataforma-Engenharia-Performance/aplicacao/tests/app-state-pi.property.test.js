// Feature: score-performance-executivo
// Teste de propriedade da independência Seletor de PI × Seletor de Mês (Task 15).
// Valida o `app-state.js` (AppState) quanto aos Requirements 18.7 e 19.3: o PI
// Selecionado dos painéis de Retrospectiva de PI é persistido em uma chave
// PRÓPRIA e é totalmente independente do Mês Selecionado do Score — mudar um
// nunca altera o outro, em qualquer ordem/sequência de alterações.
//
// Property coberta: 25 (Independência do Seletor de PI e do Seletor de Mês).
//
// _Requirements: 18.7, 19.3_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const AppState = require('../js/app-state.js');
const PEPRetroData = require('../js/retro-data.js');

const NUM_RUNS = 100;

// Geradores do espaço de entrada:
//  - mês válido 1..12 (contrato de setSelectedMonth);
//  - PI entre os PIs reais do dataset (contrato de setSelectedPI, string não-vazia).
const genMonth = fc.integer({ min: 1, max: 12 });
const genPI = fc.constantFrom(...PEPRetroData.getPIs());

// Uma "ação" é alterar o mês OU o PI. A sequência mistura as duas dimensões
// em ordem arbitrária, exercitando a independência nos dois sentidos.
const genAction = fc.oneof(
    genMonth.map((month) => ({ kind: 'month', value: month })),
    genPI.map((pi) => ({ kind: 'pi', value: pi }))
);
const genActionSequence = fc.array(genAction, { minLength: 0, maxLength: 30 });

describe('Property 25: Independência do Seletor de PI e do Seletor de Mês', () => {
    // Feature: score-performance-executivo, Property 25: Para qualquer sequência de alterações do PI selecionado, o Mês Selecionado do Score permanece inalterado, e vice-versa.
    it('alterar o PI NÃO altera o Mês Selecionado (qualquer PI inicial × qualquer sequência de PIs)', () => {
        fc.assert(
            fc.property(genMonth, fc.array(genPI, { minLength: 0, maxLength: 30 }), (initialMonth, piSeq) => {
                // Usuário fixa o mês inicial do Score.
                AppState.setSelectedMonth(initialMonth);
                expect(AppState.getSelectedMonth()).toBe(initialMonth);

                // Qualquer sequência de trocas de PI mantém o mês inalterado.
                for (const pi of piSeq) {
                    AppState.setSelectedPI(pi);
                    expect(AppState.getSelectedPI()).toBe(pi);
                    expect(AppState.getSelectedMonth()).toBe(initialMonth);
                }

                // O mês continua sendo o escolhido pelo usuário.
                expect(AppState.getSelectedMonth()).toBe(initialMonth);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 25: Para qualquer sequência de alterações do PI selecionado, o Mês Selecionado do Score permanece inalterado, e vice-versa.
    it('alterar o Mês NÃO altera o PI Selecionado (qualquer PI inicial × qualquer sequência de meses)', () => {
        fc.assert(
            fc.property(genPI, fc.array(genMonth, { minLength: 0, maxLength: 30 }), (initialPI, monthSeq) => {
                // Usuário fixa o PI inicial da Retrospectiva.
                AppState.setSelectedPI(initialPI);
                expect(AppState.getSelectedPI()).toBe(initialPI);

                // Qualquer sequência de trocas de mês mantém o PI inalterado.
                for (const month of monthSeq) {
                    AppState.setSelectedMonth(month);
                    expect(AppState.getSelectedMonth()).toBe(month);
                    expect(AppState.getSelectedPI()).toBe(initialPI);
                }

                // O PI continua sendo o escolhido pelo usuário.
                expect(AppState.getSelectedPI()).toBe(initialPI);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 25: Para qualquer sequência de alterações do PI selecionado, o Mês Selecionado do Score permanece inalterado, e vice-versa.
    it('sequência arbitrária intercalando mês e PI: cada dimensão só muda por ação própria', () => {
        fc.assert(
            fc.property(genMonth, genPI, genActionSequence, (initialMonth, initialPI, actions) => {
                // Estado inicial explícito nas duas dimensões.
                AppState.setSelectedMonth(initialMonth);
                AppState.setSelectedPI(initialPI);

                // Rastreamos o esperado em cada dimensão de forma independente.
                let expectedMonth = initialMonth;
                let expectedPI = initialPI;

                for (const action of actions) {
                    if (action.kind === 'month') {
                        AppState.setSelectedMonth(action.value);
                        expectedMonth = action.value; // só o mês muda
                    } else {
                        AppState.setSelectedPI(action.value);
                        expectedPI = action.value;     // só o PI muda
                    }
                    // Após CADA ação, ambas as dimensões batem com o esperado:
                    // a dimensão não tocada permanece exatamente onde estava.
                    expect(AppState.getSelectedMonth()).toBe(expectedMonth);
                    expect(AppState.getSelectedPI()).toBe(expectedPI);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    it('expõe RETRO_VIEWS com as 8 visões dos painéis de Retrospectiva de PI (18.7)', () => {
        expect(AppState.RETRO_VIEWS).toEqual([
            'retroPontos', 'retroProdutividade', 'retroEpicos', 'retroCycleTime',
            'retroLeadTime', 'retroDebito', 'retroSustentacao', 'retroExtras'
        ]);
    });
});
