# Plataforma de Engenharia de Performance (PEP)

Esta pasta reúne **todo o projeto** da Plataforma de Engenharia de Performance,
organizado em duas partes:

```
Plataforma-Engenharia-Performance/
├── aplicacao/        # A aplicação (app estático HTML/CSS/JS) + manual + modelos CSV
└── documentacao/     # Documentação técnica (requisitos, design, arquitetura, DDL, ADRs)
```

## O que é

Aplicação **100% estática** (sem backend) para calcular e visualizar o **Score de
Performance** dos times de engenharia (teto de 1089 pontos, organizado nos 4 Ps:
Processos, Práticas, Produto e Pessoas), além da **Retrospectiva de PI** com painéis
alinhados ao template de métricas e **perfis de acesso (RBAC)**.

Os dados são carregados por **importação manual de CSV/JSON** (extrações do Jira,
baselines de PI etc.) e salvos no **localStorage** do navegador. Integração automática
é evolução futura.

## Por onde começar

| Objetivo | Arquivo |
|----------|---------|
| Rodar a aplicação | `aplicacao/index.html` (abrir no navegador) |
| Entender como usar / alimentar dados | `aplicacao/MANUAL-DO-USUARIO.md` |
| Modelos de CSV para importar | `aplicacao/modelos-csv/` |
| Visão técnica da aplicação | `aplicacao/README.md` |
| Índice de toda a documentação | `documentacao/README.md` |
| Como o sistema funciona por dentro | `documentacao/funcionamento-do-sistema.md` |
| Requisitos do produto | `documentacao/requirements.md` |
| Decisões de design | `documentacao/design.md` |
| Plano de implementação | `documentacao/tasks.md` |
| Arquitetura (atual + alvo) | `documentacao/architecture.md` |
| Modelo de dados (ER) | `documentacao/data-model.md` |
| DDL do banco (produção) | `documentacao/database-ddl.sql` |
| Tematização e UI (tema claro) | `documentacao/guia-estilo-ui.md` |
| Como rodar os testes | `documentacao/guia-testes.md` |
| Publicar / hospedar | `documentacao/guia-deploy.md` |
| Decisões arquiteturais (ADRs) | `documentacao/adr/` |

## Observações

- A **spec viva** do Kiro continua em `.kiro/specs/score-performance-executivo/`
  (requirements.md, design.md, tasks.md). As cópias em `documentacao/` servem para
  leitura fora do Kiro; a spec no `.kiro/specs/` é a referência de trabalho.
- O app **não** precisa de `node_modules` para funcionar. Ele só é necessário para
  rodar os testes (Vitest + fast-check), conforme instruções no `aplicacao/README.md`.
