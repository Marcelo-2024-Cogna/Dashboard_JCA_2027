# Retomada — Métricas Jeito Cogna Ágil (v1.5)

> Handoff para abrir a próxima sessão. Atualizado em 28/10/2026 (noite).
> A v1.0 (JSON + extração via HTTP) está congelada em `dashboard-v1.0/`
> (http://localhost:8080/dashboard-v1.0/) e no histórico do git.

## SERVIDOR DE HOMOLOGAÇÃO — achados de 10/11/2026 (usuário operando; eu só oriento)

- Host de homologação, Ubuntu 20.04 (focal), Apache 2.4.41 prefork + mod_php 7.4.3, MySQL 8.0.41.
  Kernel em uso `5.4.0-200`. App em `/apps/metricas_jca` (partição `/apps`, 186 GB livres).
- **O PPA `ondrej/php` NÃO publica mais pacotes para o focal**: o índice `Packages` dele para focal tem
  0 bytes (Release de 09/06/2025). `apt install php8.2` não funciona e não vai funcionar neste SO.
  **PHP 8.x exige trocar de SO** (22.04 = PHP 8.1; 24.04 = PHP 8.3) ou VM nova: decisão/projeto da infra.
  **Caminho adotado para o deploy: ficar no PHP 7.4** (o app é compatível; polyfills em `app/compat.php`).
- `sources.list` apontava para `old-releases.ubuntu.com` (404 para o focal); corrigido para
  `archive.ubuntu.com`. Atualização de kernel 5.4.0-208 interrompida (disco cheio) foi desfeita com
  `apt remove --purge` dos pacotes `*-208*`; microcódigo e kernel 200 marcados como manuais.
- Volumes pequenos e sem folga no VG (`VFree 0`): `/var` 4 GB (~89% após limpeza), `/opt` 1 GB em
  100%, `/usr` 3,6 GB (~77%). `/var/lib/mysql` ~201 MB, `/var/log` 2,3 GB. Pedir à infra para ampliar
  `/var` e olhar `/opt`. Criado `/etc/apt/apt.conf.d/02lowdisk` (sem cache binário do apt).
- Pré-voo ainda pendente no servidor: `mod_rewrite` e `mod_headers` (a2enmod), `php7.4-mbstring`,
  `php7.4-curl` (e opcional `-zip`, `-xml`). Depois: schema, dump, views, triggers, `config/db.php`,
  `config/secrets.local.php`, vhost + DigiCert, cron.
- Dica de segurança: PHP 7.4 está sem suporte upstream; patches só via Ubuntu Pro/ESM
  (`/var/lib/ubuntu-advantage` existe: pedir à infra `pro status`).
- **PHP 7.4.3-4ubuntu2.29 instalado em 12/11** (mbstring, curl, xml, zip), `rewrite` e `headers`
  habilitados, Apache reiniciado, `configtest` OK.
- **INCIDENTE DE DISCO (12-13/11)**: `/var` chegou a 100% (20 KB livres) e o `CREATE DATABASE`
  falhou com `errno 28`. Causa: `/var/log/syslog` com 1,6 GB, inundado pelo serviço
  `ITS.Cloud.Servico.Agent` (92% das linhas) que tenta conectar a um broker RabbitMQ externo e falha
  com `BIO_new_file: no such file` (arquivo de certificado/chave ausente), várias vezes por segundo,
  ~430 MB/dia. O `logrotate` parou em 10/11 (disco cheio impediu a rotação). Alívio feito pelo
  usuário: cópia do log em `/apps/_diag` (chmod 700), `syslog` truncado; `/var` voltou a 44%.
  **Causa-raiz é do agente ITS (não nosso): reportar à infra/ITS.** Se não corrigirem, o disco volta a
  encher em ~5 dias. Pedir também ampliar `/var` (VG sem espaço livre) e olhar `/opt` (100%).
- `log_bin` do MySQL está ON (retenção 30 dias); datadir `/var/lib/mysql` (~200 MB). Contas: criar o
  usuário da aplicação só com DML; não usar a conta do Score Performance para DDL.
- **Pacote pronto (13/11)**: `data/deploy/pacote/` (ignorado pelo git): `metricas_jca/` (158 arquivos, sem
  `db.php` nem `secrets.local.php`) e `banco/` (dump `dados_20261112.sql` sha256 601A208E...D53F90,
  `01`..`04` SQL). Ensaio de importação em MySQL 8.4.9 descartável OK; lint 7.4 e 8.2 OK; boot 7.4 x 8.2
  byte-idêntico. **NÃO aplicar `db/schema.sql`** (cria `metricas_jca@'%'` com senha fraca): usar
  `banco/01_schema.sql`. Números de referência novos (13/11 10:21, Secretaria PI 4/26): velocidade
  607/637/592 média 197,33; previsibilidade 458/329 = 71,8%; plan-real 604/607/637/592; lead time
  121 itens 6,9008; produtividade 6,33; visão geral foco SP 26.4.4 aceitos 32, anterior 217/193.
- **STATUS DO SERVIDOR (13/11, ~14:45 BRT)**: dados carregados (conferido por contagem: 38.953
  `extracao_jira`; PIs 10.549/10.010/9.211/9.183). App no ar via vhost temporário em `127.0.0.1:8088`
  (acesso por túnel SSH/PuTTY); Velocidade, Visão Geral, Lead Time, Previsibilidade, Produtividade OK.
  Cron do `www-data` instalado (worker a cada minuto + `extract_diaria` 02:00 UTC + `extract_check`
  02:30 UTC). Dry-run do job OK (10 jornadas, JQL principal e de fallback). Worker ocioso, sem erro.
- **SERVIDOR EM UTC**: BRT = UTC-3 sem horário de verão; por isso 23:00/23:30 BRT viram 02:00/02:30
  no crontab. O PHP usa `America/Sao_Paulo` para as datas do job.
- **BUG DE COLLATION (corrigido em 13/11)**: no servidor as 15 views foram criadas em
  `utf8mb4_0900_ai_ci` (cliente DBeaver) e o PHP conecta em `utf8mb4_general_ci`; o Planejado x
  Realizado dava 500 (`Illegal mix of collations`, 1267). `db/views.sql` e `deploy/sql/02_views.sql`
  agora começam com `SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;`. Servidor corrigido: as views
  foram recriadas e a consulta de `information_schema.views` mostra `utf8mb4_general_ci` nas 15.
  `/graficos/plan-real` é o canário no smoke test.
- **ARMADILHA DO JOB**: se existir ordem CONCLUÍDA no dia, `extract_diaria` sai com "já executada
  hoje" (exit 0) e NÃO roda. Uma extração real manual feita no mesmo dia (BRT) antes das 23:00 faz o
  job da noite ser pulado; use `--refazer` só quando for intencional.
- **PENDENTE no servidor**: teste do token Jira (`200` esperado); primeira extração real automática
  (14/11 02:00 UTC); vhost definitivo + DigiCert + DNS (infra); `JOB_NOTIFY_TO` e MTA para `mail()`;
  trocar `metricas_jca@%` por `localhost` com senha própria; remover `info.php` da raiz do app;
  ampliar `/var` e corrigir o agente ITS que inunda o syslog (chamado de infra).

## DECISÕES DO USUÁRIO — 09/11/2026 (segunda; infra ainda sem resposta ao chamado de upgrade do PHP)

1. **PHP**: manter o código compatível com **7.4 E 8.0+** (polyfills em `app/compat.php`; removê-los só
   depois do upgrade oficial do servidor). Todo código novo deve passar no lint do 7.4
   (`deploy/lint_php74.ps1`; PHP 7.4.33 de teste em `%TEMP%\php74`, se ainda existir) e do 8.2.
2. **Nomes**: o legado (`*Controller`, `*Service`, `*Model`, `*Repository`, `vw_*.php`) NÃO será
   renomeado agora. **Código NOVO** usa prefixos `ctrl`, `srv`, `mdl`, `vmvw_`.
3. **Fallback de PI na extração** (reverte a decisão de 04/11 "só fixVersion"): considerar os itens que
   pertencem às **sprints da PI 4/26 pelo calendário** (tabela `sprints`) mesmo quando o `fixVersion`
   está VAZIO **ou aponta para PI anterior** (caso do APAP: itens com `fixVersion ~ PI 3/26` na sprint
   26.4.x). O usuário quer monitorar a regra de perto. NÃO implementado ainda (vem depois da reextração).
   Após implementar: nova reextração + novo dump.
4. **Horários**: extração às **23:00**, verificação às **23:30**.
5. **Visão Geral / UI**: manter SÓ o card de **Produtividade da Sprint Ativa**; remover os cards e
   históricos de produtividade de sprints encerradas (já existem na tela Produtividade). Cards devem
   se ajustar dinamicamente ao filtrar por time/sprint (sem buracos).
6. **Falhas do job**: `mail()` nativo do PHP em produção (servidor de e-mail local) + log em arquivo
   como garantia. **v2.0**: webhook do Microsoft Teams.
7. **Marco oficial = PI 1/27**. Histórico antigo do Ingresso e de-para `JI*` -> `IN*` DESCARTADOS.
   Sustentação e novos PDF/XLSX seguem pausados para a v2.0.
8. **CONCLUÍDO em 09/11** (tudo com push, `origin/main` em `e707a12`): reextração da PI 4/26;
   análise Banco x JSON; comparativo com `Plataforma-Engenharia-Performance`; revisão do
   `virtualHost.bash`; limpeza dos cards da Visão Geral; **Fase 1 do job noturno**.
   Relatórios (em `.agents/tasks/`, ignorado pelo git; recriar se faltarem): `banco-vs-json.md`,
   `comparativo-pep.md`, `jql-aderencia.md`, `extracao-incremental-analise.md`, `fase1-job-noturno.md`.
9. **FASE 1 DO JOB NOTURNO (commits `558d3fe`, `e707a12`)**:
   - `bin/extract_diaria.php` (23:00) e `bin/extract_check.php` (23:30); classes `srv*` em
     `app/Srv/`; testes em `bin/teste_job_noturno.php` (60/60 em 7.4 e 8.2, banco descartável).
   - Códigos de saída do diário: 0 ok; 1 falha parcial; 2 config/PI inválida; 3 já em execução;
     4 erro fatal/Jira indisponível. Verificador: 0 ok; 1 alerta; 2 config; 4 fatal.
   - **Fallback de PI por sprint** (ligado por padrão em `ExtractionService`): itens das sprints da
     PI com `fixVersion` vazio ou de PI anterior entram na PI corrente; coluna nova
     `extracao_jira.origem_pi` (`fixversion` | `sprint` | NULL). Migração:
     `db/migracoes/2026-11-09_origem_pi.sql` e `deploy/sql/04_migracao_origem_pi.sql` (aplicar ANTES
     do código novo em bancos existentes). PI 4/26: 7690 -> 8780 itens (1044 do fallback, 738 SP);
     APAP 130 itens/257 SP; previsibilidade do APAP foi de 3,2% para 78,3%.
   - **Extração por sprint está BLOQUEADA** (400 no `/extracao/agendar`; seletor desativado no modal):
     o DELETE apagaria as outras sprints da PI. Voltará na v2.0 com DELETE que respeite a sprint.
   - DELETE agora por PI + prefixo de chave validado; aborta se vier < 80% do existente;
     retry/backoff; lock por PI em `data/locks`. Aviso de falha: `mail()` + log
     (`JOB_NOTIFY_TO` / `JOB_NOTIFY_FROM`, ainda SEM destinatário definido).
10. **NÚMEROS DE REFERÊNCIA ATUAIS** (após a Fase 1; valores de 09/11 ~19h, Secretaria PI 4/26):
    janela padrão inclui SP 26.4.1–26.4.3 fechadas; sprint em foco SP 26.4.4; Velocidade média
    ~197; resíduos 0. Os números antigos (435/410/396 etc.) deixaram de valer.
11. **PENDENTE DO USUÁRIO**: (a) item com `fixVersion` de PI futura (ex.: SGSE-5375 `PI 2/27`) no
    fallback: manter ou excluir; (b) sprints de sustentação (26.4.7/26.4.8) no fallback: manter;
    (c) `JOB_NOTIFY_TO`/`JOB_NOTIFY_FROM`; (d) cancelar ordens locais #16 (pendente), #18 e #19
    (erro de teste) e apagar `extracao_jira_snap_20261109b`; (e) cache de resposta da Visão Geral
    (recomendado pela análise; ~1 dia); (f) gerar o DUMP FINAL (o `dados_20261109.sql` está
    defasado após o fallback); (g) infra: sem resposta ao chamado do PHP/acesso; faltam
    `mod_rewrite`, `mod_headers`, `php-mbstring`, `php-curl` no servidor.
12. **v2.0**: decisões do comparativo em `comparativo-pep.md` seção 8 (produtividade oficial,
    unidade de tempo do Cycle Time, fonte do Planejado vs Realizado, autenticação, etc.); perguntar se o
    app `score_performance` de produção é o mesmo que o PEP.

## DIRETRIZES DO USUÁRIO — 06/11/2026 (ler primeiro)

1. **Imediato**: (a) aguardar o upgrade do ambiente (o usuário tentará subir o PHP do
   servidor de 7.4 para 8.x na SEGUNDA 10/11); (b) push do código atual — FEITO,
   `origin/main` em `7c1b73e`. O deploy roda em 7.4 (backport commitado e verificado) e em 8.x.

2. **Segunda-feira (pós-deploy e homologação), planejar a v2.0**:
   - **Comparação de repositórios**: analisar `Métricas JCA` contra `Plataforma-Engenharia-Performance/`
     (a pasta "PEP"/Score Performance que o usuário rastreou em `af81878`; NUNCA foi alterada por
     mim), para mapear funcionalidades equivalentes e migrar as que faltam para a nossa base.
     O usuário disse que vai **refatorar o Score Performance** na v2.0.
   - **Validação da Visão Geral**: confirmar se os JQLs estão 100% aderentes às métricas da tela
     (já há o relatório `.agents/tasks/jql-aderencia.md`: hoje ~98% de aderência; as 12 decisões
     da seção 7 continuam PENDENTES do usuário).

3. **Diretriz técnica no radar — EXTRAÇÃO INCREMENTAL E CUMULATIVA por PI**:
   - Para **sprints fechadas**, a extração deve ser **incremental** e **cumulativa**: a cada sprint
     que fecha, rodar o JQL acrescentando essa sprint às demais já fechadas da PI em curso
     (comportamento "macro do Excel": cada nova extração acumula e recalcula o panorama da PI).
   - O usuário rascunhou uma classe `srvJiraIncrementalExtraction` (controle de estado local das
     sprints já processadas; JQL `sprint IN (...)` cumulativo; nomenclatura `srv`). Guardado em
     `data/import/srvJiraIncrementalExtraction.exemplo.php` para referência. O usuário quer as
     MINHAS percepções sobre o caminho na segunda (ver nota de análise abaixo).

4. **Preocupação do usuário — o BUG da extração por sprint** (que eu reportei em 06/11):
   `ExtractionService::extract` faz DELETE por `configuracao_pi_id` + projeto IGNORANDO a sprint.
   Extrair só uma sprint apagaria as outras sprints da PI daquele time. HOJE isso é contornado
   reextraindo a PI inteira (sem `--sprints`), que é idempotente. A proposta incremental do item 3
   ou um DELETE que respeite a sprint resolvem de vez; é prioridade antes de automatizar extração
   por sprint. Ver análise técnica que vou escrever para a segunda.

---


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
> **Git (atualizado em 04/11)**: o usuário commitou e fez push em 30/10 (`af81878`
> "Nova Estrutura - Jessica"), levando junto o checklist do deploy (`f537366`) e o estado do
> handoff; `origin/main` == local. Esse commit também **passou a rastrear** a pasta
> `Plataforma-Engenharia-Performance/` (141 arquivos; app estático separado — "PEP", Score de
> Performance — trazido pelo usuário; não faz parte deste dashboard e não foi alterado por mim).
> Não há mais "commit pendente do checklist".
> **Commits desde `af81878` (feitos e com push por mim, 04–05/11)**: `0dfcc21` Velocidade ·
> `ed67df5` docs (histórico produtividade, overrides, levantamento 22395) · `e14f160` Visão
> Geral · `2fb6fd9` ignora `.agents/` · `3ce2067` filtros universais + Extração movida ao Painel
> · `9be4dd6` segurança (remove credenciais do versionamento). `origin/main` == local em `9be4dd6`.
> **ATENÇÃO — dados e janela (04/11)**: a última extração é de 29/10 (dados com ~6 dias). A
> SP 26.4.3 termina em **06/11**; a partir de 07/11 a janela padrão "sprints fechadas" passa a
> incluir a SP 26.4.3 e TODOS os números de referência usados nas conferências (Secretaria:
> 331/235 = 71,0%; 412/435/410/396; lead time 77 itens/7,64; produtividade 6,33) mudam POR
> DESENHO. Antes de validar a Velocidade, decidir se reextrai a PI 4/26 (não há cron; manual).
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
### DECISÕES DO USUÁRIO — 04/11/2026 (respostas às pendências de 29/10)
1. **Escopo da extração: manter SÓ `fixVersion ~ "*PI x/yy*"`** (NÃO recuperar carry-over por
   sprint). Os ~11% de itens da baseline fora da extração permanecem fora.
2. **PGPO ("Pós") segue o Jira → jornada `Pos Graduação`** (movido no banco em 04/11).
3. **Inativados em 04/11** (`times.ativo='N'`): JAAM, SGTH, SGTP, VAPP, JFTR, JFSP (fusões/sem
   dados). JFTR/JFSP têm histórico nas PIs 1/26–2/26 que não aparece enquanto inativos
   (reativável por UPDATE). Registro em `config/catalogo_overrides.php` — **nada o lê ainda;
   NÃO reexecutar `bin/seed_catalogo.php`** (apaga/recria `times` e deixa
   `produtividade_historico`/`sustentacao_historico` órfãs, além de desfazer estas correções);
   o futuro motor de importação deve honrá-lo.
4. **Histórico de produtividade** das demais jornadas (PIs 1/26–3/26): necessário (comparativo).
   **CONCLUÍDO em 04/11**: extraído do Jira real e consolidado em `produtividade_historico`
   (101 linhas, 8 jornadas com dados). Médias ponderadas (Σh/Σpts) conferidas; Secretaria
   permanece 5,89 / 6,58 / 6,65 (sem regressão). **Ingresso ficou sem histórico**: nas PIs
   antigas os itens estão sob chaves `JI*`, não `IN*` — falta o de-para do usuário para extrair.
   Outlier a conferir: Inteligência Artificial com time até 11,2 h/ponto na PI 1/26.
5. **Visão Geral = igual ao dashboard Jira** `https://cogna.atlassian.net/jira/dashboards/22395`
   (replicar os gadgets). Levantamento somente-leitura via API **CONCLUÍDO** (13 gadgets,
   relatório em `data/exports/jira_dashboard_22395.md`). Decisões do usuário (04/11): visão
   **por jornada** consolidando vários projetos/times, filtros descem ao time (ADSE==SGSE = mesmo
   time); **seletor Sprint ativa | PI inteira** com sprints dinâmicas; Burndown/CFD/Tempo-por-status
   em **versões aproximadas por datas de fluxo** (rotuladas); gadgets de Sustentação ficam para o
   **módulo independente** (o ÷5 do Jira = produtividade: média da jornada p/ jornada, média do time
   p/ time). Construção da página em andamento (workflow de desenho aprovado).
6. **Painel de Controle será reestruturado** (antes da PI 1/27): interface única com
   (a) Modal de Extração Jira, (b) Modal do Motor de Importação (Velocidade, Baseline,
   Calendários), (c) Motor de configurações de exceções, (d) Modal de configurações de PI e
   Times. Substitui o Painel atual (JSON da v1.0).
7. **Sustentação** vira **módulo independente** (o usuário identificou regras próprias que vai
   detalhar). Segue PAUSADO.
8. **Velocidade = widget Jira "Team Velocity"**: por sprint, Escopo Inicial, Escopo Final,
   Concluído, Velocidade Média (linha), Mudança de Escopo, Adicionado, Removido.
   **CONCLUÍDO e verificado em 04/11** (ver 3a). `app/Repositories/VelocidadeRepository.php`,
   `GET /graficos/velocidade`, página `/metricas/velocidade` reescrita no formato do widget.
   Secretaria PI 4/26: totais 435 / 410 / 396 (= PI Planning / Sprint Planning / Entregues do
   Planejado x Realizado), resíduo da identidade 0 em todas as sprints e times, Velocidade Média
   198 sobre as 2 sprints fechadas. SP 26.4.3 exibida em andamento (hachurada, fora da média).
   Semântica adotada: Escopo Inicial = SPE baseline (PI Planning filtrado na sprint); Escopo Final =
   SPE extração sem Cancelado (Sprint Planning); Concluído = SP atuais (Entregues);
   Adicionado/Removido/Mudança de Escopo pela diferença de conjuntos baseline×extração.

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
3c. **Decisões da cobertura de dados (29/10, noite)** — RESPONDIDAS em 04/11 (ver bloco acima; a lista abaixo fica como histórico):
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
> segredos do repositório**. **FEITO em 05/11/2026** (ver abaixo).

**Segredos — RESOLVIDO em 05/11/2026 (commit `9be4dd6`, com push)**
- O usuário **revogou o token do Jira** no Atlassian. Verificado por mim com
  `GET /rest/api/3/myself` usando o token antigo → **HTTP 401 Unauthorized**:
  o token está morto/inerte.
- `config/jira.php`: token e e-mail agora vêm **só de variável de ambiente**
  (`getenv('JIRA_API_TOKEN')` / `getenv('JIRA_EMAIL')`), **sem fallback com
  segredo**. Se faltar o token, a extração falha de forma clara. No deploy,
  definir essas env vars no servidor (Apache/XAMPP ou systemd/.env não versionado)
  com o **token NOVO**.
- `.kiro/settings/mcp.json`: **removido do controle de versão** (`git rm --cached`;
  o arquivo local permanece intacto e a extração local segue funcionando) e
  **adicionado ao `.gitignore`**. Continua valendo: NÃO abrir/`ConvertFrom-Json`/
  imprimir esse arquivo (contém comentário `//` e tokens).
- `config/db.php`: **nunca foi versionado** (já no `.gitignore`); nada a fazer.
- As credenciais de **produção** (banco `score_performance`, VPN) nunca estiveram
  em arquivo do repositório — só vieram no chat.
- **Decisão do usuário (05/11)**: NÃO reescrever o histórico. Logo o token antigo
  **ainda aparece nos commits antigos do GitHub** (`8edcbb5` em diante), porém
  **revogado/inerte**. Reescrita (`git filter-repo`/BFG + force-push) fica
  disponível se um dia quiser, mas não é urgente com o token morto.
- Ainda a revisar no deploy: senha de dev do banco (`metricas_jca`/`metricas_jca`)
  em `db/schema.sql` e a senha do root usada nos testes (`app/Services/Logger.php`
  já tem máscara de segredos).

**ESTADO DO PREPARO DO DEPLOY (05–06/11/2026) — repositório PRONTO, servidor NÃO tocado**
- Destino: Linux/Apache 2.4, `/apps/metricas_jca`, `https://metricas.cogna.com.br/` (RAIZ do
  domínio), **Opção A: DocumentRoot = `/apps/metricas_jca/public`**. Acesso por VPN ao share
  `\\<servidor>\apps` (existem `metricas_jca` com só um `index.php` placeholder e
  `score_performance`). Nada foi gravado no servidor; credenciais de produção só vieram no chat.
- Commits locais, AINDA SEM PUSH: `6638b1c` (feat deploy) e `99ddd18` (.gitattributes LF).
- Feito e verificado: `public/index.php` com basePath por `SCRIPT_NAME` (vazio na raiz; antes o
  fallback `/dashboard` quebraria tudo); `public/.htaccess` sem RewriteBase; Chart.js 4.4.4 local em
  `public/assets/vendor`; `deploy/` com vhost, cron (flock), exemplos de `db.php`/`secrets.local.php`,
  SQL consolidado (`01_schema` → dados → `02_views` → `03_triggers`; 12 tabelas/15 views/1 trigger,
  iguais ao banco local), `dump_dados.ps1` (senha nunca hardcoded; dump em `data/deploy/`, ignorado),
  `excluir.txt` e `LEIAME.md` (runbook). Testado no XAMPP (`/dashboard`) e no modo raiz
  (`php -S 127.0.0.1:18081 -t public`; a porta 8081 é bloqueada neste Windows).
- NÃO testado (validar no 1º smoke test no servidor): `.htaccess`/mod_rewrite, vhost, TLS, cron,
  Jira com `JIRA_CA_BUNDLE`, import do dump em Linux.
- **PENDENTE DO USUÁRIO (responde em 06/11)**: (1) nome do schema e usuário do banco de produção
  (recomendado usuário dedicado, só DML; triggers exigem conta privilegiada; NÃO reaproveitar
  `score_performance`); (2) versões de PHP (mín. 8.1) e MySQL e se é mod_php ou php-fpm;
  (3) certificado corporativo (o vhost tem placeholders; o modelo do Score Performance tem
  `...snakeoil.keyls`, provável typo, e usa certificado autoassinado); (4) o domínio é só interno
  (VPN/ACL)? O app NÃO tem autenticação (opções comentadas no vhost: Basic Auth / `Require ip`);
  (5) saída de rede do servidor para `https://cogna.atlassian.net` (pelo SMB não dá para testar:
  página PHP temporária de diagnóstico que eu apago, ou `curl -I` no servidor).
- Tokens: o do Jira (clássico, sem escopo) está só em `config/secrets.local.php` (ignorado); no
  servidor vai em `config/secrets.local.php` (chmod 640) ou env. Tokens com escopo foram testados
  (401 em Basic auth), descartados e revogados. Histórico do git NÃO será reescrito (decisão do usuário).

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
