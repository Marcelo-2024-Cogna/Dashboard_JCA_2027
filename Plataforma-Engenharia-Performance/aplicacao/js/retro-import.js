/**
 * PEP - Plataforma de Engenharia de Performance
 * Importação/Exportação - Retrospectiva de PI (Retro PI) — retro-import.js
 *
 * Task 14 (spec score-performance-executivo): parser, validação e exportação
 * reversível dos dados de Retrospectiva de PI em CSV/JSON, mantendo a
 * abordagem de app estático sem backend. Descrito no design em
 * "Módulo de Métricas de Retrospectiva de PI".
 * Requirements 18.2 (importar CSV/JSON), 18.3 (validar PI/Time/indicador),
 * 18.4 (rejeitar inválidos com mensagem) e 18.6 (round-trip reversível).
 *
 * Diferença essencial em relação a `data-import.js`: o módulo de Retro PI lida
 * com VÁRIAS coleções heterogêneas (pontosTrabalhados, cycleTime, leadTime,
 * epicos, debito, sustentacao, ...). Cada registro carrega uma coluna "tipo"
 * (= nome da coleção), além de PI/Time e os campos do indicador. O cabeçalho
 * CSV é a UNIÃO estável dos campos de todas as coleções; cada linha preenche
 * somente as colunas do seu tipo. O round-trip preserva o tipo e os campos.
 *
 * API (espelha o contrato de data-import.js):
 *   - parseCSV(text)       → { records, errors }
 *   - parseJSON(text)      → { records, errors }
 *   - validateRecord(rec)  → { valid, errors:[string] } (por tipo de registro)
 *   - importText(text,fmt) → { imported, rejected:[{record,errors}], ... }
 *   - toCSV(records)       → string (';' + BOM, cabeçalho estável, reversível)
 *
 * Segue o padrão IIFE + guarda de ambiente (module.exports/globalThis) dos
 * demais módulos; é puro e carrega sem efeitos colaterais.
 */

const RetroImport = (() => {

    const CSV_SEPARATOR = ';';
    const BOM = '\uFEFF';

    // Separadores internos para o campo-mapa `porPI` (produtividade). Evitamos
    // o ';' do CSV: pares separados por '|' e chave=valor por '='.
    const MAP_PAIR_SEP = '|';
    const MAP_KV_SEP = '=';

    // =========================================================================
    // SCHEMAS POR TIPO DE REGISTRO (coleção)
    // Cada tipo declara:
    //   - key:   campos de identidade/indicador na ordem canônica.
    //   - kind:  'text' | 'num' | 'int' | 'map' | 'textOrNull'.
    //   - required: campos exigidos na validação (18.3). A regra base — PI,
    //     Time e o indicador correspondente — é expressa por tipo, pois nem toda
    //     coleção tem `time` (ex.: leadTime usa `squad`, cargaTrabalho não tem
    //     time) e nem toda tem `pi` como campo direto (produtividade usa `porPI`).
    //   - indicator: ao menos UM destes campos precisa ter valor numérico, para
    //     garantir que o registro "contém o indicador correspondente ao painel".
    // =========================================================================
    const TYPE_SCHEMAS = {
        pontosTrabalhados: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'sprint', kind: 'text' },
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'sustentacao', kind: 'num' },
                { key: 'entregaValor', kind: 'num' },
                { key: 'total', kind: 'num' },
                { key: 'planejado', kind: 'num' },
                { key: 'real', kind: 'num' },
                { key: 'piPlanning', kind: 'num' },
                { key: 'velocidade', kind: 'num' }
            ],
            required: ['pi', 'time'],
            indicator: ['sustentacao', 'entregaValor', 'total']
        },
        produtividade: {
            fields: [
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'porPI', kind: 'map' }
            ],
            required: ['time'],
            indicator: ['porPI']
        },
        epicos: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'chave', kind: 'text' },
                { key: 'situacao', kind: 'text' },
                { key: 'origem', kind: 'text' }
            ],
            required: ['pi', 'chave'],
            indicator: ['situacao']
        },
        cycleTime: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'ideacao', kind: 'num' },
                { key: 'desenvolvimento', kind: 'num' },
                { key: 'homologacao', kind: 'num' },
                { key: 'implantacao', kind: 'num' }
            ],
            required: ['pi', 'time'],
            indicator: ['ideacao', 'desenvolvimento', 'homologacao', 'implantacao']
        },
        leadTime: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'squad', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'tipoItem', kind: 'text' },
                { key: 'dias', kind: 'num' }
            ],
            // leadTime usa 'squad' como "Time"; exige PI, squad e o indicador.
            required: ['pi', 'squad'],
            indicator: ['dias']
        },
        debito: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'backlogQtde', kind: 'num' },
                { key: 'planejadoSP', kind: 'num' },
                { key: 'realSP', kind: 'num' },
                { key: 'entregueSP', kind: 'num' },
                { key: 'pendenteSP', kind: 'num' },
                { key: 'canceladoSP', kind: 'num' },
                { key: 'planejadoQtd', kind: 'num' },
                { key: 'realQtd', kind: 'num' },
                { key: 'entregueQtd', kind: 'num' },
                { key: 'pendenteQtd', kind: 'num' },
                { key: 'canceladoQtd', kind: 'num' }
            ],
            required: ['pi', 'time'],
            indicator: ['backlogQtde', 'planejadoSP', 'realSP', 'entregueSP']
        },
        sustentacao: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'realizado', kind: 'num' },
                { key: 'planejado', kind: 'num' }
            ],
            required: ['pi', 'time'],
            indicator: ['realizado', 'planejado']
        },
        sustentacaoHistorico: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'time', kind: 'text' },
                { key: 'jornada', kind: 'text' },
                { key: 'valor', kind: 'num' },
                { key: 'pct', kind: 'num' }
            ],
            required: ['pi', 'time'],
            indicator: ['valor', 'pct']
        },
        cargaTrabalho: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'tipoItem', kind: 'text' },
                { key: 'pontos', kind: 'num' },
                { key: 'pontosPct', kind: 'num' },
                { key: 'qtdeItens', kind: 'num' },
                { key: 'itensPct', kind: 'num' },
                { key: 'pontuacaoMedia', kind: 'num' }
            ],
            // cargaTrabalho não tem Time; o eixo é Tipo de Item.
            required: ['pi', 'tipoItem'],
            indicator: ['pontos', 'qtdeItens']
        },
        alteracoesPlano: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'dimensao', kind: 'text' },
                { key: 'baseline', kind: 'num' },
                { key: 'planPIPlanning', kind: 'num' },
                { key: 'mudancaEscopo', kind: 'num' },
                { key: 'cancelado', kind: 'num' },
                { key: 'transbordoAceito', kind: 'num' },
                { key: 'transbordoPendentes', kind: 'num' }
            ],
            required: ['pi', 'dimensao'],
            indicator: ['baseline', 'planPIPlanning']
        },
        impedimentos: {
            fields: [
                { key: 'pi', kind: 'text' },
                { key: 'sprint', kind: 'textOrNull' },
                { key: 'quantidade', kind: 'num' },
                { key: 'localizacao', kind: 'num' },
                { key: 'tempoRemocao', kind: 'num' }
            ],
            required: ['pi'],
            indicator: ['quantidade', 'localizacao', 'tempoRemocao']
        }
    };

    // Ordem canônica dos tipos (= ordem das coleções do dataset).
    const TYPE_ORDER = Object.keys(TYPE_SCHEMAS);

    // =========================================================================
    // Cabeçalho CSV canônico: 'tipo' seguido da UNIÃO estável dos campos de
    // todas as coleções (primeira aparição na ordem dos tipos). Mantido estável
    // para garantir reversibilidade do round-trip.
    // =========================================================================
    const CSV_HEADER = (() => {
        const header = ['tipo'];
        const seen = new Set(['tipo']);
        TYPE_ORDER.forEach((tipo) => {
            TYPE_SCHEMAS[tipo].fields.forEach((f) => {
                if (!seen.has(f.key)) {
                    seen.add(f.key);
                    header.push(f.key);
                }
            });
        });
        return header;
    })();

    // Mapa auxiliar: kind de cada campo (para parse de célula por nome de coluna).
    const FIELD_KIND = (() => {
        const map = {};
        TYPE_ORDER.forEach((tipo) => {
            TYPE_SCHEMAS[tipo].fields.forEach((f) => {
                // Um mesmo nome de campo tem o mesmo kind em todas as coleções
                // (ex.: 'pi' é sempre 'text', 'total' é sempre 'num').
                map[f.key] = f.kind;
            });
        });
        return map;
    })();

    // =========================================================================
    // Normalização de valor numérico. Campos vazios → null (Sem Dados).
    // Decimais aceitos com vírgula OU ponto (normalizados para '.').
    //   null   → ausente/vazio (válido)
    //   number → valor parseado
    //   NaN    → texto não numérico (inválido; o chamador detecta)
    // =========================================================================
    function parseNumeric(raw) {
        if (raw === null || raw === undefined) return null;
        if (typeof raw === 'number') return raw;
        const s = String(raw).trim();
        if (s === '') return null;
        const normalized = s.replace(',', '.');
        const n = Number(normalized);
        return Number.isNaN(n) ? NaN : n;
    }

    // Formata uma célula numérica, garantindo round-trip com parseNumeric.
    //   null/undefined/NaN/não-finito → '' (Sem Dados)
    //   number → representação decimal com PONTO (String(n), reversível).
    function formatNumericCell(value) {
        if (value === null || value === undefined) return '';
        if (typeof value !== 'number' || Number.isNaN(value) || !Number.isFinite(value)) {
            return '';
        }
        return String(value);
    }

    // =========================================================================
    // Serialização do campo-mapa `porPI` (produtividade).
    // Formato: "PI 4/25=5.07|PI 1/26=5.7|PI 2/26=5.4". Chaves preservadas na
    // ordem de inserção; valores com ponto decimal. Vazio quando ausente.
    // =========================================================================
    function serializeMap(map) {
        if (!map || typeof map !== 'object') return '';
        const parts = [];
        Object.keys(map).forEach((k) => {
            const v = map[k];
            parts.push(`${k}${MAP_KV_SEP}${formatNumericCell(v)}`);
        });
        return parts.join(MAP_PAIR_SEP);
    }

    // Desserializa "k=v|k=v" → { k: number }. Valores vazios viram null.
    // Retorna { map, error } — error não-nulo se o formato for inválido.
    function deserializeMap(raw) {
        const map = {};
        if (raw === null || raw === undefined) return { map, error: null };
        const s = String(raw).trim();
        if (s === '') return { map, error: null };
        const pairs = s.split(MAP_PAIR_SEP);
        for (const pair of pairs) {
            if (pair.trim() === '') continue;
            const idx = pair.indexOf(MAP_KV_SEP);
            if (idx < 0) {
                return { map, error: `porPI: par inválido "${pair}" (esperado chave=valor)` };
            }
            const k = pair.slice(0, idx).trim();
            const vRaw = pair.slice(idx + 1).trim();
            if (k === '') {
                return { map, error: `porPI: chave vazia em "${pair}"` };
            }
            const v = parseNumeric(vRaw);
            if (Number.isNaN(v)) {
                return { map, error: `porPI: valor não numérico para "${k}"` };
            }
            map[k] = v;
        }
        return { map, error: null };
    }

    // =========================================================================
    // Converte uma célula textual para o valor normalizado conforme o kind.
    //   text        → string (trim) ou undefined se vazia
    //   textOrNull  → string (trim) ou null se vazia (ex.: impedimentos.sprint)
    //   num         → number | null | NaN (via parseNumeric)
    //   map         → tratado à parte (deserializeMap)
    // =========================================================================
    function cellToValue(kind, cell) {
        const s = cell === undefined || cell === null ? '' : String(cell).trim();
        switch (kind) {
            case 'text':
                return s === '' ? undefined : s;
            case 'textOrNull':
                return s === '' ? null : s;
            case 'num':
                return parseNumeric(s);
            default:
                return s === '' ? undefined : s;
        }
    }

    // =========================================================================
    // Constrói um registro normalizado a partir de um mapa coluna→célula e do
    // schema do tipo. Campos ausentes no schema são ignorados.
    // Retorna { record, cellErrors:[string] } — cellErrors para mapas inválidos.
    // =========================================================================
    function buildRecordFromCells(tipo, getCell) {
        const schema = TYPE_SCHEMAS[tipo];
        const record = { tipo };
        const cellErrors = [];

        schema.fields.forEach((f) => {
            const raw = getCell(f.key);
            if (f.kind === 'map') {
                const { map, error } = deserializeMap(raw);
                if (error) cellErrors.push(error);
                record[f.key] = map;
            } else {
                const value = cellToValue(f.kind, raw);
                // Não gravamos `undefined` para campos de texto ausentes; mantém
                // o registro enxuto e a validação cuida da obrigatoriedade.
                if (value !== undefined) record[f.key] = value;
            }
        });

        return { record, cellErrors };
    }

    // =========================================================================
    // parseCSV(text) → { records, errors }
    // Separador ';', aceita BOM UTF-8, decimais com vírgula ou ponto.
    // A primeira coluna é obrigatoriamente 'tipo'. Linhas com tipo desconhecido
    // acumulam erro estrutural, mas o parse das demais prossegue.
    // =========================================================================
    function parseCSV(text) {
        const errors = [];
        const records = [];

        if (typeof text !== 'string') {
            errors.push('CSV inválido: conteúdo não textual.');
            return { records, errors };
        }

        let content = text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
        const lines = content.split(/\r\n|\n|\r/).filter(l => l.trim() !== '');
        if (lines.length === 0) {
            errors.push('CSV vazio: nenhuma linha encontrada.');
            return { records, errors };
        }

        const header = lines[0].split(CSV_SEPARATOR).map(h => h.trim());
        if (!header.includes('tipo')) {
            errors.push(
                `Cabeçalho CSV inválido: coluna "tipo" ausente. ` +
                `Esperado iniciar por: ${CSV_HEADER.join(CSV_SEPARATOR)}`
            );
            return { records, errors };
        }

        const colIndex = {};
        header.forEach((h, i) => { colIndex[h] = i; });

        for (let i = 1; i < lines.length; i++) {
            const lineNo = i + 1;
            const cells = lines[i].split(CSV_SEPARATOR);
            const get = (name) => {
                const idx = colIndex[name];
                const cell = idx !== undefined ? cells[idx] : undefined;
                return cell === undefined ? '' : String(cell).trim();
            };

            const tipo = get('tipo');
            if (!TYPE_SCHEMAS[tipo]) {
                errors.push(`Linha ${lineNo}: tipo desconhecido "${tipo}". Tipos válidos: ${TYPE_ORDER.join(', ')}.`);
                continue;
            }

            const { record, cellErrors } = buildRecordFromCells(tipo, get);
            record._line = lineNo;
            if (cellErrors.length > 0) {
                record._cellErrors = cellErrors;
            }
            records.push(record);
        }

        return { records, errors };
    }

    // =========================================================================
    // parseJSON(text) → { records, errors }
    // Aceita dois formatos:
    //   A) { collections: { pontosTrabalhados:[...], cycleTime:[...], ... } }
    //      ou o próprio Dataset (ex.: PEPRetroData.DATASET) com coleções no topo.
    //   B) { records: [ { tipo, ...campos } ] } — formato achatado por tipo.
    // Normaliza para a lista interna comum ao CSV (cada registro com `tipo`).
    // =========================================================================
    function parseJSON(text) {
        const errors = [];
        const records = [];

        let data;
        try {
            data = (typeof text === 'string') ? JSON.parse(text) : text;
        } catch (e) {
            errors.push(`JSON inválido: ${e.message}`);
            return { records, errors };
        }
        if (!data || typeof data !== 'object') {
            errors.push('JSON inválido: raiz deve ser um objeto.');
            return { records, errors };
        }

        const pushNormalized = (tipo, src, ref) => {
            const schema = TYPE_SCHEMAS[tipo];
            const rec = { tipo };
            schema.fields.forEach((f) => {
                const raw = src[f.key];
                if (f.kind === 'map') {
                    const map = {};
                    if (raw && typeof raw === 'object') {
                        Object.keys(raw).forEach((k) => {
                            map[k] = parseNumeric(raw[k]);
                        });
                    }
                    rec[f.key] = map;
                } else if (f.kind === 'num') {
                    rec[f.key] = parseNumeric(raw);
                } else if (f.kind === 'textOrNull') {
                    rec[f.key] = (raw === null || raw === undefined || String(raw).trim() === '')
                        ? null : String(raw).trim();
                } else { // text
                    if (raw !== undefined && raw !== null && String(raw).trim() !== '') {
                        rec[f.key] = String(raw).trim();
                    }
                }
            });
            rec._ref = ref;
            records.push(rec);
        };

        // Formato B: lista achatada com `tipo`.
        if (Array.isArray(data.records)) {
            data.records.forEach((rec, i) => {
                const src = (rec && typeof rec === 'object') ? rec : {};
                const tipo = src.tipo;
                if (!TYPE_SCHEMAS[tipo]) {
                    errors.push(`records[${i}]: tipo desconhecido "${tipo}".`);
                    return;
                }
                pushNormalized(tipo, src, `records[${i}]`);
            });
            return { records, errors };
        }

        // Formato A: coleções no topo (Dataset) ou em `data.collections`.
        const collections = (data.collections && typeof data.collections === 'object')
            ? data.collections : data;
        let anyCollection = false;
        TYPE_ORDER.forEach((tipo) => {
            const arr = collections[tipo];
            if (Array.isArray(arr)) {
                anyCollection = true;
                arr.forEach((rec, i) => {
                    const src = (rec && typeof rec === 'object') ? rec : {};
                    pushNormalized(tipo, src, `${tipo}[${i}]`);
                });
            }
        });

        if (!anyCollection && records.length === 0) {
            errors.push('JSON inválido: nenhuma coleção de Retro PI reconhecida nem lista "records".');
        }

        return { records, errors };
    }

    // =========================================================================
    // validateRecord(record) → { valid, errors:[string] }
    // Por tipo de registro: exige PI, Time (ou equivalente) e o indicador
    // correspondente ao painel (Requirement 18.3). Mensagens descritivas
    // "campo: motivo" (Requirement 18.4). Campos numéricos ausentes (null) são
    // "Sem Dados" e permitidos, desde que ao menos um indicador tenha valor.
    // =========================================================================
    function validateRecord(record) {
        const errors = [];

        if (!record || typeof record !== 'object') {
            return { valid: false, errors: ['registro: estrutura inválida (esperado objeto)'] };
        }

        const tipo = record.tipo;
        const schema = TYPE_SCHEMAS[tipo];
        if (!schema) {
            return {
                valid: false,
                errors: [`tipo: ausente ou desconhecido${tipo !== undefined ? ` ("${tipo}")` : ''}. Tipos válidos: ${TYPE_ORDER.join(', ')}.`]
            };
        }

        // Erros de célula propagados do parse (ex.: mapa inválido).
        if (Array.isArray(record._cellErrors)) {
            record._cellErrors.forEach(e => errors.push(e));
        }

        // Campos obrigatórios de identidade (PI, Time/equivalente, dimensão...).
        schema.required.forEach((field) => {
            const v = record[field];
            if (v === undefined || v === null || String(v).trim() === '') {
                errors.push(`${field}: campo obrigatório ausente`);
            }
        });

        // Domínio dos campos numéricos presentes + checagem de "NaN" (texto).
        schema.fields.forEach((f) => {
            if (f.kind === 'num') {
                const v = record[f.key];
                if (v === undefined || v === null) return; // Sem Dados: permitido
                if (typeof v !== 'number' || Number.isNaN(v)) {
                    errors.push(`${f.key}: valor não numérico`);
                } else if (!Number.isFinite(v)) {
                    errors.push(`${f.key}: valor inválido (não finito)`);
                }
            } else if (f.kind === 'map') {
                const m = record[f.key];
                if (m && typeof m === 'object') {
                    Object.keys(m).forEach((k) => {
                        const v = m[k];
                        if (v !== null && v !== undefined &&
                            (typeof v !== 'number' || Number.isNaN(v) || !Number.isFinite(v))) {
                            errors.push(`${f.key}.${k}: valor não numérico`);
                        }
                    });
                }
            }
        });

        // Indicador correspondente ao painel: ao menos um com valor.
        const hasIndicator = schema.indicator.some((key) => {
            const v = record[key];
            if (v === null || v === undefined) return false;
            if (TYPE_SCHEMAS[tipo].fields.find(f => f.key === key)?.kind === 'map') {
                return typeof v === 'object' && Object.keys(v).length > 0;
            }
            if (typeof v === 'number') return !Number.isNaN(v) && Number.isFinite(v);
            // Indicadores de texto (ex.: epicos.situacao).
            return String(v).trim() !== '';
        });
        if (!hasIndicator) {
            errors.push(`indicador: registro "${tipo}" não contém o indicador correspondente ao painel (${schema.indicator.join('/')})`);
        }

        return { valid: errors.length === 0, errors };
    }

    // =========================================================================
    // Monta uma linha CSV para um registro, respeitando a UNIÃO de colunas do
    // cabeçalho. Colunas não pertencentes ao tipo ficam vazias.
    // =========================================================================
    function recordToRow(record) {
        const tipo = record.tipo;
        const schema = TYPE_SCHEMAS[tipo];
        if (!schema) return null;

        // Conjunto de campos que o tipo possui, para preencher só as colunas certas.
        const fieldByKey = {};
        schema.fields.forEach(f => { fieldByKey[f.key] = f; });

        return CSV_HEADER.map((col) => {
            if (col === 'tipo') return tipo;
            const f = fieldByKey[col];
            if (!f) return ''; // coluna não pertence a este tipo
            const value = record[f.key];
            switch (f.kind) {
                case 'map':
                    return serializeMap(value);
                case 'num':
                    return formatNumericCell(value);
                case 'textOrNull':
                    return value === null || value === undefined ? '' : String(value);
                default: // text
                    return value === undefined || value === null ? '' : String(value);
            }
        }).join(CSV_SEPARATOR);
    }

    // =========================================================================
    // toCSV(records) → string
    // Exportação reversível (Requirements 18.6): BOM UTF-8, separador ';',
    // cabeçalho estável ('tipo' + união dos campos). Aceita:
    //   - um array de registros achatados ({ tipo, ...campos }), OU
    //   - um Dataset/objeto com coleções no topo (ex.: PEPRetroData.DATASET) ou
    //     em `.collections` — nesse caso achatamos as coleções conhecidas na
    //     ordem canônica dos tipos.
    // =========================================================================
    function flattenDataset(input) {
        const list = [];
        if (Array.isArray(input)) {
            input.forEach((rec) => {
                if (rec && typeof rec === 'object' && rec.tipo) list.push(rec);
            });
            return list;
        }
        if (!input || typeof input !== 'object') return list;

        const collections = (input.collections && typeof input.collections === 'object')
            ? input.collections : input;
        TYPE_ORDER.forEach((tipo) => {
            const arr = collections[tipo];
            if (Array.isArray(arr)) {
                arr.forEach((rec) => {
                    if (rec && typeof rec === 'object') {
                        list.push({ tipo, ...rec });
                    }
                });
            }
        });
        return list;
    }

    function toCSV(records) {
        const list = flattenDataset(records);
        const lines = [CSV_HEADER.join(CSV_SEPARATOR)];
        list.forEach((rec) => {
            const row = recordToRow(rec);
            if (row !== null) lines.push(row);
        });
        return BOM + lines.join('\r\n');
    }

    // =========================================================================
    // Normaliza um registro válido para o formato "limpo" de importação:
    // remove campos internos (_line, _ref, _cellErrors) e mantém só os campos
    // do schema (+ tipo).
    // =========================================================================
    function cleanRecord(record) {
        const schema = TYPE_SCHEMAS[record.tipo];
        const out = { tipo: record.tipo };
        schema.fields.forEach((f) => {
            if (Object.prototype.hasOwnProperty.call(record, f.key)) {
                if (f.kind === 'map') {
                    out[f.key] = { ...(record[f.key] || {}) };
                } else {
                    out[f.key] = record[f.key];
                }
            }
        });
        return out;
    }

    // =========================================================================
    // importText(text, format) → { imported, rejected:[{record,errors}], ... }
    // Faz o parse conforme o formato ('csv' | 'json'), valida cada registro,
    // importa os válidos (limpos) e acumula os inválidos em `rejected[]` com
    // mensagens descritivas (Requirement 18.4).
    // =========================================================================
    function importText(text, format) {
        const fmt = String(format || '').toLowerCase();
        let parsed;
        if (fmt === 'csv') {
            parsed = parseCSV(text);
        } else if (fmt === 'json') {
            parsed = parseJSON(text);
        } else {
            return {
                imported: 0,
                rejected: [],
                records: [],
                parseErrors: [`Formato não reconhecido: "${format}". Use "csv" ou "json".`]
            };
        }

        if (parsed.errors && parsed.errors.length > 0) {
            return {
                imported: 0,
                rejected: [],
                records: [],
                parseErrors: parsed.errors.slice()
            };
        }

        const validRecords = [];
        const rejected = [];
        parsed.records.forEach((rec) => {
            const { valid, errors } = validateRecord(rec);
            if (valid) {
                validRecords.push(cleanRecord(rec));
            } else {
                rejected.push({ record: rec, errors });
            }
        });

        return {
            imported: validRecords.length,
            rejected,
            records: validRecords,
            parseErrors: []
        };
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    return {
        // Constantes/metadados.
        CSV_SEPARATOR,
        BOM,
        CSV_HEADER,
        TYPE_SCHEMAS,
        TYPE_ORDER,
        FIELD_KIND,

        // Helpers de normalização (expostos para testes).
        parseNumeric,
        serializeMap,
        deserializeMap,
        flattenDataset,

        // Parsers e validação.
        parseCSV,
        parseJSON,
        validateRecord,
        importText,

        // Exportação reversível (Requirement 18.6).
        toCSV
    };
})();

// Environment guard: expõe o módulo IIFE para testes em Node (Vitest) sem
// afetar o carregamento por <script> no navegador.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RetroImport;
}

if (typeof globalThis !== 'undefined') {
    globalThis.RetroImport = RetroImport;
}
