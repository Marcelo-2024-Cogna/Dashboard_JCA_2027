# Documentação — Plataforma de Engenharia de Performance (PEP)

Índice navegável de toda a documentação técnica e de produto do projeto.

## Produto e requisitos
- [requirements.md](./requirements.md) — Requisitos (user stories + critérios de aceite).
- [design.md](./design.md) — Decisões de design, regras de domínio e matriz de permissões.
- [tasks.md](./tasks.md) — Plano de implementação (status das tarefas por fase).

## Arquitetura e dados
- [architecture.md](./architecture.md) — Arquitetura atual (app estático) + alvo de produção + migração.
- [funcionamento-do-sistema.md](./funcionamento-do-sistema.md) — Como o sistema funciona por dentro: módulos JS, fluxo de dados (import → cálculo → render), estado/persistência e extensão.
- [data-model.md](./data-model.md) — Modelo lógico/ER com diagrama.
- [database-ddl.sql](./database-ddl.sql) — DDL PostgreSQL para o alvo de produção.
- [adr/](./adr/) — Architecture Decision Records:
  - [0001](./adr/0001-app-estatico-sem-backend.md) — App estático sem backend (Opção B).
  - [0002](./adr/0002-perfis-client-side.md) — Perfis/RBAC client-side.
  - [0003](./adr/0003-ingestao-manual-csv-jira.md) — Ingestão manual CSV/Jira.
  - [0004](./adr/0004-tema-claro-via-variaveis-css.md) — Tema claro via variáveis CSS.
  - [0005](./adr/0005-estrutura-pastas-aplicacao-documentacao.md) — Estrutura de pastas (aplicacao/ + documentacao/).
  - [0006](./adr/0006-carga-real-pi4-26-seed.md) — Carga real do PI 4/26 no seed (Jira + baseline), sem fabricar dados.

## Engenharia e manutenção
- [guia-estilo-ui.md](./guia-estilo-ui.md) — Tematização (tema claro, variáveis CSS, dark mode futuro), layout e convenções de UI.
- [guia-testes.md](./guia-testes.md) — Como rodar a suíte (Vitest + fast-check), ambiente (Node 24 / Vitest 3) e armadilhas (caminho com espaços).

## Operação e uso
- [MANUAL-DO-USUARIO.md](./MANUAL-DO-USUARIO.md) — Manual completo (páginas, perfis, como alimentar dados).
- [modelos-csv/](./modelos-csv/) — Modelos de CSV para importação + LEIA-ME.
- [guia-deploy.md](./guia-deploy.md) — Como publicar o app estático (opções de hospedagem).
- [roadmap-producao.md](./roadmap-producao.md) — Backlog para evoluir o app até produção.
- [handoff-backend-resumo.md](./handoff-backend-resumo.md) — Resumo de 1 página para a conversa inicial com o dev de backend.
- [handoff-backend-integracoes.md](./handoff-backend-integracoes.md) — Lista acionável para o dev de backend: banco, API, SSO/RBAC e integrações (ETL Jira/baseline/OKRs), com mapeamentos reais validados.
- [handoff-jira-insumos.md](./handoff-jira-insumos.md) — Insumos técnicos do ETL do Jira (JQL, paginação, mapeamento, status→situação, changelog, invariantes) e as decisões que dependem do time.
- [pauta-decisoes-integracoes.md](./pauta-decisoes-integracoes.md) — Pauta de reunião: as 7 decisões de negócio (contexto, opções, recomendação) que destravam o ETL, com quadro-resumo.

## Referência
- [glossario.md](./glossario.md) — Termos do domínio (jornada, squad, PI, sprint, score, farol...).
- [faq.md](./faq.md) — Perguntas frequentes.

## Convenções
- Idioma: português (BR).
- CSV: separador `;` e UTF-8 com BOM (compatível com Excel BR).
- Score: 4 categorias (os 4 Ps), teto de **1089 pontos** (base 1000 + bônus).
- UI: **tema claro** por variáveis CSS (ver `guia-estilo-ui.md`).
- Módulos de gestão ativos: Perfis, Tribos/Squads e Integração (Usuários, KPIs, OKRs e
  Comunicação foram removidos do produto).
- A spec viva do Kiro fica em `.kiro/specs/score-performance-executivo/`; os arquivos
  `requirements.md`, `design.md` e `tasks.md` aqui são cópias para leitura.
