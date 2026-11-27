<?php
/**
 * vw_epicos.php — Status dos Épicos
 * ───────────────────────────────────
 * Agrupa itens por épico (campo "pai"), calculando progresso (SP entregue /
 * SP total) por épico. Dados via /extract.
 */
?>
<div class="alert alert-warn" role="status">Aviso: Módulo Pendente!</div>
<div id="ep-root">
  <div class="loading"><div class="spinner"></div> Carregando épicos...</div>
</div>

<template id="ep-tpl">
  <div class="stitle">Visão dos Épicos</div>
  <div class="kpi-grid" id="ep-kpis"></div>

  <div class="stitle" style="margin-top:22px">Distribuição</div>
  <div class="cgrid c2">
    <div class="ccard">
      <h3>Principais épicos por Story Points <span class="badge">total planejado</span></h3>
      <div class="cwrap tall"><canvas id="ep-sp"></canvas></div>
    </div>
    <div class="ccard">
      <h3>Progresso dos Épicos <span class="badge">faixa de completude</span></h3>
      <div class="cwrap tall"><canvas id="ep-prog"></canvas></div>
    </div>
  </div>

  <div class="stitle" style="margin-top:22px">Detalhamento por Épico</div>
  <div class="ccard" id="ep-lista-card"></div>
</template>

<script>
(function () {
  const { Api, Util } = window.App || {};
  const root = document.getElementById('ep-root');
  if (!Api) { root.innerHTML = '<div class="alert alert-error">Erro: núcleo JS não carregou.</div>'; return; }

  const DONE = ['Aceito','Em produção','Pendente de Produção'];
  const MET = <?= json_encode(\App\Services\MetodologiaService::paraTela('epicos'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

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
    const lbl = document.querySelectorAll('#ep-kpis .kpi-label');
    const cheia = (n, chave) => { if (lbl[n]) { lbl[n].appendChild(document.createTextNode(' ')); lbl[n].appendChild(chave); } };
    cheia(0, window.App.Legenda.botao('ep_total', MET, op('Épicos (com itens)', true)));
    cheia(1, window.App.Legenda.botao('ep_concluidos', MET, op('Épicos concluídos', true)));
    cheia(2, window.App.Legenda.botao('ep_risco', MET, op('Épicos em risco', true)));
    cheia(3, window.App.Legenda.botao('ep_nao_iniciados', MET, op('Épicos não iniciados', true)));
    const tit = id => { const c = document.getElementById(id); return c && c.closest('.ccard') ? c.closest('.ccard').querySelector('h3') : null; };
    const hSp = tit('ep-sp'), hProg = tit('ep-prog');
    if (hSp) { hSp.appendChild(document.createTextNode(' ')); hSp.appendChild(window.App.Legenda.botao('ep_grafico_sp', MET, op('Principais épicos por Story Points', false))); }
    if (hProg) { hProg.appendChild(document.createTextNode(' ')); hProg.appendChild(window.App.Legenda.botao('ep_grafico_progresso', MET, op('Progresso dos Épicos', false))); }
    const card = document.getElementById('ep-lista-card');
    if (card) {
      const ab = document.createElement('div');
      ab.className = 'pr-sub';
      ab.appendChild(window.App.Legenda.botao('ep_tabela', MET, op('Detalhamento por Épico', false)));
      card.insertBefore(ab, card.firstChild);
    }
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

  async function load() {
    try {
      const ext = await Api.get('/extract');
      const items = filterItems(ext.items || []).filter(i=>i.pai); // só itens vinculados a épico
      render(items, ext);
    } catch (e) { Util.error(root, e.message); }
  }

  function render(items, ext) {
    root.innerHTML = '';
    root.appendChild(document.getElementById('ep-tpl').content.cloneNode(true));

    // Agrupa por épico (pai)
    const epics = {};
    items.forEach(i => {
      const k = i.pai;
      if (!epics[k]) epics[k] = { key:k, items:0, sp:0, spDone:0, lista:[] };
      epics[k].items++;
      epics[k].lista.push(i);
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
    renderLista(list);
    anexarLegendas(ext);
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
    const chartSp = Charts.make('ep-sp', {
      type:'bar',
      data:{ labels:top.map(e=>e.key), datasets:[
        { label:'SP Total', data:top.map(e=>e.sp), backgroundColor:Charts.fill(0,.5), borderColor:Charts.color(0), borderWidth:1.5, borderRadius:4 },
        { label:'SP Entregue', data:top.map(e=>e.spDone), backgroundColor:Charts.color(2), borderRadius:4 },
      ]},
      options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y', plugins:{legend:{position:'bottom'}}, scales:{x:{beginAtZero:true, title:{display:true,text:'Story Points'}}} }
    });
    // Atalho: clique na barra abre os itens do épico (o botão "Ver épicos" é o caminho por teclado).
    window.App.ListaItens.clicavel(chartSp, document.getElementById('ep-sp'), i => { if (top[i]) abrirItens(top[i], null); });
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

  // Modal 2: itens do épico (dados de /extract já carregados)
  function abrirItens(e, origem) {
    window.App.ListaItens.abrir({
      titulo: 'Itens do épico ' + e.key,
      subtitulo: e.items + ' itens · ' + e.spDone + ' de ' + e.sp + ' SP entregues (' + e.completude + '%)',
      origem,
      colunas: [
        { k:'chave', rotulo:'Chave', tipo:'chave' },
        { k:'tipo', rotulo:'Tipo', tipo:'texto' },
        { k:'status', rotulo:'Status', tipo:'status' },
        { k:'sp', rotulo:'SP', tipo:'num', casas:1 },
        { k:'sprint', rotulo:'Sprint', tipo:'texto' },
      ],
      linhas: e.lista.map(i => ({ chave:i.chave, tipo:i.tipo_item, status:i.status, sp:Number(i.story_points)||0, sprint:Util.shortSprint(i.sprint) })),
      vazio: 'Nenhum item neste épico.',
    });
  }

  // Modal 1: lista de épicos; clicar numa linha (ou Enter) abre o Modal 2
  function abrirEpicos(sorted, origem) {
    window.App.ListaItens.abrir({
      titulo: 'Épicos',
      subtitulo: sorted.length + ' épicos na seleção · clique numa linha para ver os itens',
      origem,
      colunas: [
        { k:'key', rotulo:'Épico', tipo:'chave' },
        { k:'items', rotulo:'Itens', tipo:'num', casas:0 },
        { k:'sp', rotulo:'SP total', tipo:'num', casas:1 },
        { k:'spDone', rotulo:'SP entregue', tipo:'num', casas:1 },
        { k:'completude', rotulo:'Completude (%)', tipo:'num', casas:0 },
      ],
      linhas: sorted,
      vazio: 'Nenhum épico na seleção.',
    });
    const dlg = document.getElementById('dlg-detalhe');
    if (!dlg) return;
    dlg.querySelectorAll('tbody tr').forEach((tr, idx) => {
      const e = sorted[idx];
      if (!e) return;
      tr.style.cursor = 'pointer';
      tr.tabIndex = 0;
      tr.title = 'Ver os itens do épico ' + e.key;
      tr.addEventListener('click', ev => { if (!ev.target.closest('a')) abrirItens(e, origem); });
      tr.addEventListener('keydown', ev => { if (ev.key === 'Enter' && !ev.target.closest('a')) abrirItens(e, origem); });
    });
  }

  function renderLista(list) {
    const sorted = [...list].sort((a,b)=>b.sp-a.sp);
    const card = document.getElementById('ep-lista-card');
    const p = document.createElement('p');
    p.className = 'pr-sub';
    p.textContent = sorted.length + ' épicos com itens na seleção. Os itens de cada épico abrem a partir da lista.';
    card.append(p, window.App.ListaItens.botao('Ver épicos', b => abrirEpicos(sorted, b)));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
