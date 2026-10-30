# ADR 0005 — Estrutura de pastas: aplicacao/ + documentacao/

- Status: Aceito
- Data: 2026
- Contexto da decisão: reorganização do projeto

## Contexto

O projeto estava disperso (pasta `performance-engineering-mvp/` + arquivos de documentação
soltos). Precisávamos consolidar tudo em uma estrutura clara, sem quebrar a spec do Kiro.

## Decisão

Consolidar o projeto em `Plataforma-Engenharia-Performance/` com duas subpastas:

```
Plataforma-Engenharia-Performance/
├── LEIA-ME.md         # guia de entrada
├── aplicacao/         # a aplicação estática (index.html, css/, js/, tests/, modelos-csv/)
└── documentacao/      # documentação técnica e de produto
```

A **spec viva do Kiro permanece** em `.kiro/specs/score-performance-executivo/`
(`requirements.md`, `design.md`, `tasks.md`), pois o Kiro só reconhece specs nesse local.
Cópias desses arquivos são mantidas em `documentacao/` para leitura fora do Kiro.

## Justificativa

- Separa claramente **o que roda** (`aplicacao/`) do **que documenta** (`documentacao/`).
- Caminhos internos do app são relativos (`js/`, `css/`), então mover a pasta não quebra nada.
- A spec no `.kiro/specs/` continua sendo a referência de trabalho do agente.

## Consequências

- Positivas: navegação simples, onboarding mais rápido, publicação do app = servir
  `aplicacao/`.
- Negativas: há **duas cópias** de requirements/design/tasks (spec viva + cópia em doc). É
  preciso **atualizar ambas** quando a spec mudar (procedimento seguido nas alterações).
- Numeração dos modelos CSV preserva "buracos" (04/06/07/09 removidos) para não quebrar
  referências cruzadas — documentado no `modelos-csv/LEIA-ME.md`.

## Observações

- Uma pasta residual vazia `performance-engineering-mvp/` pode ter permanecido travada por
  processo externo do SO durante a migração; é inócua e pode ser removida manualmente.
