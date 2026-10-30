/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Cycle Time por Fase (`js/views/retro-cycle-time.js`)
 *
 * Task 18.1 (spec score-performance-executivo). Implementa o Requirement 13 e a
 * seção de design "Módulo de Retro PI → Painéis → Cycle Time por fase":
 *
 *   - Tempo médio por fase (Ideação, Desenvolvimento, Homologação, Implantação)
 *     por Time + média da Jornada em cada fase.                       (13.1, 13.2)
 *   - Filtro por Tipo de Item e Status (quando aplicável na UI); ao
 *     aplicar o filtro, os tempos são recalculados.                   (13.3)
 *   - Ausência de dado por fase indicada visualmente.                 (13.4)
 *
 * Registro: `window.PEPViews.retroCycleTime = { render }`. O `app.js` chama
 * `render(container, { pi })` ao trocar PI/sub-aba. O render é isolado em
 * try/catch pelo próprio app.js; ainda assim, protegemos internamente para não
 * derrubar outros painéis.
 *
 * Dependências (globais via <script> no browser; via require() sob guarda de
 * ambiente em Node/Vitest): RetroAggregation (retro-aggregation.js),
 * PEPRetroData (retro-data.js), Chart (Chart.js via CDN). O app é estático e o
 * tema é escuro.
 *
 * CSS: prefixo EXCLUSIVO `.rct-*` (ver css/styles.css, bloco apensado).
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Instância de Chart.js viva (destruída antes de recriar, para evitar
    // vazamento ao re-renderizar na troca de PI/sub-aba).
    // -------------------------------------------------------------------------
    const charts = { barras: null };

    // Fases do Cycle Time (ordem canônica do Requirement 13.1).
    const FASES = ['ideacao', 'desenvolvimento', 'homologacao', 'implantacao'];
    const FASE_LABEL = {
        ideacao: 'Ideação',
        desenvolvimento: 'Desenvolvimento',
        homologacao: 'Homologação',
        implantacao: 'Implantação'
    };

    // Cores por fase (consistentes com o tema escuro / paleta das visões).
    const FASE_COLORS = {
        ideacao: '#3b82f6',          // azul
        desenvolvimento: '#22c55e',  // verde
        homologacao: '#f59e0b',      // laranja
        implantacao: '#8b5cf6'       // roxo
    };

    // -------------------------------------------------------------------------
    // Resolução de dependências: browser (window/globalThis) com fallback a
    // require() em Node (sob guarda de ambiente), igual ao padrão dos módulos.
    // -------------------------------------------------------------------------
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

    // Formata dias com uma casa decimal; ausência de dado → marcador "—".
    const fmtDias = (n) => (typeof n === 'number' && isFinite(n)) ? `${n.toFixed(1)} d` : '—';
    const hasData = (n) => (typeof n === 'number' && isFinite(n));

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const pi = (opts && opts.pi != null) ? opts.pi : null;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');

        if (!RetroAggregation || typeof RetroAggregation.cycleTimePorFase !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        // Destrói chart anterior antes de recriar o DOM.
        destroyCharts();

        // Filtro vindo do estado interno do painel (persistido no container via
        // dataset) — Tipo de Item e Status (Requirement 13.3). A fonte de dados
        // de exemplo (retro-data.js) não desdobra os itens por Tipo/Status, de
        // modo que o filtro é exposto na UI e repassado à agregação; quando um
        // filtro "estreita" além do disponível, as médias caem para "Sem Dado".
        const filtro = readFiltro(container, opts);

        let agg;
        try {
            agg = RetroAggregation.cycleTimePorFase(pi, filtro);
        } catch (e) {
            container.innerHTML = errorBlock('Falha ao agregar o Cycle Time por fase.');
            return;
        }

        const porTime = (agg && Array.isArray(agg.porTime)) ? agg.porTime : [];
        const mediaJornada = (agg && agg.mediaJornada) ? agg.mediaJornada : {};

        // Escopo exibido reflete o filtro ativo (squad > jornada > dado > todas).
        const jornada = (filtro.squad) || (filtro.jornada)
            || ((porTime[0] && porTime[0].jornada) ? porTime[0].jornada : 'Todas as Jornadas');
        const tiposItem = tipoItemOptions();
        const statusOpcoes = statusOptions();

        // --- Estado neutro: sem dado para o PI/filtro ---
        if (porTime.length === 0) {
            container.innerHTML = buildShell({
                pi, jornada, filtro, tiposItem, statusOpcoes,
                body: neutralBlock(pi)
            });
            wireControls(container, opts);
            return;
        }

        container.innerHTML = buildShell({
            pi, jornada, filtro, tiposItem, statusOpcoes,
            body: buildBody({ porTime, mediaJornada, jornada })
        });

        // Desenha o gráfico de barras agrupadas (guarda: falha não derruba a UI).
        try {
            drawBarras(container, { porTime, mediaJornada, jornada });
        } catch (e) {
            console.error('retro-cycle-time barras:', e);
        }

        wireControls(container, opts);
    }

    // -------------------------------------------------------------------------
    // Filtro (Tipo de Item + Status). Lido do dataset do container (persiste a
    // seleção entre re-renders provocados por troca de PI) com fallback a opts.
    // -------------------------------------------------------------------------
    function readFiltro(container, opts) {
        const ds = (container && container.dataset) ? container.dataset : {};
        const fromOpts = (opts && opts.filtro) ? opts.filtro : {};
        // Filtro global de Retro (jornada/squad/sprint) vindo do app.js.
        const gf = (opts && opts.filter) ? opts.filter : {};
        const tipoItem = ds.rctTipoItem || fromOpts.tipoItem || 'all';
        const status = ds.rctStatus || fromOpts.status || 'all';
        const filtro = {};
        // Squad do filtro global tem precedência sobre opts.time.
        if (gf.squad != null && gf.squad !== 'all') filtro.squad = gf.squad;
        else if (opts && opts.time != null) filtro.time = opts.time;
        if (gf.jornada != null && gf.jornada !== 'all') filtro.jornada = gf.jornada;
        if (tipoItem && tipoItem !== 'all') filtro.tipoItem = tipoItem;
        if (status && status !== 'all') filtro.status = status;
        // Mantém os rótulos selecionados para a UI.
        filtro._tipoItemSel = tipoItem;
        filtro._statusSel = status;
        return filtro;
    }

    function tipoItemOptions() {
        return ['all', 'História', 'Enabler', 'Spike', 'Teste', 'Débito Técnico'];
    }
    function statusOptions() {
        return ['all', 'Concluído', 'Em andamento'];
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rct">
            <div class="rct-head">
                <div class="rct-head-left">
                    <h2 class="rct-title">⏱️ Cycle Time por Fase</h2>
                    <span class="rct-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rct-pi">PI: ${piLabel}</span>
            </div>

            <div class="rct-filters" role="group" aria-label="Filtros do Cycle Time">
                <div class="rct-filter">
                    <label for="rct-filter-tipo">Tipo de Item:</label>
                    <select id="rct-filter-tipo" class="rct-select" aria-label="Filtrar por Tipo de Item">
                        ${selectOptions(m.tiposItem, m.filtro._tipoItemSel)}
                    </select>
                </div>
                <div class="rct-filter">
                    <label for="rct-filter-status">Status:</label>
                    <select id="rct-filter-status" class="rct-select" aria-label="Filtrar por Status">
                        ${selectOptions(m.statusOpcoes, m.filtro._statusSel)}
                    </select>
                </div>
            </div>

            ${m.body}
        </div>`;
    }

    function selectOptions(values, selected) {
        return values.map(v => {
            const label = (v === 'all') ? 'Todos' : v;
            const sel = (v === selected) ? ' selected' : '';
            return `<option value="${esc(v)}"${sel}>${esc(label)}</option>`;
        }).join('');
    }

    function buildBody(m) {
        return `
        <div class="rct-chart-card">
            <h3 class="rct-card-title">Tempo médio por fase (dias) — por Time e média da Jornada</h3>
            <div class="rct-chart-wrap">
                <canvas id="rct-barras-canvas"></canvas>
            </div>
            ${legend()}
        </div>
        ${buildTable(m)}`;
    }

    function legend() {
        const items = FASES.map(f => `
            <span class="rct-leg-item">
                <span class="rct-leg-dot" style="background:${FASE_COLORS[f]}"></span>
                ${esc(FASE_LABEL[f])}
            </span>`).join('');
        return `<div class="rct-legend">${items}</div>`;
    }

    // Tabela de apoio: Time × fases + linha da média da Jornada. Ausência de
    // dado por fase é marcada com a classe `.rct-nodata` e o texto "Sem dado".
    function buildTable(m) {
        const head = `
            <tr>
                <th class="rct-th-left">Time</th>
                ${FASES.map(f => `<th>${esc(FASE_LABEL[f])}</th>`).join('')}
            </tr>`;

        const rows = m.porTime.map(linha => {
            const cells = FASES.map(f => cellTd(linha[f])).join('');
            return `<tr><td class="rct-td-left">${esc(linha.time)}</td>${cells}</tr>`;
        }).join('');

        const mediaCells = FASES.map(f => cellTd(m.mediaJornada[f])).join('');
        const mediaRow = `
            <tr class="rct-row-media">
                <td class="rct-td-left">Média da Jornada</td>
                ${mediaCells}
            </tr>`;

        return `
        <div class="rct-table-card">
            <table class="rct-table">
                <thead>${head}</thead>
                <tbody>${rows}${mediaRow}</tbody>
            </table>
        </div>`;
    }

    function cellTd(value) {
        if (!hasData(value)) {
            return `<td class="rct-nodata" title="Sem dado para a fase">Sem dado</td>`;
        }
        return `<td>${esc(fmtDias(value))}</td>`;
    }

    function neutralBlock(pi) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        return `
        <div class="rct-neutral">
            <div class="rct-neutral-icon" aria-hidden="true">🗓️</div>
            <p class="rct-neutral-text">Sem dados de Cycle Time para o PI ${piLabel} (ou filtro aplicado).</p>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="rct">
            <div class="rct-neutral">
                <div class="rct-neutral-icon" aria-hidden="true">⚠️</div>
                <p class="rct-neutral-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // EVENTOS (filtros) — recalcula ao mudar Tipo de Item/Status (Req 13.3)
    // =========================================================================
    function wireControls(container, opts) {
        if (!container || typeof container.querySelector !== 'function') return;

        const selTipo = container.querySelector('#rct-filter-tipo');
        const selStatus = container.querySelector('#rct-filter-status');

        const onChange = () => {
            if (!container.dataset) container.dataset = {};
            if (selTipo) container.dataset.rctTipoItem = selTipo.value;
            if (selStatus) container.dataset.rctStatus = selStatus.value;
            // Re-renderiza o próprio painel aplicando o novo filtro.
            render(container, opts || {});
        };

        if (selTipo && typeof selTipo.addEventListener === 'function') {
            selTipo.addEventListener('change', onChange);
        }
        if (selStatus && typeof selStatus.addEventListener === 'function') {
            selStatus.addEventListener('change', onChange);
        }
    }

    // =========================================================================
    // CHART (Chart.js) — barras agrupadas: dataset por fase, categorias = Times
    // + "Média da Jornada". Valores ausentes viram null (barra ausente/gap).
    // =========================================================================
    function destroyCharts() {
        if (charts.barras) { try { charts.barras.destroy(); } catch (e) {} charts.barras = null; }
    }

    function drawBarras(container, m) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rct-barras-canvas');
        if (!canvas) return;

        const labels = m.porTime.map(l => l.time).concat(['Média da Jornada']);

        const datasets = FASES.map(f => {
            const dados = m.porTime.map(l => hasData(l[f]) ? l[f] : null);
            dados.push(hasData(m.mediaJornada[f]) ? m.mediaJornada[f] : null);
            return {
                label: FASE_LABEL[f],
                data: dados,
                backgroundColor: FASE_COLORS[f],
                borderWidth: 0
            };
        });

        charts.barras = new Chart(canvas, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const v = ctx.parsed.y;
                                return `${ctx.dataset.label}: ${(v == null || !isFinite(v)) ? 'Sem dado' : v.toFixed(1) + ' d'}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'dias', color: '#94a3b8' },
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
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroCycleTime = { render };
    }

    // Guarda de ambiente para teste em Node (Vitest) sem afetar o <script>.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { render };
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroCycleTime = { render };
    }
})();
