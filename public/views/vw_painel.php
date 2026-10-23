<?php
/**
 * vw_painel.php — Painel de Controle (parametrização)
 * ─────────────────────────────────────────────────────
 * Lê os parâmetros via GET /settings e grava via POST /settings/pi e
 * /settings/capacity. Dois blocos: PI e Capacidade (tabela por time).
 * A capacidade é recalculada ao vivo conforme o usuário edita.
 */
?>
<div id="painel-alert"></div>

<!-- ══ BLOCO 1 — Parâmetros da PI ═══════════════════════════════════════ -->
<div class="stitle">Parâmetros da PI</div>
<div class="ccard">
  <div class="form-grid">
    <div class="field">
      <label>PI corrente</label>
      <input type="text" id="pi-current" placeholder="4/26" />
    </div>
    <div class="field">
      <label>Sprint de fechamento <span style="color:var(--muted)">(opcional)</span></label>
      <input type="text" id="pi-closing" placeholder="26.4.5" />
    </div>
    <div class="field" style="grid-column: span 2">
      <label>Sprints da PI <span style="color:var(--muted)">(separadas por vírgula)</span></label>
      <input type="text" id="pi-sprints" placeholder="26.4.1, 26.4.2, ..." />
    </div>
    <div class="field" style="grid-column: 1 / -1">
      <label>Projetos / Times incluídos <span style="color:var(--muted)">(IDs Jira, separados por vírgula)</span></label>
      <input type="text" id="pi-projects" placeholder="APAP, SGGU, ..." />
    </div>
  </div>
  <div style="margin-top:14px; display:flex; gap:10px">
    <button class="btn btn-primary" id="btn-save-pi">Salvar Parâmetros da PI</button>
    <span id="pi-saved-tag" style="font-size:.75rem; color:var(--muted); align-self:center"></span>
  </div>
</div>

<!-- ══ BLOCO 2 — Capacidade por time ═══════════════════════════════════ -->
<div class="stitle" style="margin-top:24px">Capacidade por Time</div>
<div class="ccard">
  <p style="font-size:.78rem; color:var(--muted); margin-bottom:14px">
    Dias úteis vêm do calendário da sprint selecionada. A capacidade é
    calculada ao vivo: <code>(dias úteis × horas/dev × nº devs) ÷ produtividade</code>.
  </p>

  <div class="filters" style="margin-bottom:14px">
    <label>Sprint de referência (dias úteis)
      <select id="cap-sprint"></select>
    </label>
    <span id="cap-dias-info" style="font-size:.75rem;color:var(--muted);align-self:end"></span>
  </div>

  <div class="twrap">
    <table id="cap-table">
      <thead>
        <tr>
          <th>Time (ID)</th>
          <th>Nº Devs</th>
          <th>Horas/dev</th>
          <th>Produtividade (H/P)</th>
          <th>Dias úteis</th>
          <th>Capacidade (SP)</th>
        </tr>
      </thead>
      <tbody id="cap-tbody">
        <tr><td colspan="6"><div class="loading"><div class="spinner"></div> Carregando...</div></td></tr>
      </tbody>
    </table>
  </div>

  <div style="margin-top:14px; display:flex; gap:10px">
    <button class="btn btn-primary" id="btn-save-cap">Salvar Capacidade</button>
    <span id="cap-saved-tag" style="font-size:.75rem; color:var(--muted); align-self:center"></span>
  </div>
</div>

<script>
(function () {
  const { Api } = window.App;
  let CONFIG = null;  // snapshot do /settings

  const alertBox = document.getElementById('painel-alert');
  function showAlert(type, msg) {
    alertBox.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    if (type === 'ok') setTimeout(() => alertBox.innerHTML = '', 4000);
  }

  // ── Carrega config e preenche os campos ──────────────────────────────
  async function load() {
    try {
      CONFIG = await Api.get('/settings');
      fillPi(CONFIG.pi);
      fillCapacity(CONFIG.capacity);
      document.getElementById('pi-saved-tag').textContent =
        CONFIG.saved.pi ? 'fonte: salvo no painel' : 'fonte: config inicial (seed)';
      document.getElementById('cap-saved-tag').textContent =
        CONFIG.saved.capacity ? 'fonte: salvo no painel' : 'fonte: config inicial (seed)';
    } catch (e) {
      showAlert('error', 'Falha ao carregar configuração: ' + e.message);
    }
  }

  // ── Bloco PI ───────────────────────────────────────────────────────────
  function fillPi(pi) {
    document.getElementById('pi-current').value  = pi.current_pi || '';
    document.getElementById('pi-closing').value  = pi.closing_sprint || '';
    document.getElementById('pi-sprints').value  = (pi.sprints || []).join(', ');
    document.getElementById('pi-projects').value = (pi.projects || []).join(', ');
  }

  async function savePi() {
    const body = {
      current_pi:     document.getElementById('pi-current').value.trim(),
      closing_sprint: document.getElementById('pi-closing').value.trim() || null,
      sprints:        document.getElementById('pi-sprints').value,
      projects:       document.getElementById('pi-projects').value,
    };
    try {
      const r = await Api.post('/settings/pi', body);
      if (!r.success) throw new Error(r.error || 'erro');
      showAlert('ok', 'Parâmetros da PI salvos com sucesso.');
      document.getElementById('pi-saved-tag').textContent = 'fonte: salvo no painel';
    } catch (e) {
      showAlert('error', 'Falha ao salvar PI: ' + e.message);
    }
  }

  // ── Bloco Capacidade ─────────────────────────────────────────────────
  function fillCapacity(cap) {
    const sel = document.getElementById('cap-sprint');
    const cal = cap.working_days_by_sprint || {};
    sel.innerHTML = Object.keys(cal).map(s => `<option value="${s}">${s} (${cal[s]} dias)</option>`).join('');
    sel.addEventListener('change', recalcAll);

    const tbody = document.getElementById('cap-tbody');
    const teams = cap.teams || {};
    const ids = Object.keys(teams).sort();
    if (!ids.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="color:var(--muted);padding:14px">Nenhum time configurado.</td></tr>';
      return;
    }
    tbody.innerHTML = ids.map(id => {
      const t = teams[id];
      return `<tr data-team="${id}">
        <td><span class="mono">${id}</span></td>
        <td><input type="number" step="0.5" min="0" class="cap-in" data-f="dev_count" value="${t.dev_count ?? ''}" style="width:80px"></td>
        <td><input type="number" step="0.5" min="0" class="cap-in" data-f="productive_hours" value="${t.productive_hours ?? ''}" style="width:80px"></td>
        <td><input type="number" step="0.1" min="0" class="cap-in" data-f="productivity" value="${t.productivity ?? ''}" style="width:90px"></td>
        <td class="cap-dias" style="text-align:center">—</td>
        <td class="cap-result" style="font-weight:700;color:var(--cyan)">—</td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('.cap-in').forEach(inp => inp.addEventListener('input', recalcAll));
    recalcAll();
  }

  function currentDias() {
    const sel = document.getElementById('cap-sprint');
    const cal = (CONFIG.capacity.working_days_by_sprint) || {};
    return Number(cal[sel.value]) || 0;
  }

  function recalcAll() {
    const dias = currentDias();
    document.getElementById('cap-dias-info').textContent = `Dias úteis: ${dias}`;
    document.querySelectorAll('#cap-tbody tr').forEach(tr => {
      const get = f => Number(tr.querySelector(`[data-f="${f}"]`)?.value) || 0;
      const devs = get('dev_count'), horas = get('productive_hours'), prod = get('productivity');
      const diasCell = tr.querySelector('.cap-dias');
      if (diasCell) diasCell.textContent = dias;
      const cap = prod > 0 ? ((dias * horas * devs) / prod) : 0;
      const resCell = tr.querySelector('.cap-result');
      if (resCell) resCell.textContent = prod > 0 ? cap.toFixed(1) : '—';
    });
  }

  async function saveCapacity() {
    const teams = {};
    document.querySelectorAll('#cap-tbody tr').forEach(tr => {
      const id = tr.dataset.team;
      const get = f => { const v = tr.querySelector(`[data-f="${f}"]`)?.value; return v === '' ? null : Number(v); };
      teams[id] = { dev_count: get('dev_count'), productive_hours: get('productive_hours'), productivity: get('productivity') };
    });
    const body = {
      working_days_by_sprint: CONFIG.capacity.working_days_by_sprint,
      teams,
      defaults: CONFIG.capacity.defaults,
    };
    try {
      const r = await Api.post('/settings/capacity', body);
      if (!r.success) throw new Error(r.error || 'erro');
      showAlert('ok', 'Parâmetros de capacidade salvos com sucesso.');
      document.getElementById('cap-saved-tag').textContent = 'fonte: salvo no painel';
    } catch (e) {
      showAlert('error', 'Falha ao salvar capacidade: ' + e.message);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    load();
    document.getElementById('btn-save-pi').addEventListener('click', savePi);
    document.getElementById('btn-save-cap').addEventListener('click', saveCapacity);
  });
})();
</script>
