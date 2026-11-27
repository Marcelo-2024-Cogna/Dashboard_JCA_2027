/* ============================================================
   sinal.js — Sinalizadores de cor (verde/amarelo/vermelho)
   Critérios em config/sinalizadores.php (window.SINAIS).
   App.Sinal.classificar(regra, valor, referencia, extra)
   App.Sinal.aplicar(el, nivel, dica)
   App.Sinal.legenda(regra)
   ============================================================ */

(function () {
  const NIVEIS = ['verde', 'amarelo', 'vermelho'];

  function regraDe(nome) {
    const s = window.SINAIS || {};
    return s[nome] || null;
  }

  function num(v) {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return isFinite(n) ? n : null;
  }

  function fmt(n, d) {
    return n.toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  /**
   * Devolve { nivel: 'verde'|'amarelo'|'vermelho'|'neutro', desvioPct, motivo }.
   * menor_melhor: valor e referencia (desvio percentual).
   * maior_melhor: valor = concluído, referencia = escopo final (p = valor/ref*100).
   * extra.alvo (menor_melhor): referência fixa que vence a automática.
   */
  function classificar(regraNome, valor, referencia, extra) {
    const r = regraDe(regraNome);
    const v = num(valor);
    let ref = num(referencia);
    const ex = extra || {};
    if (!r) return { nivel: 'neutro', desvioPct: null, motivo: 'sem critério configurado' };
    if (ex.alvo !== undefined && num(ex.alvo) !== null) ref = num(ex.alvo);
    if (v === null) return { nivel: 'neutro', desvioPct: null, motivo: 'sem valor' };

    if (r.direcao === 'maior_melhor') {
      if (ex.emAndamento) return { nivel: 'neutro', desvioPct: null, motivo: 'sprint em andamento' };
      if (ref === null || ref <= 0) return { nivel: 'neutro', desvioPct: null, motivo: 'escopo final zerado' };
      const p = v / ref * 100;
      const nivel = p >= r.meta_pct ? 'verde' : (p >= r.atencao_pct ? 'amarelo' : 'vermelho');
      return { nivel, desvioPct: p, motivo: fmt(p, 0) + '% concluído do escopo final (meta ' + r.meta_pct + '%)' };
    }

    if (ref === null || ref <= 0) {
      return { nivel: 'neutro', desvioPct: null, motivo: ex.motivoSemRef || 'sem referência de planejamento' };
    }
    const desvio = (v - ref) / ref * 100;
    const nivel = desvio <= r.verde_ate_pct ? 'verde' : (desvio <= r.amarelo_ate_pct ? 'amarelo' : 'vermelho');
    const sinal = desvio > 0 ? '+' : '';
    const dir = desvio > 0 ? 'acima' : (desvio < 0 ? 'abaixo' : 'igual');
    const motivo = desvio === 0
      ? 'igual à referência de ' + fmt(ref, 1)
      : sinal + fmt(desvio, 0) + '% ' + dir + ' da referência de ' + fmt(ref, 1);
    return { nivel, desvioPct: desvio, motivo };
  }

  /** Marca o elemento com a classe do nível e o title com a dica. */
  function aplicar(el, nivel, dica) {
    if (!el) return;
    el.classList.remove('sinal-verde', 'sinal-amarelo', 'sinal-vermelho', 'sinal-neutro', 'sinal-lateral');
    if (NIVEIS.indexOf(nivel) < 0) {
      el.classList.add('sinal-neutro');
    } else {
      el.classList.add('sinal-' + nivel);
      if (nivel !== 'verde') el.classList.add('sinal-lateral');
    }
    if (dica) el.title = dica;
  }

  /** Texto do critério, montado de window.SINAIS. */
  function legenda(regraNome) {
    const r = regraDe(regraNome);
    if (!r) return '';
    const fim = ' Ajuste em config/sinalizadores.php.';
    if (r.direcao === 'maior_melhor') {
      return 'Verde a partir de ' + r.meta_pct + '% do escopo final concluído, amarelo a partir de '
        + r.atencao_pct + '%, vermelho abaixo.' + fim;
    }
    return 'Verde até +' + r.verde_ate_pct + '% da referência, amarelo até +' + r.amarelo_ate_pct
      + '%, vermelho acima.' + fim;
  }

  window.App = window.App || {};
  window.App.Sinal = { classificar, aplicar, legenda };
})();
