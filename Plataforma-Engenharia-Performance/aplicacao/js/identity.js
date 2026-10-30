/**
 * PEP - Plataforma de Engenharia de Performance
 * Identificação canônica de Jornada/Squad (`identity.js`)
 *
 * Task 21 (spec score-performance-executivo). Requirements 23.1, 23.5.
 *
 * Fornece um mapa ÚNICO Jornada↔Squad reutilizado pelo módulo de Retrospectiva
 * de PI e pelo Operacional/Times (Score de Performance), garantindo que o mesmo
 * Squad e a mesma Jornada sejam identificados de forma idêntica nos dois módulos
 * (Requirement 23.1). Também detecta divergências de identificação sem corromper
 * dados (Requirement 23.5).
 *
 * A fonte canônica é `PEPData` (modelo mensal do Score), que já lista as 10
 * jornadas e seus squads. O módulo normaliza nomes (trim, colapso de espaços,
 * remoção de acentos e caixa) para casar identificadores escritos de formas
 * ligeiramente diferentes entre as planilhas/extrações.
 *
 * IIFE com guarda de ambiente (`module.exports`/`globalThis`) para teste em Node
 * sem afetar o carregamento por <script> no browser.
 */

const PEPIdentity = (() => {
    'use strict';

    function getData() {
        if (typeof PEPData !== 'undefined' && PEPData) return PEPData;
        if (typeof globalThis !== 'undefined' && globalThis.PEPData) return globalThis.PEPData;
        if (typeof require !== 'undefined') {
            try { return require('./data.js'); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    // Normaliza um nome para comparação: trim, colapso de espaços, sem acento,
    // minúsculas, e remove espaços internos para casar "Route 66"/"Route66".
    // Usado como CHAVE canônica (não altera o rótulo exibido).
    function normalize(name) {
        if (name == null) return '';
        return String(name)
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove acentos
            .replace(/\s+/g, '')                                // "Route 66" == "Route66"
            .trim()
            .toLowerCase();
    }

    // Aliases conhecidos: variações de nome de squad (vindas de extrações/Jira)
    // que apontam para o rótulo canônico do Operacional/Times. A chave usa a
    // normalização acima; o valor é o rótulo canônico esperado em PEPData.
    // Resolve divergências reais entre as planilhas (ex.: "Atendimento" na aba
    // de Retro vs "Athenas - Atendimento" no Operacional).
    const SQUAD_ALIASES = {
        'atendimento': 'Athenas - Atendimento'
        // "kbots"→"kBots", "route66"/"route 66"→"Route66" e "papa-leguas" já
        // casam pela normalização (acento/espaço/caixa), não precisam de alias.
    };

    // Constrói o índice canônico a partir de PEPData.
    //   squadKey -> { squad, jornada }   e   jornadaKey -> jornadaLabel
    function buildIndex() {
        const data = getData();
        const squadByKey = new Map();   // norm(squad) -> { squad, jornada }
        const jornadaByKey = new Map(); // norm(jornada) -> jornada (rótulo)
        const squadsByJornada = new Map(); // norm(jornada) -> [squad labels]

        const jornadas = (data && Array.isArray(data.JORNADAS)) ? data.JORNADAS : [];
        jornadas.forEach(j => {
            jornadaByKey.set(normalize(j), j);
            if (!squadsByJornada.has(normalize(j))) squadsByJornada.set(normalize(j), []);
        });

        const teams = (data && Array.isArray(data.TEAMS)) ? data.TEAMS
            : (data && typeof data.teamSnapshotFor === 'function')
                ? collectAllTeams(data) : [];

        teams.forEach(t => {
            const squad = t.name != null ? t.name : t.squad;
            const jornada = t.jornada;
            if (squad == null || jornada == null) return;
            squadByKey.set(normalize(squad), { squad, jornada });
            const jk = normalize(jornada);
            if (!jornadaByKey.has(jk)) jornadaByKey.set(jk, jornada);
            if (!squadsByJornada.has(jk)) squadsByJornada.set(jk, []);
            const lista = squadsByJornada.get(jk);
            if (!lista.includes(squad)) lista.push(squad);
        });

        return { squadByKey, jornadaByKey, squadsByJornada };
    }

    // Reúne todos os times de todos os meses quando TEAMS não é exposto direto.
    function collectAllTeams(data) {
        const out = [];
        const seen = new Set();
        for (let m = 1; m <= 12; m++) {
            let snap = [];
            try { snap = data.teamSnapshotFor(m) || []; } catch (_) { snap = []; }
            snap.forEach(t => {
                const key = `${t.name}|${t.jornada}`;
                if (!seen.has(key)) { seen.add(key); out.push(t); }
            });
        }
        return out;
    }

    let _index = null;
    function index() {
        if (!_index) _index = buildIndex();
        return _index;
    }
    // Permite recomputar (ex.: após importação alterar PEPData em teste).
    function refresh() { _index = buildIndex(); return _index; }

    // =========================================================================
    // API pública
    // =========================================================================

    // Resolve a entrada canônica de um squad, aplicando aliases quando a chave
    // direta não existir no índice.
    function resolveSquadEntry(squad) {
        const key = normalize(squad);
        const direct = index().squadByKey.get(key);
        if (direct) return direct;
        const aliasLabel = SQUAD_ALIASES[key];
        if (aliasLabel) {
            const viaAlias = index().squadByKey.get(normalize(aliasLabel));
            if (viaAlias) return viaAlias;
        }
        return null;
    }

    // Jornada canônica de um Squad (pelo nome, tolerante a acento/caixa/espaço).
    // Retorna o rótulo canônico da jornada, ou null se o squad for desconhecido.
    function jornadaOf(squad) {
        const hit = resolveSquadEntry(squad);
        return hit ? hit.jornada : null;
    }

    // Rótulo canônico de uma Jornada (normaliza variações de escrita).
    function canonicalJornada(jornada) {
        return index().jornadaByKey.get(normalize(jornada)) || null;
    }

    // Rótulo canônico de um Squad (aplica aliases conhecidos).
    function canonicalSquad(squad) {
        const hit = resolveSquadEntry(squad);
        return hit ? hit.squad : null;
    }

    // Lista de squads (rótulos) de uma Jornada.
    function squadsOf(jornada) {
        return [...(index().squadsByJornada.get(normalize(jornada)) || [])];
    }

    // Lista de jornadas canônicas.
    function jornadas() {
        return [...index().jornadaByKey.values()];
    }

    // Dois identificadores referem-se à mesma entidade canônica?
    function sameSquad(a, b) { return normalize(a) === normalize(b); }
    function sameJornada(a, b) { return normalize(a) === normalize(b); }

    // Detecta divergências entre uma lista de pares {squad, jornada} (ex.: vinda
    // de uma extração/baseline) e a identificação canônica. Não altera dados;
    // apenas reporta (Requirement 23.5):
    //   - unknownSquad: squad não encontrado no índice canônico;
    //   - jornadaMismatch: squad existe, mas associado a outra jornada.
    function detectDivergences(pairs) {
        const out = [];
        (pairs || []).forEach(p => {
            const squad = p.squad != null ? p.squad : p.name;
            const jornada = p.jornada;
            const canon = resolveSquadEntry(squad);
            if (!canon) {
                out.push({ type: 'unknownSquad', squad, jornada });
                return;
            }
            if (jornada != null && !sameJornada(canon.jornada, jornada)) {
                out.push({ type: 'jornadaMismatch', squad, jornadaInformada: jornada, jornadaCanonica: canon.jornada });
            }
        });
        return out;
    }

    return {
        normalize,
        refresh,
        jornadaOf,
        canonicalJornada,
        canonicalSquad,
        squadsOf,
        jornadas,
        sameSquad,
        sameJornada,
        detectDivergences
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = PEPIdentity;
}
if (typeof globalThis !== 'undefined') {
    globalThis.PEPIdentity = PEPIdentity;
}
