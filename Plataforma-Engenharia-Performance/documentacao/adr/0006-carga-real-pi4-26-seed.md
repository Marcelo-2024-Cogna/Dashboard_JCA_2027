# ADR 0006 — Carga real do PI 4/26 no seed (Jira + baseline), sem fabricar dados

- Status: Aceito
- Data: 2026
- Relacionado: ADR 0001, ADR 0003

## Contexto

Os painéis da Retrospectiva de PI precisavam refletir a situação **real e atual** do PI
4/26, mas a coleta automatizada do Jira (tempo real + fotos em banco) depende de backend,
ainda não existente. Era preciso uma forma intermediária de ter números reais no app
estático, sem inventar valores onde não há fonte.

## Decisão

Carregar o PI 4/26 **manualmente** no seed (`retro-data.js`) a partir de fontes reais,
mantendo os PIs anteriores apenas onde já havia histórico:

- **Situação dos Épicos** — 235 épicos do Jira (10 jornadas), status atual mapeado para as
  6 situações via `status.category`. Tem `squad` real.
- **Pontos Trabalhados** — `real`/`velocidade` da planilha Velocity; **Planejado** e **PI
  Planning** do baseline (`Baseline_Jornadas_26.4.xlsx`, aba "Baseline itens": soma de Story
  Points dos itens com o PI nas *Versões corrigidas*, por jornada).
- **Débito Técnico** e **Carga de Trabalho** — contagens reais do Jira (`created >= 2026-07-01`),
  por jornada/tipo. Sem Story Points por item (coleta não lê item a item).

Onde não há fonte extraível (Cycle Time, Lead Time, Sustentação, Alterações no Plano,
Impedimentos), as coleções ficam vazias e os painéis exibem **"Sem dados"** — nunca valores
fabricados.

## Justificativa

- Dá números reais e auditáveis já no app estático (mantém ADR 0001/0003).
- **PI Planning = Planejado** porque o baseline é a foto única do plano do PI; não há no
  baseline uma coluna que separe os dois. Atribuir um segundo número seria invenção.
- "Sem dados" honesto preserva a distinção que o motor já faz entre ausência e zero.

## Consequências

- Positivas: painéis de Épicos, Pontos, Débito e Carga mostram a realidade do PI 4/26;
  filtros de jornada/squad passam a fazer sentido.
- Negativas: é uma foto estática no código — não se atualiza sozinha; precisa ser refeita a
  cada novo fechamento até existir a coleta automática.
- Granularidade: Pontos e Produtividade são por **jornada** (a fonte não quebra por squad);
  ao filtrar por squad, o painel mostra a jornada do squad com rótulo "Squad · Jornada".
- Reconciliação: a consulta compara nomes de squad de forma tolerante (acento/espaço/caixa
  + aliases), já que os rótulos do cadastro e das extrações divergem levemente.
- Caminho de evolução: substituir esta carga manual pela camada de coleta do Jira + fotos
  em banco (ver `parametrizacao-metricas-jira.md`, seções 4 e 5, e `roadmap-producao.md`).
