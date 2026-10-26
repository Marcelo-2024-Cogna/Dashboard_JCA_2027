<?php
/**
 * vw_plan_real.php — Planejado x Realizado + Previsibilidade + Scope Creep
 * ──────────────────────────────────────────────────────────────────────────
 * Visão-destaque. Consome GET /metrics (committed/delivered/previsibilidade/
 * scope creep). Se não houver baseline capturada, exibe call-to-action.
 *
 * Fórmula da Previsibilidade:
 *   (Story Points entregues / Story Point Estimate na Baseline) × 100
 */
?>
<div id="pr-root">
  <div class="loading"><div class="spinner"></div> Carregando métricas...</div>
</div>

<!-- Template do estado "sem baseline" -->
<template id="pr-empty-tpl">
  <div class="alert alert-info">
    <strong>Baseline ainda não capturada para esta PI.</strong><br>
    O Planejado x Realizado compara a <em>baseline congelada</em> (início da PI)
    com o estado atual. Capture a baseline para habilitar os indicadores.
    <div style="margin-top:12px">
      <button class="btn btn-primary" id="pr-capture">📸 Capturar Baseline da PI</button>
      <span id="pr-capture-msg" style="font-size:.78rem;color:var(--muted);margin-left:10px"></span>
    </div>
  </div>
</template>

<!-- Template dos gráficos (estado com dados) -->
<template id="pr-charts-tpl">
  <div class="stitle">Resumo da PI</div>
  <div class="kpi-grid" id="pr-kpis"></div>

  <div class="stitle" style="margin-top:22px">Planejado x Realizado</div>
  <div class="cgrid c1">
    <div class="ccard">
      <h3>Committed vs Delivered <span class="badge">Story Points por time</span></h3>
      <div class="cwrap tall"><canvas id="pr-cvd"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Taxa de Previsibilidade</div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Previsibilidade por Time <span class="badge">% · meta 80%</span></h3>
      <div class="cwrap tall"><canvas id="pr-prev"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Previsibilidade Geral <span class="badge">média da PI</span></h3>
      <div class="cwrap tall"><canvas id="pr-gauge"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Mudança de Escopo (Scope Creep)</div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Adicionado vs Removido <span class="badge">SP pós-baseline</span></h3>
      <div class="cwrap tall"><canvas id="pr-scope"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Variação de Escopo <span class="badge">% sobre o committed</span></h3>
      <div class="cwrap tall"><canvas id="pr-variation"></canvas></div>
    </div>
  </div>
</template>

<script>
(function () {
  const { Api, Util, BASE } = window.App || {};
  const root = document.getElementById('pr-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  const DONE = ['Aceito','Em produção','Pendente de Produção'];

  async function load() {
    try {
      // Passa os filtros globais (jornada/time/sprint) ao /metrics para
      // recortar a janela de sprints fechadas, a jornada e o time.
      const f = App.Filters.read();
      const params = {};
      if (f.jornada) params.jornada = f.jornada;
      if (f.time)    params.time    = f.time;
      if (f.sprint)  params.sprint  = f.sprint;

      const m = await Api.get('/metrics', params);
      if (!m || !m.data) { renderEmpty(m && m.warning); return; }
      renderCharts(m.data);
      // Mostra qual janela está sendo considerada (transparência), após montar os gráficos
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
    info.innerHTML = `<strong>Janela considerada:</strong> sprints fechadas `
      + `<code>${m.window.join(', ')}</code>`
      + (m.closing_sprint ? ` · fechamento: <code>${m.closing_sprint}</code>` : '')
      + `. Itens de sprints futuras não entram no cálculo (crescem conforme a PI avança).`;
    root.prepend(info);
  }

  // ── Estado vazio: call-to-action para capturar baseline ───────────────
  function renderEmpty(warning) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('pr-empty-tpl').content.cloneNode(true));
    const btn = document.getElementById('pr-capture');
    const msg = document.getElementById('pr-capture-msg');
    if (warning) msg.textContent = warning;

    btn.addEventListener('click', async () => {
      if (!confirm('Capturar a baseline congela o estado atual como "início da PI". '
        + 'A extração dos projetos pode levar 1-2 min. Continuar?')) return;
      btn.disabled = true; btn.classList.add('btn-busy');
      btn.textContent = '📸 Capturando (pode levar 1-2 min)...';
      try {
        const r = await fetch(`${BASE}/baseline/capture`, { headers:{'Accept':'application/json'} }).then(x=>x.json());
        if (r.success) {
          msg.textContent = `✓ Baseline capturada: ${r.teams} times, ${r.snapshots?.reduce((a,s)=>a+s.total_items,0) ?? ''} itens.`;
          setTimeout(() => location.reload(), 1200);
        } else if (r.blocked) {
          msg.textContent = r.message; // já existe baseline
          btn.disabled = false; btn.classList.remove('btn-busy'); btn.textContent = '📸 Capturar Baseline da PI';
        } else {
          throw new Error(r.error || 'erro');
        }
      } catch (e) {
        msg.textContent = 'Falha: ' + e.message;
        btn.disabled = false; btn.classList.remove('btn-busy'); btn.textContent = '📸 Capturar Baseline da PI';
      }
    });
  }

  // ── Estado com dados: renderiza os gráficos ───────────────────────────
  function renderCharts(d) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('pr-charts-tpl').content.cloneNode(true));

    renderKpis(d);
    renderCvd(d.committed_vs_delivered);
    renderPrev(d.predictability);
    renderGauge(d.predictability);
    renderScope(d.scope_creep);
    renderVariation(d.scope_creep);
  }

  function renderKpis(d) {
    const cvd = d.committed_vs_delivered || {};
    const committed = (cvd.committed || []).reduce((a,b)=>a+(b||0),0);
    const delivered = (cvd.delivered || []).reduce((a,b)=>a+(b||0),0);
    const prevArr = (d.predictability?.pct || []).filter(v=>v!=null);
    const prevAvg = prevArr.length ? (prevArr.reduce((a,b)=>a+b,0)/prevArr.length) : 0;
    const addSp  = (d.scope_creep?.scope_add || []).reduce((a,b)=>a+(b||0),0);
    const meta   = d.predictability?.meta ?? 80;

    const cards = [
      { label:'Previsibilidade (média)', val: prevAvg.toFixed(1)+'%', sub:`meta ${meta}%`, ac: prevAvg>=meta ? '#10b981' : '#f59e0b' },
      { label:'Committed (SP)',          val: committed,              sub:'planejado na baseline', ac:'#6366f1' },
      { label:'Delivered (SP)',          val: delivered,              sub:'entregue do planejado', ac:'#22d3ee' },
      { label:'Scope Add (SP)',          val: addSp,                  sub:'adicionado pós-baseline', ac:'#f97316' },
    ];
    document.getElementById('pr-kpis').innerHTML = cards.map(k=>`
      <div class="kpi" style="--ac:${k.ac}">
        <div class="kpi-label">${k.label}</div>
        <div class="kpi-val">${k.val}</div>
        <div class="kpi-sub">${k.sub}</div>
      </div>`).join('');
  }

  // Committed vs Delivered (barras agrupadas por time)
  function renderCvd(c) {
    if (!c) return;
    Charts.make('pr-cvd', {
      type:'bar',
      data:{ labels:c.labels, datasets:[
        { label:'Committed (baseline)', data:c.committed, backgroundColor:Charts.fill(0,.55), borderColor:Charts.color(0), borderWidth:1.5, borderRadius:4 },
        { label:'Delivered (entregue)', data:c.delivered, backgroundColor:Charts.color(2), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
        scales:{ y:{beginAtZero:true, title:{display:true,text:'Story Points'}}, x:{ticks:{maxRotation:30}} } }
    });
  }

  // Previsibilidade por time (barras com cor condicional à meta)
  function renderPrev(p) {
    if (!p) return;
    const meta = p.meta ?? 80;
    Charts.make('pr-prev', {
      type:'bar',
      data:{ labels:p.labels, datasets:[{
        label:'Previsibilidade %', data:p.pct,
        backgroundColor:(p.pct||[]).map(v => v>=meta ? Charts.color(2) : (v>=meta*0.75 ? Charts.color(3) : Charts.color(4))),
        borderRadius:4,
      }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
        plugins:{ legend:{display:false},
          annotation:undefined,
          tooltip:{ callbacks:{ label:ctx=>` ${ctx.parsed.x}% (meta ${meta}%)` } } },
        scales:{ x:{beginAtZero:true, max:120, title:{display:true,text:'%'},
          grid:{ color:ctx=>ctx.tick.value===meta?'#f59e0b':'#2e3349' } } } }
    });
  }

  // Gauge de previsibilidade geral (doughnut semicircular)
  function renderGauge(p) {
    if (!p) return;
    const arr = (p.pct||[]).filter(v=>v!=null);
    const avg = arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : 0;
    const meta = p.meta ?? 80;
    const val = Math.min(avg, 100);
    const color = avg>=meta ? Charts.color(2) : (avg>=meta*0.75 ? Charts.color(3) : Charts.color(4));
    Charts.make('pr-gauge', {
      type:'doughnut',
      data:{ labels:['Previsibilidade','Restante'], datasets:[{
        data:[val, Math.max(0,100-val)],
        backgroundColor:[color,'#22263a'], borderColor:'#1a1d27', borderWidth:2, circumference:180, rotation:270,
      }]},
      options:{ responsive:true, maintainAspectRatio:false, cutout:'72%',
        plugins:{ legend:{display:false}, tooltip:{enabled:false} } },
      plugins:[{
        id:'gaugeText',
        afterDraw(chart){
          const {ctx, chartArea:{width,height,top}} = chart;
          ctx.save();
          ctx.textAlign='center';
          ctx.fillStyle = color;
          ctx.font='700 32px Segoe UI';
          ctx.fillText(avg.toFixed(1)+'%', width/2, top+height*0.75);
          ctx.fillStyle='#7c8aaa'; ctx.font='12px Segoe UI';
          ctx.fillText('média · meta '+meta+'%', width/2, top+height*0.75+22);
          ctx.restore();
        }
      }]
    });
  }

  // Scope Creep: barra divergente (add positivo, drop negativo)
  function renderScope(s) {
    if (!s) return;
    Charts.make('pr-scope', {
      type:'bar',
      data:{ labels:s.labels, datasets:[
        { label:'Adicionado (+)', data:s.scope_add, backgroundColor:Charts.color(6), borderRadius:4 },
        { label:'Removido (−)',   data:s.scope_drop, backgroundColor:Charts.color(4), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
        scales:{ y:{title:{display:true,text:'Story Points'}}, x:{ticks:{maxRotation:30}} } }
    });
  }

  // Variação de Escopo % por time
  function renderVariation(s) {
    if (!s) return;
    Charts.make('pr-variation', {
      type:'bar',
      data:{ labels:s.labels, datasets:[{
        label:'Variação de Escopo %', data:s.variation_pct,
        backgroundColor:(s.variation_pct||[]).map((_,i)=>Charts.fill(5,.8)), borderColor:Charts.color(5), borderWidth:1.5, borderRadius:4,
      }]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
        plugins:{ legend:{display:false}, tooltip:{callbacks:{label:ctx=>` ${ctx.parsed.x}%`}} },
        scales:{ x:{beginAtZero:true, title:{display:true,text:'% adicionado sobre o committed'}} } }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
