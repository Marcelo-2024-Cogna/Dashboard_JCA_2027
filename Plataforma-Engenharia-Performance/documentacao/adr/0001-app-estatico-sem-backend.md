# ADR 0001 — Aplicação estática sem backend (Opção B)

- Status: Aceito
- Data: 2026
- Contexto da decisão: fase MVP da PEP

## Contexto

A plataforma precisava entregar rapidamente valor analítico (score, visões executivas, retrospectiva de PI) sobre dados que hoje vêm de planilhas e extrações manuais. Havia duas opções para a origem/coleta de dados e persistência:

- **Opção A:** backend com integração ao vivo (Jira/ServiceNow/Datadog) e banco de dados.
- **Opção B:** aplicação estática (HTML/CSS/JS), sem backend, importando extrações manuais e persistindo no navegador.

## Decisão

Adotar a **Opção B**: aplicação estática, sem servidor/build para rodar, com importação manual (CSV/JSON) e persistência em `localStorage`.

## Justificativa

- Entrega rápida e sem dependências de infraestrutura/credenciais.
- Integração ao vivo com o Jira exigiria backend/proxy para não expor credenciais no navegador — risco e custo incompatíveis com o MVP.
- O domínio (motor de score, agregações) pôde ser desenvolvido puro e testável, independente de backend.

## Consequências

- Positivas: simplicidade, portabilidade (abre via `file://`), domínio testável por PBT, time-to-value curto.
- Negativas: dados por navegador (sem compartilhamento/backup), sem autenticação real, coleta manual.
- Mitigações: exportação/backup via CSV/JSON; o modelo de dados e o DDL de produção já foram desenhados para a transição.

## Revisão

Será revisto na fase de produção (ver `architecture.md`, seção 4), quando backend, banco e integrações forem introduzidos.
