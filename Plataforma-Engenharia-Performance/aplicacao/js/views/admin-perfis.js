/**
 * PEP - Plataforma de Engenharia de Performance
 * Gestão de Perfis (`js/views/admin-perfis.js`)
 *
 * Task 33.2 (spec score-performance-executivo). Requirement 31.2 e 31.9.
 *
 *   - Visualizar e ajustar a associação de permissões (telas) por Perfil sobre
 *     `AdminStore` (coleção 'perfis'): nome do Perfil + lista de telas
 *     permitidas (permissoes). Disponível apenas a quem tem acesso à tela
 *     `gestPerfis` na Matriz de Permissões (RBAC = fonte única: Admin/DM). (31.2)
 *   - Nega/oculta o CRUD quando o perfil não tem acesso (nega por omissão). (31.9)
 *   - Import/Export CSV/JSON reversível reutilizando o store (Property 37).
 *
 * Registro: `window.PEPViews.gestPerfis = { render }`. App estático, tema
 * escuro preservado. IIFE com guarda de ambiente para teste em Node (Vitest).
 */

(function () {
    'use strict';

    const SCREEN_ID = 'gestPerfis';
    const COLLECTION = 'perfis';

    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                AdminStore: '../admin-store.js',
                Rbac: '../rbac.js',
                AppState: '../app-state.js'
            }[name];
            if (path) { try { return require(path); } catch (e) { /* ignore */ } }
        }
        return undefined;
    }

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    function currentProfile() {
        const AppState = dep('AppState');
        try { return (AppState && AppState.getProfile) ? AppState.getProfile() : 'admin'; }
        catch (e) { return 'admin'; }
    }

    function canManage(profile) {
        const Rbac = dep('Rbac');
        return !!(Rbac && Rbac.can && Rbac.can(profile, SCREEN_ID));
    }

    // Telas endereçáveis (fonte: Rbac.SCREENS) para montar os checkboxes.
    function screenOptions() {
        const Rbac = dep('Rbac');
        const all = (Rbac && Array.isArray(Rbac.ALL_SCREENS)) ? Rbac.ALL_SCREENS : [];
        const meta = (Rbac && Rbac.SCREENS) ? Rbac.SCREENS : {};
        return all.map((id) => ({ id, label: (meta[id] && meta[id].label) || id }));
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;

        const Store = dep('AdminStore');
        if (!Store) { container.innerHTML = errorBlock('Módulo de gestão indisponível.'); return; }

        const profile = currentProfile();
        if (!canManage(profile)) { container.innerHTML = deniedBlock('Gestão de Perfis'); return; }

        const model = {
            screens: screenOptions(),
            itens: safe(() => Store.list(COLLECTION), [])
        };

        container.innerHTML = buildHTML(model);
        wire(container, Store, profile);
    }

    function safe(fn, fallback) {
        try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; }
    }

    // =========================================================================
    // HTML
    // =========================================================================
    function buildHTML(m) {
        return `
        <div class="adm">
            <div class="adm-head">
                <h2 class="adm-title">🛡️ Gestão de Perfis</h2>
                <p class="adm-sub">Visualizar e ajustar a associação de permissões (telas) por Perfil.</p>
            </div>

            <section class="adm-card">
                <h3 class="adm-card-title">Novo perfil / Edição</h3>
                <form id="adm-form" class="adm-form" novalidate>
                    <input type="hidden" id="adm-id" value="">
                    <div class="adm-field">
                        <label for="adm-nome">Nome do Perfil *</label>
                        <input id="adm-nome" class="adm-input" type="text" placeholder="Ex.: Analista de Qualidade">
                    </div>
                    <div class="adm-field adm-field-full">
                        <label>Permissões (telas)</label>
                        <div class="adm-checks" id="adm-permissoes">
                            ${m.screens.map((s) => `
                            <label class="adm-check">
                                <input type="checkbox" value="${esc(s.id)}" data-perm="${esc(s.id)}">
                                <span>${esc(s.label)}</span>
                            </label>`).join('')}
                        </div>
                    </div>
                    <div class="adm-form-actions">
                        <div class="adm-form-msg" id="adm-form-msg" role="alert" aria-live="polite"></div>
                        <button type="submit" class="adm-btn adm-btn-primary" id="adm-submit">Salvar</button>
                        <button type="button" class="adm-btn" id="adm-cancel" hidden>Cancelar edição</button>
                    </div>
                </form>
            </section>

            ${listCard(m)}
            ${ioCard()}
        </div>`;
    }

    function listCard(m) {
        const rows = (m.itens || []).map((it) => itemRow(it, m.screens)).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Perfis <span class="adm-count">(${(m.itens || []).length})</span></h3>
            ${(m.itens && m.itens.length)
                ? `<div class="adm-table" id="adm-list">
                       <div class="adm-tr adm-th"><span>Perfil</span><span>Permissões</span><span>Ações</span></div>
                       ${rows}
                   </div>`
                : `<p class="adm-empty">Nenhum perfil cadastrado.</p>`}
        </section>`;
    }

    function permLabels(screens, perms) {
        const map = {};
        (screens || []).forEach((s) => { map[s.id] = s.label; });
        return (perms || []).map((id) => map[id] || id).join(', ');
    }

    function itemRow(it, screens) {
        return `
        <div class="adm-tr" data-id="${esc(it.id)}">
            <span class="adm-td">${esc(it.nome)}</span>
            <span class="adm-td">${esc(permLabels(screens, it.permissoes))}</span>
            <span class="adm-td adm-row-actions">
                <button type="button" class="adm-btn adm-btn-edit" data-edit="${esc(it.id)}">Editar</button>
                <button type="button" class="adm-btn adm-btn-del" data-del="${esc(it.id)}">Remover</button>
            </span>
        </div>`;
    }

    function ioCard() {
        return `
        <section class="adm-card adm-io">
            <h3 class="adm-card-title">Importar / Exportar</h3>
            <div class="adm-io-actions">
                <button type="button" class="adm-btn" id="adm-export-csv">Exportar CSV</button>
                <button type="button" class="adm-btn" id="adm-export-json">Exportar JSON</button>
                <label class="adm-btn adm-upload" for="adm-import">Importar CSV/JSON
                    <input type="file" id="adm-import" accept=".csv,.json" hidden>
                </label>
            </div>
            <pre class="adm-io-out" id="adm-io-out" aria-live="polite"></pre>
        </section>`;
    }

    function deniedBlock(label) {
        return `
        <div class="adm">
            <div class="adm-denied" role="alert">
                <span aria-hidden="true">🚫</span>
                Seu perfil não tem permissão para acessar <strong>${esc(label)}</strong>.
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `<div class="adm"><div class="adm-empty"><span aria-hidden="true">⚠️</span> ${esc(msg)}</div></div>`;
    }

    // =========================================================================
    // WIRING
    // =========================================================================
    function val(container, sel) {
        const el = container.querySelector(sel);
        return el && el.value != null ? el.value : '';
    }

    function showMsg(container, msg, kind) {
        const box = container.querySelector('#adm-form-msg');
        if (box) { box.textContent = msg || ''; box.className = 'adm-form-msg' + (kind ? ` adm-msg-${kind}` : ''); }
    }

    function checkedPerms(container) {
        const wrap = container.querySelector('#adm-permissoes');
        if (!wrap || typeof wrap.querySelectorAll !== 'function') return [];
        const boxes = wrap.querySelectorAll('input[type="checkbox"]');
        const out = [];
        boxes.forEach((b) => { if (b.checked) out.push(b.value); });
        return out;
    }

    function wire(container, Store, profile) {
        if (!container || typeof container.querySelector !== 'function') return;

        const form = container.querySelector('#adm-form');
        if (form && form.addEventListener) {
            form.addEventListener('submit', (e) => {
                if (e && e.preventDefault) e.preventDefault();
                handleSubmit(container, Store, profile);
            });
        }
        const cancel = container.querySelector('#adm-cancel');
        if (cancel && cancel.addEventListener) cancel.addEventListener('click', () => render(container, {}));

        const list = container.querySelector('#adm-list');
        if (list && list.addEventListener) {
            list.addEventListener('click', (e) => {
                const t = e && e.target ? e.target : null;
                if (!t || typeof t.getAttribute !== 'function') return;
                const editId = t.getAttribute('data-edit');
                const delId = t.getAttribute('data-del');
                if (editId) startEdit(container, Store, editId);
                else if (delId) { if (canManage(profile)) { Store.remove(COLLECTION, delId); render(container, {}); } }
            });
        }
        wireIO(container, Store);
    }

    function startEdit(container, Store, id) {
        const it = safe(() => Store.get(COLLECTION, id), null);
        if (!it) return;
        const set = (sel, v) => { const el = container.querySelector(sel); if (el) el.value = v; };
        set('#adm-id', it.id);
        set('#adm-nome', it.nome);
        const wrap = container.querySelector('#adm-permissoes');
        if (wrap && typeof wrap.querySelectorAll === 'function') {
            const perms = new Set(it.permissoes || []);
            wrap.querySelectorAll('input[type="checkbox"]').forEach((b) => { b.checked = perms.has(b.value); });
        }
        const cancel = container.querySelector('#adm-cancel');
        if (cancel) cancel.hidden = false;
        showMsg(container, `Editando "${it.nome}".`, 'ok');
    }

    function handleSubmit(container, Store, profile) {
        if (!canManage(profile)) { showMsg(container, 'Seu perfil não permite esta operação.', 'error'); return; }
        const id = val(container, '#adm-id');
        const nome = val(container, '#adm-nome');
        if (!String(nome).trim()) { showMsg(container, 'Nome do Perfil é obrigatório.', 'error'); return; }
        const input = { nome, permissoes: checkedPerms(container) };
        try {
            if (id) Store.update(COLLECTION, id, input);
            else Store.create(COLLECTION, input);
            render(container, {});
            showMsg(container, id ? 'Perfil atualizado.' : 'Perfil criado.', 'ok');
        } catch (e) {
            showMsg(container, (e && e.message) ? e.message : 'Falha ao salvar.', 'error');
        }
    }

    function wireIO(container, Store) {
        const out = (msg, kind) => {
            const box = container.querySelector('#adm-io-out');
            if (box) { box.textContent = msg || ''; box.className = 'adm-io-out' + (kind ? ` adm-msg-${kind}` : ''); }
        };
        const expCsv = container.querySelector('#adm-export-csv');
        if (expCsv && expCsv.addEventListener) expCsv.addEventListener('click', () => downloadText(`${COLLECTION}.csv`, Store.toCSV(COLLECTION), out));
        const expJson = container.querySelector('#adm-export-json');
        if (expJson && expJson.addEventListener) expJson.addEventListener('click', () => downloadText(`${COLLECTION}.json`, Store.toJSON(COLLECTION), out));
        const imp = container.querySelector('#adm-import');
        if (imp && imp.addEventListener) {
            imp.addEventListener('change', (e) => {
                const file = e && e.target && e.target.files ? e.target.files[0] : null;
                if (!file) return;
                const reader = new FileReader();
                reader.onload = () => {
                    const text = String(reader.result || '');
                    const isJson = /\.json$/i.test(file.name) || text.trim().startsWith('{') || text.trim().startsWith('[');
                    const res = isJson ? Store.fromJSON(COLLECTION, text) : Store.fromCSV(COLLECTION, text);
                    out(`Importados: ${res.imported}. Rejeitados: ${res.rejected.length}.`, res.rejected.length ? 'error' : 'ok');
                    render(container, {});
                };
                reader.readAsText(file);
            });
        }
    }

    function downloadText(filename, text, out) {
        try {
            if (typeof document !== 'undefined' && typeof URL !== 'undefined' && URL.createObjectURL) {
                const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = filename; a.click();
                setTimeout(() => URL.revokeObjectURL(url), 0);
            }
            if (out) out(`Exportado: ${filename}.`, 'ok');
        } catch (e) { if (out) out('Falha ao exportar.', 'error'); }
    }

    // =========================================================================
    // REGISTRO + EXPORT
    // =========================================================================
    const api = { render, buildHTML, canManage, screenOptions, SCREEN_ID, COLLECTION };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.gestPerfis = { render };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
