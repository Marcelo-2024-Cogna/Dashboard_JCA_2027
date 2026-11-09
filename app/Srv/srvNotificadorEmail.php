<?php

namespace App\Srv;

use App\Services\LogNotifier;
use App\Services\Logger;
use App\Services\Notifier;
use Throwable;

/**
 * srvNotificadorEmail
 * ───────────────────
 * Notificação de falha do job noturno. Implementa o contrato Notifier
 * (notify($destino,$assunto,$corpo)), então pode ser injetado onde o
 * LogNotifier já é usado, sem quebrar o fluxo do modal.
 *
 * Um método por CANAL:
 *   - canalLog()    SEMPRE: data/notificacoes.log + Logger (data/logs/*.log);
 *   - canalEmail()  mail() nativo do PHP (exige MTA local: sendmail/postfix);
 *                   destinatários em JOB_NOTIFY_TO (vírgula/;), remetente em
 *                   JOB_NOTIFY_FROM. Vazio => só log;
 *   - canalTeams()  RESERVADO para a v2.0 (webhook do Teams): não implementado.
 *
 * Garantias: nunca lança exceção (falha do mail() só é registrada); CR/LF são
 * removidos de assunto, remetente e destinatários (injeção de cabeçalho);
 * segredos são mascarados (Logger::sanitize) e nunca entram no corpo.
 */
class srvNotificadorEmail implements Notifier
{
    private LogNotifier $arquivo;
    private string $ambiente;
    /** @var callable function(string $to, string $assunto, string $corpo, string $cabecalhos): bool */
    private $mailer;

    public function __construct(string $logFile, string $ambiente = 'dev', ?callable $mailer = null)
    {
        $this->arquivo  = new LogNotifier($logFile);
        $this->ambiente = self::limpaLinha($ambiente) ?: 'dev';
        $this->mailer   = $mailer ?? function (string $to, string $assunto, string $corpo, string $cabecalhos): bool {
            return (bool)@mail($to, $assunto, $corpo, $cabecalhos);
        };
    }

    /** Contrato Notifier: log sempre + e-mail para o destino informado. */
    public function notify(string $destino, string $assunto, string $corpo): bool
    {
        try {
            $okLog = $this->canalLog($destino, $assunto, $corpo);
            $this->canalEmail(self::listaEmails($destino), $assunto, $corpo);
            return $okLog;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Falha/alerta do job noturno.
     * $resumo: tipo ('Extracao'|'Verificacao'), codigo_saida, jornadas_ok,
     * jornadas_erro, erros (string[]), log (caminho relativo do log).
     * @return array{log:bool,email:bool}
     */
    public function notificarFalha(string $pi, array $resumo): array
    {
        $res = ['log' => false, 'email' => false];
        try {
            $tipo    = self::limpaLinha((string)($resumo['tipo'] ?? 'Extracao'));
            $rotulo  = ($resumo['tipo'] ?? 'Extracao') === 'Verificacao' ? 'ALERTA' : 'FALHA';
            $assunto = '[Metricas JCA][' . $this->ambiente . '] ' . $tipo . ' ' . self::limpaLinha($pi) . ': ' . $rotulo;

            $linhas = [
                'Ambiente: ' . $this->ambiente,
                'PI: ' . $pi,
                'Data/hora: ' . date('Y-m-d H:i:s'),
            ];
            if (isset($resumo['codigo_saida'])) $linhas[] = 'Codigo de saida: ' . (int)$resumo['codigo_saida'];
            if (isset($resumo['jornadas_ok']) || isset($resumo['jornadas_erro'])) {
                $linhas[] = 'Jornadas ok: ' . (int)($resumo['jornadas_ok'] ?? 0) . '; com erro: ' . (int)($resumo['jornadas_erro'] ?? 0);
            }
            $erros = array_slice(array_values((array)($resumo['erros'] ?? [])), 0, 30);
            if ($erros !== []) {
                $linhas[] = 'Detalhes:';
                foreach ($erros as $e) {
                    $txt = (string)preg_replace('/\s+/', ' ', (string)$e);
                    $linhas[] = ' - ' . (function_exists('mb_substr') ? mb_substr($txt, 0, 300) : substr($txt, 0, 300));
                }
            }
            if (!empty($resumo['log'])) $linhas[] = 'Log: ' . $resumo['log'];
            $corpo = Logger::sanitize(implode("\n", $linhas));

            $para = self::listaEmails((string)getenv('JOB_NOTIFY_TO'));
            $res['log']   = $this->canalLog($para === [] ? '(sem destinatario)' : implode(',', $para), $assunto, $corpo);
            $res['email'] = $this->canalEmail($para, $assunto, $corpo);
            $this->canalTeams($assunto, $corpo);
        } catch (Throwable $e) {
            // notificar nunca derruba o job
        }
        return $res;
    }

    /** Canal LOG: arquivo de notificações + Logger. Sempre tenta. */
    public function canalLog(string $destino, string $assunto, string $corpo): bool
    {
        $ok = false;
        try {
            $ok = $this->arquivo->notify($destino, $assunto, Logger::sanitize($corpo));
            Logger::error('extracao', 'Notificação: ' . $assunto, ['destino' => $destino]);
        } catch (Throwable $e) {
            // segue
        }
        return $ok;
    }

    /**
     * Canal E-MAIL (mail() nativo). false se não há destinatário/remetente
     * válido ou se o mail() falhar; a falha é registrada, nunca lançada.
     * @param string[] $destinos
     */
    public function canalEmail(array $destinos, string $assunto, string $corpo): bool
    {
        try {
            $destinos = array_values(array_filter($destinos, function ($d) {
                return filter_var($d, FILTER_VALIDATE_EMAIL) !== false;
            }));
            if ($destinos === []) {
                Logger::warning('extracao', 'Notificação por e-mail não enviada: JOB_NOTIFY_TO vazio ou inválido');
                return false;
            }
            $from = self::limpaLinha((string)getenv('JOB_NOTIFY_FROM'));
            $cab  = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
                $cab[] = 'From: ' . $from;
            }
            $assunto = self::limpaLinha($assunto);
            $ok = (bool)call_user_func($this->mailer, implode(', ', $destinos), $assunto, Logger::sanitize($corpo), implode("\r\n", $cab));
            if ($ok) {
                Logger::info('extracao', 'Notificação por e-mail entregue ao MTA local', ['destinos' => count($destinos)]);
            } else {
                Logger::warning('extracao', 'email_falhou: mail() retornou false (MTA local ausente/indisponível); segue só com o log', ['destinos' => count($destinos)]);
            }
            return $ok;
        } catch (Throwable $e) {
            Logger::warning('extracao', 'email_falhou: exceção no envio, ignorada', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Canal TEAMS: RESERVADO para a v2.0 (webhook do Microsoft Teams).
     * Não implementado de propósito; devolve false.
     */
    public function canalTeams(string $assunto, string $corpo): bool
    {
        return false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Remove CR/LF/controle de uma linha de cabeçalho. */
    private static function limpaLinha(string $s): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    }

    /** @return string[] e-mails válidos de uma lista separada por vírgula/ponto e vírgula. */
    private static function listaEmails(string $lista): array
    {
        $out = [];
        foreach (preg_split('/[,;]+/', $lista) ?: [] as $e) {
            $e = self::limpaLinha($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false) $out[] = $e;
        }
        return array_values(array_unique($out));
    }
}
