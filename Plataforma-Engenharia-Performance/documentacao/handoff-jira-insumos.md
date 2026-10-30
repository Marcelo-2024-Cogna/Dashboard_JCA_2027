# Insumos técnicos para o ETL do Jira — detalhes que poupam retrabalho

Complemento do `handoff-backend-resumo.md` / `handoff-backend-integracoes.md`. Reúne o
conhecimento prático acumulado na carga real do PI 4/26 e no código de ingestão existente
(`jira-ingest.js`, `jira-mapping.js`, `identity.js`). Serve de base para o alinhamento com o
dev antes de desenhar o ETL.

---

## 1. Armadilha do JQL por tipo de item

- `issuetype = "História"` (com acento) retornou **vazio** em casos onde havia histórias —
  comportamento inconsistente observado na prática. Não confiar nesse filtro.
- **Recomendações:**
  - Preferir buscar **sem filtro de tipo** por projeto/período e **agrupar por
    `issuetype.name` no cliente** (1 query paginada por jornada em vez de 1 por tipo). Mais
    robusto e reduz nº de chamadas.
  - Se precisar filtrar por tipo, usar o **id do tipo** (`issuetype = 10001`) ou o
    `untranslated_name` (ex.: `Story`), não o nome traduzido.
  - Tipos reais no Jira (PT-BR), confirmados no projeto VADM:
    `Epic, História, Subtarefa, Bug, Spike, Débito Técnico, Sub-bug, Impedimento, Enabler,
    Design, Liderança, Teste, Solicitação de Serviço, Incidente, Sub-teste, Sustentação,
    Suporte, Segurança`.
  - Ao contar itens "de trabalho", **excluir** subitens e ruído: `Epic`, `Subtarefa`,
    `Sub-bug`, `Sub-teste`, `Impedimento` (conforme o caso do painel).

## 2. Paginação e ausência de contagem

- A busca pagina via **`next_page_token`** e **não** retorna total confiável
  (`total: -1`). Não assumir contagem server-side; **paginar até o fim** por jornada/projeto.
- Dica de performance: buscar só os campos necessários (ex.: `fields=key,issuetype,status`)
  para reduzir payload; resolver detalhes (changelog, SP) só quando o painel exigir.

## 3. Mapeamento projeto → jornada/squad

- A classificação se dá pelo **prefixo da chave** do item (ex.: `ASAT-123` → `ASAT`). O
  código já faz isso em `jira-mapping.js` (`projectKeyOf`) + `identity.js`.
- **Não hardcodar** no ETL: a tela de **Integração** (`admin-store`/`jira-mapping`) já modela
  `byProject: { 'ASAT': { jornada, squad } }`. O ETL deve ler essa tabela (parametrizável).
- Mapa Jornada→prefixos validado na carga do PI 4/26 está no `handoff-backend-integracoes.md`
  (seção 4.2). Use-o como seed inicial da tabela de Integração.
- O nome do **projeto** no Jira também carrega a jornada (ex.:
  `[Jornada Atendimento e Serviços] Atendimento`, `[Voomp] Admin`) — útil como fonte
  alternativa/validação do mapeamento por prefixo.

## 4. Divergência de nomes de squad (reconciliação de identidade)

- Rótulos do **cadastro** (`data.js`/Tribos-Squads) divergem dos valores vindos do **Jira**:

  | Cadastro (filtro/UI) | Jira (épicos) | Casa por igualdade exata? |
  |----------------------|---------------|---------------------------|
  | Athenas - Atendimento | Atendimento | não (alias) |
  | kBots | KBots | não (caixa) |
  | Papa-léguas | Papa-Léguas | não (caixa) |
  | Route66 | Route 66 | não (espaço) |

- **Solução já aplicada:** normalizar sem acento/espaço/caixa + aliases (ex.:
  "Athenas - Atendimento" ↔ "Atendimento"). No MVP isso vive em `identity.js`
  (`SQUAD_ALIASES`, `canonicalSquad`, `jornadaOf`) e na `query` tolerante de `retro-data.js`.
- **No ETL:** normalizar o squad do Jira para o `squad_id` **canônico** antes de gravar, e
  manter os aliases como dado configurável (não no código).

## 5. Granularidade: por jornada × por squad

- **Têm squad real:** Situação dos Épicos (campo `squad` por épico).
- **Só por jornada (na fonte atual):** Pontos Trabalhados (Velocity) e Produtividade. A
  planilha Velocity e a de Produtividade não quebram por squad.
- **Implicação no ETL/modelo:** decidir se grava `squad_id` nulo (1 registro por jornada) ou
  replica o valor da jornada para cada squad. O front **não** deve fabricar número por squad —
  hoje ele mostra "Squad · Jornada" quando filtra squad nessas métricas.
- Para ter Pontos/Produtividade **por squad**, a fonte precisa reportar SP concluídos e
  headcount **por squad** (hoje a Velocity é por jornada).

## 6. Situação dos Épicos — ATENÇÃO: duas heurísticas divergentes

Existem HOJE dois mapeamentos de status → 6 situações, e eles **não** coincidem:

- **Carga real do PI 4/26 (recomendado):** usa **`status.category`** do Jira (campo
  estruturado), mais confiável que o texto do status:
  - `Itens concluídos` (done) → `finalizado`
  - `Em andamento` (indeterminate) → `em-dia`
  - `Itens Pendentes` (new/to-do) → `em-risco`
  - Bloqueado/Impedido → `atrasado`
  - Cancelado → `despriorizado` (ou excluído)
- **`jira-ingest.js` (heurística legada por regex no texto do status):**
  `finaliz|conclu|done|encerr|resolv → finalizado`; `atras|late|overdue → atrasado`;
  `replanej → replanejado`; `risco|risk|bloque|impedid → em-risco`;
  `desprioriz|cancel → despriorizado`; senão → `em-dia`.
- **Decisão para o backend:** padronizar em **uma** regra (preferir `status.category` +
  exceções explícitas para `replanejado`/`atrasado`, que não têm categoria própria) e
  **validar o mapa com o time**. `replanejado` e `atrasado` provavelmente exigem sinal extra
  (flag, due date vencida, campo de replanejamento) — não saem só da categoria.
- Invariante a manter: **partição** — cada épico em exatamente uma situação.

## 7. Cycle Time / Lead Time — exigem changelog

- A extração simples de itens **não** tem as transições de status. Para derivar:
  - **Cycle Time por fase** (Ideação→Dev→Homologação→Implantação): ler o **changelog** de
    cada item e medir o tempo em cada status/fase. O `jira-ingest.js` já tem a fórmula
    (`diffDays`, médias por fase) assumindo datas de fase nas colunas; no ETL real essas datas
    vêm do changelog, não de colunas.
  - **Lead Time por tipo**: `criadoEm → resolvidoEm` por tipo de item (essa dá para derivar
    sem changelog, só com created/resolutiondate).
- Mapear **nomes de status → fase** é específico do fluxo de cada jornada/projeto — precisa
  ser **configurável** (status não são idênticos entre projetos). Confirmar os status reais.

## 8. Pontos Trabalhados (Velocity) — sustentação × entrega de valor

- Classificação usada: **Sustentação** = `Incidente`, `Solicitação de Serviço`, `Suporte`;
  **Entrega de Valor** = demais tipos (exceto Épico). Confirmar essa régua com o time.
- `total = sustentacao + entregaValor` (SP concluídos por sprint). Manter a invariante.
- Hoje os números reais do PI 4/26 vieram da **planilha Velocity** (por jornada/sprint), não
  do Jira. Definir se o ETL passa a derivar do Jira ou mantém a planilha como fonte.

## 9. Débito Técnico

- Itens tipo `Débito Técnico`, consolidados por (pi, jornada):
  - `planejado` = todos; `entregue` = concluídos; `cancelado` = cancelados/despriorizados;
    `real` = em andamento/aberto; **`pendente` = planejado − entregue − cancelado** (invariante).
  - `backlog` = itens não concluídos.
- A carga do PI 4/26 trouxe **quantidade** (não SP por item, que exige leitura item a item).
  Decidir se o ETL coleta SP por item também.

## 10. Baseline — Planejado / PI Planning

- Fonte: planilha de baseline (ex.: `Baseline_Jornadas_26.4.xlsx`, aba "Baseline itens").
- **Planejado por jornada** = soma de `Story Points` dos itens cujo campo **Versões
  corrigidas** contém o PI (ex.: `[ASAT] PI 4/26`). Projeto→jornada pelo nome do projeto.
- **PI Planning = Planejado**: o baseline é a foto única do plano do PI; não há coluna que os
  separe. Se o negócio quiser separá-los, precisa de uma segunda fonte (ex.: baseline
  congelado na Sprint 1 vs. escopo atual). Ver ADR 0006.
- Melhor que `created >= data`: filtrar pelo campo de **fix version** `[PREFIXO] PI x/yy` —
  é a marcação oficial do item no PI (o baseline usa exatamente isso).

## 11. OKRs via Must Win Battles (MWB)

- OKRs deixam de ser manuais: o alinhamento vem das **MWB cadastradas no Jira**. Falta
  definir **como** as MWB estão modeladas (tipo de item? projeto dedicado? label?) e como
  derivar o **% de alinhamento** (categoria Produto do Score). Levantar com quem cadastra as MWB.

## 12. SLA / Sustentação / Disponibilidade

- **SLA Alta/Média/Baixa**: confirmar se vêm do **Jira Service Management** ou do ServiceNow.
- **Sustentação** (Realizado vs Planejado): o "Realizado" sai de itens concluídos; o
  "Planejado" (capacity) **não** é derivável de itens — precisa de outra fonte.
- **Disponibilidade**: Datadog (M4.6).

## 13. Invariantes de domínio (não podem quebrar no backend)

- Score total ∈ [0, 1089]; categorias Processos 450 / Práticas 450 / Produto 50 / Pessoas 50.
- "Sem Dados" ≠ "Não atendeu"; **ausência nunca vira zero**.
- Farol: Na meta ≥ 0,99; Atenção ≥ 0,80 e < 0,99; Crítico < 0,80.
- Épicos: partição nas 6 situações.
- Débito: Pendente = Planejado − Entregue − Cancelado.
- "Todos" nos filtros = agrega (não filtra por um valor literal "all").

## 14. Regressão: validar o ETL contra a carga manual do PI 4/26

O seed atual (`retro-data.js`) tem a foto real do PI 4/26 feita à mão. Use como **oráculo de
regressão** do ETL: a coleta automática deve reproduzir (dentro de tolerância) os mesmos
números por jornada — contagem de épicos por situação, débito e carga por tipo, e os
Planejado/PI Planning do baseline. Divergência = investigar mapa de status/tipo/projeto.

---

### Resumo das decisões que dependem do time (não só técnicas)
1. Regra única de **status → situação do épico** (e como obter `replanejado`/`atrasado`).
2. Régua de **sustentação × entrega de valor** por tipo de item.
3. Mapa **status → fase** do Cycle Time por jornada/projeto.
4. Modelagem das **MWB** no Jira e fórmula do % de OKR.
5. Fonte do **Planejado de sustentação** (capacity) e dos **SLAs** (JSM vs ServiceNow).
6. **Headcount por squad** para Produtividade.
7. Se Pontos/Produtividade passarão a ser coletados **por squad** (hoje por jornada).
