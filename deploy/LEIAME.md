# Deploy de producao: Metricas Jeito Cogna Agil v1.5

Destino: Linux, Apache 2.4, aplicacao em `/apps/metricas_jca`, URL `https://metricas.cogna.com.br/` (raiz do dominio).
Opcao A: o DocumentRoot e `/apps/metricas_jca/public`. `app/`, `config/`, `bin/`, `db/` e `data/` ficam fora da web.

## 0. Pre-requisitos do servidor

- Apache 2.4 com `mod_rewrite`, `mod_ssl`, `mod_headers` (`a2enmod rewrite ssl headers`).
- PHP **7.4 ou superior** (7.4.3 do Ubuntu 20.04 validado; 8.x tambem funciona, desenvolvido em 8.2.12). O arquivo `app/compat.php` fornece polyfills guardados por `function_exists` (`str_starts_with`, `str_ends_with`, `str_contains`, `array_is_list`, `get_debug_type`), inertes no PHP 8, e e carregado no topo de `public/index.php` e de todo `bin/*.php`. As extensoes sao as mesmas em 7.4 e 8.x (`php7.4-mysql`, `-mbstring`, `-curl`, `-zip`, `-xml`). Sem Composer: o autoload e proprio. Para validar a sintaxe em 7.4 apos alteracoes: `deploy/lint_php74.ps1`.
- Extensoes PHP (Apache **e** CLI do cron): `pdo_mysql`, `mbstring`, `curl`, `json` (nativa). Para scripts de importacao em `bin/` (uso eventual): `zip` e `SimpleXML`. Confira com `php -m` no CLI e `phpinfo()`/`apache2ctl -M` na web (o `php.ini` do CLI e o do Apache sao arquivos diferentes).
- MySQL 8.x acessivel pelo servidor (o dev usa 8.4).
- Saida de rede do servidor para `https://cogna.atlassian.net` (worker de extracao).

## Pre-voo no servidor (antes do deploy)

Medido no phpinfo de 06/11 (Ubuntu 20.04, Apache 2.4.41 prefork, PHP 7.4 mod_php): `mod_ssl` e `mod_alias` carregados; **faltam** `mod_rewrite` e `mod_headers` no Apache e `mbstring` e `curl` no PHP (o app usa `mb_*` e o `curl` do `JiraModel`).

```bash
apache2ctl -M | grep -E 'rewrite|headers|ssl'          # esperado: 3 linhas
sudo a2enmod rewrite headers
sudo apt install php7.4-mbstring php7.4-curl            # opcional: php7.4-zip php7.4-xml
php -m | grep -E 'mbstring|curl|pdo_mysql|json'         # CLI (cron); conferir tambem o phpinfo da web
apache2ctl configtest
sudo systemctl restart apache2                          # restart (nao reload) para carregar extensoes do mod_php
```

Aviso: sem `mod_rewrite` o `public/.htaccess` (dentro de `IfModule mod_rewrite.c`) falha **em silencio**: so `/` e os assets abrem e as demais rotas dao 404. Sem `mod_headers` o bloco de cabecalhos do vhost e ignorado (tambem em silencio). Sem `mbstring`/`curl` ha erro fatal ao usar graficos e a extracao. Se nao puder habilitar o `mod_rewrite`, use `FallbackResource /index.php` (comentado no vhost).

## 1. Ordem do deploy

1. Copiar os arquivos (secao 2).
2. Criar banco e usuario (secao 3), aplicar `01_schema.sql`.
3. Importar o dump de dados (secao 4).
4. Aplicar `02_views.sql`, depois `03_triggers.sql` (conta privilegiada).
5. Criar `config/db.php` e `config/secrets.local.php` no servidor (secao 5).
6. Permissoes das pastas de escrita (secao 6).
7. Vhost Apache (secao 7) e cron do worker (secao 8).
8. Smoke test (secao 9).

## 2. Copiar os arquivos

Excluir tudo o que esta em `deploy/excluir.txt` (`.git`, `.agents`, `.kiro`, `config/db.php`, `config/secrets.local.php`, `data/logs`, `data/deploy`, `dashboard-v1.0`, `Plataforma-Engenharia-Performance`, `bin/seed_*.php`, etc.).

A partir de uma copia limpa do repositorio (Linux/WSL/Git Bash):

```bash
rsync -a --exclude-from=deploy/excluir.txt ./ usuario@servidor:/apps/metricas_jca/
```

Em Windows (robocopy; ajuste os caminhos):

```powershell
$o = 'C:\xampp\htdocs\dashboard'
robocopy $o D:\staging\metricas_jca /E /XD "$o\.git" "$o\.agents" "$o\.kiro" "$o\.vscode" "$o\dashboard-v1.0" "$o\Plataforma-Engenharia-Performance" "$o\data\deploy" "$o\data\logs" /XF "$o\config\db.php" "$o\config\secrets.local.php" "$o\.htaccess" "$o\bin\seed_catalogo.php" "$o\bin\seed_sustentacao.php" *.log items.json notificacoes.log Template*.xlsx Template*.png
```

Os caminhos sao completos de proposito: `/XF .htaccess` (so o nome) tambem removeria `public\.htaccess`, o front controller, e `/XD deploy` removeria `deploy/` do projeto. Apenas o `.htaccess` da RAIZ (so para o XAMPP) e excluido. Confira depois: `Test-Path D:\staging\metricas_jca\public\.htaccess` deve ser `True`.

Observacoes:
- `data/config/pi.json` e `data/config/capacity.json` guardam parametros editados no Painel (PI/Capacidade). Sao copiados junto; remova-os se quiser que a producao comece pelos valores-semente de `config/*.php`.
- A pasta `deploy/` pode ou nao ir ao servidor (e util la para o DBA aplicar `deploy/sql`). Fica fora do DocumentRoot.
- NUNCA rodar `bin/seed_catalogo.php` em producao: apaga e recria times.

## 3. Banco e usuario (privilegios minimos)

O app, em runtime, so executa SELECT/INSERT/UPDATE/DELETE (nenhum DDL no codigo de `app/` e `public/`). O `db/schema.sql` original cria o usuario `metricas_jca` com senha fraca e privilegios de DDL: isso foi **removido** de `deploy/sql/01_schema.sql`.

Nome do schema: padrao `metricas_agil` (a definir). Para outro nome:
`sed -i 's/metricas_agil/NOME/g' deploy/sql/0*.sql` e use o mesmo nome em `config/db.php`.

```bash
# 1) schema (conta DBA)
mysql -u <dba> -p --default-character-set=utf8mb4 < deploy/sql/01_schema.sql
```
```sql
-- 2) usuario da aplicacao (senha forte; ajuste o host)
CREATE USER 'metricas_jca'@'localhost' IDENTIFIED BY '<SENHA_FORTE>';
GRANT SELECT, INSERT, UPDATE, DELETE ON metricas_agil.* TO 'metricas_jca'@'localhost';
```

O app nao recebe `TRIGGER`, `CREATE` nem `ALTER`. O trigger de auditoria roda com os direitos do DEFINER (a conta que aplicou `03_triggers.sql`), que deve continuar existindo.

## 4. Dados, views e triggers (ordem obrigatoria)

Gere o dump no ambiente de dev: `deploy/dump_dados.ps1` (le a senha de `-Senha`, da variavel `METRICAS_DB_PASS` ou pergunta; grava `data/deploy/dados_<aaaammdd>.sql`, pasta ignorada pelo git).

Tabelas **incluidas**: `configuracao_pi`, `jornada`, `times`, `times_composicao`, `sprints`, `extracao_jira`, `baseline`, `baseline_ajuste` (preserva a trilha de auditoria), `produtividade_historico`, `sustentacao_historico`.
Tabelas **excluidas**: `extracao_agendamento` (fila) e `extracao_log` (log da fila); nascem vazias. Views nao entram no dump.

```bash
mysql -u <dba> -p --default-character-set=utf8mb4 metricas_agil < dados_AAAAMMDD.sql
mysql -u <dba> -p --default-character-set=utf8mb4 < deploy/sql/02_views.sql
mysql -u <conta_privilegiada> -p --default-character-set=utf8mb4 < deploy/sql/03_triggers.sql
```

Por que essa ordem: o trigger `trg_baseline_audit_upd` e AFTER UPDATE em `baseline`. O import usa INSERT (nao dispara), mas aplicar o trigger por ultimo garante que nenhuma linha falsa entre em `baseline_ajuste`. As views nao dependem dos dados. `03_triggers.sql` exige conta com `SUPER` ou `log_bin_trust_function_creators=1` (erro 1419 caso contrario). Todos os scripts sao reaplicaveis.

Dados da carga inicial refletem a extracao local; em producao a extracao do Painel os atualiza.

## 5. Configuracao (segredos criados NO servidor)

```bash
cd /apps/metricas_jca
cp deploy/config/db.php.production.example config/db.php
cp deploy/config/secrets.local.php.production.example config/secrets.local.php
# editar os dois com os valores reais
chown root:www-data config/db.php config/secrets.local.php ; chmod 640 config/db.php config/secrets.local.php
```

`secrets.local.php` traz `JIRA_EMAIL`, `JIRA_API_TOKEN` (token classico) e `JIRA_CA_BUNDLE`. Nao use `JIRA_SSL_VERIFY=0`.
Verificado no codigo: `config/jira.php` le `JIRA_CA_BUNDLE` e deixa a verificacao SSL ativa (so `JIRA_SSL_VERIFY=0` a desliga); `app/Models/JiraModel.php` aplica `CURLOPT_CAINFO` quando ha bundle. `CURLOPT_CAINFO` **substitui** o bundle do sistema: o `.pem` deve conter todas as CAs necessarias (ex.: `cat /etc/ssl/certs/ca-certificates.crt CA_CORPORATIVA.pem > config/ca-bundle.pem`) ou instale a CA no sistema (`update-ca-certificates`) e deixe a chave vazia.

## 6. Pastas com escrita pelo usuario do Apache (`www-data`)

O app grava em `data/` (logs, ordens da fila, JSONs de baseline/capacidade/config, cache `items.json`, `notificacoes.log` direto em `data/`). O cron deve rodar com o mesmo usuario.

```bash
cd /apps/metricas_jca
mkdir -p data/{logs,agendamentos,baselines,progress,capacity,config,exports,import}
chown -R root:www-data . ; chmod -R u=rwX,g=rX,o= .
chown -R www-data:www-data data ; chmod -R u=rwX,g=rwX,o= data
chown root:www-data config/*.php ; chmod 640 config/db.php config/secrets.local.php
```

Pastas: `data/` (raiz, para `items.json` e `notificacoes.log`), `data/logs`, `data/agendamentos`, `data/baselines`, `data/progress`, `data/capacity`, `data/config`, `data/exports` e `data/import` (estas duas so para scripts de `bin/`).

## 7. Apache

```bash
cp deploy/apache/metricas.cogna.com.br.conf /etc/apache2/sites-available/
# editar SSLCertificateFile / SSLCertificateKeyFile (placeholders: certificado corporativo)
a2enmod rewrite ssl headers ; a2ensite metricas.cogna.com.br
apache2ctl configtest && systemctl reload apache2
```

O vhost nao usa o certificado `snakeoil` nem a linha `Include .../default-ssl.conf` do modelo (comentada, com explicacao). Usa `Options -Indexes +FollowSymLinks`, DocumentRoot em `/apps/metricas_jca/public` (nunca na raiz do app, que exporia `config/`, `data/` e `app/`), certificado DigiCert por placeholder (com `SSLCertificateChainFile` comentado) e, opcionalmente, `FallbackResource /index.php` comentado como roteamento independente do `mod_rewrite`. `public/.htaccess` nao tem `RewriteBase` e funciona na raiz e em `/dashboard/`.

## 8. Cron do worker

```bash
sudo -u www-data crontab -l                                   # ver o que ja existe
sudo -u www-data crontab /apps/metricas_jca/deploy/cron/metricas_jca.cron
```
Roda `bin/queue_worker.php` a cada minuto com `flock -n`, saida em `data/logs/worker.log` (uma linha por minuto: configure `logrotate` semanal para esse arquivo). O worker usa o mesmo `config/db.php` e `config/secrets.local.php` da web.

## 9. Smoke test

Navegador (VPN) e `curl -I`:
1. `http://metricas.cogna.com.br/` responde 301 para `https://metricas.cogna.com.br/`.
2. `https://metricas.cogna.com.br/` 200; no HTML, links `/assets/...` e `/metricas/...` (sem `/dashboard`) e `window.APP_BASE = ""`.
3. `/assets/vendor/chart.umd.min.js` e `/assets/css/app.css` 200.
4. `/metricas/velocidade?jornada=Secretaria%20e%20Gestor%20de%20Unidades&versao=PI%204/26` renderiza o grafico.
5. `/graficos/velocidade?jornada=Secretaria%20e%20Gestor%20de%20Unidades&versao=PI%204/26`: totais 435/410/396, media 198 (se os dados da carga inicial forem os de dev). `/graficos/previsibilidade?...` 331/235/71%.
6. `/filtros` e `/painel` 200.
7. Antes de tudo: `ls -la /apps/metricas_jca/public/.htaccess` existe (sem ele, so `/` e assets funcionam e as demais rotas dao 404). `/.env` e `/.gitignore` retornam 403 (e `/config/db.php` 404, pois esta fora do docroot).
8. Painel: agendar uma extracao pequena; ver a ordem em `/extracao/fila` e, apos o worker, o resultado em `data/logs/extracao.log` e `worker.log`.
9. `SELECT COUNT(*) FROM baseline_ajuste;` igual ao dump (10) e `SHOW TRIGGERS` lista `trg_baseline_audit_upd`.

## 10. Ponto de atencao de seguranca

O app **nao tem autenticacao**. Endpoints POST como `/settings/*` e `/extracao/agendar`, alem do Painel, permitem alterar configuracao e disparar extracoes a qualquer um que alcance a URL. Recomendado restringir por rede (VPN/ACL/firewall). Como paliativo, o vhost traz comentado um bloco de HTTP Basic Auth (`AuthType Basic`, `AuthUserFile` fora do docroot, `Require valid-user`) e outro de `Require ip`.

## 11. O que NAO foi testado

- Nada foi feito no servidor de producao (sem acesso nem escrita).
- `.htaccess`/mod_rewrite, vhost, TLS e cron: nao executados localmente. O servidor embutido do PHP simula `SCRIPT_NAME=/index.php` mas ignora `.htaccess`. Validar no primeiro smoke test.
- Chamada real ao Jira com `JIRA_CA_BUNDLE` (so a leitura da config foi verificada).
- Importacao do dump com `mysql` em Linux e contas reais; grants minimos (testado apenas com a conta root local e o schema descartavel).
- PHP 7.4: testado localmente com o PHP 7.4.33 (lint de todos os .php, boot via servidor embutido e comparacao das respostas HTTP com o PHP 8.2.12). Nao testado: o PHP 7.4.3-4ubuntu2.28 real do servidor, Apache com mod_php/php-fpm e o worker (`bin/queue_worker.php`) com chamadas reais ao Jira em 7.4.
