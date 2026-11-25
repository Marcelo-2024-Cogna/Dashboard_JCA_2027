<?php
/**
 * vw_sustain.php — Pontos de Sustentação
 * ─────────────────────────────────────────
 * Esforço em sustentação (Incidente, Solicitação de Serviço, Suporte,
 * Sustentação) vs entrega de valor. % de horas gastas em chamados.
 * Dados via /extract.
 */
?>
<div class="alert alert-warn" role="status">Aviso: Módulo Pendente!</div>
<div id="su-root">
  <div class="loading"><div class="spinner"></div> Carregando sustentação...</div>
</div>

<template id="su-tpl">
  <div class="stitle">Esforço em Sustentação</div>
  <div class="kpi-grid" id="su-kpis"></div>

  <div class="stitle" style="margin-top:22px">Valor vs Sustentação</div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Horas: Valor vs Sustentação <span class="badge">por time</span></h3>
      <div class="cwrap tall"><canvas id="su-horas"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Tipos de item de Sustentação <span class="badge">quantidade</span></h3>
      <div class="cwrap tall"><canvas id="su-tipos"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">% de Esforço em Chamados por Time</div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>% de horas em sustentação <span class="badge">sustentação / total</span></h3>
      <div class="cwrap xtall"><canvas id="su-pct"></canvas></div>
    </div>
  </div>
</template>

<script>
(function () {
  const { Api, Util } = window.App || {};
  const root = document.getElementById('su-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  // Tipos de sustentação (mesma definição do backend)
  const SUST = ['Incidente','Solicitação de Serviço','Suporte','Sustentação'];
  const MET = <?= json_encode(\App\Services\MetodologiaService::paraTela('sustentacao'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

  // Data da extração e idade do cache de /extract, para "Carga dos dados" da legenda.
  function cargaTxt(ext) {
    if (!ext || ext._no_cache) return 'Sem dados extraídos do Jira ainda.';
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(ext.extracted_at || '');
    const quando = m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : 'data da extração não informada';
    const idade = typeof ext._cache_age_seconds === 'number' ? ' · cache com ' + Math.round(ext._cache_age_seconds / 60) + ' min' : '';
    return 'Extraído do Jira em ' + quando + idade + (ext._cache_stale ? ' · cache vencido' : '') + '.';
  }

  // Botões "Como é calculado" por DOM, depois do render (sem novo innerHTML).
  function anexarLegendas(ext) {
    const x = { extras: { carga: cargaTxt(ext) } };
    const op = (rotulo, mini) => Object.assign({ rotulo, mini }, x);
    const lbl = document.querySelectorAll('#su-kpis .kpi-label');
    const noKpi = (n, btn) => { if (lbl[n]) { lbl[n].appendChild(document.createTextNode(' ')); lbl[n].appendChild(btn); } };
    noKpi(0, window.App.Legenda.botao('su_itens', MET, op('Itens de Sustentação', true)));
    noKpi(1, window.App.Legenda.botao('su_horas_sust', MET, op('Horas em Sustentação', true)));
    noKpi(2, window.App.Legenda.botao('su_pct', MET, op('% do Esforço Total', true)));
    noKpi(3, window.App.Legenda.botao('su_horas_valor', MET, op('Horas em Valor', true)));
    const tit = id => { const c = document.getElementById(id); return c && c.closest('.ccard') ? c.closest('.ccard').querySelector('h3') : null; };
    const hHoras = tit('su-horas'), hTipos = tit('su-tipos'), hPct = tit('su-pct');
    if (hHoras) { hHoras.appendChild(document.createTextNode(' ')); hHoras.appendChild(window.App.Legenda.botao('su_grafico_horas', MET, op('Horas: Valor vs Sustentação', false))); }
    if (hTipos) { hTipos.appendChild(document.createTextNode(' ')); hTipos.appendChild(window.App.Legenda.botao('su_grafico_tipos', MET, op('Tipos de item de Sustentação', false))); }
    if (hPct) { hPct.appendChild(document.createTextNode(' ')); hPct.appendChild(window.App.Legenda.botao('su_grafico_pct', MET, op('% de horas em sustentação', false))); }
  }

  function filterItems(items) {
    const f = App.Filters.read();
    return items.filter(i => {
      if (f.jornada) { const mj=/^\[([^\]]+)\]/.exec(i.projeto||''); if (!mj || mj[1].trim()!==f.jornada) return false; }
      if (f.versao && (i.versoes_corrigidas||'').split(';')[0].trim()!==f.versao) return false;
      if (f.sprint && Util.shortSprint(i.sprint)!==f.sprint) return false;
      return true;
    });
  }

  const teamOf = i => i.projeto ? (i.projeto.replace(/^\[.*?\]\s*/,'')||i.projeto) : '(outros)';
  const isSust = i => SUST.includes(i.tipo_item);

  async function load() {
    try {
      const ext = await Api.get('/extract');
      const items = filterItems(ext.items || []);
      render(items, ext);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items, ext) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('su-tpl').content.cloneNode(true));
    renderKpis(items);
    renderHoras(items);
    renderTipos(items);
    renderPct(items);
    anexarLegendas(ext);
  }

  function renderKpis(items) {
    const sustItems = items.filter(isSust);
    const hSust = sustItems.reduce((a,i)=>a+(Number(i.tempo_gasto_horas)||0),0);
    const hTotal = items.reduce((a,i)=>a+(Number(i.tempo_gasto_horas)||0),0);
    const pct = hTotal>0 ? (hSust/hTotal*100) : 0;
    const cards = [
      { label:'Itens de Sustentação', val: sustItems.length, sub:'incidentes, SS, suporte', ac:'#f97316' },
      { label:'Horas em Sustentação', val: hSust.toFixed(0),  sub:'tempo gasto',            ac:'#f43f5e' },
      { label:'% do Esforço Total',   val: pct.toFixed(1)+'%', sub:'sustentação / total',    ac: pct>30?'#f43f5e':'#f59e0b' },
      { label:'Horas em Valor',       val: (hTotal-hSust).toFixed(0), sub:'entrega de valor',  ac:'#10b981' },
    ];
    document.getElementById('su-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}"><div class="kpi-label">${k.label}</div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div></div>`).join('');
  }

  // Horas: valor vs sustentação por time (barras empilhadas)
  function renderHoras(items) {
    const byTeam = {};
    items.forEach(i => {
      const t = teamOf(i);
      if (!byTeam[t]) byTeam[t] = { valor:0, sust:0 };
      const h = Number(i.tempo_gasto_horas)||0;
      if (isSust(i)) byTeam[t].sust += h; else byTeam[t].valor += h;
    });
    const keys = Object.keys(byTeam).filter(t => byTeam[t].valor+byTeam[t].sust > 0)
                                    .sort((a,b)=>(byTeam[b].valor+byTeam[b].sust)-(byTeam[a].valor+byTeam[a].sust));
    Charts.make('su-horas', {
      type:'bar',
      data:{ labels:keys, datasets:[
        { label:'Valor', data:keys.map(t=>+byTeam[t].valor.toFixed(0)), backgroundColor:Charts.color(2), borderRadius:3, stack:'h' },
        { label:'Sustentação', data:keys.map(t=>+byTeam[t].sust.toFixed(0)), backgroundColor:Charts.color(4), borderRadius:3, stack:'h' },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
        scales:{ x:{stacked:true, ticks:{maxRotation:60,minRotation:45}}, y:{stacked:true, beginAtZero:true, title:{display:true,text:'horas'}} } }
    });
  }

  function renderTipos(items) {
    const g = {};
    items.filter(isSust).forEach(i=>{ g[i.tipo_item]=(g[i.tipo_item]||0)+1; });
    const keys = Object.keys(g).sort((a,b)=>g[b]-g[a]);
    Charts.make('su-tipos', {
      type:'doughnut',
      data:{ labels:keys, datasets:[{ data:keys.map(k=>g[k]), backgroundColor:keys.map((_,i)=>Charts.color(i+3)), borderColor:'#1a1d27', borderWidth:3, hoverOffset:6 }]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right'}}, cutout:'58%' }
    });
  }

  // % de horas em sustentação por time
  function renderPct(items) {
    const byTeam = {};
    items.forEach(i => {
      const t = teamOf(i);
      if (!byTeam[t]) byTeam[t] = { sust:0, total:0 };
      const h = Number(i.tempo_gasto_horas)||0;
      byTeam[t].total += h;
      if (isSust(i)) byTeam[t].sust += h;
    });
    const rows = Object.keys(byTeam).filter(t=>byTeam[t].total>0)
      .map(t=>({ t, pct:+(byTeam[t].sust/byTeam[t].total*100).toFixed(1) }))
      .sort((a,b)=>b.pct-a.pct);
    Charts.make('su-pct', {
      type:'bar',
      data:{ labels:rows.map(r=>r.t), datasets:[{ label:'% em sustentação', data:rows.map(r=>r.pct),
        backgroundColor:rows.map(r=> r.pct>30?Charts.color(4):(r.pct>15?Charts.color(3):Charts.color(2))), borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
        plugins:{legend:{display:false}, tooltip:{callbacks:{label:ctx=>` ${ctx.parsed.x}% das horas`}}},
        scales:{ x:{beginAtZero:true, max:100, title:{display:true,text:'% das horas em sustentação'}} } }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
