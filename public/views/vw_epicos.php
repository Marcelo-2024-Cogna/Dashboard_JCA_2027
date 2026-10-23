<?php
/**
 * vw_epicos.php — Status dos Épicos
 * ───────────────────────────────────
 * Agrupa itens por épico (campo "pai"), calculando progresso (SP entregue /
 * SP total) por épico. Dados via /extract.
 */
?>
<div id="ep-root">
  <div class="loading"><div class="spinner"></div> Carregando épicos...</div>
</div>

<template id="ep-tpl">
  <div class="stitle">Visão dos Épicos</div>
  <div class="kpi-grid" id="ep-kpis"></div>

  <div class="stitle" style="margin-top:22px">Distribuição</div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Top Épicos por Story Points <span class="badge">total planejado</span></h3>
      <div class="cwrap tall"><canvas id="ep-sp"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Progresso dos Épicos <span class="badge">faixa de completude</span></h3>
      <div class="cwrap tall"><canvas id="ep-prog"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Detalhamento por Épico</div>
  <div class="ccard">
    <div class="twrap" style="max-height:480px;overflow-y:auto">
      <table id="ep-table">
        <thead><tr>
          <th>Épico</th><th>Itens</th><th>SP Total</th><th>SP Entregue</th><th>Completude</th>
        </tr></thead>
        <tbody id="ep-tbody"></tbody>
      </table>
    </div>
  </div>
</template>

<script>
(function () {
  const { Api, Util } = window.App || {};
  const root = document.getElementById('ep-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  const DONE = ['Aceito','Em produção','Pendente de Produção'];

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
      const items = filterItems(ext.items || []).filter(i=>i.pai); // só itens vinculados a épico
      render(items);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('ep-tpl').content.cloneNode(true));

    // Agrupa por épico (pai)
    const epics = {};
    items.forEach(i => {
      const k = i.pai;
      if (!epics[k]) epics[k] = { key:k, items:0, sp:0, spDone:0 };
      epics[k].items++;
      const sp = Number(i.story_points)||0;
      epics[k].sp += sp;
      if (DONE.includes(i.status)) epics[k].spDone += sp;
    });
    const list = Object.values(epics).map(e => ({
      ...e, completude: e.sp>0 ? +(e.spDone/e.sp*100).toFixed(0) : 0
    }));

    renderKpis(list);
    renderSp(list);
    renderProg(list);
    renderTable(list);
  }

  function renderKpis(list) {
    const total = list.length;
    const concluidos = list.filter(e=>e.completude>=100).length;
    const emRisco = list.filter(e=>e.completude>0 && e.completude<50).length;
    const naoIniciados = list.filter(e=>e.completude===0).length;
    const cards = [
      { label:'Épicos (com itens)', val: total,        sub:'na seleção',        ac:'#6366f1' },
      { label:'Concluídos',         val: concluidos,   sub:'100% entregue',     ac:'#10b981' },
      { label:'Em Risco',           val: emRisco,      sub:'< 50% completo',    ac:'#f97316' },
      { label:'Não Iniciados',      val: naoIniciados, sub:'0% entregue',       ac:'#7c8aaa' },
    ];
    document.getElementById('ep-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}"><div class="kpi-label">${k.label}</div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div></div>`).join('');
  }

  function renderSp(list) {
    const top = [...list].sort((a,b)=>b.sp-a.sp).slice(0,12);
    Charts.make('ep-sp', {
      type:'bar',
      data:{ labels:top.map(e=>e.key), datasets:[
        { label:'SP Total', data:top.map(e=>e.sp), backgroundColor:Charts.fill(0,.5), borderColor:Charts.color(0), borderWidth:1.5, borderRadius:4 },
        { label:'SP Entregue', data:top.map(e=>e.spDone), backgroundColor:Charts.color(2), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{position:'bottom'}}, scales:{x:{beginAtZero:true, title:{display:true,text:'Story Points'}}} }
    });
  }

  function renderProg(list) {
    const faixas = { 'Concluído (100%)':0, 'Avançado (50-99%)':0, 'Em risco (1-49%)':0, 'Não iniciado (0%)':0 };
    list.forEach(e=>{
      if (e.completude>=100) faixas['Concluído (100%)']++;
      else if (e.completude>=50) faixas['Avançado (50-99%)']++;
      else if (e.completude>0) faixas['Em risco (1-49%)']++;
      else faixas['Não iniciado (0%)']++;
    });
    const keys = Object.keys(faixas);
    const colors = [Charts.color(2), Charts.color(1), Charts.color(6), '#7c8aaa'];
    Charts.make('ep-prog', {
      type:'doughnut',
      data:{ labels:keys, datasets:[{ data:keys.map(k=>faixas[k]), backgroundColor:colors, borderColor:'#1a1d27', borderWidth:3, hoverOffset:6 }]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right'}}, cutout:'58%' }
    });
  }

  function barColor(p){ return p>=100?'var(--green)':p>=50?'var(--cyan)':p>0?'var(--orange)':'var(--muted)'; }

  function renderTable(list) {
    const sorted = [...list].sort((a,b)=>b.sp-a.sp);
    document.getElementById('ep-tbody').innerHTML = sorted.map(e=>`
      <tr>
        <td><span class="mono">${e.key}</span></td>
        <td style="text-align:center">${e.items}</td>
        <td style="text-align:center">${e.sp}</td>
        <td style="text-align:center">${e.spDone}</td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;background:var(--surface2);border-radius:4px;height:6px;overflow:hidden;min-width:70px">
              <div style="height:100%;width:${Math.min(e.completude,100)}%;background:${barColor(e.completude)}"></div>
            </div>
            <span style="font-size:.72rem;color:var(--muted);min-width:36px">${e.completude}%</span>
          </div>
        </td>
      </tr>`).join('');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
