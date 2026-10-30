/**
 * PEP - Plataforma de Engenharia de Performance
 * Dashboard legado — aba "Operacional/Times" (Task 10, spec score-performance-executivo)
 *
 * Preserva TODAS as capacidades do MVP original (Requirements 9.1–9.6):
 *   - Ranking de times ordenado por score desc.
 *   - Filtros por Jornada e por Time.
 *   - Detalhamento por métrica (modal) com as 12 métricas.
 *   - Entrada manual de métricas (atualiza o score).
 *   - Exportação CSV (separador ';' e UTF-8 com BOM).
 *   - Identidade visual de tema escuro e padrão de UX.
 *
 * Ajustes desta tarefa:
 *   - Modelo de dados agora é MENSAL (data.js migrado na tarefa 3). O dashboard
 *     monta ranking/figuras a partir dos registros do MÊS SELECIONADO, lido do
 *     AppState. Expõe `Dashboard.setMonth(month)` e `Dashboard.init()` (ambos
 *     chamados por app.js).
 *   - Usa as 4 CATEGORIAS corrigidas do scoring-engine
 *     (Processos/Práticas/Produto/Pessoas) — o legado antigo usava 3 dimensões.
 *   - Entrada manual usa as escalas reconciliadas (percentuais 0..1, Cycle Time
 *     em dias, Team Health Check em 0–10, Frequência de Deploy como categoria).
 *
 * Observação de testabilidade: o dashboard resolve ScoringEngine/PEPData/
 * AppState como GLOBAIS em tempo de execução (carregados por <script> no
 * browser). Sob guarda de ambiente (`module.exports`/`globalThis.Dashboard`)
 * expomos a IIFE para os testes de regressão em Node (Vitest), que montam um
 * `document` stub — sem alterar o carregamento por <script> no browser.
 */

const Dashboard = (() => {
    'use strict';

    // Estado interno do dashboard.
    let currentMonth = null;      // mês de referência (lido do AppState).
    let teamsSnapshot = [];       // times do mês atual (snapshot {id,name,jornada,metrics}).
    let scoredTeams = [];         // times com scoreResult, ordenados por score desc.
    let journeyScores = [];       // scores médios por jornada (ordenados desc).
    let filteredTeams = [];       // times após aplicar os filtros atuais.
    let charts = {};
    let listenersBound = false;   // garante binding único dos eventos da toolbar.
    let currentFilters = {
        jornada: 'all',
        team: 'all'
    };

    // -------------------------------------------------------------------------
    // Resolução de dependências com guarda de ambiente (globais no browser).
    // -------------------------------------------------------------------------
    function engine() {
        const e = (typeof ScoringEngine !== 'undefined') ? ScoringEngine
            : (typeof globalThis !== 'undefined' ? globalThis.ScoringEngine : undefined);
        if (!e) throw new Error('Dashboard: ScoringEngine indisponível');
        return e;
    }

    function data() {
        const d = (typeof PEPData !== 'undefined') ? PEPData
            : (typeof globalThis !== 'undefined' ? globalThis.PEPData : undefined);
        if (!d) throw new Error('Dashboard: PEPData indisponível');
        return d;
    }

    function appState() {
        return (typeof AppState !== 'undefined') ? AppState
            : (typeof globalThis !== 'undefined' ? globalThis.AppState : undefined);
    }

    function doc() {
        return (typeof document !== 'undefined') ? document
            : (typeof globalThis !== 'undefined' ? globalThis.document : undefined);
    }

    function byId(id) {
        const d = doc();
        return d && typeof d.getElementById === 'function' ? d.getElementById(id) : null;
    }

    // Mês de referência: usa o informado, senão o do AppState, senão o último
    // mês com dados do dataset.
    function resolveMonth(month) {
        if (typeof month === 'number' && month >= 1 && month <= 12) return month;
        const st = appState();
        if (st && typeof st.getSelectedMonth === 'function') {
            const m = st.getSelectedMonth();
            if (typeof m === 'number') return m;
        }
        const d = data();
        if (typeof d.getLatestMonth === 'function') {
            const latest = d.getLatestMonth();
            if (typeof latest === 'number') return latest;
        }
        return 1;
    }

    // =========================================================================
    // INITIALIZATION
    // =========================================================================
    function init() {
        currentMonth = resolveMonth(currentMonth);
        rebuildScores();

        renderFilters();
        renderDashboard();
        setupEventListeners();
    }

    // Troca o mês de referência (chamado por app.js quando a aba fica ativa ou
    // quando o Seletor de Mês muda). Recalcula tudo a partir do novo mês e
    // re-renderiza, preservando os filtros atuais.
    function setMonth(month) {
        const resolved = resolveMonth(month);
        if (resolved === currentMonth && scoredTeams.length) {
            return; // nada mudou
        }
        currentMonth = resolved;
        rebuildScores();

        // Se o time filtrado não existir no novo mês, volta para "todos".
        if (currentFilters.team !== 'all' &&
            !teamsSnapshot.some(t => t.id === currentFilters.team)) {
            currentFilters.team = 'all';
        }

        renderFilters();
        applyFilters(); // já chama renderDashboard()
    }

    // Recalcula o snapshot do mês, os scores e as jornadas a partir do dataset
    // mensal (data.js). O ranking é ordenado por score desc por calculateAllTeams.
    function rebuildScores() {
        const d = data();
        const eng = engine();
        teamsSnapshot = (typeof d.teamSnapshotFor === 'function')
            ? d.teamSnapshotFor(currentMonth)
            : (d.TEAMS || []);
        scoredTeams = eng.calculateAllTeams(teamsSnapshot);
        journeyScores = eng.calculateJourneyScores(scoredTeams);
        filteredTeams = [...scoredTeams];
    }

    // =========================================================================
    // FILTERS
    // =========================================================================
    function renderFilters() {
        const jornadaSelect = byId('filter-jornada');
        const teamSelect = byId('filter-team');
        if (!jornadaSelect || !teamSelect) return;

        // Popula o filtro de jornada.
        jornadaSelect.innerHTML = '<option value="all">Todas as Jornadas</option>';
        data().JORNADAS.forEach(j => {
            jornadaSelect.innerHTML += `<option value="${j}">${j}</option>`;
        });
        jornadaSelect.value = currentFilters.jornada;

        updateTeamFilter();
    }

    function updateTeamFilter() {
        const teamSelect = byId('filter-team');
        if (!teamSelect) return;
        teamSelect.innerHTML = '<option value="all">Todos os Times</option>';

        const teamsToShow = currentFilters.jornada === 'all'
            ? teamsSnapshot
            : teamsSnapshot.filter(t => t.jornada === currentFilters.jornada);

        teamsToShow.forEach(t => {
            teamSelect.innerHTML += `<option value="${t.id}">${t.name}</option>`;
        });
        teamSelect.value = currentFilters.team;
    }

    function applyFilters() {
        if (currentFilters.jornada === 'all' && currentFilters.team === 'all') {
            filteredTeams = [...scoredTeams];
        } else if (currentFilters.team !== 'all') {
            filteredTeams = scoredTeams.filter(t => t.id === currentFilters.team);
        } else {
            filteredTeams = scoredTeams.filter(t => t.jornada === currentFilters.jornada);
        }

        renderDashboard();
    }

    function setupEventListeners() {
        if (listenersBound) return; // evita binding duplicado em re-init.
        const jornadaEl = byId('filter-jornada');
        const teamEl = byId('filter-team');
        const exportEl = byId('btn-export');
        const inputEl = byId('btn-input-data');
        const modalCloseEl = byId('modal-close');
        const modalOverlayEl = byId('modal-overlay');

        if (jornadaEl) jornadaEl.addEventListener('change', (e) => {
            currentFilters.jornada = e.target.value;
            currentFilters.team = 'all';
            updateTeamFilter();
            applyFilters();
        });

        if (teamEl) teamEl.addEventListener('change', (e) => {
            currentFilters.team = e.target.value;
            applyFilters();
        });

        if (exportEl) exportEl.addEventListener('click', exportCSV);
        if (inputEl) inputEl.addEventListener('click', openInputModal);

        // Modal close.
        if (modalCloseEl) modalCloseEl.addEventListener('click', closeModal);
        if (modalOverlayEl) modalOverlayEl.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        listenersBound = true;
    }

    // =========================================================================
    // DASHBOARD RENDERING
    // =========================================================================
    function renderDashboard() {
        renderKPICards();
        renderGaugeChart();
        renderCategoryChart();
        renderRanking();
        renderJourneyComparison();
        renderMetricDetails();
    }

    function avgScoreOf(teams) {
        if (!teams.length) return 0;
        return teams.reduce((sum, t) => sum + t.scoreResult.totalScore, 0) / teams.length;
    }

    function monthLabel() {
        const labels = data().MONTH_LABELS || [];
        return labels[currentMonth - 1] || `Mês ${currentMonth}`;
    }

    function renderKPICards() {
        const container = byId('kpi-cards');
        if (!container) return;

        const eng = engine();
        const avgScore = avgScoreOf(filteredTeams);
        const maxTeam = filteredTeams[0];
        const band = eng.getPerformanceBand(avgScore);

        container.innerHTML = `
            <div class="kpi-card">
                <div class="kpi-label">Score Médio</div>
                <div class="kpi-value" style="color: ${band.color}">${avgScore.toFixed(0)}</div>
                <div class="kpi-subtitle">${band.name}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Times Avaliados</div>
                <div class="kpi-value">${filteredTeams.length}</div>
                <div class="kpi-subtitle">${currentFilters.jornada === 'all' ? 'Todas as Jornadas' : currentFilters.jornada} · ${monthLabel()}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Melhor Time</div>
                <div class="kpi-value">${maxTeam ? maxTeam.scoreResult.totalScore.toFixed(0) : '-'}</div>
                <div class="kpi-subtitle">${maxTeam ? maxTeam.name : '-'}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Score Máximo</div>
                <div class="kpi-value">${eng.MAX_TOTAL_SCORE}</div>
                <div class="kpi-subtitle">Pontuação máxima possível</div>
            </div>
        `;
    }

    function renderGaugeChart() {
        const ctx = byId('gauge-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        const eng = engine();
        const avgScore = avgScoreOf(filteredTeams);

        if (charts.gauge) charts.gauge.destroy();

        charts.gauge = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Score', 'Restante'],
                datasets: [{
                    data: [avgScore, eng.MAX_TOTAL_SCORE - avgScore],
                    backgroundColor: [
                        eng.getPerformanceBand(avgScore).color,
                        'rgba(15,23,42,0.08)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                circumference: 270,
                rotation: -135,
                cutout: '75%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            },
            plugins: [{
                id: 'gaugeText',
                afterDraw: (chart) => {
                    const { ctx: context, width, height } = chart;
                    const cssV = (typeof getComputedStyle !== 'undefined')
                        ? getComputedStyle(document.documentElement) : null;
                    const colorPrimary = (cssV && cssV.getPropertyValue('--text-primary').trim()) || '#0f172a';
                    const colorMuted = (cssV && cssV.getPropertyValue('--text-muted').trim()) || '#64748b';
                    context.save();
                    context.font = 'bold 36px Inter, sans-serif';
                    context.fillStyle = colorPrimary;
                    context.textAlign = 'center';
                    context.textBaseline = 'middle';
                    context.fillText(avgScore.toFixed(0), width / 2, height / 2 + 10);
                    context.font = '14px Inter, sans-serif';
                    context.fillStyle = colorMuted;
                    context.fillText(`de ${eng.MAX_TOTAL_SCORE} pts`, width / 2, height / 2 + 40);
                    context.restore();
                }
            }]
        });
    }

    // Radar das 4 CATEGORIAS corrigidas (Processos/Práticas/Produto/Pessoas),
    // substitui o radar legado de 3 dimensões. Cada eixo é o % do máximo da
    // categoria (média dos times filtrados).
    function renderCategoryChart() {
        const ctx = byId('dimension-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        const eng = engine();
        const cats = eng.CATEGORIES;
        const ids = ['processos', 'praticas', 'produto', 'pessoas'];

        const acc = { processos: 0, praticas: 0, produto: 0, pessoas: 0 };
        filteredTeams.forEach(t => {
            const cs = t.scoreResult.categoryScores || {};
            ids.forEach(id => { acc[id] += (cs[id] || 0); });
        });
        const count = filteredTeams.length || 1;

        const labels = ids.map(id => `${cats[id].name} (${cats[id].maxScore})`);
        const values = ids.map(id => {
            const max = cats[id].maxScore || 1;
            return (acc[id] / count / max * 100).toFixed(1);
        });

        if (charts.dimension) charts.dimension.destroy();

        charts.dimension = new Chart(ctx, {
            type: 'radar',
            data: {
                labels,
                datasets: [{
                    label: 'Score Médio (%)',
                    data: values,
                    backgroundColor: 'rgba(52, 152, 219, 0.2)',
                    borderColor: '#3498db',
                    borderWidth: 2,
                    pointBackgroundColor: '#3498db'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { color: '#94a3b8', backdropColor: 'transparent' },
                        grid: { color: 'rgba(15,23,42,0.12)' },
                        pointLabels: { color: '#e2e8f0', font: { size: 12 } }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    function renderRanking() {
        const container = byId('ranking-list');
        if (!container) return;

        let html = '';
        filteredTeams.forEach((team, index) => {
            const band = team.scoreResult.performanceBand;
            const percentage = team.scoreResult.percentage;

            html += `
                <div class="ranking-item" onclick="Dashboard.showTeamDetail('${team.id}')">
                    <div class="ranking-position">${index + 1}º</div>
                    <div class="ranking-info">
                        <div class="ranking-name">${team.name}</div>
                        <div class="ranking-journey">${team.jornada}</div>
                    </div>
                    <div class="ranking-score-section">
                        <div class="ranking-bar-container">
                            <div class="ranking-bar" style="width: ${percentage}%; background-color: ${band.color}"></div>
                        </div>
                        <div class="ranking-score" style="color: ${band.color}">${team.scoreResult.totalScore.toFixed(0)} pts</div>
                    </div>
                    <div class="ranking-badge" style="background-color: ${band.color}20; color: ${band.color}">
                        ${band.name.replace(' Performance', '')}
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    function renderJourneyComparison() {
        const ctx = byId('journey-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        const eng = engine();
        const journeyData = currentFilters.jornada === 'all'
            ? journeyScores
            : journeyScores.filter(j => j.name === currentFilters.jornada);

        if (charts.journey) charts.journey.destroy();

        charts.journey = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: journeyData.map(j => j.name.length > 15 ? j.name.substring(0, 15) + '...' : j.name),
                datasets: [{
                    label: 'Score Médio',
                    data: journeyData.map(j => j.avgScore.toFixed(0)),
                    backgroundColor: journeyData.map(j => j.performanceBand.color + '80'),
                    borderColor: journeyData.map(j => j.performanceBand.color),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        max: eng.MAX_TOTAL_SCORE,
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(15,23,42,0.08)' }
                    },
                    y: {
                        ticks: { color: '#e2e8f0', font: { size: 11 } },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    function renderMetricDetails() {
        const container = byId('metric-details');
        if (!container) return;

        const eng = engine();

        // Médias agregadas por métrica (as 12 métricas).
        let html = '<div class="metrics-grid">';

        Object.entries(eng.METRICS).forEach(([metricId, metricDef]) => {
            let avgScore = 0;
            let avgValue = 0;
            let validCount = 0;

            filteredTeams.forEach(t => {
                const metricResult = t.scoreResult.metrics[metricId];
                if (metricResult) avgScore += metricResult.score;
                const raw = t.metrics ? t.metrics[metricId] : undefined;
                if (raw !== null && raw !== undefined && !(typeof raw === 'number' && Number.isNaN(raw))) {
                    avgValue += raw;
                    validCount++;
                }
            });

            const count = filteredTeams.length || 1;
            avgScore = avgScore / count;
            avgValue = validCount > 0 ? avgValue / validCount : null;

            // Avalia a faixa a partir do valor médio (via avaliador genérico das
            // 4 categorias reconciliadas).
            const result = avgValue !== null
                ? eng.evaluateMetric(metricId, avgValue)
                : { band: 'sem-dados', label: 'Sem Dados' };
            const percentage = metricDef.maxScore > 0 ? (avgScore / metricDef.maxScore * 100) : 0;

            const bandColor = getBandColor(result.band);
            const catId = eng.METRIC_RULES[metricId] ? eng.METRIC_RULES[metricId].category : metricDef.dimension;
            const cat = eng.CATEGORIES[catId] || { icon: '', name: catId };

            html += `
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-name">${metricDef.name}</span>
                        <span class="metric-dimension-badge">${cat.icon} ${cat.name}</span>
                    </div>
                    <div class="metric-value">${avgValue !== null ? metricDef.formatValue(avgValue) : 'N/A'}</div>
                    <div class="metric-score-bar">
                        <div class="metric-bar-fill" style="width: ${percentage}%; background-color: ${bandColor}"></div>
                    </div>
                    <div class="metric-footer">
                        <span class="metric-score">${avgScore.toFixed(1)} / ${metricDef.maxScore} pts</span>
                        <span class="metric-band" style="color: ${bandColor}">${result.label}</span>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        container.innerHTML = html;
    }

    // =========================================================================
    // TEAM DETAIL VIEW (modal com as 12 métricas e as 4 categorias)
    // =========================================================================
    function showTeamDetail(teamId) {
        const eng = engine();
        const team = scoredTeams.find(t => t.id === teamId);
        if (!team) return;

        const modal = byId('modal-overlay');
        const modalTitle = byId('modal-title');
        const modalBody = byId('modal-body');
        if (!modal || !modalTitle || !modalBody) return;

        modalTitle.textContent = `${team.name} - Detalhamento de Score`;

        const band = team.scoreResult.performanceBand;
        const cats = eng.CATEGORIES;
        const cs = team.scoreResult.categoryScores || {};

        let html = `
            <div class="detail-header">
                <div class="detail-score" style="color: ${band.color}">
                    ${team.scoreResult.totalScore.toFixed(0)} pts
                </div>
                <div class="detail-band" style="background: ${band.color}20; color: ${band.color}">
                    ${band.name}
                </div>
                <div class="detail-journey">${team.jornada} · ${monthLabel()}</div>
            </div>
            <div class="detail-dimensions">
                <div class="dim-item">
                    <span>${cats.processos.icon} Processos</span>
                    <span>${(cs.processos || 0).toFixed(1)} / ${cats.processos.maxScore}</span>
                </div>
                <div class="dim-item">
                    <span>${cats.praticas.icon} Práticas</span>
                    <span>${(cs.praticas || 0).toFixed(1)} / ${cats.praticas.maxScore}</span>
                </div>
                <div class="dim-item">
                    <span>${cats.produto.icon} Produto</span>
                    <span>${(cs.produto || 0).toFixed(1)} / ${cats.produto.maxScore}</span>
                </div>
                <div class="dim-item">
                    <span>${cats.pessoas.icon} Pessoas</span>
                    <span>${(cs.pessoas || 0).toFixed(1)} / ${cats.pessoas.maxScore}</span>
                </div>
            </div>
            <div class="detail-metrics">
                <h4>Detalhamento por Métrica</h4>
                <table class="detail-table">
                    <thead>
                        <tr>
                            <th>Métrica</th>
                            <th>Valor</th>
                            <th>Score</th>
                            <th>Máx</th>
                            <th>Faixa</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        Object.entries(eng.METRICS).forEach(([metricId, metricDef]) => {
            const metricResult = team.scoreResult.metrics[metricId];
            const bandColor = getBandColor(metricResult.band);
            const value = metricResult.value !== null && metricResult.value !== undefined
                ? metricDef.formatValue(metricResult.value)
                : 'N/A';

            html += `
                <tr>
                    <td>${metricDef.name}</td>
                    <td>${value}</td>
                    <td style="color: ${bandColor}; font-weight: 600">${metricResult.score.toFixed(1)}</td>
                    <td>${metricDef.maxScore}</td>
                    <td><span class="band-badge" style="background: ${bandColor}20; color: ${bandColor}">${metricResult.label}</span></td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        modalBody.innerHTML = html;
        modal.classList.add('active');
    }

    // =========================================================================
    // DATA INPUT MODAL (entrada manual — escalas reconciliadas)
    // =========================================================================
    // Entrada de Dados: agora o modal exibe a tela de ENTRADA MANUAL DE MÉTRICAS
    // (apenas as métricas de origem manual, via PEPViews.entradaManual), em vez
    // do formulário legado de todas as métricas. A tela de entrada manual deixou
    // de existir no menu de gestão — este modal é o ponto único de entrada.
    function openInputModal() {
        const modal = byId('modal-overlay');
        const modalTitle = byId('modal-title');
        const modalBody = byId('modal-body');
        if (!modal || !modalTitle || !modalBody) return;

        modalTitle.textContent = `Entrada Manual de Métricas · ${monthLabel()}`;

        const views = (typeof window !== 'undefined' && window.PEPViews) ? window.PEPViews
            : (typeof globalThis !== 'undefined' ? globalThis.PEPViews : null);
        const view = views && views.entradaManual;
        if (view && typeof view.render === 'function') {
            // A view lê o mês selecionado via AppState; passamos também por opts.
            const mes = (typeof AppState !== 'undefined' && AppState.getSelectedMonth)
                ? AppState.getSelectedMonth() : undefined;
            modalBody.innerHTML = '';
            view.render(modalBody, { month: mes });
        } else {
            modalBody.innerHTML = '<p style="padding:1rem">Entrada manual de métricas indisponível.</p>';
        }
        modal.classList.add('active');
    }

    // Lê o valor de um input por id (string vazia quando ausente/vazio).
    function readInput(id) {
        const el = byId(id);
        return el ? el.value : '';
    }

    function handleFormSubmit(e) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();

        const teamId = readInput('input-team');
        if (!teamId) return;

        const team = teamsSnapshot.find(t => t.id === teamId);
        if (!team) return;

        // Lê os campos do formulário.
        const freqDeploy = readInput('input-freq-deploy');
        const teamHealth = readInput('input-team-health');
        const planejado = readInput('input-planejado');
        const okrs = readInput('input-okrs');
        const ctDev = readInput('input-ct-dev');
        const ctHomolog = readInput('input-ct-homolog');
        const ctImpl = readInput('input-ct-impl');
        const disponibilidade = readInput('input-disponibilidade');
        const vulnerabilidade = readInput('input-vulnerabilidade');
        const slaAlta = readInput('input-sla-alta');
        const slaMedia = readInput('input-sla-media');
        const slaBaixa = readInput('input-sla-baixa');

        // Atualiza as métricas usando as escalas reconciliadas:
        //  - Percentuais em escala 0..1 (dividimos a entrada em % por 100).
        //  - Cycle Time em dias (direto).
        //  - Frequência de Deploy como categoria inteira.
        //  - Team Health Check em escala 0–10 (direto, SEM dividir por 100).
        if (freqDeploy !== '') team.metrics.freqDeploy = parseInt(freqDeploy, 10);
        if (teamHealth !== '') team.metrics.teamHealth = parseFloat(teamHealth);
        if (planejado !== '') team.metrics.planejadoVsRealizado = parseFloat(planejado) / 100;
        if (okrs !== '') team.metrics.okrs = parseFloat(okrs) / 100;
        if (ctDev !== '') team.metrics.cycleTimeDev = parseFloat(ctDev);
        if (ctHomolog !== '') team.metrics.cycleTimeHomolog = parseFloat(ctHomolog);
        if (ctImpl !== '') team.metrics.cycleTimeImpl = parseFloat(ctImpl);
        if (disponibilidade !== '') team.metrics.disponibilidade = parseFloat(disponibilidade) / 100;
        if (vulnerabilidade !== '') team.metrics.vulnerabilidade = parseFloat(vulnerabilidade) / 100;
        if (slaAlta !== '') team.metrics.slaAlta = parseFloat(slaAlta) / 100;
        if (slaMedia !== '') team.metrics.slaMedia = parseFloat(slaMedia) / 100;
        if (slaBaixa !== '') team.metrics.slaBaixa = parseFloat(slaBaixa) / 100;

        // Recalcula os scores sobre o snapshot atual (mantém o mês de referência).
        const eng = engine();
        scoredTeams = eng.calculateAllTeams(teamsSnapshot);
        journeyScores = eng.calculateJourneyScores(scoredTeams);
        applyFilters();
        closeModal();

        showToast('Dados atualizados com sucesso!');
    }

    // =========================================================================
    // EXPORT (CSV com separador ';' e UTF-8 com BOM) — Requirement 9.5
    // =========================================================================
    function exportCSV() {
        const eng = engine();
        const headers = [
            'Posição', 'Time', 'Jornada', 'Mês', 'Score Total', 'Faixa',
            'Processos', 'Práticas', 'Produto', 'Pessoas',
            ...Object.values(eng.METRICS).map(m => m.name + ' (Valor)'),
            ...Object.values(eng.METRICS).map(m => m.name + ' (Score)')
        ];

        const rows = filteredTeams.map((team, index) => {
            const cs = team.scoreResult.categoryScores || {};
            const metricValues = Object.keys(eng.METRICS).map(mId => {
                const val = team.metrics[mId];
                return val !== null && val !== undefined ? val : '';
            });
            const metricScores = Object.keys(eng.METRICS).map(mId => {
                return team.scoreResult.metrics[mId].score.toFixed(2);
            });

            return [
                index + 1,
                team.name,
                team.jornada,
                monthLabel(),
                team.scoreResult.totalScore.toFixed(2),
                team.scoreResult.performanceBand.name,
                (cs.processos || 0).toFixed(2),
                (cs.praticas || 0).toFixed(2),
                (cs.produto || 0).toFixed(2),
                (cs.pessoas || 0).toFixed(2),
                ...metricValues,
                ...metricScores
            ];
        });

        let csvContent = '\uFEFF'; // BOM para UTF-8.
        csvContent += headers.join(';') + '\n';
        rows.forEach(row => {
            csvContent += row.join(';') + '\n';
        });

        // Em ambiente de teste (sem Blob/URL/document real), não há download;
        // retornamos o conteúdo para permitir asserts de regressão.
        try {
            const d = doc();
            if (typeof Blob !== 'undefined' && typeof URL !== 'undefined' &&
                d && typeof d.createElement === 'function') {
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = d.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `pep-scores-${monthLabel()}-${new Date().toISOString().split('T')[0]}.csv`;
                if (typeof link.click === 'function') link.click();
                showToast('Exportação CSV gerada com sucesso!');
            }
        } catch (err) {
            // Falha de ambiente não deve quebrar o fluxo; retornamos o conteúdo.
        }

        return csvContent;
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================
    function closeModal() {
        const modal = byId('modal-overlay');
        if (modal) modal.classList.remove('active');
    }

    function getBandColor(band) {
        const colors = {
            'nao-atendeu': '#e74c3c',
            'atendeu-parcialmente': '#f39c12',
            'atendeu-totalmente': '#27ae60',
            'superou': '#3498db',
            'sem-dados': '#6b7280'
        };
        return colors[band] || '#6b7280';
    }

    function showToast(message) {
        const toast = byId('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        init,
        setMonth,
        showTeamDetail,
        applyFilters,
        exportCSV,
        // Expostos para testes de regressão (inspeção de estado interno).
        _getState: () => ({
            currentMonth,
            scoredTeams,
            journeyScores,
            filteredTeams,
            currentFilters: { ...currentFilters }
        }),
        _handleFormSubmit: handleFormSubmit,
        _openInputModal: openInputModal
    };
})();

// Environment guard: expõe a IIFE para testes em Node (Vitest) sem afetar o
// carregamento por <script> no browser. No browser `module` é undefined, então
// este bloco é ignorado e `Dashboard` permanece como global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Dashboard;
}

if (typeof globalThis !== 'undefined') {
    globalThis.Dashboard = Dashboard;
}
