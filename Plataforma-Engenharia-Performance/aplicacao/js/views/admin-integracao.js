/**
 * PEP - Plataforma de Engenharia de Performance
 * Configuração de Integração (`js/views/admin-integracao.js`)
 *
 * Task 33.2 (spec score-performance-executivo). Requirement 31.7 e 31.9.
 *
 *   - Configurar os parâmetros de ingestão (mapeamento de projetos do Jira →
 *     Jornadas/Squads) sobre `AdminStore` (coleção 'integracao'): sistema,
 *     projeto Jira, jornada e squad.                                      (31.7)
 *   - SEM credenciais sensíveis no navegador: a view NÃO possui campos de
 *     token/senha/apiKey; o `AdminStore` também remove chaves sensíveis
 *     (stripSensitive) por defesa em profundidade.                        (31.7)
 *   - Jornada/Squad consistentes com a identidade canônica (PEPIdentity).
 *   - Disponível apenas a quem tem `Rbac.can(profile, 'cfgIntegracao')`
 *     (RBAC = fonte única: Admin/DM). Nega por omissão.                   (31.9)
 *   - Import/Export CSV/JSON reversível reutilizando o store (Property 37).
 *
 * Registro: `window.PEPViews.cfgIntegracao = { render }`. App estático,
 * tema escuro preservado. IIFE com guarda de ambiente (Vitest).
 */

(function () {
    'use strict';

    const SCREEN_ID = 'cfgIntegracao';
    const COLLECTION = 'integracao';

    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                AdminStore: '../admin-store.js', Rbac: '../rbac.js',
                AppState: '../app-state.js', PEPIdentity: '../identity.js'
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
        try { return (AppState && AppState.getProfile) ? AppState.getProfile() : 'admin'; } catch (e) { return 'admin'; }
    }
    function canManage(profile) {
        const Rbac = dep('Rbac');
        return !!(Rbac && Rbac.can && Rbac.can(profile, SCREEN_ID));
    }
    function catalog() {
        const Identity = dep('PEPIdentity');
        const out = [];
        if (!Identity) return out;
        let js = [];
        try { js = Identity.jornadas() || []; } catch (e) { js = []; }
        js.forEach((j) => {
            let sq = [];
            try { sq = Identity.squadsOf(j) || []; } catch (e) { sq = []; }
            out.push({ jornada: j, squads: sq });
        });
        return out;
    }

    const SISTEMAS = ['Jira', 'ServiceNow', 'Datadog'];

    function render(container) {
        if (!container) return;
        const Store = dep('AdminStore');
        if (!Store) { container.innerHTML = errorBlock('Módulo de gestão indisponível.'); return; }
        const profile = currentProfile();
        if (!canManage(profile)) { container.innerHTML = deniedBlock('Configuração de Integração'); return; }
        const model = { catalog: catalog(), itens: safe(() => Store.list(COLLECTION), []) };
        container.innerHTML = buildHTML(model);
        wire(container, Store, profile);
    }

    function safe(fn, fallback) { try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; } }

    function buildHTML(m) {
        return `
        <div class="adm">
            <div class="adm-head">
                <h2 class="adm-title">🔌 Configuração de Integração</h2>
                <p class="adm-sub">Mapeamento de projetos do Jira → Jornadas/Squads para a ingestão.</p>
            </div>

            <div class="adm-note" id="adm-cred-note" role="note">
                <span aria-hidden="true">🔒</span>
                Credenciais sensíveis (token/senha) <strong>não são armazenadas</strong> no navegador.
                Apenas os parâmetros de mapeamento são persistidos localmente.
            </div>

            <section class="adm-card">
                <h3 class="adm-card-title">Novo mapeamento / Edição</h3>
                <form id="adm-form" class="adm-form" novalidate>
                    <input type="hidden" id="adm-id" value="">
                    <div class="adm-field">
                        <label for="adm-sistema">Sistema *</label>
                        <select id="adm-sistema" class="adm-input">
                            <option value="">Selecione o sistema</option>
                            ${SISTEMAS.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="adm-field">
                        <label for="adm-projetoJira">Projeto Jira *</label>
                        <input id="adm-projetoJira" class="adm-input" type="text" placeholder="Ex.: ACAD, SABER">
                    </div>
                    <div class="adm-field">
                        <label for="adm-jornada">Jornada *</label>
                        ${jornadaSelect(m.catalog)}
                    </div>
                    <div class="adm-field">
                        <label for="adm-squad">Squad</label>
                        ${squadSelect(m.catalog)}
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

    function jornadaSelect(cat) {
        const opts = cat.map((g) => `<option value="${esc(g.jornada)}">${esc(g.jornada)}</option>`).join('');
        return `<select id="adm-jornada" class="adm-input"><option value="">Selecione a jornada</option>${opts}</select>`;
    }
    function squadSelect(cat) {
        const groups = cat.map((g) => {
            const opts = g.squads.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
            return opts ? `<optgroup label="${esc(g.jornada)}">${opts}</optgroup>` : '';
        }).join('');
        return `<select id="adm-squad" class="adm-input"><option value="">(opcional) Selecione o squad</option>${groups}</select>`;
    }

    function listCard(m) {
        const rows = (m.itens || []).map((it) => itemRow(it)).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Mapeamentos <span class="adm-count">(${(m.itens || []).length})</span></h3>
            ${(m.itens && m.itens.length)
                ? `<div class="adm-table" id="adm-list">
                       <div class="adm-tr adm-th"><span>Sistema</span><span>Projeto Jira</span><span>Jornada</span><span>Squad</span><span>Ações</span></div>
                       ${rows}
                   </div>`
                : `<p class="adm-empty">Nenhum mapeamento configurado.</p>`}
        </section>`;
    }
    function itemRow(it) {
        return `
        <div class="adm-tr" data-id="${esc(it.id)}">
            <span class="adm-td">${esc(it.sistema)}</span>
            <span class="adm-td">${esc(it.projetoJira)}</span>
            <span class="adm-td">${esc(it.jornada)}</span>
            <span class="adm-td">${esc(it.squad) || '—'}</span>
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
    function errorBlock(msg) { return `<div class="adm"><div class="adm-empty"><span aria-hidden="true">⚠️</span> ${esc(msg)}</div></div>`; }

    function val(container, sel) { const el = container.querySelector(sel); return el && el.value != null ? el.value : ''; }
    function showMsg(container, msg, kind) {
        const box = container.querySelector('#adm-form-msg');
        if (box) { box.textContent = msg || ''; box.className = 'adm-form-msg' + (kind ? ` adm-msg-${kind}` : ''); }
    }

    function wire(container, Store, profile) {
        if (!container || typeof container.querySelector !== 'function') return;
        const form = container.querySelector('#adm-form');
        if (form && form.addEventListener) form.addEventListener('submit', (e) => { if (e && e.preventDefault) e.preventDefault(); handleSubmit(container, Store, profile); });
        const cancel = container.querySelector('#adm-cancel');
        if (cancel && cancel.addEventListener) cancel.addEventListener('click', () => render(container));
        const list = container.querySelector('#adm-list');
        if (list && list.addEventListener) {
            list.addEventListener('click', (e) => {
                const t = e && e.target ? e.target : null;
                if (!t || typeof t.getAttribute !== 'function') return;
                const editId = t.getAttribute('data-edit'); const delId = t.getAttribute('data-del');
                if (editId) startEdit(container, Store, editId);
                else if (delId) { if (canManage(profile)) { Store.remove(COLLECTION, delId); render(container); } }
            });
        }
        wireIO(container, Store);
    }

    function startEdit(container, Store, id) {
        const it = safe(() => Store.get(COLLECTION, id), null);
        if (!it) return;
        const set = (sel, v) => { const el = container.querySelector(sel); if (el) el.value = v; };
        set('#adm-id', it.id); set('#adm-sistema', it.sistema); set('#adm-projetoJira', it.projetoJira);
        set('#adm-jornada', it.jornada); set('#adm-squad', it.squad);
        const cancel = container.querySelector('#adm-cancel'); if (cancel) cancel.hidden = false;
        showMsg(container, `Editando mapeamento "${it.projetoJira}".`, 'ok');
    }

    function handleSubmit(container, Store, profile) {
        if (!canManage(profile)) { showMsg(container, 'Seu perfil não permite esta operação.', 'error'); return; }
        const id = val(container, '#adm-id');
        // IMPORTANTE: nenhum campo de credencial é lido/enviado (Req 31.7).
        const input = {
            sistema: val(container, '#adm-sistema'), projetoJira: val(container, '#adm-projetoJira'),
            jornada: val(container, '#adm-jornada'), squad: val(container, '#adm-squad')
        };
        const errors = [];
        if (!String(input.sistema).trim()) errors.push('Sistema é obrigatório.');
        if (!String(input.projetoJira).trim()) errors.push('Projeto Jira é obrigatório.');
        if (!String(input.jornada).trim()) errors.push('Jornada é obrigatória.');
        if (errors.length) { showMsg(container, errors.join(' '), 'error'); return; }
        try {
            if (id) Store.update(COLLECTION, id, input); else Store.create(COLLECTION, input);
            render(container);
            showMsg(container, id ? 'Mapeamento atualizado.' : 'Mapeamento criado.', 'ok');
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
                    render(container);
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

    const api = { render, buildHTML, canManage, catalog, SCREEN_ID, COLLECTION };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.cfgIntegracao = { render };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})();
