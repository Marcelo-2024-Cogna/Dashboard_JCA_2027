<?php

namespace App\Services;

/**
 * Notifier
 * ────────
 * Contrato de notificação (e-mail) do fim das extrações. A implementação
 * atual (LogNotifier) é um STUB que só registra em arquivo; o SMTP real
 * será plugado depois trocando a implementação injetada.
 */
interface Notifier
{
    /**
     * @return bool true se a notificação foi entregue/registrada.
     */
    public function notify(string $destino, string $assunto, string $corpo): bool;
}
