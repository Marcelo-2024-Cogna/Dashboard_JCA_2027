/**
 * PEP - Plataforma de Engenharia de Performance
 * Agregação Time → Jornada → Engenharia (`aggregation.js`)
 *
 * Task 4.1 (spec score-performance-executivo): implementa a agregação em dois
 * níveis descrita no design ("Components and Interfaces" → "2. Agregação —
 * aggregation.js") e no glossário/Requirements 3.1 e 4.1:
 *
 *   - Score de uma Jornada em um mês = MÉDIA ARITMÉTICA dos scores (totalScore)
 *     dos Times da jornada que possuem dado naquele mês.
 *   - Score de Engenharia em um mês = MÉDIA ARITMÉTICA dos scores (avgScore) das
 *     Jornadas que possuem dado naquele mês (média de Jornadas, NÃO ponderada
 *     pelo nº de times — conforme design "Agregação em dois níveis").
 *
 * Em cada nível consolidamos também `categoryScores` (média das 4 categorias:
 * processos/praticas/produto/pessoas) e `performanceBand` (via
 * ScoringEngine.getPerformanceBand do score médio do nível).
 *
 * Times/jornadas SEM dado no mês são IGNORADOS no cálculo das médias.
 *
 * Task 4.2 adiciona `monthDelta` e `highlights` (variação de score vs o mês
 * anterior COM DADOS e destaques de maior alta/queda por jornada). Ver design
 * ("Components and Interfaces" → "2. Agregação") e Requirements 3.6, 4.4, 3.8.
 *
 * Task 4.3 adiciona `metricAverage` (média dos valores da métrica por jornada,
 * ignorando "Sem Dados"), `metricDistribution` (contagem de times por Faixa de
 * Atendimento usando ScoringEngine.evaluateMetric) e o agregador de
 * conveniência `journeyMetrics`. Ver design ("Components and Interfaces" →
 * "2. Agregação") e Requirements 5.3, 5.4 (Properties 11 e 12).
 *
 * Dependências (carregadas por <script> no browser como globais, ou via
 * require() em Node/Vitest sob guarda de ambiente):
 *   - ScoringEngine.calculateTeamScore, getPerformanceBand, CATEGORIES,
 *     evaluateMetric, METRIC_RULES
 *   - PEPData.getRecords(month), getTeamsByJornada, getMonthsWithData,
 *     getLatestMonth, JORNADAS
 */

const Aggregation = (() => {

    // -------------------------------------------------------------------------
    // Resolução de dependências com guarda de ambiente.
    // No browser os módulos existem como globais (window/globalThis). Em Node
    // (Vitest) resolvemos via require() relativo. Mantém o app estático intacto.
    // -------------------------------------------------------------------------
    let _ScoringEngine = (typeof globalThis !== 'undefined' && globalThis.ScoringEngine) ? globalThis.ScoringEngine : undefined;
    let _PEPData = (typeof globalThis !== 'undefined' && globalThis.PEPData) ? globalThis.PEPData : undefined;

    if ((!_ScoringEngine || !_PEPData) && typeof require === 'function') {
        try { if (!_ScoringEngine) _ScoringEngine = require('./scoring-engine.js'); } catch (e) { /* ignore */ }
        try { if (!_PEPData) _PEPData = require('./data.js'); } catch (e) { /* ignore */ }
    }

    function getEngine() {
        const eng = _ScoringEngine || (typeof globalThis !== 'undefined' ? globalThis.ScoringEngine : undefined);
        if (!eng) throw new Error('Aggregation: ScoringEngine indisponível');
        return eng;
    }

    function getData() {
        const data = _PEPData || (typeof globalThis !== 'undefined' ? globalThis.PEPData : undefined);
        if (!data) throw new Error('Aggregation: PEPData indisponível');
        return data;
    }

    const round2 = (n) => Math.round(n * 100) / 100;

    const CATEGORY_IDS = ['processos', 'praticas', 'produto', 'pessoas'];

    function emptyCategoryScores() {
        return { processos: 0, praticas: 0, produto: 0, pessoas: 0 };
    }

    // Média aritmética simples de uma lista de números (assume length > 0).
    function mean(values) {
        let sum = 0;
        for (const v of values) sum += v;
        return sum / values.length;
    }

    // Média, categoria a categoria, de uma lista de objetos categoryScores.
    function meanCategoryScores(list) {
        const acc = emptyCategoryScores();
        if (list.length === 0) return acc;
        for (const cs of list) {
            for (const id of CATEGORY_IDS) {
                acc[id] += (cs && typeof cs[id] === 'number') ? cs[id] : 0;
            }
        }
        for (const id of CATEGORY_IDS) {
            acc[id] = round2(acc[id] / list.length);
        }
        return acc;
    }

    // Um time "tem dado" no mês se possui ao menos uma métrica não nula/definida.
    function teamHasData(team) {
        const m = team && team.metrics;
        if (!m || typeof m !== 'object') return false;
        return Object.values(m).some(v =>
            v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v))
        );
    }

    // -------------------------------------------------------------------------
    // journeyScore(month, journeyName)
    // Média dos totalScore dos times COM DADO da jornada no mês.
    // Retorna null (campos neutros) quando a jornada não tem nenhum time com
    // dado no mês — permitindo que as visões indiquem ausência.
    // (Requirement 4.1)
    // -------------------------------------------------------------------------
    function journeyScore(month, journeyName) {
        const engine = getEngine();
        const data = getData();

        const teams = data.getTeamsByJornada(journeyName, month) || [];
        const withData = teams.filter(teamHasData);

        const teamResults = withData.map(team => {
            const scoreResult = engine.calculateTeamScore(team.metrics);
            return {
                teamId: team.id,
                teamName: team.name,
                jornada: team.jornada,
                totalScore: scoreResult.totalScore,
                categoryScores: scoreResult.categoryScores,
                performanceBand: scoreResult.performanceBand,
                scoreResult
            };
        });

        if (teamResults.length === 0) {
            return {
                journey: journeyName,
                month,
                hasData: false,
                avgScore: null,
                teams: [],
                categoryScores: emptyCategoryScores(),
                performanceBand: null
            };
        }

        const avgScore = round2(mean(teamResults.map(t => t.totalScore)));
        const categoryScores = meanCategoryScores(teamResults.map(t => t.categoryScores));
        const performanceBand = engine.getPerformanceBand(avgScore);

        return {
            journey: journeyName,
            month,
            hasData: true,
            avgScore,
            teams: teamResults,
            categoryScores,
            performanceBand
        };
    }

    // -------------------------------------------------------------------------
    // engineeringScore(month)
    // Média dos avgScore das JORNADAS com dado no mês (média de jornadas, NÃO
    // ponderada pelo nº de times). Consolida categoryScores como a média das
    // categoryScores das jornadas e a Faixa de Performance do score médio.
    // (Requirement 3.1)
    // -------------------------------------------------------------------------
    function engineeringScore(month) {
        const engine = getEngine();
        const data = getData();

        const jornadas = data.JORNADAS || [];
        const journeyResults = jornadas
            .map(name => journeyScore(month, name))
            .filter(j => j && j.hasData);

        if (journeyResults.length === 0) {
            return {
                month,
                hasData: false,
                avgScore: null,
                journeys: [],
                categoryScores: emptyCategoryScores(),
                performanceBand: null
            };
        }

        const avgScore = round2(mean(journeyResults.map(j => j.avgScore)));
        const categoryScores = meanCategoryScores(journeyResults.map(j => j.categoryScores));
        const performanceBand = engine.getPerformanceBand(avgScore);

        return {
            month,
            hasData: true,
            avgScore,
            journeys: journeyResults,
            categoryScores,
            performanceBand
        };
    }

    // -------------------------------------------------------------------------
    // previousMonthWithData(month)
    // Retorna o mês COM DADO imediatamente anterior a `month`, usando a lista
    // real de meses com dados (NÃO assume month-1, pois o dataset é esparso).
    // Retorna null quando não existe nenhum mês com dado antes de `month`.
    // -------------------------------------------------------------------------
    function previousMonthWithData(month) {
        const data = getData();
        const months = (data.getMonthsWithData && data.getMonthsWithData()) || [];
        // Maior mês com dado que seja estritamente menor que `month`.
        let prev = null;
        for (const m of months) {
            if (m < month && (prev === null || m > prev)) prev = m;
        }
        return prev;
    }

    // Score de uma entidade (jornada por nome, ou Engenharia quando null) em um
    // mês. Retorna o avgScore quando há dado, ou null caso contrário.
    function entityScore(month, journeyName) {
        if (journeyName === null || journeyName === undefined) {
            const eng = engineeringScore(month);
            return eng.hasData ? eng.avgScore : null;
        }
        const j = journeyScore(month, journeyName);
        return j.hasData ? j.avgScore : null;
    }

    // -------------------------------------------------------------------------
    // monthDelta(month, journeyName|null)
    // Delta de score vs o mês anterior COM DADOS: score(month) − score(prev).
    // Quando journeyName é null → delta do score de Engenharia; caso contrário
    // → delta da jornada informada.
    // Retorna null quando:
    //   - não há mês anterior com dados; OU
    //   - a entidade não possui dado no mês atual ou no mês anterior.
    // (Requirements 3.6, 4.4, 3.8)
    // -------------------------------------------------------------------------
    function monthDelta(month, journeyName = null) {
        const prev = previousMonthWithData(month);
        if (prev === null) return null;

        const current = entityScore(month, journeyName);
        const previous = entityScore(prev, journeyName);
        if (current === null || previous === null) return null;

        return round2(current - previous);
    }

    // -------------------------------------------------------------------------
    // highlights(month)
    // Destaques de maior alta e maior queda de score por JORNADA vs o mês
    // anterior com dados. Só considera jornadas com dado em AMBOS os meses.
    //   - topRise: jornada com o MAIOR delta positivo; null se nenhuma subiu
    //     ou se não há mês anterior / nenhuma jornada comparável.
    //   - topFall: jornada com o MENOR delta (mais negativo); null se nenhuma
    //     caiu ou se não há mês anterior / nenhuma jornada comparável.
    // Em empate, prevalece a primeira na ordem de PEPData.JORNADAS (determinístico).
    // (Requirements 3.6, 4.4, 3.8)
    // -------------------------------------------------------------------------
    function highlights(month) {
        const empty = { topRise: null, topFall: null };

        const prev = previousMonthWithData(month);
        if (prev === null) return empty;

        const data = getData();
        const jornadas = data.JORNADAS || [];

        // Deltas por jornada considerando apenas jornadas com dado em AMBOS os meses.
        const deltas = [];
        for (const name of jornadas) {
            const current = entityScore(month, name);
            const previous = entityScore(prev, name);
            if (current === null || previous === null) continue;
            deltas.push({ journey: name, delta: round2(current - previous) });
        }

        if (deltas.length === 0) return empty;

        // topRise = maior delta positivo (> 0). topFall = menor delta negativo (< 0).
        // Percorremos na ordem de JORNADAS para que empates sejam resolvidos de
        // forma estável pela primeira ocorrência.
        let topRise = null;
        let topFall = null;
        for (const d of deltas) {
            if (d.delta > 0 && (topRise === null || d.delta > topRise.delta)) {
                topRise = { journey: d.journey, delta: d.delta };
            }
            if (d.delta < 0 && (topFall === null || d.delta < topFall.delta)) {
                topFall = { journey: d.journey, delta: d.delta };
            }
        }

        return { topRise, topFall };
    }

    // =========================================================================
    // Task 4.3 — Média de métrica por jornada e distribuição por faixa
    // (Requirements 5.3 e 5.4; Properties 11 e 12)
    //
    // Uma métrica de um time é considerada "Sem Dados" quando seu valor é
    // null/undefined/NaN. Tanto a média (metricAverage) quanto a distribuição
    // (metricDistribution) IGNORAM os times "Sem Dados": eles não entram na
    // soma, na contagem nem no total.
    // =========================================================================

    // Ids das 4 faixas de atendimento (sem 'sem-dados', que é ignorado).
    const BAND_IDS = ['nao-atendeu', 'atendeu-parcialmente', 'atendeu-totalmente', 'superou'];

    // Lista de ids de métrica conhecidos pelo motor (chaves de METRIC_RULES).
    function metricIds() {
        const engine = getEngine();
        const rules = engine.METRIC_RULES || {};
        return Object.keys(rules);
    }

    // Valor "com dado" (distinto de "Sem Dados"): não null/undefined/NaN.
    function hasMetricValue(value) {
        return value !== null && value !== undefined &&
            !(typeof value === 'number' && Number.isNaN(value));
    }

    // -------------------------------------------------------------------------
    // metricAverage(month, journeyName, metricId)
    // Média aritmética dos VALORES (brutos) da métrica entre os times da jornada
    // que possuem dado para aquela métrica no mês. Times "Sem Dados" para a
    // métrica são ignorados.
    //   -> { avg: number|null, count: number }
    //   - count = nº de times considerados (com dado para a métrica).
    //   - avg   = média dos valores, ou null quando nenhum time tem dado.
    // (Requirement 5.3 / Property 11)
    // -------------------------------------------------------------------------
    function metricAverage(month, journeyName, metricId) {
        const data = getData();
        const teams = data.getTeamsByJornada(journeyName, month) || [];

        const values = [];
        for (const team of teams) {
            const m = team && team.metrics;
            const v = m ? m[metricId] : undefined;
            if (hasMetricValue(v)) values.push(v);
        }

        if (values.length === 0) {
            return { avg: null, count: 0 };
        }
        return { avg: round2(mean(values)), count: values.length };
    }

    // -------------------------------------------------------------------------
    // metricDistribution(month, journeyName, metricId)
    // Contagem de times por Faixa de Atendimento para a métrica no mês, usando
    // ScoringEngine.evaluateMetric para classificar cada time COM DADO. Times
    // "Sem Dados" NÃO entram na soma (a soma das 4 faixas = `total` = nº de
    // times com dado para a métrica).
    //   -> { 'nao-atendeu':n, 'atendeu-parcialmente':n,
    //        'atendeu-totalmente':n, 'superou':n, total:n }
    // (Requirement 5.4 / Property 12)
    // -------------------------------------------------------------------------
    function metricDistribution(month, journeyName, metricId) {
        const engine = getEngine();
        const data = getData();
        const teams = data.getTeamsByJornada(journeyName, month) || [];

        const dist = {
            'nao-atendeu': 0,
            'atendeu-parcialmente': 0,
            'atendeu-totalmente': 0,
            'superou': 0,
            total: 0
        };

        for (const team of teams) {
            const m = team && team.metrics;
            const v = m ? m[metricId] : undefined;
            if (!hasMetricValue(v)) continue; // Sem Dados: ignorado.

            const result = engine.evaluateMetric(metricId, v);
            const band = result && result.band;

            // Defensivo: só contamos as 4 faixas de atendimento. Um 'sem-dados'
            // inesperado (não deveria ocorrer aqui) é ignorado para preservar
            // a invariante soma(faixas) === total.
            if (BAND_IDS.includes(band)) {
                dist[band] += 1;
                dist.total += 1;
            }
        }

        return dist;
    }

    // -------------------------------------------------------------------------
    // journeyMetrics(month, journeyName)
    // Agregador de conveniência para a view-metricas: retorna, para cada métrica
    // conhecida, { average, distribution }. Prepara o consumo da Visão de
    // Métricas (Requirements 5.3, 5.4).
    //   -> { [metricId]: { average:{avg,count}, distribution:{...} } }
    // -------------------------------------------------------------------------
    function journeyMetrics(month, journeyName) {
        const out = {};
        for (const id of metricIds()) {
            out[id] = {
                average: metricAverage(month, journeyName, id),
                distribution: metricDistribution(month, journeyName, id)
            };
        }
        return out;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        journeyScore,
        engineeringScore,
        monthDelta,
        highlights,
        // Task 4.3
        metricAverage,
        metricDistribution,
        journeyMetrics
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped and `Aggregation` stays global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Aggregation;
}

if (typeof globalThis !== 'undefined') {
    globalThis.Aggregation = Aggregation;
}
