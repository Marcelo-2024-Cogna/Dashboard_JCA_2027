# Ajustes pós-feedback da v1.6 (sem mudança de versão)

## Itens implementados

1. **Visão Geral**: removido o card "Sustentação - aguardando módulo independente" (tela, legenda `sustentacao_placeholder` e o gadget `sustentacao` do backend). A linha "Incidentes/Solicitações: aguardando o módulo de Sustentação" do bloco da sprint anterior continua, porque não fazia parte do pedido.
2. **Lead Time**: removido o card "Mediana (P50)" (e a legenda `lt_p50`). P50 e máximo continuam no tooltip do gráfico e no bloco recolhível "Detalhes".
3. **Lead Time, "Entregues sem implantação concluída"**: ver (a).
4. **Como ler esta tela**: adicionado em Lead Time e Previsibilidade (fim da tela), texto escrito a partir dos cartões, gráficos e tabelas de cada tela.
5. **Como ler este gráfico**: adicionado em Planejado x Realizado; Produtividade já tinha o bloco e ganhou uma explicação de cada um dos dois gráficos.
   - Padrão de expandir/recolher: `el('details', 'pr-diag')` + `el('summary', null, '...')` (o mesmo dos blocos "Detalhes" e "Diagnósticos dos dados"). Os rodapés "Como ler esta tela" da Visão Geral e da Velocidade eram caixas fixas e agora usam o mesmo padrão.
6. **Cancelados**: removido o card "Cancelado" da Previsibilidade (cartão, coluna da tabela e legenda `prev_cancelado`). Na Visão Geral, o card "Cancelados na PI" abre um modal `App.ListaItens` (botão "Ver itens" ou clique no card).
7. **Tradução**: ver (b) e (c).

## (a) Critério de "Pendente de Produção" (item 3)

**Já estava coberto, nenhum código de cálculo foi alterado.**

- O card conta itens de valor entregues (`entregue = 1`, que na view `vw_item` inclui `Aceito`, `Pendente de Produção` e `Em produção`) menos os que têm `fim_implantacao`.
- A listagem do modal usa o mesmo critério: `is_entrega_valor = 1 AND entregue = 1 AND fim_implantacao IS NULL`.
- Assim, todo item "Pendente de Produção" sem fim de implantação entra no número e na lista. O subtítulo do card já separa "N pendentes de produção" de "M em outros status".
- Conferido no banco local (itens de valor entregues, todas as PIs): Pendente de Produção = 442 sem fim de implantação e 0 com fim; Em produção = 3.209 com fim e 0 sem; Aceito = 3.766 sem fim.
- Observação: itens "Aceito" também aparecem como "em outros status", como já acontecia na v1.6.

## (b) Nomenclatura final (item 7)

| Antes | Depois |
|---|---|
| Committed | **Escopo Inicial** (rótulo completo: "Escopo Inicial (SPE da baseline)"), o mesmo termo da tela Velocidade e de "Escopo Inicial = SPE da baseline dos itens planejados" |
| "Destino do committed" | "Destino do escopo inicial" |
| "sem committed" / "Sem committed na janela" | "sem escopo inicial" / "Sem escopo inicial na janela" |
| Efficiency | **Eficiência** (ex.: "Eficiência: custo real por SP") |
| Debt Load | **Carga de Dívida** ("Debt" = "Dívida", como já aparece em "Dívida (SP)"; "Load" = "Carga", como em "Fator de Carga") |

Identificadores de código, chaves JSON, nomes de colunas e das legendas (`prev_committed`, `efficiency`, `debt_load`, `committed`) não foram alterados.

## (c) Outros termos em inglês corrigidos na varredura

- "Team Velocity" → "Velocidade do time" (Visão Geral, Velocidade, legendas).
- "Velocity" → "Velocidade" (legenda da barra Velocidade do Planejado x Realizado, Produtividade).
- "Story Points Estimate" (série/colunas/legendas da Velocidade por time) → "Story Points estimados"; "Δ entregues − Estimate" → "Δ entregues − estimados".
- "Top Épicos por Story Points" → "Principais épicos por Story Points".
- "tipo de issue" → "tipo de item".
- "tooltip" no texto das legendas → "dica ao passar o mouse"; "badge" no texto → "etiqueta".
- "carry-over" → "transbordo"; "estimate" → "estimativa" (textos de Produtividade).

**Mantidos de propósito** (nomes técnicos ou termos já estabelecidos no dashboard): Jira, Lead Time, Cycle time, Story Point(s) / SPE (nome do campo do Jira), Baseline, Backlog, Burndown, PI Planning, Sprint Planning, Sprint, Done, gadget do Jira, nomes de campos/colunas/views do banco.

## Observações

- A lista de cancelados usa a mesma população do número do card (PI inteira, já filtrada por jornada, time e versão). O filtro de sprint não restringe o card, então a lista traz todos e marca a coluna "No foco" (os itens no foco da sprint vêm primeiro, com a contagem em nota).
- No gráfico "Destino do escopo inicial" da Previsibilidade, o balde "Cancelado" foi mantido: ele é uma das partes cuja soma fecha o Escopo Inicial do time. O "Como ler esta tela" da Previsibilidade explica que o total de cancelados fica na Visão Geral.

## Verificação executada

- `php -l` nos PHP alterados: sem erros.
- `node --check` no script de cada view alterada (Visão Geral, Lead Time, Previsibilidade, Planejado x Realizado, Produtividade, Velocidade): OK. `vw_epicos.php` tem PHP dentro do script e não pôde ser checado isoladamente (mudança é só um texto).
- `php bin/teste_todos.php --rapido`: 5 de 5 testes passaram.
- Chamada real a `/graficos/visao-geral?modo=sprint`: `lista_cancelados` com 95 itens e 256 SP, igual ao card (95 itens, 256 SP); 7 itens no foco, igual a `cancelados_foco`; gadget `sustentacao` ausente.
- Não feita: verificação visual no navegador (cliques e modais exigem confirmação do usuário).
