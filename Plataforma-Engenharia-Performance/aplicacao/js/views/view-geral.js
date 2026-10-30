/**
 * PEP - Plataforma de Engenharia de Performance
 * Visão Geral Executiva (`js/views/view-geral.js`)
 *
 * Task 9.1 (spec score-performance-executivo). Implementa o Requirement 3 e a
 * seção de design "Components and Interfaces → 6. Visões executivas →
 * view-geral":
 *
 *   - Gauge 0–1000 do score total de Engenharia (média das jornadas). (3.1, 3.2)
 *   - Rótulo da Faixa de Performance (Baixa/Média/Boa/Ótima).          (3.3)
 *   - % de atingimento da meta do mês para Engenharia.                 (3.4)
 *   - Contagem de jornadas por Farol (Na meta / Atenção / Crítico).    (3.5)
 *   - Destaques maior alta e maior queda de jornada vs mês anterior.   (3.6)
 *   - Donut com a distribuição de pontos entre as 4 categorias.        (3.7)
 *   - Primeiro mês com dados: indicadores numéricos de variação exibidos
 *     como INDISPONÍVEIS; destaques de maior alta/queda ficam vazios.  (3.8)
 *   - Ausência de dado no mês: estado neutro.
 *
 * Registro: `window.PEPViews.geral = { render }`. O `app.js` chama
 * `render(container, { month })` ao trocar mês/aba. O render é isolado em
 * try/catch pelo próprio app.js; ainda assim, protegemos internamente para não
 * derrubar outras visões.
 *
 * Dependências (globais via <script>): ScoringEngine, Aggregation, Targets,
 * PEPData, Chart (CDN). O app é estático e o tema é escuro.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Instâncias de Chart.js vivas (destruídas antes de recriar, para evitar
    // vazamento ao re-renderizar na troca de mês/aba).
    // -------------------------------------------------------------------------
    const charts = { gauge: null, donut: null };

    // Cores das 4 categorias (consistentes com o tema escuro).
    const CATEGORY_COLORS = {
        processos: '#3b82f6', // accent-blue
        praticas:  '#22c55e', // accent-green
        produto:   '#8b5cf6', // accent-purple
        pessoas:   '#f59e0b'  // accent-orange
    };

    const FAROL_META = {
        'na-meta': { label: 'Na meta', color: '#22c55e' },
        'atencao': { label: 'Atenção', color: '#f59e0b' },
        'critico': { label: 'Crítico', color: '#ef4444' }
    };

    // -------------------------------------------------------------------------
    // Helpers de dependência (guarda defensiva; não quebra se faltar algo).
    // -------------------------------------------------------------------------
    function dep(name) {
        return (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
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
    // Determina se o mês selecionado é o PRIMEIRO mês com dados (Requirement
    // 3.8). Usamos a lista real de meses com dado (dataset esparso).
    // -------------------------------------------------------------------------
    function isFirstMonthWithData(month) {
        const PEPData = dep('PEPData');
        if (!PEPData || typeof PEPData.getMonthsWithData !== 'function') return false;
        const months = PEPData.getMonthsWithData() || [];
        if (months.length === 0) return false;
        return month === months[0];
    }

    // -------------------------------------------------------------------------
    // Atingimento da meta do mês para ENGENHARIA (Requirement 3.4).
    // A meta de Engenharia não está definida explicitamente nos dados; conforme
    // orientação da tarefa, derivamos de forma consistente com o realizado:
    // o realizado de Engenharia é a MÉDIA das jornadas (Aggregation), então a
    // meta de Engenharia é a MÉDIA das metas do mês das jornadas (Targets.metaMes).
    // Atingimento = realizado médio ÷ meta média. Usa Targets.farol como fonte
    // única de classificação. Retorna null quando indisponível.
    // -------------------------------------------------------------------------
    function engineeringAtingimento(month, engScore) {
        const Targets = dep('Targets');
        const PEPData = dep('PEPData');
        if (!Targets || !PEPData || !engScore || !engScore.hasData) return null;

        const jornadas = PEPData.JORNADAS || [];
        const metas = [];
        for (const name of jornadas) {
            try {
                const meta = Targets.metaMes(name, month);
                if (typeof meta === 'number' && isFinite(meta) && meta > 0) metas.push(meta);
            } catch (e) { /* jornada sem baseline → ignora */ }
        }
        if (metas.length === 0) return null;

        const metaMedia = metas.reduce((a, b) => a + b, 0) / metas.length;
        if (!(metaMedia > 0)) return null;
        return engScore.avgScore / metaMedia;
    }

    // -------------------------------------------------------------------------
    // Contagem de jornadas por Farol (Requirement 3.5).
    // Para cada jornada COM DADO no mês, calcula o atingimento (realizado ÷
    // metaMes) e classifica via Targets.farol. A soma das três contagens = nº
    // de jornadas com dado e atingimento disponível (Property 9).
    // -------------------------------------------------------------------------
    function farolCounts(month, engScore) {
        const Targets = dep('Targets');
        const counts = { 'na-meta': 0, 'atencao': 0, 'critico': 0, total: 0 };
        if (!Targets || !engScore || !engScore.hasData) return counts;

        for (const j of engScore.journeys) {
            let at = null;
            try { at = Targets.atingimento(j.journey, month, j.avgScore); } catch (e) { at = null; }
            const f = Targets.farol(at);
            if (f && Object.prototype.hasOwnProperty.call(counts, f)) {
                counts[f] += 1;
                counts.total += 1;
            }
        }
        return counts;
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

        if (!Aggregation || !ScoringEngine) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        // Destrói charts anteriores antes de recriar o DOM.
        destroyCharts();

        const monthLabel = (PEPData && Array.isArray(PEPData.MONTH_LABELS) && month)
            ? PEPData.MONTH_LABELS[month - 1] : (month != null ? `Mês ${month}` : '—');

        const engScore = Aggregation.engineeringScore(month);

        // --- Estado neutro: sem dado no mês ---
        if (!engScore || !engScore.hasData) {
            container.innerHTML = neutralBlock(monthLabel);
            return;
        }

        const band = engScore.performanceBand || ScoringEngine.getPerformanceBand(engScore.avgScore);
        const baseMax = ScoringEngine.BASE_MAX_SCORE || 1000;

        const firstMonth = isFirstMonthWithData(month);
        // Variação numérica vs mês anterior (Requirement 3.8): indisponível no 1º mês.
        const delta = firstMonth ? null : Aggregation.monthDelta(month, null);
        // Destaques (Requirement 3.6/3.8): vazios quando não há mês anterior.
        const hi = Aggregation.highlights(month);

        const at = engineeringAtingimento(month, engScore);
        const counts = farolCounts(month, engScore);

        // Monta o HTML (estrutura; gráficos são desenhados após inserir no DOM).
        container.innerHTML = buildHTML({
            monthLabel,
            avgScore: engScore.avgScore,
            baseMax,
            band,
            atingimento: at,
            delta,
            firstMonth,
            counts,
            highlights: hi,
            categoryScores: engScore.categoryScores
        });

        // Desenha os gráficos (gauge + donut) com guarda — falha de um gráfico
        // não deve impedir o restante da visão.
        try { drawGauge(container, engScore.avgScore, baseMax, band); } catch (e) { console.error('view-geral gauge:', e); }
        try { drawDonut(container, engScore.categoryScores); } catch (e) { console.error('view-geral donut:', e); }
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        const bandName = m.band ? m.band.name : '—';
        const bandColor = m.band ? m.band.color : 'var(--text-muted)';

        const deltaText = m.firstMonth ? 'Indisponível' : fmtDelta(m.delta);
        const deltaClass = (!m.firstMonth && typeof m.delta === 'number' && isFinite(m.delta))
            ? (m.delta > 0 ? 'vg-up' : (m.delta < 0 ? 'vg-down' : '')) : 'vg-muted';

        return `
        <div class="vg">
            <div class="vg-head">
                <h2 class="vg-title">📊 Visão Geral — Engenharia</h2>
                <span class="vg-month">${esc(m.monthLabel)}</span>
            </div>

            <div class="vg-grid">
                <!-- Gauge do score de Engenharia -->
                <div class="vg-card vg-gauge-card">
                    <h3>Score de Engenharia</h3>
                    <div class="vg-gauge-wrap">
                        <canvas id="vg-gauge-canvas"></canvas>
                    </div>
                    <div class="vg-band" style="color:${bandColor}">
                        <span class="vg-band-dot" style="background:${bandColor}"></span>
                        ${esc(bandName)}
                    </div>
                </div>

                <!-- Indicadores numéricos -->
                <div class="vg-card vg-kpis">
                    <div class="vg-kpi">
                        <div class="vg-kpi-label">Atingimento da meta do mês</div>
                        <div class="vg-kpi-value">${fmtPct(m.atingimento)}</div>
                        <div class="vg-kpi-sub">Engenharia (média das jornadas)</div>
                    </div>
                    <div class="vg-kpi">
                        <div class="vg-kpi-label">Variação vs mês anterior</div>
                        <div class="vg-kpi-value ${deltaClass}">${esc(deltaText)}</div>
                        <div class="vg-kpi-sub">${m.firstMonth ? 'Primeiro mês com dados' : 'Delta de score total'}</div>
                    </div>
                </div>

                <!-- Contagem de jornadas por Farol -->
                <div class="vg-card vg-farol">
                    <h3>Jornadas por Farol</h3>
                    <div class="vg-farol-row">
                        ${farolPill('na-meta', m.counts['na-meta'])}
                        ${farolPill('atencao', m.counts['atencao'])}
                        ${farolPill('critico', m.counts['critico'])}
                    </div>
                    <div class="vg-farol-total">${m.counts.total} jornada(s) com atingimento</div>
                </div>

                <!-- Destaques: maior alta / maior queda -->
                <div class="vg-card vg-highlights">
                    <h3>Destaques vs mês anterior</h3>
                    ${highlightRow('▲ Maior alta', m.highlights ? m.highlights.topRise : null, 'vg-up')}
                    ${highlightRow('▼ Maior queda', m.highlights ? m.highlights.topFall : null, 'vg-down')}
                    ${(!m.highlights || (!m.highlights.topRise && !m.highlights.topFall))
                        ? `<div class="vg-highlight-empty">Sem destaques (sem mês anterior com dados).</div>` : ''}
                </div>

                <!-- Donut das 4 categorias -->
                <div class="vg-card vg-donut-card">
                    <h3>Distribuição por categoria</h3>
                    <div class="vg-donut-wrap">
                        <canvas id="vg-donut-canvas"></canvas>
                    </div>
                    ${categoryLegend(m.categoryScores)}
                </div>
            </div>
        </div>`;
    }

    function farolPill(id, count) {
        const meta = FAROL_META[id];
        return `
        <div class="vg-farol-pill" style="border-color:${meta.color}">
            <span class="vg-farol-dot" style="background:${meta.color}"></span>
            <span class="vg-farol-count">${count}</span>
            <span class="vg-farol-name">${meta.label}</span>
        </div>`;
    }

    function highlightRow(label, item, cls) {
        if (!item) {
            return `
            <div class="vg-highlight">
                <span class="vg-highlight-label">${label}</span>
                <span class="vg-highlight-val vg-muted">—</span>
            </div>`;
        }
        return `
        <div class="vg-highlight">
            <span class="vg-highlight-label">${label}</span>
            <span class="vg-highlight-val ${cls}">
                ${esc(item.journey)} <strong>${fmtDelta(item.delta)}</strong>
            </span>
        </div>`;
    }

    function categoryLegend(categoryScores) {
        const ScoringEngine = dep('ScoringEngine');
        const cats = (ScoringEngine && ScoringEngine.CATEGORIES) ? ScoringEngine.CATEGORIES : {
            processos: { name: 'Processos', maxScore: 450 },
            praticas:  { name: 'Práticas',  maxScore: 450 },
            produto:   { name: 'Produto',   maxScore: 50 },
            pessoas:   { name: 'Pessoas',   maxScore: 50 }
        };
        const order = ['processos', 'praticas', 'produto', 'pessoas'];
        let rows = '';
        for (const id of order) {
            const c = cats[id] || {};
            const val = (categoryScores && typeof categoryScores[id] === 'number') ? categoryScores[id] : 0;
            rows += `
            <div class="vg-leg-item">
                <span class="vg-leg-dot" style="background:${CATEGORY_COLORS[id]}"></span>
                <span class="vg-leg-name">${esc(c.name || id)}</span>
                <span class="vg-leg-val">${val.toFixed(0)} / ${c.maxScore ?? '—'} pts</span>
            </div>`;
        }
        return `<div class="vg-legend">${rows}</div>`;
    }

    function neutralBlock(monthLabel) {
        return `
        <div class="vg">
            <div class="vg-head">
                <h2 class="vg-title">📊 Visão Geral — Engenharia</h2>
                <span class="vg-month">${esc(monthLabel)}</span>
            </div>
            <div class="vg-neutral">
                <div class="vg-neutral-icon" aria-hidden="true">🗓️</div>
                <p class="vg-neutral-text">Sem dados para o mês selecionado.</p>
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="vg">
            <div class="vg-neutral">
                <div class="vg-neutral-icon" aria-hidden="true">⚠️</div>
                <p class="vg-neutral-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // CHARTS (Chart.js)
    // =========================================================================
    function destroyCharts() {
        if (charts.gauge) { try { charts.gauge.destroy(); } catch (e) {} charts.gauge = null; }
        if (charts.donut) { try { charts.donut.destroy(); } catch (e) {} charts.donut = null; }
    }

    function drawGauge(container, score, maxScore, band) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#vg-gauge-canvas');
        if (!canvas) return;

        const color = (band && band.color) ? band.color : '#3b82f6';
        const clamped = Math.max(0, Math.min(score, maxScore));

        charts.gauge = new Chart(canvas, {
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
                cutout: '75%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            },
            plugins: [{
                id: 'vgGaugeText',
                afterDraw: (chart) => {
                    const { ctx, width, height } = chart;
                    // Cores do texto lidas do tema (variáveis CSS), para
                    // funcionar tanto no tema claro quanto no escuro.
                    const css = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (css && css.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (css && css.getPropertyValue('--text-muted').trim()) || '#64748b';
                    ctx.save();
                    ctx.font = 'bold 34px Inter, sans-serif';
                    ctx.fillStyle = colorPrimary;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(fmtScore(score), width / 2, height / 2 + 8);
                    ctx.font = '13px Inter, sans-serif';
                    ctx.fillStyle = colorMuted;
                    ctx.fillText(`de ${maxScore} pts`, width / 2, height / 2 + 36);
                    ctx.restore();
                }
            }]
        });
    }

    function drawDonut(container, categoryScores) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#vg-donut-canvas');
        if (!canvas) return;

        const order = ['processos', 'praticas', 'produto', 'pessoas'];
        const ScoringEngine = dep('ScoringEngine');
        const cats = (ScoringEngine && ScoringEngine.CATEGORIES) ? ScoringEngine.CATEGORIES : {};
        const labels = order.map(id => (cats[id] && cats[id].name) ? cats[id].name : id);
        const data = order.map(id => (categoryScores && typeof categoryScores[id] === 'number') ? categoryScores[id] : 0);
        const colors = order.map(id => CATEGORY_COLORS[id]);

        charts.donut = new Chart(canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.label}: ${Number(ctx.parsed).toFixed(0)} pts`
                        }
                    }
                }
            }
        });
    }

    // =========================================================================
    // REGISTRO DA VISÃO
    // =========================================================================
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.geral = { render };
    }
})();
