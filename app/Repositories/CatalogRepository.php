<?php

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * CatalogRepository
 * ─────────────────
 * Repositório SOMENTE LEITURA do catálogo que alimenta os filtros em
 * cascata do dashboard (jornada → time → PI → sprint).
 *
 * Lê das views de configuração e das tabelas de catálogo — nunca grava.
 * A carga do catálogo é feita pelo seed (bin/seed_catalogo.php) e, no
 * futuro, pelos importadores do Motor de Ingestão (ver
 * data/import/README.md). Este repositório só consulta.
 *
 * Prepared statements sempre; cada método devolve arrays associativos.
 */
class CatalogRepository
{
    private PDO $pdo;

    /**
     * Recebe a conexão PDO por injeção (facilita testes). Se nenhuma for
     * passada, usa a conexão única de App\Database::pdo().
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * Jornadas ATIVAS (jornada.ativo = 'S'), ordenadas por nome.
     * Primeiro nível do filtro em cascata.
     */
    public function jornadas(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_jornada, nome, ativo
               FROM jornada
              WHERE ativo = 'S'
           ORDER BY nome"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Times do catálogo (vw_config_time), ordenados por jornada, time.
     * Colunas: jornada, time, projeto_key, qtde_dev, horas_dev,
     *          media_produtividade_pi.
     */
    public function times(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vw_config_time ORDER BY jornada, time'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Sprints por PI (vw_config_sprints_por_pi), com filtro OPCIONAL por
     * desc_pi. Ordenado por desc_pi, sprint.
     * Colunas: desc_pi, sprint, tipo, dias_uteis, data_ini, data_fim, ativo.
     */
    public function sprintsPorPi(?string $descPi = null): array
    {
        $sql    = 'SELECT * FROM vw_config_sprints_por_pi';
        $params = [];
        if ($descPi !== null) {
            $sql           .= ' WHERE desc_pi = :desc_pi';
            $params[':desc_pi'] = $descPi;
        }
        $sql .= ' ORDER BY desc_pi, sprint';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Times ATIVOS de jornadas ATIVAS que têm projeto_key (não exige
     * composição, ao contrário de vw_config_time). Alimenta os filtros do
     * header. Colunas: jornada, time, projeto_key.
     */
    public function timesAtivos(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.nome AS jornada, t.nome AS time, t.projeto_key
               FROM times t
               JOIN jornada j ON j.id_jornada = t.jornada_id
              WHERE t.ativo = 'S' AND j.ativo = 'S'
                AND t.projeto_key IS NOT NULL AND t.projeto_key <> ''
           ORDER BY j.nome, t.nome"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * PIs ATIVAS (configuracao_pi.ativo = 'S'), ordenadas por desc_pi.
     * Alimenta o seletor de PI do dashboard.
     */
    public function pis(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_versao_corrigida, desc_pi, qtde_sprints, data_ini, data_fim, ativo
               FROM configuracao_pi
              WHERE ativo = 'S'
           ORDER BY desc_pi"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
