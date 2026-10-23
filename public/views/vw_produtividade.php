<?php
/**
 * vw_produtividade.php — Produtividade
 * ──────────────────────────────────────
 * Produtividade (H/P) por time, capacidade projetada e comparação com o
 * entregue. Usa /settings (parâmetros por time) e /metrics (entregue).
 */
?>
<div id="prod-root">
  <div class="loading"><div class="spinner"></div> Carregando produtividade...</div>
</div>

<template id="prod-tpl">
  <div class="stitle">Produtividade e Capacidade</div>
  <div class="kpi-grid" id="prod-kpis"></div>

  <div class="stitle" style="margin-top:22px">Produtividade por Time <span style="text-transform:none;font-weight:400">· horas por ponto (menor = melhor)</span></div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Produtividade (H/P) <span class="badge">horas gastas por Story Point</span></h3>
      <div class="cwrap xtall"><canvas id="prod-hp"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Capacidade Projetada</div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Capacidade por Time <span class="badge">(dias × horas × devs) ÷ produtividade</span></h3>
      <div class="cwrap xtall"><canvas id="prod-cap"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Detalhamento</div>
  <div class="ccard">
    <div class="twrap">
      <table id="prod-table">
        <thead><tr>
          <th>Time</th><th>Devs</th><th>Horas/dev</th><th>Produtividade (H/P)</th><th>Capacidade (SP)</th><th>Entregue (SP)</th><th>Utilização</th>
        </tr></thead>
        <tbody id="prod-tbody"></tbody>
      </table>
    </div>
  </div>
</template>

<script>
(function () {
  const { Api, Util } = window.App || {};
  const root = document.getElementById('prod-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  async function load() {
    try {
      const f = App.Filters.read();

      // Parâmetros por time (devs/produtividade/horas) + entregue (métricas)
      const settings = await Api.get('/settings');
      const metricsParams = {};
      if (f.jornada) metricsParams.jornada = f.jornada;
      if (f.sprint)  metricsParams.sprint  = f.sprint;
      const m = await Api.get('/metrics', metricsParams).catch(()=>null);

      const teams = settings.capacity?.teams || {};
      const cal   = settings.capacity?.working_days_by_sprint || {};
      // Dias úteis: usa a sprint do filtro, senão a 1a do calendário
      const refSprint = f.sprint || Object.keys(cal)[0];
      const dias = Number(cal[refSprint]) || 8;

      // Entregue por time (da janela de métricas)
      const deliveredByTeam = {};
      if (m && m.data) {
        const lbl = m.data.committed_vs_delivered.labels;
        const del = m.data.committed_vs_delivered.delivered;
        lbl.forEach((t,i)=> deliveredByTeam[t] = del[i]);
      }

      render(teams, dias, deliveredByTeam, refSprint);
    } catch (e) {
      Util.error(root, e.message);
    }
  }

  function render(teams, dias, deliveredByTeam, refSprint) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('prod-tpl').content.cloneNode(true));

    // Considera só times com produtividade definida (mapeados)
    const ids = Object.keys(teams).filter(id => teams[id].productivity != null).sort();

    const rows = ids.map(id => {
      const t = teams[id];
      const devs = Number(t.dev_count)||0, horas = Number(t.productive_hours)||0, prod = Number(t.productivity)||0;
      const cap = prod>0 ? (dias*horas*devs)/prod : 0;
      const entregue = deliveredByTeam[id] ?? null;
      const util = (cap>0 && entregue!=null) ? (entregue/cap*100) : null;
      return { id, devs, horas, prod, cap:+cap.toFixed(1), entregue, util };
    });

    renderKpis(rows, refSprint);
    renderHp(rows);
    renderCap(rows);
    renderTable(rows);
  }

  function renderKpis(rows, refSprint) {
    const valid = rows.filter(r=>r.prod>0);
    const avgProd = valid.length ? valid.reduce((a,r)=>a+r.prod,0)/valid.length : 0;
    const totalCap = rows.reduce((a,r)=>a+r.cap,0);
    const totalEnt = rows.reduce((a,r)=>a+(r.entregue||0),0);
    const util = totalCap>0 ? (totalEnt/totalCap*100) : 0;

    const cards = [
      { label:'Produtividade Média', val: avgProd.toFixed(1), sub:'H/P (menor = melhor)', ac:'#22d3ee' },
      { label:'Capacidade Total',    val: totalCap.toFixed(0), sub:`sprint ${refSprint||'—'}`, ac:'#6366f1' },
      { label:'Entregue Total',      val: totalEnt.toFixed(0), sub:'SP na janela', ac:'#10b981' },
      { label:'Utilização',          val: util.toFixed(1)+'%', sub:'entregue / capacidade', ac: util>100?'#f43f5e':'#f59e0b' },
    ];
    document.getElementById('prod-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}">
        <div class="kpi-label">${k.label}</div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div>
      </div>`).join('');
  }

  function renderHp(rows) {
    const sorted = [...rows].sort((a,b)=>a.prod-b.prod); // menor H/P primeiro (melhor)
    Charts.make('prod-hp', {
      type:'bar',
      data:{ labels:sorted.map(r=>r.id), datasets:[{
        label:'H/P', data:sorted.map(r=>r.prod),
        backgroundColor:sorted.map((_,i)=>Charts.fill(1,.8)), borderColor:Charts.color(1), borderWidth:1.5, borderRadius:4,
      }]},
      options:{ responsive:true, maintainAspectRatio:false,
        plugins:{legend:{display:false}, tooltip:{callbacks:{label:ctx=>` ${ctx.parsed.y} h/ponto`}}},
        scales:{ y:{beginAtZero:true, title:{display:true,text:'horas por ponto'}}, x:{ticks:{maxRotation:60,minRotation:45}} } }
    });
  }

  function renderCap(rows) {
    Charts.make('prod-cap', {
      type:'bar',
      data:{ labels:rows.map(r=>r.id), datasets:[
        { label:'Capacidade', data:rows.map(r=>r.cap), backgroundColor:Charts.fill(0,.55), borderColor:Charts.color(0), borderWidth:1.5, borderRadius:4 },
        { label:'Entregue',   data:rows.map(r=>r.entregue), backgroundColor:Charts.color(2), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
        scales:{ y:{beginAtZero:true, title:{display:true,text:'Story Points'}}, x:{ticks:{maxRotation:60,minRotation:45}} } }
    });
  }

  function renderTable(rows) {
    document.getElementById('prod-tbody').innerHTML = rows.map(r=>`
      <tr>
        <td><span class="mono">${r.id}</span></td>
        <td style="text-align:center">${r.devs}</td>
        <td style="text-align:center">${r.horas}</td>
        <td style="text-align:center">${r.prod}</td>
        <td style="text-align:center;color:var(--indigo);font-weight:600">${r.cap}</td>
        <td style="text-align:center">${r.entregue ?? '—'}</td>
        <td style="text-align:center">${r.util!=null ? r.util.toFixed(0)+'%' : '—'}</td>
      </tr>`).join('');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
