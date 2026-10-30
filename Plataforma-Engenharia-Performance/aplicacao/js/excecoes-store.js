/**
 * PEP - Plataforma de Engenharia de Performance
 * Store do Fluxo de Exceção com aprovação (`js/excecoes-store.js`)
 *
 * Task 32 (spec score-performance-executivo). Requirements 30.1–30.10.
 *
 * Store observável das Solicitações de Exceção por Indicador e Squad, com:
 *   - criação de solicitação no estado "Pendente de aprovação" (30.1, 30.2);
 *   - fila de pendências para aprovar/rejeitar, registrando quem decidiu e, na
 *     rejeição, o motivo (30.3, 30.5, 30.6);
 *   - transição para "Aprovada" (30.4) ou "Rejeitada" (30.6) e consulta das
 *     exceções APROVADAS por Squad+Indicador+Período para sinalizar o indicador
 *     afetado nas visões SOMENTE quando aprovada (30.4);
 *   - validação de campos obrigatórios com mensagem descritiva (30.8);
 *   - persistência no navegador (localStorage) mantendo a natureza estática
 *     (30.9);
 *   - histórico de cada solicitação (solicitante, aprovador/rejeitador, estados
 *     e datas) para rastreabilidade (30.10).
 *
 * NÃO contém regra de UI nem de permissão: quem pode solicitar/aprovar é
 * decidido pela view consultando `Rbac.canAction` (fonte única de RBAC). O
 * store apenas guarda e consulta os dados, de forma independente de perfil.
 *
 * Persistência defensiva: `localStorage` pode não existir em Node (Vitest) ou
 * em modo privado; nesse caso caímos para um store em memória, mantendo o
 * módulo testável sem browser e o app estático intacto (mesmo padrão de
 * `app-state.js`).
 *
 * IIFE com guarda de ambiente (`module.exports`/`globalThis`) para teste em
 * Node sem afetar o carregamento por <script> no browser.
 */

const ExcecoesStore = (() => {
    'use strict';

    const STORAGE_KEY = 'pep.excecoes';

    // Estados possíveis de uma Solicitação de Exceção.
    const STATUS = {
        PENDENTE: 'pendente',
        APROVADA: 'aprovada',
        REJEITADA: 'rejeitada'
    };

    const STATUS_LABEL = {
        pendente: 'Pendente de aprovação',
        aprovada: 'Aprovada',
        rejeitada: 'Rejeitada'
    };

    // Campos obrigatórios da solicitação (Req 30.1, 30.8).
    const REQUIRED_FIELDS = ['squad', 'indicador', 'justificativa', 'periodo'];

    const FIELD_LABEL = {
        squad: 'Squad',
        indicador: 'Indicador',
        justificativa: 'Justificativa',
        periodo: 'Período de referência',
        metricaSubstituta: 'Métrica substituta'
    };

    // Tratamento da exceção (como a pontuação da métrica excetuada é tratada):
    //   - 'redistribuir' (default): o peso da métrica sai do cálculo e é
    //     redistribuído proporcionalmente entre as demais métricas do time.
    //   - 'substituir': a métrica é substituída por outra (metricaSubstituta),
    //     que assume o peso da excetuada no cálculo do Score.
    const TRATAMENTO = {
        REDISTRIBUIR: 'redistribuir',
        SUBSTITUIR: 'substituir'
    };

    const subscribers = [];

    // -------------------------------------------------------------------------
    // Storage defensivo (localStorage utilizável; senão, memória).
    // -------------------------------------------------------------------------
    function createMemoryStore() {
        const mem = Object.create(null);
        return {
            getItem(k) { return Object.prototype.hasOwnProperty.call(mem, k) ? mem[k] : null; },
            setItem(k, v) { mem[k] = String(v); },
            removeItem(k) { delete mem[k]; },
            _isMemory: true
        };
    }

    function resolveStore() {
        try {
            const ls = (typeof globalThis !== 'undefined' && globalThis.localStorage)
                ? globalThis.localStorage
                : (typeof localStorage !== 'undefined' ? localStorage : undefined);
            if (!ls) return createMemoryStore();
            const probe = '__pep_exc_probe__';
            ls.setItem(probe, '1');
            ls.removeItem(probe);
            return ls;
        } catch (e) {
            return createMemoryStore();
        }
    }

    const store = resolveStore();

    // Estado em memória: lista de solicitações (ordem de criação).
    let requests = [];
    load();

    // -------------------------------------------------------------------------
    // Persistência
    // -------------------------------------------------------------------------
    function persist() {
        try { store.setItem(STORAGE_KEY, JSON.stringify(requests)); }
        catch (e) { /* ignora falha de escrita */ }
    }

    function load() {
        let raw = null;
        try { raw = store.getItem(STORAGE_KEY); } catch (e) { raw = null; }
        if (!raw) { requests = []; return; }
        try {
            const parsed = JSON.parse(raw);
            requests = Array.isArray(parsed) ? parsed.filter(isValidShape) : [];
        } catch (e) { requests = []; }
    }

    // Checa a forma mínima de um registro persistido (defensivo contra storage
    // corrompido/forjado).
    function isValidShape(r) {
        return r && typeof r === 'object'
            && typeof r.id === 'string'
            && typeof r.status === 'string'
            && r.squad != null && r.indicador != null;
    }

    function notify() {
        const snap = list();
        for (const fn of subscribers.slice()) {
            try { fn(snap); } catch (e) { /* isolado por subscriber */ }
        }
    }

    function subscribe(fn) {
        if (typeof fn !== 'function') return () => {};
        subscribers.push(fn);
        return function unsubscribe() {
            const idx = subscribers.indexOf(fn);
            if (idx >= 0) subscribers.splice(idx, 1);
        };
    }

    // -------------------------------------------------------------------------
    // Util
    // -------------------------------------------------------------------------
    function now() { return new Date().toISOString(); }

    // Gera um id único e estável (não depende de libs externas).
    let _seq = 0;
    function genId() {
        _seq += 1;
        const rnd = Math.random().toString(36).slice(2, 8);
        return `exc-${Date.now().toString(36)}-${_seq.toString(36)}-${rnd}`;
    }

    function trimmed(v) { return (v == null) ? '' : String(v).trim(); }

    // Normaliza para comparação de período/indicador/squad (tolerante a espaço/caixa).
    function norm(v) { return trimmed(v).toLowerCase(); }

    // -------------------------------------------------------------------------
    // Validação dos campos obrigatórios (Req 30.8). Retorna a lista de erros
    // (vazia quando válido), cada um com { field, message } descritivos.
    // -------------------------------------------------------------------------
    function validate(input) {
        const errors = [];
        const src = input || {};
        REQUIRED_FIELDS.forEach((f) => {
            if (trimmed(src[f]) === '') {
                errors.push({ field: f, message: `O campo "${FIELD_LABEL[f]}" é obrigatório.` });
            }
        });
        // Quando o tratamento é "substituir", a métrica substituta é obrigatória.
        if (trimmed(src.tratamento) === TRATAMENTO.SUBSTITUIR && trimmed(src.metricaSubstituta) === '') {
            errors.push({ field: 'metricaSubstituta', message: `O campo "${FIELD_LABEL.metricaSubstituta}" é obrigatório quando a métrica é substituída.` });
        }
        return errors;
    }

    // Normaliza o tratamento para um valor válido (default = redistribuir).
    function normTratamento(v) {
        const t = trimmed(v);
        return (t === TRATAMENTO.SUBSTITUIR) ? TRATAMENTO.SUBSTITUIR : TRATAMENTO.REDISTRIBUIR;
    }

    // -------------------------------------------------------------------------
    // Criação de uma Solicitação de Exceção (Req 30.1, 30.2, 30.10).
    //
    // input = { squad, indicador, justificativa, periodo, solicitante }
    //   - solicitante: identificação de quem solicita (ex.: perfil/nome).
    //
    // Lança Error com a mensagem descritiva do PRIMEIRO campo faltante quando a
    // validação falha (Req 30.8). A view deve chamar `validate` antes para
    // exibir todos os erros; aqui garantimos a invariante do store.
    //
    // Estado inicial = "pendente" (Req 30.2); registra o evento 'solicitada' no
    // histórico (Req 30.10).
    // -------------------------------------------------------------------------
    function request(input) {
        const errors = validate(input);
        if (errors.length) {
            const err = new Error(errors[0].message);
            err.validation = errors;
            throw err;
        }
        const ts = now();
        const tratamento = normTratamento(input.tratamento);
        const rec = {
            id: genId(),
            squad: trimmed(input.squad),
            indicador: trimmed(input.indicador),
            justificativa: trimmed(input.justificativa),
            periodo: trimmed(input.periodo),
            solicitante: trimmed(input.solicitante) || 'desconhecido',
            // Tratamento da pontuação da métrica excetuada (Req: redistribuir/substituir).
            tratamento,
            metricaSubstituta: (tratamento === TRATAMENTO.SUBSTITUIR) ? trimmed(input.metricaSubstituta) : null,
            status: STATUS.PENDENTE,
            criadoEm: ts,
            atualizadoEm: ts,
            // Decisão (preenchida na aprovação/rejeição).
            decididoPor: null,
            decididoEm: null,
            motivoRejeicao: null,
            // Histórico de estados (rastreabilidade — Req 30.10).
            historico: [{ estado: STATUS.PENDENTE, por: trimmed(input.solicitante) || 'desconhecido', em: ts, acao: 'solicitada' }]
        };
        requests.push(rec);
        persist();
        notify();
        return clone(rec);
    }

    // -------------------------------------------------------------------------
    // Aprovação de uma solicitação pendente (Req 30.3, 30.4).
    //   decidor: quem aprova (perfil/nome). O controle de PERMISSÃO
    //   (`excecao:aprovar`) é feito pela view via Rbac; o store apenas aplica a
    //   transição e registra o histórico.
    // Só transiciona a partir de "pendente".
    // -------------------------------------------------------------------------
    function approve(id, decidor) {
        const rec = find(id);
        if (!rec) throw new Error(`Exceção não encontrada: ${id}`);
        if (rec.status !== STATUS.PENDENTE) {
            throw new Error(`Somente solicitações pendentes podem ser aprovadas (estado atual: ${STATUS_LABEL[rec.status] || rec.status}).`);
        }
        const ts = now();
        rec.status = STATUS.APROVADA;
        rec.decididoPor = trimmed(decidor) || 'desconhecido';
        rec.decididoEm = ts;
        rec.atualizadoEm = ts;
        rec.historico.push({ estado: STATUS.APROVADA, por: rec.decididoPor, em: ts, acao: 'aprovada' });
        persist();
        notify();
        return clone(rec);
    }

    // -------------------------------------------------------------------------
    // Rejeição de uma solicitação pendente (Req 30.3, 30.6).
    //   motivo: obrigatório na rejeição (Req 30.3 "no caso de rejeição, o
    //   motivo"). Sem efeito nas visões (Req 30.6).
    // -------------------------------------------------------------------------
    function reject(id, decidor, motivo) {
        const rec = find(id);
        if (!rec) throw new Error(`Exceção não encontrada: ${id}`);
        if (rec.status !== STATUS.PENDENTE) {
            throw new Error(`Somente solicitações pendentes podem ser rejeitadas (estado atual: ${STATUS_LABEL[rec.status] || rec.status}).`);
        }
        if (trimmed(motivo) === '') {
            throw new Error('O motivo da rejeição é obrigatório.');
        }
        const ts = now();
        rec.status = STATUS.REJEITADA;
        rec.decididoPor = trimmed(decidor) || 'desconhecido';
        rec.decididoEm = ts;
        rec.motivoRejeicao = trimmed(motivo);
        rec.atualizadoEm = ts;
        rec.historico.push({ estado: STATUS.REJEITADA, por: rec.decididoPor, em: ts, acao: 'rejeitada', motivo: rec.motivoRejeicao });
        persist();
        notify();
        return clone(rec);
    }

    // -------------------------------------------------------------------------
    // Consultas
    // -------------------------------------------------------------------------
    function find(id) { return requests.find((r) => r.id === id) || null; }

    // Lista todas (clones), mais recentes por último (ordem de criação).
    function list() { return requests.map(clone); }

    // Fila de pendências (Req 30.5): apenas "pendente".
    function pending() { return requests.filter((r) => r.status === STATUS.PENDENTE).map(clone); }

    // Exceções aprovadas (Req 30.4): usadas para sinalizar o indicador afetado.
    function approved() { return requests.filter((r) => r.status === STATUS.APROVADA).map(clone); }

    // Rejeitadas.
    function rejected() { return requests.filter((r) => r.status === STATUS.REJEITADA).map(clone); }

    // Existe EXCEÇÃO APROVADA para o Squad+Indicador (+ período opcional)?
    // É a consulta que as visões usam para sinalizar o indicador afetado
    // SOMENTE quando "Aprovada" (Req 30.4). Pendente/Rejeitada NÃO contam
    // (Req 30.5, 30.6).
    function hasApprovedException(squad, indicador, periodo) {
        return requests.some((r) =>
            r.status === STATUS.APROVADA
            && norm(r.squad) === norm(squad)
            && norm(r.indicador) === norm(indicador)
            && (periodo == null || norm(r.periodo) === norm(periodo)));
    }

    // Lista de exceções aprovadas que afetam um squad (todas as métricas),
    // opcionalmente filtradas por período. Útil para a view marcar os
    // indicadores afetados.
    function approvedFor(squad, periodo) {
        return requests.filter((r) =>
            r.status === STATUS.APROVADA
            && norm(r.squad) === norm(squad)
            && (periodo == null || norm(r.periodo) === norm(periodo))
        ).map(clone);
    }

    // Clona profundamente um registro (evita mutação externa do estado interno).
    function clone(rec) {
        return JSON.parse(JSON.stringify(rec));
    }

    // Limpa tudo (uso em testes/dev). Mantém a persistência coerente.
    function clear() {
        requests = [];
        persist();
        notify();
    }

    // Recarrega do storage (uso em testes após manipular localStorage direto).
    function reload() { load(); notify(); return list(); }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        STATUS,
        STATUS_LABEL,
        TRATAMENTO,
        REQUIRED_FIELDS,
        FIELD_LABEL,
        STORAGE_KEY,
        validate,
        request,
        approve,
        reject,
        find,
        list,
        pending,
        approved,
        rejected,
        hasApprovedException,
        approvedFor,
        subscribe,
        clear,
        reload
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ExcecoesStore;
}
if (typeof globalThis !== 'undefined') {
    globalThis.ExcecoesStore = ExcecoesStore;
}
