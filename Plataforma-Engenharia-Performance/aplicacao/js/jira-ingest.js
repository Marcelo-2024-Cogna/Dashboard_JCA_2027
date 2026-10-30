/**
 * PEP - Plataforma de Engenharia de Performance
 * Ingestão de Extração do Jira e geração por Sprint (`jira-ingest.js`)
 *
 * Task 23 (spec score-performance-executivo). Requirements 21.1–21.7.
 *
 * Opção B (app estático, sem backend): a "coleta do Jira" é a importação de uma
 * EXTRAÇÃO exportada do Jira (CSV/JSON), processada no navegador. A coleta
 * automática direta do Jira é evolução futura (depende de backend).
 *
 * Pipeline determinístico e testável:
 *   parseJira(text, format) -> { items, errors }        (21.1, 21.2, 21.4)
 *   buildRetroFromJira(items, mapping) -> RetroDataset  (21.3 — geração/Sprint)
 *   toCSV(items) -> string                              (21.6 — round-trip)
 *
 * `JiraMapping` (opcional) resolve projeto/campos do Jira → Jornada/Squad
 * canônicos (21.7), reutilizando PEPIdentity quando disponível.
 *
 * IIFE com guarda de ambiente para teste em Node.
 */

const JiraIngest = (() => {
    'use strict';

    // Cabeçalho canônico da extração (ordem usada no toCSV / round-trip).
    const FIELDS = [
        'chave', 'tipoItem', 'status', 'jornada', 'squad', 'sprint', 'pi',
        'storyPoints', 'criadoEm', 'inicioDev', 'fimDev', 'homologadoEm',
        'implantadoEm', 'resolvidoEm'
    ];

    // Campos mínimos obrigatórios (21.4).
    const REQUIRED = ['chave', 'tipoItem', 'sprint', 'pi'];

    function dep(name, relPath) {
        if (typeof window !== 'undefined' && window[name]) return window[name];
        if (typeof globalThis !== 'undefined' && globalThis[name]) return globalThis[name];
        if (relPath && typeof require !== 'undefined') {
            try { return require(relPath); } catch (_) { /* ignore */ }
        }
        return undefined;
    }

    // Normaliza um número que pode vir com vírgula decimal ou vazio.
    function toNum(v) {
        if (v == null || v === '') return null;
        const n = Number(String(v).replace(',', '.'));
        return Number.isFinite(n) ? n : null;
    }

    const strip = (s) => (s == null ? '' : String(s).trim());

    // Converte uma data (ISO ou dd/mm/aaaa) para timestamp ms, ou null.
    function toTime(v) {
        if (v == null || v === '') return null;
        const s = String(v).trim();
        // dd/mm/aaaa (com hora opcional) → reordena para aaaa-mm-dd.
        const m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})(.*)$/);
        const iso = m ? `${m[3]}-${m[2]}-${m[1]}${m[4] || ''}` : s;
        const t = Date.parse(iso);
        return Number.isFinite(t) ? t : null;
    }

    // Diferença em dias (>= 0) entre dois instantes; null se qualquer um faltar
    // ou se a ordem for inválida (fim antes do início).
    function diffDays(fromV, toV) {
        const a = toTime(fromV);
        const b = toTime(toV);
        if (a == null || b == null) return null;
        const dias = (b - a) / 86400000;
        return dias >= 0 ? dias : null;
    }

    // Média aritmética dos valores presentes (ignora null/NaN); null se vazio.
    function mean(vals) {
        const v = (vals || []).filter(x => x != null && !Number.isNaN(Number(x))).map(Number);
        if (v.length === 0) return null;
        return v.reduce((a, b) => a + b, 0) / v.length;
    }

    // Remove acentos e caixa para comparar rótulos de tipo/status.
    const canon = (s) => String(s == null ? '' : s)
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    // =========================================================================
    // PARSE (CSV com ';' ou ',' / JSON) — Requirements 21.1, 21.2, 21.4
    // =========================================================================
    function parseJira(text, format) {
        const fmt = (format || detectFormat(text)).toLowerCase();
        if (fmt === 'json') return parseJSON(text);
        return parseCSV(text);
    }

    function detectFormat(text) {
        const t = String(text || '').trim();
        return (t.startsWith('{') || t.startsWith('[')) ? 'json' : 'csv';
    }

    function parseJSON(text) {
        const items = [];
        const errors = [];
        let arr;
        try {
            const parsed = JSON.parse(text);
            arr = Array.isArray(parsed) ? parsed : (parsed && Array.isArray(parsed.items) ? parsed.items : null);
        } catch (e) {
            return { items, errors: [{ linha: 0, motivo: 'JSON inválido: ' + e.message }] };
        }
        if (!arr) return { items, errors: [{ linha: 0, motivo: 'JSON sem array de itens' }] };
        arr.forEach((raw, i) => classify(normalizeRaw(raw), i + 1, items, errors));
        return { items, errors };
    }

    function parseCSV(text) {
        const items = [];
        const errors = [];
        const linhas = String(text || '').replace(/^\uFEFF/, '').split(/\r?\n/).filter(l => l.trim() !== '');
        if (linhas.length === 0) return { items, errors: [{ linha: 0, motivo: 'Arquivo vazio' }] };

        const sep = linhas[0].includes(';') ? ';' : ',';
        const header = linhas[0].split(sep).map(h => strip(h));
        const idx = mapHeader(header);

        for (let i = 1; i < linhas.length; i++) {
            const cols = linhas[i].split(sep);
            const raw = {};
            FIELDS.forEach(f => {
                const j = idx[f];
                raw[f] = (j != null && j < cols.length) ? strip(cols[j]) : '';
            });
            classify(normalizeRaw(raw), i + 1, items, errors);
        }
        return { items, errors };
    }

    // Mapeia o cabeçalho da extração (tolerante a nomes comuns do Jira).
    function mapHeader(header) {
        const alias = {
            chave: ['chave', 'key', 'issue key', 'issuekey'],
            tipoItem: ['tipoitem', 'tipo de item', 'tipo', 'issue type', 'issuetype', 'type'],
            status: ['status'],
            jornada: ['jornada'],
            squad: ['squad', 'time', 'team'],
            sprint: ['sprint'],
            pi: ['pi', 'program increment'],
            storyPoints: ['storypoints', 'story points', 'pontos', 'sp'],
            criadoEm: ['criadoem', 'criado', 'created'],
            inicioDev: ['iniciodev', 'inicio dev', 'in progress', 'início dev'],
            fimDev: ['fimdev', 'fim dev', 'dev done'],
            homologadoEm: ['homologadoem', 'homologado', 'homologação'],
            implantadoEm: ['implantadoem', 'implantado', 'implantação', 'deploy'],
            resolvidoEm: ['resolvidoem', 'resolvido', 'resolved', 'resolution date']
        };
        const norm = (s) => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        const idx = {};
        const hNorm = header.map(norm);
        FIELDS.forEach(f => {
            const cands = alias[f] || [f];
            idx[f] = hNorm.findIndex(h => cands.map(norm).includes(h));
            if (idx[f] < 0) idx[f] = null;
        });
        return idx;
    }

    function normalizeRaw(raw) {
        return {
            chave: strip(raw.chave),
            tipoItem: strip(raw.tipoItem),
            status: strip(raw.status),
            jornada: strip(raw.jornada),
            squad: strip(raw.squad),
            sprint: strip(raw.sprint),
            pi: strip(raw.pi),
            storyPoints: toNum(raw.storyPoints),
            criadoEm: strip(raw.criadoEm) || null,
            inicioDev: strip(raw.inicioDev) || null,
            fimDev: strip(raw.fimDev) || null,
            homologadoEm: strip(raw.homologadoEm) || null,
            implantadoEm: strip(raw.implantadoEm) || null,
            resolvidoEm: strip(raw.resolvidoEm) || null
        };
    }

    // Rótulos amigáveis dos campos para mensagens descritivas (21.4/21.5).
    const FIELD_LABELS = {
        chave: 'chave do item',
        tipoItem: 'tipo de item',
        sprint: 'Sprint',
        pi: 'PI'
    };

    // =========================================================================
    // VALIDAÇÃO (21.4, 21.5) — validateItem
    //
    // Valida um item já normalizado (shape JiraItem), retornando
    // { valid:boolean, errors:[string] } com mensagens descritivas por campo.
    // Regras:
    //   - Campos mínimos obrigatórios (REQUIRED): chave, tipoItem, sprint, pi.
    //   - storyPoints, quando presente, deve ser número não-negativo.
    // =========================================================================
    function validateItem(item) {
        const errors = [];
        const it = item || {};

        REQUIRED.forEach(f => {
            const v = it[f];
            if (v == null || String(v).trim() === '') {
                errors.push('Campo obrigatório ausente: ' + (FIELD_LABELS[f] || f));
            }
        });

        // storyPoints é opcional, mas se informado deve ser número >= 0.
        if (it.storyPoints != null) {
            const n = Number(it.storyPoints);
            if (!Number.isFinite(n)) {
                errors.push('Story Points inválido: não é um número');
            } else if (n < 0) {
                errors.push('Story Points inválido: não pode ser negativo');
            }
        }

        return { valid: errors.length === 0, errors };
    }

    // Valida os campos mínimos e separa válidos de inválidos (21.4).
    function classify(item, linha, items, errors) {
        const { valid, errors: itemErrors } = validateItem(item);
        if (!valid) {
            errors.push({
                linha,
                chave: item.chave || '(sem chave)',
                motivo: itemErrors.join('; '),
                erros: itemErrors
            });
            return;
        }
        items.push(item);
    }

    // =========================================================================
    // GERAÇÃO POR SPRINT (21.3, 21.4) — buildRetroFromJira
    //
    // Agrega os itens por (pi, jornada, squad, sprint) produzindo os blocos do
    // RetroDataset derivados de itens:
    //   - pontosTrabalhados (por pi/jornada/squad/sprint; sustentação vs valor)
    //   - cargaTrabalho (por pi/tipoItem; pontos/qtde/percentuais/média)
    //   - cycleTime (por pi/squad/jornada; dias médios por fase do fluxo)
    //   - leadTime (por pi/squad/jornada/tipoItem; dias médios criado→resolvido)
    //   - epicos (itens Épico/Epic → situação derivada do status)
    //   - debito (Débito Técnico → planejado/real/entregue/pendente/cancelado)
    //   - sustentacao (Incidente/Solicitação → realizado por squad)
    //   - impedimentos (vazio: campos não presentes na extração padrão)
    // Mantém `items` crus para derivações adicionais. A soma de Story Points e a
    // contagem por recorte (pi/jornada/squad/sprint) são preservadas (Property 27).
    // =========================================================================
    function buildRetroFromJira(items, mapping) {
        const id = dep('PEPIdentity', './identity.js');
        const map = mapping || dep('JiraMapping', './jira-mapping.js');

        // Resolve jornada/squad canônicos quando houver mapeamento/identidade.
        const resolved = (items || []).map(it => {
            let jornada = it.jornada;
            let squad = it.squad;
            if (map && typeof map.resolveSquad === 'function') {
                squad = map.resolveSquad(it) || squad;
            }
            if (map && typeof map.resolveJornada === 'function') {
                jornada = map.resolveJornada(it) || jornada;
            }
            if (id) {
                jornada = (id.canonicalJornada && id.canonicalJornada(jornada)) || jornada;
                squad = (id.canonicalSquad && id.canonicalSquad(squad)) || squad;
                if ((!jornada || jornada === it.jornada) && id.jornadaOf) {
                    jornada = id.jornadaOf(squad) || jornada;
                }
            }
            return { ...it, jornada, squad };
        });

        // Classifica tipo de item em categoria de pontos: Sustentação
        // (Incidente/Solicitação/Suporte) vs Entrega de Valor (demais).
        const SUSTENTACAO = new Set(['incidente', 'solicitacao de servico', 'suporte']);
        const isSustentacao = (tipo) => SUSTENTACAO.has(canon(tipo));
        const isEpico = (tipo) => { const c = canon(tipo); return c === 'epico' || c === 'epic'; };
        const isDebito = (tipo) => canon(tipo) === 'debito tecnico';

        // pontosTrabalhados por (pi, jornada, squad, sprint).
        const ptMap = new Map();
        // cargaTrabalho por (pi, tipoItem).
        const cargaMap = new Map();

        resolved.forEach(it => {
            const sp = Number(it.storyPoints) || 0;

            const ptKey = [it.pi, it.jornada, it.squad, it.sprint].join('||');
            if (!ptMap.has(ptKey)) {
                ptMap.set(ptKey, { pi: it.pi, jornada: it.jornada, squad: it.squad, sprint: it.sprint, sustentacao: 0, entregaValor: 0, total: 0 });
            }
            const pt = ptMap.get(ptKey);
            if (isSustentacao(it.tipoItem)) pt.sustentacao += sp; else pt.entregaValor += sp;
            pt.total += sp;

            const cKey = [it.pi, it.tipoItem].join('||');
            if (!cargaMap.has(cKey)) {
                cargaMap.set(cKey, { pi: it.pi, tipoItem: it.tipoItem, pontos: 0, qtdeItens: 0 });
            }
            const c = cargaMap.get(cKey);
            c.pontos += sp;
            c.qtdeItens += 1;
        });

        const pontosTrabalhados = [...ptMap.values()];

        // Percentuais da carga por PI.
        const cargaTrabalho = [...cargaMap.values()];
        const totalPontosPorPI = {};
        const totalItensPorPI = {};
        cargaTrabalho.forEach(c => {
            totalPontosPorPI[c.pi] = (totalPontosPorPI[c.pi] || 0) + c.pontos;
            totalItensPorPI[c.pi] = (totalItensPorPI[c.pi] || 0) + c.qtdeItens;
        });
        cargaTrabalho.forEach(c => {
            c.pontosPct = totalPontosPorPI[c.pi] > 0 ? c.pontos / totalPontosPorPI[c.pi] : 0;
            c.itensPct = totalItensPorPI[c.pi] > 0 ? c.qtdeItens / totalItensPorPI[c.pi] : 0;
            c.pontuacaoMedia = c.qtdeItens > 0 ? c.pontos / c.qtdeItens : 0;
        });

        // =====================================================================
        // CYCLE TIME por fase (21.4) — média de dias por (pi, squad, jornada).
        // Fases: Ideação (criadoEm→inicioDev), Desenvolvimento (inicioDev→fimDev),
        // Homologação (fimDev→homologadoEm), Implantação (homologadoEm→implantadoEm).
        // Acumula amostras por fase, ignorando itens sem as datas da fase.
        // =====================================================================
        const cycleMap = new Map();
        resolved.forEach(it => {
            if (isSustentacao(it.tipoItem) || isEpico(it.tipoItem)) return; // fluxo de entrega de valor
            const key = [it.pi, it.squad, it.jornada].join('||');
            if (!cycleMap.has(key)) {
                cycleMap.set(key, {
                    pi: it.pi, squad: it.squad, time: it.squad, jornada: it.jornada,
                    _ideacao: [], _desenvolvimento: [], _homologacao: [], _implantacao: []
                });
            }
            const c = cycleMap.get(key);
            const d1 = diffDays(it.criadoEm, it.inicioDev);
            const d2 = diffDays(it.inicioDev, it.fimDev);
            const d3 = diffDays(it.fimDev, it.homologadoEm);
            const d4 = diffDays(it.homologadoEm, it.implantadoEm);
            if (d1 != null) c._ideacao.push(d1);
            if (d2 != null) c._desenvolvimento.push(d2);
            if (d3 != null) c._homologacao.push(d3);
            if (d4 != null) c._implantacao.push(d4);
        });
        const cycleTime = [...cycleMap.values()].map(c => ({
            pi: c.pi, squad: c.squad, time: c.time, jornada: c.jornada,
            ideacao: mean(c._ideacao),
            desenvolvimento: mean(c._desenvolvimento),
            homologacao: mean(c._homologacao),
            implantacao: mean(c._implantacao)
        }));

        // =====================================================================
        // LEAD TIME por tipo de item (21.4) — média de dias (criadoEm→resolvidoEm)
        // por (pi, squad, jornada, tipoItem). Ignora itens sem as duas datas.
        // =====================================================================
        const leadMap = new Map();
        resolved.forEach(it => {
            const dias = diffDays(it.criadoEm, it.resolvidoEm);
            if (dias == null) return;
            const key = [it.pi, it.squad, it.jornada, it.tipoItem].join('||');
            if (!leadMap.has(key)) {
                leadMap.set(key, { pi: it.pi, squad: it.squad, jornada: it.jornada, tipoItem: it.tipoItem, _dias: [] });
            }
            leadMap.get(key)._dias.push(dias);
        });
        const leadTime = [...leadMap.values()].map(l => ({
            pi: l.pi, squad: l.squad, jornada: l.jornada, tipoItem: l.tipoItem, dias: mean(l._dias)
        }));

        // =====================================================================
        // SITUAÇÃO DOS ÉPICOS (21.4) — itens do tipo Épico/Epic.
        // Deriva a situação a partir do status, mapeando para o vocabulário
        // canônico das 6 situações. Status desconhecido → 'em-dia' (neutro).
        // =====================================================================
        function situacaoDeStatus(status) {
            const s = canon(status);
            if (/finaliz|conclu|done|encerr|resolv/.test(s)) return 'finalizado';
            if (/atras|late|overdue/.test(s)) return 'atrasado';
            if (/replanej/.test(s)) return 'replanejado';
            if (/risco|risk|bloque|impedid/.test(s)) return 'em-risco';
            if (/desprioriz|cancel/.test(s)) return 'despriorizado';
            return 'em-dia';
        }
        const epicos = resolved
            .filter(it => isEpico(it.tipoItem))
            .map(it => ({
                pi: it.pi, jornada: it.jornada, squad: it.squad, chave: it.chave,
                situacao: situacaoDeStatus(it.status), origem: 'baseline'
            }));

        // =====================================================================
        // DÉBITO TÉCNICO (21.4) — itens do tipo "Débito Técnico" consolidados por
        // (pi, jornada). Classifica cada item por status: entregue (concluído),
        // cancelado, real (em andamento/aberto). Planejado = todos; Pendente =
        // Planejado − Entregue − Cancelado. Totais em SP e em quantidade.
        // =====================================================================
        const debitoMap = new Map();
        resolved.forEach(it => {
            if (!isDebito(it.tipoItem)) return;
            const key = [it.pi, it.jornada].join('||');
            if (!debitoMap.has(key)) {
                debitoMap.set(key, {
                    pi: it.pi, time: it.jornada, jornada: it.jornada, backlogQtde: 0,
                    planejadoSP: 0, realSP: 0, entregueSP: 0, pendenteSP: 0, canceladoSP: 0,
                    planejadoQtd: 0, realQtd: 0, entregueQtd: 0, pendenteQtd: 0, canceladoQtd: 0
                });
            }
            const d = debitoMap.get(key);
            const sp = Number(it.storyPoints) || 0;
            const s = canon(it.status);
            d.planejadoSP += sp; d.planejadoQtd += 1;
            if (/finaliz|conclu|done|encerr|resolv/.test(s)) { d.entregueSP += sp; d.entregueQtd += 1; }
            else if (/cancel|desprioriz/.test(s)) { d.canceladoSP += sp; d.canceladoQtd += 1; }
            else { d.realSP += sp; d.realQtd += 1; }
        });
        const debito = [...debitoMap.values()].map(d => ({
            ...d,
            pendenteSP: d.planejadoSP - d.entregueSP - d.canceladoSP,
            pendenteQtd: d.planejadoQtd - d.entregueQtd - d.canceladoQtd
        }));

        // =====================================================================
        // SUSTENTAÇÃO (21.4) — Incidente/Solicitação de Serviço: conta os itens
        // "Realizado" (concluídos) por (pi, squad, jornada). Planejado não é
        // derivável de itens individuais → 0 (preenchido por outra fonte).
        // =====================================================================
        const sustMap = new Map();
        resolved.forEach(it => {
            const c = canon(it.tipoItem);
            if (c !== 'incidente' && c !== 'solicitacao de servico') return;
            const key = [it.pi, it.squad, it.jornada].join('||');
            if (!sustMap.has(key)) {
                sustMap.set(key, { pi: it.pi, time: it.squad, squad: it.squad, jornada: it.jornada, realizado: 0, planejado: 0, total: 0 });
            }
            const r = sustMap.get(key);
            r.total += 1;
            if (/finaliz|conclu|done|encerr|resolv/.test(canon(it.status))) r.realizado += 1;
        });
        const sustentacao = [...sustMap.values()];

        // =====================================================================
        // IMPEDIMENTOS (21.4) — a extração padrão de itens não possui campos de
        // impedimento (quantidade/localização/tempo de remoção). Mantemos o
        // bloco vazio em vez de fabricar dados (coerente com 21.4).
        // =====================================================================
        const impedimentos = [];

        const pis = [];
        resolved.forEach(it => { if (it.pi && !pis.includes(it.pi)) pis.push(it.pi); });
        const jornadas = [];
        resolved.forEach(it => { if (it.jornada && !jornadas.includes(it.jornada)) jornadas.push(it.jornada); });

        return {
            pis,
            jornadas,
            items: resolved,
            pontosTrabalhados,
            cargaTrabalho,
            cycleTime,
            leadTime,
            epicos,
            debito,
            sustentacao,
            impedimentos,
            source: sourceInfo()
        };
    }

    // =========================================================================
    // EXPORT reversível (21.6/21.7) — toCSV / round-trip (Property 28)
    //
    // Os `items` normalizados SÃO a representação reversível dos dados derivados:
    // regeneram o RetroDataset de forma determinística via buildRetroFromJira
    // (os blocos derivados — pontosTrabalhados, cycle/lead time, épicos, débito,
    // sustentação, carga — são funções puras dos itens). Portanto exportar os
    // itens em CSV e reimportá-los (parseCSV) é a ida-e-volta exigida: um
    // round-trip equivalente dos itens garante a equivalência das derivações.
    //
    // Convenção do projeto (igual a data-import.js / retro-import.js): BOM UTF-8,
    // separador ';', cabeçalho canônico (FIELDS) em ordem fixa, sem escape de
    // célula. Os campos de uma extração do Jira (chave, tipo, status, rótulos de
    // Sprint/PI, Jornada/Squad, datas e Story Points) não contêm ';' nem quebras
    // de linha; valores com o separador estão fora do escopo desta extração.
    // Como salvaguarda mínima e consistente, sanitizamos ';' / CR / LF em
    // ' ' para preservar o alinhamento de colunas no round-trip, sem introduzir
    // uma gramática de aspas divergente das demais exportações do projeto.
    // =========================================================================
    function csvCell(v) {
        if (v == null) return '';
        return String(v).replace(/[;\r\n]+/g, ' ');
    }

    function toCSV(items) {
        const linhas = [FIELDS.join(';')];
        (items || []).forEach(it => {
            linhas.push(FIELDS.map(f => csvCell(it[f])).join(';'));
        });
        return '\uFEFF' + linhas.join('\n');
    }

    // Nota de origem dos dados (21.5): a fonte atual é a extração manual do
    // Jira; a coleta automática é evolução futura (depende de backend).
    function sourceInfo() {
        return {
            current: 'extracao-jira-manual',
            currentLabel: 'Extração manual do Jira (CSV/JSON)',
            futurePlanned: ['Jira (coleta automática via backend)']
        };
    }

    return {
        FIELDS,
        REQUIRED,
        parseJira,
        parseCSV,
        parseJSON,
        validateItem,
        buildRetroFromJira,
        toCSV,
        sourceInfo
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = JiraIngest;
}
if (typeof globalThis !== 'undefined') {
    globalThis.JiraIngest = JiraIngest;
}
