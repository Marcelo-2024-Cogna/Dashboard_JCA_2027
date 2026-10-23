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
      return { jornada: u.get('jornada') || '', versao: u.get('versao') || '', sprint: u.get('sprint') || '' };
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
  };

  // ── Inicializa a barra de filtros (preenche selects, liga eventos) ───────
  async function initFilters() {
    const selJornada = document.getElementById('flt-jornada');
    const selVersao  = document.getElementById('flt-versao');
    const selSprint  = document.getElementById('flt-sprint');
    if (!selJornada) return; // páginas sem filtros (ex: painel)

    const cur = Filters.read();

    // Popula os selects a partir dos dados de extração (cache), com CASCATA:
    // Jornada filtra Versão e Sprint; Versão filtra Sprint.
    try {
      const json = await Api.get('/extract');
      const items = (json.items) || [];

      // Pré-processa os itens com jornada/versão/sprint normalizados
      const rows = items.map(i => {
        const mj = /^\[([^\]]+)\]/.exec(i.projeto || '');
        return {
          jornada: mj ? mj[1].trim() : '',
          versao:  i.versoes_corrigidas ? i.versoes_corrigidas.split(';')[0].trim() : '',
          sprint:  i.sprint ? Util.shortSprint(i.sprint) : '',
        };
      });

      const rebuild = () => {
        const j = selJornada.value, v = selVersao.value;
        // Jornadas: sempre todas
        const jornadas = [...new Set(rows.map(r => r.jornada).filter(Boolean))].sort();
        // Versões: filtradas pela jornada escolhida
        const versoes = [...new Set(rows.filter(r => !j || r.jornada === j).map(r => r.versao).filter(Boolean))].sort();
        // Sprints: filtradas por jornada E versão
        const sprints = [...new Set(rows.filter(r => (!j || r.jornada === j) && (!v || r.versao === v)).map(r => r.sprint).filter(Boolean))].sort();
        fillSelect(selJornada, jornadas, selJornada.value);
        fillSelect(selVersao,  versoes,  selVersao.value);
        fillSelect(selSprint,  sprints,  selSprint.value);
      };

      // Restaura valores da URL e monta a cascata inicial
      fillSelect(selJornada, [...new Set(rows.map(r=>r.jornada).filter(Boolean))].sort(), cur.jornada);
      selVersao.value = cur.versao; selSprint.value = cur.sprint;
      rebuild();
    } catch (e) {
      console.warn('Falha ao popular filtros:', e.message);
    }

    // Ao mudar Jornada: reseta versão/sprint dependentes e recarrega.
    selJornada.addEventListener('change', () => Filters.apply({ jornada: selJornada.value, versao: '', sprint: '' }));
    selVersao .addEventListener('change', () => Filters.apply({ versao:  selVersao.value, sprint: '' }));
    selSprint .addEventListener('change', () => Filters.apply({ sprint:  selSprint.value }));
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

  // ── Atualizar dados do Jira (força reextração, ignora cache) ─────────────
  async function refreshJira() {
    const btn = document.getElementById('btn-refresh-jira');
    if (!btn) return;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = '↻ Atualizando (pode levar 1-2 min)...';
    btn.classList.add('btn-busy');

    try {
      // force=1 ignora o cache de 30min e rebusca do Jira (36 projetos,
      // pode levar minutos). fetch direto, sem cache de cliente.
      const res = await fetch(`${BASE}/extract?force=1`, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      if (json.error) throw new Error(json.error);
      const total = json.total_items ?? (json.items ? json.items.length : '?');
      // Invalida o cache de cliente do /extract para a página recarregada
      // pegar os dados novos.
      Api.invalidate('/extract');
      btn.textContent = `✓ ${total} itens — atualizando tela...`;
      setTimeout(() => location.reload(), 800);
    } catch (e) {
      btn.textContent = '⚠ Falha ao atualizar';
      btn.title = 'Erro: ' + e.message;
      btn.disabled = false;
      btn.classList.remove('btn-busy');
      setTimeout(() => { btn.textContent = original; btn.title = 'Buscar dados atualizados do Jira (ignora cache)'; }, 4000);
    }
  }

  // ── Bootstrap da página ──────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    initFilters();
    document.getElementById('btn-refresh-jira')?.addEventListener('click', refreshJira);
    document.getElementById('btn-export-pdf')?.addEventListener('click', exportPdf);
    document.getElementById('btn-export-xlsx')?.addEventListener('click', exportXlsx);
  });

  // Exporta para as views usarem
  window.App = { Api, Filters, Util, BASE };
})();
