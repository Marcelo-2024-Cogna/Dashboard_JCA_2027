<?php
/**
 * vw_plan_real.php — Planejado x Realizado
 * ─────────────────────────────────────────
 * 4 barras horizontais empilhadas (de cima para baixo):
 *   Velocidade | PI Planning | Sprint Planning  → grupo "Planejado"
 *   Entregues                                    → grupo "Entregue"
 * Segmentos: Sustentação · Entrega de Valor (sem épico) · Entrega de Valor (com épico)
 * · Capacidade (só na Velocidade). Dados: GET /graficos/plan-real (lê as views do banco).
 * Os filtros do header (jornada/time/versao/sprint) controlam a página; a janela
 * de sprints é a mesma da Previsibilidade.
 *
 * Todo texto vindo do servidor entra no DOM via textContent (sem innerHTML).
 */
?>
<div id="pr-root">
  <div class="loading"><div class="spinner"></div> Carregando planejado x realizado...</div>
</div>

<script>
(function () {
  const root = document.getElementById('pr-root');
  const BASE = window.APP_BASE || '';

  // ── helpers de DOM (sempre textContent) ───────────────────────────────
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

  // Barras e segmentos (ordem = ordem de empilhamento e da legenda)
  const BARRAS = [
    ['velocidade',      'Velocidade'],
    ['pi_planning',     'PI Planning'],
    ['sprint_planning', 'Sprint Planning'],
    ['entregues',       'Entregues'],
  ];
  const SEGS = [
    { k: 'sustentacao',     nome: 'Sustentação',                 cor: '#cbd5e1', txt: '#1e293b' },
    { k: 'valor_sem_epico', nome: 'Entrega de Valor (sem épico)', cor: '#9333ea', txt: '#fbbf24' },
    { k: 'valor_com_epico', nome: 'Entrega de Valor (com épico)', cor: '#8f9bff', txt: '#ffffff' },
    { k: 'capacidade',      nome: 'Capacidade',                  cor: '#0072c6', txt: '#ffffff' },
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
      res = await fetch(BASE + '/graficos/plan-real' + (qs.toString() ? '?' + qs : ''), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular o planejado x realizado agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
    }
    if (!json || json.success !== true) throw new Error('Resposta inesperada do servidor.');
    return json;
  }

  function faixaJanela(d) {
    const j = d.janela || {};
    const box = el('div', 'alert alert-info prev-janela');
    box.setAttribute('role', 'status');
    box.appendChild(el('strong', null, 'Sprints consideradas: '));
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
    const partes = [];
    if (j.pi) partes.push(j.pi + (fa.versao_padrao ? ' (PI corrente)' : ''));
    if (fa.jornada) partes.push('Jornada: ' + fa.jornada);
    if (fa.time) partes.push('Time: ' + fa.time);
    if (partes.length) box.appendChild(el('div', 'kpi-sub', partes.join(' · ')));
    return box;
  }

  function kpis(d) {
    const b = d.barras, dl = d.deltas;
    const pct = (a, t) => (t > 0 ? nf1.format(a / t * 100) + '%' : '—');
    const cards = [
      { label: 'Velocidade', val: fmt(b.velocidade.total),
        sub: 'sustentação ' + fmt(b.velocidade.sustentacao) + ' · capacidade ' + fmt(b.velocidade.capacidade), ac: '#0072c6' },
      { label: 'Comprometido no PI Planning', val: fmt(b.pi_planning.total),
        sub: pct(b.pi_planning.total, b.velocidade.total) + ' da velocidade (SPE da baseline)', ac: '#8f9bff' },
      { label: 'Sprint Planning', val: fmt(b.sprint_planning.total),
        sub: 'Δ ' + fmtD(dl.pi_planning_para_sprint_planning.total) + ' vs PI Planning (novas histórias, cancelamentos, remanejamentos)', ac: '#9333ea' },
      { label: 'Entregue', val: fmt(b.entregues.total),
        sub: 'Δ ' + fmtD(dl.sprint_planning_para_entregues.total) + ' vs Sprint Planning · ' + pct(b.entregues.total, b.sprint_planning.total) + ' do Sprint Planning', ac: '#10b981' },
    ];
    const grid = el('div', 'kpi-grid');
    cards.forEach(c => {
      const k = el('div', 'kpi'); k.style.setProperty('--ac', c.ac);
      k.append(el('div', 'kpi-label', c.label), el('div', 'kpi-val', c.val), el('div', 'kpi-sub', c.sub));
      grid.appendChild(k);
    });
    return grid;
  }

  // ── Plugins locais: rótulos nos segmentos, total (+Δ) à direita, grupos ──
  function pluginRotulos(d) {
    return {
      id: 'prRotulos',
      afterDatasetsDraw(chart) {
        const { ctx, scales } = chart;
        ctx.save();
        ctx.textBaseline = 'middle';
        // valor dentro de cada segmento (omitido quando estreito demais)
        chart.data.datasets.forEach((ds, di) => {
          const seg = SEGS[di];
          chart.getDatasetMeta(di).data.forEach((bar, i) => {
            const v = ds.data[i];
            if (!v) return;
            const txt = nf.format(v);
            ctx.font = '700 13px Segoe UI, sans-serif';
            const w = ctx.measureText(txt).width;
            const x0 = scales.x.getPixelForValue(bar.$ini), x1 = scales.x.getPixelForValue(bar.$fim);
            if (x1 - x0 < w + 6) return;
            ctx.fillStyle = seg.txt; ctx.textAlign = 'center';
            ctx.fillText(txt, (x0 + x1) / 2, bar.y);
          });
        });
        // total à direita de cada barra (+ Δ nas barras de planejamento/entrega)
        const m0 = chart.getDatasetMeta(0);
        const dl = d.deltas;
        const deltas = [null, null, dl.pi_planning_para_sprint_planning.total, dl.sprint_planning_para_entregues.total];
        BARRAS.forEach(([k], i) => {
          const tot = d.barras[k].total;
          const x = scales.x.getPixelForValue(tot) + 8, y = m0.data[i].y;
          ctx.font = '700 14px Segoe UI, sans-serif'; ctx.textAlign = 'left'; ctx.fillStyle = '#e2e8f0';
          const txt = nf.format(tot);
          ctx.fillText(txt, x, y);
          if (deltas[i] != null && deltas[i] !== 0) {
            ctx.font = '600 11px Segoe UI, sans-serif';
            ctx.fillStyle = deltas[i] > 0 ? '#34d399' : '#fda4af';
            ctx.fillText('Δ ' + fmtD(deltas[i]), x + ctx.measureText(txt).width + 12, y);
          }
        });
        ctx.restore();
      },
    };
  }

  // Rótulos de grupo (Planejado / Entregue) à esquerda, com divisórias
  function pluginGrupos() {
    return {
      id: 'prGrupos',
      afterDraw(chart) {
        const { ctx, chartArea } = chart;
        const m = chart.getDatasetMeta(0).data;
        if (m.length < 4) return;
        const passo = m[1].y - m[0].y, meia = passo / 2;
        const topo = m[0].y - meia, meio = (m[2].y + m[3].y) / 2, base = m[3].y + meia;
        ctx.save();
        ctx.strokeStyle = '#2e3349'; ctx.lineWidth = 1;
        [topo, meio, base].forEach(y => { ctx.beginPath(); ctx.moveTo(2, y); ctx.lineTo(chartArea.left, y); ctx.stroke(); });
        ctx.beginPath(); ctx.moveTo(chartArea.left, topo); ctx.lineTo(chartArea.left, base); ctx.stroke();
        ctx.fillStyle = '#e2e8f0'; ctx.font = '600 12px Segoe UI, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        [['Planejado', (topo + meio) / 2], ['Entregue', (meio + base) / 2]].forEach(([t, y]) => {
          ctx.save(); ctx.translate(12, y); ctx.rotate(-Math.PI / 2); ctx.fillText(t, 0, 0); ctx.restore();
        });
        ctx.restore();
      },
    };
  }

  function grafico(d) {
    const card = el('div', 'ccard');
    const h = el('h3', null, 'Planejado x Realizado ');
    h.appendChild(el('span', 'badge', 'pontos por categoria'));
    card.appendChild(h);
    const wrap = el('div', 'cwrap');
    wrap.style.height = '380px';
    const cv = document.createElement('canvas');
    cv.id = 'pr-chart';
    cv.setAttribute('role', 'img');
    const b = d.barras;
    cv.setAttribute('aria-label', 'Barras horizontais empilhadas: Velocidade ' + fmt(b.velocidade.total)
      + ', PI Planning ' + fmt(b.pi_planning.total) + ', Sprint Planning ' + fmt(b.sprint_planning.total)
      + ' e Entregues ' + fmt(b.entregues.total) + ' pontos. Detalhes na tabela abaixo.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const leg = el('div', 'prev-legenda');
    leg.style.justifyContent = 'center';
    SEGS.forEach(s => { const sp = el('span'); const i = el('i'); i.style.background = s.cor; sp.append(i, s.nome); leg.appendChild(sp); });
    card.appendChild(leg);

    // Datasets por segmento; guarda início/fim de cada barra p/ posicionar o rótulo.
    const datasets = SEGS.map(s => ({
      label: s.nome, backgroundColor: s.cor, borderColor: '#1a1d27', borderWidth: 1, barThickness: 40,
      data: BARRAS.map(([k]) => (b[k][s.k] == null ? 0 : b[k][s.k])),
    }));
    const marca = {
      id: 'prMarca',
      afterUpdate(chart) {
        const acum = BARRAS.map(() => 0);
        chart.data.datasets.forEach((ds, di) => {
          chart.getDatasetMeta(di).data.forEach((bar, i) => { bar.$ini = acum[i]; acum[i] += ds.data[i] || 0; bar.$fim = acum[i]; });
        });
      },
    };
    card._draw = () => window.Charts.make('pr-chart', {
      type: 'bar',
      data: { labels: BARRAS.map(([, n]) => n), datasets },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        layout: { padding: { left: 30, right: 120, top: 6 } },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: it => it.raw > 0,
            callbacks: {
              label: ctx => {
                const k = BARRAS[ctx.dataIndex][0], tot = b[k].total;
                return ctx.dataset.label + ': ' + fmt(ctx.raw) + ' (' + (tot > 0 ? nf1.format(ctx.raw / tot * 100) : '0,0') + '%)';
              },
              footer: items => 'Total: ' + fmt(b[BARRAS[items[0].dataIndex][0]].total),
            },
          },
        },
        scales: {
          x: { stacked: true, min: 0, ticks: { precision: 0 }, grid: { color: '#22263a' } },
          y: { stacked: true, grid: { display: false }, ticks: { color: '#e2e8f0', font: { size: 13 } } },
        },
      },
      plugins: [marca, pluginRotulos(d), pluginGrupos()],
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

  function tabelaPontos(d) {
    const { card, tb } = tabelaBase('Pontos por barra', 'Pontos de cada barra por categoria, com os deltas entre barras');
    tb.style.minWidth = '720px';
    cabecalho(tb, [['Barra', ''], ['Sustentação', 'num'], ['Valor sem épico', 'num'], ['Valor com épico', 'num'], ['Capacidade', 'num'], ['Total', 'num']]);
    const tbody = el('tbody');
    BARRAS.forEach(([k, nome]) => {
      const x = d.barras[k], tr = el('tr');
      tr.appendChild(el('td', null, nome));
      tr.appendChild(el('td', 'num', fmt(x.sustentacao)));
      tr.appendChild(el('td', 'num', k === 'velocidade' ? '—' : fmt(x.valor_sem_epico)));
      tr.appendChild(el('td', 'num', k === 'velocidade' ? '—' : fmt(x.valor_com_epico)));
      tr.appendChild(el('td', 'num', k === 'velocidade' ? fmt(x.capacidade) : '—'));
      tr.appendChild(el('td', 'num', fmt(x.total)));
      tbody.appendChild(tr);
    });
    [['Δ PI Planning → Sprint Planning', d.deltas.pi_planning_para_sprint_planning],
     ['Δ Sprint Planning → Entregues', d.deltas.sprint_planning_para_entregues]].forEach(([nome, x]) => {
      const tr = el('tr', 'pr-delta');
      tr.appendChild(el('td', null, nome));
      tr.appendChild(el('td', 'num', fmtD(x.sustentacao)));
      tr.appendChild(el('td', 'num', fmtD(x.valor_sem_epico)));
      tr.appendChild(el('td', 'num', fmtD(x.valor_com_epico)));
      tr.appendChild(el('td', 'num', '—'));
      tr.appendChild(el('td', 'num', fmtD(x.total)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    return card;
  }

  function tabelaTimes(d) {
    const { card, tb } = tabelaBase('Pontos por time', 'Total de cada barra e deltas por time; abaixo do total, o detalhe sustentação, valor sem épico e valor com épico');
    cabecalho(tb, [['Time', ''], ['Jornada', ''], ['Velocidade', 'num'], ['PI Planning', 'num'], ['Sprint Planning', 'num'],
                   ['Entregues', 'num'], ['Δ PI → Sprint Planning', 'num'], ['Δ Sprint Planning → Entregues', 'num']]);
    const celBarra = (x, vel) => {
      const td = el('td', 'num');
      td.appendChild(el('div', null, fmt(x.total)));
      td.appendChild(el('div', 'pr-sub', vel
        ? 'S ' + fmt(x.sustentacao) + ' · C ' + fmt(x.capacidade)
        : 'S ' + fmt(x.sustentacao) + ' · SE ' + fmt(x.valor_sem_epico) + ' · CE ' + fmt(x.valor_com_epico)));
      return td;
    };
    const linha = (tr, x) => {
      tr.appendChild(celBarra(x.barras.velocidade, true));
      tr.appendChild(celBarra(x.barras.pi_planning, false));
      tr.appendChild(celBarra(x.barras.sprint_planning, false));
      tr.appendChild(celBarra(x.barras.entregues, false));
      tr.appendChild(el('td', 'num', fmtD(x.deltas.pi_planning_para_sprint_planning.total)));
      tr.appendChild(el('td', 'num', fmtD(x.deltas.sprint_planning_para_entregues.total)));
    };
    const tbody = el('tbody');
    (d.por_time || []).forEach(t => {
      const tr = el('tr');
      const tdT = el('td', null, t.time);
      if (!t.tem_baseline) tdT.title = 'Sem baseline importada para este time';
      tr.appendChild(tdT);
      tr.appendChild(el('td', null, t.jornada));
      linha(tr, t);
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    const tf = el('tfoot'); const trf = el('tr');
    trf.appendChild(el('td', null, 'Total da seleção'));
    trf.appendChild(el('td', null, ''));
    linha(trf, d);
    tf.appendChild(trf); tb.appendChild(tf);
    const nota = el('div', 'pr-sub', 'S = sustentação · SE = valor sem épico · CE = valor com épico · C = capacidade');
    nota.style.margin = '8px 4px 0';
    card.appendChild(nota);
    return card;
  }

  function diagnosticos(d) {
    const g = d.diagnosticos || {};
    const det = el('details', 'pr-diag');
    det.style.marginTop = '18px';
    det.appendChild(el('summary', null, 'Diagnósticos dos dados'));
    const ul = el('ul');
    const fora = g.tipos_fora_das_barras || [];
    const rot = { pi_planning: 'PI Planning', sprint_planning: 'Sprint Planning', entregues: 'Entregues' };
    if (fora.length) {
      const porBarra = {};
      fora.forEach(f => { (porBarra[f.barra] = porBarra[f.barra] || []).push(f.tipo_item + ': ' + fmt(f.pontos) + ' pts (' + f.itens + ' itens)'); });
      Object.keys(porBarra).forEach(k => ul.appendChild(el('li', null, 'Tipos fora das barras em ' + (rot[k] || k) + ' → ' + porBarra[k].join('; '))));
    } else {
      ul.appendChild(el('li', null, 'Nenhum tipo de item ficou fora das barras.'));
    }
    const st = g.status_sustentacao || [];
    ul.appendChild(el('li', null, 'Status dos itens de Sustentação: ' + (st.length
      ? st.map(s => s.status + ' (' + s.itens + ' itens, ' + (s.considerado_entregue ? 'conta como entregue' : 'não conta como entregue') + ')').join('; ')
      : 'nenhum item')));
    const sn = g.sustentacao_sp_nulo || {};
    ul.appendChild(el('li', null, 'Sustentação com SP nulo e SPE > 0: ' + ((sn.janela || {}).itens || 0) + ' itens (' + fmt((sn.janela || {}).spe || 0)
      + ' SPE), dos quais entregues: ' + ((sn.entregues || {}).itens || 0) + '. Nenhum fallback é aplicado.'));
    const vn = g.valor_sp_nulo_entregues || {};
    ul.appendChild(el('li', null, 'Valor entregue com SP nulo e SPE > 0: ' + (vn.itens || 0) + ' itens (' + fmt(vn.spe || 0) + ' SPE).'));
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

    const temDados = (d.por_time || []).length > 0 && d.janela && d.janela.sprints && d.janela.sprints.length > 0;
    if (!temDados) {
      const a = el('div', 'alert alert-info');
      a.setAttribute('role', 'status');
      a.appendChild(el('strong', null, 'Sem dados para a seleção.'));
      a.appendChild(document.createElement('br'));
      a.appendChild(el('span', null, 'Não há extração do Jira para os filtros e a janela escolhidos. '
        + 'Ajuste jornada, time, versão ou sprint no cabeçalho.'));
      root.appendChild(a);
      return;
    }

    root.appendChild(kpis(d));
    root.appendChild(el('div', 'stitle', 'Planejado x Realizado'));
    const g = grafico(d);
    root.appendChild(g);
    g._draw();
    root.appendChild(tabelaPontos(d));
    root.appendChild(tabelaTimes(d));
    root.appendChild(diagnosticos(d));
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir o planejado x realizado.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
