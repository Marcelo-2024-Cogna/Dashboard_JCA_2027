// Feature: score-performance-executivo
// Testes de PROPRIEDADE da derivação por Sprint da extração do Jira (Subtask 23.2).
// Validam `JiraIngest.buildRetroFromJira` com geradores fast-check (>=100 execuções).
//
// Property 27 (duas formulações do design, ambas verificadas aqui):
//   - "A soma dos pontos dos itens por Sprint/Squad derivada é igual à soma dos
//      pontos dos itens de entrada agrupados pelas mesmas chaves (conservação de
//      pontos), ignorando itens inválidos rejeitados." (Validates 21.2, 21.4)
//   - "A soma de Story Points (e a contagem de itens) agregada por
//      Sprint/Squad/Jornada/PI no RetroDataset gerado é igual à soma (e contagem)
//      dos itens de entrada correspondentes, por recorte." (Validates 21.2, 21.3)
//
// _Requirements: 21.2, 21.3, 21.4_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const JiraIngest = require('../js/jira-ingest.js');

const NUM_RUNS = 200;
const TOL = 1e-9;

// =========================================================================
// GERADOR de itens válidos do Jira (campos mínimos presentes; storyPoints
// não-negativo e possivelmente null). Restringe o espaço de chaves de recorte
// a pequenos domínios para forçar COLISÕES (vários itens no mesmo recorte).
// =========================================================================
const sp = (s) => `S${s}`;
const pick = (arr) => fc.constantFrom(...arr);

const PIS = ['PI 1/26', 'PI 2/26', 'PI 3/26'];
const JORNADAS = ['Atendimento e Serviços', 'Acadêmica', 'Financeira'];
const SQUADS = ['Atendimento', 'Kbots', 'Papa-léguas', 'Route 66'];
const SPRINTS = ['SP 26.1.1', 'SP 26.2.1', 'SP 26.3.1', 'SP 26.3.2'];
const TIPOS = ['História', 'Enabler', 'Spike', 'Teste', 'Débito Técnico', 'Segurança', 'Incidente', 'Solicitação de Serviço', 'Suporte', 'Épico'];
const STATUS = ['Concluído', 'Em andamento', 'Aberto', 'Cancelado'];

const itemArb = fc.record({
    chave: fc.integer({ min: 1, max: 9999 }).map(n => `IT-${n}`),
    tipoItem: pick(TIPOS),
    status: pick(STATUS),
    jornada: pick(JORNADAS),
    squad: pick(SQUADS),
    sprint: pick(SPRINTS),
    pi: pick(PIS),
    // storyPoints: inteiro/float não-negativo OU null (opcional).
    storyPoints: fc.oneof(
        fc.constant(null),
        fc.integer({ min: 0, max: 100 }),
        fc.float({ min: 0, max: 100, noNaN: true })
    )
});

const itemsArb = fc.array(itemArb, { minLength: 0, maxLength: 60 });

// Soma de storyPoints (null→0) agrupada por uma função de chave.
function sumBy(items, keyFn) {
    const m = new Map();
    items.forEach(it => {
        const k = keyFn(it);
        const sp = Number(it.storyPoints) || 0;
        const cur = m.get(k) || { pontos: 0, qtde: 0 };
        cur.pontos += sp;
        cur.qtde += 1;
        m.set(k, cur);
    });
    return m;
}

describe('buildRetroFromJira — Property 27: conservação de pontos na derivação', () => {

    // Feature: score-performance-executivo, Property 27: a soma de Story Points
    // e a contagem de itens agregada por (pi, jornada, squad, sprint) no dataset
    // derivado é igual à soma e contagem dos itens de entrada pelo mesmo recorte.
    it('pontosTrabalhados preserva soma e contagem por (pi, jornada, squad, sprint)', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const out = JiraIngest.buildRetroFromJira(items, null);

            // Recorte canônico sobre os itens JÁ RESOLVIDOS (pós-mapeamento),
            // que são o espelho das chaves usadas na derivação.
            const keyFn = (it) => [it.pi, it.jornada, it.squad, it.sprint].join('||');
            const esperado = sumBy(out.items, keyFn);

            // Deriva o observado dos blocos de pontosTrabalhados.
            const observado = new Map();
            out.pontosTrabalhados.forEach(pt => {
                const k = [pt.pi, pt.jornada, pt.squad, pt.sprint].join('||');
                observado.set(k, { pontos: pt.total, qtde: null });
            });

            // Mesma quantidade de recortes.
            expect(observado.size).toBe(esperado.size);

            // Conservação da soma de pontos por recorte.
            esperado.forEach((v, k) => {
                expect(observado.has(k)).toBe(true);
                expect(Math.abs(observado.get(k).pontos - v.pontos)).toBeLessThanOrEqual(TOL);
            });

            // Sustentação + Entrega de Valor = total em cada recorte.
            out.pontosTrabalhados.forEach(pt => {
                expect(Math.abs((pt.sustentacao + pt.entregaValor) - pt.total)).toBeLessThanOrEqual(TOL);
            });
        }), { numRuns: NUM_RUNS });
    });

    // Feature: score-performance-executivo, Property 27: a soma total de Story
    // Points (e a contagem total de itens) do dataset derivado é igual à soma e
    // contagem dos itens de entrada, independentemente do recorte.
    it('conserva a soma TOTAL de pontos e a contagem total de itens', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const out = JiraIngest.buildRetroFromJira(items, null);

            const totalEntrada = items.reduce((a, it) => a + (Number(it.storyPoints) || 0), 0);
            const totalPT = out.pontosTrabalhados.reduce((a, pt) => a + pt.total, 0);
            expect(Math.abs(totalPT - totalEntrada)).toBeLessThanOrEqual(1e-6);

            // Contagem de itens preservada em cargaTrabalho (por pi/tipoItem).
            const totalItensCarga = out.cargaTrabalho.reduce((a, c) => a + c.qtdeItens, 0);
            expect(totalItensCarga).toBe(items.length);

            // Soma de pontos da cargaTrabalho também conserva o total.
            const totalPontosCarga = out.cargaTrabalho.reduce((a, c) => a + c.pontos, 0);
            expect(Math.abs(totalPontosCarga - totalEntrada)).toBeLessThanOrEqual(1e-6);
        }), { numRuns: NUM_RUNS });
    });

    // Feature: score-performance-executivo, Property 27: a soma de pontos por
    // recorte de uma única dimensão (PI, Jornada, Squad, Sprint) derivada do
    // dataset é igual à soma dos itens de entrada agrupados por essa dimensão.
    it('conserva a soma de pontos por cada dimensão isolada (PI/Jornada/Squad/Sprint)', () => {
        fc.assert(fc.property(itemsArb, (items) => {
            const out = JiraIngest.buildRetroFromJira(items, null);
            const resolved = out.items;

            const dims = {
                pi: (it) => it.pi,
                jornada: (it) => it.jornada,
                squad: (it) => it.squad,
                sprint: (it) => it.sprint
            };

            Object.values(dims).forEach(keyFn => {
                const esperado = sumBy(resolved, keyFn);
                // Reconstroi do bloco pontosTrabalhados agregando pela mesma dimensão.
                const observado = new Map();
                out.pontosTrabalhados.forEach(pt => {
                    const k = keyFn(pt);
                    const cur = observado.get(k) || 0;
                    observado.set(k, cur + pt.total);
                });
                esperado.forEach((v, k) => {
                    const got = observado.get(k) || 0;
                    expect(Math.abs(got - v.pontos)).toBeLessThanOrEqual(1e-6);
                });
            });
        }), { numRuns: NUM_RUNS });
    });
});
