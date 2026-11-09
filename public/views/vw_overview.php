<?php
/**
 * vw_overview.php — Visão Geral (por jornada, consolidando projetos)
 * ──────────────────────────────────────────────────────────────────
 * Reescrita conforme §D.7: segue o padrão de vw_velocity.php.
 *   - #vg-root, fetch DIRETO de /graficos/visao-geral (não Api.get, para ler o
 *     corpo do 400 em português), el() com textContent (nunca innerHTML com
 *     dado do servidor), window.Charts.make, window.App.Filters.
 *   - O 'modo' (sprint|pi) é lido de location.search (Filters.read só lê
 *     jornada/time/versao/sprint). O filtro de sprint do header vence o modo.
 *   - Acessibilidade (NFR-3): todo canvas tem aria-label; tabelas têm caption e
 *     th scope; o toggle é aria-pressed; o dado de cada gráfico também aparece em
 *     tabela; cor nunca é o único canal; estados loading/erro/vazio explícitos.
 */
?>
<div id="vg-root">
  <div class="loading"><div class="spinner"></div> Carregando a Visão Geral...</div>
</div>

<script>
(function () {
  const root = document.getElementById('vg-root');
  const BASE = window.APP_BASE || '';

  // ── Helpers de DOM e formatação ───────────────────────────────────────
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf  = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const fmt  = v => (v == null ? '—' : nf.format(v));
  const fmt1 = v => (v == null ? '—' : nf1.format(v));
  const pctF = v => (v == null ? '—' : nf1.format(v) + '%');
  const int  = v => (v == null ? '—' : new Intl.NumberFormat('pt-BR').format(v));

  // Data "2026-10-26" → "26/10"
  function diaMes(iso) {
    if (!iso || typeof iso !== 'string') return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return m ? (m[3] + '/' + m[2]) : iso;
  }
  function dataHora(iso) {
    if (!iso || typeof iso !== 'string') return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(iso);
    if (m) return m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5];
    const d = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return d ? (d[3] + '/' + d[2]) : iso;
  }

  // Lê o 'modo' da URL (não faz parte de Filters.read).
  function modoDaUrl() {
    const m = (new URLSearchParams(location.search).get('modo') || '').toLowerCase().trim();
    return m === 'pi' ? 'pi' : 'sprint';
  }

  function mensagem(tipo, titulo, texto) {
    root.textContent = '';
    const a = el('div', 'alert alert-' + tipo);
    a.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    a.appendChild(el('strong', null, titulo));
    if (texto) { a.appendChild(document.createElement('br')); a.appendChild(el('span', null, texto)); }
    root.appendChild(a);
  }

  // ── Fetch direto (lê o corpo do 400 em português) ─────────────────────
  async function buscar() {
    const f = window.App.Filters.read();
    const qs = new URLSearchParams();
    ['jornada', 'time', 'versao', 'sprint'].forEach(k => { if (f[k]) qs.set(k, f[k]); });
    qs.set('modo', modoDaUrl());
    let res;
    try {
      res = await fetch(BASE + '/graficos/visao-geral?' + qs.toString(), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular a Visão Geral agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
    }
    if (!json || json.success !== true) throw new Error('Resposta inesperada do servidor.');
    return json;
  }

  // ── Cartões auxiliares ─────────────────────────────────────────────────
  function cardBase(titulo, badgeTxt, largo) {
    const card = el('div', 'ccard' + (largo ? ' vg-wide' : ''));
    const h = el('h3', null, titulo + ' ');
    if (badgeTxt) h.appendChild(el('span', 'badge', badgeTxt));
    card.appendChild(h);
    return card;
  }
  // Card aproximado: badge "aproximado por datas de fluxo" + regra em pr-sub.
  function cardAprox(titulo, regra, largo) {
    const card = el('div', 'ccard' + (largo ? ' vg-wide' : ''));
    const h = el('h3', null, titulo + ' ');
    const b = el('span', 'badge vg-badge-aprox', 'aproximado por datas de fluxo');
    b.title = 'Gadget aproximado: usa as datas de fluxo (não há sprint burndown nativo). ' + (regra || '');
    h.appendChild(b);
    card.appendChild(h);
    if (regra) card.appendChild(el('div', 'pr-sub', regra));
    return card;
  }
  // Mostra o motivo de um gadget vazio dentro do próprio card.
  function cardVazio(titulo, g, largo) {
    const card = cardBase(titulo, null, largo);
    const a = el('div', 'alert alert-info');
    a.style.margin = '6px 0 0';
    a.setAttribute('role', 'status');
    a.appendChild(el('span', null, (g && g.motivo) || 'Sem dados para a seleção.'));
    card.appendChild(a);
    card._vazio = true;
    return card;
  }

  function kpi(label, valor, sub, ac, tooltip) {
    const k = el('div', 'kpi');
    if (ac) k.style.setProperty('--ac', ac);
    const l = el('div', 'kpi-label', label);
    if (tooltip) { l.title = tooltip; l.appendChild(document.createTextNode(' ⓘ')); }
    k.appendChild(l);
    k.appendChild(el('div', 'kpi-val', valor));
    if (sub) k.appendChild(el('div', 'kpi-sub', sub));
    return k;
  }

  function tabelaBase(titulo, legenda, minWidth) {
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl vg-tbl');
    if (minWidth) tb.style.minWidth = minWidth;
    const cap = el('caption', null, legenda);
    cap.style.cssText = 'position:absolute;left:-9999px';
    tb.appendChild(cap);
    if (titulo) {
      const h = el('div', 'stitle', titulo);
      h.style.cssText = 'font-size:.74rem;margin:12px 0 6px';
      w.appendChild(h);
    }
    w.appendChild(tb);
    return { wrap: w, tb };
  }
  function cabecalho(tb, cols) {
    const thead = el('thead'); const trh = el('tr');
    cols.forEach(([t, c]) => { const th = el('th', c, t); th.scope = 'col'; trh.appendChild(th); });
    thead.appendChild(trh); tb.appendChild(thead);
  }

  // ── 1. Faixa de contexto ────────────────────────────────────────────────
  function faixaContexto(d) {
    const j = d.janela || {}, fa = d.filtros || {}, ex = d.extracao || {};
    const defas = ex.defasagem_dias;
    const ambar = defas != null && defas > 1;
    const box = el('div', 'alert ' + (ambar ? 'vg-faixa-ambar' : 'alert-info') + ' prev-janela');
    box.setAttribute('role', 'status');

    const linha1 = el('div');
    linha1.appendChild(el('strong', null, (j.pi || '—') + (fa.versao_padrao ? ' (PI corrente)' : '')));
    const partes = [];
    if (fa.jornada) partes.push('Jornada: ' + fa.jornada);
    else partes.push('Todas as jornadas (consolidado)');
    if (fa.time) partes.push('Time: ' + fa.time);
    linha1.appendChild(el('span', 'kpi-sub', '  ·  ' + partes.join('  ·  ')));
    box.appendChild(linha1);

    const sf = j.sprint_foco, sa = j.sprint_anterior;
    const ctx = el('div', 'kpi-sub');
    ctx.style.marginTop = '4px';
    const bits = [];
    if (sf) bits.push('Sprint em foco: ' + sf.nome + ' (' + (sf.status || '') + ', ' + diaMes(sf.data_ini) + '–' + diaMes(sf.data_fim) + ')');
    else bits.push('Sem sprint em foco nesta PI');
    if (sa) bits.push('Sprint anterior: ' + sa.nome);
    ctx.textContent = bits.join('  ·  ');
    box.appendChild(ctx);

    const ext = el('div', 'kpi-sub');
    ext.style.marginTop = '4px';
    if (ex.ultima_carga) {
      ext.textContent = 'Dados extraídos em ' + dataHora(ex.ultima_carga)
        + (defas != null ? ' (' + defas + ' dia' + (defas === 1 ? '' : 's') + ' de defasagem)' : '');
      if (ambar) ext.appendChild(el('strong', null, '  — atenção: dados podem estar desatualizados'));
    } else {
      ext.textContent = 'Sem registro de carga do Jira para esta seleção.';
    }
    box.appendChild(ext);

    if (Array.isArray(ex.prefixos_sem_time) && ex.prefixos_sem_time.length) {
      const semTime = ex.prefixos_sem_time.map(p => p.prefixo + ' (' + p.itens + ')').join(', ');
      box.appendChild(el('div', 'kpi-sub', 'Prefixos extraídos sem time ativo: ' + semTime));
    }
    return box;
  }

  // ── 2. Seletor Sprint ativa | PI inteira ─────────────────────────────────
  function seletor(d) {
    const modo = (d.filtros || {}).modo_efetivo || 'sprint';
    const temSprint = !!(d.filtros || {}).sprint;
    const wrap = el('div', 'vg-seg');
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Escopo da Visão Geral');

    const btnSprint = el('button', 'vg-seg-btn', 'Sprint ativa');
    btnSprint.type = 'button';
    btnSprint.setAttribute('aria-pressed', String(modo === 'sprint'));
    btnSprint.addEventListener('click', () => window.App.Filters.apply({ modo: 'sprint', sprint: '' }));

    const btnPi = el('button', 'vg-seg-btn', 'PI inteira');
    btnPi.type = 'button';
    btnPi.setAttribute('aria-pressed', String(modo === 'pi'));
    btnPi.addEventListener('click', () => window.App.Filters.apply({ modo: 'pi', sprint: '' }));

    wrap.append(btnSprint, btnPi);

    const box = el('div', 'vg-seg-wrap');
    box.appendChild(wrap);
    if (temSprint) {
      box.appendChild(el('span', 'pr-sub', 'Sprint escolhida no filtro do cabeçalho (' + d.filtros.sprint + ').'));
    }
    return box;
  }

  // ── 3. Avisos ─────────────────────────────────────────────────────────
  function avisos(d) {
    const lista = (d.avisos || []).slice();
    if (!(d.filtros || {}).jornada) {
      lista.push('Escolha uma jornada no cabeçalho para uma resposta mais rápida.');
    }
    if (!lista.length) return null;
    const ul = el('ul', 'prev-avisos');
    lista.forEach(a => ul.appendChild(el('li', null, a)));
    return ul;
  }

  // ── 4. Andamento ─────────────────────────────────────────────────────
  function cardAndamento(d) {
    const g = d.gadgets.andamento;
    const sec = el('div');
    sec.appendChild(el('div', 'stitle', 'Andamento da ' + (g.escopo === 'pi' ? 'PI' : 'sprint') + (g.rotulo_escopo ? ' — ' + g.rotulo_escopo : '')));
    if (!g || g.estado !== 'ok') {
      const a = el('div', 'alert alert-info'); a.setAttribute('role', 'status');
      a.appendChild(el('span', null, (g && g.motivo) || 'Sem dados para a seleção.'));
      sec.appendChild(a);
      return sec;
    }
    const grid = el('div', 'kpi-grid vg-kpi-grid');
    grid.appendChild(kpi('Backlog', fmt(g.backlog_sp) + ' SP', int(g.itens.backlog) + ' itens', '#7c8aaa'));
    grid.appendChild(kpi('Em andamento', fmt(g.em_andamento_sp) + ' SP', int(g.itens.em_andamento) + ' itens',
      '#6366f1', 'Inclui itens Concluído/Homologação/Pronto — prontos, ainda sem aceite; difere do filtro Jira 19364.'));
    grid.appendChild(kpi('Bloqueados', fmt(g.bloqueado_sp) + ' SP', int(g.itens.bloqueado) + ' itens', '#f43f5e'));
    grid.appendChild(kpi('Aceitos', fmt(g.aceitos_sp) + ' SP',
      'valor ' + fmt(g.aceitos_valor_sp) + ' · sustentação ' + fmt(g.aceitos_sustentacao_sp), '#10b981',
      'Fonte: ' + (g.aceitos_sp_origem === 'velocidade' ? 'Velocidade (= Entregues do Planejado x Realizado)' : 'cálculo local')));
    const prod = g.produtividade_ate_agora || {};
    grid.appendChild(kpi('Produtividade até agora', prod.h_por_sp == null ? '—' : fmt(prod.h_por_sp) + ' h/SP',
      'base: ' + fmt(prod.pontos) + ' SP de valor entregues', '#22d3ee',
      'Horas totais para entregar 1 ponto. Divisor usa só SP de valor; Aceitos inclui sustentação.'));
    grid.appendChild(kpi('Cancelados na PI', fmt(g.cancelados_pi.sp) + ' SP', int(g.cancelados_pi.itens) + ' itens', '#f97316'));
    sec.appendChild(grid);

    sec.appendChild(el('div', 'pr-sub', 'Total do escopo: ' + fmt(g.total_sp) + ' SP (backlog + em andamento + bloqueado + aceitos; cancelados ficam fora).'));

    // Modo PI: itens fora da janela.
    if (g.escopo === 'pi') {
      const fdj = g.fora_da_janela || {};
      const foraBits = [];
      if (fdj.sem_sprint_itens) foraBits.push(int(fdj.sem_sprint_itens) + ' item(ns) (' + fmt(fdj.sem_sprint_sp) + ' SP) ainda sem sprint');
      if (fdj.sprint_de_outra_pi_itens) foraBits.push(int(fdj.sprint_de_outra_pi_itens) + ' item(ns) (' + fmt(fdj.sprint_de_outra_pi_sp) + ' SP) em sprint de outra PI');
      if (fdj.sprints_futuras_sp) foraBits.push(fmt(fdj.sprints_futuras_sp) + ' SP em sprints futuras');
      if (foraBits.length) sec.appendChild(el('div', 'pr-sub', 'Fora da janela: ' + foraBits.join(' · ') + '.'));
    }
    return sec;
  }

  // ── 5. Sprint anterior ──────────────────────────────────────────────
  function cardSprintAnterior(d) {
    const g = d.gadgets.sprint_anterior;
    const sec = el('div');
    sec.appendChild(el('div', 'stitle', 'Dados da sprint anterior' + (g && g.sprint ? ' — ' + g.sprint : '')));
    if (!g || g.estado !== 'ok') {
      const a = el('div', 'alert alert-info'); a.setAttribute('role', 'status');
      a.appendChild(el('span', null, (g && g.motivo) || 'Nenhuma sprint anterior fechada nesta PI.'));
      sec.appendChild(a);
      return sec;
    }
    const grid = el('div', 'kpi-grid vg-kpi-grid');
    grid.appendChild(kpi('Alocados', fmt(g.alocados_sp) + ' SP', 'passaram pela sprint', '#6366f1'));
    grid.appendChild(kpi('Aceitos na sprint', fmt(g.aceitos_sp) + ' SP',
      'valor ' + fmt(g.aceitos_valor_sp) + ' · sustentação ' + fmt(g.aceitos_sustentacao_sp), '#10b981'));
    grid.appendChild(kpi('Não aceitos sem continuidade', fmt(g.nao_aceitos_sem_continuidade_sp) + ' SP', 'não seguiram para a próxima', '#f59e0b'));
    const tr = g.transbordo || {};
    if (tr.disponivel) {
      grid.appendChild(kpi('Transbordo', fmt(tr.sp) + ' SP',
        int(tr.itens) + ' itens → ' + (tr.para_sprint || '—'), '#a78bfa'));
    } else {
      const k = kpi('Transbordo', 'n/d', 'indisponível', '#a78bfa', tr.motivo || 'Transbordo indisponível para esta sprint.');
      grid.appendChild(k);
    }
    sec.appendChild(grid);
    sec.appendChild(el('div', 'pr-sub', 'Incidentes/Solicitações: aguardando o módulo de Sustentação.'));
    return sec;
  }

  // ── 6a. Burndown ─────────────────────────────────────────────────────
  function cardBurndown(d) {
    const g = d.gadgets.burndown;
    if (!g || g.estado !== 'ok') return cardVazio('Burndown', g);
    const card = cardAprox('Burndown', g.regra);
    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-burndown';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Burndown da sprint: linha ideal tracejada e linha real do escopo restante por dia. Valores na tabela abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const di = g.diagnosticos || {};
    if (di.sustentacao_fora_sp) {
      card.appendChild(el('div', 'pr-sub', 'Sustentação fora do escopo de valor: ' + fmt(di.sustentacao_fora_sp) + ' SP em ' + int(di.sustentacao_fora_itens) + ' itens.'));
    }

    const { wrap: tw, tb } = tabelaBase(null, 'Burndown: escopo ideal e real restante por dia', '460px');
    cabecalho(tb, [['Dia', ''], ['Ideal (SP)', 'num'], ['Real (SP)', 'num']]);
    const tbody = el('tbody');
    g.dias.forEach((dia, i) => {
      const tr = el('tr');
      tr.appendChild(el('td', null, dia === 'inicio' ? 'Início' : diaMes(dia)));
      tr.appendChild(el('td', 'num', fmt(g.ideal[i])));
      tr.appendChild(el('td', 'num', g.real[i] == null ? '—' : fmt(g.real[i])));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    card._draw = () => window.Charts.make('vg-burndown', {
      type: 'line',
      data: {
        labels: g.dias.map(x => x === 'inicio' ? 'Início' : diaMes(x)),
        datasets: [
          { label: 'Ideal', data: g.ideal, borderColor: '#7c8aaa', borderDash: [6, 4], borderWidth: 2, pointRadius: 0, tension: 0 },
          { label: 'Real', data: g.real, borderColor: '#22d3ee', backgroundColor: '#22d3ee', borderWidth: 2.5, pointRadius: 3, spanGaps: false, tension: 0 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6b. CFD ──────────────────────────────────────────────────────────
  function cardCfd(d) {
    const g = d.gadgets.cfd;
    if (!g || g.estado !== 'ok') return cardVazio('Fluxo acumulado (CFD)', g);
    const card = cardAprox('Fluxo acumulado (CFD)', g.regra);
    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-cfd';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Diagrama de fluxo acumulado: áreas empilhadas de Pendente, Em andamento e Concluído por dia. Valores na tabela abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const { wrap: tw, tb } = tabelaBase(null, 'Fluxo acumulado: itens pendentes, em andamento e concluídos por dia', '520px');
    cabecalho(tb, [['Dia', ''], ['Pendente (SP)', 'num'], ['Em andamento (SP)', 'num'], ['Concluído (SP)', 'num']]);
    const tbody = el('tbody');
    g.dias.forEach((dia, i) => {
      const tr = el('tr');
      tr.appendChild(el('td', null, diaMes(dia)));
      tr.appendChild(el('td', 'num', g.pendente[i] == null ? '—' : fmt(g.pendente[i])));
      tr.appendChild(el('td', 'num', g.em_andamento[i] == null ? '—' : fmt(g.em_andamento[i])));
      tr.appendChild(el('td', 'num', g.concluido[i] == null ? '—' : fmt(g.concluido[i])));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    card._draw = () => window.Charts.make('vg-cfd', {
      type: 'line',
      data: {
        labels: g.dias.map(diaMes),
        datasets: [
          { label: 'Pendente', data: g.pendente, borderColor: '#7c8aaa', backgroundColor: 'rgba(124,138,170,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
          { label: 'Em andamento', data: g.em_andamento, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
          { label: 'Concluído', data: g.concluido, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, stacked: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6c. Cycle time por status ──────────────────────────────────────────
  function cardCycle(d) {
    const g = d.gadgets.cycle_time_status;
    if (!g || g.estado !== 'ok') return cardVazio('Cycle time por status', g);
    const isPi = (d.filtros || {}).modo_efetivo === 'pi';
    const card = cardBase('Cycle time por status', 'dias úteis');
    card.appendChild(el('div', 'pr-sub', 'Janela: ' + (g.sprints || []).join(', ') + ' · ' + int(g.itens) + ' itens de valor entregues.'));

    const grid = el('div', 'kpi-grid vg-kpi-grid');
    const et = (label, e) => kpi(label, e.dias == null ? '—' : fmt(e.dias) + ' d', int(e.n) + ' itens', '#6366f1');
    grid.appendChild(et('Desenvolvimento', g.desenvolvimento));
    grid.appendChild(et('Homologação', g.homologacao));
    grid.appendChild(et('Implantação', g.implantacao));
    grid.appendChild(kpi('Lead time médio', g.lead_time_medio == null ? '—' : fmt(g.lead_time_medio) + ' d', int(g.itens) + ' itens', '#22d3ee'));
    card.appendChild(grid);

    const jp = g.janela_padrao;
    if (isPi && jp) {
      card.appendChild(el('div', 'pr-sub', 'Sprints fechadas (igual à tela Lead Time): ' + (jp.sprints || []).join(', ')
        + ' · ' + int(jp.itens) + ' itens · lead time ' + (jp.lead_time_medio == null ? '—' : fmt(jp.lead_time_medio) + ' d')
        + ' (dev ' + (jp.desenvolvimento.dias == null ? '—' : fmt(jp.desenvolvimento.dias)) + ' · hml '
        + (jp.homologacao.dias == null ? '—' : fmt(jp.homologacao.dias)) + ' · impl '
        + (jp.implantacao.dias == null ? '—' : fmt(jp.implantacao.dias)) + ').'));
    }
    return card;
  }

  // ── 6e. Team Velocity (mini) ──────────────────────────────────────────
  function cardTeamVelocity(d) {
    const g = d.gadgets.team_velocity;
    if (!g || g.estado !== 'ok' || !(g.serie || []).length) return cardVazio('Team Velocity', g);
    const card = cardBase('Team Velocity', 'SP concluídos por sprint');
    const r = g.resumo || {};
    const dest = el('div');
    dest.style.cssText = 'display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:6px';
    const big = el('div', null, r.velocidade_ultimas_n == null ? '— SP' : fmt(r.velocidade_ultimas_n) + ' SP');
    big.style.cssText = 'color:#d62728;font-size:1.6rem;font-weight:700';
    const n = r.n_sprints_na_media || 0;
    dest.append(big, el('div', 'kpi-sub', n > 0 ? 'velocidade das últimas ' + n + ' fechadas' : 'sem sprint fechada'));
    card.appendChild(dest);

    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-velmini';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras do Concluído por sprint (a sprint em andamento hachurada). Valores na tabela abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);
    const linkP = el('div', 'pr-sub');
    const a = document.createElement('a');
    a.href = BASE + '/metricas/velocidade';
    a.textContent = 'Ver a tela de Velocidade completa';
    a.style.color = '#a5b4fc';
    linkP.appendChild(a);
    card.appendChild(linkP);

    const { wrap: tw, tb } = tabelaBase(null, 'Concluído por sprint', '340px');
    cabecalho(tb, [['Sprint', ''], ['Status', ''], ['Concluído (SP)', 'num']]);
    const tbody = el('tbody');
    g.serie.forEach(s => {
      const tr = el('tr');
      tr.appendChild(el('td', null, s.sprint));
      tr.appendChild(el('td', null, s.status === 'em_andamento' ? 'Ativa' : 'Fechada'));
      tr.appendChild(el('td', 'num', fmt(s.concluido)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    card._draw = () => window.Charts.make('vg-velmini', {
      type: 'bar',
      data: {
        labels: g.serie.map(s => s.sprint),
        datasets: [{
          label: 'Concluído', data: g.serie.map(s => s.concluido),
          backgroundColor: g.serie.map(s => s.status === 'em_andamento' ? 'rgba(44,160,44,.45)' : '#2ca02c'),
          borderColor: '#2ca02c', borderWidth: g.serie.map(s => s.status === 'em_andamento' ? 1.5 : 0),
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' Concluído: ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6f. Chamados ─────────────────────────────────────────────────────
  function cardChamados(d) {
    const g = d.gadgets.chamados;
    if (!g || g.estado !== 'ok') return cardVazio('Chamados', g);
    const card = cardAprox('Chamados', g.regra);
    card.appendChild(el('div', 'pr-sub', 'Últimos ' + int(g.janela_dias) + ' dias · independe do seletor · limite de ' + int(g.limite_dias) + ' dia(s) úteis.'));

    const { wrap: tw, tb } = tabelaBase(null, 'Chamados por tipo: quantidade, média e mediana de dias, percentual dentro do limite', '540px');
    cabecalho(tb, [['Tipo', ''], ['Qtd', 'num'], ['Média (dias)', 'num'], ['Mediana (dias)', 'num'], ['% no limite', 'num'], ['Média desde criação', 'num']]);
    const tbody = el('tbody');
    (g.por_tipo || []).forEach(t => {
      const tr = el('tr');
      tr.appendChild(el('td', null, t.tipo));
      tr.appendChild(el('td', 'num', int(t.n)));
      tr.appendChild(el('td', 'num', fmt(t.media_dias)));
      tr.appendChild(el('td', 'num', fmt(t.mediana_dias)));
      tr.appendChild(el('td', 'num', pctF(t.pct_dentro_limite)));
      tr.appendChild(el('td', 'num', fmt(t.media_dias_desde_criacao)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    const c = g.cobertura || {};
    card.appendChild(el('div', 'pr-sub', 'Cobertura: ' + int(c.resolvidos_pi) + ' resolvidos na PI · ' + int(c.com_fim_fluxo)
      + ' com data de fim · ' + int(c.na_janela) + ' na janela · ' + pctF(c.pct_com_data) + ' com data.'));
    return card;
  }

  // ── 7. Tempo por status ────────────────────────────────────────────────
  function cardTempoStatus(d) {
    const g = d.gadgets.tempo_status;
    if (!g || g.estado !== 'ok') return cardVazio('Tempo do item em cada status', g, true);
    const isPi = (d.filtros || {}).modo_efetivo === 'pi';
    const card = cardAprox('Tempo do item em cada status', g.regra, true);
    const fases = g.fases || [];

    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-tempo';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras horizontais da média de dias úteis por fase (' + fases.join(', ') + '). Valores na tabela de médias abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    if (isPi) card.appendChild(el('div', 'pr-sub', 'No modo PI a lista mostra só itens em andamento; as médias consideram todos os itens da janela.'));

    // Tabela de médias
    const { wrap: tmw, tb: tmb } = tabelaBase('Médias por fase', 'Média de dias úteis por fase', '420px');
    cabecalho(tmb, [['Fase', ''], ['Média (dias)', 'num'], ['Itens', 'num']]);
    const tmbody = el('tbody');
    (g.medias || []).forEach(m => {
      const tr = el('tr');
      tr.appendChild(el('td', null, m.fase));
      tr.appendChild(el('td', 'num', fmt(m.dias)));
      tr.appendChild(el('td', 'num', int(m.n)));
      tmbody.appendChild(tr);
    });
    tmb.appendChild(tmbody);
    card.appendChild(tmw);

    // Tabela dos itens
    const { wrap: tiw, tb: tib } = tabelaBase('Itens', 'Dias por fase de cada item', '760px');
    const cols = [['Chave', ''], ['Tipo', ''], ['Status', ''], ['SP', 'num'], ['Fase atual', '']];
    fases.forEach(f => cols.push([f, 'num']));
    cols.push(['Total (dias)', 'num']);
    cabecalho(tib, cols);
    const tibody = el('tbody');
    (g.itens || []).forEach(it => {
      const tr = el('tr');
      tr.appendChild(el('td', null, it.chave));
      tr.appendChild(el('td', null, it.tipo));
      tr.appendChild(el('td', null, it.status));
      tr.appendChild(el('td', 'num', fmt(it.sp)));
      tr.appendChild(el('td', null, it.fase_atual));
      fases.forEach(f => tr.appendChild(el('td', 'num', it.dias && it.dias[f] != null ? fmt(it.dias[f]) : '—')));
      tr.appendChild(el('td', 'num', fmt(it.total_dias)));
      tibody.appendChild(tr);
    });
    tib.appendChild(tibody);
    card.appendChild(tiw);
    if (g.truncado) card.appendChild(el('div', 'pr-sub', 'Lista truncada: exibindo os primeiros itens.'));

    const di = g.diagnosticos || {};
    if (di.sustentacao_fora_sp) card.appendChild(el('div', 'pr-sub', 'Sustentação fora do escopo de valor: ' + fmt(di.sustentacao_fora_sp) + ' SP em ' + int(di.sustentacao_fora_itens) + ' itens.'));

    card._draw = () => window.Charts.make('vg-tempo', {
      type: 'bar',
      data: {
        labels: fases,
        datasets: [{
          label: 'Média de dias úteis', data: (g.medias || []).map(m => m.dias),
          backgroundColor: '#6366f1', borderRadius: 4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + fmt(c.raw) + ' dias' } } },
        scales: {
          x: { beginAtZero: true, title: { display: true, text: 'Dias úteis' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
          y: { grid: { display: false } },
        },
      },
    });
    return card;
  }

  // ── 8. Lista de itens da sprint anterior ─────────────────────────────
  function cardLista(d) {
    const g = d.gadgets.lista_sprint_anterior;
    if (!g || g.estado !== 'ok') return cardVazio('Itens da sprint anterior', g, true);
    const card = cardBase('Itens da sprint anterior' + (g.sprint ? ' — ' + g.sprint : ''), null, true);

    const { wrap: tw, tb } = tabelaBase(null, 'Itens da sprint anterior: tipo, chave, épico, resumo, status, sprint, SP e transbordo', '920px');
    cabecalho(tb, [['Tipo', ''], ['Chave', ''], ['Épico (pai)', ''], ['Resumo', ''], ['Status', ''], ['Sprint (maior)', ''], ['SP', 'num'], ['Transbordo', '']]);
    const tbody = el('tbody');
    (g.itens || []).forEach(it => {
      const tr = el('tr');
      tr.appendChild(el('td', null, it.tipo));
      tr.appendChild(el('td', null, it.chave));
      tr.appendChild(el('td', null, it.pai || '—'));
      tr.appendChild(el('td', null, it.resumo || '—'));
      tr.appendChild(el('td', null, it.status));
      const tdS = el('td', null, it.sprint_maior || '—');
      if (Array.isArray(it.sprints) && it.sprints.length) tdS.title = it.sprints.join(', ');
      tr.appendChild(tdS);
      tr.appendChild(el('td', 'num', fmt(it.sp)));
      tr.appendChild(el('td', null, it.transbordo ? 'Sim' : 'Não'));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    const notas = ['Σ ' + fmt(g.total_sp) + ' SP em ' + int(g.total_itens) + ' itens.'];
    if (g.resumo_disponivel_pct != null) notas.push('Resumo disponível em ' + pctF(g.resumo_disponivel_pct) + ' dos itens.');
    if (g.truncado) notas.push('Lista truncada ao limite de linhas.');
    card.appendChild(el('div', 'pr-sub', notas.join(' ')));
    return card;
  }

  // ── 9. Placeholder de Sustentação ────────────────────────────────────
  function cardSustentacao(d) {
    const g = d.gadgets.sustentacao || {};
    const card = el('div', 'ccard vg-wide vg-placeholder');
    card.appendChild(el('h3', null, 'Sustentação — aguardando módulo independente'));
    card.appendChild(el('div', 'pr-sub', 'Os gadgets abaixo dependem do módulo de Sustentação (regras ainda não definidas). A divisão por 5 do Jira corresponde à Produtividade (horas por ponto) da jornada/time.'));
    const ul = el('ul');
    ul.style.cssText = 'margin:8px 0 0;padding-left:18px;font-size:.78rem;color:var(--muted)';
    (g.gadgets || []).forEach(t => ul.appendChild(el('li', null, t)));
    card.appendChild(ul);
    return card;
  }

  // ── 10. Rodapé "Como ler esta tela" ──────────────────────────────────
  function rodape() {
    const box = el('div', 'alert alert-info');
    box.style.marginTop = '18px';
    box.setAttribute('role', 'note');
    box.appendChild(el('strong', null, 'Como ler esta tela'));
    const ul = el('ul'); ul.style.cssText = 'margin:6px 0 0;padding-left:18px;font-size:.78rem';
    [
      'Visão por jornada, consolidando os projetos dos times da seleção. Os filtros do cabeçalho (jornada, time, versão, sprint) controlam a tela; ADSE e SGSE são o mesmo time.',
      'Seletor "Sprint ativa | PI inteira": mostra a sprint em foco ou a PI inteira (sprints de valor já iniciadas). Um filtro de sprint no cabeçalho vence o seletor.',
      'Unidade: Story Points, universo de valor + sustentação (como no Planejado x Realizado). Aceitos = Entregues do Planejado x Realizado = Concluído da Velocidade.',
      'Cycle time bate com a tela Lead Time (bloco "sprints fechadas"). O histórico de Produtividade por sprint fica na tela Produtividade; aqui só aparece a da sprint ativa.',
      'Burndown, Fluxo acumulado (CFD), Tempo por status e Chamados são aproximados por datas de fluxo (não há burndown nativo de sprint); a badge "aproximado" sinaliza isso.',
      'Os gadgets de Sustentação aguardam o módulo independente; o divisor ÷5 do Jira equivale à Produtividade (horas por ponto).',
    ].forEach(t => ul.appendChild(el('li', null, t)));
    box.appendChild(ul);
    return box;
  }

  // ── Render ─────────────────────────────────────────────────────────────
  function render(d) {
    root.textContent = '';
    root.appendChild(el('div', 'stitle', 'Visão Geral'));
    root.appendChild(faixaContexto(d));
    root.appendChild(seletor(d));
    const av = avisos(d);
    if (av) root.appendChild(av);

    // 4 e 5: andamento e sprint anterior
    root.appendChild(cardAndamento(d));
    root.appendChild(cardSprintAnterior(d));

    // 6: grade de gráficos (coleta os _draw para desenhar após inserir no DOM)
    const aDesenhar = [];
    // Grade fluida: cards com dados em pares; o último sem par e o Cycle time
    // ocupam a linha toda; cards vazios/indisponíveis vão ao fim, em linha inteira.
    const grade = el('div', 'cgrid vg-grid');
    const cycle = cardCycle(d);
    const pares = [cardBurndown(d), cardCfd(d), cardTeamVelocity(d), cardChamados(d)];
    const comDados = pares.filter(c => !c._vazio);
    const vazios = pares.filter(c => c._vazio);
    if (cycle._vazio) vazios.unshift(cycle);
    if (comDados.length % 2 === 1) comDados[comDados.length - 1].classList.add('vg-span');
    const ordem = comDados.concat(cycle._vazio ? [] : [cycle], vazios);
    if (!cycle._vazio) cycle.classList.add('vg-span');
    vazios.forEach(c => c.classList.add('vg-span'));
    ordem.forEach(c => {
      grade.appendChild(c);
      if (c._draw) aDesenhar.push(c);
    });
    root.appendChild(grade);

    // 7: tempo por status (largo)
    const tempo = cardTempoStatus(d);
    tempo.style.marginTop = '18px';
    root.appendChild(tempo);
    if (tempo._draw) aDesenhar.push(tempo);

    // 8: lista (largo)
    const lista = cardLista(d);
    lista.style.marginTop = '18px';
    root.appendChild(lista);

    // 9: placeholder de sustentação
    const sust = cardSustentacao(d);
    sust.style.marginTop = '18px';
    root.appendChild(sust);

    // 10: rodapé
    root.appendChild(rodape());

    // Desenha os gráficos depois de tudo no DOM (canvas precisa de tamanho).
    aDesenhar.forEach(c => { try { c._draw(); } catch (e) { console.warn('Falha ao desenhar gráfico da Visão Geral:', e.message); } });
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir a Visão Geral.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
