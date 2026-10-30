/**
 * PEP - Plataforma de Engenharia de Performance
 * Dashboard Executivo por Squad (`js/views/view-exec-squad.js`)
 *
 * Task 30 (spec score-performance-executivo). Implementa o Requirement 28 e a
 * seção de design "Fase 3 → Novas telas de dashboard → view-exec-squad":
 *
 *   - Score de performance do Squad selecionado com a MESMA régua de Faixas de
 *     Performance do Score (Baixa/Média/Boa/Ótima).                     (28.1)
 *   - Seletor de Squad entre os squads disponíveis na identificação
 *     canônica (PEPIdentity), agrupados por jornada.                    (28.2)
 *   - Detalhamento por categoria (4 Ps: Processos/Práticas/Produto/Pessoas)
 *     no formato "X de Y pontos" e o Farol do Squad no período.         (28.3)
 *   - Ausência de dado do Squad no período indicada visualmente.        (28.4)
 *   - Home do perfil TL (Rbac.homeView('tl') === 'execSquad').
 *
 * O score do Squad reutiliza o motor (ScoringEngine.calculateTeamScore) sobre
 * as métricas do time no mês selecionado — não recalcula regras próprias. O
 * Farol reutiliza Targets (atingimento = score do squad ÷ meta do mês da sua
 * jornada; farol é a FONTE ÚNICA de classificação).
 *
 * Registro: `window.PEPViews.execSquad = { render }`. O `app.js` chama
 * `render(container, { month })` ao ativar a tela; o render é isolado em
 * try/catch pelo app.js e, defensivamente, aqui também. O seletor de squad é
 * cabeado internamente (change → re-render do mesmo container, preservando o
 * squad escolhido).
 *
 * Dependências (globais via <script> no browser; require() em Node/Vitest sob
 * guarda de ambiente): ScoringEngine, PEPIdentity, PEPData, Targets, Chart (CDN).
 * App estático, tema escuro preservado.
 */

(function () {
    'use strict';

    // Gauge de Chart.js vivo (destruído antes de recriar na troca de squad/mês).
    let gauge = null;

    // Squad atualmente selecionado (estado de sessão da view). Mantido entre
    // re-renders disparados pela troca de mês/aba; a troca pelo seletor atualiza
    // este valor e re-renderiza o mesmo container.
    let selectedSquad = null;

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
                ScoringEngine: '../scoring-engine.js',
                PEPIdentity: '../identity.js',
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

    // -------------------------------------------------------------------------
    // Catálogo de squads por jornada a partir da identificação canônica
    // (Requirement 28.2). Retorna [{ jornada, squads:[nome,...] }, ...] na
    // ordem canônica das jornadas; jornadas sem squads são omitidas.
    // -------------------------------------------------------------------------
    function squadCatalog() {
        const Identity = dep('PEPIdentity');
        const PEPData = dep('PEPData');
        const out = [];
        if (!Identity) return out;

        let jornadas = [];
        try { jornadas = Identity.jornadas() || []; } catch (e) { jornadas = []; }
        if ((!jornadas || !jornadas.length) && PEPData && Array.isArray(PEPData.JORNADAS)) {
            jornadas = PEPData.JORNADAS;
        }

        jornadas.forEach((j) => {
            let squads = [];
            try { squads = Identity.squadsOf(j) || []; } catch (e) { squads = []; }
            if (squads.length) out.push({ jornada: j, squads });
        });
        return out;
    }

    // Lista plana de todos os squads (rótulos canônicos).
    function allSquads() {
        const flat = [];
        squadCatalog().forEach((g) => g.squads.forEach((s) => flat.push(s)));
        return flat;
    }

    // Jornada canônica de um squad.
    function jornadaOf(squad) {
        const Identity = dep('PEPIdentity');
        if (!Identity || squad == null) return null;
        try { return Identity.jornadaOf(squad); } catch (e) { return null; }
    }

    // -------------------------------------------------------------------------
    // Localiza as métricas de um squad em um mês (via sua jornada canônica).
    // Retorna o objeto de métricas, ou null quando não há registro no período.
    // -------------------------------------------------------------------------
    function squadMetrics(squad, month) {
        const PEPData = dep('PEPData');
        if (!PEPData || squad == null || month == null) return null;

        const jornada = jornadaOf(squad);
        if (!jornada) return null;

        let teams = [];
        try { teams = PEPData.getTeamsByJornada(jornada, month) || []; } catch (e) { teams = []; }

        const Identity = dep('PEPIdentity');
        const same = (a, b) => Identity && typeof Identity.sameSquad === 'function'
            ? Identity.sameSquad(a, b) : String(a) === String(b);

        const team = teams.find((t) => same(t.name, squad));
        return team && team.metrics ? team.metrics : null;
    }

    // Um conjunto de métricas "tem dado" quando possui ao menos uma métrica
    // não nula/definida (coerente com Aggregation.teamHasData).
    function hasAnyData(metrics) {
        if (!metrics || typeof metrics !== 'object') return false;
        return Object.values(metrics).some((v) =>
            v !== null && v !== undefined && !(typeof v === 'number' && Number.isNaN(v)));
    }

    // -------------------------------------------------------------------------
    // Atingimento do squad no mês (Req. 28.3): score do squad ÷ meta do mês da
    // sua jornada (as metas são definidas por jornada em Targets). Retorna null
    // quando indisponível (meta zero/ausente). O Farol deriva daqui.
    // -------------------------------------------------------------------------
    function squadAtingimento(squad, month, score) {
        const Targets = dep('Targets');
        if (!Targets || typeof score !== 'number' || !isFinite(score)) return null;
        const jornada = jornadaOf(squad);
        if (!jornada) return null;
        try { return Targets.atingimento(jornada, month, score); }
        catch (e) { return null; }
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;

        const month = (opts && typeof opts.month === 'number') ? opts.month : null;

        const ScoringEngine = dep('ScoringEngine');
        const PEPData = dep('PEPData');
        const Targets = dep('Targets');

        destroyGauge();

        if (!ScoringEngine || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        // Resolve o squad selecionado: prioriza opts.squad, depois o estado de
        // sessão, com fallback para o 1º squad do catálogo canônico.
        const squads = allSquads();
        if (opts && opts.squad != null) selectedSquad = opts.squad;
        if (!selectedSquad || squads.indexOf(selectedSquad) === -1) {
            selectedSquad = squads.length ? squads[0] : null;
        }

        const monthLabel = (Array.isArray(PEPData.MONTH_LABELS) && month)
            ? PEPData.MONTH_LABELS[month - 1] : (month != null ? `Mês ${month}` : '—');

        const baseMax = ScoringEngine.BASE_MAX_SCORE || 1000;

        // Modelo do squad selecionado no mês.
        let model = { hasData: false };
        if (selectedSquad) {
            const metrics = squadMetrics(selectedSquad, month);
            if (hasAnyData(metrics)) {
                const result = ScoringEngine.calculateTeamScore(metrics);
                const at = squadAtingimento(selectedSquad, month, result.totalScore);
                const farolId = Targets ? Targets.farol(at) : null;
                model = {
                    hasData: true,
                    score: result.totalScore,
                    band: result.performanceBand,
                    categoryScores: result.categoryScores,
                    atingimento: at,
                    farolId
                };
            }
        }

        container.innerHTML = buildHTML({
            monthLabel,
            baseMax,
            catalog: squadCatalog(),
            selectedSquad,
            jornada: selectedSquad ? jornadaOf(selectedSquad) : null,
            model
        });

        // Cabeia o seletor de squad: troca re-renderiza o mesmo container.
        wireSelector(container, month);

        // Desenha o gauge (sob guarda — a falha não derruba o restante).
        if (model.hasData) {
            try { drawGauge(container, 'es-gauge', model.score, baseMax, model.band); }
            catch (e) { if (typeof console !== 'undefined') console.error('view-exec-squad gauge:', e); }
        }
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        return `
        <div class="es">
            <div class="es-head">
                <h2 class="es-title">🧩 Executivo por Squad</h2>
                <span class="es-month">${esc(m.monthLabel)}</span>
            </div>

            <div class="es-controls">
                <label class="es-sel-label" for="es-squad-selector">Squad</label>
                ${squadSelector(m.catalog, m.selectedSquad)}
                ${m.jornada ? `<span class="es-jornada">Jornada: ${esc(m.jornada)}</span>` : ''}
            </div>

            ${m.model.hasData
                ? dataPanel(m.model, m.baseMax, m.selectedSquad)
                : emptyPanel(m.selectedSquad)}
        </div>`;
    }

    // Seletor de squad agrupado por jornada (<optgroup>). Identidade canônica.
    function squadSelector(catalog, selected) {
        if (!catalog.length) {
            return `<select id="es-squad-selector" class="es-select" disabled>
                        <option>Sem squads disponíveis</option>
                    </select>`;
        }
        const groups = catalog.map((g) => {
            const opts = g.squads.map((s) => {
                const sel = (s === selected) ? ' selected' : '';
                return `<option value="${esc(s)}"${sel}>${esc(s)}</option>`;
            }).join('');
            return `<optgroup label="${esc(g.jornada)}">${opts}</optgroup>`;
        }).join('');
        return `<select id="es-squad-selector" class="es-select">${groups}</select>`;
    }

    function dataPanel(model, baseMax, squad) {
        const bandName = model.band ? model.band.name : '—';
        const bandColor = model.band ? model.band.color : 'var(--text-muted)';

        const farol = model.farolId ? FAROL_META[model.farolId] : null;
        const farolPill = farol
            ? `<span class="es-farol" style="border-color:${farol.color};color:${farol.color}">
                   <span class="es-farol-dot" style="background:${farol.color}"></span>${farol.label}
               </span>`
            : `<span class="es-farol es-muted" style="border-color:var(--border-color)">
                   <span class="es-farol-dot" style="background:var(--text-muted)"></span>Indisponível
               </span>`;

        return `
        <div class="es-panel">
            <div class="es-card es-card-score">
                <div class="es-card-head">
                    <h3 class="es-squad" title="${esc(squad)}">${esc(squad)}</h3>
                    ${farolPill}
                </div>
                <div class="es-gauge-wrap">
                    <canvas id="es-gauge"></canvas>
                </div>
                <div class="es-band" style="color:${bandColor}">
                    <span class="es-band-dot" style="background:${bandColor}"></span>
                    ${esc(bandName)}
                </div>
                <div class="es-kpi">
                    <span class="es-kpi-label">Meta vs realizado</span>
                    <span class="es-kpi-value">${fmtPct(model.atingimento)}</span>
                </div>
            </div>

            <div class="es-card es-card-cats">
                <h3 class="es-cats-title">Detalhamento por categoria (4 Ps)</h3>
                ${categoryBreakdown(model.categoryScores)}
            </div>
        </div>`;
    }

    // Detalhamento "X de Y pontos" por categoria (Requirement 28.3).
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
            <div class="es-cat">
                <div class="es-cat-head">
                    <span class="es-cat-dot" style="background:${CATEGORY_COLORS[id]}"></span>
                    <span class="es-cat-name">${esc(c.name || id)}</span>
                    <span class="es-cat-val">${val.toFixed(0)} de ${max} pontos</span>
                </div>
                <div class="es-cat-bar">
                    <div class="es-cat-fill" style="width:${pct.toFixed(1)}%;background:${CATEGORY_COLORS[id]}"></div>
                </div>
            </div>`;
        }
        return `<div class="es-cats">${rows}</div>`;
    }

    // Estado "sem dado" do squad no período (Requirement 28.4).
    function emptyPanel(squad) {
        return `
        <div class="es-panel es-panel-empty">
            <div class="es-card es-card-empty">
                <div class="es-empty">
                    <div class="es-empty-icon" aria-hidden="true">🗓️</div>
                    <p class="es-empty-text">
                        Sem dados${squad ? ` para o squad <strong>${esc(squad)}</strong>` : ''} no período selecionado.
                    </p>
                </div>
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="es">
            <div class="es-empty">
                <div class="es-empty-icon" aria-hidden="true">⚠️</div>
                <p class="es-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // SELETOR — cabeamento (change re-renderiza o mesmo container)
    // =========================================================================
    function wireSelector(container, month) {
        if (!container || typeof container.querySelector !== 'function') return;
        const select = container.querySelector('#es-squad-selector');
        if (!select || typeof select.addEventListener !== 'function') return;
        select.addEventListener('change', (e) => {
            const value = (e && e.target) ? e.target.value : (select.value);
            selectedSquad = value;
            render(container, { month, squad: value });
        });
    }

    // =========================================================================
    // CHART (Chart.js) — gauge do squad (0–1000)
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
                id: 'esGaugeText',
                afterDraw: (c) => {
                    const { ctx, width, height } = c;
                    const cssV = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (cssV && cssV.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (cssV && cssV.getPropertyValue('--text-muted').trim()) || '#64748b';
                    ctx.save();
                    ctx.font = 'bold 30px Inter, sans-serif';
                    ctx.fillStyle = colorPrimary;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(fmtScore(score), width / 2, height / 2 + 6);
                    ctx.font = '11px Inter, sans-serif';
                    ctx.fillStyle = colorMuted;
                    ctx.fillText(`de ${maxScore} pts`, width / 2, height / 2 + 30);
                    ctx.restore();
                }
            }]
        });
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = { render, buildHTML, categoryBreakdown, squadCatalog, allSquads, squadMetrics };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.execSquad = { render };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
