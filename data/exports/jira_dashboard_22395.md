# Levantamento do dashboard Jira 22395 ("novo modelo dashboard")

Consulta feita em 2026-11-04 (~16:03 UTC), somente leitura (GET), com a credencial já configurada na aplicação. Nenhuma escrita no Jira, no banco ou no código. Token e cabeçalho de autenticação não constam deste relatório.

## (a) Metadados do dashboard

| Item | Valor |
|---|---|
| ID / nome | 22395 / "novo modelo dashboard" |
| Descrição | "Painel de acompanhamento dos trabalhos do time por PI" |
| Dono | Marcelo Dias de Oliveira |
| Compartilhamento (visualizar) | `loggedin` (qualquer usuário logado) |
| Edição | grupo "[Cogna] Delivery Manager" |
| Favorito | sim (popularity 1) |
| Dashboard de sistema | não |
| Rotas | `GET /rest/api/3/dashboard/22395` = 200 (não foi preciso usar a v2) |
| Gadgets | 13, todos na coluna 0 (layout de 1 coluna, 13 linhas: 0 a 12) |

Gadgets de apps de terceiros, todos Forge, do mesmo app (extension `8455aa21-a6ca-4d48-89ad-9e946c173760`; o nome comercial do app não é devolvido pela API):
- `static/advanced-filter-based-formula` (6 gadgets, a maioria "cartões" com fórmulas)
- `static/cumulative-flow-chart`
- `static/velocity-gadget` (Team Velocity)
- `static/cycle-time-control-chart`

Outros: 1 gadget Connect (Bloompeak "Status Time", `io.bloompeak.status-time-free__st-dashboard-item`) e 2 gadgets nativos Jira (Filter Results e Sprint Burndown).

## (b) Layout (ordem visual: linha crescente, coluna 0)

Legenda dos campos: `customfield_10026` = "Story Points"; `customfield_10016` = "Story point estimate"; `aggregatetimespent` = tempo gasto agregado em segundos (as fórmulas dividem por 3600 para obter horas); `customfield_10020` = Sprint; `10281` = "Ciclo de Desenvolvimento"; `10341` = "Ciclo Homologação"; `10345` = "Ciclo de Implantação".

| Linha | ID | Título | Tipo / app | O que mostra | Configuração relevante |
|---|---|---|---|---|---|
| 0 | 69343 | Gadget do gráfico de burndown do sprint | Nativo (greenhopper sprint-burndown) | Burndown da sprint | board 1351 (Quadro SGSE), `sprintId=auto` (sprint ativa) |
| 1 | 69325 | Cumulative Flow Diagram | Forge `cumulative-flow-chart` | Fluxo acumulado por categoria de status | filtro 19358; estatística Story Points; últimos 15 dias; faixas por categoria de status (Pendentes / Em andamento / Concluídos); sem fins de semana; sem subtarefas |
| 2 | 69327 | Andamento da Sprint Atual | Forge `advanced-filter-based-formula` (cartões) | 6 cartões (detalhe abaixo) | filtros 19364, 19363, 19362, 19365 + JQL de cancelados |
| 3 | 69328 | Visão da Sustentação na Sprint atual | Forge `advanced-filter-based-formula` (cartões) | 6 cartões (detalhe abaixo) | filtros 19366, 21033, 19367, 21037, 19353, 19352 |
| 4 | 69335 | Tempo gastos em tarefas de Sustentação | Forge `advanced-filter-based-formula` (cartões) | 3 cartões, em horas | filtros 21033, 21037, 21041 (soma de `aggregatetimespent`/3600) |
| 5 | 69326 | Dados da Sprint Anterior | Forge `advanced-filter-based-formula` (cartões) | 6 cartões (detalhe abaixo) | filtros 19369, 19368, 19447, 19372, 19373 |
| 6 | 69336 | Cycle time por status de itens Aceitos | Forge `advanced-filter-based-formula` (cartões) | 3 médias em dias | filtro 20953; média de `10281`, `10341`, `10345` |
| 7 | 69337 | Visão Geral \| Produtividade | Forge `advanced-filter-based-formula` (cartões) | Horas por ponto das sprints 26.4.1, 26.4.2, 26.4.3 | 6 JQLs digitados à mão (sprint fixa) |
| 8 | 69338 | Visão Geral \| Produtividade | Forge `advanced-filter-based-formula` (cartões) | Horas por ponto das sprints 26.4.4, 26.4.5, 26.4.6 | 6 JQLs digitados à mão (sprint fixa) |
| 9 | 69331 | Média em dias para resolver chamados | Forge `cycle-time-control-chart` | Gráfico de controle de cycle time de Incidente e Bug | JQL (abaixo); início = categoria "To Do", fim = categoria "Done"; sem fins de semana; unidade dias; limite 2 dias; linha de média; últimos 30 dias |
| 10 | 69329 | Team Velocity | Forge `velocity-gadget` | Por sprint: Escopo Inicial, Escopo Final, Concluído, Velocidade Média (linha vermelha), Mudança de Escopo, Adicionado, Removido | board 1351; 4 sprints; Story Points; sem subtarefas; inclui sprint ativa; "Concluído" = última coluna do board |
| 11 | 69333 | Tempo do item em cada Status da sprint Atual | Connect Bloompeak "Status Time" | Lista de itens da sprint aberta com duração por status | JQL (abaixo); colunas tipo, status, Story Points; relatório "list"; calendário 16126 |
| 12 | 69341 | Filtrar resultados | Nativo (filter-results) | Lista de 15 itens | filtro 19357; ordem status DESC; colunas: tipo, chave, pai, resumo, status, sprint, Story Points, "ID do Epico" |

### Detalhe dos cartões (fórmulas guardadas na configuração)

Variáveis a..f = uma por filtro/JQL, com agregação `sum` (ou `count`) sobre o campo indicado.

**69327 Andamento da Sprint Atual**
- Pts. em Backlog até o momento = `d` = filtro 19365 (Itens não iniciados, status Backlog em sprint aberta).
- Pts. em Andamento = `a` = filtro 19364 (status Em Andamento, Em desenvolvimento, Em teste, Revisão de código; sprint aberta).
- Pts. Bloqueados = `b` = filtro 19363 (status Bloqueado, Em espera; sprint aberta).
- Pts. Aceitos = `c` = filtro 19362 (Aceito, Em produção, Pendente de Produção; sprint aberta).
- Produtividade até o momento = `(e/3600)/c` (e = tempo gasto dos itens do filtro 19362) em horas por ponto, sufixo " hr.".
- Pts. Cancelados na PI atual = `f` (JQL: `project = ADSE` e standardIssueTypes e status Cancelado e `fixVersion in(unreleasedVersions())`).
- Todas as somas usam `customfield_10026` (Story Points).

**69326 Dados da Sprint Anterior** (sprint anterior digitada nos filtros: "SP 26.4.2")
- Pts. Alocados = `f` = filtro 19369 (standardIssueTypes na sprint).
- Pts. Aceitos = `a` = filtro 19368 (Aceito/Em produção/Pendente de Produção na sprint e não na sprint seguinte).
- Pts. Transbordo = `b` = filtro 19447 (itens que estão ao mesmo tempo na sprint 26.4.3 e na 26.4.2).
- Pts. Incidentes = `c` = filtro 19372 (tipo Sustentação, "Tipo Sustentação" = Incidente, sprint).
- Pts. Solicitações = `e` = filtro 19373 (Sustentação, "Tipo Sustentação" = Solicitação de Serviço).
- Produtividade (horas por ponto) = `(d/3600)/a`, onde d = tempo gasto dos itens do filtro 19368.

**69328 Visão da Sustentação na Sprint atual**
- Reserva de Sustentação (Incidentes) = `a` = soma de `customfield_10016` (Story point estimate) do filtro 19366 (tipo Sustentação, Incidente, sprint aberta).
- Pts. Sustentação utilizados (Incidentes) = `(b/3600)/5`, b = tempo gasto das subtarefas do filtro 21033 (subtarefas cujo "Tipo item pai" contém Incidente, bug ou Sustentação, com worklogDate entre 2026-10-26 e 2026-11-06). O divisor 5 está na fórmula (hipótese: 5 horas por ponto; a API não explica).
- Qtde de Incidentes = `e` = contagem do filtro 19353 (tipo Incidente em sprint aberta, status fora de Aceito/Concluído).
- Reserva de Sustentação (Solicitações) = `c` = soma de `customfield_10016` do filtro 19367.
- Pts. Sustentação utilizado (Solicitações) = `(d/3600)/5`, d = tempo gasto das subtarefas do filtro 21037 (pai = Solicitação de Serviço, mesma janela de worklog).
- Qtde de Solicitações = `f` = contagem do filtro 19352.

**69335 Tempo gastos em Sustentação** (horas): "Incidentes + Bug" = 21033; "Solicitação de Serviço" = 21037; "Suporte" = 21041 (subtarefas com pai Suporte, sprint aberta).

**69336 Cycle time por status de itens Aceitos**: título interno "Lead time por status de itens Aceitos". "Desenvolvimento - Concluído" = média de `10281`; "Homologação - Aceite" = média de `10341`; "Implantação - Produção" = média de `10345`. Todas sobre o filtro 20953 (`project in (ADSE)`, tipos Débito Técnico/Enabler/História/Segurança, `fixVersion in unreleasedVersions()`, status Aceito/Pendente de Produção/Em produção). Sufixo "dia(s)".

**69337 / 69338 Produtividade**: para cada sprint, soma de SP (A, C, E) dos itens com status Aceito/Em produção/Pendente de Produção na sprint N e não na N+1, e soma de `aggregatetimespent` (B, D, F); cartão = `(horas)/(pontos)`, sufixo " hr.". 69337 cobre 26.4.1 a 26.4.3 e 69338 cobre 26.4.4 a 26.4.6. Escopo: `project = ADSE`, tipos "Débito Técnico, Enabler, História, Spike, Teste, Bug, Suporte, Segurança, Sustentação, Incidente, Solicitação de Serviço".

**69331 JQL**: `project = ADSE and fixVersion in unreleasedVersions() and sprint in(closedSprints(),openSprints()) and issuetype in(Incidente, bug)`.

**69333 JQL**: `project = ADSE and issuetype in (standardIssueTypes()) and sprint in (openSprints()) and "Story Points[Number]" not in (EMPTY) order by issuetype`.

## Filtros referenciados (todos lidos com HTTP 200; dono: Marcelo Dias de Oliveira)

| ID | Nome | JQL (resumido) |
|---|---|---|
| 19358 | [ADSE] - Listar todos Itens alocados na sprint atual | `project = ADSE AND issuetype in standardIssueTypes() AND Sprint in openSprints()` |
| 19357 | [ADSE] - Listar todos Itens alocados na sprint anterior | `project = ADSE AND issuetype in standardIssueTypes() AND fixVersion in unreleasedVersions() AND Sprint in closedSprints() AND Sprint not in(futureSprints()) AND "Story Points" != 0 ORDER BY status` |
| 19364 | Itens em Andamento na Sprint | `project = SGSE AND standardIssueTypes() AND status in ("Em Andamento","Em desenvolvimento","Em teste","Revisão de código") AND cf[10020] in opensprints()` |
| 19363 | Itens Bloqueados ou Em espera | `project = SGSE ... status in (Bloqueado,"Em espera") and sprint in(openSprints())` |
| 19362 | Itens aceitos na sprint | `project = SGSE ... status in (Aceito,"Em produção","Pendente de Produção") AND Sprint in openSprints()` |
| 19365 | Itens não Iniciados na Sprint | `project = SGSE ... status = Backlog AND cf[10020] in opensprints()` |
| 19366 | Reserva de Sustentação (Incidentes) | `project = SGSE AND issuetype = Sustentação AND sprint in openSprints() AND "Tipo Sustentação" = Incidente` |
| 19367 | Reserva de Sustentação (Solicitação de Serviços) | idem com "Tipo Sustentação" = "Solicitação de Serviço" |
| 19353 | Listar Incidentes por sprint | `project = SGSE AND issuetype = Incidente AND sprint in(openSprints()) AND status not in(Aceito, Concluído)` |
| 19352 | Listar Solicitações por sprint | `project = SGSE AND issuetype in("Solicitação de Serviço") AND sprint in opensprints()` |
| 21033 | 09 - [ADSE ART Sync] Sub itens - Sustentação - Incidente | `project = SGSE AND issuetype in subTaskIssueTypes() AND ("Tipo item pai" ~ Incidente or ~ bug or ~ Sustentação) AND worklogDate >= "2026-10-26" AND worklogDate <= "2026-11-06"` |
| 21037 | 09 - ... Sub itens - Sustentação - Solicitação de Serviço | idem com "Tipo item pai" ~ "Solicitação de Serviço", mesma janela de worklog |
| 21041 | 09 - ... Sub itens - Sustentação - Suporte | `... "Tipo item pai" ~ "Suporte" and Sprint in openSprints()` |
| 20953 | 09 - ... Cycle Time - História, Enabler e Débito Técnico | `project in (ADSE) AND issuetype in ("Débito Técnico",Enabler,História,Segurança) AND fixVersion in unreleasedVersions() AND status in(Aceito,"Pendente de Produção","Em produção")` |
| 19368 | Somatória de Pontos Aceitos na Sprint Anterior | `project = SGSE ... status in (Aceito,"Em produção","Pendente de Produção") and sprint in("SP 26.4.2") and sprint not in("SP 26.4.3")` |
| 19447 | Somatória de Pontos De Transbordo da Sprint Anterior | `project = SGSE ... Sprint in ("SP 26.4.3") and Sprint in ("SP 26.4.2")` |
| 19369 | Somatória de Pontos Alocados na Sprint Anterior | `project = SGSE ... Sprint in("SP 26.4.2")` |
| 19372 / 19373 | Sustentação Realizada na Sprint Anterior (Incidentes / Solicitações) | `project = SGSE AND issuetype = Sustentação AND Sprint in("SP 26.4.2") and "Tipo Sustentação" = Incidente / "Solicitação de Serviço"` |

Contagens (opcional, limitadas a 3 filtros): o endpoint `GET /rest/api/3/search/jql` respondeu 200, mas não devolve `total`. Usei `maxResults=1` e obtive só `isLast`: 19366 `isLast=true` (cabe numa página), 19364 e 19357 `isLast=false` (mais de 1 item). Consulta em 2026-11-04 ~16:03 UTC. Total numérico: não obtido.

## Board / sprints (board 1351)

- Board 1351 "Quadro SGSE", tipo scrum, projeto SGSE ("[Jornada Secretaria e Gestor de Unidades] Secretaria - Enturmação"), filtro do board 19266, estimativa por campo `customfield_10026` (Story Points).
- Sprints (state active,closed; 48 retornadas): a convenção muda ao longo do tempo: `[ASEC] Sprint 24.4.x`, depois `[ADSE] SP 25.1.1` ... `[ADSE] SP 26.1.6`, e desde 26.2 `[SGSE] SP 26.2.1`. Padrão: `SP AA.P.N` (ano, PI, número da sprint dentro da PI, de 1 a 6; em 25.4 vão até 8).
- Sprint ativa: `[SGSE] SP 26.4.3` (início 2026-10-26, fim 2026-11-07). Últimas fechadas: `SP 26.4.2` (2026-10-13 a 2026-10-24) e `SP 26.4.1` (2026-09-28 a 2026-10-10).
- Observação: as sprints 26.4.4 a 26.4.6 usadas no gadget 69338 ainda não existem na lista (só active/closed foram consultadas).

## (c) Proposta de equivalente na nossa Visão Geral

Classificações: R = replicável com dados do nosso banco; D = depende de dado que não extraímos; N = não identificado.

| Gadget | Classe | Proposta / o que faltaria |
|---|---|---|
| 69343 Burndown da sprint | D | Exige histórico diário de SP restantes na sprint (changelog de estimativa/status por dia). Extraímos datas de fluxo, não a série diária. Alternativa R: burndown aproximado por data de conclusão (SP concluídos acumulados por dia) se a data de aceite estiver em `extracao_jira`. |
| 69325 CFD | D | Precisa de snapshots diários por categoria de status (ou changelog completo). Hoje temos só datas de fluxo (marcos). Alternativa R: CFD aproximado por marcos (criação, início, conclusão), a validar com o usuário. |
| 69327 Andamento da Sprint Atual | R (parcial) | Cartões por status (Backlog / Em andamento / Bloqueado / Aceito) somando SP da sprint ativa; produtividade = horas gastas / SP aceitos. Funciona se a extração incluir a sprint ativa e status Bloqueado/Em espera/Cancelado. O cartão "Cancelados" usa `fixVersion`, o que casa com a nossa extração por fixVersion. |
| 69328 Sustentação na sprint | D | Precisa do campo "Tipo Sustentação", das subtarefas com "Tipo item pai" e dos worklogs por data; "Reserva" usa `customfield_10016`. Não confirmado se extraímos. Regra de sustentação já está prevista como módulo independente. |
| 69335 Tempo gasto em Sustentação | D | Mesma dependência (subtarefas + worklog por janela + tipo do pai). |
| 69326 Dados da Sprint Anterior | R | Alocados, Aceitos, Transbordo, Incidentes, Solicitações, Produtividade (h/SP) por sprint. Transbordo exige itens presentes em duas sprints consecutivas; depende de a extração guardar o histórico de sprints do item (hoje mantemos só fixVersion, sem carry-over, conforme decisão do usuário). Aceitos, Alocados e Produtividade são R; Transbordo e sustentação são D. |
| 69336 Cycle time por status | D | Campos `10281`, `10341`, `10345` (ciclos de Desenvolvimento, Homologação, Implantação). Equivalente R se as datas de fluxo já extraídas permitirem os mesmos intervalos (a validar). Tela de Lead Time existente cobre parte. |
| 69337 / 69338 Produtividade (6 sprints) | R | Horas por ponto por sprint = `tempo gasto / SP aceitos`, por sprint da PI. Já alinhado à tela Produtividade. Vantagem sobre o Jira: sprint dinâmica (o gadget usa nomes fixos digitados à mão). |
| 69331 Média em dias p/ resolver chamados | D | Cycle time de Incidente e Bug entre categoria "To Do" e "Done", sem fins de semana; exige data de início real (primeira saída do To Do) e de fim. R se as datas de fluxo permitirem; senão depende de changelog. |
| 69329 Team Velocity | R | Já em refação (série Escopo Inicial/Final/Concluído/Velocidade Média/Mudança/Adicionado/Removido). Escopo inicial/final e adicionado/removido exigem histórico de entrada/saída de itens na sprint (changelog do campo Sprint) ou a `baseline` (escopo inicial) contra o final. Confirmar o que o `baseline` cobre. |
| 69333 Tempo em cada status da sprint | D | Exige tempo por status (changelog de status com calendário 16126). Não extraímos. |
| 69341 Filtrar resultados (sprint anterior) | R | Lista de itens da sprint anterior com tipo, chave, pai, resumo, status, sprint, SP e épico: tudo em `extracao_jira`. |

## (d) O que o nosso banco tem hoje (conforme informado pelo orquestrador; não revalidei o esquema neste levantamento)

- `extracao_jira`: itens da PI com tipo, status, story points, sprint, projeto, datas de fluxo, pai/épico, tempo gasto.
- `baseline`, `sprints`, times e jornadas.
- Telas prontas: Previsibilidade, Planejado x Realizado, Lead Time, Produtividade. A Velocidade está sendo refeita com as séries do widget Team Velocity.
- Extração restrita a fixVersion, sem carry-over por sprint (decisão do usuário).

Lacunas observadas frente ao dashboard: histórico diário (burndown/CFD), tempo por status, subtarefas/worklog por data para sustentação, campo "Tipo Sustentação", itens fora da PI/fixVersion (o dashboard também usa `Sprint in openSprints()` sem fixVersion em vários filtros).

## (e) Perguntas em aberto

1. O dashboard mistura `project = ADSE` e `project = SGSE` nos JQLs (as sprints atuais são "[SGSE] SP 26.4.x"). A Visão Geral deve ser por time/jornada (um projeto por vez) ou consolidar vários projetos? Não verifiquei se `ADSE` ainda retorna itens.
2. O dashboard cobre um único time/projeto e uma sprint por vez. A Visão Geral nova será por PI inteira, por sprint ativa ou ambos (seletor)?
3. Sustentação (3 gadgets): replicamos agora com o que temos ou esperamos o módulo independente?
4. Burndown, CFD e tempo por status exigem changelog. Aceitamos versões aproximadas por marcos de fluxo, ou ampliamos a extração (escopo maior e mais lenta)?
5. Fatores como "÷5" (pontos de sustentação = horas/5) e a janela de worklog digitada (2026-10-26 a 2026-11-06) são regras locais do dashboard: mantemos igual ou parametrizamos?
6. Campos de ciclo (`10281`, `10341`, `10345`) valem como fonte oficial do Cycle time por status, ou usamos nossas datas de fluxo?
7. Qual Velocidade Média usar: a do widget (últimas 4 sprints, incluindo ativa) ou a da PI?

## (f) Limitações

- Nenhuma chamada retornou 401/403/404. Todas as listadas retornaram 200.
- Os JQLs/filtros e configurações vêm da API, mas **o que cada app Forge calcula internamente não é devolvido**. Para o Team Velocity só há as opções (board 1351, 4 sprints, Story Points, "Concluído" = última coluna do board, sem subtarefas, inclui sprint ativa, método de % concluído `final_scope`). A regra exata de Escopo Inicial/Final, Adicionado/Removido e Velocidade Média não consta da configuração; não foi inventada.
- Totais dos filtros não foram obtidos (o endpoint de busca atual não devolve `total`); só `isLast` para 3 filtros.
- Não foram lidos valores das issues (nenhuma busca em massa).
- Os nomes comerciais dos apps Forge não são devolvidos (só o `moduleKey`).
- Vários gadgets têm sprint e datas fixas digitadas nos filtros (SP 26.4.1 a 26.4.6; janela de worklog 2026-10-26 a 2026-11-06), logo o dashboard exige manutenção manual a cada sprint.
- O cartão "Backlog" de Andamento da Sprint Atual usa o filtro 19365 (status = Backlog em sprint aberta), conforme a fórmula guardada (`mathFormula1 = d`).
- Sobre o método: o CLI do PHP não tinha cURL; usei `file_get_contents` com GET e Basic auth (credencial da aplicação).
