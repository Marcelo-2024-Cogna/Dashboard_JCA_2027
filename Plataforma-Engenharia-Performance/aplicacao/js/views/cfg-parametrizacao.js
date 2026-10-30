/**
 * PEP - Plataforma de Engenharia de Performance
 * Parametrização de Métricas (`js/views/cfg-parametrizacao.js`)
 *
 * Tela de configuração (perfil Admin/DM) que edita, via `MetricConfig`:
 *   1. ORIGEM de cada métrica mensal do Score ('jira' | 'manual' |
 *      'integracao-futura'); persistido em localStorage.
 *   2. SPRINT DE FECHAMENTO por PI (5 ou 6, parametrizável) — define quando a
 *      Foto de PI é congelada.
 *   3. Resumo informativo das duas FOTOS (mensal / PI).
 *
 * Registro: `window.PEPViews.cfgParametrizacao = { render }`. App estático,
 * sem backend. IIFE com guarda de ambiente para teste em Node (Vitest).
 */

(function () {
    'use strict';

    const SCREEN_ID = 'cfgParametrizacao';

    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                MetricConfig: '../metric-config.js',
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
        // Reusa a permissão da tela; se não houver RBAC, libera (contexto de teste).
        if (!Rbac || !Rbac.can) return true;
        return !!Rbac.can(profile, SCREEN_ID);
    }

    function safe(fn, fallback) {
        try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; }
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const MC = dep('MetricConfig');
        if (!MC) { container.innerHTML = errorBlock('Módulo de parametrização indisponível.'); return; }

        const profile = currentProfile();
        if (!canManage(profile)) { container.innerHTML = deniedBlock('Parametrização de Métricas'); return; }

        const model = {
            metricas: safe(() => MC.listMetricas(), []),
            pis: safe(() => MC.listPIs(), []),
            origens: MC.ORIGENS,
            fotos: MC.FOTOS,
            sprintsValidas: MC.SPRINTS_FECHAMENTO_VALIDAS || [5, 6]
        };
        container.innerHTML = buildHTML(model);
        wire(container, MC);
    }

    // =========================================================================
    // HTML
    // =========================================================================
    function buildHTML(m) {
        return `
        <div class="adm">
            <div class="adm-head">
                <h2 class="adm-title">🎛️ Parametrização de Métricas</h2>
                <p class="adm-sub">Defina a origem de cada métrica do Score e a sprint de fechamento de cada PI.</p>
            </div>

            ${fotosCard(m)}
            ${origemCard(m)}
            ${piCard(m)}

            <div class="adm-form-msg" id="cfg-msg" role="status" aria-live="polite"></div>
        </div>`;
    }

    function fotosCard(m) {
        const f = m.fotos || {};
        const mensal = f.mensal || {};
        const pi = f.pi || {};
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Fotos (snapshots)</h3>
            <div class="adm-table">
                <div class="adm-tr adm-th"><span>Foto</span><span>Quando congela</span><span>Escopo</span></div>
                <div class="adm-tr">
                    <span class="adm-td">${esc(mensal.label || 'Foto Mensal')}</span>
                    <span class="adm-td">Último dia útil do mês</span>
                    <span class="adm-td">Métricas do Score (mês a mês)</span>
                </div>
                <div class="adm-tr">
                    <span class="adm-td">${esc(pi.label || 'Foto de PI')}</span>
                    <span class="adm-td">Sprint de fechamento do PI (5 ou 6)</span>
                    <span class="adm-td">Métricas de Retrospectiva (PI a PI)</span>
                </div>
            </div>
        </section>`;
    }

    function origemOptions(origens, atual) {
        return Object.keys(origens).map((id) =>
            `<option value="${esc(id)}"${id === atual ? ' selected' : ''}>${esc(origens[id].label)}</option>`
        ).join('');
    }

    function origemCard(m) {
        const rows = (m.metricas || []).map((mt) => {
            const futura = mt.integracaoFutura ? ` <span class="adm-hint">(futuro: ${esc(mt.integracaoFutura)})</span>` : '';
            const detalhe = mt.origemDetalhe ? ` <span class="adm-hint">· ${esc(mt.origemDetalhe)}</span>` : '';
            return `
            <div class="adm-tr" data-metric="${esc(mt.id)}">
                <span class="adm-td">${esc(mt.nome)}${detalhe}</span>
                <span class="adm-td">${esc(mt.categoria)} · ${esc(String(mt.peso))} pts</span>
                <span class="adm-td">
                    <select class="adm-input cfg-origem" data-metric="${esc(mt.id)}" aria-label="Origem de ${esc(mt.nome)}">
                        ${origemOptions(m.origens, mt.origem)}
                    </select>${futura}
                </span>
            </div>`;
        }).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Origem por métrica (Score) <span class="adm-count">(${(m.metricas || []).length})</span></h3>
            <div class="adm-table" id="cfg-origem-list">
                <div class="adm-tr adm-th"><span>Métrica</span><span>Categoria · Peso</span><span>Origem</span></div>
                ${rows}
            </div>
        </section>`;
    }

    function sprintOptions(validas, atual) {
        return validas.map((n) =>
            `<option value="${esc(String(n))}"${Number(n) === Number(atual) ? ' selected' : ''}>Sprint ${esc(String(n))}</option>`
        ).join('');
    }

    function piCard(m) {
        const rows = (m.pis || []).map((p) => `
            <div class="adm-tr" data-pi="${esc(p.pi)}">
                <span class="adm-td">${esc(p.pi)}</span>
                <span class="adm-td">
                    <select class="adm-input cfg-sprint" data-pi="${esc(p.pi)}" aria-label="Sprint de fechamento de ${esc(p.pi)}">
                        ${sprintOptions(m.sprintsValidas, p.sprintFechamento)}
                    </select>
                </span>
            </div>`).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Sprint de fechamento por PI <span class="adm-count">(${(m.pis || []).length})</span></h3>
            <div class="adm-table" id="cfg-pi-list">
                <div class="adm-tr adm-th"><span>PI</span><span>Fechamento</span></div>
                ${rows}
            </div>
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
    function showMsg(container, msg) {
        const box = container.querySelector('#cfg-msg');
        if (box) { box.textContent = msg || ''; box.className = 'adm-form-msg adm-msg-ok'; }
    }

    function wire(container, MC) {
        if (!container || typeof container.querySelector !== 'function') return;

        const origemList = container.querySelector('#cfg-origem-list');
        if (origemList && origemList.addEventListener) {
            origemList.addEventListener('change', (e) => {
                const t = e && e.target ? e.target : null;
                if (!t || typeof t.getAttribute !== 'function') return;
                const metricId = t.getAttribute('data-metric');
                if (!metricId) return;
                if (MC.setOrigem(metricId, t.value)) {
                    showMsg(container, `Origem de "${metricId}" atualizada para "${t.value}".`);
                }
            });
        }

        const piList = container.querySelector('#cfg-pi-list');
        if (piList && piList.addEventListener) {
            piList.addEventListener('change', (e) => {
                const t = e && e.target ? e.target : null;
                if (!t || typeof t.getAttribute !== 'function') return;
                const pi = t.getAttribute('data-pi');
                if (!pi) return;
                if (MC.setSprintFechamento(pi, t.value)) {
                    showMsg(container, `Sprint de fechamento de "${pi}" definida como ${t.value}.`);
                }
            });
        }
    }

    // =========================================================================
    // REGISTRO
    // =========================================================================
    const api = { render };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.cfgParametrizacao = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.cfgParametrizacao = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
