// Feature: score-performance-executivo
// Teste de propriedade da persistência do Mês Selecionado (Task 7.2).
// Valida o `app-state.js` (AppState) quanto ao Requirement 2.5: ao navegar
// entre as visões executivas, o Mês Selecionado permanece inalterado e é
// aplicado à visão acessada, a menos que o usuário o altere explicitamente
// via setSelectedMonth.
//
// Property coberta: 17 (persistência do Mês Selecionado ao navegar).
//
// _Requirements: 2.5_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const AppState = require('../js/app-state.js');

const NUM_RUNS = 100;

// Visões executivas disponíveis no AppState (fonte: design -> AppState.VIEWS).
const VIEWS = AppState.VIEWS;

// Gerador de um mês inicial válido (1..12).
const genMonth = fc.integer({ min: 1, max: 12 });

// Gerador de uma sequência arbitrária (0..20) de visões de AppState.VIEWS.
const genViewSequence = fc.array(fc.constantFrom(...VIEWS), { minLength: 0, maxLength: 20 });

describe('Property 17: Persistência do Mês Selecionado ao navegar', () => {
    // Feature: score-performance-executivo, Property 17: Para qualquer sequência de navegação entre as visões executivas, o Mês Selecionado permanece inalterado e é aplicado à visão acessada, a menos que o usuário o altere explicitamente pelo Seletor de Mês.
    it('navegar entre visões (setActiveView) mantém o Mês Selecionado inalterado', () => {
        fc.assert(
            fc.property(genMonth, genViewSequence, (initialMonth, viewSeq) => {
                // Usuário define explicitamente o mês inicial.
                AppState.setSelectedMonth(initialMonth);
                expect(AppState.getSelectedMonth()).toBe(initialMonth);

                // Para cada visão acessada na sequência, o mês deve permanecer
                // inalterado e ser aplicado à visão ativa.
                for (const view of viewSeq) {
                    AppState.setActiveView(view);
                    // A visão acessada passa a ser a ativa...
                    expect(AppState.getActiveView()).toBe(view);
                    // ...e o Mês Selecionado NÃO muda ao navegar.
                    expect(AppState.getSelectedMonth()).toBe(initialMonth);
                }

                // Após toda a navegação, o mês continua sendo o definido pelo usuário.
                expect(AppState.getSelectedMonth()).toBe(initialMonth);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 17: Para qualquer sequência de navegação entre as visões executivas, o Mês Selecionado permanece inalterado e é aplicado à visão acessada, a menos que o usuário o altere explicitamente pelo Seletor de Mês.
    it('somente setSelectedMonth no meio da sequência altera o mês; o novo mês persiste na navegação restante', () => {
        fc.assert(
            fc.property(
                genMonth,                                   // mês inicial
                genViewSequence,                            // navegação antes da troca
                genMonth,                                   // novo mês escolhido explicitamente
                genViewSequence,                            // navegação depois da troca
                (initialMonth, viewsBefore, newMonth, viewsAfter) => {
                    // Usuário define o mês inicial e navega.
                    AppState.setSelectedMonth(initialMonth);
                    for (const view of viewsBefore) {
                        AppState.setActiveView(view);
                        expect(AppState.getSelectedMonth()).toBe(initialMonth);
                    }

                    // Usuário altera explicitamente o Mês Selecionado no meio.
                    AppState.setSelectedMonth(newMonth);
                    expect(AppState.getSelectedMonth()).toBe(newMonth);

                    // A partir daqui, navegar entre visões mantém o NOVO mês.
                    for (const view of viewsAfter) {
                        AppState.setActiveView(view);
                        expect(AppState.getActiveView()).toBe(view);
                        expect(AppState.getSelectedMonth()).toBe(newMonth);
                    }

                    // O novo mês persiste após toda a navegação restante.
                    expect(AppState.getSelectedMonth()).toBe(newMonth);
                    return true;
                }
            ),
            { numRuns: NUM_RUNS }
        );
    });
});
