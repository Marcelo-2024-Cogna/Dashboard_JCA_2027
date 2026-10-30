/**
 * PEP - Plataforma de Engenharia de Performance
 * RBAC — Matriz de permissões por Perfil (`rbac.js`)
 *
 * Task 27 (spec score-performance-executivo). Requirements 27.1–27.8.
 *
 * FONTE ÚNICA de permissões (simulação de UX client-side; sem backend/segurança
 * real). O Menu (Req 26), a guarda de acesso de telas (Req 25) e as telas de
 * gestão (Req 31) consultam ESTE módulo — nunca duplicam regra. Padrão
 * "nega por omissão" (Req 27.7).
 *
 * Matriz validada com o usuário:
 *   - Admin ≡ DM (permissões idênticas, acesso total; rótulos distintos).
 *   - TM: dashboards (exceto executivo por squad) + produtividade +
 *     SOLICITAR exceção (sem aprovar).
 *   - GPM: executivo de jornadas, detalhado por jornada, detalhado por squad e
 *     apresentação; sem produtividade, sem gestão, sem exceção.
 *   - TL: executivo por squad (home), detalhado por jornada, detalhado por
 *     squad e produtividade.
 *   - Exceção: solicitar (TM/Admin/DM); aprovar (Admin/DM).
 *
 * IIFE com guarda de ambiente para teste em Node (Vitest) sem afetar o browser.
 */

const Rbac = (() => {
    'use strict';

    // Papéis canônicos (Req 25.1).
    const PROFILES = ['admin', 'dm', 'tm', 'gpm', 'tl'];

    const PROFILE_LABEL = {
        admin: 'Administrador do Sistema',
        dm: 'Delivery Manager (DM)',
        tm: 'Tech Manager (TM)',
        gpm: 'Group Product Manager (GPM)',
        tl: 'Tech Lead (TL)'
    };

    // Telas endereçáveis (ids estáveis). Agrupadas por seção de menu (Req 26).
    const SCREENS = {
        // Seção "Dashboards e Configurações"
        execGeral:      { label: 'Executivo de Jornadas', section: 'dashboards', icon: '📊' },
        execSquad:      { label: 'Executivo por Squad',   section: 'dashboards', icon: '🧩' },
        detJornada:     { label: 'Detalhado por Jornada', section: 'dashboards', icon: '🧭' },
        detSquad:       { label: 'Detalhado por Squad',   section: 'dashboards', icon: '🗂️' },
        apresentacao:   { label: 'Apresentação Executiva', section: 'dashboards', icon: '🖥️' },
        excecao:        { label: 'Exceções',              section: 'dashboards', icon: '⚠️' },
        // Seção "Gestão e Configuração"
        gestPerfis:     { label: 'Gestão de Perfis',      section: 'gestao', icon: '🔑' },
        gestTribosSquads: { label: 'Gestão de Tribos e Squads', section: 'gestao', icon: '🏷️' },
        cfgParametrizacao: { label: 'Parametrização de Métricas', section: 'gestao', icon: '🎛️' },
        cfgIntegracao:  { label: 'Configuração de Integração', section: 'gestao', icon: '🔌' }
    };

    // Conjunto completo de telas (acesso total — Admin/DM).
    const ALL_SCREENS = Object.keys(SCREENS);

    // =========================================================================
    // MATRIZ DE PERMISSÕES (fonte única — Req 27)
    // Admin e DM compartilham o mesmo conjunto (acesso total).
    // =========================================================================
    const TM_SCREENS = [
        'execGeral', 'detJornada', 'detSquad', 'apresentacao', 'excecao'
    ];
    const GPM_SCREENS = [
        'execGeral', 'detJornada', 'detSquad', 'apresentacao'
    ];
    const TL_SCREENS = [
        'execSquad', 'detJornada', 'detSquad'
    ];

    const PERMISSIONS = {
        admin: new Set(ALL_SCREENS),
        dm:    new Set(ALL_SCREENS),
        tm:    new Set(TM_SCREENS),
        gpm:   new Set(GPM_SCREENS),
        tl:    new Set(TL_SCREENS)
    };

    // Permissões de AÇÃO do fluxo de exceção (Req 27.6 / Req 30).
    const ACTIONS = {
        'excecao:solicitar': new Set(['admin', 'dm', 'tm']),
        'excecao:aprovar':   new Set(['admin', 'dm'])
    };

    // Tela inicial por perfil (Req 25.4). TL entra no executivo por squad.
    const HOME = {
        admin: 'execGeral',
        dm:    'execGeral',
        tm:    'execGeral',
        gpm:   'execGeral',
        tl:    'execSquad'
    };

    // =========================================================================
    // API pública
    // =========================================================================

    // Perfil válido? (fallback para 'admin' em entradas inválidas é tratado
    // pelo chamador; aqui apenas verificamos).
    function isProfile(p) { return PROFILES.includes(p); }

    // Pode acessar a tela? Nega por omissão (Req 27.7).
    function can(profile, screenId) {
        const set = PERMISSIONS[profile];
        return !!(set && set.has(screenId));
    }

    // Pode executar a ação? (ex.: 'excecao:aprovar'). Nega por omissão.
    function canAction(profile, actionId) {
        const set = ACTIONS[actionId];
        return !!(set && set.has(profile));
    }

    // Tela inicial do perfil (Req 25.4). Garante uma tela permitida (Property 34).
    function homeView(profile) {
        const h = HOME[profile];
        if (h && can(profile, h)) return h;
        // Fallback defensivo: primeira tela permitida, ou execGeral.
        const first = ALL_SCREENS.find(s => can(profile, s));
        return first || 'execGeral';
    }

    // Menu do perfil, agrupado por seção (Req 26.4). Reflete exatamente a
    // matriz (Property 33): só telas em que can() é verdadeiro.
    function menuFor(profile) {
        const dashboards = [];
        const gestao = [];
        ALL_SCREENS.forEach(screenId => {
            if (!can(profile, screenId)) return;
            const meta = SCREENS[screenId];
            const entry = { id: screenId, label: meta.label, icon: meta.icon };
            if (meta.section === 'gestao') gestao.push(entry); else dashboards.push(entry);
        });
        return { dashboards, gestao };
    }

    // Lista plana das telas permitidas (útil para testes/guards).
    function allowedScreens(profile) {
        return ALL_SCREENS.filter(s => can(profile, s));
    }

    return {
        PROFILES,
        PROFILE_LABEL,
        SCREENS,
        ALL_SCREENS,
        PERMISSIONS,
        ACTIONS,
        HOME,
        isProfile,
        can,
        canAction,
        homeView,
        menuFor,
        allowedScreens
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Rbac;
}
if (typeof globalThis !== 'undefined') {
    globalThis.Rbac = Rbac;
}
