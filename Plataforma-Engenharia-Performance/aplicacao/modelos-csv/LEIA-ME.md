# Modelos de CSV para carga de dados — PEP

Modelos prontos para você preencher e importar na plataforma (estado atual, antes da integração automática).

## Regras gerais (válidas para todos os arquivos)

- **Separador:** ponto-e-vírgula (`;`).
- **Codificação:** UTF-8 (os arquivos já vêm com BOM, para abrir certo no Excel pt-BR).
- **Decimais:** aceitam vírgula (`0,97`) ou ponto (`0.97`).
- **Coluna `id`:** deixe **vazia** ao criar registros novos — a plataforma gera o id. Preencha só ao reimportar algo exportado.
- **Nomes de jornada/squad:** mantenha **idênticos** entre todos os arquivos (Score, Jira, Baseline, Gestão). Divergência de nome gera inconsistência entre telas.
- Linhas inválidas são **rejeitadas com mensagem**; as válidas são importadas.

## Arquivos

| Arquivo | Onde importar | O que carrega |
|---|---|---|
| `01-score-mensal.csv` | Aba Operacional/Times (importação de Score) | 12 métricas por time/mês |
| `02-extracao-jira.csv` | Retrospectiva de PI → "Extração do Jira" | Itens do Jira (gera indicadores por Sprint) |
| `03-baseline-pi.csv` | Retrospectiva de PI → "Baseline do PI" | Baseline de épicos do PI |
| `05-gestao-tribos-squads.csv` | Gestão → Tribos e Squads | Estrutura organizacional |
| `08-gestao-perfis.csv` | Gestão → Perfis | Permissões por perfil (telas) |
| `10-config-integracao.csv` | Gestão → Integração | Mapeamento projeto Jira → jornada/squad |

> Observação: os modelos de Gestão de Usuários, Gestão de KPIs, Gestão de OKRs e
> Configuração de Comunicação foram descontinuados — essas telas foram removidas
> do produto. A numeração dos demais arquivos foi preservada para não quebrar
> referências.

## Observações por arquivo

### 01 — Score mensal
- `mes`: 1–12 ou rótulo (Jan…Dez).
- Percentuais (planejadoVsRealizado, disponibilidade, vulnerabilidade, slaAlta/Media/Baixa, okrs): escala **0 a 1** (ex.: 0,97 = 97%).
- Cycle Times (dev/homolog/impl): em **dias**.
- `freqDeploy`: categoria inteira (0/1 = menos de 1 por mês · 2 = entre mensal e semanal · 3 = semanal · 4+ = mais de 1 por semana).
- `teamHealth`: escala **0 a 10**.
- Campo vazio = "Sem Dados" (não é "Não atendeu"). Uma linha por time × mês.

> Nota: `okrs` continua sendo uma **métrica do Score** (categoria Produto). Apenas a
> *tela* de Gestão de OKRs foi removida — a métrica permanece no cálculo.

### 02 — Extração do Jira
- Obrigatórios: `chave`, `tipoItem`, `sprint`, `pi`. Datas em `aaaa-mm-dd` (ou dd/mm/aaaa). Campos de data podem ficar vazios se não houver.
- Tipos de item comuns: História, Enabler, Spike, Teste, Débito Técnico, Incidente, Solicitação de Serviço, Suporte, Segurança.

### 03 — Baseline do PI
- `situacao`: um de `atrasado`, `replanejado`, `em-risco`, `em-dia`, `finalizado`, `despriorizado`.
- Também aceita `.xlsx` com as mesmas colunas. É associado ao PI selecionado na tela.

### 08 — Perfis
- `permissoes`: lista separada por `|` com os ids de tela permitidos. Ids válidos: `execGeral`, `execSquad`, `detJornada`, `detSquad`, `apresentacao`, `produtividade`, `excecao`, `cfgIntegracao`, `gestPerfis`, `gestTribosSquads`.

### 10 — Integração
- **Nunca** inclua tokens, senhas ou chaves de API — campos sensíveis são descartados por segurança.

## Ordem recomendada de carga

1. `05-gestao-tribos-squads.csv` (base de identidade)
2. `01-score-mensal.csv`
3. `02-extracao-jira.csv`
4. `03-baseline-pi.csv`

Os dados de exemplo nos modelos usam a jornada "Atendimento e Serviços". Substitua pelos seus dados reais mantendo o cabeçalho (primeira linha) intacto.
