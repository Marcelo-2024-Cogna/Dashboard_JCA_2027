// Feature: score-performance-executivo
// Testes de propriedade e exemplo da importação/exportação (Task 6.3).
//
// Cobertura:
//   - Property 16 (round-trip): parseCSV(toCSV(ds)) ≈ ds, dentro de 0,01, para
//     datasets mensais válidos (month 1..12, jornada, teamName, métricas nas
//     escalas corretas incluindo null = Sem Dados).
//   - Exemplos: (a) registro incompleto é rejeitado por validateRecord/importText
//     com mensagem descritiva; (b) toCSV inicia com BOM ('\uFEFF') e usa ';'.
//
// _Requirements: 8.4, 8.5, 8.7, 9.5_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const DataImport = require('../js/data-import.js');

const NUM_RUNS = 100;
const TOL = 0.01; // tolerância de duas casas decimais (Requirement 8.7)

const {
    METRIC_FIELDS,
    METRIC_KEYS,
    MONTH_LABELS,
    CSV_HEADER,
    CSV_SEPARATOR,
    BOM
} = DataImport;

// =========================================================================
// GERADORES (fast-check) — produzem registros mensais VÁLIDOS, respeitando o
// domínio de cada escala de métrica e permitindo explicitamente `null`
// (Sem Dados). Pelo menos UMA métrica tem valor por registro, pois
// validateRecord exige ao menos um valor de métrica presente.
//
// As escalas (kind) vêm de METRIC_FIELDS do próprio módulo:
//   ratio    → [0, 2]  (1.0 = 100%; aceita "Superou" acima de 100%)
//   days     → [0, 12] (Cycle Time)
//   category → inteiro [0, 6] (Frequência de Deploy)
//   thc0a10  → [0, 10] (Team Health Check)
//
// Para garantir round-trip exato dentro de 0,01, limitamos os decimais a 2
// casas (toCSV usa String(n) e parseCSV reparseia via Number(); valores com
// muitas casas poderiam, em teoria, sofrer ruído de ponto flutuante — 2 casas
// é o contrato de equivalência do Requirement 8.7).
// =========================================================================

// Número com no máximo 2 casas decimais dentro de [min, max].
function gen2dp(min, max) {
    const steps = Math.round((max - min) * 100);
    return fc.integer({ min: 0, max: steps }).map(i => Math.round((min + i / 100) * 100) / 100);
}

function genMetricValue(kind) {
    let valueGen;
    switch (kind) {
        case 'ratio':    valueGen = gen2dp(0, 2); break;
        case 'days':     valueGen = gen2dp(0, 12); break;
        case 'category': valueGen = fc.integer({ min: 0, max: 6 }); break;
        case 'thc0a10':  valueGen = gen2dp(0, 10); break;
        default:         valueGen = gen2dp(0, 1); break;
    }
    // Mistura valores do domínio com null (Sem Dados).
    return fc.oneof(valueGen, fc.constant(null));
}

// Gera o objeto `metrics` com as 12 chaves; garante ≥1 valor não-null forçando
// a primeira métrica a ter um valor numérico do seu domínio.
function genMetrics() {
    const shape = {};
    METRIC_FIELDS.forEach(({ key, kind }, idx) => {
        if (idx === 0) {
            // Primeira métrica sempre com valor (sem null) para satisfazer a
            // regra "ao menos uma métrica com valor" do validateRecord.
            switch (kind) {
                case 'ratio':    shape[key] = gen2dp(0, 2); break;
                case 'days':     shape[key] = gen2dp(0, 12); break;
                case 'category': shape[key] = fc.integer({ min: 0, max: 6 }); break;
                case 'thc0a10':  shape[key] = gen2dp(0, 10); break;
                default:         shape[key] = gen2dp(0, 1); break;
            }
        } else {
            shape[key] = genMetricValue(kind);
        }
    });
    return fc.record(shape);
}

// Jornada/time: strings não vazias e SEM o separador ';' nem quebras de linha
// (o CSV simples do módulo não escapa separadores embutidos).
const genSafeText = fc.string({ minLength: 1, maxLength: 20 })
    .map(s => s.replace(/[;\r\n\uFEFF]/g, ' ').trim())
    .filter(s => s.length > 0);

function genRecord() {
    return fc.record({
        month: fc.integer({ min: 1, max: 12 }),
        jornada: genSafeText,
        teamName: genSafeText,
        metrics: genMetrics()
    });
}

// Dataset mensal: lista não vazia de registros válidos.
function genDataset() {
    return fc.record({
        year: fc.constant(2026),
        records: fc.array(genRecord(), { minLength: 1, maxLength: 15 })
    });
}

// Compara dois valores de métrica permitindo null==null e número dentro de TOL.
function metricsEquivalent(a, b) {
    const an = (a === null || a === undefined) ? null : a;
    const bn = (b === null || b === undefined) ? null : b;
    if (an === null || bn === null) return an === bn;
    return Math.abs(an - bn) <= TOL;
}

// =========================================================================
// Property 16: Round-trip de exportação/importação CSV
// =========================================================================
describe('Property 16: Round-trip parseCSV(toCSV(ds)) equivalente dentro de 0,01', () => {
    // Feature: score-performance-executivo, Property 16: Para qualquer conjunto de dados mensais válido, exportar para CSV (separador ';', UTF-8 com BOM) e importar novamente produz um conjunto de dados equivalente ao original, dentro da tolerância numérica de duas casas decimais.
    it('toCSV → importText(csv) preserva month, jornada, teamName e métricas (±0,01)', () => {
        fc.assert(
            fc.property(genDataset(), (ds) => {
                const csv = DataImport.toCSV(ds);

                // Exportação deve ser reconhecível pela importação (sem erros estruturais).
                const result = DataImport.importText(csv, 'csv');
                expect(result.parseErrors).toEqual([]);
                expect(result.imported).toBe(ds.records.length);
                expect(result.rejected).toEqual([]);
                expect(result.records.length).toBe(ds.records.length);

                // A ordem é preservada pelo serializador/parser (linha a linha).
                ds.records.forEach((original, i) => {
                    const roundTripped = result.records[i];
                    expect(roundTripped.month).toBe(original.month);
                    expect(roundTripped.jornada).toBe(original.jornada);
                    expect(roundTripped.teamName).toBe(original.teamName);
                    METRIC_KEYS.forEach((key) => {
                        const ok = metricsEquivalent(original.metrics[key], roundTripped.metrics[key]);
                        expect(ok, `métrica ${key}: ${original.metrics[key]} vs ${roundTripped.metrics[key]}`).toBe(true);
                    });
                });
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Verifica que null (Sem Dados) continua null após o round-trip.
    it('null (Sem Dados) permanece null após o round-trip', () => {
        fc.assert(
            fc.property(genDataset(), (ds) => {
                const result = DataImport.importText(DataImport.toCSV(ds), 'csv');
                ds.records.forEach((original, i) => {
                    const roundTripped = result.records[i];
                    METRIC_KEYS.forEach((key) => {
                        if (original.metrics[key] === null) {
                            expect(roundTripped.metrics[key]).toBeNull();
                        }
                    });
                });
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Exemplo (a): registro incompleto é rejeitado com mensagem descritiva.
// _Requirements: 8.4, 8.5_
// =========================================================================
describe('Exemplo: registro incompleto é rejeitado com mensagem descritiva', () => {
    const validMetrics = () => {
        const m = {};
        METRIC_FIELDS.forEach(({ key, kind }) => {
            m[key] = kind === 'category' ? 3 : (kind === 'thc0a10' ? 8 : (kind === 'days' ? 2 : 1.0));
        });
        return m;
    };

    it('validateRecord rejeita registro sem mês, com mensagem citando "mes"', () => {
        const rec = { jornada: 'Acadêmica', teamName: 'Colaborar', metrics: validMetrics() };
        const { valid, errors } = DataImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /mes/i.test(e))).toBe(true);
    });

    it('validateRecord rejeita registro sem jornada e sem time, citando ambos', () => {
        const rec = { month: 3, jornada: '', teamName: '   ', metrics: validMetrics() };
        const { valid, errors } = DataImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /jornada/i.test(e))).toBe(true);
        expect(errors.some(e => /time/i.test(e))).toBe(true);
    });

    it('validateRecord rejeita registro sem nenhum valor de métrica', () => {
        const emptyMetrics = {};
        METRIC_KEYS.forEach(k => { emptyMetrics[k] = null; });
        const rec = { month: 3, jornada: 'Acadêmica', teamName: 'Colaborar', metrics: emptyMetrics };
        const { valid, errors } = DataImport.validateRecord(rec);
        expect(valid).toBe(false);
        expect(errors.some(e => /métrica|metric/i.test(e))).toBe(true);
    });

    it('importText (CSV) separa registros inválidos em rejected[] com mensagem; importa os válidos', () => {
        // Linha 2: completa (válida). Linha 3: faltando jornada e time (inválida).
        const metricCols = METRIC_KEYS.map(k => {
            const field = METRIC_FIELDS.find(f => f.key === k);
            return field.kind === 'category' ? '3' : (field.kind === 'thc0a10' ? '8' : (field.kind === 'days' ? '2' : '1'));
        }).join(CSV_SEPARATOR);

        const csv = [
            CSV_HEADER.join(CSV_SEPARATOR),
            ['3', 'Acadêmica', 'Colaborar', metricCols].join(CSV_SEPARATOR),
            ['3', '', '', metricCols].join(CSV_SEPARATOR)
        ].join('\r\n');

        const result = DataImport.importText(csv, 'csv');
        expect(result.parseErrors).toEqual([]);
        expect(result.imported).toBe(1);
        expect(result.rejected.length).toBe(1);
        expect(Array.isArray(result.rejected[0].errors)).toBe(true);
        expect(result.rejected[0].errors.length).toBeGreaterThan(0);
        expect(result.rejected[0].errors.some(e => /jornada/i.test(e))).toBe(true);
        expect(result.rejected[0].errors.some(e => /time/i.test(e))).toBe(true);
    });
});

// =========================================================================
// Exemplo (b): toCSV inicia com BOM e usa ';'.
// _Requirements: 8.7, 9.5_
// =========================================================================
describe('Exemplo: toCSV inicia com BOM UTF-8 e usa separador ";"', () => {
    const sampleDataset = {
        year: 2026,
        records: [
            {
                month: 1,
                jornada: 'Acadêmica',
                teamName: 'Colaborar',
                metrics: (() => {
                    const m = {};
                    METRIC_FIELDS.forEach(({ key, kind }) => {
                        m[key] = kind === 'category' ? 3 : (kind === 'thc0a10' ? 8 : (kind === 'days' ? 2 : 1.0));
                    });
                    return m;
                })()
            }
        ]
    };

    it('o CSV exportado começa com o caractere BOM (\\uFEFF)', () => {
        const csv = DataImport.toCSV(sampleDataset);
        expect(csv.charCodeAt(0)).toBe(0xFEFF);
        expect(csv.startsWith(BOM)).toBe(true);
    });

    it('o cabeçalho usa ";" como separador e corresponde ao CSV_HEADER canônico', () => {
        const csv = DataImport.toCSV(sampleDataset);
        const firstLine = csv.slice(BOM.length).split(/\r\n|\n/)[0];
        expect(firstLine).toBe(CSV_HEADER.join(';'));
        expect(firstLine).toContain(';');
        expect(firstLine.startsWith('mes;jornada;time;')).toBe(true);
    });

    it('as linhas de dados usam ";" e refletem mês/jornada/time', () => {
        const csv = DataImport.toCSV(sampleDataset);
        const lines = csv.slice(BOM.length).split(/\r\n|\n/);
        const dataLine = lines[1];
        const cells = dataLine.split(';');
        expect(cells[0]).toBe('1');            // mes
        expect(cells[1]).toBe('Acadêmica');    // jornada
        expect(cells[2]).toBe('Colaborar');    // time
        expect(cells.length).toBe(CSV_HEADER.length);
    });
});
