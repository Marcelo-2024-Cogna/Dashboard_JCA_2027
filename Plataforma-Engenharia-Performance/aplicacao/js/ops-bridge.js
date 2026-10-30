/**
 * PEP - Plataforma de Engenharia de Performance
 * Ponte Operacional/Times ↔ demais visões (`ops-bridge.js`)
 *
 * Task 25 (spec score-performance-executivo). Requirements 23.2, 23.3, 23.4, 23.5.
 *
 * O Operacional/Times (modelo mensal do Score, `data.js`) é a FONTE das
 * métricas de Score por Jornada/Squad e alimenta as visões executivas. Esta
 * ponte:
 *   - expõe os squads/jornadas do Operacional de forma canônica (via
 *     PEPIdentity) para que Retro PI e Score usem a MESMA identidade;  (23.3)
 *   - mapeia indicadores derivados da extração do Jira que correspondem a
 *     métricas do Score (ex.: Cycle Time Desenvolvimento, Frequência de
 *     Deploy) para as entradas do Operacional/Times, resolvendo o Squad à
 *     identificação canônica;                                          (23.2)
 *   - deriva, do Operacional/Times, o resumo de Score que alimenta as visões
 *     executivas, refletindo atualizações dos dados;                   (23.2, 23.4)
 *   - detecta Squads sem correspondência entre as áreas, sinalizando sem
 *     corromper dados.                                                  (23.5)
 *
 * NÃO sobrescreve os dados do Score silenciosamente: produz um "patch" sugerido
 * que a UI/consumidor pode aplicar explicitamente, preservando a entrada manual.
 * Também não fabrica métricas desconhecidas: só mapeia para chaves que existem
 * de fato em `METRIC_RULES` do motor de Score.
 *
 * IIFE com guarda de ambiente para teste em Node.
 */

const OpsBridge = (() => {
    'use strict';

    function dep(name, relPath) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        if (relPath && typeof require !== 'undefined') {
            try { return require(relPath); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    const avg = (arr) => arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : null;

    // =========================================================================
    // MAPA DECLARATIVO: indicador derivado do Jira → métrica do Score (23.2/23.3)
    //
    // Cada entrada liga um campo presente numa linha de indicador derivado da
    // extração do Jira (ver `JiraIngest.buildRetroFromJira`) à chave canônica de
    // uma métrica do Score (ver `ScoringEngine.METRIC_RULES`). A ponte só emite
    // chaves que existam de fato no motor (validação em `knownScoreMetrics`),
    // evitando corromper o modelo do Operacional/Times com métricas inventadas.
    //
    //   desenvolvimento  → cycleTimeDev      (Cycle Time Desenvolvimento, dias)
    //   homologacao      → cycleTimeHomolog  (Cycle Time Homologação, dias)
    //   implantacao      → cycleTimeImpl     (Cycle Time Implantação, dias)
    //   freqDeploy       → freqDeploy        (Frequência de Deploy, categoria)
    //
    // Observação: `freqDeploy` é uma categoria inteira no Score; só é mapeado
    // quando a linha derivada o fornecer (a extração padrão de itens não deriva
    // frequência de deploy — ver jira-ingest.js — mas a ponte aceita o valor
    // quando outra fonte o calcular, conforme Requirement 23.3).
    // =========================================================================
    const METRIC_MAP = {
        desenvolvimento: 'cycleTimeDev',
        homologacao: 'cycleTimeHomolog',
        implantacao: 'cycleTimeImpl',
        freqDeploy: 'freqDeploy'
    };

    // Conjunto de métricas de Score reconhecidas pelo motor (fonte da verdade).
    // Se o motor não estiver disponível (ex.: teste isolado sem dependência),
    // cai para as chaves do próprio METRIC_MAP para não travar o mapeamento.
    function knownScoreMetrics() {
        const engine = dep('ScoringEngine', './scoring-engine.js');
        if (engine && engine.METRIC_RULES && typeof engine.METRIC_RULES === 'object') {
            return new Set(Object.keys(engine.METRIC_RULES));
        }
        return new Set(Object.values(METRIC_MAP));
    }

    // Rótulo canônico de um squad (aplica aliases/normalização via PEPIdentity).
    // Se a identidade não reconhecer o squad, devolve o rótulo original (não
    // corrompe) e deixa a sinalização de divergência a cargo do chamador.
    function canonicalSquad(id, squad) {
        if (id && typeof id.canonicalSquad === 'function') {
            const canon = id.canonicalSquad(squad);
            if (canon) return canon;
        }
        return squad;
    }

    // =========================================================================
    // RECONCILIAÇÃO DE IDENTIDADE (23.3, 23.5)
    //
    // Compara os squads presentes no Operacional/Times (fonte canônica) com os
    // squads presentes num conjunto de registros de Retro (ou extração do Jira),
    // retornando: emAmbos, soNoOperacional, soNoRetro — por chave normalizada.
    // =========================================================================
    function reconcileSquads(retroSquads) {
        const id = dep('PEPIdentity', './identity.js');
        const data = dep('PEPData', './data.js');

        const opsSquads = [];
        if (data && Array.isArray(data.TEAMS)) {
            data.TEAMS.forEach(t => { if (t.name && !opsSquads.includes(t.name)) opsSquads.push(t.name); });
        } else if (id && typeof id.jornadas === 'function') {
            id.jornadas().forEach(j => (id.squadsOf(j) || []).forEach(s => { if (!opsSquads.includes(s)) opsSquads.push(s); }));
        }

        const normFn = (id && id.normalize) ? id.normalize : (s) => String(s).toLowerCase().trim();
        // Resolve cada squad do Retro ao rótulo canônico (aplica aliases) antes
        // de comparar, para que "Atendimento" case com "Athenas - Atendimento".
        const canon = (s) => (id && id.canonicalSquad && id.canonicalSquad(s)) || s;
        const opsKeys = new Map(opsSquads.map(s => [normFn(s), s]));
        const retroKeys = new Map((retroSquads || []).map(s => [normFn(canon(s)), canon(s)]));

        const emAmbos = [];
        const soNoOperacional = [];
        const soNoRetro = [];

        opsKeys.forEach((label, key) => {
            if (retroKeys.has(key)) emAmbos.push(label); else soNoOperacional.push(label);
        });
        retroKeys.forEach((label, key) => {
            if (!opsKeys.has(key)) soNoRetro.push(label);
        });

        return { emAmbos, soNoOperacional, soNoRetro };
    }

    // =========================================================================
    // MAPA Retro(Jira) → métricas de Score (23.2, 23.3, 23.5)
    //
    // A partir dos indicadores derivados da extração do Jira (ex.: cycle time
    // por fase por squad, e opcionalmente freqDeploy), produz um "patch" sugerido
    // de métricas do Score por squad, no formato aceito pelo Operacional/Times.
    // Não aplica — apenas sugere, para a UI aplicar explicitamente (preserva a
    // entrada manual).
    //
    // Retorna { patch, divergences }:
    //   - patch: { [squadCanonico]: { [scoreMetricId]: valor } }
    //   - divergences: [{ type:'unknownSquad', squad }] para squads sem
    //     correspondência na identidade canônica (sinaliza sem corromper: a
    //     linha ainda é mapeada sob o rótulo original para não perder o dado).
    //
    // Mantém compatibilidade: o objeto retornado também é "patch-like" através
    // de suggestScorePatch (abaixo), que devolve só o patch.
    // =========================================================================
    function mapToScore(retroCycleByTime) {
        const id = dep('PEPIdentity', './identity.js');
        const known = knownScoreMetrics();
        const patch = {};
        const divergences = [];
        const seenUnknown = new Set();

        (retroCycleByTime || []).forEach(row => {
            const rawSquad = row.time || row.squad;
            if (rawSquad == null || String(rawSquad).trim() === '') return;

            // Resolve ao rótulo canônico; sinaliza divergência quando o squad não
            // for reconhecido (23.5) — mas ainda assim mapeia sob o rótulo
            // original para não descartar o dado silenciosamente.
            let squad = rawSquad;
            const reconhecido = !!(id && id.canonicalSquad && id.canonicalSquad(rawSquad));
            if (reconhecido) {
                squad = canonicalSquad(id, rawSquad);
            } else if (id && typeof id.canonicalSquad === 'function') {
                const key = String(rawSquad);
                if (!seenUnknown.has(key)) {
                    seenUnknown.add(key);
                    divergences.push({ type: 'unknownSquad', squad: rawSquad });
                }
            }

            const metrics = {};
            Object.keys(METRIC_MAP).forEach(src => {
                const target = METRIC_MAP[src];
                if (!known.has(target)) return;               // só métricas reais
                const v = row[src];
                if (Number.isFinite(v)) metrics[target] = v;  // ignora null/NaN
            });

            if (Object.keys(metrics).length > 0) {
                // Mescla quando houver mais de uma linha para o mesmo squad (a
                // última vence por chave de métrica, preservando as já definidas).
                patch[squad] = Object.assign({}, patch[squad], metrics);
            }
        });

        return { patch, divergences };
    }

    // Compat: devolve apenas o patch (comportamento histórico desta função).
    function suggestScorePatch(retroCycleByTime) {
        return mapToScore(retroCycleByTime).patch;
    }

    // =========================================================================
    // ALIMENTAÇÃO DAS VISÕES (23.2, 23.4)
    //
    // Resumo de Score por Jornada derivado do Operacional/Times para um mês,
    // reutilizando a agregação já existente (Aggregation). Serve de ponto único
    // para as visões executivas consumirem (quando precisarem via a ponte).
    // Como lê diretamente de PEPData/Aggregation, qualquer atualização do
    // Operacional/Times (importação ou entrada manual) se reflete aqui (23.4).
    // =========================================================================
    function scoreByJornada(month) {
        const agg = dep('Aggregation', './aggregation.js');
        if (!agg || typeof agg.engineeringScore !== 'function') return null;
        const eng = agg.engineeringScore(month);
        return (eng && eng.hasData) ? eng : null;
    }

    return {
        METRIC_MAP,
        reconcileSquads,
        mapToScore,
        suggestScorePatch,
        scoreByJornada,
        _avg: avg,
        _knownScoreMetrics: knownScoreMetrics
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = OpsBridge;
}
if (typeof globalThis !== 'undefined') {
    globalThis.OpsBridge = OpsBridge;
}
