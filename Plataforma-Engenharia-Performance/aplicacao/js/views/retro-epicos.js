/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Situação dos Épicos (`js/views/retro-epicos.js`)
 *
 * Task 17.3 (spec score-performance-executivo). Implementa o Requirement 12 e a
 * seção de design "Módulo de Retro PI → Painéis → Situação dos Épicos":
 *
 *   - Contagem de Épicos em cada situação: Atrasado, Replanejado, Em Risco,
 *     Em Dia, Finalizado, Despriorizado.                              (12.1)
 *   - Contagens separadas entre Baseline e Mudança de Escopo.         (12.2)
 *   - Listagem dos Épicos de uma situação selecionada.                (12.3)
 *   - Cada Épico em exatamente uma situação (partição — garantida pela
 *     agregação `situacaoEpicos`).                                    (12.4)
 *
 * Registro: `window.PEPViews.retroEpicos = { render }`.
 * Dependências: RetroAggregation (retro-aggregation.js), PEPRetroData
 * (retro-data.js), Chart (CDN). App estático, tema escuro. CSS: prefixo `.rep-*`.
 */

(function () {
    'use strict';

    const charts = { barras: null };

    // Situação selecionada para listagem (estado interno do painel).
    let situacaoSel = null;

    const SITUACOES = ['atrasado', 'replanejado', 'em-risco', 'em-dia', 'finalizado', 'despriorizado'];
    const SIT_LABEL = {
        atrasado: 'Atrasado',
        replanejado: 'Replanejado',
        'em-risco': 'Em Risco',
        'em-dia': 'Em Dia',
        finalizado: 'Finalizado',
        despriorizado: 'Despriorizado'
    };
    const SIT_COLORS = {
        atrasado: '#ef4444',       // vermelho
        replanejado: '#f59e0b',    // laranja
        'em-risco': '#eab308',     // amarelo
        'em-dia': '#3b82f6',       // azul
        finalizado: '#22c55e',     // verde
        despriorizado: '#64748b'   // cinza
    };

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

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);
        const jornadaFiltro = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : undefined;
        const squadFiltro = (filter.squad != null && filter.squad !== 'all') ? filter.squad : undefined;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');

        if (!RetroAggregation || typeof RetroAggregation.situacaoEpicos !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        destroyCharts();

        let agg;
        try {
            agg = RetroAggregation.situacaoEpicos(pi, { jornada: jornadaFiltro, squad: squadFiltro });
        } catch (e) {
            container.innerHTML = errorBlock('Falha ao agregar a Situação dos Épicos.');
            return;
        }

        const baseline = (agg && agg.baseline) ? agg.baseline : {};
        const mudanca = (agg && agg.mudancaEscopo) ? agg.mudancaEscopo : {};
        // Escopo exibido reflete o filtro ativo (squad > jornada > todas).
        const jornada = squadFiltro || jornadaFiltro || 'Todas as Jornadas';

        const totalBaseline = SITUACOES.reduce((a, s) => a + (baseline[s] || 0), 0);
        const totalMudanca = SITUACOES.reduce((a, s) => a + (mudanca[s] || 0), 0);

        // Lista de épicos da situação selecionada (origem baseline por padrão).
        const lista = (situacaoSel && typeof agg.listaPorSituacao === 'function')
            ? agg.listaPorSituacao(situacaoSel, null)
            : [];

        container.innerHTML = buildShell({
            pi, jornada, baseline, mudanca, totalBaseline, totalMudanca, lista
        });

        try {
            drawBarras(container, { baseline, mudanca });
        } catch (e) {
            console.error('retro-epicos barras:', e);
        }

        wireControls(container, opts);
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rep">
            <div class="rep-head">
                <div class="rep-head-left">
                    <h2 class="rep-title">🗂️ Situação dos Épicos</h2>
                    <span class="rep-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rep-pi">PI: ${piLabel}</span>
            </div>

            <div class="rep-cards">
                ${SITUACOES.map(s => cardSituacao(s, m.baseline[s] || 0, m.mudanca[s] || 0)).join('')}
            </div>

            <div class="rep-chart-card">
                <h3 class="rep-card-title">Contagem por situação — Baseline vs Mudança de Escopo</h3>
                <div class="rep-chart-wrap">
                    <canvas id="rep-barras-canvas"></canvas>
                </div>
                <div class="rep-totais">
                    <span>Total Baseline: <strong>${m.totalBaseline}</strong></span>
                    <span>Total Mudança de Escopo: <strong>${m.totalMudanca}</strong></span>
                </div>
            </div>

            ${buildLista(m.lista)}
        </div>`;
    }

    // Cartão clicável por situação (seleciona para listar os épicos).
    function cardSituacao(s, baseline, mudanca) {
        const ativo = (situacaoSel === s) ? ' rep-card-active' : '';
        return `
        <button type="button" class="rep-card${ativo}" data-situacao="${esc(s)}"
            style="border-left-color:${SIT_COLORS[s]}" aria-pressed="${situacaoSel === s ? 'true' : 'false'}">
            <span class="rep-card-dot" style="background:${SIT_COLORS[s]}"></span>
            <span class="rep-card-name">${esc(SIT_LABEL[s])}</span>
            <span class="rep-card-count">${baseline}</span>
            <span class="rep-card-sub">Mud. escopo: ${mudanca}</span>
        </button>`;
    }

    // Lista de épicos da situação selecionada (Req 12.3).
    function buildLista(lista) {
        if (!situacaoSel) {
            return `
            <div class="rep-lista rep-lista-empty">
                <p class="rep-lista-hint">Selecione uma situação acima para listar os épicos.</p>
            </div>`;
        }
        if (!lista || lista.length === 0) {
            return `
            <div class="rep-lista">
                <h3 class="rep-lista-title">${esc(SIT_LABEL[situacaoSel])} — nenhum épico detalhado</h3>
            </div>`;
        }
        const items = lista.map(e => `
            <li class="rep-lista-item">
                <span class="rep-lista-chave">${esc(e.chave)}</span>
                <span class="rep-lista-origem">${esc(e.origem === 'mudanca-escopo' ? 'Mudança de Escopo' : 'Baseline')}</span>
            </li>`).join('');
        return `
        <div class="rep-lista">
            <h3 class="rep-lista-title">Épicos — ${esc(SIT_LABEL[situacaoSel])} (${lista.length})</h3>
            <ul class="rep-lista-ul">${items}</ul>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="rep">
            <div class="rep-neutral">
                <div class="rep-neutral-icon" aria-hidden="true">⚠️</div>
                <p class="rep-neutral-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // EVENTOS — seleção de situação para listar (Req 12.3)
    // =========================================================================
    function wireControls(container, opts) {
        if (!container || typeof container.querySelectorAll !== 'function') return;
        const cards = container.querySelectorAll('.rep-card');
        cards.forEach(card => {
            if (typeof card.addEventListener !== 'function') return;
            card.addEventListener('click', () => {
                const s = card.dataset ? card.dataset.situacao : null;
                situacaoSel = (situacaoSel === s) ? null : s; // toggle
                render(container, opts || {});
            });
        });
    }

    // =========================================================================
    // CHART — barras agrupadas por situação (Baseline vs Mudança de Escopo).
    // =========================================================================
    function destroyCharts() {
        if (charts.barras) { try { charts.barras.destroy(); } catch (e) {} charts.barras = null; }
    }

    function drawBarras(container, m) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rep-barras-canvas');
        if (!canvas) return;

        const labels = SITUACOES.map(s => SIT_LABEL[s]);
        charts.barras = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Baseline',
                        data: SITUACOES.map(s => m.baseline[s] || 0),
                        backgroundColor: SITUACOES.map(s => SIT_COLORS[s]),
                        borderWidth: 0
                    },
                    {
                        label: 'Mudança de Escopo',
                        data: SITUACOES.map(s => m.mudanca[s] || 0),
                        backgroundColor: 'rgba(15,23,42,0.25)',
                        borderWidth: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', boxWidth: 12 } },
                    tooltip: {
                        callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y} épico(s)` }
                    }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8', precision: 0 },
                        grid: { color: 'rgba(15,23,42,0.08)' }
                    }
                }
            }
        });
    }

    // =========================================================================
    // REGISTRO DA VISÃO
    // =========================================================================
    const api = { render, _resetState: () => { situacaoSel = null; } };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroEpicos = { render };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroEpicos = { render };
    }
})();
