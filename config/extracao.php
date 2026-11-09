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

    // ── Job noturno (extract_diaria.php / extract_check.php) ─────────────
    // R1: aborta a gravação se o Jira devolver menos que este percentual das
    // linhas já existentes do recorte (0 itens com recorte existente também
    // aborta; recorte vazio não tem base de comparação). --forcar ignora.
    'limiar_parcial'       => 0.8,
    // R6: retry com backoff exponencial no cliente Jira.
    'retry' => [
        'tentativas'    => 4,
        'espera_base_s' => 2,
        'espera_max_s'  => 60,
        'timeout_s'     => 30,
    ],
    // R5: pasta dos locks (relativa à raiz) e espera máxima pelo lock da PI.
    'lock_dir'             => 'data/locks',
    'lock_espera_s'        => 600,
    // Virada de PI: a PI anterior continua sendo extraída por N dias após o data_fim.
    'periodo_graca_dias'   => 14,
    // Liga o fallback por sprint (itens da PI sem fixVersion / com fixVersion de outra PI).
    'fallback_sprints'     => true,
    // Verificador (23:30): defasagem máxima (h), queda brusca e arquivo de estado.
    'checagem_horas_max'   => 25,
    'checagem_queda_pct'   => 0.8,
    'estado_check'         => 'data/extract_check_estado.json',
    // Rótulo do ambiente nos avisos (APP_ENV no servidor: producao, dev...).
    'ambiente'             => getenv('APP_ENV') ?: 'dev',
];
