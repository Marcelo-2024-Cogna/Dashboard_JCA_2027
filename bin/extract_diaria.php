<?php
/**
 * bin/extract_diaria.php — JOB NOTURNO de extração Jira → MySQL (23:00)
 * ─────────────────────────────────────────────────────────────────────────
 * Resolve a PI corrente (e a anterior, por 14 dias após o fim dela), monta um
 * lote por jornada ativa (projetos dos times ativos), enfileira UMA ordem com
 * hash do dia em extracao_agendamento e a processa neste mesmo processo
 * (extração principal por fixVersion + fallback por sprint). O resultado
 * aparece em /extracao/fila, em data/logs/extracao.log (linha RESUMO) e, em
 * caso de falha, em data/notificacoes.log + e-mail (mail() nativo).
 *
 * Toda a lógica vive em App\Srv\srvExtracaoDiaria.
 *
 * Execute `php bin/extract_diaria.php --help` para opções e códigos de saída.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}

require_once __DIR__ . '/../app/compat.php';

define('BASE_DIR', dirname(__DIR__));
date_default_timezone_set((require BASE_DIR . '/config/app.php')['timezone'] ?? 'America/Sao_Paulo');
spl_autoload_register(function (string $class): void {
    $file = BASE_DIR . '/' . str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    if (is_file($file)) require_once $file;
});
// Carrega config/secrets.local.php no ambiente (credenciais do Jira, JOB_NOTIFY_*).
require_once BASE_DIR . '/config/jira.php';

use App\Database;
use App\Services\ExtractionService;
use App\Services\LogNotifier;
use App\Services\Logger;
use App\Srv\srvExtracaoDiaria;
use App\Srv\srvNotificadorEmail;

function extract_diaria_ajuda(): string
{
    return <<<'TXT'
Uso: php bin/extract_diaria.php [opções]

Job noturno de extração (agendado às 23:00). Extrai a PI corrente (e a anterior
por 14 dias após o fim dela): extração principal por fixVersion + fallback por
sprint (itens das sprints da PI sem fixVersion ou com fixVersion de outra PI).

Opções:
  --dry-run        resolve PI, lotes e JQLs e imprime; NÃO toca no Jira nem grava
                   no banco (só SELECT)
  --pi='PI x/yy'   força a PI (deve existir e estar ativa em configuracao_pi)
  --forcar         relaxa a proteção R1 (aceita queda grande de itens)
  --sem-fallback   desliga o fallback por sprint (para comparação)
  --refazer        executa de novo mesmo que já exista ordem concluída hoje
  --fonte=jira|fixture   padrão jira (fixture = apenas testes)
  --help           esta ajuda

Códigos de saída:
  0  ok (inclui "já executada hoje")
  1  falha parcial (alguma jornada/PI com erro)
  2  configuração/PI inválida (sem PI cadastrada, fora do período de graça,
     opção inválida, nenhum time ativo)
  3  já em execução (lock) - sem erro
  4  erro fatal / Jira indisponível (nenhuma jornada gravada)
TXT;
}

$permitidas = ['--dry-run', '--pi', '--forcar', '--sem-fallback', '--refazer', '--fonte', '--help'];
foreach (array_slice($argv, 1) as $a) {
    $nome = strtok((string)$a, '=');
    if (strncmp((string)$a, '--', 2) !== 0 || !in_array($nome, $permitidas, true)) {
        fwrite(STDERR, "Opção inválida: {$a}\n\n" . extract_diaria_ajuda() . "\n");
        exit(2);
    }
}

$opts = getopt('', ['dry-run', 'pi:', 'forcar', 'sem-fallback', 'refazer', 'fonte:', 'help']);
if (array_key_exists('help', $opts)) {
    echo extract_diaria_ajuda() . "\n";
    exit(0);
}
$fonte = strtolower(trim((string)($opts['fonte'] ?? 'jira')));
if (!in_array($fonte, ['jira', 'fixture'], true)) {
    fwrite(STDERR, "Valor inválido para --fonte: {$fonte} (use jira|fixture)\n");
    exit(2);
}
if (array_key_exists('pi', $opts) && (!is_string($opts['pi']) || trim($opts['pi']) === '')) {
    fwrite(STDERR, "Valor inválido para --pi\n");
    exit(2);
}

try {
    $cfg  = require BASE_DIR . '/config/extracao.php';
    $pdo  = Database::pdo();
    $logN = BASE_DIR . '/' . ltrim((string)$cfg['log_notificacoes'], '/\\');

    $job = new srvExtracaoDiaria(
        $pdo,
        $cfg,
        BASE_DIR,
        new ExtractionService($pdo, null, null, null, $cfg),
        new LogNotifier($logN),
        new srvNotificadorEmail($logN, (string)$cfg['ambiente'])
    );
    $codigo = $job->executar([
        'dry_run'      => array_key_exists('dry-run', $opts),
        'pi'           => $opts['pi'] ?? null,
        'forcar'       => array_key_exists('forcar', $opts),
        'sem_fallback' => array_key_exists('sem-fallback', $opts),
        'refazer'      => array_key_exists('refazer', $opts),
        'fonte'        => $fonte,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO FATAL: ' . Logger::sanitize($e->getMessage()) . "\n");
    Logger::exception('extracao', 'extract_diaria: erro fatal na inicialização', $e);
    $codigo = 4;
}

exit($codigo);
