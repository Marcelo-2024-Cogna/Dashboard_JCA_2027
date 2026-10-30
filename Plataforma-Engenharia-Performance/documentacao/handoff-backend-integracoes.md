# Handoff — Backend e Integrações (PEP)

Lista acionável do que falta implementar para tirar a Plataforma de Engenharia de
Performance (PEP) do modelo **estático/manual** atual para **backend + banco + integrações
automáticas**. Destinado ao dev de backend.

> Contexto rápido: hoje o app é 100% client-side (HTML/CSS/JS, sem build), persiste em
> `localStorage` e é alimentado por **upload manual** de CSV/JSON (Jira) e `.xlsx` (baseline).
> O núcleo de cálculo (score, agregações, metas, RBAC) é **puro e testado** (Vitest +
> fast-check, 440 testes). A meta do backend é automatizar a coleta e centralizar os dados,
> **reaproveitando esse domínio puro** e mantendo o contrato CSV/JSON como ponte.

## Documentos de referência (ler antes de começar)

- `architecture.md` — arquitetura atual e alvo (diagramas), plano de migração (seções 4–6).
- `roadmap-producao.md` — marcos M1–M6 com esforço/prioridade (este handoff detalha M1–M4).
- `data-model.md` + `database-ddl.sql` — modelo lógico e DDL PostgreSQL de referência.
- `parametrizacao-metricas-jira.md` — **fontes por métrica**, ritmo das fotos (mensal/PI) e o
  estado atual da carga do PI 4/26 (seção 5). É a espinha dorsal das integrações.
- `funcionamento-do-sistema.md` — módulos JS e fluxo de dados (import → cálculo → render).
- `adr/0003` (ingestão manual) e `adr/0006` (carga real do PI 4/26).

---

## 0. Decisões a confirmar antes de codar (com o time/produto)

1. **Stack do backend** (sugestão: Node/TypeScript para reaproveitar o domínio puro JS como
   pacote; alternativa: portar para a stack corporativa). Definir também API **REST ou GraphQL**.
2. **Hospedagem/execução dos jobs de ETL**: serviço contínuo (cron/worker) vs. serverless
   agendado. Impacta M4.
3. **SSO corporativo** (Azure AD / Cogna) — provedor e biblioteca.
4. **Jira**: Cloud ou Server/DC? (muda API e paginação). Confirmar acesso de serviço
   (conta técnica + escopo de leitura) e se o **Jira Service Management** expõe os SLAs.
5. **Headcount por squad** (necessário para Produtividade): fonte oficial e cadência de
   atualização (hoje vem de planilha de estrutura).

---

## 1. Marco M1 — Fundação: banco + domínio + API de leitura (P0)

- [ ] **1.1 Portar/empacotar o domínio puro.** Extrair `scoring-engine.js`, `aggregation.js`,
  `targets.js`, `retro-aggregation.js`, `rbac.js`, `identity.js` como pacote reutilizável no
  backend. São funções puras — não reescrever regra de score; reusar como está.
- [ ] **1.2 Provisionar PostgreSQL** e aplicar `database-ddl.sql` (14+). Ver seção 5 deste
  handoff para **ajustes de schema** necessários (colunas que faltam).
- [ ] **1.3 Seed inicial** a partir dos CSVs/seeds atuais (round-trip `toCSV/fromCSV` já
  existe). Popular `tribo/squad/perfil/permissao/metrica/pi/sprint` e o histórico disponível.
- [ ] **1.4 API de leitura dos dashboards**: score por squad/jornada/mês, séries mensais,
  e as coleções de Retro por PI (pontos, produtividade, épicos, débito, carga). Contrato de
  resposta = o que as views já consomem (ver `retro-aggregation.js`).
- [ ] **1.5 Testes de equivalência API × domínio**: reusar o oráculo PBT do MVP (comparar o
  cálculo do backend com o do domínio puro dentro de 0,01).

## 2. Marco M2 — AuthN/AuthZ (P0)

- [ ] **2.1 Login via SSO** corporativo (Azure AD).
- [ ] **2.2 RBAC server-side como autoridade.** Popular `perfil`/`permissao`/`perfil_permissao`
  a partir de `rbac.PERMISSIONS` (Admin≡DM, TM, GPM, TL). Nega por omissão.
- [ ] **2.3 Frontend reflete** o RBAC do servidor (deixa de decidir; hoje é só simulação de
  visibilidade).
- [ ] **2.4 Auditoria** de acesso e de ações sensíveis (exceções, gestão).

## 3. Marco M3 — Migração de escrita (P1)

- [ ] **3.1 CRUD** para gestão: tribos/squads, perfis, integração (mapeamento Jira→Jornada/Squad).
- [ ] **3.2 Workflow de exceção** no servidor (`excecao` + `excecao_historico`, estados
  solicitar→aprovar/rejeitar; só "aprovada" sinaliza o indicador). Regras em `excecoes-store.js`.
- [ ] **3.3 Manter import/export CSV/JSON** como contrato estável (ponte MVP ↔ produção).
- [ ] **3.4 Migrar dados-piloto** de `localStorage` (export → import na API).

---

## 4. Marco M4 — Integrações automáticas / ETL (foco principal deste handoff)

> Hoje tudo abaixo é feito **manualmente** e gravado no seed. O objetivo é substituir por
> coleta agendada. Toda a lógica de mapeamento abaixo **já foi validada na prática** durante
> a carga do PI 4/26 — reaproveitar.

### 4.1 Cofre de segredos
- [ ] Guardar credenciais de Jira/ServiceNow/Datadog **fora** do banco de domínio (a tabela
  `integracao` nunca armazena token/senha/apiKey — regra já aplicada no MVP).

### 4.2 ETL do Jira — coleta por PI/Sprint (P1)
Substitui o upload manual. Precisa reproduzir o que foi feito à mão no PI 4/26:

- [ ] **Autenticação de serviço** (conta técnica, leitura dos projetos relevantes).
- [ ] **Mapa Jornada → projetos (prefixos de chave)** — usar exatamente este (validado):
  - Acadêmica: `JACM, JACO, JADO, JAES, JAMO, JASA, JAAM`
  - Saber: `SADV, SAJO, SATT`
  - Financeira: `JFEA, JFHM, JFSP, JFTR, JFTS` (+ Digital Finance: `DFCR, DFOC, DFFA`)
  - Ingresso: `JIBK, JICA, JICE, JICS, JIEC` (+ `INCA, INBI, INBO, INAP, INFU, INPV`)
  - Pós Graduação: `PGCM, PGEX, PGPO, PGBS` (+ `PGEN, PGFN`)
  - Inteligência Artificial: `IAAL, IACI, IAPI`
  - Voomp: `VADM, VBEP, VGAT, VGFE, VMPG, VPLY` (+ `VAPP, VHUB, VFAC`)
  - Atendimento e Serviços: `ASAT, ASKB, ASPL, ASR6`
  - Aprendizagem: `APAD, APAP, APAV, APCO, APEX, APIN, APPL, APRE, APSF`
  - Secretaria e Gestor de Unidades: `SGGS, SGGU, SGNF, SGRP, SGSE, SGTB, SGTH, SGTP`
  > Confirmar/parametrizar na tela de **Integração** (não hardcode): projeto→(jornada, squad).
- [ ] **Período do PI**: filtrar por `created >= <início do PI>` até a data de fechamento.
  (No PI 4/26 usamos `created >= "2026-07-01"`.) Idealmente usar o campo de versão/*fix version*
  (`[PREFIXO] PI x/yy`) como no baseline — é mais fiel que `created`.
- [ ] **Tipos de item** (nomes reais no Jira, PT-BR): `Epic`, `História`, `Subtarefa`, `Bug`,
  `Spike`, `Débito Técnico`, `Sub-bug`, `Impedimento`, `Enabler`, `Design`, `Liderança`,
  `Teste`, `Solicitação de Serviço`, `Incidente`, `Sub-teste`, `Sustentação`, `Suporte`,
  `Segurança`. **Atenção ao JQL**: `issuetype = "História"` teve comportamento inconsistente;
  preferir buscar **sem filtro de tipo** e agrupar por `issuetype.name` no cliente, ou usar o
  `untranslated_name`/id do tipo.
- [ ] **Paginação**: o `jira_search` pagina via `next_page_token` e **não** traz contagem
  server-side (`total: -1`). Paginar até o fim por jornada.
- [ ] **Derivações por painel** (reusar `jira-ingest.js` como especificação):
  - **Situação dos Épicos** — por épico: mapear `status.category` → 6 situações
    (Itens concluídos→`finalizado`; Em andamento→`em-dia`; Itens Pendentes→`em-risco`;
    Bloqueado/Impedido→`atrasado`; Cancelado→`despriorizado`/excluído; replanejado conforme
    regra). Gravar `squad` (campo real do épico). **Validar o mapa com o time.**
  - **Débito Técnico** — contagem de itens tipo "Débito Técnico" por jornada; backlog = não
    concluídos; entregue = concluídos no período. (SP por item exige ler item a item.)
  - **Carga de Trabalho** — contagem por tipo de item (`qtdeItens`) por jornada. SP opcional.
  - **Pontos Trabalhados (Velocity)** — SP concluídos por sprint, separando *entrega de valor*
    × *sustentação*. Hoje vem da planilha **Velocity**; definir se será do Jira ou mantido.
- [ ] **Cycle Time / Lead Time** — exigem **changelog** (transições de status) item a item.
  Hoje ficam "Sem dados". Implementar a leitura de changelog para derivar tempo por fase
  (ideação/dev/homologação/implantação) e criado→resolvido por tipo.
- [ ] **Impedimentos / Alterações no Plano / Sustentação** — hoje "Sem dados". Definir fonte:
  subtarefas/flags de impedimento (changelog), comparação baseline×replanejado, e capacity
  planejado de sustentação.

### 4.3 Reconciliação de identidade (P1)
- [ ] Reusar `identity.js` (normalização sem acento/espaço/caixa + aliases). **Divergência
  real já encontrada**: rótulos do cadastro vs. Jira, ex.: "Athenas - Atendimento" (cadastro)
  ↔ "Atendimento" (épicos); "Route66" ↔ "Route 66"; "kBots" ↔ "KBots". No ETL, normalizar o
  nome do squad do Jira para o `squad_id` canônico antes de gravar.

### 4.4 Baseline do PI (Planejado / PI Planning) (P1)
- [ ] Automatizar o que foi feito à mão com `Baseline_Jornadas_26.4.xlsx` (aba "Baseline
  itens"): **Planejado** por jornada = soma de `Story Points` dos itens cujo campo
  *Versões corrigidas* contém `PI x/yy`. **PI Planning = Planejado** (o baseline é a foto
  única do plano do PI; não há coluna que separe os dois — ver ADR 0006). Projeto→jornada pelo
  nome do projeto (ex.: `[Jornada Atendimento e Serviços] Atendimento`).

### 4.5 OKRs via Must Win Battles (MWB) (P1)
- [ ] OKRs deixam de ser manuais: o alinhamento é medido pelas **Must Win Battles cadastradas
  no Jira**. Definir como ler as MWB e derivar o `% de alinhamento` (categoria Produto do Score).

### 4.6 ServiceNow / Datadog (P2)
- [ ] **ServiceNow** — incidentes/SLA (alimenta SLA Alta/Média/Baixa e sustentação). Confirmar
  se SLAs vêm do ServiceNow ou do Jira Service Management.
- [ ] **Datadog** — disponibilidade.
- [ ] **Painel de saúde das integrações** (última coleta, erros, latência).

---

## 5. Ajustes de schema necessários (além do `database-ddl.sql` atual)

O DDL de referência está majoritariamente pronto, mas estas lacunas surgiram nas últimas
alterações e **precisam ser incorporadas**:

- [ ] **`pontos_trabalhados`**: adicionar `planejado NUMERIC(8,2)`, `real NUMERIC(8,2)`,
  `pi_planning NUMERIC(8,2)`, `velocidade NUMERIC(8,2)`. Hoje a tabela só tem
  `sustentacao/entrega_valor/total`, mas os KPIs do painel precisam desses quatro
  (Planejado/PI Planning vêm do baseline; Real/Velocidade da Velocity).
- [ ] **Granularidade de squad**: `pontos_trabalhados` e `produtividade` hoje são medidos por
  **jornada** (não por squad) na fonte. Decidir: (a) manter `squad_id` nulo e um registro por
  jornada, ou (b) replicar o valor da jornada para cada squad. Só a **Situação dos Épicos**
  tem dado real por squad. Documentar a escolha para que a API/front não "fabrique" número por
  squad (o front hoje mostra "Squad · Jornada" quando filtra squad nessas métricas).
- [ ] **Snapshots (fotos) — NOVO**: criar tabelas de foto imutável para o comparativo
  histórico (não existem no DDL atual). Sugestão:
  - `foto_mensal(id, squad_id, ano, mes, metrica_id, valor, pontos, faixa, congelado_em)` —
    congela no **último dia útil do mês** (as 12 métricas do Score).
  - `foto_pi(id, pi_id, jornada/squad, painel, chave_valor..., congelado_em)` — congela na
    **sprint de fechamento** do PI.
  - `pi.sprint_fechamento INT` (5 ou 6, parametrizável por PI) — atributo do plano do PI.
  - Regra: entre fechamentos a tela mostra **ao vivo**; histórico sempre lê a **foto**; métrica
    sem dado grava `sem-dados` (não zero). Ver `parametrizacao-metricas-jira.md` seções 1–3.
- [ ] **Origem por métrica**: marcar cada métrica como `jira` | `manual` | `integracao-futura`
  (tabela de parametrização), conforme a seção 2 de `parametrizacao-metricas-jira.md`.

---

## 6. Regras de negócio que o backend NÃO pode violar (invariantes testadas)

Estas propriedades são garantidas por testes no MVP; o backend deve preservá-las:

- Score total ∈ [0, 1089]; soma das 4 categorias (Processos 450, Práticas 450, Produto 50,
  Pessoas 50 = base 1000 + bônus 89). "Sem Dados" ≠ "Não atendeu" (nunca tratar ausência como 0).
- Farol: Na meta ≥ 0,99; Atenção ≥ 0,80 e < 0,99; Crítico < 0,80.
- Situação dos Épicos é **partição**: cada épico em exatamente uma das 6 situações.
- Débito: Pendente = Planejado − Entregue − Cancelado (consistência).
- Filtros: "Todos" agrega; nomes de jornada/squad reconciliados por identidade canônica.
- Independência: Seletor de Mês (Score) e Seletor de PI (Retro) não se afetam.

## 7. Ordem sugerida de execução

1. M1 (banco + domínio + API leitura) → base de tudo.
2. M2 (SSO + RBAC server-side).
3. M4.1–M4.4 (cofre, ETL Jira, identidade, baseline) — maior valor: elimina a carga manual.
4. M4.5 (OKRs via MWB).
5. M3 (escrita: gestão + exceções).
6. M4.6 (ServiceNow/Datadog), M5 (analytics), M6 (CI/CD, observabilidade, LGPD).

## 8. Como validar cada entrega

- Reusar o **oráculo de equivalência** (property-based) do MVP para o cálculo de score.
- Para o ETL do Jira, comparar a saída do job com a **carga manual do PI 4/26** já no seed
  (`retro-data.js`) como baseline de regressão (épicos por situação, débito e carga por
  jornada devem bater).
- Manter o round-trip CSV/JSON funcionando (contrato estável).
