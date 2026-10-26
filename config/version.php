<?php
/**
 * config/version.php — Versão da aplicação
 * ─────────────────────────────────────────
 * Fonte única do número de versão exibido no header do dashboard.
 *
 * Histórico:
 *   1.0 → estrutura em JSON, extração do Jira via request HTTP (congelada
 *         em c:\xampp\htdocs\dashboard-v1.0).
 *   1.5 → migração para banco relacional + serviço batch de catálogo e
 *         extração sob demanda com modal de seleção (em andamento).
 */
return [
    'number' => '1.5',
    'label'  => 'v1.5',
];
