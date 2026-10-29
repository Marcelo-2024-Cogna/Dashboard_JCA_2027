-- =====================================================================
-- Metricas Jeito Cogna Agil - TRIGGERS v1.5 (auditoria da baseline)
-- SGBD: MySQL 8.x (portavel para CentOS/Linux em producao)
-- Idempotente: DROP TRIGGER IF EXISTS + CREATE TRIGGER.
--
-- PRIVILEGIO: com log_bin ligado, criar trigger exige SUPER (ou
-- log_bin_trust_function_creators=1) alem do privilegio TRIGGER (erro 1419
-- para o usuario de aplicacao). Aplique este arquivo com uma CONTA
-- PRIVILEGIADA (root/DBA), nunca com metricas_jca. O app NAO recebe o
-- privilegio TRIGGER (nao precisa dele em runtime e, sem ele, nao consegue
-- remover o trigger de auditoria).
--
-- FORMATO: cada comando termina com ';' no FIM DA LINHA e nao ha ';' dentro
-- do corpo do trigger (corpo = um unico INSERT ... SELECT), entao o arquivo
-- pode ser aplicado tanto pelo cliente mysql
--     mysql -u root -p metricas_agil < db/triggers.sql
-- quanto por PDO::exec, um comando por vez (dividir em ';' + quebra de linha).
--
-- COMPORTAMENTO: DETECTA, nao bloqueia. Todo UPDATE em baseline que altere
-- uma coluna auditada grava uma linha por coluna em baseline_ajuste.
--   @ajuste_motivo / @ajuste_usuario (variaveis de sessao) preenchidas
--   -> origem 'formal'; ausentes -> origem 'sql_direto' (usuario = USER()).
-- Colunas auditadas: story_point_estimate, story_points, sprint, sprint_ref,
-- tipo_item, status, pai, projeto, ativo. DELETE/INSERT nao sao auditados
-- (reimportacao e um evento da carga, registrado no log 'importacao').
-- =====================================================================

DROP TRIGGER IF EXISTS trg_baseline_audit_upd;

CREATE TRIGGER trg_baseline_audit_upd AFTER UPDATE ON baseline FOR EACH ROW
INSERT INTO baseline_ajuste
    (baseline_id, configuracao_pi_id, chave, campo, valor_anterior, valor_novo, motivo, usuario, origem, criado_em)
SELECT NEW.id_baseline, NEW.configuracao_pi_id, NEW.chave, a.campo, a.anterior, a.novo,
       @ajuste_motivo, COALESCE(@ajuste_usuario, USER()),
       IF(@ajuste_motivo IS NULL, 'sql_direto', 'formal'), NOW()
  FROM (
    SELECT 'story_point_estimate' AS campo, CAST(OLD.story_point_estimate AS CHAR) AS anterior, CAST(NEW.story_point_estimate AS CHAR) AS novo
      FROM DUAL WHERE NOT (OLD.story_point_estimate <=> NEW.story_point_estimate)
    UNION ALL
    SELECT 'story_points', CAST(OLD.story_points AS CHAR), CAST(NEW.story_points AS CHAR)
      FROM DUAL WHERE NOT (OLD.story_points <=> NEW.story_points)
    UNION ALL
    SELECT 'sprint', CAST(OLD.sprint AS CHAR), CAST(NEW.sprint AS CHAR)
      FROM DUAL WHERE NOT (OLD.sprint <=> NEW.sprint)
    UNION ALL
    SELECT 'sprint_ref', CAST(OLD.sprint_ref AS CHAR), CAST(NEW.sprint_ref AS CHAR)
      FROM DUAL WHERE NOT (OLD.sprint_ref <=> NEW.sprint_ref)
    UNION ALL
    SELECT 'tipo_item', CAST(OLD.tipo_item AS CHAR), CAST(NEW.tipo_item AS CHAR)
      FROM DUAL WHERE NOT (OLD.tipo_item <=> NEW.tipo_item)
    UNION ALL
    SELECT 'status', CAST(OLD.status AS CHAR), CAST(NEW.status AS CHAR)
      FROM DUAL WHERE NOT (OLD.status <=> NEW.status)
    UNION ALL
    SELECT 'pai', CAST(OLD.pai AS CHAR), CAST(NEW.pai AS CHAR)
      FROM DUAL WHERE NOT (OLD.pai <=> NEW.pai)
    UNION ALL
    SELECT 'projeto', CAST(OLD.projeto AS CHAR), CAST(NEW.projeto AS CHAR)
      FROM DUAL WHERE NOT (OLD.projeto <=> NEW.projeto)
    UNION ALL
    SELECT 'ativo', CAST(OLD.ativo AS CHAR), CAST(NEW.ativo AS CHAR)
      FROM DUAL WHERE NOT (OLD.ativo <=> NEW.ativo)
  ) a;
