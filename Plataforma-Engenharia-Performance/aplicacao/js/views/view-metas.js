/**
 * PEP - Plataforma de Engenharia de Performance
 * Visão de Metas (`js/views/view-metas.js`)
 *
 * Task 9.5 (spec score-performance-executivo). Implementa o Requirement 7
 * (7.1–7.7) e a seção de design "Components and Interfaces → 6. Visões
 * executivas → view-metas":
 *
 *   - Tabela com as colunas:
 *       Jornada · Partida dez/25 · Meta % · Meta 2026 · acréscimo/mês ·
 *       Meta do Mês (Jan..Dez).                                        (7.6)
 *   - Partida de cada Jornada = score realizado de dez/2025.           (7.1)
 *   - Meta 2026 = Partida × (1 + Meta %).                              (7.2, 7.7)
 *   - Meta do Mês = Partida + (Meta2026 − Partida) ÷ 12 × nº do mês.   (7.3)
 *   - acréscimo/mês = (Meta2026 − Partida) ÷ 12.                       (7.6)
 *   - Atingimento = realizado do mês ÷ meta do mês.                    (7.4)
 *   - Farol por Jornada no MÊS SELECIONADO (Na meta ≥99% / Atenção
 *     ≥80% e <99% / Crítico <80%), usando Targets.farol como fonte
 *     ÚNICA sobre o Atingimento.                                      (7.5)
 *
 * TODOS os cálculos (partida, meta2026, metaMes, acrescimoMensal,
 * atingimento, farol) são delegados ao módulo `targets.js` (fonte única das
 * regras de meta e do Farol). O "realizado" por Jornada no mês vem de
 * `aggregation.js` (média dos times da jornada). Nenhum número de meta é
 * recalculado localmente, evitando divergências.
 *
 * META_PCT (via Targets): Secretaria e Gestor de Unidades = 0,145 (14,5%);
 * demais jornadas = 0,05 (5%).                                        (7.7)
 *
 * Registro: `window.PEPViews.metas = { render }`. O `app.js` chama
 * `render(container, { month })` ao trocar mês/aba e isola o render em
 * try/catch; ainda assim protegemos internamente para não derrubar outras
 * visões.
 *
 * Reutiliza os módulos já implementados: Targets, Aggregation, PEPData e,
 * defensivamente, AppState (para o mês selecionado quando não informado).
 * App estático, tema escuro. Esta visão é uma TABELA — não usa Chart.js.
 *
 * Convenção de CSS: todas as classes usam o prefixo exclusivo `.mt-` para
 * não colidir com as demais visões.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Resolução de dependências (guarda defensiva). No browser os módulos são
    // globais (via <script>); em Node (Vitest) resolvemos via require() sob
    // guarda de ambiente — mesmo padrão de view-jornadas.js / view-metricas.js.
    // -------------------------------------------------------------------------
    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                Targets: '../targets.js',
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

    // Score com 0 casas (metas são pontos de score). "—" quando indisponível.
    const fmtScore = (n) => (typeof n === 'number' && isFinite(n)) ? n.toFixed(0) : '—';
    // Acréscimo mensal com 1 casa (valor pequeno; mostra a progressão).
    const fmtAcr = (n) => (typeof n === 'number' && isFinite(n)) ? n.toFixed(1) : '—';
    // Percentual (0..1 → %). Usado para Meta % e para Atingimento.
    const fmtPct = (ratio) => (typeof ratio === 'number' && isFinite(ratio))
        ? `${(ratio * 100).toFixed(1)}%` : 'Indisponível';

    const FAROL_META = {
        'na-meta': { label: 'Na meta', color: '#22c55e' },
        'atencao': { label: 'Atenção', color: '#f59e0b' },
        'critico': { label: 'Crítico', color: '#ef4444' }
    };

    // -------------------------------------------------------------------------
    // Realizado de uma jornada no mês selecionado (via Aggregation). Retorna o
    // avgScore quando a jornada tem dado no mês, ou null caso contrário.
    // -------------------------------------------------------------------------
    function journeyRealized(month, journeyName) {
        const Aggregation = dep('Aggregation');
        if (!Aggregation || typeof month !== 'number') return null;
        try {
            const js = Aggregation.journeyScore(month, journeyName);
            return (js && js.hasData && typeof js.avgScore === 'number') ? js.avgScore : null;
        } catch (e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Monta o modelo de uma linha da tabela (uma jornada). Todos os valores de
    // meta vêm de Targets; o realizado e o atingimento referem-se ao MÊS
    // SELECIONADO (para o Farol da linha).
    // -------------------------------------------------------------------------
    function buildRow(Targets, journeyName, month, monthsCount) {
        const partida = Targets.partida(journeyName);
        const hasPartida = (typeof partida === 'number' && isFinite(partida));

        const metaPct = Targets.metaPct(journeyName);
        const meta2026 = hasPartida ? Targets.meta2026(journeyName) : null;
        const acrescimo = hasPartida ? Targets.acrescimoMensal(journeyName) : null;

        // Meta de cada mês Jan..Dez (nº do mês = 1..12).
        const metasMes = [];
        for (let m = 1; m <= monthsCount; m++) {
            metasMes.push(hasPartida ? Targets.metaMes(journeyName, m) : null);
        }

        // Realizado e Atingimento no mês selecionado → Farol da jornada (7.4/7.5).
        const realizado = journeyRealized(month, journeyName);
        const atingimento = (hasPartida && typeof realizado === 'number')
            ? Targets.atingimento(journeyName, month, realizado)
            : null;
        const farolId = Targets.farol(atingimento);

        return {
            journey: journeyName,
            partida: hasPartida ? partida : null,
            metaPct,
            meta2026,
            acrescimo,
            metasMes,
            realizado,
            atingimento,
            farolId
        };
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;

        const Targets = dep('Targets');
        const PEPData = dep('PEPData');

        if (!Targets || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        // Mês selecionado: via opts, ou AppState, ou último mês com dados.
        let month = (opts && typeof opts.month === 'number') ? opts.month : null;
        if (month == null) {
            const AppState = dep('AppState');
            if (AppState && typeof AppState.getSelectedMonth === 'function') {
                try { month = AppState.getSelectedMonth(); } catch (e) { /* ignore */ }
            }
        }
        if (month == null && typeof PEPData.getLatestMonth === 'function') {
            month = PEPData.getLatestMonth();
        }

        const monthLabels = Array.isArray(PEPData.MONTH_LABELS) ? PEPData.MONTH_LABELS : [];
        const monthsCount = monthLabels.length || 12;
        const monthLabel = (month != null && monthLabels[month - 1]) ? monthLabels[month - 1]
            : (month != null ? `Mês ${month}` : '—');

        const jornadas = PEPData.JORNADAS || [];
        const rows = jornadas.map((name) => buildRow(Targets, name, month, monthsCount));

        container.innerHTML = buildHTML({ monthLabel, month, monthLabels, monthsCount, rows });
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        const monthLabels = m.monthLabels.length ? m.monthLabels
            : Array.from({ length: m.monthsCount }, (_, i) => `M${i + 1}`);

        // Cabeçalho: colunas fixas + metas Jan..Dez. A coluna do mês selecionado
        // é destacada (classe .mt-col-sel) para orientar a leitura do Farol.
        const monthHeads = monthLabels.map((lbl, i) => {
            const selCls = (m.month === (i + 1)) ? ' mt-col-sel' : '';
            return `<th class="mt-th mt-th-mes${selCls}" scope="col">${esc(lbl)}</th>`;
        }).join('');

        const bodyRows = m.rows.map((r) => dataRow(r, m.month, m.monthsCount)).join('');

        return `
        <div class="mt">
            <div class="mt-head">
                <h2 class="mt-title">🎯 Metas</h2>
                <span class="mt-month">${esc(m.monthLabel)}</span>
            </div>

            <p class="mt-legend">
                <span class="mt-legend-item"><span class="mt-dot" style="background:${FAROL_META['na-meta'].color}"></span>Na meta ≥ 99%</span>
                <span class="mt-legend-item"><span class="mt-dot" style="background:${FAROL_META['atencao'].color}"></span>Atenção ≥ 80% e &lt; 99%</span>
                <span class="mt-legend-item"><span class="mt-dot" style="background:${FAROL_META['critico'].color}"></span>Crítico &lt; 80%</span>
                <span class="mt-legend-note">Farol calculado sobre o atingimento do mês selecionado (realizado ÷ meta do mês).</span>
            </p>

            <div class="mt-table-wrap">
                <table class="mt-table">
                    <thead>
                        <tr>
                            <th class="mt-th mt-th-jornada" scope="col">Jornada</th>
                            <th class="mt-th mt-th-farol" scope="col">Farol</th>
                            <th class="mt-th mt-th-num" scope="col">Partida dez/25</th>
                            <th class="mt-th mt-th-num" scope="col">Meta %</th>
                            <th class="mt-th mt-th-num" scope="col">Meta 2026</th>
                            <th class="mt-th mt-th-num" scope="col">Acréscimo/mês</th>
                            ${monthHeads}
                        </tr>
                    </thead>
                    <tbody>
                        ${bodyRows}
                    </tbody>
                </table>
            </div>
        </div>`;
    }

    function dataRow(r, selectedMonth, monthsCount) {
        const farol = r.farolId ? FAROL_META[r.farolId] : null;
        const farolCell = farol
            ? `<span class="mt-farol" style="border-color:${farol.color};color:${farol.color}" title="Atingimento ${fmtPct(r.atingimento)}">
                   <span class="mt-farol-dot" style="background:${farol.color}"></span>${farol.label}
               </span>`
            : `<span class="mt-farol mt-muted" style="border-color:var(--border-color)" title="Atingimento indisponível">
                   <span class="mt-farol-dot" style="background:var(--text-muted)"></span>—
               </span>`;

        // Células das metas mensais; a do mês selecionado recebe destaque.
        let metaCells = '';
        for (let i = 0; i < monthsCount; i++) {
            const selCls = (selectedMonth === (i + 1)) ? ' mt-col-sel' : '';
            const v = r.metasMes[i];
            metaCells += `<td class="mt-td mt-td-mes${selCls}">${esc(fmtScore(v))}</td>`;
        }

        return `
        <tr class="mt-row">
            <td class="mt-td mt-td-jornada" title="${esc(r.journey)}">${esc(r.journey)}</td>
            <td class="mt-td mt-td-farol">${farolCell}</td>
            <td class="mt-td mt-td-num">${esc(fmtScore(r.partida))}</td>
            <td class="mt-td mt-td-num">${esc(fmtPct(r.metaPct))}</td>
            <td class="mt-td mt-td-num">${esc(fmtScore(r.meta2026))}</td>
            <td class="mt-td mt-td-num">${esc(fmtAcr(r.acrescimo))}</td>
            ${metaCells}
        </tr>`;
    }

    function errorBlock(msg) {
        return `
        <div class="mt">
            <div class="mt-empty">
                <div class="mt-empty-icon" aria-hidden="true">⚠️</div>
                <p class="mt-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = { render, buildHTML, buildRow, journeyRealized };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.metas = { render };
    }

    // Guarda de ambiente: expõe para testes (Vitest) sem afetar o <script>.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
