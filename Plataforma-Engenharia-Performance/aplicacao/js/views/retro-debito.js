/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Débito Técnico (`js/views/retro-debito.js`)
 *
 * Task 18.3 (spec score-performance-executivo). Implementa o Requirement 15:
 *   - Backlog de Débito Técnico por Time.                              (15.1)
 *   - No PI: Planejado/Real/Entregue/Pendente/Cancelado em SP e qtde.  (15.2)
 *   - Total da Jornada para cada indicador.                            (15.3)
 *
 * Registro: `window.PEPViews.retroDebito = { render }`. CSS: prefixo `.rdt-`.
 */

(function () {
    'use strict';

    const charts = { backlog: null, pirc: null };

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

    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);
        const squad = (filter.squad != null && filter.squad !== 'all') ? filter.squad : undefined;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : undefined;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        if (!RetroAggregation || typeof RetroAggregation.debitoTecnico !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        destroyCharts();

        let agg;
        try { agg = RetroAggregation.debitoTecnico(pi, { squad, jornada: jornadaSel }); }
        catch (e) { container.innerHTML = errorBlock('Falha ao agregar o Débito Técnico.'); return; }

        const porTime = (agg && Array.isArray(agg.porTime)) ? agg.porTime : [];
        const total = (agg && agg.totalJornada) ? agg.totalJornada : {};
        // Rótulo do escopo: só nomeia a jornada/squad quando há filtro ativo.
        // Sem filtro, o painel consolida TODAS as jornadas — não deve exibir o
        // nome da primeira jornada (seria enganoso).
        const jornada = squad || jornadaSel || 'Todas as Jornadas';

        if (porTime.length === 0) {
            container.innerHTML = buildShell({ pi, jornada, body: neutralBlock(pi) });
            return;
        }

        container.innerHTML = buildShell({ pi, jornada, body: buildBody({ porTime, total }) });

        try { drawBacklog(container, porTime); } catch (e) { console.error('retro-debito backlog:', e); }
        try { drawPIRC(container, total); } catch (e) { console.error('retro-debito pirc:', e); }
    }

    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rdt">
            <div class="rdt-head">
                <div class="rdt-head-left">
                    <h2 class="rdt-title">🧰 Débito Técnico</h2>
                    <span class="rdt-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rdt-pi">PI: ${piLabel}</span>
            </div>
            ${m.body}
        </div>`;
    }

    function buildBody(m) {
        return `
        <div class="rdt-grid">
            <div class="rdt-chart-card">
                <h3 class="rdt-card-title">Backlog de Débito Técnico por Time (qtde)</h3>
                <div class="rdt-chart-wrap"><canvas id="rdt-backlog-canvas"></canvas></div>
            </div>
            <div class="rdt-chart-card">
                <h3 class="rdt-card-title">PI — Planejado / Real / Entregue / Pendente / Cancelado</h3>
                <div class="rdt-chart-wrap"><canvas id="rdt-pirc-canvas"></canvas></div>
            </div>
        </div>
        ${buildTable(m)}`;
    }

    // Tabela de totais da Jornada em Story Points e em quantidade (15.2, 15.3).
    function buildTable(m) {
        const t = m.total;
        const linhas = [
            ['Story Points', t.planejadoSP, t.realSP, t.entregueSP, t.pendenteSP, t.canceladoSP],
            ['Quantidade',   t.planejadoQtd, t.realQtd, t.entregueQtd, t.pendenteQtd, t.canceladoQtd]
        ];
        const body = linhas.map(l => `
            <tr>
                <td class="rdt-td-left">${esc(l[0])}</td>
                <td>${esc(fmtNum(l[1]))}</td>
                <td>${esc(fmtNum(l[2]))}</td>
                <td>${esc(fmtNum(l[3]))}</td>
                <td>${esc(fmtNum(l[4]))}</td>
                <td>${esc(fmtNum(l[5]))}</td>
            </tr>`).join('');
        return `
        <div class="rdt-table-card">
            <h3 class="rdt-card-title">Total da Jornada</h3>
            <table class="rdt-table">
                <thead><tr><th class="rdt-th-left">Métrica</th><th>Planejado</th><th>Real</th><th>Entregue</th><th>Pendente</th><th>Cancelado</th></tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`;
    }

    function neutralBlock(pi) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        return `<div class="rdt-neutral"><div class="rdt-neutral-icon" aria-hidden="true">🗓️</div><p class="rdt-neutral-text">Sem dados de Débito Técnico para o PI ${piLabel}.</p></div>`;
    }
    function errorBlock(msg) {
        return `<div class="rdt"><div class="rdt-neutral"><div class="rdt-neutral-icon" aria-hidden="true">⚠️</div><p class="rdt-neutral-text">${esc(msg)}</p></div></div>`;
    }

    function destroyCharts() {
        if (charts.backlog) { try { charts.backlog.destroy(); } catch (e) {} charts.backlog = null; }
        if (charts.pirc) { try { charts.pirc.destroy(); } catch (e) {} charts.pirc = null; }
    }

    function drawBacklog(container, porTime) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rdt-backlog-canvas');
        if (!canvas) return;
        charts.backlog = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: porTime.map(t => t.time),
                datasets: [{ label: 'Backlog (qtde)', data: porTime.map(t => t.backlogQtde), backgroundColor: '#8b5cf6', borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(15,23,42,0.08)' } }
                }
            }
        });
    }

    function drawPIRC(container, total) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rdt-pirc-canvas');
        if (!canvas) return;
        const labels = ['Planejado', 'Real', 'Entregue', 'Pendente', 'Cancelado'];
        charts.pirc = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Story Points', data: [total.planejadoSP, total.realSP, total.entregueSP, total.pendenteSP, total.canceladoSP], backgroundColor: '#3b82f6', borderWidth: 0 },
                    { label: 'Quantidade', data: [total.planejadoQtd, total.realQtd, total.entregueQtd, total.pendenteQtd, total.canceladoQtd], backgroundColor: '#22c55e', borderWidth: 0 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#94a3b8', boxWidth: 12 } } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } }
                }
            }
        });
    }

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroDebito = { render };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = { render }; }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroDebito = { render };
    }
})();
