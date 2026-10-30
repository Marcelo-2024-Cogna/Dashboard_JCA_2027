// Feature: score-performance-executivo
// Testes de PROPRIEDADE da agregação (Task 4.4).
// Validam `aggregation.js` com geradores fast-check (mínimo 100 execuções).
//
// Properties cobertas:
//   7  — Agregação em dois níveis por média (Time→Jornada→Engenharia).
//   10 — Destaques de maior alta e maior queda vs mês anterior.
//   11 — Média de métrica por Jornada ignora "Sem Dados".
//   12 — Distribuição de Times por Faixa de Atendimento é completa.
//
// Estratégia: `aggregation.js` consome `PEPData` (getTeamsByJornada, JORNADAS,
// getMonthsWithData) e `ScoringEngine`. Para exercitar o domínio amplo das
// propriedades geramos datasets mensais sintéticos e INJETAMOS esses dados
// substituindo temporariamente os acessores de `PEPData` (as mesmas funções
// usadas pelos example tests). O oráculo das médias/deltas é reimplementado
// de forma independente da própria agregação, usando apenas
// ScoringEngine.calculateTeamScore / evaluateMetric como "fonte da verdade"
// das pontuações por time/faixa (que já é validada por Properties 1–6).
//
// _Requirements: 3.1, 4.1, 3.6, 4.4, 3.8, 5.3, 5.4_

import { describe, it, expect, afterEach } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Aggregation = require('../js/aggregation.js');
const ScoringEngine = require('../js/scoring-engine.js');
const PEPData = require('../js/data.js');

const NUM_RUNS = 100;
const TOL = 0.01; // tolerância de duas casas decimais

const round2 = (n) => Math.round(n * 100) / 100;

// Ids das 12 métricas (fonte: METRIC_RULES do motor reconciliado).
const METRIC_IDS = Object.keys(ScoringEngine.METRIC_RULES);

// =========================================================================
// GERADORES (fast-check)
// =========================================================================

// Gera um valor plausível para uma métrica na escala correta, incluindo
// explicitamente `null` (Sem Dados — Requirement 1.10) como possibilidade.
function genMetricValue(metricId) {
    const scale = ScoringEngine.METRIC_RULES[metricId].scale;
    const base = (() => {
        switch (scale) {
            case 'days':     return fc.double({ min: 0, max: 12, noNaN: true });
            case 'thc0a10':  return fc.double({ min: 0, max: 10, noNaN: true });
            case 'category': return fc.integer({ min: 0, max: 5 });
            case 'ratio':
            default:         return fc.double({ min: 0, max: 1.2, noNaN: true });
        }
    })();
    // ~15% dos valores são null para exercitar "Sem Dados".
    return fc.oneof(
        { weight: 6, arbitrary: base },
        { weight: 1, arbitrary: fc.constant(null) }
    );
}

// Gera o objeto `metrics` completo (12 métricas) de um time.
function genTeamMetrics() {
    const shape = {};
    for (const id of METRIC_IDS) shape[id] = genMetricValue(id);
    return fc.record(shape);
}

// Gera um "time" sintético { id, name, jornada, metrics }.
function genTeam(jornada, idx) {
    return genTeamMetrics().map((metrics) => ({
        id: `${jornada}-t${idx}`,
        name: `Time ${idx}`,
        jornada,
        metrics
    }));
}

// Gera uma jornada com 0..5 times (pode ficar vazia).
function genJourneyTeams(jornada) {
    return fc.array(fc.constant(null), { minLength: 0, maxLength: 5 }).chain((arr) =>
        arr.length === 0
            ? fc.constant([])
            : fc.tuple(...arr.map((_, i) => genTeam(jornada, i)))
    );
}

// Gera um "mês" como um mapa { [jornada]: Team[] } para um conjunto de jornadas.
function genMonthData(jornadas) {
    const shape = {};
    for (const j of jornadas) shape[j] = genJourneyTeams(j);
    return fc.record(shape);
}

// =========================================================================
// INJEÇÃO DE DADOS: substitui os acessores de PEPData por um dataset
// sintético baseado em um mapa { month: { jornada: Team[] } }.
// Restaura os originais em afterEach.
// =========================================================================
const _orig = {
    getTeamsByJornada: PEPData.getTeamsByJornada,
    getMonthsWithData: PEPData.getMonthsWithData,
    JORNADAS: PEPData.JORNADAS
};

afterEach(() => {
    PEPData.getTeamsByJornada = _orig.getTeamsByJornada;
    PEPData.getMonthsWithData = _orig.getMonthsWithData;
    // JORNADAS é propriedade de dado; restauramos por segurança.
    try { PEPData.JORNADAS = _orig.JORNADAS; } catch (e) { /* ignore */ }
});

// Instala um dataset sintético. `byMonth` = { [month:number]: { [jornada]: Team[] } }.
// `jornadas` = lista de nomes de jornada a expor em PEPData.JORNADAS.
function installDataset(byMonth, jornadas) {
    const months = Object.keys(byMonth).map(Number).sort((a, b) => a - b);
    PEPData.getMonthsWithData = () => months.slice();
    PEPData.JORNADAS = jornadas.slice();
    PEPData.getTeamsByJornada = (jornada, month) => {
        const m = byMonth[month];
        if (!m) return [];
        return (m[jornada] || []).map((t) => ({ ...t }));
    };
}

// Oráculo independente: um time "tem dado" se possui ≥1 métrica não nula.
function teamHasData(team) {
    const m = team && team.metrics;
    if (!m) return false;
    return Object.values(m).some(
        (v) => v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v))
    );
}

// Oráculo: avgScore de uma jornada = média dos totalScore dos times com dado.
function oracleJourneyAvg(teams) {
    const withData = teams.filter(teamHasData);
    if (withData.length === 0) return null;
    const scores = withData.map((t) => ScoringEngine.calculateTeamScore(t.metrics).totalScore);
    return round2(scores.reduce((a, b) => a + b, 0) / scores.length);
}

// Oráculo: avgScore de Engenharia = média dos avgScore das jornadas com dado.
function oracleEngineeringAvg(monthMap, jornadas) {
    const avgs = jornadas
        .map((j) => oracleJourneyAvg(monthMap[j] || []))
        .filter((a) => a !== null);
    if (avgs.length === 0) return null;
    return round2(avgs.reduce((a, b) => a + b, 0) / avgs.length);
}

// =========================================================================
// Property 7: Agregação em dois níveis por média
// =========================================================================
describe('Property 7: Agregação em dois níveis por média (Time→Jornada→Engenharia)', () => {
    const JORNADAS = ['J1', 'J2', 'J3'];

    // Feature: score-performance-executivo, Property 7: Para qualquer mês e conjunto de times/jornadas com dados, o score de cada Jornada é igual à média aritmética dos scores de seus Times, e o score de Engenharia é igual à média aritmética dos scores das Jornadas.
    it('journeyScore = média dos Times; engineeringScore = média das Jornadas', () => {
        fc.assert(
            fc.property(genMonthData(JORNADAS), (monthMap) => {
                installDataset({ 1: monthMap }, JORNADAS);

                // Nível 1: cada jornada = média dos seus times com dado.
                for (const j of JORNADAS) {
                    const expected = oracleJourneyAvg(monthMap[j] || []);
                    const result = Aggregation.journeyScore(1, j);
                    if (expected === null) {
                        expect(result.hasData).toBe(false);
                        expect(result.avgScore).toBeNull();
                    } else {
                        expect(result.hasData).toBe(true);
                        expect(result.avgScore).toBeCloseTo(expected, 2);
                        // Faixa de performance consistente com o avgScore.
                        expect(result.performanceBand).toEqual(
                            ScoringEngine.getPerformanceBand(result.avgScore)
                        );
                    }
                }

                // Nível 2: Engenharia = média (não ponderada) das jornadas com dado.
                const expectedEng = oracleEngineeringAvg(monthMap, JORNADAS);
                const eng = Aggregation.engineeringScore(1);
                if (expectedEng === null) {
                    expect(eng.hasData).toBe(false);
                    expect(eng.avgScore).toBeNull();
                } else {
                    expect(eng.hasData).toBe(true);
                    expect(eng.avgScore).toBeCloseTo(expectedEng, 2);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 10: Destaques de maior alta e maior queda
// =========================================================================
describe('Property 10: Destaques de maior alta e maior queda vs mês anterior', () => {
    const JORNADAS = ['J1', 'J2', 'J3', 'J4'];

    // Feature: score-performance-executivo, Property 10: Para qualquer par de meses consecutivos com dados, a Jornada de maior alta é aquela com o maior delta positivo de score e a de maior queda é a de menor delta; quando não há mês anterior com dados, ambos os destaques ficam vazios e o delta é indisponível.
    it('topRise = maior delta positivo; topFall = menor delta; vazios sem mês anterior', () => {
        fc.assert(
            fc.property(genMonthData(JORNADAS), genMonthData(JORNADAS), (prevMap, curMap) => {
                // Instala dois meses com dados: mês 1 (anterior) e mês 2 (atual).
                installDataset({ 1: prevMap, 2: curMap }, JORNADAS);

                // No PRIMEIRO mês com dados não há mês anterior → destaques vazios
                // e delta indisponível (Requirement 3.8).
                expect(Aggregation.highlights(1)).toEqual({ topRise: null, topFall: null });
                expect(Aggregation.monthDelta(1, null)).toBeNull();
                for (const j of JORNADAS) {
                    expect(Aggregation.monthDelta(1, j)).toBeNull();
                }

                // Oráculo dos deltas: só jornadas com dado em AMBOS os meses.
                const deltas = [];
                for (const j of JORNADAS) {
                    const prev = oracleJourneyAvg(prevMap[j] || []);
                    const cur = oracleJourneyAvg(curMap[j] || []);
                    if (prev === null || cur === null) continue;
                    deltas.push({ journey: j, delta: round2(cur - prev) });
                }

                const { topRise, topFall } = Aggregation.highlights(2);

                if (deltas.length === 0) {
                    expect(topRise).toBeNull();
                    expect(topFall).toBeNull();
                    return true;
                }

                const maxDelta = Math.max(...deltas.map((d) => d.delta));
                const minDelta = Math.min(...deltas.map((d) => d.delta));

                // topRise: existe sse algum delta > 0, e seu valor é o máximo.
                if (maxDelta > 0) {
                    expect(topRise).not.toBeNull();
                    expect(topRise.delta).toBeCloseTo(maxDelta, 2);
                    // A jornada apontada realmente possui esse delta.
                    const match = deltas.find((d) => d.journey === topRise.journey);
                    expect(match).toBeTruthy();
                    expect(match.delta).toBeCloseTo(topRise.delta, 2);
                } else {
                    expect(topRise).toBeNull();
                }

                // topFall: existe sse algum delta < 0, e seu valor é o mínimo.
                if (minDelta < 0) {
                    expect(topFall).not.toBeNull();
                    expect(topFall.delta).toBeCloseTo(minDelta, 2);
                    const match = deltas.find((d) => d.journey === topFall.journey);
                    expect(match).toBeTruthy();
                    expect(match.delta).toBeCloseTo(topFall.delta, 2);
                } else {
                    expect(topFall).toBeNull();
                }

                // monthDelta por jornada coincide com o oráculo (quando comparável).
                for (const d of deltas) {
                    expect(Aggregation.monthDelta(2, d.journey)).toBeCloseTo(d.delta, 2);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 11: Média de métrica por Jornada ignora "Sem Dados"
// =========================================================================
describe('Property 11: Média de métrica por Jornada ignora "Sem Dados"', () => {
    const JORNADA = 'J1';

    // Feature: score-performance-executivo, Property 11: Para qualquer Jornada e métrica no Mês Selecionado, a média exibida é a média aritmética dos valores dos Times que possuem dado para aquela métrica, desconsiderando os Times marcados como "Sem Dados".
    it('metricAverage = média dos valores com dado; count = nº de times com dado', () => {
        fc.assert(
            fc.property(genJourneyTeams(JORNADA), fc.constantFrom(...METRIC_IDS), (teams, metricId) => {
                installDataset({ 1: { [JORNADA]: teams } }, [JORNADA]);

                // Oráculo: considera apenas valores não null/undefined/NaN.
                const values = teams
                    .map((t) => t.metrics[metricId])
                    .filter((v) => v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v)));

                const result = Aggregation.metricAverage(1, JORNADA, metricId);
                expect(result.count).toBe(values.length);
                if (values.length === 0) {
                    expect(result.avg).toBeNull();
                } else {
                    const expected = round2(values.reduce((a, b) => a + b, 0) / values.length);
                    expect(result.avg).toBeCloseTo(expected, 2);
                }
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});

// =========================================================================
// Property 12: Distribuição de Times por Faixa de Atendimento é completa
// =========================================================================
describe('Property 12: Distribuição de Times por Faixa de Atendimento é completa', () => {
    const JORNADA = 'J1';
    const BAND_IDS = ['nao-atendeu', 'atendeu-parcialmente', 'atendeu-totalmente', 'superou'];

    // Feature: score-performance-executivo, Property 12: Para qualquer Jornada e métrica, a soma das contagens de Times em cada Faixa de Atendimento (Não atendeu, Atendeu parcialmente, Atendeu totalmente, Superou) é igual ao número de Times com dado para aquela métrica, e cada Time é contado na faixa correspondente ao seu valor.
    it('soma das 4 faixas = total = nº de times com dado; cada time na faixa do seu valor', () => {
        fc.assert(
            fc.property(genJourneyTeams(JORNADA), fc.constantFrom(...METRIC_IDS), (teams, metricId) => {
                installDataset({ 1: { [JORNADA]: teams } }, [JORNADA]);

                // Oráculo: classifica cada time COM DADO via evaluateMetric.
                const expected = {
                    'nao-atendeu': 0,
                    'atendeu-parcialmente': 0,
                    'atendeu-totalmente': 0,
                    'superou': 0,
                    total: 0
                };
                for (const t of teams) {
                    const v = t.metrics[metricId];
                    if (v === null || v === undefined || (typeof v === 'number' && Number.isNaN(v))) continue;
                    const band = ScoringEngine.evaluateMetric(metricId, v).band;
                    if (BAND_IDS.includes(band)) {
                        expected[band] += 1;
                        expected.total += 1;
                    }
                }

                const dist = Aggregation.metricDistribution(1, JORNADA, metricId);

                // Invariante de completude: soma das 4 faixas === total.
                const sum = BAND_IDS.reduce((acc, b) => acc + dist[b], 0);
                expect(sum).toBe(dist.total);

                // Distribuição coincide exatamente com o oráculo.
                expect(dist).toEqual(expected);
                return true;
            }),
            { numRuns: NUM_RUNS }
        );
    });
});
