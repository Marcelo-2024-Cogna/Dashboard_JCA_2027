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
    'previsibilidade' => '🔮',
    'plan-real'     => '🎯',
    'produtividade' => '📈',
    'epicos'        => '🗂',
    'sustentacao'   => '🛠',
    'painel'        => '⚙',
];
// Ordem do menu (painel separado por divisória)
$menuMain = ['overview','velocidade','cycle-time','previsibilidade','plan-real','produtividade','epicos','sustentacao'];
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
  <script src="<?= $base ?>/assets/js/extracao.js"></script>
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
    <div class="header-title">
      <h2><?= htmlspecialchars($pageTitle) ?></h2>
      <span class="app-brand">Métricas Jeito Cogna Ágil
        <?php if (!empty($appVersion)): ?><span class="app-version"><?= htmlspecialchars($appVersion) ?></span><?php endif; ?>
      </span>
    </div>
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
      <button type="button" class="btn btn-primary" id="btn-extrair" aria-haspopup="dialog" title="Agendar uma extração do Jira por jornada, PI, sprint e time (e acompanhar a fila)">⬇ Extrair do Jira</button>
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

<?php if ($activeSection !== 'painel'): ?>
<!-- ── Modal de extração do Jira (elemento nativo <dialog>) ── -->
<dialog id="dlg-extracao" class="modal" aria-labelledby="ext-titulo">
  <form method="dialog" class="modal-form" id="ext-form" novalidate>
    <header class="modal-head">
      <h3 id="ext-titulo">Extrair do Jira</h3>
      <button type="button" class="btn btn-ghost modal-x" id="ext-fechar" aria-label="Fechar">✕</button>
    </header>

    <div class="modal-tabs" role="tablist" aria-label="Seções da extração">
      <button type="button" class="tab is-active" role="tab" id="tab-nova" aria-controls="painel-nova" aria-selected="true">Nova extração</button>
      <button type="button" class="tab" role="tab" id="tab-fila" aria-controls="painel-fila" aria-selected="false" tabindex="-1">Fila de extrações</button>
    </div>

    <div class="modal-body">
      <!-- Aba 1: nova extração -->
      <section id="painel-nova" role="tabpanel" aria-labelledby="tab-nova">
        <div id="ext-carregando" class="loading" role="status">Carregando catálogo…</div>
        <div id="ext-campos" hidden>
          <div class="ext-grid">
            <fieldset class="ext-box" id="box-jornadas">
              <legend>Jornadas <span class="req" aria-hidden="true">*</span></legend>
              <div class="ext-tools">
                <button type="button" class="link-btn" data-sel="all" data-alvo="jornadas">Selecionar todos</button>
                <button type="button" class="link-btn" data-sel="none" data-alvo="jornadas">Limpar</button>
              </div>
              <div class="ext-list" id="lst-jornadas"></div>
            </fieldset>

            <fieldset class="ext-box" id="box-pis">
              <legend>Versões Corrigidas / PIs <span class="req" aria-hidden="true">*</span></legend>
              <div class="ext-tools">
                <button type="button" class="link-btn" data-sel="all" data-alvo="pis">Selecionar todos</button>
                <button type="button" class="link-btn" data-sel="none" data-alvo="pis">Limpar</button>
              </div>
              <div class="ext-list" id="lst-pis"></div>
            </fieldset>

            <fieldset class="ext-box" id="box-sprints">
              <legend>Sprints</legend>
              <div class="ext-tools">
                <button type="button" class="link-btn" data-sel="none" data-alvo="sprints" title="Equivale a não filtrar por sprint">Todas as sprints da PI</button>
                <button type="button" class="link-btn" data-sel="all" data-alvo="sprints">Selecionar todos</button>
                <button type="button" class="link-btn" data-sel="none" data-alvo="sprints">Limpar</button>
              </div>
              <div class="ext-list" id="lst-sprints"></div>
              <p class="ext-hint">Nenhuma marcada = todas as sprints das PIs escolhidas.</p>
            </fieldset>

            <fieldset class="ext-box" id="box-times">
              <legend>Times</legend>
              <div class="ext-tools">
                <button type="button" class="link-btn" data-sel="all" data-alvo="times">Selecionar todos</button>
                <button type="button" class="link-btn" data-sel="none" data-alvo="times">Limpar</button>
              </div>
              <div class="ext-list" id="lst-times"></div>
              <p class="ext-hint">Nenhum marcado = todos os times das jornadas escolhidas.</p>
            </fieldset>
          </div>

          <div class="field ext-email">
            <label for="ext-email">E-mail para notificação</label>
            <input type="email" id="ext-email" autocomplete="email" placeholder="nome@empresa.com" aria-describedby="ext-email-erro" />
            <span id="ext-email-erro" class="ext-field-erro" role="alert"></span>
          </div>

          <p class="ext-resumo" id="ext-resumo" aria-live="polite"></p>
          <p class="ext-hint">A extração é disparada em ~10 minutos após o agendamento.</p>
        </div>
      </section>

      <!-- Aba 2: fila -->
      <section id="painel-fila" role="tabpanel" aria-labelledby="tab-fila" hidden>
        <div class="fila-tools">
          <button type="button" class="btn btn-ghost" id="fila-atualizar">↻ Atualizar</button>
          <span class="ext-hint" id="fila-quando"></span>
        </div>
        <div id="fila-lista" aria-live="polite"></div>
      </section>

      <!-- Mensagens (sucesso / erro / aviso de duplicidade) -->
      <div id="ext-msg" class="ext-msg" role="status" aria-live="polite"></div>
    </div>

    <footer class="modal-foot">
      <button type="button" class="btn btn-ghost" id="ext-cancelar">Cancelar</button>
      <button type="button" class="btn btn-ghost" id="ext-confirmar" hidden>Confirmar e substituir</button>
      <button type="submit" class="btn btn-primary" id="ext-agendar" disabled>Agendar extração</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
</body>
</html>
