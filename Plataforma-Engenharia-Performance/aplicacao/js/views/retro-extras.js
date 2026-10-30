/**
 * PEP - Plataforma de Engenharia de Performance
 * Painel de Carga, Alterações no Plano e Impedimentos (`js/views/retro-extras.js`)
 *
 * Task 19 (spec score-performance-executivo). Implementa o Requirement 17 (três
 * seções num único painel):
 *   - Carga de Trabalho por Tipo de Item: pontos, %pontos, qtde, %itens,
 *     pontuação média.                                                 (17.1)
 *   - Alterações no Plano: Baseline / Plano PI Planning / Mudança de Escopo /
 *     Cancelado / Transbordo (Aceito, Pendentes), em Épicos/Histórias/Pontos. (17.2)
 *   - Impedimentos: quantidade, localização, tempo de remoção por PI e Sprint. (17.3)
 *   - Ausência de dado indicada visualmente.                           (17.4)
 *
 * Registro: `window.PEPViews.retroExtras = { render }`. CSS: prefixo `.rex-`.
 */

(function () {
    'use strict';

    const charts = { carga: null };

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
    const fmtDec = (n) => hasData(n) ? n.toFixed(2) : '—';
    const fmtPct = (r) => hasData(r) ? `${(r * 100).toFixed(1)}%` : '—';

    const DIM_LABEL = { epicos: 'Épicos', historias: 'Histórias', pontos: 'Pontos' };

    function render(container, opts) {
        if (!container) return;
        const filter = (opts && opts.filter) ? opts.filter : {};
        const pi = (opts && opts.pi != null) ? opts.pi : (filter.pi != null && filter.pi !== 'all' ? filter.pi : null);
        const squad = (filter.squad != null && filter.squad !== 'all') ? filter.squad : undefined;
        const jornadaSel = (filter.jornada != null && filter.jornada !== 'all') ? filter.jornada : undefined;

        const PEPRetroData = dep('PEPRetroData', '../retro-data.js');
        if (!PEPRetroData || typeof PEPRetroData.query !== 'function') {
            container.innerHTML = errorBlock('Módulo de dados de Retro PI indisponível.');
            return;
        }

        destroyCharts();

        // Aplica o filtro de jornada/squad quando a coleção o suportar.
        const fq = {};
        if (squad) fq.squad = squad;
        if (jornadaSel) fq.jornada = jornadaSel;
        // Carga: consolidada por Tipo de Item sobre o escopo filtrado (soma as
        // jornadas quando nenhuma é selecionada), evitando tipos duplicados na
        // visão "Todas as Jornadas". Usa RetroAggregation.cargaTotalTrabalho.
        const RetroAgg = dep('RetroAggregation', '../retro-aggregation.js');
        const carga = (RetroAgg && typeof RetroAgg.cargaTotalTrabalho === 'function')
            ? RetroAgg.cargaTotalTrabalho(pi, fq)
            : PEPRetroData.query('cargaTrabalho', { pi, ...fq });
        const alteracoes = PEPRetroData.query('alteracoesPlano', { pi, ...fq });
        // Impedimentos têm natureza histórica (série por PI/Sprint). Mostramos
        // o histórico completo, com destaque opcional ao PI selecionado — e não
        // apenas o PI atual, que esconderia a tendência (fiel à aba da planilha).
        const impedimentos = PEPRetroData.query('impedimentos', {});
        // Escopo exibido reflete o filtro ativo, não uma jornada fixa.
        const jornada = squad || jornadaSel || 'Todas as Jornadas';

        container.innerHTML = buildShell({
            pi, jornada,
            body: `
                ${cargaSection(carga)}
                ${alteracoesSection(alteracoes)}
                ${impedimentosSection(impedimentos)}`
        });

        if (carga.length > 0) {
            try { drawCarga(container, carga); } catch (e) { console.error('retro-extras carga:', e); }
        }
    }

    function buildShell(m) {
        const piLabel = (m.pi != null) ? esc(String(m.pi)) : '—';
        return `
        <div class="rex">
            <div class="rex-head">
                <div class="rex-head-left">
                    <h2 class="rex-title">🧩 Carga, Alterações e Impedimentos</h2>
                    <span class="rex-sub">${esc(m.jornada)}</span>
                </div>
                <span class="rex-pi">PI: ${piLabel}</span>
            </div>
            ${m.body}
        </div>`;
    }

    // --- Carga de Trabalho (17.1) ---
    function cargaSection(carga) {
        if (!carga || carga.length === 0) return sectionEmpty('Carga Total de Trabalho');
        const rows = carga.map(r => `
            <tr>
                <td class="rex-td-left">${esc(r.tipoItem)}</td>
                <td>${esc(fmtNum(r.pontos))}</td>
                <td>${esc(fmtPct(r.pontosPct))}</td>
                <td>${esc(fmtNum(r.qtdeItens))}</td>
                <td>${esc(fmtPct(r.itensPct))}</td>
                <td>${esc(fmtDec(r.pontuacaoMedia))}</td>
            </tr>`).join('');
        return `
        <div class="rex-section">
            <h3 class="rex-card-title">Carga Total de Trabalho — por Tipo de Item</h3>
            <div class="rex-chart-wrap"><canvas id="rex-carga-canvas"></canvas></div>
            <table class="rex-table">
                <thead><tr><th class="rex-th-left">Tipo de Item</th><th>Pontos</th><th>% Pontos</th><th>Qtde Itens</th><th>% Itens</th><th>Pontuação Média</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    // --- Alterações no Plano (17.2) ---
    function alteracoesSection(alteracoes) {
        if (!alteracoes || alteracoes.length === 0) return sectionEmpty('Alterações no Plano');
        const rows = alteracoes.map(a => `
            <tr>
                <td class="rex-td-left">${esc(DIM_LABEL[a.dimensao] || a.dimensao)}</td>
                <td>${esc(fmtNum(a.baseline))}</td>
                <td>${esc(fmtNum(a.planPIPlanning))}</td>
                <td>${esc(fmtNum(a.mudancaEscopo))}</td>
                <td>${esc(fmtNum(a.cancelado))}</td>
                <td>${esc(fmtNum(a.transbordoAceito))}</td>
                <td>${esc(fmtNum(a.transbordoPendentes))}</td>
            </tr>`).join('');
        return `
        <div class="rex-section">
            <h3 class="rex-card-title">Alterações no Plano</h3>
            <table class="rex-table">
                <thead><tr><th class="rex-th-left">Dimensão</th><th>Baseline</th><th>PI Planning</th><th>Mud. Escopo</th><th>Cancelado</th><th>Transbordo Aceito</th><th>Transbordo Pendentes</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    // --- Impedimentos (17.3) ---
    function impedimentosSection(impedimentos) {
        if (!impedimentos || impedimentos.length === 0) return sectionEmpty('Impedimentos');
        const rows = impedimentos.map(i => `
            <tr>
                <td class="rex-td-left">${esc(i.pi)}${i.sprint ? ' · ' + esc(i.sprint) : ''}</td>
                <td>${esc(fmtNum(i.quantidade))}</td>
                <td>${esc(fmtDec(i.localizacao))}</td>
                <td>${esc(fmtDec(i.tempoRemocao))}</td>
            </tr>`).join('');
        return `
        <div class="rex-section">
            <h3 class="rex-card-title">Impedimentos — por PI e Sprint</h3>
            <table class="rex-table">
                <thead><tr><th class="rex-th-left">PI / Sprint</th><th>Quantidade</th><th>Localização</th><th>Tempo de Remoção</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    function sectionEmpty(titulo) {
        return `
        <div class="rex-section">
            <h3 class="rex-card-title">${esc(titulo)}</h3>
            <div class="rex-nodata-block">
                <span class="rex-nodata-icon" aria-hidden="true">🗓️</span>
                <span class="rex-nodata-text">Sem dado para o PI selecionado.</span>
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `<div class="rex"><div class="rex-nodata-block"><span class="rex-nodata-icon" aria-hidden="true">⚠️</span><span class="rex-nodata-text">${esc(msg)}</span></div></div>`;
    }

    function destroyCharts() {
        if (charts.carga) { try { charts.carga.destroy(); } catch (e) {} charts.carga = null; }
    }

    // Rosca de distribuição por Tipo de Item (Carga de Trabalho). Usa Pontos
    // quando a fonte os fornece; caso contrário (coleta A1, sem Story Points)
    // usa a Quantidade de Itens — sempre o dado real disponível.
    function drawCarga(container, carga) {
        if (typeof Chart === 'undefined') return;
        const canvas = container.querySelector('#rex-carga-canvas');
        if (!canvas) return;
        const temPontos = carga.some(r => typeof r.pontos === 'number' && isFinite(r.pontos));
        const usarPontos = temPontos;
        const valores = carga.map(r => usarPontos ? r.pontos : r.qtdeItens);
        const unidade = usarPontos ? 'pts' : 'itens';
        const palette = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#eab308', '#ec4899', '#14b8a6'];
        charts.carga = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: carga.map(r => r.tipoItem),
                datasets: [{ data: valores, backgroundColor: carga.map((_, i) => palette[i % palette.length]), borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '55%',
                plugins: {
                    legend: { position: 'right', labels: { color: '#94a3b8', boxWidth: 12, font: { size: 11 } } },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${Number(ctx.parsed).toFixed(0)} ${unidade}` } }
                }
            }
        });
    }

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.retroExtras = { render };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = { render }; }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.retroExtras = { render };
    }
})();
