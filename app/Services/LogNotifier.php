<?php

namespace App\Services;

/**
 * LogNotifier — implementação STUB de Notifier: não envia e-mail,
 * apenas acrescenta a notificação a um arquivo de log.
 */
class LogNotifier implements Notifier
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    public function notify(string $destino, string $assunto, string $corpo): bool
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $linha = sprintf(
            "[%s] PARA=%s | ASSUNTO=%s | %s\n",
            date('Y-m-d H:i:s'),
            $destino,
            str_replace(["\r", "\n"], ' ', $assunto),
            str_replace(["\r", "\n"], ' ', $corpo)
        );
        return @file_put_contents($this->logFile, $linha, FILE_APPEND | LOCK_EX) !== false;
    }
}
