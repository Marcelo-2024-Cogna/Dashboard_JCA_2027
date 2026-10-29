-- =====================================================================
-- Metricas Jeito Cogna Agil - VIEWS v1.5 (ETAPA 4: camada de calculo)
-- SGBD: MySQL 8.x (portavel para CentOS/Linux em producao)
-- Idempotente: CREATE OR REPLACE VIEW. Pode ser reaplicado sem erro.
-- Charset do banco: utf8mb4. Literais acentuados (Historia, Seguranca,
-- Producao) sao comparados exatamente como aparecem na extracao.
-- =====================================================================
--
-- CONVENCOES DE NEGOCIO (regra mestra - ENTREGA DE VALOR):
--   tipo_item de VALOR ∈ {Historia, Enabler, Debito Tecnico, Spike,
--                         Teste, Seguranca}.
--   STATUS ENTREGUE ∈ {Aceito, Pendente de Producao, Em producao}.
--   COM EPICO = `pai` preenchido (NOT NULL e <> '').
--
-- CALCULO DE DIAS UTEIS (NETWORKDAYS.INTL modo 1, seg-sex, inclusivo):
--   WEEKDAY() do MySQL: 0=segunda .. 6=domingo.
--   networkdays_inclusivo(d1,d2) =
--       (DATEDIFF(d2,d1)+1)                                  -- dias corridos inclusivos
--     - (FLOOR((DATEDIFF(d2,d1)+WEEKDAY(d1)+1)/7)*2)         -- fins de semana completos
--     - (CASE WHEN WEEKDAY(d1)=6 THEN 1 ELSE 0 END)          -- ajuste ponta inicial (domingo)
--     - (CASE WHEN WEEKDAY(d2)=5 THEN 1 ELSE 0 END)          -- ajuste ponta final (sabado)
--   Validado: 2026-09-28(seg)->2026-10-09(sex)=10; d->d=1; sex->seg=2;
--             sab->dom=0; seg->dom=5.
--   A planilha subtrai 1 do NETWORKDAYS (etapa_dias = networkdays - 1).
-- =====================================================================

USE metricas_agil;

-- ---------------------------------------------------------------------
-- 1. vw_extracao_calculada
--    Extracao bruta (ativo='S') + colunas derivadas de ciclo e flags.
--    Formulas AK/AM/AO/AQ/AS da aba 'Extracao Jira - Itens'.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_extracao_calculada AS
SELECT
    e.id_extracao,
    e.configuracao_pi_id,
    e.tipo_item,
    e.chave,
    e.sprint,
    e.sprint_ref,
    e.projeto,
    e.status,
    e.versoes_corrigidas,
    e.story_points,
    e.story_point_estimate,
    e.soma_tempo_gasto,
    e.categoria_status,
    e.pai,
    e.criado,
    e.ponto_compromisso,
    e.inicio_fluxo,
    e.fim_fluxo,
    e.inicio_homologacao,
    e.fim_homologacao,
    e.inicio_implantacao,
    e.fim_implantacao,
    e.ativo,

    -- AK Ideacao: NULL se SS/Incidente ou criado/ponto_compromisso nulos;
    --             senao max(1, networkdays(criado, ponto_compromisso)-1)
    CASE
        WHEN e.tipo_item IN ('Solicitação de Serviço','Incidente') THEN NULL
        WHEN e.criado IS NULL OR e.ponto_compromisso IS NULL THEN NULL
        ELSE GREATEST(1,
            (DATEDIFF(e.ponto_compromisso, e.criado)+1)
          - (FLOOR((DATEDIFF(e.ponto_compromisso, e.criado)+WEEKDAY(e.criado)+1)/7)*2)
          - (CASE WHEN WEEKDAY(e.criado)=6 THEN 1 ELSE 0 END)
          - (CASE WHEN WEEKDAY(e.ponto_compromisso)=5 THEN 1 ELSE 0 END)
          - 1)
    END AS dias_ideacao,

    -- AM Desenvolvimento: NULL se SS/Incidente ou inicio_fluxo/fim_fluxo nulos;
    --                     senao max(1, networkdays(inicio_fluxo, fim_fluxo)-1).
    --                     TODO bloqueio: a planilha (AJ) subtrai o tempo de
    --                     bloqueio; nao temos bloqueio confiavel em dias, IGNORADO.
    CASE
        WHEN e.tipo_item IN ('Solicitação de Serviço','Incidente') THEN NULL
        WHEN e.inicio_fluxo IS NULL OR e.fim_fluxo IS NULL THEN NULL
        ELSE GREATEST(1,
            (DATEDIFF(e.fim_fluxo, e.inicio_fluxo)+1)
          - (FLOOR((DATEDIFF(e.fim_fluxo, e.inicio_fluxo)+WEEKDAY(e.inicio_fluxo)+1)/7)*2)
          - (CASE WHEN WEEKDAY(e.inicio_fluxo)=6 THEN 1 ELSE 0 END)
          - (CASE WHEN WEEKDAY(e.fim_fluxo)=5 THEN 1 ELSE 0 END)
          - 1)
    END AS dias_desenvolvimento,

    -- AO Homologacao: NULL se SS/Incidente ou inicio/fim_homologacao nulos;
    --                 senao max(1, networkdays(inicio_homologacao, fim_homologacao)-1)
    CASE
        WHEN e.tipo_item IN ('Solicitação de Serviço','Incidente') THEN NULL
        WHEN e.inicio_homologacao IS NULL OR e.fim_homologacao IS NULL THEN NULL
        ELSE GREATEST(1,
            (DATEDIFF(e.fim_homologacao, e.inicio_homologacao)+1)
          - (FLOOR((DATEDIFF(e.fim_homologacao, e.inicio_homologacao)+WEEKDAY(e.inicio_homologacao)+1)/7)*2)
          - (CASE WHEN WEEKDAY(e.inicio_homologacao)=6 THEN 1 ELSE 0 END)
          - (CASE WHEN WEEKDAY(e.fim_homologacao)=5 THEN 1 ELSE 0 END)
          - 1)
    END AS dias_homologacao,

    -- AQ Implantacao: NULL se SS/Incidente ou inicio/fim_implantacao nulos;
    --                 senao networkdays(inicio_implantacao, fim_implantacao)-1;
    --                 se <0 -> NULL; se =0 -> 1.
    CASE
        WHEN e.tipo_item IN ('Solicitação de Serviço','Incidente') THEN NULL
        WHEN e.inicio_implantacao IS NULL OR e.fim_implantacao IS NULL THEN NULL
        ELSE (
            CASE
                WHEN (
                    (DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+1)
                  - (FLOOR((DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+WEEKDAY(e.inicio_implantacao)+1)/7)*2)
                  - (CASE WHEN WEEKDAY(e.inicio_implantacao)=6 THEN 1 ELSE 0 END)
                  - (CASE WHEN WEEKDAY(e.fim_implantacao)=5 THEN 1 ELSE 0 END)
                  - 1) < 0 THEN NULL
                WHEN (
                    (DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+1)
                  - (FLOOR((DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+WEEKDAY(e.inicio_implantacao)+1)/7)*2)
                  - (CASE WHEN WEEKDAY(e.inicio_implantacao)=6 THEN 1 ELSE 0 END)
                  - (CASE WHEN WEEKDAY(e.fim_implantacao)=5 THEN 1 ELSE 0 END)
                  - 1) = 0 THEN 1
                ELSE (
                    (DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+1)
                  - (FLOOR((DATEDIFF(e.fim_implantacao, e.inicio_implantacao)+WEEKDAY(e.inicio_implantacao)+1)/7)*2)
                  - (CASE WHEN WEEKDAY(e.inicio_implantacao)=6 THEN 1 ELSE 0 END)
                  - (CASE WHEN WEEKDAY(e.fim_implantacao)=5 THEN 1 ELSE 0 END)
                  - 1)
            END
        )
    END AS dias_implantacao,

    -- AS Lead Time: NULL se SS/Incidente ou criado nulo.
    --   tipo ∈ {Historia, Enabler, Debito Tecnico} -> networkdays(criado, fim_implantacao)-1
    --        (NULL se fim_implantacao nulo);
    --   senao -> networkdays(criado, fim_homologacao)-1 (NULL se fim_homologacao nulo).
    CASE
        WHEN e.tipo_item IN ('Solicitação de Serviço','Incidente') THEN NULL
        WHEN e.criado IS NULL THEN NULL
        WHEN e.tipo_item IN ('História','Enabler','Débito Técnico') THEN
            CASE WHEN e.fim_implantacao IS NULL THEN NULL
                 ELSE (
                    (DATEDIFF(e.fim_implantacao, e.criado)+1)
                  - (FLOOR((DATEDIFF(e.fim_implantacao, e.criado)+WEEKDAY(e.criado)+1)/7)*2)
                  - (CASE WHEN WEEKDAY(e.criado)=6 THEN 1 ELSE 0 END)
                  - (CASE WHEN WEEKDAY(e.fim_implantacao)=5 THEN 1 ELSE 0 END)
                  - 1)
            END
        ELSE
            CASE WHEN e.fim_homologacao IS NULL THEN NULL
                 ELSE (
                    (DATEDIFF(e.fim_homologacao, e.criado)+1)
                  - (FLOOR((DATEDIFF(e.fim_homologacao, e.criado)+WEEKDAY(e.criado)+1)/7)*2)
                  - (CASE WHEN WEEKDAY(e.criado)=6 THEN 1 ELSE 0 END)
                  - (CASE WHEN WEEKDAY(e.fim_homologacao)=5 THEN 1 ELSE 0 END)
                  - 1)
            END
    END AS lead_time,

    -- Tempo gasto em horas (soma_tempo_gasto em SEGUNDOS / 3600).
    e.soma_tempo_gasto / 3600 AS tempo_gasto_horas,

    -- Flag entrega de valor.
    CASE WHEN e.tipo_item IN
        ('História','Enabler','Débito Técnico','Spike','Teste','Segurança')
        THEN 1 ELSE 0 END AS is_entrega_valor,

    -- Flag com epico (pai preenchido).
    CASE WHEN e.pai IS NOT NULL AND e.pai <> '' THEN 1 ELSE 0 END AS com_epico
FROM extracao_jira e
WHERE e.ativo = 'S';

-- ---------------------------------------------------------------------
-- 2. vw_cycle_time
--    Media (arredondada) por TIME das 4 etapas, SOMENTE entrega de valor.
--    O time vem do campo `projeto` no formato '[Jornada X] NomeTime':
--      jornada = texto entre colchetes; time = parte apos '] '.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_cycle_time AS
SELECT
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.projeto, ']', 1), '[', -1)) AS jornada,
    TRIM(SUBSTRING_INDEX(c.projeto, '] ', -1))                        AS time,
    ROUND(AVG(c.dias_ideacao))        AS media_ideacao,
    ROUND(AVG(c.dias_desenvolvimento)) AS media_desenvolvimento,
    ROUND(AVG(c.dias_homologacao))    AS media_homologacao,
    ROUND(AVG(c.dias_implantacao))    AS media_implantacao,
    COUNT(*)                          AS qtd_itens
FROM vw_extracao_calculada c
WHERE c.is_entrega_valor = 1
  -- v1.5: so a PI CORRENTE (extracao_jira passou a guardar varias PIs; antes so havia a corrente)
  AND c.configuracao_pi_id = (
      SELECT cp.id_versao_corrigida FROM configuracao_pi cp
       WHERE cp.ativo = 'S'
       ORDER BY (CURDATE() BETWEEN cp.data_ini AND cp.data_fim) DESC, cp.data_fim DESC
       LIMIT 1)
GROUP BY
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.projeto, ']', 1), '[', -1)),
    TRIM(SUBSTRING_INDEX(c.projeto, '] ', -1));

-- ---------------------------------------------------------------------
-- 3. vw_velocidade
--    VELOCITY e CAPACITY por time para a PI corrente ('PI 4/26'),
--    calculadas POR SPRINT (sprints de tipo='valor') e somadas por time.
--
--    Para cada time e cada sprint de valor da PI 4/26:
--      velocity_sprint    = qtde_dev * horas_dev * dias_uteis_sprint
--                           / media_produtividade_pi
--      sustentacao_sprint = pts de sustentação daquele time+sprint
--                           (sustentacao_historico; 0 se não houver)
--      capacity_sprint    = velocity_sprint - sustentacao_sprint
--
--    A planilha arredonda CADA sprint para inteiro antes de somar — por isso
--    aplicamos ROUND(...,0) no velocity_sprint e no capacity_sprint ANTES do
--    SUM, para bater numericamente com a Velocity.
--
--    Exposição por time:
--      velocity     = SUM(ROUND(velocity_sprint))
--      sustentacao  = SUM(sustentacao_sprint)
--      capacity     = SUM(ROUND(capacity_sprint))
--      dias_uteis_pi= SUM(dias_uteis_sprint das sprints de valor)
--    Só times de jornada ativa, time ativo e composição ativa.
-- ---------------------------------------------------------------------
--
--    v1.5 (Planejado x Realizado): a conta POR SPRINT passou para
--    vw_velocidade_sprint (3a) e vw_velocidade e agora a agregacao dela para
--    a PI CORRENTE (a que contem hoje; senao a de maior data_fim), sem PI fixa.
--    Colunas inalteradas (jornada, time, projeto_key, dias_uteis_pi, velocity,
--    sustentacao, capacity) para nao quebrar MetricsRepository.
-- ---------------------------------------------------------------------
-- 3a. vw_velocidade_sprint  (grao: time x sprint, TODAS as PIs/sprints)
--      velocity    = ROUND(qtde_dev * horas_dev * dias_uteis / media_produtividade_pi)
--      sustentacao = sustentacao_historico (time + PI + sprint_nome); 0 se nao houver
--      capacity    = velocity - sustentacao   (velocity = sustentacao + capacity)
--    `tipo` (valor | sustentacao) permite filtrar as sprints de valor.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_velocidade_sprint AS
SELECT
    t.projeto_key,
    j.nome                                  AS jornada,
    t.nome                                  AS time,
    p.desc_pi                               AS pi,
    s.nome                                  AS sprint,
    s.dias_uteis,
    v.velocity,
    COALESCE(sh.pts_sustentacao, 0)         AS sustentacao,
    v.velocity - COALESCE(sh.pts_sustentacao, 0) AS capacity,
    s.tipo,
    -- capacity no arredondamento da planilha (ROUND de velocity_bruta - sustentacao);
    -- so para manter vw_velocidade identica a versao anterior quando a sustentacao e fracionaria.
    ROUND(v.bruta - COALESCE(sh.pts_sustentacao, 0), 0) AS capacity_planilha
FROM times t
JOIN jornada j           ON j.id_jornada = t.jornada_id
JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
JOIN configuracao_pi p   ON p.ativo = 'S'
JOIN sprints s           ON s.configuracao_pi_id = p.id_versao_corrigida
                        AND s.ativo = 'S'
LEFT JOIN sustentacao_historico sh
                         ON sh.times_id = t.id_time
                        AND sh.configuracao_pi_id = p.id_versao_corrigida
                        AND sh.sprint_nome = s.nome
                        AND sh.ativo = 'S'
JOIN LATERAL (
    SELECT CASE WHEN tc.media_produtividade_pi > 0
                THEN (tc.qtde_dev * tc.horas_dev * s.dias_uteis) / tc.media_produtividade_pi
                ELSE NULL END AS bruta,
           CASE WHEN tc.media_produtividade_pi > 0
                THEN ROUND((tc.qtde_dev * tc.horas_dev * s.dias_uteis) / tc.media_produtividade_pi, 0)
                ELSE NULL END AS velocity
) v ON TRUE
WHERE t.ativo = 'S'
  AND j.ativo = 'S';

CREATE OR REPLACE VIEW vw_velocidade AS
SELECT
    vs.jornada,
    vs.time,
    vs.projeto_key,
    SUM(vs.dias_uteis)    AS dias_uteis_pi,
    SUM(vs.velocity)      AS velocity,
    SUM(vs.sustentacao)   AS sustentacao,
    SUM(vs.capacity_planilha) AS capacity
FROM vw_velocidade_sprint vs
WHERE vs.tipo = 'valor'
  AND vs.pi = (
      SELECT cp.desc_pi FROM configuracao_pi cp
       WHERE cp.ativo = 'S'
       ORDER BY (CURDATE() BETWEEN cp.data_ini AND cp.data_fim) DESC, cp.data_fim DESC
       LIMIT 1)
GROUP BY vs.jornada, vs.time, vs.projeto_key;

-- ---------------------------------------------------------------------
-- 4a. vw_item  (BASE para os graficos: Previsibilidade, Planejado x
--     Realizado, Lead Time, Produtividade)
--    Item da extracao (ativo='S') enriquecido com o CATALOGO. A ligacao com
--    o time e SEMPRE por projeto_key (prefixo da chave: PROJ-123 -> PROJ),
--    nunca pelo texto 'projeto' do Jira. Expoe nomes do CATALOGO
--    (jornada.nome, times.nome), a PI (desc_pi) e a sprint de referencia
--    (sprint_ref = maior sprint do campo `sprint`, ex. 'SP 26.4.2').
--    Herda as colunas derivadas de vw_extracao_calculada (dias_*, lead_time,
--    tempo_gasto_horas, is_entrega_valor, com_epico) e acrescenta `entregue`.
--    Somente times e jornadas ATIVOS do catalogo.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_item AS
SELECT
    c.id_extracao,
    c.chave,
    t.projeto_key,
    j.nome                AS jornada,
    t.nome                AS time,
    p.desc_pi             AS pi,
    c.configuracao_pi_id,
    c.tipo_item,
    c.status,
    c.categoria_status,
    c.sprint,
    c.sprint_ref,
    c.pai,
    c.projeto             AS projeto_jira,
    c.story_points,
    c.story_point_estimate,
    c.soma_tempo_gasto,
    c.tempo_gasto_horas,
    c.criado,
    c.ponto_compromisso,
    c.inicio_fluxo,
    c.fim_fluxo,
    c.inicio_homologacao,
    c.fim_homologacao,
    c.inicio_implantacao,
    c.fim_implantacao,
    c.dias_ideacao,
    c.dias_desenvolvimento,
    c.dias_homologacao,
    c.dias_implantacao,
    c.lead_time,
    c.is_entrega_valor,
    CASE WHEN c.status IN ('Aceito','Pendente de Produção','Em produção')
         THEN 1 ELSE 0 END AS entregue,
    c.com_epico
FROM vw_extracao_calculada c
JOIN times t   ON t.projeto_key = SUBSTRING_INDEX(c.chave, '-', 1)
              AND t.ativo = 'S'
JOIN jornada j ON j.id_jornada = t.jornada_id
              AND j.ativo = 'S'
LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = c.configuracao_pi_id;

-- ---------------------------------------------------------------------
-- 4b. vw_baseline_item
--    Item da baseline (ativo='S', snapshot imutavel por PI) enriquecido com
--    o catalogo, igual a vw_item. PI = baseline.configuracao_pi_id.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_baseline_item AS
SELECT
    b.id_baseline,
    b.chave,
    t.projeto_key,
    j.nome                AS jornada,
    t.nome                AS time,
    p.desc_pi             AS pi,
    b.configuracao_pi_id,
    b.tipo_item,
    b.status,
    b.sprint,
    b.sprint_ref,
    b.pai,
    b.story_points,
    b.story_point_estimate,
    CASE WHEN b.tipo_item IN
        ('História','Enabler','Débito Técnico','Spike','Teste','Segurança')
        THEN 1 ELSE 0 END AS is_entrega_valor
FROM baseline b
JOIN times t   ON t.projeto_key = SUBSTRING_INDEX(b.chave, '-', 1)
              AND t.ativo = 'S'
JOIN jornada j ON j.id_jornada = t.jornada_id
              AND j.ativo = 'S'
LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = b.configuracao_pi_id
WHERE b.ativo = 'S';

-- ---------------------------------------------------------------------
-- 4b2. vw_planejado_realizado_item  (base do grafico Planejado x Realizado)
--    1 linha por item, das duas origens:
--      origem 'baseline' : snapshot do PI Planning (vw_baseline_item)
--      origem 'extracao' : estado atual (vw_item)
--    categoria: 'valor_sem_epico' | 'valor_com_epico' | NULL.
--    A categoria 'sustentacao' NAO e definida aqui: os tipos que contam como
--    sustentacao ficam em config/baseline.php ('plan_real_sustentacao_types')
--    e o PlanejadoRealizadoRepository os aplica sobre tipo_item. Itens nao
--    valor e nao sustentacao (Incidente, Bug, ...) ficam fora das barras
--    mas continuam visiveis para o diagnostico.
--    entregue: status ∈ {Aceito, Pendente de Producao, Em producao}
--    (sempre 0 na origem baseline). A janela e a agregacao sao feitas no
--    PlanejadoRealizadoRepository.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_planejado_realizado_item AS
SELECT
    'baseline'           AS origem,
    b.chave, b.projeto_key, b.jornada, b.time, b.pi,
    b.tipo_item, b.status, b.sprint_ref,
    CASE WHEN b.is_entrega_valor = 1 AND b.pai IS NOT NULL AND b.pai <> '' THEN 'valor_com_epico'
         WHEN b.is_entrega_valor = 1 THEN 'valor_sem_epico'
         ELSE NULL END   AS categoria,
    b.story_point_estimate AS spe,
    b.story_points         AS sp,
    0                      AS entregue
FROM vw_baseline_item b
UNION ALL
SELECT
    'extracao'           AS origem,
    i.chave, i.projeto_key, i.jornada, i.time, i.pi,
    i.tipo_item, i.status, i.sprint_ref,
    CASE WHEN i.is_entrega_valor = 1 AND i.pai IS NOT NULL AND i.pai <> '' THEN 'valor_com_epico'
         WHEN i.is_entrega_valor = 1 THEN 'valor_sem_epico'
         ELSE NULL END   AS categoria,
    i.story_point_estimate AS spe,
    i.story_points         AS sp,
    i.entregue             AS entregue
FROM vw_item i;

-- ---------------------------------------------------------------------
-- 4b3. vw_lead_time_item  (base do grafico LEAD TIME / cycle time medio por etapas)
--    1 linha por item de ENTREGA DE VALOR ENTREGUE (vw_item: extracao ativa,
--    time/jornada do CATALOGO por projeto_key; etapas ja em dias uteis vindas
--    de vw_extracao_calculada). SEM ideacao (decisao do usuario).
--      lead_time_item = dias_desenvolvimento + dias_homologacao + dias_implantacao,
--                       somando apenas as etapas PRESENTES (NULL = ausente);
--                       NULL quando o item nao tem nenhuma etapa (qtd_etapas = 0).
--      qtd_etapas     = quantas das 3 etapas o item possui.
--    A regra de lead_time de vw_extracao_calculada (com ideacao, planilha) NAO
--    muda. A janela de sprints e a agregacao por time/jornada ficam no
--    LeadTimeRepository (filtra sprint_ref IN janela).
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_lead_time_item AS
SELECT
    i.projeto_key,
    i.jornada,
    i.time,
    i.pi,
    i.sprint_ref,
    i.chave,
    i.tipo_item,
    i.dias_desenvolvimento,
    i.dias_homologacao,
    i.dias_implantacao,
    CASE WHEN i.dias_desenvolvimento IS NULL AND i.dias_homologacao IS NULL AND i.dias_implantacao IS NULL
         THEN NULL
         ELSE COALESCE(i.dias_desenvolvimento, 0) + COALESCE(i.dias_homologacao, 0) + COALESCE(i.dias_implantacao, 0)
    END AS lead_time_item,
    (i.dias_desenvolvimento IS NOT NULL) + (i.dias_homologacao IS NOT NULL) + (i.dias_implantacao IS NOT NULL) AS qtd_etapas,
    -- v1.5: sinalizacao de implantacao pendente (nao altera o calculo do lead time)
    i.status,
    i.inicio_implantacao,
    i.fim_implantacao
FROM vw_item i
WHERE i.is_entrega_valor = 1
  AND i.entregue = 1;

-- ---------------------------------------------------------------------
-- 4. vw_previsibilidade  (REDEFINIDA - grao: projeto_key, jornada, time,
--    pi, sprint_ref [+ sprint_ref_baseline, ver abaixo]). A janela de
--    sprints e a agregacao por time/jornada/total sao feitas na CONSULTA
--    (PrevisibilidadeRepository), filtrando sprint_ref IN (janela).
--    Semantica replicada de MetricsService::consolidateWindowed (v1.0),
--    restrita aos itens de ENTREGA DE VALOR:
--
--    REGRA v1.5 (decisao do usuario), LADO BASELINE (sprint_ref = sprint de
--    referencia do item NA BASELINE; "planejado para a janela" = item de valor
--    da baseline cujo sprint_ref esta na janela):
--      committed_spe        = SUM(baseline.story_point_estimate)   [COMMITTED]
--      delivered_planned_sp = SUM(extracao.story_points ATUAIS) dos itens
--                             planejados hoje em status ENTREGUE (casamento
--                             por chave + mesma PI). Item ausente da extracao
--                             NAO conta como entregue (nao ha como confirmar).
--      delivered_planned_spe_base = SUM(baseline.story_point_estimate) desses
--                             mesmos itens entregues (base da variacao)
--      variacao_estimativa  = delivered_planned_sp - delivered_planned_spe_base
--                             (o que mudou entre PI Planning e Sprint Planning)
--      previsibilidade %    = delivered_planned_sp / NULLIF(committed_spe,0)*100
--                             (calculada na consulta, apos somar a janela)
--    O DESTINO do committed (baldes de transbordo) e por item e depende de
--    comparar sprints por TUPLA ano.pi.seq (nao por texto): ver
--    vw_previsibilidade_item (4c) e PrevisibilidadeRepository.
--    LADO EXTRACAO (sprint_ref = sprint de referencia do item NA EXTRACAO;
--                   sprint_ref_baseline = sprint_ref do MESMO item na
--                   baseline, NULL se ele nao esta na baseline):
--      scope_add_sp         = SUM(story_points) dos itens de valor que NAO
--                             existem na baseline (chave+PI)
--      scope_realocado_sp   = SUM(story_points) dos itens de valor que existem
--                             na baseline (qualquer sprint)
--      delivered_total_sp   = SUM(story_points) dos itens de valor entregues
--    ESCOPO ADICIONADO NA JANELA (v1.0): item da extracao na janela que nao
--    esta na baseline JANELADA = scope_add_sp + scope_realocado_sp das linhas
--    cujo sprint_ref_baseline e NULL ou fora da janela (feito na consulta).
--    Colunas *_itens trazem as contagens. Item sem sprint -> sprint_ref NULL
--    (fora de qualquer janela). A PI do lado baseline e baseline.
--    configuracao_pi_id; a do lado extracao e extracao_jira.configuracao_pi_id.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_previsibilidade AS
SELECT
    m.projeto_key,
    m.jornada,
    m.time,
    m.pi,
    m.sprint_ref,
    m.sprint_ref_baseline,
    SUM(m.committed_spe)               AS committed_spe,
    SUM(m.delivered_planned_sp)        AS delivered_planned_sp,
    SUM(m.delivered_planned_spe_base)  AS delivered_planned_spe_base,
    SUM(m.delivered_planned_sp) - SUM(m.delivered_planned_spe_base) AS variacao_estimativa,
    SUM(m.scope_add_sp)                AS scope_add_sp,
    SUM(m.scope_realocado_sp)          AS scope_realocado_sp,
    SUM(m.delivered_total_sp)          AS delivered_total_sp,
    SUM(m.committed_itens)             AS committed_itens,
    SUM(m.delivered_planned_itens)     AS delivered_planned_itens,
    SUM(m.scope_add_itens)             AS scope_add_itens,
    SUM(m.scope_realocado_itens)       AS scope_realocado_itens,
    SUM(m.delivered_total_itens)       AS delivered_total_itens
FROM (
    -- lado BASELINE
    SELECT
        b.projeto_key, b.jornada, b.time, b.pi, b.sprint_ref,
        b.sprint_ref AS sprint_ref_baseline,
        COALESCE(b.story_point_estimate, 0)  AS committed_spe,
        CASE WHEN e.status IN ('Aceito','Pendente de Produção','Em produção')
             THEN COALESCE(e.story_points, 0) ELSE 0 END AS delivered_planned_sp,
        CASE WHEN e.status IN ('Aceito','Pendente de Produção','Em produção')
             THEN COALESCE(b.story_point_estimate, 0) ELSE 0 END AS delivered_planned_spe_base,
        0 AS scope_add_sp,
        0 AS scope_realocado_sp,
        0 AS delivered_total_sp,
        1 AS committed_itens,
        CASE WHEN e.status IN ('Aceito','Pendente de Produção','Em produção')
             THEN 1 ELSE 0 END AS delivered_planned_itens,
        0 AS scope_add_itens,
        0 AS scope_realocado_itens,
        0 AS delivered_total_itens
    FROM vw_baseline_item b
    LEFT JOIN extracao_jira e
           ON e.chave = b.chave
          AND e.ativo = 'S'
          AND e.configuracao_pi_id = b.configuracao_pi_id
    WHERE b.is_entrega_valor = 1

    UNION ALL

    -- lado EXTRACAO
    SELECT
        i.projeto_key, i.jornada, i.time, i.pi, i.sprint_ref,
        bl.sprint_ref AS sprint_ref_baseline,
        0 AS committed_spe,
        0 AS delivered_planned_sp,
        0 AS delivered_planned_spe_base,
        CASE WHEN bl.chave IS NULL     THEN COALESCE(i.story_points, 0) ELSE 0 END AS scope_add_sp,
        CASE WHEN bl.chave IS NOT NULL THEN COALESCE(i.story_points, 0) ELSE 0 END AS scope_realocado_sp,
        CASE WHEN i.entregue = 1       THEN COALESCE(i.story_points, 0) ELSE 0 END AS delivered_total_sp,
        0 AS committed_itens,
        0 AS delivered_planned_itens,
        CASE WHEN bl.chave IS NULL     THEN 1 ELSE 0 END AS scope_add_itens,
        CASE WHEN bl.chave IS NOT NULL THEN 1 ELSE 0 END AS scope_realocado_itens,
        CASE WHEN i.entregue = 1       THEN 1 ELSE 0 END AS delivered_total_itens
    FROM vw_item i
    LEFT JOIN baseline bl
           ON bl.chave = i.chave
          AND bl.ativo = 'S'
          AND bl.configuracao_pi_id = i.configuracao_pi_id
    WHERE i.is_entrega_valor = 1
) m
GROUP BY m.projeto_key, m.jornada, m.time, m.pi, m.sprint_ref, m.sprint_ref_baseline;

-- ---------------------------------------------------------------------
-- 4c. vw_previsibilidade_item  (grao: 1 linha por item de VALOR da baseline)
--    Base do DESTINO DO COMMITTED (transbordo). Cada item planejado traz a
--    sua situacao ATUAL na extracao (casamento por chave + mesma PI):
--      sprint_ref_baseline / spe_baseline : o planejado (PI Planning)
--      na_extracao                        : 0 = item ausente da extracao da PI
--      status_atual, sprint_ref_atual     : situacao hoje (NULL se ausente)
--      sp_atual, spe_atual                : story_points / SPE atuais
--      entregue_atual, cancelado_atual    : flags (status ENTREGUE / 'Cancelado')
--    A janela e a classificacao em baldes (entregue na janela, entregue apos,
--    transbordo aberto, nao concluido, backlog, cancelado, fora da extracao)
--    sao feitas no repositorio: "posterior a janela" exige comparar a TUPLA
--    ano.pi.seq de sprint_ref, nao o texto.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_previsibilidade_item AS
SELECT
    b.chave,
    b.projeto_key,
    b.jornada,
    b.time,
    b.pi,
    b.sprint_ref                 AS sprint_ref_baseline,
    COALESCE(b.story_point_estimate, 0) AS spe_baseline,
    CASE WHEN e.chave IS NULL THEN 0 ELSE 1 END AS na_extracao,
    e.status                     AS status_atual,
    e.sprint_ref                 AS sprint_ref_atual,
    e.story_points               AS sp_atual,
    e.story_point_estimate       AS spe_atual,
    CASE WHEN e.status IN ('Aceito','Pendente de Produção','Em produção')
         THEN 1 ELSE 0 END       AS entregue_atual,
    CASE WHEN e.status = 'Cancelado' THEN 1 ELSE 0 END AS cancelado_atual
FROM vw_baseline_item b
LEFT JOIN extracao_jira e
       ON e.chave = b.chave
      AND e.ativo = 'S'
      AND e.configuracao_pi_id = b.configuracao_pi_id
WHERE b.is_entrega_valor = 1;

-- ---------------------------------------------------------------------
-- 4d. vw_baseline_ajuste  (trilha de auditoria da baseline, p/ tela futura)
--    baseline_ajuste enriquecida com a PI (desc_pi) e o CATALOGO (time e
--    jornada por projeto_key = prefixo da chave). LEFT JOIN: a trilha nunca
--    perde linhas, mesmo se o item/time sair do catalogo ou da baseline.
--    origem: 'formal' | 'sql_direto' | 'regularizado'.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_baseline_ajuste AS
SELECT
    a.id_ajuste,
    p.desc_pi                              AS pi,
    a.chave,
    SUBSTRING_INDEX(a.chave, '-', 1)       AS projeto_key,
    t.nome                                 AS time,
    j.nome                                 AS jornada,
    a.campo,
    a.valor_anterior,
    a.valor_novo,
    a.motivo,
    a.usuario,
    a.origem,
    a.criado_em
FROM baseline_ajuste a
LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = a.configuracao_pi_id
LEFT JOIN times t           ON t.projeto_key = SUBSTRING_INDEX(a.chave, '-', 1)
                           AND t.ativo = 'S'
LEFT JOIN jornada j         ON j.id_jornada = t.jornada_id;

-- ---------------------------------------------------------------------
-- 5. vw_produtividade
--    Por time, ao vivo da extracao_jira (itens de valor ENTREGUES):
--      horas_gastas    = SUM(soma_tempo_gasto)/3600
--      pontos_entregues = SUM(story_points)
--      produtividade    = horas_gastas / NULLIF(pontos_entregues, 0)
--    Time extraido de `projeto` como em vw_cycle_time.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_produtividade AS
SELECT
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.projeto, ']', 1), '[', -1)) AS jornada,
    TRIM(SUBSTRING_INDEX(e.projeto, '] ', -1))                        AS time,
    SUM(e.story_points)                                 AS pontos_entregues,
    SUM(e.soma_tempo_gasto) / 3600                      AS horas_gastas,
    (SUM(e.soma_tempo_gasto) / 3600) / NULLIF(SUM(e.story_points), 0) AS produtividade
FROM extracao_jira e
WHERE e.ativo = 'S'
  AND e.status IN ('Aceito','Pendente de Produção','Em produção')
  AND e.tipo_item IN ('História','Enabler','Débito Técnico','Spike','Teste','Segurança')
  -- v1.5: so a PI CORRENTE (extracao_jira passou a guardar varias PIs; antes so havia a corrente).
  -- O historico por PI vem de vw_produtividade_pi / produtividade_historico.
  AND e.configuracao_pi_id = (
      SELECT cp.id_versao_corrigida FROM configuracao_pi cp
       WHERE cp.ativo = 'S'
       ORDER BY (CURDATE() BETWEEN cp.data_ini AND cp.data_fim) DESC, cp.data_fim DESC
       LIMIT 1)
GROUP BY
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.projeto, ']', 1), '[', -1)),
    TRIM(SUBSTRING_INDEX(e.projeto, '] ', -1));

-- ---------------------------------------------------------------------
-- 5b. vw_produtividade_pi  (PRODUTIVIDADE POR PI = horas para entregar 1 ponto)
--    Grao: time (projeto_key, CATALOGO) x PI, AO VIVO de extracao_jira.
--    Itens de ENTREGA DE VALOR ENTREGUES (vw_item) da PI (configuracao_pi_id):
--      horas_gastas     = SUM(soma_tempo_gasto)/3600
--      pontos_entregues = SUM(story_points)   (SP nulo NAO usa estimate; entra em itens_sp_nulo)
--      produtividade    = horas_gastas / NULLIF(pontos_entregues, 0)
--    Fonte do consolidado bin/consolidar_produtividade.php -> produtividade_historico.
--    NAO substitui vw_produtividade (antiga, PI corrente por nome do Jira).
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_produtividade_pi AS
SELECT
    i.projeto_key,
    i.jornada,
    i.time,
    i.pi,
    i.configuracao_pi_id,
    SUM(i.soma_tempo_gasto) / 3600                                       AS horas_gastas,
    SUM(i.story_points)                                                  AS pontos_entregues,
    (SUM(i.soma_tempo_gasto) / 3600) / NULLIF(SUM(i.story_points), 0)    AS produtividade,
    COUNT(*)                                                             AS itens,
    SUM(i.story_points IS NULL)                                          AS itens_sp_nulo
FROM vw_item i
WHERE i.is_entrega_valor = 1
  AND i.entregue = 1
GROUP BY i.projeto_key, i.jornada, i.time, i.pi, i.configuracao_pi_id;

-- ---------------------------------------------------------------------
-- 6. vw_config_sprints_por_pi
--    Catalogo de sprints por PI. Ordenado por PI e nome da sprint.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_config_sprints_por_pi AS
SELECT
    p.desc_pi,
    s.nome       AS sprint,
    s.tipo,
    s.dias_uteis,
    s.data_ini,
    s.data_fim,
    s.ativo
FROM sprints s
JOIN configuracao_pi p ON p.id_versao_corrigida = s.configuracao_pi_id
ORDER BY p.desc_pi, s.nome;

-- ---------------------------------------------------------------------
-- 7. vw_config_time
--    Formacao por time ativo de jornada ativa (composicao ativa).
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_config_time AS
SELECT
    j.nome       AS jornada,
    t.nome       AS time,
    t.projeto_key,
    tc.qtde_dev,
    tc.horas_dev,
    tc.media_produtividade_pi
FROM times t
JOIN jornada j           ON j.id_jornada = t.jornada_id
JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
WHERE j.ativo = 'S'
  AND t.ativo = 'S';
