/**
 * PEP - Plataforma de Engenharia de Performance
 * Fluxo de Exceção com aprovação (`js/views/view-excecoes.js`)
 *
 * Task 32 (spec score-performance-executivo). Requirements 30.1–30.10 e a seção
 * de design "Fase 3 → Novas telas de dashboard → view-excecoes".
 *
 *   - Formulário de SOLICITAÇÃO (Squad por identidade canônica, indicador entre
 *     as 12 métricas do Score, justificativa e período de referência), visível
 *     a quem tem `excecao:solicitar` (TM/Admin/DM).                    (30.1, 30.7)
 *   - Toda solicitação nasce "Pendente de aprovação".                  (30.2)
 *   - Fila de aprovação com botões Aprovar/Rejeitar (motivo), visível/ativa só
 *     para quem tem `excecao:aprovar` (Admin/DM). TM NÃO vê o botão de aprovar
 *     (apenas a fila em modo leitura).                                  (30.3, 30.5)
 *   - Aprovar marca "Aprovada" e sinaliza o indicador afetado; rejeitar marca
 *     "Rejeitada" com motivo e não aplica efeito.                       (30.4, 30.6)
 *   - Validação de campos obrigatórios com mensagem descritiva.         (30.8)
 *   - Histórico/persistência ficam no store (localStorage).             (30.9, 30.10)
 *
 * O RBAC é a FONTE ÚNICA de permissão: a view consulta `Rbac.canAction(profile,
 * 'excecao:solicitar'|'excecao:aprovar')` com o perfil ativo de
 * `AppState.getProfile()`. O estado das exceções vive em `ExcecoesStore`.
 *
 * Registro: `window.PEPViews.excecao = { render }`. O `app.js` chama
 * `render(container, { month })` ao ativar a tela (kind 'screenView'). O render
 * é isolado por try/catch no app.js e, defensivamente, também aqui.
 *
 * App estático, tema escuro preservado.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Resolução de dependências com guarda de ambiente.
    // -------------------------------------------------------------------------
    function dep(name) {
        const g = (typeof window !== 'undefined' && window[name]) ? window[name]
            : (typeof globalThis !== 'undefined' ? globalThis[name] : undefined);
        if (g) return g;
        if (typeof require === 'function') {
            const path = {
                ExcecoesStore: '../excecoes-store.js',
                Rbac: '../rbac.js',
                AppState: '../app-state.js',
                PEPIdentity: '../identity.js',
                PEPData: '../data.js',
                ScoringEngine: '../scoring-engine.js'
            }[name];
            if (path) { try { return require(path); } catch (e) { /* ignore */ } }
        }
        return undefined;
    }

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const STATUS_META = {
        pendente:  { label: 'Pendente de aprovação', color: '#f59e0b', cls: 'exc-badge-pendente' },
        aprovada:  { label: 'Aprovada',              color: '#22c55e', cls: 'exc-badge-aprovada' },
        rejeitada: { label: 'Rejeitada',             color: '#ef4444', cls: 'exc-badge-rejeitada' }
    };

    // Perfil ativo (fallback 'admin' quando AppState indisponível).
    function currentProfile() {
        const AppState = dep('AppState');
        try { return (AppState && AppState.getProfile) ? AppState.getProfile() : 'admin'; }
        catch (e) { return 'admin'; }
    }

    function canSolicitar(profile) {
        const Rbac = dep('Rbac');
        return !!(Rbac && Rbac.canAction && Rbac.canAction(profile, 'excecao:solicitar'));
    }
    function canAprovar(profile) {
        const Rbac = dep('Rbac');
        return !!(Rbac && Rbac.canAction && Rbac.canAction(profile, 'excecao:aprovar'));
    }

    // Lista plana de squads canônicos agrupados por jornada (p/ <optgroup>).
    function squadCatalog() {
        const Identity = dep('PEPIdentity');
        const out = [];
        if (!Identity) return out;
        let jornadas = [];
        try { jornadas = Identity.jornadas() || []; } catch (e) { jornadas = []; }
        jornadas.forEach((j) => {
            let squads = [];
            try { squads = Identity.squadsOf(j) || []; } catch (e) { squads = []; }
            if (squads.length) out.push({ jornada: j, squads });
        });
        return out;
    }

    // Lista de indicadores (12 métricas do Score) [{id, name}].
    function indicadores() {
        const ScoringEngine = dep('ScoringEngine');
        const metrics = (ScoringEngine && ScoringEngine.METRICS) ? ScoringEngine.METRICS : {};
        return Object.keys(metrics).map((id) => ({ id, name: metrics[id].name || id }));
    }

    // Métricas candidatas a SUBSTITUTA. Predefinição: as métricas que a
    // ferramenta já acompanha mas que NÃO entram no Score (painéis de
    // Retrospectiva de PI). Também permitimos as próprias métricas do Score como
    // alternativa. Agrupadas para o <optgroup> do select.
    function metricasSubstitutas() {
        const retro = [
            { id: 'retro:pontosTrabalhados', name: 'Pontos Trabalhados (Velocity)' },
            { id: 'retro:produtividade',     name: 'Produtividade' },
            { id: 'retro:situacaoEpicos',    name: 'Situação dos Épicos' },
            { id: 'retro:cycleTime',         name: 'Cycle Time (Retrospectiva)' },
            { id: 'retro:leadTime',          name: 'Lead Time por Tipo de Item' },
            { id: 'retro:debito',            name: 'Débito Técnico' },
            { id: 'retro:sustentacao',       name: 'Sustentação' },
            { id: 'retro:cargaTrabalho',     name: 'Carga de Trabalho' },
            { id: 'retro:alteracoesPlano',   name: 'Alterações no Plano' },
            { id: 'retro:impedimentos',      name: 'Impedimentos' }
        ];
        const score = indicadores().map((i) => ({ id: `score:${i.id}`, name: i.name }));
        return { retro, score };
    }

    // Lista de períodos (rótulos de mês do ano corrente) [{value,label}].
    function periodos() {
        const PEPData = dep('PEPData');
        const labels = (PEPData && Array.isArray(PEPData.MONTH_LABELS)) ? PEPData.MONTH_LABELS
            : ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        const year = (PEPData && PEPData.YEAR) ? PEPData.YEAR : new Date().getFullYear();
        return labels.map((l) => ({ value: `${l}/${year}`, label: `${l}/${year}` }));
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    function render(container, opts) {
        if (!container) return;

        const Store = dep('ExcecoesStore');
        if (!Store) {
            container.innerHTML = errorBlock('Módulo de exceções indisponível.');
            return;
        }

        const month = (opts && typeof opts.month === 'number') ? opts.month : null;
        const profile = currentProfile();

        const model = {
            profile,
            podeSolicitar: canSolicitar(profile),
            podeAprovar: canAprovar(profile),
            catalog: squadCatalog(),
            indicadores: indicadores(),
            substitutas: metricasSubstitutas(),
            periodos: periodos(),
            pendentes: safe(() => Store.pending(), []),
            historico: safe(() => Store.list(), []),
            defaultPeriodo: defaultPeriodo(month)
        };

        container.innerHTML = buildHTML(model);
        wire(container, Store, profile);
    }

    function defaultPeriodo(month) {
        const PEPData = dep('PEPData');
        const labels = (PEPData && Array.isArray(PEPData.MONTH_LABELS)) ? PEPData.MONTH_LABELS : null;
        const year = (PEPData && PEPData.YEAR) ? PEPData.YEAR : new Date().getFullYear();
        if (labels && typeof month === 'number' && month >= 1 && month <= 12) {
            return `${labels[month - 1]}/${year}`;
        }
        return '';
    }

    function safe(fn, fallback) {
        try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; }
    }

    // =========================================================================
    // HTML BUILDERS
    // =========================================================================
    function buildHTML(m) {
        return `
        <div class="exc">
            <div class="exc-head">
                <h2 class="exc-title">🚩 Fluxo de Exceção</h2>
                <p class="exc-sub">Solicitação e aprovação de exceções por indicador e squad.</p>
            </div>

            ${m.podeSolicitar ? solicitarForm(m) : noSolicitarNote()}

            ${filaAprovacao(m)}

            ${historico(m)}
        </div>`;
    }

    // Formulário de solicitação (Req 30.1). Só para quem tem excecao:solicitar.
    function solicitarForm(m) {
        return `
        <section class="exc-card" id="exc-form-card">
            <h3 class="exc-card-title">Nova solicitação de exceção</h3>
            <form id="exc-form" class="exc-form" novalidate>
                <div class="exc-field">
                    <label for="exc-squad">Squad *</label>
                    ${squadSelect(m.catalog)}
                </div>
                <div class="exc-field">
                    <label for="exc-indicador">Indicador *</label>
                    ${indicadorSelect(m.indicadores)}
                </div>
                <div class="exc-field">
                    <label for="exc-periodo">Período de referência *</label>
                    ${periodoSelect(m.periodos, m.defaultPeriodo)}
                </div>
                <div class="exc-field exc-field-full">
                    <label for="exc-justificativa">Justificativa *</label>
                    <textarea id="exc-justificativa" class="exc-input" rows="3"
                        placeholder="Descreva o motivo da exceção..."></textarea>
                </div>
                ${tratamentoFields(m)}
                <div class="exc-form-actions">
                    <div class="exc-form-msg" id="exc-form-msg" role="alert" aria-live="polite"></div>
                    <button type="submit" class="exc-btn exc-btn-primary" id="exc-submit">Solicitar exceção</button>
                </div>
            </form>
        </section>`;
    }

    // Campos de tratamento da pontuação (Req: redistribuir vs substituir).
    // Checkbox "Substituir métrica": se DESMARCADO (default), a pontuação da
    // métrica excetuada é redistribuída entre as demais; se MARCADO, o select da
    // métrica substituta é habilitado e seu valor passa a ser obrigatório.
    function tratamentoFields(m) {
        return `
        <div class="exc-field exc-field-full exc-tratamento">
            <label class="exc-check">
                <input type="checkbox" id="exc-substituir">
                <span>Substituir métrica por outra</span>
            </label>
            <p class="exc-hint" id="exc-tratamento-hint">
                Desmarcado: a pontuação da métrica excetuada é <strong>redistribuída</strong> entre as demais métricas do time.
                Marcado: a métrica é <strong>substituída</strong> pela métrica selecionada abaixo.
            </p>
            <div class="exc-field" id="exc-substituta-wrap" hidden>
                <label for="exc-substituta">Métrica substituta *</label>
                ${substitutaSelect(m.substitutas)}
            </div>
        </div>`;
    }

    function substitutaSelect(sub) {
        const s = sub || { retro: [], score: [] };
        const grpRetro = (s.retro || []).map((i) => `<option value="${esc(i.id)}">${esc(i.name)}</option>`).join('');
        const grpScore = (s.score || []).map((i) => `<option value="${esc(i.id)}">${esc(i.name)}</option>`).join('');
        return `<select id="exc-substituta" class="exc-input" disabled>
                    <option value="">Selecione a métrica substituta</option>
                    <optgroup label="Métricas acompanhadas (fora do Score)">${grpRetro}</optgroup>
                    <optgroup label="Métricas do Score">${grpScore}</optgroup>
                </select>`;
    }

    function noSolicitarNote() {
        return `
        <section class="exc-card exc-note">
            <p>Seu perfil não permite solicitar exceções. A fila de aprovação é exibida abaixo em modo de leitura.</p>
        </section>`;
    }

    function squadSelect(catalog) {
        if (!catalog.length) {
            return `<select id="exc-squad" class="exc-input" disabled>
                        <option value="">Sem squads disponíveis</option>
                    </select>`;
        }
        const groups = catalog.map((g) => {
            const opts = g.squads.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
            return `<optgroup label="${esc(g.jornada)}">${opts}</optgroup>`;
        }).join('');
        return `<select id="exc-squad" class="exc-input">
                    <option value="">Selecione um squad</option>${groups}
                </select>`;
    }

    function indicadorSelect(inds) {
        const opts = inds.map((i) => `<option value="${esc(i.name)}">${esc(i.name)}</option>`).join('');
        return `<select id="exc-indicador" class="exc-input">
                    <option value="">Selecione um indicador</option>${opts}
                </select>`;
    }

    function periodoSelect(pers, def) {
        const opts = pers.map((p) => {
            const sel = (p.value === def) ? ' selected' : '';
            return `<option value="${esc(p.value)}"${sel}>${esc(p.label)}</option>`;
        }).join('');
        return `<select id="exc-periodo" class="exc-input">
                    <option value="">Selecione um período</option>${opts}
                </select>`;
    }

    // Fila de aprovação (Req 30.5). Botões de aprovar/rejeitar só para quem tem
    // excecao:aprovar (Req 30.3) — TM vê a fila sem os botões (apenas leitura).
    function filaAprovacao(m) {
        const rows = (m.pendentes || []).map((r) => pendingRow(r, m.podeAprovar)).join('');
        return `
        <section class="exc-card" id="exc-fila-card">
            <h3 class="exc-card-title">Fila de aprovação <span class="exc-count">(${(m.pendentes || []).length})</span></h3>
            ${(m.pendentes && m.pendentes.length)
                ? `<div class="exc-list" id="exc-fila">${rows}</div>`
                : `<p class="exc-empty">Nenhuma solicitação pendente.</p>`}
        </section>`;
    }

    function pendingRow(r, podeAprovar) {
        const actions = podeAprovar
            ? `<div class="exc-row-actions">
                   <button type="button" class="exc-btn exc-btn-approve" data-approve="${esc(r.id)}">Aprovar</button>
                   <button type="button" class="exc-btn exc-btn-reject" data-reject="${esc(r.id)}">Rejeitar</button>
               </div>`
            : `<div class="exc-row-actions exc-readonly"><span class="exc-readonly-note">Somente aprovadores</span></div>`;
        return `
        <div class="exc-row" data-id="${esc(r.id)}">
            <div class="exc-row-main">
                <div class="exc-row-head">
                    <span class="exc-badge exc-badge-pendente">${esc(STATUS_META.pendente.label)}</span>
                    <strong class="exc-row-squad">${esc(r.squad)}</strong>
                    <span class="exc-row-ind">${esc(r.indicador)}</span>
                    <span class="exc-row-per">${esc(r.periodo)}</span>
                </div>
                <p class="exc-row-just">${esc(r.justificativa)}</p>
                <span class="exc-row-meta">Solicitado por ${esc(r.solicitante)}</span>
                <span class="exc-row-meta">${tratamentoLabel(r)}</span>
            </div>
            ${actions}
        </div>`;
    }

    // Rótulo legível do tratamento da exceção (para fila/histórico).
    function tratamentoLabel(r) {
        if (r && r.tratamento === 'substituir') {
            const nome = metricaSubstitutaNome(r.metricaSubstituta);
            return `Tratamento: substituir por ${esc(nome || r.metricaSubstituta || '—')}`;
        }
        return 'Tratamento: redistribuir pontuação entre as demais métricas';
    }

    // Resolve o nome amigável de uma métrica substituta a partir do id
    // ('retro:*' ou 'score:*').
    function metricaSubstitutaNome(id) {
        if (!id) return '';
        const sub = metricasSubstitutas();
        const all = [...(sub.retro || []), ...(sub.score || [])];
        const found = all.find((x) => x.id === id);
        return found ? found.name : id;
    }

    // Histórico (Req 30.10) — todas as solicitações e seus estados/decisões.
    function historico(m) {
        const all = (m.historico || []).slice().reverse();
        const rows = all.map((r) => historyRow(r)).join('');
        return `
        <section class="exc-card" id="exc-hist-card">
            <h3 class="exc-card-title">Histórico <span class="exc-count">(${all.length})</span></h3>
            ${all.length
                ? `<div class="exc-list exc-hist">${rows}</div>`
                : `<p class="exc-empty">Nenhuma exceção registrada.</p>`}
        </section>`;
    }

    function historyRow(r) {
        const meta = STATUS_META[r.status] || STATUS_META.pendente;
        const decisao = (r.status === 'aprovada')
            ? `<span class="exc-row-meta">Aprovada por ${esc(r.decididoPor)}</span>`
            : (r.status === 'rejeitada')
                ? `<span class="exc-row-meta">Rejeitada por ${esc(r.decididoPor)} — motivo: ${esc(r.motivoRejeicao)}</span>`
                : '';
        return `
        <div class="exc-row exc-hist-row" data-id="${esc(r.id)}">
            <div class="exc-row-main">
                <div class="exc-row-head">
                    <span class="exc-badge ${meta.cls}">${esc(meta.label)}</span>
                    <strong class="exc-row-squad">${esc(r.squad)}</strong>
                    <span class="exc-row-ind">${esc(r.indicador)}</span>
                    <span class="exc-row-per">${esc(r.periodo)}</span>
                </div>
                <p class="exc-row-just">${esc(r.justificativa)}</p>
                <span class="exc-row-meta">Solicitado por ${esc(r.solicitante)}</span>
                <span class="exc-row-meta">${tratamentoLabel(r)}</span>
                ${decisao}
            </div>
        </div>`;
    }

    function errorBlock(msg) {
        return `
        <div class="exc">
            <div class="exc-empty">
                <span aria-hidden="true">⚠️</span> ${esc(msg)}
            </div>
        </div>`;
    }

    // =========================================================================
    // WIRING (submit + aprovar/rejeitar). Re-renderiza o mesmo container.
    // =========================================================================
    function wire(container, Store, profile) {
        if (!container || typeof container.querySelector !== 'function') return;

        // Submit do formulário de solicitação.
        const form = container.querySelector('#exc-form');
        if (form && typeof form.addEventListener === 'function') {
            form.addEventListener('submit', (e) => {
                if (e && typeof e.preventDefault === 'function') e.preventDefault();
                handleSubmit(container, Store, profile);
            });
        }

        // Toggle do select de métrica substituta conforme o checkbox "Substituir".
        const chk = container.querySelector('#exc-substituir');
        if (chk && typeof chk.addEventListener === 'function') {
            chk.addEventListener('change', () => toggleSubstituta(container));
        }

        // Aprovar / Rejeitar (delegação simples por data-attrs). Só efetiva se o
        // perfil tiver permissão (defesa em profundidade além de ocultar o botão).
        const fila = container.querySelector('#exc-fila');
        if (fila && typeof fila.addEventListener === 'function') {
            fila.addEventListener('click', (e) => {
                const target = e && e.target ? e.target : null;
                if (!target || typeof target.getAttribute !== 'function') return;
                const approveId = target.getAttribute('data-approve');
                const rejectId = target.getAttribute('data-reject');
                if (approveId) handleApprove(container, Store, profile, approveId);
                else if (rejectId) handleReject(container, Store, profile, rejectId);
            });
        }
    }

    function val(container, sel) {
        const el = container.querySelector(sel);
        return el && el.value != null ? el.value : '';
    }

    function isChecked(container, sel) {
        const el = container.querySelector(sel);
        return !!(el && el.checked);
    }

    // Habilita/desabilita e mostra/oculta o select da métrica substituta.
    function toggleSubstituta(container) {
        const on = isChecked(container, '#exc-substituir');
        const wrap = container.querySelector('#exc-substituta-wrap');
        const sel = container.querySelector('#exc-substituta');
        if (wrap) wrap.hidden = !on;
        if (sel) {
            sel.disabled = !on;
            if (!on) sel.value = '';
        }
    }

    function showMsg(container, msg, kind) {
        const box = container.querySelector('#exc-form-msg');
        if (box) {
            box.textContent = msg || '';
            box.className = 'exc-form-msg' + (kind ? ` exc-msg-${kind}` : '');
        }
    }

    function handleSubmit(container, Store, profile) {
        if (!canSolicitar(profile)) {
            showMsg(container, 'Seu perfil não permite solicitar exceções.', 'error');
            return;
        }
        const substituir = isChecked(container, '#exc-substituir');
        const Store2 = dep('ExcecoesStore');
        const T = (Store2 && Store2.TRATAMENTO) ? Store2.TRATAMENTO : { REDISTRIBUIR: 'redistribuir', SUBSTITUIR: 'substituir' };
        const input = {
            squad: val(container, '#exc-squad'),
            indicador: val(container, '#exc-indicador'),
            periodo: val(container, '#exc-periodo'),
            justificativa: val(container, '#exc-justificativa'),
            tratamento: substituir ? T.SUBSTITUIR : T.REDISTRIBUIR,
            metricaSubstituta: substituir ? val(container, '#exc-substituta') : '',
            solicitante: profile
        };
        const errors = Store.validate(input);
        if (errors.length) {
            // Mensagem descritiva de TODOS os campos faltantes (Req 30.8).
            showMsg(container, errors.map((e) => e.message).join(' '), 'error');
            return;
        }
        try {
            Store.request(input);
            // Re-render preserva mês implícito; mantém o perfil atual.
            render(container, { month: currentMonth() });
            showMsg(container, 'Solicitação criada e pendente de aprovação.', 'ok');
        } catch (e) {
            showMsg(container, (e && e.message) ? e.message : 'Falha ao criar a solicitação.', 'error');
        }
    }

    function handleApprove(container, Store, profile, id) {
        if (!canAprovar(profile)) return; // defesa em profundidade (Req 30.3)
        try { Store.approve(id, profile); } catch (e) { /* ignora transição inválida */ }
        render(container, { month: currentMonth() });
    }

    function handleReject(container, Store, profile, id) {
        if (!canAprovar(profile)) return;
        const motivo = promptMotivo();
        if (motivo == null) return;            // cancelado
        if (String(motivo).trim() === '') return; // motivo obrigatório (Req 30.3)
        try { Store.reject(id, profile, motivo); } catch (e) { /* ignora */ }
        render(container, { month: currentMonth() });
    }

    // prompt do motivo (browser). Em ambiente sem prompt, retorna null (no-op).
    function promptMotivo() {
        try {
            if (typeof window !== 'undefined' && typeof window.prompt === 'function') {
                return window.prompt('Motivo da rejeição (obrigatório):', '');
            }
            if (typeof prompt === 'function') return prompt('Motivo da rejeição (obrigatório):', '');
        } catch (e) { /* ignore */ }
        return null;
    }

    function currentMonth() {
        const AppState = dep('AppState');
        try { return (AppState && AppState.getSelectedMonth) ? AppState.getSelectedMonth() : null; }
        catch (e) { return null; }
    }

    // =========================================================================
    // REGISTRO DA VISÃO (browser) + EXPORT PARA TESTE (Node)
    // =========================================================================
    const api = {
        render, buildHTML, squadCatalog, indicadores, metricasSubstitutas, periodos,
        canSolicitar, canAprovar
    };

    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.excecao = { render };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
