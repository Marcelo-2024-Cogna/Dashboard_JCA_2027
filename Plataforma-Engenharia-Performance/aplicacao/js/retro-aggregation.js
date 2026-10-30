/**
 * PEP - Plataforma de Engenharia de Performance
 * Agregação de Retrospectiva de PI (`retro-aggregation.js`)
 *
 * Módulo de funções PURAS que agregam os dados brutos de `retro-data.js`
 * (PEPRetroData) para alimentar os painéis de Retrospectiva de PI. Descrito no
 * design em "Módulo de Métricas de Retrospectiva de PI" → "Componentes e
 * interfaces (Retro PI)" (objeto `RetroAggregation`).
 *
 * Este módulo é INDEPENDENTE do Score de Performance (não toca `scoring-engine.js`
 * nem `data.js`/`aggregation.js`). Segue o mesmo padrão dos demais módulos: IIFE
 * carregado por <script> no browser, com guarda de ambiente
 * (`module.exports`/`globalThis`) para teste em Node (Vitest).
 *
 * Task 13.1 (ESTE arquivo) implementa:
 *   - `pontosPorSprint(pi, time)`  → Req 10.1, 10.2 (Property 18)
 *   - `produtividadePorPI(filtro)` → Req 11.1, 11.2 (Property 19)
 *   - `situacaoEpicos(pi)`         → Req 12.1, 12.4 (Property 20)
 *
 * Task 13.2 ADICIONA ao MESMO objeto `RetroAggregation`:
 *   - `cycleTimePorFase`, `leadTimePorTipo`, `debitoTecnico`, `sustentacao`.
 *   (Ver bloco "EXTENSÕES (Task 13.2)" ao final deste arquivo.)
 *
 * Dependência (carregada por <script> no browser como global, ou via require()
 * em Node/Vitest sob guarda de ambiente):
 *   - PEPRetroData (retro-data.js): query(), getProdutividade(),
 *     getEpicos(), getEpicosContagem(), produtividadeMediaJornada, DATASET.
 */

const RetroAggregation = (() => {

    // Resolve o módulo de dados tanto no browser (global) quanto em Node (require).
    function getData() {
        if (typeof PEPRetroData !== 'undefined' && PEPRetroData) return PEPRetroData;
        if (typeof globalThis !== 'undefined' && globalThis.PEPRetroData) return globalThis.PEPRetroData;
        if (typeof require !== 'undefined') {
            try { return require('./retro-data.js'); } catch (_) { /* ignore */ }
        }
        throw new Error('RetroAggregation: PEPRetroData (retro-data.js) não disponível.');
    }

    // As seis situações de épico (ordem canônica do Requirement 12.1).
    const SITUACOES = ['atrasado', 'replanejado', 'em-risco', 'em-dia', 'finalizado', 'despriorizado'];

    // As duas origens de contagem de épicos (Requirement 12.2).
    const ORIGENS = ['baseline', 'mudanca-escopo'];

    // Divisão segura: retorna 0 quando o denominador é 0 (evita NaN/Infinity).
    function safeRatio(num, den) {
        return den > 0 ? num / den : 0;
    }

    // Normaliza o 2º argumento das agregações: aceita o formato legado
    // (`time` string) OU um objeto de filtro `{ time, squad, jornada, sprint }`.
    // Retorna um objeto de consulta pronto para data.query (sem `pi`, que é
    // passado separadamente). Mantém retrocompatibilidade total.
    // Sentinela "Todos" dos filtros (RetroFilters.ALL). Valores iguais a este
    // significam "sem recorte" e NÃO devem virar critério de filtro na query
    // (senão filtrariam por um squad/jornada literalmente chamado "all",
    // retornando vazio — era o bug dos filtros de Retro).
    const FILTER_ALL = 'all';
    const isAll = (v) => (v == null || v === FILTER_ALL);

    function normFilter(arg) {
        if (arg == null) return {};
        if (typeof arg === 'string') return isAll(arg) ? {} : { time: arg };
        const f = {};
        if (!isAll(arg.time)) f.time = arg.time;
        if (!isAll(arg.squad)) f.squad = arg.squad;
        if (!isAll(arg.jornada)) f.jornada = arg.jornada;
        if (!isAll(arg.sprint)) f.sprint = arg.sprint;
        return f;
    }

    // =========================================================================
    // PONTOS TRABALHADOS POR SPRINT (Req 10.1, 10.2) — Property 18
    //
    // Para o PI (e opcionalmente o Time) informados, retorna uma linha por
    // Sprint com Sustentação, Entrega de Valor, total e os percentuais de cada
    // categoria sobre o total. Por construção `entregaValor + sustentacao` é
    // igual a `total`, e `pct.sustentacao + pct.entregaValor` soma 1 (quando o
    // total > 0; se o total for 0, ambos os percentuais são 0).
    // =========================================================================
    function pontosPorSprint(pi, filtro) {
        const data = getData();
        const registros = data.query('pontosTrabalhados', { pi, ...normFilter(filtro) });
        // Preserva o indicador numérico fornecido pela fonte (ou null quando
        // ausente), sem transformar null→0 — o painel distingue "sem dado" (—)
        // de zero real nos KPIs Planejado/Real/PI Planning/Velocidade.
        const indicador = (v) => (v != null && !Number.isNaN(Number(v))) ? Number(v) : null;
        return registros.map(r => {
            const sustentacao = Number(r.sustentacao) || 0;
            const entregaValor = Number(r.entregaValor) || 0;
            const total = sustentacao + entregaValor;
            return {
                pi: r.pi,
                sprint: r.sprint,
                time: r.time,
                jornada: r.jornada,
                sustentacao,
                entregaValor,
                total,
                // Indicadores de topo (Req 10.3). Propagados da fonte para que o
                // painel exiba Planejado/Real/PI Planning/Velocidade.
                planejado: indicador(r.planejado),
                real: indicador(r.real),
                piPlanning: indicador(r.piPlanning),
                velocidade: indicador(r.velocidade),
                pct: {
                    sustentacao: safeRatio(sustentacao, total),
                    entregaValor: safeRatio(entregaValor, total)
                }
            };
        });
    }

    // =========================================================================
    // PRODUTIVIDADE POR PI (Req 11.1, 11.2) — Property 19
    //
    // Retorna a produtividade de cada Time por PI (pontos por integrante) e a
    // média da Jornada por PI. A média é a média aritmética das produtividades
    // dos Times COM DADO naquele PI, ignorando Times sem dado (valor
    // null/undefined/NaN ou PI ausente no registro do time).
    //
    // `filtro` (opcional): { jornada } restringe os Times considerados.
    // =========================================================================
    function produtividadePorPI(filtro = {}) {
        const data = getData();
        const { jornada } = filtro || {};
        let times = data.getProdutividade();
        if (jornada != null) {
            times = times.filter(t => t.jornada === jornada);
        }

        // Conjunto de todos os PIs presentes nos registros de produtividade.
        const pisSet = new Set();
        times.forEach(t => {
            Object.keys(t.porPI || {}).forEach(p => pisSet.add(p));
        });
        const pis = [...pisSet];

        const porTime = times.map(t => ({
            time: t.time,
            jornada: t.jornada,
            porPI: { ...(t.porPI || {}) }
        }));

        // Média da Jornada por PI, ignorando Times sem dado.
        const mediaJornadaPorPI = {};
        pis.forEach(p => {
            const valores = porTime
                .map(t => t.porPI[p])
                .filter(v => v != null && !Number.isNaN(Number(v)))
                .map(Number);
            mediaJornadaPorPI[p] = valores.length > 0
                ? valores.reduce((a, b) => a + b, 0) / valores.length
                : null;
        });

        return { porTime, mediaJornadaPorPI };
    }

    // =========================================================================
    // SITUAÇÃO DOS ÉPICOS (Req 12.1, 12.4) — Property 20
    //
    // Para o PI informado, retorna as contagens das seis situações por origem
    // (Baseline e Mudança de Escopo) e um utilitário para listar os épicos de
    // uma situação/origem. As contagens particionam o conjunto de épicos: cada
    // épico é contado em EXATAMENTE uma situação; situações desconhecidas são
    // ignoradas (não contadas), preservando a partição sobre épicos válidos.
    //
    // A fonte das contagens é a tabela `epicosContagem` da planilha (fiel ao
    // oráculo). A listagem usa a coleção `epicos` (subconjunto detalhado por
    // chave), filtrada por situação e origem.
    // =========================================================================
    function situacaoEpicos(pi, filtro) {
        const data = getData();
        const _f = normFilter(filtro);
        const epicos = data.query('epicos', { pi, ..._f });

        // Conta as 6 situações por origem DIRETO da lista já filtrada (pi +
        // jornada + squad). Assim a contagem é sempre consistente com a lista
        // exibida — incluindo o filtro por Squad (antes a contagem só olhava a
        // Jornada e divergia da listagem). Default 0 para situações ausentes.
        function contarOrigem(origem) {
            const out = {};
            SITUACOES.forEach(s => { out[s] = 0; });
            epicos.forEach(e => {
                // Só conta épicos cuja origem é EXATAMENTE a pedida (origens
                // desconhecidas não entram em nenhuma célula — preserva a partição).
                if (e.origem !== origem) return;
                if (out[e.situacao] != null) out[e.situacao] += 1;
            });
            return out;
        }

        const baseline = contarOrigem('baseline');
        const mudancaEscopo = contarOrigem('mudanca-escopo');

        // Lista os épicos (chave + metadados) de uma situação/origem.
        function listaPorSituacao(situacao, origem) {
            return epicos.filter(e => {
                if (situacao != null && e.situacao !== situacao) return false;
                if (origem != null && e.origem !== origem) return false;
                return true;
            });
        }

        return { baseline, mudancaEscopo, listaPorSituacao };
    }

    // =========================================================================
    // EXTENSÕES (Task 13.2) — cycle/lead time, débito técnico e sustentação.
    //
    // Mesmo padrão da 13.1: funções PURAS que consomem `PEPRetroData`
    // (retro-data.js) via getData(), sem efeitos colaterais. Adicionadas ao
    // MESMO objeto `RetroAggregation` (ver PUBLIC API ao final).
    // =========================================================================

    // Tolerância para comparações de consistência numérica (Property 22).
    const TOLERANCIA = 1e-6;

    // Média aritmética dos valores presentes (ignora null/undefined/NaN).
    // Retorna null quando nenhum valor está disponível (Property 21).
    function mediaIgnorandoAusencias(valores) {
        const validos = (valores || [])
            .filter(v => v != null && !Number.isNaN(Number(v)))
            .map(Number);
        if (validos.length === 0) return null;
        return validos.reduce((a, b) => a + b, 0) / validos.length;
    }

    // =========================================================================
    // CYCLE TIME POR FASE (Req 13.2) — Property 21
    //
    // Para o PI informado, retorna o tempo médio de cada Time nas quatro fases
    // (Ideação, Desenvolvimento, Homologação, Implantação) e a média da Jornada
    // em cada fase. A média da Jornada é a média aritmética dos valores dos
    // Times COM DADO naquela fase, ignorando ausências (null/undefined/NaN).
    //
    // `filtro` (opcional): { time } restringe os Times considerados (Req 13.3 —
    // a infraestrutura de filtro por Tipo de Item/Status é dos painéis; aqui
    // mantemos o filtro por Time/PI suportado pela fonte de dados).
    // =========================================================================
    const CYCLE_FASES = ['ideacao', 'desenvolvimento', 'homologacao', 'implantacao'];

    function cycleTimePorFase(pi, filtro = {}) {
        const data = getData();
        const registros = data.query('cycleTime', { pi, ...normFilter(filtro) });

        const porTime = registros.map(r => {
            const linha = { time: r.time, jornada: r.jornada };
            CYCLE_FASES.forEach(fase => {
                const v = r[fase];
                linha[fase] = (v != null && !Number.isNaN(Number(v))) ? Number(v) : null;
            });
            return linha;
        });

        // Média da Jornada por fase, ignorando Times sem dado na fase.
        const mediaJornada = {};
        CYCLE_FASES.forEach(fase => {
            mediaJornada[fase] = mediaIgnorandoAusencias(porTime.map(l => l[fase]));
        });

        return { porTime, mediaJornada };
    }

    // =========================================================================
    // LEAD TIME POR TIPO DE ITEM (Req 14.2) — Property 21
    //
    // Para o PI informado, pivota os registros normalizados de Lead Time
    // (um por (squad, tipoItem, dias)) em uma linha por Squad com o Lead Time
    // de cada Tipo de Item, e calcula a média da Jornada por Tipo de Item como
    // a média aritmética dos Squads COM DADO naquele tipo, ignorando ausências.
    // =========================================================================
    function leadTimePorTipo(pi, filtro) {
        const data = getData();
        const registros = data.query('leadTime', { pi, ...normFilter(filtro) });

        // Descobre os tipos presentes (ordem de primeira aparição) e agrupa por squad.
        const tipos = [];
        const porSquadMap = new Map();
        registros.forEach(r => {
            if (!tipos.includes(r.tipoItem)) tipos.push(r.tipoItem);
            if (!porSquadMap.has(r.squad)) {
                porSquadMap.set(r.squad, { squad: r.squad, jornada: r.jornada, tipos: {} });
            }
            const dias = r.dias;
            porSquadMap.get(r.squad).tipos[r.tipoItem] =
                (dias != null && !Number.isNaN(Number(dias))) ? Number(dias) : null;
        });

        const porSquad = [...porSquadMap.values()];

        // Média da Jornada por Tipo de Item, ignorando Squads sem dado no tipo.
        const mediaJornadaPorTipo = {};
        tipos.forEach(tipo => {
            mediaJornadaPorTipo[tipo] = mediaIgnorandoAusencias(
                porSquad.map(s => (tipo in s.tipos ? s.tipos[tipo] : null))
            );
        });

        return { porSquad, mediaJornadaPorTipo };
    }

    // =========================================================================
    // DÉBITO TÉCNICO (Req 15.2, 15.3) — Property 22
    //
    // Para o PI informado, retorna o backlog por Time (coleção
    // `debitoBacklogPorTime`) e o total da Jornada de cada indicador em Story
    // Points e em quantidade (coleção `debito`, já consolidada por Jornada).
    //
    // O total da Jornada expõe `pendenteSP`/`pendenteQtd` como fornecidos pela
    // fonte, mais os valores DERIVADOS (`pendenteSPDerivado` = Planejado −
    // Entregue − Cancelado, idem para quantidade) usados para checar a
    // consistência da Property 22. `pendenteConsistente` indica se o Pendente
    // informado bate com o derivado dentro da tolerância.
    // =========================================================================
    function debitoTecnico(pi, filtro) {
        const data = getData();
        const _f = normFilter(filtro);

        // Backlog por Time (quantidade), fiel à aba — aplica filtro squad/jornada.
        const backlog = data.query('debitoBacklogPorTime', { pi, ..._f });
        const porTime = backlog.map(r => ({
            time: r.time,
            jornada: r.jornada,
            backlogQtde: (r.backlogQtde != null && !Number.isNaN(Number(r.backlogQtde)))
                ? Number(r.backlogQtde) : 0
        }));

        // Total consolidado da Jornada (SP + quantidade) — respeita o filtro
        // de jornada/squad para que o painel reflita o escopo selecionado
        // (sem filtro = soma de todas as jornadas).
        const totais = data.query('debito', { pi, ..._f });
        const num = (v) => (v != null && !Number.isNaN(Number(v))) ? Number(v) : 0;

        const planejadoSP = totais.reduce((a, r) => a + num(r.planejadoSP), 0);
        const realSP      = totais.reduce((a, r) => a + num(r.realSP), 0);
        const entregueSP  = totais.reduce((a, r) => a + num(r.entregueSP), 0);
        const pendenteSP  = totais.reduce((a, r) => a + num(r.pendenteSP), 0);
        const canceladoSP = totais.reduce((a, r) => a + num(r.canceladoSP), 0);

        const planejadoQtd = totais.reduce((a, r) => a + num(r.planejadoQtd), 0);
        const realQtd      = totais.reduce((a, r) => a + num(r.realQtd), 0);
        const entregueQtd  = totais.reduce((a, r) => a + num(r.entregueQtd), 0);
        const pendenteQtd  = totais.reduce((a, r) => a + num(r.pendenteQtd), 0);
        const canceladoQtd = totais.reduce((a, r) => a + num(r.canceladoQtd), 0);

        const backlogQtdeTotal = porTime.reduce((a, r) => a + r.backlogQtde, 0);

        // Pendente derivado = Planejado − Entregue − Cancelado (Req 15.2).
        const pendenteSPDerivado  = planejadoSP - entregueSP - canceladoSP;
        const pendenteQtdDerivado = planejadoQtd - entregueQtd - canceladoQtd;
        const pendenteConsistente =
            Math.abs(pendenteSP - pendenteSPDerivado) <= TOLERANCIA &&
            Math.abs(pendenteQtd - pendenteQtdDerivado) <= TOLERANCIA;

        const totalJornada = {
            backlogQtde: backlogQtdeTotal,
            planejadoSP, realSP, entregueSP, pendenteSP, canceladoSP,
            planejadoQtd, realQtd, entregueQtd, pendenteQtd, canceladoQtd,
            pendenteSPDerivado, pendenteQtdDerivado,
            pendenteConsistente
        };

        return { porTime, totalJornada };
    }

    // =========================================================================
    // CARGA TOTAL DE TRABALHO (Req 17.1)
    //
    // Para o PI informado, agrega a carga por Tipo de Item somando `qtdeItens`
    // (e `pontos` quando disponíveis) sobre o escopo filtrado (jornada/squad).
    // Isso permite a visão "Todas as Jornadas" (sem filtro) sem linhas de tipo
    // duplicadas: os registros do seed são por (jornada × tipo) e aqui são
    // consolidados por tipo. Os percentuais (`itensPct`/`pontosPct`) são
    // recalculados sobre o total do escopo. `pontuacaoMedia` = pontos/qtde
    // quando há pontos; caso contrário null (coleta A1 não lê Story Points).
    //
    // A ordem de saída preserva a ordem canônica de primeira aparição dos tipos.
    // =========================================================================
    function cargaTotalTrabalho(pi, filtro) {
        const data = getData();
        const registros = data.query('cargaTrabalho', { pi, ...normFilter(filtro) });

        const num = (v) => (v != null && !Number.isNaN(Number(v))) ? Number(v) : 0;
        const temPontos = (v) => (v != null && !Number.isNaN(Number(v)));

        const ordem = [];
        const mapa = new Map();
        registros.forEach(r => {
            const tipo = r.tipoItem;
            if (!mapa.has(tipo)) {
                ordem.push(tipo);
                mapa.set(tipo, { tipoItem: tipo, pontos: 0, temPontos: false, qtdeItens: 0 });
            }
            const acc = mapa.get(tipo);
            acc.qtdeItens += num(r.qtdeItens);
            if (temPontos(r.pontos)) { acc.pontos += Number(r.pontos); acc.temPontos = true; }
        });

        const linhas = ordem.map(t => mapa.get(t));
        const totalQtde = linhas.reduce((a, l) => a + l.qtdeItens, 0);
        const totalPontos = linhas.reduce((a, l) => a + (l.temPontos ? l.pontos : 0), 0);

        return linhas.map(l => ({
            tipoItem: l.tipoItem,
            pontos: l.temPontos ? l.pontos : null,
            pontosPct: l.temPontos ? safeRatio(l.pontos, totalPontos) : null,
            qtdeItens: l.qtdeItens,
            itensPct: safeRatio(l.qtdeItens, totalQtde),
            pontuacaoMedia: (l.temPontos && l.qtdeItens > 0) ? l.pontos / l.qtdeItens : null
        }));
    }

    // =========================================================================
    // SUSTENTAÇÃO (Req 16.1, 16.2) — Property 23
    //
    // Para o PI informado, retorna o Realizado vs Planejado por Time, o total
    // da Jornada (soma dos Times) para Realizado e Planejado, e o histórico de
    // Sustentação por PI (coleção `sustentacaoHistorico`, com percentual quando
    // disponível). O total da Jornada é, por construção, a soma dos valores dos
    // Times (Property 23).
    // =========================================================================
    function sustentacao(pi, filtro) {
        const data = getData();
        const num = (v) => (v != null && !Number.isNaN(Number(v))) ? Number(v) : 0;

        const registros = data.query('sustentacao', { pi, ...normFilter(filtro) });
        const porTime = registros.map(r => ({
            time: r.time,
            jornada: r.jornada,
            realizado: num(r.realizado),
            planejado: num(r.planejado)
        }));

        const totalJornada = {
            realizado: porTime.reduce((a, r) => a + r.realizado, 0),
            planejado: porTime.reduce((a, r) => a + r.planejado, 0)
        };

        // Histórico por PI (todos os PIs do histórico; preserva percentual).
        const historicoRegs = (data.DATASET && Array.isArray(data.DATASET.sustentacaoHistorico))
            ? data.DATASET.sustentacaoHistorico
            : [];
        const historico = historicoRegs.map(r => ({
            pi: r.pi,
            time: r.time,
            jornada: r.jornada,
            valor: num(r.valor),
            pct: (r.pct != null && !Number.isNaN(Number(r.pct))) ? Number(r.pct) : null
        }));

        return { porTime, totalJornada, historico };
    }

    // =========================================================================
    // PUBLIC API (Task 13.1 + Task 13.2)
    // Objeto único `RetroAggregation` ao qual a Task 13.2 adiciona métodos.
    // =========================================================================
    return {
        // Constantes auxiliares (reutilizáveis pela 13.2 e pelos painéis/testes).
        SITUACOES,
        ORIGENS,

        // Agregações da Task 13.1.
        pontosPorSprint,
        produtividadePorPI,
        situacaoEpicos,

        // Agregações da Task 13.2.
        cycleTimePorFase,
        leadTimePorTipo,
        debitoTecnico,
        sustentacao,
        cargaTotalTrabalho
    };
})();

// Environment guard: expose the IIFE module for Node-based tests (Vitest)
// without affecting the browser <script> load. In the browser `module` is
// undefined, so this block is skipped and `RetroAggregation` stays global.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RetroAggregation;
}

if (typeof globalThis !== 'undefined') {
    globalThis.RetroAggregation = RetroAggregation;
}
