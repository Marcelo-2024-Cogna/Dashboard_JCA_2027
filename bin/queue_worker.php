<?php
/**
 * bin/queue_worker.php — WORKER da fila de extração (v1.5, ETAPA 6b-1)
 * ─────────────────────────────────────────────────────────────────────────
 * A cada execução:
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
 * Respeita a env var JIRA_SSL_VERIFY do ambiente (ex JIRA_SSL_VERIFY=0).
 *
 * AGENDAMENTO (rodar a cada 1 minuto; o claim atômico evita duplo processamento):
 *   CentOS (crontab -e):
 *     * * * * * /usr/bin/php /caminho/do/projeto/bin/queue_worker.php >> /var/log/metricas_worker.log 2>&1
 *   Windows (Task Scheduler, repetir a cada 1 minuto):
 *     C:\xampp\php\php.exe bin\queue_worker.php   (iniciar em: pasta do projeto)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}

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
use App\Services\Notifier;

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
/** @var Notifier $notifier */
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

foreach ($ids as $id) {
    // ── 3. Claim atômico ────────────────────────────────────────────────────
    if (!$queue->reivindicar($id)) {
        logw("Ordem #{$id}: claim perdido (outro worker pegou). Ignorando.");
        continue;
    }
    logw("Ordem #{$id}: claim OK, processando (fonte={$fonte}).");
    Logger::info('extracao', "Ordem #{$id}: início do processamento", ['ordem' => $id, 'fonte' => $fonte]);

    $ordemRow = $queue->buscar($id);
    $inicio   = date('Y-m-d H:i:s');
    $ordem    = null;

    // Lê o JSON da ordem (arquivo) com fallback para parametros_json.
    $arq = $ordemRow['arquivo_json'] ?? null;
    if ($arq) {
        $path = BASE_DIR . '/' . ltrim($arq, '/\\');
        if (is_file($path)) {
            $ordem = json_decode((string)file_get_contents($path), true);
        }
    }
    if (!is_array($ordem) || !isset($ordem['lotes'], $ordem['pis'])) {
        $ordem = json_decode((string)($ordemRow['parametros_json'] ?? ''), true);
    }

    $totalItens = 0;
    $totalApag  = 0;
    $falhas     = [];
    $okLotes    = 0;
    $resumo     = [];

    if (!is_array($ordem) || empty($ordem['lotes']) || empty($ordem['pis'])) {
        $falhas[] = 'ordem ilegível ou sem lotes/PIs';
        Logger::error('extracao', "Ordem #{$id}: ordem ilegível ou sem lotes/PIs", ['ordem' => $id, 'arquivo' => $arq]);
    } else {
        // ── 4. Jornada por jornada x PI ─────────────────────────────────────
        foreach ($ordem['lotes'] as $lote) {
            $jornada  = (string)($lote['jornada'] ?? '?');
            $projetos = array_values((array)($lote['projetos'] ?? []));
            foreach ($ordem['pis'] as $piDef) {
                $pi      = (string)($piDef['pi'] ?? '');
                $sprints = array_values((array)($piDef['sprints'] ?? []));
                $rotulo  = "{$jornada} / {$pi}";
                Logger::info('extracao', "Ordem #{$id}: início da jornada {$rotulo}", ['ordem' => $id, 'jornada' => $jornada, 'pi' => $pi, 'projetos' => $projetos, 'sprints' => $sprints]);
                try {
                    $r = $service->extract($projetos, $pi, $sprints ?: null, $fonte);
                    $totalItens += $r['total_itens'];
                    $totalApag  += $r['registros_apagados'];
                    $okLotes++;
                    $resumo[] = "{$rotulo}: {$r['total_itens']} itens, {$r['registros_apagados']} apagados";
                    logw("  OK   {$rotulo}: {$r['total_itens']} itens, {$r['registros_apagados']} apagados");
                    Logger::info('extracao', "Ordem #{$id}: fim da jornada {$rotulo} (OK)", ['ordem' => $id, 'itens' => $r['total_itens'], 'apagados' => $r['registros_apagados']]);
                } catch (Throwable $e) {
                    $falhas[] = "{$rotulo}: " . $e->getMessage();
                    logw("  ERRO {$rotulo}: " . $e->getMessage());
                    Logger::exception('extracao', "Ordem #{$id}: jornada {$rotulo} FALHOU", $e, ['ordem' => $id, 'jornada' => $jornada, 'pi' => $pi]);
                }
            }
        }
    }

    // ── 5. Resultado, notificação, log e status ─────────────────────────────
    $statusOrdem = $okLotes > 0 ? 'concluido' : 'erro';
    $statusLog   = $okLotes > 0 ? 'sucesso' : 'erro';
    $mensagem = implode(' | ', $resumo);
    if ($falhas) {
        $mensagem .= ($mensagem !== '' ? ' || ' : '') . 'FALHAS: ' . implode(' | ', $falhas);
    }

    $destino = trim((string)($ordemRow['email_destino'] ?? '')) ?: (string)$cfg['email_destino_padrao'];
    $assunto = "Extração #{$id} " . ($statusOrdem === 'concluido' ? 'concluída' : 'com erro');
    $corpo   = "Itens: {$totalItens}; apagados: {$totalApag}. " . $mensagem;

    $enviado = 'N';
    try {
        $enviado = $notifier->notify($destino, $assunto, $corpo) ? 'S' : 'N';
    } catch (Throwable $e) {
        logw("  Falha ao notificar: " . $e->getMessage());
    }

    $queue->gravarLog($id, $inicio, date('Y-m-d H:i:s'), $statusLog, $totalItens, $totalApag, $mensagem, $enviado);
    $queue->atualizarStatus($id, $statusOrdem);
    logw("Ordem #{$id}: {$statusOrdem} (itens={$totalItens}, apagados={$totalApag}, email_enviado={$enviado}).");
    $ctxFim = ['ordem' => $id, 'status' => $statusOrdem, 'itens' => $totalItens, 'apagados' => $totalApag, 'falhas' => count($falhas)];
    if ($statusOrdem === 'erro') {
        Logger::error('extracao', "Ordem #{$id}: fim do processamento com ERRO (todas as jornadas falharam)", $ctxFim);
    } else {
        Logger::info('extracao', "Ordem #{$id}: fim do processamento ({$statusOrdem})", $ctxFim);
    }
}
