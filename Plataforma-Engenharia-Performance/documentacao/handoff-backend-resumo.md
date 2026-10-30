# Backend & Integrações — Resumo para conversa inicial (PEP)

> Versão de 1 página para o primeiro papo com o dev. O detalhamento completo (checklists,
> mapeamentos, schema) está em `handoff-backend-integracoes.md`.

## O que é a PEP, em uma frase
Plataforma de métricas de engenharia (Score de Performance mensal + Retrospectiva de PI)
hoje **100% estática** (HTML/CSS/JS, sem build), com dados em `localStorage` e alimentada por
**upload manual** de CSV/JSON (Jira) e `.xlsx` (baseline).

## O que já está pronto (e deve ser reaproveitado)
- **Núcleo de cálculo puro e testado** (score, agregações, metas, RBAC, identidade) — 440
  testes (Vitest + fast-check). Não reescrever regra; portar como pacote.
- **Modelo de dados** desenhado (`data-model.md` + `database-ddl.sql` PostgreSQL).
- **Contrato CSV/JSON** reversível (serve de seed e de ponte durante a migração).
- Carga **real do PI 4/26** já feita à mão (serve de baseline de regressão para o ETL).

## O que falta (o trabalho do backend)
1. **Banco + API + domínio** (M1): subir PostgreSQL, seed pelos CSVs, API de leitura dos
   dashboards reaproveitando o domínio puro.
2. **SSO + RBAC no servidor** (M2): hoje o controle de acesso é só visual no cliente.
3. **Integrações/ETL** (M4) — **maior valor, elimina a carga manual**:
   - **Jira**: coleta agendada por PI/Sprint (épicos→situação, débito, carga, velocity).
   - **Baseline**: Planejado/PI Planning por jornada.
   - **OKRs via Must Win Battles** no Jira.
   - Depois: ServiceNow (SLA) e Datadog (disponibilidade).
4. **Escrita via API** (M3): gestão e workflow de exceção.
5. **Fotos/snapshots**: congelar métricas no fechamento (mensal e por PI) para o comparativo
   histórico — exige tabelas novas (não existem ainda).

## Decisões a fechar na conversa
- Stack do backend (sugestão **Node/TypeScript** p/ reusar o domínio JS) e **REST ou GraphQL**.
- Onde rodam os **jobs de ETL** (worker/cron vs serverless agendado).
- **SSO**: Azure AD (contexto Cogna)?
- **Jira Cloud ou Server/DC?** (muda API/paginação) + conta de serviço de leitura.
- Fonte oficial do **headcount por squad** (necessário p/ Produtividade).

## Pontos de atenção que já descobrimos (poupam retrabalho)
- JQL `issuetype = "História"` é inconsistente → buscar sem filtro de tipo e agrupar no cliente.
- `jira_search` pagina por `next_page_token` e **não** retorna contagem total.
- **Nomes de squad divergem** entre cadastro e Jira (ex.: "Athenas - Atendimento" ↔
  "Atendimento", "Route66" ↔ "Route 66") → normalizar por identidade canônica no ETL.
- **Granularidade**: Pontos e Produtividade são por **jornada** (não por squad) na fonte; só
  Épicos tem squad real. Não fabricar número por squad.
- **Cycle/Lead Time, Sustentação, Impedimentos, Alterações** exigem **changelog** do Jira
  (item a item) — hoje estão "Sem dados"; é trabalho dedicado.
- Invariantes a preservar: score ∈ [0,1089]; "Sem Dados" ≠ zero; situação do épico é partição;
  Pendente = Planejado − Entregue − Cancelado.

## Ordem sugerida
**M1 → M2 → M4 (Jira/baseline/OKRs) → M3 → ServiceNow/Datadog → analytics/observabilidade.**

## Como validar
Reusar o **oráculo de equivalência** (testes do MVP) para o score; comparar a saída do **ETL
do Jira** com a carga manual do PI 4/26 (já no seed) como regressão.

---
Leitura de apoio: `architecture.md` (visão e diagramas), `roadmap-producao.md` (marcos),
`parametrizacao-metricas-jira.md` (fontes por métrica + fotos), `handoff-backend-integracoes.md`
(checklist completo com os mapeamentos) e `handoff-jira-insumos.md` (detalhes técnicos do ETL
do Jira e as decisões que dependem do time).
