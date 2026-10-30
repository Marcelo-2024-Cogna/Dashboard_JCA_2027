# PEP - Plataforma de Engenharia de Performance

## Visão Geral

Plataforma de cálculo e visualização do **Score de Performance** para times de engenharia,
reconciliada com a planilha de referência ("Pílula do Score"). O score é organizado em
**4 categorias (os 4 Ps)** e tem **teto de 1089 pontos** (base de 1000 pts + bônus por
métricas superadas).

| Categoria | Máximo base | O que mede |
|-----------|-------------|------------|
| **Processos** | 450 pts | Planejado vs Realizado, Cycle Times (Dev/Homolog/Implantação), Frequência de Deploy |
| **Práticas** | 450 pts | Disponibilidade, Índice de Vulnerabilidade, SLAs (Alta/Média/Baixa criticidade) |
| **Produto** | 50 pts | Alinhamento com OKRs |
| **Pessoas** | 50 pts | Team Health Check (escala 0–10) |

> Observação: o código mantém, por compatibilidade, uma visão legada de 3 "dimensões"
> (Pessoas/Processos/Práticas) consumida por partes do dashboard antigo. A **fonte da
> verdade** para o score são as 4 categorias acima.

## Arquitetura (resumo)

Aplicação **100% estática** (HTML/CSS/JavaScript), **sem backend**. Os dados são carregados
por **importação manual de CSV/JSON** (incluindo extrações do Jira e baselines de PI em
Excel/CSV) e persistidos em **localStorage** do navegador. A integração automática ao vivo
(Jira/ServiceNow/Datadog) é uma evolução futura, documentada nos ADRs.

Detalhes em `../documentacao/architecture.md` e nas decisões em `../documentacao/adr/`.

## Como Usar

1. Abra o arquivo `index.html` diretamente no navegador (`file://`).
2. Não requer servidor, build tools ou instalação para uso.
3. Funciona offline (exceto fontes e Chart.js via CDN).
4. Para carregar dados, use as telas de importação e os modelos em `modelos-csv/`
   (veja o `MANUAL-DO-USUARIO.md`).

## Funcionalidades

### Visões Executivas do Score
- **Visão Geral** — score consolidado, gauge, KPIs e radar das categorias.
- **Jornadas** — detalhamento por jornada e squad.
- **Métricas** — valor atual, score calculado e faixa de performance por métrica.
- **Evolução Mensal** — série histórica do score.
- **Metas** — metas progressivas por time/jornada.
- **Apresentação Executiva** — layout para apresentação por jornada.
- **Executivo por Squad** — home do perfil Tech Lead.

### Retrospectiva de PI (Retro PI)
Painéis alinhados ao template "03 - Cogna - Métricas Review - Retro PI":
Pontos Trabalhados, Produtividade, Situação dos Épicos, Cycle Time, Lead Time,
Débito Técnico, Sustentação e Extras (Carga/Alterações/Impedimentos).
Com **filtros por Jornada, Squad, Sprint e PI**, ingestão de extrações do Jira,
carga do **baseline de cada PI** e ponte com o Operacional/Times.

### Perfis e Permissões (RBAC)
Cinco perfis com permissões validadas: **Admin ≡ DM** (acesso total), **TM**
(dashboards + produtividade + solicitar exceção), **GPM**
(apenas 4 dashboards de visualização), **TL** (executivo/detalhado por squad +
produtividade). Exceções seguem fluxo
*solicitar (TM) → aprovar (DM/Admin)*.

### Telas de Gestão (Admin/DM)
Perfis e permissionamento, Tribos e Squads e Integração.

### Entrada e Exportação de Dados
- Importação manual de CSV/JSON (score mensal, times, Jira, baseline etc.).
- Export CSV com separador `;` e UTF-8 com BOM (compatível com Excel BR).

## Estrutura de Arquivos

```
Plataforma-Engenharia-Performance/
├── aplicacao/
│   ├── index.html              # Página principal (SPA estática)
│   ├── css/
│   │   └── styles.css          # Estilos (tema claro via variáveis CSS) + menu lateral vertical
│   ├── js/
│   │   ├── scoring-engine.js   # Motor de cálculo (4 Ps, teto 1089, bandas)
│   │   ├── data.js             # Dados base dos times/jornadas
│   │   ├── data-import.js      # Importação manual de CSV/JSON
│   │   ├── app-state.js        # Estado da app + persistência localStorage
│   │   ├── app.js              # Inicialização e roteamento de views
│   │   ├── dashboard.js        # Dashboard legado preservado
│   │   ├── aggregation.js      # Agregações do score
│   │   ├── targets.js          # Metas progressivas
│   │   ├── identity.js         # Identidade canônica (times/pessoas)
│   │   ├── rbac.js             # Perfis e permissões
│   │   ├── admin-store.js      # Persistência das telas de gestão
│   │   ├── excecoes-store.js   # Exceções (solicitar/aprovar)
│   │   ├── jira-ingest.js      # Ingestão de extrações do Jira
│   │   ├── jira-mapping.js     # Mapeamento de campos do Jira
│   │   ├── baseline-loader.js  # Carga do baseline de PI
│   │   ├── ops-bridge.js       # Ponte Operacional/Times
│   │   ├── retro-*.js          # Dados/filtros/agregação/import da Retro PI
│   │   └── views/              # Telas (view-*, retro-*, admin-*)
│   ├── pages/                  # Reservado para páginas futuras
│   ├── tests/                  # Testes (Vitest + fast-check)
│   ├── modelos-csv/            # 10 modelos de CSV + LEIA-ME
│   ├── MANUAL-DO-USUARIO.md    # Manual completo de uso
│   └── README.md               # Este arquivo
└── documentacao/
    ├── requirements.md         # Requisitos (cópia da spec)
    ├── design.md               # Design (cópia da spec)
    ├── tasks.md                # Plano de implementação (cópia da spec)
    ├── data-model.md           # Modelo lógico/ER
    ├── architecture.md         # Arquitetura atual + alvo
    ├── database-ddl.sql        # DDL PostgreSQL (alvo de produção)
    └── adr/                    # Decisões de arquitetura (ADRs)
```

> A spec viva permanece em `.kiro/specs/score-performance-executivo/`; a pasta
> `documentacao/` contém cópias para leitura fora do Kiro.

## Regras de Scoring

### Faixas de Performance (Bands)
| Faixa | Pontuação |
|-------|-----------|
| Baixa Performance | 0–500 |
| Média Performance | 501–839 |
| Boa Performance | 840–1000 |
| Ótima Performance | 1001–1089 |

### Métricas, Categorias e Pesos

| Métrica | Categoria | Peso base |
|---------|-----------|-----------|
| Planejado vs Realizado | Processos | 150 |
| Cycle Time Desenvolvimento | Processos | 125 |
| Cycle Time Homologação | Processos | 50 |
| Cycle Time Implantação | Processos | 50 |
| Frequência de Deploy | Processos | 75 |
| Disponibilidade | Práticas | 175 |
| Índice de Vulnerabilidade | Práticas | 175 |
| SLA Alta Criticidade | Práticas | 50 |
| SLA Média Criticidade | Práticas | 30 |
| SLA Baixa Criticidade | Práticas | 20 |
| Alinhamento com OKRs | Produto | 50 |
| Team Health Check (0–10) | Pessoas | 50 |

Métricas que **superam** a meta geram bônus acima do peso base, respeitando o teto de 1089.
O Farol considera atendimento ≥ 99%.

### Interpolação Proporcional
Dentro de cada faixa, o score é calculado por interpolação linear entre os limites
mínimo e máximo da faixa:
```
score = floor + ((value - from) / (to - from)) * (top - floor)
```

## Tecnologias
- HTML5, CSS3, JavaScript (ES6+)
- Chart.js (CDN) e Google Fonts (Inter)
- Sem frameworks e sem etapa de build para o app
- Testes: Vitest + fast-check (property-based)

## Testes

As dependências de teste ficam em `devDependencies`. No ambiente deste projeto o `npm`/`npx`
podem estar bloqueados por ExecutionPolicy; nesse caso rode o Vitest diretamente pelo Node:

```powershell
node ".\node_modules\vitest\vitest.mjs" --run --root "."
```

Caso `node_modules` não exista neste novo local, reinstale as dependências de teste
(`vitest`, `fast-check`) antes de rodar. O app em si **não** depende de `node_modules`.

## Roadmap (evoluções futuras)
- [ ] Integração automática com APIs (Jira, ServiceNow, Datadog, SonarQube)
- [ ] Backend com persistência (ver `documentacao/database-ddl.sql`)
- [ ] Previsões de indicadores com base no histórico dos times
- [ ] Autenticação corporativa (SSO) substituindo o RBAC client-side
- [ ] Alertas automáticos e insights por time/jornada
