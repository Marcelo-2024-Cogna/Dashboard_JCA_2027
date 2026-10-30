// Feature: score-performance-executivo
// Testes de integração/UI das Telas de Gestão (Task 33.2, Req 31.1–31.7, 31.9),
// com stub de DOM (sem jsdom). Cobrem:
//   - RBAC como fonte única: todas as telas de gestão restantes
//     (perfis, tribos-squads, integração) são exclusivas de Admin/DM. (31.9)
//     Obs.: Usuários, KPIs, OKRs e Comunicação foram removidos do produto.
//   - CRUD representativo (criar/editar/remover) via AdminStore. (31.2–31.3)
//   - Tribos/Squads consistentes com a identidade canônica + sinalização de
//     divergência. (31.3)
//   - Integração sem campos de credenciais sensíveis. (31.7)
//
// Reutiliza os módulos reais (admin-store, rbac, app-state, identity). O perfil
// ativo é controlado por AppState.setProfile.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// ---- localStorage em memória (store + app-state usam store defensivo) ----
function installStorage() {
    const mem = new Map();
    globalThis.localStorage = {
        getItem: (k) => (mem.has(k) ? mem.get(k) : null),
        setItem: (k, v) => mem.set(k, String(v)),
        removeItem: (k) => mem.delete(k),
        clear: () => mem.clear()
    };
}

// -----------------------------------------------------------------------------
// Stub de DOM mínimo: um "elemento" com innerHTML, value, checked, textContent,
// atributos e addEventListener/dispatch. O container resolve seletores por id
// (#x), por tipo de checkbox e expõe querySelectorAll para os checkboxes de
// permissão. A view re-renderiza substituindo o innerHTML do MESMO container e
// re-cabeando os listeners.
// -----------------------------------------------------------------------------
function makeElement(id) {
    const listeners = {};
    return {
        id,
        value: '',
        checked: false,
        hidden: false,
        textContent: '',
        className: '',
        _attrs: {},
        getAttribute(n) { return this._attrs[n] != null ? this._attrs[n] : null; },
        setAttribute(n, v) { this._attrs[n] = v; },
        addEventListener(t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
        dispatch(t, evt) { (listeners[t] || []).forEach((fn) => fn(evt || {})); },
        querySelectorAll() { return []; },
        _listeners: listeners
    };
}

// Container com cache de elementos por seletor (preserva estado entre
// querySelector no mesmo render). Suporta um conjunto fixo de ids usados pelas
// views de gestão + o grupo de checkboxes de permissão.
function makeContainer() {
    const ids = [
        'adm-form', 'adm-list', 'adm-id', 'adm-nome', 'adm-email', 'adm-perfil',
        'adm-tribo', 'adm-squad', 'adm-objetivo', 'adm-keyResult', 'adm-progresso',
        'adm-nome2', 'adm-canal', 'adm-destino', 'adm-ativo', 'adm-sistema',
        'adm-projetoJira', 'adm-jornada', 'adm-meta', 'adm-cancel', 'adm-form-msg',
        'adm-permissoes', 'adm-io-out', 'adm-export-csv', 'adm-export-json', 'adm-import'
    ];
    const container = {
        innerHTML: '',
        _cache: {},
        querySelector(sel) {
            if (sel && sel.charAt(0) === '#') {
                const id = sel.slice(1);
                if (ids.indexOf(id) === -1) return null;
                const idAttr = `id="${id}"`;
                if (!this.innerHTML.includes(idAttr)) return null;
                if (!this._cache[sel]) {
                    const el = makeElement(id);
                    // O grupo de permissões expõe os checkboxes (data-perm).
                    if (id === 'adm-permissoes') {
                        el.querySelectorAll = () => permCheckboxes(this);
                    }
                    this._cache[sel] = el;
                }
                return this._cache[sel];
            }
            return null;
        }
    };
    return container;
}

// Extrai os ids das telas marcadas como data-perm no HTML e cria "checkboxes"
// controláveis, memorizados no container para persistir o estado checked.
function permCheckboxes(container) {
    if (!container._perm) container._perm = {};
    const html = container.innerHTML;
    const re = /data-perm="([^"]+)"/g;
    const out = [];
    let m;
    while ((m = re.exec(html)) !== null) {
        const value = m[1];
        if (!container._perm[value]) container._perm[value] = { type: 'checkbox', value, checked: false };
        out.push(container._perm[value]);
    }
    return out;
}

const AppState = require('../js/app-state.js');
const Rbac = require('../js/rbac.js');
const PEPIdentity = require('../js/identity.js');

function loadStore() {
    const p = require.resolve('../js/admin-store.js');
    delete require.cache[p];
    return require('../js/admin-store.js');
}
function loadView(file) {
    const p = require.resolve(`../js/views/${file}`);
    delete require.cache[p];
    return require(`../js/views/${file}`);
}

// Preenche campos do stub e dispara submit.
function fill(container, map) {
    Object.keys(map).forEach((sel) => {
        const el = container.querySelector(sel);
        if (el) el.value = map[sel];
    });
}
function submit(container) {
    const form = container.querySelector('#adm-form');
    form.dispatch('submit', { preventDefault() {} });
}

const SQUAD = PEPIdentity.squadsOf(PEPIdentity.jornadas()[0])[0];
const JORNADA = PEPIdentity.jornadaOf(SQUAD);

describe('Telas de Gestão — guarda de acesso pela Matriz de Permissões (31.9)', () => {
    beforeEach(() => { installStorage(); loadStore().clear(); });

    it('Perfis: visível só a Admin e DM; negado a TM, GPM e TL', () => {
        const view = loadView('admin-perfis.js');
        ['admin', 'dm'].forEach((p) => {
            AppState.setProfile(p);
            const c = makeContainer();
            view.render(c, {});
            expect(Rbac.can(p, 'gestPerfis')).toBe(true);
            expect(c.innerHTML).toContain('Gestão de Perfis');
            expect(c.innerHTML).not.toContain('adm-denied');
        });
        ['tm', 'gpm', 'tl'].forEach((p) => {
            AppState.setProfile(p);
            const c = makeContainer();
            view.render(c, {});
            expect(Rbac.can(p, 'gestPerfis')).toBe(false);
            expect(c.innerHTML).toContain('adm-denied');
        });
    });

    it('Tribos/Squads, Integração: só Admin e DM', () => {
        const specs = [
            ['admin-tribos-squads.js', 'gestTribosSquads'],
            ['admin-integracao.js', 'cfgIntegracao']
        ];
        specs.forEach(([file, screen]) => {
            const view = loadView(file);
            ['admin', 'dm'].forEach((p) => {
                AppState.setProfile(p);
                const c = makeContainer();
                view.render(c, {});
                expect(Rbac.can(p, screen)).toBe(true);
                expect(c.innerHTML).not.toContain('adm-denied');
            });
            ['tm', 'gpm', 'tl'].forEach((p) => {
                AppState.setProfile(p);
                const c = makeContainer();
                view.render(c, {});
                expect(Rbac.can(p, screen)).toBe(false);
                expect(c.innerHTML).toContain('adm-denied');
            });
        });
    });
});

describe('Gestão de Perfis — associação de permissões (31.2)', () => {
    let Store, view;
    beforeEach(() => {
        installStorage();
        Store = loadStore(); Store.clear();
        AppState.setProfile('admin');
        view = loadView('admin-perfis.js');
    });

    it('cria um perfil com permissões marcadas', () => {
        const c = makeContainer();
        view.render(c, {});
        fill(c, { '#adm-nome': 'Analista' });
        // Marca duas permissões conhecidas.
        const boxes = c.querySelector('#adm-permissoes').querySelectorAll();
        const byId = {};
        boxes.forEach((b) => { byId[b.value] = b; });
        byId['execGeral'].checked = true;
        byId['apresentacao'].checked = true;
        submit(c);
        const itens = Store.list('perfis');
        expect(itens).toHaveLength(1);
        expect(itens[0].nome).toBe('Analista');
        expect(itens[0].permissoes).toEqual(expect.arrayContaining(['execGeral', 'apresentacao']));
    });
});

describe('Gestão de Tribos/Squads — identidade canônica (31.3)', () => {
    let Store, view;
    beforeEach(() => {
        installStorage();
        Store = loadStore(); Store.clear();
        AppState.setProfile('admin');
        view = loadView('admin-tribos-squads.js');
    });

    it('oferece as jornadas canônicas no datalist e cria um registro', () => {
        const c = makeContainer();
        view.render(c, {});
        // As jornadas canônicas aparecem como opções do datalist.
        PEPIdentity.jornadas().forEach((j) => expect(c.innerHTML).toContain(`value="${j}"`));
        fill(c, { '#adm-tribo': JORNADA, '#adm-squad': SQUAD });
        submit(c);
        const itens = Store.list('tribosSquads');
        expect(itens).toHaveLength(1);
        expect(itens[0].squad).toBe(SQUAD);
    });

    it('sinaliza divergência quando o squad não é canônico', () => {
        Store.create('tribosSquads', { tribo: 'Jornada Fantasma', squad: 'Squad Inexistente XYZ' });
        const c = makeContainer();
        view.render(c, {});
        expect(c.innerHTML).toContain('Divergências com a identidade canônica');
        expect(c.innerHTML).toContain('Squad Inexistente XYZ');
    });
});

describe('Configuração de Integração — sem credenciais sensíveis (31.7)', () => {
    let Store, view;
    beforeEach(() => {
        installStorage();
        Store = loadStore(); Store.clear();
        AppState.setProfile('admin');
        view = loadView('admin-integracao.js');
    });

    it('não possui campos de token/senha/apiKey na UI', () => {
        const c = makeContainer();
        view.render(c, {});
        const html = c.innerHTML.toLowerCase();
        ['token', 'senha', 'password', 'apikey', 'secret', 'credencial'].forEach((k) => {
            expect(html).not.toContain(`id="adm-${k}"`);
            expect(html).not.toContain(`name="${k}"`);
        });
        // Exibe a nota de que credenciais não são armazenadas.
        expect(c.innerHTML).toContain('não são armazenadas');
    });

    it('cria um mapeamento Jira → Jornada/Squad', () => {
        const c = makeContainer();
        view.render(c, {});
        fill(c, { '#adm-sistema': 'Jira', '#adm-projetoJira': 'ACAD', '#adm-jornada': JORNADA, '#adm-squad': SQUAD });
        submit(c);
        const itens = Store.list('integracao');
        expect(itens).toHaveLength(1);
        expect(itens[0].projetoJira).toBe('ACAD');
        // Mesmo que um token fosse injetado, o store o removeria (defesa em profundidade).
        expect(Object.keys(itens[0])).not.toContain('token');
    });
});

describe('Telas de Gestão — registro em window.PEPViews e robustez', () => {
    const files = [
        ['admin-perfis.js', 'gestPerfis'],
        ['admin-tribos-squads.js', 'gestTribosSquads'],
        ['admin-integracao.js', 'cfgIntegracao']
    ];

    beforeEach(() => { installStorage(); loadStore().clear(); AppState.setProfile('admin'); });

    it('cada view registra seu renderer e é defensiva a container ausente', () => {
        files.forEach(([file, key]) => {
            const prevWindow = globalThis.window;
            globalThis.window = {};
            try {
                loadView(file);
                expect(globalThis.window.PEPViews).toBeTruthy();
                expect(typeof globalThis.window.PEPViews[key].render).toBe('function');
            } finally {
                if (prevWindow === undefined) delete globalThis.window; else globalThis.window = prevWindow;
            }
            const view = loadView(file);
            expect(() => view.render(null, {})).not.toThrow();
        });
    });
});
