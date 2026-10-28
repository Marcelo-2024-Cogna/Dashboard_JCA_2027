<?php
/**
 * config/extracao.php — parâmetros da fila de extração (v1.5, ETAPA 6b-1)
 */
return [
    // Atraso entre o agendamento e o disparo pelo worker.
    'delay_minutes'        => 10,
    // Destinatário padrão das notificações quando a ordem não informa e-mail.
    'email_destino_padrao' => 'metricas@exemplo.com',
    // Ordens 'processando' há mais de N horas são marcadas 'erro' (timeout).
    'zumbi_horas'          => 2,
    // Diretório (relativo à raiz) dos JSONs de ordem.
    'dir_agendamentos'     => 'data/agendamentos',
    // Arquivo de log do LogNotifier (relativo à raiz).
    'log_notificacoes'     => 'data/notificacoes.log',
    // Quantidade de ordens listadas em GET /extracao/fila.
    'fila_limite'          => 20,
];
