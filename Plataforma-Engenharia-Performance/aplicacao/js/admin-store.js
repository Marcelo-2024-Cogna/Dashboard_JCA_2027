/**
 * PEP - Plataforma de Engenharia de Performance
 * Store de Gestão (CRUD client-side) — `js/admin-store.js`
 *
 * Task 33.1 (spec score-performance-executivo). Requirement 31.8 (round-trip
 * de ida e volta das coleções) e, por extensão, as operações CRUD das telas de
 * gestão descritas no Requirement 31 (31.1–31.7, 31.9).
 *
 * Guarda, no navegador (localStorage), as coleções de administração da
 * plataforma:
 *   - perfis          (Req 31.2) — nome do Perfil e lista de permissões (telas).
 *   - tribosSquads    (Req 31.3) — Tribo (Jornada) e Squad.
 *   - integracao      (Req 31.7) — parâmetros de ingestão (mapeamento de
 *                                  projetos do Jira → Jornada/Squad). NÃO
 *                                  armazena credenciais sensíveis (token/senha).
 *
 * API (contrato do design — bloco "AdminStore"):
 *   list(collection) -> [...]            — itens (clones) da coleção.
 *   create(collection, item) -> item     — cria com id estável.
 *   update(collection, id, patch) -> item| null
 *   remove(collection, id) -> boolean
 *   toCSV(collection) -> string          — exportação reversível (';' + BOM).
 *   fromCSV(collection, text) -> { imported, rejected:[{row,errors}] }
 *   toJSON(collection) -> string         — exportação reversível em JSON.
 *   fromJSON(collection, text) -> { imported, rejected:[...] }
 *
 * Round-trip (Property 37): para qualquer coleção válida,
 * `fromCSV(toCSV(coleção))` (e o equivalente JSON) produz um conjunto
 * equivalente, com tolerância de duas casas decimais nos campos numéricos.
 *
 * Persistência defensiva: `localStorage` pode não existir em Node (Vitest) ou
 * em modo privado; nesse caso caímos para um store em memória, mantendo o
 * módulo testável sem browser e o app estático intacto (mesmo padrão de
 * `app-state.js` e `excecoes-store.js`).
 *
 * IIFE com guarda de ambiente (`module.exports`/`globalThis`) para teste em
 * Node sem afetar o carregamento por <script> no browser. Módulo puro no
 * carregamento (sem efeitos colaterais além de resolver o storage).
 */

const AdminStore = (() => {
    'use strict';

    const STORAGE_PREFIX = 'pep.admin.';
    const CSV_SEPARATOR = ';';
    const BOM = '\uFEFF';

    // -------------------------------------------------------------------------
    // SCHEMAS POR COLEÇÃO
    // Cada campo declara seu `kind` para o round-trip CSV/JSON:
    //   'text' → string (round-trip literal; vazio preservado como '')
    //   'num'  → número (decimais com vírgula OU ponto na entrada; saída com
    //            ponto; tolerância de 2 casas no round-trip)
    //   'bool' → booleano (true/false; aceita 1/0, sim/não na entrada)
    //   'list' → lista de strings (serializada com separador interno '|')
    //
    // `id` é sempre o primeiro campo (identidade estável); gerado quando ausente.
    // -------------------------------------------------------------------------
    const LIST_ITEM_SEP = '|';

    const SCHEMAS = {
        // Req 31.2 — Perfis e associação de permissões (telas permitidas).
        perfis: {
            fields: [
                { key: 'id', kind: 'text' },
                { key: 'nome', kind: 'text' },
                { key: 'permissoes', kind: 'list' }
            ]
        },
        // Req 31.3 — Tribos (Jornadas) e Squads (consistente com a identidade canônica).
        tribosSquads: {
            fields: [
                { key: 'id', kind: 'text' },
                { key: 'tribo', kind: 'text' },
                { key: 'squad', kind: 'text' }
            ]
        },
        // Req 31.7 — Configuração de integração (mapeamento Jira → Jornada/Squad).
        // IMPORTANTE: NÃO armazenar credenciais sensíveis (token, senha, apiKey).
        integracao: {
            fields: [
                { key: 'id', kind: 'text' },
                { key: 'sistema', kind: 'text' },
                { key: 'projetoJira', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'squad', kind: 'text' }
            ]
        }
    };

    const COLLECTIONS = Object.keys(SCHEMAS);

    // Chaves consideradas sensíveis — removidas de QUALQUER item antes de
    // persistir (Req 31.7: integração sem credenciais sensíveis no navegador).
    const SENSITIVE_KEYS = ['token', 'senha', 'password', 'apikey', 'apiKey',
        'secret', 'secretKey', 'credential', 'credentials', 'authorization', 'auth'];

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
            const probe = '__pep_admin_probe__';
            ls.setItem(probe, '1');
            ls.removeItem(probe);
            return ls;
        } catch (e) {
            return createMemoryStore();
        }
    }

    const store = resolveStore();

    // -------------------------------------------------------------------------
    // Util
    // -------------------------------------------------------------------------
    function assertCollection(collection) {
        if (!Object.prototype.hasOwnProperty.call(SCHEMAS, collection)) {
            throw new Error(`Coleção de gestão desconhecida: "${collection}". Válidas: ${COLLECTIONS.join(', ')}.`);
        }
    }

    function storageKey(collection) { return STORAGE_PREFIX + collection; }

    function trimmed(v) { return (v == null) ? '' : String(v).trim(); }

    let _seq = 0;
    function genId(collection) {
        _seq += 1;
        const rnd = Math.random().toString(36).slice(2, 8);
        return `${collection}-${Date.now().toString(36)}-${_seq.toString(36)}-${rnd}`;
    }

    function clone(obj) { return JSON.parse(JSON.stringify(obj)); }

    // Remove chaves sensíveis de um item (defesa de credenciais — Req 31.7).
    function stripSensitive(item) {
        if (!item || typeof item !== 'object') return item;
        const lowerSensitive = SENSITIVE_KEYS.map(k => k.toLowerCase());
        Object.keys(item).forEach((k) => {
            if (lowerSensitive.includes(String(k).toLowerCase())) {
                delete item[k];
            }
        });
        return item;
    }

    // Normaliza um valor numérico de entrada: aceita número, ou string com
    // vírgula/ponto decimal. Retorna número, null (vazio) ou NaN (inválido).
    function parseNumeric(raw) {
        if (raw === null || raw === undefined) return null;
        if (typeof raw === 'number') return Number.isFinite(raw) ? raw : NaN;
        const s = String(raw).trim();
        if (s === '') return null;
        const n = Number(s.replace(',', '.'));
        return Number.isNaN(n) ? NaN : n;
    }

    // Normaliza um booleano de entrada (true/false, 1/0, sim/não, yes/no).
    function parseBool(raw) {
        if (typeof raw === 'boolean') return raw;
        const s = trimmed(raw).toLowerCase();
        if (s === '') return false;
        return s === 'true' || s === '1' || s === 'sim' || s === 'yes';
    }

    // -------------------------------------------------------------------------
    // Normalização de item conforme o schema da coleção. Garante o shape
    // canônico (todos os campos presentes, tipos corretos) e descarta chaves
    // fora do schema e sensíveis.
    // -------------------------------------------------------------------------
    function normalizeItem(collection, raw) {
        const schema = SCHEMAS[collection];
        const src = (raw && typeof raw === 'object') ? raw : {};
        const out = {};
        schema.fields.forEach(({ key, kind }) => {
            const v = src[key];
            switch (kind) {
                case 'num': {
                    const n = parseNumeric(v);
                    out[key] = (n === null || Number.isNaN(n)) ? null : n;
                    break;
                }
                case 'bool':
                    out[key] = parseBool(v);
                    break;
                case 'list':
                    out[key] = normalizeList(v);
                    break;
                case 'text':
                default:
                    out[key] = trimmed(v);
                    break;
            }
        });
        return out;
    }

    // Lista de strings: aceita array ou string serializada ('a|b|c').
    function normalizeList(v) {
        let arr;
        if (Array.isArray(v)) {
            arr = v;
        } else if (v == null || v === '') {
            arr = [];
        } else {
            arr = String(v).split(LIST_ITEM_SEP);
        }
        return arr.map(x => trimmed(x)).filter(x => x !== '');
    }

    // -------------------------------------------------------------------------
    // Persistência
    // -------------------------------------------------------------------------
    function readRaw(collection) {
        let raw = null;
        try { raw = store.getItem(storageKey(collection)); } catch (e) { raw = null; }
        if (!raw) return [];
        try {
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed
                .filter(r => r && typeof r === 'object')
                .map(r => normalizeItem(collection, r))
                .filter(r => trimmed(r.id) !== '');
        } catch (e) {
            return [];
        }
    }

    function writeRaw(collection, items) {
        try { store.setItem(storageKey(collection), JSON.stringify(items)); }
        catch (e) { /* ignora falha de escrita (mantém app estático estável) */ }
    }

    // =========================================================================
    // CRUD
    // =========================================================================
    function list(collection) {
        assertCollection(collection);
        return readRaw(collection).map(clone);
    }

    function get(collection, id) {
        assertCollection(collection);
        const found = readRaw(collection).find(r => r.id === String(id));
        return found ? clone(found) : null;
    }

    function create(collection, item) {
        assertCollection(collection);
        const safe = stripSensitive(clone(item || {}));
        const rec = normalizeItem(collection, safe);
        if (trimmed(rec.id) === '') rec.id = genId(collection);
        const items = readRaw(collection);
        items.push(rec);
        writeRaw(collection, items);
        return clone(rec);
    }

    function update(collection, id, patch) {
        assertCollection(collection);
        const items = readRaw(collection);
        const idx = items.findIndex(r => r.id === String(id));
        if (idx < 0) return null;
        const safePatch = stripSensitive(clone(patch || {}));
        // Mescla preservando o id original, depois re-normaliza.
        const merged = Object.assign({}, items[idx], safePatch, { id: items[idx].id });
        items[idx] = normalizeItem(collection, merged);
        writeRaw(collection, items);
        return clone(items[idx]);
    }

    function remove(collection, id) {
        assertCollection(collection);
        const items = readRaw(collection);
        const next = items.filter(r => r.id !== String(id));
        if (next.length === items.length) return false;
        writeRaw(collection, next);
        return true;
    }

    function clear(collection) {
        if (collection === undefined) {
            COLLECTIONS.forEach(c => { try { store.removeItem(storageKey(c)); } catch (e) { /* ignore */ } });
            return;
        }
        assertCollection(collection);
        try { store.removeItem(storageKey(collection)); } catch (e) { /* ignore */ }
    }

    // Substitui a coleção inteira (uso em import/testes). Normaliza e persiste.
    function replaceAll(collection, items) {
        assertCollection(collection);
        const normalized = (Array.isArray(items) ? items : [])
            .map(it => {
                const rec = normalizeItem(collection, stripSensitive(clone(it || {})));
                if (trimmed(rec.id) === '') rec.id = genId(collection);
                return rec;
            });
        writeRaw(collection, normalized);
        return normalized.map(clone);
    }

    // =========================================================================
    // Serialização de célula CSV por tipo (reversível).
    //   - text → literal (round-trip exato)
    //   - num  → ponto decimal; null → '' (sem dado)
    //   - bool → 'true'/'false'
    //   - list → itens unidos por '|'
    // Convenção do projeto: valores de texto NÃO contêm ';' nem quebras de
    // linha (fora do escopo das coleções de gestão), por isso não há aspas.
    // =========================================================================
    function formatCell(kind, value) {
        switch (kind) {
            case 'num':
                if (value === null || value === undefined) return '';
                if (typeof value !== 'number' || !Number.isFinite(value)) return '';
                return String(value);
            case 'bool':
                return value ? 'true' : 'false';
            case 'list':
                return Array.isArray(value) ? value.join(LIST_ITEM_SEP) : normalizeList(value).join(LIST_ITEM_SEP);
            case 'text':
            default:
                return value === null || value === undefined ? '' : String(value);
        }
    }

    // =========================================================================
    // toCSV(collection) → string
    // Exportação reversível: prefixo BOM UTF-8, separador ';', cabeçalho
    // canônico (ordem do schema), uma linha por item. Linhas separadas por
    // CRLF (compatível com planilhas); o parser tolera CRLF/LF/CR.
    // =========================================================================
    function toCSV(collection, items) {
        assertCollection(collection);
        const schema = SCHEMAS[collection];
        const source = items === undefined ? readRaw(collection)
            : (Array.isArray(items) ? items.map(it => normalizeItem(collection, it)) : []);

        const header = schema.fields.map(f => f.key);
        const lines = [header.join(CSV_SEPARATOR)];

        source.forEach((rec) => {
            const row = schema.fields.map(f => formatCell(f.kind, rec[f.key]));
            lines.push(row.join(CSV_SEPARATOR));
        });

        return BOM + lines.join('\r\n');
    }

    // =========================================================================
    // fromCSV(collection, text) → { imported, rejected:[{row,errors}], records }
    // Importa reversivelmente a partir do CSV produzido por toCSV. Cada linha
    // válida vira um item normalizado e SUBSTITUI a coleção persistida (import
    // de ida e volta — Req 31.8). Linhas sem id recebem id gerado; linhas
    // estruturalmente inválidas vão para `rejected`.
    //
    // Observação de contrato: o round-trip (Property 37) é avaliado sobre os
    // REGISTROS retornados (`records`), equivalentes à coleção de origem.
    // =========================================================================
    function fromCSV(collection, text) {
        assertCollection(collection);
        const schema = SCHEMAS[collection];
        const rejected = [];
        const records = [];

        if (typeof text !== 'string') {
            return { imported: 0, rejected: [{ row: 0, errors: ['CSV inválido: conteúdo não textual.'] }], records };
        }

        let content = text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
        const lines = content.split(/\r\n|\n|\r/).filter(l => l.trim() !== '');
        if (lines.length === 0) {
            return { imported: 0, rejected: [{ row: 0, errors: ['CSV vazio: nenhuma linha encontrada.'] }], records };
        }

        const header = lines[0].split(CSV_SEPARATOR).map(h => h.trim());
        const colIndex = {};
        header.forEach((h, i) => { colIndex[h] = i; });

        const missing = schema.fields.map(f => f.key).filter(k => !(k in colIndex));
        if (missing.length > 0) {
            return {
                imported: 0,
                rejected: [{ row: 1, errors: [`Cabeçalho CSV inválido: faltam colunas [${missing.join(', ')}]. Esperado: ${schema.fields.map(f => f.key).join(CSV_SEPARATOR)}`] }],
                records
            };
        }

        for (let i = 1; i < lines.length; i++) {
            const lineNo = i + 1;
            const cells = lines[i].split(CSV_SEPARATOR);
            const raw = {};
            const errors = [];
            schema.fields.forEach(({ key, kind }) => {
                const cell = cells[colIndex[key]];
                const value = cell === undefined ? '' : String(cell).trim();
                if (kind === 'num' && value !== '') {
                    const n = parseNumeric(value);
                    if (Number.isNaN(n)) errors.push(`${key}: valor não numérico ("${value}")`);
                }
                raw[key] = value;
            });

            if (errors.length) {
                rejected.push({ row: lineNo, errors });
                continue;
            }

            const rec = normalizeItem(collection, raw);
            if (trimmed(rec.id) === '') rec.id = genId(collection);
            records.push(rec);
        }

        writeRaw(collection, records);
        return { imported: records.length, rejected, records: records.map(clone) };
    }

    // =========================================================================
    // JSON reversível. Formato: { collection, records:[...] } ou um array puro.
    // =========================================================================
    function toJSON(collection, items) {
        assertCollection(collection);
        const source = items === undefined ? readRaw(collection)
            : (Array.isArray(items) ? items.map(it => normalizeItem(collection, it)) : []);
        return JSON.stringify({ collection, records: source }, null, 2);
    }

    function fromJSON(collection, text) {
        assertCollection(collection);
        const rejected = [];
        let data;
        try {
            data = (typeof text === 'string') ? JSON.parse(text) : text;
        } catch (e) {
            return { imported: 0, rejected: [{ row: 0, errors: [`JSON inválido: ${e.message}`] }], records: [] };
        }
        const rawRecords = Array.isArray(data) ? data
            : (data && Array.isArray(data.records) ? data.records : null);
        if (!rawRecords) {
            return { imported: 0, rejected: [{ row: 0, errors: ['JSON inválido: esperado array ou { collection, records }.'] }], records: [] };
        }

        const records = [];
        rawRecords.forEach((rec, i) => {
            if (!rec || typeof rec !== 'object') {
                rejected.push({ row: i + 1, errors: ['registro: estrutura inválida (esperado objeto)'] });
                return;
            }
            const normalized = normalizeItem(collection, stripSensitive(clone(rec)));
            if (trimmed(normalized.id) === '') normalized.id = genId(collection);
            records.push(normalized);
        });

        writeRaw(collection, records);
        return { imported: records.length, rejected, records: records.map(clone) };
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        STORAGE_PREFIX,
        CSV_SEPARATOR,
        BOM,
        LIST_ITEM_SEP,
        SCHEMAS,
        COLLECTIONS,
        SENSITIVE_KEYS,
        // CRUD
        list,
        get,
        create,
        update,
        remove,
        clear,
        replaceAll,
        // Import/Export reversível
        toCSV,
        fromCSV,
        toJSON,
        fromJSON,
        // Helpers expostos para testes/consumo
        normalizeItem
    };
})();

// Environment guard: expõe o módulo IIFE para testes em Node (Vitest) sem
// afetar o carregamento por <script> no navegador.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminStore;
}
if (typeof globalThis !== 'undefined') {
    globalThis.AdminStore = AdminStore;
}
