/**
 * PEP - Plataforma de Engenharia de Performance
 * Estado da aplicação e navegação (`app-state.js`)
 *
 * Task 7.1 (spec score-performance-executivo): implementa o `AppState`
 * descrito no design ("Components and Interfaces" → "5. Estado da aplicação e
 * navegação — app-state.js") e nos Requirements 2.1, 2.2 e 2.5:
 *
 *   - getSelectedMonth() -> number(1..12): lê o Mês Selecionado do
 *     localStorage; quando ausente/inválido usa o default = ÚLTIMO MÊS COM
 *     DADOS (via PEPData.getMonthsWithData()/getLatestMonth() quando
 *     disponível). (Requirement 2.1)
 *   - setSelectedMonth(m): valida/normaliza, persiste em localStorage e dispara
 *     os subscribers para re-render de todas as visões. (Requirement 2.2)
 *   - getActiveView() -> 'geral'|'jornadas'|'metricas'|'evolucao'|'metas'|'legado'.
 *   - setActiveView(v): troca a visão SEM alterar o Mês Selecionado, mantendo o
 *     mês ao navegar entre visões. (Requirement 2.5)
 *   - subscribe(fn): registra um callback chamado em mudanças de mês/visão/PI;
 *     retorna uma função para cancelar a inscrição (unsubscribe).
 *
 * Task 15 (spec score-performance-executivo): estende o `AppState` com o
 * Seletor de PI independente dos painéis de Retrospectiva de PI (Requirements
 * 18.7 e 19.3):
 *
 *   - getSelectedPI() -> string: lê o PI Selecionado do localStorage em uma
 *     CHAVE PRÓPRIA (distinta da chave do Mês); default = PI vigente (último de
 *     PEPRetroData.getPIs(), via getLatestPI quando disponível).
 *   - setSelectedPI(pi): persiste em sua chave própria e notifica os
 *     subscribers com { type:'pi' }, SEM alterar o Mês Selecionado (e vice-
 *     versa), garantindo independência total (Property 25 / Requirement 19.3).
 *   - RETRO_VIEWS: lista das visões dos painéis de Retrospectiva de PI.
 *
 * NOTA sobre persistência (importante para testabilidade): `localStorage` pode
 * não existir em Node (Vitest) nem em alguns contextos de `file://`. Usamos uma
 * GUARDA DEFENSIVA que detecta um Storage utilizável e, quando ele não existe
 * ou lança exceção (ex.: modo privado), cai para um STORE EM MEMÓRIA. Assim o
 * módulo carrega e é testável sem browser, mantendo o app estático intacto.
 *
 * Dependências (opcionais, resolvidas sob guarda de ambiente): PEPData, para
 * derivar o default do Mês Selecionado como o último mês com dados. Quando o
 * PEPData não está disponível, o default cai para 1 (Jan).
 */

const AppState = (() => {

    // -------------------------------------------------------------------------
    // Constantes
    // -------------------------------------------------------------------------
    const STORAGE_KEY_MONTH = 'pep.selectedMonth';
    const DEFAULT_MONTH_FALLBACK = 1; // Jan, usado só se não houver dados/PEPData.

    const VIEWS = ['geral', 'jornadas', 'metricas', 'evolucaoMetas', 'legado'];
    const DEFAULT_VIEW = 'geral';

    // -------------------------------------------------------------------------
    // Seletor de PI (Retrospectiva de PI) — Requirements 18.7, 19.3
    //
    // O PI Selecionado é persistido em uma chave PRÓPRIA no localStorage,
    // DISTINTA da chave do Mês Selecionado. Isso garante a independência total
    // entre os dois seletores: mudar o PI não altera o Mês, e vice-versa
    // (Property 25 / Requirement 19.3).
    //
    // `RETRO_VIEWS` lista as visões dos painéis de Retrospectiva de PI; é
    // mantida separada de `VIEWS` (as visões executivas do Score) porque o PI
    // direciona os painéis de Retro, enquanto o Mês direciona as visões de Score.
    // -------------------------------------------------------------------------
    const STORAGE_KEY_PI = 'pep.selectedPI';

    // Perfil ativo (simulação de UX client-side — Fase 3, Req 25). Chave própria,
    // independente de Mês e PI. Default = 'admin'.
    const STORAGE_KEY_PROFILE = 'pep.profile';
    const VALID_PROFILES = ['admin', 'dm', 'tm', 'gpm', 'tl'];
    const DEFAULT_PROFILE = 'admin';

    const RETRO_VIEWS = ['retroPontos', 'retroProdutividade', 'retroEpicos', 'retroCycleTime',
        'retroLeadTime', 'retroDebito', 'retroSustentacao', 'retroExtras'];

    // -------------------------------------------------------------------------
    // Resolução de dependências com guarda de ambiente (PEPData é opcional).
    // -------------------------------------------------------------------------
    let _PEPData = (typeof globalThis !== 'undefined' && globalThis.PEPData) ? globalThis.PEPData : undefined;
    if (!_PEPData && typeof require === 'function') {
        try { _PEPData = require('./data.js'); } catch (e) { /* opcional */ }
    }
    function getData() {
        return _PEPData || (typeof globalThis !== 'undefined' ? globalThis.PEPData : undefined);
    }

    // PEPRetroData é opcional: fornece a lista de PIs (getPIs/getLatestPI) para
    // derivar o default do PI Selecionado = PI vigente (último de getPIs()).
    let _PEPRetroData = (typeof globalThis !== 'undefined' && globalThis.PEPRetroData) ? globalThis.PEPRetroData : undefined;
    if (!_PEPRetroData && typeof require === 'function') {
        try { _PEPRetroData = require('./retro-data.js'); } catch (e) { /* opcional */ }
    }
    function getRetroData() {
        return _PEPRetroData || (typeof globalThis !== 'undefined' ? globalThis.PEPRetroData : undefined);
    }

    // -------------------------------------------------------------------------
    // Storage defensivo: usa localStorage quando utilizável; senão, memória.
    // Detectamos a usabilidade escrevendo/lendo/removendo uma chave de teste,
    // já que apenas checar `typeof localStorage` não cobre modo privado etc.
    // -------------------------------------------------------------------------
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
            const probe = '__pep_probe__';
            ls.setItem(probe, '1');
            ls.removeItem(probe);
            return ls;
        } catch (e) {
            // localStorage existe mas lançou (ex.: modo privado/cota) → memória.
            return createMemoryStore();
        }
    }

    const store = resolveStore();

    // -------------------------------------------------------------------------
    // Subscribers (callbacks de re-render em mudanças de mês/visão).
    // -------------------------------------------------------------------------
    const subscribers = [];

    function subscribe(fn) {
        if (typeof fn !== 'function') return () => {};
        subscribers.push(fn);
        // Retorna um unsubscribe para quem quiser cancelar.
        return function unsubscribe() {
            const idx = subscribers.indexOf(fn);
            if (idx >= 0) subscribers.splice(idx, 1);
        };
    }

    function notify(change) {
        // `change` descreve o que mudou:
        //   { type:'month', month, view }  → mudança do Mês Selecionado (Score)
        //   { type:'view',  month, view }  → troca de visão executiva (Score)
        //   { type:'pi',    pi,    view }  → mudança do PI Selecionado (Retro PI)
        // O campo `type` permite que subscribers DISTINGAM a mudança de PI da
        // mudança de mês (independência total — Requirement 19.3 / Property 25).
        // Falha de um subscriber não deve impedir os demais de serem notificados.
        for (const fn of subscribers.slice()) {
            try { fn(change); } catch (e) { /* isolado por subscriber */ }
        }
    }

    // -------------------------------------------------------------------------
    // Normalização/validação do Mês (1..12).
    // Aceita número ou string numérica; retorna undefined quando inválido.
    // -------------------------------------------------------------------------
    function normalizeMonth(m) {
        let n = m;
        if (typeof n === 'string') {
            const parsed = parseInt(n.trim(), 10);
            n = Number.isNaN(parsed) ? NaN : parsed;
        }
        if (typeof n !== 'number' || !Number.isInteger(n) || n < 1 || n > 12) {
            return undefined;
        }
        return n;
    }

    // Default do Mês Selecionado = último mês com dados (quando houver PEPData).
    function defaultMonth() {
        const data = getData();
        if (data) {
            try {
                if (typeof data.getLatestMonth === 'function') {
                    const latest = normalizeMonth(data.getLatestMonth());
                    if (latest !== undefined) return latest;
                }
                if (typeof data.getMonthsWithData === 'function') {
                    const months = data.getMonthsWithData() || [];
                    if (months.length) {
                        const latest = normalizeMonth(months[months.length - 1]);
                        if (latest !== undefined) return latest;
                    }
                }
            } catch (e) { /* cai no fallback */ }
        }
        return DEFAULT_MONTH_FALLBACK;
    }

    // -------------------------------------------------------------------------
    // Mês Selecionado (persistente) — Requirements 2.1, 2.2
    // -------------------------------------------------------------------------
    function getSelectedMonth() {
        let raw = null;
        try { raw = store.getItem(STORAGE_KEY_MONTH); } catch (e) { raw = null; }
        const stored = normalizeMonth(raw);
        if (stored !== undefined) return stored;
        // Sem valor persistido válido → default = último mês com dados.
        return defaultMonth();
    }

    function setSelectedMonth(m) {
        const month = normalizeMonth(m);
        if (month === undefined) {
            throw new Error('AppState.setSelectedMonth: mês inválido (esperado 1..12), recebido: ' + m);
        }
        try { store.setItem(STORAGE_KEY_MONTH, String(month)); } catch (e) { /* ignora falha de escrita */ }
        notify({ type: 'month', month, view: activeView });
        return month;
    }

    // -------------------------------------------------------------------------
    // View ativa — Requirement 2.5 (trocar de visão NÃO altera o mês).
    // Mantida em memória (navegação é por sessão); o mês é o estado persistente.
    // -------------------------------------------------------------------------
    let activeView = DEFAULT_VIEW;

    function getActiveView() {
        return activeView;
    }

    function setActiveView(v) {
        if (!VIEWS.includes(v)) {
            throw new Error('AppState.setActiveView: visão inválida: ' + v +
                ' (esperado uma de: ' + VIEWS.join(', ') + ')');
        }
        activeView = v;
        // IMPORTANTE: NÃO alteramos o Mês Selecionado ao trocar de visão
        // (Requirement 2.5). Apenas notificamos para re-render da visão ativa,
        // aplicando o mesmo mês já selecionado.
        notify({ type: 'view', month: getSelectedMonth(), view: activeView });
        return activeView;
    }

    // -------------------------------------------------------------------------
    // PI Selecionado (persistente, chave própria) — Requirements 18.7, 19.3
    //
    // Independência total do Mês Selecionado: lê/grava em STORAGE_KEY_PI (chave
    // distinta de STORAGE_KEY_MONTH) e NUNCA toca o estado do mês. O default é o
    // PI vigente = último de PEPRetroData.getPIs() (via getLatestPI quando
    // disponível); sem PEPRetroData, cai para undefined.
    // -------------------------------------------------------------------------

    // Normaliza o PI: aceita string não-vazia; caso contrário, undefined.
    function normalizePI(pi) {
        if (typeof pi !== 'string') return undefined;
        const trimmed = pi.trim();
        return trimmed.length ? trimmed : undefined;
    }

    // Default do PI Selecionado = PI vigente (último de getPIs()).
    function defaultPI() {
        const retro = getRetroData();
        if (retro) {
            try {
                if (typeof retro.getLatestPI === 'function') {
                    const latest = normalizePI(retro.getLatestPI());
                    if (latest !== undefined) return latest;
                }
                if (typeof retro.getPIs === 'function') {
                    const pis = retro.getPIs() || [];
                    if (pis.length) {
                        const latest = normalizePI(pis[pis.length - 1]);
                        if (latest !== undefined) return latest;
                    }
                }
            } catch (e) { /* cai no fallback */ }
        }
        return undefined;
    }

    function getSelectedPI() {
        let raw = null;
        try { raw = store.getItem(STORAGE_KEY_PI); } catch (e) { raw = null; }
        const stored = normalizePI(raw);
        if (stored !== undefined) return stored;
        // Sem valor persistido válido → default = PI vigente.
        return defaultPI();
    }

    function setSelectedPI(pi) {
        const value = normalizePI(pi);
        if (value === undefined) {
            throw new Error('AppState.setSelectedPI: PI inválido (esperado string não-vazia), recebido: ' + pi);
        }
        // Persiste em chave PRÓPRIA; NÃO altera o Mês Selecionado (independência).
        try { store.setItem(STORAGE_KEY_PI, value); } catch (e) { /* ignora falha de escrita */ }
        // Notifica com type:'pi' para que subscribers distingam de mudança de mês.
        notify({ type: 'pi', pi: value, view: activeView });
        return value;
    }

    // -------------------------------------------------------------------------
    // Perfil ativo (persistente, chave própria) — Requirements 25.1, 25.2, 25.7
    //
    // Independente de Mês e PI: lê/grava em STORAGE_KEY_PROFILE e NUNCA altera o
    // mês nem o PI (Property 36). Default = 'admin'. Perfil inválido no storage
    // cai no default.
    // -------------------------------------------------------------------------
    function getProfile() {
        let raw = null;
        try { raw = store.getItem(STORAGE_KEY_PROFILE); } catch (e) { raw = null; }
        return VALID_PROFILES.includes(raw) ? raw : DEFAULT_PROFILE;
    }

    function setProfile(p) {
        if (!VALID_PROFILES.includes(p)) {
            throw new Error('AppState.setProfile: perfil inválido, recebido: ' + p);
        }
        // Persiste em chave própria; NÃO altera Mês nem PI (independência).
        try { store.setItem(STORAGE_KEY_PROFILE, p); } catch (e) { /* ignora falha de escrita */ }
        notify({ type: 'profile', profile: p, view: activeView });
        return p;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        VIEWS,
        RETRO_VIEWS,
        getSelectedMonth,
        setSelectedMonth,
        getActiveView,
        setActiveView,
        getSelectedPI,
        setSelectedPI,
        getProfile,
        setProfile,
        subscribe
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped and `AppState` stays global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AppState;
}

if (typeof globalThis !== 'undefined') {
    globalThis.AppState = AppState;
}
