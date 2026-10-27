<?php
/**
 * bin/extract_runner.php — RUNNER DE EXTRAÇÃO Jira → MySQL (v1.5, ETAPA 6a)
 * ─────────────────────────────────────────────────────────────────────────
 * Runner CLI que extrai itens de um RECORTE (projeto(s) + PI + sprint(s)) e
 * popula a tabela extracao_jira (ou baseline, com --baseline=1). SEM
 * modal/fila/e-mail (próximas etapas): por ora os parâmetros vêm por CLI.
 *
 * REUSA:
 *   - App\Models\JiraModel   (fetchAll paginado + mapIssue dos 33 campos).
 *   - App\Services\JqlBuilder (novo forSelection() para o recorte).
 *   - App\Database::pdo()    (conexão única, lê config/db.php).
 *
 * PARÂMETROS (getopt):
 *   --projetos=SGGU,SGTB    lista de projeto_key (obrigatório)
 *   --pi='PI 4/26'          rótulo da fixVersion/PI (obrigatório)
 *   --sprints='SP 26.4.1'   lista de sprints (opcional)
 *   --baseline=0|1          1 = grava em baseline; default 0 (extracao_jira)
 *   --fonte=jira|fixture    default jira
 *   --fixture=caminho.json  opcional; default data/import/fixtures/jira_sample.json
 *
 * DECISÃO — soma_tempo_gasto em SEGUNDOS (sem dupla divisão):
 *   A view vw_extracao_calculada (e vw_produtividade) calcula as horas como
 *   soma_tempo_gasto/3600. O JiraModel JÁ expõe tempo_gasto_horas (dividido).
 *   Para NÃO dividir duas vezes, o runner grava em soma_tempo_gasto o valor
 *   BRUTO em SEGUNDOS de aggregatetimespent, lido do novo campo aditivo
 *   JiraModel::mapIssue()['tempo_gasto_segundos']. Assim a view produz as
 *   horas corretas (segundos/3600).
 *
 * DATAS: o Jira retorna ISO 8601 (ex 2026-09-28T09:00:00.000-0300). O runner
 *   converte para DATETIME MySQL (Y-m-d H:i:s). Vazio/erro → NULL.
 *
 * configuracao_pi_id: resolvido por desc_pi = <pi> em configuracao_pi; se não
 *   casar → NULL (e aviso).
 *
 * REGRA DE DELETE (idempotência do recorte): antes de inserir, dentro da MESMA
 *   transação InnoDB, apaga os registros do MESMO recorte (mesma
 *   configuracao_pi_id + mesmos nomes de `projeto` presentes no lote), evitando
 *   duplicidade ao reexecutar. Insere com data_carga=NOW() e ativo='S'.
 *
 * SEGURANÇA: o token do Jira NUNCA é impresso.
 *
 * Uso:
 *   php bin/extract_runner.php --projetos=SGGU --pi="PI 4/26" --fonte=fixture
 *   php bin/extract_runner.php --projetos=SGGU --pi="PI 4/26" --sprints="SP 26.4.1" --fonte=jira
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Models/JiraModel.php';
require __DIR__ . '/../app/Services/JqlBuilder.php';

use App\Database;
use App\Models\JiraModel;
use App\Services\JqlBuilder;

// ── 1. Parâmetros ───────────────────────────────────────────────────────────
$opts = getopt('', [
    'projetos:', 'pi:', 'sprints::', 'baseline::', 'fonte::', 'fixture::',
]);

function splitList(?string $v): array
{
    if ($v === null || trim($v) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

$projetos = splitList($opts['projetos'] ?? null);
// NOTA: usamos $piLabel (não $pi) de propósito — config/baseline.php faz
// `$pi = require pi.php` e, carregado no escopo global do runner, sobrescreveria
// uma variável chamada $pi.
$piLabel  = isset($opts['pi']) ? trim((string)$opts['pi']) : '';
$sprints  = splitList($opts['sprints'] ?? null);
$baseline = (int)($opts['baseline'] ?? 0) === 1;
$fonte    = strtolower(trim((string)($opts['fonte'] ?? 'jira')));
$fixture  = $opts['fixture'] ?? (__DIR__ . '/../data/import/fixtures/jira_sample.json');

if ($projetos === [] || $piLabel === '') {
    fwrite(STDERR, "Uso: php bin/extract_runner.php --projetos=SGGU[,SGTB] --pi=\"PI 4/26\" [--sprints=\"SP 26.4.1\"] [--baseline=0|1] [--fonte=jira|fixture]\n");
    exit(2);
}
if (!in_array($fonte, ['jira', 'fixture'], true)) {
    fwrite(STDERR, "Valor inválido para --fonte: {$fonte} (use jira|fixture)\n");
    exit(2);
}

$tabela = $baseline ? 'baseline' : 'extracao_jira';

echo str_repeat('=', 74) . "\n";
echo "  RUNNER DE EXTRAÇÃO — recorte\n";
echo str_repeat('=', 74) . "\n";
echo "  Projetos : " . implode(', ', $projetos) . "\n";
echo "  PI       : {$piLabel}\n";
echo "  Sprints  : " . ($sprints ? implode(', ', $sprints) : '(todas)') . "\n";
echo "  Destino  : {$tabela}\n";
echo "  Fonte    : {$fonte}\n";

// ── 2. Config + JQL do recorte ───────────────────────────────────────────────
$jiraCfg     = require __DIR__ . '/../config/jira.php';
$baselineCfg = require __DIR__ . '/../config/baseline.php';

$jqlBuilder = new JqlBuilder($baselineCfg);
$jql = $jqlBuilder->forSelection($projetos, $piLabel, $sprints ?: null);
echo "  JQL      : {$jql}\n";
echo str_repeat('-', 74) . "\n";

// ── 3. Obtém os itens (mapeados nos 33 campos) ───────────────────────────────
$model = new JiraModel($jiraCfg);

$items  = [];
$jiraOk = null; // null=não tentou; true/false para fonte=jira

if ($fonte === 'fixture') {
    if (!is_file($fixture)) {
        fwrite(STDERR, "Fixture não encontrada: {$fixture}\n");
        exit(1);
    }
    $raw = json_decode((string)file_get_contents($fixture), true);
    if (!is_array($raw) || !isset($raw['issues']) || !is_array($raw['issues'])) {
        fwrite(STDERR, "Fixture inválida (esperado objeto com chave 'issues').\n");
        exit(1);
    }
    $items = $model->mapRawIssues($raw['issues']);
    echo "  Fonte fixture: " . count($items) . " itens lidos de " . basename($fixture) . "\n";
} else {
    // fonte=jira: chamada real à API. Pode falhar (rede/SSL). Não trava.
    try {
        $result = $model->fetchAll($jql);
        $items  = $result['items'];
        $jiraOk = true;
        echo "  Jira OK: " . count($items) . " itens em " . $result['pages'] . " página(s)\n";
        foreach ($result['logs'] as $l) echo "     · {$l}\n";
    } catch (Throwable $e) {
        $jiraOk = false;
        // NÃO imprime o token; a mensagem do JiraModel não o contém.
        echo "  Jira FALHOU: " . $e->getMessage() . "\n";
        echo "  (ambiente sem acesso ao Jira; valide o pipeline com --fonte=fixture)\n";
        echo str_repeat('=', 74) . "\n";
        exit(1);
    }
}

// ── 4. Helpers de conversão/gravação ─────────────────────────────────────────

/** ISO 8601 → 'Y-m-d H:i:s'; vazio/erro → null. */
function isoToDatetime(mixed $v): ?string
{
    if ($v === null || (is_string($v) && trim($v) === '')) return null;
    try {
        return (new DateTimeImmutable((string)$v))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/** Numérico ou null. */
function numOrNull(mixed $v): ?float
{
    return is_numeric($v) ? (float)$v : null;
}

/** String não-vazia ou null. */
function strOrNull(mixed $v): ?string
{
    if ($v === null) return null;
    $s = is_array($v) ? implode('; ', array_filter(array_map('strval', $v))) : (string)$v;
    return trim($s) === '' ? null : $s;
}

$pdo = Database::pdo();

// Resolve configuracao_pi_id por desc_pi = <pi>.
$stmt = $pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :pi LIMIT 1');
$stmt->execute([':pi' => $piLabel]);
$piId = $stmt->fetchColumn();
$piId = $piId === false ? null : (int)$piId;
if ($piId === null) {
    echo "  AVISO: PI '{$piLabel}' não encontrada em configuracao_pi — configuracao_pi_id ficará NULL.\n";
} else {
    echo "  configuracao_pi_id resolvido: {$piId}\n";
}

// Projetos (display-name) distintos presentes no lote — usados no DELETE do recorte.
$projetosNoLote = [];
foreach ($items as $it) {
    $p = strOrNull($it['projeto'] ?? null);
    if ($p !== null) $projetosNoLote[$p] = true;
}
$projetosNoLote = array_keys($projetosNoLote);

// ── 5. Transação: DELETE do recorte + INSERT ─────────────────────────────────
$agora    = (new DateTimeImmutable())->format('Y-m-d H:i:s');
$apagados = 0;
$inseridos = 0;

try {
    $pdo->beginTransaction();

    // DELETE do MESMO recorte: mesma configuracao_pi_id + mesmos `projeto` do lote.
    // Idempotente: reexecutar com o mesmo recorte não acumula linhas.
    if ($projetosNoLote !== []) {
        $place = implode(', ', array_fill(0, count($projetosNoLote), '?'));
        if ($piId !== null) {
            $sqlDel = "DELETE FROM {$tabela} WHERE configuracao_pi_id = ? AND projeto IN ({$place})";
            $paramsDel = array_merge([$piId], $projetosNoLote);
        } else {
            $sqlDel = "DELETE FROM {$tabela} WHERE configuracao_pi_id IS NULL AND projeto IN ({$place})";
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
                 sprint, tipo_sustentacao, jornada_dona_epico, data_carga, ativo)
             VALUES
                (:pi, :tipo_item, :chave, :resumo, :projeto, :status,
                 :versoes, :pai, :spe, :sp,
                 :sprint, :tipo_sust, :jornada, :carga, :ativo)'
        );
        foreach ($items as $it) {
            $ins->execute([
                ':pi'        => $piId,
                ':tipo_item' => strOrNull($it['tipo_item'] ?? null),
                ':chave'     => strOrNull($it['chave'] ?? null),
                ':resumo'    => strOrNull($it['resumo'] ?? null),
                ':projeto'   => strOrNull($it['projeto'] ?? null),
                ':status'    => strOrNull($it['status'] ?? null),
                ':versoes'   => strOrNull($it['versoes_corrigidas'] ?? null),
                ':pai'       => strOrNull($it['pai'] ?? null),
                ':spe'       => numOrNull($it['story_point_estimate'] ?? null),
                ':sp'        => numOrNull($it['story_points'] ?? null),
                ':sprint'    => strOrNull($it['sprint'] ?? null),
                ':tipo_sust' => strOrNull($it['tipo_sustentacao'] ?? null),
                ':jornada'   => null, // jornada dona do épico: não vem do item; preenchida depois
                ':carga'     => $agora,
                ':ativo'     => 'S',
            ]);
            $inseridos++;
        }
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO extracao_jira
                (configuracao_pi_id, tipo_item, chave, sprint, projeto, status,
                 versoes_corrigidas, story_points, story_point_estimate,
                 soma_tempo_gasto, categoria_status, pai, alteracao_escopo,
                 resolucao, tipo_sustentacao, criado, ponto_compromisso,
                 inicio_fluxo, fim_fluxo, inicio_homologacao, fim_homologacao,
                 inicio_implantacao, fim_implantacao, ambiente,
                 tempo_total_bloqueado, data_carga, ativo)
             VALUES
                (:pi, :tipo_item, :chave, :sprint, :projeto, :status,
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
                ':tipo_item'  => strOrNull($it['tipo_item'] ?? null),
                ':chave'      => strOrNull($it['chave'] ?? null),
                ':sprint'     => strOrNull($it['sprint'] ?? null),
                ':projeto'    => strOrNull($it['projeto'] ?? null),
                ':status'     => strOrNull($it['status'] ?? null),
                ':versoes'    => strOrNull($it['versoes_corrigidas'] ?? null),
                ':sp'         => numOrNull($it['story_points'] ?? null),
                ':spe'        => numOrNull($it['story_point_estimate'] ?? null),
                // soma_tempo_gasto em SEGUNDOS (valor bruto) — evita dupla divisão.
                ':soma_seg'   => $it['tempo_gasto_segundos'] ?? null,
                ':cat'        => strOrNull($it['categoria_status'] ?? null),
                ':pai'        => strOrNull($it['pai'] ?? null),
                ':alt_escopo' => strOrNull($it['alteracao_escopo'] ?? null),
                ':resolucao'  => strOrNull($it['resolucao'] ?? null),
                ':tipo_sust'  => strOrNull($it['tipo_sustentacao'] ?? null),
                ':criado'     => isoToDatetime($it['criado'] ?? null),
                ':ponto_comp' => isoToDatetime($it['ponto_compromisso'] ?? null),
                ':ini_fluxo'  => isoToDatetime($it['inicio_fluxo'] ?? null),
                ':fim_fluxo'  => isoToDatetime($it['fim_fluxo'] ?? null),
                ':ini_homo'   => isoToDatetime($it['inicio_homologacao'] ?? null),
                ':fim_homo'   => isoToDatetime($it['fim_homologacao'] ?? null),
                ':ini_impl'   => isoToDatetime($it['inicio_implantacao'] ?? null),
                ':fim_impl'   => isoToDatetime($it['fim_implantacao'] ?? null),
                ':ambiente'   => strOrNull($it['ambiente'] ?? null),
                ':bloqueado'  => strOrNull($it['tempo_total_bloqueado'] ?? null),
                ':carga'      => $agora,
                ':ativo'      => 'S',
            ]);
            $inseridos++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO na gravação, rollback executado: " . $e->getMessage() . "\n");
    exit(1);
}

// ── 6. Relatório ─────────────────────────────────────────────────────────────
echo str_repeat('-', 74) . "\n";
echo "  Recorte apagado (idempotência): {$apagados} linha(s)\n";
echo "  Inseridos: {$inseridos} em {$tabela} (data_carga={$agora}, ativo='S')\n";
$tot = (int)$pdo->query("SELECT COUNT(*) FROM {$tabela}")->fetchColumn();
echo "  Total atual em {$tabela}: {$tot}\n";
echo str_repeat('=', 74) . "\n";
echo "Concluído.\n";
