<?php
/**
 * vw_velocity.php — Velocidade (Team Velocity Widget)
 * ─────────────────────────────────────────────────────
 * Estilo Jira Team Velocity: comprometido vs entregue por time (SP),
 * considerando a janela de sprints fechadas. Complementa com a
 * capacidade projetada (quanto o time podia assumir) por time.
 */
?>
<div id="vel-root">
  <div class="loading"><div class="spinner"></div> Carregando velocidade...</div>
</div>

<template id="vel-tpl">
  <div class="stitle">Velocidade dos Times</div>
  <div class="kpi-grid" id="vel-kpis"></div>

  <div class="stitle" style="margin-top:22px">Team Velocity <span style="text-transform:none;font-weight:400">· comprometido vs entregue (janela fechada)</span></div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Comprometido vs Entregue <span class="badge">Story Points por time</span></h3>
      <div class="cwrap xtall"><canvas id="vel-cvd"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Capacidade vs Realizado</div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Capacidade projetada vs SP entregue <span class="badge">por time</span></h3>
      <div class="cwrap xtall"><canvas id="vel-cap"></canvas></div>
    </div>
  </div>
</template>

<template id="vel-empty-tpl">
  <div class="alert alert-info">
    <strong>Sem dados de velocidade ainda.</strong><br>
    <span id="vel-empty-msg"></span>
  </div>
</template>

<script>
(function () {
  const { Api, Util, BASE } = window.App || {};
  const root = document.getElementById('vel-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  async function load() {
    try {
      const f = App.Filters.read();
      const params = {};
      if (f.jornada) params.jornada = f.jornada;
      if (f.sprint)  params.sprint  = f.sprint;

      const m = await Api.get('/metrics', params);
      if (!m || !m.data) {
        root.innerHTML = '';
        root.appendChild(document.getElementById('vel-empty-tpl').content.cloneNode(true));
        document.getElementById('vel-empty-msg').textContent = m && m.warning ? m.warning
          : 'Capture a baseline e aguarde o fechamento de sprints.';
        return;
      }

      root.innerHTML = '';
      root.appendChild(document.getElementById('vel-tpl').content.cloneNode(true));

      renderKpis(m.data);
      renderVelocity(m.data.committed_vs_delivered);
      await renderCapacity(m.data, params);
      renderWindowInfo(m);
    } catch (e) {
      Util.error(root, e.message);
    }
  }

  function renderWindowInfo(m) {
    if (!m.window || !m.window.length) return;
    const info = document.createElement('div');
    info.className = 'alert alert-info';
    info.style.marginBottom = '14px';
    info.innerHTML = `<strong>Janela:</strong> sprints fechadas <code>${m.window.join(', ')}</code>`;
    root.prepend(info);
  }

  function renderKpis(d) {
    const c = d.committed_vs_delivered || {};
    const committed = (c.committed||[]).reduce((a,b)=>a+(b||0),0);
    const delivered = (c.delivered||[]).reduce((a,b)=>a+(b||0),0);
    const nTimes = (c.labels||[]).length;
    const avgDeliv = nTimes ? (delivered/nTimes) : 0;
    const taxa = committed>0 ? (delivered/committed*100) : 0;

    const cards = [
      { label:'Velocidade Total', val: delivered,          sub:'SP entregues (janela)',   ac:'#10b981' },
      { label:'Comprometido',     val: committed,          sub:'SP planejados (janela)',  ac:'#6366f1' },
      { label:'Taxa de Entrega',  val: taxa.toFixed(1)+'%', sub:'entregue / comprometido', ac: taxa>=80?'#10b981':'#f59e0b' },
      { label:'Média por Time',   val: avgDeliv.toFixed(1), sub:`${nTimes} times`,         ac:'#22d3ee' },
    ];
    document.getElementById('vel-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}">
        <div class="kpi-label">${k.label}</div>
        <div class="kpi-val">${k.val}</div>
        <div class="kpi-sub">${k.sub}</div>
      </div>`).join('');
  }

  // Team Velocity: barras comprometido vs entregue por time
  function renderVelocity(c) {
    if (!c) return;
    Charts.make('vel-cvd', {
      type:'bar',
      data:{ labels:c.labels, datasets:[
        { label:'Comprometido', data:c.committed, backgroundColor:Charts.fill(0,.55), borderColor:Charts.color(0), borderWidth:1.5, borderRadius:4 },
        { label:'Entregue',     data:c.delivered, backgroundColor:Charts.color(2), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{position:'bottom'} },
        scales:{ y:{beginAtZero:true, title:{display:true,text:'Story Points'}}, x:{ticks:{maxRotation:60, minRotation:45}} } }
    });
  }

  // Capacidade projetada vs entregue — busca capacidade por time
  async function renderCapacity(d, params) {
    const labels = (d.committed_vs_delivered?.labels) || [];
    const delivered = (d.committed_vs_delivered?.delivered) || [];

    // Busca capacidade de cada time (em paralelo). Usa a sprint da janela.
    const sprint = params.sprint || (d && d.window ? null : null);
    const caps = await Promise.all(labels.map(async (team) => {
      try {
        const r = await Api.get('/capacity/plan', sprint ? { team, sprint } : { team });
        return r.result?.calculated_capacity ?? null;
      } catch { return null; }
    }));

    Charts.make('vel-cap', {
      type:'bar',
      data:{ labels, datasets:[
        { label:'Capacidade projetada', data:caps, backgroundColor:Charts.fill(5,.55), borderColor:Charts.color(5), borderWidth:1.5, borderRadius:4 },
        { label:'SP entregue',          data:delivered, backgroundColor:Charts.color(1), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{position:'bottom'},
          tooltip:{ callbacks:{ afterLabel:(ctx)=>{
            const cap=caps[ctx.dataIndex], del=delivered[ctx.dataIndex];
            if (cap && del!=null) { const u=(del/cap*100).toFixed(0); return `utilização: ${u}%`; }
            return '';
          }}}},
        scales:{ y:{beginAtZero:true, title:{display:true,text:'Story Points'}}, x:{ticks:{maxRotation:60, minRotation:45}} } }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
