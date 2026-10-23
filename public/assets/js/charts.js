/* ============================================================
   charts.js — Helpers Chart.js (paleta, defaults, factory)
   Compartilhado por todas as views.
   ============================================================ */

(function () {
  // Paleta do tema escuro
  const P = ['#6366f1','#22d3ee','#10b981','#f59e0b','#f43f5e',
             '#a78bfa','#f97316','#38bdf8','#84cc16','#ec4899'];

  // Defaults globais do Chart.js
  if (window.Chart) {
    Chart.defaults.color       = '#7c8aaa';
    Chart.defaults.borderColor = '#2e3349';
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.plugins.legend.labels.boxWidth = 11;
    Chart.defaults.plugins.legend.labels.padding  = 14;
    Chart.defaults.plugins.tooltip.backgroundColor = '#1a1d27';
    Chart.defaults.plugins.tooltip.borderColor     = '#2e3349';
    Chart.defaults.plugins.tooltip.borderWidth     = 1;
    Chart.defaults.plugins.tooltip.padding         = 10;
  }

  // Registro de instâncias (para destruir/recriar sem vazar)
  const registry = {};

  const Charts = {
    palette: P,
    color: (i) => P[i % P.length],
    fill:  (i, a = 0.18) => P[i % P.length] + Math.round(a * 255).toString(16).padStart(2, '0'),

    /** Cria (ou recria) um chart no canvas por id. */
    make(canvasId, config) {
      const el = document.getElementById(canvasId);
      if (!el) return null;
      if (registry[canvasId]) registry[canvasId].destroy();
      registry[canvasId] = new Chart(el, config);
      return registry[canvasId];
    },

    get(canvasId) { return registry[canvasId] || null; },

    destroyAll() {
      Object.keys(registry).forEach(id => { registry[id].destroy(); delete registry[id]; });
    },
  };

  window.Charts = Charts;
})();
