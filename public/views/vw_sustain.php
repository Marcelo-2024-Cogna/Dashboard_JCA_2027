<?php
/**
 * vw_sustain.php — Pontos de Sustentação
 * ─────────────────────────────────────────
 * Esforço em sustentação (Incidente, Solicitação de Serviço, Suporte,
 * Sustentação) vs entrega de valor. % de horas gastas em chamados.
 * Dados via /extract.
 */
?>
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
      render(items);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('su-tpl').content.cloneNode(true));
    renderKpis(items);
    renderHoras(items);
    renderTipos(items);
    renderPct(items);
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
