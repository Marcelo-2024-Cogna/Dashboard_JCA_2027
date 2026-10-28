# Motor de Ingestão de Dados — `xlsx → JSON → Banco`

Esta pasta é o ponto de entrada do **pipeline de ingestão** acordado para o
dashboard "Métricas Jeito Cogna Ágil". O objetivo é padronizar como os dados
de **Baseline** e **Velocidade/Produtividade/Formação dos times** entram no
banco a cada novo Program Increment (PI).

> **Status:** o **importador da Baseline** (`bin/import_baseline.php`) e a
> **ferramenta de conferência planilha x banco** (`bin/conferir_planilha.php`)
> estão **implementados** (ver seções abaixo). Os importadores de Velocity
> (calendário/produtividade) seguem como etapa futura (ver pendências).

## Importador da Baseline — `bin/import_baseline.php` (implementado)

```
php bin/import_baseline.php --pi="PI 4/26" [--arquivo=CAMINHO.xlsx] [--dry-run] [--substituir]
```

| Parâmetro | Descrição |
|---|---|
| `--pi` | **Obrigatório.** Descrição da PI (`configuracao_pi.desc_pi`), ex.: `PI 4/26`. |
| `--arquivo` | Planilha de origem. Padrão: o `.xlsx` mais recente de `data/import/baseline/`; se a pasta estiver vazia, **copia** `Template Baseline 26-4.xlsx` da raiz. |
| `--dry-run` | Gera o JSON e o relatório, **não grava** no banco. |
| `--substituir` | Permite sobrescrever uma baseline já existente da PI. |

Pipeline: **[1]** lê a aba `Baseline itens` (valida o cabeçalho) e gera
`data/import/baseline/baseline_<pi-slug>_<aaaammdd_hhmmss>.json` (itens aceitos,
rejeitados com motivo e metadados/contagens); **[2]** valida e imprime o
relatório (lidos, aceitos, rejeitados por motivo, distribuição por
jornada/time, Σ `story_point_estimate` por time); **[3]** carrega em `baseline`
numa única transação.

**Linhas da PI alvo.** A coluna *Versões corrigidas* está vazia em parte das
linhas, então uma linha pertence à PI se *Versões corrigidas* contém o token da
PI (`PI 4/26`) **ou** *Sprint* contém o token no formato de sprint (`26.4`, em
`SP 26.4.1`, `PI 26.4`, `SP 26.4 - Planejamento`...). O critério usado fica em
cada item do JSON (`fixversion` | `sprint` | `ambos`). O que não casa é
rejeitado e listado no JSON, nunca descartado em silêncio.

**Rejeições:** cabeçalho divergente (aborta tudo), campo obrigatório vazio,
chave fora do formato `XXXX-N`, chave duplicada, `projeto_key` (prefixo da
chave) **sem time ATIVO** em `times.projeto_key`, story point não numérico,
texto maior que a coluna. Cada motivo vai para o `data/logs/importacao.log`
como WARNING (com os `projeto_key` e contagens, sem textos dos itens).

**Regra de imutabilidade.** A baseline é um **snapshot imutável por PI**. Se já
existem linhas da PI e `--substituir` não foi passado, o importador **aborta
sem alterar nada** (exit 3). Com `--substituir`, apaga as linhas da PI e
recarrega na mesma transação (não duplica). Falha na carga = rollback + ERROR
no log (exit 4). Exit 1 = parâmetros/arquivo/PI; 2 = cabeçalho divergente.

## Conferência planilha x banco — `bin/conferir_planilha.php` (implementado)

Somente leitura. Compara a aba `Extração Jira - Itens` da planilha *Template
Métricas Review* com `extracao_jira` / `vw_extracao_calculada`.

```
php bin/conferir_planilha.php --projetos=SGGU,SGSE,SGGS,SGTB [--sprints=26.4.1,26.4.2]
                              [--planilha="Template Métricas Review.xlsx"] [--aba="Extração Jira - Itens"]
```

- `--projetos` (obrigatório): prefixos de chave.
- `--sprints` (opcional): restringe o lado do **banco** aos itens cujo campo
  `sprint` contém essas sprints (a planilha é extraída por sprint; o banco, por
  fixVersion da PI).
- Valida o cabeçalho (posições das colunas) e aborta com mensagem clara se divergir.
- Relatório por projeto e total (stdout + `data/exports/conferencia_<aaaammdd_hhmmss>.txt`):
  contagens, chaves só na planilha/só no banco (com causa agrupada: carry-over de
  PI anterior, sem fixVersion, PI posterior...), divergências nos 14 campos brutos
  (datas comparadas em precisão de minuto), colunas calculadas AH/AK/AM/AO/AQ/AS
  x view (valores **negativos** da planilha sinalizados) e totais (itens, SP, SP
  de valor entregue, estimate). Resumo no log `importacao`.

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

## Virada de PI (ex.: PI 4/26 → PI 1/27) — o que alimenta o quê

Princípio: **nada de PI/sprint/time fica fixo em código**. Dashboard, Painel
e Modal de Extração leem o catálogo do **banco**; o banco é alimentado pelas
planilhas via este pipeline.

| Fonte | Alimenta | Tabelas |
|---|---|---|
| **Velocity** — aba *Apoio* (calendário) | PIs, sprints, dias úteis, datas | `configuracao_pi`, `sprints` |
| **Velocity** — aba de produtividade do ano | jornadas, times, composição, sustentação por sprint | `jornada`, `times`, `times_composicao`, `sustentacao_historico` |
| **Baseline** (itens planejados) | committed congelado da PI + conferência | `baseline` |
| Jira (modal → fila → worker) | itens do estado atual | `extracao_jira` |

A Baseline **não** é a fonte do catálogo: ela só traz os projetos que têm itens.
O catálogo de times/calendário vem da Velocity; a Baseline serve para o
committed e para **conferir** (PIs/sprints/projeto_key que aparecem nela devem
existir no catálogo; divergência vai para `data/logs/importacao.log`).

### Passo a passo da virada
1. Colocar as planilhas novas em `data/import/velocidade/` e `.../baseline/`.
2. Rodar a conferência (relatório sem gravar): datas das sprints (serial Excel,
   epoch 30/12/1899), times sem `projeto_key` (ver `config/team_keys.php`),
   projetos da Baseline sem time no catálogo.
3. Validar o relatório e só então carregar (transacional, idempotente).
4. Conferir o log `data/logs/importacao.log` e o relatório final.

### Pendências conhecidas (não implementadas — fazer antes da PI 1/27)
- **Importador do calendário (Apoio)** → `configuracao_pi` + `sprints`
  (o seed atual NÃO toca nessas tabelas).
- **Tipo da sprint** (`valor` | `sustentacao`): hoje foi marcado à mão
  (SP 26.4.7/26.4.8 = sustentação). Definir de onde vem na PI 1/27
  (coluna na planilha ou parâmetro no Painel).
- **`vw_velocidade` tem `'PI 4/26'` fixo**: passar a expor PI e sprint como
  colunas (filtráveis) em vez de fixar a PI.
- **Painel de Controle** ainda grava JSON da v1.0 (`/settings`,
  `data/config/*.json`, `config/pi.php`): migrar para o banco.
- **Filtros do dashboard** ainda saem do `/extract` (cache v1.0): passar a ler
  o catálogo do banco, como o modal já faz (`/extracao/catalogo`).
- **PI corrente** deve ser um dado (ex.: PI cuja janela contém a data de
  hoje), não uma constante. (O importador da Baseline já está implementado.)
- **Baseline: projetos sem time no catálogo** (hoje DFCR, DFOC, DFFA, JAES,
  JACM, JASA, PGBS, VGFE) são rejeitados e reportados; cadastrar os times na
  Velocity/`config/team_keys.php` e rodar `--substituir`.
