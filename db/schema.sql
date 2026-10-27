-- =====================================================================
-- Metricas Jeito Cogna Agil - Schema v1.5 (ETAPA 1: DDL)
-- SGBD: MySQL 8.x (portavel para CentOS/Linux em producao)
-- Idempotente: pode ser reaplicado sem erro.
-- NAO contem VIEWs (vw_*) - etapa posterior.
-- =====================================================================

-- ---------------------------------------------------------------------
-- a) Database
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS metricas_agil
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- b) Usuario de aplicacao + GRANTs (somente no database metricas_agil)
--    Dois hosts: 'localhost' (XAMPP) e '%' (CentOS/rede).
-- ---------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'metricas_jca'@'localhost' IDENTIFIED BY 'metricas_jca';
CREATE USER IF NOT EXISTS 'metricas_jca'@'%'         IDENTIFIED BY 'metricas_jca';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW
    ON metricas_agil.* TO 'metricas_jca'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW
    ON metricas_agil.* TO 'metricas_jca'@'%';

FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------
-- c) Tabelas
-- ---------------------------------------------------------------------
USE metricas_agil;

-- 1. jornada ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS jornada (
    id_jornada        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome              VARCHAR(255) NOT NULL,
    projeto_baseline  VARCHAR(255) NULL,
    ativo             CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. times ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS times (
    id_time           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    jornada_id        INT UNSIGNED NOT NULL,
    nome              VARCHAR(255) NOT NULL,
    projeto_baseline  VARCHAR(255) NULL,
    projeto_key       VARCHAR(20) NULL,
    ativo             CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_time),
    KEY idx_times_jornada_id (jornada_id),
    CONSTRAINT fk_times_jornada
        FOREIGN KEY (jornada_id) REFERENCES jornada (id_jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. times_composicao -------------------------------------------------
CREATE TABLE IF NOT EXISTS times_composicao (
    id_time_composicao      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    times_id                INT UNSIGNED NOT NULL,
    qtde_dev                DECIMAL(4,1) NULL,
    horas_dev               DECIMAL(6,2) NULL,
    media_produtividade_pi  DECIMAL(10,2) NULL,
    ativo                   CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_time_composicao),
    KEY idx_times_composicao_times_id (times_id),
    CONSTRAINT fk_times_composicao_times
        FOREIGN KEY (times_id) REFERENCES times (id_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. configuracao_pi --------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracao_pi (
    id_versao_corrigida  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    desc_pi              VARCHAR(255) NOT NULL,
    qtde_sprints         INT NULL,
    data_ini             DATE NULL,
    data_fim             DATE NULL,
    ativo                CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. sprints ----------------------------------------------------------
-- Sprint pertence à PI (catálogo da PI), não a um time específico.
-- `tipo` separa sprints de entrega de VALOR das de SUSTENTAÇÃO (ex: as duas
-- últimas sprints da PI 4/26). Views de valor consideram só tipo='valor'.
CREATE TABLE IF NOT EXISTS sprints (
    id_sprint            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    configuracao_pi_id   INT UNSIGNED NOT NULL,
    nome                 VARCHAR(50) NOT NULL,
    tipo                 VARCHAR(20) NOT NULL DEFAULT 'valor',  -- valor | sustentacao
    dias_uteis           INT NULL,
    data_ini             DATE NULL,
    data_fim             DATE NULL,
    ativo                CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_sprint),
    KEY idx_sprints_configuracao_pi_id (configuracao_pi_id),
    CONSTRAINT fk_sprints_configuracao_pi
        FOREIGN KEY (configuracao_pi_id) REFERENCES configuracao_pi (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. extracao_jira ----------------------------------------------------
CREATE TABLE IF NOT EXISTS extracao_jira (
    id_extracao            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    configuracao_pi_id     INT UNSIGNED NULL,
    tipo_item              VARCHAR(100) NULL,
    chave                  VARCHAR(50) NULL,
    sprint                 VARCHAR(100) NULL,
    projeto                VARCHAR(255) NULL,
    status                 VARCHAR(100) NULL,
    versoes_corrigidas     VARCHAR(255) NULL,
    story_points           DECIMAL(10,2) NULL,
    story_point_estimate   DECIMAL(10,2) NULL,
    soma_tempo_gasto       DECIMAL(14,2) NULL,
    categoria_status       VARCHAR(100) NULL,
    pai                    VARCHAR(50) NULL,
    alteracao_escopo       VARCHAR(255) NULL,
    resolucao              VARCHAR(255) NULL,
    tipo_sustentacao       VARCHAR(255) NULL,
    criado                 DATETIME NULL,
    ponto_compromisso      DATETIME NULL,
    inicio_fluxo           DATETIME NULL,
    fim_fluxo              DATETIME NULL,
    inicio_homologacao     DATETIME NULL,
    fim_homologacao        DATETIME NULL,
    inicio_implantacao     DATETIME NULL,
    fim_implantacao        DATETIME NULL,
    ambiente               VARCHAR(100) NULL,
    tempo_total_bloqueado  VARCHAR(100) NULL,
    data_carga             DATETIME NULL,
    ativo                  CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_extracao),
    KEY idx_extracao_jira_chave (chave),
    KEY idx_extracao_jira_configuracao_pi_id (configuracao_pi_id),
    KEY idx_extracao_jira_tipo_item (tipo_item),
    KEY idx_extracao_jira_status (status),
    CONSTRAINT fk_extracao_jira_configuracao_pi
        FOREIGN KEY (configuracao_pi_id) REFERENCES configuracao_pi (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. baseline ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS baseline (
    id_baseline            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    configuracao_pi_id     INT UNSIGNED NULL,
    tipo_item              VARCHAR(100) NULL,
    chave                  VARCHAR(50) NULL,
    resumo                 TEXT NULL,
    projeto                VARCHAR(255) NULL,
    status                 VARCHAR(100) NULL,
    versoes_corrigidas     VARCHAR(255) NULL,
    pai                    VARCHAR(50) NULL,
    story_point_estimate   DECIMAL(10,2) NULL,
    story_points           DECIMAL(10,2) NULL,
    sprint                 VARCHAR(100) NULL,
    tipo_sustentacao       VARCHAR(255) NULL,
    jornada_dona_epico     VARCHAR(255) NULL,
    data_carga             DATETIME NULL,
    ativo                  CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_baseline),
    KEY idx_baseline_chave (chave),
    KEY idx_baseline_configuracao_pi_id (configuracao_pi_id),
    CONSTRAINT fk_baseline_configuracao_pi
        FOREIGN KEY (configuracao_pi_id) REFERENCES configuracao_pi (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. extracao_agendamento ---------------------------------------------
CREATE TABLE IF NOT EXISTS extracao_agendamento (
    id_agendamento   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parametros_json  TEXT NOT NULL,
    arquivo_json     VARCHAR(500) NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'pendente',
    agendado_para    DATETIME NULL,
    criado_em        DATETIME NOT NULL,
    atualizado_em    DATETIME NULL,
    email_destino    VARCHAR(255) NULL,
    ativo            CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_agendamento),
    KEY idx_extracao_agendamento_status (status),
    KEY idx_extracao_agendamento_agendado_para (agendado_para)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. produtividade_historico -----------------------------------------
-- Consolida a produtividade (horas por ponto) por time e PI quando uma PI
-- (ou sprint) fecha. Preserva o histórico mesmo após o DELETE do dado bruto
-- da extração. A média consolidada ao fim da PI alimenta o cálculo de
-- velocidade da PI seguinte (times_composicao.media_produtividade_pi).
CREATE TABLE IF NOT EXISTS produtividade_historico (
    id_produtividade     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    times_id             INT UNSIGNED NOT NULL,
    configuracao_pi_id   INT UNSIGNED NOT NULL,
    produtividade        DECIMAL(10,2) NULL,  -- horas gastas / pontos entregues
    pontos_entregues     DECIMAL(10,2) NULL,
    horas_gastas         DECIMAL(14,2) NULL,
    data_consolidacao    DATETIME NULL,
    ativo                CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id_produtividade),
    KEY idx_produtividade_historico_times_id (times_id),
    KEY idx_produtividade_historico_configuracao_pi_id (configuracao_pi_id),
    CONSTRAINT fk_produtividade_historico_times
        FOREIGN KEY (times_id) REFERENCES times (id_time),
    CONSTRAINT fk_produtividade_historico_configuracao_pi
        FOREIGN KEY (configuracao_pi_id) REFERENCES configuracao_pi (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. sustentacao_historico -------------------------------------------
-- Histórico de pontos de sustentação por time, PI e sprint. Fonte: coluna
-- "Média dos Pts de Sustentação da PI passada" da aba 2026_Produtividade da
-- planilha Velocity. A média da PI anterior é descontada da velocity para
-- compor a capacity (ver vw_velocidade). times_id pode ser NULL (times sem
-- projeto_key ainda são identificados pelo nome no seed).
CREATE TABLE IF NOT EXISTS sustentacao_historico (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    times_id             INT UNSIGNED NULL,
    configuracao_pi_id   INT UNSIGNED NOT NULL,
    sprint_nome          VARCHAR(50) NULL,
    pts_sustentacao      DECIMAL(10,2) NULL,
    ativo                CHAR(1) NOT NULL DEFAULT 'S',
    PRIMARY KEY (id),
    KEY idx_sustentacao_historico_times_id (times_id),
    KEY idx_sustentacao_historico_configuracao_pi_id (configuracao_pi_id),
    CONSTRAINT fk_sustentacao_historico_times
        FOREIGN KEY (times_id) REFERENCES times (id_time),
    CONSTRAINT fk_sustentacao_historico_configuracao_pi
        FOREIGN KEY (configuracao_pi_id) REFERENCES configuracao_pi (id_versao_corrigida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. extracao_log -----------------------------------------------------
CREATE TABLE IF NOT EXISTS extracao_log (
    id_log              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agendamento_id      BIGINT UNSIGNED NULL,
    inicio              DATETIME NULL,
    fim                 DATETIME NULL,
    status              VARCHAR(20) NULL,
    total_itens         INT NULL,
    registros_apagados  INT NULL,
    mensagem            TEXT NULL,
    email_enviado       CHAR(1) NULL DEFAULT 'N',
    criado_em           DATETIME NOT NULL,
    PRIMARY KEY (id_log),
    KEY idx_extracao_log_agendamento_id (agendamento_id),
    CONSTRAINT fk_extracao_log_agendamento
        FOREIGN KEY (agendamento_id) REFERENCES extracao_agendamento (id_agendamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
