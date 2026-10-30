/**
 * PEP - Plataforma de Engenharia de Performance
 * Metas progressivas por jornada (`targets.js`)
 *
 * Task 5.1 (spec score-performance-executivo): implementa o cálculo de metas
 * por jornada descrito no design ("Components and Interfaces" → "3. Metas —
 * targets.js") e nos Requirements 7.1, 7.2, 7.3, 7.4, 7.6, 7.7:
 *
 *   - partida(jornada)              = score REALIZADO de dez/2025 (Baseline),
 *                                     usado como ponto de partida. (Req. 7.1)
 *   - meta2026(jornada)             = Partida × (1 + Meta%).            (Req. 7.2, 7.7)
 *   - acrescimoMensal(jornada)      = (Meta2026 − Partida) ÷ 12.        (Req. 7.6)
 *   - metaMes(jornada, nºMes)       = Partida + acréscimoMensal × nºMes.(Req. 7.3)
 *   - atingimento(jornada, nºMes, realizado) = realizado ÷ metaMes.     (Req. 7.4)
 *   - farol(atingimento)            = classificação por atingimento, fonte
 *                                     ÚNICA usada por todas as visões.
 *                                     (Req. 2.3, 4.3, 6.3, 7.5)
 *
 * `META_PCT` carrega o percentual de crescimento anual por jornada: Secretaria
 * e Gestor de Unidades = 0,145 (14,5%) e as demais jornadas usam `_default`
 * = 0,05 (5%). (Req. 7.7)
 *
 * Task 5.2: `farol(at)` é a fonte única de classificação por atingimento:
 *   Na meta ≥ 0,99; Atenção ≥ 0,80 e < 0,99; Crítico < 0,80. Quando o
 *   atingimento é indisponível (`null`/`NaN`), o Farol não é calculado
 *   (retorna `null`).
 *
 * Tratamento de borda (design "Error Handling" → "Divisões por zero em metas"):
 * quando `metaMes` é 0 (Partida e Meta2026 iguais a zero), o Atingimento é
 * tratado como INDISPONÍVEL (`null`), nunca retornando `NaN`/`Infinity`.
 *
 * Dependências (carregadas por <script> no browser como globais, ou via
 * require() em Node/Vitest sob guarda de ambiente):
 *   - PEPData.getPartida(jornada) / PEPData.BASELINE  (Baseline dez/25)
 */

const Targets = (() => {

    // -------------------------------------------------------------------------
    // Resolução de dependências com guarda de ambiente.
    // No browser `PEPData` existe como global; em Node (Vitest) resolvemos via
    // require() relativo. Mantém o app estático intacto.
    // -------------------------------------------------------------------------
    let _PEPData = (typeof globalThis !== 'undefined' && globalThis.PEPData) ? globalThis.PEPData : undefined;

    if (!_PEPData && typeof require === 'function') {
        try { _PEPData = require('./data.js'); } catch (e) { /* ignore */ }
    }

    function getData() {
        const d = _PEPData || (typeof globalThis !== 'undefined' ? globalThis.PEPData : undefined);
        if (!d) throw new Error('Targets: PEPData indisponível');
        return d;
    }

    // -------------------------------------------------------------------------
    // META % por jornada (Requirement 7.7)
    // Secretaria e Gestor de Unidades = 14,5%; demais jornadas = 5%.
    // `Gestor de Unidades` é mantido como alias defensivo caso a jornada venha
    // com esse rótulo abreviado em algum dataset.
    // -------------------------------------------------------------------------
    const META_PCT = {
        'Secretaria e Gestor de Unidades': 0.145,
        'Gestor de Unidades': 0.145,
        _default: 0.05
    };

    // Percentual de meta efetivo da jornada (usa `_default` quando não houver
    // valor específico).
    function metaPct(journeyName) {
        if (Object.prototype.hasOwnProperty.call(META_PCT, journeyName)) {
            return META_PCT[journeyName];
        }
        return META_PCT._default;
    }

    // -------------------------------------------------------------------------
    // Partida (Requirement 7.1): score realizado de dez/2025 (Baseline).
    // Delega ao acessor de dados; retorna o valor do Baseline por jornada.
    // -------------------------------------------------------------------------
    function partida(journeyName) {
        return getData().getPartida(journeyName);
    }

    // -------------------------------------------------------------------------
    // Meta 2026 (Requirements 7.2, 7.7): Partida × (1 + Meta%).
    // -------------------------------------------------------------------------
    function meta2026(journeyName) {
        const p = partida(journeyName);
        return p * (1 + metaPct(journeyName));
    }

    // -------------------------------------------------------------------------
    // Acréscimo mensal (Requirement 7.6): (Meta2026 − Partida) ÷ 12.
    // É o incremento constante aplicado a cada mês.
    // -------------------------------------------------------------------------
    function acrescimoMensal(journeyName) {
        const p = partida(journeyName);
        return (meta2026(journeyName) - p) / 12;
    }

    // -------------------------------------------------------------------------
    // Meta do Mês (Requirement 7.3):
    //   Partida + (Meta2026 − Partida) ÷ 12 × nº do mês
    // monthNumber em 1..12 (Jan=1 ... Dez=12). No mês 12 a meta iguala a
    // Meta 2026 (progressão linear).
    // -------------------------------------------------------------------------
    function metaMes(journeyName, monthNumber) {
        const p = partida(journeyName);
        return p + acrescimoMensal(journeyName) * monthNumber;
    }

    // -------------------------------------------------------------------------
    // Atingimento (Requirement 7.4): realizado do mês ÷ meta do mês.
    // Quando `metaMes` é 0, o atingimento é INDISPONÍVEL (`null`) para evitar
    // divisão por zero (NaN/Infinity) — ver design "Error Handling".
    // -------------------------------------------------------------------------
    function atingimento(journeyName, monthNumber, realizado) {
        const meta = metaMes(journeyName, monthNumber);
        if (!meta) return null; // meta === 0 (ou falsy) → indisponível
        return realizado / meta;
    }

    // -------------------------------------------------------------------------
    // Farol (Task 5.2) — FONTE ÚNICA de classificação por Atingimento.
    //
    // Usada por Visão Geral, Jornadas, Métricas, Evolução e Metas (design
    // "Components and Interfaces" → "3. Metas — targets.js"; Requirements 2.3,
    // 4.3, 6.3, 7.5 e glossário "Farol"):
    //
    //   at >= 0,99          → 'na-meta'  (≥99% tratado como aproximação de 100%)
    //   at >= 0,80 e < 0,99 → 'atencao'
    //   at < 0,80           → 'critico'
    //
    // Atingimento indisponível (design "Error Handling" → "Divisões por zero em
    // metas"): quando o Atingimento é `null` (meta zero) — ou qualquer valor
    // não numérico (`undefined`/`NaN`) — o Farol NÃO é calculado e retornamos
    // `null`, de modo coerente com `atingimento`, que já retorna `null` para
    // metas indisponíveis. As visões tratam `null` como célula/estado neutro.
    // -------------------------------------------------------------------------
    function farol(at) {
        if (at === null || at === undefined || typeof at !== 'number' || Number.isNaN(at)) {
            return null; // Atingimento indisponível → Farol não é calculado
        }
        if (at >= 0.99) return 'na-meta'; // ≥99% ≈ 100%
        if (at >= 0.80) return 'atencao';  // 80%..<99%
        return 'critico';                   // <80%
    }

    // -------------------------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------------------------
    return {
        META_PCT,
        metaPct,
        partida,
        meta2026,
        acrescimoMensal,
        metaMes,
        atingimento,
        farol
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped and `Targets` stays global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Targets;
}

if (typeof globalThis !== 'undefined') {
    globalThis.Targets = Targets;
}
