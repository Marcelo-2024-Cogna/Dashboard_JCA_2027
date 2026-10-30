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
- **Dados hoje (29/10, cobertura de dados)**: `extracao_jira` **14119** itens —
  PI 1/26 2624 · PI 2/26 2317 · PI 3/26 2350 (só Secretaria) · **PI 4/26 6828**
  (TODAS as 10 jornadas, PI inteira, sem filtro de sprint). PI 4/26 por jornada:
  Secretaria 1374 · Aprendizagem Digital 1295 · Ingresso 1122 · Atendimento e Serviços 819
  (reextraído pela PI inteira; antes 409 só das SP 26.4.1/2) · Jornada Acadêmica 507 ·
  Financeiro 478 · Voomp 404 · Pós Graduação 340 · Saber 302 · Inteligência Artificial 187.
  `baseline` 3807 (só PI 4/26; telas de Previsibilidade das PIs passadas avisam
  "sem baseline") · `baseline_ajuste` 10 (regularizados) ·
  `produtividade_historico` 11.
  - **Projetos sem itens na PI 4/26** (chave aceita pelo Jira, sem item com fixVersion PI 4/26;
    decisão de de-para é do usuário): JFTR (Financeiro/Triforce), JAAM (Acadêmica/Alexandria-Maia),
    VAPP (Voomp/APP). JFSP (Sparta) tem 1 item e JFTS concentra o que parece ser "Triforce & Sparta".
    Nenhuma `projeto_key` do catálogo falhou (nenhum HTTP 400).
  - **Cobertura baixa vs baseline** (<80%): a baseline traz itens cuja fixVersion NÃO inclui
    PI 4/26 (ex.: só "[PGCM] PI 3/26"), que a JQL `fixVersion ~ "*PI 4/26*"` não devolve
    (PGCM 13,9% · APAP 39,3% · VBEP 44,4% · PGEX 53,8% · ASAT 59,4% · JAMO 59,5% ·
    INBI 69,5% · SADV 72,7% · VADM 72,9% · APAV 78,4% · VPLY 50%). Causa a confirmar com o usuário.
  - **Jornadas ainda SEM histórico de PIs 1/26–3/26** (extração pendente de decisão):
    todas exceto Secretaria — Aprendizagem Digital, Atendimento e Serviços, Financeiro, Ingresso,
    Inteligência Artificial, Jornada Acadêmica, Pós Graduação, Saber, Voomp.
  - **Fila**: a ordem #15 (Aprendizagem + Acadêmica, só SP 26.4.1/2, criada pelo usuário às
    19:12) foi **CANCELADA a pedido do usuário** ("por enquanto"; reativável com
    `UPDATE extracao_agendamento SET status='pendente' WHERE id_agendamento=15`, só se ele pedir).
    Motivo: o DELETE do recorte trocaria a PI inteira já extraída por só 2 sprints. Estado:
    #13 cancelado · #14 concluído (Atendimento) · #15 cancelado → nenhuma ordem pendente.
    Atendimento foi REEXTRAÍDO pela PI inteira (não há mais a limitação a 2 sprints).
- **CUIDADO com chaves repetidas entre PIs**: um item com duas fixVersions existe
  em 2 linhas de `extracao_jira` (hoje 7 chaves). Todo JOIN/mapa por `chave` deve
  incluir a PI (`configuracao_pi_id`) — já ajustado em `bin/conferir_planilha.php`
  (`--pi=`, padrão = PI corrente) e nas views de PI; `vw_cycle_time` e
  `vw_produtividade` (ANTIGAS, páginas legadas) foram restritas à PI corrente.
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
> **Git ao encerrar 29/10**: local à frente do `origin/main` por 1 commit (`f537366`, checklist
> do deploy) + este arquivo modificado e sem commit. O usuário pediu: **commitar o checklist
> junto com o próximo lote (Velocidade) e então fazer o push**. A pasta
> `Plataforma-Engenharia-Performance/` é do usuário (não rastrear/commitar).
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
   **Implantação pendente (29/10)**: o endpoint traz `implantacao_resumo` e a página
   mostra "Implantação concluída x de n ⚠ N pend." + card; números do lead time
   inalterados. Na janela padrão: 17 de 77 itens têm fim de implantação; dos 60
   restantes, 37 são "Pendente de Produção" e 23 são "Aceito" (sem a data
   preenchida — possível ajuste de qualidade de dado no Jira).
3. ~~**Gráfico de Produtividade**~~ **CONCLUÍDO** (`/metricas/produtividade`, endpoint
   `/graficos/produtividade`, `ProdutividadeRepository`). Definição: h/ponto = Σ horas
   gastas ÷ Σ story points dos itens de valor ENTREGUES; sem fallback para estimate
   (`itens_sp_nulo` reportado). Média da jornada = **PONDERADA** (Σh ÷ Σpts; a média
   simples vai só como dado). Colunas = PIs até a de referência (filtro Versão; padrão =
   corrente). PIs fechadas vêm de `produtividade_historico`; a PI de referência corrente
   é AO VIVO na janela de sprints fechadas (ou só na sprint do filtro). Time sem dado =
   "—" (nunca 0). Tabela traz a referência de planejamento (`times_composicao.media_produtividade_pi`)
   e a **sugestão para a próxima PI** (produtividade da PI fechada mais recente com dados).
   **A sugestão é SOMENTE LEITURA**: nada é gravado em `times_composicao` até a carga da
   PI 1/27 (aplicar antes alteraria a Velocidade validada da PI 4/26).
   Consolidar uma PI fechada: `php bin/consolidar_produtividade.php --pi="PI x/yy" [--dry-run]`.
   (Histórico do planejamento desta etapa) Dados prontos:
   `extracao_jira` tem PIs 1/26–4/26 da Secretaria; view `vw_produtividade_pi`
   (ao vivo, por time x PI); `produtividade_historico` tem 11 linhas (PIs 1/26–3/26,
   gravadas por `bin/consolidar_produtividade.php --pi="PI x/yy"`; recusa a PI
   corrente sem `--forcar`; `--dry-run` disponível). Definição: Σ horas gastas ÷
   Σ story points dos itens de VALOR ENTREGUES da PI; sem fallback para estimate.
   Resultado (h/ponto): PI 1/26 SGSE 4,80 · SGGU 6,41 · SGGS 6,01 (SGTB não existia);
   PI 2/26 SGTB 6,17 · SGSE 6,63 · SGGU 6,29 · SGGS 7,09; PI 3/26 SGTB 6,50 ·
   SGSE 6,95 · SGGU 6,63 · SGGS 6,52 — coerente com o template (5–7). Decidir:
   média da jornada ponderada (Σh/Σpts) ou simples; e se a produtividade média da
   PI passada alimenta `times_composicao.media_produtividade_pi` da PI seguinte.
3a. **PRÓXIMO — Velocidade refeita** (`/metricas/velocidade`, hoje legada da v1.0: lê o
   cache antigo `data/items.json`/`/metrics`, 31 times). Pedido do usuário (29/10): os gráficos
   passam a usar os rótulos **"Story Points Estimate"** e **"Story Points entregues"**, e os
   números **precisam bater com o Planejado x Realizado**. Interpretação adotada (confirmar
   se o usuário preferir outra): Estimate = barra **PI Planning** (SPE da baseline dos itens
   planejados para a janela, incl. sustentação; Secretaria 435) e entregues = barra
   **Entregues** (SP atuais; Secretaria 396); gráfico de capacidade usa velocity/capacity da
   mesma janela (Secretaria velocity 412 = 86 sust. + 326 capacidade). Implementar lendo do
   MESMO repositório (`PlanejadoRealizadoRepository`/`/graficos/plan-real`, `por_time`), para
   os totais baterem por construção, e provar com cálculo independente. Alternativa em
   aberto: usar Sprint Planning (410) em vez de PI Planning como "Estimate".
3b. **Visão Geral: o usuário está preparando outra proposta — NÃO alterar até ele trazer.**
3c. **Decisões em aberto da cobertura de dados (29/10, noite)** — o usuário ainda vai decidir:
   1. **Escopo da extração**: manter só `fixVersion ~ "*PI x/yy*"` ou acrescentar `OR Sprint in
      (sprints da PI)`. Medido: 108 itens de valor da baseline (325 de 2964 SPE = 11%) ficam fora da
      extração da PI 4/26 — 61 itens/187 SPE rotulados "PI 3/26" (carry-over; APAP sozinho 30),
      34 itens/113 SPE que contêm PI 4/26 mas não vieram, 9 sem versão, 4 outra. Afeta
      previsibilidade e lead time do APAP (39% de cobertura). Oferta: comparar as duas formas no
      APAP SEM gravar antes de decidir.
   2. **Time "Pós" (PGPO)** está no catálogo dentro da jornada **Voomp** (veio da Velocity), mas na
      baseline/Jira PGPO é da "Jornada Pós Graduação" (104 itens inflam o Voomp). Descobrir a chave
      real do "Pós" da Voomp ou mover PGPO para Pos Graduação.
   3. **Times sem itens na PI 4/26**: JAAM (Alexandria/Maia), SGTH, SGTP, VAPP (sem baseline);
      JFTR e JFSP são pré-fusão (sugestão: inativar, só geram aviso). Conferir se a chave JAAM está certa.
   4. Extrair PIs 1/26–3/26 das demais jornadas para histórico de produtividade (estimativa
      ~25–35 mil linhas, ~8–15 min no total) — ainda não pedido.
   Registro de correção: o 48,7% do ASKB na previsibilidade é REAL (17 SPE de transbordo aberto);
   a extração limitada a 2 sprints só o classificava como "fora da extração".
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

## 8. CHECKLIST DO DIA DO DEPLOY (ambiente interno)
> Combinado em 29/10/2026: no dia do deploy o usuário pede para **excluir os
> segredos do repositório**. Não fazer antes disso sem pedido. Até lá, os tokens
> seguem expostos no histórico do GitHub (commit `8edcbb5`) e na conversa.

**Segredos (a pedido do usuário no dia do deploy)**
- Token do **Jira** em texto em `config/jira.php` e em `.kiro/settings/mcp.json`;
  token do **Confluence** e e-mail corporativo também em `.kiro/settings/mcp.json`
  (arquivo rastreado, contém comentário `//` → não é JSON puro; NÃO ler com
  `ConvertFrom-Json`/imprimir, o conteúdo vaza no terminal).
- Fazer: (1) usuário **revoga e gera novos tokens** (id.atlassian.com → Segurança →
  Tokens de API) — a única correção que de fato resolve; (2) `config/jira.php`
  passa a ler só `getenv('JIRA_API_TOKEN')` (documentar onde definir: Apache/XAMPP
  e CentOS/systemd); (3) parar de rastrear `.kiro/settings/mcp.json` (`git rm
  --cached` + `.gitignore`) e criar `mcp.json.example` sem segredos;
  (4) reescrever histórico (`git filter-repo`/BFG + force-push) SOMENTE se o
  usuário pedir (destrutivo; com os tokens revogados deixa de ser urgente).
- Também revisar: senha de dev do banco (`metricas_jca`/`metricas_jca`) em
  `db/schema.sql`, senha do root usada nos testes, `app/Services/Logger.php`
  (máscara de segredos já existe).

**Banco**: aplicar `db/schema.sql`, `db/views.sql`, `db/triggers.sql` (este com
conta privilegiada); trocar a senha do usuário de aplicação por uma forte;
`config/db.php` real no servidor (fora do git).

**Servidor / rede interna**
- **Chart.js e libs vêm de CDN** (`cdn.jsdelivr.net` no `layout.php`; html2pdf e
  xlsx sob demanda em `app.js`). Se o ambiente interno não tiver internet, os
  gráficos NÃO renderizam → baixar e servir as libs localmente (`public/assets/vendor`).
  Confirmar com o usuário se há acesso à internet no ambiente.
- Caminho base: `.htaccess` (raiz e `public/`) e `public/index.php` têm `/dashboard`
  fixo (RewriteBase e remoção de prefixo de URI) → ajustar se o app for publicado
  em outro caminho/virtual host.
- Jira: usar CA bundle (`JIRA_CA_BUNDLE`) em vez de `JIRA_SSL_VERIFY=0`.
- Registrar o worker (`bin/queue_worker.php`) no crontab de 1 em 1 minuto; definir
  SMTP real no lugar do `LogNotifier`; permissões de escrita em `data/`
  (`logs`, `agendamentos`, `exports`, `import`).
- Fuso `America/Sao_Paulo` (config/app.php) e sessão MySQL já alinhados.

**Dados**: base será zerada e reimportada pelo modal de importação (Baseline ×
Velocity) quando existir; sem ele, usar `bin/seed_catalogo.php`,
`bin/seed_sustentacao.php`, `bin/import_baseline.php` e os runners de extração.
