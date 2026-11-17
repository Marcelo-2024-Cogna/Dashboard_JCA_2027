<?php
/**
 * bin/extract_check.php — VERIFICADOR do job noturno (23:30)
 * ─────────────────────────────────────────────────────────────────────────
 * Confere, para a PI corrente, se o job das 23:00 rodou e carregou tudo:
 *   - cada jornada ativa com dados recentes (MIN data_carga <= 25 h);
 *   - o job enfileirou ordem hoje e a última ordem não está em erro/parcial;
 *   - a contagem de itens da PI não caiu bruscamente (< 80% do último
 *     valor guardado em data/extract_check_estado.json);
 *   - salvaguardas: ordem 'processando' parada (> checagem_processando_min),
 *     ordem 'pendente' do job vencida (> checagem_pendente_venc_min) e espaço
 *     livre em disco (config/extracao.php, chave 'disco').
 * Alertas vão para o log (data/logs/extracao.log), data/notificacoes.log e
 * e-mail (mail() nativo). Só LÊ o banco; grava apenas o arquivo de estado.
 * Sem conexão com o banco: registra, notifica e sai com 4.
 * A lógica vive em App\Srv\srvVerificadorCli / srvVerificadorExtracao.
 *
 * Execute `php bin/extract_check.php --help` para opções e códigos de saída.
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
// Carrega config/secrets.local.php no ambiente (JOB_NOTIFY_*).
require_once BASE_DIR . '/config/jira.php';

use App\Database;
use App\Services\Logger;
use App\Srv\srvVerificadorCli;

function extract_check_ajuda(): string
{
    return <<<'TXT'
Uso: php bin/extract_check.php [opções]

Verificador do job noturno (agendado às 23:30).

Opções:
  --dry-run   verifica e imprime; não notifica nem grava o arquivo de estado
  --help      esta ajuda

Alertas: defasagem de dados, job não rodou hoje, ordem do job em erro/parcial,
queda brusca, ordem 'processando' parada há mais de checagem_processando_min,
ordem 'pendente' do job vencida há mais de checagem_pendente_venc_min e disco
abaixo do limite (config/extracao.php: disco.caminhos, min_livre_mb, min_livre_pct).

Códigos de saída:
  0  tudo ok
  1  alerta (defasagem, job não rodou hoje, ordem em erro/parcial, queda brusca,
     ordem travada, disco)
  2  configuração/PI inválida (sem PI cadastrada ou fora do período de graça)
  4  erro fatal (ex.: banco indisponível; registra, notifica e sai)
TXT;
}

foreach (array_slice($argv, 1) as $a) {
    if (!in_array($a, ['--dry-run', '--help'], true)) {
        fwrite(STDERR, "Opção inválida: {$a}\n\n" . extract_check_ajuda() . "\n");
        exit(2);
    }
}
$opts = getopt('', ['dry-run', 'help']);
if (array_key_exists('help', $opts)) {
    echo extract_check_ajuda() . "\n";
    exit(0);
}
$dry = array_key_exists('dry-run', $opts);

try {
    $cfg = require BASE_DIR . '/config/extracao.php';
    $codigo = (new srvVerificadorCli())->executar(
        function () {
            return Database::pdo();
        },
        $cfg,
        BASE_DIR,
        $dry
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO FATAL: ' . Logger::sanitize($e->getMessage()) . "\n");
    Logger::exception('extracao', 'extract_check: erro fatal', $e);
    $codigo = 4;
}
exit($codigo);
