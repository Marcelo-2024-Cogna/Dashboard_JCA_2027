-- =============================================================================
-- PEP — Plataforma de Engenharia de Performance
-- DDL físico (PostgreSQL) — derivado de data-model.md
--
-- Objetivo: script de referência para a fase de produção. NÃO é usado pelo MVP
-- (que é estático e persiste em localStorage). Representa o modelo lógico já
-- existente no código (admin-store.js, data.js, retro-data.js, excecoes-store.js,
-- rbac.js) como esquema relacional.
--
-- Convenções:
--  - PKs em TEXT (UUID/slug) para compatibilidade com os ids gerados no MVP.
--  - Nomes canônicos de jornada/squad com UNIQUE (identidade de identity.js).
--  - Valores de score/percentuais em NUMERIC para evitar erro de ponto flutuante.
--  - Credenciais de integração NÃO são modeladas aqui (ficam em cofre de segredos).
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- Organização
-- ---------------------------------------------------------------------------
CREATE TABLE tribo (
    id              TEXT PRIMARY KEY,
    nome            TEXT NOT NULL,
    nome_canonico   TEXT NOT NULL UNIQUE
);

CREATE TABLE squad (
    id              TEXT PRIMARY KEY,
    tribo_id        TEXT NOT NULL REFERENCES tribo(id) ON DELETE RESTRICT,
    nome            TEXT NOT NULL,
    nome_canonico   TEXT NOT NULL,
    UNIQUE (tribo_id, nome_canonico)
);

-- ---------------------------------------------------------------------------
-- Acesso (perfis, permissões, usuários) — espelha rbac.js
-- ---------------------------------------------------------------------------
CREATE TABLE perfil (
    id      TEXT PRIMARY KEY,              -- admin, dm, tm, gpm, tl
    nome    TEXT NOT NULL UNIQUE
);

CREATE TABLE permissao (
    id          TEXT PRIMARY KEY,          -- screenId ou acao (ex.: excecao:aprovar)
    label       TEXT NOT NULL,
    tipo        TEXT NOT NULL DEFAULT 'tela' CHECK (tipo IN ('tela','acao'))
);

CREATE TABLE perfil_permissao (
    perfil_id       TEXT NOT NULL REFERENCES perfil(id) ON DELETE CASCADE,
    permissao_id    TEXT NOT NULL REFERENCES permissao(id) ON DELETE CASCADE,
    PRIMARY KEY (perfil_id, permissao_id)
);

CREATE TABLE usuario (
    id          TEXT PRIMARY KEY,
    nome        TEXT NOT NULL,
    email       TEXT NOT NULL UNIQUE,
    perfil_id   TEXT NOT NULL REFERENCES perfil(id) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------------
-- Ciclo e tempo
-- ---------------------------------------------------------------------------
CREATE TABLE pi (
    id          TEXT PRIMARY KEY,
    codigo      TEXT NOT NULL UNIQUE,      -- ex.: 'PI 3/26'
    ano         INT  NOT NULL,
    numero      INT  NOT NULL
);

CREATE TABLE sprint (
    id          TEXT PRIMARY KEY,
    pi_id       TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    codigo      TEXT NOT NULL,             -- ex.: 'SP 26.3.1'
    ordem       INT  NOT NULL,
    UNIQUE (pi_id, codigo)
);

-- ---------------------------------------------------------------------------
-- Score de Performance (modelo mensal)
-- ---------------------------------------------------------------------------
CREATE TABLE metrica (
    id          TEXT PRIMARY KEY,          -- chave: planejadoVsRealizado, ...
    nome        TEXT NOT NULL,
    categoria   TEXT NOT NULL CHECK (categoria IN ('processos','praticas','produto','pessoas')),
    peso_max    NUMERIC(6,2) NOT NULL,
    escala      TEXT NOT NULL,             -- ratio | days | category | thc0a10
    direcao     TEXT NOT NULL CHECK (direcao IN ('higher','lower','category'))
);

CREATE TABLE score_mensal (
    id          TEXT PRIMARY KEY,
    squad_id    TEXT NOT NULL REFERENCES squad(id) ON DELETE CASCADE,
    ano         INT NOT NULL,
    mes         INT NOT NULL CHECK (mes BETWEEN 1 AND 12),
    total       NUMERIC(7,2),              -- score total consolidado (cache)
    UNIQUE (squad_id, ano, mes)
);

CREATE TABLE score_metrica_valor (
    id              TEXT PRIMARY KEY,
    score_mensal_id TEXT NOT NULL REFERENCES score_mensal(id) ON DELETE CASCADE,
    metrica_id      TEXT NOT NULL REFERENCES metrica(id) ON DELETE RESTRICT,
    valor           NUMERIC(10,4),         -- valor bruto (ratio/dias/0-10/categoria)
    pontos          NUMERIC(7,2),          -- pontuação calculada
    faixa           TEXT,                  -- nao-atendeu | parcial | atendeu | superou | sem-dados
    UNIQUE (score_mensal_id, metrica_id)
);

CREATE TABLE meta_kpi (
    id          TEXT PRIMARY KEY,
    tribo_id    TEXT REFERENCES tribo(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    nome        TEXT NOT NULL,
    meta        NUMERIC(10,2),
    meta_pct    NUMERIC(6,4),              -- ex.: 0.05 (5%), 0.145 (14,5%)
    partida     NUMERIC(10,2),             -- score realizado dez/25
    CHECK (tribo_id IS NOT NULL OR squad_id IS NOT NULL)
);

-- ---------------------------------------------------------------------------
-- Retrospectiva de PI
-- ---------------------------------------------------------------------------
CREATE TABLE item_jira (
    id              TEXT PRIMARY KEY,
    chave           TEXT NOT NULL UNIQUE,  -- ex.: ASAT-12388
    tipo_item       TEXT NOT NULL,
    status          TEXT,
    squad_id        TEXT REFERENCES squad(id) ON DELETE SET NULL,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    sprint_id       TEXT REFERENCES sprint(id) ON DELETE SET NULL,
    story_points    NUMERIC(6,2),
    criado_em       TIMESTAMPTZ,
    inicio_dev      TIMESTAMPTZ,
    fim_dev         TIMESTAMPTZ,
    homologado_em   TIMESTAMPTZ,
    implantado_em   TIMESTAMPTZ,
    resolvido_em    TIMESTAMPTZ
);

CREATE TABLE pontos_trabalhados (
    id              TEXT PRIMARY KEY,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    sprint_id       TEXT REFERENCES sprint(id) ON DELETE CASCADE,
    squad_id        TEXT REFERENCES squad(id) ON DELETE CASCADE,
    sustentacao     NUMERIC(8,2) NOT NULL DEFAULT 0,
    entrega_valor   NUMERIC(8,2) NOT NULL DEFAULT 0,
    total           NUMERIC(8,2) NOT NULL DEFAULT 0
);

CREATE TABLE cycle_time (
    id              TEXT PRIMARY KEY,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id        TEXT REFERENCES squad(id) ON DELETE CASCADE,
    ideacao         NUMERIC(8,2),
    desenvolvimento NUMERIC(8,2),
    homologacao     NUMERIC(8,2),
    implantacao     NUMERIC(8,2)
);

CREATE TABLE lead_time (
    id          TEXT PRIMARY KEY,
    pi_id       TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    tipo_item   TEXT NOT NULL,
    dias        NUMERIC(8,2)
);

CREATE TABLE produtividade (
    id          TEXT PRIMARY KEY,
    pi_id       TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    valor       NUMERIC(8,4)               -- pontos por integrante
);

CREATE TABLE situacao_epico (
    id          TEXT PRIMARY KEY,
    pi_id       TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    chave       TEXT NOT NULL,
    situacao    TEXT NOT NULL CHECK (situacao IN
                 ('atrasado','replanejado','em-risco','em-dia','finalizado','despriorizado')),
    origem      TEXT NOT NULL CHECK (origem IN ('baseline','mudanca-escopo'))
);

CREATE TABLE debito (
    id              TEXT PRIMARY KEY,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id        TEXT REFERENCES squad(id) ON DELETE CASCADE,
    backlog_qtde    INT,
    planejado_sp    NUMERIC(8,2), real_sp NUMERIC(8,2), entregue_sp NUMERIC(8,2),
    pendente_sp     NUMERIC(8,2), cancelado_sp NUMERIC(8,2),
    planejado_qtd   INT, real_qtd INT, entregue_qtd INT, pendente_qtd INT, cancelado_qtd INT
);

CREATE TABLE sustentacao (
    id          TEXT PRIMARY KEY,
    pi_id       TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    realizado   NUMERIC(8,2),
    planejado   NUMERIC(8,2)
);

CREATE TABLE carga_trabalho (
    id              TEXT PRIMARY KEY,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    tipo_item       TEXT NOT NULL,
    pontos          NUMERIC(8,2),
    qtde_itens      INT,
    pontuacao_media NUMERIC(8,4)
);

CREATE TABLE alteracao_plano (
    id                      TEXT PRIMARY KEY,
    pi_id                   TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    dimensao                TEXT NOT NULL CHECK (dimensao IN ('epicos','historias','pontos')),
    baseline                NUMERIC(8,2),
    plan_pi_planning        NUMERIC(8,2),
    mudanca_escopo          NUMERIC(8,2),
    cancelado               NUMERIC(8,2),
    transbordo_aceito       NUMERIC(8,2),
    transbordo_pendentes    NUMERIC(8,2)
);

CREATE TABLE impedimento (
    id              TEXT PRIMARY KEY,
    pi_id           TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    sprint_id       TEXT REFERENCES sprint(id) ON DELETE SET NULL,
    quantidade      INT,
    localizacao     NUMERIC(6,2),
    tempo_remocao   NUMERIC(8,2)
);

CREATE TABLE baseline_pi (
    id                  TEXT PRIMARY KEY,
    pi_id               TEXT NOT NULL REFERENCES pi(id) ON DELETE CASCADE,
    squad_id            TEXT REFERENCES squad(id) ON DELETE SET NULL,
    chave_epico         TEXT NOT NULL,
    situacao_planejada  TEXT
);

-- ---------------------------------------------------------------------------
-- OKRs e governança
-- ---------------------------------------------------------------------------
CREATE TABLE okr (
    id          TEXT PRIMARY KEY,
    tribo_id    TEXT REFERENCES tribo(id) ON DELETE CASCADE,
    squad_id    TEXT REFERENCES squad(id) ON DELETE CASCADE,
    objetivo    TEXT NOT NULL,
    key_result  TEXT,
    progresso   NUMERIC(5,4)               -- 0..1
);

CREATE TABLE excecao (
    id                  TEXT PRIMARY KEY,
    squad_id            TEXT NOT NULL REFERENCES squad(id) ON DELETE CASCADE,
    metrica_id          TEXT REFERENCES metrica(id) ON DELETE SET NULL,
    indicador           TEXT,              -- quando não for uma das 12 métricas
    justificativa       TEXT NOT NULL,
    periodo             TEXT,
    status              TEXT NOT NULL CHECK (status IN ('pendente','aprovada','rejeitada')),
    solicitante_id      TEXT REFERENCES usuario(id) ON DELETE SET NULL,
    aprovador_id        TEXT REFERENCES usuario(id) ON DELETE SET NULL,
    motivo_rejeicao     TEXT,
    criado_em           TIMESTAMPTZ NOT NULL DEFAULT now(),
    decidido_em         TIMESTAMPTZ
);

CREATE TABLE excecao_historico (
    id          TEXT PRIMARY KEY,
    excecao_id  TEXT NOT NULL REFERENCES excecao(id) ON DELETE CASCADE,
    estado      TEXT NOT NULL,
    ator_id     TEXT REFERENCES usuario(id) ON DELETE SET NULL,
    data        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Configuração (sem credenciais sensíveis)
-- ---------------------------------------------------------------------------
CREATE TABLE comunicacao (
    id      TEXT PRIMARY KEY,
    canal   TEXT NOT NULL,
    destino TEXT NOT NULL,
    ativo   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE integracao (
    id              TEXT PRIMARY KEY,
    sistema         TEXT NOT NULL,         -- Jira, ServiceNow, Datadog
    projeto_jira    TEXT,
    jornada         TEXT,
    squad           TEXT
    -- NUNCA armazenar token/senha/apiKey aqui — usar cofre de segredos.
);

-- ---------------------------------------------------------------------------
-- Índices de apoio para consultas frequentes
-- ---------------------------------------------------------------------------
CREATE INDEX idx_score_mensal_squad ON score_mensal(squad_id, ano, mes);
CREATE INDEX idx_item_jira_pi       ON item_jira(pi_id, sprint_id, squad_id);
CREATE INDEX idx_pontos_pi_squad    ON pontos_trabalhados(pi_id, squad_id);
CREATE INDEX idx_excecao_status     ON excecao(status, squad_id);
CREATE INDEX idx_squad_tribo        ON squad(tribo_id);

COMMIT;

-- =============================================================================
-- Notas:
--  - Seed inicial pode ser feito a partir dos CSVs de modelos-csv/ (round-trip
--    já suportado no MVP via toCSV/fromCSV).
--  - A matriz do rbac.PERMISSIONS popula perfil_permissao.
--  - Tecnologia-alvo sugerida: PostgreSQL 14+. Ajuste tipos conforme o SGBD.
-- =============================================================================
