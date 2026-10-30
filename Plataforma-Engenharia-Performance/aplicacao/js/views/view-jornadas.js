/**
 * PEP - Plataforma de Engenharia de Performance
 * Visão de Jornadas (`js/views/view-jornadas.js`)
 *
 * Task 9.2 (spec score-performance-executivo). Implementa o Requirement 4 (e o
 * 2.3) e a seção de design "Components and Interfaces → 6. Visões executivas →
 * view-jornadas":
 *
 *   - Card por Jornada com:
 *       • gauge de score (0–1000);                                        (4.2)
 *       • rótulo da Faixa de Performance (Baixa/Média/Boa/Ótima);         (4.2)
 *       • % de meta versus realizado (atingimento);                       (4.2)
 *       • Farol (Na meta / Atenção / Crítico) por Atingimento;            (4.3)
 *       • delta de score vs mês anterior;                                 (4.4)
 *       • detalhamento "X de Y pontos" por categoria (as 4 categorias).   (4.5)
 *   - Score de cada Jornada = média dos scores dos seus Times.            (4.1)
 *   - Indica visualmente a AUSÊNCIA de dado da jornada no mês.            (2.3)
 *
 * Registro: `window.PEPViews.jornadas = { render }`. O `app.js` chama
 * `render(container, { month })` ao trocar mês/aba e isola o render em
 * try/catch; ainda assim, protegemos internamente para não derrubar outras
 * visões (cada gauge é desenhado sob guarda própria).
 *
 * Reutiliza os módulos já implementados (globais via <script>): ScoringEngine,
 * Aggregation, Targets, PEPData e Chart.js (CDN). App estático, tema escuro.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Instâncias de Chart.js vivas por jornada (destruídas antes de recriar,
    // para evitar vazamento ao re-renderizar na troca de mês/aba).
    // -------------------------------------------------------------------------
    let gauges = [];

    // Cores das 4 categorias (consistentes com a Visão Geral / tema escuro).
    const CATEGORY_COLORS = {
        processos: '#3b82f6', // accent-blue
        praticas:  '#22c55e', // accent-green
        produto:   '#8b5cf6', // accent-purple
        pessoas:   '#f59e0b'  // accent-orange
    };

    const CATEGORY_ORDER = ['processos', 'praticas', 'produto', 'pessoas'];

    const FAROL_META = {
        'na-meta': { label: 'Na meta', color: '#22c55e' },
        'atencao': { label: 'Atenção', color: '#f59e0b' },
        'critico': { label: 'Crítico', color: '#ef4444' }
    };

    // -------------------------------------------------------------------------
    // Helpers de dependência (guarda defensiva; não quebra se faltar algo).
    // No browser os módulos existem como globais; em Node (teste) resolvemos via
    // require() relativo sob guarda de ambiente.
    // -------------------------------------------------------------------------
    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                ScoringEngine: '../scoring-engine.js',
                Aggregation: '../aggregation.js',
                Targets: '../targets.js',
                PEPData: '../data.js'
            }[name];
            if (path) { try { return require(path); } catch (e) { /* ignore */ } }
        }
        return undefined;
    }

    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const fmtScore = (n) => (typeof n === 'number' && isFinite(n)) ? n.toFixed(0) : '—';
    const fmtPct = (ratio) => (typeof ratio === 'number' && isFinite(ratio))
        ? `${(ratio * 100).toFixed(1)}%` : 'Indisponível';
    const fmtDelta = (d) => {
        if (d === null || d === undefined || !isFinite(d)) return 'Indisponível';
        const s = d > 0 ? '+' : '';
        return `${s}${d.toFixed(0)} pts`;
    };

    // -------------------------------------------------------------------------
    // Atingimento (% de meta vs realizado) de uma jornada no mês (Req. 4.2/4.3).
    // Usa Targets.atingimento (realizado ÷ metaMes). Retorna null quando a meta
    // é indisponível (ex.: jornada sem baseline) ou quando não há realizado.
    // -------------------------------------------------------------------------
    function journeyAtingimento(month, journeyName, realizado) {
        const Targets = dep('Targets');
        if (!Targets || typeof realizado !== 'number' || !isFinite(realizado)) return null;
        try {
            return Targets.atingimento(journeyName, month, realizado);
        } catch (e) {
            return null;
        }
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const month = (opts && typeof opts.month === 'number') ? opts.month : null;

        const Aggregation = dep('Aggregation');
        const ScoringEngine = dep('ScoringEngine');
        const PEPData = dep('PEPData');
        const Targets = dep('Targets');

        if (!Aggregation || !ScoringEngine || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        // Destrói gauges anteriores antes de recriar o DOM.
        destroyGauges();

        const monthLabel = (Array.isArray(PEPData.MONTH_LABELS) && month)
            ? PEPData.MONTH_LABELS[month - 1] : (month != null ? `Mês ${month}` : '—');

        const jornadas = PEPData.JORNADAS || [];
        const baseMax = ScoringEngine.BASE_MAX_SCORE || 1000;

        // Monta o modelo de cada card (uma jornada por card).
        const cards = jornadas.map((name) => {
            const js = Aggregation.journeyScore(month, name);

            if (!js || !js.hasData) {
                return { journey: name, hasData: false };
            }

            const band = js.performanceBand || ScoringEngine.getPerformanceBand(js.avgScore);
            const at = journeyAtingimento(month, name, js.avgScore);
            const farolId = Targets ? Targets.farol(at) : null;
            const delta = Aggregation.monthDelta(month, name);

            return {
                journey: name,
                hasData: true,
                avgScore: js.avgScore,
                band,
                atingimento: at,
                farolId,
                delta,
                categoryScores: js.categoryScores,
                teamCount: Array.isArray(js.teams) ? js.teams.length : 0
            };
        });

        container.innerHTML = buildHTML({ monthLabel, baseMax, cards });

        // Desenha os gauges por jornada (apenas as com dado) com guarda — a
        // falha de um gauge não impede os demais nem o restante da visão.
        cards.forEach((card, idx) => {
            if (!card.hasData) return;
            try {
                drawGauge(container, `vj-gauge-${idx}`, card.avgScore, baseMax, card.band);
            } catch (e) {
                console.error('view-jornadas gauge:', e);
            }
        });
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        const cardsHTML = m.cards.map((c, idx) => c.hasData
            ? dataCard(c, idx, m.baseMax)
            : emptyCard(c)).join('');

        return `
        <div class="vj">
            <div class="vj-head">
                <h2 class="vj-title">🧭 Jornadas</h2>
                <span class="vj-month">${esc(m.monthLabel)}</span>
            </div>
            <div class="vj-grid">
                ${cardsHTML}
            </div>
        </div>`;
    }

    function dataCard(c, idx, baseMax) {
        const bandName = c.band ? c.band.name : '—';
        const bandColor = c.band ? c.band.color : 'var(--text-muted)';

        const farol = c.farolId ? FAROL_META[c.farolId] : null;
        const farolPill = farol
            ? `<span class="vj-farol" style="border-color:${farol.color};color:${farol.color}">
                   <span class="vj-farol-dot" style="background:${farol.color}"></span>${farol.label}
               </span>`
            : `<span class="vj-farol vj-muted" style="border-color:var(--border-color)">
                   <span class="vj-farol-dot" style="background:var(--text-muted)"></span>Indisponível
               </span>`;

        const deltaClass = (typeof c.delta === 'number' && isFinite(c.delta))
            ? (c.delta > 0 ? 'vj-up' : (c.delta < 0 ? 'vj-down' : 'vj-muted'))
            : 'vj-muted';

        return `
        <div class="vj-card">
            <div class="vj-card-head">
                <h3 class="vj-journey" title="${esc(c.journey)}">${esc(c.journey)}</h3>
                ${farolPill}
            </div>

            <div class="vj-gauge-wrap">
                <canvas id="vj-gauge-${idx}"></canvas>
            </div>

            <div class="vj-band" style="color:${bandColor}">
                <span class="vj-band-dot" style="background:${bandColor}"></span>
                ${esc(bandName)}
            </div>

            <div class="vj-kpis">
                <div class="vj-kpi">
                    <span class="vj-kpi-label">Meta vs realizado</span>
                    <span class="vj-kpi-value">${fmtPct(c.atingimento)}</span>
                </div>
                <div class="vj-kpi">
                    <span class="vj-kpi-label">Δ vs mês anterior</span>
                    <span class="vj-kpi-value ${deltaClass}">${esc(fmtDelta(c.delta))}</span>
                </div>
            </div>

            ${categoryBreakdown(c.categoryScores)}

            <div class="vj-teams">${c.teamCount} time(s) com dado</div>
        </div>`;
    }

    // Detalhamento "X de Y pontos" por categoria (Requirement 4.5).
    function categoryBreakdown(categoryScores) {
        const ScoringEngine = dep('ScoringEngine');
        const cats = (ScoringEngine && ScoringEngine.CATEGORIES) ? ScoringEngine.CATEGORIES : {
            processos: { name: 'Processos', maxScore: 450 },
            praticas:  { name: 'Práticas',  maxScore: 450 },
            produto:   { name: 'Produto',   maxScore: 50 },
            pessoas:   { name: 'Pessoas',   maxScore: 50 }
        };

        let rows = '';
        for (const id of CATEGORY_ORDER) {
            const c = cats[id] || {};
            const val = (categoryScores && typeof categoryScores[id] === 'number') ? categoryScores[id] : 0;
            const max = (c.maxScore != null) ? c.maxScore : 0;
            const pct = max > 0 ? Math.max(0, Math.min(100, (val / max) * 100)) : 0;
            rows += `
            <div class="vj-cat">
                <div class="vj-cat-head">
                    <span class="vj-cat-dot" style="background:${CATEGORY_COLORS[id]}"></span>
                    <span class="vj-cat-name">${esc(c.name || id)}</span>
                    <span class="vj-cat-val">${val.toFixed(0)} de ${max} pontos</span>
                </div>
                <div class="vj-cat-bar">
                    <div class="vj-cat-fill" style="width:${pct.toFixed(1)}%;background:${CATEGORY_COLORS[id]}"></div>
                </div>
            </div>`;
        }
        return `<div class="vj-cats">${rows}</div>`;
    }

    // Estado "sem dado" de uma jornada no mês (Requirement 2.3).
    function emptyCard(c) {
        return `
        <div class="vj-card vj-card-empty">
            <div class="vj-card-head">
                <h3 class="vj-journey" title="${esc(c.journey)}">${esc(c.journey)}</h3>
                <span class="vj-farol vj-muted" style="border-color:var(--border-color)">
                    <span class="vj-farol-dot" style="background:var(--text-muted)"></span>Sem dado
                </span>
            </div>
            <div class="vj-empty">
                <div class="vj-empty-icon" aria-hidden="true">🗓️</div>
                <p class="vj-empty-text">Sem dado para esta jornada no mês selecionado.</p>
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="vj">
            <div class="vj-empty">
                <div class="vj-empty-icon" aria-hidden="true">⚠️</div>
                <p class="vj-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // CHARTS (Chart.js) — gauge por jornada
    // =========================================================================
    function destroyGauges() {
        for (const ch of gauges) {
            try { ch.destroy(); } catch (e) { /* ignore */ }
        }
        gauges = [];
    }

    function drawGauge(container, canvasId, score, maxScore, band) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#' + canvasId);
        if (!canvas) return;

        const color = (band && band.color) ? band.color : '#3b82f6';
        const clamped = Math.max(0, Math.min(score, maxScore));

        const chart = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ['Score', 'Restante'],
                datasets: [{
                    data: [clamped, Math.max(0, maxScore - clamped)],
                    backgroundColor: [color, 'rgba(15,23,42,0.08)'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                circumference: 270,
                rotation: -135,
                cutout: '72%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            },
            plugins: [{
                id: 'vjGaugeText',
                afterDraw: (c) => {
                    const { ctx, width, height } = c;
                    const cssV = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (cssV && cssV.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (cssV && cssV.getPropertyValue('--text-muted').trim()) || '#64748b';
                    ctx.save();
                    ctx.font = 'bold 26px Inter, sans-serif';
                    ctx.fillStyle = colorPrimary;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(fmtScore(score), width / 2, height / 2 + 6);
                    ctx.font = '11px Inter, sans-serif';
                    ctx.fillStyle = colorMuted;
                    ctx.fillText(`de ${maxScore} pts`, width / 2, height / 2 + 28);
                    ctx.restore();
                }
            }]
        });
        gauges.push(chart);
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = { render, buildHTML, categoryBreakdown, journeyAtingimento };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.jornadas = { render };
    }

    // Guarda de ambiente: expõe para testes (Vitest) sem afetar o <script>.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
