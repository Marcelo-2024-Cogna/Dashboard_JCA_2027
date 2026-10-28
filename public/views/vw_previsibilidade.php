<?php
/**
 * vw_previsibilidade.php — Previsibilidade por times
 * ───────────────────────────────────────────────────
 * Previsibilidade = SP entregue do planejado / SP comprometido (baseline) × 100,
 * por time, na janela de sprints (fechadas, ou a sprint escolhida no filtro).
 * Meta: 80%. Dados: GET /graficos/previsibilidade (lê as views do banco).
 * Filtros do header (jornada/time/versao/sprint) controlam a página.
 *
 * Todo texto vindo do servidor entra no DOM via textContent (sem innerHTML).
 */
?>
<div id="prev-root">
  <div class="loading"><div class="spinner"></div> Carregando previsibilidade...</div>
</div>

<script>
(function () {
  const root = document.getElementById('prev-root');
  const BASE = window.APP_BASE || '';
  const META_PADRAO = 80;

  // ── helpers de DOM (sempre textContent) ───────────────────────────────
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });
  const fmtSp  = v => (v == null ? '—' : nf0.format(v));
  const fmtPct = v => (v == null ? '—' : nf1.format(v) + '%');

  // faixa: >=80 verde, >=60 âmbar, senão vermelho
  function faixa(v, meta) {
    if (v == null) return { cor: '#7c8aaa', cls: 'prev-pct-na' };
    if (v >= meta) return { cor: '#10b981', cls: 'prev-pct-ok' };
    if (v >= 60)   return { cor: '#f59e0b', cls: 'prev-pct-warn' };
    return { cor: '#f43f5e', cls: 'prev-pct-bad' };
  }

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
      res = await fetch(BASE + '/graficos/previsibilidade' + (qs.toString() ? '?' + qs : ''), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular a previsibilidade agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
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
    const meta = d.meta || META_PADRAO, t = d.total || {};
    const fx = faixa(t.previsibilidade_pct, meta);
    const cards = [
      { label: 'Previsibilidade', val: fmtPct(t.previsibilidade_pct), sub: 'meta ' + meta + '% · entregue do planejado / committed', ac: fx.cor },
      { label: 'Committed', val: fmtSp(t.committed), sub: 'SP da baseline na janela (' + ((t.itens || {}).committed || 0) + ' itens)', ac: '#6366f1' },
      { label: 'Entregue do planejado', val: fmtSp(t.delivered_planned), sub: 'SP da baseline hoje entregues (' + ((t.itens || {}).delivered_planned || 0) + ' itens)', ac: '#10b981' },
      { label: 'Escopo adicionado', val: fmtSp(t.scope_add), sub: 'SP fora da baseline na janela (' + ((t.itens || {}).scope_add || 0) + ' itens)', ac: '#22d3ee' },
    ];
    const grid = el('div', 'kpi-grid');
    cards.forEach(c => {
      const k = el('div', 'kpi'); k.style.setProperty('--ac', c.ac);
      k.append(el('div', 'kpi-label', c.label), el('div', 'kpi-val', c.val), el('div', 'kpi-sub', c.sub));
      grid.appendChild(k);
    });
    return grid;
  }

  // Plugins locais: linha da meta e rótulos de valor (sem lib extra).
  function pluginMeta(meta) {
    return {
      id: 'metaLine',
      afterDatasetsDraw(chart) {
        const { ctx, chartArea, scales } = chart;
        const x = scales.x.getPixelForValue(meta);
        ctx.save();
        ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1.5; ctx.setLineDash([6, 4]);
        ctx.beginPath(); ctx.moveTo(x, chartArea.top); ctx.lineTo(x, chartArea.bottom); ctx.stroke();
        ctx.setLineDash([]); ctx.fillStyle = '#e2e8f0'; ctx.font = '11px Segoe UI, sans-serif'; ctx.textAlign = 'center';
        ctx.fillText('Meta ' + meta + '%', x, chartArea.top - 6);
        ctx.restore();
      },
    };
  }
  function pluginValores(valores) {
    return {
      id: 'valores',
      afterDatasetsDraw(chart) {
        const { ctx, scales } = chart;
        const meta = chart.getDatasetMeta(0);
        ctx.save();
        ctx.font = '600 12px Segoe UI, sans-serif'; ctx.textBaseline = 'middle'; ctx.textAlign = 'left'; ctx.fillStyle = '#e2e8f0';
        meta.data.forEach((bar, i) => {
          const v = valores[i];
          const xBase = v == null ? scales.x.getPixelForValue(0) : bar.x;
          ctx.fillText(v == null ? 'sem committed' : nf1.format(v) + '%', xBase + 8, bar.y);
        });
        ctx.restore();
      },
    };
  }

  function grafico(d) {
    const meta = d.meta || META_PADRAO;
    const times = d.por_time || [];
    const card = el('div', 'ccard');
    const h = el('h3', null, 'Previsibilidade por time ');
    h.appendChild(el('span', 'badge', '% entregue do planejado'));
    card.appendChild(h);
    const wrap = el('div', 'cwrap');
    wrap.style.height = Math.max(260, 42 * times.length + 90) + 'px';
    const cv = document.createElement('canvas');
    cv.id = 'prev-chart';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras horizontais com a previsibilidade percentual de cada time e a meta de ' + meta + '%.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const leg = el('div', 'prev-legenda');
    [['#10b981', '≥ ' + meta + '%'], ['#f59e0b', '≥ 60%'], ['#f43f5e', '< 60%']].forEach(([c, t]) => {
      const s = el('span'); const i = el('i'); i.style.background = c; s.append(i, t); leg.appendChild(s);
    });
    card.appendChild(leg);

    const valores = times.map(t => t.previsibilidade_pct);
    const maxV = Math.max(meta, ...valores.filter(v => v != null));
    const xMax = Math.ceil((maxV + 15) / 10) * 10;
    // O Chart.js precisa do canvas no DOM: cria depois do append (feito pelo chamador).
    card._draw = () => window.Charts.make('prev-chart', {
      type: 'bar',
      data: {
        labels: times.map(t => t.time),
        datasets: [{
          label: 'Previsibilidade (%)',
          data: valores.map(v => (v == null ? 0 : v)),
          backgroundColor: valores.map(v => faixa(v, meta).cor),
          borderRadius: 4, barThickness: 22,
        }],
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 18, right: 90 } },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => {
            const t = times[ctx.dataIndex];
            return t.previsibilidade_pct == null
              ? 'Sem committed na janela'
              : [fmtPct(t.previsibilidade_pct), 'Entregue do planejado: ' + fmtSp(t.delivered_planned) + ' SP', 'Committed: ' + fmtSp(t.committed) + ' SP'];
          } } },
        },
        scales: {
          x: { min: 0, max: xMax, ticks: { callback: v => v + '%' }, title: { display: true, text: 'Previsibilidade (%)' } },
          y: { grid: { display: false } },
        },
      },
      plugins: [pluginMeta(meta), pluginValores(valores)],
    });
    return card;
  }

  function tabela(d) {
    const meta = d.meta || META_PADRAO;
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    card.appendChild(el('h3', null, 'Números por time'));
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl');
    const cap = el('caption', null, 'Committed, entregue do planejado, previsibilidade e escopo adicionado por time, em Story Points');
    cap.style.cssText = 'position:absolute;left:-9999px';
    tb.appendChild(cap);
    const thead = el('thead'); const trh = el('tr');
    [['Time', ''], ['Jornada', ''], ['Committed (SP)', 'num'], ['Entregue do planejado (SP)', 'num'], ['Previsibilidade', 'num'],
     ['Escopo adicionado (SP)', 'num'], ['Entregue total (SP)', 'num']].forEach(([t, c]) => {
      const th = el('th', c, t); th.scope = 'col'; trh.appendChild(th);
    });
    thead.appendChild(trh); tb.appendChild(thead);

    const tbody = el('tbody');
    (d.por_time || []).forEach(t => {
      const tr = el('tr');
      const tdT = el('td', null, t.time);
      if (!t.tem_baseline) tdT.title = 'Sem baseline importada para este time';
      tr.appendChild(tdT);
      tr.appendChild(el('td', null, t.jornada));
      tr.appendChild(el('td', 'num', fmtSp(t.committed)));
      tr.appendChild(el('td', 'num', fmtSp(t.delivered_planned)));
      tr.appendChild(el('td', 'num ' + faixa(t.previsibilidade_pct, meta).cls, fmtPct(t.previsibilidade_pct)));
      tr.appendChild(el('td', 'num', fmtSp(t.scope_add)));
      tr.appendChild(el('td', 'num', fmtSp(t.delivered_total)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);

    const t = d.total || {};
    const tf = el('tfoot'); const trf = el('tr');
    trf.appendChild(el('td', null, 'Total da seleção'));
    trf.appendChild(el('td', null, ''));
    trf.appendChild(el('td', 'num', fmtSp(t.committed)));
    trf.appendChild(el('td', 'num', fmtSp(t.delivered_planned)));
    trf.appendChild(el('td', 'num ' + faixa(t.previsibilidade_pct, meta).cls, fmtPct(t.previsibilidade_pct)));
    trf.appendChild(el('td', 'num', fmtSp(t.scope_add)));
    trf.appendChild(el('td', 'num', fmtSp(t.delivered_total)));
    tf.appendChild(trf); tb.appendChild(tf);

    w.appendChild(tb); card.appendChild(w);
    return card;
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
      a.appendChild(el('span', null, 'Não há baseline e extração do Jira para os filtros e a janela escolhidos. '
        + 'Ajuste jornada, time, versão ou sprint no cabeçalho.'));
      root.appendChild(a);
      return;
    }
    if (d.total && d.total.committed === 0) {
      const a = el('div', 'alert alert-info');
      a.setAttribute('role', 'status');
      a.appendChild(el('strong', null, 'Sem baseline na janela.'));
      a.appendChild(document.createElement('br'));
      a.appendChild(el('span', null, 'Nenhum item comprometido na baseline para estas sprints; a previsibilidade não pode ser calculada.'));
      root.appendChild(a);
    }

    root.appendChild(kpis(d));
    root.appendChild(el('div', 'stitle', 'Previsibilidade por time'));
    const g = grafico(d);
    root.appendChild(g);
    g._draw();
    root.appendChild(tabela(d));
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir a previsibilidade.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
