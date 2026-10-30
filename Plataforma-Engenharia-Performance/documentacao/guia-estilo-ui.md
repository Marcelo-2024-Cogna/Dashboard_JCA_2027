# Guia de Estilo e Tematização de UI — PEP

Como a interface é estilizada e como alterar o tema de cores da plataforma. Público-alvo:
quem mantém o CSS/JS do front-end.

## Princípios

- **Tema claro é o padrão.** A paleta é definida por **variáveis CSS** no seletor `:root`
  de `aplicacao/css/styles.css`. Quase todas as cores da interface derivam dessas variáveis.
- **Fonte única de cor.** Componentes referenciam `var(--nome)` em vez de cores literais.
  Isso permite trocar o tema inteiro em um único lugar.
- **Gráficos seguem o tema.** Os textos dos gráficos (Chart.js) leem as variáveis CSS em
  tempo de execução (via `getComputedStyle`), então acompanham o tema sem edição manual.

## Variáveis de tema (`:root`)

| Variável | Papel | Valor (tema claro) |
|----------|-------|--------------------|
| `--bg-primary` | Fundo geral da página | `#f1f5f9` |
| `--bg-secondary` | Header, sidebar, barras | `#ffffff` |
| `--bg-card` | Fundo dos cards | `#ffffff` |
| `--bg-hover` | Realce de hover | `#e2e8f0` |
| `--text-primary` | Texto principal | `#0f172a` |
| `--text-secondary` | Texto secundário | `#475569` |
| `--text-muted` | Texto suave/legendas | `#64748b` |
| `--border-color` | Bordas | `#e2e8f0` |
| `--accent-blue` | Destaque/ação primária | `#2563eb` |
| `--accent-green` / `--accent-orange` / `--accent-red` / `--accent-purple` | Acentos | — |
| `--track-bg` | Fundo de "trilhos" (barras de progresso, grades) | `rgba(15,23,42,0.06)` |
| `--track-bg-strong` | Trilho/realce mais forte | `rgba(15,23,42,0.08)` |
| `--shadow` / `--shadow-lg` | Sombras dos cards | tons suaves |
| `--radius` / `--radius-sm` | Raio de borda | `12px` / `8px` |

As faixas de performance (`--band-*`) e os acentos são semânticos e, em geral, valem para
qualquer tema.

## Cores nos gráficos (Chart.js)

Os gauges e gráficos não podem usar `var(--...)` diretamente no canvas. O padrão adotado é
ler as variáveis do tema em tempo de desenho:

```js
const css = getComputedStyle(document.documentElement);
const colorPrimary = css.getPropertyValue('--text-primary').trim() || '#0f172a';
const colorMuted   = css.getPropertyValue('--text-muted').trim()   || '#64748b';
// ...usar colorPrimary / colorMuted em ctx.fillStyle, ticks.color, etc.
```

Esse padrão está nos textos centrais dos gauges (`view-geral`, `view-jornadas`,
`view-metricas`, `view-exec-squad`, `view-apresentacao`, `dashboard.js`). As **grades** e
**trilhos** dos gráficos usam preto translúcido (`rgba(15,23,42,0.08)`) para aparecerem sobre
fundo claro.

## Como criar um tema escuro (ou alternância claro/escuro)

A estrutura já está preparada. Para adicionar um tema escuro:

1. Defina as mesmas variáveis sob um seletor alternativo, por exemplo:
   ```css
   :root[data-theme="dark"] {
       --bg-primary: #0f172a;
       --bg-secondary: #1e293b;
       --bg-card: #1e293b;
       --bg-hover: #334155;
       --text-primary: #f1f5f9;
       --text-secondary: #94a3b8;
       --text-muted: #64748b;
       --border-color: #334155;
       --track-bg: rgba(255,255,255,0.06);
       --track-bg-strong: rgba(255,255,255,0.08);
       /* acentos podem permanecer */
   }
   ```
2. Alterne com JS definindo `document.documentElement.dataset.theme = 'dark' | ''` (persista
   a escolha em `localStorage`, à semelhança de `pep.profile`).
3. Os gráficos acompanham automaticamente, pois leem as variáveis em cada render. Se o tema
   mudar com gráficos já na tela, chame o `render` da view ativa para redesenhar.

Nenhuma cor hardcoded de fundo/texto deve ser reintroduzida nos componentes — sempre use as
variáveis para manter a tematização funcionando.

## Layout e navegação

- **Menu lateral vertical**: a navegação (`.nav-toolbar`) é uma sidebar fixa à esquerda
  (`--sidebar-width: 260px`); header e conteúdo são deslocados para a direita. Em telas
  `< 900px` a sidebar vira faixa superior e o conteúdo ocupa a largura inteira.
- **Sub-abas da Retrospectiva** (`.retro-subtabs` / `.retro-subtab`): compactas e em **uma
  única linha**, com rolagem horizontal quando faltar largura (`flex-wrap: nowrap` +
  `overflow-x: auto`).

## Convenções

- Tipografia: fonte **Inter** (Google Fonts via CDN).
- Prefixos de classe por módulo: `vg-` (Visão Geral), `vj-` (Jornadas), `vm-` (Métricas),
  `ve-` (Evolução), `mt-` (Metas), `es-` (Exec. Squad), `ap-` (Apresentação), `rpt-`/`rpd-`
  etc. (Retro), `adm-` (gestão), `exc-` (exceções). Mantenha o prefixo ao criar estilos novos.
- CSV/export: separador `;` e UTF-8 com BOM (compatível com Excel BR) — não é estilo, mas é
  convenção transversal da plataforma.
