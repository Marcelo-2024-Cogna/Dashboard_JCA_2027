/**
 * PEP - Plataforma de Engenharia de Performance
 * Dashboard de Apresentação Executiva (`js/views/view-apresentacao.js`)
 *
 * Task 31 (spec score-performance-executivo). Implementa o Requirement 29 e a
 * seção de design "Fase 3 → Novas telas de dashboard → view-apresentacao":
 *
 *   - Exibe, POR JORNADA, os indicadores executivos consolidados (score, faixa,
 *     atingimento da meta e destaques) em um layout voltado para APRESENTAÇÃO —
 *     uma jornada por vez, grande e limpa (modo "slide").               (29.1)
 *   - Navegação SEQUENCIAL entre as jornadas (anterior/próxima + indicador de
 *     posição "X de Y"; teclado ←/→). A jornada atual é estado de sessão da
 *     view, preservado entre re-renders (troca de mês).                 (29.2)
 *   - REUTILIZA os dados do Operacional/Times via Aggregation/Targets, SEM
 *     recalcular regras próprias (score da jornada = média dos times; faixa e
 *     Farol vêm do motor/Targets, a fonte única).                       (29.3)
 *   - Preserva o tema escuro e a identidade visual existentes.          (29.4)
 *
 * O "destaque" da jornada no slide combina o Δ vs mês anterior (Aggregation.
 * monthDelta) e os destaques globais de maior alta/queda (Aggregation.highlights)
 * quando a jornada atual é o topRise/topFall do mês.
 *
 * Registro: `window.PEPViews.apresentacao = { render }`. O `app.js` chama
 * `render(container, { month })` ao ativar a tela; o render é isolado em
 * try/catch pelo app.js e, defensivamente, aqui também. A navegação é cabeada
 * internamente (prev/next/dots → re-render do mesmo container, preservando o
 * índice da jornada e o mês).
 *
 * Dependências (globais via <script> no browser; require() em Node/Vitest sob
 * guarda de ambiente): Aggregation, ScoringEngine, Targets, PEPData, Chart (CDN).
 * App estático, tema escuro preservado.
 */

(function () {
    'use strict';

    // Gauge de Chart.js vivo (destruído antes de recriar na navegação/troca de mês).
    let gauge = null;

    // Índice da jornada atualmente exibida (estado de sessão do "slide").
    // Mantido entre re-renders disparados pela troca de mês/aba; a navegação
    // (prev/next/dots) atualiza este valor e re-renderiza o mesmo container.
    let currentIndex = 0;

    // Handler de teclado atualmente registrado (removido antes de re-registrar).
    let keyHandler = null;

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
    // Resolução de dependências com guarda de ambiente (browser: globais;
    // Node/Vitest: require() relativo). Não quebra se faltar algo.
    // -------------------------------------------------------------------------
    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                Aggregation: '../aggregation.js',
                ScoringEngine: '../scoring-engine.js',
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
    // Atingimento (% de meta vs realizado) de uma jornada no mês (Req. 29.1).
    // Reutiliza Targets.atingimento (realizado ÷ metaMes) — não recalcula regra
    // própria. Retorna null quando indisponível (meta zero/sem realizado).
    // -------------------------------------------------------------------------
    function journeyAtingimento(month, journeyName, realizado) {
        const Targets = dep('Targets');
        if (!Targets || typeof realizado !== 'number' || !isFinite(realizado)) return null;
        try { return Targets.atingimento(journeyName, month, realizado); }
        catch (e) { return null; }
    }

    // -------------------------------------------------------------------------
    // Monta o modelo do "slide" de uma jornada no mês, REUTILIZANDO os dados do
    // Operacional/Times via Aggregation/Targets (Requirement 29.3). Retorna
    // { hasData:false } quando a jornada não possui dado no mês.
    // -------------------------------------------------------------------------
    function journeyModel(month, journeyName) {
        const Aggregation = dep('Aggregation');
        const ScoringEngine = dep('ScoringEngine');
        const Targets = dep('Targets');
        if (!Aggregation) return { journey: journeyName, hasData: false };

        const js = Aggregation.journeyScore(month, journeyName);
        if (!js || !js.hasData) return { journey: journeyName, hasData: false };

        const band = js.performanceBand
            || (ScoringEngine ? ScoringEngine.getPerformanceBand(js.avgScore) : null);
        const at = journeyAtingimento(month, journeyName, js.avgScore);
        const farolId = Targets ? Targets.farol(at) : null;
        const delta = Aggregation.monthDelta(month, journeyName);

        return {
            journey: journeyName,
            hasData: true,
            avgScore: js.avgScore,
            band,
            atingimento: at,
            farolId,
            delta,
            categoryScores: js.categoryScores,
            teamCount: Array.isArray(js.teams) ? js.teams.length : 0
        };
    }

    // Destaque textual da jornada no slide: posição relativa nos destaques
    // globais do mês (maior alta/queda) + o Δ próprio. Reutiliza
    // Aggregation.highlights (não recalcula). Retorna { badge, deltaText }.
    function journeyHighlight(month, journeyName, delta) {
        const Aggregation = dep('Aggregation');
        let badge = null;
        if (Aggregation && typeof Aggregation.highlights === 'function') {
            let h = { topRise: null, topFall: null };
            try { h = Aggregation.highlights(month) || h; } catch (e) { /* ignore */ }
            if (h.topRise && h.topRise.journey === journeyName) {
                badge = { kind: 'rise', label: '🚀 Maior alta do mês', delta: h.topRise.delta };
            } else if (h.topFall && h.topFall.journey === journeyName) {
                badge = { kind: 'fall', label: '⚠️ Maior queda do mês', delta: h.topFall.delta };
            }
        }
        return { badge, deltaText: fmtDelta(delta) };
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

        destroyGauge();
        detachKeys();

        if (!Aggregation || !ScoringEngine || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        const jornadas = PEPData.JORNADAS || [];
        const total = jornadas.length;

        if (total === 0) {
            container.innerHTML = errorBlock('Nenhuma jornada disponível.');
            return;
        }

        // Resolve o índice do slide: prioriza opts.index, depois o estado de
        // sessão, com clamp em [0, total-1] (navegação sequencial fechada).
        if (opts && typeof opts.index === 'number') currentIndex = opts.index;
        if (!Number.isInteger(currentIndex) || currentIndex < 0) currentIndex = 0;
        if (currentIndex > total - 1) currentIndex = total - 1;

        const monthLabel = (Array.isArray(PEPData.MONTH_LABELS) && month)
            ? PEPData.MONTH_LABELS[month - 1] : (month != null ? `Mês ${month}` : '—');

        const baseMax = ScoringEngine.BASE_MAX_SCORE || 1000;

        const journeyName = jornadas[currentIndex];
        const model = journeyModel(month, journeyName);
        const highlight = model.hasData
            ? journeyHighlight(month, journeyName, model.delta)
            : { badge: null, deltaText: 'Indisponível' };

        container.innerHTML = buildHTML({
            monthLabel,
            baseMax,
            jornadas,
            index: currentIndex,
            total,
            model,
            highlight
        });

        // Cabeia a navegação sequencial (prev/next/dots + teclado ←/→).
        wireNavigation(container, month, total);

        // Desenha o gauge do slide (sob guarda — a falha não derruba o restante).
        if (model.hasData) {
            try { drawGauge(container, 'ap-gauge', model.avgScore, baseMax, model.band); }
            catch (e) { if (typeof console !== 'undefined') console.error('view-apresentacao gauge:', e); }
        }
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        return `
        <div class="ap">
            <div class="ap-head">
                <h2 class="ap-title">🖥️ Apresentação Executiva</h2>
                <span class="ap-month">${esc(m.monthLabel)}</span>
            </div>

            <div class="ap-stage">
                ${navButton('prev', m.index > 0)}

                <div class="ap-slide">
                    ${m.model.hasData
                        ? slidePanel(m.model, m.baseMax, m.highlight)
                        : emptySlide(m.model.journey)}
                </div>

                ${navButton('next', m.index < m.total - 1)}
            </div>

            <div class="ap-nav">
                <span class="ap-pos">Jornada ${m.index + 1} de ${m.total}</span>
                <div class="ap-dots" role="tablist" aria-label="Jornadas">
                    ${m.jornadas.map((j, i) => `
                        <button type="button" class="ap-dot${i === m.index ? ' ap-dot-active' : ''}"
                                data-ap-index="${i}" role="tab"
                                aria-selected="${i === m.index ? 'true' : 'false'}"
                                title="${esc(j)}" aria-label="${esc(j)}"></button>`).join('')}
                </div>
            </div>
        </div>`;
    }

    function navButton(dir, enabled) {
        const label = dir === 'prev' ? 'Jornada anterior' : 'Próxima jornada';
        const glyph = dir === 'prev' ? '‹' : '›';
        const disAttr = enabled ? '' : ' disabled';
        return `<button type="button" class="ap-navbtn ap-navbtn-${dir}" data-ap-nav="${dir}"
                        aria-label="${label}" title="${label}"${disAttr}>${glyph}</button>`;
    }

    function slidePanel(model, baseMax, highlight) {
        const bandName = model.band ? model.band.name : '—';
        const bandColor = model.band ? model.band.color : 'var(--text-muted)';

        const farol = model.farolId ? FAROL_META[model.farolId] : null;
        const farolPill = farol
            ? `<span class="ap-farol" style="border-color:${farol.color};color:${farol.color}">
                   <span class="ap-farol-dot" style="background:${farol.color}"></span>${farol.label}
               </span>`
            : `<span class="ap-farol ap-muted" style="border-color:var(--border-color)">
                   <span class="ap-farol-dot" style="background:var(--text-muted)"></span>Indisponível
               </span>`;

        const badge = highlight.badge
            ? `<div class="ap-badge ap-badge-${highlight.badge.kind}">
                   ${esc(highlight.badge.label)} (${esc(fmtDelta(highlight.badge.delta))})
               </div>`
            : '';

        const deltaClass = (typeof model.delta === 'number' && isFinite(model.delta))
            ? (model.delta > 0 ? 'ap-up' : (model.delta < 0 ? 'ap-down' : 'ap-muted'))
            : 'ap-muted';

        return `
        <div class="ap-panel">
            <div class="ap-left">
                <div class="ap-journey-head">
                    <h3 class="ap-journey" title="${esc(model.journey)}">${esc(model.journey)}</h3>
                    ${farolPill}
                </div>
                <div class="ap-gauge-wrap">
                    <canvas id="ap-gauge"></canvas>
                </div>
                <div class="ap-band" style="color:${bandColor}">
                    <span class="ap-band-dot" style="background:${bandColor}"></span>
                    ${esc(bandName)}
                </div>
            </div>

            <div class="ap-right">
                ${badge}
                <div class="ap-kpis">
                    <div class="ap-kpi">
                        <span class="ap-kpi-label">Atingimento da meta</span>
                        <span class="ap-kpi-value">${fmtPct(model.atingimento)}</span>
                    </div>
                    <div class="ap-kpi">
                        <span class="ap-kpi-label">Δ vs mês anterior</span>
                        <span class="ap-kpi-value ${deltaClass}">${esc(fmtDelta(model.delta))}</span>
                    </div>
                </div>
                <h4 class="ap-cats-title">Pontos por categoria (4 Ps)</h4>
                ${categoryBreakdown(model.categoryScores)}
                <div class="ap-teams">${model.teamCount} time(s) com dado</div>
            </div>
        </div>`;
    }

    // Detalhamento "X de Y pontos" por categoria (destaques consolidados — 29.1).
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
            <div class="ap-cat">
                <div class="ap-cat-head">
                    <span class="ap-cat-dot" style="background:${CATEGORY_COLORS[id]}"></span>
                    <span class="ap-cat-name">${esc(c.name || id)}</span>
                    <span class="ap-cat-val">${val.toFixed(0)} de ${max} pontos</span>
                </div>
                <div class="ap-cat-bar">
                    <div class="ap-cat-fill" style="width:${pct.toFixed(1)}%;background:${CATEGORY_COLORS[id]}"></div>
                </div>
            </div>`;
        }
        return `<div class="ap-cats">${rows}</div>`;
    }

    // Slide "sem dado" da jornada no mês (Requirement 2.3 / 29.1).
    function emptySlide(journeyName) {
        return `
        <div class="ap-panel ap-panel-empty">
            <div class="ap-journey-head">
                <h3 class="ap-journey" title="${esc(journeyName)}">${esc(journeyName)}</h3>
                <span class="ap-farol ap-muted" style="border-color:var(--border-color)">
                    <span class="ap-farol-dot" style="background:var(--text-muted)"></span>Sem dado
                </span>
            </div>
            <div class="ap-empty">
                <div class="ap-empty-icon" aria-hidden="true">🗓️</div>
                <p class="ap-empty-text">Sem dados para esta jornada no mês selecionado.</p>
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="ap">
            <div class="ap-empty">
                <div class="ap-empty-icon" aria-hidden="true">⚠️</div>
                <p class="ap-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // NAVEGAÇÃO SEQUENCIAL — cabeamento (prev/next/dots + teclado ←/→)
    // (Requirement 29.2). Cada ação atualiza currentIndex e re-renderiza o
    // mesmo container preservando o mês.
    // =========================================================================
    function go(container, month, total, nextIndex) {
        const clamped = Math.max(0, Math.min(nextIndex, total - 1));
        currentIndex = clamped;
        render(container, { month, index: clamped });
    }

    function wireNavigation(container, month, total) {
        if (!container || typeof container.querySelector !== 'function') return;

        const prev = container.querySelector('[data-ap-nav="prev"]');
        const next = container.querySelector('[data-ap-nav="next"]');
        if (prev && typeof prev.addEventListener === 'function') {
            prev.addEventListener('click', () => go(container, month, total, currentIndex - 1));
        }
        if (next && typeof next.addEventListener === 'function') {
            next.addEventListener('click', () => go(container, month, total, currentIndex + 1));
        }

        const dots = (typeof container.querySelectorAll === 'function')
            ? container.querySelectorAll('[data-ap-index]') : [];
        if (dots && dots.forEach) {
            dots.forEach((dot) => {
                if (!dot || typeof dot.addEventListener !== 'function') return;
                dot.addEventListener('click', () => {
                    const i = parseInt(dot.getAttribute('data-ap-index'), 10);
                    if (Number.isInteger(i)) go(container, month, total, i);
                });
            });
        }

        // Teclado: ←/→ navegam entre jornadas. Registrado no document (quando
        // disponível) e removido antes de re-registrar para não acumular.
        attachKeys(container, month, total);
    }

    function attachKeys(container, month, total) {
        if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') return;
        detachKeys();
        keyHandler = (e) => {
            if (!e) return;
            if (e.key === 'ArrowLeft') go(container, month, total, currentIndex - 1);
            else if (e.key === 'ArrowRight') go(container, month, total, currentIndex + 1);
        };
        document.addEventListener('keydown', keyHandler);
    }

    function detachKeys() {
        if (keyHandler && typeof document !== 'undefined' && typeof document.removeEventListener === 'function') {
            document.removeEventListener('keydown', keyHandler);
        }
        keyHandler = null;
    }

    // =========================================================================
    // CHART (Chart.js) — gauge da jornada do slide (0–1000)
    // =========================================================================
    function destroyGauge() {
        if (gauge) { try { gauge.destroy(); } catch (e) { /* ignore */ } gauge = null; }
    }

    function drawGauge(container, canvasId, score, maxScore, band) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#' + canvasId);
        if (!canvas) return;

        const color = (band && band.color) ? band.color : '#3b82f6';
        const clamped = Math.max(0, Math.min(score, maxScore));

        gauge = new Chart(canvas, {
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
                id: 'apGaugeText',
                afterDraw: (c) => {
                    const { ctx, width, height } = c;
                    const cssV = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (cssV && cssV.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (cssV && cssV.getPropertyValue('--text-muted').trim()) || '#64748b';
                    ctx.save();
                    ctx.font = 'bold 40px Inter, sans-serif';
                    ctx.fillStyle = colorPrimary;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(fmtScore(score), width / 2, height / 2 + 6);
                    ctx.font = '12px Inter, sans-serif';
                    ctx.fillStyle = colorMuted;
                    ctx.fillText(`de ${maxScore} pts`, width / 2, height / 2 + 34);
                    ctx.restore();
                }
            }]
        });
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = { render, buildHTML, slidePanel, categoryBreakdown, journeyModel, journeyAtingimento };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.apresentacao = { render };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
