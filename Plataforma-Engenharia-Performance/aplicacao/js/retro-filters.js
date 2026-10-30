/**
 * PEP - Plataforma de Engenharia de Performance
 * Store de filtros de Retrospectiva de PI (`retro-filters.js`)
 *
 * Task 22 (spec score-performance-executivo). Requirements 20.1, 20.2, 20.3,
 * 20.5, 20.7.
 *
 * Store observável do estado de filtros `{ pi, jornada, squad, sprint }` dos
 * painéis de Retrospectiva de PI, com:
 *   - encadeamento Jornada→Squad (selecionar jornada restringe os squads) e
 *     PI→Sprint (selecionar PI restringe as sprints);                  (20.2, 20.3)
 *   - valor sentinela "all" (Todos) que agrega a dimensão;             (20.5)
 *   - persistência em localStorage para manter a seleção ao navegar.   (20.7)
 *
 * As opções de Squad vêm da identificação canônica (PEPIdentity) e as de
 * Sprint/PI do dataset (PEPRetroData), mantendo consistência entre módulos.
 *
 * IIFE com guarda de ambiente para teste em Node.
 */

const RetroFilters = (() => {
    'use strict';

    const ALL = 'all';
    const STORAGE_KEY = 'pep.retroFilters';

    const state = { pi: ALL, jornada: ALL, squad: ALL, sprint: ALL };
    const subscribers = [];

    function dep(name, relPath) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        if (relPath && typeof require !== 'undefined') {
            try { return require(relPath); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    function retroData() { return dep('PEPRetroData', './retro-data.js'); }
    function identity() { return dep('PEPIdentity', './identity.js'); }

    // --- Persistência (defensiva: localStorage pode não existir em teste) ---
    function persist() {
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            }
        } catch (_) { /* ignore */ }
    }
    function restore() {
        try {
            if (typeof localStorage !== 'undefined') {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (raw) {
                    const o = JSON.parse(raw);
                    ['pi', 'jornada', 'squad', 'sprint'].forEach(k => {
                        if (o && typeof o[k] === 'string') state[k] = o[k];
                    });
                }
            }
        } catch (_) { /* ignore */ }
    }

    function notify() {
        const snap = getState();
        subscribers.forEach(fn => { try { fn(snap); } catch (_) {} });
    }

    // =========================================================================
    // OPÇÕES (dependem do estado atual para o encadeamento)
    // =========================================================================

    // PIs disponíveis (do dataset).
    function piOptions() {
        const d = retroData();
        const pis = (d && typeof d.getPIs === 'function') ? d.getPIs() : [];
        return [ALL, ...pis];
    }

    // Jornadas disponíveis (identificação canônica).
    function jornadaOptions() {
        const id = identity();
        const js = (id && typeof id.jornadas === 'function') ? id.jornadas() : [];
        return [ALL, ...js];
    }

    // Squads restritos à Jornada selecionada (20.2). Com jornada "all", lista
    // todos os squads conhecidos.
    function squadOptions() {
        const id = identity();
        if (!id) return [ALL];
        if (state.jornada !== ALL && typeof id.squadsOf === 'function') {
            return [ALL, ...id.squadsOf(state.jornada)];
        }
        // Todos os squads de todas as jornadas.
        const todos = [];
        (typeof id.jornadas === 'function' ? id.jornadas() : []).forEach(j => {
            (id.squadsOf(j) || []).forEach(s => { if (!todos.includes(s)) todos.push(s); });
        });
        return [ALL, ...todos];
    }

    // Sprints restritas ao PI selecionado (20.3). Com PI "all", lista todas as
    // sprints presentes no dataset de pontos trabalhados.
    function sprintOptions() {
        const d = retroData();
        if (!d || typeof d.query !== 'function') return [ALL];
        const regs = d.query('pontosTrabalhados', state.pi !== ALL ? { pi: state.pi } : {});
        const sprints = [];
        regs.forEach(r => { if (r.sprint && !sprints.includes(r.sprint)) sprints.push(r.sprint); });
        return [ALL, ...sprints];
    }

    // =========================================================================
    // MUTADORES (aplicam o encadeamento e persistem)
    // =========================================================================

    function setPI(pi) {
        state.pi = pi || ALL;
        // Encadeamento PI→Sprint: se a sprint atual não pertence ao PI, reseta.
        if (state.sprint !== ALL && !sprintOptions().includes(state.sprint)) {
            state.sprint = ALL;
        }
        persist(); notify();
    }

    function setJornada(jornada) {
        state.jornada = jornada || ALL;
        // Encadeamento Jornada→Squad: se o squad atual não é da jornada, reseta.
        if (state.squad !== ALL && !squadOptions().includes(state.squad)) {
            state.squad = ALL;
        }
        persist(); notify();
    }

    function setSquad(squad) {
        const next = squad || ALL;
        // Só aceita squads válidos para a jornada atual (ou "all").
        if (next === ALL || squadOptions().includes(next)) {
            state.squad = next;
            persist(); notify();
        }
    }

    function setSprint(sprint) {
        const next = sprint || ALL;
        if (next === ALL || sprintOptions().includes(next)) {
            state.sprint = next;
            persist(); notify();
        }
    }

    // Define vários de uma vez (respeitando o encadeamento, nesta ordem).
    function setFilters(partial) {
        if (!partial) return;
        if ('pi' in partial) setPI(partial.pi);
        if ('jornada' in partial) setJornada(partial.jornada);
        if ('squad' in partial) setSquad(partial.squad);
        if ('sprint' in partial) setSprint(partial.sprint);
    }

    function reset() {
        state.pi = ALL; state.jornada = ALL; state.squad = ALL; state.sprint = ALL;
        persist(); notify();
    }

    function getState() { return { ...state }; }

    // Converte o estado em um filtro de consulta (omite dimensões "all").
    function toQuery() {
        const q = {};
        if (state.pi !== ALL) q.pi = state.pi;
        if (state.jornada !== ALL) q.jornada = state.jornada;
        if (state.squad !== ALL) q.squad = state.squad;
        if (state.sprint !== ALL) q.sprint = state.sprint;
        return q;
    }

    function subscribe(fn) {
        if (typeof fn === 'function') subscribers.push(fn);
        return () => {
            const i = subscribers.indexOf(fn);
            if (i >= 0) subscribers.splice(i, 1);
        };
    }

    // Inicializa a partir do localStorage (sem notificar).
    restore();

    return {
        ALL,
        getState, toQuery,
        piOptions, jornadaOptions, squadOptions, sprintOptions,
        setPI, setJornada, setSquad, setSprint, setFilters, reset,
        subscribe
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = RetroFilters;
}
if (typeof globalThis !== 'undefined') {
    globalThis.RetroFilters = RetroFilters;
}
