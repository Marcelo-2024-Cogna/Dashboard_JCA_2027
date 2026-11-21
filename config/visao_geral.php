<?php
/**
 * config/visao_geral.php — FONTE ÚNICA de listas de status, limites e janelas
 * ───────────────────────────────────────────────────────────────────────────
 * Única fonte de verdade para a classificação de status, os limites de
 * tamanho de resposta e as janelas temporais usadas pela Visão Geral
 * (VisaoGeralCalculator / VisaoGeralRepository).
 *
 * Os nomes de status são usados EXATAMENTE como aparecem no banco
 * (vw_item / extracao_jira): atenção à acentuação — os valores reais são
 * acentuados ('Revisão de código', 'Concluído', 'Em produção',
 * 'Pendente de Produção'). Qualquer divergência de acento joga o status
 * em status_nao_mapeados (AC-5b exige vazio na PI 4/26).
 */

return [
    // ── Classificação de status ───────────────────────────────────────────
    'status' => [
        'cancelado'    => ['Cancelado'],
        'bloqueado'    => ['Bloqueado', 'Em espera'],
        'backlog'      => ['Backlog', 'Refinamento'],
        // Divergência CONSCIENTE do filtro Jira 19364: 'Concluído',
        // 'Homologação', 'Pronto', 'Em produção' e 'Pendente de Produção'
        // são tratados aqui como em_andamento — itens em fluxo que ainda
        // NÃO foram aceitos (aceito vem da coluna `entregue`, não do status;
        // ver A.2 premissa 3 e o censo de status da Parte B do design).
        // 'Refinamento'/'Homologação'/'Pronto' ficam como salvaguarda,
        // mesmo não aparecendo no universo valor+sustentação hoje.
        'em_andamento' => [
            'Em Andamento',
            'Em Desenvolvimento',
            'Em teste',
            'Revisão de código',
            'Homologação',
            'Concluído',
            'Pronto',
            'Em produção',
            'Pendente de Produção',
        ],
    ],

    // Fonte do cálculo de produtividade por sprint ('sql' = via SQL direto).
    'produtividade_por_sprint' => 'sql',

    // ── Janelas e limites ───────────────────────────────────────────────────
    'janela_chamados_dias'   => 30,  // janela (dias) para a contagem de chamados
    'limite_chamado_dias'    => 2,   // limite (dias) de resolução de chamado
    'max_linhas_lista'       => 500, // teto de linhas na lista de itens
    'max_linhas_tempo_status' => 300, // teto de linhas no tempo por status
    'aviso_defasagem_dias'   => 1,   // (legado) defasagem em dias; o aviso agora usa horas
    'aviso_defasagem_horas'  => 25,  // defasagem (h) da carga mais antiga que dispara aviso

    // Efficiency Baseline Ratio: limite de apresentação (% de horas em itens multissprint)
    // acima do qual a tela exibe aviso. Não é regra de cálculo.
    'efficiency' => ['aviso_multissprint_pct' => 10],

    // Fator de Carga (demanda planejada ÷ capacidade líquida). Decisão 2 do usuário:
    // 80% de disponibilidade para todos. Decisão (a) do plano da Fase 2: o planejado
    // conta só entrega de valor (sustentação/suporte fora); 'incluir_sustentacao'
    // troca o fc_pct principal, e a decomposição é sempre exibida.
    // Faixas (%): < subutilizacao_ate; até recomendada_ate; até limite; acima do limite.
    'fator_carga' => [
        'disponibilidade_pct' => 80,
        'incluir_sustentacao' => false,
        'faixas' => ['subutilizacao_ate' => 85, 'recomendada_ate' => 95, 'limite' => 100],
    ],

    // ── Cache de resposta em arquivo (GET /graficos/visao-geral) ────────────
    // Desligar: 'ativo' => false aqui, ou VG_CACHE=0 no ambiente (SetEnv no vhost).
    // 'dir' relativo é resolvido contra a raiz do projeto.
    'cache' => [
        'ativo'           => true,
        'ttl_s'           => 900,          // expiração máxima (cobre edições não rastreadas)
        'dir'             => 'data/cache',
        'max_bytes'       => 4194304,      // tamanho máximo por entrada
        'espera_trava_s'  => 5,            // espera de quem perde a corrida da regeneração
        'servir_stale'    => true,         // serve a versão anterior enquanto outro regenera
        'limpeza_idade_s' => 86400,        // entradas sem regravação por 24 h são apagadas
    ],
];
