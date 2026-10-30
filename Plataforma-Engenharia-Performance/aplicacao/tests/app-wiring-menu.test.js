// Feature: score-performance-executivo
// Testes de integração/UI do WIRING do MENU POR PERFIL em `app.js` (Task 29).
//
// Foco: o cabeamento do Seletor de Perfil + Menu por seções + guarda de acesso
// — NÃO re-testamos a matriz do Rbac (coberta por tests/rbac.test.js) nem o
// conteúdo das telas (tarefas 30–33). Verificamos (Requirements 25.3–25.6,
// 26.1–26.5):
//   1. O menu é renderizado em DUAS seções a partir de Rbac.menuFor(perfil) e
//      mostra SOMENTE as telas permitidas ao perfil atual (nega por omissão).  (25.3, 26.*)
//   2. O Seletor de Perfil lista os 5 perfis com os rótulos de Rbac.PROFILE_LABEL
//      e reflete o perfil atual; trocar o perfil re-renderiza menu E tela.      (25.1, 25.2, 25.7)
//   3. A tela inicial do perfil (Rbac.homeView) é aplicada no bootstrap.        (25.4)
//   4. GUARDA DE ACESSO: tentar abrir uma tela negada redireciona para a home
//      e sinaliza o acesso negado (Property 35).                               (25.5)
//   5. O banner de "simulação de visibilidade (sem autenticação)" está presente.(25.6)
//
// Mesmo espírito de tests/app-wiring.test.js / app-wiring-retro.test.js: STUB
// mínimo de `document`/elementos (sem jsdom) e os módulos REAIS `Rbac` e
// `AppState` instalados como GLOBAIS antes de carregar app.js (que os resolve
// em runtime). Usar o Rbac real garante que o menu/guarda reflitam a matriz
// de permissões de verdade, não um stub.

import { describe, it, expect, afterEach, vi } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const PEPData = require('../js/data.js');
const Rbac = require('../js/rbac.js');

const MONTH = PEPData.getLatestMonth();

// -----------------------------------------------------------------------------
// Stub mínimo de elemento DOM (cobre o que app.js usa). querySelectorAll
// resolve também as classes do menu (.menu-item), além de .view-tab/.retro-subtab.
// -----------------------------------------------------------------------------
function makeEl(tag = 'div', id = '') {
    const el = {
        tagName: String(tag).toUpperCase(),
        id,
        textContent: '',
        hidden: false,
        tabIndex: 0,
        type: '',
        value: '',
        disabled: false,
        dataset: {},
        _children: [],
        _innerHTML: '',
        _attrs: {},
        _listeners: {},
        _classSet: new Set(),
        // `innerHTML` espelha o DOM real: atribuir uma string LIMPA os filhos
        // (o browser substitui o conteúdo). Isso é essencial para re-renders
        // (buildMenu/onProfileChange fazem `nav.innerHTML = ''` antes de
        // reconstruir), evitando acúmulo de filhos entre renders.
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) { this._innerHTML = String(v); this._children = []; },
        get className() { return [...this._classSet].join(' '); },
        set className(v) { this._classSet = new Set(String(v).split(/\s+/).filter(Boolean)); },
        classList: {
            add(c) { el._classSet.add(c); },
            remove(c) { el._classSet.delete(c); },
            contains(c) { return el._classSet.has(c); },
            toggle(c, on) {
                const want = on === undefined ? !el._classSet.has(c) : !!on;
                if (want) el._classSet.add(c); else el._classSet.delete(c);
                return want;
            }
        },
        setAttribute(k, v) { this._attrs[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
        addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); },
        dispatch(type, evt) { (this._listeners[type] || []).forEach((fn) => fn(evt)); },
        appendChild(child) { this._children.push(child); return child; },
        // Resolve recursivamente os descendentes por classe (o menu tem
        // seções > listas > itens, então uma busca só nos filhos diretos não
        // basta como no stub de abas).
        querySelectorAll(sel) {
            const cls = sel.replace(/^\./, '');
            const out = [];
            const walk = (node) => {
                (node._children || []).forEach((c) => {
                    if (c._classSet && c._classSet.has(cls)) out.push(c);
                    walk(c);
                });
            };
            walk(this);
            return out;
        },
        querySelector(sel) {
            const all = this.querySelectorAll(sel);
            return all.length ? all[0] : null;
        }
    };
    return el;
}

// -----------------------------------------------------------------------------
// Stub de `document`: inclui os elementos do menu por perfil (Task 29) —
// profile-selector, profile-menu, profile-sim-banner, menu-access-denied — além
// dos elementos de Score/Retro e dos containers de todas as telas do Rbac.
// -----------------------------------------------------------------------------
function makeDocument() {
    const byId = new Map();
    const doc = {
        _byId: byId,
        _listeners: {},
        getElementById(id) { return byId.get(id) || null; },
        createElement(tag) { return makeEl(tag); },
        addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
    };
    const ensure = (id, tag = 'div') => { const el = makeEl(tag, id); byId.set(id, el); return el; };
    // Score.
    ensure('month-selector', 'select');
    ensure('month-selector-wrap', 'div');
    ensure('view-tabs', 'nav');
    ensure('sprint-info', 'span');
    ensure('bands-legend', 'div');
    // Retro.
    ensure('retro-toolbar', 'div');
    ensure('retro-subtabs', 'nav');
    ensure('pi-selector', 'select');
    // Menu por perfil (Task 29).
    ensure('profile-selector', 'select');
    ensure('profile-menu', 'nav');
    const banner = ensure('profile-sim-banner', 'div');
    banner.textContent = 'Controle por Perfil é uma simulação de visibilidade (sem autenticação de servidor).';
    const denied = ensure('menu-access-denied', 'div');
    denied.hidden = true;
    // A sinalização de acesso negado tem um span de texto interno.
    const deniedText = makeEl('span');
    deniedText.classList.add('menu-access-denied-text');
    denied.appendChild(deniedText);
    // Containers das 6 visões de Score.
    ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado']
        .forEach((v) => ensure(`view-${v}`, 'section'));
    // Containers dos 8 painéis de Retro.
    RETRO_VIEWS.forEach((v) => ensure(`view-${v}`, 'section'));
    // Containers das telas do Rbac (padrão view-<screenId>), incluindo os
    // placeholders das telas das tarefas 30–33.
    Object.keys(Rbac.SCREENS).forEach((sid) => ensure(`view-${sid}`, 'section'));
    return doc;
}

// NOTA sobre o AppState: o módulo real é um SINGLETON cujo array de subscribers
// é de nível de módulo e NÃO é limpo entre `require`s (o cache do Node o mantém
// vivo). Reaproveitá-lo entre cargas faria os subscribers de testes anteriores
// dispararem em cada setProfile, acumulando re-renders no menu. Por isso usamos
// um STUB de AppState fresco por `loadApp` — fiel ao contrato usado por app.js
// (VIEWS/RETRO_VIEWS, get/set de mês/view/PI/perfil, subscribe→notify) — do
// mesmo modo que tests/app-wiring.test.js e app-wiring-retro.test.js. O Rbac,
// esse sim, é o módulo REAL (fonte única das permissões).
const RETRO_VIEWS = require('../js/app-state.js').RETRO_VIEWS;

function makeAppState(profile) {
    let month = 7;
    let view = 'geral';
    let pi = 'PI 3/26';
    let prof = profile || 'admin';
    const subs = [];
    const notify = (change) => subs.slice().forEach((fn) => { try { fn(change); } catch (e) {} });
    return {
        VIEWS: ['geral', 'jornadas', 'metricas', 'evolucao', 'metas', 'legado'],
        RETRO_VIEWS: [...RETRO_VIEWS],
        getSelectedMonth() { return month; },
        setSelectedMonth(m) { month = m; notify({ type: 'month', month, view }); return m; },
        getActiveView() { return view; },
        setActiveView(v) { view = v; notify({ type: 'view', month, view }); return v; },
        getSelectedPI() { return pi; },
        setSelectedPI(p) { pi = p; notify({ type: 'pi', pi, view }); return p; },
        getProfile() { return prof; },
        setProfile(p) { prof = p; notify({ type: 'profile', profile: p, view }); return p; },
        subscribe(fn) { subs.push(fn); return () => {}; }
    };
}

// -----------------------------------------------------------------------------
// Stub de PEPViews: registra renderizadores para as views de Score (grava as
// chamadas). As telas placeholder do Rbac não têm renderer — app.js usa o
// placeholder "em construção" de forma defensiva.
// -----------------------------------------------------------------------------
function makePEPViews() {
    const calls = {};
    const views = {};
    for (const v of ['geral', 'jornadas', 'metricas', 'evolucao', 'metas']) {
        calls[v] = [];
        views[v] = {
            render(container, opts) {
                calls[v].push({ container, month: opts && opts.month });
                if (container) container.innerHTML = `<div class="view-${v}-ok">ok</div>`;
            }
        };
    }
    return { views, calls };
}

// -----------------------------------------------------------------------------
// Carrega app.js limpo, instalando os globais que ele resolve em runtime. Zera
// o perfil persistido (volta ao default 'admin') antes de cada carga para que
// os testes sejam determinísticos.
// -----------------------------------------------------------------------------
function loadApp({ profile } = {}) {
    const doc = makeDocument();
    const { views, calls } = makePEPViews();
    const appState = makeAppState(profile || 'admin');

    globalThis.document = doc;
    globalThis.window = globalThis;
    globalThis.PEPData = PEPData;
    globalThis.AppState = appState;
    globalThis.Rbac = Rbac;
    globalThis.PEPViews = views;
    const dashboardCalls = { init: 0, setMonth: [] };
    globalThis.Dashboard = {
        init() { dashboardCalls.init += 1; },
        setMonth(m) { dashboardCalls.setMonth.push(m); }
    };
    globalThis.ScoringEngine = require('../js/scoring-engine.js');

    const appPath = require.resolve('../js/app.js');
    delete require.cache[appPath];
    const PEPApp = require('../js/app.js');

    return { PEPApp, doc, appState, views, calls, dashboardCalls };
}

function cleanupGlobals() {
    delete globalThis.document;
    delete globalThis.PEPData;
    delete globalThis.AppState;
    delete globalThis.Rbac;
    delete globalThis.PEPViews;
    delete globalThis.Dashboard;
    delete globalThis.ScoringEngine;
    delete globalThis.PEPApp;
}

// Rótulos (ids) das telas visíveis no menu renderizado, agregando as duas seções.
function menuScreenIds(doc) {
    const nav = doc.getElementById('profile-menu');
    return nav.querySelectorAll('.menu-item').map((btn) => btn.dataset.screen);
}

describe('Wiring do menu por perfil em app.js (Task 29)', () => {
    let ctx;

    afterEach(() => {
        vi.restoreAllMocks();
        cleanupGlobals();
    });

    it('expõe a API de wiring do menu sob guarda de ambiente', () => {
        ctx = loadApp();
        const { PEPApp } = ctx;
        for (const fn of ['buildProfileSelector', 'buildMenu', 'syncMenuSelection',
            'selectScreen', 'applyHomeForProfile', 'onProfileChange', 'profileNavEnabled']) {
            expect(typeof PEPApp[fn]).toBe('function');
        }
        expect(PEPApp.profileNavEnabled()).toBe(true);
    });

    it('o banner de simulação de visibilidade (sem autenticação) está presente (25.6)', () => {
        ctx = loadApp();
        ctx.PEPApp.init();
        const banner = ctx.doc.getElementById('profile-sim-banner');
        expect(banner).toBeTruthy();
        expect(banner.hidden).toBe(false);
        expect(banner.textContent.toLowerCase()).toContain('simulação de visibilidade');
        expect(banner.textContent.toLowerCase()).toContain('sem autenticação');
    });

    it('o Seletor de Perfil lista os 5 perfis com rótulos e reflete o perfil atual (25.1, 25.2)', () => {
        ctx = loadApp({ profile: 'tm' });
        ctx.PEPApp.init();
        const select = ctx.doc.getElementById('profile-selector');
        expect(select._children.length).toBe(Rbac.PROFILES.length); // 5 perfis
        Rbac.PROFILES.forEach((p, i) => {
            const opt = select._children[i];
            expect(opt.value).toBe(p);
            expect(opt.textContent).toBe(Rbac.PROFILE_LABEL[p]);
        });
        expect(select.value).toBe('tm'); // reflete o perfil atual
    });

    it('o menu é renderizado em DUAS seções com os rótulos corretos (26.1)', () => {
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        const nav = ctx.doc.getElementById('profile-menu');
        const sections = nav.querySelectorAll('.menu-section');
        // Admin tem telas nas duas seções (dashboards + gestão).
        expect(sections.length).toBe(2);
        // Os títulos agora são cabeçalhos colapsáveis (button) com o rótulo em
        // um <span> e um contador; verificamos pelo conteúdo (textContent OU
        // innerHTML, para funcionar com o stub de DOM e com o DOM real).
        const titles = nav.querySelectorAll('.menu-section-title')
            .map((t) => `${t.textContent || ''} ${t.innerHTML || ''}`);
        const joined = titles.join(' | ');
        expect(joined).toContain('Dashboards e Configurações');
        expect(joined).toContain('Gestão e Configuração');
    });

    it('o menu mostra SOMENTE o permitido ao perfil — admin vê todas as telas (25.3, 26.*)', () => {
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        const shown = menuScreenIds(ctx.doc).sort();
        const allowed = Rbac.allowedScreens('admin').sort();
        expect(shown).toEqual(allowed);
    });

    it('o menu mostra SOMENTE o permitido ao perfil — gpm vê um subconjunto (25.3, 26.*)', () => {
        ctx = loadApp({ profile: 'gpm' });
        ctx.PEPApp.init();
        const shown = menuScreenIds(ctx.doc).sort();
        const allowed = Rbac.allowedScreens('gpm').sort();
        expect(shown).toEqual(allowed);
        // Sanidade: gpm NÃO vê telas de gestão nem produtividade/exceção.
        ['gestTribosSquads', 'produtividade', 'excecao', 'gestPerfis'].forEach((sid) => {
            expect(shown).not.toContain(sid);
        });
    });

    it('aplica a tela inicial do perfil no bootstrap — Rbac.homeView (25.4)', () => {
        // TL entra no executivo por squad (home = execSquad, uma tela placeholder).
        ctx = loadApp({ profile: 'tl' });
        ctx.PEPApp.init();
        expect(ctx.PEPApp.getActiveScreen()).toBe(Rbac.homeView('tl'));
        expect(ctx.PEPApp.getActiveScreen()).toBe('execSquad');

        // Admin entra no executivo de jornadas (home = execGeral → view de Score 'geral').
        cleanupGlobals();
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        expect(ctx.PEPApp.getActiveScreen()).toBe('execGeral');
        expect(ctx.doc.getElementById('view-geral').hidden).toBe(false);
    });

    it('trocar de perfil re-renderiza o menu E a tela ativa (25.7)', () => {
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        // Admin: menu completo e home execGeral ativa.
        expect(menuScreenIds(ctx.doc).sort()).toEqual(Rbac.allowedScreens('admin').sort());

        // Troca para gpm pelo Seletor de Perfil (evento change → AppState.setProfile).
        const select = ctx.doc.getElementById('profile-selector');
        select.value = 'gpm';
        select.dispatch('change', { target: select });

        // Menu re-renderizado só com o permitido ao gpm.
        expect(menuScreenIds(ctx.doc).sort()).toEqual(Rbac.allowedScreens('gpm').sort());
        // A tela ativa é uma tela permitida ao gpm (home, já que admin's screen
        // execGeral também é permitida ao gpm, deve ser mantida).
        expect(Rbac.can('gpm', ctx.PEPApp.getActiveScreen())).toBe(true);
        // O seletor reflete o novo perfil.
        expect(select.value).toBe('gpm');
    });

    it('trocar de perfil para um que NÃO permite a tela atual redireciona para a home (25.5, 25.7)', () => {
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        // Admin abre uma tela de GESTÃO que o gpm não tem (gestPerfis).
        ctx.PEPApp.selectScreen('gestPerfis');
        expect(ctx.PEPApp.getActiveScreen()).toBe('gestPerfis');

        // Troca para gpm: gestPerfis é negada → redireciona para a home do gpm.
        const select = ctx.doc.getElementById('profile-selector');
        select.value = 'gpm';
        select.dispatch('change', { target: select });

        expect(ctx.PEPApp.getActiveScreen()).toBe(Rbac.homeView('gpm'));
        expect(Rbac.can('gpm', ctx.PEPApp.getActiveScreen())).toBe(true);
        // Sinalização de acesso negado ficou visível.
        const denied = ctx.doc.getElementById('menu-access-denied');
        expect(denied.hidden).toBe(false);
    });

    it('trocar de perfil NÃO altera o Mês nem o PI (Property 36 — independência)', () => {
        ctx = loadApp({ profile: 'admin' });
        ctx.PEPApp.init();
        const monthBefore = ctx.appState.getSelectedMonth();
        const piBefore = ctx.appState.getSelectedPI();

        const select = ctx.doc.getElementById('profile-selector');
        select.value = 'tl';
        select.dispatch('change', { target: select });

        expect(ctx.appState.getSelectedMonth()).toBe(monthBefore);
        expect(ctx.appState.getSelectedPI()).toBe(piBefore);
    });

    // =========================================================================
    // Property 35: "acesso a tela negada redireciona para a home"
    // =========================================================================
    it('Property 35: acessar tela negada redireciona para a home e sinaliza (25.5)', () => {
        // Feature: score-performance-executivo, Property 35: acesso a tela
        // negada redireciona para a home.
        fc.assert(
            fc.property(
                fc.constantFrom(...Rbac.PROFILES),
                fc.constantFrom(...Rbac.ALL_SCREENS),
                (profile, screenId) => {
                    const local = loadApp({ profile });
                    local.PEPApp.init();

                    const result = local.PEPApp.selectScreen(screenId);
                    const home = Rbac.homeView(profile);
                    const denied = local.doc.getElementById('menu-access-denied');

                    if (Rbac.can(profile, screenId)) {
                        // Permitida: ativa a própria tela, sem sinalizar negação.
                        expect(result).toBe(screenId);
                        expect(local.PEPApp.getActiveScreen()).toBe(screenId);
                        expect(denied.hidden).toBe(true);
                    } else {
                        // Negada: redireciona para a HOME e sinaliza o acesso negado.
                        expect(result).toBe(home);
                        expect(local.PEPApp.getActiveScreen()).toBe(home);
                        expect(Rbac.can(profile, local.PEPApp.getActiveScreen())).toBe(true);
                        expect(denied.hidden).toBe(false);
                    }

                    cleanupGlobals();
                    return true;
                }
            ),
            { numRuns: 200 }
        );
    });

    it('a tela ativa é SEMPRE uma tela permitida ao perfil, para todos os perfis', () => {
        Rbac.PROFILES.forEach((profile) => {
            const local = loadApp({ profile });
            local.PEPApp.init();
            expect(Rbac.can(profile, local.PEPApp.getActiveScreen())).toBe(true);
            cleanupGlobals();
        });
    });
});
