/**
 * PEP - Plataforma de Engenharia de Performance
 * Inicialização da aplicação + navegação (Task 8, spec score-performance-executivo)
 *
 * Responsabilidades (Requirements 2.1, 2.2, 2.4):
 *  1. Inicializar o AppState (Mês Selecionado persistente + view ativa).
 *  2. Montar o Seletor de Mês (Jan–Dez), refletindo/atualizando
 *     AppState.getSelectedMonth()/setSelectedMonth().
 *  3. Montar as abas das 6 visões (Visão Geral, Jornadas, Métricas, Evolução,
 *     Metas e Legado/Operacional), trocando a visão via AppState.setActiveView.
 *  4. Assinar AppState (subscribe) para alternar o container visível e disparar
 *     o render da visão ativa ao trocar mês/aba.
 *
 * As visões executivas ainda não existem (tarefa 9). O dispatch de render é
 * DEFENSIVO: se existir um renderer registrado em window.PEPViews[viewId], ele
 * é chamado com (container, { month }); caso contrário, mostramos um placeholder
 * "em construção" sem quebrar a página. O legado (tarefa 10) vive no container
 * #view-legado e é inicializado pelo Dashboard existente.
 *
 * TESTABILIDADE (sem jsdom): a IIFE RETORNA as funções de wiring (init,
 * buildTabs, buildMonthSelector, renderActiveView, onStateChange, ...) e, sob
 * guarda de ambiente (`module.exports`/`globalThis.PEPApp`), as expõe para os
 * testes de integração em Node (Vitest) — sem alterar o comportamento no
 * browser, onde `init` continua sendo disparado por DOMContentLoaded.
 *
 * Task 16 (spec score-performance-executivo): estende o wiring com a navegação
 * dos painéis de Retrospectiva de PI (Requirements 18.7, 19.3):
 *  - Aba agrupadora "Retrospectiva de PI" com uma sub-aba por painel
 *    (AppState.RETRO_VIEWS: retroPontos, retroProdutividade, retroEpicos,
 *    retroCycleTime, retroLeadTime, retroDebito, retroSustentacao, retroExtras).
 *  - Seletor de PI (PEPRetroData.getPIs()) INDEPENDENTE do Seletor de Mês:
 *    trocar o PI chama AppState.setSelectedPI e NÃO altera o Mês, e vice-versa.
 *  - Dispatch DEFENSIVO de render(container, { pi }) para o painel de Retro
 *    ativo: placeholder "em construção" quando o painel ainda não existe em
 *    window.PEPViews (painéis das tarefas 17/18/19), com isolamento por
 *    try/catch. O comportamento do Score (abas + Seletor de Mês) é preservado.
 */

const PEPApp = (() => {
    'use strict';

    // Metadados das visões (ordem = ordem das abas).
    // `legado` reutiliza o dashboard existente (ranking/filtros/detalhe/export).
    const VIEW_META = {
        geral:        { label: 'Visão Geral',       icon: '📊' },
        jornadas:     { label: 'Jornadas',          icon: '🧭' },
        metricas:     { label: 'Métricas',          icon: '📈' },
        evolucaoMetas:{ label: 'Evolução & Metas',  icon: '📅' },
        legado:       { label: 'Operacional/Times', icon: '🗂️' }
    };

    // Metadados dos painéis de Retrospectiva de PI (ordem = ordem das sub-abas).
    // Correspondem a AppState.RETRO_VIEWS (tarefa 15). Os renderizadores de cada
    // painel são registrados em window.PEPViews pelas tarefas 17/18/19; aqui o
    // dispatch é DEFENSIVO (placeholder quando a view ainda não existe).
    const RETRO_VIEW_META = {
        retroPontos:        { label: 'Pontos Trabalhados', icon: '📊' },
        retroProdutividade: { label: 'Produtividade',      icon: '⚙️' },
        retroEpicos:        { label: 'Situação dos Épicos', icon: '🗺️' },
        retroCycleTime:     { label: 'Cycle Time',         icon: '⏱️' },
        retroLeadTime:      { label: 'Lead Time',          icon: '⏳' },
        retroDebito:        { label: 'Débito Técnico',     icon: '🧱' },
        retroSustentacao:   { label: 'Sustentação',        icon: '🛠️' },
        retroExtras:        { label: 'Carga / Alterações / Impedimentos', icon: '📦' }
    };

    // Id/rótulo/ícone da aba agrupadora que expõe as sub-abas de Retro PI.
    const RETRO_GROUP = { id: 'retro', label: 'Retrospectiva de PI', icon: '🔁' };

    // =========================================================================
    // MENU POR PERFIL (Task 29) — Requirements 25.3/25.4/25.5/25.6, 26.*
    //
    // O menu passa a ser renderizado a partir de Rbac.menuFor(AppState.getProfile()),
    // em DUAS SEÇÕES ("Dashboards e Configurações" / "Gestão e Configuração").
    // Cada entrada do menu é uma TELA (screenId do Rbac.SCREENS). Mapeamos cada
    // tela para o container e o modo de render apropriado, PRESERVANDO as views
    // existentes do Score/Retro:
    //   - kind 'score'   → container de view de Score (render por PEPViews ou
    //                      Dashboard legado); usa o Mês Selecionado.
    //   - kind 'retro'   → container de painel de Retro (render por PEPViews);
    //                      usa o PI Selecionado.
    //   - kind 'legacy'  → #view-legado (Dashboard operacional/times); usa o Mês.
    //   - kind 'placeholder' → telas ainda não implementadas (tarefas 30–33):
    //                      container próprio com placeholder "em construção".
    //
    // `view` é o id da view do AppState (quando kind='score') para manter o
    // Mês/aba em sincronia; `container` é o id do <section> correspondente.
    // =========================================================================
    const SCREEN_META = {
        execGeral:        { icon: '📊', kind: 'score',       view: 'geral',    container: 'view-geral' },
        execSquad:        { icon: '🧩', kind: 'screenView',  viewKey: 'execSquad', container: 'view-execSquad' },
        detJornada:       { icon: '🧭', kind: 'score',       view: 'jornadas', container: 'view-jornadas' },
        detSquad:         { icon: '🗂️', kind: 'legacy',      view: 'legado',   container: 'view-legado' },
        apresentacao:     { icon: '🖥️', kind: 'screenView',  viewKey: 'apresentacao', container: 'view-apresentacao' },
        excecao:          { icon: '🚩', kind: 'screenView',  viewKey: 'excecao', container: 'view-excecao' },
        cfgIntegracao:    { icon: '🔌', kind: 'screenView',  viewKey: 'cfgIntegracao', container: 'view-cfgIntegracao' },
        gestPerfis:       { icon: '🛡️', kind: 'screenView',  viewKey: 'gestPerfis', container: 'view-gestPerfis' },
        gestTribosSquads: { icon: '🏷️', kind: 'screenView',  viewKey: 'gestTribosSquads', container: 'view-gestTribosSquads' },
        cfgParametrizacao:{ icon: '🎛️', kind: 'screenView',  viewKey: 'cfgParametrizacao', container: 'view-cfgParametrizacao' }
    };

    // Rótulos das duas seções do menu (Requirement 26.1).
    const MENU_SECTIONS = {
        dashboards: 'Dashboards e Configurações',
        gestao: 'Gestão e Configuração'
    };

    // Tela ativa por perfil (estado de sessão do menu). A guarda de acesso
    // garante que `activeScreen` seja SEMPRE uma tela permitida ao perfil atual.
    let activeScreen = null;

    // Resolve o Rbac de forma DEFENSIVA (global no browser; require nos testes).
    function rbac() {
        if (typeof Rbac !== 'undefined') return Rbac;
        if (typeof globalThis !== 'undefined' && globalThis.Rbac) return globalThis.Rbac;
        return null;
    }

    // Lê o perfil ativo de forma DEFENSIVA (default 'admin' quando indisponível).
    function currentProfile() {
        try {
            if (typeof AppState !== 'undefined' && typeof AppState.getProfile === 'function') {
                return AppState.getProfile();
            }
        } catch (e) { /* ignore */ }
        return 'admin';
    }

    // A navegação por perfil (menu + guarda + tela inicial) só é ativada quando
    // o RBAC está disponível E o AppState expõe o perfil. Em contextos legados
    // (ex.: tests/app-wiring*.test.js, que não instalam Rbac/getProfile), o
    // fluxo de abas de Score permanece inalterado — garantindo ZERO regressão.
    function profileNavEnabled() {
        const R = rbac();
        return !!(R && typeof R.menuFor === 'function'
            && typeof AppState !== 'undefined'
            && typeof AppState.getProfile === 'function');
    }

    // Container (id) de uma tela do Rbac.
    function screenContainerId(screenId) {
        const meta = SCREEN_META[screenId];
        return meta ? meta.container : `view-${screenId}`;
    }

    // Lista das views de Retro (fonte: AppState.RETRO_VIEWS, com fallback para
    // as chaves de RETRO_VIEW_META caso o AppState ainda não a exponha).
    function retroViews() {
        if (typeof AppState !== 'undefined' && Array.isArray(AppState.RETRO_VIEWS)
            && AppState.RETRO_VIEWS.length) {
            return AppState.RETRO_VIEWS;
        }
        return Object.keys(RETRO_VIEW_META);
    }

    // Lê o PI vigente de forma DEFENSIVA: o Seletor de PI (tarefa 15) pode não
    // estar disponível em todos os contextos (ex.: AppState sem a extensão de
    // Retro). Nesses casos retornamos undefined sem quebrar o wiring do Score.
    function currentPI() {
        try {
            if (typeof AppState !== 'undefined' && typeof AppState.getSelectedPI === 'function') {
                return AppState.getSelectedPI();
            }
        } catch (e) { /* ignore */ }
        return undefined;
    }

    // Troca o PI vigente de forma DEFENSIVA (ignora quando o AppState não expõe
    // o Seletor de PI). Nunca lança a partir do handler de UI.
    function selectPI(pi) {
        try {
            if (typeof AppState !== 'undefined' && typeof AppState.setSelectedPI === 'function') {
                AppState.setSelectedPI(pi);
            }
        } catch (e) { /* ignore */ }
    }

    function isRetroView(view) {
        return retroViews().indexOf(view) !== -1;
    }

    // Rótulos de mês (fallback quando PEPData não expõe MONTH_LABELS).
    const FALLBACK_MONTH_LABELS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun',
        'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    function monthLabels() {
        if (typeof PEPData !== 'undefined' && Array.isArray(PEPData.MONTH_LABELS)
            && PEPData.MONTH_LABELS.length === 12) {
            return PEPData.MONTH_LABELS;
        }
        return FALLBACK_MONTH_LABELS;
    }

    // Meses que possuem dado (para marcar visualmente os sem dado no seletor).
    function monthsWithData() {
        try {
            if (typeof PEPData !== 'undefined' && typeof PEPData.getMonthsWithData === 'function') {
                return PEPData.getMonthsWithData() || [];
            }
        } catch (e) { /* ignore */ }
        return [];
    }

    const containerId = (view) => `view-${view}`;

    // Lista de PIs disponíveis para o Seletor de PI (fonte: PEPRetroData).
    function piList() {
        try {
            if (typeof PEPRetroData !== 'undefined' && typeof PEPRetroData.getPIs === 'function') {
                return PEPRetroData.getPIs() || [];
            }
        } catch (e) { /* ignore */ }
        return [];
    }

    // =========================================================================
    // ESTADO DE NAVEGAÇÃO DE RETRO PI (sessão)
    //
    // A aba agrupadora "Retrospectiva de PI" não é uma view do AppState (o
    // AppState.VIEWS cobre apenas as visões de Score). A SUB-aba de Retro ativa
    // é um estado de navegação por sessão mantido aqui no app.js; o PI vigente é
    // mantido pelo AppState (getSelectedPI/setSelectedPI), independente do Mês.
    // `retroActive` indica se a aba agrupadora de Retro está selecionada.
    // =========================================================================
    let retroActive = false;
    let activeRetroView = retroViews()[0] || 'retroPontos';

    // =========================================================================
    // SELETOR DE MÊS (Requirements 2.1, 2.2, 2.4)
    // =========================================================================
    function buildMonthSelector() {
        const select = document.getElementById('month-selector');
        if (!select) return;

        const labels = monthLabels();
        const withData = new Set(monthsWithData());

        select.innerHTML = '';
        for (let m = 1; m <= 12; m++) {
            const opt = document.createElement('option');
            opt.value = String(m);
            const hasData = withData.size === 0 ? true : withData.has(m);
            opt.textContent = hasData ? labels[m - 1] : `${labels[m - 1]} (sem dados)`;
            opt.disabled = withData.size > 0 && !hasData;
            select.appendChild(opt);
        }

        // Reflete o mês selecionado atual.
        select.value = String(AppState.getSelectedMonth());

        // Atualiza o AppState quando o usuário troca de mês.
        select.addEventListener('change', (e) => {
            const m = parseInt(e.target.value, 10);
            if (!Number.isNaN(m)) {
                AppState.setSelectedMonth(m);
            }
        });
    }

    // =========================================================================
    // SELETOR DE PI (Requirements 18.7, 19.3)
    //
    // INDEPENDENTE do Seletor de Mês: trocar o PI chama AppState.setSelectedPI
    // e NÃO altera o Mês; trocar o Mês não altera o PI. O Seletor de PI é
    // relevante apenas para os painéis de Retrospectiva de PI; por isso vive em
    // sua própria área da toolbar, exibida só quando a aba de Retro está ativa.
    // =========================================================================
    function buildPISelector() {
        const select = document.getElementById('pi-selector');
        if (!select) return;

        const pis = piList();
        select.innerHTML = '';

        if (!pis.length) {
            // Sem PIs disponíveis (ex.: dataset de Retro ainda vazio): mantém o
            // seletor presente, porém desabilitado, com uma opção informativa.
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '(sem PIs)';
            opt.disabled = true;
            select.appendChild(opt);
            select.disabled = true;
        } else {
            select.disabled = false;
            pis.forEach((pi) => {
                const opt = document.createElement('option');
                opt.value = String(pi);
                opt.textContent = String(pi);
                select.appendChild(opt);
            });
            // Reflete o PI selecionado atual (default = PI vigente).
            const current = currentPI();
            if (current !== undefined && current !== null) {
                select.value = String(current);
            }
        }

        // Atualiza o AppState quando o usuário troca de PI (sem tocar o Mês).
        select.addEventListener('change', (e) => {
            const pi = e.target.value;
            if (pi) {
                selectPI(pi);
            }
        });
    }

    // =========================================================================
    // SELETOR DE PERFIL (Task 29 — Requirements 25.1, 25.2)
    //
    // Lista os 5 perfis com os rótulos de Rbac.PROFILE_LABEL, reflete o perfil
    // atual (AppState.getProfile) e, ao mudar, chama AppState.setProfile — que
    // persiste e notifica { type:'profile' }, disparando a re-renderização do
    // menu e da tela ativa (sem alterar Mês nem PI — Property 36).
    // =========================================================================
    function buildProfileSelector() {
        const select = document.getElementById('profile-selector');
        if (!select) return;

        const R = rbac();
        const profiles = (R && Array.isArray(R.PROFILES) && R.PROFILES.length)
            ? R.PROFILES : ['admin', 'dm', 'tm', 'gpm', 'tl'];
        const labels = (R && R.PROFILE_LABEL) ? R.PROFILE_LABEL : {};

        select.innerHTML = '';
        profiles.forEach((p) => {
            const opt = document.createElement('option');
            opt.value = String(p);
            opt.textContent = labels[p] || p;
            select.appendChild(opt);
        });

        // Reflete o perfil atual.
        select.value = String(currentProfile());

        // Troca de perfil pelo usuário → AppState.setProfile (defensivo).
        select.addEventListener('change', (e) => {
            const p = e.target.value;
            try {
                if (typeof AppState !== 'undefined' && typeof AppState.setProfile === 'function') {
                    AppState.setProfile(p);
                }
            } catch (err) { /* perfil inválido: ignora */ }
        });
    }

    // =========================================================================
    // MENU POR SEÇÕES (Task 29 — Requirements 26.1–26.5)
    //
    // Renderizado a partir de Rbac.menuFor(perfilAtual) em DUAS seções
    // ("Dashboards e Configurações" / "Gestão e Configuração"). Cada item é uma
    // TELA (screenId) permitida ao perfil — nega por omissão. Clicar num item
    // chama selectScreen(screenId), que aplica a guarda de acesso.
    // =========================================================================
    function buildMenu() {
        const nav = document.getElementById('profile-menu');
        if (!nav) return;

        const R = rbac();
        nav.innerHTML = '';
        if (!R || typeof R.menuFor !== 'function') return;

        const profile = currentProfile();
        const menu = R.menuFor(profile) || { dashboards: [], gestao: [] };

        const sections = [
            { key: 'dashboards', entries: menu.dashboards || [] },
            { key: 'gestao', entries: menu.gestao || [] }
        ];

        sections.forEach(({ key, entries }) => {
            if (!entries.length) return; // seção vazia não é exibida
            const section = document.createElement('div');
            section.className = 'menu-section';
            section.dataset.section = key;

            // Título vira cabeçalho COLAPSÁVEL (accordion) para higienizar o menu.
            const title = document.createElement('button');
            title.type = 'button';
            title.className = 'menu-section-title';
            title.setAttribute('aria-expanded', 'false');
            title.innerHTML = `<span>${MENU_SECTIONS[key] || key}</span>`;
            section.appendChild(title);

            const list = document.createElement('div');
            list.className = 'menu-section-items';

            entries.forEach((entry) => {
                const btn = document.createElement('button');
                btn.className = 'menu-item';
                btn.id = `menu-${entry.id}`;
                btn.type = 'button';
                btn.dataset.screen = entry.id;
                btn.setAttribute('role', 'menuitem');
                btn.setAttribute('aria-controls', screenContainerId(entry.id));
                btn.setAttribute('aria-current', 'false');
                btn.innerHTML = `<span class="menu-item-icon" aria-hidden="true">${entry.icon || ''}</span>` +
                    `<span class="menu-item-label">${entry.label}</span>`;
                btn.addEventListener('click', () => selectScreen(entry.id));
                list.appendChild(btn);
            });

            // Contador discreto de itens no cabeçalho.
            const count = document.createElement('span');
            count.className = 'nav-group-count';
            count.textContent = String(entries.length);
            title.appendChild(count);

            section.appendChild(list);
            nav.appendChild(section);

            // Colapsável: clicar no título alterna a seção. Começa recolhida.
            title.addEventListener('click', () => toggleNavGroup(section, title));
            setNavGroupExpanded(section, title, false);
        });

        // Após montar, expande o grupo que contém a tela ativa (se houver), para
        // a navegação começar útil sem exigir cliques.
        expandActiveNavGroup();
    }

    // Expande o grupo/seção que contém a tela ativa (menu) e recolhe os demais.
    function expandActiveNavGroup() {
        const nav = document.getElementById('profile-menu');
        if (!nav || typeof nav.querySelectorAll !== 'function') return;
        nav.querySelectorAll('.menu-section').forEach((section) => {
            const hasActive = !!(activeScreen &&
                section.querySelector(`.menu-item[data-screen="${activeScreen}"]`));
            const title = section.querySelector('.menu-section-title');
            if (hasActive) setNavGroupExpanded(section, title, true);
        });
    }

    // Atualiza o estado "selecionado" dos itens do menu conforme a tela ativa.
    function syncMenuSelection() {
        const nav = document.getElementById('profile-menu');
        if (!nav || typeof nav.querySelectorAll !== 'function') return;
        nav.querySelectorAll('.menu-item').forEach((btn) => {
            const selected = btn.dataset.screen === activeScreen;
            btn.classList.toggle('active', selected);
            btn.setAttribute('aria-current', selected ? 'true' : 'false');
            btn.tabIndex = selected ? 0 : -1;
        });
        // Mantém aberto o grupo que contém a tela ativa.
        expandActiveNavGroup();
    }

    // =========================================================================
    // GUARDA DE ACESSO (Task 29 — Requirements 25.4, 25.5 / Property 35)
    //
    // selectScreen é o ÚNICO ponto de entrada da navegação por perfil. Se a tela
    // for negada ao perfil atual, redireciona para a homeView e sinaliza o
    // acesso negado. Caso contrário, ativa a tela traduzindo o screenId para a
    // primitiva de navegação adequada (Score/Retro/legacy/placeholder),
    // PRESERVANDO Mês e PI.
    // =========================================================================
    function showAccessDenied(screenId) {
        const el = document.getElementById('menu-access-denied');
        if (!el) return;
        const R = rbac();
        const label = (R && R.SCREENS && R.SCREENS[screenId] && R.SCREENS[screenId].label)
            ? R.SCREENS[screenId].label : screenId;
        const textEl = (typeof el.querySelector === 'function') ? el.querySelector('.menu-access-denied-text') : null;
        const msg = `Acesso não permitido ao seu perfil: "${label}". Redirecionado para a tela inicial.`;
        if (textEl) textEl.textContent = msg; else el.textContent = msg;
        el.hidden = false;
    }

    function clearAccessDenied() {
        const el = document.getElementById('menu-access-denied');
        if (el) el.hidden = true;
    }

    // Aplica a tela ativa traduzindo o screenId para a navegação existente.
    function applyScreen(screenId) {
        activeScreen = screenId;
        const meta = SCREEN_META[screenId] || { kind: 'placeholder', container: screenContainerId(screenId) };

        if (meta.kind === 'score') {
            // View de Score: usa o AppState (mantém o Mês). setActiveView notifica
            // → onStateChange renderiza a view e sincroniza containers/abas.
            retroActive = false;
            try { AppState.setActiveView(meta.view); }
            catch (e) { renderScreenPlaceholder(screenId); }
        } else if (meta.kind === 'legacy') {
            // Operacional/Times legado (view 'legado' do AppState).
            retroActive = false;
            try { AppState.setActiveView('legado'); }
            catch (e) { renderScreenPlaceholder(screenId); }
        } else if (meta.kind === 'retro') {
            // Painel de Retro: usa o PI (independente do Mês).
            activeRetroView = meta.retroView;
            activateRetro();
        } else if (meta.kind === 'screenView') {
            // Tela de dashboard própria (ex.: Executivo por Squad — Task 30):
            // view registrada em window.PEPViews[viewKey], renderizada no seu
            // container usando o Mês Selecionado. Dispatch DEFENSIVO: placeholder
            // quando a view ainda não existe no registro.
            retroActive = false;
            hideAllScoreAndRetroContainers();
            renderScreenView(screenId, meta);
        } else {
            // Placeholder (telas das tarefas 30–33 ainda não implementadas).
            retroActive = false;
            hideAllScoreAndRetroContainers();
            renderScreenPlaceholder(screenId);
        }
        syncMenuSelection();
    }

    // Esconde todos os containers de Score/Retro (usado por telas placeholder,
    // que têm container próprio fora de VIEWS/RETRO_VIEWS).
    function hideAllScoreAndRetroContainers() {
        AppState.VIEWS.forEach((view) => {
            const el = document.getElementById(containerId(view));
            if (el) el.hidden = true;
        });
        retroViews().forEach((view) => {
            const el = document.getElementById(containerId(view));
            if (el) el.hidden = true;
        });
        // Telas de dashboard próprias (kind 'screenView', ex.: Executivo por
        // Squad) também são escondidas quando uma view de Score/Retro ativa.
        Object.keys(SCREEN_META).forEach((sid) => {
            const m = SCREEN_META[sid];
            if (m && m.kind === 'screenView') {
                const el = document.getElementById(m.container);
                if (el) el.hidden = true;
            }
        });
        const retroBar = document.getElementById('retro-toolbar');
        if (retroBar) retroBar.hidden = true;
    }

    // Mostra apenas o container da tela placeholder ativa e nele o placeholder.
    function renderScreenPlaceholder(screenId) {
        // Esconde outros containers de tela placeholder.
        Object.keys(SCREEN_META).forEach((sid) => {
            const m = SCREEN_META[sid];
            if (m && m.kind === 'placeholder') {
                const el = document.getElementById(m.container);
                if (el) el.hidden = (sid !== screenId);
            }
        });
        const container = document.getElementById(screenContainerId(screenId));
        if (container) {
            container.hidden = false;
            renderPlaceholder(container, screenId, false);
        }
    }

    // Renderiza uma tela de dashboard própria (kind 'screenView') cuja view está
    // registrada em window.PEPViews[viewKey]. Usa o Mês Selecionado. Esconde os
    // demais containers 'screenView'/'placeholder' e isola o render em try/catch;
    // quando a view não existe no registro, mostra o placeholder "em construção".
    function renderScreenView(screenId, meta) {
        // Esconde os outros containers de telas próprias e placeholder.
        Object.keys(SCREEN_META).forEach((sid) => {
            const m = SCREEN_META[sid];
            if (m && (m.kind === 'placeholder' || m.kind === 'screenView')) {
                const el = document.getElementById(m.container);
                if (el) el.hidden = (sid !== screenId);
            }
        });

        const container = document.getElementById(meta.container || screenContainerId(screenId));
        if (!container) return;
        container.hidden = false;

        const registry = (typeof window !== 'undefined' && window.PEPViews) ? window.PEPViews : null;
        const view = registry ? registry[meta.viewKey] : null;

        if (view && typeof view.render === 'function') {
            try {
                view.render(container, { month: AppState.getSelectedMonth() });
            } catch (e) {
                if (typeof console !== 'undefined') console.error('renderScreenView:', e);
                renderPlaceholder(container, screenId, true);
            }
        } else {
            renderPlaceholder(container, screenId, false);
        }
    }

    // Ponto de entrada único da navegação por perfil + GUARDA DE ACESSO.
    function selectScreen(screenId) {
        const R = rbac();
        const profile = currentProfile();
        const allowed = R && typeof R.can === 'function' ? R.can(profile, screenId) : true;

        if (!allowed) {
            // Tela negada → redireciona para a homeView e sinaliza (Req 25.5).
            const home = (R && typeof R.homeView === 'function') ? R.homeView(profile) : screenId;
            showAccessDenied(screenId);
            applyScreen(home);
            return home;
        }

        clearAccessDenied();
        applyScreen(screenId);
        return screenId;
    }

    // Aplica a tela inicial do perfil (Req 25.4). Usada no bootstrap e ao trocar
    // de perfil quando a tela ativa deixa de ser permitida.
    function applyHomeForProfile() {
        const R = rbac();
        const profile = currentProfile();
        const home = (R && typeof R.homeView === 'function') ? R.homeView(profile) : 'execGeral';
        clearAccessDenied();
        applyScreen(home);
        return home;
    }

    // Reavalia o menu e a tela ativa ao trocar de perfil (Req 25.7). Mantém a
    // tela atual se ainda permitida; caso contrário, redireciona para a home e
    // sinaliza o acesso negado. Mês e PI são preservados (Property 36).
    function onProfileChange() {
        buildProfileSelector();      // reflete o novo perfil no seletor
        buildMenu();                 // re-renderiza só o permitido (Req 25.3)

        const R = rbac();
        const profile = currentProfile();
        const stillAllowed = activeScreen && R && typeof R.can === 'function'
            ? R.can(profile, activeScreen) : false;

        if (stillAllowed) {
            clearAccessDenied();
            applyScreen(activeScreen);
        } else {
            const denied = activeScreen;
            const home = (R && typeof R.homeView === 'function') ? R.homeView(profile) : 'execGeral';
            if (denied && denied !== home) showAccessDenied(denied); else clearAccessDenied();
            applyScreen(home);
        }
    }

    // =========================================================================
    // ABAS DE NAVEGAÇÃO (Requirement 2.4)
    // =========================================================================
    function buildTabs() {
        const nav = document.getElementById('view-tabs');
        if (!nav) return;

        nav.innerHTML = '';

        // Higienização do menu: as abas de Score ficam dentro de um GRUPO
        // COLAPSÁVEL ("Score de Performance"), para reduzir a poluição visual.
        // Mantemos os botões `.view-tab` (ids e data-view) para não quebrar os
        // testes e a lógica de seleção existente.
        const group = document.createElement('div');
        group.className = 'view-tabs-group';
        group.dataset.group = 'score';

        const title = document.createElement('button');
        title.type = 'button';
        title.className = 'view-tabs-group-title';
        title.setAttribute('aria-expanded', 'false');
        title.innerHTML = `<span>Score de Performance</span>`;
        group.appendChild(title);

        const items = document.createElement('div');
        items.className = 'view-tabs-group-items';

        AppState.VIEWS.forEach((view) => {
            const meta = VIEW_META[view] || { label: view, icon: '' };
            const btn = document.createElement('button');
            btn.className = 'view-tab';
            btn.id = `tab-${view}`;
            btn.type = 'button';
            btn.dataset.view = view;
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-controls', containerId(view));
            btn.setAttribute('aria-selected', 'false');
            btn.innerHTML = `<span class="view-tab-icon" aria-hidden="true">${meta.icon}</span>` +
                `<span class="view-tab-label">${meta.label}</span>`;
            btn.addEventListener('click', () => AppState.setActiveView(view));
            items.appendChild(btn);
        });

        // Aba AGRUPADORA "Retrospectiva de PI": ao ser clicada, abre a primeira
        // sub-aba de Retro. Não é uma view do AppState (Score), por isso usa um
        // estado de sessão próprio (activateRetro) em vez de setActiveView.
        const groupBtn = document.createElement('button');
        groupBtn.className = 'view-tab';
        groupBtn.id = `tab-${RETRO_GROUP.id}`;
        groupBtn.type = 'button';
        groupBtn.dataset.view = RETRO_GROUP.id;
        groupBtn.dataset.group = 'retro';
        groupBtn.setAttribute('role', 'tab');
        groupBtn.setAttribute('aria-controls', 'retro-subtabs');
        groupBtn.setAttribute('aria-selected', 'false');
        groupBtn.innerHTML = `<span class="view-tab-icon" aria-hidden="true">${RETRO_GROUP.icon}</span>` +
            `<span class="view-tab-label">${RETRO_GROUP.label}</span>`;
        groupBtn.addEventListener('click', () => activateRetro());
        items.appendChild(groupBtn);

        // Contador discreto de itens no cabeçalho.
        const count = document.createElement('span');
        count.className = 'nav-group-count';
        count.textContent = String(items.querySelectorAll('.view-tab').length);
        title.appendChild(count);

        group.appendChild(items);
        nav.appendChild(group);

        // Colapsável: clicar no título alterna o grupo. Começa recolhido.
        title.addEventListener('click', () => toggleNavGroup(group, title));
        setNavGroupExpanded(group, title, false);
    }

    // -------------------------------------------------------------------------
    // Grupos colapsáveis do menu lateral (higienização). Helpers genéricos
    // usados pelas abas de Score e pelas seções do menu por perfil.
    // -------------------------------------------------------------------------
    function setNavGroupExpanded(group, title, expanded) {
        if (!group) return;
        group.classList.toggle('nav-group-expanded', !!expanded);
        if (title && typeof title.setAttribute === 'function') {
            title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function toggleNavGroup(group, title) {
        const willExpand = !group.classList.contains('nav-group-expanded');
        setNavGroupExpanded(group, title, willExpand);
    }

    // =========================================================================
    // SUB-ABAS DE RETROSPECTIVA DE PI (Requirement 18.7)
    //
    // Uma sub-aba por painel de Retro (AppState.RETRO_VIEWS). Clicar numa
    // sub-aba troca a view de Retro ativa (estado de sessão) e re-renderiza o
    // painel correspondente com o PI vigente.
    // =========================================================================
    function buildRetroSubTabs() {
        const nav = document.getElementById('retro-subtabs');
        if (!nav) return;

        nav.innerHTML = '';
        retroViews().forEach((view) => {
            const meta = RETRO_VIEW_META[view] || { label: view, icon: '' };
            const btn = document.createElement('button');
            btn.className = 'retro-subtab';
            btn.id = `subtab-${view}`;
            btn.type = 'button';
            btn.dataset.view = view;
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-controls', containerId(view));
            btn.setAttribute('aria-selected', 'false');
            btn.innerHTML = `<span class="retro-subtab-icon" aria-hidden="true">${meta.icon}</span>` +
                `<span class="retro-subtab-label">${meta.label}</span>`;
            btn.addEventListener('click', () => setActiveRetroView(view));
            nav.appendChild(btn);
        });
    }

    // Ativa a aba agrupadora de Retro (abre a sub-aba de Retro ativa). NÃO toca
    // o Mês nem o PI; apenas alterna o modo de navegação para Retro.
    function activateRetro() {
        retroActive = true;
        if (!isRetroView(activeRetroView)) {
            activeRetroView = retroViews()[0] || activeRetroView;
        }
        onStateChange({ type: 'retro-nav', view: activeRetroView });
    }

    // Troca a sub-aba (painel) de Retro ativa e re-renderiza com o PI vigente.
    function setActiveRetroView(view) {
        if (!isRetroView(view)) {
            throw new Error('PEPApp.setActiveRetroView: painel de Retro inválido: ' + view +
                ' (esperado uma de: ' + retroViews().join(', ') + ')');
        }
        retroActive = true;
        activeRetroView = view;
        onStateChange({ type: 'retro-nav', view });
    }

    // Atualiza o estado "selecionado" das abas conforme a view ativa.
    // Quando a aba agrupadora de Retro está ativa, nenhuma aba de Score fica
    // selecionada e a aba agrupadora recebe o destaque.
    function syncTabsSelection(activeView) {
        const nav = document.getElementById('view-tabs');
        if (!nav) return;
        let anySelected = false;
        nav.querySelectorAll('.view-tab').forEach((btn) => {
            const isActive = !retroActive && btn.dataset.view === activeView;
            const isRetroGroup = retroActive && btn.dataset.group === 'retro';
            const selected = isActive || isRetroGroup;
            if (selected) anySelected = true;
            btn.classList.toggle('active', selected);
            btn.setAttribute('aria-selected', selected ? 'true' : 'false');
            btn.tabIndex = selected ? 0 : -1;
        });
        // Mantém o grupo "Score de Performance" aberto quando uma de suas abas
        // (Score ou Retrospectiva) está ativa. Guarda defensiva: em ambientes
        // de teste o `nav` é um stub mínimo que pode não implementar
        // querySelector — nesse caso apenas pulamos a sincronização do grupo.
        const group = (typeof nav.querySelector === 'function')
            ? nav.querySelector('.view-tabs-group')
            : null;
        if (group) {
            const title = (typeof group.querySelector === 'function')
                ? group.querySelector('.view-tabs-group-title')
                : null;
            if (anySelected) setNavGroupExpanded(group, title, true);
        }
    }

    // Atualiza o estado "selecionado" das sub-abas de Retro.
    function syncRetroSubTabsSelection() {
        const nav = document.getElementById('retro-subtabs');
        if (!nav) return;
        nav.querySelectorAll('.retro-subtab').forEach((btn) => {
            const isActive = retroActive && btn.dataset.view === activeRetroView;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            btn.tabIndex = isActive ? 0 : -1;
        });
    }

    // Mostra apenas o container da visão ativa (Score OU painel de Retro).
    function showActiveContainer(activeView) {
        AppState.VIEWS.forEach((view) => {
            const el = document.getElementById(containerId(view));
            if (!el) return;
            // Em modo Retro, nenhuma view de Score fica visível.
            el.hidden = retroActive || view !== activeView;
        });
        retroViews().forEach((view) => {
            const el = document.getElementById(containerId(view));
            if (!el) return;
            el.hidden = !retroActive || view !== activeRetroView;
        });

        // Esconde os containers das telas placeholder (Task 29) e das telas de
        // dashboard próprias (kind 'screenView', ex.: Executivo por Squad):
        // quando uma view de Score/Retro está ativa, nenhuma delas fica visível.
        // Esses containers vivem fora de VIEWS/RETRO_VIEWS.
        Object.keys(SCREEN_META).forEach((sid) => {
            const m = SCREEN_META[sid];
            if (m && (m.kind === 'placeholder' || m.kind === 'screenView')) {
                const el = document.getElementById(m.container);
                if (el) el.hidden = true;
            }
        });

        // Alterna a visibilidade da área de sub-abas + Seletor de PI (toolbar).
        const retroBar = document.getElementById('retro-toolbar');
        if (retroBar) retroBar.hidden = !retroActive;
        // O Seletor de Mês é relevante às visões de Score; escondê-lo em Retro
        // reforça a independência visual (sem alterar seu estado/valor).
        const monthWrap = document.getElementById('month-selector-wrap');
        if (monthWrap) monthWrap.hidden = retroActive;
    }

    // =========================================================================
    // DISPATCH DE RENDER (defensivo — views da tarefa 9 ainda não existem)
    // =========================================================================
    function renderActiveView(month) {
        // Em modo Retrospectiva de PI, delega para o dispatch de Retro (que usa
        // o PI vigente, não o Mês). Mantém o comportamento do Score intacto.
        if (retroActive) {
            renderActiveRetroView(currentPI());
            return;
        }

        const activeView = AppState.getActiveView();
        const container = document.getElementById(containerId(activeView));
        if (!container) return;

        // Legado: já é inicializado pelo Dashboard; apenas garantimos que o mês
        // atual seja respeitado quando o dashboard oferecer esse hook (tarefa 10).
        if (activeView === 'legado') {
            try {
                if (typeof Dashboard !== 'undefined' && typeof Dashboard.setMonth === 'function') {
                    Dashboard.setMonth(month);
                }
            } catch (e) { /* não quebra a navegação */ }
            return;
        }

        // Views registradas em window.PEPViews[viewId].render(container, { month }).
        const registry = (typeof window !== 'undefined' && window.PEPViews) ? window.PEPViews : null;
        const view = registry ? registry[activeView] : null;

        if (view && typeof view.render === 'function') {
            try {
                view.render(container, { month });
            } catch (err) {
                console.error(`Erro ao renderizar a visão "${activeView}":`, err);
                renderPlaceholder(container, activeView, true);
            }
        } else {
            // Ainda não implementada (tarefa 9) → placeholder não destrutivo.
            renderPlaceholder(container, activeView, false);
        }
    }

    // =========================================================================
    // DISPATCH DE RENDER DOS PAINÉIS DE RETRO PI (defensivo — tarefas 17/18/19)
    //
    // Despacha render(container, { pi }) para o painel de Retro ativo. Como os
    // painéis ainda não existem em window.PEPViews, o dispatch é DEFENSIVO:
    // mostra um placeholder "em construção" quando o renderizador não existe, e
    // isola erros de render por try/catch (um painel que lança não derruba a
    // navegação).
    // =========================================================================
    // =========================================================================
    // FILTROS (Jornada/Squad/Sprint) + UPLOADS (Jira / Baseline) — Task 26
    //
    // Popula os selects de filtro a partir do RetroFilters (encadeando
    // Jornada→Squad e PI→Sprint), liga os eventos de troca e os uploads de
    // extração do Jira e de baseline. Tudo client-side; sem backend.
    // =========================================================================
    function fillSelect(el, options, selected) {
        if (!el) return;
        const ALL = (typeof RetroFilters !== 'undefined') ? RetroFilters.ALL : 'all';
        el.innerHTML = '';
        options.forEach((opt) => {
            const o = document.createElement('option');
            o.value = opt;
            o.textContent = (opt === ALL) ? 'Todos' : opt;
            if (opt === selected) o.selected = true;
            el.appendChild(o);
        });
    }

    function refreshRetroFilterOptions() {
        if (typeof RetroFilters === 'undefined') return;
        const st = RetroFilters.getState();
        fillSelect(document.getElementById('retro-filter-jornada'), RetroFilters.jornadaOptions(), st.jornada);
        fillSelect(document.getElementById('retro-filter-squad'), RetroFilters.squadOptions(), st.squad);
        // Filtro de Sprint removido da UI (higienização): cada painel usa PI/Mês.
    }

    function setUploadStatus(msg, isError) {
        const el = document.getElementById('retro-upload-status');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('retro-upload-error', !!isError);
    }

    function wireRetroControls() {
        if (typeof RetroFilters === 'undefined') return;

        const jornadaEl = document.getElementById('retro-filter-jornada');
        const squadEl = document.getElementById('retro-filter-squad');

        refreshRetroFilterOptions();

        if (jornadaEl) jornadaEl.addEventListener('change', (e) => {
            RetroFilters.setJornada(e.target.value);
            refreshRetroFilterOptions();           // encadeia Jornada→Squad
            if (retroActive) renderActiveRetroView(currentPI());
        });
        if (squadEl) squadEl.addEventListener('change', (e) => {
            RetroFilters.setSquad(e.target.value);
            if (retroActive) renderActiveRetroView(currentPI());
        });

        // Mantém o Sprint em dia quando o PI muda pelo Seletor de PI.
        RetroFilters.subscribe(() => refreshRetroFilterOptions());

        wireRetroUploads();
    }

    function wireRetroUploads() {
        const jiraInput = document.getElementById('retro-upload-jira');
        const baseInput = document.getElementById('retro-upload-baseline');

        if (jiraInput) jiraInput.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    if (typeof JiraIngest === 'undefined') { setUploadStatus('Ingestão do Jira indisponível.', true); return; }
                    const fmt = /\.json$/i.test(file.name) ? 'json' : 'csv';
                    const { items, errors } = JiraIngest.parseJira(String(reader.result), fmt);
                    setUploadStatus(`Jira: ${items.length} item(ns) importado(s)` + (errors.length ? `, ${errors.length} rejeitado(s)` : '') + '.', errors.length > 0);
                    if (typeof PEPRetroData !== 'undefined' && typeof PEPRetroData.ingestJira === 'function') {
                        PEPRetroData.ingestJira(items);
                        refreshRetroFilterOptions();
                        if (retroActive) renderActiveRetroView(currentPI());
                    }
                } catch (err) {
                    setUploadStatus('Falha ao importar a extração do Jira.', true);
                }
            };
            reader.readAsText(file);
        });

        if (baseInput) baseInput.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            if (typeof BaselineLoader === 'undefined') { setUploadStatus('Carregador de baseline indisponível.', true); return; }
            const isXlsx = /\.xlsx$/i.test(file.name);
            const reader = new FileReader();
            reader.onload = () => {
                try {
                    const pi = currentPI();
                    const input = isXlsx ? new Uint8Array(reader.result) : String(reader.result);
                    const res = BaselineLoader.loadForPI(pi, input, isXlsx ? 'xlsx' : 'csv');
                    if (res.ok) {
                        setUploadStatus(`Baseline do ${res.pi || pi} carregado (${res.baseline.registros.length} registros).`, false);
                        if (retroActive) renderActiveRetroView(currentPI());
                    } else {
                        const motivo = (res.errors && res.errors[0] && res.errors[0].motivo) ? res.errors[0].motivo : 'arquivo inválido';
                        setUploadStatus(`Baseline rejeitado: ${motivo}. Baseline anterior mantido.`, true);
                    }
                } catch (err) {
                    setUploadStatus('Falha ao carregar o baseline.', true);
                }
            };
            if (isXlsx) reader.readAsArrayBuffer(file); else reader.readAsText(file);
        });
    }

    function renderActiveRetroView(pi) {
        const view = activeRetroView;
        const container = document.getElementById(containerId(view));
        if (!container) return;

        const registry = (typeof window !== 'undefined' && window.PEPViews) ? window.PEPViews : null;
        const panel = registry ? registry[view] : null;

        if (panel && typeof panel.render === 'function') {
            try {
                // Passa o filtro de Retro (jornada/squad/sprint/pi) aos painéis.
                // Mantém `pi` por compatibilidade com os painéis que só usam PI.
                const filter = (typeof RetroFilters !== 'undefined' && RetroFilters.getState)
                    ? RetroFilters.getState() : { pi };
                panel.render(container, { pi, filter });
            } catch (err) {
                console.error(`Erro ao renderizar o painel de Retro "${view}":`, err);
                renderPlaceholder(container, view, true);
            }
        } else {
            // Painel ainda não registrado (tarefas 17/18/19) → placeholder.
            renderPlaceholder(container, view, false);
        }
    }

    function renderPlaceholder(container, view, isError) {
        const meta = VIEW_META[view] || RETRO_VIEW_META[view] || { label: view, icon: '🧩' };
        const message = isError
            ? 'Não foi possível renderizar esta visão.'
            : 'Em construção — esta visão será implementada em breve.';
        container.innerHTML =
            `<div class="view-placeholder">` +
                `<div class="view-placeholder-icon" aria-hidden="true">${meta.icon}</div>` +
                `<h2 class="view-placeholder-title">${meta.label}</h2>` +
                `<p class="view-placeholder-text">${message}</p>` +
            `</div>`;
    }

    // =========================================================================
    // LEGADO (tarefa 10 fará a adaptação completa; aqui preservamos o MVP)
    // =========================================================================
    function initLegacyDashboard() {
        try {
            if (typeof Dashboard !== 'undefined' && typeof Dashboard.init === 'function') {
                Dashboard.init();
            }
        } catch (e) {
            console.error('Falha ao inicializar o dashboard legado:', e);
        }

        // Período de referência (badge do header) — preservado do legado.
        const sprintInfo = document.getElementById('sprint-info');
        if (sprintInfo && typeof PEPData !== 'undefined' && PEPData.CURRENT_SPRINT) {
            sprintInfo.textContent = PEPData.CURRENT_SPRINT;
        }

        renderBandsLegend();
    }

    function renderBandsLegend() {
        const container = document.getElementById('bands-legend');
        if (!container || typeof ScoringEngine === 'undefined') return;

        let html = '';
        (ScoringEngine.PERFORMANCE_BANDS || []).forEach((band) => {
            html += `
                <div class="band-legend-item">
                    <div class="band-legend-color" style="background-color: ${band.color || 'var(--text-muted)'}"></div>
                    <span class="band-legend-label">${band.name}</span>
                    <span class="band-legend-range">${band.min}-${band.max} pts</span>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    // =========================================================================
    // ASSINATURA DO APPSTATE (alterna container visível + re-render)
    // =========================================================================
    function onStateChange(change) {
        const type = change && change.type;

        // Mudança de PERFIL (Task 29 — Req 25.7): reavalia o menu e a tela ativa,
        // preservando Mês e PI (Property 36). Tratado antes do fluxo de Score/
        // Retro porque pode redirecionar a tela via guarda de acesso.
        if (type === 'profile') {
            onProfileChange();
            return;
        }

        // Uma mudança do Score (mês ou troca de visão de Score) tira a navegação
        // do modo Retro. Mudanças de PI (type:'pi') ou de navegação de Retro
        // (type:'retro-nav') NÃO alteram esse modo além do que já foi definido.
        if (type === 'month' || type === 'view') {
            retroActive = false;
        }

        const activeView = (change && change.view) || AppState.getActiveView();
        const month = (change && typeof change.month === 'number')
            ? change.month
            : AppState.getSelectedMonth();
        const pi = (change && typeof change.pi === 'string')
            ? change.pi
            : currentPI();

        // Mantém o seletor de mês sincronizado (ex.: mudança programática).
        // IMPORTANTE: isso NÃO reage a mudanças de PI — o Mês é preservado
        // (independência Seletor de PI × Seletor de Mês — Requirement 19.3).
        const select = document.getElementById('month-selector');
        if (select && select.value !== String(month)) {
            select.value = String(month);
        }

        // Mantém o Seletor de PI sincronizado (ex.: mudança programática). Isso
        // NÃO reage a mudanças de Mês — o PI é preservado (Requirement 19.3).
        const piSelect = document.getElementById('pi-selector');
        if (piSelect && pi !== undefined && pi !== null && piSelect.value !== String(pi)) {
            piSelect.value = String(pi);
        }

        syncTabsSelection(retroActive ? RETRO_GROUP.id : activeView);
        syncRetroSubTabsSelection();
        syncMenuSelection();
        showActiveContainer(activeView);
        // O dispatch de render escolhe Score (mês) ou Retro (PI) conforme o modo.
        renderActiveView(month);
    }

    // =========================================================================
    // BOOTSTRAP
    // =========================================================================
    function init() {
        console.log('🚀 PEP - Plataforma de Engenharia de Performance');
        if (typeof PEPData !== 'undefined' && Array.isArray(PEPData.TEAMS)) {
            console.log(`📊 ${PEPData.TEAMS.length} times carregados`);
        }
        if (typeof ScoringEngine !== 'undefined') {
            console.log(`🎯 Score máximo: ${ScoringEngine.MAX_TOTAL_SCORE} pontos`);
        }

        // 1. Monta UI de navegação (Score + Retrospectiva de PI).
        buildMonthSelector();
        buildPISelector();
        buildTabs();
        buildRetroSubTabs();
        wireRetroControls();

        // 1b. Menu por perfil (Task 29): Seletor de Perfil + menu por seções a
        // partir de Rbac.menuFor(perfil). Só ativa a navegação por tela inicial
        // do perfil quando o RBAC está disponível (no browser/testes de menu);
        // nos contextos sem RBAC (ex.: wiring legado), o fluxo de abas de Score
        // segue inalterado para não haver regressão.
        buildProfileSelector();
        buildMenu();

        // 2. Inicializa o legado (vive dentro de #view-legado).
        initLegacyDashboard();

        // 3. Assina mudanças de estado e aplica o estado inicial.
        AppState.subscribe(onStateChange);

        if (profileNavEnabled()) {
            // Tela inicial conforme o perfil (Req 25.4). applyScreen aciona o
            // AppState.setActiveView (para telas de Score), que por sua vez
            // dispara onStateChange e renderiza a tela inicial.
            applyHomeForProfile();
            // Garante a sincronização inicial do menu com a tela ativa.
            syncMenuSelection();
        } else {
            onStateChange({
                type: 'init',
                view: AppState.getActiveView(),
                month: AppState.getSelectedMonth()
            });
        }

        console.log('✅ Navegação inicializada (mês + abas + Retro PI + menu por perfil)');
    }

    // No browser, dispara o init quando o DOM estiver pronto. Em Node (testes)
    // não há `document`, então apenas expomos as funções de wiring para que os
    // testes montem um `document` stub e chamem `init()` explicitamente.
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('DOMContentLoaded', init);
    }

    // API interna exposta para os testes de integração (wiring). Não é usada
    // pelo browser além do bootstrap acima.
    return {
        VIEW_META,
        RETRO_VIEW_META,
        RETRO_GROUP,
        init,
        buildMonthSelector,
        buildPISelector,
        buildTabs,
        buildRetroSubTabs,
        syncTabsSelection,
        syncRetroSubTabsSelection,
        showActiveContainer,
        renderActiveView,
        renderActiveRetroView,
        renderPlaceholder,
        initLegacyDashboard,
        onStateChange,
        activateRetro,
        setActiveRetroView,
        wireRetroControls,
        refreshRetroFilterOptions,
        // Menu por perfil + guarda de acesso (Task 29).
        buildProfileSelector,
        buildMenu,
        syncMenuSelection,
        selectScreen,
        applyHomeForProfile,
        onProfileChange,
        // Expostos para introspecção nos testes de wiring (estado de sessão).
        isRetroActive: () => retroActive,
        getActiveRetroView: () => activeRetroView,
        getActiveScreen: () => activeScreen,
        profileNavEnabled
    };
})();

// Environment guard: expõe o módulo de wiring para testes em Node (Vitest) sem
// afetar o carregamento por <script> no browser. No browser `module` é
// undefined, então este bloco é ignorado e `PEPApp` permanece como global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PEPApp;
}

if (typeof globalThis !== 'undefined') {
    globalThis.PEPApp = PEPApp;
}
