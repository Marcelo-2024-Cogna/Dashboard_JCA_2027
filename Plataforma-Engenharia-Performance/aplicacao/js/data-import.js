/**
 * PEP - Plataforma de Engenharia de Performance
 * Importação de dados mensais — data-import.js
 *
 * Task 6.1 (spec score-performance-executivo): parser e validação de dados
 * mensais em CSV/JSON, mantendo a abordagem de app estático sem backend.
 * Requirements 8.3 (importar CSV/JSON), 8.4 (validar mês/jornada/time/métricas)
 * e 8.5 (rejeitar inválidos com mensagem descritiva).
 *
 * Escopo desta task:
 *   - parseCSV(text)      → separador ';', aceita BOM UTF-8, decimais com
 *                           vírgula OU ponto (normalizados para '.').
 *   - parseJSON(text)     → formato { year, records:[{month,jornada,teamName,metrics}] }.
 *   - validateRecord(rec) → { valid, errors:[string] } (campo + motivo).
 *   - importText(text,fmt)→ { imported:number, rejected:[{record,errors}] }.
 *
 * Task 6.2 adiciona `toCSV(records)` — exportação reversível (BOM UTF-8,
 * separador ';', cabeçalho canônico), round-trip com parseCSV/importText
 * (Requirements 8.7, 9.5). Os testes (Task 6.3) permanecem fora desta task.
 * O módulo é puro e carrega sem efeitos colaterais, seguindo o padrão IIFE +
 * guarda de ambiente (module.exports/globalThis) usado pelos demais módulos.
 */

const DataImport = (() => {

    // Rótulos de mês (Jan=1 ... Dez=12), espelham PEPData.MONTH_LABELS. Mantidos
    // localmente para que o módulo não dependa da ordem de carregamento dos
    // <script> (data-import.js pode carregar antes de data.js).
    const MONTH_LABELS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun',
        'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    // As 12 métricas na ordem do cabeçalho CSV do design ("Data Models").
    // Cada entrada define a chave e o domínio aceito para a validação.
    //   kind: 'ratio'    → razão (0..n), 1.0 = 100%; aceitamos [0, 2] para
    //                      acomodar métricas que podem "superar" (ex.: 1.1).
    //   kind: 'days'     → Cycle Time em dias (>= 0).
    //   kind: 'category' → Frequência de Deploy: inteiro >= 0.
    //   kind: 'thc0a10'  → Team Health Check na escala 0..10.
    const METRIC_FIELDS = [
        { key: 'planejadoVsRealizado', kind: 'ratio' },
        { key: 'cycleTimeDev',         kind: 'days' },
        { key: 'cycleTimeHomolog',     kind: 'days' },
        { key: 'cycleTimeImpl',        kind: 'days' },
        { key: 'freqDeploy',           kind: 'category' },
        { key: 'disponibilidade',      kind: 'ratio' },
        { key: 'vulnerabilidade',      kind: 'ratio' },
        { key: 'slaAlta',              kind: 'ratio' },
        { key: 'slaMedia',             kind: 'ratio' },
        { key: 'slaBaixa',             kind: 'ratio' },
        { key: 'okrs',                 kind: 'ratio' },
        { key: 'teamHealth',           kind: 'thc0a10' }
    ];

    const METRIC_KEYS = METRIC_FIELDS.map(f => f.key);

    // Cabeçalho CSV canônico (ordem do design).
    const CSV_HEADER = ['mes', 'jornada', 'time', ...METRIC_KEYS];

    const CSV_SEPARATOR = ';';
    const BOM = '\uFEFF';

    // =========================================================================
    // Normalização de mês: aceita número 1..12 ou rótulo 'Jan'..'Dez'.
    // Retorna o número do mês (1..12) ou undefined se não reconhecido.
    // =========================================================================
    function normalizeMonth(month) {
        if (month === null || month === undefined || month === '') return undefined;
        if (typeof month === 'number') {
            return Number.isInteger(month) && month >= 1 && month <= 12 ? month : undefined;
        }
        const s = String(month).trim();
        if (s === '') return undefined;
        // Rótulo (case-insensitive).
        const idx = MONTH_LABELS.findIndex(l => l.toLowerCase() === s.toLowerCase());
        if (idx >= 0) return idx + 1;
        // Número textual.
        if (/^\d+$/.test(s)) {
            const n = parseInt(s, 10);
            if (n >= 1 && n <= 12) return n;
        }
        return undefined;
    }

    // =========================================================================
    // Normalização de valor numérico de métrica. Campos vazios → null (Sem
    // Dados). Decimais aceitos com vírgula OU ponto (normalizados para '.').
    // Retorna:
    //   null        → campo ausente/vazio (válido: Sem Dados)
    //   number      → valor numérico parseado
    //   NaN         → texto não numérico (inválido; o chamador detecta)
    // =========================================================================
    function parseNumeric(raw) {
        if (raw === null || raw === undefined) return null;
        if (typeof raw === 'number') return raw;
        const s = String(raw).trim();
        if (s === '') return null;
        // Decimais com vírgula → ponto. Removemos separador de milhar simples
        // apenas quando há vírgula decimal (ex.: "1.234,56" não é esperado aqui;
        // os dados usam escala 0..n, então tratamos vírgula como decimal).
        const normalized = s.replace(',', '.');
        const n = Number(normalized);
        return Number.isNaN(n) ? NaN : n;
    }

    // =========================================================================
    // parseCSV(text) → { records:[...], errors:[...] }
    // Separador ';', aceita BOM UTF-8, decimais com vírgula ou ponto.
    // Produz registros "crus" no formato interno:
    //   { month, monthLabel?, jornada, teamName, metrics:{...}, _line }
    // Erros estruturais (sem cabeçalho / cabeçalho divergente / nº de colunas)
    // são acumulados em `errors` com a linha de referência.
    // =========================================================================
    function parseCSV(text) {
        const errors = [];
        const records = [];

        if (typeof text !== 'string') {
            errors.push('CSV inválido: conteúdo não textual.');
            return { records, errors };
        }

        // Remove BOM UTF-8 se presente.
        let content = text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;

        // Divide em linhas tolerando \r\n e \n; descarta linhas totalmente vazias.
        const lines = content.split(/\r\n|\n|\r/).filter(l => l.trim() !== '');
        if (lines.length === 0) {
            errors.push('CSV vazio: nenhuma linha encontrada.');
            return { records, errors };
        }

        // Cabeçalho.
        const header = lines[0].split(CSV_SEPARATOR).map(h => h.trim());
        const missing = CSV_HEADER.filter(h => !header.includes(h));
        if (missing.length > 0) {
            errors.push(
                `Cabeçalho CSV inválido: faltam as colunas [${missing.join(', ')}]. ` +
                `Esperado: ${CSV_HEADER.join(CSV_SEPARATOR)}`
            );
            return { records, errors };
        }

        // Mapa de índice por nome de coluna (tolera ordem/colunas extras).
        const colIndex = {};
        header.forEach((h, i) => { colIndex[h] = i; });

        for (let i = 1; i < lines.length; i++) {
            const lineNo = i + 1; // 1-based, considerando o cabeçalho na linha 1
            const cells = lines[i].split(CSV_SEPARATOR);

            const get = (name) => {
                const idx = colIndex[name];
                const cell = idx !== undefined ? cells[idx] : undefined;
                return cell === undefined ? '' : String(cell).trim();
            };

            const mesRaw = get('mes');
            const jornada = get('jornada');
            const teamName = get('time');

            const metrics = {};
            METRIC_FIELDS.forEach(({ key }) => {
                metrics[key] = parseNumeric(get(key));
            });

            const monthNum = normalizeMonth(mesRaw);
            records.push({
                month: monthNum,
                monthLabel: monthNum ? MONTH_LABELS[monthNum - 1] : undefined,
                mesRaw,
                jornada,
                teamName,
                metrics,
                _line: lineNo
            });
        }

        return { records, errors };
    }

    // =========================================================================
    // parseJSON(text) → { records:[...], errors:[...] }
    // Formato: { year, records:[{month, jornada, teamName, metrics:{...}}] }.
    // Normaliza cada registro para o formato interno comum ao CSV.
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
            errors.push('JSON inválido: raiz deve ser um objeto { year, records }.');
            return { records, errors };
        }

        const rawRecords = data.records;
        if (!Array.isArray(rawRecords)) {
            errors.push('JSON inválido: propriedade "records" ausente ou não é uma lista.');
            return { records, errors };
        }

        rawRecords.forEach((rec, i) => {
            const index = i + 1;
            const src = (rec && typeof rec === 'object') ? rec : {};
            const srcMetrics = (src.metrics && typeof src.metrics === 'object') ? src.metrics : {};

            const metrics = {};
            METRIC_FIELDS.forEach(({ key }) => {
                metrics[key] = parseNumeric(srcMetrics[key]);
            });

            const monthNum = normalizeMonth(src.month);
            records.push({
                month: monthNum,
                monthLabel: monthNum ? MONTH_LABELS[monthNum - 1] : undefined,
                mesRaw: src.month,
                jornada: typeof src.jornada === 'string' ? src.jornada.trim() : src.jornada,
                teamName: typeof src.teamName === 'string' ? src.teamName.trim() : src.teamName,
                metrics,
                _index: index
            });
        });

        return { records, errors };
    }

    // =========================================================================
    // Validação de domínio de um valor de métrica já normalizado.
    // `value` pode ser null (Sem Dados — válido), number ou NaN (inválido).
    // Retorna uma string de erro (campo + motivo) ou null se válido.
    // =========================================================================
    function validateMetricValue(field, value) {
        const { key, kind } = field;
        if (value === null) return null;          // Sem Dados é permitido
        if (typeof value !== 'number' || Number.isNaN(value)) {
            return `${key}: valor não numérico`;
        }
        if (!Number.isFinite(value)) {
            return `${key}: valor inválido (não finito)`;
        }
        switch (kind) {
            case 'ratio':
                if (value < 0 || value > 2) {
                    return `${key}: razão fora do domínio esperado (0 a 2; 1.0 = 100%)`;
                }
                return null;
            case 'days':
                if (value < 0) return `${key}: Cycle Time em dias não pode ser negativo`;
                return null;
            case 'category':
                if (value < 0 || !Number.isInteger(value)) {
                    return `${key}: Frequência de Deploy deve ser categoria inteira >= 0`;
                }
                return null;
            case 'thc0a10':
                if (value < 0 || value > 10) {
                    return `${key}: Team Health Check fora da escala 0 a 10`;
                }
                return null;
            default:
                return null;
        }
    }

    // =========================================================================
    // validateRecord(record) → { valid:boolean, errors:[string] }
    // Exige mês (1..12), jornada, time e valores de métrica; valida domínios.
    // Mensagens descritivas no formato "campo: motivo" (Requirement 8.4/8.5).
    //
    // Regra de "valores de métrica": o registro deve conter o conjunto das 12
    // chaves de métrica (presentes no objeto `metrics`), e cada valor presente
    // deve respeitar seu domínio. Valores vazios/null são aceitos como "Sem
    // Dados", mas exigimos ao menos UMA métrica com valor para considerar que o
    // registro "contém valores de métrica".
    // =========================================================================
    function validateRecord(record) {
        const errors = [];

        if (!record || typeof record !== 'object') {
            return { valid: false, errors: ['registro: estrutura inválida (esperado objeto)'] };
        }

        // Mês.
        const monthNum = record.month !== undefined && record.month !== null && record.month !== ''
            ? normalizeMonth(record.month)
            : undefined;
        if (monthNum === undefined) {
            const shown = record.mesRaw !== undefined ? record.mesRaw : record.month;
            errors.push(`mes: ausente ou inválido (use 1-12 ou Jan-Dez)${shown !== undefined && shown !== '' ? ` — recebido "${shown}"` : ''}`);
        }

        // Jornada.
        if (!record.jornada || String(record.jornada).trim() === '') {
            errors.push('jornada: campo obrigatório ausente');
        }

        // Time.
        if (!record.teamName || String(record.teamName).trim() === '') {
            errors.push('time: campo obrigatório ausente');
        }

        // Métricas: objeto presente?
        const metrics = record.metrics;
        if (!metrics || typeof metrics !== 'object') {
            errors.push('metrics: valores de métrica ausentes');
            return { valid: false, errors };
        }

        // Valida domínio de cada métrica presente e conta quantas têm valor.
        let withValue = 0;
        METRIC_FIELDS.forEach((field) => {
            const value = metrics[field.key];
            if (value !== null && value !== undefined) withValue++;
            const err = validateMetricValue(field, value);
            if (err) errors.push(err);
        });

        if (withValue === 0) {
            errors.push('metrics: registro não contém nenhum valor de métrica');
        }

        return { valid: errors.length === 0, errors };
    }

    // =========================================================================
    // Formata uma célula numérica para o CSV, garantindo round-trip com
    // parseNumeric. Regras:
    //   - null / undefined        → '' (célula vazia = Sem Dados)
    //   - NaN / não finito        → '' (não há representação válida; vira Sem Dados)
    //   - number                  → representação decimal com PONTO.
    //
    // Decisão de formato decimal (Requirement 8.7 — round-trip): usamos ponto
    // como separador decimal. `parseNumeric` faz `String(raw).replace(',', '.')`
    // e depois `Number(...)`; um valor já com ponto é parseado de volta sem
    // perda. Evitamos a vírgula porque, embora `parseNumeric` a aceite, o ponto
    // é o formato canônico interno e dispensa normalização na reimportação.
    //
    // Usamos a representação natural do número (String(n)); para inteiros isso
    // produz "3" e para decimais "0.875", ambos reversíveis por Number().
    // =========================================================================
    function formatMetricCell(value) {
        if (value === null || value === undefined) return '';
        if (typeof value !== 'number' || Number.isNaN(value) || !Number.isFinite(value)) {
            return '';
        }
        return String(value);
    }

    // =========================================================================
    // Resolve o número do mês (1..12) a partir de um registro de dataset.
    // Aceita `month` (número ou rótulo) ou `monthLabel`. Retorna o número ou
    // undefined se não reconhecido.
    // =========================================================================
    function resolveMonthNumber(record) {
        if (!record || typeof record !== 'object') return undefined;
        let n = normalizeMonth(record.month);
        if (n === undefined && record.monthLabel !== undefined) {
            n = normalizeMonth(record.monthLabel);
        }
        return n;
    }

    // =========================================================================
    // toCSV(records) → string
    // Exportação reversível do dataset mensal (Requirements 8.7, 9.5).
    //   - Prefixo BOM UTF-8, separador ';', cabeçalho canônico (CSV_HEADER).
    //   - Uma linha por registro { month|monthLabel, jornada, teamName,
    //     metrics:{...} }.
    //   - Campos null (Sem Dados) → célula vazia.
    //   - `mes` é emitido como número 1..12 (reconhecido por parseCSV via
    //     normalizeMonth), garantindo o round-trip exigido pelo Requirement 8.7.
    //   - Decimais com ponto (ver formatMetricCell) para reversibilidade.
    //
    // Aceita tanto um array de registros quanto um objeto Dataset ({ records }).
    // Linhas de quebra entre registros usam '\r\n' (CRLF) para compatibilidade
    // com planilhas; parseCSV tolera '\r\n', '\n' e '\r'.
    // =========================================================================
    function toCSV(records) {
        const list = Array.isArray(records)
            ? records
            : (records && Array.isArray(records.records) ? records.records : []);

        const lines = [CSV_HEADER.join(CSV_SEPARATOR)];

        list.forEach((rec) => {
            if (!rec || typeof rec !== 'object') return;

            const monthNum = resolveMonthNumber(rec);
            const mesCell = monthNum !== undefined ? String(monthNum) : '';
            const jornada = rec.jornada !== undefined && rec.jornada !== null
                ? String(rec.jornada) : '';
            const time = rec.teamName !== undefined && rec.teamName !== null
                ? String(rec.teamName) : '';

            const metrics = (rec.metrics && typeof rec.metrics === 'object') ? rec.metrics : {};
            const metricCells = METRIC_KEYS.map((key) => formatMetricCell(metrics[key]));

            const row = [mesCell, jornada, time, ...metricCells];
            lines.push(row.join(CSV_SEPARATOR));
        });

        return BOM + lines.join('\r\n');
    }

    // =========================================================================
    // importText(text, format) → { imported:number, rejected:[{record,errors}] }
    // Faz o parse conforme o formato ('csv' | 'json'), valida cada registro,
    // importa os válidos (normalizados) e acumula os inválidos em `rejected[]`
    // com mensagens descritivas (Requirement 8.5).
    //
    // Retorno:
    //   { imported, rejected:[{record,errors}], records:[...], parseErrors:[...] }
    //   - `records` contém os registros VÁLIDOS normalizados prontos para o
    //     dataset: { month, monthLabel, jornada, teamName, metrics }.
    //   - `parseErrors` contém erros estruturais (cabeçalho/JSON malformado);
    //     quando presentes, nada é importado.
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

        // Erro estrutural de parsing → não importa nada (dataset preservado).
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
                const monthNum = normalizeMonth(rec.month);
                validRecords.push({
                    month: monthNum,
                    monthLabel: MONTH_LABELS[monthNum - 1],
                    jornada: String(rec.jornada).trim(),
                    teamName: String(rec.teamName).trim(),
                    metrics: { ...rec.metrics }
                });
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
        // Constantes úteis (reaproveitadas por toCSV na Task 6.2).
        MONTH_LABELS,
        METRIC_KEYS,
        METRIC_FIELDS,
        CSV_HEADER,
        CSV_SEPARATOR,
        BOM,

        // Helpers de normalização (expostos para testes/consumo).
        normalizeMonth,
        parseNumeric,

        // Parsers e validação.
        parseCSV,
        parseJSON,
        validateRecord,
        importText,

        // Exportação reversível (Task 6.2 — Requirements 8.7, 9.5).
        toCSV
    };
})();

// Environment guard: expõe o módulo IIFE para testes em Node (Vitest) sem
// afetar o carregamento por <script> no navegador.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DataImport;
}

if (typeof globalThis !== 'undefined') {
    globalThis.DataImport = DataImport;
}
