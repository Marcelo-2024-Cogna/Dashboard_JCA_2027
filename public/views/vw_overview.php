<?php
/**
 * vw_overview.php — Visão Geral (por jornada, consolidando projetos)
 * ──────────────────────────────────────────────────────────────────
 * Reescrita conforme §D.7: segue o padrão de vw_velocity.php.
 *   - #vg-root, fetch DIRETO de /graficos/visao-geral (não Api.get, para ler o
 *     corpo do 400 em português), el() com textContent (nunca innerHTML com
 *     dado do servidor), window.Charts.make, window.App.Filters.
 *   - O 'modo' (sprint|pi) é lido de location.search (Filters.read só lê
 *     jornada/time/versao/sprint). O filtro de sprint do header vence o modo.
 *   - Acessibilidade (NFR-3): todo canvas tem aria-label; tabelas têm caption e
 *     th scope; o toggle é aria-pressed; o dado de cada gráfico também aparece em
 *     tabela; cor nunca é o único canal; estados loading/erro/vazio explícitos.
 */
?>
<div id="vg-root">
  <div class="loading"><div class="spinner"></div> Carregando a Visão Geral...</div>
</div>

<script>
(function () {
  const root = document.getElementById('vg-root');
  const BASE = window.APP_BASE || '';

  // ── Helpers de DOM e formatação ───────────────────────────────────────
  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  const nf  = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });
  const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const fmt  = v => (v == null ? '—' : nf.format(v));
  const fmt1 = v => (v == null ? '—' : nf1.format(v));
  const pctF = v => (v == null ? '—' : nf1.format(v) + '%');
  const int  = v => (v == null ? '—' : new Intl.NumberFormat('pt-BR').format(v));

  // Data "2026-10-26" → "26/10"
  function diaMes(iso) {
    if (!iso || typeof iso !== 'string') return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return m ? (m[3] + '/' + m[2]) : iso;
  }
  function dataHora(iso) {
    if (!iso || typeof iso !== 'string') return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(iso);
    if (m) return m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5];
    const d = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    return d ? (d[3] + '/' + d[2]) : iso;
  }

  // Lê o 'modo' da URL (não faz parte de Filters.read).
  function modoDaUrl() {
    const m = (new URLSearchParams(location.search).get('modo') || '').toLowerCase().trim();
    return m === 'pi' ? 'pi' : 'sprint';
  }

  function mensagem(tipo, titulo, texto) {
    root.textContent = '';
    const a = el('div', 'alert alert-' + tipo);
    a.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    a.appendChild(el('strong', null, titulo));
    if (texto) { a.appendChild(document.createElement('br')); a.appendChild(el('span', null, texto)); }
    root.appendChild(a);
  }

  // ── Fetch direto (lê o corpo do 400 em português) ─────────────────────
  async function buscar() {
    const f = window.App.Filters.read();
    const qs = new URLSearchParams();
    ['jornada', 'time', 'versao', 'sprint'].forEach(k => { if (f[k]) qs.set(k, f[k]); });
    qs.set('modo', modoDaUrl());
    let res;
    try {
      res = await fetch(BASE + '/graficos/visao-geral?' + qs.toString(), { headers: { Accept: 'application/json' } });
    } catch (e) {
      throw new Error('Não foi possível contatar o servidor. Verifique a conexão e tente novamente.');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* corpo não-JSON */ }
    if (!res.ok) {
      if (res.status === 400) throw new Error((json && json.erro) || 'Filtros inválidos para esta consulta.');
      throw new Error('Não foi possível calcular a Visão Geral agora (erro ' + res.status + '). Tente novamente; se persistir, consulte o log do dashboard.');
    }
    if (!json || json.success !== true) throw new Error('Resposta inesperada do servidor.');
    return json;
  }

  // ── Cartões auxiliares ─────────────────────────────────────────────────
  function cardBase(titulo, badgeTxt, largo) {
    const card = el('div', 'ccard' + (largo ? ' vg-wide' : ''));
    const h = el('h3', null, titulo + ' ');
    if (badgeTxt) h.appendChild(el('span', 'badge', badgeTxt));
    card.appendChild(h);
    return card;
  }
  // Card aproximado: badge "aproximado por datas de fluxo" + regra em pr-sub.
  function cardAprox(titulo, regra, largo) {
    const card = el('div', 'ccard' + (largo ? ' vg-wide' : ''));
    const h = el('h3', null, titulo + ' ');
    const b = el('span', 'badge vg-badge-aprox', 'aproximado por datas de fluxo');
    b.title = 'Gadget aproximado: usa as datas de fluxo (não há sprint burndown nativo). ' + (regra || '');
    h.appendChild(b);
    card.appendChild(h);
    if (regra) card.appendChild(el('div', 'pr-sub', regra));
    return card;
  }
  // Mostra o motivo de um gadget vazio dentro do próprio card.
  function cardVazio(titulo, g, largo) {
    const card = cardBase(titulo, null, largo);
    const a = el('div', 'alert alert-info');
    a.style.margin = '6px 0 0';
    a.setAttribute('role', 'status');
    a.appendChild(el('span', null, (g && g.motivo) || 'Sem dados para a seleção.'));
    card.appendChild(a);
    card._vazio = true;
    return card;
  }

  // Chaves de alvo_dias (config/sinalizadores.php) por fase do tempo por status.
  const CHAVE_FASE = {
    'Desenvolvimento': 'desenvolvimento', 'Aguardando homologação': 'aguardando_homologacao',
    'Homologação': 'homologacao', 'Aguardando implantação': 'aguardando_implantacao', 'Implantação': 'implantacao',
  };
  const COR_NIVEL = { verde: '#10b981', amarelo: '#f59e0b', vermelho: '#f43f5e', neutro: '#6366f1' };
  // Aplica o sinal de cor ao número do KPI e uma barra lateral no cartão (amarelo/vermelho).
  function sinalKpi(k, r) {
    const val = k.querySelector('.kpi-val');
    window.App.Sinal.aplicar(val, r.nivel, r.motivo);
    val.classList.remove('sinal-lateral'); // a barra fica no cartão, não no número
    if (r.nivel === 'amarelo' || r.nivel === 'vermelho') k.classList.add('kpi-sinal-' + r.nivel);
  }

  function kpi(label, valor, sub, ac, tooltip) {
    const k = el('div', 'kpi');
    if (ac) k.style.setProperty('--ac', ac);
    const l = el('div', 'kpi-label', label);
    if (tooltip) { l.title = tooltip; l.appendChild(document.createTextNode(' ⓘ')); }
    k.appendChild(l);
    k.appendChild(el('div', 'kpi-val', valor));
    if (sub) k.appendChild(el('div', 'kpi-sub', sub));
    return k;
  }

  function tabelaBase(titulo, legenda, minWidth) {
    const w = el('div', 'twrap');
    const tb = el('table', 'prev-tbl vg-tbl');
    if (minWidth) tb.style.minWidth = minWidth;
    const cap = el('caption', null, legenda);
    cap.style.cssText = 'position:absolute;left:-9999px';
    tb.appendChild(cap);
    if (titulo) {
      const h = el('div', 'stitle', titulo);
      h.style.cssText = 'font-size:.74rem;margin:12px 0 6px';
      w.appendChild(h);
    }
    w.appendChild(tb);
    return { wrap: w, tb };
  }
  function cabecalho(tb, cols) {
    const thead = el('thead'); const trh = el('tr');
    cols.forEach(([t, c]) => { const th = el('th', c, t); th.scope = 'col'; trh.appendChild(th); });
    thead.appendChild(trh); tb.appendChild(thead);
  }

  // ── Modais (App.Modal): botão real é o caminho por teclado; clique no ──
  // gráfico/KPI é só atalho. Todo texto do servidor entra por textContent.
  function botaoModal(rotulo, abrirFn) {
    const b = el('button', 'btn vg-ajuda', rotulo);
    b.type = 'button';
    b.setAttribute('aria-haspopup', 'dialog');
    b.addEventListener('click', () => abrirFn(b));
    return b;
  }
  // KPI com botão equivalente; clicar no cartão também abre (atalho de mouse).
  function kpiAcao(k, rotulo, abrirFn) {
    const b = botaoModal(rotulo, abrirFn);
    b.style.marginTop = '6px';
    k.appendChild(b);
    k.style.cursor = 'pointer';
    k.addEventListener('click', ev => { if (ev.target !== b) abrirFn(b); });
    return k;
  }
  // Tabela dentro do corpo do modal. Célula: texto ou { t, title }.
  function tabelaModal(corpo, legenda, cols, linhas, minWidth) {
    const { wrap, tb } = tabelaBase(null, legenda, minWidth);
    cabecalho(tb, cols);
    const tbody = el('tbody');
    linhas.forEach(l => {
      const tr = el('tr');
      l.forEach((c, i) => {
        const obj = c != null && typeof c === 'object';
        const td = el('td', cols[i][1] || null, obj ? c.t : c);
        if (obj && c.title) td.title = c.title;
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    corpo.appendChild(wrap);
  }
  // Status que contam como pronto: lista enviada em d.metodologia.done_statuses.
  function statusDone(d) {
    const m = d.metodologia && d.metodologia.done_statuses;
    return Array.isArray(m) ? m : ['Aceito', 'Em produção', 'Pendente de Produção'];
  }

  // ── Legendas "Como é calculado" ───────────────────────────────────────
  // O texto vem só de d.metodologia.blocos (MetodologiaService, fonte única); aqui só
  // ficam os botões (App.Legenda) e os formatadores usados pelos modais de detalhe.
  const lista = a => (Array.isArray(a) && a.length ? a.join(', ') : '—');
  const sinal = v => (v == null ? '—' : (v > 0 ? '+' : (v < 0 ? '−' : '')) + nf1.format(Math.abs(v)) + '%');
  const seta  = v => (v == null || v === 0 ? '=' : (v > 0 ? '▲' : '▼'));
  // Insere o botão de legenda logo após o título (primeiro filho) do cartão/seção.
  function anexarLegenda(container, botao) {
    const ab = el('div', 'pr-sub');
    ab.appendChild(botao);
    container.insertBefore(ab, container.firstChild ? container.firstChild.nextSibling : null);
    return container;
  }

  // ── Efficiency: KPI e modal de detalhe ─────────────────────────────────
  function kpiEfficiency(d) {
    const ef = d.gadgets.efficiency_baseline || {};
    const rotulo = 'Efficiency: custo real por SP';
    if (ef.estado !== 'ok') {
      return kpi(rotulo, '—', ef.motivo || 'Sem itens de valor entregues na janela.', '#22d3ee');
    }
    const sub = ef.baseline_h_por_sp == null
      ? 'sem baseline cadastrado'
      : 'baseline ' + fmt(ef.baseline_h_por_sp) + ' · ' + seta(ef.variacao_pct) + ' ' + sinal(ef.variacao_pct);
    const k = kpi(rotulo, ef.h_por_sp == null ? '—' : fmt(ef.h_por_sp) + ' h/SP', sub, '#22d3ee',
      'Horas gastas por ponto dos itens de valor em Done, contra o baseline h/SP dos times. Estado de hoje.');
    if (ef.parcial) k.firstChild.appendChild(el('span', 'badge', 'parcial'));
    const mh = ef.multissprint ? ef.multissprint.horas : 0;
    if (ef.horas > 0 && mh / ef.horas * 100 > ef.aviso_multissprint_pct) {
      const av = el('div', 'kpi-sub', '⚠ ' + pctF(mh / ef.horas * 100) + ' das horas vêm de itens que passaram por mais de uma sprint.');
      av.style.color = '#f59e0b';
      k.appendChild(av);
    }
    return kpiAcao(k, 'Detalhes', origem => abrirEfficiency(d, origem));
  }
  function abrirEfficiency(d, origem) {
    const ef = d.gadgets.efficiency_baseline || {};
    window.App.Modal.abrir({
      titulo: 'Efficiency: custo real por SP',
      subtitulo: (ef.rotulo_escopo || '') + (ef.parcial ? ' · parcial (sprint em andamento)' : ''),
      origem,
      construir: corpo => {
        const ul = el('ul');
        [
          'Horas: ' + fmt(ef.horas) + ' h · SP: ' + fmt(ef.pontos) + ' · itens: ' + int(ef.itens) + ' · h/SP: ' + fmt(ef.h_por_sp),
          ef.baseline_h_por_sp == null
            ? 'Sem baseline cadastrado para os times da janela.'
            : 'Baseline ponderado: ' + fmt(ef.baseline_h_por_sp) + ' h/SP · razão ' + pctF(ef.razao_pct) + ' · variação ' + seta(ef.variacao_pct) + ' ' + sinal(ef.variacao_pct),
          'Estado de hoje, não do fim da sprint.',
        ].forEach(t => ul.appendChild(el('li', null, t)));
        corpo.appendChild(ul);

        tabelaModal(corpo, 'Efficiency por time: horas, SP, h/SP, baseline e razão',
          [['Time', ''], ['Horas', 'num'], ['SP', 'num'], ['h/SP', 'num'], ['Baseline', 'num'], ['Razão', 'num']],
          (ef.por_time || []).map(t => [t.time || t.projeto_key, fmt(t.horas), fmt(t.pontos), fmt(t.h_por_sp),
            t.baseline == null ? 'sem baseline' : fmt(t.baseline), pctF(t.razao_pct)]), '520px');

        corpo.appendChild(el('h4', null, 'Diagnósticos'));
        const dg = el('ul');
        const ss = ef.sem_sp || {}, dt = ef.debito_tecnico || {}, ms = ef.multissprint || {};
        const ca = ef.concluidos_apos_fim_sprint || {}, cb = ef.cobertura_baseline || {};
        [
          'Itens sem SP: ' + int(ss.itens) + ' (' + fmt(ss.horas) + ' h entram sem pontos). Sem eles o h/SP seria ' + fmt(ss.h_por_sp_sem_itens_sem_sp) + '.',
          'Débito Técnico (incluso): ' + int(dt.itens) + ' itens, ' + fmt(dt.pontos) + ' SP, ' + fmt(dt.horas) + ' h.',
          'Multissprint: ' + int(ms.itens) + ' itens, ' + fmt(ms.horas) + ' h, ' + fmt(ms.pontos) + ' SP. Horas acumuladas na vida do item, atribuídas à última sprint.',
          'Concluídos depois do fim da sprint: ' + int(ca.itens) + ' itens, ' + fmt(ca.pontos) + ' SP. Sem data de conclusão: ' + int((ef.sem_data_conclusao || {}).itens) + ' itens.',
          'Cobertura do baseline: ' + fmt(cb.pontos_com_baseline) + ' SP com baseline, ' + fmt(cb.pontos_sem_baseline) + ' SP sem'
            + ((cb.times_sem_baseline || []).length ? ' (times: ' + cb.times_sem_baseline.join(', ') + ')' : '') + '.',
        ].forEach(t => dg.appendChild(el('li', null, t)));
        corpo.appendChild(dg);
        if (ef.regra) corpo.appendChild(el('div', 'pr-sub', ef.regra));

        const lk = el('div', 'pr-sub');
        const a = document.createElement('a');
        a.href = BASE + '/metricas/produtividade';
        a.textContent = 'Ver o histórico na tela Produtividade';
        a.style.color = '#a5b4fc';
        lk.appendChild(a);
        corpo.appendChild(lk);
      },
    });
  }

  // ── Fator de Carga: KPI e modal de detalhe ─────────────────────────────
  const FC_FAIXA = {
    subutilizacao: ['possível subutilização ou margem elevada', '#38bdf8'],
    recomendada:   ['faixa recomendada', '#10b981'],
    atencao:       ['atenção a sobrecarga', '#f59e0b'],
    acima:         ['demanda acima da capacidade líquida', '#f43f5e'],
  };
  const fcFaixaTxt = f => (FC_FAIXA[f] ? FC_FAIXA[f][0] : 'sem faixa');
  const fcPct = v => (v == null ? 'sem dados' : pctF(v));
  const fcHoras = v => (v == null ? '—' : fmt(v) + ' h');
  function kpiFatorCarga(d) {
    const fc = d.gadgets.fator_carga || {};
    const rotulo = 'Fator de Carga';
    if (fc.estado !== 'ok' || fc.fc_pct == null) {
      return kpi(rotulo, '—', 'sem dados: ' + (fc.motivo || 'sem capacidade ou demanda calculável na seleção.'), '#38bdf8');
    }
    const cor = FC_FAIXA[fc.faixa] ? FC_FAIXA[fc.faixa][1] : '#38bdf8';
    const k = kpi(rotulo, pctF(fc.fc_pct), fcFaixaTxt(fc.faixa), cor,
      'Demanda (SPE × baseline h/SP) ÷ capacidade líquida do Painel. Pertença atual à sprint.');
    k.appendChild(el('div', 'kpi-sub', 'demanda ' + fmt(fc.demanda_h) + ' h ÷ capacidade líquida ' + fmt(fc.liquida_h) + ' h'));
    if (fc.parcial) k.firstChild.appendChild(el('span', 'badge', 'parcial'));
    const sd = (fc.cobertura || {}).times_sem_dados || [];
    if (sd.length) {
      const av = el('div', 'kpi-sub', '⚠ ' + int(sd.length) + ' time(s) sem dados ficam fora do cálculo.');
      av.style.color = '#f59e0b';
      k.appendChild(av);
    }
    return kpiAcao(k, 'Detalhes', origem => abrirFatorCarga(d, origem));
  }
  function abrirFatorCarga(d, origem) {
    const fc = d.gadgets.fator_carga || {};
    const met = (d.metodologia || {}).fator_carga || {};
    window.App.Modal.abrir({
      titulo: 'Fator de Carga',
      subtitulo: (fc.rotulo_escopo || '') + (fc.parcial ? ' · parcial (sprint em andamento)' : ''),
      origem,
      construir: corpo => {
        const ul = el('ul');
        if (fc.estado !== 'ok') {
          ul.appendChild(el('li', null, 'Sem dados: ' + (fc.motivo || 'sem capacidade ou demanda calculável na seleção.')));
          corpo.appendChild(ul);
          return;
        }
        const cob = fc.cobertura || {};
        [
          'Demanda ' + fmt(fc.demanda_h) + ' h ÷ capacidade líquida ' + fmt(fc.liquida_h) + ' h (bruta ' + fmt(fc.bruta_h) + ' h, disponibilidade ' + fmt(fc.disponibilidade_pct) + '%) = ' + fcPct(fc.fc_pct) + ' · ' + fcFaixaTxt(fc.faixa),
          'SP de valor: ' + fmt((fc.decomposicao || {}).sp_valor) + ' · times com dados: ' + int(cob.times_com_dados) + ' de ' + int(cob.times_total),
          'Fonte da capacidade: ' + (met.fonte_capacidade === 'painel' ? 'Painel de Controle' : 'config/capacity.php (Painel não salvo)') + '.',
          'Regra de sustentação: ' + (met.incluir_sustentacao ? 'entra na demanda.' : 'fora do fator; só na decomposição.'),
        ].forEach(t => ul.appendChild(el('li', null, t)));
        corpo.appendChild(ul);

        tabelaModal(corpo, 'Fator de Carga por time: capacidade, demanda, fator e faixa',
          [['Time', ''], ['Devs', 'num'], ['h/dia', 'num'], ['Baseline h/SP', 'num'], ['SP valor', 'num'],
            ['Capacidade líquida', 'num'], ['Demanda', 'num'], ['Fator', 'num'], ['Faixa', '']],
          (fc.por_time || []).map(t => [t.time || t.projeto_key, fmt(t.devs), fmt(t.horas_dia), fmt(t.baseline_h_sp),
            fmt(t.sp_valor), fcHoras(t.liquida_h), fcHoras(t.demanda_h), fcPct(t.fc_pct), fcFaixaTxt(t.faixa)]), '760px');

        tabelaModal(corpo, 'Fator de Carga por sprint: dias úteis, capacidade, demanda e fator',
          [['Sprint', ''], ['Dias úteis', 'num'], ['Capacidade líquida', 'num'], ['Demanda', 'num'], ['Fator', 'num']],
          (fc.por_sprint || []).map(s => [s.sprint + (s.status === 'ativa' ? ' (em andamento)' : ''), fmt(s.dias_uteis),
            fcHoras(s.liquida_h), fcHoras(s.demanda_h), fcPct(s.fc_pct)]), '520px');

        const dc = fc.decomposicao || {};
        corpo.appendChild(el('h4', null, 'Valor x sustentação'));
        const dl = el('ul');
        [
          'Somente valor: ' + fmt(dc.sp_valor) + ' SP · ' + fcHoras(dc.demanda_valor_h) + ' · fator ' + fcPct(dc.fc_somente_valor_pct),
          'Com sustentação: +' + fmt(dc.sp_sustentacao) + ' SP · +' + fcHoras(dc.demanda_sustentacao_h) + ' · fator ' + fcPct(dc.fc_com_sustentacao_pct),
        ].forEach(t => dl.appendChild(el('li', null, t)));
        corpo.appendChild(dl);

        const sd = cob.times_sem_dados || [];
        if (sd.length) {
          corpo.appendChild(el('h4', null, 'Times sem dados'));
          const sl = el('ul');
          sd.forEach(t => sl.appendChild(el('li', null, (t.time || t.projeto_key) + ': '
            + (t.motivo === 'sem_baseline' ? 'sem baseline h/SP' : 'sem capacidade no Painel')
            + ' · SPE fora do cálculo: ' + fmt(t.sp_valor) + ' SP de valor, ' + fmt(t.sp_sustentacao) + ' SP de sustentação.')));
          corpo.appendChild(sl);
        }

        const dg = fc.diagnosticos || {};
        corpo.appendChild(el('h4', null, 'Diagnósticos'));
        const gl = el('ul');
        [
          'Itens sem SPE: ' + int(dg.itens_sem_spe) + ' (não geram demanda).',
          'Sprints sem dias úteis (fora do cálculo): ' + lista(dg.sprints_sem_dias_uteis) + '.',
          'Sprints de sustentação ignoradas: ' + lista(dg.sprints_sustentacao_ignoradas) + '.',
        ].forEach(t => gl.appendChild(el('li', null, t)));
        corpo.appendChild(gl);
        if (fc.regra) corpo.appendChild(el('div', 'pr-sub', fc.regra));
      },
    });
  }

  // ── Debt Load: KPI e modal de detalhe ──────────────────────────────────
  const dlPct = v => (v == null ? 'sem dados' : pctF(v));
  const dlSp = v => (v == null ? '—' : fmt(v) + ' SP');
  function kpiDebtLoad(d) {
    const dl = d.gadgets.debt_load || {};
    const rotulo = 'Debt Load';
    if (dl.estado !== 'ok' || dl.dl_pct == null) {
      return kpi(rotulo, '—', 'sem dados: ' + (dl.motivo || 'sem capacidade ou dívida calculável na seleção.'), '#94a3b8');
    }
    const k = kpi(rotulo, pctF(dl.dl_pct), 'sem faixa definida', '#94a3b8',
      'SP de Débito Técnico e Suporte ÷ capacidade líquida em SP (horas líquidas ÷ baseline h/SP). Estado de hoje.');
    k.appendChild(el('div', 'kpi-sub', fmt(dl.sp_divida) + ' SP de dívida ÷ ' + fmt(dl.capacidade_sp) + ' SP de capacidade líquida'));
    if (dl.parcial) k.firstChild.appendChild(el('span', 'badge', 'parcial'));
    const sd = (dl.cobertura || {}).times_sem_dados || [];
    if (sd.length) {
      const av = el('div', 'kpi-sub', '⚠ ' + int(sd.length) + ' time(s) sem dados ficam fora do cálculo.');
      av.style.color = '#f59e0b';
      k.appendChild(av);
    }
    const ss = (dl.diagnosticos || {}).itens_sem_sp || {};
    if (ss.itens > 0) {
      const av = el('div', 'kpi-sub', '⚠ ' + int(ss.itens) + ' item(ns) de dívida sem SP contam 0.');
      av.style.color = '#f59e0b';
      k.appendChild(av);
    }
    return kpiAcao(k, 'Detalhes', origem => abrirDebtLoad(d, origem));
  }
  function abrirDebtLoad(d, origem) {
    const dl = d.gadgets.debt_load || {};
    const met = (d.metodologia || {}).debt_load || {};
    window.App.Modal.abrir({
      titulo: 'Debt Load',
      subtitulo: (dl.rotulo_escopo || '') + (dl.parcial ? ' · parcial (sprint em andamento)' : ''),
      origem,
      construir: corpo => {
        const ul = el('ul');
        if (dl.estado !== 'ok') {
          ul.appendChild(el('li', null, 'Sem dados: ' + (dl.motivo || 'sem capacidade ou dívida calculável na seleção.')));
          corpo.appendChild(ul);
          return;
        }
        const cob = dl.cobertura || {};
        [
          fmt(dl.sp_divida) + ' SP de dívida ÷ ' + fmt(dl.capacidade_sp) + ' SP de capacidade líquida (' + fcHoras(dl.liquida_h) + ', disponibilidade ' + fmt(dl.disponibilidade_pct) + '%) = ' + dlPct(dl.dl_pct) + ' · sem faixa definida',
          'Só entregues: ' + dlSp(dl.sp_entregue) + ' · ' + dlPct(dl.dl_entregue_pct) + '. Em horas (diagnóstico): ' + dlPct(dl.dl_horas_pct) + '. Com SPE (diagnóstico): ' + dlSp(dl.sp_spe) + '.',
          'Times com dados: ' + int(cob.times_com_dados) + ' de ' + int(cob.times_total) + '.',
          'Tipos que entram: ' + lista(met.tipos || dl.tipos) + '. Suporte N1 não é identificável: entra todo item de Suporte.',
          'Fonte da capacidade: ' + (met.fonte_capacidade === 'painel' ? 'Painel de Controle' : 'config/capacity.php (Painel não salvo)') + '.',
        ].forEach(t => ul.appendChild(el('li', null, t)));
        corpo.appendChild(ul);

        tabelaModal(corpo, 'Debt Load por time: capacidade, dívida e percentual',
          [['Time', ''], ['Devs', 'num'], ['h/dia', 'num'], ['Baseline h/SP', 'num'], ['Capacidade líquida', 'num'],
            ['Capacidade (SP)', 'num'], ['Débito Técnico', 'num'], ['Suporte', 'num'], ['Dívida (SP)', 'num'], ['Entregue (SP)', 'num'], ['Debt Load', 'num']],
          (dl.por_time || []).map(t => [t.time || t.projeto_key, fmt(t.devs), fmt(t.horas_dia), fmt(t.baseline_h_sp), fcHoras(t.liquida_h),
            dlSp(t.capacidade_sp), dlSp(t.sp_debito_tecnico), dlSp(t.sp_suporte), dlSp(t.sp_divida), dlSp(t.sp_entregue), dlPct(t.dl_pct)]), '900px');

        tabelaModal(corpo, 'Debt Load por sprint: dias úteis, capacidade, dívida e percentual',
          [['Sprint', ''], ['Dias úteis', 'num'], ['Capacidade líquida', 'num'], ['Capacidade (SP)', 'num'], ['Dívida (SP)', 'num'], ['Debt Load', 'num']],
          (dl.por_sprint || []).map(s => [s.sprint + (s.status === 'ativa' ? ' (em andamento)' : ''), fmt(s.dias_uteis),
            fcHoras(s.liquida_h), dlSp(s.capacidade_sp), dlSp(s.sp_divida), dlPct(s.dl_pct)]), '620px');

        tabelaModal(corpo, 'Decomposição por tipo: itens, SP e SP entregue',
          [['Tipo', ''], ['Itens', 'num'], ['SP', 'num'], ['SP entregue', 'num']],
          ((dl.decomposicao || {}).por_tipo || []).map(t => [t.tipo, int(t.itens), fmt(t.sp), fmt(t.sp_entregue)]), '460px');

        const fora = dl.fora_do_indicador || [];
        if (fora.length) {
          tabelaModal(corpo, 'Fora do indicador: tipos de sustentação que não entram',
            [['Tipo', ''], ['Itens', 'num'], ['SP', 'num']],
            fora.map(t => [t.tipo, int(t.itens), fmt(t.sp)]), '460px');
        }

        const ec = dl.efficiency_comparativo || {};
        const efLinha = (nome, x) => [nome, fmt((x || {}).horas), fmt((x || {}).pontos), fmt((x || {}).h_por_sp),
          (x || {}).baseline_h_por_sp == null ? 'sem baseline' : fmt(x.baseline_h_por_sp), pctF((x || {}).razao_pct)];
        tabelaModal(corpo, 'Efficiency com e sem Débito Técnico (modo ativo: ' + (ec.modo_ativo === 'sem_debito_tecnico' ? 'sem Débito Técnico' : 'com Débito Técnico') + ')',
          [['Cálculo', ''], ['Horas', 'num'], ['SP', 'num'], ['h/SP', 'num'], ['Baseline', 'num'], ['Razão', 'num']],
          [efLinha('Com Débito Técnico', ec.com_debito_tecnico), efLinha('Sem Débito Técnico', ec.sem_debito_tecnico)], '600px');

        const im = dl.impacto_fator_carga || {};
        corpo.appendChild(el('h4', null, 'Impacto no Fator de Carga'));
        const il = el('ul');
        il.appendChild(el('li', null, 'Fator de Carga atual: ' + fcPct(im.fc_pct_atual) + ' · sem Débito Técnico: ' + fcPct(im.fc_pct_sem_debito_tecnico)
          + ' · diferença: ' + (im.delta_pp == null ? '—' : fmt(im.delta_pp) + ' p.p.') + '. Nada muda no Fator de Carga sem decisão do usuário.'));
        corpo.appendChild(il);

        const sd = cob.times_sem_dados || [];
        if (sd.length) {
          corpo.appendChild(el('h4', null, 'Times sem dados'));
          const sl = el('ul');
          sd.forEach(t => sl.appendChild(el('li', null, (t.time || t.projeto_key) + ': '
            + (t.motivo === 'sem_baseline' ? 'sem baseline h/SP' : 'sem capacidade no Painel')
            + ' · SP de dívida fora do cálculo: ' + fmt(t.sp_divida) + '.')));
          corpo.appendChild(sl);
        }

        const dg = dl.diagnosticos || {};
        const ss = dg.itens_sem_sp || {};
        corpo.appendChild(el('h4', null, 'Diagnósticos'));
        const gl = el('ul');
        [
          'Itens de dívida sem SP: ' + int(ss.itens) + ' (contam 0; ' + fmt(ss.horas) + ' h apontadas).',
          'Sprints sem dias úteis (fora do cálculo): ' + lista(dg.sprints_sem_dias_uteis) + '.',
          'Sprints de sustentação ignoradas: ' + lista(dg.sprints_sustentacao_ignoradas) + '.',
        ].forEach(t => gl.appendChild(el('li', null, t)));
        corpo.appendChild(gl);
        if (dl.regra) corpo.appendChild(el('div', 'pr-sub', dl.regra));
      },
    });
  }

  // ── 1. Faixa de contexto ────────────────────────────────────────────────
  function faixaContexto(d) {
    const j = d.janela || {}, fa = d.filtros || {}, ex = d.extracao || {};
    const defas = ex.defasagem_dias;
    const defasH = ex.defasagem_horas;
    const limH = ex.aviso_defasagem_horas != null ? ex.aviso_defasagem_horas : 25;
    const ambar = defasH != null ? defasH > limH : (defas != null && defas > 1);
    const box = el('div', 'alert ' + (ambar ? 'vg-faixa-ambar' : 'alert-info') + ' prev-janela');
    box.setAttribute('role', 'status');

    const linha1 = el('div');
    linha1.appendChild(el('strong', null, (j.pi || '—') + (fa.versao_padrao ? ' (PI corrente)' : '')));
    const partes = [];
    if (fa.jornada) partes.push('Jornada: ' + fa.jornada);
    else partes.push('Todas as jornadas (consolidado)');
    if (fa.time) partes.push('Time: ' + fa.time);
    linha1.appendChild(el('span', 'kpi-sub', '  ·  ' + partes.join('  ·  ')));
    box.appendChild(linha1);

    const sf = j.sprint_foco, sa = j.sprint_anterior;
    const ctx = el('div', 'kpi-sub');
    ctx.style.marginTop = '4px';
    const bits = [];
    if (sf) bits.push('Sprint em foco: ' + sf.nome + ' (' + (sf.status || '') + ', ' + diaMes(sf.data_ini) + '–' + diaMes(sf.data_fim) + ')');
    else bits.push('Sem sprint em foco nesta PI');
    if (sa) bits.push('Sprint anterior: ' + sa.nome);
    ctx.textContent = bits.join('  ·  ');
    box.appendChild(ctx);

    const ext = el('div', 'kpi-sub');
    ext.style.marginTop = '4px';
    if (ex.ultima_carga) {
      ext.textContent = 'Dados extraídos em ' + dataHora(ex.ultima_carga)
        + (defas != null ? ' (' + defas + ' dia' + (defas === 1 ? '' : 's') + ' de defasagem)' : '');
      if (ambar) ext.appendChild(el('strong', null, '  — atenção: dados podem estar desatualizados'));
    } else {
      ext.textContent = 'Sem registro de carga do Jira para esta seleção.';
    }
    box.appendChild(ext);

    if (Array.isArray(ex.prefixos_sem_time) && ex.prefixos_sem_time.length) {
      const semTime = ex.prefixos_sem_time.map(p => p.prefixo + ' (' + p.itens + ')').join(', ');
      box.appendChild(el('div', 'kpi-sub', 'Prefixos extraídos sem time ativo: ' + semTime));
    }
    return box;
  }

  // ── 2. Seletor Sprint ativa | PI inteira ─────────────────────────────────
  function seletor(d) {
    const modo = (d.filtros || {}).modo_efetivo || 'sprint';
    const temSprint = !!(d.filtros || {}).sprint;
    const wrap = el('div', 'vg-seg');
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Escopo da Visão Geral');

    const btnSprint = el('button', 'vg-seg-btn', 'Sprint ativa');
    btnSprint.type = 'button';
    btnSprint.setAttribute('aria-pressed', String(modo === 'sprint'));
    btnSprint.addEventListener('click', () => window.App.Filters.apply({ modo: 'sprint', sprint: '' }));

    const btnPi = el('button', 'vg-seg-btn', 'PI inteira');
    btnPi.type = 'button';
    btnPi.setAttribute('aria-pressed', String(modo === 'pi'));
    btnPi.addEventListener('click', () => window.App.Filters.apply({ modo: 'pi', sprint: '' }));

    wrap.append(btnSprint, btnPi);

    const box = el('div', 'vg-seg-wrap');
    box.appendChild(wrap);
    if (temSprint) {
      box.appendChild(el('span', 'pr-sub', 'Sprint escolhida no filtro do cabeçalho (' + d.filtros.sprint + ').'));
    }
    return box;
  }

  // ── 3. Avisos ─────────────────────────────────────────────────────────
  function avisos(d) {
    const lista = (d.avisos || []).slice();
    if (!(d.filtros || {}).jornada) {
      lista.push('Escolha uma jornada no cabeçalho para uma resposta mais rápida.');
    }
    if (!lista.length) return null;
    const ul = el('ul', 'prev-avisos');
    lista.forEach(a => ul.appendChild(el('li', null, a)));
    return ul;
  }

  // ── 4. Andamento ─────────────────────────────────────────────────────
  function cardAndamento(d) {
    const g = d.gadgets.andamento;
    const sec = el('div');
    sec.appendChild(el('div', 'stitle', 'Andamento da ' + (g.escopo === 'pi' ? 'PI' : 'sprint') + (g.rotulo_escopo ? ' — ' + g.rotulo_escopo : '')));
    if (!g || g.estado !== 'ok') {
      const a = el('div', 'alert alert-info'); a.setAttribute('role', 'status');
      a.appendChild(el('span', null, (g && g.motivo) || 'Sem dados para a seleção.'));
      sec.appendChild(a);
      return sec;
    }
    const grid = el('div', 'kpi-grid vg-kpi-grid');
    grid.appendChild(kpi('Backlog', fmt(g.backlog_sp) + ' SP', int(g.itens.backlog) + ' itens', '#7c8aaa'));
    const kAnd = kpi('Em andamento', fmt(g.em_andamento_sp) + ' SP', int(g.itens.em_andamento) + ' itens',
      '#6366f1', 'Inclui itens Concluído/Homologação/Pronto — prontos, ainda sem aceite; difere do filtro Jira 19364.');
    const porStatus = g.em_andamento_por_status || {};
    if (Object.keys(porStatus).length) {
      kpiAcao(kAnd, 'Ver por status', origem => window.App.Modal.abrir({
        titulo: 'Em andamento por status',
        subtitulo: (g.rotulo_escopo || '') + ' · ' + fmt(g.em_andamento_sp) + ' SP em ' + int(g.itens.em_andamento) + ' itens',
        origem,
        construir: corpo => tabelaModal(corpo, 'Story Points em andamento por status',
          [['Status', ''], ['SP', 'num']],
          Object.keys(porStatus).map(s => [s, fmt(porStatus[s])]), '340px'),
      }));
    }
    grid.appendChild(kAnd);
    grid.appendChild(kpi('Bloqueados', fmt(g.bloqueado_sp) + ' SP', int(g.itens.bloqueado) + ' itens', '#f43f5e'));
    grid.appendChild(kpi('Aceitos', fmt(g.aceitos_sp) + ' SP',
      'valor ' + fmt(g.aceitos_valor_sp) + ' · sustentação ' + fmt(g.aceitos_sustentacao_sp), '#10b981',
      'Fonte: ' + (g.aceitos_sp_origem === 'velocidade' ? 'Velocidade (= Entregues do Planejado x Realizado)' : 'cálculo local')));
    grid.appendChild(kpiEfficiency(d));
    grid.appendChild(kpiFatorCarga(d));
    grid.appendChild(kpiDebtLoad(d));
    const cf = g.cancelados_foco;
    grid.appendChild(kpi('Cancelados na PI', fmt(g.cancelados_pi.sp) + ' SP',
      int(g.cancelados_pi.itens) + ' itens' + (cf ? ' · no foco: ' + fmt(cf.sp) + ' SP em ' + int(cf.itens) + ' itens' : ''), '#f97316'));
    sec.appendChild(grid);

    anexarLegenda(sec, window.App.Legenda.botao('andamento', d.metodologia));
    const abLeg = sec.children[1];
    abLeg.appendChild(window.App.Legenda.botao('aceitos', d.metodologia, { rotulo: 'ⓘ Como é calculado: Aceitos' }));
    abLeg.appendChild(window.App.Legenda.botao('efficiency', d.metodologia, { rotulo: 'ⓘ Como é calculado: Efficiency' }));
    abLeg.appendChild(window.App.Legenda.botao('fator_carga', d.metodologia, { rotulo: 'ⓘ Como é calculado: Fator de Carga' }));
    abLeg.appendChild(window.App.Legenda.botao('debt_load', d.metodologia, { rotulo: 'ⓘ Como é calculado: Debt Load' }));
    abLeg.appendChild(window.App.Legenda.botao('cancelados', d.metodologia, { rotulo: 'ⓘ Como é calculado: Cancelados na PI' }));
    sec.appendChild(el('div', 'pr-sub', 'Total do escopo: ' + fmt(g.total_sp) + ' SP (backlog + em andamento + bloqueado + aceitos; cancelados ficam fora).'));

    // Modo PI: itens fora da janela.
    if (g.escopo === 'pi') {
      const fdj = g.fora_da_janela || {};
      const foraBits = [];
      if (fdj.sem_sprint_itens) foraBits.push(int(fdj.sem_sprint_itens) + ' item(ns) (' + fmt(fdj.sem_sprint_sp) + ' SP) ainda sem sprint');
      if (fdj.sprint_de_outra_pi_itens) foraBits.push(int(fdj.sprint_de_outra_pi_itens) + ' item(ns) (' + fmt(fdj.sprint_de_outra_pi_sp) + ' SP) em sprint de outra PI');
      if (fdj.sprints_futuras_sp) foraBits.push(fmt(fdj.sprints_futuras_sp) + ' SP em sprints futuras');
      if (foraBits.length) sec.appendChild(el('div', 'pr-sub', 'Fora da janela: ' + foraBits.join(' · ') + '.'));
    }
    return sec;
  }

  // ── Modal padrão (App.ListaItens) das listas de itens da sprint ──────────
  // g = gadget lista_sprint_anterior ou lista_sprint_atual. filtro (KPIs da sprint
  // anterior) e tipo (barra do gráfico) agem no navegador sobre as linhas recebidas.
  function abrirItensSprint(d, filtro, origem, g, tipo) {
    g = g || d.gadgets.lista_sprint_anterior;
    if (!g || g.estado !== 'ok') return;
    const anterior = g === d.gadgets.lista_sprint_anterior;
    const done = statusDone(d);
    const rotulos = {
      todos: anterior ? 'Alocados (todos os itens)' : 'Todos os itens',
      aceitos: 'Aceitos (status em ' + done.join(', ') + ')',
      nao_aceitos: 'Não aceitos sem continuidade (fora de Done e sem transbordo)',
      transbordo: 'Transbordo (itens que seguiram para a próxima sprint)',
    };
    const regras = {
      todos: () => true,
      aceitos: it => done.indexOf(it.status) !== -1,
      nao_aceitos: it => done.indexOf(it.status) === -1 && !it.transbordo,
      transbordo: it => !!it.transbordo,
    };
    const itens = (g.itens || []).filter(regras[filtro] || regras.todos)
      .filter(it => !tipo || (it.tipo || 'Sem tipo') === tipo);
    const sp = itens.reduce((s, it) => s + (Number(it.sp) || 0), 0);
    const colunas = [
      { k: 'chave', rotulo: 'Chave', tipo: 'chave' },
      { k: 'tipo', rotulo: 'Tipo', tipo: 'texto' },
      { k: 'resumo', rotulo: 'Título', tipo: 'texto' },
      { k: 'status', rotulo: 'Status', tipo: 'status' },
      { k: 'sp', rotulo: 'SP', tipo: 'num' },
      { k: 'fase_atual', rotulo: 'Fase atual', tipo: 'fase' },
    ];
    if (anterior) {
      colunas.push({ k: 'pai', rotulo: 'Épico pai', tipo: 'texto' },
        { k: 'sprint_maior', rotulo: 'Sprint', tipo: 'texto' },
        { k: 'transbordo_txt', rotulo: 'Transbordo', tipo: 'texto' });
    }
    const linhas = itens.map(it => Object.assign({}, it, { transbordo_txt: it.transbordo ? 'Sim' : 'Não' }));
    const notas = ['Σ lista completa: ' + fmt(g.total_sp) + ' SP em ' + int(g.total_itens) + ' itens.'];
    if (g.resumo_disponivel_pct != null) notas.push('Resumo disponível em ' + pctF(g.resumo_disponivel_pct) + ' dos itens.');
    if (g.truncado) notas.push('Lista truncada ao limite de linhas: o filtro vale só para as linhas recebidas.');
    window.App.ListaItens.abrir({
      titulo: (anterior ? 'Itens da sprint anterior' : 'Itens da sprint atual') + (g.sprint ? ' — ' + g.sprint : '')
        + (tipo ? ' · ' + tipo : ''),
      subtitulo: (rotulos[filtro] || rotulos.todos) + ' · ' + int(itens.length) + ' itens · ' + fmt(sp) + ' SP',
      origem,
      colunas,
      linhas,
      notas,
      vazio: 'Nenhum item para este filtro.',
    });
  }

  // Gráfico "itens por tipo de issue" (barras horizontais); a barra clicada abre o modal filtrado.
  function graficoPorTipo(card, canvasId, g, rotuloAria, abrirTipo) {
    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = canvasId;
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', rotuloAria + ' Valores no botão Ver itens.');
    wrap.appendChild(cv);
    card.appendChild(wrap);
    const tipos = g.por_tipo || [];
    card._draw = () => {
      const chart = window.Charts.make(canvasId, {
        type: 'bar',
        data: {
          labels: tipos.map(t => t.tipo),
          datasets: [{ label: 'Itens', data: tipos.map(t => t.itens), backgroundColor: '#6366f1', borderRadius: 4 }],
        },
        options: {
          responsive: true, maintainAspectRatio: false, indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: c => ' ' + int(c.raw) + ' itens · ' + fmt((tipos[c.dataIndex] || {}).sp) + ' SP' } },
          },
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Itens' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
            y: { grid: { display: false } },
          },
        },
      });
      window.App.ListaItens.clicavel(chart, cv, (i, rotulo) => abrirTipo(rotulo));
    };
  }
  // ── 5. Sprint anterior ──────────────────────────────────────────────
  function cardSprintAnterior(d) {
    const g = d.gadgets.sprint_anterior;
    const sec = el('div');
    sec.appendChild(el('div', 'stitle', 'Dados da sprint anterior' + (g && g.sprint ? ' — ' + g.sprint : '')));
    if (!g || g.estado !== 'ok') {
      const a = el('div', 'alert alert-info'); a.setAttribute('role', 'status');
      a.appendChild(el('span', null, (g && g.motivo) || 'Nenhuma sprint anterior fechada nesta PI.'));
      sec.appendChild(a);
      return sec;
    }
    const grid = el('div', 'kpi-grid vg-kpi-grid');
    const lista = d.gadgets.lista_sprint_anterior;
    const temLista = !!(lista && lista.estado === 'ok');
    const comLista = (k, filtro) => (temLista ? kpiAcao(k, 'Ver itens', o => abrirItensSprint(d, filtro, o)) : k);
    grid.appendChild(comLista(kpi('Alocados', fmt(g.alocados_sp) + ' SP', 'passaram pela sprint', '#6366f1'), 'todos'));
    grid.appendChild(comLista(kpi('Aceitos na sprint', fmt(g.aceitos_sp) + ' SP',
      'valor ' + fmt(g.aceitos_valor_sp) + ' · sustentação ' + fmt(g.aceitos_sustentacao_sp), '#10b981'), 'aceitos'));
    grid.appendChild(comLista(kpi('Não aceitos sem continuidade', fmt(g.nao_aceitos_sem_continuidade_sp) + ' SP', 'não seguiram para a próxima', '#f59e0b'), 'nao_aceitos'));
    const tr = g.transbordo || {};
    if (tr.disponivel) {
      grid.appendChild(comLista(kpi('Transbordo', fmt(tr.sp) + ' SP',
        int(tr.itens) + ' itens → ' + (tr.para_sprint || '—') + ' · não entregue: ' + fmt(tr.nao_entregue_sp) + ' SP', '#a78bfa'), 'transbordo'));
    } else {
      const k = kpi('Transbordo', 'n/d', 'indisponível', '#a78bfa', tr.motivo || 'Transbordo indisponível para esta sprint.');
      grid.appendChild(k);
    }
    sec.appendChild(grid);
    anexarLegenda(sec, window.App.Legenda.botao('sprint_anterior', d.metodologia));
    sec.appendChild(el('div', 'pr-sub', 'Incidentes/Solicitações: aguardando o módulo de Sustentação.'));
    return sec;
  }

  // ── 6a. Burndown ─────────────────────────────────────────────────────
  function cardBurndown(d) {
    const g = d.gadgets.burndown;
    if (!g || g.estado !== 'ok') return cardVazio('Burndown', g);
    const card = anexarLegenda(cardAprox('Burndown', g.regra), window.App.Legenda.botao('burndown', d.metodologia));
    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-burndown';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Burndown da sprint: linha ideal tracejada e linha real do escopo restante por dia. Valores no botão Ver dados.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const di = g.diagnosticos || {};
    if (di.sustentacao_fora_sp) {
      card.appendChild(el('div', 'pr-sub', 'Sustentação fora do escopo de valor: ' + fmt(di.sustentacao_fora_sp) + ' SP em ' + int(di.sustentacao_fora_itens) + ' itens.'));
    }

    const ab = el('div', 'pr-sub');
    ab.appendChild(botaoModal('Ver dados', origem => window.App.Modal.abrir({
      titulo: 'Burndown — dados por dia',
      subtitulo: 'Escopo ideal e real restante, em Story Points',
      origem,
      construir: corpo => tabelaModal(corpo, 'Burndown: escopo ideal e real restante por dia',
        [['Dia', ''], ['Ideal (SP)', 'num'], ['Real (SP)', 'num']],
        g.dias.map((dia, i) => [dia === 'inicio' ? 'Início' : diaMes(dia), fmt(g.ideal[i]), g.real[i] == null ? '—' : fmt(g.real[i])]), '460px'),
    })));
    card.appendChild(ab);

    card._draw = () => window.Charts.make('vg-burndown', {
      type: 'line',
      data: {
        labels: g.dias.map(x => x === 'inicio' ? 'Início' : diaMes(x)),
        datasets: [
          { label: 'Ideal', data: g.ideal, borderColor: '#7c8aaa', borderDash: [6, 4], borderWidth: 2, pointRadius: 0, tension: 0 },
          { label: 'Real', data: g.real, borderColor: '#22d3ee', backgroundColor: '#22d3ee', borderWidth: 2.5, pointRadius: 3, spanGaps: false, tension: 0 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6b. CFD ──────────────────────────────────────────────────────────
  function cardCfd(d) {
    const g = d.gadgets.cfd;
    if (!g || g.estado !== 'ok') return cardVazio('Fluxo acumulado (CFD)', g);
    const card = anexarLegenda(cardAprox('Fluxo acumulado (CFD)', g.regra), window.App.Legenda.botao('cfd', d.metodologia));
    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-cfd';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Diagrama de fluxo acumulado: áreas empilhadas de Pendente, Em andamento e Concluído por dia. Valores no botão Ver dados.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    const ab = el('div', 'pr-sub');
    const nv = x => (x == null ? '—' : fmt(x));
    ab.appendChild(botaoModal('Ver dados', origem => window.App.Modal.abrir({
      titulo: 'Fluxo acumulado (CFD) — dados por dia',
      subtitulo: 'Pendente, em andamento e concluído, em Story Points',
      origem,
      construir: corpo => tabelaModal(corpo, 'Fluxo acumulado: itens pendentes, em andamento e concluídos por dia',
        [['Dia', ''], ['Pendente (SP)', 'num'], ['Em andamento (SP)', 'num'], ['Concluído (SP)', 'num']],
        g.dias.map((dia, i) => [diaMes(dia), nv(g.pendente[i]), nv(g.em_andamento[i]), nv(g.concluido[i])]), '520px'),
    })));
    card.appendChild(ab);

    card._draw = () => window.Charts.make('vg-cfd', {
      type: 'line',
      data: {
        labels: g.dias.map(diaMes),
        datasets: [
          { label: 'Pendente', data: g.pendente, borderColor: '#7c8aaa', backgroundColor: 'rgba(124,138,170,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
          { label: 'Em andamento', data: g.em_andamento, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
          { label: 'Concluído', data: g.concluido, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.35)', fill: true, borderWidth: 1.5, pointRadius: 0, tension: 0, stack: 'cfd' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, stacked: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6c. Cycle time por status ──────────────────────────────────────────
  function cardCycle(d) {
    const g = d.gadgets.cycle_time_status;
    if (!g || g.estado !== 'ok') return cardVazio('Cycle time por status', g);
    const isPi = (d.filtros || {}).modo_efetivo === 'pi';
    const card = anexarLegenda(cardBase('Cycle time por status', 'dias úteis'), window.App.Legenda.botao('cycle_time', d.metodologia));
    card.appendChild(el('div', 'pr-sub', 'Janela: ' + (g.sprints || []).join(', ') + ' · ' + int(g.itens) + ' itens de valor entregues.'));

    const grid = el('div', 'kpi-grid vg-kpi-grid');
    // A3: cor pelo desvio contra a média da mesma etapa nas sprints fechadas da PI
    // (janela_padrao); alvo_dias de config/sinalizadores.php vence a referência automática.
    const sc = (window.SINAIS || {}).cycle_time || {};
    const alvos = sc.alvo_dias || {};
    const jpRef = g.janela_padrao || null;
    const poucos = !jpRef || (Number(jpRef.itens) || 0) < (Number(sc.min_itens_referencia) || 0);
    const comSinal = (k, valor, ref, alvoKey) => {
      const r = window.App.Sinal.classificar('cycle_time', valor, poucos ? null : ref,
        { alvo: alvos[alvoKey], motivoSemRef: poucos ? 'poucos itens para comparar' : undefined });
      sinalKpi(k, r);
      return k;
    };
    const et = (label, e, refEtapa, alvoKey) => comSinal(
      kpi(label, e.dias == null ? '—' : fmt(e.dias) + ' d', int(e.n) + ' itens', '#6366f1'),
      e.dias, refEtapa && refEtapa.dias, alvoKey);
    grid.appendChild(et('Desenvolvimento', g.desenvolvimento, jpRef && jpRef.desenvolvimento, 'desenvolvimento'));
    grid.appendChild(et('Homologação', g.homologacao, jpRef && jpRef.homologacao, 'homologacao'));
    grid.appendChild(et('Implantação', g.implantacao, jpRef && jpRef.implantacao, 'implantacao'));
    grid.appendChild(comSinal(
      kpi('Lead time médio', g.lead_time_medio == null ? '—' : fmt(g.lead_time_medio) + ' d', int(g.itens) + ' itens', '#22d3ee'),
      g.lead_time_medio, jpRef && jpRef.lead_time_medio, 'lead_time'));
    card.appendChild(grid);

    const jp = g.janela_padrao;
    if (isPi && jp) {
      card.appendChild(el('div', 'pr-sub', 'Sprints fechadas (igual à tela Lead Time): ' + (jp.sprints || []).join(', ')
        + ' · ' + int(jp.itens) + ' itens · lead time ' + (jp.lead_time_medio == null ? '—' : fmt(jp.lead_time_medio) + ' d')
        + ' (dev ' + (jp.desenvolvimento.dias == null ? '—' : fmt(jp.desenvolvimento.dias)) + ' · hml '
        + (jp.homologacao.dias == null ? '—' : fmt(jp.homologacao.dias)) + ' · impl '
        + (jp.implantacao.dias == null ? '—' : fmt(jp.implantacao.dias)) + ').'));
    }
    card.appendChild(el('div', 'pr-sub', 'Cor: ' + window.App.Sinal.legenda('cycle_time') + ' Referência = média da etapa nas sprints fechadas da PI.'));
    return card;
  }

  // ── 6e. Team Velocity (mini) ──────────────────────────────────────────
  function cardTeamVelocity(d) {
    const g = d.gadgets.team_velocity;
    if (!g || g.estado !== 'ok' || !(g.serie || []).length) return cardVazio('Team Velocity', g);
    const card = anexarLegenda(cardBase('Team Velocity', 'SP concluídos por sprint'), window.App.Legenda.botao('team_velocity', d.metodologia));
    const r = g.resumo || {};
    const dest = el('div');
    dest.style.cssText = 'display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:6px';
    const big = el('div', null, r.velocidade_ultimas_n == null ? '— SP' : fmt(r.velocidade_ultimas_n) + ' SP');
    big.style.cssText = 'color:#d62728;font-size:1.6rem;font-weight:700';
    const n = r.n_sprints_na_media || 0;
    dest.append(big, el('div', 'kpi-sub', n > 0 ? 'velocidade das últimas ' + n + ' fechadas' : 'sem sprint fechada'));
    card.appendChild(dest);

    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-velmini';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras do Concluído por sprint (a sprint em andamento hachurada). Valores no botão Ver dados.');
    wrap.appendChild(cv);
    card.appendChild(wrap);
    const linkP = el('div', 'pr-sub');
    const a = document.createElement('a');
    a.href = BASE + '/metricas/velocidade';
    a.textContent = 'Ver a tela de Velocidade completa';
    a.style.color = '#a5b4fc';
    linkP.appendChild(a);
    card.appendChild(linkP);

    const colsVel = [['Sprint', ''], ['Status', ''], ['Escopo inicial (SP)', 'num'], ['Adicionado (SP)', 'num'], ['Removido (SP)', 'num'],
      ['Escopo final (SP)', 'num'], ['Mudança de escopo (SP)', 'num'], ['Concluído (SP)', 'num'], ['Velocidade média (SP)', 'num']];
    const linhaVel = s => [s.sprint, s.status === 'em_andamento' ? 'Ativa' : 'Fechada', fmt(s.escopo_inicial), fmt(s.adicionado), fmt(s.removido),
      fmt(s.escopo_final), fmt(s.mudanca_escopo), fmt(s.concluido), fmt(s.velocidade_media)];
    const abrirVel = (series, origem) => window.App.Modal.abrir({
      titulo: 'Team Velocity — dados por sprint',
      subtitulo: 'Story Points; estado atual dos itens (não o fim da sprint)',
      origem,
      construir: corpo => tabelaModal(corpo, 'Team Velocity: escopo, mudanças e concluído por sprint', colsVel, series.map(linhaVel), '860px'),
    });
    const ab = el('div', 'pr-sub');
    ab.appendChild(botaoModal('Ver dados', origem => abrirVel(g.serie, origem)));
    card.appendChild(ab);
    const btnVel = ab.firstChild;
    cv.classList.add('vg-clicavel');

    card._draw = () => window.Charts.make('vg-velmini', {
      type: 'bar',
      data: {
        labels: g.serie.map(s => s.sprint),
        datasets: [{
          label: 'Concluído', data: g.serie.map(s => s.concluido),
          backgroundColor: g.serie.map(s => s.status === 'em_andamento' ? 'rgba(44,160,44,.45)' : '#2ca02c'),
          borderColor: '#2ca02c', borderWidth: g.serie.map(s => s.status === 'em_andamento' ? 1.5 : 0),
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        onClick: (ev, _els, chart) => {
          const pts = chart.getElementsAtEventForMode(ev, 'nearest', { intersect: true }, true);
          if (pts.length && g.serie[pts[0].index]) abrirVel([g.serie[pts[0].index]], btnVel);
        },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' Concluído: ' + fmt(c.raw) + ' SP' } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: false } },
          y: { beginAtZero: true, title: { display: true, text: 'Story Points' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
        },
      },
    });
    return card;
  }

  // ── 6f. Chamados ─────────────────────────────────────────────────────
  function cardChamados(d) {
    const g = d.gadgets.chamados;
    if (!g || g.estado !== 'ok') return cardVazio('Chamados', g);
    const card = anexarLegenda(cardAprox('Chamados', g.regra), window.App.Legenda.botao('chamados', d.metodologia));
    card.appendChild(el('div', 'pr-sub', 'Últimos ' + int(g.janela_dias) + ' dias · independe do seletor · limite de ' + int(g.limite_dias) + ' dia(s) úteis.'));

    const { wrap: tw, tb } = tabelaBase(null, 'Chamados por tipo: quantidade, média e mediana de dias, percentual dentro do limite', '540px');
    cabecalho(tb, [['Tipo', ''], ['Qtd', 'num'], ['Média (dias)', 'num'], ['Mediana (dias)', 'num'], ['% no limite', 'num'], ['Média desde criação', 'num']]);
    const tbody = el('tbody');
    (g.por_tipo || []).forEach(t => {
      const tr = el('tr');
      tr.appendChild(el('td', null, t.tipo));
      tr.appendChild(el('td', 'num', int(t.n)));
      tr.appendChild(el('td', 'num', fmt(t.media_dias)));
      tr.appendChild(el('td', 'num', fmt(t.mediana_dias)));
      tr.appendChild(el('td', 'num', pctF(t.pct_dentro_limite)));
      tr.appendChild(el('td', 'num', fmt(t.media_dias_desde_criacao)));
      tbody.appendChild(tr);
    });
    tb.appendChild(tbody);
    card.appendChild(tw);

    const c = g.cobertura || {};
    card.appendChild(el('div', 'pr-sub', 'Cobertura: ' + int(c.resolvidos_pi) + ' resolvidos na PI · ' + int(c.com_fim_fluxo)
      + ' com data de fim · ' + int(c.na_janela) + ' na janela · ' + pctF(c.pct_com_data) + ' com data.'));
    return card;
  }

  // ── 7. Tempo por status ────────────────────────────────────────────────
  function cardTempoStatus(d) {
    const g = d.gadgets.tempo_status;
    if (!g || g.estado !== 'ok') return cardVazio('Tempo do item em cada status', g, true);
    const isPi = (d.filtros || {}).modo_efetivo === 'pi';
    const card = anexarLegenda(cardAprox('Tempo do item em cada status', g.regra, true), window.App.Legenda.botao('tempo_status', d.metodologia));
    const fases = g.fases || [];

    const wrap = el('div', 'cwrap');
    const cv = document.createElement('canvas');
    cv.id = 'vg-tempo';
    cv.setAttribute('role', 'img');
    cv.setAttribute('aria-label', 'Barras horizontais da média de dias úteis por fase (' + fases.join(', ') + '). Valores na tabela de médias abaixo; itens no botão Ver itens.');
    wrap.appendChild(cv);
    card.appendChild(wrap);

    if (isPi) card.appendChild(el('div', 'pr-sub', 'No modo PI a lista mostra só itens em andamento; as médias consideram todos os itens da janela.'));

    // Tabela de médias
    const { wrap: tmw, tb: tmb } = tabelaBase('Médias por fase', 'Média de dias úteis por fase', '420px');
    cabecalho(tmb, [['Fase', ''], ['Média (dias)', 'num'], ['Itens', 'num']]);
    const tmbody = el('tbody');
    // A3: referência = média da fase nas sprints fechadas da PI (g.referencia);
    // alvo_dias de config/sinalizadores.php vence a referência automática.
    const refFase = {};
    (g.referencia || []).forEach(r => { refFase[r.fase] = r; });
    const alvosFase = ((window.SINAIS || {}).cycle_time || {}).alvo_dias || {};
    const nivelFase = {};
    (g.medias || []).forEach(m => {
      nivelFase[m.fase] = window.App.Sinal.classificar('cycle_time', m.dias, (refFase[m.fase] || {}).dias,
        { alvo: alvosFase[CHAVE_FASE[m.fase]], motivoSemRef: 'sem referência nas sprints fechadas' });
    });
    (g.medias || []).forEach(m => {
      const tr = el('tr');
      tr.appendChild(el('td', null, m.fase));
      const tdMedia = el('td', 'num', fmt(m.dias));
      window.App.Sinal.aplicar(tdMedia, nivelFase[m.fase].nivel, nivelFase[m.fase].motivo);
      tr.appendChild(tdMedia);
      tr.appendChild(el('td', 'num', int(m.n)));
      tmbody.appendChild(tr);
    });
    tmb.appendChild(tmbody);
    card.appendChild(tmw);
    card.appendChild(el('div', 'pr-sub', 'Cor: ' + window.App.Sinal.legenda('cycle_time') + ' Referência = média da fase nas sprints fechadas da PI.'));

    // Itens: modal (botão "Ver itens" ou clique na barra de uma fase)
    const abrirItensTempo = (fase, origem) => {
      const todos = g.itens || [];
      const itens = fase ? todos.filter(it => it.dias && it.dias[fase] != null) : todos;
      const colunas = [
        { k: 'chave', rotulo: 'Chave', tipo: 'chave' },
        { k: 'tipo', rotulo: 'Tipo', tipo: 'texto' },
        { k: 'status', rotulo: 'Status', tipo: 'status' },
        { k: 'sp', rotulo: 'SP', tipo: 'num' },
        { k: 'fase_atual', rotulo: 'Fase atual', tipo: 'fase' },
      ];
      fases.forEach((f, i) => colunas.push({ k: 'f' + i, rotulo: f, tipo: 'dias', casas: 0 }));
      colunas.push({ k: 'total_dias', rotulo: 'Total (dias)', tipo: 'dias', casas: 0 });
      const linhas = itens.map(it => {
        const l = Object.assign({}, it);
        fases.forEach((f, i) => { l['f' + i] = it.dias ? it.dias[f] : null; });
        return l;
      });
      window.App.ListaItens.abrir({
        titulo: 'Tempo por status — itens' + (fase ? ' que passaram por ' + fase : ''),
        subtitulo: int(itens.length) + ' itens · dias úteis por fase' + (g.truncado ? ' · lista truncada: o filtro vale só para as linhas recebidas' : ''),
        origem,
        colunas,
        linhas,
        vazio: 'Nenhum item passou por esta fase.',
      });
    };
    const btnItens = botaoModal('Ver itens', o => abrirItensTempo(null, o));
    const abItens = el('div', 'pr-sub');
    abItens.appendChild(btnItens);
    card.appendChild(abItens);
    cv.classList.add('vg-clicavel');
    if (g.truncado) card.appendChild(el('div', 'pr-sub', 'Lista truncada: exibindo os primeiros itens.'));

    const di = g.diagnosticos || {};
    if (di.sustentacao_fora_sp) card.appendChild(el('div', 'pr-sub', 'Sustentação fora do escopo de valor: ' + fmt(di.sustentacao_fora_sp) + ' SP em ' + int(di.sustentacao_fora_itens) + ' itens.'));

    card._draw = () => window.Charts.make('vg-tempo', {
      type: 'bar',
      data: {
        labels: fases,
        datasets: [{
          label: 'Média de dias úteis', data: (g.medias || []).map(m => m.dias),
          backgroundColor: (g.medias || []).map(m => COR_NIVEL[nivelFase[m.fase].nivel] || COR_NIVEL.neutro), borderRadius: 4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        onClick: (ev, _els, chart) => {
          const pts = chart.getElementsAtEventForMode(ev, 'nearest', { intersect: true }, true);
          if (pts.length && fases[pts[0].index]) abrirItensTempo(fases[pts[0].index], btnItens);
        },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + fmt(c.raw) + ' dias' } } },
        scales: {
          x: { beginAtZero: true, title: { display: true, text: 'Dias úteis' }, grid: { color: '#2e3349', borderDash: [4, 4] } },
          y: { grid: { display: false } },
        },
      },
    });
    return card;
  }

  // ── 8. Listas de itens: sprint atual (ou PI) e sprint anterior ───────────
  // Mesmo desenho nos dois cards: gráfico por tipo de issue + botão "Ver itens".
  function cardListaItens(d, chave, o) {
    const g = d.gadgets[chave];
    if (!g || g.estado !== 'ok') return cardVazio(o.titulo, g, true);
    const card = cardBase(o.titulo + (g.sprint ? ' — ' + g.sprint : ''), null, true);

    const ab = el('div', 'pr-sub');
    const btn = window.App.ListaItens.botao('Ver itens', origem => abrirItensSprint(d, 'todos', origem, g));
    ab.appendChild(btn);
    ab.appendChild(o.legenda());
    card.appendChild(ab);

    if ((g.por_tipo || []).length) {
      card.appendChild(el('div', 'stitle', 'Itens por tipo de issue'));
      graficoPorTipo(card, o.canvasId, g, 'Barras horizontais com a quantidade de itens por tipo de issue.',
        tipo => abrirItensSprint(d, 'todos', btn, g, tipo));
    }

    const notas = ['Σ ' + fmt(g.total_sp) + ' SP em ' + int(g.total_itens) + ' itens.'];
    if (g.resumo_disponivel_pct != null) notas.push('Resumo disponível em ' + pctF(g.resumo_disponivel_pct) + ' dos itens.');
    if (g.truncado) notas.push('Lista truncada ao limite de linhas.');
    card.appendChild(el('div', 'pr-sub', notas.join(' ')));
    return card;
  }
  function cardListaAtual(d) {
    const g = d.gadgets.lista_sprint_atual;
    const titulo = g && g.escopo === 'pi' ? 'Itens da PI' : 'Itens da sprint atual';
    return cardListaItens(d, 'lista_sprint_atual', {
      titulo, canvasId: 'vg-itens-atual',
      legenda: () => window.App.Legenda.botao('lista_sprint_atual', d.metodologia, { rotulo: 'ⓘ Como é calculado: ' + titulo }),
    });
  }
  function cardLista(d) {
    return cardListaItens(d, 'lista_sprint_anterior', {
      titulo: 'Itens da sprint anterior', canvasId: 'vg-itens-ant',
      legenda: () => window.App.Legenda.botao('lista_sprint_anterior', d.metodologia, { rotulo: 'ⓘ Como é calculado: Itens da sprint anterior' }),
    });
  }
  // ── 9. Placeholder de Sustentação ────────────────────────────────────
  function cardSustentacao(d) {
    const g = d.gadgets.sustentacao || {};
    const card = el('div', 'ccard vg-wide vg-placeholder');
    card.appendChild(el('h3', null, 'Sustentação — aguardando módulo independente'));
    card.appendChild(el('div', 'pr-sub', 'Os gadgets abaixo dependem do módulo de Sustentação (regras ainda não definidas). A divisão por 5 do Jira corresponde à Produtividade (horas por ponto) da jornada/time.'));
    const abLeg = el('div', 'pr-sub');
    abLeg.appendChild(window.App.Legenda.botao('sustentacao_placeholder', d.metodologia, { rotulo: 'ⓘ Como é calculado: Sustentação' }));
    card.appendChild(abLeg);
    const ul = el('ul');
    ul.style.cssText = 'margin:8px 0 0;padding-left:18px;font-size:.78rem;color:var(--muted)';
    (g.gadgets || []).forEach(t => ul.appendChild(el('li', null, t)));
    card.appendChild(ul);
    return card;
  }

  // ── 10. Rodapé "Como ler esta tela" ──────────────────────────────────
  function rodape() {
    const box = el('div', 'alert alert-info');
    box.style.marginTop = '18px';
    box.setAttribute('role', 'note');
    box.appendChild(el('strong', null, 'Como ler esta tela'));
    const ul = el('ul'); ul.style.cssText = 'margin:6px 0 0;padding-left:18px;font-size:.78rem';
    [
      'Visão por jornada, consolidando os projetos dos times da seleção. Os filtros do cabeçalho (jornada, time, versão, sprint) controlam a tela; ADSE e SGSE são o mesmo time.',
      'Seletor "Sprint ativa | PI inteira": mostra a sprint em foco ou a PI inteira (sprints de valor já iniciadas). Um filtro de sprint no cabeçalho vence o seletor.',
      'Unidade: Story Points, universo de valor + sustentação (como no Planejado x Realizado). Aceitos = Entregues do Planejado x Realizado = Concluído da Velocidade.',
      'Cycle time bate com a tela Lead Time (bloco "sprints fechadas"). Efficiency mostra as horas por SP dos itens de valor em Done contra o baseline h/SP de cada time, com o estado de hoje; o histórico por sprint fica na tela Produtividade. Fator de Carga mostra a demanda (SPE × baseline h/SP) contra a capacidade líquida do Painel.',
      'O botão "ⓘ Como é calculado" de cada bloco abre fonte, unidade, fórmula, o que entra e não entra e a regra de Done.',
      'Burndown, Fluxo acumulado (CFD), Tempo por status e Chamados são aproximados por datas de fluxo (não há burndown nativo de sprint); a badge "aproximado" sinaliza isso.',
      'Os gadgets de Sustentação aguardam o módulo independente; o divisor ÷5 do Jira equivale à Produtividade (horas por ponto).',
    ].forEach(t => ul.appendChild(el('li', null, t)));
    box.appendChild(ul);
    return box;
  }

  // ── Render ─────────────────────────────────────────────────────────────
  function render(d) {
    root.textContent = '';
    root.appendChild(el('div', 'stitle', 'Visão Geral'));
    root.appendChild(faixaContexto(d));
    root.appendChild(seletor(d));
    const av = avisos(d);
    if (av) root.appendChild(av);

    // 4 e 5: andamento e sprint anterior
    root.appendChild(cardAndamento(d));
    root.appendChild(cardSprintAnterior(d));

    // 6: grade de gráficos (coleta os _draw para desenhar após inserir no DOM)
    const aDesenhar = [];
    // Grade fluida: cards com dados em pares; o último sem par e o Cycle time
    // ocupam a linha toda; cards vazios/indisponíveis vão ao fim, em linha inteira.
    const grade = el('div', 'cgrid vg-grid');
    const cycle = cardCycle(d);
    const pares = [cardBurndown(d), cardCfd(d), cardTeamVelocity(d), cardChamados(d)];
    const comDados = pares.filter(c => !c._vazio);
    const vazios = pares.filter(c => c._vazio);
    if (cycle._vazio) vazios.unshift(cycle);
    if (comDados.length % 2 === 1) comDados[comDados.length - 1].classList.add('vg-span');
    const ordem = comDados.concat(cycle._vazio ? [] : [cycle], vazios);
    if (!cycle._vazio) cycle.classList.add('vg-span');
    vazios.forEach(c => c.classList.add('vg-span'));
    ordem.forEach(c => {
      grade.appendChild(c);
      if (c._draw) aDesenhar.push(c);
    });
    root.appendChild(grade);

    // 7: tempo por status (largo)
    const tempo = cardTempoStatus(d);
    tempo.style.marginTop = '18px';
    root.appendChild(tempo);
    if (tempo._draw) aDesenhar.push(tempo);

    // 8: listas (largas): sprint atual e sprint anterior
    [cardListaAtual(d), cardLista(d)].forEach(c => {
      c.style.marginTop = '18px';
      root.appendChild(c);
      if (c._draw) aDesenhar.push(c);
    });

    // 9: placeholder de sustentação
    const sust = cardSustentacao(d);
    sust.style.marginTop = '18px';
    root.appendChild(sust);

    // 10: rodapé
    root.appendChild(rodape());

    // Desenha os gráficos depois de tudo no DOM (canvas precisa de tamanho).
    aDesenhar.forEach(c => { try { c._draw(); } catch (e) { console.warn('Falha ao desenhar gráfico da Visão Geral:', e.message); } });
  }

  async function load() {
    try {
      render(await buscar());
    } catch (e) {
      mensagem('error', 'Não foi possível exibir a Visão Geral.', e.message);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})();
</script>
