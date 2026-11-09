<?php
/**
 * bin/extract_check.php — VERIFICADOR do job noturno (23:30)
 * ─────────────────────────────────────────────────────────────────────────
 * Confere, para a PI corrente, se o job das 23:00 rodou e carregou tudo:
 *   - cada jornada ativa com dados recentes (MIN data_carga <= 25 h);
 *   - o job enfileirou ordem hoje e a última ordem não está em erro/parcial;
 *   - a contagem de itens da PI não caiu bruscamente (< 80% do último
 *     valor guardado em data/extract_check_estado.json).
 * Alertas vão para o log (data/logs/extracao.log), data/notificacoes.log e
 * e-mail (mail() nativo). Só LÊ o banco; grava apenas o arquivo de estado.
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
use App\Srv\srvNotificadorEmail;
use App\Srv\srvVerificadorExtracao;

function extract_check_ajuda(): string
{
    return <<<'TXT'
Uso: php bin/extract_check.php [opções]

Verificador do job noturno (agendado às 23:30).

Opções:
  --dry-run   verifica e imprime; não notifica nem grava o arquivo de estado
  --help      esta ajuda

Códigos de saída:
  0  tudo ok
  1  alerta (defasagem, job não rodou hoje, ordem em erro/parcial, queda brusca)
  2  configuração/PI inválida (sem PI cadastrada ou fora do período de graça)
  4  erro fatal (ex.: banco indisponível)
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

function chk(string $m): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

try {
    $cfg    = require BASE_DIR . '/config/extracao.php';
    $pdo    = Database::pdo();
    $estado = BASE_DIR . '/' . ltrim((string)$cfg['estado_check'], '/\\');

    try {
        $r = (new srvVerificadorExtracao($pdo, $cfg, $estado))->verificar(new DateTimeImmutable('now'), $dry);
    } catch (RuntimeException $e) {
        if ($e->getCode() !== 2) throw $e;
        chk('CONFIGURAÇÃO/PI INVÁLIDA: ' . $e->getMessage());
        Logger::error('extracao', 'extract_check: configuração/PI inválida', ['erro' => $e->getMessage()]);
        if (!$dry) {
            $n = new srvNotificadorEmail(BASE_DIR . '/' . ltrim((string)$cfg['log_notificacoes'], '/\\'), (string)$cfg['ambiente']);
            $n->notificarFalha('?', ['tipo' => 'Verificacao', 'codigo_saida' => 2, 'erros' => [$e->getMessage()], 'log' => 'data/logs/extracao.log']);
        }
        exit(2);
    }

    chk("PI {$r['pi']}: {$r['itens']} itens em extracao_jira.");
    foreach ($r['jornadas'] as $j) {
        chk(sprintf('  %-28s itens=%-6d MIN data_carga=%s (%s h)', $j['jornada'], $j['itens'], $j['min'] ?? '-', $j['horas'] ?? '-'));
    }

    if ($r['ok']) {
        chk('VERIFICAÇÃO OK' . ($dry ? ' (dry-run: estado não gravado)' : ($r['estado_gravado'] ? ' (estado gravado)' : '')));
        Logger::info('extracao', "extract_check: ok (PI {$r['pi']}, {$r['itens']} itens)");
        exit(0);
    }

    foreach ($r['alertas'] as $a) {
        chk('ALERTA: ' . $a);
        Logger::error('extracao', 'extract_check: alerta: ' . $a);
    }
    if (!$dry) {
        $n = new srvNotificadorEmail(BASE_DIR . '/' . ltrim((string)$cfg['log_notificacoes'], '/\\'), (string)$cfg['ambiente']);
        $n->notificarFalha($r['pi'], ['tipo' => 'Verificacao', 'codigo_saida' => 1, 'erros' => $r['alertas'], 'log' => 'data/logs/extracao.log']);
    } else {
        chk('dry-run: nenhuma notificação enviada.');
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO FATAL: ' . Logger::sanitize($e->getMessage()) . "\n");
    Logger::exception('extracao', 'extract_check: erro fatal', $e);
    exit(4);
}
