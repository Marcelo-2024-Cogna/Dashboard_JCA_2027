// Feature: score-performance-executivo
// Testes de propriedade e exemplo da importação/exportação de Retrospectiva de
// PI (Task 14).
//
// Cobertura:
//   - Property 24 (round-trip): parseCSV(toCSV(ds)) ≈ ds, dentro de 0,01, para
//     RetroDatasets válidos — preservando o TIPO (coleção) e os campos de cada
//     registro (PI/Time/indicador). Inclui o round-trip do dataset de exemplo
//     real PEPRetroData (js/retro-data.js).
//   - Exemplos de rejeição com mensagem descritiva (Requirements 18.3, 18.4) e
//     verificação de que o CSV inicia com BOM e usa ';'.
//
// _Requirements: 18.2, 18.3, 18.4, 18.6_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const RetroImport = require('../js/retro-import.js');
const PEPRetroData = require('../js/retro-data.js');

const NUM_RUNS = 100;
const TOL = 0.01; // tolerância de duas casas decimais (Requirement 18.6)

const { CSV_HEADER, CSV_SEPARATOR, BOM, TYPE_ORDER, TYPE_SCHEMAS } = RetroImport;

// =========================================================================
// GERADORES (fast-check)
// Produzem registros VÁLIDOS por tipo, respeitando a regra de validação:
// PI/Time (ou equivalente) presentes e ao menos um indicador com valor.
// Números limitados a 2 casas decimais para garantir round-trip ±0,01
// (toCSV usa String(n) + parseCSV reparseia via Number()).
// =========================================================================

// Número com no máximo 2 casas decimais em [min, max].
function gen2dp(min, max) {
    const steps = Math.round((max - min) * 100);
    return fc.integer({ min: 0, max: steps }).map(i => Math.round((min + i / 100) * 100) / 100);
}

// Número do indicador: valor em [0,500] (2 casas) OU null (Sem Dados).
const genNumOrNull = fc.oneof(gen2dp(0, 500), fc.constant(null));

// Texto seguro: sem o separador ';', quebras de linha, BOM nem os separadores
// internos de mapa ('|','=') para não corromper o round-trip.
const genSafeText = fc.string({ minLength: 1, maxLength: 15 })
    .map(s => s.replace(/[;\r\n\uFEFF|=]/g, ' ').trim())
    .filter(s => s.length > 0);

// Para um schema, gera um registro: campos obrigatórios sempre presentes;
// o primeiro indicador sempre com valor numérico; demais numéricos oneof(null).
function genRecordForType(tipo) {
    const schema = TYPE_SCHEMAS[tipo];
    const shape = { tipo: fc.constant(tipo) };

    const requiredSet = new Set(schema.required);
    const firstIndicator = schema.indicator[0];

    schema.fields.forEach((f) => {
        if (f.kind === 'map') {
            // Mapa não vazio com 1..3 chaves (chaves textuais seguras, valores 2dp).
            shape[f.key] = fc.dictionary(
                genSafeText,
                gen2dp(0, 50),
                { minKeys: 1, maxKeys: 3 }
            );
        } else if (f.kind === 'num') {
            if (f.key === firstIndicator) {
                shape[f.key] = gen2dp(0, 500); // indicador garantido
            } else {
                shape[f.key] = genNumOrNull;
            }
        } else if (f.kind === 'textOrNull') {
            shape[f.key] = fc.oneof(genSafeText, fc.constant(null));
        } else { // text
            if (requiredSet.has(f.key) || f.key === firstIndicator) {
                shape[f.key] = genSafeText; // obrigatório/indicador textual presente
            } else {
                shape[f.key] = fc.oneof(genSafeText, fc.constant(undefined));
            }
        }
    });

    // Sem requiredKeys: todas as chaves do shape são sempre geradas, garantindo
    // que os campos obrigatórios e o indicador estejam presentes. Campos de
    // texto opcionais usam oneof(..., constant(undefined)).
    return fc.record(shape);
}

// Gera um array de registros de tipos variados.
function genRecords() {
    const perType = TYPE_ORDER.map(t => genRecordForType(t));
    return fc.array(fc.oneof(...perType), { minLength: 1, maxLength: 20 });
}

// =========================================================================
// Comparação de equivalência entre registro original e round-tripped.
// =========================================================================
function numEquivalent(a, b) {
    const an = (a === null || a === undefined) ? null : a;
    const bn = (b === null || b === undefined) ? null : b;
    if (an === null || bn === null) return an === bn;
    return Math.abs(an - bn) <= TOL;
}

function recordsEquivalent(original, actual) {
    expect(actual.tipo).toBe(original.tipo);
    const schema = TYPE_SCHEMAS[original.tipo];
    schema.fields.forEach((f) => {
        const o = original[f.key];
        const a = actual[f.key];
        if (f.kind === 'num') {
            // undefined no original (campo texto ausente não se aplica aqui);
            // ausência numérica equivale a null.
            expect(
                numEquivalent(o, a),
                `${original.tipo}.${f.key}: ${o} vs ${a}`
            ).toBe(true);
        } else if (f.kind === 'map') {
            const om = o || {};
            const am = a || {};
            expect(Object.keys(am).sort()).toEqual(Object.keys(om).sort());
            Object.keys(om).forEach((k) => {
                expect(numEquivalent(om[k], am[k]), `${f.key}.${k}`).toBe(true);
            });
        } else if (f.kind === 'textOrNull') {
            expect(a === undefined ? null : a).toBe(o === undefined ? null : o);
        } else { // text
            // Texto ausente (undefined) no original → ausente/undefined no actual.
            if (o === undefined || o === null || String(o).trim() === '') {
                expect(a === undefined || a === null || String(a).trim() === '').toBe(true);
            } else {
                expect(a).toBe(o);
            }
        }
    });
}

// =========================================================================
// Property 24: Round-trip de importação/exportação Retro PI
// =========================================================================
describe('Property 24: Round-trip parseCSV(toCSV(ds)) equivalente dentro de 0,01', () => {
    // Feature: score-performance-executivo, Property 24: Para qualquer RetroDataset válido, exportar para CSV (separador ';', UTF-8 com BOM) e importar novamente produz um conjunto de dados equivalente, preservando o tipo (coleção) e os campos, dentro da tolerância de duas casas decimais.
    it('toCSV → importText(csv) preserva tipo, PI/Time e campos do indicador (±0,01)', () => {
        fc.assert(
            fc.property(genRecords(), (records) => {
                const csv = RetroImport.toCSV(records);
                const result = RetroImport.importText(csv, 'csv');

                expect(result.parseErrors).toEqual([]);
                expect(result.rejected).toEqual([]);
                expect(result.imported).toBe(records.length);
                expect(result.records.length).toBe(records.length);

                records.forEach((original, i) => {
                    recordsEquivalent(original, result.records[i]);
                });
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // O round-trip também vale para o dataset de exemplo real (planilha).
    it('round-trip do dataset de exemplo PEPRetroData preserva tipo e campos', () => {
        const csv = RetroImport.toCSV(PEPRetroData.DATASET);
        const result = RetroImport.importText(csv, 'csv');

        expect(result.parseErrors).toEqual([]);
        // Nenhum registro de exemplo deve ser rejeitado.
        expect(result.rejected).toEqual([]);
        expect(result.imported).toBeGreaterThan(0);

        // Achata o dataset na mesma ordem canônica usada por toCSV e compara.
        const flat = RetroImport.flattenDataset(PEPRetroData.DATASET);
        expect(result.records.length).toBe(flat.length);
        flat.forEach((original, i) => {
            recordsEquivalent(original, result.records[i]);
        });
    });

    // Round-trip via JSON (coleções no topo) → CSV → import.
    it('toCSV aceita o Dataset com coleções no topo (achatamento por tipo)', () => {
        const csv = RetroImport.toCSV(PEPRetroData.DATASET);
        expect(csv.charCodeAt(0)).toBe(0xFEFF);
        // Pelo menos uma linha de cada tipo presente no dataset.
        const body = csv.slice(BOM.length);
        TYPE_ORDER.forEach((tipo) => {
            if (Array.isArray(PEPRetroData.DATASET[tipo]) && PEPRetroData.DATASET[tipo].length) {
                expect(body).toContain(`\n${tipo};`);
            }
        });
    });
});

// =========================================================================
// Exemplos de rejeição com mensagem descritiva (Requirements 18.3, 18.4)
// =========================================================================
describe('Exemplo: registro incompleto é rejeitado com mensagem descritiva', () => {
    it('validateRecord rejeita registro sem tipo, citando "tipo"', () => {
        const { valid, errors } = RetroImport.validateRecord({ pi: 'PI 3/26', time: 'Kbots' });
        expect(valid).toBe(false);
        expect(errors.some(e => /tipo/i.test(e))).toBe(true);
    });

    it('validateRecord rejeita cycleTime sem PI e sem Time, citando ambos', () => {
        const rec = { tipo: 'cycleTime', ideacao: 10, desenvolvimento: 2 };
        const { valid, errors } = RetroImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /pi/i.test(e))).toBe(true);
        expect(errors.some(e => /time/i.test(e))).toBe(true);
    });

    it('validateRecord rejeita pontosTrabalhados sem nenhum indicador, citando "indicador"', () => {
        const rec = { tipo: 'pontosTrabalhados', pi: 'PI 3/26', time: 'Kbots' };
        const { valid, errors } = RetroImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /indicador/i.test(e))).toBe(true);
    });

    it('validateRecord rejeita valor não numérico em campo numérico', () => {
        const rec = { tipo: 'sustentacao', pi: 'PI 3/26', time: 'Kbots', realizado: NaN, planejado: 10 };
        const { valid, errors } = RetroImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /realizado/i.test(e))).toBe(true);
    });

    it('importText (CSV) separa inválidos em rejected[] com mensagem; importa os válidos', () => {
        const header = CSV_HEADER.join(CSV_SEPARATOR);
        // Linha válida (cycleTime com PI/Time/indicador) e linha inválida (sem PI/Time).
        const colIndex = {};
        CSV_HEADER.forEach((c, i) => { colIndex[c] = i; });

        const buildLine = (vals) => {
            const cells = new Array(CSV_HEADER.length).fill('');
            Object.keys(vals).forEach((k) => { cells[colIndex[k]] = String(vals[k]); });
            return cells.join(CSV_SEPARATOR);
        };

        const validLine = buildLine({ tipo: 'cycleTime', pi: 'PI 3/26', time: 'Kbots', ideacao: '18,7', desenvolvimento: '2.5' });
        const invalidLine = buildLine({ tipo: 'cycleTime', ideacao: '5' }); // sem pi/time

        const csv = BOM + [header, validLine, invalidLine].join('\r\n');
        const result = RetroImport.importText(csv, 'csv');

        expect(result.parseErrors).toEqual([]);
        expect(result.imported).toBe(1);
        expect(result.rejected.length).toBe(1);
        expect(result.rejected[0].errors.length).toBeGreaterThan(0);
        expect(result.rejected[0].errors.some(e => /pi/i.test(e))).toBe(true);
        expect(result.rejected[0].errors.some(e => /time/i.test(e))).toBe(true);
        // Decimal com vírgula foi normalizado para ponto no registro importado.
        expect(result.records[0].ideacao).toBeCloseTo(18.7, 5);
        expect(result.records[0].desenvolvimento).toBeCloseTo(2.5, 5);
    });

    it('importText (CSV) acusa cabeçalho sem a coluna "tipo"', () => {
        const csv = BOM + ['pi;time;ideacao', 'PI 3/26;Kbots;10'].join('\r\n');
        const result = RetroImport.importText(csv, 'csv');
        expect(result.imported).toBe(0);
        expect(result.parseErrors.length).toBeGreaterThan(0);
        expect(result.parseErrors.some(e => /tipo/i.test(e))).toBe(true);
    });
});

// =========================================================================
// Exemplo: toCSV inicia com BOM UTF-8 e usa separador ';'.
// =========================================================================
describe('Exemplo: toCSV inicia com BOM UTF-8 e usa separador ";"', () => {
    const sample = [
        { tipo: 'cycleTime', pi: 'PI 3/26', time: 'Kbots', jornada: 'J', ideacao: 18.7, desenvolvimento: 2.5, homologacao: 1.1, implantacao: 0 }
    ];

    it('o CSV exportado começa com o caractere BOM (\\uFEFF)', () => {
        const csv = RetroImport.toCSV(sample);
        expect(csv.charCodeAt(0)).toBe(0xFEFF);
        expect(csv.startsWith(BOM)).toBe(true);
    });

    it('o cabeçalho usa ";" e começa por "tipo"', () => {
        const csv = RetroImport.toCSV(sample);
        const firstLine = csv.slice(BOM.length).split(/\r\n|\n/)[0];
        expect(firstLine).toBe(CSV_HEADER.join(';'));
        expect(firstLine.startsWith('tipo;')).toBe(true);
    });
});
