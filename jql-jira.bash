# JQL de pesquisas - usados em Filtros dos dahboard por times.
# Parametrizados por times, sprints e versões corrigidas em alguns casos

#FILTRO: Listar Solicitações por sprint
project = SGSE AND issuetype in("Solicitação de Serviço") AND sprint in opensprints()

#FILTRO: Listar Incidentes por sprint
project = SGSE and issuetype = Incidente and sprint in(openSprints()) AND status not in(Aceito, Concluído)

#FILTRO:  Sub itens - Sustentação - Solicitação de Serviço para ver tempo gasto no período da sprint
project = SGSE and issuetype in subTaskIssueTypes() and "Tipo item pai[Short text]" ~ "Solicitação de Serviço"  AND worklogDate >= "2026-10-26" AND worklogDate <= "2026-11-06"

#FILTRO:  Sub itens - Sustentação - Incidente para ver tempo gasto no período da sprint
project = SGSE and issuetype in subTaskIssueTypes() and ("Tipo item pai[Short text]" ~ "Incidente" or "Tipo item pai[Short text]" ~ "bug") AND worklogDate >= "2026-10-26" AND worklogDate <= "2026-11-06"

#FILTRO: Reserva de Sustentação (Solicitação de Serviços) na sprint ativa
project = SGSE AND issuetype = Sustentação AND sprint in openSprints() AND "Tipo Sustentação[Dropdown]" = "Solicitação de Serviço"

#FILTRO: Reserva de Sustentação (Incidentes) na sprint ativa
project = SGSE AND issuetype = Sustentação AND sprint in openSprints() AND "Tipo Sustentação[Dropdown]" = Incidente

#FILTRO: Itens aceitos na sprint
project = SGSE AND issuetype in standardIssueTypes() AND status in (Aceito, "Em produção", "Pendente de Produção") AND Sprint in openSprints()

#FILTRO: Itens Bloqueados ou Em espera
project = SGSE AND issuetype in standardIssueTypes() AND status in (Bloqueado, "Em espera") and sprint in(openSprints())

#FILTRO:  Itens em Andamento na Sprint
project = SGSE AND issuetype in standardIssueTypes() AND status in ("Em Andamento", "Em desenvolvimento", "Em teste", "Revisão de código") AND sprint in opensprints()

#FILTRO: Itens não Iniciados na Sprint - em Backlog
project = SGSE AND issuetype in standardIssueTypes() AND status = Backlog AND sprint in opensprints()

#FILTRO: Sustentação Realizada exclusivamente na Sprint Anterior (Solicitações) não usa closedSprints()
#Por exemplo, Sprint anterior = 26.4.2 sprint atual = 26.4.3, assim evita trazer itens indevidos
project = SGSE AND issuetype = Sustentação AND Sprint in("SP 26.4.2") and "Tipo Sustentação[Dropdown]" = "Solicitação de Serviço"

#FILTRO: Sustentação Realizada exclusivamente na Sprint Anterior (Incidentes) não usa closedSprints()
#Por exemplo, Sprint anterior = 26.4.2 sprint atual = 26.4.3, assim evita trazer itens indevidos
project = SGSE AND issuetype = Sustentação AND Sprint in("SP 26.4.2") and "Tipo Sustentação[Dropdown]" = Incidente

#FILTRO: Somatória de Pontos Aceitos exclusivamente na Sprint Anterior e que não transbordaram pontos para próxima sprint, não usa closedSprints() e futureSprint()
#Por exemplo, Sprint anterior = 26.4.2 sprint atual = 26.4.3, assim evita trazer itens indevidos
project = SGSE and issuetype in (standardIssueTypes()) and status in (Aceito, "Em produção", "Pendente de Produção") and sprint in("SP 26.4.2") and sprint not in("SP 26.4.3")

#FILTRO: Somatória de Pontos Alocados exclusivamente na Sprint Anterior, independente se houve transbordo dos pontos para próxima sprint, não usa closedSprints() e futureSprint()
#Por exemplo, Sprint anterior = 26.4.2 sprint atual = 26.4.3, assim evita trazer itens indevidos
project = SGSE and issuetype in standardIssueTypes() AND Sprint in("SP 26.4.2")

#FILTRO: Somatória de Pontos De Transbordo da Sprint Anterior, exclusivamente entre sprints Anteriore e Atuais, não usa closedSprints() e openSprints()
#Por exemplo, Sprint anterior = 26.4.2 sprint atual = 26.4.3, assim evita trazer itens indevidos
project = SGSE and issuetype in standardIssueTypes() and Sprint in ("SP 26.4.3") and Sprint in ("SP 26.4.2")

#FILTRO: Listar todos Itens alocados na sprint anterior
project = ADGP AND issuetype in standardIssueTypes() AND fixVersion in unreleasedVersions() AND Sprint in closedSprints() AND Sprint not in(futureSprints()) and "Story Points[Number]" != 0

#FILTRO: Listar todos Itens alocados na sprint atual
project = ADGP AND issuetype in standardIssueTypes() AND Sprint in openSprints()