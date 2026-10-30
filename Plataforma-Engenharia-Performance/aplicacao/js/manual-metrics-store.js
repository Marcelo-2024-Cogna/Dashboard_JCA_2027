/**
 * PEP - Plataforma de Engenharia de Performance
 * ManualMetricsStore — Entrada manual de métricas no fechamento (`js/manual-metrics-store.js`)
 *
 * Persiste os valores informados MANUALMENTE para as métricas cuja origem não é
 * o Jira (hoje: Frequência de Deploy, Disponibilidade, Vulnerabilidade, Team
 * Health Check — ver MetricConfig/ScoringEngine). Esses valores alimentam a
 * Foto Mensal no fechamento. Chave de cada registro: (mês, squadId, métrica).
 *
 * Mantém a natureza estática: localStorage com fallback para memória (mesmo
 * padrão de admin-store.js / app-state.js / excecoes-store.js). Sem backend.
 *
 * IIFE com guarda de ambiente (`module.exports`/`globalThis`) para teste em Node.
 */

const ManualMetricsStore = (() => {
    'use strict';

    const STORAGE_KEY = 'pep.manualMetrics';

    const subscribers = [];

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
            const ls = (typeof globalThis !== 'undefined' && globalThis.localStorage)
                ? globalThis.localStorage
                : (typeof localStorage !== 'undefined' ? localStorage : undefined);
            if (!ls) return createMemoryStore();
            const probe = '__pep_mm_probe__';
            ls.setItem(probe, '1'); ls.removeItem(probe);
            return ls;
        } catch (e) { return createMemoryStore(); }
    }

    let store = resolveStore();

    // Estado: { "<mes>||<squadId>||<metrica>": valorNumerico }
    let data = {};
    load();

    function keyOf(mes, squadId, metrica) {
        return `${String(mes)}||${String(squadId)}||${String(metrica)}`;
    }

    function persist() {
        try { store.setItem(STORAGE_KEY, JSON.stringify(data)); } catch (e) { /* ignora */ }
    }
    function load() {
        let raw = null;
        try { raw = store.getItem(STORAGE_KEY); } catch (e) { raw = null; }
        if (!raw) { data = {}; return; }
        try { const p = JSON.parse(raw); data = (p && typeof p === 'object') ? p : {}; }
        catch (e) { data = {}; }
    }

    function notify() {
        for (const fn of subscribers.slice()) {
            try { fn(); } catch (e) { /* isolado */ }
        }
    }
    function subscribe(fn) {
        if (typeof fn !== 'function') return () => {};
        subscribers.push(fn);
        return () => { const i = subscribers.indexOf(fn); if (i >= 0) subscribers.splice(i, 1); };
    }

    // Grava um valor manual. `valor` deve ser numérico (ou numérico-string).
    // Retorna true quando persistido; false para entrada inválida.
    function set(mes, squadId, metrica, valor) {
        if (mes == null || squadId == null || metrica == null) return false;
        // String vazia/espaços não é valor numérico válido (Number('') === 0).
        if (typeof valor === 'string' && valor.trim() === '') return false;
        if (valor == null) return false;
        const n = Number(valor);
        if (!Number.isFinite(n)) return false;
        data[keyOf(mes, squadId, metrica)] = n;
        persist(); notify();
        return true;
    }

    // Lê um valor manual (ou null se não informado).
    function get(mes, squadId, metrica) {
        const v = data[keyOf(mes, squadId, metrica)];
        return (v == null) ? null : v;
    }

    // Remove um valor manual.
    function remove(mes, squadId, metrica) {
        const k = keyOf(mes, squadId, metrica);
        if (k in data) { delete data[k]; persist(); notify(); return true; }
        return false;
    }

    // Todos os valores de um squad num mês: { metrica: valor }.
    function getForSquad(mes, squadId) {
        const out = {};
        const prefix = `${String(mes)}||${String(squadId)}||`;
        Object.keys(data).forEach((k) => {
            if (k.indexOf(prefix) === 0) out[k.slice(prefix.length)] = data[k];
        });
        return out;
    }

    // Lista plana de todos os registros: [{ mes, squadId, metrica, valor }].
    function list() {
        return Object.keys(data).map((k) => {
            const [mes, squadId, metrica] = k.split('||');
            return { mes, squadId, metrica, valor: data[k] };
        });
    }

    function clear() { data = {}; persist(); notify(); }
    function reload() { load(); notify(); return list(); }
    function _refreshStore() { store = resolveStore(); load(); }

    return {
        STORAGE_KEY,
        set, get, remove, getForSquad, list,
        subscribe, clear, reload, _refreshStore
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ManualMetricsStore;
}
if (typeof globalThis !== 'undefined') {
    globalThis.ManualMetricsStore = ManualMetricsStore;
}
