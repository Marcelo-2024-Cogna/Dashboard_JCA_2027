-- =====================================================================
--  Migração idempotente: extracao_jira.origem_pi  (job noturno / fallback de PI)
--  Valores: 'fixversion' (item da busca principal) | 'sprint' (trazido pelo
--  fallback por sprint). Linhas antigas ficam NULL (carga anterior ao
--  fallback); a próxima extração regrava a PI inteira com a origem.
--  MySQL 8.0 não tem ADD COLUMN IF NOT EXISTS: checa information_schema.
--  Pode ser reaplicada quantas vezes for preciso.
--    mysql -u <dba> -p --default-character-set=utf8mb4 <banco> < este_arquivo.sql
-- =====================================================================
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'extracao_jira'
                AND COLUMN_NAME = 'origem_pi');
SET @sql := IF(@col = 0,
    'ALTER TABLE extracao_jira ADD COLUMN origem_pi VARCHAR(10) NULL AFTER ativo',
    'SELECT 1');
PREPARE stmt_mig FROM @sql;
EXECUTE stmt_mig;
DEALLOCATE PREPARE stmt_mig;
