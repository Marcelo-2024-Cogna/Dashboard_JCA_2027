/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Lead Time por Tipo de Item (`js/views/retro-lead-time.js`)
 *
 * Task 18.2 (spec score-performance-executivo). Implementa o Requirement 14:
 *   - Lead Time médio por Squad e por Tipo de Item (História, Enabler,
 *     Débito Técnico, Spike, Teste).                                   (14.1)
 *   - Média da Jornada por Tipo de Item.                               (14.2)
 *   - Ausência de dado por Squad/Tipo indicada visualmente.            (14.3)
 *
 * Registro: `window.PEPViews.retroLeadTime = { render }`. CSS: prefixo `.rlt-`.
 */

(function () {
    'use strict';

    const charts = { barras: null };
    const SERIE_COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'];

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
    const fmtDias = (n) => hasData(n) ? `${n.toFixed(1)} d` : '—';

    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);
        const squad = (filter.squad != null && filter.squad !== 'all') ? filter.squad : undefined;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : undefined;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        if (!RetroAggregation || typeof RetroAggregation.leadTimePorTipo !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        destroyCharts();

        let agg;
        try { agg = RetroAggregation.leadTimePorTipo(pi, { squad, jornada: jornadaSel }); }
        catch (e) { container.innerHTML = errorBlock('Falha ao agregar o Lead Time.'); return; }

        const porSquad = (agg && Array.isArray(agg.porSquad)) ? agg.porSquad : [];
        const mediaPorTipo = (agg && agg.mediaJornadaPorTipo) ? agg.mediaJornadaPorTipo : {};
        const jornada = squad || jornadaSel
            || ((porSquad[0] && porSquad[0].jornada) ? porSquad[0].jornada : 'Todas as Jornadas');
        const tipos = Object.keys(mediaPorTipo);

        if (porSquad.length === 0 || tipos.length === 0) {
            container.innerHTML = buildShell({ pi, jornada, body: neutralBlock(pi) });
            return;
        }

        container.innerHTML = buildShell({
            pi, jornada,
            body: buildBody({ porSquad, mediaPorTipo, tipos, jornada })
        });

        try { drawBarras(container, { porSquad, mediaPorTipo, tipos }); }
        catch (e) { console.error('retro-lead-time barras:', e); }
    }

    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rlt">
            <div class="rlt-head">
                <div class="rlt-head-left">
                    <h2 class="rlt-title">⏳ Lead Time por Tipo de Item</h2>
                    <span class="rlt-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rlt-pi">PI: ${piLabel}</span>
            </div>
            ${m.body}
        </div>`;
    }

    function buildBody(m) {
        return `
        <div class="rlt-chart-card">
            <h3 class="rlt-card-title">Lead Time médio (dias) por Squad e Tipo de Item</h3>
            <div class="rlt-chart-wrap"><canvas id="rlt-barras-canvas"></canvas></div>
            <div class="rlt-legend">
                ${m.tipos.map((t, i) => `<span class="rlt-leg-item"><span class="rlt-leg-dot" style="background:${SERIE_COLORS[i % SERIE_COLORS.length]}"></span>${esc(t)}</span>`).join('')}
            </div>
        </div>
        ${buildTable(m)}`;
    }

    function buildTable(m) {
        const head = `
            <tr>
                <th class="rlt-th-left">Squad</th>
                ${m.tipos.map(t => `<th>${esc(t)}</th>`).join('')}
            </tr>`;
        const rows = m.porSquad.map(s => {
            const cells = m.tipos.map(t => cellTd(s.tipos ? s.tipos[t] : null)).join('');
            return `<tr><td class="rlt-td-left">${esc(s.squad)}</td>${cells}</tr>`;
        }).join('');
        const mediaCells = m.tipos.map(t => cellTd(m.mediaPorTipo[t])).join('');
        const mediaRow = `<tr class="rlt-row-media"><td class="rlt-td-left">Média da Jornada</td>${mediaCells}</tr>`;
        return `
        <div class="rlt-table-card">
            <table class="rlt-table"><thead>${head}</thead><tbody>${rows}${mediaRow}</tbody></table>
        </div>`;
    }

    function cellTd(v) {
        if (!hasData(v)) return `<td class="rlt-nodata" title="Sem dado">Sem dado</td>`;
        return `<td>${esc(fmtDias(v))}</td>`;
    }

    function neutralBlock(pi) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        return `
        <div class="rlt-neutral">
            <div class="rlt-neutral-icon" aria-hidden="true">🗓️</div>
            <p class="rlt-neutral-text">Sem dados de Lead Time para o PI ${piLabel}.</p>
        </div>`;
    }

    function errorBlock(msg) {
        return `<div class="rlt"><div class="rlt-neutral"><div class="rlt-neutral-icon" aria-hidden="true">⚠️</div><p class="rlt-neutral-text">${esc(msg)}</p></div></div>`;
    }

    function destroyCharts() {
        if (charts.barras) { try { charts.barras.destroy(); } catch (e) {} charts.barras = null; }
    }

    function drawBarras(container, m) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rlt-barras-canvas');
        if (!canvas) return;
        const labels = m.porSquad.map(s => s.squad).concat(['Média da Jornada']);
        const datasets = m.tipos.map((t, i) => {
            const dados = m.porSquad.map(s => hasData(s.tipos ? s.tipos[t] : null) ? s.tipos[t] : null);
            dados.push(hasData(m.mediaPorTipo[t]) ? m.mediaPorTipo[t] : null);
            return { label: t, data: dados, backgroundColor: SERIE_COLORS[i % SERIE_COLORS.length], borderWidth: 0 };
        });
        charts.barras = new Chart(canvas, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => { const v = ctx.parsed.y; return `${ctx.dataset.label}: ${(v == null || !isFinite(v)) ? 'Sem dado' : v.toFixed(1) + ' d'}`; } } }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: { beginAtZero: true, title: { display: true, text: 'dias', color: '#94a3b8' }, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } }
                }
            }
        });
    }

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroLeadTime = { render };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = { render }; }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroLeadTime = { render };
    }
})();
