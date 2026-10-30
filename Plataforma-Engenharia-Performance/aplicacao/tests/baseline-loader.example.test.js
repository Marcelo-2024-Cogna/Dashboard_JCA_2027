// Feature: score-performance-executivo
// Testes de EXEMPLO do carregador de Baseline por PI (`baseline-loader.js`) —
// Task 24. Validam, com casos concretos: a leitura de .xlsx no navegador
// (SheetJS, via `globalThis.XLSX`), a leitura de CSV, a substituição por novo
// upload, a rejeição de arquivo inválido com mensagem descritiva (sem alterar o
// baseline vigente) e a indicação de presença de baseline por PI.
//
// _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6_

import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const BaselineLoader = require('../js/baseline-loader.js');

let XLSX = null;
beforeAll(() => {
    XLSX = require('xlsx');
    globalThis.XLSX = XLSX; // simula a lib carregada por CDN no navegador
});

function xlsxBuffer(rows) {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, 'Baseline');
    return XLSX.write(wb, { type: 'array', bookType: 'xlsx' });
}

describe('BaselineLoader — leitura de .xlsx no navegador (22.1, 22.2)', () => {
    beforeEach(() => BaselineLoader._reset());

    it('lê um .xlsx real e associa os registros ao PI correspondente', () => {
        const buf = xlsxBuffer([
            ['pi', 'chave', 'jornada', 'squad', 'situacao'],
            ['PI 3/26', 'EP-1', 'Atendimento e Serviços', 'Atendimento', 'em-dia'],
            ['PI 3/26', 'EP-2', 'Atendimento e Serviços', 'Kbots', 'atrasado']
        ]);

        const res = BaselineLoader.loadForPI('PI 3/26', buf, 'xlsx');
        expect(res.ok).toBe(true);
        expect(res.pi).toBe('PI 3/26');

        const b = BaselineLoader.getBaselineForPI('PI 3/26');
        expect(b.registros.length).toBe(2);
        expect(b.registros.map((r) => r.chave)).toEqual(['EP-1', 'EP-2']);
        expect(b.registros[0].jornada).toBe('Atendimento e Serviços');
        expect(b.registros[1].squad).toBe('Kbots');
        // Totais derivados (contagem de épicos) usados como referência.
        expect(b.totais.epicos).toBe(2);
    });

    it('aceita workbook já parseado (objeto SheetJS) além de ArrayBuffer', () => {
        const buf = xlsxBuffer([
            ['pi', 'chave', 'situacao'],
            ['PI 1/26', 'A', 'em-dia']
        ]);
        const wb = XLSX.read(buf, { type: 'array' });
        const parsed = BaselineLoader.parseBaseline(wb, 'xlsx');
        expect(parsed.errors.length).toBe(0);
        expect(parsed.pi).toBe('PI 1/26');
        expect(parsed.registros.length).toBe(1);
    });
});

describe('BaselineLoader — leitura de CSV e situação planejada (22.1, 22.3)', () => {
    beforeEach(() => BaselineLoader._reset());

    it('lê CSV com separador ";" e captura a situação planejada do épico', () => {
        const csv = [
            'pi;chave;jornada;squad;situacao',
            'PI 2/26;E10;Acadêmica;Squad X;replanejado',
            'PI 2/26;E11;Acadêmica;Squad X;em-risco'
        ].join('\n');
        const res = BaselineLoader.loadForPI('PI 2/26', csv, 'csv');
        expect(res.ok).toBe(true);
        const b = BaselineLoader.getBaselineForPI('PI 2/26');
        expect(b.registros[0].situacaoPlanejada).toBe('replanejado');
        expect(b.registros[1].situacaoPlanejada).toBe('em-risco');
    });
});

describe('BaselineLoader — substituição por novo upload (22.5)', () => {
    beforeEach(() => BaselineLoader._reset());

    it('substitui o baseline do mesmo PI pelo novo upload válido', () => {
        BaselineLoader.loadForPI('PI 1/26', 'pi;chave\nPI 1/26;A\nPI 1/26;B', 'csv');
        expect(BaselineLoader.getBaselineForPI('PI 1/26').registros.length).toBe(2);

        BaselineLoader.loadForPI('PI 1/26', 'pi;chave\nPI 1/26;A\nPI 1/26;B\nPI 1/26;C', 'csv');
        expect(BaselineLoader.getBaselineForPI('PI 1/26').registros.length).toBe(3);
    });
});

describe('BaselineLoader — rejeição de arquivo inválido com mensagem (22.4)', () => {
    beforeEach(() => BaselineLoader._reset());

    it('rejeita cabeçalho sem colunas mínimas e mantém o baseline vigente', () => {
        // baseline vigente válido
        BaselineLoader.loadForPI('PI 3/26', 'pi;chave\nPI 3/26;A', 'csv');
        expect(BaselineLoader.getBaselineForPI('PI 3/26').registros.length).toBe(1);

        const res = BaselineLoader.loadForPI('PI 3/26', 'coluna1;coluna2\n1;2', 'csv');
        expect(res.ok).toBe(false);
        expect(res.errors.length).toBeGreaterThan(0);
        expect(res.errors[0].motivo).toMatch(/cabeçalho inválido/i);
        // vigente preservado
        expect(BaselineLoader.getBaselineForPI('PI 3/26').registros.length).toBe(1);
    });

    it('reporta mensagem descritiva quando a lib de .xlsx está indisponível', () => {
        const saved = globalThis.XLSX;
        try {
            delete globalThis.XLSX;
            const parsed = BaselineLoader.parseBaseline(new Uint8Array([1, 2, 3]).buffer, 'xlsx');
            expect(parsed.errors.length).toBeGreaterThan(0);
            expect(parsed.errors[0].motivo).toMatch(/xlsx|SheetJS|indispon/i);
        } finally {
            globalThis.XLSX = saved;
        }
    });

    it('arquivo vazio é rejeitado com mensagem', () => {
        const res = BaselineLoader.loadForPI('PI 1/26', '', 'csv');
        expect(res.ok).toBe(false);
        expect(res.errors[0].motivo).toMatch(/vazi/i);
    });
});

describe('BaselineLoader — indicação de presença por PI (22.6)', () => {
    beforeEach(() => BaselineLoader._reset());

    it('hasBaseline/listPIsWithBaseline refletem apenas PIs carregados', () => {
        expect(BaselineLoader.hasBaseline('PI 1/26')).toBe(false);
        expect(BaselineLoader.listPIsWithBaseline()).toEqual([]);

        BaselineLoader.loadForPI('PI 1/26', 'pi;chave\nPI 1/26;A', 'csv');
        BaselineLoader.loadForPI('PI 3/26', 'pi;chave\nPI 3/26;Z', 'csv');

        expect(BaselineLoader.hasBaseline('PI 1/26')).toBe(true);
        expect(BaselineLoader.hasBaseline('PI 2/26')).toBe(false);
        expect(BaselineLoader.hasBaseline('PI 3/26')).toBe(true);
        expect(BaselineLoader.listPIsWithBaseline().sort()).toEqual(['PI 1/26', 'PI 3/26']);
    });
});
