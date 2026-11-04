<?php
/**
 * vw_velocity.php — Velocidade (widget "Team Velocity" do Jira)
 * ─────────────────────────────────────────────────────────────
 * Barras agrupadas por sprint (Escopo Inicial, Escopo Final, Concluído, Mudança de
 * Escopo, Adicionado, Removido) + linha vermelha "Velocidade Média" (média móvel do
 * Concluído nas últimas 4 sprints fechadas). Dados: GET /graficos/velocidade
 * (mesmos itens e regras do Planejado x Realizado). Os filtros do header
 * (jornada/time/versao/sprint) controlam a página.
 *
 * Todo texto vindo do servidor entra no DOM via textContent (sem innerHTML).
 */
?>
<div id="vel-root">
  <div class="loading"><div class="spinner"></div> Carregando velocidade...</div>
</div>

<script>
(function () {
  const root = document.getElementById('vel-root');
  const BASE = window.APP_BASE || '';

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const fmt = v => (v == null ? '—' : nf.format(v));
  const fmtD = v => (v == null ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + nf.format(Math.abs(v)));
  const pct = (a, t) => (t > 0 ? nf1.format(a / t * 100) + '%' : '—');

  // Séries do widget. 'ord' = posição na legenda/tooltip; os datasets do Chart.js
  // seguem a ordem de desenho (barras e depois a linha).
  const SERIES = [
    { k: 'escopo_inicial',  nome: 'Escopo Inicial',     cor: '#1f77b4' },
    { k: 'escopo_final',    nome: 'Escopo Final',       cor: '#ff7f0e' },
    { k: 'concluido',       nome: 'Concluído',          cor: '#2ca02c' },
    { k: 'velocidade_media', nome: 'Velocidade Média',  cor: '#d62728', linha: true },
    { k: 'mudanca_escopo',  nome: 'Mudança de Escopo',  cor: '#9467bd' },
    { k: 'adicionado',      nome: 'Adicionado',         cor: '#e377c2' },
    { k: 'removido',        nome: 'Removido',           cor: '#7f7f7f' },
  ];

  function mensagem(tipo, titulo, texto) {
    root.textContent = '';
    const a = el('div', 'alert alert-' + tipo);
    a.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    a.appendChild(el('strong', null, titulo));
    if (texto) { a.appendChild(document.createElement('br')); a.appendChild(el('span', null, texto)); }
    root.appendChild(a);
  }

  async function buscar() {
    const f = window.App.Filters.read();
    const qs = new URLSearchParams();
    ['jornada', 'time', 'versao', 'sprint'].forEach(k => { if (f[k]) qs.set(k, f[k]); });
    let res;
    try {
      res = await fetch(BASE + '/graficos/velocidade' + (qs.toString() ? '?' + qs : ''), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular a velocidade agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
    }
    if (!json || json.success !== true) throw new Error('Resposta inesperada do servidor.');
    return json;
  }

  function faixaJanela(d) {
    const j = d.janela || {};
    const box = el('div', 'alert alert-info prev-janela');
    box.setAttribute('role', 'status');
    box.appendChild(el('strong', null, 'Sprints exibidas: '));
    const sp = j.sprints_exibidas || [];
    if (!sp.length) {
      box.appendChild(document.createTextNode('nenhuma'));
    } else {
      box.appendChild(document.createTextNode(sp.map(s => s.nome + (s.status === 'em_andamento' ? ' (em andamento)' : '')).join(', ')));
      if (j.ate_sprint) box.appendChild(document.createTextNode(' — até a sprint escolhida no filtro (' + j.ate_sprint + ')'));
    }
    const fa = d.filtros || {};
    const partes = [];
    if (j.pi) partes.push(j.pi + (fa.versao_padrao ? ' (PI corrente)' : ''));
    if (fa.jornada) partes.push('Jornada: ' + fa.jornada);
    if (fa.time) partes.push('Time: ' + fa.time);
    if (partes.length) box.appendChild(el('div', 'kpi-sub', partes.join(' · ')));
    return box;
  }

  // Padrão hachurado para a sprint em andamento
  function hachura(cor) {
    const c = document.createElement('canvas');
    c.width = c.height = 8;
    const x = c.getContext('2d');
    x.fillStyle = cor + '55'; x.fillRect(0, 0, 8, 8);
    x.strokeStyle = cor; x.lineWidth = 2;
    x.beginPath(); x.moveTo(-2, 10); x.lineTo(10, -2); x.stroke();
    return x.createPattern(c, 'repeat');
  }

  function cartaoVelocity(d) {
    const serie = d.serie;
    const card = el('div', 'ccard');

    // Cabeçalho: título à esquerda, destaque vermelho à direita
    const head = el('div');
    head.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:8px';
    const h = el('h3', null, 'Team Velocity ');
    h.style.margin = '0';
    h.appendChild(el('span', 'badge', 'Story Points por sprint'));
    head.appendChild(h);

    const r = d.resumo || {};
    const dest = el('div');
    dest.style.textAlign = 'right';
    const big = el('div', null, r.velocidade_ultimas_n == null ? '— SPs' : fmt(r.velocidade_ultimas_n) + ' SPs');
    big.style.cssText = 'color:#d62728;font-size:2rem;font-weight:700;line-height:1.1';
    big.id = 'vel-destaque';
    const n = r.n_sprints_na_media || 0;
    const sub = el('div', 'kpi-sub', n > 0
      ? 'Velocidade nos últimos ' + n + ' sprint' + (n > 1 ? 's' : '')
      : 'Sem sprint fechada para calcular a velocidade');
    dest.append(big, sub);
    if (n > 0) dest.appendChild(el('div', 'pr-sub', 'Σ concluído ' + fmt(r.soma_concluido_na_media) + ' em ' + n + ' sprint' + (n > 1 ? 's' : '') + ' fechada' + (n > 1 ? 's' : '')));
    head.appendChild(dest);
    card.appendChild(head);

    const wrap = el('div', 'cwrap xtall');
    const cv = document.createElement('canvas');
    cv.id = 'vel-chart';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras agrupadas por sprint com Escopo Inicial, Escopo Final, Concluído, Mudança de Escopo, Adicionado e Removido, '
      + 'e linha de Velocidade Média. Os valores estão na tabela Pontos por sprint.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    // Legenda na ordem do widget (cor + texto)
    const leg = el('div', 'prev-legenda');
    leg.style.justifyContent = 'center';
    SERIES.forEach(s => {
      const sp = el('span'); const i = el('i');
      i.style.background = s.cor;
      if (s.linha) { i.style.height = '3px'; i.style.verticalAlign = 'middle'; }
      sp.append(i, s.nome); leg.appendChild(sp);
    });
    card.appendChild(leg);

    const emAnd = serie.some(s => s.status === 'em_andamento');
    if (emAnd) {
      const nota = el('div', 'pr-sub', 'Barras hachuradas = sprint em andamento (Concluído parcial). Ela não entra na Velocidade Média nem no destaque; a linha tracejada repete o último valor fechado.');
      nota.style.cssText = 'text-align:center;margin-top:6px';
      card.appendChild(nota);
    }

    const labels = serie.map(s => s.sprint + (s.status === 'em_andamento' ? ' (em andamento)' : ''));
    const emA = i => serie[i].status === 'em_andamento';
    const barras = SERIES.filter(s => !s.linha).map(s => ({
      type: 'bar', label: s.nome, _k: s.k,
      data: serie.map(x => x[s.k]),
      backgroundColor: c => (emA(c.dataIndex) ? hachura(s.cor) : s.cor),
      borderColor: s.cor, borderWidth: c => (emA(c.dataIndex) ? 1.5 : 0),
      borderDash: c => (emA(c.dataIndex) ? [4, 3] : []),
      order: 2,
    }));
    const ln = SERIES.find(s => s.linha);
    const linha = {
      type: 'line', label: ln.nome, _k: ln.k, data: serie.map(x => x[ln.k]),
      borderColor: ln.cor, backgroundColor: ln.cor, borderWidth: 2.5, pointRadius: 5, pointHoverRadius: 7,
      pointStyle: 'circle', tension: 0, spanGaps: true, order: 1,
      segment: { borderDash: c => (emA(c.p1DataIndex) ? [6, 4] : undefined) },
    };
    const ordemTip = SERIES.map(s => s.nome);

    card._draw = () => window.Charts.make('vel-chart', {
      data: { labels, datasets: [...barras, linha] },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            itemSort: (a, b) => ordemTip.indexOf(a.dataset.label) - ordemTip.indexOf(b.dataset.label),
            filter: it => it.raw != null,
            titleColor: '#e2e8f0',
            callbacks: {
              title: items => (items.length ? items[0].label : ''),
              label: ctx => ' ' + ctx.dataset.label + ': ' + fmt(ctx.raw),
            },
          },
        },
        scales: {
          x: { title: { display: true, text: 'Sprints' }, grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] }, ticks: { precision: 0 } },
        },
      },
    });
    return card;
  }

  function tabelaBase(titulo, legenda) {
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    card.appendChild(el('h3', null, titulo));
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl');
    const cap = el('caption', null, legenda);
    cap.style.cssText = 'position:absolute;left:-9999px';
    tb.appendChild(cap);
    w.appendChild(tb); card.appendChild(w);
    return { card, tb };
  }
  function cabecalho(tb, cols) {
    const thead = el('thead'); const trh = el('tr');
    cols.forEach(([t, c]) => { const th = el('th', c, t); th.scope = 'col'; trh.appendChild(th); });
    thead.appendChild(trh); tb.appendChild(thead);
  }

  function tabelaSprints(d) {
    const { card, tb } = tabelaBase('Pontos por sprint',
      'Pontos por sprint: escopo inicial, adicionado, removido, mudança de escopo, escopo final, concluído, percentual concluído e velocidade média');
    tb.style.minWidth = '860px';
    cabecalho(tb, [['Sprint', ''], ['Status', ''], ['Escopo Inicial', 'num'], ['Adicionado', 'num'], ['Removido', 'num'],
                   ['Mudança', 'num'], ['Escopo Final', 'num'], ['Concluído', 'num'], ['% concluído do Escopo Final', 'num'], ['Velocidade Média', 'num']]);
    const tbody = el('tbody');
    const tot = { escopo_inicial: 0, adicionado: 0, removido: 0, mudanca_escopo: 0, escopo_final: 0, concluido: 0 };
    d.serie.forEach(s => {
      const tr = el('tr');
      const andamento = s.status === 'em_andamento';
      tr.appendChild(el('td', null, s.sprint));
      tr.appendChild(el('td', null, andamento ? 'Em andamento' : 'Fechada'));
      tr.appendChild(el('td', 'num', fmt(s.escopo_inicial)));
      tr.appendChild(el('td', 'num', fmt(s.adicionado)));
      tr.appendChild(el('td', 'num', fmt(s.removido)));
      tr.appendChild(el('td', 'num', fmtD(s.mudanca_escopo)));
      tr.appendChild(el('td', 'num', fmt(s.escopo_final)));
      tr.appendChild(el('td', 'num', fmt(s.concluido)));
      tr.appendChild(el('td', 'num', pct(s.concluido, s.escopo_final)));
      tr.appendChild(el('td', 'num', fmt(s.velocidade_media)));
      if (andamento) tr.style.fontStyle = 'italic';
      tbody.appendChild(tr);
      if (!andamento) Object.keys(tot).forEach(k => { tot[k] += s[k]; });
    });
    tb.appendChild(tbody);
    const tf = el('tfoot'); const trf = el('tr');
    trf.appendChild(el('td', null, 'Total das fechadas'));
    trf.appendChild(el('td', null, ''));
    trf.appendChild(el('td', 'num', fmt(tot.escopo_inicial)));
    trf.appendChild(el('td', 'num', fmt(tot.adicionado)));
    trf.appendChild(el('td', 'num', fmt(tot.removido)));
    trf.appendChild(el('td', 'num', fmtD(tot.mudanca_escopo)));
    trf.appendChild(el('td', 'num', fmt(tot.escopo_final)));
    trf.appendChild(el('td', 'num', fmt(tot.concluido)));
    trf.appendChild(el('td', 'num', pct(tot.concluido, tot.escopo_final)));
    trf.appendChild(el('td', 'num', fmt((d.resumo || {}).velocidade_ultimas_n)));
    tf.appendChild(trf); tb.appendChild(tf);
    return card;
  }

  function graficoTimes(d) {
    const pt = d.por_time || [];
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    const h = el('h3', null, 'Por time ');
    h.appendChild(el('span', 'badge', 'Story Points Estimate x Story Points entregues'));
    card.appendChild(h);
    const somadas = ((d.diagnosticos || {}).sprints_somadas_por_time) || [];
    card.appendChild(el('div', 'pr-sub', 'Soma das sprints ' + (d.janela.ate_sprint ? 'da sprint escolhida' : 'fechadas') + ': ' + (somadas.join(', ') || 'nenhuma')));
    const wrap = el('div', 'cwrap tall');
    const cv = document.createElement('canvas');
    cv.id = 'vel-times';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras agrupadas por time: Story Points Estimate e Story Points entregues. Valores na tabela abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);
    const leg = el('div', 'prev-legenda');
    leg.style.justifyContent = 'center';
    [['Story Points Estimate', '#1f77b4'], ['Story Points entregues', '#2ca02c']].forEach(([n, c]) => {
      const sp = el('span'); const i = el('i'); i.style.background = c; sp.append(i, n); leg.appendChild(sp);
    });
    card.appendChild(leg);
    card._draw = () => window.Charts.make('vel-times', {
      type: 'bar',
      data: {
        labels: pt.map(t => t.time),
        datasets: [
          { label: 'Story Points Estimate', data: pt.map(t => t.escopo_inicial), backgroundColor: '#1f77b4' },
          { label: 'Story Points entregues', data: pt.map(t => t.concluido), backgroundColor: '#2ca02c' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 0, autoSkip: false } },
          y: { title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] }, ticks: { precision: 0 } },
        },
      },
    });
    return card;
  }

  function tabelaTimes(d) {
    const { card, tb } = tabelaBase('Pontos por time', 'Story Points Estimate, escopo final e Story Points entregues por time, com a diferença');
    tb.style.minWidth = '720px';
    cabecalho(tb, [['Time', ''], ['Jornada', ''], ['Story Points Estimate', 'num'], ['Escopo Final', 'num'], ['Story Points entregues', 'num'], ['Δ entregues − Estimate', 'num']]);
    const linha = (tr, x) => {
      tr.appendChild(el('td', 'num', fmt(x.escopo_inicial)));
      tr.appendChild(el('td', 'num', fmt(x.escopo_final)));
      tr.appendChild(el('td', 'num', fmt(x.concluido)));
      tr.appendChild(el('td', 'num', fmtD(Math.round((x.concluido - x.escopo_inicial) * 100) / 100)));
    };
    const tbody = el('tbody');
    (d.por_time || []).forEach(t => {
      const tr = el('tr');
      const tdT = el('td', null, t.time);
      if (!t.tem_baseline) tdT.title = 'Sem baseline importada para este time';
      tr.append(tdT, el('td', null, t.jornada));
      linha(tr, t);
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    const tf = el('tfoot'); const trf = el('tr');
    trf.append(el('td', null, 'Total da seleção'), el('td', null, ''));
    linha(trf, d.total);
    tf.appendChild(trf); tb.appendChild(tf);
    return card;
  }

  function rodape(d) {
    const box = el('div', 'alert alert-info');
    box.style.marginTop = '18px';
    box.setAttribute('role', 'note');
    box.appendChild(el('strong', null, 'Como ler esta tela'));
    const ul = el('ul'); ul.style.cssText = 'margin:6px 0 0;padding-left:18px;font-size:.78rem';
    [
      'Unidade: Story Points, nas mesmas categorias do Planejado x Realizado (sustentação e entrega de valor; tipos como Incidente e Bug ficam fora).',
      'Escopo Inicial = Story Point Estimate do PI Planning (baseline) das sprints; Escopo Final = estimativa atual dos itens na sprint (Sprint Planning, sem cancelados); Concluído = Story Points entregues (Aceito, Pendente de Produção, Em produção).',
      'Identidade: Escopo Inicial + Adicionado − Removido + Mudança de Escopo = Escopo Final. Adicionado = itens que entraram na sprint depois da baseline; Removido = itens da baseline que saíram (transbordo, backlog, cancelado); Mudança = reestimativa dos itens que ficaram.',
      'Velocidade Média = média móvel do Concluído nas até 4 últimas sprints fechadas (no início da PI usa as sprints disponíveis). O destaque em vermelho é a média das últimas sprints fechadas exibidas.',
      'Sprint em andamento: aparece hachurada com Concluído parcial, não entra na média nem no destaque, e a linha tracejada repete o último valor fechado.',
      'Gráfico e tabela por time somam as sprints fechadas (ou a sprint escolhida no filtro) e batem com PI Planning e Entregues do Planejado x Realizado.',
      'Se uma sprint é escolhida no filtro, o gráfico termina nela e a média considera as sprints até ela.',
    ].forEach(t => ul.appendChild(el('li', null, t)));
    box.appendChild(ul);
    return box;
  }

  function render(d) {
    root.textContent = '';
    root.appendChild(faixaJanela(d));
    if (d.avisos && d.avisos.length) {
      const ul = el('ul', 'prev-avisos');
      d.avisos.forEach(a => ul.appendChild(el('li', null, a)));
      root.appendChild(ul);
    }
    if (!(d.serie || []).length || !(d.por_time || []).length) {
      const a = el('div', 'alert alert-info');
      a.setAttribute('role', 'status');
      a.appendChild(el('strong', null, 'Sem dados para a seleção.'));
      a.appendChild(document.createElement('br'));
      a.appendChild(el('span', null, 'Não há sprints iniciadas ou extração do Jira para os filtros escolhidos. Ajuste jornada, time, versão ou sprint no cabeçalho.'));
      root.appendChild(a);
      return;
    }
    root.appendChild(el('div', 'stitle', 'Velocidade'));
    const g = cartaoVelocity(d);
    root.appendChild(g);
    g._draw();
    root.appendChild(tabelaSprints(d));
    const gt = graficoTimes(d);
    root.appendChild(gt);
    gt._draw();
    root.appendChild(tabelaTimes(d));
    root.appendChild(rodape(d));
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir a velocidade.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
