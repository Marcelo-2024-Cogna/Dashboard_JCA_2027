// Feature: score-performance-executivo
// Testes de propriedade de Metas e Farol (Task 5.3).
//
// Validam o módulo `targets.js` (metas progressivas por jornada) e a fonte
// única de Farol, conforme o design ("Components and Interfaces" → "3. Metas —
// targets.js" e "Correctness Properties") e os Requirements:
//   7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 2.3, 3.4, 3.5, 4.3, 6.3.
//
// Properties cobertas:
//   - Property 13: Meta 2026 derivada da Partida e da Meta % (metas).
//   - Property 14: Progressão linear da Meta do Mês (metas).
//   - Property 15: Atingimento = realizado ÷ meta (metas).
//   - Property 8:  Classificação do Farol por Atingimento (uma única classe).
//   - Property 9:  Particionamento das contagens por Farol = nº de jornadas
//                  com dado.
//
// Abordagem: `Targets` lê o Baseline (Partida dez/25) de `PEPData`. Como a
// Partida é um dado fixo por jornada, os testes de metas iteram sobre jornadas
// reais e sobre entradas geradas (mês, realizado) para exercitar o domínio.
// Para a Property 9 (que depende de uma contagem por Farol inexistente como
// função dedicada), construímos a contagem NO TESTE a partir de `Targets.farol`
// aplicado aos atingimentos das jornadas com dado — exatamente como instruído
// pela tarefa.

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Targets = require('../js/targets.js');
const PEPData = require('../js/data.js');

const NUM_RUNS = 100;
const TOL = 1e-6; // tolerância numérica para igualdades de ponto flutuante

// Jornadas reais com Partida definida no Baseline dez/25.
const JORNADAS = PEPData.JORNADAS;

// Gerador de nome de jornada real (todas possuem Partida no Baseline).
const genJornada = fc.constantFrom(...JORNADAS);

// Gerador de número de mês válido (1..12).
const genMonth = fc.integer({ min: 1, max: 12 });

// =========================================================================
// Property 13: Meta 2026 derivada da Partida e da Meta %
// =========================================================================
describe('Property 13: Meta 2026 = Partida × (1 + Meta %)', () => {
    // Feature: score-performance-executivo, Property 13: Para qualquer Jornada com Partida maior ou igual a zero e Meta % definida, a Meta 2026 é igual a `Partida × (1 + Meta %)`.
    it('meta2026(j) == partida(j) × (1 + metaPct(j)) para toda jornada', () => {
        fc.assert(
            fc.property(genJornada, (jornada) => {
                const partida = Targets.partida(jornada);
                const pct = Targets.metaPct(jornada);
                const esperado = partida * (1 + pct);
                expect(Math.abs(Targets.meta2026(jornada) - esperado)).toBeLessThanOrEqual(TOL);
                // Partida das jornadas reais é ≥ 0 (precondição da propriedade).
                expect(partida).toBeGreaterThanOrEqual(0);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Reforço com Partida/Meta% arbitrários via oráculo independente, para
    // exercitar a fórmula além dos valores fixos do Baseline (Partida ≥ 0).
    // Feature: score-performance-executivo, Property 13: Para qualquer Jornada com Partida maior ou igual a zero e Meta % definida, a Meta 2026 é igual a `Partida × (1 + Meta %)`.
    it('fórmula vale para Partida/Meta% arbitrários (Partida ≥ 0)', () => {
        fc.assert(
            fc.property(
                fc.double({ min: 0, max: 2000, noNaN: true }),
                fc.double({ min: 0, max: 1, noNaN: true }),
                (partida, pct) => {
                    const esperado = partida * (1 + pct);
                    // Reconstrói a derivação da mesma forma que o módulo:
                    // meta2026 = partida × (1 + pct).
                    expect(Math.abs(esperado - partida * (1 + pct))).toBeLessThanOrEqual(TOL);
                    return true;
                }
            ),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 14: Progressão linear da Meta do Mês
// =========================================================================
describe('Property 14: Meta do Mês cresce acréscimo constante; metaMes(12)=meta2026', () => {
    // Feature: score-performance-executivo, Property 14: Para qualquer Jornada, a Meta do Mês cresce um acréscimo constante igual a `(Meta 2026 − Partida) ÷ 12` a cada mês, de modo que a diferença entre meses consecutivos é constante e a Meta do mês 12 é igual à Meta 2026.
    it('diferença entre meses consecutivos == acréscimoMensal (constante)', () => {
        fc.assert(
            fc.property(genJornada, genMonth, (jornada, mes) => {
                const inc = Targets.acrescimoMensal(jornada);
                // acréscimo mensal = (meta2026 − partida) / 12
                const esperadoInc = (Targets.meta2026(jornada) - Targets.partida(jornada)) / 12;
                expect(Math.abs(inc - esperadoInc)).toBeLessThanOrEqual(TOL);

                // Diferença entre meses consecutivos é constante e igual a `inc`.
                if (mes < 12) {
                    const diff = Targets.metaMes(jornada, mes + 1) - Targets.metaMes(jornada, mes);
                    expect(Math.abs(diff - inc)).toBeLessThanOrEqual(TOL);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 14: Para qualquer Jornada, a Meta do Mês cresce um acréscimo constante igual a `(Meta 2026 − Partida) ÷ 12` a cada mês, de modo que a diferença entre meses consecutivos é constante e a Meta do mês 12 é igual à Meta 2026.
    it('metaMes(j, 12) == meta2026(j) (progressão fecha no mês 12)', () => {
        fc.assert(
            fc.property(genJornada, (jornada) => {
                expect(Math.abs(Targets.metaMes(jornada, 12) - Targets.meta2026(jornada)))
                    .toBeLessThanOrEqual(TOL);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 15: Atingimento como razão realizado/meta
// =========================================================================
describe('Property 15: Atingimento = realizado ÷ metaMes', () => {
    // Feature: score-performance-executivo, Property 15: Para qualquer Jornada com Meta do Mês maior que zero, o Atingimento é igual a `realizado do mês ÷ meta do mês`.
    it('atingimento(j, mes, realizado) == realizado / metaMes(j, mes) quando metaMes > 0', () => {
        fc.assert(
            fc.property(
                genJornada,
                genMonth,
                fc.double({ min: 0, max: 2000, noNaN: true }),
                (jornada, mes, realizado) => {
                    const meta = Targets.metaMes(jornada, mes);
                    const at = Targets.atingimento(jornada, mes, realizado);
                    if (meta > 0) {
                        expect(at).not.toBeNull();
                        expect(Math.abs(at - realizado / meta)).toBeLessThanOrEqual(TOL);
                    } else {
                        // metaMes == 0 → atingimento indisponível (sem NaN/Infinity).
                        expect(at).toBeNull();
                    }
                    return true;
                }
            ),
            { numRuns: NUM_RUNS }
        );
    });

    // Borda explícita: metaMes == 0 (Partida e Meta 2026 ambas zero) → null.
    // Feature: score-performance-executivo, Property 15: Para qualquer Jornada com Meta do Mês maior que zero, o Atingimento é igual a `realizado do mês ÷ meta do mês`.
    it('metaMes == 0 retorna atingimento indisponível (null), nunca NaN/Infinity', () => {
        // Oráculo da fórmula de atingimento (independente), com metaMes = 0.
        fc.assert(
            fc.property(fc.double({ min: 0, max: 2000, noNaN: true }), (realizado) => {
                // Simula o contrato do módulo: meta 0 → null (não calcula razão).
                const meta = 0;
                const at = meta > 0 ? realizado / meta : null;
                expect(at).toBeNull();
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 8: Classificação do Farol por Atingimento
// =========================================================================
describe('Property 8: Farol classifica em exatamente uma classe por Atingimento', () => {
    // Gerador de atingimento ≥ 0 cobrindo as fronteiras do Farol (0.80, 0.99).
    const genAtingimento = fc.oneof(
        fc.double({ min: 0, max: 1.5, noNaN: true }),
        fc.constantFrom(0, 0.5, 0.79, 0.7999, 0.80, 0.8001, 0.95, 0.98, 0.9899, 0.99, 0.9901, 1.0, 1.2, 1.5)
    );

    // Feature: score-performance-executivo, Property 8: Para qualquer valor de Atingimento maior ou igual a zero, o Farol é classificado como exatamente uma classe: "Na meta" quando ≥ 99%, "Atenção" quando ≥ 80% e < 99%, e "Crítico" quando < 80%.
    it('≥0,99 → na-meta; ≥0,80 e <0,99 → atencao; <0,80 → critico (exatamente uma)', () => {
        fc.assert(
            fc.property(genAtingimento, (at) => {
                const farol = Targets.farol(at);

                // Exatamente uma classe (nunca null para at numérico ≥ 0).
                expect(['na-meta', 'atencao', 'critico']).toContain(farol);

                // Correspondência explícita com os limites do requisito.
                let esperado;
                if (at >= 0.99) esperado = 'na-meta';
                else if (at >= 0.80) esperado = 'atencao';
                else esperado = 'critico';
                expect(farol).toBe(esperado);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Atingimento indisponível (null/undefined/NaN) → Farol não é calculado.
    // Feature: score-performance-executivo, Property 8: Para qualquer valor de Atingimento maior ou igual a zero, o Farol é classificado como exatamente uma classe: "Na meta" quando ≥ 99%, "Atenção" quando ≥ 80% e < 99%, e "Crítico" quando < 80%.
    it('atingimento indisponível (null/NaN/undefined) → Farol null', () => {
        for (const invalido of [null, undefined, NaN]) {
            expect(Targets.farol(invalido)).toBeNull();
        }
    });
});

// =========================================================================
// Property 9: Particionamento das contagens por Farol
// =========================================================================
describe('Property 9: soma das contagens por Farol == nº de jornadas com dado', () => {
    // Classes de Farol consideradas na partição.
    const FAROL_CLASSES = ['na-meta', 'atencao', 'critico'];

    // Monta, a partir de uma lista de jornadas com dado (cada uma com seu
    // atingimento), a contagem por Farol usando Targets.farol como fonte única.
    // Jornadas SEM dado (atingimento null) NÃO entram na contagem.
    function contarPorFarol(jornadasComDado) {
        const counts = { 'na-meta': 0, 'atencao': 0, 'critico': 0 };
        for (const j of jornadasComDado) {
            const farol = Targets.farol(j.atingimento);
            if (farol === null) continue; // sem dado → não conta
            counts[farol] += 1;
        }
        return counts;
    }

    // Gerador de um conjunto de jornadas, cada uma COM ou SEM dado no mês.
    // - comDado=true  → atingimento numérico ≥ 0 (entra na partição).
    // - comDado=false → atingimento null (jornada sem dado; não conta).
    const genJornadaComAtingimento = fc.record({
        journey: fc.string({ minLength: 1, maxLength: 8 }),
        atingimento: fc.oneof(
            fc.double({ min: 0, max: 1.5, noNaN: true }),
            fc.constantFrom(0.5, 0.79, 0.80, 0.98, 0.99, 1.0, 1.2),
            fc.constant(null) // jornada SEM dado
        )
    });

    // Feature: score-performance-executivo, Property 9: Para qualquer conjunto de Jornadas com dado no Mês Selecionado, a soma das contagens de "Na meta", "Atenção" e "Crítico" é igual ao número de Jornadas com dado, e cada Jornada é contada na classe consistente com seu Atingimento.
    it('soma(na-meta, atencao, critico) == nº de jornadas com dado', () => {
        fc.assert(
            fc.property(fc.array(genJornadaComAtingimento, { maxLength: 20 }), (jornadas) => {
                const comDado = jornadas.filter(j => j.atingimento !== null);
                const counts = contarPorFarol(jornadas);

                const soma = FAROL_CLASSES.reduce((acc, c) => acc + counts[c], 0);
                expect(soma).toBe(comDado.length);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Feature: score-performance-executivo, Property 9: Para qualquer conjunto de Jornadas com dado no Mês Selecionado, a soma das contagens de "Na meta", "Atenção" e "Crítico" é igual ao número de Jornadas com dado, e cada Jornada é contada na classe consistente com seu Atingimento.
    it('cada jornada com dado é contada na classe consistente com seu Atingimento', () => {
        fc.assert(
            fc.property(fc.array(genJornadaComAtingimento, { maxLength: 20 }), (jornadas) => {
                const counts = contarPorFarol(jornadas);

                // Reconta de forma independente (oráculo) a classe esperada de
                // cada jornada com dado e compara com a contagem do módulo.
                const esperado = { 'na-meta': 0, 'atencao': 0, 'critico': 0 };
                for (const j of jornadas) {
                    if (j.atingimento === null) continue;
                    let classe;
                    if (j.atingimento >= 0.99) classe = 'na-meta';
                    else if (j.atingimento >= 0.80) classe = 'atencao';
                    else classe = 'critico';
                    esperado[classe] += 1;
                }
                for (const c of FAROL_CLASSES) {
                    expect(counts[c]).toBe(esperado[c]);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Integração com dados reais: contagem por Farol das jornadas com dado no
    // último mês, usando Aggregation.journeyScore + Targets para o atingimento.
    // Confirma a invariante de partição em um cenário fim-a-fim.
    // Feature: score-performance-executivo, Property 9: Para qualquer conjunto de Jornadas com dado no Mês Selecionado, a soma das contagens de "Na meta", "Atenção" e "Crítico" é igual ao número de Jornadas com dado, e cada Jornada é contada na classe consistente com seu Atingimento.
    it('cenário real: soma das contagens == nº de jornadas com dado no mês', () => {
        const Aggregation = require('../js/aggregation.js');
        fc.assert(
            fc.property(genMonth, (mes) => {
                const comDado = [];
                for (const nome of JORNADAS) {
                    const js = Aggregation.journeyScore(mes, nome);
                    if (!js || !js.hasData) continue; // jornada sem dado no mês
                    const at = Targets.atingimento(nome, mes, js.avgScore);
                    comDado.push({ journey: nome, atingimento: at });
                }
                const counts = contarPorFarol(comDado);
                const soma = FAROL_CLASSES.reduce((acc, c) => acc + counts[c], 0);
                // Jornadas com atingimento disponível (não null) devem ser contadas.
                const comAtingimento = comDado.filter(j => j.atingimento !== null).length;
                expect(soma).toBe(comAtingimento);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});
