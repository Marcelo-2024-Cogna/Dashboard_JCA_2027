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

## Ajuste de baseline (trilha de auditoria)

A baseline é um **snapshot imutável por PI**: depois da carga, o valor de um
item só muda por um **ajuste formal**, com **motivo** (mínimo 10 caracteres) e
**responsável** obrigatórios. Toda alteração fica em `baseline_ajuste`
(nunca é apagada), visível na view `vw_baseline_ajuste`.

### CLI — `bin/ajustar_baseline.php`

```
# ajustar (campos: story_point_estimate, story_points, sprint, status, tipo_item, pai)
php bin/ajustar_baseline.php --pi="PI 4/26" --chave=APAP-12467 --campo=story_point_estimate \
    --valor=5 --motivo="Estimativa corrigida após refinamento" --usuario=marcelo.doliveira

# listar a trilha (filtros opcionais)
php bin/ajustar_baseline.php --listar
php bin/ajustar_baseline.php --listar --pi="PI 4/26" --chave=SGGS-18004 --origem=regularizado

# reverter: reaplica o valor anterior como NOVO ajuste formal (o histórico permanece)
php bin/ajustar_baseline.php --reverter=17 --motivo="Ajuste feito por engano" --usuario=marcelo.doliveira
```

Valores numéricos devem ser ≥ 0; o valor precisa diferir do atual (senão: "sem
alteração", exit 0). Ajustar `sprint` recalcula `sprint_ref` no mesmo UPDATE.
Exit: 0 ok · 1 uso incorreto · 2 falha de validação · 4 falha de execução (rollback).
O serviço (`App\Services\BaselineAjusteService`) faz tudo numa transação e
**desfaz o ajuste se a trilha não for gravada** (trigger ausente).

### O que o trigger registra — `db/triggers.sql`

`trg_baseline_audit_upd` (AFTER UPDATE em `baseline`) grava **uma linha por
coluna alterada** entre: `story_point_estimate`, `story_points`, `sprint`,
`sprint_ref`, `tipo_item`, `status`, `pai`, `projeto`, `ativo`.

| origem | quando | motivo / usuário |
|---|---|---|
| `formal` | via serviço/CLI (variáveis de sessão `@ajuste_motivo`/`@ajuste_usuario`) | informados |
| `sql_direto` | qualquer outro `UPDATE` (cliente SQL, script) | motivo vazio; usuário = `USER()` do MySQL |
| `regularizado` | UPDATE anterior ao trigger, registrado depois (`bin/regularizar_ajustes_baseline.php`) | informados no script |

**UPDATE direto é DETECTADO, não bloqueado.** `DELETE`/`INSERT` não são
auditados (reimportação com `--substituir` é um evento da carga, no log
`importacao`). A previsibilidade exibe o aviso "N item(ns) da baseline desta
seleção foram ajustados após a carga" quando algum item planejado para a
janela tem registro na trilha (os números não mudam).

### Regularização dos ajustes feitos antes da trilha

`php bin/regularizar_ajustes_baseline.php [--dry-run]` compara o JSON de
auditoria da carga (valor anterior) com a baseline atual e insere as
diferenças como `regularizado`. Idempotente (não duplica PI+chave+campo) e
**não altera `baseline`** (confere um hash antes/depois). Hoje: 10 campos em 8
itens do SGGS (UPDATE direto de 28/10/2026 ~21:12).

### Aplicação em produção (CentOS) — conta privilegiada

Com `log_bin` ligado, criar trigger exige `SUPER` (ou
`log_bin_trust_function_creators=1`) além de `TRIGGER`; o usuário de aplicação
(`metricas_jca`) recebe erro 1419. O **DBA aplica `db/triggers.sql` com uma conta
privilegiada**, depois de `db/schema.sql`:

```
mysql -u <conta_privilegiada> -p metricas_agil < db/triggers.sql
```

O app **não recebe `TRIGGER`** (não precisa em runtime; sem ele não consegue
remover a auditoria). O trigger usa `DEFINER` da conta que o criou: ela deve
existir no servidor. Reaplicar o arquivo é seguro (`DROP TRIGGER IF EXISTS`).
Scripts que atualizam `baseline` (ex.: `bin/backfill_sprint_ref.php`) passam a
gerar linhas `sql_direto` quando alteram valores.

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

### Regras acordadas (28/10/2026)
- **Ideação negativa** (item criado depois do ponto de compromisso): vale **1**.
  A planilha mostra o valor negativo; a divergência é esperada.
- **Escopo da extração**: somente `fixVersion ~ "*PI x/yy*"` (sem `OR Sprint`).
  Carry-over de PI anterior e itens sem versão ficam de fora; revisitar no
  relatório de Sustentação (pausado). Evita duplicar o mesmo item em duas PIs.

### Planejado: modal de VALIDAÇÃO + importação (antes da PI 1/27)
Tela (Painel) para subir Baseline e Velocity, rodar a validação (dry-run) e só
então confirmar a carga. Validação **síncrona**; carga com confirmação explícita.
Checagens: nomes de jornadas e de times (Velocity x Baseline x catálogo),
`projeto_key` órfão nos dois lados, PIs e sprints existentes no calendário,
fixVersions inesperadas e valores negativos. Relatório separa **bloqueios** de
**avisos**. Upload: validar extensão/tipo/tamanho, nome aleatório, log de
auditoria em `importacao.log`; baseline continua imutável por padrão.
Depende dos importadores da aba Apoio e da Velocity (ainda não implementados).

### Previsibilidade e Planejado x Realizado — definições (28/10/2026)
**Previsibilidade** (itens de VALOR, sprints FECHADAS ou a sprint filtrada):
- Denominador (committed) = **Story Point Estimate da baseline** dos itens
  planejados para as sprints da janela (sprint_ref da baseline).
- Numerador = **Story Points atuais** (extração) desses mesmos itens que hoje
  estão em status entregue (Aceito, Pendente de Produção, Em produção).
- A diferença estimate x story points mostra o que mudou entre PI Planning e
  Sprint Planning.
- **Transbordo** é mostrado explicitamente: item planejado para a sprint da
  janela que hoje está em sprint posterior (entregue depois ou ainda aberto),
  devolvido ao backlog, cancelado ou fora da extração. Escopo adicionado =
  itens da janela que não estavam na baseline.

**Planejado x Realizado** (4 barras, cada uma dividida em Sustentação /
Entrega de valor sem épico / Entrega de valor com épico — épico = `pai`):
1. **Velocidade** = Sustentação (média da PI passada) + Capacidade
   (velocity − sustentação), somadas nas sprints da janela.
2. **PI Planning** = o que foi comprometido na baseline (Story Point Estimate).
3. **Sprint Planning** = PI Planning ± novas histórias e cancelamentos
   durante as sprints (estado atual da extração nas sprints da janela).
4. **Entregues** = pontos entregues no review.

### Decisões (28/10/2026, noite)
- **Previsibilidade: sem fallback ao Story Point Estimate.** Se o Story Points
  atual de um item entregue estiver vazio, ele conta 0 no numerador (o
  diagnóstico `diagnostico_sp_nulo` continua visível na tela/API).
- **Ajustes manuais na baseline (fase de testes)**: 10 campos em 8 itens do SGGS
  foram editados por `UPDATE` direto em 28/10 ~21:12 (por marcelo.doliveira).
  Ficam mantidos por enquanto; serão regularizados na trilha de auditoria.
  Quando houver o modal de importação, a base será **zerada e reimportada**
  para uma carga fiel às planilhas.
- **Ajuste de baseline com trilha de auditoria**: a baseline é imutável por PI;
  correções passam a ser feitas por `bin/ajustar_baseline.php` (motivo e
  responsável obrigatórios). Um trigger no banco registra também qualquer
  `UPDATE` direto (origem `sql_direto`).

### Decisões (28/10/2026, fim do dia)
- **Suporte = Sustentação** (tipo `Suporte` passa a compor o cinza do Planejado x
  Realizado). Regra aplicada no código (`plan_real_sustentacao_types` em `config/baseline.php`).
- **Sprint Planning fiel (snapshot no início da sprint)**: fica como está por
  enquanto.
- **Lead Time**: só itens de valor entregues com sprint de referência na janela;
  a **ideação não entra no gráfico nem na conta do lead time**.

### Lead Time (cycle time médio por etapas) — definição implementada (29/10/2026)
Página `/metricas/cycle-time`, endpoint `GET /graficos/lead-time`, view
`vw_lead_time_item` (a regra de `lead_time` de `vw_extracao_calculada`, com
ideação, não mudou: segue para os relatórios legados e a conferência).
- **População**: itens de entrega de valor (História, Enabler, Débito Técnico,
  Spike, Teste, Segurança) **entregues** (Aceito, Pendente de Produção, Em produção)
  da extração (ativo, PI escolhida) com `sprint_ref` atual na **janela** (mesma
  da Previsibilidade: sprints de valor fechadas ou a sprint do filtro).
  Sustentação, Suporte, Incidente, Solicitação, Bug, Liderança e Epic ficam fora
  (contados em `diagnosticos.itens_fora_por_tipo`).
- **Etapas** (dias úteis, de `vw_extracao_calculada`): Desenvolvimento,
  Homologação, Implantação; qualquer uma pode ser vazia. **Sem ideação.**
- **Lead time do item** = soma das etapas que ele TEM. Item sem nenhuma etapa
  fica fora das médias (`itens_sem_etapa`).
- **Média do time** = média do lead time dos itens do time. **Média da jornada**
  = média sobre TODOS os itens da jornada (não a média das médias dos times).
- **Segmentos da barra = contribuição** da etapa = Σ etapa (vazio = 0) ÷ nº de
  itens do time; Dev + Hom + Impl = lead time médio. Informação adicional:
  `media_nos_itens` (Σ etapa ÷ itens que têm a etapa, `n`) — o modo da planilha
  (tabela dinâmica ignora células vazias), além de P50 (mediana) e máximo do
  lead time por time, só como dado para avaliar outliers (sem tratamento).
- **Planilha** (`Cycle Time (Novo)`): tabela dinâmica sobre toda a aba de extração
  (todas as sprints da aba, só História/Enabler/Débito Técnico entregues, com
  ideação). Diferenças para o nosso gráfico: inclui ideação, restringe tipos,
  não filtra janela e é um snapshot. `Lead Time (Novo)` é só um exemplo com
  números fixos (SQUAD A..H), sem fórmula sobre a extração.

### Produtividade por PI (29/10/2026)
- **Definição (horas para entregar 1 ponto)**, por time e PI: itens de VALOR
  ENTREGUES da PI (`extracao_jira.configuracao_pi_id`);
  `produtividade = Σ(soma_tempo_gasto)/3600 ÷ Σ(story_points)`. Sem fallback para
  o estimate quando o SP está vazio (`itens_sp_nulo` fica visível na view).
- **PI corrente** = ao vivo (`vw_produtividade_pi`); **PIs fechadas** = consolidadas
  em `produtividade_historico` por `bin/consolidar_produtividade.php --pi="PI 1/26"`
  (idempotente; recusa a PI corrente sem `--forcar`; `--dry-run`).
- Para ter histórico é preciso extrair a PI passada antes (runner CLI com
  `--pi="PI x/yy"`); a mesma chave pode aparecer em duas PIs (ver cuidado de JOIN).

### Lead Time: implantação pendente (29/10/2026)
Itens "Pendente de Produção" contam como entregues, mas muitos ainda não têm fim de
implantação; o lead time soma só as etapas que o item tem. A tela sinaliza
"Implantação concluída x de n" (⚠ quando pendente). Times com muitos pendentes
aparecem com a Implantação subestimada e não são diretamente comparáveis.

### Gráfico de Produtividade na tela (29/10/2026)
- **Página** `/metricas/produtividade` · **endpoint** `GET /graficos/produtividade?jornada=&time=&versao=&sprint=` (400 para filtro inexistente).
- **h/ponto** = Σ(soma_tempo_gasto)/3600 ÷ Σ(story_points) dos itens de VALOR ENTREGUES; sem fallback para o estimate (`itens_sp_nulo` no endpoint).
- **Média da jornada = PONDERADA** (Σ horas ÷ Σ pontos dos times da seleção na PI); a média simples dos times vem em campo separado só para comparação.
- **Colunas** = PIs do catálogo até a PI de referência (filtro Versão; padrão = PI corrente), só as com dados. Time sem dados na PI = "—" (não zero).
- **Origem**: PIs fechadas = `produtividade_historico`; PI de referência corrente = AO VIVO na janela de sprints de valor fechadas (`sprint_ref`); com filtro de sprint, a PI de referência é recalculada ao vivo só para essa sprint.
- **Referência (planejamento)** = `times_composicao.media_produtividade_pi` (Velocity); a tela mostra a diferença da PI de referência contra ela.
- **Sugestão para a próxima PI** = produtividade da PI FECHADA mais recente com dados (jornada: ponderada). **Somente leitura**: nada é gravado em `times_composicao` até a carga da PI 1/27.
- Consolidar uma PI fechada: `php bin/consolidar_produtividade.php --pi="PI 3/26" [--dry-run]`.