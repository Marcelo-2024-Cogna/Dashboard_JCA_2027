// Feature: score-performance-executivo
// Smoke test (Task 1): confirma que o ambiente Vitest + fast-check carrega e
// roda, e que a exposição dos módulos IIFE via guarda de ambiente funciona
// sem exigir DOM/browser. NÃO cobre regras de negócio (ver Task 2+).
// _Requirements: 9.7_

import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { createRequire } from 'node:module';

// Os módulos de origem são IIFE em CommonJS (expostos via guarda de ambiente
// `module.exports`). Carregamos com createRequire para não exigir alteração
// no carregamento por <script> do app estático.
const require = createRequire(import.meta.url);
const ScoringEngine = require('../js/scoring-engine.js');
const PEPData = require('../js/data.js');

describe('Ambiente de testes (smoke)', () => {
    it('fast-check roda uma propriedade trivial (>= 100 execuções)', () => {
        // Propriedade trivial apenas para validar o runner + fast-check:
        // a soma é comutativa para quaisquer inteiros.
        fc.assert(
            fc.property(fc.integer(), fc.integer(), (a, b) => {
                return a + b === b + a;
            }),
            { numRuns: 100 }
        );
    });

    it('expõe o módulo ScoringEngine via guarda de ambiente', () => {
        expect(typeof ScoringEngine).toBe('object');
        expect(ScoringEngine).toHaveProperty('calculateTeamScore');
        expect(ScoringEngine).toHaveProperty('PERFORMANCE_BANDS');
        expect(typeof ScoringEngine.interpolate).toBe('function');
    });

    it('expõe o módulo PEPData via guarda de ambiente', () => {
        expect(typeof PEPData).toBe('object');
        expect(Array.isArray(PEPData.JORNADAS)).toBe(true);
        expect(Array.isArray(PEPData.TEAMS)).toBe(true);
        expect(PEPData.TEAMS.length).toBeGreaterThan(0);
    });
});
