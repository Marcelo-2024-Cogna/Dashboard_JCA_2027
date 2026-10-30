/**
 * PEP - Plataforma de Engenharia de Performance
 * Mapeamento Jira → Jornada/Squad (`jira-mapping.js`)
 *
 * Task 23 (spec score-performance-executivo). Requirement 21.7.
 *
 * Resolve a correspondência entre valores de projeto/campos de um item do Jira
 * e as Jornadas/Squads canônicas da plataforma, para que itens de diferentes
 * projetos sejam classificados corretamente. A tabela de correspondência é
 * configurável (setMapping) e, por padrão, apoia-se em PEPIdentity (que conhece
 * as jornadas e squads canônicas a partir de PEPData).
 *
 * IIFE com guarda de ambiente para teste em Node.
 */

const JiraMapping = (() => {
    'use strict';

    // Tabela configurável:
    //   byProject:   { 'ASAT': { jornada, squad } }  (chave = prefixo do projeto)
    //   bySquadName: { 'kbots': 'Kbots' }            (normalizado → rótulo canônico)
    let table = { byProject: {}, bySquadName: {} };

    function dep(name, relPath) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        if (relPath && typeof require !== 'undefined') {
            try { return require(relPath); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    const norm = (s) => s == null ? '' : String(s)
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();

    // Extrai o prefixo do projeto da chave do item (ex.: 'ASAT-123' -> 'ASAT').
    function projectKeyOf(item) {
        const chave = item && item.chave ? String(item.chave) : '';
        const m = /^([A-Za-z][A-Za-z0-9]+)-\d+/.exec(chave);
        return m ? m[1].toUpperCase() : null;
    }

    function setMapping(t) {
        table = {
            byProject: (t && t.byProject) ? { ...t.byProject } : {},
            bySquadName: (t && t.bySquadName) ? { ...t.bySquadName } : {}
        };
    }
    function getMapping() { return { byProject: { ...table.byProject }, bySquadName: { ...table.bySquadName } }; }

    // Resolve o Squad canônico de um item:
    //   1) tabela byProject (prefixo da chave);
    //   2) tabela bySquadName (nome do squad informado);
    //   3) PEPIdentity.canonicalSquad (tolerante a acento/caixa);
    //   4) fallback: o squad informado no item.
    function resolveSquad(item) {
        if (!item) return null;
        const proj = projectKeyOf(item);
        if (proj && table.byProject[proj] && table.byProject[proj].squad) {
            return table.byProject[proj].squad;
        }
        const bySquad = table.bySquadName[norm(item.squad)];
        if (bySquad) return bySquad;

        const id = dep('PEPIdentity', './identity.js');
        if (id && typeof id.canonicalSquad === 'function') {
            const canon = id.canonicalSquad(item.squad);
            if (canon) return canon;
        }
        return item.squad || null;
    }

    // Resolve a Jornada canônica de um item:
    //   1) tabela byProject (prefixo da chave);
    //   2) PEPIdentity.jornadaOf a partir do squad resolvido;
    //   3) PEPIdentity.canonicalJornada da jornada informada;
    //   4) fallback: a jornada informada.
    function resolveJornada(item) {
        if (!item) return null;
        const proj = projectKeyOf(item);
        if (proj && table.byProject[proj] && table.byProject[proj].jornada) {
            return table.byProject[proj].jornada;
        }
        const id = dep('PEPIdentity', './identity.js');
        if (id) {
            const squad = resolveSquad(item);
            if (squad && typeof id.jornadaOf === 'function') {
                const j = id.jornadaOf(squad);
                if (j) return j;
            }
            if (typeof id.canonicalJornada === 'function') {
                const cj = id.canonicalJornada(item.jornada);
                if (cj) return cj;
            }
        }
        return item.jornada || null;
    }

    return { setMapping, getMapping, projectKeyOf, resolveSquad, resolveJornada };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = JiraMapping;
}
if (typeof globalThis !== 'undefined') {
    globalThis.JiraMapping = JiraMapping;
}
