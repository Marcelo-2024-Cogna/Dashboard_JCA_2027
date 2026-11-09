<?php

namespace App\Services;

require_once __DIR__ . '/SprintRef.php';

use App\Database;
use App\Models\JiraModel;
use App\Srv\srvLock;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ExtractionService
 * ─────────────────
 * Serviço reutilizável de extração Jira → MySQL (v1.5, ETAPA 6b-1; endurecido
 * para o job noturno). Encapsula:
 *   1. montagem da JQL do recorte (JqlBuilder::forSelection);
 *   2. obtenção dos itens (Jira real via JiraModel::fetchAll, ou fixture);
 *   3. FALLBACK de PI por sprint (itens das sprints da PI sem fixVersion ou
 *      com fixVersion de outra PI), marcado em extracao_jira.origem_pi;
 *   4. persistência: limpeza por PREFIXO DE CHAVE + INSERT numa única
 *      transação, DEPOIS de buscar tudo em memória (falha de busca nunca
 *      apaga nada).
 *
 * Usado por bin/extract_runner.php, pelo worker da fila (bin/queue_worker.php
 * via srvProcessadorFila) e pelo job noturno (bin/extract_diaria.php).
 *
 * Proteções (jql-aderencia.md, Parte B):
 *   R1  aborta (ERR_PARCIAL) se o Jira devolver 0 ou < limiar do que já existe;
 *   R2/R3 limpeza por PI + prefixo de chave dos projetos SOLICITADOS;
 *   R5  lock por PI (serializa modal/worker/job noturno);
 *   R6  retry com backoff no JiraModel.
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
    /** Código da exceção quando o lock da PI não foi obtido a tempo. */
    public const ERR_LOCK = 30;
    /** Código da exceção quando R1 abortou (resposta parcial/vazia). */
    public const ERR_PARCIAL = 40;
    /** Código da exceção quando veio filtro de sprint (não suportado). */
    public const ERR_SPRINT_FILTRO = 50;
    /** Código da exceção para argumentos inválidos (ex.: chave de projeto). */
    public const ERR_ARGS = 60;

    /** Itens por DELETE (quantidade de prefixos OR-ados). */
    private const DELETE_BLOCO = 50;

    private PDO $pdo;
    private array $jiraCfg;
    private array $baselineCfg;
    private array $extCfg;
    /** @var object|null cliente Jira injetável (fetchAll + mapRawIssues) */
    private $jiraClient;

    /**
     * @param object|null $jiraClient objeto com fetchAll(string,int):array e
     *                                mapRawIssues(array):array (padrão: JiraModel)
     */
    public function __construct(
        ?PDO $pdo = null,
        ?array $jiraCfg = null,
        ?array $baselineCfg = null,
        $jiraClient = null,
        ?array $extCfg = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->pdo         = $pdo ?? Database::pdo();
        $this->jiraCfg     = $jiraCfg ?? require $root . '/config/jira.php';
        // Carregado dentro de método/construtor: o baseline.php atribui $pi
        // localmente, sem poluir o escopo global do chamador.
        $this->baselineCfg = $baselineCfg ?? require $root . '/config/baseline.php';
        $this->extCfg      = $extCfg ?? require $root . '/config/extracao.php';
        $this->jiraClient  = $jiraClient;

        if (!isset($this->jiraCfg['retry']) && isset($this->extCfg['retry'])) {
            $this->jiraCfg['retry'] = $this->extCfg['retry'];
        }
    }

    /** JQL do recorte (útil para o chamador exibir antes de extrair). */
    public function buildJql(array $projetos, string $pi, ?array $sprints): string
    {
        $sprints = ($sprints === null || $sprints === []) ? null : $sprints;
        return (new JqlBuilder($this->baselineCfg))->forSelection($projetos, $pi, $sprints);
    }

    /** JQL do fallback por sprint (sprints da PI). */
    public function buildFallbackJql(array $projetos, string $pi, array $sprintsPi): string
    {
        return (new JqlBuilder($this->baselineCfg))->forFallbackSprints($projetos, $pi, $sprintsPi);
    }

    /**
     * Sprints (nomes) cadastradas para a PI: todas as ativas (valor e
     * sustentação; ambas pertencem ao calendário da PI). [] se a PI não existe.
     * @return string[]
     */
    public function sprintsDaPi(string $pi): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.nome FROM sprints s
               JOIN configuracao_pi c ON c.id_versao_corrigida = s.configuracao_pi_id
              WHERE c.desc_pi = :pi AND s.ativo = 'S'
           ORDER BY s.nome"
        );
        $stmt->execute([':pi' => $pi]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Executa a extração de um recorte.
     *
     * A extração é SEMPRE da PI inteira: $sprints preenchido é recusado
     * (ERR_SPRINT_FILTRO). A limpeza é por PI + prefixo de chave, então um
     * filtro de sprint apagaria sprints da PI que não voltariam. A extração
     * por sprint volta na v2.0, somente com um DELETE que respeite a sprint.
     *
     * @param string[]      $projetos projeto_key(s)
     * @param string        $pi       rótulo da PI (ex 'PI 4/26')
     * @param string[]|null $sprints  deve ser null/[] (ver acima)
     * @param string        $fonte    'jira' | 'fixture'
     * @param bool          $baseline true = grava em baseline
     * @param string|null   $fixture  caminho da fixture (default data/import/fixtures/jira_sample.json)
     * @param array         $opcoes   forcar(bool) relaxa R1; fallback(bool) liga/desliga o fallback por sprint
     * @return array{total_itens:int,registros_apagados:int,jql:string,pi_id:?int,
     *               itens_lidos:int,paginas:?int,logs:string[],tabela:string,data_carga:string,
     *               itens_principal:int,itens_fallback:int,fallback_dup:int,fora_do_lote:int,
     *               jql_fallback:?string,fallback_por_projeto:array,fallback_versoes:array,
     *               existentes_antes:int}
     * @throws RuntimeException code ERR_* (ver constantes)
     */
    public function extract(
        array $projetos,
        string $pi,
        ?array $sprints = null,
        string $fonte = 'jira',
        bool $baseline = false,
        ?string $fixture = null,
        array $opcoes = []
    ): array {
        if ($sprints !== null && $sprints !== []) {
            Logger::warning(Logger::CANAL_EXTRACAO, 'Lote: filtro de sprint recusado (extração é sempre da PI inteira)', ['sprints' => $sprints]);
            throw new RuntimeException(
                'Filtro de sprint não suportado: a extração é sempre da PI inteira '
                . '(a limpeza é por PI e apagaria sprints que não voltariam).',
                self::ERR_SPRINT_FILTRO
            );
        }
        $projetos = array_values(array_unique(array_map('strval', $projetos)));
        if ($projetos === []) {
            throw new RuntimeException('Nenhum projeto informado.', self::ERR_ARGS);
        }
        foreach ($projetos as $p) {
            self::likePrefixo($p); // valida o formato da chave (lança ERR_ARGS)
        }
        if (!in_array($fonte, ['jira', 'fixture'], true)) {
            throw new RuntimeException("Fonte inválida: {$fonte}", self::ERR_ARGS);
        }

        $piId = $this->resolverPiId($pi);

        // R5: serializa extrações da mesma PI (modal/worker x job noturno).
        $lock = null;
        if (!$baseline) {
            $lock = new srvLock($this->lockDir() . '/extract_pi_' . ($piId === null ? 'null' : (string)$piId) . '.lock');
            $espera = (int)($this->extCfg['lock_espera_s'] ?? 600);
            if (!$lock->esperar($espera)) {
                Logger::error(Logger::CANAL_EXTRACAO, 'Lote: lock da PI não obtido a tempo', ['pi' => $pi, 'espera_s' => $espera]);
                throw new RuntimeException("Outra extração da {$pi} está em andamento (lock não obtido em {$espera}s).", self::ERR_LOCK);
            }
        }

        try {
            return $this->executar($projetos, $pi, $piId, $fonte, $baseline, $fixture, $opcoes);
        } finally {
            if ($lock !== null) $lock->liberar();
        }
    }

    private function executar(
        array $projetos,
        string $pi,
        ?int $piId,
        string $fonte,
        bool $baseline,
        ?string $fixture,
        array $opcoes
    ): array {
        $tabela  = $baseline ? 'baseline' : 'extracao_jira';
        $forcar  = !empty($opcoes['forcar']);
        $jql     = $this->buildJql($projetos, $pi, null);
        $model   = $this->jiraClient ?? new JiraModel($this->jiraCfg);
        $paginas = null;
        $logs    = [];
        $ctxLog  = ['projetos' => $projetos, 'pi' => $pi, 'fonte' => $fonte, 'tabela' => $tabela];

        $usarFallback = !$baseline && $fonte === 'jira'
            && (array_key_exists('fallback', $opcoes) ? (bool)$opcoes['fallback'] : (bool)($this->extCfg['fallback_sprints'] ?? true));
        $jqlFallback = null;
        $itensFallbackBrutos = [];

        Logger::info(Logger::CANAL_EXTRACAO, 'Lote: início da extração', $ctxLog + ['jql' => $jql, 'fallback' => $usarFallback]);

        // ── Obtém os itens (TUDO em memória antes de tocar no banco) ─────
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
            unset($raw);
            $logs[] = count($items) . ' itens lidos de ' . basename($fixture);
        } else {
            try {
                $result  = $model->fetchAll($jql);
                $items   = $result['items'];
                $paginas = (int)$result['pages'];
                $logs    = $result['logs'] ?? [];
                unset($result);
            } catch (Throwable $e) {
                // A mensagem do JiraModel não contém o token (e o Logger ainda mascara).
                Logger::exception(Logger::CANAL_EXTRACAO, 'Lote: falha ao buscar no Jira (HTTP/cURL/SSL/JSON)', $e, $ctxLog + ['jql' => $jql]);
                throw new RuntimeException($e->getMessage(), self::ERR_FETCH, $e);
            }

            if ($usarFallback) {
                $sprintsPi = $this->sprintsDaPi($pi);
                if ($sprintsPi === []) {
                    Logger::warning(Logger::CANAL_EXTRACAO, 'Lote: PI sem sprints cadastradas, fallback por sprint ignorado', $ctxLog);
                    $logs[] = 'Fallback ignorado: PI sem sprints cadastradas';
                } else {
                    $jqlFallback = $this->buildFallbackJql($projetos, $pi, $sprintsPi);
                    try {
                        $resultFb = $model->fetchAll($jqlFallback);
                        $itensFallbackBrutos = $resultFb['items'];
                        foreach ((array)($resultFb['logs'] ?? []) as $l) $logs[] = 'Fallback: ' . $l;
                        unset($resultFb);
                    } catch (Throwable $e) {
                        // Principal OK + fallback falho = carga incompleta: nada é gravado.
                        Logger::exception(Logger::CANAL_EXTRACAO, 'Lote: falha ao buscar o fallback por sprint no Jira', $e, $ctxLog + ['jql_fallback' => $jqlFallback]);
                        throw new RuntimeException('Fallback por sprint: ' . $e->getMessage(), self::ERR_FETCH, $e);
                    }
                }
            }
        }

        // ── Une por chave (principal vence) e filtra pelo lote ───────────
        $prefixosLote = array_flip($projetos);
        $vistas       = [];
        $finais       = [];
        $foraDoLote   = 0;
        $dupPrincipal = 0;
        foreach ($items as $it) {
            $chave = self::strOrNull($it['chave'] ?? null);
            $pref  = $chave === null ? null : (string)strstr($chave, '-', true);
            if ($chave === null || !isset($prefixosLote[$pref])) {
                $foraDoLote++;
                Logger::warning(Logger::CANAL_EXTRACAO, 'Lote: item fora do lote descartado', $ctxLog + ['chave' => $chave]);
                continue;
            }
            if (isset($vistas[$chave])) {
                $dupPrincipal++;
                continue;
            }
            $vistas[$chave] = true;
            $it['origem_pi'] = 'fixversion';
            $finais[] = $it;
        }
        $itensPrincipal = count($finais);
        unset($items);

        $fbDup = $dupPrincipal; // duplicatas da busca principal também contam como descartadas
        $fbPorProjeto = [];
        $fbVersoes    = [];
        $itensFallback = 0;
        foreach ($itensFallbackBrutos as $it) {
            $chave = self::strOrNull($it['chave'] ?? null);
            $pref  = $chave === null ? null : (string)strstr($chave, '-', true);
            if ($chave === null || !isset($prefixosLote[$pref])) {
                $foraDoLote++;
                Logger::warning(Logger::CANAL_EXTRACAO, 'Lote: item do fallback fora do lote descartado', $ctxLog + ['chave' => $chave]);
                continue;
            }
            $versoes = self::strOrNull($it['versoes_corrigidas'] ?? null);
            // Já trazido pela busca principal, repetido ou já com a PI em fixVersion: descarta.
            if (isset($vistas[$chave]) || ($versoes !== null && stripos($versoes, $pi) !== false)) {
                $fbDup++;
                continue;
            }
            $vistas[$chave] = true;
            $it['origem_pi'] = 'sprint';
            $finais[] = $it;
            $itensFallback++;
            if (!isset($fbPorProjeto[$pref])) $fbPorProjeto[$pref] = ['itens' => 0, 'sp' => 0.0];
            $fbPorProjeto[$pref]['itens']++;
            $sp = self::numOrNull($it['story_points'] ?? null);
            if ($sp !== null) $fbPorProjeto[$pref]['sp'] += $sp;
            $nomes = $versoes === null ? ['(vazio)'] : array_values(array_filter(array_map('trim', explode(';', $versoes)), function ($x) { return $x !== ''; }));
            foreach ($nomes as $n) {
                $fbVersoes[$n] = ($fbVersoes[$n] ?? 0) + 1;
            }
        }
        unset($itensFallbackBrutos, $vistas);
        ksort($fbPorProjeto);
        arsort($fbVersoes);
        $items = $finais;
        unset($finais);

        // ── R1: proteção contra resposta parcial/vazia ───────────────────
        $existentesAntes = $this->contarRecorte($tabela, $piId, $projetos);
        $limiar = (float)($this->extCfg['limiar_parcial'] ?? 0.8);
        if (!$forcar && $existentesAntes > 0 && (count($items) === 0 || count($items) < $limiar * $existentesAntes)) {
            $msg = 'Extração abortada (resposta parcial/vazia): o Jira devolveu ' . count($items)
                 . " itens e o banco tem {$existentesAntes} no recorte (limiar " . (int)round($limiar * 100)
                 . '%). Nada foi apagado. Use --forcar se a queda for legítima.';
            Logger::error(Logger::CANAL_EXTRACAO, 'Lote: ' . $msg, $ctxLog + ['lidos' => count($items), 'existentes' => $existentesAntes]);
            throw new RuntimeException($msg, self::ERR_PARCIAL);
        }

        // ── Transação: limpeza por prefixo + INSERT ──────────────────────
        $agora     = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $apagados  = 0;
        $inseridos = 0;
        $pdo       = $this->pdo;

        try {
            $pdo->beginTransaction();

            $apagados = $this->limparPorPrefixo($pdo, $tabela, $piId, $projetos);

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
                         tempo_total_bloqueado, data_carga, ativo, origem_pi)
                     VALUES
                        (:pi, :tipo_item, :chave, :sprint, :sprint_ref, :projeto, :status,
                         :versoes, :sp, :spe,
                         :soma_seg, :cat, :pai, :alt_escopo,
                         :resolucao, :tipo_sust, :criado, :ponto_comp,
                         :ini_fluxo, :fim_fluxo, :ini_homo, :fim_homo,
                         :ini_impl, :fim_impl, :ambiente,
                         :bloqueado, :carga, :ativo, :origem)'
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
                        ':origem'     => $it['origem_pi'],
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
            'itens_fallback' => $itensFallback, 'fallback_dup' => $fbDup,
            'fora_do_lote' => $foraDoLote, 'existentes_antes' => $existentesAntes,
        ]);

        return [
            'total_itens'          => $inseridos,
            'registros_apagados'   => $apagados,
            'jql'                  => $jql,
            'pi_id'                => $piId,
            'itens_lidos'          => count($items),
            'paginas'              => $paginas,
            'logs'                 => $logs,
            'tabela'               => $tabela,
            'data_carga'           => $agora,
            'itens_principal'      => $itensPrincipal,
            'itens_fallback'       => $itensFallback,
            'fallback_dup'         => $fbDup,
            'fora_do_lote'         => $foraDoLote,
            'jql_fallback'         => $jqlFallback,
            'fallback_por_projeto' => $fbPorProjeto,
            'fallback_versoes'     => $fbVersoes,
            'existentes_antes'     => $existentesAntes,
        ];
    }

    // ── Escopo da limpeza (R2/R3) ────────────────────────────────────────

    /**
     * Apaga o recorte da PI pelos PREFIXOS DE CHAVE dos projetos solicitados
     * (chave LIKE 'SGSE-%'), nunca pelo nome de exibição do projeto nem pelos
     * projetos que voltaram com itens. Projeto que volta com 0 itens é limpo;
     * projeto fora do lote não é tocado. Devolve as linhas apagadas.
     */
    private function limparPorPrefixo(PDO $pdo, string $tabela, ?int $piId, array $projetos): int
    {
        $total = 0;
        foreach (array_chunk($projetos, self::DELETE_BLOCO) as $bloco) {
            [$where, $params] = $this->filtroRecorte($piId, $bloco);
            $del = $pdo->prepare("DELETE FROM {$tabela} WHERE {$where}");
            $del->execute($params);
            $total += $del->rowCount();
        }
        return $total;
    }

    private function contarRecorte(string $tabela, ?int $piId, array $projetos): int
    {
        $total = 0;
        foreach (array_chunk($projetos, self::DELETE_BLOCO) as $bloco) {
            [$where, $params] = $this->filtroRecorte($piId, $bloco);
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE {$where}");
            $st->execute($params);
            $total += (int)$st->fetchColumn();
        }
        return $total;
    }

    /** @return array{0:string,1:array} cláusula WHERE e parâmetros posicionais */
    private function filtroRecorte(?int $piId, array $projetos): array
    {
        $likes  = [];
        $params = [];
        foreach ($projetos as $p) {
            $likes[]  = "chave LIKE ? ESCAPE '\\\\'";
            $params[] = self::likePrefixo($p);
        }
        $where = '(' . implode(' OR ', $likes) . ')';
        if ($piId !== null) {
            $where = 'configuracao_pi_id = ? AND ' . $where;
            array_unshift($params, $piId);
        } else {
            $where = 'configuracao_pi_id IS NULL AND ' . $where;
        }
        return [$where, $params];
    }

    /**
     * Padrão LIKE seguro para o prefixo de chave: 'SGSE' → 'SGSE-%'.
     * Valida o formato Jira (maiúsculas/dígitos/_) e escapa \ % _.
     * @throws RuntimeException ERR_ARGS se a chave for inválida
     */
    private static function likePrefixo(string $chave): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $chave)) {
            throw new RuntimeException('Chave de projeto inválida: ' . substr($chave, 0, 40), self::ERR_ARGS);
        }
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $chave) . '-%';
    }

    private function resolverPiId(string $pi): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :pi LIMIT 1');
        $stmt->execute([':pi' => $pi]);
        $piId = $stmt->fetchColumn();
        return $piId === false ? null : (int)$piId;
    }

    private function lockDir(): string
    {
        $dir = (string)($this->extCfg['lock_dir'] ?? 'data/locks');
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
            return rtrim($dir, '/\\');
        }
        return dirname(__DIR__, 2) . '/' . trim($dir, '/\\');
    }

    // ── Helpers de conversão ─────────────────────────────────────────────

    /** ISO 8601 → 'Y-m-d H:i:s'; vazio/erro → null. */
    private static function isoToDatetime($v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) return null;
        try {
            return (new DateTimeImmutable((string)$v))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function numOrNull($v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    private static function strOrNull($v): ?string
    {
        if ($v === null) return null;
        $s = is_array($v) ? implode('; ', array_filter(array_map('strval', $v))) : (string)$v;
        return trim($s) === '' ? null : $s;
    }
}
