# Pauta de reunião — Decisões para as integrações do Jira (PEP)

Objetivo da reunião: fechar as 7 decisões de **negócio/produto** que destravam o desenho do
ETL do Jira. Cada item traz contexto curto, opções e uma **recomendação** para agilizar. As
questões puramente técnicas estão em `handoff-jira-insumos.md`.

> Como usar: percorrer item a item; marcar a opção escolhida e o responsável por fornecer o
> dado/validação. O que ficar "a confirmar" vira tarefa com dono e prazo.

Participantes sugeridos: Produto/Liderança de Engenharia, um Tech Lead que conheça o fluxo no
Jira, e o dev de backend.

---

## 1. Regra de status → situação do épico (as 6 situações)

**Contexto.** As 6 situações são: Finalizado, Em Dia, Em Risco, Atrasado, Replanejado,
Despriorizado. Hoje há duas regras divergentes no projeto (categoria do status vs. texto do
status) e a carga do PI 4/26 usou a **categoria** do status.

- **Opção A (recomendada):** usar `status.category` do Jira como base
  (Concluídos→Finalizado; Em andamento→Em Dia; Pendentes→Em Risco) e definir sinais
  explícitos para os dois que não têm categoria própria:
  - **Atrasado** = due date vencida (ou status "Bloqueado/Impedido").
  - **Replanejado** = mudança de fix version / flag de replanejamento.
- **Opção B:** mapear **status a status** (lista fechada de status do fluxo → situação).
  Mais preciso, porém depende de a lista de status ser estável entre projetos.

**A decidir:** adotar A ou B; e **como identificar Atrasado e Replanejado** (campo/flag).
**Precisamos do time:** lista de status usados e o que significa "atrasado"/"replanejado" na prática.

Escolha: ____  · Responsável pelo insumo: ____

---

## 2. Régua "Sustentação × Entrega de Valor" (Pontos Trabalhados)

**Contexto.** Os SP concluídos por sprint são separados em Sustentação vs Entrega de Valor.
Hoje a régua é: Sustentação = Incidente + Solicitação de Serviço + Suporte; Entrega de Valor =
os demais (exceto Épico).

- **Opção A (recomendada):** manter a régua atual por **tipo de item**.
- **Opção B:** incluir também `Sustentação` e `Bug` em Sustentação (se o time considerar
  correção como manutenção).

**A decidir:** a lista final de tipos que contam como Sustentação.
**Precisamos do time:** confirmar se Bug/Sustentação entram como manutenção ou valor.

Escolha: ____  · Responsável: ____

---

## 3. Mapa "status → fase" do Cycle Time

**Contexto.** Cycle Time por fase (Ideação, Desenvolvimento, Homologação, Implantação) exige
saber a qual fase cada status pertence, lido do **changelog** do item. Os status variam entre
projetos/jornadas.

- **Opção A (recomendada):** definir um **mapa configurável** status→fase, com um default e
  exceções por projeto/jornada.
- **Opção B:** padronizar os status no Jira (projeto de longo prazo) e usar um único mapa.

**A decidir:** adotar o mapa configurável (A) e **coletar a lista real de status** por fase.
**Precisamos do time:** quais status representam Em Desenvolvimento, Homologação/Teste, Pronto
para Deploy → Em Produção.

Escolha: ____  · Responsável pela lista de status: ____

---

## 4. OKRs via Must Win Battles (MWB) no Jira

**Contexto.** OKRs deixam de ser manuais e passam a ser medidos pelas **MWB cadastradas no
Jira** (categoria Produto do Score). Falta saber como as MWB estão modeladas e como virar um
**% de alinhamento**.

- **Perguntas a responder:**
  - Como a MWB existe no Jira? (tipo de item dedicado? projeto específico? label/épico?)
  - O que define o **% de alinhamento** do OKR? (progresso da MWB? itens vinculados
    concluídos ÷ planejados? campo de progresso?)
  - Granularidade: MWB por jornada, por squad, ou corporativa?
- **Recomendação:** pedir 2–3 exemplos reais de MWB no Jira para modelar a fórmula.

**A decidir:** modelagem da MWB + fórmula do %.
**Precisamos do time:** quem cadastra as MWB mostra exemplos reais.

Definição: ____  · Responsável: ____

---

## 5. Fonte do "Planejado" de Sustentação (capacity) e dos SLAs

**Contexto.** O painel de Sustentação compara Realizado vs **Planejado**. O "Realizado" sai de
itens concluídos, mas o **Planejado (capacity)** não é derivável de itens. Os **SLAs**
(Alta/Média/Baixa) precisam de fonte.

- **Capacity de sustentação:**
  - **Opção A:** entrada manual no fechamento (por enquanto).
  - **Opção B:** derivar de um campo/planejamento no Jira, se existir.
- **SLAs:**
  - **Opção A (recomendada):** Jira Service Management (JSM), se os chamados estão lá.
  - **Opção B:** ServiceNow (M4.6).

**A decidir:** fonte do capacity e **onde vivem os SLAs** (JSM vs ServiceNow).
**Precisamos do time:** confirmar a ferramenta de chamados/SLA.

Escolha capacity: ____  · Escolha SLA: ____  · Responsável: ____

---

## 6. Headcount por squad (para Produtividade)

**Contexto.** Produtividade = pontos por integrante. Depende do **nº de integrantes por
squad**, hoje vindo de planilha de estrutura.

- **Opção A (recomendada):** manter planilha/entrada manual com cadência definida (ex.:
  atualizar a cada início de PI).
- **Opção B:** integrar com a fonte oficial de RH/estrutura (futuro).

**A decidir:** fonte e cadência de atualização do headcount.
**Precisamos do time:** quem mantém e com que frequência o headcount muda.

Escolha: ____  · Responsável: ____

---

## 7. Granularidade de Pontos e Produtividade: por jornada ou por squad?

**Contexto.** Hoje a Velocity e a Produtividade são medidas **por jornada** (não há quebra por
squad na fonte). Por isso, ao filtrar por squad, esses painéis mostram a jornada do squad
("Squad · Jornada"). Só a Situação dos Épicos tem dado por squad.

- **Opção A (recomendada p/ agora):** manter **por jornada** e deixar claro no front. Simples e
  fiel à fonte.
- **Opção B:** passar a coletar SP concluídos e headcount **por squad** — exige que a fonte
  (Velocity) reporte por squad e headcount por squad (ver item 6). Mais trabalho, mais granular.

**A decidir:** manter por jornada (A) ou investir para ter por squad (B).
**Impacto:** B altera o modelo (`squad_id` obrigatório) e o ETL; só vale se o negócio usa o
número por squad nessas duas métricas.

Escolha: ____  · Responsável: ____

---

## Quadro-resumo de decisões

| # | Tema | Opção escolhida | Responsável pelo insumo | Prazo |
|---|------|-----------------|--------------------------|-------|
| 1 | Status → situação do épico | | | |
| 2 | Sustentação × Valor | | | |
| 3 | Status → fase (Cycle Time) | | | |
| 4 | OKRs via MWB | | | |
| 5 | Capacity sustentação + SLAs | | | |
| 6 | Headcount por squad | | | |
| 7 | Granularidade jornada × squad | | | |

## Depois da reunião
- As decisões viram parâmetros/tarefas no `handoff-backend-integracoes.md` (M4) e ajustam o
  schema (`handoff-backend-integracoes.md` seção 5).
- O que não for decidido fica como **"manual por enquanto"** (não bloqueia o ETL dos itens que
  já têm fonte: épicos, débito, carga, baseline).
