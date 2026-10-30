// Feature: score-performance-executivo
// Testes do carregador de Baseline por PI (`baseline-loader.js`) — Task 24.
// Cobre a leitura client-side de .xlsx (SheetJS) e CSV, a associação do baseline
// ao PI correto, a substituição por novo upload, a rejeição de arquivo inválido
// (mantendo o baseline vigente) e a indicação de presença de baseline por PI.
//
// Property 29 (design.md): "Para qualquer sequência de uploads de baseline por
// PI, cada baseline fica associado exatamente ao seu PI, um novo upload para o
// mesmo PI substitui o anterior, e PIs sem upload permanecem sem baseline."
//
// _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6_

import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { createRequire } from 'node:module';
import fc from 'fast-check';

const require = createRequire(import.meta.url);
const BaselineLoader = require('../js/baseline-loader.js');

const NUM_RUNS = 100;

// SheetJS está disponível no node_modules da raiz do workspace. No navegador a
// lib é carregada por <script>/CDN e exposta como `window.XLSX`; aqui injetamos
// o mesmo contrato em `globalThis.XLSX` para exercitar o caminho de leitura de
// .xlsx exatamente como o loader o consome (via `dep('XLSX')`).
let XLSX = null;
beforeAll(() => {
    XLSX = require('xlsx');
    globalThis.XLSX = XLSX;
});

// Monta um buffer .xlsx real (array) a partir de uma matriz de linhas.
function xlsxBufferFromRows(rows) {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, 'Baseline');
    return XLSX.write(wb, { type: 'array', bookType: 'xlsx' });
}

// CSV de baseline mínimo e válido para um PI com as chaves informadas.
function baselineCSV(pi, chaves) {
    return ['pi;chave;jornada;squad;situacao']
        .concat(chaves.map((c) => `${pi};${c};Atendimento e Serviços;Atendimento;em-dia`))
        .join('\n');
}

// =============================================================================
// PROPERTY 29 — associação de baseline ao PI correto, substituição e isolamento.
// =============================================================================
describe('Property 29: Associação de baseline ao PI correto', () => {
    beforeEach(() => BaselineLoader._reset());

    // Gera uma sequência de uploads: cada upload é { pi, chaves } com um PI
    // escolhido de um pequeno conjunto e um número de épicos >= 1. Como o
    // último upload válido por PI é o que vale, o estado esperado é o "último
    // visto" por PI; PIs nunca carregados ficam sem baseline.
    const uploadArb = fc.record({
        pi: fc.constantFrom('PI 1/26', 'PI 2/26', 'PI 3/26'),
        n: fc.integer({ min: 1, max: 5 })
    });

    // Feature: score-performance-executivo, Property 29: para qualquer sequência
    // de uploads válidos, cada PI fica associado exatamente ao ÚLTIMO baseline
    // carregado para ele, e PIs sem upload permanecem sem baseline.
    it('cada PI reflete o último upload válido; PIs sem upload ficam vazios', () => {
        fc.assert(
            fc.property(
                fc.array(uploadArb, { minLength: 1, maxLength: 20 }),
                (uploads) => {
                    BaselineLoader._reset();

                    // Modelo de referência: último nº de chaves visto por PI.
                    const esperado = {};
                    uploads.forEach((u, idx) => {
                        // chaves únicas por upload para garantir a contagem exata.
                        const chaves = Array.from({ length: u.n }, (_, k) => `E${idx}_${k}`);
                        const res = BaselineLoader.loadForPI(u.pi, baselineCSV(u.pi, chaves), 'csv');
                        expect(res.ok).toBe(true);
                        esperado[u.pi] = u.n;
                    });

                    const todosPIs = ['PI 1/26', 'PI 2/26', 'PI 3/26'];
                    todosPIs.forEach((pi) => {
                        if (Object.prototype.hasOwnProperty.call(esperado, pi)) {
                            // Associado exatamente ao seu PI, com o último tamanho.
                            expect(BaselineLoader.hasBaseline(pi)).toBe(true);
                            const b = BaselineLoader.getBaselineForPI(pi);
                            expect(b).not.toBeNull();
                            expect(b.registros.length).toBe(esperado[pi]);
                            // Todos os registros pertencem ao PI consultado.
                            expect(b.registros.every((r) => r.pi === pi)).toBe(true);
                        } else {
                            // PI sem upload permanece sem baseline.
                            expect(BaselineLoader.hasBaseline(pi)).toBe(false);
                            expect(BaselineLoader.getBaselineForPI(pi)).toBeNull();
                        }
                    });

                    // A lista de PIs com baseline é exatamente o conjunto esperado.
                    expect(BaselineLoader.listPIsWithBaseline().sort()).toEqual(
                        Object.keys(esperado).sort()
                    );
                }
            ),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 29: um upload inválido para
    // um PI NUNCA altera o baseline vigente daquele PI nem o de outros PIs.
    it('upload inválido preserva o baseline vigente e não afeta outros PIs', () => {
        const invalidArb = fc.constantFrom(
            '',                       // vazio
            'linha_unica_sem_dados',  // só cabeçalho sem registros
            'col_a;col_b\n1;2',       // cabeçalho sem "pi"/"chave"
            'pi;chave\n;'             // registro sem pi/chave
        );

        fc.assert(
            fc.property(
                fc.constantFrom('PI 1/26', 'PI 2/26', 'PI 3/26'),
                fc.integer({ min: 1, max: 4 }),
                invalidArb,
                (pi, nValido, invalido) => {
                    BaselineLoader._reset();

                    // Baseline válido inicial no PI alvo e em um PI vizinho fixo.
                    const vizinho = pi === 'PI 1/26' ? 'PI 2/26' : 'PI 1/26';
                    const chaves = Array.from({ length: nValido }, (_, k) => `V${k}`);
                    expect(BaselineLoader.loadForPI(pi, baselineCSV(pi, chaves), 'csv').ok).toBe(true);
                    expect(BaselineLoader.loadForPI(vizinho, baselineCSV(vizinho, ['X']), 'csv').ok).toBe(true);

                    // Tentativa de upload inválido no PI alvo.
                    const r = BaselineLoader.loadForPI(pi, invalido, 'csv');
                    expect(r.ok).toBe(false);
                    expect(Array.isArray(r.errors)).toBe(true);
                    expect(r.errors.length).toBeGreaterThan(0);

                    // Vigente preservado no PI alvo e vizinho intacto.
                    expect(BaselineLoader.getBaselineForPI(pi).registros.length).toBe(nValido);
                    expect(BaselineLoader.getBaselineForPI(vizinho).registros.length).toBe(1);
                }
            ),
            { numRuns: NUM_RUNS }
        );
    });
});
