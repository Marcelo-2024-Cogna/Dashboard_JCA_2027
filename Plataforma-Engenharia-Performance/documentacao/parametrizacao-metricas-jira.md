# Parametrização de Métricas — Coleta em Tempo Real (Jira) + Fotos Periódicas

> Documento para **validação**. Objetivo: alinhar, antes de implementar, (1) quais
> métricas serão coletadas do Jira em tempo real, (2) quando cada métrica é
> "congelada" em uma foto no banco e (3) qual a granularidade de cada uma.
>
> Status: **decisões de negócio validadas (ver seção 0)**. Coleta em tempo real + fotos em
> banco ainda dependem de backend (não iniciadas). Já houve, porém, uma **carga manual
> real do PI 4/26** no seed a partir do Jira e do baseline — ver seção 5 (Estado atual da
> carga do PI 4/26).

---

## 0. Decisões validadas pelo negócio

Registradas a partir da validação das listas:

1. **Métricas fora do Jira (OKRs, Disponibilidade, Vulnerabilidade, THC):** entram por
   **entrada manual** no fechamento, por enquanto. Há **integração futura** prevista com
   outras aplicações.
   - **Exceção — OKRs:** agora é apurado pelas **Must Win Battles (MWB) cadastradas no
     Jira**. Ou seja, OKRs **passa a ter fonte Jira** (não é mais manual): o alinhamento
     é medido pelas MWB registradas no Jira.
2. **Frequência de Deploy:** **entrada manual** por enquanto; **integração futura com
   Git** (contagem de releases/deploys a partir do repositório).
3. **Ritmo das fotos:**
   - **Mensal:** último dia útil do mês. **OK (fixo).**
   - **Por PI:** congela na **Sprint 5 ou 6**, dependendo da configuração do PI. A sprint
     de fechamento é **parametrizável no plano do PI** (configurada quando o PI é
     planejado), não fixa.

> Enquanto a integração não existe, os valores manuais ficam editáveis até o momento do
> fechamento; no fechamento, o valor vigente é congelado na foto.

---

## 1. Modelo de fotos (snapshots) proposto

A plataforma hoje é um app estático com os dados em seed (memória). A parametrização
pedida introduz duas coisas:

1. **Visão ao vivo (tempo real):** ao abrir uma métrica, consultar o Jira e exibir o
   valor atualizado "conforme hoje" (sem gravar).
2. **Fotos congeladas (snapshots em banco):** gravar o valor fechado em dois ritmos
   distintos, para permitir o comparativo histórico:

| Foto | Quando congela | Para quê serve | Cobre quais métricas |
|------|----------------|----------------|----------------------|
| **Foto Mensal** | Último dia útil de cada mês | Compor o **Score do mês** e o comparativo mês a mês | As **12 métricas do Score** (lista 1) |
| **Foto de PI** | No fechamento da **Sprint 5 ou 6** (parametrizável por PI) | Comparativo **PI a PI** | As **métricas de Retrospectiva** (lista 2) |

Regras (pós-validação):

- A **Foto Mensal** sempre usa a visão ao vivo do Jira no último dia útil às 23:59 e
  grava um registro imutável por (mês, jornada, squad, métrica).
- A **Foto de PI** congela no evento de fechamento da **sprint de encerramento definida
  no plano do PI** (Sprint 5 ou 6 — parametrizável); grava um registro por
  (PI, jornada, squad, métrica/painel). A sprint de fechamento é um atributo do PI,
  configurado no planejamento.
- Entre fechamentos, a tela mostra **ao vivo**; o histórico (meses/PIs anteriores)
  mostra sempre a **foto**, nunca recalcula.
- Métrica sem dado no Jira no momento do fechamento → grava `sem-dados` (não zero),
  preservando a distinção que o motor de score já faz.

---

## 2. LISTA 1 — Métricas MENSAIS (compõem o Score de Performance)

Fonte: `scoring-engine.js` (`METRIC_RULES` / `METRICS`). São **12 métricas**, em 4
categorias, somando a base de **1000 pts** (teto com bônus: 1089). Foto no **último
dia útil do mês**.

| # | Métrica | Categoria | Peso (máx) | Unidade/escala | Direção | Fonte Jira proposta (a validar) |
|---|---------|-----------|-----------:|----------------|---------|----------------------------------|
| 1 | Planejado vs Realizado | Processos | 150 | % (0–1, 1=100%) | maior melhor | Pontos concluídos ÷ pontos planejados na sprint/mês |
| 2 | Cycle Time Desenvolvimento | Processos | 125 | dias | menor melhor | Tempo médio em status "Em Desenvolvimento" |
| 3 | Cycle Time Homologação | Processos | 50 | dias | menor melhor | Tempo médio em status "Homologação/Teste" |
| 4 | Cycle Time Implantação | Processos | 50 | dias | menor melhor | Tempo médio de "Pronto p/ Deploy" → "Em Produção" |
| 5 | Frequência de Deploy | Processos | 75 | categoria (0–4) | categórica | **Manual** agora → **integração futura com Git** (releases/deploys) |
| 6 | Alinhamento com OKRs | Produto | 50 | % (0–1) | maior melhor | **Jira** — apurado pelas **Must Win Battles (MWB)** cadastradas no Jira |
| 7 | Disponibilidade | Práticas | 175 | % (0–1) | maior melhor | **Manual** agora → integração futura (monitoração) |
| 8 | Índice de Vulnerabilidade | Práticas | 175 | % (0–1) | maior melhor | **Manual** agora → integração futura (security scan) |
| 9 | SLA Alta Criticidade | Práticas | 50 | % (0–1) | maior melhor | Jira (JSM) — % de chamados de alta criticidade dentro do SLA |
| 10 | SLA Média Criticidade | Práticas | 30 | % (0–1) | maior melhor | Jira (JSM) — % de chamados de média criticidade dentro do SLA |
| 11 | SLA Baixa Criticidade | Práticas | 20 | % (0–1) | maior melhor | Jira (JSM) — % de chamados de baixa criticidade dentro do SLA |
| 12 | Team Health Check | Pessoas | 50 | 0–10 | maior melhor | **Manual** agora → integração futura (pesquisa THC) |

**Totais por categoria:** Processos 450 · Práticas 450 · Produto 50 · Pessoas 50 = **1000**.

**Origem de cada métrica mensal (consolidado pós-validação):**
- **Jira (automatizável):** Planejado vs Realizado, Cycle Time Dev/Homolog/Impl, SLA
  Alta/Média/Baixa e **OKRs (via Must Win Battles)**.
- **Manual agora, integração futura:** Frequência de Deploy (→ Git), Disponibilidade,
  Vulnerabilidade, Team Health Check.
- Pontos a confirmar na implementação: estrutura das MWB no Jira (como medir o % de
  alinhamento do OKR a partir delas) e se os chamados de SLA estão no Jira Service
  Management.

---

## 3. LISTA 2 — Métricas por PI (Retrospectiva de PI)

Fonte: `retro-data.js` (coleções do `RetroDataset`). Foto no **fechamento da Sprint 6**
de cada PI. Granularidade varia por painel (por sprint, por squad, por tipo de item).

| # | Métrica / Painel | Granularidade | Campos principais | Fonte Jira proposta (a validar) |
|---|------------------|---------------|-------------------|----------------------------------|
| 1 | Pontos Trabalhados (Velocity) | por PI × sprint × squad | sustentação, entrega de valor, total, velocidade | Soma de story points concluídos por sprint, separados por tipo (sustentação × valor) |
| 2 | Produtividade | por PI × squad | pontos por integrante (porPI) | Pontos concluídos ÷ nº de integrantes do squad |
| 3 | Situação dos Épicos | por PI × jornada × squad × épico | chave, situação (6 estados), origem | Status do épico no Jira → mapa de 6 situações |
| 4 | Cycle Time (fases) | por PI × squad | ideação, desenvolvimento, homologação, implantação | Tempo médio por fase/status do fluxo |
| 5 | Lead Time por Tipo de Item | por PI × squad × tipo | dias | Criação → conclusão por tipo (História/Enabler/Débito/Spike/Teste) |
| 6 | Débito Técnico | por PI (Jornada) + backlog por squad | planejadoSP, realSP, backlogQtde | Itens do tipo "Débito Técnico": planejado, realizado, backlog |
| 7 | Sustentação | por PI × squad + histórico de incidentes | realizado, planejado, incidentes | Pontos/itens de sustentação e incidentes por PI |
| 8 | Carga de Trabalho | por PI × tipo de item | pontos, % pontos, qtde itens, % itens, pontuação média | Distribuição de itens concluídos por tipo |
| 9 | Alterações no Plano | por PI × dimensão | baseline, PI planning, mudança escopo, cancelado, transbordo (aceito/pendente) | Comparação baseline × replanejamento × transbordo de épicos/histórias/pontos |
| 10 | Impedimentos | por PI (e por sprint) | quantidade, localização, tempo de remoção | Itens/flags de impedimento: contagem e tempo até remoção |

**Observações importantes para validar:**
- As situações dos épicos já têm mapeamento definido (Status Jira → 6 situações:
  finalizado / em-dia / em-risco / atrasado / despriorizado / replanejado). Confirmar
  se o mapa de status atual está correto.
- Produtividade depende do **nº de integrantes por squad** (hoje vem de planilha de
  estrutura). Definir se esse headcount é mantido manual ou integrado.
- Débito, Sustentação e Lead Time dependem de **tipo de item** e **status** padronizados
  no Jira. Confirmar os nomes de tipos e status por jornada.

---

## 4. Próximos passos de implementação

Com as decisões da seção 0 validadas, a parametrização será feita nesta ordem:

1. **Configuração do PI:** adicionar ao plano do PI o atributo `sprintFechamento`
   (valor 5 ou 6) que define quando a Foto de PI congela.
2. **Modelo de origem por métrica:** marcar cada métrica como `jira` (automatizável),
   `manual` (entrada no fechamento) ou `integracao-futura` (Git, monitoração, THC),
   conforme a tabela da seção 2.
3. **OKRs via Must Win Battles:** definir como ler as MWB do Jira e derivar o % de
   alinhamento do OKR.
4. **Camada de coleta Jira (tempo real):** consulta ao vivo das métricas automatizáveis
   para o mês/PI corrente.
5. **Jobs de fechamento + persistência das fotos:** Foto Mensal (último dia útil) e Foto
   de PI (sprint de fechamento), gravando snapshots imutáveis para o comparativo.

> **Decisão de arquitetura pendente:** a plataforma hoje é 100% estática (sem backend).
> Coleta em tempo real do Jira + fotos em banco exigem um backend (ou função serverless
> + banco). Antes de codar os passos 4 e 5, precisamos escolher esse caminho.

---

## 5. Estado atual da carga do PI 4/26 (seed real, sem backend)

Enquanto a coleta automatizada (seções 4.4 e 4.5) não existe, o PI 4/26 foi carregado
**manualmente** no seed (`retro-data.js`) com dados **reais** extraídos na "visão de hoje".
Esta é uma foto estática mantida no código; os PIs anteriores (1/26, 2/26, 3/26) foram
mantidos apenas onde já havia histórico e **não** foram recarregados.

### 5.1 O que foi carregado e de qual fonte

| Painel / coleção | Fonte real usada | Observações |
|------------------|------------------|-------------|
| **Situação dos Épicos** (`epicos`) | Jira (235 épicos, 10 jornadas), status atual mapeado para as 6 situações via `status.category` | Tem `squad` real por épico. |
| **Pontos Trabalhados** (`pontosTrabalhados`) | Planilha **Velocity_2026** | `real` e `velocidade` por jornada/sprint. |
| **Planejado / PI Planning** (em `pontosTrabalhados`) | **Baseline_Jornadas_26.4.xlsx** (aba "Baseline itens") | Soma de Story Points dos itens com "PI 4/26" nas *Versões corrigidas*, por jornada. **PI Planning = Planejado** (o baseline é a foto única do plano do PI; não há coluna que os separe). |
| **Débito Técnico** (`debito`, `debitoBacklogPorTime`) | Jira (tipo "Débito Técnico", `created >= 2026-07-01`) | Quantidade por jornada (A1). Sem Story Points por item. |
| **Carga de Trabalho** (`cargaTrabalho`) | Jira (contagem por tipo, paginada) | `qtdeItens` reais; **sem** Story Points (coleta A1 não lê item a item) → `pontos/pontuacaoMedia` nulos, exibidos como "—". |

### 5.2 O que ficou "Sem dados" (honesto, não fabricado)

Cycle Time, Lead Time, Sustentação, Alterações no Plano e Impedimentos **não** têm fonte
extraível por esta interface (exigem changelog item a item ou campos/convenções
inexistentes). As coleções ficam vazias e os painéis exibem "Sem dados" — nunca valores
inventados. Serão preenchidos quando houver a camada de coleta (seções 4.4/4.5) ou carga
manual de fonte confiável.

### 5.3 Granularidade de squad por painel (importante)

- **Situação dos Épicos** tem granularidade **por squad** (campo `squad` real) → o filtro
  de Squad traz os números do squad.
- **Pontos Trabalhados** e **Produtividade** são medidos **por jornada** na fonte (Velocity
  e Produtividade não têm quebra por squad). Ao filtrar por um Squad, o painel resolve a
  **jornada** daquele squad e mostra os valores da jornada, com o rótulo deixando claro o
  escopo (ex.: "Athenas - Atendimento · Atendimento e Serviços"). Não há fabricação de
  número por squad. Para ter Pontos/Produtividade por squad, a fonte precisa reportar
  nessa granularidade.

### 5.4 Reconciliação de nomes de squad (filtro × dados)

Os rótulos de squad do filtro (vindos de `data.js`/Tribos-Squads, ex.: "Athenas - Atendimento",
"Route66") divergem levemente dos valores gravados nos épicos (ex.: "Atendimento", "Route 66").
A consulta interna (`PEPRetroData.query`) compara squad de forma **tolerante** — normaliza
acento/espaço/caixa e aplica o alias "Athenas - Atendimento" ↔ "Atendimento" — para o filtro
funcionar sem exigir grafia idêntica. Mesma lógica de identidade do `identity.js`.
