<?php
/**
 * layout.php — Shell comum de todas as páginas
 * ─────────────────────────────────────────────
 * Variáveis esperadas (injetadas pelo ViewController):
 *   $base          string  prefixo da app (ex: "/dashboard")
 *   $activeSection string  seção ativa (ex: "produtividade")
 *   $pageTitle     string  título da página
 *   $sections      array   mapa seção => [arquivo, título]
 *   $contentView   string  caminho absoluto da view a incluir
 *
 * Ícones por seção (emoji, sem dependência de icon-font).
 */
$icons = [
    'overview'      => '◼',
    'velocidade'    => '🚀',
    'cycle-time'    => '⏱',
    'plan-real'     => '🎯',
    'produtividade' => '📈',
    'epicos'        => '🗂',
    'sustentacao'   => '🛠',
    'painel'        => '⚙',
];
// Ordem do menu (painel separado por divisória)
$menuMain = ['overview','velocidade','cycle-time','plan-real','produtividade','epicos','sustentacao'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?> · Métricas Jeito Cogna Ágil</title>
  <link rel="stylesheet" href="<?= $base ?>/assets/css/app.css" />
  <!-- Base URL + libs + núcleo JS carregados ANTES do conteúdo, para que os
       scripts inline das views já encontrem window.App e window.Charts. -->
  <script>
    window.APP_BASE = <?= json_encode($base) ?>;
    // Tipos de "entrega de valor" (config/baseline.php). Usado pelas views
    // para filtrar métricas de valor (Lead Time, Produtividade).
    window.VALUE_TYPES = <?= json_encode($valueTypes ?? []) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <script src="<?= $base ?>/assets/js/charts.js"></script>
  <script src="<?= $base ?>/assets/js/app.js"></script>
</head>
<body>
<div class="layout">

  <!-- ── Sidebar / Menu ── -->
  <aside class="sidebar">
    <div class="brand">
      <div class="logo">📊</div>
      <div>
        <div class="title">Métricas Ágil</div>
        <div class="subtitle">Jeito Cogna Ágil</div>
      </div>
    </div>
    <ul class="nav">
      <?php foreach ($menuMain as $sec): $url = $sec === 'overview' ? "$base/" : "$base/metricas/$sec"; ?>
        <li>
          <a href="<?= $url ?>" class="<?= $activeSection === $sec ? 'active' : '' ?>">
            <span class="ico"><?= $icons[$sec] ?? '•' ?></span>
            <?= htmlspecialchars($sections[$sec][1]) ?>
          </a>
        </li>
      <?php endforeach; ?>
      <li class="divider"></li>
      <li>
        <a href="<?= $base ?>/painel" class="<?= $activeSection === 'painel' ? 'active' : '' ?>">
          <span class="ico"><?= $icons['painel'] ?></span>
          <?= htmlspecialchars($sections['painel'][1]) ?>
        </a>
      </li>
    </ul>
  </aside>

  <!-- ── Header + filtros globais ── -->
  <header class="header">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>
    <?php if ($activeSection !== 'painel'): ?>
    <div class="filters" id="global-filters">
      <label>Jornada
        <select id="flt-jornada"><option value="">Todas</option></select>
      </label>
      <label>Time
        <select id="flt-time"><option value="">Todos</option></select>
      </label>
      <label>Versão Corrigida
        <select id="flt-versao"><option value="">Todas</option></select>
      </label>
      <label>Sprint
        <select id="flt-sprint"><option value="">Todas</option></select>
      </label>
      <button class="btn btn-primary" id="btn-refresh-jira" title="Buscar dados atualizados do Jira (ignora cache)">↻ Atualizar Jira</button>
      <button class="btn btn-ghost" id="btn-export-pdf" title="Exportar PDF">⬇ PDF</button>
      <button class="btn btn-ghost" id="btn-export-xlsx" title="Exportar XLSX">⬇ XLSX</button>
    </div>
    <?php endif; ?>
  </header>

  <!-- ── Conteúdo (view da seção) ── -->
  <main class="main" id="main-content">
    <?php if ($activeSection !== 'painel'): ?>
    <!-- Labels destacados dos filtros ativos -->
    <div id="active-filters" class="active-filters"></div>
    <?php endif; ?>
    <?php require $contentView; ?>
  </main>

</div>
</body>
</html>
