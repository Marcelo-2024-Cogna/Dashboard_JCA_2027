# Motor de Ingestão de Dados — `xlsx → JSON → Banco`

Esta pasta é o ponto de entrada do **pipeline de ingestão** acordado para o
dashboard "Métricas Jeito Cogna Ágil". O objetivo é padronizar como os dados
de **Baseline** e **Velocidade/Produtividade/Formação dos times** entram no
banco a cada novo Program Increment (PI).

> ⚠️ **Status:** nesta etapa só existe a **estrutura de pastas e este
> contrato**. Os importadores ainda **não** foram implementados — isso é de
> uma **etapa futura**.

## Visão geral do pipeline

```
Planilha-fonte (.xlsx)  →  JSON normalizado  →  Banco (tabelas)
      [1]                        [2]                  [3]
```

### [1] Planilhas-fonte (entrada)

As planilhas originais são colocadas nas subpastas desta pasta:

- `data/import/baseline/` — planilhas de **Baseline** (o *committed* congelado
  no início da PI: Story Point Estimate por item/time).
- `data/import/velocidade/` — planilhas de **Velocity** (formação dos times,
  horas, dias úteis e dados que alimentam velocidade/produtividade).

### [2] Leitura e normalização para JSON

Um **leitor** converte a aba relevante da planilha em um **JSON normalizado**,
reaproveitando a técnica de leitura `.xlsx` já usada no projeto
(`ZipArchive` + `SimpleXML`, lendo o valor calculado das células) presente em:

- `bin/check_apoio_dates.php` — leitura da aba "Apoio" e conversão de seriais
  de data do Excel (epoch 30/12/1899).
- `bin/seed_catalogo.php` — leitura + carga transacional idempotente do
  catálogo.

O JSON normalizado é um passo intermediário auditável (permite conferir os
dados antes de gravar), seguindo o mesmo espírito da estrutura `data/` que já
era usada para JSON em versões anteriores.

### [3] Importador → Banco (transacional e idempotente)

Um **importador** valida o JSON e grava nas tabelas de dados:

- `baseline` — committed congelado da PI.
- `times_composicao` — formação dos times (qtde de devs, horas, produtividade
  de referência).
- `produtividade_historico` — histórico de produtividade por sprint fechada.

A gravação ocorre dentro de **uma transação InnoDB idempotente** (rodar de
novo não duplica), no mesmo padrão de `bin/seed_catalogo.php`.

## O dashboard LÊ SOMENTE DO BANCO

O dashboard **nunca** lê dos arquivos JSON desta pasta. Ele consulta
**apenas as views do banco** (via `App\Repositories\MetricsRepository` e
`App\Repositories\CatalogRepository`, que são **somente leitura**). O JSON é
só um estágio do pipeline de ingestão, não uma fonte de consumo do dashboard.

## A cada nova PI

A cada virada de PI (por exemplo, **PI 1/27**), a **baseline** nova e os dados
de **velocidade/produtividade/formação** dos times — que mudam de um ciclo
para outro — são **recarregados por aqui**: novas planilhas nas subpastas,
reexecução do leitor e do importador. Assim o banco reflete a realidade do
novo ciclo sem reescrever código.

## Outras pastas de dados relacionadas

- `data/agendamentos/` — reservada para agendamentos de execução (etapa
  futura).
- `data/exports/` — reservada para artefatos exportados pelo dashboard (etapa
  futura).
