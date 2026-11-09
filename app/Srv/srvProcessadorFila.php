<?php

namespace App\Srv;

use App\Repositories\ExtractionQueueRepository;
use App\Services\ExtractionService;
use App\Services\Logger;
use App\Services\Notifier;
use Throwable;

/**
 * srvProcessadorFila
 * ──────────────────
 * Corpo do worker da fila (antes embutido em bin/queue_worker.php), extraído
 * para classe para poder ser reutilizado pelo job noturno (extract_diaria.php)
 * e testado sem processos filhos. O comportamento da fila do modal é o mesmo:
 *   - claim atômico da ordem;
 *   - processa JORNADA POR JORNADA x PI via ExtractionService (falha em uma
 *     jornada não aborta as demais);
 *   - grava extracao_log, notifica (Notifier) e marca a ordem
 *     'concluido' (ao menos um lote OK) ou 'erro' (todos falharam).
 *
 * R7: o resultado traz 'exit' = 1 quando QUALQUER ordem terminou em erro ou
 * teve lotes com falha (antes o worker saía 0 se ao menos 1 lote deu certo).
 *
 * Opções da ordem (JSON, chave 'opcoes'): forcar (bool) e fallback (bool),
 * repassadas ao ExtractionService.
 */
class srvProcessadorFila
{
    private ExtractionQueueRepository $queue;
    private ExtractionService $service;
    private Notifier $notifier;
    private array $cfg;
    private string $baseDir;
    /** @var callable */
    private $log;

    public function __construct(
        ExtractionQueueRepository $queue,
        ExtractionService $service,
        Notifier $notifier,
        array $cfg,
        callable $log,
        string $baseDir
    ) {
        $this->queue    = $queue;
        $this->service  = $service;
        $this->notifier = $notifier;
        $this->cfg      = $cfg;
        $this->log      = $log;
        $this->baseDir  = rtrim($baseDir, '/\\');
    }

    /**
     * @param int[]  $ids   ordens a processar (em ordem)
     * @param string $fonte 'jira' | 'fixture'
     * @return array{ordens:array,falhas:int,itens:int,apagados:int,fallback:int,exit:int,perdidas:int}
     */
    public function processar(array $ids, string $fonte): array
    {
        $out = ['ordens' => [], 'falhas' => 0, 'itens' => 0, 'apagados' => 0, 'fallback' => 0, 'exit' => 0, 'perdidas' => 0];

        foreach ($ids as $id) {
            $id = (int)$id;
            // ── Claim atômico ───────────────────────────────────────────
            if (!$this->queue->reivindicar($id)) {
                $this->say("Ordem #{$id}: claim perdido (outro worker pegou). Ignorando.");
                $out['perdidas']++;
                continue;
            }
            $this->say("Ordem #{$id}: claim OK, processando (fonte={$fonte}).");
            Logger::info('extracao', "Ordem #{$id}: início do processamento", ['ordem' => $id, 'fonte' => $fonte]);

            $r = $this->processarOrdem($id, $fonte);
            $out['ordens'][] = $r;
            $out['falhas']   += count($r['falhas']);
            $out['itens']    += $r['itens'];
            $out['apagados'] += $r['apagados'];
            $out['fallback'] += $r['fallback'];
            if ($r['status'] === 'erro' || $r['falhas'] !== []) {
                $out['exit'] = 1;
            }
        }
        return $out;
    }

    private function processarOrdem(int $id, string $fonte): array
    {
        $ordemRow = $this->queue->buscar($id);
        $inicio   = date('Y-m-d H:i:s');
        $ordem    = null;

        // Lê o JSON da ordem (arquivo) com fallback para parametros_json.
        $arq = $ordemRow['arquivo_json'] ?? null;
        if ($arq) {
            $path = $this->baseDir . '/' . ltrim((string)$arq, '/\\');
            if (is_file($path)) {
                $ordem = json_decode((string)file_get_contents($path), true);
            }
        }
        if (!is_array($ordem) || !isset($ordem['lotes'], $ordem['pis'])) {
            $ordem = json_decode((string)($ordemRow['parametros_json'] ?? ''), true);
        }

        $totalItens = 0;
        $totalApag  = 0;
        $totalFb    = 0;
        $falhas     = [];
        $okLotes    = 0;
        $resumo     = [];
        $lotesDet   = [];

        if (!is_array($ordem) || empty($ordem['lotes']) || empty($ordem['pis'])) {
            $falhas[] = 'ordem ilegível ou sem lotes/PIs';
            Logger::error('extracao', "Ordem #{$id}: ordem ilegível ou sem lotes/PIs", ['ordem' => $id, 'arquivo' => $arq]);
        } else {
            $opcoes = [];
            $oo = (array)($ordem['opcoes'] ?? []);
            if (array_key_exists('forcar', $oo))   $opcoes['forcar']   = (bool)$oo['forcar'];
            if (array_key_exists('fallback', $oo)) $opcoes['fallback'] = (bool)$oo['fallback'];

            // ── Jornada por jornada x PI ────────────────────────────────
            foreach ($ordem['lotes'] as $lote) {
                $jornada  = (string)($lote['jornada'] ?? '?');
                $projetos = array_values((array)($lote['projetos'] ?? []));
                foreach ($ordem['pis'] as $piDef) {
                    $pi      = (string)($piDef['pi'] ?? '');
                    $sprints = array_values((array)($piDef['sprints'] ?? []));
                    $rotulo  = "{$jornada} / {$pi}";
                    Logger::info('extracao', "Ordem #{$id}: início da jornada {$rotulo}", ['ordem' => $id, 'jornada' => $jornada, 'pi' => $pi, 'projetos' => $projetos, 'sprints' => $sprints]);
                    try {
                        $r = $this->service->extract($projetos, $pi, $sprints ?: null, $fonte, false, null, $opcoes);
                        $totalItens += $r['total_itens'];
                        $totalApag  += $r['registros_apagados'];
                        $totalFb    += (int)($r['itens_fallback'] ?? 0);
                        $okLotes++;
                        $fbTxt = (int)($r['itens_fallback'] ?? 0) > 0 ? ", fallback {$r['itens_fallback']}" : '';
                        $resumo[] = "{$rotulo}: {$r['total_itens']} itens, {$r['registros_apagados']} apagados{$fbTxt}";
                        $lotesDet[] = ['rotulo' => $rotulo, 'jornada' => $jornada, 'pi' => $pi, 'ok' => true,
                            'itens' => $r['total_itens'], 'apagados' => $r['registros_apagados'],
                            'fallback' => (int)($r['itens_fallback'] ?? 0), 'erro' => null, 'detalhe' => $r];
                        $this->say("  OK   {$rotulo}: {$r['total_itens']} itens, {$r['registros_apagados']} apagados{$fbTxt}");
                        Logger::info('extracao', "Ordem #{$id}: fim da jornada {$rotulo} (OK)", ['ordem' => $id, 'itens' => $r['total_itens'], 'apagados' => $r['registros_apagados'], 'fallback' => (int)($r['itens_fallback'] ?? 0)]);
                    } catch (Throwable $e) {
                        $falhas[] = "{$rotulo}: " . $e->getMessage();
                        $lotesDet[] = ['rotulo' => $rotulo, 'jornada' => $jornada, 'pi' => $pi, 'ok' => false,
                            'itens' => 0, 'apagados' => 0, 'fallback' => 0, 'erro' => $e->getMessage(), 'codigo' => $e->getCode()];
                        $this->say("  ERRO {$rotulo}: " . $e->getMessage());
                        Logger::exception('extracao', "Ordem #{$id}: jornada {$rotulo} FALHOU", $e, ['ordem' => $id, 'jornada' => $jornada, 'pi' => $pi]);
                    }
                }
            }
        }

        // ── Resultado, notificação, log e status ────────────────────────
        $statusOrdem = $okLotes > 0 ? 'concluido' : 'erro';
        $statusLog   = $okLotes > 0 ? 'sucesso' : 'erro';
        $mensagem = implode(' | ', $resumo);
        if ($falhas) {
            $mensagem .= ($mensagem !== '' ? ' || ' : '') . 'FALHAS: ' . implode(' | ', $falhas);
        }

        $destino = trim((string)($ordemRow['email_destino'] ?? '')) ?: (string)($this->cfg['email_destino_padrao'] ?? '');
        $assunto = "Extração #{$id} " . ($statusOrdem === 'concluido' ? 'concluída' : 'com erro');
        $corpo   = "Itens: {$totalItens}; apagados: {$totalApag}. " . $mensagem;

        $enviado = 'N';
        try {
            $enviado = $this->notifier->notify($destino, $assunto, $corpo) ? 'S' : 'N';
        } catch (Throwable $e) {
            $this->say('  Falha ao notificar: ' . $e->getMessage());
        }

        $this->queue->gravarLog($id, $inicio, date('Y-m-d H:i:s'), $statusLog, $totalItens, $totalApag, $mensagem, $enviado);
        $this->queue->atualizarStatus($id, $statusOrdem);
        $this->say("Ordem #{$id}: {$statusOrdem} (itens={$totalItens}, apagados={$totalApag}, email_enviado={$enviado}).");
        $ctxFim = ['ordem' => $id, 'status' => $statusOrdem, 'itens' => $totalItens, 'apagados' => $totalApag, 'falhas' => count($falhas)];
        if ($statusOrdem === 'erro') {
            Logger::error('extracao', "Ordem #{$id}: fim do processamento com ERRO (todas as jornadas falharam)", $ctxFim);
        } elseif ($falhas) {
            Logger::warning('extracao', "Ordem #{$id}: fim do processamento com FALHA PARCIAL", $ctxFim);
        } else {
            Logger::info('extracao', "Ordem #{$id}: fim do processamento ({$statusOrdem})", $ctxFim);
        }

        return [
            'id' => $id, 'status' => $statusOrdem, 'itens' => $totalItens, 'apagados' => $totalApag,
            'fallback' => $totalFb, 'ok_lotes' => $okLotes, 'falhas' => $falhas, 'resumo' => $resumo,
            'lotes' => $lotesDet, 'mensagem' => $mensagem,
        ];
    }

    private function say(string $msg): void
    {
        call_user_func($this->log, $msg);
    }
}
