<?php
/**
 * vw_cycle_time.php — Lead Time / Cycle Time
 * ────────────────────────────────────────────
 * Ciclos por fase (Ideação, Desenvolvimento, Homologação, Implantação) e
 * Lead Time / Customer Lead Time por tipo de item. Dados via /extract.
 * Usa MEDIANA (robusta a outliers) por padrão.
 */
?>
<div id="ct-root">
  <div class="loading"><div class="spinner"></div> Carregando lead time...</div>
</div>

<template id="ct-tpl">
  <div class="stitle">Tempos de Fluxo (dias)</div>
  <div class="kpi-grid" id="ct-kpis"></div>

  <div class="stitle" style="margin-top:22px">Cycle Time por Fase <span style="text-transform:none;font-weight:400">· mediana geral</span></div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Ciclo médio por fase <span class="badge">mediana (dias)</span></h3>
      <div class="cwrap tall"><canvas id="ct-fases"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Lead Time por tipo de item <span class="badge">mediana (dias)</span></h3>
      <div class="cwrap tall"><canvas id="ct-lt-tipo"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Ciclo de Desenvolvimento por Time</div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Desenvolvimento <span class="badge">mediana por time (dias)</span></h3>
      <div class="cwrap xtall"><canvas id="ct-dev-time"></canvas></div>
    </div>
  </div>
</template>

<script>
(function () {
  const { Api, Util } = window.App || {};
  const root = document.getElementById('ct-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  const median = arr => {
    const s = arr.filter(v=>v!=null && !isNaN(v)).map(Number).sort((a,b)=>a-b);
    if (!s.length) return null;
    const m = Math.floor(s.length/2);
    return s.length%2 ? s[m] : +((s[m-1]+s[m])/2).toFixed(1);
  };

  function filterItems(items) {
    const f = App.Filters.read();
    return items.filter(i => {
      if (f.jornada) { const mj=/^\[([^\]]+)\]/.exec(i.projeto||''); if (!mj || mj[1].trim()!==f.jornada) return false; }
      if (f.versao && (i.versoes_corrigidas||'').split(';')[0].trim()!==f.versao) return false;
      if (f.sprint && Util.shortSprint(i.sprint)!==f.sprint) return false;
      return true;
    });
  }

  async function load() {
    try {
      const ext = await Api.get('/extract');
      const items = filterItems(ext.items || []);
      render(items);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('ct-tpl').content.cloneNode(true));
    renderKpis(items);
    renderFases(items);
    renderLtTipo(items);
    renderDevTime(items);
  }

  function renderKpis(items) {
    const ideacao = median(items.map(i=>i.ciclo_ideacao));
    const dev = median(items.map(i=>i.ciclo_desenvolvimento));
    const hom = median(items.map(i=>i.ciclo_homologacao));
    const lt = median(items.map(i=>i.lead_time));
    const cards = [
      { label:'Lead Time (mediana)',   val: lt!=null?lt+'d':'—',      sub:'criação → produção', ac:'#f59e0b' },
      { label:'Ciclo Ideação',         val: ideacao!=null?ideacao+'d':'—', sub:'mediana',       ac:'#6366f1' },
      { label:'Ciclo Desenvolvimento', val: dev!=null?dev+'d':'—',    sub:'mediana',            ac:'#22d3ee' },
      { label:'Ciclo Homologação',     val: hom!=null?hom+'d':'—',    sub:'mediana',            ac:'#10b981' },
    ];
    document.getElementById('ct-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}"><div class="kpi-label">${k.label}</div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div></div>`).join('');
  }

  function renderFases(items) {
    const fases = ['Ideação','Desenvolvimento','Homologação','Implantação'];
    const vals = [
      median(items.map(i=>i.ciclo_ideacao)),
      median(items.map(i=>i.ciclo_desenvolvimento)),
      median(items.map(i=>i.ciclo_homologacao)),
      median(items.map(i=>i.ciclo_implantacao)),
    ];
    Charts.make('ct-fases', {
      type:'bar',
      data:{ labels:fases, datasets:[{ label:'dias (mediana)', data:vals,
        backgroundColor:fases.map((_,i)=>Charts.fill(i,.8)), borderColor:fases.map((_,i)=>Charts.color(i)), borderWidth:1.5, borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, title:{display:true,text:'dias'}}} }
    });
  }

  function renderLtTipo(items) {
    const tipos = [...new Set(items.map(i=>i.tipo_item).filter(Boolean))];
    const g = {}; tipos.forEach(t => g[t] = median(items.filter(i=>i.tipo_item===t).map(i=>i.lead_time)));
    const keys = tipos.filter(t=>g[t]!=null).sort((a,b)=>g[b]-g[a]);
    Charts.make('ct-lt-tipo', {
      type:'bar',
      data:{ labels:keys, datasets:[{ label:'Lead Time (mediana)', data:keys.map(k=>g[k]),
        backgroundColor:keys.map((_,i)=>Charts.fill(i+2,.8)), borderColor:keys.map((_,i)=>Charts.color(i+2)), borderWidth:1.5, borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, title:{display:true,text:'dias'}}} }
    });
  }

  function renderDevTime(items) {
    const byTeam = {};
    items.forEach(i => {
      const m=/^\[([^\]]+)\]\s*(.+)?/.exec(i.projeto||''); const team = i.projeto ? (i.projeto.replace(/^\[.*?\]\s*/,'')||i.projeto) : '(outros)';
      (byTeam[team]=byTeam[team]||[]).push(i.ciclo_desenvolvimento);
    });
    const keys = Object.keys(byTeam).map(t=>({t, v:median(byTeam[t])})).filter(x=>x.v!=null).sort((a,b)=>b.v-a.v);
    Charts.make('ct-dev-time', {
      type:'bar',
      data:{ labels:keys.map(x=>x.t), datasets:[{ label:'Ciclo Dev (mediana)', data:keys.map(x=>x.v),
        backgroundColor:keys.map((_,i)=>Charts.fill(1,.8)), borderColor:Charts.color(1), borderWidth:1.5, borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, title:{display:true,text:'dias'}}} }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
