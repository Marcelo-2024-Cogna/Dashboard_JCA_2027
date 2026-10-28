/* ============================================================
   extracao.js — Modal "Extrair do Jira" (v1.5, ETAPA 6b-2)
   - Filtros em cascata com seleção múltipla (Jornadas → Times;
     PIs → Sprints), alimentados por GET /extracao/catalogo
   - Agendamento via POST /extracao/agendar (fetch próprio, para ler
     o corpo de 400/409)
   - Fila de extrações (GET /extracao/fila), com auto-refresh de ~15s
     apenas enquanto o modal estiver aberto
   Segurança: todo texto vindo do servidor entra no DOM via
   textContent (nunca innerHTML).
   ============================================================ */

(function () {
  // O script é carregado no <head>: espera o DOM para localizar o modal.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  function init() {
  const BASE = window.APP_BASE || '';
  const LS_EMAIL = 'extracao.email';
  const REFRESH_MS = 15000;

  const $ = (id) => document.getElementById(id);

  const dlg = $('dlg-extracao');
  const btnOpen = $('btn-extrair');
  if (!dlg || !btnOpen) return; // página sem o modal (ex: painel)

  // ── Estado ───────────────────────────────────────────────────────────────
  let catalog = null;                 // resposta de /extracao/catalogo
  let loadedOnce = false;
  let sending = false;
  let timer = null;
  const sel = {
    jornadas: new Set(),
    pis: new Set(),
    sprints: new Set(),
    times: new Set(),                 // projeto_key
  };

  // ── Helpers de DOM / formatação ──────────────────────────────────────────
  function el(tag, props, children) {
    const n = document.createElement(tag);
    if (props) {
      Object.entries(props).forEach(([k, v]) => {
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else n.setAttribute(k, v);
      });
    }
    (children || []).forEach((c) => c && n.appendChild(c));
    return n;
  }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

  /** 'YYYY-MM-DD[ HH:MM:SS]' → 'dd/mm/aaaa[ HH:MM]' sem converter fuso (o servidor já usa America/Sao_Paulo). */
  function fmtData(s, comHora) {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(s || '');
    if (!m) return s || '—';
    return `${m[3]}/${m[2]}/${m[1]}` + (comHora && m[4] ? ` ${m[4]}:${m[5]}` : '');
  }
  function plural(n, um, varios) { return `${n} ${n === 1 ? um : varios}`; }
  function emailOk(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

  // ── Mensagens ────────────────────────────────────────────────────────────
  const msgBox = $('ext-msg');
  function showMsg(kind, text, detalhes) {
    clear(msgBox);
    msgBox.className = 'ext-msg alert alert-' + kind;
    msgBox.appendChild(el('span', { text }));
    if (detalhes && detalhes.length) {
      msgBox.appendChild(el('div', { class: 'ext-msg-det', text: detalhes.join(', ') }));
    }
  }
  function clearMsg() {
    clear(msgBox);
    msgBox.className = 'ext-msg';
    $('ext-confirmar').hidden = true;
  }

  // ── Catálogo → derivados da cascata ──────────────────────────────────────
  function sprintsDisponiveis() {
    const seen = new Map();
    sel.pis.forEach((pi) => {
      ((catalog.sprints_por_pi || {})[pi] || []).forEach((s) => {
        if (!seen.has(s.sprint)) seen.set(s.sprint, { sprint: s.sprint, tipo: s.tipo, pi });
      });
    });
    return [...seen.values()];
  }
  function timesDisponiveis() {
    const out = [];
    sel.jornadas.forEach((j) => {
      ((catalog.times_por_jornada || {})[j] || []).forEach((t) => out.push({ jornada: j, time: t.time, key: t.projeto_key }));
    });
    return out;
  }

  // ── Render das listas ────────────────────────────────────────────────────
  function checkItem(grupo, value, texto, checked, extra) {
    const input = el('input', { type: 'checkbox', value });
    input.dataset.grupo = grupo;
    input.checked = checked;
    const lab = el('label', { class: 'ext-item' }, [input, el('span', { class: 'ext-item-txt', text: texto })]);
    if (extra) lab.appendChild(extra);
    return lab;
  }
  function vazio(texto) { return el('p', { class: 'ext-vazio', text: texto }); }

  function renderJornadas() {
    const box = $('lst-jornadas'); clear(box);
    (catalog.jornadas || []).forEach((j) => box.appendChild(checkItem('jornadas', j.nome, j.nome, sel.jornadas.has(j.nome))));
    if (!box.firstChild) box.appendChild(vazio('Nenhuma jornada ativa.'));
  }
  function renderPis() {
    const box = $('lst-pis'); clear(box);
    (catalog.pis || []).forEach((p) => {
      const periodo = p.data_ini && p.data_fim ? el('span', { class: 'ext-sub', text: `${fmtData(p.data_ini)} – ${fmtData(p.data_fim)}` }) : null;
      box.appendChild(checkItem('pis', p.desc_pi, p.desc_pi, sel.pis.has(p.desc_pi), periodo));
    });
    if (!box.firstChild) box.appendChild(vazio('Nenhuma PI ativa.'));
  }
  function renderSprints() {
    const box = $('lst-sprints'); clear(box);
    sprintsDisponiveis().forEach((s) => {
      const tipo = String(s.tipo || '').toLowerCase();
      const cls = tipo.indexOf('sust') === 0 ? 'bs bs-risk' : 'bs bs-prog';
      const rotulo = tipo.indexOf('sust') === 0 ? 'sustentação' : (tipo || '—');
      box.appendChild(checkItem('sprints', s.sprint, s.sprint, sel.sprints.has(s.sprint), el('span', { class: cls, text: rotulo })));
    });
    if (!box.firstChild) box.appendChild(vazio('Marque ao menos uma PI para ver as sprints.'));
  }
  function renderTimes() {
    const box = $('lst-times'); clear(box);
    let atual = null;
    timesDisponiveis().forEach((t) => {
      if (t.jornada !== atual) {
        atual = t.jornada;
        box.appendChild(el('div', { class: 'ext-grp', text: atual }));
      }
      box.appendChild(checkItem('times', t.key, t.time, sel.times.has(t.key), el('span', { class: 'ext-sub mono', text: t.key })));
    });
    if (!box.firstChild) box.appendChild(vazio('Marque ao menos uma jornada para ver os times.'));
  }

  function renderResumo() {
    const sp = sprintsDisponiveis();
    const tm = timesDisponiveis();
    const nTimesTotal = tm.length;
    const keysTotal = new Set(tm.map((t) => t.key));

    const nSprints = sel.sprints.size
      ? plural(sel.sprints.size, 'sprint', 'sprints')
      : `todas as sprints (${sp.length})`;
    const nTimes = sel.times.size
      ? plural(tm.filter((t) => sel.times.has(t.key)).length, 'time', 'times')
      : `todos os times (${nTimesTotal})`;
    const nProj = sel.times.size ? sel.times.size : keysTotal.size;

    $('ext-resumo').textContent =
      `${plural(sel.jornadas.size, 'jornada', 'jornadas')} · ${plural(sel.pis.size, 'PI', 'PIs')} · ` +
      `${nSprints} · ${nTimes} (${plural(nProj, 'projeto', 'projetos')})`;
  }

  /** Remove seleções que deixaram de existir após mudança em nível superior. */
  function podar() {
    const sprintsOk = new Set(sprintsDisponiveis().map((s) => s.sprint));
    [...sel.sprints].forEach((s) => { if (!sprintsOk.has(s)) sel.sprints.delete(s); });
    const timesOk = new Set(timesDisponiveis().map((t) => t.key));
    [...sel.times].forEach((k) => { if (!timesOk.has(k)) sel.times.delete(k); });
  }

  function renderTudo(quais) {
    const todos = !quais;
    if (todos || quais.includes('jornadas')) renderJornadas();
    if (todos || quais.includes('pis')) renderPis();
    if (todos || quais.includes('sprints')) renderSprints();
    if (todos || quais.includes('times')) renderTimes();
    renderResumo();
  }

  // Muda um grupo (checkbox ou atalho) e propaga a cascata.
  function aoMudar(grupo) {
    clearMsg();
    podar();
    if (grupo === 'pis') renderTudo(['sprints']);
    else if (grupo === 'jornadas') renderTudo(['times']);
    else renderResumo();
  }

  // ── Catálogo ─────────────────────────────────────────────────────────────
  async function carregarCatalogo() {
    $('ext-carregando').hidden = false;
    $('ext-campos').hidden = true;
    $('ext-agendar').disabled = true;
    try {
      const res = await fetch(`${BASE}/extracao/catalogo`, { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(`HTTP ${res.status} em /extracao/catalogo`);
      catalog = await res.json();

      sel.jornadas.clear(); sel.pis.clear(); sel.sprints.clear(); sel.times.clear();
      if (catalog.pi_corrente) sel.pis.add(catalog.pi_corrente);

      let email = '';
      try { email = localStorage.getItem(LS_EMAIL) || ''; } catch (e) { /* storage indisponível */ }
      $('ext-email').value = email || catalog.email_padrao || '';

      renderTudo();
      $('ext-carregando').hidden = true;
      $('ext-campos').hidden = false;
      $('ext-agendar').disabled = false;
      loadedOnce = true;
    } catch (e) {
      console.error('[extracao] falha ao carregar catálogo:', e);
      clear($('ext-carregando'));
      $('ext-carregando').textContent = 'Não foi possível carregar o catálogo. Feche e abra o modal para tentar de novo.';
    }
  }

  // ── Envio ────────────────────────────────────────────────────────────────
  function setSending(on) {
    sending = on;
    $('ext-agendar').disabled = on;
    $('ext-confirmar').disabled = on;
    $('ext-agendar').textContent = on ? 'Agendando…' : 'Agendar extração';
  }

  function validar() {
    const email = $('ext-email').value.trim();
    const eErro = $('ext-email-erro');
    eErro.textContent = '';
    $('ext-email').removeAttribute('aria-invalid');
    if (!sel.jornadas.size) { showMsg('error', 'Selecione ao menos 1 jornada.'); return null; }
    if (!sel.pis.size) { showMsg('error', 'Selecione ao menos 1 PI (versão corrigida).'); return null; }
    if (email !== '' && !emailOk(email)) {
      eErro.textContent = 'Informe um e-mail válido.';
      $('ext-email').setAttribute('aria-invalid', 'true');
      $('ext-email').focus();
      return null;
    }
    return {
      jornadas: [...sel.jornadas],
      pis: [...sel.pis],
      sprints: [...sel.sprints],
      times: [...sel.times],
      email,
    };
  }

  async function enviar(confirmar) {
    if (sending) return;
    clearMsg();
    const payload = validar();
    if (!payload) return;
    payload.confirmar = confirmar === true;

    setSending(true);
    try {
      const res = await fetch(`${BASE}/extracao/agendar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      let body = null;
      try { body = await res.json(); } catch (e) { /* corpo não-JSON */ }

      if (res.status === 200 && body) {
        try { if (payload.email) localStorage.setItem(LS_EMAIL, payload.email); } catch (e) { /* ignora */ }
        const quando = fmtData(body.agendado_para, true);
        showMsg('ok', `Extração agendada para ${quando}. Você será avisado por e-mail ao concluir.`);
        carregarFila();
      } else if (res.status === 400) {
        const det = body && Array.isArray(body.invalidos) ? body.invalidos.map(String) : null;
        showMsg('error', (body && body.erro) || 'Dados inválidos.', det);
      } else if (res.status === 409) {
        showMsg('info', (body && body.aviso) || 'Já existe uma extração igual hoje. A anterior será apagada e substituída.');
        $('ext-confirmar').hidden = false;
        $('ext-confirmar').focus();
      } else {
        console.error('[extracao] resposta inesperada', res.status, body);
        showMsg('error', 'Não foi possível agendar agora. Tente novamente em instantes.');
      }
    } catch (e) {
      console.error('[extracao] erro de rede ao agendar:', e);
      showMsg('error', 'Falha de comunicação com o servidor. Verifique a conexão e tente novamente.');
    } finally {
      setSending(false);
    }
  }

  // ── Fila ─────────────────────────────────────────────────────────────────
  const STATUS = {
    pendente:    { cls: 'bs bs-todo',  txt: 'pendente' },
    processando: { cls: 'bs bs-prog',  txt: 'processando' },
    concluido:   { cls: 'bs bs-done',  txt: 'concluído' },
    erro:        { cls: 'bs bs-block', txt: 'erro' },
  };

  async function carregarFila() {
    const box = $('fila-lista');
    try {
      const res = await fetch(`${BASE}/extracao/fila`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!res.ok) throw new Error(`HTTP ${res.status} em /extracao/fila`);
      const data = await res.json();
      renderFila(data.ordens || []);
      const d = new Date();
      $('fila-quando').textContent = 'Atualizado às ' + d.toLocaleTimeString('pt-BR');
    } catch (e) {
      console.error('[extracao] falha ao carregar a fila:', e);
      clear(box);
      box.appendChild(el('div', { class: 'alert alert-error', text: 'Não foi possível carregar a fila.' }));
    }
  }

  function renderFila(ordens) {
    const box = $('fila-lista'); clear(box);
    if (!ordens.length) { box.appendChild(vazio('Nenhuma extração agendada ainda.')); return; }

    const head = el('tr', null, ['#', 'Status', 'Agendado para', 'Itens', 'Detalhe'].map((h) => el('th', { scope: 'col', text: h })));
    const tbody = el('tbody');
    ordens.forEach((o) => {
      const st = STATUS[o.status] || { cls: 'bs bs-todo', txt: String(o.status || '—') };
      let detalhe = '';
      const msg = String(o.mensagem || '');
      if (o.status === 'erro' || o.log_status === 'erro') detalhe = msg || 'Falha sem detalhe registrado.';
      else if (msg.indexOf('FALHAS:') !== -1) detalhe = msg.slice(msg.indexOf('FALHAS:'));
      const tdDet = el('td', { class: 'fila-det' });
      if (detalhe) tdDet.appendChild(el('span', { class: 'fila-erro', text: detalhe }));
      tbody.appendChild(el('tr', null, [
        el('td', { text: '#' + o.id }),
        el('td', null, [el('span', { class: st.cls, text: st.txt })]),
        el('td', { text: fmtData(o.agendado_para, true) }),
        el('td', { text: o.total_itens == null ? '—' : String(o.total_itens) }),
        tdDet,
      ]));
    });
    const table = el('table', { class: 'fila-tab' }, [el('thead', null, [head]), tbody]);
    box.appendChild(el('div', { class: 'twrap' }, [table]));
  }

  function iniciarAutoRefresh() {
    pararAutoRefresh();
    timer = setInterval(carregarFila, REFRESH_MS);
  }
  function pararAutoRefresh() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  // ── Abas ─────────────────────────────────────────────────────────────────
  function ativarAba(qual) {
    const nova = qual === 'nova';
    $('tab-nova').classList.toggle('is-active', nova);
    $('tab-fila').classList.toggle('is-active', !nova);
    $('tab-nova').setAttribute('aria-selected', String(nova));
    $('tab-fila').setAttribute('aria-selected', String(!nova));
    $('tab-nova').tabIndex = nova ? 0 : -1;
    $('tab-fila').tabIndex = nova ? -1 : 0;
    $('painel-nova').hidden = !nova;
    $('painel-fila').hidden = nova;
    $('ext-agendar').hidden = !nova;
    if (!nova) carregarFila();
  }

  // ── Abrir / fechar ───────────────────────────────────────────────────────
  function abrir() {
    clearMsg();
    dlg.showModal();
    ativarAba('nova');
    if (!loadedOnce) carregarCatalogo();
    iniciarAutoRefresh();
    const primeiro = dlg.querySelector('.ext-item input') || $('ext-titulo');
    if (primeiro === $('ext-titulo')) $('ext-titulo').tabIndex = -1;
    primeiro.focus();
  }
  function fechar() { if (dlg.open) dlg.close(); }

  dlg.addEventListener('close', () => {
    pararAutoRefresh();
    btnOpen.focus();
  });
  // Clique no backdrop (fora do conteúdo) fecha.
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) fechar(); });

  // ── Eventos ──────────────────────────────────────────────────────────────
  btnOpen.addEventListener('click', abrir);
  $('ext-fechar').addEventListener('click', fechar);
  $('ext-cancelar').addEventListener('click', fechar);
  $('ext-form').addEventListener('submit', (ev) => { ev.preventDefault(); enviar(false); });
  $('ext-confirmar').addEventListener('click', () => enviar(true));
  $('fila-atualizar').addEventListener('click', carregarFila);

  $('tab-nova').addEventListener('click', () => ativarAba('nova'));
  $('tab-fila').addEventListener('click', () => ativarAba('fila'));
  [$('tab-nova'), $('tab-fila')].forEach((tab) => {
    tab.addEventListener('keydown', (ev) => {
      if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
      ev.preventDefault();
      const destino = tab === $('tab-nova') ? 'fila' : 'nova';
      ativarAba(destino);
      $('tab-' + destino).focus();
    });
  });

  $('ext-email').addEventListener('input', () => {
    $('ext-email-erro').textContent = '';
    $('ext-email').removeAttribute('aria-invalid');
    clearMsg();
  });

  // Delegação: marcar/desmarcar checkboxes das listas.
  $('ext-campos').addEventListener('change', (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLInputElement) || t.type !== 'checkbox' || !t.dataset.grupo) return;
    const set = sel[t.dataset.grupo];
    if (t.checked) set.add(t.value); else set.delete(t.value);
    aoMudar(t.dataset.grupo);
  });

  // Delegação: atalhos "Selecionar todos" / "Limpar" / "Todas as sprints da PI".
  $('ext-campos').addEventListener('click', (ev) => {
    const b = ev.target.closest && ev.target.closest('button[data-sel]');
    if (!b) return;
    const grupo = b.dataset.alvo;
    const set = sel[grupo];
    set.clear();
    if (b.dataset.sel === 'all') {
      const valores = grupo === 'jornadas' ? (catalog.jornadas || []).map((j) => j.nome)
        : grupo === 'pis' ? (catalog.pis || []).map((p) => p.desc_pi)
        : grupo === 'sprints' ? sprintsDisponiveis().map((s) => s.sprint)
        : timesDisponiveis().map((t) => t.key);
      valores.forEach((v) => set.add(v));
    }
    clearMsg();
    podar();
    // Re-renderiza o próprio grupo e os dependentes.
    if (grupo === 'jornadas') renderTudo(['jornadas', 'times']);
    else if (grupo === 'pis') renderTudo(['pis', 'sprints']);
    else renderTudo([grupo]);
  });
  } // init
})();
