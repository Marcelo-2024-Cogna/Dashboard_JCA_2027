# Visão Geral — relatório de entrega (FR-9)

Tela **Visão Geral** reescrita para consumir `GET /graficos/visao-geral`, consolidando
os projetos de cada jornada (ADSE e SGSE são o mesmo time). Esta nota registra o que é
exato, o que é aproximado, o que fica vazio e as medições que fecham a verificação.

Referência das medições: jornada **Secretaria e Gestor de Unidades** (keys SGSE, SGGS,
SGGU, SGTB) na **PI 4/26**, com a data real do servidor (**2026-11-04**). Os números
valem enquanto a data real ≤ 2026-11-06 (a SP 26.4.3 fecha em 06/11); depois, conferir
com `hoje=2026-11-04` injetado.

## 1. Gadgets exatos

Calculados sobre `vw_item` (universo valor + sustentação), batendo com as telas próprias:

- **Andamento da sprint/PI** — baldes (backlog, em andamento, bloqueado, aceitos),
  `total_sp`, cancelados na PI, produtividade até agora.
  - Modo sprint (SP 26.4.3): backlog 56, em andamento 89, bloqueado 5, **aceitos 57 SP**
    (valor 11 · sustentação 46), total 207, cancelados na PI 5 SP.
  - Modo PI (SP 26.4.1–26.4.3): backlog 56, em andamento 89, bloqueado 5,
    **aceitos 453 SP** (valor 295 · sustentação 158), **total 603 SP**.
  - `total_sp = backlog + em_andamento + bloqueado + aceitos_sp` (cancelados ficam fora):
    conferido 603 == soma dos 4 baldes == SQL direto (universo valor+sustentação, não
    cancelado, `sprint_ref ∈ sprints_modo_pi`).
- **Aceitos = Entregues do Planejado x Realizado = Concluído da Velocidade** (fonte única):
  - sprint: `aceitos_sp = 57 == serie[SP 26.4.3].concluido` (AC-6);
  - PI: `aceitos_sp = 453 == Σ serie[SP 26.4.1..3].concluido` (AC-6);
  - `reconciliacao.residuo_aceitos = 0` nos dois modos (AC-5).
- **Dados da sprint anterior** (SP 26.4.2): alocados 249, aceitos 230 (valor 164 ·
  sustentação 66), não aceitos sem continuidade 0, transbordo 19 SP / 3 itens → SP 26.4.3,
  produtividade 6,41 h/SP.
- **Cycle time por status** (bloco `janela_padrao` = sprints fechadas = tela Lead Time):
  **77 itens** · dev 3,73 · hml 2,12 · impl 8,12 · **lead time médio 7,64** (AC-7, bate com
  `/graficos/lead-time`). O bloco principal no modo sprint usa só a sprint em foco.
- **Produtividade por sprint** (bloco `pi_ponderada_fechadas` = tela Produtividade):
  **6,33 h/SP** sobre as fechadas (SP 26.4.1 6,21 · SP 26.4.2 6,41); incluindo a sprint
  ativa (`pi_ponderada`): 6,51 h/SP (AC-8).
- **Team Velocity** — reuso direto de `VelocidadeRepository`; velocidade das últimas 2
  fechadas = 198 SP. A view mapeia `status="em_andamento"` → "Ativa".
- **Lista de itens da sprint anterior** — SP 26.4.2, 56 itens / 249 SP.

## 2. Gadgets aproximados (badge "aproximado por datas de fluxo")

Não existe burndown nativo de sprint no Jira extraído; estes gadgets são reconstruídos
pelas datas de fluxo (`inicio_fluxo`/`fim_fluxo` e marcos de homologação/implantação),
com escopo constante. A badge âmbar "aproximado por datas de fluxo" sinaliza isso na tela.

- **Burndown** — linha ideal (queda linear) × real (escopo restante por data de aceite);
  feriados ignorados. Regra: "Escopo constante; conclusão pela data de aceite; feriados
  ignorados." Sustentação fora do escopo de valor: 54 SP / 8 itens (modo sprint).
- **Fluxo acumulado (CFD)** — três séries empilhadas (pendente, em andamento, concluído)
  por data de fluxo, com invariante do último dia.
- **Tempo do item em cada status** — fases em dias úteis entre marcos de fluxo. No modo PI
  a lista mostra só itens em andamento; as médias consideram todos os itens da janela.
- **Chamados** — dias úteis de `inicio_fluxo` a `fim_fluxo`, janela fixa de 30 dias,
  **independe do seletor** (Sprint ativa / PI inteira). Limite de 2 dias úteis.
  - Secretaria: Incidente 261 (média 0,59 d · 95,79% no limite) e Bug 1 (1 d · 100%).
  - Cobertura coerente: 864 resolvidos na PI ≥ 355 com data de fim ≥ 262 na janela
    (41,09% com data).

## 3. Gadgets vazios (mostram o motivo no próprio card)

Estados `vazio` renderizam um `alert-info` dentro do card com o `motivo` em português:

- Sem sprint em foco / anterior fechada: "Nenhuma sprint em foco nesta PI." /
  "Nenhuma sprint anterior fechada nesta PI." (G1, G2, G6).
- Cycle time sem item de valor entregue na janela: "Nenhum item de valor entregue na janela."
- Chamados com `pi_corrente=false`: vazio com motivo explícito (PI encerrada).
- Seleção sem times com extração: todos os gadgets de dados ficam vazios com
  "Nenhum time da seleção tem itens extraídos nesta PI." (Q1/Q2/Q3/porSprint/Velocidade
  não são executadas, sem `IN ()`).

## 4. Decisão de transbordo (D.6)

"Transbordo" = item que passou pela sprint N (token N no campo `sprint`), não cancelado,
e que também aparece na sprint seguinte N+1 (`sprint_maior > N`), isto é, não foi fechado
dentro de N e seguiu adiante. Como no Jira, item **aceito em N+1** também conta como
transbordo. O `sprint_ref` (maior sprint) define a coluna "Sprint (maior)"; a tabela mostra
os tokens completos no `title`. Medido na SP 26.4.2 da Secretaria: 19 SP / 3 itens → SP 26.4.3.

## 5. Nota de design da Sustentação (Parte E)

Os três gadgets de Sustentação ("Visão da Sustentação na Sprint atual", "Tempo gastos em
tarefas de Sustentação" e "Incidentes/Solicitações da sprint anterior") **aguardam o módulo
independente** (regras ainda não definidas). A tela mostra um placeholder tracejado com a
lista dos três.

O **divisor ÷5** das fórmulas do Jira (`pts de Sustentação = horas das subtarefas ÷ 5`) é o
valor da **Produtividade** — horas totais para entregar 1 ponto. No futuro módulo: usar a
Produtividade **ponderada** da jornada (`ProdutividadeRepository → jornada.por_pi[PI]`,
Σh÷Σpts) para a visão de jornada e a Produtividade **do time** para a visão de time; nunca
fixar 5, e mostrar "—" quando não houver produtividade.

## 6. Medições de tempo (AC-17)

Meta: com jornada ≤ 3 s; sem jornada ≤ 8 s. Medido por `curl -w %{time_total}` (PI 4/26),
3 repetições por cenário, na verificação integrada:

| cenário | rep 1 | rep 2 | rep 3 |
|---|---|---|---|
| com jornada (Secretaria) | 0,62 s | 0,49 s | 0,64 s |
| sem jornada (todas) | 3,08 s | 3,93 s | 3,35 s |

Com jornada fica bem abaixo de 3 s; sem jornada (consolidado de todas as jornadas, resposta
de ~197 KB) fica em ~3–4 s, com folga confortável para a meta de 8 s. **Plano B não foi
acionado**: `config/visao_geral.php → produtividade_por_sprint` permanece `'sql'`.

## 7. Contagem de `data_invertida` (base `inicio_fluxo`)

- Chamados com `fim_fluxo` anterior a `inicio_fluxo` (excluídos da média):
  `chamados.diagnosticos.data_invertida = 0` (Secretaria, PI 4/26).
- Itens da base com `inicio_fluxo` e `fim_fluxo` preenchidos e `fim_fluxo < inicio_fluxo`:
  **0** (SQL direto sobre `vw_item`, mesma seleção).

## 8. `resumo_disponivel_pct` por jornada (lista da sprint anterior)

Percentual de itens da sprint anterior com `resumo` (título) disponível na baseline:

| jornada | PI | sprint anterior | resumo_disponivel_pct |
|---|---|---|---|
| Secretaria e Gestor de Unidades | PI 4/26 | SP 26.4.2 | 82,14% |
| Atendimento e Serviços | PI 4/26 | SP 26.4.2 | 97,10% |

A baseline (Q2) é lida em blocos de até 500 chaves; a 1ª ocorrência vence. Itens sem resumo
aparecem com "—" na coluna Resumo (sem assumir texto).

## 9. Verificação integrada (Parte F)

- **Lint**: `php -l` limpo em `public/views/vw_overview.php` e `public/views/layout.php`;
  `node --check` passa no `<script>` extraído de `vw_overview.php` e em `public/assets/js/app.js`.
- **Consistência cruzada (ACs 5–14)**: script temporário confrontou o endpoint com SQL
  direto e com `/graficos/velocidade`, `/graficos/lead-time` e `/graficos/produtividade` —
  todos os comparáveis batem (residuo 0, aceitos 57/453, total 603, cycle 77, ponderada 6,33,
  cobertura de chamados coerente).
- **Correção de seam na verificação (AC-5b)**: a lista de status em `config/visao_geral.php`
  usava formas **sem acento** (`Concluido`, `Revisao de codigo`) e não incluía
  `Em produção`/`Pendente de Produção`, que existem no universo valor+sustentação. Com isso o
  status `Concluído` (acentuado no banco) caía em `status_nao_mapeados` (AC-5b exige vazio na
  PI 4/26). Corrigidos os acentos e acrescentados os dois status de produção à lista
  `em_andamento`. A correção **não altera totais** (status não mapeado já caía em
  `em_andamento` por fallback): só zera o diagnóstico `status_nao_mapeados` e rotula
  corretamente `em_andamento_por_status`. Reverificado: `status_nao_mapeados = []` em Secretaria
  (sprint/pi/sp2), SGGU, Atendimento e PI 3/26.
- **`DiasUteis` (AC-22)**: testes unitários (`espera` em fim de semana, inversão → null) e
  paridade exata com `vw_item.dias_desenvolvimento|homologacao|implantacao` (0 divergências em
  901/898/221 itens). `LeadTimeRepository::linha` == `vw_lead_time_item.lead_time_item`/
  `qtd_etapas` (0 divergências em 902 itens). `VelocidadeRepository` com e sem `$ctx` injetado:
  resposta idêntica (AC-15).
- **Itens fora da janela (AC-20)**: todas as jornadas, modo PI, `sprint_de_outra_pi_itens` =
  **13** (SQL direto confirma 13; o número do desenho — 17 — era de um snapshot anterior). O
  aviso do modo PI cita o número.
- **Regressão (AC-15)**: `/graficos/velocidade`, `/graficos/lead-time`,
  `/graficos/produtividade`, `/graficos/previsibilidade`, `/graficos/plan-real` e `/filtros`
  continuam respondendo 200.
- **HTTP (AC-1/2/18)**: 200 (default, sprint, pi, SGGU, Atendimento, PI 3/26);
  400 (`modo=xyz`, jornada inexistente, `jornada[]=a`, 256 caracteres); `modo=` vazio não é
  400; 405 em POST com `Allow: GET`.
- **Rótulo do select de sprint (AC-3)**: na Visão Geral mostra **"Sprint ativa"**
  (`modo=sprint`/vazio) e **"PI inteira"** (`modo=pi`); Velocidade/Lead Time/Produtividade
  seguem com "Todas as fechadas". Controlado por `window.APP_SECTION` + `rotuloSprintVazio()`.
- **Screenshots headless (AC-16)**: Chrome `--headless=new` renderizou sem erro de console as
  4 URLs — Secretaria (modo sprint e PI), SGGU e Atendimento. Aparecem as badges "aproximado
  por datas de fluxo", o placeholder de Sustentação e a faixa de contexto âmbar (defasagem
  de 6–7 dias na extração atual).

## 10. Nota sobre a defasagem da extração

A última carga do Jira para a Secretaria está com **7 dias** de defasagem (Atendimento, 6
dias). A faixa de contexto fica **âmbar** quando a defasagem passa de 1 dia, com o texto
"atenção: dados podem estar desatualizados". Os números de referência deste relatório
refletem a extração vigente.
