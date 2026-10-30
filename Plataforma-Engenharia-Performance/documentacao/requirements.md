# Requirements Document

## Introduction

Esta feature expande o MVP existente **PEP — Plataforma de Engenharia de Performance** (aplicação estática HTML/CSS/JS, sem backend, Chart.js via CDN, tema da plataforma, localizada em `c:\Kiro - Analises\aplicacao\`) para alimentar um conjunto de **visões executivas** do Score de Performance de Engenharia.

O MVP atual calcula o Score de Performance por time/jornada a partir de um snapshot único de métricas (`js/scoring-engine.js`, `js/data.js`, `js/dashboard.js`). Esta feature adiciona: (1) a reconciliação do motor de cálculo com a fonte oficial de regras — a "Pílula de Score de Performance"; e (2) novas telas executivas (Visão Geral, Jornadas, Métricas, Evolução mensal, Metas) baseadas em dados mensais por jornada/time, consolidados a partir das planilhas mensais (template "03 - Cogna - Template Métricas Review - Retro PI" e "Cogna - Score de Performance_Jornadas").

O fluxo real do mundo é: as métricas mensais são coletadas em planilha manual, consolidadas na planilha de Score (que aplica cálculos e produz um gauge), e esses resultados devem ser trazidos para a plataforma de engenharia para alimentar as visões executivas. Hoje a origem das métricas é manual (planilha); futuramente a origem automatizada será ServiceNow e Datadog, com alinhamento da área de SI/Segurança.

A feature **deve preservar** a identidade visual, a UX e as capacidades existentes do MVP (ranking, filtros por jornada/time, detalhamento de métrica, entrada manual, exportação CSV).

## Glossary

- **PEP**: Plataforma de Engenharia de Performance, o MVP existente a ser estendido.
- **Pílula de Score de Performance**: documento oficial que define as regras do score (categorias, pesos, faixas por métrica e faixas de performance). É a **fonte da verdade** para regras de cálculo.
- **Score de Performance**: indicador único que consolida 12 métricas operacionais, com valor base de 0 a 1000 pontos, podendo chegar a 1089 pontos quando há bônus de métricas superadas (teto oficial definido pela planilha de Score de Performance).
- **4 Categorias / 4 Ps**: as quatro dimensões do score — **Processos** (450 pts, 45%), **Práticas** (450 pts, 45%), **Produto** (50 pts, 5%) e **Pessoas** (50 pts, 5%).
- **12 Métricas**: Planejado vs Realizado, Cycle Time Desenvolvimento, Cycle Time Homologação, Cycle Time Implantação, Frequência de Deploy (Processos); Disponibilidade de Sistema, Índice de Vulnerabilidade, SLA Incidente Alta Criticidade, SLA Média Criticidade, SLA Baixa Criticidade (Práticas); Alinhamento com OKRs (Produto); Team Health Check (Pessoas).
- **Faixas de Performance**: classificação do Score total — **Baixa** (0–500), **Média** (501–839), **Boa** (840–1000), **Ótima** (acima de 1000).
- **Faixas de Atendimento por Métrica**: as quatro bandas de pontuação de cada métrica — **Não atendeu** (0 pts), **Atendeu parcialmente** (pontuação proporcional por interpolação), **Atendeu totalmente** (pontuação máxima), **Superou** (máxima + ~10% de bônus). As métricas de SLA **não possuem** banda "Superou".
- **Interpolação Proporcional**: na faixa "Atendeu parcialmente", a pontuação varia linearmente conforme o resultado dentro da faixa, iniciando em um piso baixo e crescendo até o valor-topo definido para a faixa parcial na planilha de Score de Performance. Fórmula: `pontos = piso + (valor − início_faixa) ÷ (fim_faixa − início_faixa) × (topo − piso)`.
- **Bônus Superou**: acréscimo de aproximadamente 10% sobre a pontuação máxima de uma métrica quando o valor atinge a banda "Superou".
- **Jornada**: agrupamento organizacional de times (ex.: Acadêmica, Saber, Financeira). O score de uma Jornada é a média dos scores de seus Times.
- **Time**: unidade (squad) que possui métricas próprias. O score de Engenharia (total) é a média dos scores das Jornadas.
- **Evolução Mensal**: série temporal, mês a mês (Jan a Dez), do score realizado por Jornada e do total de Engenharia.
- **Partida**: ponto de partida de cada Jornada, igual ao score realizado de dezembro de 2025 (dez/25).
- **Meta %**: percentual de crescimento anual definido por Jornada (ex.: Secretaria e Gestor de Unidades = 14,5%; demais = 5%).
- **Meta 2026**: meta anual de uma Jornada, calculada como `Partida × (1 + Meta %)`.
- **Meta do Mês**: meta progressiva de um mês, calculada como `Partida + (Meta 2026 − Partida) ÷ 12 × nº do mês`, de modo que a meta cresce o mesmo valor a cada mês.
- **Atingimento**: razão `realizado do mês ÷ meta do mês`, expressa em percentual.
- **Farol**: classificação de status por Atingimento — **Na meta** (≥ 99%, tratando o limite de 99% como aproximação de 100%), **Atenção** (≥ 80% e < 99%), **Crítico** (< 80%).
- **Mês Selecionado**: mês de referência (Jan a Dez) escolhido pelo usuário, que direciona os dados exibidos em todas as visões.
- **Seletor de Mês**: controle de interface que permite escolher o Mês Selecionado.
- **Métricas de Retrospectiva de PI (Retro PI)**: conjunto de indicadores operacionais por PI/Sprint/Time extraídos do Jira e consolidados na planilha "03 - Cogna - Template Métricas Review - Retro PI", apresentados como painéis de gráfico na plataforma. São distintos do Score de Performance e não entram no cálculo das 12 métricas.
- **PI (Program Increment)**: ciclo de planejamento (ex.: PI 1/26, PI 2/26, PI 3/26), composto por várias Sprints.
- **Sprint**: período de iteração dentro de um PI (ex.: SP 26.1.1 … SP 26.3.6).
- **Tipo de Item**: classificação de um item de trabalho — História, Enabler, Spike, Teste, Débito Técnico, Segurança, Incidente, Solicitação de Serviço, Suporte.
- **Story Points (SP) / Pontos**: unidade de esforço estimado de um item.
- **Pontos Trabalhados**: pontos entregues/planejados por Sprint, separados em Sustentação e Entrega de Valor, incluindo Velocidade (média de pontos entregues) e o planejado do PI Planning.
- **Entrega de Valor vs Sustentação**: separação dos pontos trabalhados entre esforço de valor novo (Entrega de Valor) e esforço de manutenção/incidentes (Sustentação).
- **Velocidade (Velocity)**: média de pontos entregues por Sprint, usada como base de capacidade.
- **Produtividade**: pontos entregues por integrante do Time em um PI, comparável entre PIs (ex.: PI 4/25 a PI 3/26), com média da Jornada.
- **Situação dos Épicos**: classificação de cada Épico em uma de seis situações — Atrasado, Replanejado, Em Risco, Em Dia, Finalizado, Despriorizado — comparando Baseline e Mudança de Escopo.
- **Cycle Time (por fase)**: tempo médio em cada fase do fluxo — Ideação, Desenvolvimento, Homologação, Implantação — por Time, com média da Jornada.
- **Lead Time**: tempo total do item por Tipo de Item (História, Enabler, Débito Técnico, Spike, Teste), por Squad, com média da Jornada.
- **Débito Técnico (Retro PI)**: visão de débitos técnicos — tamanho do backlog por Time e, no PI, Planejado vs Real vs Entregue vs Pendente vs Cancelado (em Story Points e em quantidade).
- **Sustentação (Retro PI)**: volume de Incidentes + Solicitações de Serviço por Time, Realizado vs Planejado no PI atual, com histórico por PI.
- **Carga Total de Trabalho**: distribuição do trabalho por Tipo de Item (pontos, % de pontos, quantidade de itens, % de itens e pontuação média).
- **Alterações no Plano**: comparação entre Baseline, Plano do PI Planning, Mudança de Escopo, Cancelados e Transbordo (Aceito/Pendentes), em Épicos, Histórias e Pontos.
- **Impedimento**: quantidade de impedimentos, localização no fluxo e tempo de remoção, por PI e por Sprint.
- **Baseline (Retro PI)**: fotografia de referência do planejamento no início do PI, usada para comparação com o realizado e com a mudança de escopo.

## Requirements

### Requirement 1: Reconciliação do Motor de Cálculo com a Pílula

**User Story:** Como gestor de engenharia, quero que o score seja calculado exatamente conforme as regras oficiais da Pílula, para que as visões executivas reflitam a fonte da verdade sem divergências.

#### Acceptance Criteria

1. THE Motor_de_Cálculo SHALL organizar as 12 métricas em 4 categorias: Processos (450 pts), Práticas (450 pts), Produto (50 pts) e Pessoas (50 pts).
2. THE Motor_de_Cálculo SHALL classificar a métrica Alinhamento com OKRs na categoria Produto.
3. THE Motor_de_Cálculo SHALL atribuir às 12 métricas os pesos máximos definidos na Pílula: Planejado vs Realizado 150, Cycle Time Desenvolvimento 125, Cycle Time Homologação 50, Cycle Time Implantação 50, Frequência de Deploy 75, Disponibilidade de Sistema 175, Índice de Vulnerabilidade 175, SLA Alta Criticidade 50, SLA Média Criticidade 30, SLA Baixa Criticidade 20, Alinhamento com OKRs 50, Team Health Check 50.
4. THE Motor_de_Cálculo SHALL aplicar, para cada uma das 12 métricas, as faixas de valor, os pontos por faixa e a interpolação da faixa "Atendeu parcialmente" exatamente conforme a tabela de regras abaixo (fonte: Pílula de Score de Performance e planilha de Score). Métricas percentuais recebem o valor em escala de 0 a 1 (ex.: 97,0% = 0,970); Cycle Time recebe o valor em dias; Team Health Check recebe o valor em escala de 0 a 10; Frequência de Deploy recebe o valor como categoria (0/1 = menos de 1 por mês, 2 = entre 1 por mês e 1 por semana, 3 = 1 por semana, 4+ = mais de 1 por semana).

| Métrica | Categoria | Peso (máx) | Não atendeu (0 pts) | Atendeu parcialmente (interpolação piso→topo) | Atendeu totalmente (pontos) | Superou (pontos) |
|---|---|---|---|---|---|---|
| Planejado vs Realizado | Processos | 150 | < 75,0% | ≥ 75,0% e < 100% → 50 a 120 | = 100% → 150 | > 100% → 165 |
| Cycle Time Desenvolvimento | Processos | 125 | ≥ 6 dias | ≥ 4 e < 6 dias → 50 a 10 | ≥ 3 e < 4 dias → 125 a 100 | < 3 dias → 138 |
| Cycle Time Homologação | Processos | 50 | ≥ 3 dias | ≥ 2 e < 3 dias → 10 a 5 | ≥ 1 e < 2 dias → 50 a 30 | < 1 dia → 55 |
| Cycle Time Implantação | Processos | 50 | ≥ 5 dias | ≥ 3 e < 5 dias → 10 a 5 | ≥ 2 e < 3 dias → 50 a 30 | < 2 dias → 55 |
| Frequência de Deploy | Processos | 75 | categoria 0/1 | categoria 2 → 30 | categoria 3 → 75 | categoria 4+ → 80 |
| Disponibilidade de Sistema | Práticas | 175 | < 97,0% | ≥ 97,0% e < 99,0% → 10 a 50 | ≥ 99,0% e < 99,5% → 100 a 175 | ≥ 99,5% → 193 |
| Índice de Vulnerabilidade | Práticas | 175 | < 75,0% | ≥ 75,0% e < 100% → 50 a 120 | = 100% → 175 | > 100% → 193 |
| SLA Alta Criticidade | Práticas | 50 | < 90,0% | ≥ 90,0% e < 100% → 5 a 40 | = 100% → 50 | — (sem faixa) |
| SLA Média Criticidade | Práticas | 30 | < 90,0% | ≥ 90,0% e < 100% → 3 a 25 | = 100% → 30 | — (sem faixa) |
| SLA Baixa Criticidade | Práticas | 20 | < 90,0% | ≥ 90,0% e < 100% → 2 a 15 | = 100% → 20 | — (sem faixa) |
| Alinhamento com OKRs | Produto | 50 | < 60% | ≥ 60% e < 80% → 10 a 25 | ≥ 80% e < 100% → 40 a 50 | = 100% → 55 |
| Team Health Check | Pessoas | 50 | < 7 | ≥ 7 e ≤ 8 → 10 a 20 | > 8 e ≤ 9,5 → 40 a 50 | > 9,5 → 55 |

5. WHEN uma métrica cai na faixa "Atendeu parcialmente", THE Motor_de_Cálculo SHALL calcular a pontuação por Interpolação Proporcional entre o piso e o topo definidos para a faixa parcial daquela métrica na tabela de regras, nunca retornando valor abaixo do piso nem acima do topo da faixa.
6. THE Motor_de_Cálculo SHALL tratar as métricas de Cycle Time como "quanto menor, melhor", de modo que valores menores correspondam a faixas superiores de atendimento.
7. THE Motor_de_Cálculo SHALL calcular o score total como a soma das pontuações das 12 métricas, com valor base máximo de 1000 pontos e teto máximo com bônus de 1089 pontos.
8. THE Motor_de_Cálculo SHALL atribuir às métricas de SLA (Alta, Média e Baixa Criticidade) apenas três faixas de atendimento (Não atendeu, Atendeu parcialmente, Atendeu totalmente), sem a faixa Superou.
9. THE Motor_de_Cálculo SHALL classificar o score total nas Faixas de Performance: Baixa (0–500), Média (501–839), Boa (840–1000) e Ótima (acima de 1000).
10. IF o valor de uma métrica for nulo ou ausente, THEN THE Motor_de_Cálculo SHALL atribuir 0 pontos à métrica e marcá-la com o rótulo "Sem Dados", distinto de "Não atendeu".
11. IF um valor de regra, escala, piso ou teto divergir entre a implementação atual do MVP e a tabela de regras desta seção, THEN THE Motor_de_Cálculo SHALL ser ajustado para adotar o valor definido na tabela de regras (fonte da verdade: Pílula e planilha de Score de Performance).
12. FOR ALL métricas e faixas, o resultado produzido pelo Motor_de_Cálculo SHALL ser idêntico ao resultado da planilha de Score de Performance para os mesmos valores de entrada, dentro de uma tolerância de arredondamento de duas casas decimais (propriedade de equivalência com a fonte da verdade).

### Requirement 2: Seletor de Mês

**User Story:** Como executivo, quero selecionar o mês de referência, para que todas as visões apresentem os dados daquele mês.

#### Acceptance Criteria

1. THE Seletor_de_Mês SHALL apresentar os doze meses do ano (Jan a Dez) como opções de seleção.
2. WHEN o usuário seleciona um mês no Seletor_de_Mês, THE PEP SHALL atualizar todas as visões executivas para refletir os dados do Mês Selecionado.
3. IF não existir dado para o Mês Selecionado em uma Jornada, THEN THE PEP SHALL indicar visualmente a ausência de dado para aquela Jornada.
4. THE Seletor_de_Mês SHALL permanecer acessível em todas as visões executivas.
5. WHEN o usuário navega entre visões executivas, THE PEP SHALL persistir o Mês Selecionado e aplicá-lo automaticamente à visão acessada, sem retornar a um mês padrão.

### Requirement 3: Visão Geral Executiva

**User Story:** Como executivo de engenharia, quero uma visão geral consolidada do mês, para que eu entenda rapidamente o desempenho de Engenharia e os principais destaques.

#### Acceptance Criteria

1. WHEN a Visão Geral é exibida para o Mês Selecionado, THE PEP SHALL apresentar o score total de Engenharia como a média dos scores das Jornadas.
2. THE Visão_Geral SHALL exibir um gauge de 0 a 1000 pontos com ponteiro indicando o score total de Engenharia.
3. THE Visão_Geral SHALL exibir o rótulo da Faixa de Performance correspondente ao score total de Engenharia.
4. THE Visão_Geral SHALL exibir o percentual de atingimento da meta do mês para Engenharia.
5. THE Visão_Geral SHALL exibir a contagem de Jornadas em cada status de Farol: Na meta, Atenção e Crítico.
6. THE Visão_Geral SHALL exibir a Jornada de maior alta e a Jornada de maior queda de score em relação ao mês anterior.
7. THE Visão_Geral SHALL exibir um gráfico de rosca (donut) com a distribuição de pontos entre as 4 categorias.
8. IF o Mês Selecionado for o primeiro mês com dados, THEN THE Visão_Geral SHALL exibir apenas os indicadores numéricos de variação em relação ao mês anterior (ex.: percentual de variação do score total) como indisponíveis, mantendo o cálculo dos destaques de Jornada de maior alta e maior queda, que ficam vazios quando não houver dados do mês anterior.

### Requirement 4: Visão de Jornadas

**User Story:** Como executivo, quero ver o score de cada jornada com seu status e evolução, para que eu identifique quais jornadas precisam de atenção.

#### Acceptance Criteria

1. WHEN a Visão de Jornadas é exibida para o Mês Selecionado, THE PEP SHALL calcular o score de cada Jornada como a média dos scores de seus Times.
2. THE Visão_de_Jornadas SHALL exibir, para cada Jornada, um gauge de score, o rótulo da Faixa de Performance e o percentual de meta versus realizado.
3. THE Visão_de_Jornadas SHALL exibir o status de Farol de cada Jornada: Na meta quando o Atingimento for maior ou igual a 99%, Atenção quando maior ou igual a 80% e menor que 99%, e Crítico quando menor que 80%.
4. THE Visão_de_Jornadas SHALL exibir o delta de score de cada Jornada em relação ao mês anterior.
5. THE Visão_de_Jornadas SHALL exibir, para cada Jornada, o detalhamento de pontos por categoria no formato "X de Y pontos" para as 4 categorias.

### Requirement 5: Visão de Métricas por Jornada

**User Story:** Como gestor de uma jornada, quero ver o detalhamento das 12 métricas da minha jornada, para que eu saiba onde melhorar.

#### Acceptance Criteria

1. WHEN uma Jornada é selecionada na Visão de Métricas para o Mês Selecionado, THE PEP SHALL exibir as 12 métricas da Jornada agrupadas pelas 4 categorias.
2. THE Visão_de_Métricas SHALL exibir, para cada métrica, um gauge com o valor atual, a pontuação no formato "pontos de máximo" e o rótulo da Faixa de Atendimento (Não atendeu, Atendeu parcialmente, Atendeu totalmente ou Superou).
3. THE Visão_de_Métricas SHALL exibir, para cada métrica, a média dos Times da Jornada.
4. THE Visão_de_Métricas SHALL exibir, para cada métrica, a contagem de Times por Faixa de Atendimento no formato de distribuição (ex.: "2 não atendeu · 1 parcial · 1 atendeu · 1 superou").

### Requirement 6: Evolução Mensal

**User Story:** Como executivo, quero acompanhar a evolução do score mês a mês, para que eu avalie a tendência ao longo do ano.

#### Acceptance Criteria

1. THE Evolução_Mensal SHALL exibir uma tabela com os meses de Jan a Dez em colunas e as Jornadas mais o total de Engenharia em linhas.
2. THE Evolução_Mensal SHALL exibir, em cada célula com dado, o score realizado da Jornada no mês correspondente.
3. THE Evolução_Mensal SHALL colorir cada célula com o Farol calculado como `realizado ÷ meta projetada do mês`: Na meta quando maior ou igual a 99%, Atenção quando maior ou igual a 80% e menor que 99%, e Crítico quando menor que 80%.
4. IF um mês não possuir dado para uma Jornada, THEN THE Evolução_Mensal SHALL exibir a célula correspondente em cinza.
5. WHEN o usuário alterna o modo de exibição, THE Evolução_Mensal SHALL apresentar os valores como "Score realizado" ou como "% da meta", conforme a opção escolhida.

### Requirement 7: Visão de Metas

**User Story:** Como executivo, quero ver a progressão de metas mensais por jornada, para que eu acompanhe se cada jornada está no caminho da meta anual.

#### Acceptance Criteria

1. THE Visão_de_Metas SHALL definir a Partida de cada Jornada como o score realizado de dezembro de 2025 (dez/25).
2. THE Visão_de_Metas SHALL calcular a Meta 2026 de cada Jornada como `Partida × (1 + Meta %)`.
3. THE Visão_de_Metas SHALL calcular a Meta do Mês de cada Jornada como `Partida + (Meta 2026 − Partida) ÷ 12 × nº do mês`.
4. THE Visão_de_Metas SHALL calcular o Atingimento de cada Jornada como `realizado do mês ÷ meta do mês`.
5. THE Visão_de_Metas SHALL exibir o Farol de cada Jornada: Na meta quando o Atingimento for maior ou igual a 99%, Atenção quando maior ou igual a 80% e menor que 99%, e Crítico quando menor que 80%.
6. THE Visão_de_Metas SHALL exibir uma tabela com as colunas: Jornada, Partida dez/25, Meta %, Meta 2026, acréscimo por mês e os valores de meta de cada mês de Jan a Dez.
7. WHERE uma Jornada possui Meta % específica, THE Visão_de_Metas SHALL utilizar o valor de Meta % próprio da Jornada no cálculo da Meta 2026 (ex.: Secretaria e Gestor de Unidades = 14,5%; demais Jornadas = 5%).

### Requirement 8: Modelo de Dados Mensal e Ingestão

**User Story:** Como responsável pela plataforma, quero representar e carregar dados mensais de score por jornada e time, para que as visões executivas sejam alimentadas mês a mês a partir das planilhas.

#### Acceptance Criteria

1. THE PEP SHALL representar o score realizado por Jornada e por Time para cada mês do ano (Jan a Dez), em vez de um único snapshot atual.
2. THE PEP SHALL representar o valor mensal de cada uma das 12 métricas por Time.
3. THE PEP SHALL permitir a importação de dados mensais em formato CSV ou JSON, mantendo a abordagem de aplicação estática sem backend.
4. WHEN um arquivo de dados mensais é importado, THE PEP SHALL validar que cada registro contém mês, Jornada, Time e valores de métrica.
5. IF um registro importado estiver incompleto ou inválido, THEN THE PEP SHALL rejeitar o registro e apresentar uma mensagem descritiva do erro.
6. THE PEP SHALL registrar que a origem atual das métricas é a planilha manual e que a origem automatizada futura prevista é a integração com ServiceNow e Datadog.
7. FOR ALL dados mensais importados, exportar os dados em CSV e importá-los novamente SHALL produzir um conjunto de dados equivalente (propriedade de ida e volta).

### Requirement 9: Preservação das Capacidades e Identidade do MVP

**User Story:** Como usuário atual do MVP, quero continuar usando as funcionalidades e a aparência existentes, para que a expansão não quebre meu fluxo de trabalho.

#### Acceptance Criteria

1. THE PEP SHALL preservar a funcionalidade de ranking de Times.
2. THE PEP SHALL preservar os filtros por Jornada e por Time.
3. THE PEP SHALL preservar o detalhamento por métrica existente.
4. THE PEP SHALL preservar a entrada manual de métricas.
5. THE PEP SHALL preservar a exportação de dados em CSV com separador ponto-e-vírgula e codificação UTF-8 com BOM.
6. THE PEP SHALL preservar a identidade visual de tema da plataforma e o padrão de UX do MVP existente nas novas visões executivas.
7. THE PEP SHALL manter a execução como aplicação estática, sem necessidade de servidor, build ou instalação.

### Requirement 10: Painel de Pontos Trabalhados

**User Story:** Como gestor de engenharia, quero ver os pontos trabalhados por Sprint separados entre Entrega de Valor e Sustentação, para que eu entenda a capacidade e o foco de esforço do Time ao longo do PI.

#### Acceptance Criteria

1. WHEN o Painel_de_Pontos_Trabalhados é exibido para um PI e Time selecionados, THE PEP SHALL apresentar, por Sprint, os pontos de Entrega de Valor e os pontos de Sustentação.
2. THE Painel_de_Pontos_Trabalhados SHALL exibir o total de pontos por Sprint e o percentual de cada categoria (Entrega de Valor e Sustentação) sobre o total.
3. THE Painel_de_Pontos_Trabalhados SHALL exibir os indicadores de Planejado, Real, PI Planning e Velocidade.
4. THE Painel_de_Pontos_Trabalhados SHALL exibir a série de pontos trabalhados por Sprint dentro do PI selecionado.
5. IF uma Sprint não possuir dado, THEN THE Painel_de_Pontos_Trabalhados SHALL indicar visualmente a ausência de dado para aquela Sprint.

### Requirement 11: Painel de Produtividade

**User Story:** Como gestor de engenharia, quero comparar a produtividade dos Times entre PIs, para que eu identifique tendências de evolução ou queda.

#### Acceptance Criteria

1. WHEN o Painel_de_Produtividade é exibido, THE PEP SHALL apresentar a produtividade de cada Time por PI (ex.: PI 4/25, PI 1/26, PI 2/26, PI 3/26).
2. THE Painel_de_Produtividade SHALL exibir a média de produtividade da Jornada por PI.
3. THE Painel_de_Produtividade SHALL permitir alternar a visão entre PI vigente e histórico acumulado de PIs anteriores.
4. IF um Time não possuir dado de produtividade para um PI, THEN THE Painel_de_Produtividade SHALL indicar visualmente a ausência de dado.

### Requirement 12: Painel de Situação dos Épicos

**User Story:** Como gestor de engenharia, quero ver a situação dos Épicos do PI, para que eu acompanhe riscos e atrasos de entrega.

#### Acceptance Criteria

1. WHEN o Painel_de_Situação_dos_Épicos é exibido para um PI selecionado, THE PEP SHALL apresentar a contagem de Épicos em cada situação: Atrasado, Replanejado, Em Risco, Em Dia, Finalizado e Despriorizado.
2. THE Painel_de_Situação_dos_Épicos SHALL apresentar as contagens separadas entre Baseline e Mudança de Escopo.
3. THE Painel_de_Situação_dos_Épicos SHALL permitir listar os Épicos de uma situação selecionada.
4. THE Painel_de_Situação_dos_Épicos SHALL classificar cada Épico em exatamente uma das seis situações.

### Requirement 13: Painel de Cycle Time por Fase

**User Story:** Como gestor de engenharia, quero ver o Cycle Time médio por fase do fluxo por Time, para que eu identifique gargalos de processo.

#### Acceptance Criteria

1. WHEN o Painel_de_Cycle_Time é exibido para um PI selecionado, THE PEP SHALL apresentar, por Time, o tempo médio nas fases Ideação, Desenvolvimento, Homologação e Implantação.
2. THE Painel_de_Cycle_Time SHALL exibir a média da Jornada em cada fase.
3. WHERE houver filtro por Tipo de Item e Status, THE Painel_de_Cycle_Time SHALL recalcular os tempos considerando apenas os itens que atendem ao filtro.
4. IF um Time não possuir dado em uma fase, THEN THE Painel_de_Cycle_Time SHALL indicar visualmente a ausência de dado para aquela fase.

### Requirement 14: Painel de Lead Time por Tipo de Item

**User Story:** Como gestor de engenharia, quero ver o Lead Time por Tipo de Item e por Squad, para que eu compare o tempo total de entrega entre tipos de trabalho.

#### Acceptance Criteria

1. WHEN o Painel_de_Lead_Time é exibido, THE PEP SHALL apresentar, por Squad, o Lead Time médio por Tipo de Item (História, Enabler, Débito Técnico, Spike, Teste).
2. THE Painel_de_Lead_Time SHALL exibir a média da Jornada por Tipo de Item.
3. IF um Squad não possuir dado para um Tipo de Item, THEN THE Painel_de_Lead_Time SHALL indicar visualmente a ausência de dado.

### Requirement 15: Painel de Débito Técnico

**User Story:** Como gestor de engenharia, quero ver o débito técnico por Time e sua evolução no PI, para que eu acompanhe o endereçamento da dívida técnica.

#### Acceptance Criteria

1. WHEN o Painel_de_Débito_Técnico é exibido, THE PEP SHALL apresentar o tamanho do backlog de Débito Técnico por Time.
2. THE Painel_de_Débito_Técnico SHALL apresentar, no PI selecionado, os valores de Planejado, Real, Entregue, Pendente e Cancelado, em Story Points e em quantidade de itens.
3. THE Painel_de_Débito_Técnico SHALL exibir o total da Jornada para cada indicador.

### Requirement 16: Painel de Sustentação

**User Story:** Como gestor de engenharia, quero ver o volume de Sustentação por Time no PI com histórico, para que eu dimensione o esforço de manutenção.

#### Acceptance Criteria

1. WHEN o Painel_de_Sustentação é exibido para o PI atual, THE PEP SHALL apresentar, por Time, o volume de Incidentes e Solicitações de Serviço Realizado versus Planejado.
2. THE Painel_de_Sustentação SHALL exibir o total da Jornada de Realizado e Planejado.
3. THE Painel_de_Sustentação SHALL exibir o histórico de Sustentação por PI, incluindo o percentual sobre o total de trabalho quando disponível.

### Requirement 17: Painéis de Carga de Trabalho, Alterações no Plano e Impedimentos

**User Story:** Como gestor de engenharia, quero ver a composição da carga de trabalho, as alterações no plano e os impedimentos do PI, para que eu entenda a distribuição do esforço e os riscos ao planejamento.

#### Acceptance Criteria

1. THE Painel_de_Carga_de_Trabalho SHALL apresentar, por Tipo de Item, os pontos, o percentual de pontos, a quantidade de itens, o percentual de itens e a pontuação média.
2. THE Painel_de_Alterações_no_Plano SHALL apresentar, em Épicos, Histórias e Pontos, os valores de Baseline, Plano do PI Planning, Mudança de Escopo, Cancelado e Transbordo (Aceito e Pendentes).
3. THE Painel_de_Impedimentos SHALL apresentar a quantidade de impedimentos, a localização no fluxo e o tempo de remoção, por PI e por Sprint.
4. IF um indicador não possuir dado no PI selecionado, THEN os painéis SHALL indicar visualmente a ausência de dado.

### Requirement 18: Modelo de Dados e Ingestão das Métricas de Retrospectiva de PI

**User Story:** Como responsável pela plataforma, quero representar e carregar os dados de Retrospectiva de PI por PI, Sprint, Time e Tipo de Item, para que os painéis sejam alimentados a partir das extrações do Jira consolidadas na planilha.

#### Acceptance Criteria

1. THE PEP SHALL representar os dados de Retrospectiva de PI organizados por PI, Sprint, Time e Tipo de Item, de forma independente do modelo mensal do Score de Performance.
2. THE PEP SHALL permitir a importação dos dados de Retrospectiva de PI em formato CSV ou JSON, mantendo a abordagem de aplicação estática sem backend.
3. WHEN um arquivo de Retrospectiva de PI é importado, THE PEP SHALL validar que cada registro contém, no mínimo, PI, Time e o indicador correspondente ao painel.
4. IF um registro de Retrospectiva de PI estiver incompleto ou inválido, THEN THE PEP SHALL rejeitar o registro e apresentar uma mensagem descritiva do erro.
5. THE PEP SHALL registrar que a origem atual dos dados de Retrospectiva de PI é a planilha manual alimentada por extrações do Jira e que a origem automatizada futura prevista é a integração direta com o Jira.
6. FOR ALL dados de Retrospectiva de PI importados, exportar os dados em CSV e importá-los novamente SHALL produzir um conjunto de dados equivalente (propriedade de ida e volta).
7. THE PEP SHALL permitir a seleção do PI de referência que direciona os dados exibidos nos painéis de Retrospectiva de PI.

### Requirement 19: Preservação e Consistência com o Módulo de Score

**User Story:** Como usuário da plataforma, quero que os painéis de Retrospectiva de PI convivam com as visões de Score sem conflito, para que a plataforma permaneça coesa e estável.

#### Acceptance Criteria

1. THE PEP SHALL apresentar os painéis de Retrospectiva de PI preservando a identidade visual de tema da plataforma e o padrão de UX do MVP existente.
2. THE PEP SHALL manter a execução como aplicação estática, sem necessidade de servidor, build ou instalação, após a adição dos painéis de Retrospectiva de PI.
3. THE PEP SHALL manter o Seletor de PI dos painéis de Retrospectiva de PI independente do Seletor de Mês do Score de Performance, sem que a troca de um altere o outro.
4. THE PEP SHALL preservar todas as capacidades e visões já existentes do Score de Performance após a adição dos painéis de Retrospectiva de PI.

### Requirement 20: Filtros por Jornada, Squad, Sprint e PI nos painéis de Retro

**User Story:** Como gestor de engenharia, quero filtrar os painéis de Retrospectiva de PI por Jornada, Squad, Sprint e PI, para que eu analise os resultados no nível de granularidade que precisar.

#### Acceptance Criteria

1. THE PEP SHALL disponibilizar, nos painéis de Retrospectiva de PI, filtros de Jornada, Squad, Sprint e PI.
2. WHEN o usuário seleciona uma Jornada, THE PEP SHALL restringir o filtro de Squad aos Squads daquela Jornada e recalcular os painéis.
3. WHEN o usuário seleciona um PI, THE PEP SHALL restringir o filtro de Sprint às Sprints daquele PI e recalcular os painéis.
4. WHEN o usuário altera qualquer filtro, THE PEP SHALL recalcular e re-renderizar todos os painéis de Retrospectiva de PI de acordo com a combinação de filtros ativa.
5. WHERE um filtro é definido como "Todos", THE PEP SHALL agregar os dados de todos os valores daquela dimensão (ex.: todas as Jornadas, todos os Squads).
6. IF a combinação de filtros não possuir dado, THEN THE PEP SHALL indicar visualmente a ausência de dado no painel afetado.
7. THE PEP SHALL persistir a seleção de filtros ao navegar entre os painéis de Retrospectiva de PI, sem resetar para o padrão.

### Requirement 21: Ingestão de Extração do Jira e Geração de Dados por Sprint

**User Story:** Como responsável pela plataforma, quero importar uma extração do Jira e gerar automaticamente os dados por Sprint, para que os painéis sejam alimentados a partir da fonte real sem digitação manual.

#### Acceptance Criteria

1. THE PEP SHALL permitir a importação de uma extração do Jira em formato CSV ou JSON, mantendo a abordagem de aplicação estática sem backend.
2. WHEN uma extração do Jira é importada, THE PEP SHALL derivar, a partir dos itens, os dados por Sprint, Squad, Jornada e PI utilizados pelos painéis de Retrospectiva de PI.
3. THE PEP SHALL reconhecer, na extração do Jira, no mínimo os campos: chave do item, tipo de item, status, Sprint, PI, Squad/Jornada, pontos (story points) e datas relevantes de fluxo.
4. WHEN os dados por Sprint são derivados, THE PEP SHALL calcular os indicadores dos painéis (pontos trabalhados, cycle time, lead time, situação de épicos, débito técnico, sustentação, carga de trabalho, impedimentos) a partir dos itens extraídos.
5. IF um item da extração estiver incompleto ou inválido, THEN THE PEP SHALL rejeitar o item e apresentar uma mensagem descritiva do erro, importando os itens válidos.
6. THE PEP SHALL registrar que a origem atual é a extração manual do Jira e que a integração automática (coleta direta via API do Jira) é uma evolução futura prevista, dependente de backend.
7. FOR ALL extrações importadas, exportar os dados derivados em CSV e importá-los novamente SHALL produzir um conjunto de dados equivalente (propriedade de ida e volta).

### Requirement 22: Carga do Excel de Baseline por PI

**User Story:** Como responsável pela plataforma, quero carregar o Excel de baseline de cada PI, para que as comparações de plano versus realizado e a situação dos épicos usem a referência oficial do PI.

#### Acceptance Criteria

1. THE PEP SHALL disponibilizar um campo para carregar o arquivo Excel de baseline de um PI, processado no navegador sem backend.
2. WHEN um Excel de baseline é carregado para um PI, THE PEP SHALL associar aquele baseline ao PI correspondente.
3. THE PEP SHALL utilizar o baseline carregado como referência nas comparações de Baseline versus Mudança de Escopo e na Situação dos Épicos daquele PI.
4. IF o arquivo de baseline estiver em formato inválido ou não reconhecido, THEN THE PEP SHALL rejeitar o arquivo e apresentar uma mensagem descritiva do erro, sem alterar o baseline atual.
5. WHERE existir baseline carregado para um PI, THE PEP SHALL permitir substituí-lo por um novo upload para o mesmo PI.
6. THE PEP SHALL indicar visualmente, por PI, se há ou não um baseline carregado.

### Requirement 23: Compatibilidade com o Operacional/Times e Fluxo de Dados

**User Story:** Como executivo, quero que os dados de Retrospectiva de PI sejam compatíveis com as métricas do Operacional/Times e que o Operacional/Times alimente as demais visões, para que a plataforma seja coesa e sem divergência entre telas.

#### Acceptance Criteria

1. THE PEP SHALL representar Jornada e Squad de forma consistente entre o módulo de Retrospectiva de PI e o Operacional/Times (Score de Performance), de modo que o mesmo Squad e a mesma Jornada sejam identificados igualmente nos dois módulos.
2. THE PEP SHALL utilizar os dados consolidados no Operacional/Times (Score de Performance por Jornada e por Squad) como fonte para alimentar as visões executivas de Score (Visão Geral, Jornadas, Métricas, Evolução mensal e Metas).
3. WHERE um indicador da Retrospectiva de PI corresponde a uma métrica do Score de Performance (ex.: Cycle Time, Frequência de Deploy, Disponibilidade), THE PEP SHALL permitir que o valor derivado da extração do Jira seja utilizado como entrada daquela métrica no Operacional/Times.
4. WHEN os dados do Operacional/Times são atualizados, THE PEP SHALL refletir a atualização nas visões executivas que dele dependem.
5. IF houver divergência de identificação de Jornada/Squad entre os módulos, THEN THE PEP SHALL sinalizar a inconsistência de forma visível, sem corromper os dados existentes.

### Requirement 24: Preservação e Natureza Estática após os Novos Fluxos

**User Story:** Como usuário da plataforma, quero que os filtros, a ingestão do Jira e o upload de baseline não quebrem a natureza estática nem as capacidades existentes, para que a plataforma permaneça simples de usar e estável.

#### Acceptance Criteria

1. THE PEP SHALL manter a execução como aplicação estática, sem necessidade de servidor, build ou instalação, após a adição dos filtros, da ingestão do Jira e do upload de baseline.
2. THE PEP SHALL processar a extração do Jira e o Excel de baseline inteiramente no navegador, sem transmitir dados a serviços externos.
3. THE PEP SHALL preservar todas as capacidades e visões já existentes do Score de Performance e dos painéis de Retrospectiva de PI após a adição dos novos fluxos.
4. THE PEP SHALL preservar a identidade visual de tema da plataforma e o padrão de UX do MVP existente nos novos controles de filtro e de carga de arquivos.

### Requirement 20: Filtros por Jornada, Squad, Sprint e PI nos painéis de Retro PI

**User Story:** Como gestor de engenharia, quero filtrar os painéis de Retrospectiva de PI por Jornada, Squad, Sprint e PI, para que eu analise os resultados no recorte que me interessa.

#### Acceptance Criteria

1. THE Painéis_de_Retro_PI SHALL disponibilizar filtros de Jornada, Squad, Sprint e PI aplicáveis às visões de Retrospectiva de PI.
2. WHEN o usuário seleciona uma Jornada, THE Painéis_de_Retro_PI SHALL restringir o Squad e os dados exibidos aos pertencentes àquela Jornada.
3. WHEN o usuário seleciona um Squad, THE Painéis_de_Retro_PI SHALL restringir os dados exibidos àquele Squad.
4. WHEN o usuário seleciona um PI, THE Painéis_de_Retro_PI SHALL restringir os dados ao PI selecionado e disponibilizar as Sprints pertencentes a esse PI.
5. WHEN o usuário seleciona uma Sprint, THE Painéis_de_Retro_PI SHALL restringir os dados à Sprint selecionada.
6. WHERE um filtro é definido como "Todos", THE Painéis_de_Retro_PI SHALL agregar os dados de todos os valores daquela dimensão.
7. IF a combinação de filtros não possuir dados, THEN THE Painéis_de_Retro_PI SHALL indicar visualmente a ausência de dados para o recorte selecionado.
8. THE Painéis_de_Retro_PI SHALL manter os filtros selecionados ao alternar entre os painéis de Retrospectiva de PI.

### Requirement 21: Ingestão de Extração do Jira e Geração de Dados por Sprint

**User Story:** Como responsável pela plataforma, quero carregar a extração de itens do Jira e gerar automaticamente os indicadores por Sprint, para que os painéis sejam alimentados a partir dos dados reais sem backend.

#### Acceptance Criteria

1. THE PEP SHALL permitir a importação de uma Extração do Jira em formato CSV ou JSON exportado do Jira, mantendo a abordagem de aplicação estática sem backend.
2. THE PEP SHALL mapear, a partir da Extração do Jira, ao menos os campos: chave do item, tipo de item, status, Jornada, Squad, Sprint, PI, Story Points e datas de fluxo necessárias para os indicadores.
3. WHEN uma Extração do Jira é importada, THE PEP SHALL gerar os indicadores por Sprint (pontos trabalhados, carga de trabalho e demais indicadores derivados de itens) agregando os itens por Sprint, Squad, Jornada e PI.
4. IF um item da Extração do Jira estiver sem os campos mínimos (chave, tipo, Sprint ou PI), THEN THE PEP SHALL rejeitar o item e apresentar uma mensagem descritiva do erro, importando os demais itens válidos.
5. THE PEP SHALL registrar que a origem atual é a Extração do Jira importada manualmente e que a coleta automática direta do Jira é uma evolução futura prevista (dependente de backend).
6. FOR ALL itens importados, exportar os dados gerados em CSV e importá-los novamente SHALL produzir um conjunto de dados equivalente (propriedade de ida e volta).
7. THE PEP SHALL permitir mapear/ajustar a correspondência entre valores de projeto/campos do Jira e as Jornadas e Squads da plataforma, para que itens de diferentes projetos sejam classificados corretamente.

### Requirement 22: Carga do Baseline do PI por Planilha

**User Story:** Como gestor de engenharia, quero carregar a planilha de baseline de cada PI, para que as comparações de Baseline versus Mudança de Escopo e as situações dos épicos reflitam o planejamento oficial do PI.

#### Acceptance Criteria

1. THE PEP SHALL disponibilizar um campo para carregar o arquivo de Baseline do PI em formato de planilha (.xlsx) ou CSV, processado no próprio navegador (client-side), sem backend.
2. WHEN um arquivo de Baseline do PI é carregado, THE PEP SHALL associar o Baseline ao PI correspondente e usá-lo como referência de planejamento daquele PI.
3. THE PEP SHALL utilizar o Baseline carregado nas comparações de Baseline versus Mudança de Escopo (Alterações no Plano e Situação dos Épicos).
4. IF o arquivo de Baseline estiver em formato inválido ou sem os campos esperados, THEN THE PEP SHALL rejeitar o arquivo e apresentar uma mensagem descritiva do erro, sem alterar o Baseline vigente.
5. WHERE já existe um Baseline carregado para um PI, THE PEP SHALL permitir substituí-lo por um novo carregamento, mantendo o anterior até a substituição ser bem-sucedida.

### Requirement 23: Compatibilidade e Fluxo de Dados entre Operacional/Times e as Demais Visões

**User Story:** Como usuário da plataforma, quero que os dados do Operacional/Times (Score de Performance por Jornada e Squad) sejam a fonte que alimenta as demais visões, para que todas as telas fiquem consistentes com uma única base de dados.

#### Acceptance Criteria

1. THE PEP SHALL tratar o Operacional/Times como a fonte das métricas de Score de Performance por Jornada e por Squad.
2. THE PEP SHALL alimentar as visões executivas de Score (Visão Geral, Jornadas, Métricas, Evolução Mensal e Metas) a partir dos dados do Operacional/Times.
3. THE PEP SHALL representar as Jornadas e Squads de forma consistente entre o Operacional/Times e os painéis de Retrospectiva de PI, de modo que o mesmo Squad seja identificável nas duas áreas.
4. WHEN os dados do Operacional/Times são atualizados (por importação ou entrada manual), THE PEP SHALL refletir a atualização nas visões alimentadas por eles.
5. WHERE um Squad existe nos painéis de Retrospectiva de PI mas não possui métricas de Score no Operacional/Times (ou vice-versa), THE PEP SHALL indicar visualmente a ausência de correspondência sem impedir a exibição das demais informações.
6. THE PEP SHALL manter a separação entre o Seletor de Mês (Score) e o Seletor de PI (Retrospectiva de PI), preservando a independência de navegação já estabelecida.

### Requirement 25: Perfis de Acesso e Visibilidade por Papel (simulação client-side)

**User Story:** Como organização, quero que a plataforma apresente menus e telas conforme o papel do usuário (Administrador, Delivery Manager, Tech Manager, Group Product Manager, Tech Lead), para que cada público veja apenas o que lhe compete.

> Nota de escopo: trata-se de uma **simulação de UX client-side** (Opção B, sem backend). O controle de perfil ajusta a navegação e a visibilidade de telas no navegador; **não constitui segurança real** (não há autenticação de servidor nem proteção de dados por credencial).

#### Acceptance Criteria

1. THE PEP SHALL oferecer um Seletor de Perfil com os papéis: Administrador do Sistema, Delivery Manager (DM), Tech Manager (TM), Group Product Manager (GPM) e Tech Lead (TL).
2. THE PEP SHALL persistir o Perfil selecionado (localStorage) e aplicá-lo em toda a navegação até ser alterado.
3. WHEN um Perfil é selecionado, THE PEP SHALL exibir apenas as entradas de menu e as telas permitidas àquele Perfil, ocultando as demais.
4. THE PEP SHALL definir a tela inicial conforme o Perfil: dashboard executivo de todas as jornadas para Administrador, DM, TM e GPM; dashboard executivo por squad para Tech Lead.
5. IF o usuário tentar acessar diretamente (ex.: via estado salvo) uma tela não permitida ao Perfil atual, THEN THE PEP SHALL redirecioná-lo para a tela inicial do Perfil e indicar que o acesso não é permitido.
6. THE PEP SHALL deixar explícito na interface que o controle por Perfil é uma simulação de visibilidade (sem autenticação de servidor).
7. WHERE o Perfil é alterado, THE PEP SHALL reavaliar imediatamente o menu e a tela ativa, mantendo o Mês e o PI selecionados quando a tela de destino os utilizar.

### Requirement 26: Menu de Navegação Estruturado por Seções

**User Story:** Como usuário, quero um menu organizado em seções de visualização e de gestão/configuração, para que eu encontre rapidamente dashboards e ferramentas conforme meu papel.

#### Acceptance Criteria

1. THE Menu SHALL organizar as entradas em duas seções: "Dashboards e Configurações" (visualização e configurações operacionais) e "Gestão e Configuração" (administração da plataforma).
2. THE Menu SHALL listar, na seção de dashboards: Dashboard executivo, Dashboard detalhado por jornada, Dashboard detalhado por squad, Dashboard de apresentação executiva, Dashboard de produtividade, Lançamento de exceção e Configuração de integração.
3. THE Menu SHALL listar, na seção de gestão: Gestão de perfis e permissionamento, Gestão de tribos e squads e Configuração de integração. (Os módulos de Gestão de usuários, Gestão de OKRs, Gestão de KPIs e Configuração de comunicação foram removidos do produto.)
4. THE Menu SHALL exibir cada entrada somente quando o Perfil atual tiver permissão para ela (conforme a matriz de permissões do Requirement 27).
5. THE Menu SHALL preservar a identidade visual de tema da plataforma e o padrão de UX do MVP existente.

### Requirement 27: Matriz de Permissões por Perfil

**User Story:** Como organização, quero uma matriz de permissões explícita por perfil, para que a visibilidade de cada tela seja consistente e auditável.

#### Acceptance Criteria

1. THE Matriz_de_Permissões SHALL conceder ao Administrador acesso a todas as telas de dashboards, gestão e configurações, incluindo aprovar exceções, com tela inicial no dashboard executivo de todas as jornadas.
2. THE Matriz_de_Permissões SHALL conceder ao DM as MESMAS permissões do Administrador (acesso total, incluindo aprovar exceções), com tela inicial no dashboard executivo de todas as jornadas; Administrador e DM são rótulos distintos com permissões idênticas.
3. THE Matriz_de_Permissões SHALL conceder ao TM: dashboards executivo por jornada, detalhado por squad, detalhado por jornada, apresentação executiva e produtividade; e a capacidade de SOLICITAR exceção (sem aprovar); sem telas de gestão/configuração.
4. THE Matriz_de_Permissões SHALL conceder ao GPM: dashboards executivo por jornada, detalhado por squad, detalhado por jornada e apresentação executiva; sem produtividade, sem gestão e sem exceção.
5. THE Matriz_de_Permissões SHALL conceder ao TL: dashboard executivo por squad, dashboard detalhado por jornada, dashboard detalhado por squad e dashboard de produtividade; sem telas de gestão/configuração e sem exceção; tela inicial no dashboard executivo por squad.
6. THE Matriz_de_Permissões SHALL distinguir, para a funcionalidade de exceção, duas permissões: "solicitar exceção" (TM, Administrador e DM) e "aprovar/rejeitar exceção" (Administrador e DM).
7. FOR ALL perfis, uma tela ou permissão não listada como permitida SHALL ser tratada como negada (padrão "nega por omissão").
8. THE Matriz_de_Permissões SHALL ser a fonte única consultada pelo Menu (Requirement 26) e pelo controle de acesso de telas (Requirement 25).

### Requirement 28: Dashboard Executivo por Squad

**User Story:** Como Tech Lead, quero um dashboard executivo focado no meu squad, para que eu acompanhe o desempenho do squad logo na entrada.

#### Acceptance Criteria

1. WHEN o Dashboard_Executivo_por_Squad é exibido, THE PEP SHALL apresentar o score de performance do Squad selecionado com a mesma régua de Faixas de Performance do Score.
2. THE Dashboard_Executivo_por_Squad SHALL permitir selecionar o Squad entre os squads disponíveis na identificação canônica.
3. THE Dashboard_Executivo_por_Squad SHALL exibir o detalhamento por categoria (4 Ps) e o Farol do Squad no período selecionado.
4. IF o Squad selecionado não possuir dados no período, THEN THE Dashboard_Executivo_por_Squad SHALL indicar visualmente a ausência de dados.

### Requirement 29: Dashboard de Apresentação Executiva

**User Story:** Como DM/TM/GPM, quero um modo de apresentação executiva por jornada, para que eu conduza reuniões executivas com uma visão limpa e sequencial.

#### Acceptance Criteria

1. THE Dashboard_de_Apresentação SHALL exibir, por Jornada, os indicadores executivos consolidados (score, faixa, atingimento da meta e destaques) em um layout voltado para apresentação.
2. THE Dashboard_de_Apresentação SHALL permitir navegar sequencialmente entre as Jornadas.
3. THE Dashboard_de_Apresentação SHALL reutilizar os dados do Operacional/Times, sem recalcular regras próprias.
4. THE Dashboard_de_Apresentação SHALL preservar o tema da plataforma e a identidade visual existentes.

### Requirement 30: Fluxo de Exceção por Indicador e Squad (Solicitação e Aprovação)

**User Story:** Como Tech Manager, quero solicitar exceções em indicadores de um squad e, como Delivery Manager, quero aprová-las ou rejeitá-las, para que situações justificadas passem por um fluxo de aprovação antes de refletir nas visões.

#### Acceptance Criteria

1. THE Fluxo_de_Exceção SHALL permitir que o Perfil Tech Manager (TM) crie uma Solicitação de Exceção selecionando o Squad e o indicador afetado, com justificativa e período de referência.
2. THE Solicitação_de_Exceção SHALL ser criada no estado "Pendente de aprovação".
3. THE Fluxo_de_Exceção SHALL permitir que os Perfis Delivery Manager (DM) e Administrador aprovem ou rejeitem uma Solicitação de Exceção Pendente, registrando quem decidiu e, no caso de rejeição, o motivo.
4. WHEN uma Solicitação de Exceção é aprovada, THE PEP SHALL marcar a exceção como "Aprovada" e sinalizar visualmente o indicador afetado nas visões, indicando a existência da exceção aprovada.
5. WHILE uma Solicitação de Exceção está "Pendente de aprovação", THE PEP SHALL exibi-la na fila de aprovação dos Perfis DM e Administrador e NÃO SHALL aplicar seu efeito às visões.
6. WHEN uma Solicitação de Exceção é rejeitada, THE PEP SHALL marcá-la como "Rejeitada" com o motivo e NÃO SHALL aplicar seu efeito às visões.
7. THE Fluxo_de_Exceção SHALL permitir ao Administrador e ao DM também criar exceções (equivalendo a solicitar e aprovar), conforme a Matriz de Permissões.
8. IF os campos obrigatórios da solicitação não forem preenchidos, THEN THE PEP SHALL rejeitar o registro e apresentar uma mensagem descritiva do erro.
9. THE PEP SHALL persistir as Solicitações de Exceção e suas decisões no navegador (localStorage), mantendo a natureza estática.
10. THE PEP SHALL registrar o histórico de cada Solicitação de Exceção (solicitante, aprovador/rejeitador, estados e datas), preservando a rastreabilidade.

### Requirement 31: Telas de Gestão (CRUD client-side) — Perfis, Tribos/Squads e Integração

**User Story:** Como Administrador (e DM, no escopo permitido), quero telas de gestão para administrar perfis, estrutura organizacional e integrações, para que a plataforma seja configurável sem edição de código.

> Nota de escopo: operações de gestão são **CRUD client-side** persistidos em localStorage (Opção B). Não há backend; os dados de gestão residem no navegador.
>
> Nota de evolução: os módulos de **Gestão de Usuários, Gestão de KPIs, Gestão de OKRs e Configuração de Comunicação foram removidos do produto**. As telas de gestão remanescentes são exclusivas de Administrador e DM.

#### Acceptance Criteria

1. THE Gestão_de_Perfis SHALL permitir visualizar e ajustar a associação de permissões por Perfil, disponível a Administrador e DM.
2. THE Gestão_de_Tribos_e_Squads SHALL permitir criar, listar, editar e remover Tribos (Jornadas) e Squads, mantendo a consistência com a identificação canônica usada nas demais visões, disponível a Administrador e DM.
3. THE Configuração_de_Integração SHALL permitir configurar os parâmetros de ingestão (ex.: mapeamento de projetos do Jira para Jornadas/Squads), disponível a Administrador (e DM quando aplicável), sem armazenar credenciais sensíveis no navegador.
4. FOR ALL telas de gestão, exportar os dados em CSV/JSON e importá-los novamente SHALL produzir um conjunto de dados equivalente (propriedade de ida e volta), preservando a natureza estática.
5. THE Telas_de_Gestão SHALL respeitar a Matriz de Permissões (Requirement 27), ocultando ou negando o acesso quando o Perfil não tiver permissão.
