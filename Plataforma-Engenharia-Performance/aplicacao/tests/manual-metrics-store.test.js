// Feature: parametrização — entrada manual de métricas (ManualMetricsStore).
// Valida persistência por (mês, squad, métrica), leitura, remoção e consultas.

import { describe, it, expect, beforeEach } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Store = require('../js/manual-metrics-store.js');

describe('ManualMetricsStore — entrada manual por (mês, squad, métrica)', () => {
    beforeEach(() => { Store._refreshStore(); Store.clear(); });

    it('grava e lê um valor numérico', () => {
        expect(Store.set(9, 'acad-moodle', 'disponibilidade', 0.995)).toBe(true);
        expect(Store.get(9, 'acad-moodle', 'disponibilidade')).toBe(0.995);
    });

    it('rejeita valores não numéricos e campos ausentes', () => {
        expect(Store.set(9, 'acad-moodle', 'teamHealth', 'abc')).toBe(false);
        expect(Store.set(9, 'acad-moodle', 'teamHealth', '')).toBe(false);
        expect(Store.set(null, 'x', 'y', 1)).toBe(false);
        expect(Store.get(9, 'acad-moodle', 'teamHealth')).toBeNull();
    });

    it('aceita valor numérico em string', () => {
        expect(Store.set(9, 'sq', 'freqDeploy', '4')).toBe(true);
        expect(Store.get(9, 'sq', 'freqDeploy')).toBe(4);
    });

    it('remove um valor', () => {
        Store.set(8, 'sq', 'vulnerabilidade', 1);
        expect(Store.remove(8, 'sq', 'vulnerabilidade')).toBe(true);
        expect(Store.get(8, 'sq', 'vulnerabilidade')).toBeNull();
        expect(Store.remove(8, 'sq', 'inexistente')).toBe(false);
    });

    it('getForSquad retorna só as métricas daquele mês/squad', () => {
        Store.set(9, 'sqA', 'disponibilidade', 0.99);
        Store.set(9, 'sqA', 'teamHealth', 8.5);
        Store.set(9, 'sqB', 'disponibilidade', 0.97);
        const a = Store.getForSquad(9, 'sqA');
        expect(a).toEqual({ disponibilidade: 0.99, teamHealth: 8.5 });
    });

    it('list devolve registros planos; clear esvazia', () => {
        Store.set(9, 'sq', 'freqDeploy', 3);
        const l = Store.list();
        expect(l.length).toBe(1);
        expect(l[0]).toEqual({ mes: '9', squadId: 'sq', metrica: 'freqDeploy', valor: 3 });
        Store.clear();
        expect(Store.list().length).toBe(0);
    });
});
