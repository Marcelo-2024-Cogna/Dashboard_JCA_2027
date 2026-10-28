/* ============================================================
   app.js — Núcleo do frontend
   - API helper (fetch às rotas PHP)
   - Filtros globais (Jornada, Versão, Sprint) persistidos na URL
   - Utilitários compartilhados pelas views
   ============================================================ */

(function () {
  const BASE = window.APP_BASE || '';

  // ── API helper (com cache em memória p/ GETs pesados como /extract) ──────
  const _cache = {};
  const Api = {
    async get(path, params) {
      const qs = params ? ('?' + new URLSearchParams(params)) : '';
      const key = path + qs;
      // Reusa a MESMA promise durante o carregamento da página (evita baixar
      // /extract 7MB duas vezes: uma nos filtros, outra na view).
      if (_cache[key]) return _cache[key];
      const p = fetch(`${BASE}${path}${qs}`, { headers: { 'Accept': 'application/json' } })
        .then(res => {
          if (!res.ok) throw new Error(`HTTP ${res.status} em ${path}`);
          return res.json();
        })
        .catch(err => { delete _cache[key]; throw err; }); // não cacheia erro
      _cache[key] = p;
      return p;
    },
    /** Invalida o cache de um GET (ex: após forçar reextração). */
    invalidate(path) {
      Object.keys(_cache).forEach(k => { if (k.startsWith(path)) delete _cache[k]; });
    },
    async post(path, body) {
      const res = await fetch(`${BASE}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error(`HTTP ${res.status} em ${path}`);
      return res.json();
    },
  };

  // ── Estado de filtros (lido/gravado na query string da própria página) ──
  const Filters = {
    read() {
      const u = new URLSearchParams(location.search);
      return {
        jornada: u.get('jornada') || '',
        time:    u.get('time')    || '',
        versao:  u.get('versao')  || '',
        sprint:  u.get('sprint')  || '',
      };
    },
    apply(partial) {
      const u = new URLSearchParams(location.search);
      Object.entries(partial).forEach(([k, v]) => {
        if (v) u.set(k, v); else u.delete(k);
      });
      // Recarrega a MESMA página com os novos filtros (páginas PHP tradicionais)
      location.search = u.toString();
    },
  };

  // ── Utilitários ──────────────────────────────────────────────────────────
  const Util = {
    fmt(v, d = 1) { return v == null ? '—' : (typeof v === 'number' ? v.toFixed(d) : v); },
    shortSprint(s) { return !s ? '—' : s.split(';')[0].replace(/\[.*?\]\s*/g, '').trim(); },
    shortTeam(p)   { return !p ? '—' : (p.replace(/\[.*?\]\s*/g, '').trim() || p); },
    loading(el, on = true) {
      if (!el) return;
      el.innerHTML = on ? '<div class="loading"><div class="spinner"></div> Carregando...</div>' : '';
    },
    error(el, msg) {
      if (el) el.innerHTML = `<div class="alert alert-error"><strong>Erro:</strong> ${msg}</div>`;
    },
    /** Jornada do item (prefixo "[Jornada] Time" do projeto). */
    jornadaOf(i) { const m = /^\[([^\]]+)\]/.exec(i.projeto || ''); return m ? m[1].trim() : ''; },
    /** Time do item (parte após "[Jornada] "). */
    teamOf(i) { const m = /^\[[^\]]+\]\s*(.*)$/.exec(i.projeto || ''); return m && m[1] ? m[1].trim() : ''; },
    /**
     * Aplica os filtros globais (jornada/time/versão/sprint) a uma lista de
     * itens do /extract. Centraliza a lógica usada por todas as views.
     */
    applyFilters(items, filters) {
      const f = filters || Filters.read();
      return items.filter(i => {
        if (f.jornada && this.jornadaOf(i) !== f.jornada) return false;
        if (f.time    && this.teamOf(i)    !== f.time)    return false;
        if (f.versao  && (i.versoes_corrigidas || '').split(';')[0].trim() !== f.versao) return false;
        if (f.sprint  && this.shortSprint(i.sprint) !== f.sprint) return false;
        return true;
      });
    },
    /** Tipos de entrega de valor (de window.VALUE_TYPES). */
    isValueType(i) {
      const vt = window.VALUE_TYPES || [];
      return vt.length ? vt.includes(i.tipo_item) : true;
    },
  };

  // ── Inicializa a barra de filtros (preenche selects, liga eventos) ───────
  async function initFilters() {
    const selJornada = document.getElementById('flt-jornada');
    const selTime    = document.getElementById('flt-time');
    const selVersao  = document.getElementById('flt-versao');
    const selSprint  = document.getElementById('flt-sprint');
    if (!selJornada) return; // páginas sem filtros (ex: painel)

    const cur = Filters.read();

    // Popula os selects a partir dos dados de extração (cache), com CASCATA:
    // Jornada → Time → Versão → Sprint.
    try {
      const json = await Api.get('/extract');
      const items = (json.items) || [];

      // Pré-processa os itens com jornada/time/versão/sprint normalizados
      const rows = items.map(i => {
        const mj = /^\[([^\]]+)\]\s*(.*)$/.exec(i.projeto || '');
        return {
          jornada: mj ? mj[1].trim() : '',
          time:    mj && mj[2] ? mj[2].trim() : '',
          versao:  i.versoes_corrigidas ? i.versoes_corrigidas.split(';')[0].trim() : '',
          sprint:  i.sprint ? Util.shortSprint(i.sprint) : '',
        };
      });

      const j = cur.jornada, t = cur.time, v = cur.versao;
      // Jornadas: todas
      const jornadas = [...new Set(rows.map(r => r.jornada).filter(Boolean))].sort();
      // Times: filtrados pela jornada
      const times = [...new Set(rows.filter(r => !j || r.jornada === j).map(r => r.time).filter(Boolean))].sort();
      // Versões: filtradas por jornada E time
      const versoes = [...new Set(rows.filter(r => (!j || r.jornada === j) && (!t || r.time === t)).map(r => r.versao).filter(Boolean))].sort();
      // Sprints: filtradas por jornada, time E versão
      const sprints = [...new Set(rows.filter(r => (!j || r.jornada === j) && (!t || r.time === t) && (!v || r.versao === v)).map(r => r.sprint).filter(Boolean))].sort();

      fillSelect(selJornada, jornadas, cur.jornada);
      fillSelect(selTime,    times,    cur.time);
      fillSelect(selVersao,  versoes,  cur.versao);
      fillSelect(selSprint,  sprints,  cur.sprint);
    } catch (e) {
      console.warn('Falha ao popular filtros:', e.message);
    }

    // Cascata: mudar um nível superior reseta os inferiores.
    selJornada.addEventListener('change', () => Filters.apply({ jornada: selJornada.value, time: '', versao: '', sprint: '' }));
    selTime   .addEventListener('change', () => Filters.apply({ time:    selTime.value, versao: '', sprint: '' }));
    selVersao .addEventListener('change', () => Filters.apply({ versao:  selVersao.value, sprint: '' }));
    selSprint .addEventListener('change', () => Filters.apply({ sprint:  selSprint.value }));

    renderActiveFilters(cur);
  }

  // ── Labels destacados dos filtros ativos ─────────────────────────────────
  function renderActiveFilters(cur) {
    const box = document.getElementById('active-filters');
    if (!box) return;
    const chips = [
      { label: 'Jornada', value: cur.jornada, ac: '#6366f1' },
      { label: 'Time',    value: cur.time,    ac: '#22d3ee' },
      { label: 'Versão',  value: cur.versao,  ac: '#f59e0b' },
      { label: 'Sprint',  value: cur.sprint,  ac: '#10b981' },
    ].filter(c => c.value);
    box.innerHTML = chips.map(c =>
      `<span class="filter-chip" style="--ac:${c.ac}">
         <span class="chip-label">${c.label}</span>
         <span class="chip-value">${c.value}</span>
       </span>`).join('');
  }

  function fillSelect(sel, options, current) {
    const first = sel.querySelector('option'); // mantém "Todas"
    sel.innerHTML = '';
    sel.appendChild(first);
    options.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o; opt.textContent = o;
      if (o === current) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  // ── Exportação (carrega libs sob demanda via CDN) ────────────────────────
  async function exportPdf() {
    await loadScript('https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js');
    const el = document.getElementById('main-content');
    window.html2pdf().set({
      margin: 8, filename: `metricas-${location.pathname.split('/').pop() || 'home'}.pdf`,
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, backgroundColor: '#0f1117' },
      jsPDF: { orientation: 'landscape', unit: 'mm', format: 'a4' },
    }).from(el).save();
  }

  async function exportXlsx() {
    await loadScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
    // Exporta as tabelas presentes na página (se houver)
    const tables = document.querySelectorAll('table');
    if (!tables.length) { alert('Nenhuma tabela para exportar nesta tela.'); return; }
    const wb = window.XLSX.utils.book_new();
    tables.forEach((t, i) => {
      const ws = window.XLSX.utils.table_to_sheet(t);
      window.XLSX.utils.book_append_sheet(wb, ws, `Tabela${i + 1}`);
    });
    window.XLSX.writeFile(wb, `metricas-${location.pathname.split('/').pop() || 'home'}.xlsx`);
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`script[src="${src}"]`)) return resolve();
      const s = document.createElement('script');
      s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('Falha ao carregar ' + src));
      document.head.appendChild(s);
    });
  }

  // ── Bootstrap da página ──────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    initFilters();
    document.getElementById('btn-export-pdf')?.addEventListener('click', exportPdf);
    document.getElementById('btn-export-xlsx')?.addEventListener('click', exportXlsx);
  });

  // Exporta para as views usarem
  window.App = { Api, Filters, Util, BASE };
})();
