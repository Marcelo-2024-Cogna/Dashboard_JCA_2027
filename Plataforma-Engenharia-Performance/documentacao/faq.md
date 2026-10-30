# FAQ — Plataforma de Engenharia de Performance (PEP)

Perguntas frequentes sobre uso, dados e evolução da plataforma. Para detalhes de uso passo a
passo, veja o `MANUAL-DO-USUARIO.md`.

## Uso geral

**Preciso instalar algo para usar?**
Não. Basta abrir `aplicacao/index.html` no navegador. Não há build nem servidor obrigatório.

**Funciona offline?**
Parcialmente. O app roda offline, mas Google Fonts, Chart.js e SheetJS vêm de CDN; sem rede,
as fontes/gráficos podem não carregar. Para offline total, sirva essas libs localmente
(ver `guia-deploy.md`).

**Qual navegador devo usar?**
Qualquer navegador moderno (Chrome, Edge, Firefox). Em alguns casos, abrir por `file://`
impõe restrições; se notar problemas, use um servidor estático local (ver `guia-deploy.md`).

## Dados

**Onde os dados ficam salvos?**
No `localStorage` do seu navegador, por origem (domínio). Não há servidor central no MVP.

**Como alimento os dados hoje?**
Importando CSV/JSON manualmente (extrações do Jira, baseline de PI, planilhas), usando os
modelos em `modelos-csv/`. Veja o passo a passo no `MANUAL-DO-USUARIO.md`.

**Qual o formato dos CSVs?**
Separador `;` e UTF-8 com BOM (compatível com Excel BR). Os cabeçalhos exatos estão nos
modelos em `modelos-csv/` e no `MANUAL-DO-USUARIO.md`.

**Como faço backup?**
Use a exportação CSV/JSON das telas. Guarde os arquivos. Para migrar de máquina/domínio,
exporte e depois importe no destino (localStorage não é transferido automaticamente).

**Se eu limpar os dados do navegador, perco tudo?**
Sim — o estado local é apagado. Mantenha backups por exportação.

**Troquei o endereço de hospedagem e meus dados sumiram. Por quê?**
`localStorage` é isolado por origem (domínio). Dados do domínio antigo não aparecem no novo.
Exporte no antigo e importe no novo.

## Score

**Qual é o teto do score?**
1089 pontos: base de 1000 + bônus por métricas superadas.

**Quais são as categorias?**
Os 4 Ps: Processos (450), Práticas (450), Produto (50) e Pessoas (50). OKRs ficam em Produto;
Team Health Check (escala 0–10) fica em Pessoas.

**E as "3 dimensões" que aparecem em alguns lugares?**
É uma visão legada (Pessoas/Processos/Práticas) mantida por compatibilidade. A fonte da
verdade são as 4 categorias.

**O que é o Farol?**
Indicador de atendimento de meta; considera ≥ 99% como atendido ("verde").

## Perfis e acesso

**Quais perfis existem?**
Admin, DM, TM, GPM e TL. Admin e DM têm permissões idênticas (acesso total). A matriz
completa está em `design.md` e resumida no `glossario.md`.

**Quem pode lançar exceção de indicador?**
TM e TL solicitam; DM e Admin aprovam. É um fluxo de duas etapas.

**O RBAC é seguro?**
No MVP é apenas controle de experiência (UX) no cliente — não é segurança real. Autenticação
e RBAC server-side vêm na fase de produção (ver `roadmap-producao.md`).

## Retrospectiva de PI

**Como gero os dados por sprint?**
Importando a extração do Jira e carregando o baseline do PI. Os painéis então agregam por
Jornada/Squad/Sprint/PI conforme os filtros.

**Quais painéis existem?**
Pontos Trabalhados, Produtividade, Situação dos Épicos, Cycle Time, Lead Time, Débito
Técnico, Sustentação e Extras (Carga/Alterações/Impedimentos).

**Filtrei por Squad e Pontos/Produtividade mostraram a jornada inteira. É bug?**
Não. Essas duas métricas são medidas **por jornada** na fonte (Velocity e Produtividade não
têm quebra por squad). Ao filtrar por um squad, o painel mostra os valores da jornada daquele
squad e indica o escopo como "Squad · Jornada". Só a **Situação dos Épicos** tem número por
squad. Para Pontos/Produtividade por squad, a fonte precisa reportar nessa granularidade.

**De onde vêm "Planejado" e "PI Planning" no painel de Pontos Trabalhados?**
Do **baseline do PI** (soma de Story Points dos itens do PI, por jornada). "Real" e
"Velocidade" vêm da Velocity. Quando uma métrica não tem fonte, aparece como "—" (nunca é
inventada).

**Por que alguns painéis do PI 4/26 mostram "Sem dados"?**
Cycle Time, Lead Time, Sustentação, Alterações no Plano e Impedimentos não têm fonte
extraível pela interface atual do Jira (exigem changelog item a item). Preferimos exibir "Sem
dados" a fabricar números; serão preenchidos quando houver coleta automática (ver
`parametrizacao-metricas-jira.md`).

## Integrações e futuro

**Dá para integrar automaticamente com o Jira?**
Ainda não. Hoje a ingestão é por exportação manual (CSV/JSON). A integração automática exige
backend e cofre de segredos — está planejada no `roadmap-producao.md` (Marco 4).

**Posso colocar credenciais do Jira/ServiceNow/Datadog no app?**
Não. O app é client-side e não deve conter segredos. A coleta automática virá com backend.

**Como será a transição para produção?**
Mantendo o domínio puro (motor de score) e o contrato CSV/JSON estáveis, adicionando banco,
API, SSO e ETL de forma incremental. Detalhes em `architecture.md` e `roadmap-producao.md`.

## Testes e desenvolvimento

**Como rodo os testes?**
Com Vitest + fast-check. Se `npm`/`npx` estiverem bloqueados, rode o Vitest direto pelo Node:
`node ".\node_modules\vitest\vitest.mjs" --run --root "."` a partir de `aplicacao/`
(reinstale `vitest`/`fast-check` se `node_modules` não existir no local).

**O app depende de `node_modules`?**
Não. `node_modules` só é necessário para os testes; o app abre sem ele.
