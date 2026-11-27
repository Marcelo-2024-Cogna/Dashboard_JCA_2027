<?php
/**
 * config/sinalizadores.php — Critério das cores (sinalizadores) do dashboard
 * ──────────────────────────────────────────────────────────────────────────
 * ÚNICO lugar para ajustar os limites de verde/amarelo/vermelho. Basta editar
 * e recarregar a página: o ViewController injeta este array em window.SINAIS
 * e o helper App.Sinal (public/assets/js/sinal.js) classifica cada valor.
 *
 * Regra 'menor_melhor' (tempos, horas por ponto):
 *   desvio = (valor - referência) / referência * 100
 *   desvio <= verde_ate_pct   → verde
 *   desvio <= amarelo_ate_pct → amarelo
 *   acima disso               → vermelho
 *   Valor abaixo da referência é sempre verde; referência vazia ou <= 0 → neutro.
 *
 * Regra 'maior_melhor' (velocidade, % concluído do Escopo Final):
 *   p >= meta_pct     → verde
 *   p >= atencao_pct  → amarelo
 *   abaixo disso      → vermelho
 */
$baseline = require __DIR__ . '/baseline.php';

return [
    // Cycle time: dias úteis por etapa/fase contra a média da mesma etapa.
    'cycle_time' => [
        'direcao'         => 'menor_melhor',
        'verde_ate_pct'   => 10,
        'amarelo_ate_pct' => 25,
        // Alvo fixo em dias por etapa. null = usa a referência automática
        // (média das sprints fechadas da PI).
        'alvo_dias' => [
            'desenvolvimento'        => null,
            'homologacao'            => null,
            'implantacao'            => null,
            'lead_time'              => null,
            'aguardando_homologacao' => null,
            'aguardando_implantacao' => null,
        ],
        // Janelas com menos itens que isto ficam neutras (sem cor).
        'min_itens_referencia' => 3,
    ],

    // Velocidade: % concluído do Escopo Final.
    'velocidade' => [
        'direcao'     => 'maior_melhor',
        'meta_pct'    => $baseline['predictability_target'], // mesma meta da Previsibilidade
        'atencao_pct' => 60,                                  // mesmo corte do faixa() da Previsibilidade
    ],

    // Produtividade: h/ponto por time e PI contra a referência de planejamento do time.
    'produtividade' => [
        'direcao'         => 'menor_melhor',
        'verde_ate_pct'   => 10,
        'amarelo_ate_pct' => 25,
    ],

    // Lead time pendente: dias aguardando implantação contra a média de Implantação da seleção.
    'lead_time_pendente' => [
        'direcao'         => 'menor_melhor',
        'verde_ate_pct'   => 10,
        'amarelo_ate_pct' => 25,
    ],
];
