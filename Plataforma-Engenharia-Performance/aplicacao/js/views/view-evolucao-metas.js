/**
 * PEP - Plataforma de Engenharia de Performance
 * Visão unificada: Evolução mensal + Metas (`js/views/view-evolucao-metas.js`)
 *
 * Higienização: as abas "Evolução mensal" e "Metas" foram unificadas em uma só
 * página ("Evolução & Metas"). Esta view NÃO duplica lógica — ela apenas compõe
 * os dois renderizadores existentes (PEPViews.evolucao e PEPViews.metas) em dois
 * blocos dentro do mesmo container, preservando o comportamento de cada um.
 *
 * Registro: `window.PEPViews.evolucaoMetas = { render }`. O app.js chama
 * `render(container, { month })` ao trocar mês/aba (kind 'score'). Render
 * defensivo: a falha de um bloco não derruba o outro.
 */

(function () {
    'use strict';

    function views() {
        if (typeof window !== 'undefined' && window.PEPViews) return window.PEPViews;
        if (typeof globalThis !== 'undefined' && globalThis.PEPViews) return globalThis.PEPViews;
        return null;
    }

    // Cria (ou cria stub de) um elemento div para sub-render.
    function makeSection(doc, id) {
        const el = (doc && typeof doc.createElement === 'function') ? doc.createElement('div') : null;
        if (el) { el.className = 'evm-section'; if (id) el.id = id; }
        return el;
    }

    function render(container, opts) {
        if (!container) return;
        const V = views();
        const doc = (typeof document !== 'undefined') ? document
            : (typeof globalThis !== 'undefined' && globalThis.document ? globalThis.document : null);

        // Monta a casca com dois blocos. Em DOM real usamos appendChild para que
        // cada sub-view escreva no seu próprio container sem sobrescrever o outro.
        container.innerHTML = `
        <div class="evm">
            <div class="evm-head">
                <h2 class="evm-title">📅 Evolução &amp; Metas</h2>
                <p class="evm-sub">Evolução mensal do Score e acompanhamento das metas por jornada.</p>
            </div>
        </div>`;

        const root = (typeof container.querySelector === 'function') ? container.querySelector('.evm') : null;
        const evoEl = makeSection(doc, 'evm-evolucao');
        const metasEl = makeSection(doc, 'evm-metas');

        if (root && evoEl && metasEl && typeof root.appendChild === 'function') {
            root.appendChild(evoEl);
            root.appendChild(metasEl);
            safeRender(V && V.evolucao, evoEl, opts);
            safeRender(V && V.metas, metasEl, opts);
        } else {
            // Fallback (ambiente sem createElement/appendChild): renderiza em
            // sequência reaproveitando o próprio container (um após o outro).
            safeRender(V && V.evolucao, container, opts);
        }
    }

    function safeRender(view, el, opts) {
        if (!view || typeof view.render !== 'function' || !el) return;
        try { view.render(el, opts || {}); } catch (e) { /* isola a falha do bloco */ }
    }

    const api = { render };
    if (typeof window !== 'undefined') {
        window.PEPViews = window.PEPViews || {};
        window.PEPViews.evolucaoMetas = api;
    }
    if (typeof globalThis !== 'undefined') {
        globalThis.PEPViews = globalThis.PEPViews || {};
        globalThis.PEPViews.evolucaoMetas = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
