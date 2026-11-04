<?php
/**
 * config/catalogo_overrides.php — Decisões do usuário que corrigem o catálogo
 * ───────────────────────────────────────────────────────────────────────────
 * Registro das correções aplicadas ao catálogo (jornada/times) em 04/11/2026,
 * que NÃO vêm da planilha Velocity (fonte do catálogo) e por isso precisam
 * sobreviver a uma nova carga.
 *
 * STATUS: estas correções foram aplicadas DIRETAMENTE no banco. Este arquivo é só
 * o REGISTRO (nada o lê ainda).
 *
 * ⚠ NÃO reexecutar `bin/seed_catalogo.php` enquanto não houver o motor de
 * importação: ele apaga e recria `times` (novos id_time) e deixaria
 * `produtividade_historico` e `sustentacao_historico` ÓRFÃS; além disso
 * desfaria as correções abaixo. O motor de importação (Painel) deve honrar
 * este arquivo.
 */
return [
    // projeto_key => nome da jornada (como no Jira), mesmo que a Velocity diga outra.
    'jornada_por_projeto_key' => [
        // "Pós": a Velocity lista dentro de Voomp; no Jira/Baseline é da Pós Graduação.
        'PGPO' => 'Pos Graduação',
    ],

    // Times a manter INATIVOS (times.ativo = 'N'): fundidos ou sem itens na PI.
    'inativos' => [
        'JAAM',  // Alexandria/Maia (fusão de JAAL/JAMA) — sem itens nem baseline
        'SGTH',  // Thanos — só PIs anteriores
        'SGTP',  // ThreadPool — só PIs anteriores
        'VAPP',  // Voomp / APP — sem itens nem baseline
        'JFTR',  // Triforce — fundido em JFTS (Triforce & Sparta)
        'JFSP',  // Sparta   — fundido em JFTS
        // Obs.: JFTR/JFSP têm histórico nas PIs 1/26–2/26 que não aparece enquanto inativos.
    ],
];
