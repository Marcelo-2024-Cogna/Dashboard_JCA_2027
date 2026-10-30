/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Sustentação (`js/views/retro-sustentacao.js`)
 *
 * Task 18.4 (spec score-performance-executivo). Implementa o Requirement 16:
 *   - Incidentes + Solicitações Realizado vs Planejado por Time.       (16.1)
 *   - Total da Jornada de Realizado e Planejado.                       (16.2)
 *   - Histórico de Sustentação por PI, com % quando disponível.        (16.3)
 *
 * Registro: `window.PEPViews.retroSustentacao = { render }`. CSS: prefixo `.rst-`.
 */

(function () {
    'use strict';

    const charts = { rp: null, hist: null };
    const COLOR_REAL = '#3b82f6';
    const COLOR_PLAN = '#64748b';

    function dep(name, relPath) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        if (relPath && typeof require !== 'undefined') {
            try { return require(relPath); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
    const hasData = (n) => (typeof n === 'number' && isFinite(n));
    const fmtNum = (n) => hasData(n) ? n.toFixed(0) : '—';
    const fmtPct = (r) => hasData(r) ? `${(r * 100).toFixed(0)}%` : '—';

    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);
        const squad = (filter.squad != null && filter.squad !== 'all') ? filter.squad : undefined;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : undefined;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        if (!RetroAggregation || typeof RetroAggregation.sustentacao !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        destroyCharts();

        let agg;
        try { agg = RetroAggregation.sustentacao(pi, { squad, jornada: jornadaSel }); }
        catch (e) { container.innerHTML = errorBlock('Falha ao agregar a Sustentação.'); return; }

        const porTime = (agg && Array.isArray(agg.porTime)) ? agg.porTime : [];
        const total = (agg && agg.totalJornada) ? agg.totalJornada : { realizado: 0, planejado: 0 };
        const historico = (agg && Array.isArray(agg.historico)) ? agg.historico : [];
        // Escopo exibido: reflete o filtro ativo (squad > jornada > todas), nunca
        // uma jornada fixa enganosa quando não há dado.
        const escopo = squad || jornadaSel
            || ((porTime[0] && porTime[0].jornada) ? porTime[0].jornada : 'Todas as Jornadas');

        if (porTime.length === 0) {
            container.innerHTML = buildShell({ pi, jornada: escopo, body: neutralBlock(pi) });
            return;
        }

        container.innerHTML = buildShell({ pi, jornada: escopo, body: buildBody({ porTime, total, historico }) });

        try { drawRP(container, porTime, total); } catch (e) { console.error('retro-sustentacao rp:', e); }
        try { drawHist(container, historico); } catch (e) { console.error('retro-sustentacao hist:', e); }
    }

    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rst">
            <div class="rst-head">
                <div class="rst-head-left">
                    <h2 class="rst-title">🛟 Sustentação</h2>
                    <span class="rst-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rst-pi">PI: ${piLabel}</span>
            </div>
            ${m.body}
        </div>`;
    }

    function buildBody(m) {
        return `
        <div class="rst-chart-card">
            <h3 class="rst-card-title">Incidentes + Solicitações — Realizado vs Planejado por Time</h3>
            <div class="rst-chart-wrap"><canvas id="rst-rp-canvas"></canvas></div>
            <div class="rst-legend">
                <span class="rst-leg-item"><span class="rst-leg-dot" style="background:${COLOR_REAL}"></span>Realizado</span>
                <span class="rst-leg-item"><span class="rst-leg-dot" style="background:${COLOR_PLAN}"></span>Planejado</span>
            </div>
        </div>
        ${buildTable(m)}
        ${histBlock(m.historico)}`;
    }

    function buildTable(m) {
        const rows = m.porTime.map(t => `
            <tr>
                <td class="rst-td-left">${esc(t.time)}</td>
                <td>${esc(fmtNum(t.realizado))}</td>
                <td>${esc(fmtNum(t.planejado))}</td>
            </tr>`).join('');
        const totalRow = `
            <tr class="rst-row-total">
                <td class="rst-td-left">Total da Jornada</td>
                <td>${esc(fmtNum(m.total.realizado))}</td>
                <td>${esc(fmtNum(m.total.planejado))}</td>
            </tr>`;
        return `
        <div class="rst-table-card">
            <table class="rst-table">
                <thead><tr><th class="rst-th-left">Time</th><th>Realizado</th><th>Planejado</th></tr></thead>
                <tbody>${rows}${totalRow}</tbody>
            </table>
        </div>`;
    }

    function histBlock(historico) {
        if (!historico || historico.length === 0) return '';
        return `
        <div class="rst-chart-card">
            <h3 class="rst-card-title">Histórico de Incidentes por PI</h3>
            <div class="rst-chart-wrap"><canvas id="rst-hist-canvas"></canvas></div>
        </div>`;
    }

    function neutralBlock(pi) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        return `<div class="rst-neutral"><div class="rst-neutral-icon" aria-hidden="true">🗓️</div><p class="rst-neutral-text">Sem dados de Sustentação para o PI ${piLabel}.</p></div>`;
    }
    function errorBlock(msg) {
        return `<div class="rst"><div class="rst-neutral"><div class="rst-neutral-icon" aria-hidden="true">⚠️</div><p class="rst-neutral-text">${esc(msg)}</p></div></div>`;
    }

    function destroyCharts() {
        if (charts.rp) { try { charts.rp.destroy(); } catch (e) {} charts.rp = null; }
        if (charts.hist) { try { charts.hist.destroy(); } catch (e) {} charts.hist = null; }
    }

    function drawRP(container, porTime, total) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rst-rp-canvas');
        if (!canvas) return;
        const labels = porTime.map(t => t.time).concat(['Total da Jornada']);
        charts.rp = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Realizado', data: porTime.map(t => t.realizado).concat([total.realizado]), backgroundColor: COLOR_REAL, borderWidth: 0 },
                    { label: 'Planejado', data: porTime.map(t => t.planejado).concat([total.planejado]), backgroundColor: COLOR_PLAN, borderWidth: 0 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } }
                }
            }
        });
    }

    function drawHist(container, historico) {
        if (typeof Chart === 'undefined' || !historico || historico.length === 0) return;
        const canvas = container.querySelector('#rst-hist-canvas');
        if (!canvas) return;
        // Agrupa o histórico por PI (soma dos valores dos times).
        const porPI = {};
        historico.forEach(h => { porPI[h.pi] = (porPI[h.pi] || 0) + (h.valor || 0); });
        const labels = Object.keys(porPI);
        charts.hist = new Chart(canvas, {
            type: 'bar',
            data: { labels, datasets: [{ label: 'Incidentes', data: labels.map(p => porPI[p]), backgroundColor: '#f59e0b', borderWidth: 0 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } }
                }
            }
        });
    }

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroSustentacao = { render };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = { render }; }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroSustentacao = { render };
    }
})();
