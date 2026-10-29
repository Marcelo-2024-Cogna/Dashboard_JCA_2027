<?php
/**
 * vw_cycle_time.php — Lead Time (cycle time médio por etapas)
 * ────────────────────────────────────────────────────────────
 * Colunas verticais empilhadas, uma por time (+ coluna destacada "Média da jornada"):
 *   Desenvolvimento (base) · Homologação · Implantação (topo). Ideação fora da conta.
 * Cada segmento = CONTRIBUIÇÃO da etapa ao lead time médio (Σ etapa ÷ nº de itens),
 * de modo que Dev + Hom + Impl = lead time médio. Itens: entrega de valor ENTREGUE
 * com sprint na janela. Dados: GET /graficos/lead-time (lê as views do banco).
 * Os filtros do header (jornada/time/versao/sprint) controlam a página; a janela
 * de sprints é a mesma da Previsibilidade e do Planejado x Realizado.
 *
 * Todo texto vindo do servidor entra no DOM via textContent (sem innerHTML).
 */
?>
<div id="lt-root">
  <div class="loading"><div class="spinner"></div> Carregando lead time...</div>
</div>

<script>
(function () {
  const root = document.getElementById('lt-root');
  const BASE = window.APP_BASE || '';

  // ── helpers de DOM (sempre textContent) ───────────────────────────────
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
  const f1 = v => (v == null ? '—' : nf1.format(v));
  const COR_ALERTA = '#fbbf24';   // âmbar: sempre acompanhado de ⚠ + texto
  // Resumo da implantação (entregues x concluídas x pendentes) de uma linha de dados
  const resumoDe = l => (l && l.implantacao_resumo) || { entregues: 0, concluidas: 0, pendentes_producao: 0, sem_fim_outros: 0, pct_concluidas: null };
  const textoPendentes = r => r.pendentes_producao + ' pendentes de produção: contados como entregues, mas ainda sem implantação concluída';
  const marcaImplantacao = l => { const r = resumoDe(l); return r.pct_concluidas != null && r.pct_concluidas < 50; };

  // Etapas na ordem de empilhamento (a primeira fica na base da coluna)
  const ETAPAS = [
    { k: 'desenvolvimento', nome: 'Desenvolvimento', cor: '#9333ea', txt: '#ffffff' },
    { k: 'homologacao',     nome: 'Homologação',     cor: '#a9b8e8', txt: '#1e293b' },
    { k: 'implantacao',     nome: 'Implantação',     cor: '#d5ddf2', txt: '#1e293b' },
  ];
  const COR_MEDIA = '#fbbf24';

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
      res = await fetch(BASE + '/graficos/lead-time' + (qs.toString() ? '?' + qs : ''), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular o lead time agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
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
    const t = d.total;
    // etapa que mais pesa = maior contribuição ao lead time médio da seleção
    let top = null;
    ETAPAS.forEach(e => { const c = t[e.k].contribuicao; if (c != null && (top === null || c > top.c)) top = { nome: e.nome, c }; });
    const cards = [
      { label: 'Lead time médio', val: t.lead_time_medio == null ? '—' : f1(t.lead_time_medio) + ' d',
        sub: 'dias úteis · Dev + Hom + Impl por item', ac: '#9333ea' },
      { label: 'Itens considerados', val: nf0.format(t.itens),
        sub: 'valor entregue na janela' + (t.itens_sem_etapa ? ' · ' + t.itens_sem_etapa + ' sem etapa (fora da média)' : ''), ac: '#8f9bff' },
      { label: 'Etapa que mais pesa', val: top ? top.nome : '—',
        sub: top && t.lead_time_medio ? f1(top.c) + ' d de ' + f1(t.lead_time_medio) + ' d (' + nf0.format(top.c / t.lead_time_medio * 100) + '%)' : 'sem dados', ac: '#a9b8e8' },
      { label: 'Mediana (P50)', val: t.p50 == null ? '—' : f1(t.p50) + ' d',
        sub: t.max == null ? 'sem dados' : 'máximo ' + nf0.format(t.max) + ' d', ac: '#10b981' },
    ];
    // Entregues sem implantação concluída (sinalização; não altera o lead time)
    const rs = resumoDe(t);
    const semImpl = rs.entregues - rs.concluidas;
    cards.push({
      label: '⚠ Entregues sem implantação concluída',
      val: nf0.format(semImpl) + ' de ' + nf0.format(rs.entregues) + (rs.pct_concluidas == null ? '' : ' (' + nf0.format(100 - rs.pct_concluidas) + '%)'),
      sub: rs.pendentes_producao + ' pendentes de produção · ' + rs.sem_fim_outros + ' em outros status · contados como entregues',
      ac: semImpl > 0 ? COR_ALERTA : '#10b981', title: textoPendentes(rs),
    });
    const grid = el('div', 'kpi-grid');
    cards.forEach(c => {
      const k = el('div', 'kpi'); k.style.setProperty('--ac', c.ac);
      const v = el('div', 'kpi-val', c.val);
      if (c.val.length > 8) v.style.fontSize = '1.1rem';   // valor textual (nome da etapa) não pode estourar o card
      if (c.title) k.title = c.title;
      k.append(el('div', 'kpi-label', c.label), v, el('div', 'kpi-sub', c.sub));
      grid.appendChild(k);
    });
    return grid;
  }

  // Quebra o nome em linhas curtas para o eixo X
  function quebra(s, max) {
    const linhas = []; let cur = '';
    String(s).split(/\s+/).forEach(w => {
      if (cur && (cur + ' ' + w).length > max) { linhas.push(cur); cur = w; } else { cur = cur ? cur + ' ' + w : w; }
    });
    if (cur) linhas.push(cur);
    return linhas;
  }

  // Colunas do gráfico: times de cada jornada + "Média da jornada" (destacada)
  function colunas(d) {
    const cols = [];
    const varias = (d.por_jornada || []).length > 1;
    (d.por_jornada || []).forEach(j => {
      j.times.forEach(t => cols.push({ rotulo: t.time, linha: t, media: false, jornada: j.jornada }));
      cols.push({ rotulo: varias ? 'Média · ' + j.jornada : 'Média da jornada', linha: j.media_jornada, media: true, jornada: j.jornada });
    });
    return cols;
  }

  function grafico(d) {
    const cols = colunas(d);
    const card = el('div', 'ccard');
    const h = el('h3', null, 'Cycle time médio por etapas ');
    h.appendChild(el('span', 'badge', 'dias úteis · contribuição de cada etapa ao lead time médio'));
    card.appendChild(h);

    const scroll = el('div', 'twrap');
    const wrap = el('div', 'cwrap');
    wrap.style.height = '400px';
    wrap.style.minWidth = Math.max(480, cols.length * 96) + 'px';
    const cv = document.createElement('canvas');
    cv.id = 'lt-chart';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Colunas empilhadas do lead time médio por etapa: '
      + cols.map(c => c.rotulo + ' ' + (c.linha.lead_time_medio == null ? 'sem dados' : f1(c.linha.lead_time_medio) + ' dias')).join('; ')
      + '. Detalhes na tabela abaixo.');
    wrap.appendChild(cv);
    scroll.appendChild(wrap);
    card.appendChild(scroll);

    const leg = el('div', 'prev-legenda');
    leg.style.justifyContent = 'center';
    ETAPAS.forEach(s => { const sp = el('span'); const i = el('i'); i.style.background = s.cor; sp.append(i, s.nome); leg.appendChild(sp); });
    const spM = el('span'); const iM = el('i'); iM.style.cssText = 'background:transparent;border:2px solid ' + COR_MEDIA; spM.append(iM, 'Média da jornada (todos os itens da jornada)');
    leg.appendChild(spM);
    card.appendChild(leg);

    const datasets = ETAPAS.map(s => ({
      label: s.nome, backgroundColor: s.cor, barPercentage: 0.62, categoryPercentage: 0.9,
      borderWidth: c => (cols[c.dataIndex] && cols[c.dataIndex].media ? 2 : 1),
      borderColor: c => (cols[c.dataIndex] && cols[c.dataIndex].media ? COR_MEDIA : '#1a1d27'),
      data: cols.map(c => c.linha[s.k].contribuicao || 0),
    }));

    // faixa de fundo nas colunas de média, valores nos segmentos e total acima da coluna
    const plugin = {
      id: 'ltRotulos',
      beforeDatasetsDraw(chart) {
        const { ctx, chartArea, scales } = chart;
        const w = chartArea.width / cols.length;
        ctx.save();
        ctx.fillStyle = 'rgba(251,191,36,.07)';
        cols.forEach((c, i) => { if (c.media) ctx.fillRect(scales.x.getPixelForValue(i) - w / 2, chartArea.top, w, chartArea.height); });
        ctx.restore();
      },
      afterDatasetsDraw(chart) {
        const { ctx, scales } = chart;
        ctx.save();
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        chart.data.datasets.forEach((ds, di) => {
          chart.getDatasetMeta(di).data.forEach((bar, i) => {
            const v = ds.data[i];
            if (!v) return;
            const alt = Math.abs(bar.base - bar.y);
            if (alt < 16) return;                 // segmento estreito: sem rótulo (vale o tooltip)
            ctx.font = '700 12px Segoe UI, sans-serif';
            ctx.fillStyle = ETAPAS[di].txt;
            ctx.fillText(nf1.format(v), bar.x, (bar.base + bar.y) / 2);
          });
        });
        cols.forEach((c, i) => {
          const x = scales.x.getPixelForValue(i);
          const tot = c.linha.lead_time_medio;
          ctx.font = '700 14px Segoe UI, sans-serif';
          if (tot == null) {
            ctx.fillStyle = '#7c8aaa'; ctx.fillText('sem itens', x, scales.y.getPixelForValue(0) - 12);
          } else {
            ctx.fillStyle = c.media ? COR_MEDIA : '#e2e8f0';
            ctx.fillText(nf1.format(tot), x, scales.y.getPixelForValue(tot) - 11);
          }
        });
        ctx.restore();
      },
    };

    card._draw = () => window.Charts.make('lt-chart', {
      type: 'bar',
      data: { labels: cols.map(c => { const q = quebra(c.rotulo, 14); if (marcaImplantacao(c.linha)) q.push('⚠ impl. pendente'); return q; }), datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 26 } },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: it => it.raw > 0 || cols[it.dataIndex].linha[ETAPAS[it.datasetIndex].k].n > 0,
            callbacks: {
              title: items => cols[items[0].dataIndex].rotulo,
              label: ctx => {
                const k = ETAPAS[ctx.datasetIndex].k;
                const e = cols[ctx.dataIndex].linha[k];
                const linha = ctx.dataset.label + ': ' + f1(e.contribuicao) + ' d'
                  + (e.n > 0 ? ' · média nos itens que têm a etapa: ' + f1(e.media_nos_itens) + ' d (n=' + e.n + ')' : ' · nenhum item tem esta etapa');
                if (k !== 'implantacao') return linha;
                const r = resumoDe(cols[ctx.dataIndex].linha);
                return [linha, 'concluída em ' + r.concluidas + ' de ' + r.entregues + ' itens (' + r.pendentes_producao + ' pendentes)'];
              },
              footer: items => {
                const l = cols[items[0].dataIndex].linha;
                return 'Lead time médio: ' + f1(l.lead_time_medio) + ' d · ' + l.itens + ' itens · P50 ' + f1(l.p50) + ' · máx ' + (l.max == null ? '—' : l.max);
              },
            },
          },
        },
        scales: {
          x: { stacked: true, grid: { display: false }, ticks: { color: '#e2e8f0', font: { size: 12 }, maxRotation: 0, autoSkip: false } },
          y: { stacked: true, beginAtZero: true, grace: '8%', title: { display: true, text: 'dias úteis' }, grid: { color: '#22263a' } },
        },
      },
      plugins: [plugin],
    });
    return card;
  }

  // ── Tabelas ───────────────────────────────────────────────────────────
  function tabelaBase(titulo, legenda, minW) {
    const card = el('div', 'ccard');
    card.style.marginTop = '18px';
    card.appendChild(el('h3', null, titulo));
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl');
    tb.style.minWidth = minW + 'px';
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
  const linhasDaTabela = d => {
    const out = [];
    const varias = (d.por_jornada || []).length > 1;
    (d.por_jornada || []).forEach(j => {
      if (varias) out.push({ grupo: j.jornada });
      j.times.forEach(t => out.push({ nome: t.time, l: t }));
      out.push({ nome: 'MÉDIA DA JORNADA', l: j.media_jornada, media: true });
    });
    if (varias) out.push({ nome: 'TOTAL DA SELEÇÃO', l: d.total, media: true });
    return out;
  };
  function linhaGrupo(tbody, texto, colspan) {
    const tr = el('tr', 'lt-grupo'); const td = el('td', null, texto); td.colSpan = colspan;
    tr.appendChild(td); tbody.appendChild(tr);
  }

  function tabelaPrincipal(d) {
    const { card, tb } = tabelaBase('Cycle time médio por etapas (dias úteis)',
      'Contribuição de cada etapa ao lead time médio por time, com lead time médio e número de itens', 640);
    cabecalho(tb, [['Time', ''], ['Desenvolvimento', 'num'], ['Homologação', 'num'], ['Implantação', 'num'], ['Lead time médio', 'num'], ['Itens', 'num'], ['Implantação concluída', 'num']]);
    tb.style.minWidth = '860px';
    const tbody = el('tbody');
    linhasDaTabela(d).forEach(r => {
      if (r.grupo) { linhaGrupo(tbody, r.grupo, 7); return; }
      const tr = el('tr', r.media ? 'lt-media' : null);
      tr.appendChild(el('td', null, r.nome));
      ETAPAS.forEach(e => tr.appendChild(el('td', 'num', f1(r.l[e.k].contribuicao))));
      tr.appendChild(el('td', 'num lt-total', f1(r.l.lead_time_medio)));
      tr.appendChild(el('td', 'num', nf0.format(r.l.itens)));
      const rs = resumoDe(r.l);
      const td = el('td', 'num', rs.concluidas + ' de ' + rs.entregues);
      if (rs.pendentes_producao > 0) {
        const aviso = el('span', null, ' ⚠ ' + rs.pendentes_producao + ' pend.');
        aviso.style.cssText = 'color:' + COR_ALERTA + ';font-weight:700;white-space:nowrap';
        td.appendChild(aviso);
        td.title = textoPendentes(rs);
      }
      tr.appendChild(td);
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    const nota = el('div', 'pr-sub', 'Cada etapa mostra a contribuição ao lead time médio (Σ dias da etapa ÷ itens do time); a soma das três etapas é o lead time médio. '
      + 'Ideação fora da conta. A média da jornada é a média de todos os itens da jornada, não a média dos times. '
      + '⚠ Implantação concluída: itens "Pendente de Produção" contam como entregues, mas ainda não têm fim de implantação; '
      + 'a Implantação só soma as etapas que o item tem, então times com muitos pendentes aparecem com implantação subestimada e não são diretamente comparáveis '
      + '(⚠ no eixo do gráfico = menos de 50% dos itens com implantação concluída).');
    nota.style.margin = '8px 4px 0';
    card.appendChild(nota);
    return card;
  }

  function tabelaSecundaria(d) {
    const det = el('details', 'pr-diag');
    det.style.marginTop = '18px';
    det.appendChild(el('summary', null, 'Detalhes: média nos itens que têm a etapa, P50 e máximo (para avaliar outliers)'));
    const { card, tb } = tabelaBase('', 'Média de cada etapa apenas nos itens que a possuem, com o n, e mediana e máximo do lead time por item', 760);
    card.style.marginTop = '10px';
    card.removeChild(card.firstChild);   // sem título (o summary já descreve)
    cabecalho(tb, [['Time', ''], ['Desenvolvimento (n)', 'num'], ['Homologação (n)', 'num'], ['Implantação (n)', 'num'],
                   ['P50 (mediana)', 'num'], ['Máx.', 'num'], ['Sem etapa', 'num']]);
    const tbody = el('tbody');
    linhasDaTabela(d).forEach(r => {
      if (r.grupo) { linhaGrupo(tbody, r.grupo, 7); return; }
      const tr = el('tr', r.media ? 'lt-media' : null);
      tr.appendChild(el('td', null, r.nome));
      ETAPAS.forEach(e => {
        const x = r.l[e.k];
        const td = el('td', 'num', f1(x.media_nos_itens));
        td.appendChild(el('span', 'pr-sub', ' (' + x.n + ')'));
        tr.appendChild(td);
      });
      tr.appendChild(el('td', 'num', f1(r.l.p50)));
      tr.appendChild(el('td', 'num', r.l.max == null ? '—' : nf0.format(r.l.max)));
      tr.appendChild(el('td', 'num', nf0.format(r.l.itens_sem_etapa)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    det.appendChild(card);
    return det;
  }

  function diagnosticos(d) {
    const g = d.diagnosticos || {};
    const det = el('details', 'pr-diag');
    det.style.marginTop = '18px';
    det.appendChild(el('summary', null, 'Diagnósticos dos dados'));
    const ul = el('ul');
    const fora = g.itens_fora_por_tipo || [];
    ul.appendChild(el('li', null, 'Itens da janela fora do lead time (tipo que não é entrega de valor): '
      + (fora.length ? fora.map(f => f.tipo_item + ' (' + f.itens + ')').join('; ') : 'nenhum') + '.'));
    const ne = g.valor_nao_entregue_por_status || [];
    ul.appendChild(el('li', null, 'Itens de entrega de valor na janela ainda não entregues: '
      + (ne.length ? ne.map(f => f.status + ' (' + f.itens + ')').join('; ') : 'nenhum') + '.'));
    const se = (g.itens_sem_etapa_por_time || []).filter(x => x.itens > 0);
    ul.appendChild(el('li', null, 'Itens entregues sem nenhuma etapa (fora da média): '
      + (se.length ? se.map(x => x.time + ' (' + x.itens + ')').join('; ') : 'nenhum') + '.'));
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

    const temDados = d.total && d.total.itens > 0 && d.janela && d.janela.sprints && d.janela.sprints.length > 0;
    if (!temDados) {
      const a = el('div', 'alert alert-info');
      a.setAttribute('role', 'status');
      a.appendChild(el('strong', null, 'Sem dados para a seleção.'));
      a.appendChild(document.createElement('br'));
      a.appendChild(el('span', null, 'Não há itens de entrega de valor entregues na janela para os filtros escolhidos. '
        + 'Ajuste jornada, time, versão ou sprint no cabeçalho.'));
      root.appendChild(a);
      return;
    }

    root.appendChild(kpis(d));
    root.appendChild(el('div', 'stitle', 'Lead Time'));
    const g = grafico(d);
    root.appendChild(g);
    g._draw();
    root.appendChild(tabelaPrincipal(d));
    root.appendChild(tabelaSecundaria(d));
    root.appendChild(diagnosticos(d));
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir o lead time.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
