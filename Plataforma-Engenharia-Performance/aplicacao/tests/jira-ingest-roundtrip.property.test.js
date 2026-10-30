// Feature: score-performance-executivo
// Testes de PROPRIEDADE do round-trip da exportação reversível da extração do
// Jira (Subtask 23.3). Validam `JiraIngest.toCSV` + `JiraIngest.parseJira(...,'csv')`
// com geradores fast-check (>=100 execuções).
//
// Property 28 (Round-trip dos dados derivados do Jira):
//   "Para qualquer conjunto de itens válidos do Jira (que são a representação
//    reversível dos dados derivados de uma extração), exportar para CSV e
//    importar novamente produz um conjunto equivalente, dentro da tolerância
//    numérica de duas casas decimais."
//
// _Requirements: 21.6, 21.7_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const JiraIngest = require('../js/jira-ingest.js');

const NUM_RUNS = 200;
const TOL = 0.01; // tolerância numérica de duas casas decimais (Property 28)

const pick = (arr) => fc.constantFrom(...arr);

// Domínios realistas da extração do Jira (sem ';' nem quebras de linha — fora
// do escopo da extração, conforme convenção do projeto em toCSV).
const JORNADAS = ['Atendimento e Serviços', 'Acadêmica', 'Financeira', ''];
const SQUADS = ['Atendimento', 'Kbots', 'Papa-léguas', 'Route 66', ''];
const SPRINTS = ['SP 26.1.1', 'SP 26.2.1', 'SP 26.3.1', 'SP 26.3.2'];
const PIS = ['PI 1/26', 'PI 2/26', 'PI 3/26'];
const TIPOS = ['História', 'Enabler', 'Spike', 'Teste', 'Débito Técnico', 'Segurança', 'Incidente', 'Solicitação de Serviço', 'Suporte', 'Épico'];
const STATUS = ['Concluído', 'Em andamento', 'Aberto', 'Cancelado', ''];

// Datas ISO estáveis (ou ausentes). ISO round-trips como string literal.
const dateArb = fc.oneof(
    fc.constant(null),
    pick(['2026-01-05', '2026-02-10T13:30:00', '2026-03-20', '2026-04-01T09:00:00'])
);

const itemArb = fc.record({
    chave: fc.integer({ min: 1, max: 9999 }).map(n => `IT-${n}`),
    tipoItem: pick(TIPOS),
    status: pick(STATUS),
    jornada: pick(JORNADAS),
    squad: pick(SQUADS),
    sprint: pick(SPRINTS),
    pi: pick(PIS),
    // storyPoints: null, inteiro, ou decimal não-negativo.
    storyPoints: fc.oneof(
        fc.constant(null),
        fc.integer({ min: 0, max: 100 }),
        fc.float({ min: Math.fround(0), max: Math.fround(100), noNaN: true })
    ),
    criadoEm: dateArb,
    inicioDev: dateArb,
    fimDev: dateArb,
    homologadoEm: dateArb,
    implantadoEm: dateArb,
    resolvidoEm: dateArb
});

const itemsArb = fc.array(itemArb, { minLength: 0, maxLength: 50 });

// Normaliza um item gerado para o shape canônico que o parser produz, para que
// a comparação do round-trip seja feita contra o mesmo contrato de normalização
// (strings vazias de datas → null; storyPoints numérico/null preservado).
function normalizeExpected(it) {
    const strip = (s) => (s == null ? '' : String(s).trim());
    const dateOrNull = (s) => (strip(s) || null);
    return {
        chave: strip(it.chave),
        tipoItem: strip(it.tipoItem),
        status: strip(it.status),
        jornada: strip(it.jornada),
        squad: strip(it.squad),
        sprint: strip(it.sprint),
        pi: strip(it.pi),
        storyPoints: it.storyPoints == null ? null : Number(it.storyPoints),
        criadoEm: dateOrNull(it.criadoEm),
        inicioDev: dateOrNull(it.inicioDev),
        fimDev: dateOrNull(it.fimDev),
        homologadoEm: dateOrNull(it.homologadoEm),
        implantadoEm: dateOrNull(it.implantadoEm),
        resolvidoEm: dateOrNull(it.resolvidoEm)
    };
}

const STRING_FIELDS = ['chave', 'tipoItem', 'status', 'jornada', 'squad', 'sprint', 'pi'];
const DATE_FIELDS = ['criadoEm', 'inicioDev', 'fimDev', 'homologadoEm', 'implantadoEm', 'resolvidoEm'];

function assertEquivalent(expected, actual) {
    // Campos de texto preservados exatamente.
    STRING_FIELDS.forEach(f => expect(actual[f]).toBe(expected[f]));
    // Campos de data: null/''→null preservado consistentemente.
    DATE_FIELDS.forEach(f => expect(actual[f]).toBe(expected[f]));
    // storyPoints: equivalência de null + tolerância numérica de 0.01.
    if (expected.storyPoints == null) {
        expect(actual.storyPoints).toBeNull();
    } else {
        expect(actual.storyPoints).not.toBeNull();
        expect(Math.abs(actual.storyPoints - expected.storyPoints)).toBeLessThanOrEqual(TOL);
    }
}

describe('toCSV + parseJira — Property 28: round-trip reversível dos itens', () => {

    // Feature: score-performance-executivo, Property 28: para qualquer conjunto
    // de itens válidos do Jira, exportar com toCSV e reimportar com parseJira
    // (csv) produz um conjunto equivalente, dentro da tolerância de 0.01.
    it('exportar (toCSV) e reimportar (parseJira csv) preserva os itens dentro de 0.01', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const esperado = items.map(normalizeExpected);

            const csv = JiraIngest.toCSV(esperado);
            const { items: back, errors } = JiraIngest.parseJira(csv, 'csv');

            // Round-trip não deve inventar erros nem perder/duplicar itens.
            expect(errors.length).toBe(0);
            expect(back.length).toBe(esperado.length);

            esperado.forEach((exp, i) => assertEquivalent(exp, back[i]));
        }), { numRuns: NUM_RUNS });
    });

    // Feature: score-performance-executivo, Property 28: o round-trip é
    // IDEMPOTENTE — reaplicar toCSV sobre os itens reimportados produz exatamente
    // o mesmo texto CSV (ponto fixo), confirmando a estabilidade do contrato.
    it('é idempotente: toCSV(parseJira(toCSV(items))) == toCSV(items)', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const esperado = items.map(normalizeExpected);
            const csv1 = JiraIngest.toCSV(esperado);
            const back = JiraIngest.parseJira(csv1, 'csv').items;
            const csv2 = JiraIngest.toCSV(back);
            expect(csv2).toBe(csv1);
        }), { numRuns: NUM_RUNS });
    });

    // Feature: score-performance-executivo, Property 28: o CSV exportado respeita
    // o contrato de formato — prefixo BOM UTF-8, separador ';' e cabeçalho
    // canônico (FIELDS) em ordem fixa — do qual o round-trip depende.
    it('mantém o contrato de formato: BOM, separador ";" e cabeçalho canônico', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const esperado = items.map(normalizeExpected);
            const csv = JiraIngest.toCSV(esperado);
            expect(csv.charCodeAt(0)).toBe(0xFEFF); // BOM UTF-8
            const header = csv.replace(/^\uFEFF/, '').split('\n')[0];
            expect(header).toBe(JiraIngest.FIELDS.join(';'));
            // Uma linha de cabeçalho + uma por item.
            const linhas = csv.replace(/^\uFEFF/, '').split('\n');
            expect(linhas.length).toBe(esperado.length + 1);
        }), { numRuns: NUM_RUNS });
    });
});
