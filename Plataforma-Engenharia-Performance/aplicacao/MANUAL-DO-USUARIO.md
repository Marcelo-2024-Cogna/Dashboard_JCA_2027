# Manual do Usuário — Plataforma de Engenharia de Performance (PEP)

> Versão do manual: estado atual da plataforma (MVP, **antes da integração automática com o Jira/ServiceNow**).
> A plataforma é uma **aplicação estática** (abre no navegador, sem servidor). Todos os dados que você carrega ficam **no seu navegador** (localStorage) — não são compartilhados entre pessoas nem enviados a servidores.

---

## 1. Como abrir a plataforma

1. Abra o arquivo `index.html` (pasta `aplicacao`) no navegador, **ou** sirva a pasta por um servidor estático simples.
2. Não há login. O acesso é controlado por um **Seletor de Perfil** (simulação de visibilidade — ver seção 3).
3. A navegação fica no **menu lateral esquerdo** (vertical): seletores no topo, abas de visões e o menu por seções.

> Importante: o controle por Perfil ajusta apenas o que aparece no menu/telas. **Não é segurança real** (qualquer pessoa pode trocar de perfil). Isso está sinalizado no banner no topo da tela.

---

## 2. Visão geral da navegação (menu lateral)

O menu lateral tem, de cima para baixo:

- **Seletor de Perfil** — define quais telas você enxerga (Admin, DM, TM, GPM, TL).
- **Seletor de Mês** — mês de referência das visões de Score (Jan a Dez).
- **Abas de visões do Score** — Visão Geral, Jornadas, Métricas, Evolução mensal, Metas, Operacional/Times.
- **Retrospectiva de PI** — aba que abre os 8 painéis de PI (com seu próprio Seletor de PI e filtros).
- **Menu por seções** — "Dashboards e Configurações" e "Gestão e Configuração" (as entradas visíveis dependem do seu perfil).

O **Seletor de Mês** (Score) e o **Seletor de PI** (Retrospectiva) são independentes: trocar um não afeta o outro.

---

## 3. Perfis de acesso

| Perfil | O que enxerga (resumo) |
|---|---|
| **Administrador** | Tudo: todos os dashboards, gestão e configurações. |
| **Delivery Manager (DM)** | Igual ao Administrador (acesso total). |
| **Tech Manager (TM)** | Dashboards (jornada, squad, apresentação, produtividade) e **solicitar** exceção. |
| **Group Product Manager (GPM)** | Dashboards de jornada, squad e apresentação (sem produtividade, sem gestão). |
| **Tech Lead (TL)** | Executivo por squad (tela inicial), detalhado por jornada e squad e produtividade. |

Para trocar de perfil: use o **Seletor de Perfil** no topo do menu lateral. A tela inicial e o menu se ajustam na hora. Se você tentar abrir uma tela não permitida ao perfil, a plataforma redireciona para a tela inicial do perfil e avisa.

---

## 4. As páginas (o que cada uma mostra)

### 4.1 Visão Geral (Score)
Panorama executivo de Engenharia no mês selecionado: medidor (gauge) do score total, faixa de performance, % de atingimento da meta do mês, contagem de jornadas por Farol (Na meta / Atenção / Crítico), destaques de maior alta e maior queda, e a distribuição de pontos entre as 4 categorias (Processos, Práticas, Produto, Pessoas).

### 4.2 Jornadas (Score)
Um cartão por jornada com: gauge de score, faixa de performance, % meta vs realizado, Farol, variação vs mês anterior e o detalhamento "X de Y pontos" por categoria.

### 4.3 Métricas por Jornada (Score)
Seleciona uma jornada e mostra as **12 métricas** agrupadas pelas 4 categorias, cada uma com gauge, pontuação, faixa de atendimento, média dos times e distribuição dos times por faixa.

### 4.4 Evolução Mensal (Score)
Tabela Jan–Dez × (jornadas + Engenharia), com cada célula colorida pelo Farol (realizado ÷ meta do mês). Alterna entre "Score realizado" e "% da meta".

### 4.5 Metas (Score)
Tabela por jornada com Partida (dez/25), Meta %, Meta 2026, acréscimo por mês e as metas de cada mês. Mostra o Farol da jornada no mês selecionado.

### 4.6 Operacional/Times (dashboard detalhado)
Ranking de times por score, filtros por jornada/time, detalhamento por métrica (modal com as 12 métricas), **entrada manual de métricas** e exportação CSV.

### 4.7 Retrospectiva de PI (8 painéis)
Abre com Seletor de PI e filtros de **Jornada** e **Squad** (cada painel usa o período certo —
mês ou PI). Painéis:
- **Pontos Trabalhados** — Entrega de Valor vs Sustentação por Sprint, com os indicadores
  Planejado / Real / PI Planning / Velocidade. **Planejado** e **PI Planning** vêm do baseline
  do PI; **Real** e **Velocidade**, da Velocity. Valores sem fonte aparecem como "—".
- **Produtividade** — pontos por integrante, por PI, com média da jornada.
- **Situação dos Épicos** — contagem nas 6 situações (Baseline vs Mudança de Escopo).

**Como os filtros se comportam:** ao escolher uma **Jornada**, todos os painéis passam a
mostrar só aquela jornada. Ao escolher um **Squad**:
- **Situação dos Épicos** mostra os números daquele squad (tem dado por squad).
- **Pontos Trabalhados** e **Produtividade** mostram os valores da **jornada do squad** —
  essas métricas são medidas por jornada, então o painel indica o escopo como
  "Squad · Jornada" (ex.: "Athenas - Atendimento · Atendimento e Serviços"). Não há número
  fabricado por squad.
- **Cycle Time** — tempo médio por fase (Ideação/Dev/Homologação/Implantação).
- **Lead Time** — por tipo de item e squad.
- **Débito Técnico** — backlog e Planejado/Real/Entregue/Pendente/Cancelado.
- **Sustentação** — Realizado vs Planejado por time, com histórico por PI.
- **Carga / Alterações / Impedimentos** — composição da carga, alterações no plano e impedimentos.

### 4.8 Apresentação Executiva
Modo de apresentação por jornada (score, faixa, atingimento e destaques), com navegação sequencial entre as jornadas.

### 4.9 Executivo por Squad
Tela inicial do Tech Lead: score do squad escolhido, detalhamento por categoria e Farol no período.

### 4.10 Lançamento de Exceção (fluxo com aprovação)
- O **TM solicita** uma exceção (squad, indicador, justificativa, período) — fica **Pendente**.
- O **DM/Admin aprova ou rejeita**. Só quando **aprovada** a exceção sinaliza o indicador afetado nas visões.
- Todo o histórico (quem solicitou, quem decidiu, estados e datas) fica registrado.

### 4.11 Telas de Gestão (seção "Gestão e Configuração")
CRUD no navegador: **Perfis, Tribos/Squads e Integração** (exclusivas de Admin/DM). Cada tela permite importar/exportar CSV/JSON.

---

## 5. Como alimentar as informações HOJE (antes da integração)

Enquanto a integração automática não existe, os dados entram por **importação de arquivo** ou **entrada manual**. Todos os CSV usam **separador ponto-e-vírgula (`;`)** e codificação **UTF-8**. Decimais aceitam vírgula ou ponto.

### 5.1 Score mensal (visões Visão Geral, Jornadas, Métricas, Evolução, Metas)

Fonte: a planilha mensal de Score. Importe um CSV com este cabeçalho **exato** (ordem recomendada):

```
mes;jornada;time;planejadoVsRealizado;cycleTimeDev;cycleTimeHomolog;cycleTimeImpl;freqDeploy;disponibilidade;vulnerabilidade;slaAlta;slaMedia;slaBaixa;okrs;teamHealth
```

Regras dos valores:
- **mes**: número 1–12 ou rótulo (Jan…Dez).
- **jornada / time**: nomes consistentes com a estrutura de tribos/squads.
- **Percentuais** (planejadoVsRealizado, disponibilidade, vulnerabilidade, slaAlta, slaMedia, slaBaixa, okrs): escala **0 a 1** (ex.: `0,97` = 97%).
- **Cycle Times** (dev/homolog/impl): em **dias**.
- **freqDeploy**: categoria inteira (0/1 = menos de 1 por mês, 2 = entre mensal e semanal, 3 = semanal, 4+ = mais de 1 por semana).
- **teamHealth**: escala **0 a 10**.
- Campo vazio = "Sem Dados" (não conta como "Não atendeu").

Uma linha por **time × mês**. Linhas inválidas são rejeitadas com mensagem; as válidas são importadas.

Exemplo de linha:
```
Ago;Atendimento e Serviços;kBots;0,93;3,4;1,3;5,2;3;0,996;0,98;0,985;1,0;1,0;0,86;8,8
```

### 5.2 Entrada manual (Operacional/Times)

Na aba **Operacional/Times**, botão **Entrada de Dados**: escolha o time e preencha as métricas na janela. Útil para ajustes pontuais sem reimportar o CSV inteiro. Lembre as escalas: percentuais em %, Cycle Time em dias, Team Health em 0–10, Frequência de Deploy como categoria.

### 5.3 Extração do Jira (painéis de Retrospectiva de PI)

Fonte: exportação de itens do Jira (CSV ou JSON). Na aba **Retrospectiva de PI**, use **Extração do Jira**. Colunas reconhecidas (nomes toleram variações comuns do Jira):

```
chave;tipoItem;status;jornada;squad;sprint;pi;storyPoints;criadoEm;inicioDev;fimDev;homologadoEm;implantadoEm;resolvidoEm
```

- **Obrigatórios**: `chave`, `tipoItem`, `sprint`, `pi`. Itens sem esses campos são rejeitados (com mensagem), e os válidos entram.
- `storyPoints` numérico; as datas em ISO (aaaa-mm-dd) ou dd/mm/aaaa.
- A plataforma **gera os indicadores por Sprint** automaticamente a partir dos itens (pontos trabalhados, carga de trabalho, etc.).
- Se os projetos do Jira usam nomes diferentes dos squads da plataforma, ajuste o mapeamento na tela **Configuração de Integração** (seção de Gestão).

### 5.4 Baseline do PI (comparações Baseline × Mudança de Escopo e Situação dos Épicos)

Fonte: a planilha de baseline do PI. Na aba **Retrospectiva de PI**, use **Baseline do PI** (aceita `.xlsx` ou `.csv`). Colunas mínimas:

```
pi;chave;jornada;squad;situacao
```

- É associado ao **PI selecionado**. Carregar um novo baseline para o mesmo PI substitui o anterior (um arquivo inválido não apaga o baseline que já existia).
- O baseline também é a fonte dos indicadores **Planejado** e **PI Planning** do painel
  Pontos Trabalhados: a soma de Story Points dos itens do PI, por jornada. (Na planilha
  completa do baseline — ex.: `Baseline_Jornadas_26.4.xlsx` — isso vem da aba "Baseline
  itens", pelos itens marcados com o PI nas *Versões corrigidas*.)

### 5.5 Dados de gestão (Perfis, Tribos/Squads, Integração)

Em cada tela de **Gestão**, você pode cadastrar manualmente (criar/editar/remover) ou **importar/exportar CSV/JSON**. Campos por tela:
- **Perfis**: `nome`, `permissoes` (lista de telas).
- **Tribos/Squads**: `tribo`, `squad` (mantenha consistente com os nomes usados no Score e no Jira).
- **Integração**: `sistema`, `projetoJira`, `jornada`, `squad` (**nunca** inclua tokens/senhas — campos sensíveis são descartados).

---

## 6. Ordem recomendada de carga (passo a passo)

1. **Tribos/Squads** (Gestão) — garanta que os nomes de jornadas e squads estejam cadastrados e padronizados. Essa é a base de identidade usada em todas as telas.
2. **Score mensal** (CSV, seção 5.1) — alimenta as 5 visões executivas.
3. **Metas/KPIs** (Gestão) — Partida, Meta % por jornada, se diferentes do padrão.
4. **Extração do Jira** (seção 5.3) — alimenta os painéis de Retrospectiva de PI.
5. **Baseline do PI** (seção 5.4) — habilita as comparações de plano vs realizado.

---

## 7. Exportar e fazer backup

Como os dados vivem no navegador, **exporte regularmente**:
- Score: exportação CSV na aba Operacional/Times.
- Gestão: cada tela exporta a sua coleção em CSV/JSON.
- Retrospectiva de PI: exportação dos dados derivados em CSV.

Guarde esses arquivos como backup. Se você limpar o cache do navegador ou trocar de máquina, os dados precisam ser reimportados.

---

## 8. Dúvidas comuns

- **"Troquei de perfil e sumiram telas"** — é esperado: cada perfil vê um conjunto de telas (seção 3).
- **"Importei o CSV e deu erro em algumas linhas"** — linhas inválidas são rejeitadas com a mensagem do motivo; as válidas entram. Corrija as apontadas e reimporte.
- **"Os números não batem com a planilha"** — confira as escalas (percentuais em 0–1, Team Health em 0–10) e se os nomes de jornada/squad estão padronizados.
- **"A exceção que lancei não apareceu nas visões"** — exceções só refletem após **aprovação** do DM/Admin.
- **"Perdi meus dados"** — eles ficam no navegador; use os exports como backup (seção 7).

---

## 9. Limitações atuais (serão resolvidas na fase de produção)

- Sem login/autenticação real (perfis são simulação de visibilidade).
- Dados por navegador (sem compartilhamento, sem backup automático).
- Coleta do Jira/ServiceNow é **manual** (importação), não automática.
- Previsões, insights automáticos e regras de medição por time ainda não existem.
