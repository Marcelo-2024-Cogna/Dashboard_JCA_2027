<?php
/**
 * bin/queue_worker.php — WORKER da fila de extração (v1.5, ETAPA 6b-1)
 * ─────────────────────────────────────────────────────────────────────────
 * Wrapper fino sobre App\Srv\srvProcessadorFila (a lógica vive na classe, que
 * também é usada pelo job noturno bin/extract_diaria.php). A cada execução:
 *   1. marca como 'erro' (timeout) ordens 'processando' há mais de N horas;
 *   2. seleciona ordens 'pendente' com agendado_para <= NOW() (em ordem);
 *   3. faz CLAIM ATÔMICO (UPDATE ... WHERE status='pendente'; rowCount()==1);
 *   4. processa JORNADA POR JORNADA x PI via ExtractionService (um lote de
 *      projetos por vez, para não estourar timeout). Falha em uma jornada
 *      não aborta as demais;
 *   5. grava extracao_log, notifica (Notifier; hoje LogNotifier = stub) e
 *      marca 'concluido' (ao menos um lote OK) ou 'erro' (todos falharam).
 *
 * OPÇÕES:
 *   --fonte=jira|fixture   default jira (fixture = teste sem Jira)
 *   --agora                ignora o atraso (agendado_para) — apenas testes
 *   --id=N                 processa somente a ordem N — apenas testes
 *
 * CÓDIGOS DE SAÍDA:
 *   0  nada pendente, ou todas as ordens processadas sem falha
 *   1  alguma ordem terminou em erro OU teve lotes com falha (R7)
 *   2  opção inválida
 *
 * Respeita a env var JIRA_SSL_VERIFY do ambiente (ex JIRA_SSL_VERIFY=0).
 *
 * AGENDAMENTO (rodar a cada 1 minuto; o claim atômico evita duplo processamento):
 *   Linux (crontab -e):
 *     * * * * * /usr/bin/php /caminho/do/projeto/bin/queue_worker.php >> /var/log/metricas_worker.log 2>&1
 *   Windows (Task Scheduler, repetir a cada 1 minuto):
 *     C:\xampp\php\php.exe bin\queue_worker.php   (iniciar em: pasta do projeto)
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

use App\Repositories\ExtractionQueueRepository;
use App\Services\ExtractionService;
use App\Services\LogNotifier;
use App\Services\Logger;
use App\Srv\srvProcessadorFila;

$opts   = getopt('', ['fonte::', 'agora', 'id::']);
$fonte  = strtolower(trim((string)($opts['fonte'] ?? 'jira')));
$agora  = array_key_exists('agora', $opts);
$soId   = isset($opts['id']) ? (int)$opts['id'] : null;
if (!in_array($fonte, ['jira', 'fixture'], true)) {
    fwrite(STDERR, "Valor inválido para --fonte: {$fonte} (use jira|fixture)\n");
    exit(2);
}

function logw(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

$cfg      = require BASE_DIR . '/config/extracao.php';
$queue    = new ExtractionQueueRepository();
$service  = new ExtractionService();
$notifier = new LogNotifier(BASE_DIR . '/' . ltrim((string)$cfg['log_notificacoes'], '/\\'));

// ── 1. Zumbis ───────────────────────────────────────────────────────────────
foreach ($queue->marcarZumbis((int)$cfg['zumbi_horas']) as $zid) {
    logw("Ordem #{$zid} em 'processando' além do limite → marcada 'erro' (timeout).");
    Logger::warning('extracao', "Ordem #{$zid} zumbi: 'processando' além do limite, marcada 'erro' (timeout)", ['ordem' => $zid, 'zumbi_horas' => (int)$cfg['zumbi_horas']]);
}

// ── 2. Ordens vencidas ──────────────────────────────────────────────────────
$ids = $queue->pendentesVencidas($agora, $soId);
if ($ids === []) {
    logw('Nenhuma ordem pendente vencida.');
    exit(0);
}

// ── 3-5. Claim, processamento, log, notificação e status ───────────────────
$processador = new srvProcessadorFila($queue, $service, $notifier, $cfg, 'logw', BASE_DIR);
$resultado   = $processador->processar($ids, $fonte);

exit($resultado['exit']);
