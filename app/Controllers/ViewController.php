<?php

namespace App\Controllers;

/**
 * ViewController
 * ──────────────
 * Renderiza páginas PHP tradicionais (server-side) com URLs limpas:
 *   /                         → Home (Visão Geral)
 *   /metricas/{secao}         → página da seção
 *   /painel                   → Painel de Controle
 *
 * Cada página monta o LAYOUT comum (header + menu + filtros) e inclui a
 * view modular (public/views/vw_{secao}.php) no lugar do conteúdo.
 *
 * As views carregam seus dados via fetch pontual às rotas JSON já
 * existentes (/metrics, /extract, /capacity/*) — sem SPA, sem reload
 * completo para atualizar gráficos.
 */
class ViewController
{
    private string $viewsDir;
    private string $layout;
    private array  $baseUrlParts;
    private array  $viewData;   // dados extras repassados ao layout/views

    /** Mapa seção → [arquivo da view, título]. URLs limpas → arquivos vw_*. */
    private const SECTIONS = [
        'overview'      => ['vw_overview.php',      'Visão Geral'],
        'velocidade'    => ['vw_velocity.php',      'Velocidade'],
        'cycle-time'    => ['vw_cycle_time.php',    'Lead Time'],
        'previsibilidade' => ['vw_previsibilidade.php', 'Previsibilidade'],
        'plan-real'     => ['vw_plan_real.php',     'Planejado x Realizado'],
        'produtividade' => ['vw_produtividade.php', 'Produtividade'],
        'epicos'        => ['vw_epicos.php',        'Status dos Épicos'],
        'sustentacao'   => ['vw_sustain.php',       'Pontos de Sustentação'],
        'painel'        => ['vw_painel.php',        'Painel de Controle'],
    ];

    public function __construct(string $viewsDir, string $basePath, array $viewData = [])
    {
        $this->viewsDir = rtrim($viewsDir, '/\\');
        $this->layout   = $this->viewsDir . '/layout.php';
        // basePath = prefixo da app no servidor (ex: "/dashboard")
        $this->baseUrlParts = ['base' => rtrim($basePath, '/')];
        $this->viewData = $viewData;
    }

    /**
     * Renderiza a seção pedida. Se não existir, cai para overview.
     */
    public function render(string $section): void
    {
        if (!isset(self::SECTIONS[$section])) {
            $section = 'overview';
        }
        [$viewFile, $title] = self::SECTIONS[$section];
        $viewPath = $this->viewsDir . '/' . $viewFile;

        if (!is_file($viewPath)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo "View não encontrada: {$viewFile}";
            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        // Variáveis disponíveis para o layout e a view
        $base          = $this->baseUrlParts['base'];
        $activeSection = $section;
        $pageTitle     = $title;
        $sections      = self::SECTIONS;
        $contentView   = $viewPath;
        // Dados extras (ex: valueTypes) disponíveis no layout.
        $valueTypes    = $this->viewData['value_types'] ?? [];
        $appVersion    = $this->viewData['version'] ?? '';

        // O layout inclui $contentView na área de conteúdo.
        if (is_file($this->layout)) {
            require $this->layout;
        } else {
            require $viewPath; // fallback sem layout
        }
    }

    /** Lista as seções (para montar o menu em qualquer lugar). */
    public static function sections(): array
    {
        return self::SECTIONS;
    }
}
