<?php

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * ExtractionQueueRepository
 * ─────────────────────────
 * Acesso a dados da FILA de extração (extracao_agendamento + extracao_log)
 * e às consultas de catálogo usadas para resolver as ordens.
 *
 * Todos os horários usam o relógio do MySQL (NOW()) para evitar divergência
 * de fuso entre PHP e banco. Prepared statements sempre.
 */
class ExtractionQueueRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    // ── Catálogo (times ativos com projeto_key) ──────────────────────────

    /**
     * Times ATIVOS com projeto_key, de jornadas ATIVAS (times sem
     * projeto_key são ignorados). Colunas: jornada, time, projeto_key.
     */
    public function timesAtivosComProjeto(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.nome AS jornada, t.nome AS `time`, t.projeto_key
               FROM times t
               JOIN jornada j ON j.id_jornada = t.jornada_id
              WHERE t.ativo = 'S' AND j.ativo = 'S'
                AND t.projeto_key IS NOT NULL AND t.projeto_key <> ''
           ORDER BY j.nome, t.nome"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Fila ─────────────────────────────────────────────────────────────

    /** Cria a ordem (pendente) e devolve o id. agendado_para = NOW()+delay. */
    public function criar(string $parametrosJson, string $hash, ?string $email, int $delayMinutes): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO extracao_agendamento
                (parametros_json, parametros_hash, status, agendado_para, criado_em, atualizado_em, email_destino, ativo)
             VALUES
                (:json, :hash, 'pendente', NOW() + INTERVAL :delay MINUTE, NOW(), NOW(), :email, 'S')"
        );
        $stmt->bindValue(':json', $parametrosJson);
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':delay', $delayMinutes, PDO::PARAM_INT);
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function definirArquivo(int $id, string $arquivoRelativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE extracao_agendamento SET arquivo_json = :a WHERE id_agendamento = :id');
        $stmt->execute([':a' => $arquivoRelativo, ':id' => $id]);
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM extracao_agendamento WHERE id_agendamento = :id');
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    /** Ordem (pendente/processando/concluida) criada HOJE com o mesmo hash. */
    public function buscarPorHashHoje(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_agendamento AS id, status, agendado_para, criado_em
               FROM extracao_agendamento
              WHERE parametros_hash = :hash
                AND DATE(criado_em) = CURDATE()
                AND status IN ('pendente', 'processando', 'concluido')
           ORDER BY id_agendamento DESC
              LIMIT 1"
        );
        $stmt->execute([':hash' => $hash]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    /**
     * Ordens pendentes vencidas, em ordem de agendamento.
     * $ignorarAtraso = true (teste) ignora agendado_para.
     * @return int[] ids
     */
    public function pendentesVencidas(bool $ignorarAtraso = false, ?int $somenteId = null): array
    {
        $sql = "SELECT id_agendamento FROM extracao_agendamento
                 WHERE status = 'pendente' AND ativo = 'S'";
        $params = [];
        if (!$ignorarAtraso) {
            $sql .= ' AND agendado_para <= NOW()';
        }
        if ($somenteId !== null) {
            $sql .= ' AND id_agendamento = :id';
            $params[':id'] = $somenteId;
        }
        $sql .= ' ORDER BY agendado_para, id_agendamento';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Claim ATÔMICO: só um processo vence. true se esta chamada obteve a ordem.
     */
    public function reivindicar(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE extracao_agendamento
                SET status = 'processando', atualizado_em = NOW()
              WHERE id_agendamento = :id AND status = 'pendente'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function atualizarStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE extracao_agendamento SET status = :s, atualizado_em = NOW() WHERE id_agendamento = :id'
        );
        $stmt->execute([':s' => $status, ':id' => $id]);
    }

    /**
     * Marca como 'erro' as ordens 'processando' há mais de $horas e registra
     * o log de timeout. Retorna os ids afetados.
     * @return int[]
     */
    public function marcarZumbis(int $horas): array
    {
        $sel = $this->pdo->prepare(
            "SELECT id_agendamento FROM extracao_agendamento
              WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL :h HOUR"
        );
        $sel->bindValue(':h', $horas, PDO::PARAM_INT);
        $sel->execute();
        $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

        foreach ($ids as $id) {
            $up = $this->pdo->prepare(
                "UPDATE extracao_agendamento SET status = 'erro', atualizado_em = NOW()
                  WHERE id_agendamento = :id AND status = 'processando'"
            );
            $up->execute([':id' => $id]);
            if ($up->rowCount() === 1) {
                $this->gravarLog($id, null, null, 'erro', 0, 0, "Timeout: ordem em 'processando' há mais de {$horas}h.", 'N');
            }
        }
        return $ids;
    }

    /**
     * Grava extracao_log. $inicio/$fim 'Y-m-d H:i:s' ou null (null = NOW()).
     */
    public function gravarLog(
        int $agendamentoId,
        ?string $inicio,
        ?string $fim,
        string $status,
        int $totalItens,
        int $registrosApagados,
        ?string $mensagem,
        string $emailEnviado
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO extracao_log
                (agendamento_id, inicio, fim, status, total_itens, registros_apagados, mensagem, email_enviado, criado_em)
             VALUES
                (:ag, COALESCE(:ini, NOW()), COALESCE(:fim, NOW()), :st, :tot, :apag, :msg, :em, NOW())'
        );
        $stmt->execute([
            ':ag' => $agendamentoId, ':ini' => $inicio, ':fim' => $fim, ':st' => $status,
            ':tot' => $totalItens, ':apag' => $registrosApagados, ':msg' => $mensagem, ':em' => $emailEnviado,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Últimas ordens com o resumo do último log (se houver). */
    public function listarFila(int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id_agendamento AS id, a.status, a.agendado_para, a.criado_em, a.atualizado_em,
                    a.email_destino, a.arquivo_json,
                    l.id_log, l.inicio AS log_inicio, l.fim AS log_fim, l.status AS log_status,
                    l.total_itens, l.registros_apagados, l.mensagem, l.email_enviado
               FROM extracao_agendamento a
          LEFT JOIN extracao_log l
                 ON l.id_log = (SELECT MAX(l2.id_log) FROM extracao_log l2 WHERE l2.agendamento_id = a.id_agendamento)
              ORDER BY a.id_agendamento DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
