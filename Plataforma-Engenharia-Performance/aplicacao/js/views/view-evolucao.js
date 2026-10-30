/**
 * PEP - Plataforma de Engenharia de Performance
 * Evolução Mensal (`js/views/view-evolucao.js`)
 *
 * Task 9.4 (spec score-performance-executivo). Implementa o Requirement 6 e a
 * seção de design "Components and Interfaces → 6. Visões executivas →
 * view-evolucao":
 *
 *   - Tabela com os meses de Jan a Dez em COLUNAS e as Jornadas + o total de
 *     Engenharia em LINHAS.                                                (6.1)
 *   - Em cada célula COM DADO, o score realizado da Jornada no mês.        (6.2)
 *   - Cada célula é colorida pelo Farol calculado como
 *     `realizado ÷ meta projetada do mês` (Na meta ≥ 99%, Atenção ≥ 80% e
 *     < 99%, Crítico < 80%), usando Targets.farol como FONTE ÚNICA.        (6.3)
 *   - Célula SEM DADO para a jornada/mês aparece em CINZA (estado neutro). (6.4)
 *   - Alternância do modo de exibição entre "Score realizado" e
 *     "% da meta".                                                         (6.5)
 *
 * Registro: `window.PEPViews.evolucao = { render }`. O `app.js` chama
 * `render(container, { month })` ao trocar mês/aba e isola o render em
 * try/catch; ainda assim protegemos internamente para não derrubar outras
 * visões. O `month` recebido é usado apenas para destacar a coluna do mês
 * selecionado (a tabela mostra o ano inteiro, Jan–Dez).
 *
 * Reutiliza os módulos já implementados (globais via <script> no browser, ou
 * require() em Node/Vitest sob guarda de ambiente): Aggregation
 * (journeyScore/engineeringScore por mês), Targets (metaMes, farol,
 * atingimento), PEPData (meses com dado, JORNADAS, MONTH_LABELS) e AppState.
 * App estático, tema escuro. Prefixo CSS exclusivo desta visão: `.ve-*`.
 */

(function () {
    'use strict';

    // Modo de exibição corrente (persistido entre re-renders da própria visão).
    //   'score' → mostra o score realizado por célula (Requirement 6.2)
    //   'meta'  → mostra o % da meta (atingimento) por célula (Requirement 6.5)
    let displayMode = 'score';

    // Cores por Farol (consistentes com as demais visões / tema escuro).
    const FAROL_META = {
        'na-meta': { label: 'Na meta', color: '#22c55e' },
        'atencao': { label: 'Atenção', color: '#f59e0b' },
        'critico': { label: 'Crítico', color: '#ef4444' }
    };

    // Linha especial que representa o total de Engenharia (não é jornada).
    const ENGENHARIA_ROW = '__engenharia__';

    // -------------------------------------------------------------------------
    // Helpers de dependência (guarda defensiva; não quebra se faltar algo).
    // No browser os módulos existem como globais; em Node (teste) resolvemos via
    // require() relativo sob guarda de ambiente — mesmo padrão de view-jornadas
    // e view-metricas (NÃO use o padrão só-window, para os testes passarem).
    // -------------------------------------------------------------------------
    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                Aggregation: '../aggregation.js',
                Targets: '../targets.js',
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

    const fmtScore = (n) => (typeof n === 'number' && isFinite(n)) ? n.toFixed(0) : '—';
    const fmtPct = (ratio) => (typeof ratio === 'number' && isFinite(ratio))
        ? `${(ratio * 100).toFixed(0)}%` : '—';

    // -------------------------------------------------------------------------
    // Score realizado de uma entidade (jornada por nome, ou Engenharia quando
    // o nome é ENGENHARIA_ROW) em um mês. Retorna null quando não há dado.
    // -------------------------------------------------------------------------
    function entityScore(Aggregation, month, rowKey) {
        if (rowKey === ENGENHARIA_ROW) {
            const eng = Aggregation.engineeringScore(month);
            return (eng && eng.hasData) ? eng.avgScore : null;
        }
        const js = Aggregation.journeyScore(month, rowKey);
        return (js && js.hasData) ? js.avgScore : null;
    }

    // -------------------------------------------------------------------------
    // Atingimento (realizado ÷ meta do mês) de uma entidade no mês.
    //   - Jornada: Targets.atingimento(jornada, mês, realizado).
    //   - Engenharia: usa a meta de Engenharia como MÉDIA das metas do mês das
    //     jornadas (coerente com o realizado de Engenharia, que é a média das
    //     jornadas), e divide o realizado médio por essa meta média.
    // Retorna null quando a meta/atingimento é indisponível.
    // -------------------------------------------------------------------------
    function entityAtingimento(Targets, PEPData, month, rowKey, realizado) {
        if (typeof realizado !== 'number' || !isFinite(realizado)) return null;

        if (rowKey !== ENGENHARIA_ROW) {
            try {
                return Targets.atingimento(rowKey, month, realizado);
            } catch (e) {
                return null;
            }
        }

        // Engenharia: meta média do mês entre as jornadas com baseline válido.
        const jornadas = (PEPData && PEPData.JORNADAS) ? PEPData.JORNADAS : [];
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
        return realizado / metaMedia;
    }

    // -------------------------------------------------------------------------
    // Modelo de uma célula (jornada/mês): realizado, atingimento e Farol.
    //   { hasData, score, atingimento, farolId }
    // hasData=false quando a entidade não tem dado no mês → célula cinza (6.4).
    // -------------------------------------------------------------------------
    function buildCell(Aggregation, Targets, PEPData, month, rowKey) {
        const score = entityScore(Aggregation, month, rowKey);
        if (score === null) {
            return { hasData: false, score: null, atingimento: null, farolId: null };
        }
        const at = entityAtingimento(Targets, PEPData, month, rowKey, score);
        const farolId = Targets ? Targets.farol(at) : null;
        return { hasData: true, score, atingimento: at, farolId };
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;
        const selectedMonth = (opts && typeof opts.month === 'number') ? opts.month : null;

        const Aggregation = dep('Aggregation');
        const Targets = dep('Targets');
        const PEPData = dep('PEPData');

        if (!Aggregation || !Targets || !PEPData) {
            container.innerHTML = errorBlock('Módulos de cálculo indisponíveis.');
            return;
        }

        // Permite forçar o modo via chamada externa (ex.: testes).
        if (opts && (opts.mode === 'score' || opts.mode === 'meta')) {
            displayMode = opts.mode;
        }

        const jornadas = PEPData.JORNADAS || [];
        const monthLabels = Array.isArray(PEPData.MONTH_LABELS) ? PEPData.MONTH_LABELS
            : ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        // Tabela sempre cobre o ano inteiro (Jan..Dez = 1..12), independentemente
        // de quais meses têm dado (as células sem dado ficam cinza — 6.1/6.4).
        const months = [];
        for (let m = 1; m <= 12; m++) months.push(m);

        // Linhas: todas as jornadas + o total de Engenharia (6.1).
        const rowKeys = [...jornadas, ENGENHARIA_ROW];

        // Pré-computa a matriz de células (linha × mês).
        const matrix = {};
        for (const rowKey of rowKeys) {
            matrix[rowKey] = {};
            for (const m of months) {
                matrix[rowKey][m] = buildCell(Aggregation, Targets, PEPData, m, rowKey);
            }
        }

        container.innerHTML = buildHTML({
            months,
            monthLabels,
            rowKeys,
            matrix,
            displayMode,
            selectedMonth
        });

        // Cabeamento do seletor de modo (Score realizado / % da meta) — 6.5.
        wireModeToggle(container, selectedMonth);
    }

    // Liga os botões de modo ao re-render interno. Defensivo para ambientes sem
    // DOM real (o stub de teste não tem addEventListener nos botões).
    function wireModeToggle(container, selectedMonth) {
        if (!container || typeof container.querySelector !== 'function') return;
        const btnScore = container.querySelector('#ve-mode-score');
        const btnMeta = container.querySelector('#ve-mode-meta');
        if (btnScore && typeof btnScore.addEventListener === 'function') {
            btnScore.addEventListener('click', () => {
                displayMode = 'score';
                render(container, { month: selectedMonth });
            });
        }
        if (btnMeta && typeof btnMeta.addEventListener === 'function') {
            btnMeta.addEventListener('click', () => {
                displayMode = 'meta';
                render(container, { month: selectedMonth });
            });
        }
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        const PEPData = dep('PEPData');

        // Cabeçalho das colunas (Jan..Dez), destacando o mês selecionado.
        const headCells = m.months.map((month) => {
            const label = m.monthLabels[month - 1] || `M${month}`;
            const sel = (month === m.selectedMonth) ? ' ve-col-selected' : '';
            return `<th class="ve-th ve-th-month${sel}" scope="col">${esc(label)}</th>`;
        }).join('');

        // Linhas de jornada + a linha de Engenharia (destacada).
        const bodyRows = m.rowKeys.map((rowKey) => rowHTML(m, rowKey)).join('');

        const scoreActive = m.displayMode === 'score';
        const legend = farolLegend();

        return `
        <div class="ve">
            <div class="ve-head">
                <h2 class="ve-title">📅 Evolução Mensal</h2>
                <div class="ve-mode" role="group" aria-label="Modo de exibição">
                    <button type="button" id="ve-mode-score"
                        class="ve-mode-btn${scoreActive ? ' ve-mode-active' : ''}"
                        aria-pressed="${scoreActive ? 'true' : 'false'}">Score realizado</button>
                    <button type="button" id="ve-mode-meta"
                        class="ve-mode-btn${!scoreActive ? ' ve-mode-active' : ''}"
                        aria-pressed="${!scoreActive ? 'true' : 'false'}">% da meta</button>
                </div>
            </div>

            <div class="ve-table-wrap">
                <table class="ve-table">
                    <thead>
                        <tr>
                            <th class="ve-th ve-th-row" scope="col">Jornada</th>
                            ${headCells}
                        </tr>
                    </thead>
                    <tbody>
                        ${bodyRows}
                    </tbody>
                </table>
            </div>

            ${legend}
        </div>`;
    }

    function rowHTML(m, rowKey) {
        const PEPData = dep('PEPData');
        const isEng = rowKey === ENGENHARIA_ROW;
        const rowLabel = isEng ? 'Engenharia (total)' : rowKey;
        const rowClass = isEng ? 've-row ve-row-eng' : 've-row';

        const cells = m.months.map((month) => {
            const cell = m.matrix[rowKey][month];
            const sel = (month === m.selectedMonth) ? ' ve-col-selected' : '';
            return cellHTML(cell, m.displayMode, sel);
        }).join('');

        return `
        <tr class="${rowClass}">
            <th class="ve-td ve-td-row" scope="row" title="${esc(rowLabel)}">${esc(rowLabel)}</th>
            ${cells}
        </tr>`;
    }

    // Uma célula da matriz. Sem dado → cinza (6.4). Com dado → colorida pelo
    // Farol (6.3) e exibe score (6.2) ou % da meta (6.5) conforme o modo.
    function cellHTML(cell, displayMode, selClass) {
        if (!cell || !cell.hasData) {
            return `<td class="ve-cell ve-cell-empty${selClass}" title="Sem dado">—</td>`;
        }

        const farol = cell.farolId ? FAROL_META[cell.farolId] : null;
        // Classe de Farol para colorir a célula; quando o atingimento é
        // indisponível (sem meta/baseline), a célula fica neutra mas COM dado.
        const farolClass = cell.farolId ? ` ve-farol-${cell.farolId}` : ' ve-farol-none';

        const text = (displayMode === 'meta')
            ? fmtPct(cell.atingimento)
            : fmtScore(cell.score);

        // Tooltip rico: sempre mostra score e % da meta (quando disponível).
        const pctPart = (typeof cell.atingimento === 'number' && isFinite(cell.atingimento))
            ? ` · ${fmtPct(cell.atingimento)} da meta` : '';
        const farolPart = farol ? ` · ${farol.label}` : '';
        const title = `${fmtScore(cell.score)} pts${pctPart}${farolPart}`;

        return `<td class="ve-cell${farolClass}${selClass}" title="${esc(title)}">
            <span class="ve-cell-val">${esc(text)}</span>
        </td>`;
    }

    function farolLegend() {
        const items = ['na-meta', 'atencao', 'critico'].map((id) => {
            const f = FAROL_META[id];
            return `
            <span class="ve-leg-item">
                <span class="ve-leg-dot ve-farol-${id}" style="background:${f.color}"></span>
                ${f.label}
            </span>`;
        }).join('');
        return `
        <div class="ve-legend">
            ${items}
            <span class="ve-leg-item">
                <span class="ve-leg-dot ve-cell-empty"></span>
                Sem dado
            </span>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="ve">
            <div class="ve-empty">
                <div class="ve-empty-icon" aria-hidden="true">⚠️</div>
                <p class="ve-empty-text">${esc(msg)}</p>
            </div>
        </div>`;
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = {
        render,
        buildHTML,
        buildCell,
        entityScore,
        entityAtingimento,
        // Reset do estado interno (útil em testes para isolar re-renders).
        _resetState: () => { displayMode = 'score'; }
    };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.evolucao = { render };
    }

    // Guarda de ambiente: expõe para testes (Vitest) sem afetar o <script>.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
