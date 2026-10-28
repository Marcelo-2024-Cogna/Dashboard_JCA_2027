<?php

namespace App\Services;

require_once __DIR__ . '/SprintRef.php';

use App\Database;
use App\Models\JiraModel;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ExtractionService
 * ─────────────────
 * Serviço reutilizável de extração Jira → MySQL (v1.5, ETAPA 6b-1).
 * Encapsula a lógica antes embutida em bin/extract_runner.php:
 *   1. monta a JQL do recorte (JqlBuilder::forSelection);
 *   2. obtém os itens (Jira real via JiraModel::fetchAll, ou fixture);
 *   3. mapeia os campos e persiste: DELETE do recorte + INSERT em transação.
 *
 * Usado pelo runner CLI (bin/extract_runner.php) e pelo worker da fila
 * (bin/queue_worker.php).
 *
 * soma_tempo_gasto é gravado em SEGUNDOS (a view divide por 3600).
 * O token do Jira nunca é impresso/registrado.
 */
class ExtractionService
{
    /** Código da exceção quando a busca (Jira/fixture) falha. */
    public const ERR_FETCH = 10;
    /** Código da exceção quando a gravação falha (rollback executado). */
    public const ERR_PERSIST = 20;

    private PDO $pdo;
    private array $jiraCfg;
    private array $baselineCfg;

    public function __construct(?PDO $pdo = null, ?array $jiraCfg = null, ?array $baselineCfg = null)
    {
        $root = dirname(__DIR__, 2);
        $this->pdo         = $pdo ?? Database::pdo();
        $this->jiraCfg     = $jiraCfg ?? require $root . '/config/jira.php';
        // Carregado dentro de método/construtor: o baseline.php atribui $pi
        // localmente, sem poluir o escopo global do chamador.
        $this->baselineCfg = $baselineCfg ?? require $root . '/config/baseline.php';
    }

    /** JQL do recorte (útil para o chamador exibir antes de extrair). */
    public function buildJql(array $projetos, string $pi, ?array $sprints): string
    {
        $sprints = ($sprints === null || $sprints === []) ? null : $sprints;
        return (new JqlBuilder($this->baselineCfg))->forSelection($projetos, $pi, $sprints);
    }

    /**
     * Executa a extração de um recorte.
     *
     * @param string[]      $projetos projeto_key(s)
     * @param string        $pi       rótulo da PI (ex 'PI 4/26')
     * @param string[]|null $sprints  sprints (null/[] = todas)
     * @param string        $fonte    'jira' | 'fixture'
     * @param bool          $baseline true = grava em baseline
     * @param string|null   $fixture  caminho da fixture (default data/import/fixtures/jira_sample.json)
     * @return array{total_itens:int,registros_apagados:int,jql:string,pi_id:?int,
     *               itens_lidos:int,paginas:?int,logs:string[],tabela:string,data_carga:string}
     * @throws RuntimeException code ERR_FETCH (busca) ou ERR_PERSIST (gravação)
     */
    public function extract(
        array $projetos,
        string $pi,
        ?array $sprints = null,
        string $fonte = 'jira',
        bool $baseline = false,
        ?string $fixture = null
    ): array {
        $tabela = $baseline ? 'baseline' : 'extracao_jira';
        $jql    = $this->buildJql($projetos, $pi, $sprints);
        $model  = new JiraModel($this->jiraCfg);
        $paginas = null;
        $logs    = [];
        $ctxLog  = ['projetos' => $projetos, 'pi' => $pi, 'sprints' => $sprints ?: [], 'fonte' => $fonte, 'tabela' => $tabela];

        Logger::info(Logger::CANAL_EXTRACAO, 'Lote: início da extração', $ctxLog + ['jql' => $jql]);

        // ── Obtém os itens ───────────────────────────────────────────────
        if ($fonte === 'fixture') {
            $fixture = $fixture ?? dirname(__DIR__, 2) . '/data/import/fixtures/jira_sample.json';
            if (!is_file($fixture)) {
                Logger::error(Logger::CANAL_EXTRACAO, 'Lote: fixture não encontrada', $ctxLog + ['fixture' => basename($fixture)]);
                throw new RuntimeException("Fixture não encontrada: {$fixture}", self::ERR_FETCH);
            }
            $raw = json_decode((string)file_get_contents($fixture), true);
            if (!is_array($raw) || !isset($raw['issues']) || !is_array($raw['issues'])) {
                Logger::error(Logger::CANAL_EXTRACAO, 'Lote: fixture com JSON inválido', $ctxLog + ['fixture' => basename($fixture)]);
                throw new RuntimeException("Fixture inválida (esperado objeto com chave 'issues').", self::ERR_FETCH);
            }
            $items  = $model->mapRawIssues($raw['issues']);
            $logs[] = count($items) . ' itens lidos de ' . basename($fixture);
        } else {
            try {
                $result  = $model->fetchAll($jql);
                $items   = $result['items'];
                $paginas = (int)$result['pages'];
                $logs    = $result['logs'] ?? [];
            } catch (Throwable $e) {
                // A mensagem do JiraModel não contém o token (e o Logger ainda mascara).
                Logger::exception(Logger::CANAL_EXTRACAO, 'Lote: falha ao buscar no Jira (HTTP/cURL/SSL/JSON)', $e, $ctxLog + ['jql' => $jql]);
                throw new RuntimeException($e->getMessage(), self::ERR_FETCH, $e);
            }
        }

        // ── configuracao_pi_id ───────────────────────────────────────────
        $stmt = $this->pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :pi LIMIT 1');
        $stmt->execute([':pi' => $pi]);
        $piId = $stmt->fetchColumn();
        $piId = $piId === false ? null : (int)$piId;

        // Projetos (display-name) presentes no lote — usados no DELETE.
        $projetosNoLote = [];
        foreach ($items as $it) {
            $p = self::strOrNull($it['projeto'] ?? null);
            if ($p !== null) $projetosNoLote[$p] = true;
        }
        $projetosNoLote = array_keys($projetosNoLote);

        // ── Transação: DELETE do recorte + INSERT ────────────────────────
        $agora     = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $apagados  = 0;
        $inseridos = 0;
        $pdo       = $this->pdo;

        try {
            $pdo->beginTransaction();

            if ($projetosNoLote !== []) {
                $place = implode(', ', array_fill(0, count($projetosNoLote), '?'));
                if ($piId !== null) {
                    $sqlDel    = "DELETE FROM {$tabela} WHERE configuracao_pi_id = ? AND projeto IN ({$place})";
                    $paramsDel = array_merge([$piId], $projetosNoLote);
                } else {
                    $sqlDel    = "DELETE FROM {$tabela} WHERE configuracao_pi_id IS NULL AND projeto IN ({$place})";
                    $paramsDel = $projetosNoLote;
                }
                $del = $pdo->prepare($sqlDel);
                $del->execute($paramsDel);
                $apagados = $del->rowCount();
            }

            if ($baseline) {
                $ins = $pdo->prepare(
                    'INSERT INTO baseline
                        (configuracao_pi_id, tipo_item, chave, resumo, projeto, status,
                         versoes_corrigidas, pai, story_point_estimate, story_points,
                         sprint, sprint_ref, tipo_sustentacao, jornada_dona_epico, data_carga, ativo)
                     VALUES
                        (:pi, :tipo_item, :chave, :resumo, :projeto, :status,
                         :versoes, :pai, :spe, :sp,
                         :sprint, :sprint_ref, :tipo_sust, :jornada, :carga, :ativo)'
                );
                foreach ($items as $it) {
                    $ins->execute([
                        ':pi'        => $piId,
                        ':tipo_item' => self::strOrNull($it['tipo_item'] ?? null),
                        ':chave'     => self::strOrNull($it['chave'] ?? null),
                        ':resumo'    => self::strOrNull($it['resumo'] ?? null),
                        ':projeto'   => self::strOrNull($it['projeto'] ?? null),
                        ':status'    => self::strOrNull($it['status'] ?? null),
                        ':versoes'   => self::strOrNull($it['versoes_corrigidas'] ?? null),
                        ':pai'       => self::strOrNull($it['pai'] ?? null),
                        ':spe'       => self::numOrNull($it['story_point_estimate'] ?? null),
                        ':sp'        => self::numOrNull($it['story_points'] ?? null),
                        ':sprint'    => self::strOrNull($it['sprint'] ?? null),
                        ':sprint_ref' => SprintRef::fromText(self::strOrNull($it['sprint'] ?? null)),
                        ':tipo_sust' => self::strOrNull($it['tipo_sustentacao'] ?? null),
                        ':jornada'   => null,
                        ':carga'     => $agora,
                        ':ativo'     => 'S',
                    ]);
                    $inseridos++;
                }
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO extracao_jira
                        (configuracao_pi_id, tipo_item, chave, sprint, sprint_ref, projeto, status,
                         versoes_corrigidas, story_points, story_point_estimate,
                         soma_tempo_gasto, categoria_status, pai, alteracao_escopo,
                         resolucao, tipo_sustentacao, criado, ponto_compromisso,
                         inicio_fluxo, fim_fluxo, inicio_homologacao, fim_homologacao,
                         inicio_implantacao, fim_implantacao, ambiente,
                         tempo_total_bloqueado, data_carga, ativo)
                     VALUES
                        (:pi, :tipo_item, :chave, :sprint, :sprint_ref, :projeto, :status,
                         :versoes, :sp, :spe,
                         :soma_seg, :cat, :pai, :alt_escopo,
                         :resolucao, :tipo_sust, :criado, :ponto_comp,
                         :ini_fluxo, :fim_fluxo, :ini_homo, :fim_homo,
                         :ini_impl, :fim_impl, :ambiente,
                         :bloqueado, :carga, :ativo)'
                );
                foreach ($items as $it) {
                    $ins->execute([
                        ':pi'         => $piId,
                        ':tipo_item'  => self::strOrNull($it['tipo_item'] ?? null),
                        ':chave'      => self::strOrNull($it['chave'] ?? null),
                        ':sprint'     => self::strOrNull($it['sprint'] ?? null),
                        ':sprint_ref' => SprintRef::fromText(self::strOrNull($it['sprint'] ?? null)),
                        ':projeto'    => self::strOrNull($it['projeto'] ?? null),
                        ':status'     => self::strOrNull($it['status'] ?? null),
                        ':versoes'    => self::strOrNull($it['versoes_corrigidas'] ?? null),
                        ':sp'         => self::numOrNull($it['story_points'] ?? null),
                        ':spe'        => self::numOrNull($it['story_point_estimate'] ?? null),
                        ':soma_seg'   => $it['tempo_gasto_segundos'] ?? null,
                        ':cat'        => self::strOrNull($it['categoria_status'] ?? null),
                        ':pai'        => self::strOrNull($it['pai'] ?? null),
                        ':alt_escopo' => self::strOrNull($it['alteracao_escopo'] ?? null),
                        ':resolucao'  => self::strOrNull($it['resolucao'] ?? null),
                        ':tipo_sust'  => self::strOrNull($it['tipo_sustentacao'] ?? null),
                        ':criado'     => self::isoToDatetime($it['criado'] ?? null),
                        ':ponto_comp' => self::isoToDatetime($it['ponto_compromisso'] ?? null),
                        ':ini_fluxo'  => self::isoToDatetime($it['inicio_fluxo'] ?? null),
                        ':fim_fluxo'  => self::isoToDatetime($it['fim_fluxo'] ?? null),
                        ':ini_homo'   => self::isoToDatetime($it['inicio_homologacao'] ?? null),
                        ':fim_homo'   => self::isoToDatetime($it['fim_homologacao'] ?? null),
                        ':ini_impl'   => self::isoToDatetime($it['inicio_implantacao'] ?? null),
                        ':fim_impl'   => self::isoToDatetime($it['fim_implantacao'] ?? null),
                        ':ambiente'   => self::strOrNull($it['ambiente'] ?? null),
                        ':bloqueado'  => self::strOrNull($it['tempo_total_bloqueado'] ?? null),
                        ':carga'      => $agora,
                        ':ativo'      => 'S',
                    ]);
                    $inseridos++;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::exception(Logger::CANAL_EXTRACAO, 'Lote: erro de banco na gravação, rollback executado', $e, $ctxLog);
            throw new RuntimeException('Erro na gravação, rollback executado: ' . $e->getMessage(), self::ERR_PERSIST, $e);
        }

        Logger::info(Logger::CANAL_EXTRACAO, 'Lote: extração concluída', $ctxLog + [
            'itens_lidos' => count($items), 'inseridos' => $inseridos,
            'apagados' => $apagados, 'paginas' => $paginas, 'pi_id' => $piId,
        ]);

        return [
            'total_itens'        => $inseridos,
            'registros_apagados' => $apagados,
            'jql'                => $jql,
            'pi_id'              => $piId,
            'itens_lidos'        => count($items),
            'paginas'            => $paginas,
            'logs'               => $logs,
            'tabela'             => $tabela,
            'data_carga'         => $agora,
        ];
    }

    // ── Helpers de conversão ─────────────────────────────────────────────

    /** ISO 8601 → 'Y-m-d H:i:s'; vazio/erro → null. */
    private static function isoToDatetime(mixed $v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) return null;
        try {
            return (new DateTimeImmutable((string)$v))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function numOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    private static function strOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = is_array($v) ? implode('; ', array_filter(array_map('strval', $v))) : (string)$v;
        return trim($s) === '' ? null : $s;
    }
}
