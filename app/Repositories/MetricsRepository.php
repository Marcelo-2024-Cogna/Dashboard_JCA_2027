<?php

namespace App\Repositories;

use App\Database;
use PDO;

/**
 * MetricsRepository
 * ─────────────────
 * Repositório SOMENTE LEITURA das métricas do dashboard.
 *
 * Consulta exclusivamente as VIEWS do banco (vw_velocidade, vw_cycle_time,
 * vw_previsibilidade, vw_produtividade) — nunca grava. A escrita/ingestão
 * dos dados brutos (baseline, composição de times, histórico de
 * produtividade) é feita por OUTROS componentes (seed de catálogo e os
 * futuros importadores do Motor de Ingestão — ver data/import/README.md).
 *
 * Todos os métodos aceitam filtros OPCIONAIS por jornada e/ou time
 * (parâmetros nullable). Quando informados, viram WHERE jornada=:j e/ou
 * time=:t. O resultado vem ordenado por jornada, time. Prepared statements
 * sempre; cada método devolve um array de arrays associativos (fetchAll).
 */
class MetricsRepository
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
     * Velocidade/capacidade por time (vw_velocidade).
     * Colunas: jornada, time, qtde_dev, horas_dev, dias_uteis_pi,
     *          capacidade_horas, capacidade_pontos.
     */
    public function velocidade(?string $jornada = null, ?string $time = null): array
    {
        return $this->selectView('vw_velocidade', $jornada, $time);
    }

    /**
     * Cycle time médio por fase (vw_cycle_time).
     * Colunas: jornada, time, media_ideacao, media_desenvolvimento,
     *          media_homologacao, media_implantacao, qtd_itens.
     */
    public function cycleTime(?string $jornada = null, ?string $time = null): array
    {
        return $this->selectView('vw_cycle_time', $jornada, $time);
    }

    /**
     * Previsibilidade (entregue x baseline) por time (vw_previsibilidade).
     * Colunas: jornada, time, sp_entregue, sp_baseline, previsibilidade_pct.
     */
    public function previsibilidade(?string $jornada = null, ?string $time = null): array
    {
        return $this->selectView('vw_previsibilidade', $jornada, $time);
    }

    /**
     * Produtividade (pontos x horas) por time (vw_produtividade).
     * Colunas: jornada, time, pontos_entregues, horas_gastas, produtividade.
     */
    public function produtividade(?string $jornada = null, ?string $time = null): array
    {
        return $this->selectView('vw_produtividade', $jornada, $time);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helper interno
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * SELECT * da view informada, com filtros opcionais por jornada/time,
     * ordenado por jornada, time. O nome da view é controlado internamente
     * (constante de cada método), nunca vem do usuário — os valores de
     * filtro vão sempre por prepared statement.
     */
    private function selectView(string $view, ?string $jornada, ?string $time): array
    {
        $sql    = "SELECT * FROM {$view}";
        $where  = [];
        $params = [];

        if ($jornada !== null) {
            $where[]        = 'jornada = :jornada';
            $params[':jornada'] = $jornada;
        }
        if ($time !== null) {
            $where[]      = 'time = :time';
            $params[':time'] = $time;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY jornada, time';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
