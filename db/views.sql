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
CREATE OR REPLACE VIEW vw_velocidade AS
SELECT
    sp.jornada,
    sp.time,
    sp.projeto_key,
    SUM(sp.dias_uteis)                 AS dias_uteis_pi,
    SUM(ROUND(sp.velocity_sprint, 0))  AS velocity,
    SUM(sp.sustentacao_sprint)         AS sustentacao,
    SUM(ROUND(sp.capacity_sprint, 0))  AS capacity
FROM (
    SELECT
        j.nome                                            AS jornada,
        t.nome                                            AS time,
        t.projeto_key,
        s.nome                                            AS sprint_nome,
        s.dias_uteis,
        CASE WHEN tc.media_produtividade_pi > 0
             THEN (tc.qtde_dev * tc.horas_dev * s.dias_uteis) / tc.media_produtividade_pi
             ELSE NULL
        END                                               AS velocity_sprint,
        COALESCE(sh.pts_sustentacao, 0)                   AS sustentacao_sprint,
        CASE WHEN tc.media_produtividade_pi > 0
             THEN ((tc.qtde_dev * tc.horas_dev * s.dias_uteis) / tc.media_produtividade_pi)
                  - COALESCE(sh.pts_sustentacao, 0)
             ELSE NULL
        END                                               AS capacity_sprint
    FROM times t
    JOIN jornada j           ON j.id_jornada = t.jornada_id
    JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
    JOIN configuracao_pi p   ON p.desc_pi = 'PI 4/26'
    JOIN sprints s           ON s.configuracao_pi_id = p.id_versao_corrigida
                            AND s.tipo = 'valor'
    LEFT JOIN sustentacao_historico sh
                             ON sh.times_id = t.id_time
                            AND sh.configuracao_pi_id = p.id_versao_corrigida
                            AND sh.sprint_nome = s.nome
                            AND sh.ativo = 'S'
    WHERE t.ativo = 'S'
      AND j.ativo = 'S'
) sp
GROUP BY sp.jornada, sp.time, sp.projeto_key;

-- ---------------------------------------------------------------------
-- 4. vw_previsibilidade
--    (SP entregue de valor / SP Estimate da baseline de valor) * 100, por time.
--    Numerador: SUM(story_points) de extracao_jira com status entregue e
--               tipo de valor (ativo='S').
--    Denominador: SUM(story_point_estimate) da baseline (itens de valor).
--    baseline vazia agora -> retorna vazio/NULL ate haver dados (ok).
--    Time extraido de `projeto` como em vw_cycle_time. NULLIF evita /0.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_previsibilidade AS
SELECT
    COALESCE(ent.jornada, bl.jornada) AS jornada,
    COALESCE(ent.time, bl.time)       AS time,
    ent.sp_entregue,
    bl.sp_baseline,
    (ent.sp_entregue / NULLIF(bl.sp_baseline, 0)) * 100 AS previsibilidade_pct
FROM (
    SELECT
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.projeto, ']', 1), '[', -1)) AS jornada,
        TRIM(SUBSTRING_INDEX(e.projeto, '] ', -1))                        AS time,
        SUM(e.story_points) AS sp_entregue
    FROM extracao_jira e
    WHERE e.ativo = 'S'
      AND e.status IN ('Aceito','Pendente de Produção','Em produção')
      AND e.tipo_item IN ('História','Enabler','Débito Técnico','Spike','Teste','Segurança')
    GROUP BY
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.projeto, ']', 1), '[', -1)),
        TRIM(SUBSTRING_INDEX(e.projeto, '] ', -1))
) ent
LEFT JOIN (
    SELECT
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(b.projeto, ']', 1), '[', -1)) AS jornada,
        TRIM(SUBSTRING_INDEX(b.projeto, '] ', -1))                        AS time,
        SUM(b.story_point_estimate) AS sp_baseline
    FROM baseline b
    WHERE b.ativo = 'S'
      AND b.tipo_item IN ('História','Enabler','Débito Técnico','Spike','Teste','Segurança')
    GROUP BY
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(b.projeto, ']', 1), '[', -1)),
        TRIM(SUBSTRING_INDEX(b.projeto, '] ', -1))
) bl ON bl.jornada = ent.jornada AND bl.time = ent.time;

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
GROUP BY
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(e.projeto, ']', 1), '[', -1)),
    TRIM(SUBSTRING_INDEX(e.projeto, '] ', -1));

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
