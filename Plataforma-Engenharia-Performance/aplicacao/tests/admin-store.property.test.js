// Feature: score-performance-executivo
// Testes de PROPRIEDADE do round-trip reversível das coleções de gestão
// (Subtask 33.1). Validam `AdminStore.toCSV` + `AdminStore.fromCSV` e
// `AdminStore.toJSON` + `AdminStore.fromJSON` com geradores fast-check
// (>=100 execuções).
//
// Property 37 (Round-trip das coleções de gestão):
//   "Para qualquer coleção de gestão válida, fromCSV(toCSV(coleção)) produz um
//    conjunto equivalente, dentro da tolerância de duas casas decimais quando
//    numérico."
//
// _Requirements: 31.8_

import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const AdminStore = require('../js/admin-store.js');

const NUM_RUNS = 200;
const TOL = 0.01; // tolerância numérica de duas casas decimais (Property 37)

// Limpa o storage (memória, em Node) antes de cada execução para isolar casos.
beforeEach(() => {
    AdminStore.clear();
});

// -----------------------------------------------------------------------------
// Geradores de valores por tipo, respeitando a convenção do projeto:
//   - textos SEM ';' (separador CSV), SEM '|' (separador interno de listas),
//     SEM quebras de linha e sem espaços nas pontas (o parser faz trim()).
//   - números com até 2 casas (round-trip tolerante a 0.01) ou null (sem dado).
// -----------------------------------------------------------------------------
const safeText = fc.string({ minLength: 0, maxLength: 20 })
    .map(s => s.replace(/[;|\r\n]/g, ' ').trim());

const safeNonEmptyText = fc.string({ minLength: 1, maxLength: 20 })
    .map(s => s.replace(/[;|\r\n]/g, ' ').trim())
    // Garante não-vazio após o saneamento (fallback estável).
    .map(s => (s === '' ? 'x' : s));

const numArb = fc.oneof(
    fc.constant(null),
    fc.integer({ min: 0, max: 1000 }),
    // Decimais com 2 casas → representáveis exatamente no round-trip.
    fc.integer({ min: 0, max: 100000 }).map(n => Math.round(n) / 100)
);

const boolArb = fc.boolean();

const listArb = fc.array(safeNonEmptyText, { minLength: 0, maxLength: 5 })
    // Itens de lista também não podem conter '|' (já saneado por safeText).
    .map(arr => arr.filter(x => x !== ''));

// Gera o id (não vazio para que o round-trip preserve a identidade estável).
const idArb = fc.integer({ min: 1, max: 1e9 }).map(n => `id-${n}`);

// Fábrica de gerador de item a partir do schema da coleção.
function itemArbFor(collection) {
    const schema = AdminStore.SCHEMAS[collection];
    const shape = {};
    schema.fields.forEach(({ key, kind }) => {
        if (key === 'id') { shape[key] = idArb; return; }
        switch (kind) {
            case 'num': shape[key] = numArb; break;
            case 'bool': shape[key] = boolArb; break;
            case 'list': shape[key] = listArb; break;
            case 'text':
            default: shape[key] = safeText; break;
        }
    });
    return fc.record(shape);
}

function collectionArbFor(collection) {
    return fc.array(itemArbFor(collection), { minLength: 0, maxLength: 30 })
        // Garante ids únicos dentro da coleção (identidade estável do round-trip).
        .map(items => {
            const seen = new Set();
            return items.filter(it => {
                if (seen.has(it.id)) return false;
                seen.add(it.id);
                return true;
            });
        });
}

// Normaliza uma coleção gerada para o shape canônico que o store produz, de
// modo que a comparação do round-trip use o mesmo contrato de normalização.
function expectedFor(collection, items) {
    return items.map(it => AdminStore.normalizeItem(collection, it));
}

// Compara dois itens campo a campo conforme o tipo (tolerância em numéricos).
function assertItemEquivalent(collection, expected, actual) {
    const schema = AdminStore.SCHEMAS[collection];
    schema.fields.forEach(({ key, kind }) => {
        if (kind === 'num') {
            if (expected[key] === null) {
                expect(actual[key]).toBeNull();
            } else {
                expect(actual[key]).not.toBeNull();
                expect(Math.abs(actual[key] - expected[key])).toBeLessThanOrEqual(TOL);
            }
        } else if (kind === 'bool') {
            expect(actual[key]).toBe(expected[key]);
        } else if (kind === 'list') {
            expect(actual[key]).toEqual(expected[key]);
        } else {
            expect(actual[key]).toBe(expected[key]);
        }
    });
}

function assertCollectionEquivalent(collection, expected, actual) {
    expect(actual.length).toBe(expected.length);
    expected.forEach((exp, i) => assertItemEquivalent(collection, exp, actual[i]));
}

describe('AdminStore — Property 37: round-trip reversível das coleções de gestão', () => {

    AdminStore.COLLECTIONS.forEach((collection) => {

        // Feature: score-performance-executivo, Property 37: para qualquer
        // coleção de gestão válida, exportar com toCSV e reimportar com fromCSV
        // produz um conjunto equivalente, dentro da tolerância de 0.01.
        it(`[${collection}] fromCSV(toCSV(coleção)) é equivalente (tol. 0.01)`, () => {
            fc.assert(fc.property(collectionArbFor(collection), (items) => {
                const esperado = expectedFor(collection, items);

                const csv = AdminStore.toCSV(collection, esperado);
                const { imported, rejected, records } = AdminStore.fromCSV(collection, csv);

                // Round-trip não deve rejeitar nada nem perder/duplicar itens.
                expect(rejected.length).toBe(0);
                expect(imported).toBe(esperado.length);
                assertCollectionEquivalent(collection, esperado, records);
            }), { numRuns: NUM_RUNS });
        });

        // Feature: score-performance-executivo, Property 37: o round-trip CSV é
        // IDEMPOTENTE — reaplicar toCSV sobre os itens reimportados produz
        // exatamente o mesmo texto (ponto fixo), confirmando a estabilidade.
        it(`[${collection}] round-trip CSV é idempotente (ponto fixo do texto)`, () => {
            fc.assert(fc.property(collectionArbFor(collection), (items) => {
                const esperado = expectedFor(collection, items);
                const csv1 = AdminStore.toCSV(collection, esperado);
                const back = AdminStore.fromCSV(collection, csv1).records;
                const csv2 = AdminStore.toCSV(collection, back);
                expect(csv2).toBe(csv1);
            }), { numRuns: NUM_RUNS });
        });

        // Feature: score-performance-executivo, Property 37: a reversibilidade
        // também vale para o formato JSON (fromJSON(toJSON(coleção)) equivalente).
        it(`[${collection}] fromJSON(toJSON(coleção)) é equivalente (tol. 0.01)`, () => {
            fc.assert(fc.property(collectionArbFor(collection), (items) => {
                const esperado = expectedFor(collection, items);

                const json = AdminStore.toJSON(collection, esperado);
                const { imported, rejected, records } = AdminStore.fromJSON(collection, json);

                expect(rejected.length).toBe(0);
                expect(imported).toBe(esperado.length);
                assertCollectionEquivalent(collection, esperado, records);
            }), { numRuns: NUM_RUNS });
        });

        // Feature: score-performance-executivo, Property 37: o CSV exportado
        // respeita o contrato de formato — BOM UTF-8, separador ';' e cabeçalho
        // canônico (ordem do schema) — do qual o round-trip depende.
        it(`[${collection}] CSV mantém o contrato: BOM, separador ";" e cabeçalho canônico`, () => {
            fc.assert(fc.property(collectionArbFor(collection), (items) => {
                const esperado = expectedFor(collection, items);
                const csv = AdminStore.toCSV(collection, esperado);
                expect(csv.charCodeAt(0)).toBe(0xFEFF); // BOM UTF-8
                const linhas = csv.replace(/^\uFEFF/, '').split('\r\n');
                const header = linhas[0];
                const expectedHeader = AdminStore.SCHEMAS[collection].fields.map(f => f.key).join(';');
                expect(header).toBe(expectedHeader);
                expect(linhas.length).toBe(esperado.length + 1);
            }), { numRuns: NUM_RUNS });
        });
    });
});
