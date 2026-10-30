/**
 * PEP - Plataforma de Engenharia de Performance
 * Carga do Baseline do PI por planilha (`baseline-loader.js`)
 *
 * Task 24 (spec score-performance-executivo). Requirements 22.1–22.6.
 *
 * Opção B (app estático): o Baseline de cada PI é carregado por planilha
 * (.xlsx) ou CSV e processado NO NAVEGADOR (client-side), sem backend. Para
 * .xlsx usamos a lib global `XLSX` (SheetJS) carregada por <script>/CDN quando
 * presente; CSV é lido por parser próprio. O Baseline é guardado por PI
 * (`baselineByPI`) e usado como referência de planejamento (Baseline × Mudança
 * de Escopo e Situação dos Épicos).
 *
 * IIFE com guarda de ambiente para teste em Node.
 */

const BaselineLoader = (() => {
    'use strict';

    // Estado: baseline por PI (último carregado com sucesso).
    const baselineByPI = {};

    const strip = (s) => (s == null ? '' : String(s).trim());
    function toNum(v) {
        if (v == null || v === '') return null;
        const n = Number(String(v).replace(',', '.'));
        return Number.isFinite(n) ? n : null;
    }
    const norm = (s) => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    function dep(name) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        return undefined;
    }

    // =========================================================================
    // PARSE — detecta formato e extrai { pi, registros, totais, errors }
    //   Linha de registro esperada: pi; chave; jornada; squad; situacaoPlanejada
    //   (as colunas de totais são derivadas das contagens quando ausentes).
    // =========================================================================
    function parseBaseline(input, format) {
        const fmt = (format || '').toLowerCase();
        try {
            if (fmt === 'xlsx' || (input && typeof input !== 'string')) {
                return parseXLSX(input);
            }
            return parseCSV(String(input));
        } catch (e) {
            return { pi: null, registros: [], totais: null, errors: [{ motivo: 'Falha ao ler o arquivo: ' + e.message }] };
        }
    }

    function parseXLSX(arrayBufferOrWorkbook) {
        const XLSX = dep('XLSX');
        if (!XLSX) {
            return { pi: null, registros: [], totais: null, errors: [{ motivo: 'Leitor de .xlsx (XLSX/SheetJS) indisponível. Use CSV ou carregue a lib.' }] };
        }
        const wb = (arrayBufferOrWorkbook && arrayBufferOrWorkbook.SheetNames)
            ? arrayBufferOrWorkbook
            : XLSX.read(arrayBufferOrWorkbook, { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
        return rowsToBaseline(rows);
    }

    function parseCSV(text) {
        const linhas = String(text || '').replace(/^\uFEFF/, '').split(/\r?\n/).filter(l => l.trim() !== '');
        if (linhas.length === 0) return { pi: null, registros: [], totais: null, errors: [{ motivo: 'Arquivo vazio' }] };
        const sep = linhas[0].includes(';') ? ';' : ',';
        const rows = linhas.map(l => l.split(sep).map(strip));
        return rowsToBaseline(rows);
    }

    // Converte linhas (array de arrays) em baseline estruturado.
    function rowsToBaseline(rows) {
        const errors = [];
        if (!rows || rows.length < 2) {
            return { pi: null, registros: [], totais: null, errors: [{ motivo: 'Planilha sem linhas de dados' }] };
        }
        const header = rows[0].map(h => norm(h));
        const col = (nomes) => header.findIndex(h => nomes.map(norm).includes(h));
        const iPI = col(['pi', 'program increment']);
        const iChave = col(['chave', 'épico', 'epico', 'key']);
        const iJornada = col(['jornada']);
        const iSquad = col(['squad', 'time']);
        const iSit = col(['situacao', 'situação', 'situacaoplanejada', 'situação planejada', 'status']);

        if (iPI < 0 || iChave < 0) {
            return { pi: null, registros: [], totais: null, errors: [{ motivo: 'Cabeçalho inválido: colunas mínimas "pi" e "chave" não encontradas' }] };
        }

        const registros = [];
        let pi = null;
        for (let i = 1; i < rows.length; i++) {
            const r = rows[i];
            const rpi = strip(r[iPI]);
            const chave = strip(r[iChave]);
            if (!rpi || !chave) {
                errors.push({ linha: i + 1, motivo: 'Registro sem PI ou chave' });
                continue;
            }
            if (pi == null) pi = rpi;
            registros.push({
                pi: rpi,
                chave,
                jornada: iJornada >= 0 ? strip(r[iJornada]) : null,
                squad: iSquad >= 0 ? strip(r[iSquad]) : null,
                situacaoPlanejada: iSit >= 0 ? strip(r[iSit]) : null
            });
        }

        if (registros.length === 0) {
            return { pi: null, registros: [], totais: null, errors: errors.length ? errors : [{ motivo: 'Nenhum registro válido' }] };
        }

        // Totais derivados (contagem de épicos; histórias/pontos quando houver).
        const totais = { epicos: registros.length, historias: null, pontos: null };
        return { pi, registros, totais, errors };
    }

    // =========================================================================
    // ESTADO POR PI (22.2, 22.5) — substituição segura
    //   Só substitui o baseline vigente quando o novo é válido (sem errors
    //   fatais e com registros). Carregar o baseline de um PI não afeta outro.
    // =========================================================================
    function setBaselineForPI(pi, baseline) {
        if (!pi) return { ok: false, motivo: 'PI não informado' };
        if (!baseline || !Array.isArray(baseline.registros) || baseline.registros.length === 0) {
            return { ok: false, motivo: 'Baseline inválido; mantido o anterior' };
        }
        baselineByPI[pi] = baseline;
        return { ok: true };
    }

    function getBaselineForPI(pi) {
        return baselineByPI[pi] || null;
    }

    function hasBaseline(pi) { return !!baselineByPI[pi]; }

    function listPIsWithBaseline() { return Object.keys(baselineByPI); }

    // Carrega e associa em um passo: parseia, e só substitui se válido (22.4/22.5).
    function loadForPI(pi, input, format) {
        const parsed = parseBaseline(input, format);
        const fatal = parsed.errors && parsed.errors.some(e => e.motivo && /inválid|vazi|indisponível|sem linhas|cabeçalho/i.test(e.motivo));
        if (fatal || parsed.registros.length === 0) {
            return { ok: false, errors: parsed.errors, baselineMantido: getBaselineForPI(pi) };
        }
        const alvoPI = pi || parsed.pi;
        const res = setBaselineForPI(alvoPI, parsed);
        return { ok: res.ok, pi: alvoPI, errors: parsed.errors, baseline: getBaselineForPI(alvoPI) };
    }

    // Reset (uso em teste).
    function _reset() { Object.keys(baselineByPI).forEach(k => delete baselineByPI[k]); }

    return {
        parseBaseline,
        setBaselineForPI,
        getBaselineForPI,
        hasBaseline,
        listPIsWithBaseline,
        loadForPI,
        _reset
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = BaselineLoader;
}
if (typeof globalThis !== 'undefined') {
    globalThis.BaselineLoader = BaselineLoader;
}
