<?php
/**
 * vw_overview.php — Visão Geral
 * KPIs consolidados + distribuições. Dados via fetch (/metrics, /extract).
 */
?>
<div class="stitle">Indicadores da PI</div>
<div class="kpi-grid" id="ov-kpis">
  <div class="loading"><div class="spinner"></div> Carregando indicadores...</div>
</div>

<div class="stitle" style="margin-top:22px">Distribuições</div>
<div class="cgrid c2">
  <div class="ccard">
    <h3>Status dos Itens <span class="badge">quantidade</span></h3>
    <div class="cwrap"><canvas id="ov-status"></canvas></div>
  </div>
  <div class="ccard">
    <h3>Story Points por Jornada <span class="badge">entregue</span></h3>
    <div class="cwrap"><canvas id="ov-jornada"></canvas></div>
  </div>
</div>

<script>
(function () {
  async function load() {
    const kpis = document.getElementById('ov-kpis');
    const { Api, Util } = window.App || {};
    if (!Api) { kpis.innerHTML = '<div class="alert alert-error">Erro: núcleo JS (app.js) não carregou.</div>'; return; }
    try {
      // /metrics é leve e rápido; /extract pode ser grande (cacheado no cliente).
      const m = await Api.get('/metrics').catch(() => null);
      let items = [];
      try { const ext = await Api.get('/extract'); items = ext.items || []; }
      catch (e) { Util.error(kpis, 'Falha ao carregar itens do Jira: ' + e.message); return; }

      renderKpis(kpis, m, items);
      renderStatus(items);
      renderJornada(items);
    } catch (e) {
      Util.error(kpis, e.message);
    }
  }

  function renderKpis(el, m, items) {
    const total = items.length;
    const done = items.filter(i => ['Aceito','Em produção','Pendente de Produção'].includes(i.status));
    const sp = items.reduce((a,i)=>a+(Number(i.story_points)||0),0);
    const spDone = done.reduce((a,i)=>a+(Number(i.story_points)||0),0);

    // Previsibilidade média (se /metrics trouxe dados)
    let prev = '—';
    if (m && m.data && m.data.predictability && m.data.predictability.pct) {
      const arr = m.data.predictability.pct.filter(v => v != null);
      if (arr.length) prev = (arr.reduce((a,b)=>a+b,0)/arr.length).toFixed(1) + '%';
    }

    const cards = [
      { label:'Previsibilidade (média)', val: prev,                 sub:'entregue / planejado', ac:'#10b981' },
      { label:'Itens na PI',            val: total,                 sub:'total extraído',        ac:'#6366f1' },
      { label:'Story Points',           val: `${spDone}/${sp}`,     sub:'entregue / total',      ac:'#22d3ee' },
      { label:'Itens Entregues',        val: done.length,           sub:'status Done',           ac:'#f59e0b' },
    ];
    el.innerHTML = cards.map(k => `
      <div class="kpi" style="--ac:${k.ac}">
        <div class="kpi-label">${k.label}</div>
        <div class="kpi-val">${k.val}</div>
        <div class="kpi-sub">${k.sub}</div>
      </div>`).join('');
  }

  function groupBy(arr, keyFn) {
    return arr.reduce((acc,x)=>{ const k=keyFn(x)||'(vazio)'; (acc[k]=acc[k]||[]).push(x); return acc; }, {});
  }

  function renderStatus(items) {
    const g = groupBy(items, i => i.status);
    const keys = Object.keys(g).sort((a,b)=>g[b].length-g[a].length);
    Charts.make('ov-status', {
      type:'doughnut',
      data:{ labels:keys, datasets:[{ data:keys.map(k=>g[k].length),
        backgroundColor:keys.map((_,i)=>Charts.color(i)), borderColor:'#1a1d27', borderWidth:3, hoverOffset:6 }] },
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right'}}, cutout:'58%' }
    });
  }

  function renderJornada(items) {
    const g = groupBy(items, i => { const m=/^\[([^\]]+)\]/.exec(i.projeto||''); return m?m[1].trim():'(outros)'; });
    const keys = Object.keys(g);
    const sp = keys.map(k => g[k].filter(i=>['Aceito','Em produção','Pendente de Produção'].includes(i.status))
                                 .reduce((a,i)=>a+(Number(i.story_points)||0),0));
    Charts.make('ov-jornada', {
      type:'bar',
      data:{ labels:keys, datasets:[{ label:'SP entregue', data:sp,
        backgroundColor:keys.map((_,i)=>Charts.fill(i,.8)), borderColor:keys.map((_,i)=>Charts.color(i)), borderWidth:1.5, borderRadius:4 }] },
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}} }
    });
  }

  // Roda já se o DOM estiver pronto; senão, espera.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
</script>
