# Prompt de Retomada — Dashboard Ágil (Métricas Jira / PI Planning)

> Documento de handoff. Abrir este arquivo ao retomar. Resume o estado do
> projeto e o que fazer a seguir (Etapa 3 + Painel + Consultas + Menu).

---

## 1. Onde paramos

Backend (PHP puro, sem Composer, padrão MVC) e modelo de dados **concluídos e
testados**. Falta o **frontend** (Etapa 3) e as **interfaces** (Painel de
Controle, Tela de Consulta, Menu Home).

Ambiente operacional:
- **XAMPP** Apache na porta **8080** (serviço `ApacheXAMPP`) — serve o dashboard.
- **Apache24** na porta **8082** (FastCGI php-cgi na 9000, auto-start via tarefa).
- Dashboard acessível em **http://localhost:8080/dashboard/**
- PHP CLI do XAMPP: `C:\xampp\php\php.exe`. O cURL do PHP CLI pode falhar por
  SSL (proxy corporativo) — use `JIRA_SSL_VERIFY=0` ao testar via CLI. Via
  Apache funciona normalmente.

---

## 2. Arquitetura atual (MVC)

```
dashboard/
├── public/
│   ├── index.php      ← front controller (roteamento HTTP) + .htaccess
│   ├── index.html     ← SPA atual (PRECISA SER REFEITO na Etapa 3)
│   └── .htaccess      ← rewrite p/ index.php, charset UTF-8
├── .htaccess          ← redireciona raiz p/ public/
├── app/
│   ├── Controllers/
│   │   ├── ExtractorController.php  (/extract)
│   │   ├── BaselineController.php   (/baseline/*, /metrics)
│   │   ├── CapacityController.php   (/capacity/*)
│   │   └── McpController.php        (MCP via bin/server.php)
│   ├── Models/
│   │   ├── JiraModel.php            (API Jira, 33 campos, paginação cursor)
│   │   ├── BaselineSnapshot.php     (foto imutável; parse [TIME] PI X/YY)
│   │   └── ProgressSnapshot.php     (ponto da curva de evolução)
│   ├── Repositories/
│   │   ├── BaselineRepositoryInterface.php
│   │   ├── JsonBaselineRepository.php  (baselines/ + progress/)
│   │   └── CapacityRepository.php      (capacity/{team}.json)
│   └── Services/
│       ├── JqlBuilder.php           (fonte única de JQL)
│       ├── MetricsService.php       (previsibilidade, scope creep, etc.)
│       └── CapacityCalculator.php   (produtividade, capacidade, velocidade)
├── config/
│   ├── pi.php          ← PARÂMETROS DA PI (editar a cada ciclo)
│   ├── capacity.php    ← calendário + devs + produtividade + horas por time
│   ├── baseline.php    ← regras de negócio (Done, tipos valor/sustentação) + merge pi.php
│   └── jira.php        ← credenciais + campos customizados Jira
├── bin/server.php      ← entry MCP (STDIO)
└── data/               ← JSON: items.json, baselines/, progress/, capacity/
```

## 3. Rotas HTTP já implementadas

| Rota | O que faz |
|------|-----------|
| `GET /dashboard/` | serve o SPA (index.html) |
| `GET /dashboard/extract` | extrai itens do Jira (cache 30min em data/items.json) |
| `GET /dashboard/baseline/capture` | congela baseline da PI (imutável; trava recaptura, `?force=1`) |
| `GET /dashboard/baseline/progress[?sprint=26.4.1]` | grava ponto de evolução (acumula) |
| `GET /dashboard/baseline/list` | lista baselines + progressos |
| `GET /dashboard/metrics` | committed vs delivered, previsibilidade, scope creep + evolution |
| `GET /dashboard/capacity/plan?team=SGGU[&sprint=26.4.1]` | capacidade projetada do time |
| `GET /dashboard/capacity/sprint?...` | registra sprint no histórico (manual) |
| `GET /dashboard/capacity/sync[?pi=4/26]` | alimenta histórico AUTO dos progressos |
| `GET /dashboard/capacity/history?team=SGGU` | histórico do time |

## 4. Regras de negócio consolidadas (decisões do usuário)

- **PI corrente:** 4/26. Sprints 26.4.1 a 26.4.6. Fonte única: `config/pi.php`.
- **PI/Time** derivados de "Versões corrigidas" (`[TIME] PI X/YY`).
- **Done** = Aceito + Em produção + Pendente de Produção.
- **Produtividade** = horas_gastas / pontos_entregues, considerando **SÓ
  entrega de valor** (História, Enabler, Teste, Spike, Débito Técnico).
  Sustentação (Incidente, Solicitação de Serviço, Suporte, Sustentação) é
  EXCLUÍDA da produtividade (guardada à parte p/ % de chamados no futuro).
- **Capacidade** = (dias_úteis × horas_produtivas × devs) / produtividade.
- **Previsibilidade** = (delivered do escopo planejado / committed) × 100.
- **Scope Creep**: add = novos (não estavam na baseline); drop = removidos.
- **Variação de Escopo %** = (pontos adicionados pós-baseline / committed) × 100.
- **Fechamento**: olhar até a última sprint ENCERRADA (`config/pi.php → closing_sprint`).
- **Dias úteis por sprint** (da planilha): 26.4.1=8, .2=7, .3=7, .4=7, .5=8, .6=8.
- **Jornadas configuradas** (29 times): Secretaria e Gestor de Unidades,
  Ingresso, Acadêmica, Aprendizagem Digital, Atendimento e Serviços, Saber.
- **Jornadas excluídas** (default 4 devs, 7h): Financeira, Pós Graduação, IA,
  Voomp, Digital Finance.
- **Snapshot da baseline é IMUTÁVEL**: findBaseline retorna sempre o mais antigo.
- **Persistência JSON hoje**; migração futura para MySQL/PostgreSQL (a interface
  do Repository já é agnóstica).

## 5. Planilhas de referência (na raiz)

- **Template Métricas Review.xlsx** — design/estrutura dos gráficos do dashboard.
- **Template Baseline 26-4.xlsx** — mapa ID↔time↔jornada (col D Projeto, col F Versões).
- **Template Velocity 26-4.xlsx** — aba 2026_Produtividade: devs, produtividade, horas.

---

## 6. TAREFAS DA PRÓXIMA SESSÃO (Etapa 3 + Interfaces)

### Passo A — Etapa 3: Gráficos do frontend
Renderizar os gráficos baseados na **Template Métricas Review**, com destaque
para **Planejado vs Realizado**, **Velocidade** e **Previsibilidade**.
(O index.html atual é um protótipo e deve ser refeito/reorganizado.)

### Passo B — Painel de Controle (parametrização)
Interface administrativa para cadastro/ajuste dos parâmetros de cálculo.
Campos: Identificação da PI e Time/Jornada, Dias úteis da Sprint, Horas
produtivas por dev, Qtde de devs, Total de horas gastas e pontos entregues
(histórico p/ produtividade), Capacidade calculada.
→ Conectar aos JSON/PHP de capacidade (rotas `/capacity/*` já existem;
   pode ser necessário criar endpoints de escrita/edição da config).

### Passo C — Tela de Consulta (Velocidade Inicial + Baseline)
Filtros obrigatórios: Jornada, Time, Sprint/PI.
→ Consome `/metrics`, `/baseline/list`, `/capacity/plan`.

### Passo D — Menu Principal (Home)
Visões: 1) Visão Geral  2) Velocidade  3) Lead Time  4) Planejado x Realizado
5) Produtividade  6) Status dos Épicos  7) Pontos de Sustentação.

### Ações pedidas ao Kiro
1. Propor layout/arquitetura das telas (Painel de Controle + Consulta).
2. Definir componentes gráficos (Chart.js) e estrutura de rotas/menu do frontend.
3. Conectar o Painel de Controle aos JSON/PHP de capacidade/produtividade.

---

## 7. Pendências / pontos de atenção

- **Documentação para os pares**: o usuário pediu uma documentação explicativa
  do trabalho (arquitetura, fórmulas, fluxo) — ainda a produzir.
- **Baseline oficial da PI 4/26 ainda não foi capturada** (`/baseline/capture`).
  Rodar quando quiser dados reais nos gráficos (demora alguns min — 36 projetos).
- **Mapa código→devs**: se o usuário subir planilhas de outra PI, reimportar.
- Ao abrir endpoints de escrita da config (Painel), lembrar que `config/*.php`
  são arrays PHP — avaliar gravar os parâmetros editáveis em JSON para o painel
  poder alterá-los sem reescrever PHP (decisão a tomar na próxima sessão).
