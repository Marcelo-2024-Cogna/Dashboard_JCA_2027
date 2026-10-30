# Guia de Deploy — PEP (MVP estático)

Como publicar e distribuir a aplicação **atual** (Opção B: estática, sem backend). O app é
composto apenas de arquivos estáticos em `aplicacao/` e **não requer build**.

> Para o alvo de produção (backend + banco + integrações), veja `roadmap-producao.md` e
> `architecture.md`.

## O que precisa ser publicado

Todo o conteúdo da pasta `aplicacao/` (exceto itens de desenvolvimento):

Publicar:
- `index.html`
- `css/`
- `js/` (inclui `js/views/`)
- (opcional) `modelos-csv/` e `MANUAL-DO-USUARIO.md` para os usuários baixarem

Não publicar (desenvolvimento):
- `tests/`, `node_modules/`, `package.json`, `package-lock.json`, `.gitignore`

## Dependências externas (CDN)

O app carrega via CDN: Google Fonts (Inter), Chart.js e SheetJS (XLSX). Portanto precisa de
**acesso de rede a esses CDNs** no navegador do usuário. Em rede corporativa restritiva:
- Garanta liberação dos domínios `fonts.googleapis.com`, `fonts.gstatic.com`,
  `cdn.jsdelivr.net`; ou
- Baixe essas bibliotecas e sirva localmente (ajustar as tags `<script>`/`<link>` no
  `index.html` para caminhos locais).

## Opção 1 — Uso local (file://)

A forma mais simples: abrir `aplicacao/index.html` com duplo clique no navegador.
Funciona porque todos os caminhos internos são relativos.

Limitações: cada usuário tem seus próprios dados em `localStorage` (sem compartilhamento);
e, dependendo do navegador, algumas políticas de `file://` podem restringir leitura de
arquivos — nesse caso, use um servidor estático (Opção 2).

## Opção 2 — Servidor estático local (recomendado para testes)

Sirva a pasta `aplicacao/` por HTTP. Exemplos (rodar a partir de `aplicacao/`):

```powershell
# Com Node (http-server), se disponível:
npx http-server . -p 8080

# Ou com Python 3:
python -m http.server 8080
```

Depois acesse `http://localhost:8080`.

> Observação: neste ambiente `npm`/`npx` podem estar bloqueados por ExecutionPolicy.
> Se for o caso, prefira a Opção 1 (file://) ou a hospedagem da Opção 3.

## Opção 3 — Hospedagem estática (compartilhado)

Qualquer host de site estático serve, pois não há backend:

- **Servidor web corporativo (IIS/Nginx/Apache):** copie o conteúdo de `aplicacao/` para o
  diretório público (ex.: `wwwroot`/`/var/www/html`). Nenhuma configuração de runtime.
- **Azure Static Web Apps / Blob Storage (static website):** aponte a origem para o conteúdo
  de `aplicacao/`. Adequado ao contexto Azure/Cogna.
- **GitHub Pages / Netlify / Vercel:** publicar a pasta como site estático (sem comando de
  build; diretório de publicação = `aplicacao/`).

### Exemplo mínimo de Nginx

```nginx
server {
    listen 80;
    server_name pep.exemplo.corp;
    root /var/www/pep/aplicacao;
    index index.html;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## Dados e persistência

- Os dados ficam no `localStorage` do navegador de cada usuário, por origem (domínio).
- **Backup:** oriente os usuários a exportar CSV/JSON periodicamente (há export nas telas).
- **Reset:** limpar os dados do site no navegador zera o estado local.
- Trocar o domínio de hospedagem **não** carrega os dados do domínio antigo (localStorage é
  por origem) — exporte/importe para migrar.

## Verificação pós-deploy (checklist)

- [ ] `index.html` abre e renderiza o dashboard inicial.
- [ ] Fontes, Chart.js e SheetJS carregam (sem erros 403/bloqueio no console).
- [ ] Navegação entre as visões (Geral, Jornadas, Métricas, Evolução, Metas) funciona.
- [ ] Retrospectiva de PI abre e os filtros (Jornada/Squad/Sprint/PI) respondem.
- [ ] Troca de perfil (RBAC) ajusta o menu conforme a matriz de permissões.
- [ ] Importação de um CSV de modelo (`modelos-csv/`) é aceita e reflete nos painéis.
- [ ] Exportação CSV gera arquivo com `;` e UTF-8 BOM (abre certo no Excel BR).

## Segurança (MVP)

- Não há autenticação real nem segredos no app — o RBAC é apenas de UX client-side.
- Não insira credenciais de Jira/ServiceNow/Datadog no app: a integração automática só virá
  com backend/cofre de segredos (ver `architecture.md`).
- Sirva sempre por **HTTPS** quando hospedado (Opção 3).
