/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Produtividade (`js/views/retro-produtividade.js`)
 *
 * Task 17.2 (spec score-performance-executivo). Implementa o Requirement 11 e a
 * seção de design "Módulo de Retro PI → Painéis → Produtividade":
 *
 *   - Produtividade de cada Time por PI (pontos por integrante).       (11.1)
 *   - Média de produtividade da Jornada por PI.                        (11.2)
 *   - Alternância entre PI vigente e histórico acumulado de PIs.       (11.3)
 *   - Ausência de dado de um Time em um PI indicada visualmente.       (11.4)
 *
 * Registro: `window.PEPViews.retroProdutividade = { render }`.
 * Dependências: RetroAggregation (retro-aggregation.js), PEPRetroData
 * (retro-data.js), Chart (CDN). App estático, tema escuro. CSS: prefixo `.rpd-*`.
 */

(function () {
    'use strict';

    const charts = { linhas: null };

    // Modo de exibição: 'historico' (todos os PIs) ou 'vigente' (só o PI atual).
    let displayMode = 'historico';

    // Paleta para as séries de Time (ciclada se houver mais times que cores).
    const SERIE_COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'];
    const MEDIA_COLOR = '#e2e8f0'; // média da Jornada (destaque claro)

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
    const fmtProd = (n) => hasData(n) ? n.toFixed(1) : '—';

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const pi = (opts && opts.pi != null) ? opts.pi : null;

        if (opts && (opts.mode === 'historico' || opts.mode === 'vigente')) {
            displayMode = opts.mode;
        }

        // Filtro de Retro (jornada/squad). Sentinela "all" = sem recorte.
        const filter = (opts && opts.filter) ? opts.filter : {};
        const squadSel = (filter.squad != null && filter.squad !== 'all') ? filter.squad : null;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : null;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        const PEPIdentity = dep('PEPIdentity', '../identity.js');

        if (!RetroAggregation || typeof RetroAggregation.produtividadePorPI !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        // A produtividade é registrada por JORNADA (não há quebra por squad
        // individual nesta base). Quando o usuário filtra por Squad, resolvemos
        // a Jornada correspondente e exibimos a produtividade dessa Jornada,
        // deixando claro o escopo no rótulo (Squad · Jornada).
        let jornadaAlvo = jornadaSel;
        if (!jornadaAlvo && squadSel && PEPIdentity && typeof PEPIdentity.jornadaOf === 'function') {
            try { jornadaAlvo = PEPIdentity.jornadaOf(squadSel) || null; } catch (_) { jornadaAlvo = null; }
        }
        // Fallback: squad informado que já é um nome de jornada (compat.).
        if (!jornadaAlvo && squadSel) jornadaAlvo = squadSel;

        destroyCharts();

        let agg;
        try {
            // Passa a jornada-alvo ao agregador para restringir os Times/linhas.
            agg = RetroAggregation.produtividadePorPI(jornadaAlvo ? { jornada: jornadaAlvo } : {});
        } catch (e) {
            container.innerHTML = errorBlock('Falha ao agregar a Produtividade.');
            return;
        }

        const porTime = (agg && Array.isArray(agg.porTime)) ? agg.porTime : [];
        const mediaJornadaPorPI = (agg && agg.mediaJornadaPorPI) ? agg.mediaJornadaPorPI : {};

        // Escopo apresentado no cabeçalho: Squad · Jornada quando há Squad;
        // só a Jornada quando filtrada; "Todas as Jornadas" sem recorte.
        const semRecorte = !squadSel && !jornadaAlvo;
        const jornada = semRecorte
            ? 'Todas as Jornadas'
            : (squadSel && String(squadSel) !== String(jornadaAlvo)
                ? `${squadSel} · ${jornadaAlvo}`
                : jornadaAlvo);

        // PIs considerados conforme o modo (vigente = só o PI selecionado).
        const todosPIs = ordenarPIs(coletarPIs(porTime, mediaJornadaPorPI));
        const pisExibidos = (displayMode === 'vigente' && pi != null)
            ? todosPIs.filter(p => p === pi)
            : todosPIs;

        if (porTime.length === 0 || pisExibidos.length === 0) {
            container.innerHTML = buildShell({ pi, jornada, body: neutralBlock(pi) });
            wireControls(container, opts);
            return;
        }

        container.innerHTML = buildShell({
            pi, jornada,
            body: buildBody({ porTime, mediaJornadaPorPI, pis: pisExibidos, jornada })
        });

        try {
            drawLinhas(container, { porTime, mediaJornadaPorPI, pis: pisExibidos });
        } catch (e) {
            console.error('retro-produtividade linhas:', e);
        }

        wireControls(container, opts);
    }

    function coletarPIs(porTime, mediaJornadaPorPI) {
        const set = new Set(Object.keys(mediaJornadaPorPI || {}));
        porTime.forEach(t => Object.keys(t.porPI || {}).forEach(p => set.add(p)));
        return [...set];
    }

    // Ordena PIs no formato "PI a/bb" por ano e por número de PI.
    function ordenarPIs(pis) {
        const parse = (s) => {
            const m = /PI\s*(\d+)\s*\/\s*(\d+)/.exec(String(s));
            return m ? (parseInt(m[2], 10) * 10 + parseInt(m[1], 10)) : 0;
        };
        return [...pis].sort((a, b) => parse(a) - parse(b));
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        const histAtivo = displayMode === 'historico';
        return `
        <div class="rpd">
            <div class="rpd-head">
                <div class="rpd-head-left">
                    <h2 class="rpd-title">📈 Produtividade</h2>
                    <span class="rpd-sub">${esc(m.jornada)}</span>
                </div>
                <div class="rpd-head-right">
                    <span class="rpd-pi">PI: ${piLabel}</span>
                    <div class="rpd-mode" role="group" aria-label="Modo de exibição">
                        <button type="button" id="rpd-mode-hist"
                            class="rpd-mode-btn${histAtivo ? ' rpd-mode-active' : ''}"
                            aria-pressed="${histAtivo ? 'true' : 'false'}">Histórico</button>
                        <button type="button" id="rpd-mode-vig"
                            class="rpd-mode-btn${!histAtivo ? ' rpd-mode-active' : ''}"
                            aria-pressed="${!histAtivo ? 'true' : 'false'}">PI vigente</button>
                    </div>
                </div>
            </div>
            ${m.body}
        </div>`;
    }

    function buildBody(m) {
        return `
        <div class="rpd-chart-card">
            <h3 class="rpd-card-title">Pontos por integrante — por Time e média da Jornada</h3>
            <div class="rpd-chart-wrap">
                <canvas id="rpd-linhas-canvas"></canvas>
            </div>
        </div>
        ${buildTable(m)}`;
    }

    // Tabela: Time × PIs + linha de média da Jornada. Ausência = "Sem dado".
    function buildTable(m) {
        const head = `
            <tr>
                <th class="rpd-th-left">Time</th>
                ${m.pis.map(p => `<th>${esc(p)}</th>`).join('')}
            </tr>`;

        const rows = m.porTime.map(t => {
            const cells = m.pis.map(p => cellTd(t.porPI ? t.porPI[p] : null)).join('');
            return `<tr><td class="rpd-td-left">${esc(t.time)}</td>${cells}</tr>`;
        }).join('');

        const mediaCells = m.pis.map(p => cellTd(m.mediaJornadaPorPI[p])).join('');
        const mediaRow = `
            <tr class="rpd-row-media">
                <td class="rpd-td-left">Média da Jornada</td>
                ${mediaCells}
            </tr>`;

        return `
        <div class="rpd-table-card">
            <table class="rpd-table">
                <thead>${head}</thead>
                <tbody>${rows}${mediaRow}</tbody>
            </table>
        </div>`;
    }

    function cellTd(v) {
        if (!hasData(v)) {
            return `<td class="rpd-nodata" title="Sem dado neste PI">Sem dado</td>`;
        }
        return `<td>${esc(fmtProd(v))}</td>`;
    }

    function neutralBlock(pi) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        return `
        <div class="rpd-neutral">
            <div class="rpd-neutral-icon" aria-hidden="true">🗓️</div>
            <p class="rpd-neutral-text">Sem dados de Produtividade para o PI ${piLabel}.</p>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="rpd">
            <div class="rpd-neutral">
                <div class="rpd-neutral-icon" aria-hidden="true">⚠️</div>
                <p class="rpd-neutral-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // EVENTOS — alterna histórico / PI vigente (Req 11.3)
    // =========================================================================
    function wireControls(container, opts) {
        if (!container || typeof container.querySelector !== 'function') return;
        const btnHist = container.querySelector('#rpd-mode-hist');
        const btnVig = container.querySelector('#rpd-mode-vig');
        if (btnHist && typeof btnHist.addEventListener === 'function') {
            btnHist.addEventListener('click', () => { displayMode = 'historico'; render(container, opts || {}); });
        }
        if (btnVig && typeof btnVig.addEventListener === 'function') {
            btnVig.addEventListener('click', () => { displayMode = 'vigente'; render(container, opts || {}); });
        }
    }

    // =========================================================================
    // CHART — linhas: uma série por Time + a média da Jornada (destacada).
    // =========================================================================
    function destroyCharts() {
        if (charts.linhas) { try { charts.linhas.destroy(); } catch (e) {} charts.linhas = null; }
    }

    function drawLinhas(container, m) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rpd-linhas-canvas');
        if (!canvas) return;

        const labels = m.pis;
        const datasets = m.porTime.map((t, i) => ({
            label: t.time,
            data: m.pis.map(p => hasData(t.porPI ? t.porPI[p] : null) ? t.porPI[p] : null),
            borderColor: SERIE_COLORS[i % SERIE_COLORS.length],
            backgroundColor: SERIE_COLORS[i % SERIE_COLORS.length],
            borderWidth: 2,
            tension: 0.25,
            spanGaps: false
        }));
        datasets.push({
            label: 'Média da Jornada',
            data: m.pis.map(p => hasData(m.mediaJornadaPorPI[p]) ? m.mediaJornadaPorPI[p] : null),
            borderColor: MEDIA_COLOR,
            backgroundColor: MEDIA_COLOR,
            borderWidth: 3,
            borderDash: [6, 4],
            tension: 0.25,
            spanGaps: false
        });

        charts.linhas = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const v = ctx.parsed.y;
                                return `${ctx.dataset.label}: ${(v == null || !isFinite(v)) ? 'Sem dado' : v.toFixed(1)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'pontos / integrante', color: '#94a3b8' },
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(15,23,42,0.08)' }
                    }
                }
            }
        });
    }

    // =========================================================================
    // REGISTRO DA VISÃO
    // =========================================================================
    const api = { render, ordenarPIs, _resetState: () => { displayMode = 'historico'; } };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroProdutividade = { render };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroProdutividade = { render };
    }
})();
