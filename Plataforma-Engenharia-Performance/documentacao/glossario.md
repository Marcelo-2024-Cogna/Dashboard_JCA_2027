# Glossário — Plataforma de Engenharia de Performance (PEP)

Termos do domínio usados na plataforma, nos requisitos e nos documentos técnicos.

## Estrutura organizacional

**Jornada** — Agrupamento de squads por área de negócio/produto (ex.: Acadêmica, Financeira,
Ingresso). É o nível de agregação executiva mais alto nos dashboards.

**Tribo** — Sinônimo organizacional de jornada em alguns contextos; nas telas de gestão
aparece como "Tribos e Squads".

**Squad** — Time de desenvolvimento. Unidade mínima de medição do Score de Performance.

**Operacional/Times** — Visão que consolida o Score de Performance de cada jornada e squad;
é a fonte que **alimenta as demais visões** (Geral, Jornadas, Métricas, Evolução, Metas).

## Score de Performance

**Score de Performance** — Pontuação consolidada de um squad, com teto de **1089 pontos**
(base de 1000 + bônus por métricas superadas), reconciliada com a planilha de referência
("Pílula do Score").

**4 Ps / Categorias** — As quatro categorias que compõem o score:
- **Processos** (450 pts base) — Planejado vs Realizado, Cycle Times, Frequência de Deploy.
- **Práticas** (450 pts base) — Disponibilidade, Vulnerabilidade, SLAs.
- **Produto** (50 pts) — Alinhamento com OKRs.
- **Pessoas** (50 pts) — Team Health Check.

**Dimensões (legado)** — Visão antiga de 3 agrupamentos (Pessoas/Processos/Práticas) mantida
por compatibilidade em partes do dashboard. A fonte da verdade são as 4 categorias.

**Faixa de Performance (Band)** — Classificação do score total:
Baixa (0–500), Média (501–839), Boa (840–1000), Ótima (1001–1089).

**Interpolação proporcional** — Cálculo do score dentro de uma faixa por interpolação linear
entre os limites mínimo e máximo da faixa.

**Farol** — Indicador de atendimento de meta; considera atendimento **≥ 99%** como "verde".

**Meta progressiva** — Alvo que evolui ao longo do tempo (por mês/PI), por time/jornada.

## Métricas

**Planejado vs Realizado** — Razão entre o que foi entregue e o planejado no período.

**Cycle Time** — Tempo de ciclo em uma etapa (Desenvolvimento, Homologação, Implantação).
Métrica "quanto menor, melhor".

**Lead Time** — Tempo total do item desde a criação até a entrega.

**Frequência de Deploy** — Métrica categórica da cadência de deploys do squad.

**Disponibilidade** — Percentual de disponibilidade dos sistemas do squad.

**Índice de Vulnerabilidade** — Percentual relacionado à remediação/ausência de
vulnerabilidades.

**SLA (Alta/Média/Baixa Criticidade)** — Percentual de atendimento ao SLA por nível de
criticidade dos chamados/incidentes.

**OKRs** — Objetivos e Resultados-Chave; a métrica "Alinhamento com OKRs" compõe a categoria
Produto.

**Team Health Check (THC)** — Avaliação de saúde do time, em escala **0–10**, compondo a
categoria Pessoas.

**Débito Técnico** — Volume/indicador de dívida técnica acompanhado na Retrospectiva de PI.

**Sustentação** — Esforço/itens de manutenção e suporte acompanhados na Retro PI.

## Agilidade e cadência

**PI (Program Increment)** — Incremento de programa; janela de planejamento que agrupa várias
sprints (modelo SAFe).

**Sprint** — Ciclo curto de desenvolvimento (tipicamente 2 semanas).

**Baseline de PI** — Planilha/arquivo de referência carregado por PI, usado para comparar o
realizado com o planejado.

**Pontos Trabalhados** — Soma de story points trabalhados no período.

**Produtividade** — Relação entre pontos entregues e capacidade/esforço do time.

**Épico** — Item de trabalho grande, decomposto em histórias; a Retro PI tem painel de
"Situação dos Épicos".

**Retrospectiva de PI (Retro PI)** — Módulo com painéis de métricas do PI (pontos,
produtividade, épicos, cycle/lead time, débito técnico, sustentação, extras), com filtros
por Jornada, Squad, Sprint e PI.

## Perfis e acesso (RBAC)

**RBAC** — Controle de acesso baseado em papéis.

**Admin** — Administrador do sistema; acesso total. Equivalente ao DM em permissões.

**DM (Delivery Manager)** — Acesso total (idêntico ao Admin); aprova exceções.

**TM (Tech Manager)** — Dashboards + produtividade; solicita exceção (não aprova).

**GPM (Group Product Manager)** — Apenas 4 dashboards de visualização (sem produtividade nem
gestão).

**TL (Tech Lead)** — Executivo/detalhado por squad + produtividade.

**Exceção (de indicador)** — Pedido para desconsiderar/ajustar um indicador de um squad; fluxo
*solicitar (TM) → aprovar (DM/Admin)*.

> Nota: as telas de gestão restantes (Perfis, Tribos/Squads, Integração) são exclusivas de
> Admin/DM. Os módulos de Gestão de Usuários, Gestão de KPIs, Gestão de OKRs e Configuração
> de Comunicação foram removidos do produto.

## Fontes de dados

**Extração do Jira** — Arquivo CSV/JSON exportado do Jira, importado manualmente para gerar
os dados por sprint/PI.

**localStorage** — Armazenamento do navegador onde a aplicação persiste os dados importados
e as edições (sem backend).
