/**
 * PEP - Plataforma de Engenharia de Performance
 * Entrada Manual de Métricas (`js/views/entrada-manual.js`)
 *
 * Tela (perfil Admin/DM) para informar manualmente, no fechamento, os valores
 * das métricas cuja origem NÃO é o Jira (via MetricConfig.metricasManuais()).
 * Hoje: Frequência de Deploy, Disponibilidade, Vulnerabilidade, Team Health
 * Check. Os valores são persistidos em ManualMetricsStore por (mês, squad,
 * métrica) e alimentam a Foto Mensal.
 *
 * Registro: `window.PEPViews.entradaManual = { render }`. App estático, sem
 * backend. IIFE com guarda de ambiente para teste em Node (Vitest).
 */

(function () {
    'use strict';

    const SCREEN_ID = 'entradaManual';

    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                MetricConfig: '../metric-config.js',
                ManualMetricsStore: '../manual-metrics-store.js',
                PEPData: '../data.js',
                AppState: '../app-state.js',
                Rbac: '../rbac.js'
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
    // A entrada manual deixou de ser uma tela de menu: agora é exibida no modal
    // "Entrada de Dados" da tela Operacional/Times (cujo acesso já é controlado
    // por RBAC). Portanto a view sempre renderiza quando chamada.
    function canManage(/* profile */) { return true; }
    function safe(fn, fallback) {
        try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; }
    }

    function currentMonth() {
        const AppState = dep('AppState');
        const PEPData = dep('PEPData');
        let m = safe(() => AppState && AppState.getSelectedMonth && AppState.getSelectedMonth(), null);
        if (m == null) m = safe(() => PEPData && PEPData.getLatestMonth && PEPData.getLatestMonth(), 1);
        return m || 1;
    }
    function monthLabel(m) {
        const PEPData = dep('PEPData');
        const labels = (PEPData && PEPData.MONTH_LABELS) || [];
        return labels[(m || 1) - 1] || String(m);
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const MC = dep('MetricConfig');
        const Store = dep('ManualMetricsStore');
        const PEPData = dep('PEPData');
        if (!MC || !Store || !PEPData) {
            container.innerHTML = errorBlock('Módulos de entrada manual indisponíveis.');
            return;
        }
        const profile = currentProfile();
        if (!canManage(profile)) { container.innerHTML = deniedBlock('Entrada Manual de Métricas'); return; }

        const mes = (opts && opts.month != null) ? opts.month : currentMonth();
        const metricasManuais = safe(() => MC.metricasManuais(), []);
        const teams = safe(() => PEPData.TEAMS, []);

        const model = { mes, mesLabel: monthLabel(mes), metricas: metricasManuais, teams, Store };
        container.innerHTML = buildHTML(model);
        wire(container, Store, mes);
    }

    // =========================================================================
    // HTML
    // =========================================================================
    function buildHTML(m) {
        if (!m.metricas.length) {
            return `<div class="adm"><div class="adm-head"><h2 class="adm-title">✍️ Entrada Manual de Métricas</h2></div>
                <p class="adm-empty">Nenhuma métrica manual configurada. Todas as métricas têm origem Jira.</p></div>`;
        }
        return `
        <div class="adm">
            <div class="adm-head">
                <h2 class="adm-title">✍️ Entrada Manual de Métricas</h2>
                <p class="adm-sub">Mês de referência: <strong>${esc(m.mesLabel)}</strong>. Informe os valores das métricas de origem manual para cada squad.</p>
            </div>
            <section class="adm-card">
                <h3 class="adm-card-title">Métricas manuais <span class="adm-count">(${m.metricas.length})</span></h3>
                <p class="adm-hint">${m.metricas.map((x) => esc(x.nome)).join(' · ')}</p>
            </section>
            ${tableCard(m)}
            <div class="adm-form-msg" id="man-msg" role="status" aria-live="polite"></div>
        </div>`;
    }

    function tableCard(m) {
        const headCols = m.metricas.map((mt) => `<span>${esc(mt.nome)}</span>`).join('');
        const rows = (m.teams || []).map((t) => rowFor(t, m)).join('');
        return `
        <section class="adm-card">
            <h3 class="adm-card-title">Squads <span class="adm-count">(${(m.teams || []).length})</span></h3>
            <div class="adm-table adm-table-wide" id="man-list">
                <div class="adm-tr adm-th"><span>Squad / Jornada</span>${headCols}</div>
                ${rows}
            </div>
        </section>`;
    }

    function rowFor(t, m) {
        const cells = m.metricas.map((mt) => {
            const atual = safe(() => m.Store.get(m.mes, t.id, mt.id), null);
            const v = (atual == null) ? '' : String(atual);
            return `<span class="adm-td">
                <input type="number" step="any" class="adm-input man-input"
                       data-squad="${esc(t.id)}" data-metric="${esc(mt.id)}"
                       value="${esc(v)}" placeholder="—"
                       aria-label="${esc(mt.nome)} de ${esc(t.name)}">
            </span>`;
        }).join('');
        return `
        <div class="adm-tr" data-squad="${esc(t.id)}">
            <span class="adm-td"><strong>${esc(t.name)}</strong><br><span class="adm-hint">${esc(t.jornada)}</span></span>
            ${cells}
        </div>`;
    }

    function deniedBlock(label) {
        return `<div class="adm"><div class="adm-denied" role="alert"><span aria-hidden="true">🚫</span>
            Seu perfil não tem permissão para acessar <strong>${esc(label)}</strong>.</div></div>`;
    }
    function errorBlock(msg) {
        return `<div class="adm"><div class="adm-empty"><span aria-hidden="true">⚠️</span> ${esc(msg)}</div></div>`;
    }

    // =========================================================================
    // WIRING
    // =========================================================================
    function showMsg(container, msg, kind) {
        const box = container.querySelector('#man-msg');
        if (box) { box.textContent = msg || ''; box.className = 'adm-form-msg' + (kind ? ` adm-msg-${kind}` : ''); }
    }

    function wire(container, Store, mes) {
        if (!container || typeof container.querySelector !== 'function') return;
        const list = container.querySelector('#man-list');
        if (!list || !list.addEventListener) return;

        // Salva ao sair do campo (change) — valor numérico ou limpa se vazio.
        list.addEventListener('change', (e) => {
            const t = e && e.target ? e.target : null;
            if (!t || typeof t.getAttribute !== 'function') return;
            const squad = t.getAttribute('data-squad');
            const metric = t.getAttribute('data-metric');
            if (!squad || !metric) return;
            const raw = (t.value == null) ? '' : String(t.value).trim();
            if (raw === '') {
                Store.remove(mes, squad, metric);
                showMsg(container, `Valor de ${metric} para ${squad} removido.`, 'ok');
                return;
            }
            if (Store.set(mes, squad, metric, raw)) {
                showMsg(container, `Valor salvo: ${metric} = ${raw} (${squad}).`, 'ok');
            } else {
                showMsg(container, `Valor inválido para ${metric} (${squad}). Informe um número.`, 'error');
            }
        });
    }

    // =========================================================================
    // REGISTRO
    // =========================================================================
    const api = { render };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.entradaManual = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.entradaManual = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
