<?php
/**
 * vw_cycle_time.php — Lead Time / Cycle Time
 * ────────────────────────────────────────────
 * Ciclos por fase (Ideação, Desenvolvimento, Homologação, Implantação),
 * Lead Time por tipo de item (só entrega de valor) e ciclos por time
 * (Desenvolvimento, Homologação, Implantação). Dados via /extract.
 * Usa MEDIANA. Respeita filtros globais (jornada/time/versão/sprint).
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
      <h3>Lead Time por tipo de item <span class="badge">mediana (dias) · só entrega de valor</span></h3>
      <div class="cwrap tall"><canvas id="ct-lt-tipo"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Ciclos por Time <span style="text-transform:none;font-weight:400">· mediana (dias)</span></div>
  <div class="cgrid c3">
    <div class="ccard">
      <h3>Desenvolvimento <span class="badge">por time</span></h3>
      <div class="cwrap tall"><canvas id="ct-dev-time"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Homologação <span class="badge">por time</span></h3>
      <div class="cwrap tall"><canvas id="ct-hom-time"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Implantação <span class="badge">por time</span></h3>
      <div class="cwrap tall"><canvas id="ct-imp-time"></canvas></div>
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

  async function load() {
    try {
      const ext = await Api.get('/extract');
      const items = Util.applyFilters(ext.items || []);
      render(items);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('ct-tpl').content.cloneNode(true));
    renderKpis(items);
    renderFases(items);
    renderLtTipo(items);
    renderCicloPorTime(items, 'ciclo_desenvolvimento', 'ct-dev-time', 1);
    renderCicloPorTime(items, 'ciclo_homologacao',     'ct-hom-time', 2);
    renderCicloPorTime(items, 'ciclo_implantacao',     'ct-imp-time', 3);
  }

  function renderKpis(items) {
    const ideacao = median(items.map(i=>i.ciclo_ideacao));
    const dev = median(items.map(i=>i.ciclo_desenvolvimento));
    const hom = median(items.map(i=>i.ciclo_homologacao));
    const lt = median(items.map(i=>i.lead_time));
    const cards = [
      // Ajuste: Lead Time até a Implantação (não "produção").
      { label:'Lead Time (mediana)',   val: lt!=null?lt+'d':'—',      sub:'criação → implantação', ac:'#f59e0b' },
      { label:'Ciclo Ideação',         val: ideacao!=null?ideacao+'d':'—', sub:'mediana',          ac:'#6366f1' },
      { label:'Ciclo Desenvolvimento', val: dev!=null?dev+'d':'—',    sub:'mediana',               ac:'#22d3ee' },
      { label:'Ciclo Homologação',     val: hom!=null?hom+'d':'—',    sub:'mediana',               ac:'#10b981' },
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

  // Lead Time por tipo — SÓ entrega de valor (exclui sustentação/liderança/etc).
  function renderLtTipo(items) {
    const valor = items.filter(i => Util.isValueType(i));
    const tipos = [...new Set(valor.map(i=>i.tipo_item).filter(Boolean))];
    const g = {}; tipos.forEach(t => g[t] = median(valor.filter(i=>i.tipo_item===t).map(i=>i.lead_time)));
    const keys = tipos.filter(t=>g[t]!=null).sort((a,b)=>g[b]-g[a]);
    Charts.make('ct-lt-tipo', {
      type:'bar',
      data:{ labels:keys, datasets:[{ label:'Lead Time (mediana)', data:keys.map(k=>g[k]),
        backgroundColor:keys.map((_,i)=>Charts.fill(i+2,.8)), borderColor:keys.map((_,i)=>Charts.color(i+2)), borderWidth:1.5, borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, title:{display:true,text:'dias'}}} }
    });
  }

  // Ciclo (dev/hom/imp) por time — mediana, barras horizontais.
  function renderCicloPorTime(items, field, canvasId, colorIdx) {
    const byTeam = {};
    items.forEach(i => {
      const team = Util.teamOf(i) || '(outros)';
      (byTeam[team] = byTeam[team] || []).push(i[field]);
    });
    const rows = Object.keys(byTeam)
      .map(t => ({ t, v: median(byTeam[t]) }))
      .filter(x => x.v != null)
      .sort((a,b) => b.v - a.v);
    Charts.make(canvasId, {
      type:'bar',
      data:{ labels:rows.map(x=>x.t), datasets:[{ label:'dias (mediana)', data:rows.map(x=>x.v),
        backgroundColor:rows.map(()=>Charts.fill(colorIdx,.8)), borderColor:Charts.color(colorIdx), borderWidth:1.5, borderRadius:4 }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, title:{display:true,text:'dias'}}} }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
