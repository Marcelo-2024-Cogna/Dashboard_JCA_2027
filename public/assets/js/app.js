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
    /** projeto_key do item (prefixo da chave: "SGGU-123" → "SGGU"). */
    keyOf(i) { const m = /^([A-Za-z0-9]+)-\d+$/.exec(i.chave || ''); return m ? m[1].toUpperCase() : ''; },
    /**
     * Aplica os filtros globais (jornada/time/versão/sprint) a uma lista de
     * itens do /extract (páginas LEGADAS). Os filtros do header agora usam
     * nomes do CATÁLOGO do banco; aqui:
     *   - jornada/time: casam pelo projeto_key da chave do item, usando o
     *     catálogo de /filtros (App.catalog) quando já carregado; senão,
     *     melhor esforço pelo texto do Jira (jornada sem o prefixo "Jornada";
     *     time só casa se o nome for igual ao do Jira);
     *   - versão: "contém" em versoes_corrigidas (ex.: "[SGGU] PI 4/26" ∋ "PI 4/26");
     *   - sprint: "contém" em sprint (ex.: "[SGGU] SP 26.4.1;..." ∋ "SP 26.4.1").
     */
    applyFilters(items, filters) {
      const f = filters || Filters.read();
      const cat = window.App && window.App.catalog;
      const norm = s => (s || '').replace(/^Jornada\s+/i, '').trim().toLowerCase();
      const reSprint = f.sprint ? sprintRegex(f.sprint) : null;
      return items.filter(i => {
        if (f.jornada) {
          const keys = cat && cat.keysByJornada[f.jornada];
          const k = this.keyOf(i);
          const ok = keys && k ? keys.has(k) : norm(this.jornadaOf(i)) === norm(f.jornada);
          if (!ok) return false;
        }
        if (f.time) {
          const keys = cat && cat.keysByTime[f.time];
          const k = this.keyOf(i);
          const ok = keys && k ? keys.has(k) : this.teamOf(i) === f.time;
          if (!ok) return false;
        }
        if (f.versao && !(i.versoes_corrigidas || '').includes(f.versao)) return false;
        if (reSprint && !reSprint.test(i.sprint || '')) return false;
        return true;
      });
    },
    /** Tipos de entrega de valor (de window.VALUE_TYPES). */
    isValueType(i) {
      const vt = window.VALUE_TYPES || [];
      return vt.length ? vt.includes(i.tipo_item) : true;
    },
  };

  /** Regex que casa o número da sprint ("SP 26.4.1" → 26.4.1) sem confundir 26.4.1 com 26.4.10. */
  function sprintRegex(name) {
    const num = (/\d+\.\d+\.\d+/.exec(name) || [name])[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp('(?<![\\d.])' + num + '(?![\\d.]*\\d)');
  }

  /** Índices do catálogo de /filtros usados pelo Util.applyFilters (legado). */
  function indexCatalog(cat) {
    const keysByJornada = {}, keysByTime = {};
    (cat.times || []).forEach(t => {
      (keysByJornada[t.jornada] = keysByJornada[t.jornada] || new Set()).add(t.projeto_key);
      (keysByTime[t.time] = keysByTime[t.time] || new Set()).add(t.projeto_key);
    });
    return { raw: cat, keysByJornada, keysByTime };
  }

  // ── Inicializa a barra de filtros (preenche selects, liga eventos) ───────
  // Fonte: GET /filtros (catálogo do BANCO). Cascata: Jornada → Time →
  // Versão Corrigida (PI) → Sprint. Parâmetros de URL: jornada, time, versao, sprint.
  async function initFilters() {
    const selJornada = document.getElementById('flt-jornada');
    const selTime    = document.getElementById('flt-time');
    const selVersao  = document.getElementById('flt-versao');
    const selSprint  = document.getElementById('flt-sprint');
    if (!selJornada) return; // páginas sem filtros (ex: painel)

    const cur = Filters.read();

    // Popula os selects a partir do catálogo do BANCO (GET /filtros), com CASCATA.
    const eff = Object.assign({}, cur); // filtros efetivos (versão padrão = PI corrente)
    try {
      const cat = await Api.get('/filtros');
      window.App.catalog = indexCatalog(cat);

      // Versão: sem valor na URL → PI corrente.
      eff.versao = cur.versao || cat.pi_corrente || '';

      const jornadas = (cat.jornadas || []).map(j => j.nome);
      // Times: filtrados pela jornada escolhida (nome do catálogo).
      const times = [...new Set((cat.times || [])
        .filter(t => !cur.jornada || t.jornada === cur.jornada)
        .map(t => t.time))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
      const versoes = (cat.pis || []).map(p => p.desc_pi);
      // Sprints da PI efetiva (nome 'SP 26.4.1'); vazio = "Todas as fechadas".
      const sprints = ((cat.sprints_por_pi || {})[eff.versao] || []).map(s => s.nome);

      fillSelect(selJornada, jornadas, cur.jornada);
      fillSelect(selTime,    times,    cur.time);
      fillSelect(selVersao,  versoes,  eff.versao, { semVazio: true });
      fillSelect(selSprint,  sprints,  cur.sprint, { rotuloVazio: 'Todas as fechadas' });
    } catch (e) {
      console.warn('Falha ao popular filtros:', e.message);
    }

    // Cascata: mudar um nível superior reseta os inferiores.
    selJornada.addEventListener('change', () => Filters.apply({ jornada: selJornada.value, time: '', versao: '', sprint: '' }));
    selTime   .addEventListener('change', () => Filters.apply({ time:    selTime.value, versao: '', sprint: '' }));
    selVersao .addEventListener('change', () => Filters.apply({ versao:  selVersao.value, sprint: '' }));
    selSprint .addEventListener('change', () => Filters.apply({ sprint:  selSprint.value }));

    renderActiveFilters(eff);
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
    // DOM + textContent: o valor vem da URL (não confiável).
    box.textContent = '';
    chips.forEach(c => {
      const chip = document.createElement('span');
      chip.className = 'filter-chip';
      chip.style.setProperty('--ac', c.ac);
      const l = document.createElement('span'); l.className = 'chip-label'; l.textContent = c.label;
      const v = document.createElement('span'); v.className = 'chip-value'; v.textContent = c.value;
      chip.append(l, ' ', v);
      box.appendChild(chip);
    });
  }

  /**
   * Preenche um <select>. opts.semVazio remove a opção vazia ("Todas");
   * opts.rotuloVazio troca o texto dela.
   */
  function fillSelect(sel, options, current, opts = {}) {
    const first = sel.querySelector('option'); // opção vazia ("Todas")
    sel.innerHTML = '';
    if (!opts.semVazio) {
      if (opts.rotuloVazio) first.textContent = opts.rotuloVazio;
      sel.appendChild(first);
    }
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
