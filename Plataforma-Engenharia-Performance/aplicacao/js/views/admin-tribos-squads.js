/**
 * PEP - Plataforma de Engenharia de Performance
 * Gestão de Tribos e Squads (`js/views/admin-tribos-squads.js`)
 *
 * Task 33.2 (spec score-performance-executivo). Requirement 31.3 e 31.9.
 *
 *   - CRUD client-side de Tribos (Jornadas) e Squads sobre `AdminStore`
 *     (coleção 'tribosSquads'), MANTENDO a consistência com a identificação
 *     canônica (`PEPIdentity`) usada nas demais visões.                   (31.3)
 *   - O seletor de Tribo oferece as jornadas canônicas; a view sinaliza
 *     divergências entre os pares cadastrados e a identidade canônica
 *     (via PEPIdentity.detectDivergences), sem corromper dados.
 *   - Disponível apenas a quem tem `Rbac.can(profile, 'gestTribosSquads')`
 *     (RBAC = fonte única: Admin/DM). Nega por omissão.                   (31.9)
 *   - Import/Export CSV/JSON reversível reutilizando o store (Property 37).
 *
 * Registro: `window.PEPViews.gestTribosSquads = { render }`. App estático,
 * tema escuro preservado. IIFE com guarda de ambiente (Vitest).
 */

(function () {
    'use strict';

    const SCREEN_ID = 'gestTribosSquads';
    const COLLECTION = 'tribosSquads';

    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                AdminStore: '../admin-store.js',
                Rbac: '../rbac.js',
                AppState: '../app-state.js',
                PEPIdentity: '../identity.js'
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

    // Jornadas canônicas (identidade compartilhada).
    function jornadasCanonicas() {
        const Identity = dep('PEPIdentity');
        try { return (Identity && Identity.jornadas) ? (Identity.jornadas() || []) : []; }
        catch (e) { return []; }
    }

    // Divergências dos pares cadastrados vs identidade canônica (Req 31.3/23.5).
    function divergencias(itens) {
        const Identity = dep('PEPIdentity');
        if (!Identity || typeof Identity.detectDivergences !== 'function') return [];
        try {
            return Identity.detectDivergences((itens || []).map((it) => ({ squad: it.squad, jornada: it.tribo })));
        } catch (e) { return []; }
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const Store = dep('AdminStore');
        if (!Store) { container.innerHTML = errorBlock('Módulo de gestão indisponível.'); return; }

        const profile = currentProfile();
        if (!canManage(profile)) { container.innerHTML = deniedBlock('Gestão de Tribos e Squads'); return; }

        const itens = safe(() => Store.list(COLLECTION), []);
        const model = { jornadas: jornadasCanonicas(), itens, divergencias: divergencias(itens) };

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
                <h2 class="adm-title">🏷️ Gestão de Tribos e Squads</h2>
                <p class="adm-sub">Criar, listar, editar e remover Tribos (Jornadas) e Squads — consistentes com a identidade canônica.</p>
            </div>

            <section class="adm-card">
                <h3 class="adm-card-title">Nova Tribo / Squad</h3>
                <form id="adm-form" class="adm-form" novalidate>
                    <input type="hidden" id="adm-id" value="">
                    <div class="adm-field">
                        <label for="adm-tribo">Tribo (Jornada) *</label>
                        <input id="adm-tribo" class="adm-input" type="text" list="adm-tribos-list" placeholder="Ex.: Acadêmica">
                        <datalist id="adm-tribos-list">
                            ${m.jornadas.map((j) => `<option value="${esc(j)}"></option>`).join('')}
                        </datalist>
                    </div>
                    <div class="adm-field">
                        <label for="adm-squad">Squad *</label>
                        <input id="adm-squad" class="adm-input" type="text" placeholder="Ex.: Athenas - Atendimento">
                    </div>
                    <div class="adm-form-actions">
                        <div class="adm-form-msg" id="adm-form-msg" role="alert" aria-live="polite"></div>
                        <button type="submit" class="adm-btn adm-btn-primary" id="adm-submit">Salvar</button>
                        <button type="button" class="adm-btn" id="adm-cancel" hidden>Cancelar edição</button>
                    </div>
                </form>
            </section>

            ${divergCard(m)}
            ${listCard(m)}
            ${ioCard()}
        </div>`;
    }

    function divergCard(m) {
        if (!m.divergencias || !m.divergencias.length) return '';
        const rows = m.divergencias.map((d) => {
            if (d.type === 'unknownSquad') {
                return `<li>Squad fora da identidade canônica: <strong>${esc(d.squad)}</strong>${d.jornada ? ` (tribo "${esc(d.jornada)}")` : ''}.</li>`;
            }
            return `<li>Divergência de jornada para <strong>${esc(d.squad)}</strong>: informada "${esc(d.jornadaInformada)}", canônica "${esc(d.jornadaCanonica)}".</li>`;
        }).join('');
        return `
        <section class="adm-card adm-warn" id="adm-diverg">
            <h3 class="adm-card-title">⚠️ Divergências com a identidade canônica</h3>
            <ul class="adm-diverg-list">${rows}</ul>
        </section>`;
    }

    function listCard(m) {
        const rows = (m.itens || []).map((it) => itemRow(it)).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Tribos e Squads <span class="adm-count">(${(m.itens || []).length})</span></h3>
            ${(m.itens && m.itens.length)
                ? `<div class="adm-table" id="adm-list">
                       <div class="adm-tr adm-th"><span>Tribo (Jornada)</span><span>Squad</span><span>Ações</span></div>
                       ${rows}
                   </div>`
                : `<p class="adm-empty">Nenhuma tribo/squad cadastrada.</p>`}
        </section>`;
    }

    function itemRow(it) {
        return `
        <div class="adm-tr" data-id="${esc(it.id)}">
            <span class="adm-td">${esc(it.tribo)}</span>
            <span class="adm-td">${esc(it.squad)}</span>
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
        return `<div class="adm"><div class="adm-denied" role="alert"><span aria-hidden="true">🚫</span> Seu perfil não tem permissão para acessar <strong>${esc(label)}</strong>.</div></div>`;
    }
    function errorBlock(msg) {
        return `<div class="adm"><div class="adm-empty"><span aria-hidden="true">⚠️</span> ${esc(msg)}</div></div>`;
    }

    // =========================================================================
    // WIRING
    // =========================================================================
    function val(container, sel) { const el = container.querySelector(sel); return el && el.value != null ? el.value : ''; }
    function showMsg(container, msg, kind) {
        const box = container.querySelector('#adm-form-msg');
        if (box) { box.textContent = msg || ''; box.className = 'adm-form-msg' + (kind ? ` adm-msg-${kind}` : ''); }
    }

    function wire(container, Store, profile) {
        if (!container || typeof container.querySelector !== 'function') return;
        const form = container.querySelector('#adm-form');
        if (form && form.addEventListener) {
            form.addEventListener('submit', (e) => { if (e && e.preventDefault) e.preventDefault(); handleSubmit(container, Store, profile); });
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
        set('#adm-id', it.id); set('#adm-tribo', it.tribo); set('#adm-squad', it.squad);
        const cancel = container.querySelector('#adm-cancel');
        if (cancel) cancel.hidden = false;
        showMsg(container, `Editando "${it.squad}".`, 'ok');
    }

    function handleSubmit(container, Store, profile) {
        if (!canManage(profile)) { showMsg(container, 'Seu perfil não permite esta operação.', 'error'); return; }
        const id = val(container, '#adm-id');
        const input = { tribo: val(container, '#adm-tribo'), squad: val(container, '#adm-squad') };
        const errors = [];
        if (!String(input.tribo).trim()) errors.push('Tribo (Jornada) é obrigatória.');
        if (!String(input.squad).trim()) errors.push('Squad é obrigatório.');
        if (errors.length) { showMsg(container, errors.join(' '), 'error'); return; }
        try {
            if (id) Store.update(COLLECTION, id, input); else Store.create(COLLECTION, input);
            render(container, {});
            showMsg(container, id ? 'Registro atualizado.' : 'Registro criado.', 'ok');
        } catch (e) { showMsg(container, (e && e.message) ? e.message : 'Falha ao salvar.', 'error'); }
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
                const a = document.createElement('a'); a.href = url; a.download = filename; a.click();
                setTimeout(() => URL.revokeObjectURL(url), 0);
            }
            if (out) out(`Exportado: ${filename}.`, 'ok');
        } catch (e) { if (out) out('Falha ao exportar.', 'error'); }
    }

    // =========================================================================
    // REGISTRO + EXPORT
    // =========================================================================
    const api = { render, buildHTML, canManage, jornadasCanonicas, divergencias, SCREEN_ID, COLLECTION };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.gestTribosSquads = { render };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
