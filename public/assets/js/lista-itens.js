/* ============================================================
   lista-itens.js — Modal padrão de listagem de itens (v1.6)
   App.ListaItens.abrir({ titulo, subtitulo, origem, colunas, linhas, notas, vazio, sinal })
   App.ListaItens.botao(rotulo, abrirFn)
   App.ListaItens.linkJira(chave)
   App.ListaItens.clicavel(chart, canvas, fn)
   Todo texto entra por textContent/DOM (nunca innerHTML).
   ============================================================ */

(function () {
  const RE_CHAVE = /^[A-Z][A-Z0-9]+-\d+$/;
  const MAX_SEM_LARGO = 6;

  function jiraBase() {
    return String(window.JIRA_BASE || '').replace(/\/+$/, '');
  }

  /** <a> para o Jira quando a chave é válida e há host; senão <span>. */
  function linkJira(chave) {
    const txt = chave == null ? '' : String(chave);
    const base = jiraBase();
    if (base && RE_CHAVE.test(txt)) {
      const a = document.createElement('a');
      a.className = 'li-link';
      a.href = base + '/browse/' + encodeURIComponent(txt);
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = txt;
      return a;
    }
    const s = document.createElement('span');
    s.className = 'li-chave';
    s.textContent = txt || '—';
    return s;
  }

  function fmtNum(v, d) {
    const n = Number(v);
    if (v === null || v === undefined || v === '' || !isFinite(n)) return '—';
    return n.toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  function fmtData(v) {
    if (!v) return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v));
    return m ? m[3] + '/' + m[2] + '/' + m[1] : String(v);
  }

  /** Preenche a célula conforme o tipo da coluna. */
  function celula(td, col, valor) {
    const tipo = col.tipo || 'texto';
    if (tipo === 'chave') {
      td.className = 'li-col-chave';
      td.appendChild(linkJira(valor));
    } else if (tipo === 'num') {
      td.className = 'num';
      td.textContent = fmtNum(valor, col.casas != null ? col.casas : 1);
    } else if (tipo === 'dias') {
      td.className = 'num';
      td.textContent = valor == null || valor === '' ? '—' : fmtNum(valor, col.casas != null ? col.casas : 1);
    } else if (tipo === 'data') {
      td.textContent = fmtData(valor);
    } else if (tipo === 'status' || tipo === 'fase') {
      td.className = 'li-' + tipo;
      td.textContent = valor == null || valor === '' ? '—' : String(valor);
    } else {
      td.textContent = valor == null || valor === '' ? '—' : String(valor);
    }
  }

  function abrir(opts) {
    opts = opts || {};
    const colunas = opts.colunas || [];
    const linhas = opts.linhas || [];

    window.App.Modal.abrir({
      titulo: opts.titulo,
      subtitulo: opts.subtitulo,
      origem: opts.origem,
      construir(corpo) {
        if (!linhas.length) {
          const p = document.createElement('p');
          p.className = 'li-vazio';
          p.textContent = opts.vazio || 'Nenhum item.';
          corpo.appendChild(p);
        } else {
          const wrap = document.createElement('div');
          wrap.className = 'twrap li-wrap';
          const tbl = document.createElement('table');
          tbl.className = 'prev-tbl li-tbl';
          const thead = document.createElement('thead');
          const trh = document.createElement('tr');
          colunas.forEach(c => {
            const th = document.createElement('th');
            th.scope = 'col';
            if (c.tipo === 'num' || c.tipo === 'dias') th.className = 'num';
            th.textContent = c.rotulo || c.k;
            trh.appendChild(th);
          });
          thead.appendChild(trh);
          const tbody = document.createElement('tbody');
          let somaSp = 0;
          const temSp = colunas.some(c => c.k === 'sp' && c.tipo === 'num');
          linhas.forEach(l => {
            const tr = document.createElement('tr');
            if (typeof opts.sinal === 'function') {
              const nivel = opts.sinal(l);
              if (nivel === 'verde' || nivel === 'amarelo' || nivel === 'vermelho') {
                tr.className = 'li-sinal li-sinal-' + nivel;
              }
            }
            colunas.forEach(c => {
              const td = document.createElement('td');
              celula(td, c, l[c.k]);
              tr.appendChild(td);
            });
            if (temSp) {
              const n = Number(l.sp);
              if (isFinite(n)) somaSp += n;
            }
            tbody.appendChild(tr);
          });
          tbl.append(thead, tbody);
          wrap.appendChild(tbl);
          corpo.appendChild(wrap);

          const rod = document.createElement('div');
          rod.className = 'li-rodape';
          let txt = linhas.length + (linhas.length === 1 ? ' item' : ' itens');
          if (temSp) txt += ' · ' + fmtNum(somaSp, 1) + ' SP';
          rod.textContent = txt;
          corpo.appendChild(rod);
        }
        (opts.notas || []).forEach(n => {
          const p = document.createElement('p');
          p.className = 'li-nota';
          p.textContent = n;
          corpo.appendChild(p);
        });
      },
    });

    // Largura maior para tabelas com muitas colunas; removida ao fechar.
    const dlg = document.getElementById('dlg-detalhe');
    if (dlg) {
      dlg.classList.toggle('modal-wide', colunas.length > MAX_SEM_LARGO);
      dlg.addEventListener('close', () => dlg.classList.remove('modal-wide'), { once: true });
    }
  }

  /** Botão real (teclado) que abre a listagem. */
  function botao(rotulo, abrirFn) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn vg-ajuda';
    b.setAttribute('aria-haspopup', 'dialog');
    b.textContent = rotulo;
    b.addEventListener('click', () => { if (typeof abrirFn === 'function') abrirFn(b); });
    return b;
  }

  /** Clique no gráfico (Chart.js) → fn(indice, rotulo). Atalho; o botão é o caminho acessível. */
  function clicavel(chart, canvas, fn) {
    if (!chart || !canvas || typeof fn !== 'function') return;
    canvas.classList.add('vg-clicavel');
    canvas.style.cursor = 'pointer';
    canvas.addEventListener('click', ev => {
      const pts = chart.getElementsAtEventForMode(ev, 'nearest', { intersect: true }, true);
      if (!pts || !pts.length) return;
      const i = pts[0].index;
      const rot = chart.data && chart.data.labels ? chart.data.labels[i] : null;
      fn(i, rot);
    });
  }

  window.App = window.App || {};
  window.App.ListaItens = { abrir, botao, linkJira, clicavel };
})();
