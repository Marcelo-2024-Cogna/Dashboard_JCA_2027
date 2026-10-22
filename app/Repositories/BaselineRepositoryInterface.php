<?php

namespace App\Repositories;

use App\Models\BaselineSnapshot;
use App\Models\ProgressSnapshot;

/**
 * BaselineRepositoryInterface
 * ───────────────────────────
 * Contrato de persistência de snapshots.
 *
 * Trabalha com DOIS tipos de foto:
 *   - BASELINE (imutável): o retrato do início da PI. Uma por PI+time.
 *   - PROGRESS (acumulativo): um retrato por extração manual, formando a
 *     curva de evolução (burn-up) ao longo da PI.
 *
 * Hoje há implementação em JSON (JsonBaselineRepository).
 * No futuro, PdoBaselineRepository (MySQL/PostgreSQL) pode substituí-la
 * sem alterar Services/Controllers, trocando só a instanciação.
 */
interface BaselineRepositoryInterface
{
    // ── BASELINE (imutável — início da PI) ────────────────────────────────

    /**
     * Persiste um snapshot de baseline. Retorna um identificador
     * (nome de arquivo no JSON; id numérico no banco).
     */
    public function save(BaselineSnapshot $snapshot): string;

    /**
     * Indica se JÁ existe baseline para uma PI + Time.
     * Usado para impedir recaptura acidental (trava de imutabilidade).
     */
    public function baselineExists(string $piId, string $team): bool;

    /**
     * Carrega o snapshot de baseline de uma PI + Time.
     * Retorna o MAIS ANTIGO (o verdadeiro início da PI), mesmo que
     * existam duplicados. Null se não houver.
     */
    public function findBaseline(string $piId, string $team): ?BaselineSnapshot;

    /**
     * Lista a baseline (uma por time) de uma PI. Em caso de duplicados
     * por time, retorna o mais antigo de cada time.
     * @return BaselineSnapshot[]
     */
    public function findByPi(string $piId): array;

    /**
     * Lista todas as baselines armazenadas (metadados).
     * @return BaselineSnapshot[]
     */
    public function all(): array;

    // ── PROGRESS (acumulativo — evolução da PI) ───────────────────────────

    /**
     * Persiste um snapshot de progresso (estado atual numa extração).
     * Acumula — nunca sobrescreve. Retorna o identificador.
     */
    public function saveProgress(ProgressSnapshot $snapshot): string;

    /**
     * Lista os snapshots de progresso de uma PI + Time, em ordem
     * cronológica, para montar a curva de evolução.
     * @return ProgressSnapshot[]
     */
    public function findProgress(string $piId, string $team): array;

    /**
     * Lista os snapshots de progresso de toda a PI (todos os times).
     * @return ProgressSnapshot[]
     */
    public function findProgressByPi(string $piId): array;
}
