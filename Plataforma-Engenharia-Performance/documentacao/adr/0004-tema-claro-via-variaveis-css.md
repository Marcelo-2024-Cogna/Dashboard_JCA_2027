# ADR 0004 — Tema claro via variáveis CSS

- Status: Aceito
- Data: 2026
- Contexto da decisão: evolução de UI da PEP

## Contexto

A plataforma nasceu com tema escuro. O usuário pediu **fundo claro em toda a plataforma**.
Havia cores definidas em variáveis CSS (`:root`) e algumas cores escuras/brancas
hardcoded em CSS e no JS dos gráficos (Chart.js), que não acompanhavam variáveis.

## Decisão

Adotar o **tema claro como padrão**, implementado por **variáveis CSS** no `:root` de
`css/styles.css`. Eliminar cores hardcoded em favor das variáveis, incluindo:

- uma variável `--track-bg` para "trilhos" (barras de progresso e grades de gráficos), que no
  tema claro usa preto translúcido em vez de branco translúcido;
- leitura dinâmica das variáveis de tema no JS dos gráficos (via `getComputedStyle`) para os
  textos dos gauges e cores de eixo/grade.

## Justificativa

- Centraliza a paleta em um único lugar (troca de tema de baixo custo).
- Mantém o código **preparado para alternância de tema** (claro/escuro) no futuro, sem
  reescrever componentes.
- Corrige elementos que "sumiam" no fundo claro (textos brancos, grades brancas).

## Consequências

- Positivas: consistência visual, manutenção simples, caminho aberto para dark mode opcional.
- Negativas: exige disciplina para **nunca** reintroduzir cores hardcoded nos componentes.
- Procedimento de dark mode futuro documentado em `guia-estilo-ui.md`.

## Notas

- Regressão evitada: os testes de domínio não dependem de cor; mudanças de tema foram
  validadas visualmente no navegador e com a suíte (sem regressão).
