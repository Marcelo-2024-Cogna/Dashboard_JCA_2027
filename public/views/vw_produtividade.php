<?php
/**
 * vw_produtividade.php — Produtividade (horas para entregar 1 ponto)
 * ────────────────────────────────────────────────────────────────────
 * (1) barra única da JORNADA (média PONDERADA = Σ horas ÷ Σ pontos) na PI de referência;
 * (2) colunas agrupadas por time, uma série por PI;
 * (3) tabela time x PI + referência de planejamento + sugestão p/ a próxima PI,
 *     com a linha final JORNADA.
 * Dados: GET /graficos/produtividade (lê o banco). Os filtros do header
 * (jornada/time/versao/sprint) controlam a página. PIs fechadas vêm de
 * produtividade_historico; a PI de referência corrente é calculada AO VIVO na janela
 * de sprints fechadas (ou só na sprint escolhida no filtro).
 *
 * Todo texto vindo do servidor entra no DOM via textContent (sem innerHTML).
 */
?>
<div id="pd-root">
  <div class="loading"><div class="spinner"></div> Carregando produtividade...</div>
</div>

<script>
(function () {
  const root = document.getElementById('pd-root');
  const BASE = window.APP_BASE || '';

  // ── helpers de DOM (sempre textContent) ───────────────────────────────
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf2 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
  const f2 = v => (v == null ? '—' : nf2.format(v));
  const sinal = v => (v > 0 ? '+' : v < 0 ? '−' : '') + nf2.format(Math.abs(v));

  const COR_JORNADA = '#9333ea';                       // roxo (destaque da jornada)
  const COR_PIS = ['#cbd5e1', '#fbbf24', '#8f9bff', '#22d3ee'];   // PI 1 cinza · 2 âmbar · 3 lilás · 4 (corrente) ciano
  const corPi = i => COR_PIS[i % COR_PIS.length];

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
      res = await fetch(BASE + '/graficos/produtividade' + (qs.toString() ? '?' + qs : ''), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular a produtividade agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
    }
    if (!json || json.success !== true) throw new Error('Resposta inesperada do servidor.');
    return json;
  }

  // PI de referência = a marcada pelo servidor (senão, a última coluna)
  const piRef = d => (d.pis || []).find(p => p.referencia) || (d.pis || [])[d.pis.length - 1] || null;
  const rotuloPi = p => p.desc_pi + (p.status === 'corrente' ? ' (em andamento)' : '');

  function faixaJanela(d) {
    const j = d.janela || {};
    const box = el('div', 'alert alert-info prev-janela');
    box.setAttribute('role', 'status');
    box.appendChild(el('strong', null, 'Sprints consideradas (PI de referência): '));
    if (!j.sprints || !j.sprints.length) {
      box.appendChild(document.createTextNode('nenhuma'));
    } else {
      const det = {}; (j.detalhe || []).forEach(s => { det[s.nome] = s; });
      box.appendChild(document.createTextNode(j.sprints.join(', ')));
      const abertas = j.sprints.filter(n => det[n] && !det[n].fechada);
      const rotulo = j.origem === 'filtro'
        ? (abertas.length ? ' — escolhida no filtro (sprint em andamento ou futura)' : ' — escolhida no filtro (fechada)')
        : ' — fechadas';
      box.appendChild(document.createTextNode(rotulo));
    }
    const fa = d.filtros || {};
    const r = piRef(d);
    if (r && r.origem === 'historico') {
      box.appendChild(el('div', 'kpi-sub', 'A ' + r.desc_pi + ' é uma PI fechada: os valores vêm do histórico consolidado e a janela de sprints não se aplica (use o filtro de sprint para recalcular ao vivo).'));
    }
    const partes = [];
    if (j.pi) partes.push(j.pi + (fa.versao_padrao ? ' (PI corrente)' : ''));
    if (fa.jornada) partes.push('Jornada: ' + fa.jornada);
    if (fa.time) partes.push('Time: ' + fa.time);
    if (partes.length) box.appendChild(el('div', 'kpi-sub', partes.join(' · ')));
    return box;
  }

  function kpis(d) {
    const ref = piRef(d);
    const j = d.jornada || {};
    const jr = ref && j.por_pi ? j.por_pi[ref.desc_pi] : null;
    const idx = ref ? d.pis.indexOf(ref) : -1;
    const ant = idx > 0 ? d.pis[idx - 1] : null;
    const ja = ant ? j.por_pi[ant.desc_pi] : null;

    // melhor/pior time na PI de referência (menor h/ponto = melhor)
    const times = ref ? d.por_time.filter(t => t.por_pi[ref.desc_pi] && t.por_pi[ref.desc_pi].produtividade != null)
      .map(t => ({ nome: t.time, v: t.por_pi[ref.desc_pi].produtividade })).sort((a, b) => a.v - b.v) : [];
    const melhor = times[0], pior = times.length > 1 ? times[times.length - 1] : null;

    const cards = [];
    cards.push({ label: 'Produtividade da jornada', ac: COR_JORNADA,
      val: jr && jr.produtividade_ponderada != null ? f2(jr.produtividade_ponderada) + ' h/pt' : '—',
      sub: ref && jr ? ref.desc_pi + ' · ponderada: ' + nf0.format(jr.horas) + ' h ÷ ' + nf0.format(jr.pontos) + ' pts'
        + (jr.produtividade_media_simples != null ? ' · média simples ' + f2(jr.produtividade_media_simples) : '') : 'sem dados' });
    cards.push({ label: 'Melhor time (menos h/pt)', ac: '#10b981', val: melhor ? f2(melhor.v) + ' h/pt' : '—', sub: melhor ? melhor.nome : 'sem dados' });
    cards.push({ label: 'Pior time (mais h/pt)', ac: '#f43f5e', val: pior ? f2(pior.v) + ' h/pt' : '—', sub: pior ? pior.nome : 'sem dados' });

    let varVal = '—', varSub = 'sem PI anterior com dados', varAc = '#8f9bff';
    if (jr && ja && jr.produtividade_ponderada != null && ja.produtividade_ponderada != null) {
      const dlt = jr.produtividade_ponderada - ja.produtividade_ponderada;
      const pct = ja.produtividade_ponderada > 0 ? dlt / ja.produtividade_ponderada * 100 : null;
      const seta = dlt > 0 ? '▲ ' : dlt < 0 ? '▼ ' : '';
      varVal = seta + sinal(dlt) + ' h/pt';
      varSub = (dlt < 0 ? 'melhorou' : dlt > 0 ? 'piorou' : 'estável') + ' vs ' + ant.desc_pi + ' (' + f2(ja.produtividade_ponderada) + ')'
        + (pct != null ? ' · ' + (pct > 0 ? '+' : pct < 0 ? '−' : '') + nf1.format(Math.abs(pct)) + '%' : '');
      varAc = dlt < 0 ? '#10b981' : dlt > 0 ? '#fbbf24' : '#8f9bff';
    }
    cards.push({ label: 'Variação vs PI anterior', ac: varAc, val: varVal, sub: varSub });

    const sg = j.sugestao_proxima_pi;
    cards.push({ label: 'Sugestão p/ próxima PI', ac: '#22d3ee', val: sg ? f2(sg.valor) + ' h/pt' : '—',
      sub: sg ? 'ponderada da ' + sg.pi_origem + ' (fechada) · somente leitura' : 'nenhuma PI fechada com dados' });

    const grid = el('div', 'kpi-grid');
    cards.forEach(c => {
      const k = el('div', 'kpi'); k.style.setProperty('--ac', c.ac);
      const v = el('div', 'kpi-val', c.val);
      if (c.val.length > 11) v.style.fontSize = '1.25rem';
      k.append(el('div', 'kpi-label', c.label), v, el('div', 'kpi-sub', c.sub));
      grid.appendChild(k);
    });
    return grid;
  }

  function quebra(s, max) {
    const linhas = []; let cur = '';
    String(s).split(/\s+/).forEach(w => {
      if (cur && (cur + ' ' + w).length > max) { linhas.push(cur); cur = w; } else { cur = cur ? cur + ' ' + w : w; }
    });
    if (cur) linhas.push(cur);
    return linhas;
  }

  // ── (1) Barra única da jornada ─────────────────────────────────────────
  function graficoJornada(d) {
    const ref = piRef(d);
    const jr = ref ? d.jornada.por_pi[ref.desc_pi] : null;
    const valor = jr ? jr.produtividade_ponderada : null;
    const varias = new Set(d.por_time.map(t => t.jornada)).size > 1;
    const rotulo = varias ? 'SELEÇÃO' : 'JORNADA';

    const card = el('div', 'ccard');
    const h = el('h3', null, 'TOTAL EM HORAS PARA ENTREGAR 1 PONTO ');
    h.appendChild(el('span', 'badge', (ref ? ref.desc_pi + (ref.origem === 'ao_vivo' ? ' · ao vivo' : ' · histórico') : '—') + ' · média ponderada'));
    card.appendChild(h);

    const wrap = el('div', 'cwrap'); wrap.style.height = '130px';
    const cv = document.createElement('canvas'); cv.id = 'pd-jornada';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barra da ' + rotulo.toLowerCase() + ': ' + (valor == null ? 'sem dados' : f2(valor) + ' horas para entregar 1 ponto')
      + (ref ? ' na ' + ref.desc_pi : '') + '.');
    wrap.appendChild(cv); card.appendChild(wrap);

    const plugin = {
      id: 'pdJornadaRotulo',
      afterDatasetsDraw(chart) {
        const bar = chart.getDatasetMeta(0).data[0];
        if (!bar) return;
        const { ctx } = chart;
        ctx.save();
        ctx.font = '700 22px Segoe UI, sans-serif'; ctx.fillStyle = '#c084fc';
        ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.fillText(valor == null ? 'sem dados' : nf2.format(valor), bar.x + 12, bar.y);
        ctx.restore();
      },
    };
    card._draw = () => window.Charts.make('pd-jornada', {
      type: 'bar',
      data: { labels: [rotulo], datasets: [{ data: [valor || 0], backgroundColor: COR_JORNADA, borderRadius: 3, barThickness: 44 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        layout: { padding: { right: 90 } },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: () => valor == null ? 'sem dados' : f2(valor) + ' h/pt = ' + nf0.format(jr.horas) + ' h ÷ ' + nf0.format(jr.pontos) + ' pts' } },
        },
        scales: {
          x: { beginAtZero: true, grid: { color: '#22263a' }, ticks: { callback: v => nf0.format(v) } },
          y: { grid: { display: false }, ticks: { color: '#e2e8f0', font: { size: 13, weight: '600' } } },
        },
      },
      plugins: [plugin],
    });
    return card;
  }

  // ── (2) Colunas agrupadas por time, uma série por PI ───────────────────
  function graficoTimes(d) {
    const times = d.por_time, pis = d.pis;
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    const h = el('h3', null, 'TOTAL EM HORAS PARA ENTREGAR 1 PONTO ');
    h.appendChild(el('span', 'badge', 'por time · uma série por PI · menos horas por ponto = melhor'));
    card.appendChild(h);

    const scroll = el('div', 'twrap');
    const wrap = el('div', 'cwrap'); wrap.style.height = '380px';
    wrap.style.minWidth = Math.max(480, times.length * pis.length * 46 + 80) + 'px';
    const cv = document.createElement('canvas'); cv.id = 'pd-times';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Colunas agrupadas de horas por ponto, por time e PI: '
      + times.map(t => t.time + ' (' + pis.map(p => p.desc_pi + ' ' + (t.por_pi[p.desc_pi] ? f2(t.por_pi[p.desc_pi].produtividade) : 'sem dados')).join(', ') + ')').join('; ')
      + '. Detalhes na tabela abaixo.');
    wrap.appendChild(cv); scroll.appendChild(wrap); card.appendChild(scroll);

    const leg = el('div', 'prev-legenda'); leg.style.justifyContent = 'center';
    pis.forEach((p, i) => { const sp = el('span'); const ic = el('i'); ic.style.background = corPi(i); sp.append(ic, rotuloPi(p)); leg.appendChild(sp); });
    card.appendChild(leg);

    const datasets = pis.map((p, i) => ({
      label: rotuloPi(p), backgroundColor: corPi(i), borderRadius: 3, barPercentage: 0.9, categoryPercentage: 0.82,
      data: times.map(t => { const x = t.por_pi[p.desc_pi]; return x && x.produtividade != null ? x.produtividade : null; }),
    }));

    const plugin = {
      id: 'pdRotulos',
      afterDatasetsDraw(chart) {
        const { ctx, scales } = chart;
        ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        chart.data.datasets.forEach((ds, di) => {
          chart.getDatasetMeta(di).data.forEach((bar, i) => {
            const v = ds.data[i];
            ctx.font = '700 11.5px Segoe UI, sans-serif';
            if (v == null) {      // sem dados: "—", nunca 0
              ctx.fillStyle = '#7c8aaa'; ctx.fillText('—', bar.x, scales.y.getPixelForValue(0) - 6);
            } else {
              ctx.fillStyle = '#e2e8f0'; ctx.fillText(nf2.format(v), bar.x, bar.y - 6);
            }
          });
        });
        ctx.restore();
      },
    };
    card._draw = () => window.Charts.make('pd-times', {
      type: 'bar',
      data: { labels: times.map(t => quebra(t.time, 16)), datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 22 } },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: it => it.raw != null,
            callbacks: {
              title: items => times[items[0].dataIndex].time,
              label: ctx => {
                const x = times[ctx.dataIndex].por_pi[pis[ctx.datasetIndex].desc_pi];
                return ctx.dataset.label + ': ' + f2(x.produtividade) + ' h/pt (' + nf0.format(x.horas) + ' h ÷ ' + nf0.format(x.pontos) + ' pts · ' + (x.origem === 'ao_vivo' ? 'ao vivo' : 'histórico') + ')';
              },
            },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#e2e8f0', font: { size: 12 }, maxRotation: 0, autoSkip: false } },
          y: { beginAtZero: true, grace: '10%', title: { display: true, text: 'horas por ponto' }, grid: { color: '#22263a' } },
        },
      },
      plugins: [plugin],
    });
    return card;
  }

  // ── (3) Tabela ─────────────────────────────────────────────────────────
  function tabela(d) {
    const pis = d.pis, ref = piRef(d);
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    card.appendChild(el('h3', null, 'Produtividade (h/ponto) por time e PI'));
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl');
    tb.style.minWidth = (300 + pis.length * 100 + 300) + 'px';
    const cap = el('caption', null, 'Horas para entregar 1 ponto por time em cada PI, referência de planejamento, diferença da PI de referência e sugestão para a próxima PI');
    cap.style.cssText = 'position:absolute;left:-9999px';
    tb.appendChild(cap);

    const thead = el('thead'); const trh = el('tr');
    const th = (t, c, sub) => { const x = el('th', c, t); x.scope = 'col'; if (sub) { x.appendChild(document.createElement('br')); x.appendChild(el('span', 'pr-sub', sub)); } trh.appendChild(x); return x; };
    th('Time', '');
    pis.forEach(p => {
      const x = th(p.desc_pi, 'num' + (p.status === 'corrente' ? ' pd-corrente' : ''),
        p.status === 'corrente' ? 'em andamento · ' + (p.origem === 'ao_vivo' ? 'ao vivo' : 'histórico') : (p.origem === 'ao_vivo' ? 'ao vivo (sprint)' : 'histórico'));
      if (p.referencia) x.title = 'PI de referência';
    });
    th('Referência (planejamento)', 'num', 'Velocity');
    th('Δ ' + (ref ? ref.desc_pi : 'PI') + ' vs referência', 'num', 'h/pt · %');
    th('Sugestão p/ próxima PI', 'num', 'PI de origem');
    thead.appendChild(trh); tb.appendChild(thead);

    const tbody = el('tbody');
    const celula = (tr, texto, cls, p) => {
      const td = el('td', 'num' + (cls ? ' ' + cls : '') + (p && p.status === 'corrente' ? ' pd-corrente' : ''), texto);
      tr.appendChild(td); return td;
    };
    d.por_time.forEach(t => {
      const tr = el('tr');
      const tdn = el('td', null, t.time); tdn.title = t.jornada + ' · ' + t.projeto_key; tr.appendChild(tdn);
      pis.forEach(p => {
        const x = t.por_pi[p.desc_pi];
        const td = celula(tr, x && x.produtividade != null ? f2(x.produtividade) : '—', '', p);
        if (x) {
          td.title = nf1.format(x.horas) + ' h ÷ ' + nf1.format(x.pontos) + ' pts' + (x.itens != null ? ' · ' + x.itens + ' itens' : '')
            + (x.itens_sp_nulo ? ' · ' + x.itens_sp_nulo + ' sem SP' : '');
          if (x.itens_sp_nulo) td.appendChild(el('span', 'pr-sub', ' ⚠'));
        } else td.title = 'Sem dados neste time e PI';
      });
      celula(tr, f2(t.referencia_planejamento), '');
      const v = t.variacao_vs_referencia;
      celula(tr, v ? (v.absoluta > 0 ? '▲ ' : v.absoluta < 0 ? '▼ ' : '') + sinal(v.absoluta) + (v.pct != null ? ' · ' + (v.pct > 0 ? '+' : v.pct < 0 ? '−' : '') + nf1.format(Math.abs(v.pct)) + '%' : '') : '—', '');
      const sg = t.sugestao_proxima_pi;
      const tds = celula(tr, sg ? f2(sg.valor) : '—', 'lt-total');
      if (sg) tds.appendChild(el('span', 'pr-sub', ' (' + sg.pi_origem + ')'));
      tbody.appendChild(tr);
    });

    // JORNADA (média ponderada) + média simples como dado
    const varias = new Set(d.por_time.map(t => t.jornada)).size > 1;
    const trj = el('tr', 'lt-media');
    trj.appendChild(el('td', null, varias ? 'SELEÇÃO (ponderada)' : 'JORNADA'));
    pis.forEach(p => {
      const a = d.jornada.por_pi[p.desc_pi];
      const td = celula(trj, a ? f2(a.produtividade_ponderada) : '—', '', p);
      if (a) {
        td.title = nf1.format(a.horas) + ' h ÷ ' + nf1.format(a.pontos) + ' pts · média simples dos times: ' + f2(a.produtividade_media_simples);
        td.appendChild(el('span', 'pr-sub', ' (' + f2(a.produtividade_media_simples) + ')'));
      }
    });
    celula(trj, '—', ''); celula(trj, '—', '');
    const sj = d.jornada.sugestao_proxima_pi;
    const tdj = celula(trj, sj ? f2(sj.valor) : '—', 'lt-total');
    if (sj) tdj.appendChild(el('span', 'pr-sub', ' (' + sj.pi_origem + ')'));
    tbody.appendChild(trj);
    tb.appendChild(tbody);

    w.appendChild(tb); card.appendChild(w);
    const nota = el('div', 'pr-sub',
      'Linha JORNADA = média PONDERADA (Σ horas ÷ Σ pontos dos times); entre parênteses, a média simples dos times, só para comparação. '
      + '"—" = time sem dados naquela PI (não é zero). ⚠ = há itens entregues sem Story Points (não entram nos pontos). '
      + 'Δ = produtividade da PI de referência menos a referência de planejamento (▼ menos horas por ponto que o planejado).');
    nota.style.margin = '8px 4px 0';
    card.appendChild(nota);
    return card;
  }

  function rodape(d) {
    const ref = piRef(d);
    const box = el('details', 'pr-diag'); box.style.marginTop = '18px'; box.open = true;
    box.appendChild(el('summary', null, 'Como ler este gráfico'));
    const ul = el('ul');
    [
      'Produtividade = horas gastas ÷ pontos entregues, considerando só itens de entrega de valor entregues (Aceito, Pendente de Produção, Em produção). Item sem Story Points não usa o estimate: fica fora dos pontos.',
      'Média da jornada ponderada: Σ horas ÷ Σ pontos de todos os times da seleção na PI. Times com mais pontos pesam mais; não é a média simples dos times.',
      'Origem dos valores: PIs fechadas vêm do histórico consolidado (produtividade_historico, gravado por bin/consolidar_produtividade.php). '
        + 'A PI de referência' + (ref ? ' (' + ref.desc_pi + ')' : '') + ', quando está em andamento, é calculada ao vivo pela extração, só com as sprints de valor fechadas da janela. '
        + 'Se você escolhe uma sprint no filtro, a PI de referência é recalculada ao vivo só para essa sprint; as demais PIs não mudam.',
      'Colunas: PIs em ordem cronológica até a PI de referência (filtro Versão; padrão = PI corrente), só as que têm dados para a seleção.',
      'Referência (planejamento): h/ponto planejado na Velocity de cada time (times_composicao).',
    ].forEach(t => ul.appendChild(el('li', null, t)));
    box.appendChild(ul);
    const nota = el('div', 'alert alert-info');
    nota.style.marginTop = '10px';
    nota.setAttribute('role', 'note');
    nota.appendChild(el('strong', null, 'Sugestão para a próxima PI: somente leitura. '));
    nota.appendChild(document.createTextNode('É a produtividade ao fim da PI fechada mais recente com dados e deve alimentar a Velocidade da próxima PI, '
      + 'mas esta tela não grava nada: a aplicação será feita na carga da PI 1/27. Aplicar agora alteraria a Velocidade já validada da PI 4/26.'));
    box.appendChild(nota);
    return box;
  }

  function diagnosticos(d) {
    const g = d.diagnosticos || {};
    const det = el('details', 'pr-diag'); det.style.marginTop = '18px';
    det.appendChild(el('summary', null, 'Diagnósticos dos dados'));
    const ul = el('ul');
    const sp = g.itens_sp_nulo || [];
    ul.appendChild(el('li', null, 'Itens entregues sem Story Points (fora dos pontos): '
      + (sp.length ? sp.map(x => x.time + ' ' + x.pi + ' (' + x.itens_sp_nulo + ')').join('; ') : 'nenhum') + '.'));
    const sd = g.times_sem_dados_por_pi || {};
    const linhas = Object.keys(sd).filter(k => sd[k].length);
    ul.appendChild(el('li', null, 'Times sem dados por PI: '
      + (linhas.length ? linhas.map(k => k + ': ' + sd[k].join(', ')).join('; ') : 'nenhum') + '.'));
    const sc = g.pis_sem_consolidado || {};
    const pc = Object.keys(sc);
    if (pc.length) ul.appendChild(el('li', null, 'PIs fechadas sem consolidado no histórico: ' + pc.map(k => k + ' (' + sc[k].join(', ') + ')').join('; ') + '.'));
    det.appendChild(ul);
    return det;
  }

  function render(d) {
    root.textContent = '';
    root.appendChild(faixaJanela(d));

    if (d.avisos && d.avisos.length) {
      const ul = el('ul', 'prev-avisos');
      d.avisos.forEach(a => ul.appendChild(el('li', null, a)));
      root.appendChild(ul);
    }

    if (!d.por_time || !d.por_time.length || !d.pis || !d.pis.length) {
      const a = el('div', 'alert alert-info');
      a.setAttribute('role', 'status');
      a.appendChild(el('strong', null, 'Sem dados para a seleção.'));
      a.appendChild(document.createElement('br'));
      a.appendChild(el('span', null, 'Não há itens de entrega de valor entregues (nem histórico consolidado) para os filtros escolhidos. '
        + 'Ajuste jornada, time, versão ou sprint no cabeçalho.'));
      root.appendChild(a);
      return;
    }

    root.appendChild(kpis(d));
    root.appendChild(el('div', 'stitle', 'Produtividade'));
    const g1 = graficoJornada(d); root.appendChild(g1); g1._draw();
    const g2 = graficoTimes(d); root.appendChild(g2); g2._draw();
    root.appendChild(tabela(d));
    root.appendChild(rodape(d));
    root.appendChild(diagnosticos(d));
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir a produtividade.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
