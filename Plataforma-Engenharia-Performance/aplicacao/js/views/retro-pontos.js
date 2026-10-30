/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Pontos Trabalhados (`js/views/retro-pontos.js`)
 *
 * Task 17.1 (spec score-performance-executivo). Implementa o Requirement 10 e a
 * seção de design "Módulo de Retro PI → Painéis → Pontos Trabalhados":
 *
 *   - Barras empilhadas por Sprint: Entrega de Valor vs Sustentação.   (10.1)
 *   - Total por Sprint e percentual de cada categoria sobre o total.   (10.2)
 *   - Indicadores Planejado / Real / PI Planning / Velocidade.         (10.3)
 *   - Série de pontos trabalhados por Sprint dentro do PI.             (10.4)
 *   - Ausência de dado por Sprint indicada visualmente (cinza).        (10.5)
 *
 * Registro: `window.PEPViews.retroPontos = { render }`. O `app.js` chama
 * `render(container, { pi })` ao trocar PI/sub-aba. O render é isolado em
 * try/catch pelo próprio app.js; protegemos internamente também.
 *
 * Dependências (globais via <script> no browser; via require() sob guarda de
 * ambiente em Node/Vitest): RetroAggregation (retro-aggregation.js),
 * PEPRetroData (retro-data.js), Chart (Chart.js via CDN). App estático, tema
 * escuro. CSS: prefixo EXCLUSIVO `.rpt-*`.
 */

(function () {
    'use strict';

    const charts = { barras: null };

    const CAT_COLORS = {
        entregaValor: '#22c55e', // verde — valor novo
        sustentacao: '#f59e0b'   // laranja — manutenção/incidentes
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

    const hasData = (n) => (typeof n === 'number' && isFinite(n));
    const fmtNum = (n) => hasData(n) ? n.toFixed(0) : '—';
    const fmtPct = (ratio) => hasData(ratio) ? `${(ratio * 100).toFixed(0)}%` : '—';

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);

        // Squad/jornada selecionados nos filtros (sentinela "all" = Todos).
        const squadSel = (filter.squad != null && filter.squad !== 'all') ? filter.squad : null;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : null;

        const RetroAggregation = dep('RetroAggregation', '../retro-aggregation.js');
        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        const PEPIdentity = dep('PEPIdentity', '../identity.js');

        if (!RetroAggregation || typeof RetroAggregation.pontosPorSprint !== 'function') {
            container.innerHTML = errorBlock('Módulo de agregação de Retro PI indisponível.');
            return;
        }

        // Os Pontos Trabalhados (Velocity) são registrados por JORNADA — não há
        // quebra por squad individual nesta base. Quando o usuário filtra por um
        // Squad, resolvemos a Jornada correspondente (via identidade canônica) e
        // filtramos a Velocity dessa Jornada. Assim o filtro deixa de "não trazer
        // nada" e passa a mostrar a Velocity da Jornada do Squad selecionado.
        let jornadaAlvo = jornadaSel;
        if (!jornadaAlvo && squadSel && PEPIdentity && typeof PEPIdentity.jornadaOf === 'function') {
            try { jornadaAlvo = PEPIdentity.jornadaOf(squadSel) || null; } catch (_) { jornadaAlvo = null; }
        }
        // Fallback: quando o squad informado já é um nome de jornada (compat.).
        const time = (!jornadaAlvo && squadSel) ? squadSel
            : (opts && opts.time != null) ? opts.time : null;

        destroyCharts();

        let linhas;
        try {
            const sprint = (filter.sprint != null && filter.sprint !== 'all') ? filter.sprint : undefined;
            // `jornada` é a dimensão real da Velocity; `time` cobre o legado.
            linhas = RetroAggregation.pontosPorSprint(pi, { time, jornada: jornadaAlvo || undefined, sprint }) || [];
        } catch (e) {
            container.innerHTML = errorBlock('Falha ao agregar os Pontos Trabalhados.');
            return;
        }

        // Jornada exibida: a resolvida do filtro, senão a do 1º registro, senão a padrão.
        const jornadaPadrao = (PEPRetroData && PEPRetroData.JORNADA) ? PEPRetroData.JORNADA : 'Jornada';
        const jornada = jornadaAlvo || (linhas[0] && linhas[0].jornada) || jornadaPadrao;

        // Rótulo do escopo apresentado (Squad → Jornada) para cabeçalho/gráfico/tabela.
        // Sem Squad e sem Jornada selecionados, o painel agrega todas as jornadas.
        const semRecorte = !squadSel && !jornadaAlvo;
        const escopo = semRecorte ? 'Todas as Jornadas' : buildEscopoLabel({ squadSel, jornada });

        // Indicadores Planejado/Real/PI Planning/Velocidade (do 1º registro com dado).
        const resumo = extrairResumo(linhas);

        if (linhas.length === 0) {
            container.innerHTML = buildShell({ pi, jornada, escopo, resumo, body: neutralBlock(pi, escopo) });
            return;
        }

        container.innerHTML = buildShell({
            pi, jornada, escopo, resumo,
            body: buildBody({ linhas, jornada, escopo })
        });

        try {
            drawBarras(container, linhas);
        } catch (e) {
            console.error('retro-pontos barras:', e);
        }
    }

    // Monta o rótulo do escopo exibido. Quando há Squad selecionado, mostra
    // "Squad (Jornada)" deixando claro que a Velocity é a da Jornada do Squad;
    // caso contrário mostra apenas a Jornada.
    function buildEscopoLabel(m) {
        const jornada = m.jornada || 'Jornada';
        if (m.squadSel && String(m.squadSel) !== jornada) {
            return `${m.squadSel} · ${jornada}`;
        }
        return jornada;
    }

    function extrairResumo(linhas) {
        const r = linhas.find(l => hasData(l.planejado) || hasData(l.real)
            || hasData(l.piPlanning) || hasData(l.velocidade));
        return {
            planejado: r ? r.planejado : null,
            real: r ? r.real : null,
            piPlanning: r ? r.piPlanning : null,
            velocidade: r ? r.velocidade : null
        };
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        // Escopo apresentado (Squad · Jornada). Mantém `rpt-sub` para compat.
        const escopo = m.escopo || m.jornada;
        return `
        <div class="rpt">
            <div class="rpt-head">
                <div class="rpt-head-left">
                    <h2 class="rpt-title">📦 Pontos Trabalhados</h2>
                    <span class="rpt-sub">${esc(escopo)}</span>
                </div>
                <span class="rpt-pi">PI: ${piLabel}</span>
            </div>

            ${kpiRow(m.resumo)}

            ${m.body}
        </div>`;
    }

    // Cartões de indicadores (Requirement 10.3).
    function kpiRow(resumo) {
        const cards = [
            { label: 'Planejado', value: resumo.planejado },
            { label: 'Real', value: resumo.real },
            { label: 'PI Planning', value: resumo.piPlanning },
            { label: 'Velocidade', value: resumo.velocidade }
        ].map(c => `
            <div class="rpt-kpi">
                <span class="rpt-kpi-label">${esc(c.label)}</span>
                <span class="rpt-kpi-value">${esc(fmtNum(c.value))}</span>
            </div>`).join('');
        return `<div class="rpt-kpis">${cards}</div>`;
    }

    function buildBody(m) {
        const escopo = m.escopo || m.jornada;
        return `
        <div class="rpt-chart-card">
            <h3 class="rpt-card-title">Pontos por Sprint — Entrega de Valor vs Sustentação</h3>
            <p class="rpt-card-scope">${esc(escopo)}</p>
            <div class="rpt-chart-wrap">
                <canvas id="rpt-barras-canvas"></canvas>
            </div>
            ${legend()}
        </div>
        ${buildTable(m)}`;
    }

    function legend() {
        return `
        <div class="rpt-legend">
            <span class="rpt-leg-item"><span class="rpt-leg-dot" style="background:${CAT_COLORS.entregaValor}"></span>Entrega de Valor</span>
            <span class="rpt-leg-item"><span class="rpt-leg-dot" style="background:${CAT_COLORS.sustentacao}"></span>Sustentação</span>
        </div>`;
    }

    // Tabela: Sprint × (Entrega de Valor, Sustentação, Total, % Valor, % Sust).
    function buildTable(m) {
        const escopo = m.escopo || m.jornada;
        const caption = `<caption class="rpt-table-caption">${esc(escopo)}</caption>`;
        const head = `
            <tr>
                <th class="rpt-th-left">Sprint</th>
                <th>Entrega de Valor</th>
                <th>Sustentação</th>
                <th>Total</th>
                <th>% Valor</th>
                <th>% Sustentação</th>
            </tr>`;

        const rows = m.linhas.map(l => {
            const semDado = !hasData(l.total) || l.total === 0;
            const cls = semDado ? ' class="rpt-row-nodata"' : '';
            return `
            <tr${cls}>
                <td class="rpt-td-left">${esc(l.sprint)}</td>
                <td>${esc(fmtNum(l.entregaValor))}</td>
                <td>${esc(fmtNum(l.sustentacao))}</td>
                <td>${esc(fmtNum(l.total))}</td>
                <td>${esc(fmtPct(l.pct ? l.pct.entregaValor : null))}</td>
                <td>${esc(fmtPct(l.pct ? l.pct.sustentacao : null))}</td>
            </tr>`;
        }).join('');

        return `
        <div class="rpt-table-card">
            <table class="rpt-table">
                ${caption}
                <thead>${head}</thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    function neutralBlock(pi, escopo) {
        const piLabel = (pi != null) ? esc(String(pi)) : 'selecionado';
        const escopoTxt = escopo ? ` — ${esc(escopo)}` : '';
        return `
        <div class="rpt-neutral">
            <div class="rpt-neutral-icon" aria-hidden="true">🗓️</div>
            <p class="rpt-neutral-text">Sem dados de Pontos Trabalhados para o PI ${piLabel}${escopoTxt}.</p>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="rpt">
            <div class="rpt-neutral">
                <div class="rpt-neutral-icon" aria-hidden="true">⚠️</div>
                <p class="rpt-neutral-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // CHART — barras empilhadas por Sprint (Entrega de Valor + Sustentação).
    // =========================================================================
    function destroyCharts() {
        if (charts.barras) { try { charts.barras.destroy(); } catch (e) {} charts.barras = null; }
    }

    function drawBarras(container, linhas) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rpt-barras-canvas');
        if (!canvas) return;

        const labels = linhas.map(l => l.sprint);
        const datasets = [
            {
                label: 'Entrega de Valor',
                data: linhas.map(l => hasData(l.entregaValor) ? l.entregaValor : null),
                backgroundColor: CAT_COLORS.entregaValor,
                borderWidth: 0
            },
            {
                label: 'Sustentação',
                data: linhas.map(l => hasData(l.sustentacao) ? l.sustentacao : null),
                backgroundColor: CAT_COLORS.sustentacao,
                borderWidth: 0
            }
        ];

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
                                return `${ctx.dataset.label}: ${(v == null || !isFinite(v)) ? 'Sem dado' : v.toFixed(0) + ' pts'}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(15,23,42,0.08)' } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'pontos', color: '#94a3b8' },
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
    const api = { render, extrairResumo };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroPontos = { render };
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroPontos = { render };
    }
})();
