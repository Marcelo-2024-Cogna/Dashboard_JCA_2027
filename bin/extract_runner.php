<?php
/**
 * bin/extract_runner.php — WRAPPER CLI da extração Jira → MySQL
 * ─────────────────────────────────────────────────────────────────────────
 * Wrapper fino sobre App\Services\ExtractionService (toda a lógica de JQL,
 * busca, mapeamento e DELETE+INSERT em transação vive no serviço, reusado
 * também pelo worker da fila: bin/queue_worker.php).
 *
 * PARÂMETROS (getopt):
 *   --projetos=SGGU,SGTB    lista de projeto_key (obrigatório)
 *   --pi='PI 4/26'          rótulo da fixVersion/PI (obrigatório)
 *   --sprints='SP 26.4.1'   lista de sprints (opcional)
 *   --baseline=0|1          1 = grava em baseline; default 0 (extracao_jira)
 *   --fonte=jira|fixture    default jira
 *   --fixture=caminho.json  opcional; default data/import/fixtures/jira_sample.json
 *
 * soma_tempo_gasto é gravado em SEGUNDOS (a view divide por 3600).
 * O token do Jira NUNCA é impresso.
 *
 * Uso:
 *   php bin/extract_runner.php --projetos=SGGU --pi="PI 4/26" --fonte=fixture
 *   php bin/extract_runner.php --projetos=SGGU --pi="PI 4/26" --sprints="SP 26.4.1" --fonte=jira
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Models/JiraModel.php';
require __DIR__ . '/../app/Services/JqlBuilder.php';
require __DIR__ . '/../app/Services/ExtractionService.php';

use App\Database;
use App\Services\ExtractionService;

$opts = getopt('', [
    'projetos:', 'pi:', 'sprints::', 'baseline::', 'fonte::', 'fixture::',
]);

function splitList(?string $v): array
{
    if ($v === null || trim($v) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

$projetos = splitList($opts['projetos'] ?? null);
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
$service = new ExtractionService();

echo str_repeat('=', 74) . "\n";
echo "  RUNNER DE EXTRAÇÃO — recorte\n";
echo str_repeat('=', 74) . "\n";
echo "  Projetos : " . implode(', ', $projetos) . "\n";
echo "  PI       : {$piLabel}\n";
echo "  Sprints  : " . ($sprints ? implode(', ', $sprints) : '(todas)') . "\n";
echo "  Destino  : {$tabela}\n";
echo "  Fonte    : {$fonte}\n";
echo "  JQL      : " . $service->buildJql($projetos, $piLabel, $sprints ?: null) . "\n";
echo str_repeat('-', 74) . "\n";

try {
    $r = $service->extract($projetos, $piLabel, $sprints ?: null, $fonte, $baseline, $fixture);
} catch (RuntimeException $e) {
    if ($e->getCode() === ExtractionService::ERR_FETCH) {
        if ($fonte === 'jira') {
            echo "  Jira FALHOU: " . $e->getMessage() . "\n";
            echo "  (ambiente sem acesso ao Jira; valide o pipeline com --fonte=fixture)\n";
            echo str_repeat('=', 74) . "\n";
        } else {
            fwrite(STDERR, $e->getMessage() . "\n");
        }
        exit(1);
    }
    fwrite(STDERR, "ERRO: " . $e->getMessage() . "\n");
    exit(1);
}

if ($fonte === 'fixture') {
    echo "  Fonte fixture: {$r['logs'][0]}\n";
} else {
    echo "  Jira OK: {$r['itens_lidos']} itens em {$r['paginas']} página(s)\n";
    foreach ($r['logs'] as $l) echo "     · {$l}\n";
}
if ($r['pi_id'] === null) {
    echo "  AVISO: PI '{$piLabel}' não encontrada em configuracao_pi — configuracao_pi_id ficará NULL.\n";
} else {
    echo "  configuracao_pi_id resolvido: {$r['pi_id']}\n";
}

echo str_repeat('-', 74) . "\n";
echo "  Recorte apagado (idempotência): {$r['registros_apagados']} linha(s)\n";
echo "  Inseridos: {$r['total_itens']} em {$tabela} (data_carga={$r['data_carga']}, ativo='S')\n";
$tot = (int)Database::pdo()->query("SELECT COUNT(*) FROM {$tabela}")->fetchColumn();
echo "  Total atual em {$tabela}: {$tot}\n";
echo str_repeat('=', 74) . "\n";
echo "Concluído.\n";
