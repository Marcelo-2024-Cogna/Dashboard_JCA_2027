/**
 * PEP - Plataforma de Engenharia de Performance
 * MetricConfig — Parametrização de Métricas (fotos mensal/PI + origem por métrica)
 *
 * Camada de PARAMETRIZAÇÃO (sem backend). Centraliza, de forma editável e
 * persistível em localStorage:
 *   1. ORIGEM de cada métrica mensal do Score ('jira' | 'manual' |
 *      'integracao-futura'), usando ScoringEngine.METRIC_RULES como default.
 *   2. CONFIG de fechamento por PI (sprintFechamento = 5 ou 6), usando
 *      PEPRetroData.PI_CONFIG como default.
 *   3. Definição das duas FOTOS (snapshots) previstas:
 *        - Foto Mensal  → último dia útil do mês (compõe o Score do mês).
 *        - Foto de PI   → sprint de fechamento do PI (5 ou 6, parametrizável).
 *
 * Decisões de negócio validadas (ver documentacao/parametrizacao-metricas-jira.md):
 *   - OKRs: fonte Jira via Must Win Battles (MWB).
 *   - Frequência de Deploy: manual agora; integração futura com Git.
 *   - Disponibilidade / Vulnerabilidade / Team Health Check: manual agora;
 *     integração futura (monitoração / security scan / pesquisa THC).
 *   - Demais (Planejado vs Realizado, Cycle Times, SLAs): Jira.
 *
 * IMPORTANTE: este módulo NÃO coleta do Jira nem grava fotos — isso depende de
 * backend (fora do escopo estático). Ele apenas PARAMETRIZA e persiste as
 * escolhas, deixando a coleta/persistência prontas para ligar quando o backend
 * existir. Segue o padrão IIFE + guarda de ambiente dos demais módulos.
 */

const MetricConfig = (() => {

    // -------------------------------------------------------------------------
    // Origens possíveis de uma métrica.
    // -------------------------------------------------------------------------
    const ORIGENS = {
        jira:               { id: 'jira',               label: 'Jira (coleta)',            automatizavel: true },
        manual:             { id: 'manual',             label: 'Entrada manual',           automatizavel: false },
        'integracao-futura':{ id: 'integracao-futura',  label: 'Integração futura',        automatizavel: false }
    };

    // Ritmo das fotos (snapshots).
    const FOTOS = {
        mensal: {
            id: 'mensal',
            label: 'Foto Mensal',
            quando: 'ultimo-dia-util',
            descricao: 'Congela no último dia útil do mês; compõe o Score do mês e o comparativo mês a mês.',
            escopo: 'metricas-score'
        },
        pi: {
            id: 'pi',
            label: 'Foto de PI',
            quando: 'sprint-fechamento',
            descricao: 'Congela na sprint de fechamento do PI (5 ou 6, parametrizável no plano do PI); comparativo PI a PI.',
            escopo: 'metricas-retrospectiva'
        }
    };

    // Valores válidos para a sprint de fechamento do PI.
    const SPRINTS_FECHAMENTO_VALIDAS = [5, 6];

    // -------------------------------------------------------------------------
    // Resolução de dependências (browser via globalThis; Node via require).
    // -------------------------------------------------------------------------
    function resolveScoring() {
        if (typeof ScoringEngine !== 'undefined') return ScoringEngine;
        if (typeof globalThis !== 'undefined' && globalThis.ScoringEngine) return globalThis.ScoringEngine;
        return null;
    }
    function resolveRetro() {
        if (typeof PEPRetroData !== 'undefined') return PEPRetroData;
        if (typeof globalThis !== 'undefined' && globalThis.PEPRetroData) return globalThis.PEPRetroData;
        return null;
    }

    // -------------------------------------------------------------------------
    // Storage defensivo (localStorage utilizável; senão, memória) — mesmo
    // padrão de admin-store.js / app-state.js.
    // -------------------------------------------------------------------------
    const STORAGE_KEY = 'pep.config.metricas';

    function createMemoryStore() {
        const mem = Object.create(null);
        return {
            getItem(k) { return Object.prototype.hasOwnProperty.call(mem, k) ? mem[k] : null; },
            setItem(k, v) { mem[k] = String(v); },
            removeItem(k) { delete mem[k]; },
            _isMemory: true
        };
    }
    function resolveStore() {
        try {
            if (typeof localStorage !== 'undefined' && localStorage) {
                const probe = '__pep_probe__';
                localStorage.setItem(probe, '1');
                localStorage.removeItem(probe);
                return localStorage;
            }
        } catch (_) { /* cai para memória */ }
        return createMemoryStore();
    }
    let store = resolveStore();

    function readOverrides() {
        try {
            const raw = store.getItem(STORAGE_KEY);
            if (!raw) return { origem: {}, pi: {} };
            const parsed = JSON.parse(raw);
            return {
                origem: (parsed && parsed.origem) || {},
                pi: (parsed && parsed.pi) || {}
            };
        } catch (_) {
            return { origem: {}, pi: {} };
        }
    }
    function writeOverrides(obj) {
        store.setItem(STORAGE_KEY, JSON.stringify({
            origem: obj.origem || {},
            pi: obj.pi || {}
        }));
    }

    // =========================================================================
    // 1. ORIGEM POR MÉTRICA MENSAL (Score)
    // =========================================================================

    // Lista as 12 métricas mensais com sua origem efetiva (default do
    // scoring-engine, sobreposta pelo override persistido se houver).
    function listMetricas() {
        const SE = resolveScoring();
        if (!SE || !SE.METRIC_RULES) return [];
        const overrides = readOverrides().origem;
        return Object.entries(SE.METRIC_RULES).map(([id, rule]) => {
            const meta = (SE.METRICS && SE.METRICS[id]) || {};
            const origemDefault = rule.origem || 'manual';
            const origem = overrides[id] || origemDefault;
            return {
                id,
                nome: meta.name || id,
                categoria: rule.category,
                peso: rule.max,
                unidade: meta.unit || rule.scale,
                origemDefault,
                origem,
                origemLabel: (ORIGENS[origem] && ORIGENS[origem].label) || origem,
                integracaoFutura: rule.integracaoFutura || null,
                origemDetalhe: rule.origemDetalhe || null
            };
        });
    }

    // Origem efetiva de uma métrica (override > default).
    function getOrigem(metricId) {
        const SE = resolveScoring();
        const rule = SE && SE.METRIC_RULES ? SE.METRIC_RULES[metricId] : null;
        if (!rule) return null;
        const overrides = readOverrides().origem;
        return overrides[metricId] || rule.origem || 'manual';
    }

    // Define a origem de uma métrica (persistido). Retorna true se aplicado.
    function setOrigem(metricId, origem) {
        const SE = resolveScoring();
        if (!SE || !SE.METRIC_RULES || !SE.METRIC_RULES[metricId]) return false;
        if (!ORIGENS[origem]) return false;
        const cur = readOverrides();
        cur.origem[metricId] = origem;
        writeOverrides(cur);
        return true;
    }

    // Métricas que exigem entrada manual na foto (origem != 'jira').
    function metricasManuais() {
        return listMetricas().filter((m) => m.origem !== 'jira');
    }

    // =========================================================================
    // 2. CONFIG DE FECHAMENTO POR PI
    // =========================================================================

    // sprintFechamento efetivo de um PI (override > default do retro-data).
    function getSprintFechamento(pi) {
        const overrides = readOverrides().pi;
        if (overrides[pi] && overrides[pi].sprintFechamento) return overrides[pi].sprintFechamento;
        const RD = resolveRetro();
        if (RD && typeof RD.getSprintFechamento === 'function') return RD.getSprintFechamento(pi);
        return (RD && RD.DEFAULT_SPRINT_FECHAMENTO) || 6;
    }

    // Define a sprint de fechamento (5 ou 6) de um PI (persistido).
    function setSprintFechamento(pi, sprint) {
        const n = Math.trunc(Number(sprint));
        if (!SPRINTS_FECHAMENTO_VALIDAS.includes(n)) return false;
        const cur = readOverrides();
        cur.pi[pi] = Object.assign({}, cur.pi[pi], { sprintFechamento: n });
        writeOverrides(cur);
        return true;
    }

    // Lista os PIs com sua sprint de fechamento efetiva.
    function listPIs() {
        const RD = resolveRetro();
        const pis = (RD && typeof RD.getPIs === 'function') ? RD.getPIs() : [];
        return pis.map((pi) => ({ pi, sprintFechamento: getSprintFechamento(pi) }));
    }

    // =========================================================================
    // Utilidades gerais
    // =========================================================================

    // Limpa os overrides persistidos (volta aos defaults do seed/engine).
    function reset() {
        try { store.removeItem(STORAGE_KEY); } catch (_) { /* noop */ }
    }

    // Reavalia o storage (útil em testes que trocam o ambiente).
    function _refreshStore() { store = resolveStore(); }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        ORIGENS,
        FOTOS,
        SPRINTS_FECHAMENTO_VALIDAS,

        // Origem por métrica (mensal / Score)
        listMetricas,
        getOrigem,
        setOrigem,
        metricasManuais,

        // Config de fechamento por PI
        getSprintFechamento,
        setSprintFechamento,
        listPIs,

        // Utilidades
        reset,
        _refreshStore
    };
})();

// Environment guard: expõe o módulo para testes Node (Vitest) sem afetar o
// carregamento por <script> no browser.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MetricConfig;
}

if (typeof globalThis !== 'undefined') {
    globalThis.MetricConfig = MetricConfig;
}
