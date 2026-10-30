// Feature: score-performance-executivo
// Testes de PROPRIEDADE das agregações de Retrospectiva de PI (Task 13.3).
// Validam `retro-aggregation.js` com geradores fast-check (mínimo 100 execuções).
//
// Properties cobertas (design.md, "Correctness Properties", P18–P23):
//   18 — Composição dos Pontos Trabalhados por Sprint (Entrega de Valor +
//        Sustentação = total; percentuais somam 1 dentro de 0,01 quando total>0).
//   19 — Média de Produtividade da Jornada (média aritmética dos Times COM DADO,
//        ignorando ausências).
//   20 — Partição da Situação dos Épicos (soma das 6 situações por origem = nº de
//        épicos considerados; cada épico em exatamente uma situação).
//   21 — Média de Jornada no Cycle Time e Lead Time (média aritmética dos
//        Times/Squads COM DADO, ignorando ausências).
//   22 — Consistência do Débito Técnico no PI (Pendente = Planejado − Entregue −
//        Cancelado dentro da tolerância; total da Jornada = soma dos Times).
//   23 — Totais de Sustentação da Jornada (total = soma dos valores dos Times).
//
// Estratégia: `retro-aggregation.js` resolve seus dados via `getData()`, que
// lê `globalThis.PEPRetroData` quando presente (antes de cair no require do
// fixture real). Para exercitar os invariantes com AMPLITUDE, geramos datasets
// SINTÉTICOS com fast-check e INSTALAMOS um stub compatível em
// `globalThis.PEPRetroData` (query/getProdutividade/getEpicos/
// getEpicosContagem/DATASET). Como `getData()` é chamado a cada invocação, o
// stub é consumido imediatamente; restauramos o global em afterEach.
//
// _Requirements: 10.1, 10.2, 11.1, 11.2, 12.1, 12.4, 13.2, 14.2, 15.2, 15.3, 16.1, 16.2_

import { describe, it, expect, afterEach } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const RetroAggregation = require('../js/retro-aggregation.js');

const NUM_RUNS = 200;
const TOL = 0.01;

// =========================================================================
// INJEÇÃO DE DADOS: instala um stub `PEPRetroData` em globalThis que serve
// as coleções geradas. `getData()` do módulo lê este global primeiro.
// =========================================================================
const _origGlobal = globalThis.PEPRetroData;

afterEach(() => {
    globalThis.PEPRetroData = _origGlobal;
});

// Monta um stub de PEPRetroData a partir de um mapa de coleções sintéticas.
// `collections` = { pontosTrabalhados?, produtividade?, epicos?,
// epicosContagem?, cycleTime?, leadTime?, debito?, debitoBacklogPorTime?,
// sustentacao?, sustentacaoHistorico? }.
function installStub(collections) {
    const DATASET = {
        pontosTrabalhados: collections.pontosTrabalhados || [],
        produtividade: collections.produtividade || [],
        epicos: collections.epicos || [],
        epicosContagem: collections.epicosContagem || {},
        cycleTime: collections.cycleTime || [],
        leadTime: collections.leadTime || [],
        debito: collections.debito || [],
        debitoBacklogPorTime: collections.debitoBacklogPorTime || [],
        sustentacao: collections.sustentacao || [],
        sustentacaoHistorico: collections.sustentacaoHistorico || []
    };

    function query(collection, { pi, time } = {}) {
        const arr = DATASET[collection];
        if (!Array.isArray(arr)) return [];
        return arr.filter(r => {
            if (pi != null && r.pi !== pi) return false;
            if (time != null && r.time !== time) return false;
            return true;
        });
    }

    const stub = {
        DATASET,
        query,
        getProdutividade: () => DATASET.produtividade,
        getEpicos: (pi) => query('epicos', { pi }),
        getEpicosContagem: () => DATASET.epicosContagem
    };
    globalThis.PEPRetroData = stub;
    return stub;
}

// Oráculo independente: média aritmética dos valores COM DADO (ignora
// null/undefined/NaN); null quando nenhum valor presente.
function oracleMedia(valores) {
    const validos = valores
        .filter(v => v != null && !Number.isNaN(Number(v)))
        .map(Number);
    if (validos.length === 0) return null;
    return validos.reduce((a, b) => a + b, 0) / validos.length;
}

// Gerador de um valor numérico "com dado" ou ausente (null) — para exercitar
// o descarte de ausências nas médias.
const genValorOuAusente = fc.oneof(
    { weight: 4, arbitrary: fc.double({ min: 0, max: 1000, noNaN: true }) },
    { weight: 1, arbitrary: fc.constant(null) }
);

const genInteiroNaoNeg = fc.integer({ min: 0, max: 2000 });

// =========================================================================
// Property 18: Composição dos Pontos Trabalhados por Sprint
// =========================================================================
describe('Property 18: Composição dos Pontos Trabalhados por Sprint', () => {
    const PI = 'PI X';

    // Gera 1..8 Sprints com sustentação/entregaValor não-negativos (podendo ser 0).
    const genSprints = fc.array(
        fc.record({
            sustentacao: fc.integer({ min: 0, max: 500 }),
            entregaValor: fc.integer({ min: 0, max: 500 })
        }),
        { minLength: 1, maxLength: 8 }
    ).map(rows => rows.map((r, i) => ({
        pi: PI, sprint: `SP-${i}`, time: 'J', jornada: 'J',
        sustentacao: r.sustentacao, entregaValor: r.entregaValor
    })));

    // Feature: score-performance-executivo, Property 18: Para qualquer Sprint com dado, a soma de Entrega de Valor e Sustentação é igual ao total de pontos da Sprint, e os percentuais de cada categoria somam 1 (dentro de 0,01).
    it('entregaValor + sustentacao = total; pct.sustentacao + pct.entregaValor = 1 (±0,01) quando total>0', () => {
        fc.assert(
            fc.property(genSprints, (pontosTrabalhados) => {
                installStub({ pontosTrabalhados });
                const linhas = RetroAggregation.pontosPorSprint(PI);
                expect(linhas.length).toBe(pontosTrabalhados.length);

                for (const l of linhas) {
                    // total = sustentação + entrega de valor (composição exata).
                    expect(l.total).toBe(l.sustentacao + l.entregaValor);
                    // Percentuais consistentes com os valores.
                    if (l.total > 0) {
                        expect(l.pct.sustentacao + l.pct.entregaValor).toBeCloseTo(1, 2);
                        expect(l.pct.sustentacao).toBeCloseTo(l.sustentacao / l.total, 6);
                        expect(l.pct.entregaValor).toBeCloseTo(l.entregaValor / l.total, 6);
                    } else {
                        // total = 0 → ambos os percentuais são 0 (sem divisão por zero).
                        expect(l.pct.sustentacao).toBe(0);
                        expect(l.pct.entregaValor).toBe(0);
                    }
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 19: Média de Produtividade da Jornada
// =========================================================================
describe('Property 19: Média de Produtividade da Jornada', () => {
    const JORNADA = 'J1';
    const PIS = ['PI A', 'PI B', 'PI C'];

    // Gera 0..6 Times, cada um com produtividade por PI (valor ou ausente).
    const genProdutividade = fc.array(
        fc.record({
            'PI A': genValorOuAusente,
            'PI B': genValorOuAusente,
            'PI C': genValorOuAusente
        }),
        { minLength: 0, maxLength: 6 }
    ).map(rows => rows.map((porPI, i) => {
        // Remove chaves ausentes para simular "PI ausente no registro do time".
        const clean = {};
        for (const p of PIS) if (porPI[p] != null) clean[p] = porPI[p];
        return { time: `T${i}`, jornada: JORNADA, porPI: clean };
    }));

    // Feature: score-performance-executivo, Property 19: Para qualquer PI, a média de produtividade da Jornada é a média aritmética das produtividades dos Times com dado naquele PI, ignorando Times sem dado.
    it('mediaJornadaPorPI = média aritmética dos Times COM DADO; null quando nenhum time tem dado', () => {
        fc.assert(
            fc.property(genProdutividade, (produtividade) => {
                installStub({ produtividade });
                const { porTime, mediaJornadaPorPI } = RetroAggregation.produtividadePorPI({ jornada: JORNADA });
                expect(porTime.length).toBe(produtividade.length);

                for (const p of PIS) {
                    const valores = produtividade.map(t => t.porPI[p]);
                    const esperado = oracleMedia(valores);
                    const obtido = mediaJornadaPorPI[p];
                    if (esperado === null) {
                        // PI pode nem aparecer (undefined) ou ser explicitamente null.
                        expect(obtido == null).toBe(true);
                    } else {
                        expect(obtido).toBeCloseTo(esperado, 6);
                    }
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 20: Partição da Situação dos Épicos
// =========================================================================
describe('Property 20: Partição da Situação dos Épicos', () => {
    const PI = 'PI X';
    const SITUACOES = RetroAggregation.SITUACOES; // 6 situações canônicas
    const ORIGENS = RetroAggregation.ORIGENS;      // ['baseline', 'mudanca-escopo']

    // Gera a tabela de contagem: por origem, uma contagem não-negativa por situação.
    const genContagemOrigem = fc.record(
        Object.fromEntries(SITUACOES.map(s => [s, genInteiroNaoNeg]))
    );
    const genEpicosContagem = fc.record({
        baseline: genContagemOrigem,
        'mudanca-escopo': genContagemOrigem
    });

    // Gera épicos individuais (chave + situação + origem), incluindo situações
    // DESCONHECIDAS que devem ser ignoradas pela listagem de partição.
    const genEpicos = fc.array(
        fc.record({
            situacao: fc.oneof(
                fc.constantFrom(...SITUACOES),
                fc.constant('situacao-desconhecida')
            ),
            origem: fc.constantFrom(...ORIGENS, 'origem-desconhecida')
        }),
        { minLength: 0, maxLength: 30 }
    ).map(rows => rows.map((r, i) => ({
        pi: PI, jornada: 'J', chave: `E-${i}`, situacao: r.situacao, origem: r.origem
    })));

    // Feature: score-performance-executivo, Property 20: Para qualquer PI, a soma das contagens das seis situações (por origem Baseline e Mudança de Escopo) é igual ao número de Épicos considerados, e cada Épico é contado em exatamente uma situação.
    it('soma das 6 situações por origem = total da origem; listagem particiona épicos (cada um em exatamente uma situação)', () => {
        fc.assert(
            fc.property(genEpicosContagem, genEpicos, (epicosContagem, epicos) => {
                installStub({ epicosContagem, epicos });
                const res = RetroAggregation.situacaoEpicos(PI);

                // (a) Contagem: a contagem é DERIVADA da própria lista de épicos
                //     (filtrada por pi/jornada/squad), garantindo consistência
                //     total entre os números e a listagem exibida. Assim, a soma
                //     das 6 situações por origem iguala o nº de épicos VÁLIDOS
                //     daquela origem na lista (não a tabela `epicosContagem`, que
                //     deixou de ser a fonte — isso corrigiu a divergência do
                //     filtro por Squad).
                for (const origem of ORIGENS) {
                    const chave = origem === 'baseline' ? 'baseline' : 'mudancaEscopo';
                    const normalizada = res[chave];
                    // Todas as 6 situações presentes no resultado.
                    expect(Object.keys(normalizada).sort()).toEqual([...SITUACOES].sort());
                    const somaResultado = SITUACOES.reduce((a, s) => a + normalizada[s], 0);
                    const validosDaOrigem = epicos.filter(
                        e => SITUACOES.includes(e.situacao) && e.origem === origem
                    ).length;
                    expect(somaResultado).toBe(validosDaOrigem);
                }

                // (b) Listagem: cada épico VÁLIDO aparece em exatamente uma
                //     (situação, origem). Épicos com situação/origem desconhecida
                //     não entram em nenhuma célula válida.
                let totalListado = 0;
                for (const s of SITUACOES) {
                    for (const origem of ORIGENS) {
                        const lista = res.listaPorSituacao(s, origem);
                        // Cada item listado realmente tem a situação/origem pedidas.
                        for (const e of lista) {
                            expect(e.situacao).toBe(s);
                            expect(e.origem).toBe(origem);
                        }
                        totalListado += lista.length;
                    }
                }
                const epicosValidos = epicos.filter(
                    e => SITUACOES.includes(e.situacao) && ORIGENS.includes(e.origem)
                ).length;
                // Partição: nenhum épico válido contado duas vezes, nenhum omitido.
                expect(totalListado).toBe(epicosValidos);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 21: Média de Jornada no Cycle Time e Lead Time
// =========================================================================
describe('Property 21: Média de Jornada no Cycle Time e Lead Time', () => {
    const PI = 'PI X';
    const FASES = ['ideacao', 'desenvolvimento', 'homologacao', 'implantacao'];

    // Cycle Time: 0..6 Times, cada fase com valor ou ausente.
    const genCycleTime = fc.array(
        fc.record({
            ideacao: genValorOuAusente,
            desenvolvimento: genValorOuAusente,
            homologacao: genValorOuAusente,
            implantacao: genValorOuAusente
        }),
        { minLength: 0, maxLength: 6 }
    ).map(rows => rows.map((r, i) => ({
        pi: PI, time: `T${i}`, jornada: 'J',
        ideacao: r.ideacao, desenvolvimento: r.desenvolvimento,
        homologacao: r.homologacao, implantacao: r.implantacao
    })));

    // Feature: score-performance-executivo, Property 21: Para qualquer PI e fase (Cycle Time), a média da Jornada é a média aritmética dos valores dos Times com dado, ignorando ausências.
    it('cycleTimePorFase: média da Jornada por fase = média dos Times COM DADO', () => {
        fc.assert(
            fc.property(genCycleTime, (cycleTime) => {
                installStub({ cycleTime });
                const { porTime, mediaJornada } = RetroAggregation.cycleTimePorFase(PI);
                expect(porTime.length).toBe(cycleTime.length);

                for (const fase of FASES) {
                    const esperado = oracleMedia(cycleTime.map(r => r[fase]));
                    const obtido = mediaJornada[fase];
                    if (esperado === null) {
                        expect(obtido).toBeNull();
                    } else {
                        expect(obtido).toBeCloseTo(esperado, 6);
                    }
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Lead Time: tipos variados por squad; cada (squad, tipo) com valor ou ausente.
    const TIPOS = ['História', 'Enabler', 'Débito Técnico', 'Spike', 'Teste'];
    const genLeadTime = fc.array(
        fc.record(Object.fromEntries(TIPOS.map(t => [t, genValorOuAusente]))),
        { minLength: 0, maxLength: 8 }
    ).map(rows => {
        const registros = [];
        rows.forEach((row, i) => {
            const squad = `SQUAD-${i}`;
            TIPOS.forEach(tipo => {
                // Só emite registro quando há dado (valor não nulo) OU com dado nulo
                // explícito; mantém o tipo presente para a média ignorar ausências.
                registros.push({ pi: PI, squad, jornada: 'J', tipoItem: tipo, dias: row[tipo] });
            });
        });
        return { registros, rows };
    });

    // Feature: score-performance-executivo, Property 21: Para qualquer PI e Tipo de Item (Lead Time), a média da Jornada é a média aritmética dos valores dos Squads com dado, ignorando ausências.
    it('leadTimePorTipo: média da Jornada por Tipo de Item = média dos Squads COM DADO', () => {
        fc.assert(
            fc.property(genLeadTime, ({ registros, rows }) => {
                installStub({ leadTime: registros });
                const { porSquad, mediaJornadaPorTipo } = RetroAggregation.leadTimePorTipo(PI);
                expect(porSquad.length).toBe(rows.length);

                for (const tipo of TIPOS) {
                    const esperado = oracleMedia(rows.map(r => r[tipo]));
                    const obtido = tipo in mediaJornadaPorTipo ? mediaJornadaPorTipo[tipo] : null;
                    if (esperado === null) {
                        expect(obtido == null).toBe(true);
                    } else {
                        expect(obtido).toBeCloseTo(esperado, 6);
                    }
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 22: Consistência do Débito Técnico no PI
// =========================================================================
describe('Property 22: Consistência do Débito Técnico no PI', () => {
    const PI = 'PI X';

    // Gera totais da Jornada em que Pendente = Planejado − Entregue − Cancelado
    // POR CONSTRUÇÃO (dados sintéticos), de modo que `pendenteConsistente` deve
    // ser true. Também gera o backlog por Time (soma independente).
    const genEntregueCancelado = fc.record({
        entregue: fc.integer({ min: 0, max: 300 }),
        cancelado: fc.integer({ min: 0, max: 300 }),
        extra: fc.integer({ min: 0, max: 300 }) // folga adicionada ao planejado
    });

    const genDebito = fc.tuple(genEntregueCancelado, genEntregueCancelado).map(([sp, qtd]) => {
        // Planejado = Entregue + Cancelado + Pendente(>=0, = extra) ⇒ Pendente derivado = extra.
        const planejadoSP = sp.entregue + sp.cancelado + sp.extra;
        const planejadoQtd = qtd.entregue + qtd.cancelado + qtd.extra;
        return {
            pi: PI, time: 'J', jornada: 'J',
            backlogQtde: 0,
            planejadoSP, realSP: sp.entregue, entregueSP: sp.entregue,
            pendenteSP: sp.extra, canceladoSP: sp.cancelado,
            planejadoQtd, realQtd: qtd.entregue, entregueQtd: qtd.entregue,
            pendenteQtd: qtd.extra, canceladoQtd: qtd.cancelado
        };
    });

    // Backlog por Time: 0..6 Times com quantidades não-negativas.
    const genBacklog = fc.array(
        fc.integer({ min: 0, max: 500 }),
        { minLength: 0, maxLength: 6 }
    ).map(qts => qts.map((q, i) => ({
        pi: PI, time: `T${i}`, jornada: 'J', backlogQtde: q
    })));

    // Feature: score-performance-executivo, Property 22: Para qualquer PI, Pendente é consistente com Planejado − Entregue − Cancelado dentro da tolerância, e o total da Jornada é a soma dos Times, em Story Points e em quantidade.
    it('pendenteConsistente=true quando Pendente=Planejado−Entregue−Cancelado; total da Jornada = soma dos Times', () => {
        fc.assert(
            fc.property(genDebito, genBacklog, (debitoTotal, backlog) => {
                installStub({ debito: [debitoTotal], debitoBacklogPorTime: backlog });
                const { porTime, totalJornada } = RetroAggregation.debitoTecnico(PI);

                // (a) Consistência do Pendente (SP e quantidade) dentro da tolerância.
                expect(totalJornada.pendenteConsistente).toBe(true);
                expect(Math.abs(totalJornada.pendenteSP - totalJornada.pendenteSPDerivado)).toBeLessThanOrEqual(TOL);
                expect(Math.abs(totalJornada.pendenteQtd - totalJornada.pendenteQtdDerivado)).toBeLessThanOrEqual(TOL);
                // Pendente derivado bate com Planejado − Entregue − Cancelado.
                expect(totalJornada.pendenteSPDerivado).toBe(
                    totalJornada.planejadoSP - totalJornada.entregueSP - totalJornada.canceladoSP
                );
                expect(totalJornada.pendenteQtdDerivado).toBe(
                    totalJornada.planejadoQtd - totalJornada.entregueQtd - totalJornada.canceladoQtd
                );

                // (b) Total da Jornada do backlog = soma dos Times.
                const somaBacklog = backlog.reduce((a, r) => a + r.backlogQtde, 0);
                expect(porTime.length).toBe(backlog.length);
                expect(totalJornada.backlogQtde).toBe(somaBacklog);

                // (c) Totais SP/quantidade = soma dos registros de débito (aqui, 1 registro).
                expect(totalJornada.planejadoSP).toBe(debitoTotal.planejadoSP);
                expect(totalJornada.planejadoQtd).toBe(debitoTotal.planejadoQtd);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });

    // Caso adicional: múltiplos registros de débito somam no total da Jornada.
    const genDebitoMulti = fc.array(genDebito, { minLength: 1, maxLength: 4 });

    // Feature: score-performance-executivo, Property 22: o total da Jornada é a soma dos Times (vários registros), em Story Points e em quantidade.
    it('múltiplos registros de débito: totais SP/quantidade = soma dos registros', () => {
        fc.assert(
            fc.property(genDebitoMulti, (debitos) => {
                installStub({ debito: debitos, debitoBacklogPorTime: [] });
                const { totalJornada } = RetroAggregation.debitoTecnico(PI);

                const soma = (campo) => debitos.reduce((a, r) => a + r[campo], 0);
                expect(totalJornada.planejadoSP).toBe(soma('planejadoSP'));
                expect(totalJornada.entregueSP).toBe(soma('entregueSP'));
                expect(totalJornada.canceladoSP).toBe(soma('canceladoSP'));
                expect(totalJornada.pendenteSP).toBe(soma('pendenteSP'));
                expect(totalJornada.planejadoQtd).toBe(soma('planejadoQtd'));
                expect(totalJornada.entregueQtd).toBe(soma('entregueQtd'));
                expect(totalJornada.canceladoQtd).toBe(soma('canceladoQtd'));
                expect(totalJornada.pendenteQtd).toBe(soma('pendenteQtd'));
                // Pendente informado = soma dos pendentes = Planejado−Entregue−Cancelado por construção.
                expect(totalJornada.pendenteConsistente).toBe(true);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 23: Totais de Sustentação da Jornada
// =========================================================================
describe('Property 23: Totais de Sustentação da Jornada', () => {
    const PI = 'PI X';

    // Gera 0..8 Times com realizado/planejado não-negativos.
    const genSustentacao = fc.array(
        fc.record({
            realizado: fc.integer({ min: 0, max: 500 }),
            planejado: fc.integer({ min: 0, max: 500 })
        }),
        { minLength: 0, maxLength: 8 }
    ).map(rows => rows.map((r, i) => ({
        pi: PI, time: `T${i}`, jornada: 'J',
        realizado: r.realizado, planejado: r.planejado
    })));

    // Feature: score-performance-executivo, Property 23: Para qualquer PI, o total da Jornada de Realizado e de Planejado é a soma dos valores dos Times.
    it('totalJornada.realizado/planejado = soma dos Times', () => {
        fc.assert(
            fc.property(genSustentacao, (sustentacao) => {
                installStub({ sustentacao });
                const { porTime, totalJornada } = RetroAggregation.sustentacao(PI);

                expect(porTime.length).toBe(sustentacao.length);
                const somaReal = sustentacao.reduce((a, r) => a + r.realizado, 0);
                const somaPlan = sustentacao.reduce((a, r) => a + r.planejado, 0);
                expect(totalJornada.realizado).toBe(somaReal);
                expect(totalJornada.planejado).toBe(somaPlan);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// EXEMPLO (documental): fixture real de Débito Técnico do PI 4/26 (Jira, A1).
// A coleta A1 traz a QUANTIDADE de itens "Débito Técnico" criados no PI por
// jornada (backlog/entrada), sem Story Points nem breakdown Entregue/Pendente/
// Cancelado. Logo, no total da Jornada esses campos são nulos (tratados como 0
// pelo agregador) e o Pendente derivado (Planejado − Entregue − Cancelado) é
// igual ao informado (0) → pendenteConsistente=true. O backlog total é a soma
// das 10 jornadas. Não é uma propriedade; é um teste de exemplo.
// =========================================================================
describe('Exemplo: fixture real de Débito Técnico (PI 4/26 — Jira, coleta A1)', () => {
    it('backlog por jornada somado + Pendente derivado consistente (sem Story Points)', () => {
        // Usa o módulo de dados REAL (sem stub): limpa o global instalado por testes.
        delete globalThis.PEPRetroData;
        const RetroDataReal = require('../js/retro-data.js');
        globalThis.PEPRetroData = RetroDataReal;

        const { porTime, totalJornada } = RetroAggregation.debitoTecnico('PI 4/26');

        // 10 jornadas no backlog; total = 9+9+27+75+78+24+19+57+13+81 = 392.
        expect(porTime.length).toBe(10);
        expect(totalJornada.backlogQtde).toBe(392);

        // Quantidade planejada total = soma das jornadas (392); SP ausentes → 0.
        expect(totalJornada.planejadoQtd).toBe(392);
        expect(totalJornada.planejadoSP).toBe(0);

        // Derivado = Planejado − Entregue − Cancelado. SP ausentes (→0) → derivado
        // SP = 0; em quantidade, derivado = 392 − 0 − 0 = 392.
        expect(totalJornada.pendenteSPDerivado).toBe(0);
        expect(totalJornada.pendenteQtdDerivado).toBe(392);
        // Pendente informado é nulo (→0). Em SP bate (0=0); em quantidade NÃO
        // (0 ≠ 392), pois A1 só coleta a entrada e não o que foi entregue.
        // Logo pendenteConsistente=false (esperado para a coleta A1 sem SP).
        expect(totalJornada.pendenteSP).toBe(0);
        expect(totalJornada.pendenteConsistente).toBe(false);
    });
});
