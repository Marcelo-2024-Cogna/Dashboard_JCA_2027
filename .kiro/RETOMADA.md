# Retomada — Métricas Jeito Cogna Ágil (v1.5)

> Handoff para abrir a próxima sessão. Atualizado em 28/10/2026 (noite).
> A v1.0 (JSON + extração via HTTP) está congelada em `dashboard-v1.0/`
> (http://localhost:8080/dashboard-v1.0/) e no histórico do git.

## 1. Ambiente
- Dashboard v1.5: http://localhost:8080/dashboard/ (XAMPP Apache, porta 8080).
- MySQL 8.4 · banco `metricas_agil` · usuário de app `metricas_jca` (credenciais
  de dev em `config/db.php`, fora do git; modelo em `config/db.php.example`).
- PHP CLI: `C:\xampp\php\php.exe`. Fuso único `America/Sao_Paulo` (config/app.php).
- Jira real só responde com `JIRA_SSL_VERIFY=0` (proxy corporativo; app só na
  rede interna/VPN). Deploy futuro: Linux CentOS + crontab.
- Logs de texto em `data/logs/` (extracao, importacao, dashboard, erros).

## 2. O que está pronto (verificado)
- **Banco**: 12 tabelas InnoDB (`db/schema.sql`), views em `db/views.sql`,
  trigger de auditoria da baseline em `db/triggers.sql` (aplicar com conta
  privilegiada). Catálogo: 46 times (10 jornadas), PIs 1/26–4/26, sprints.
- **Extração Jira → MySQL**: `ExtractionService`, `bin/extract_runner.php`,
  fila (`/extracao/catalogo|agendar|fila`), `bin/queue_worker.php` (cron),
  modal "Extrair do Jira" no header, e-mail em modo STUB.
- **Dados hoje (PI 4/26)**: `extracao_jira` 1374 itens (Secretaria: SGGU, SGSE,
  SGGS, SGTB) · `baseline` 3807 · `baseline_ajuste` 10 (regularizados).
- **Importação**: `bin/import_baseline.php` (imutável por PI), `bin/seed_catalogo.php`,
  `bin/seed_sustentacao.php`, `bin/backfill_sprint_ref.php`.
- **Conferência**: `bin/conferir_planilha.php` (planilha x banco, relatório em
  `data/exports/`), `bin/check_apoio_dates.php`.
- **Ajuste de baseline**: `bin/ajustar_baseline.php` (motivo + responsável
  obrigatórios, `--listar`, `--reverter`); trigger registra também UPDATE direto.
- **Filtros do header** lendo o banco (`/filtros`): Jornada → Time → Versão → Sprint.
- **Páginas novas**: `/metricas/previsibilidade` e `/metricas/plan-real`
  (Planejado x Realizado, 4 barras como `Template_Proposta_Grafico_Plan_x_Real.png`).
- Detalhes das regras de negócio e da virada de PI: `data/import/README.md`.

## 3. Regras de negócio fechadas
- **Entrega de valor**: História, Enabler, Débito Técnico, Spike, Teste, Segurança.
  **Entregue**: Aceito, Pendente de Produção, Em produção. **Com épico**: `pai` preenchido.
- **Sustentação** = tipo `Sustentação` **e `Suporte`** (decidido 28/10; aplicado
  no código — tipos em `config/baseline.php` → `plan_real_sustentacao_types`).
- **Ideação negativa vale 1** (item criado depois do ponto de compromisso).
- **Escopo da extração**: só `fixVersion ~ "*PI x/yy*"` (sem `OR Sprint`).
- **Janela**: sprints de tipo `valor` da PI com data_fim < hoje; filtro de sprint
  vence. Hoje fechadas: SP 26.4.1 e SP 26.4.2. PI 4/26 tem 8 sprints (7 e 8 = sustentação).
- **Previsibilidade** = Story Points ATUAIS dos itens planejados que estão
  entregues ÷ **Story Point Estimate da baseline** dos itens planejados para a
  janela. Sem fallback para o estimate quando SP está vazio. Transbordo é
  mostrado em baldes (entregue na janela/após, transbordo aberto, backlog,
  cancelado, fora da extração).
- **Planejado x Realizado**: Velocidade = sustentação (média da PI passada) +
  capacidade; PI Planning = SPE da baseline; Sprint Planning = SPE da extração
  nas sprints da janela (sem cancelados); Entregues = SP atuais entregues.
  Cada barra por Sustentação / Valor sem épico / Valor com épico.
- **Sprint Planning fiel (snapshot no início da sprint)**: fica como está por
  enquanto; ideia futura.

## 4. PRÓXIMOS PASSOS (ordem sugerida para amanhã)
1. ~~**Aplicar "Suporte = Sustentação"**~~ **CONCLUÍDA**: categoria sustentação
   (Sustentação + Suporte) lida de `plan_real_sustentacao_types` e aplicada no
   `PlanejadoRealizadoRepository`; Suporte saiu de `tipos_fora_das_barras`.
2. ~~**Gráfico de Lead Time**~~ **CONCLUÍDO** (`/metricas/cycle-time`, endpoint
   `/graficos/lead-time`, view `vw_lead_time_item`, `LeadTimeRepository`). Definição:
   itens de valor ENTREGUES com sprint_ref na janela; lead time do item = Dev + Hom
   + Impl (soma das etapas que o item tem; **sem ideação**); segmentos da barra =
   contribuição da etapa (Σ etapa ÷ nº de itens), logo somam o lead time médio;
   média da jornada = média de todos os itens da jornada (não das médias dos times).
   Também no endpoint: média nos itens que têm a etapa (n), P50 e máx. por time
   (outliers: dado apenas, sem tratamento). A `vw_cycle_time` antiga segue
   existindo (usada por `MetricsRepository::cycleTime()`). Detalhes em
   `data/import/README.md`.
3. **Gráfico de Produtividade** (`Template_Proposta_Grafico_Produtividade.png`):
   horas para entregar 1 ponto — barra da jornada + colunas por time ao longo das
   PIs. **Atenção**: `produtividade_historico` está VAZIA e as extrações de PIs
   passadas não existem no banco; definir a fonte do histórico (planilha Velocity,
   aba 2026_Produtividade / pivot) e criar o carregamento.
4. Ajustes visuais da Previsibilidade: 7º card quebra de linha; coluna "Jornada"
   redundante nas tabelas quando há uma só jornada.

## 5. Fases seguintes (antes da PI 1/27)
- **Importadores + modal de validação/importação** (Baseline × Velocity):
  validação síncrona (nomes de jornada/time, `projeto_key` órfão, PIs/sprints,
  fixVersions, negativos), carga com confirmação, upload endurecido, auditoria.
  Falta importador da aba **Apoio** (calendário: `configuracao_pi` + `sprints`) e
  decidir de onde vem o **tipo da sprint** (valor|sustentação). A base será
  **zerada e reimportada** quando o modal existir (ajustes de teste somem).
- **Painel de Controle**: ainda grava JSON da v1.0 (`/settings`, `data/config/`);
  migrar para o banco (ativação de registros, parâmetros, agendador).
- **Páginas legadas** (Visão Geral, Velocidade; Épicos e Sustentação PAUSADOS):
  refazer sobre as views; podem estar degradadas pelos filtros novos.
- **Relatório de Sustentação** (carry-over de PI anterior e itens sem versão
  ficam fora da extração por fixVersion — revisitar).
- **Extração**: SMTP real (hoje stub); registrar cron/Task Scheduler para o
  `queue_worker` (nenhum agendado ainda); medir extração por jornada em lote.
- **Deploy CentOS**: gerar `.sql` de deploy (schema, views, triggers com conta
  privilegiada), crontab, senha forte do banco, CA bundle em vez de SSL_VERIFY=0.
- **Segurança**: `config/jira.php` tem o token do Jira em texto — mover para
  variável de ambiente e rotacionar antes de compartilhar/subir o repositório.
  App sem autenticação (depende de VPN/DMZ); upload do modal exigirá cuidado.
- **Documentação para os pares** (arquitetura, fórmulas, fluxo).

## 6. Pendências com terceiros / dados
- **Ingresso**: de-para de `projeto_key` ajustado conforme DM do Ingresso (IN*);
  Backoffice e Ecomm foram divididos em 2 times (herdaram a velocidade do
  original); Cursos Técnicos (INCT) sem dados na Velocity; planilha Velocity
  do DM Ingresso em atualização.
- **Governança**: SGGS-18402 (3 SP, entregue na 26.4.x) foi reetiquetado para PI 1/27.
- Thanos e ThreadPool sem extração na PI 4/26 (aviso na tela).

## 7. Como trabalhamos (para manter o padrão)
- Implementação via agentes (workflow) com brief completo; **verificação
  independente minha** depois: cálculo direto das tabelas x endpoint, screenshots
  com Chrome headless (`C:\Program Files\Google\Chrome\Application\chrome.exe
  --headless=new --screenshot=...`), lint `php -l` e `node --check`.
- Não editar baseline/extração por `UPDATE` direto: usar `bin/ajustar_baseline.php`.
- PowerShell corrompe UTF-8 em `Set-Content`: gravar arquivos com a ferramenta de edição.
