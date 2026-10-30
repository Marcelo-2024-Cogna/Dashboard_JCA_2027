/**
 * PEP - Plataforma de Engenharia de Performance
 * Visão de Métricas por Jornada (`js/views/view-metricas.js`)
 *
 * Task 9.3 (spec score-performance-executivo). Implementa o Requirement 5 e a
 * seção de design "Components and Interfaces → 6. Visões executivas →
 * view-metricas":
 *
 *   - Seletor de Jornada → ao escolher uma Jornada, exibir as 12 métricas da
 *     Jornada AGRUPADAS pelas 4 categorias (Processos, Práticas, Produto,
 *     Pessoas).                                                            (5.1)
 *   - Para cada métrica:
 *       • gauge com o VALOR ATUAL (média dos times da jornada p/ a métrica);
 *       • a pontuação no formato "pontos de máximo" (ex.: "120 de 150");
 *       • o rótulo da Faixa de Atendimento (Não atendeu / Atendeu
 *         parcialmente / Atendeu totalmente / Superou).                   (5.2)
 *   - Para cada métrica: a MÉDIA dos Times da Jornada.                    (5.3)
 *   - Para cada métrica: a distribuição de Times por Faixa de Atendimento no
 *     formato "2 não atendeu · 1 parcial · 1 atendeu · 1 superou".        (5.4)
 *
 * Registro: `window.PEPViews.metricas = { render }`. O `app.js` chama
 * `render(container, { month })` ao trocar mês/aba e isola o render em
 * try/catch; ainda assim protegemos internamente para não derrubar outras
 * visões (cada gauge é desenhado sob guarda própria).
 *
 * O "valor atual" de cada métrica (gauge/pontuação/faixa) é derivado da MÉDIA
 * dos times da jornada para aquela métrica (Aggregation.metricAverage) e
 * avaliado pelo motor (ScoringEngine.evaluateMetric). Assim, o gauge e a
 * pontuação exibidos são coerentes com a média mostrada no card (Req. 5.3) e
 * com a distribuição de times por faixa (Req. 5.4).
 *
 * Reutiliza os módulos já implementados (globais via <script>): ScoringEngine
 * (evaluateMetric, METRIC_RULES, CATEGORIES, METRICS), Aggregation
 * (metricAverage e metricDistribution — Task 4.3), PEPData, AppState e
 * Chart.js (CDN). App estático, tema escuro.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Instâncias de Chart.js vivas por métrica (destruídas antes de recriar,
    // para evitar vazamento ao re-renderizar na troca de mês/jornada/aba).
    // -------------------------------------------------------------------------
    let gauges = [];

    // Jornada selecionada atualmente (persistida entre re-renders da própria
    // visão). Inicia como null → resolvida para a 1ª jornada no primeiro render.
    let selectedJourney = null;

    // Ordem canônica das 4 categorias (4 Ps).
    const CATEGORY_ORDER = ['processos', 'praticas', 'produto', 'pessoas'];

    const CATEGORY_COLORS = {
        processos: '#3b82f6', // accent-blue
        praticas:  '#22c55e', // accent-green
        produto:   '#8b5cf6', // accent-purple
        pessoas:   '#f59e0b'  // accent-orange
    };

    // Cores por Faixa de Atendimento (consistentes com o tema).
    const BAND_COLORS = {
        'sem-dados':            '#64748b',
        'nao-atendeu':          '#ef4444',
        'atendeu-parcialmente': '#f59e0b',
        'atendeu-totalmente':   '#22c55e',
        'superou':              '#3b82f6'
    };

    // Rótulos curtos para a distribuição "N não atendeu · N parcial · ...".
    const DIST_SHORT = {
        'nao-atendeu': 'não atendeu',
        'atendeu-parcialmente': 'parcial',
        'atendeu-totalmente': 'atendeu',
        'superou': 'superou'
    };
    const DIST_ORDER = ['nao-atendeu', 'atendeu-parcialmente', 'atendeu-totalmente', 'superou'];

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
                PEPData: '../data.js',
                AppState: '../app-state.js'
            }[name];
            if (path) { try { return require(path); } catch (e) { /* ignore */ } }
        }
        return undefined;
    }

    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    // Formata o valor "atual" de uma métrica conforme a escala da regra.
    function formatMetricValue(rule, value) {
        if (value === null || value === undefined || (typeof value === 'number' && Number.isNaN(value))) {
            return 'Sem dados';
        }
        const round2 = (n) => Math.round(n * 100) / 100;
        switch (rule.scale) {
            case 'ratio':    return `${round2(value * 100)}%`;
            case 'days':     return `${round2(value)} dias`;
            case 'thc0a10':  return `${round2(value)}`;
            case 'category': return `Categoria ${Math.trunc(value)}`;
            default:         return `${round2(value)}`;
        }
    }

    const fmtScore = (n) => (typeof n === 'number' && isFinite(n)) ? (Math.round(n * 10) / 10).toString() : '—';

    // Nome legível da métrica (via METRICS do motor; fallback para o id).
    function metricName(ScoringEngine, metricId) {
        const def = ScoringEngine.METRICS && ScoringEngine.METRICS[metricId];
        return (def && def.name) ? def.name : metricId;
    }

    // Distribuição textual: "2 não atendeu · 1 parcial · 1 atendeu · 1 superou".
    function distributionText(dist) {
        const parts = [];
        for (const id of DIST_ORDER) {
            const n = (dist && typeof dist[id] === 'number') ? dist[id] : 0;
            parts.push(`${n} ${DIST_SHORT[id]}`);
        }
        return parts.join(' · ');
    }

    // =========================================================================
    // MODELO POR MÉTRICA
    // Para a métrica informada, calcula o "valor atual" (média dos times da
    // jornada), avalia com o motor (score/faixa) e obtém a distribuição por
    // faixa. Retorna um objeto consumido pelo card.
    // =========================================================================
    function buildMetricModel(ScoringEngine, Aggregation, month, journey, metricId) {
        const rule = ScoringEngine.METRIC_RULES[metricId];

        // Média do valor bruto da métrica entre os times com dado (Req. 5.3).
        const avg = Aggregation.metricAverage(month, journey, metricId); // { avg, count }
        const avgValue = (avg && typeof avg.avg === 'number') ? avg.avg : null;

        // Avaliação do valor médio pelo motor: score/faixa/label (Req. 5.2).
        const evalResult = ScoringEngine.evaluateMetric(metricId, avgValue);

        // Distribuição de times por Faixa de Atendimento (Req. 5.4).
        const dist = Aggregation.metricDistribution(month, journey, metricId);

        return {
            metricId,
            name: metricName(ScoringEngine, metricId),
            category: rule.category,
            max: rule.max,
            scale: rule.scale,
            hasData: avgValue !== null,
            avgValue,
            avgCount: avg ? avg.count : 0,
            valueFormatted: formatMetricValue(rule, avgValue),
            score: evalResult.score,
            band: evalResult.band,
            bandLabel: evalResult.label,
            distribution: dist
        };
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const month = (opts && typeof opts.month === 'number') ? opts.month : null;

        const ScoringEngine = dep('ScoringEngine');
        const Aggregation = dep('Aggregation');
        const PEPData = dep('PEPData');

        if (!ScoringEngine || !Aggregation || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        destroyGauges();

        const jornadas = PEPData.JORNADAS || [];
        if (jornadas.length === 0) {
            container.innerHTML = errorBlock('Nenhuma jornada disponível.');
            return;
        }

        // Resolve a jornada selecionada: mantém a anterior se ainda existir,
        // senão usa a primeira (opts.journey permite forçar via chamada externa).
        if (opts && typeof opts.journey === 'string' && jornadas.includes(opts.journey)) {
            selectedJourney = opts.journey;
        }
        if (!selectedJourney || !jornadas.includes(selectedJourney)) {
            selectedJourney = jornadas[0];
        }

        const monthLabel = (Array.isArray(PEPData.MONTH_LABELS) && month)
            ? PEPData.MONTH_LABELS[month - 1] : (month != null ? `Mês ${month}` : '—');

        // A jornada tem dado no mês? (ao menos um time com qualquer métrica).
        const journeyHasData = Aggregation.journeyScore(month, selectedJourney);
        const hasData = !!(journeyHasData && journeyHasData.hasData);

        // Monta os modelos das 12 métricas agrupadas por categoria.
        const metricIds = Object.keys(ScoringEngine.METRIC_RULES);
        const models = metricIds.map((id) =>
            buildMetricModel(ScoringEngine, Aggregation, month, selectedJourney, id));

        const byCategory = {};
        for (const id of CATEGORY_ORDER) byCategory[id] = [];
        for (const m of models) {
            if (byCategory[m.category]) byCategory[m.category].push(m);
        }

        container.innerHTML = buildHTML({
            monthLabel,
            jornadas,
            selectedJourney,
            hasData,
            byCategory,
            categories: ScoringEngine.CATEGORIES
        });

        // Desenha os gauges por métrica (apenas as com dado) sob guarda — a
        // falha de um gauge não impede os demais nem o restante da visão.
        if (hasData) {
            let idx = 0;
            for (const catId of CATEGORY_ORDER) {
                for (const m of byCategory[catId]) {
                    if (m.hasData) {
                        try {
                            drawGauge(container, `vm-gauge-${idx}`, m.score, m.max, m.band);
                        } catch (e) {
                            console.error('view-metricas gauge:', e);
                        }
                    }
                    idx++;
                }
            }
        }

        // Cabeamento do seletor de jornada → re-render da própria visão.
        // Guardado por capability check (o stub de teste não tem addEventListener).
        wireJourneySelector(container, month);
    }

    // Liga o <select> de jornada ao re-render interno (sem mexer no AppState,
    // que controla apenas o mês/aba). Defensivo para ambientes sem DOM real.
    function wireJourneySelector(container, month) {
        if (!container || typeof container.querySelector !== 'function') return;
        const select = container.querySelector('#vm-journey-select');
        if (!select || typeof select.addEventListener !== 'function') return;
        select.addEventListener('change', (e) => {
            const val = e && e.target ? e.target.value : null;
            if (typeof val === 'string') {
                selectedJourney = val;
                render(container, { month });
            }
        });
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        const options = m.jornadas.map((name) =>
            `<option value="${esc(name)}"${name === m.selectedJourney ? ' selected' : ''}>${esc(name)}</option>`
        ).join('');

        const body = m.hasData
            ? m.categories && categoriesHTML(m)
            : emptyJourney(m.selectedJourney);

        return `
        <div class="vm">
            <div class="vm-head">
                <h2 class="vm-title">📈 Métricas por Jornada</h2>
                <span class="vm-month">${esc(m.monthLabel)}</span>
            </div>

            <div class="vm-controls">
                <label class="vm-journey-label" for="vm-journey-select">Jornada:</label>
                <select id="vm-journey-select" class="vm-journey-select" aria-label="Selecionar jornada">
                    ${options}
                </select>
            </div>

            ${body}
        </div>`;
    }

    // As 4 categorias, cada uma com seus cards de métrica (Requirement 5.1).
    function categoriesHTML(m) {
        let out = '';
        for (const catId of CATEGORY_ORDER) {
            const cat = m.categories[catId] || { name: catId, maxScore: 0, icon: '' };
            const metrics = m.byCategory[catId] || [];
            if (metrics.length === 0) continue;

            // Índice global contínuo para casar o id do canvas (vm-gauge-N).
            const cards = metrics.map((metric) => metricCard(metric, globalIndex(m, catId, metric))).join('');

            out += `
            <section class="vm-category">
                <div class="vm-category-head">
                    <span class="vm-cat-icon" aria-hidden="true">${esc(cat.icon || '')}</span>
                    <h3 class="vm-cat-name">${esc(cat.name)}</h3>
                    <span class="vm-cat-max">${cat.maxScore} pts</span>
                </div>
                <div class="vm-grid">
                    ${cards}
                </div>
            </section>`;
        }
        return out;
    }

    // Índice global (contínuo pela ordem de categorias) de uma métrica — usado
    // para o id do canvas de gauge, coerente com a ordem de desenho no render.
    function globalIndex(m, catId, metric) {
        let idx = 0;
        for (const c of CATEGORY_ORDER) {
            const list = m.byCategory[c] || [];
            for (const mm of list) {
                if (c === catId && mm.metricId === metric.metricId) return idx;
                idx++;
            }
        }
        return idx;
    }

    // Card de uma métrica: gauge + valor atual + "pontos de máximo" + faixa +
    // média dos times + distribuição por faixa (Requirements 5.2, 5.3, 5.4).
    function metricCard(metric, idx) {
        const bandColor = BAND_COLORS[metric.band] || 'var(--text-muted)';

        if (!metric.hasData) {
            return `
            <div class="vm-card vm-card-empty">
                <div class="vm-card-head">
                    <h4 class="vm-metric-name" title="${esc(metric.name)}">${esc(metric.name)}</h4>
                </div>
                <div class="vm-empty">
                    <div class="vm-empty-icon" aria-hidden="true">∅</div>
                    <p class="vm-empty-text">Sem dados para esta métrica.</p>
                </div>
            </div>`;
        }

        return `
        <div class="vm-card">
            <div class="vm-card-head">
                <h4 class="vm-metric-name" title="${esc(metric.name)}">${esc(metric.name)}</h4>
                <span class="vm-band" style="border-color:${bandColor};color:${bandColor}">
                    <span class="vm-band-dot" style="background:${bandColor}"></span>${esc(metric.bandLabel)}
                </span>
            </div>

            <div class="vm-gauge-wrap">
                <canvas id="vm-gauge-${idx}"></canvas>
            </div>

            <div class="vm-value">
                <span class="vm-value-label">Valor atual</span>
                <span class="vm-value-num">${esc(metric.valueFormatted)}</span>
            </div>

            <div class="vm-points">
                <span class="vm-points-num">${fmtScore(metric.score)} de ${metric.max}</span>
                <span class="vm-points-unit">pontos</span>
            </div>

            <div class="vm-avg">
                <span class="vm-avg-label">Média dos times</span>
                <span class="vm-avg-value">${esc(metric.valueFormatted)} <small>(${metric.avgCount} time(s))</small></span>
            </div>

            <div class="vm-dist" title="Distribuição de times por faixa de atendimento">
                ${distributionDots(metric.distribution)}
                <span class="vm-dist-text">${esc(distributionText(metric.distribution))}</span>
            </div>
        </div>`;
    }

    // Pequenos marcadores coloridos para a distribuição por faixa.
    function distributionDots(dist) {
        let dots = '';
        for (const id of DIST_ORDER) {
            const n = (dist && typeof dist[id] === 'number') ? dist[id] : 0;
            if (n <= 0) continue;
            dots += `<span class="vm-dist-dot" style="background:${BAND_COLORS[id]}" title="${esc(DIST_SHORT[id])}: ${n}"></span>`;
        }
        return `<span class="vm-dist-dots">${dots}</span>`;
    }

    // Estado "sem dado" da jornada no mês (Requirement 2.3 / 5.*).
    function emptyJourney(journey) {
        return `
        <div class="vm-empty-journey">
            <div class="vm-empty-icon" aria-hidden="true">🗓️</div>
            <p class="vm-empty-text">Sem dados para a jornada <strong>${esc(journey)}</strong> no mês selecionado.</p>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="vm">
            <div class="vm-empty-journey">
                <div class="vm-empty-icon" aria-hidden="true">⚠️</div>
                <p class="vm-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // CHARTS (Chart.js) — gauge por métrica (score vs máximo da métrica)
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

        const color = BAND_COLORS[band] || '#3b82f6';
        const safeMax = (typeof maxScore === 'number' && maxScore > 0) ? maxScore : 1;
        const clamped = Math.max(0, Math.min(score, safeMax));

        const chart = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ['Pontos', 'Restante'],
                datasets: [{
                    data: [clamped, Math.max(0, safeMax - clamped)],
                    backgroundColor: [color, 'rgba(15,23,42,0.08)'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                circumference: 270,
                rotation: -135,
                cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            },
            plugins: [{
                id: 'vmGaugeText',
                afterDraw: (c) => {
                    const { ctx, width, height } = c;
                    const cssV = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (cssV && cssV.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (cssV && cssV.getPropertyValue('--text-muted').trim()) || '#64748b';
                    ctx.save();
                    ctx.font = 'bold 20px Inter, sans-serif';
                    ctx.fillStyle = colorPrimary;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(fmtScore(score), width / 2, height / 2 + 4);
                    ctx.font = '10px Inter, sans-serif';
                    ctx.fillStyle = colorMuted;
                    ctx.fillText(`de ${safeMax}`, width / 2, height / 2 + 22);
                    ctx.restore();
                }
            }]
        });
        gauges.push(chart);
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = {
        render,
        buildHTML,
        categoriesHTML,
        buildMetricModel,
        distributionText,
        // Reset do estado interno (útil em testes para isolar re-renders).
        _resetState: () => { selectedJourney = null; }
    };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.metricas = { render };
    }

    // Guarda de ambiente: expõe para testes (Vitest) sem afetar o <script>.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
