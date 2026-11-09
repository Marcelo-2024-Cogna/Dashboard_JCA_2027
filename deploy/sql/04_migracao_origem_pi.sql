-- =====================================================================
--  Produção: aplicar SOMENTE em bancos criados ANTES desta versão (quem
--  aplica o novo 01_schema.sql já tem a coluna). Idempotente.
--    mysql -u <dba> -p --default-character-set=utf8mb4 metricas_agil < deploy/sql/04_migracao_origem_pi.sql
--  Adiciona extracao_jira.origem_pi ('fixversion' | 'sprint'; NULL = carga
--  anterior ao fallback por sprint do job noturno).
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
