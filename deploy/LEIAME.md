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

**Caminho preferido: o deploy automatizado (secao 12)**, que faz tudo abaixo (exclusoes, backup, donos, lint, smoke e rollback) com um comando. O procedimento manual a seguir continua valido.

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

**Collation das views (importante).** `02_views.sql` comeca com `SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;` porque as colunas de texto fixo das views herdam a collation da conexao que as cria, e a aplicacao PHP conecta em `utf8mb4_general_ci`. Se as views forem criadas por um cliente que usa `utf8mb4_0900_ai_ci` (padrao de varios clientes no MySQL 8), `/graficos/plan-real` falha com erro 500 (`Illegal mix of collations`, 1267). Confira depois de aplicar (esperado: `utf8mb4_general_ci` nas 15 views):

```sql
SELECT character_set_client, collation_connection, COUNT(*) n
FROM information_schema.views WHERE table_schema = 'metricas_agil' GROUP BY 1, 2;
```
Se aparecer outra collation, reaplique `02_views.sql` (e nao remova a linha `SET NAMES` do inicio).

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
mkdir -p data/{logs,agendamentos,baselines,progress,capacity,config,exports,import,locks,cache}
chown -R root:www-data . ; chmod -R u=rwX,g=rX,o= .
chown -R www-data:www-data data ; chmod -R u=rwX,g=rwX,o= data
chown root:www-data config/*.php ; chmod 640 config/db.php config/secrets.local.php
```

Pastas: `data/` (raiz, para `items.json` e `notificacoes.log`), `data/logs`, `data/agendamentos`, `data/config`, `data/exports` e `data/import` (estas duas so para scripts de `bin/`), `data/locks` (locks `flock` do job noturno e das extracoes por PI; precisa de escrita pelo usuario do cron) e `data/cache` (cache de respostas; gerado em runtime, fora do deploy). O verificador tambem grava `data/extract_check_estado.json` (em `data/`).

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

## 8b. Job noturno (extracao 23:00 e verificador 23:30)

Dois scripts novos, ambos em `bin/` e agendados no mesmo `deploy/cron/metricas_jca.cron` (com `flock -n`, saida em `data/logs/extracao_diaria.log`):

| Hora  | Script | O que faz |
|-------|--------|-----------|
| 23:00 | `bin/extract_diaria.php` | Resolve a PI corrente (e a anterior por 14 dias apos o fim dela), enfileira UMA ordem com hash do dia e a processa no mesmo processo: extracao principal por `fixVersion` + **fallback por sprint** (itens das sprints da PI sem `fixVersion` ou com `fixVersion` de outra PI; gravados com `extracao_jira.origem_pi = 'sprint'`; o fallback nao traz itens com `fixVersion` de PI futura). O resultado aparece em `/extracao/fila`, na linha `RESUMO ...` de `data/logs/extracao.log` e, em falha, em `data/notificacoes.log` + e-mail. |
| 23:30 | `bin/extract_check.php` | Confere jornada com dados de ate 25 h, ordem do job criada hoje e sem erro, e queda brusca (< 80%) de itens da PI contra `data/extract_check_estado.json`. Alerta por log + e-mail. Apague o arquivo de estado para "zerar" a referencia de uma queda legitima. |

**Codigos de saida** (tambem em `--help`):

| Codigo | `extract_diaria.php` | `extract_check.php` | `queue_worker.php` |
|--------|----------------------|---------------------|--------------------|
| 0 | ok (inclui "ja executada hoje") | tudo ok | nada pendente ou tudo ok |
| 1 | falha parcial (alguma jornada/PI) | alerta | alguma ordem em erro ou com lotes em falha |
| 2 | configuracao/PI invalida (sem PI cadastrada, fora do periodo de graca, nenhum time ativo, opcao invalida) | idem | opcao invalida |
| 3 | ja em execucao (lock), sem erro | n/a | n/a |
| 4 | erro fatal / Jira indisponivel (nenhuma jornada gravada) | erro fatal (ex.: banco indisponivel; registra, notifica e sai) | n/a |
| 5 | disco insuficiente: pre-voo (livre < `disco.min_livre_mb`; `--ignorar-disco` forca) | n/a | n/a |

**Opcoes de `extract_diaria.php`**: `--dry-run` (nao toca no Jira nem grava; so SELECT), `--pi='PI x/yy'`, `--forcar` (relaxa a protecao contra resposta parcial), `--sem-fallback`, `--refazer` (executa de novo no mesmo dia), `--ignorar-disco` (ignora o pre-voo de disco, ver 8c), `--help`.

**Protecoes**: a limpeza antes do INSERT e por **PI + prefixo de chave** dos projetos do lote (`chave LIKE 'SGSE-%'`), nunca por nome de projeto; a gravacao so ocorre depois de buscar tudo (transacao unica por lote, rollback em erro); se o Jira devolver 0 itens ou menos de 80% do que ja existe no recorte, o lote e abortado sem apagar nada (`limiar_parcial` em `config/extracao.php`); retry com backoff (4 tentativas; honra `Retry-After`; nao repete 400/401/403); lock `flock` em `data/locks/` (job noturno e extracao por PI). A extracao e sempre da PI inteira: `/extracao/agendar` recusa `sprints` preenchido (400).

**Notificacao de falha** (`mail()` nativo do PHP + log em arquivo como garantia). Variaveis (ambiente ou `config/secrets.local.php`): `JOB_NOTIFY_TO` (destinatarios separados por virgula), `JOB_NOTIFY_FROM` (remetente) e `APP_ENV` (rotulo no assunto). Vazio = so log. `mail()` exige **MTA local** (sendmail/postfix) no servidor; sem ele a falha do envio e registrada no log e o job segue (nunca derruba a extracao). Canal Teams: reservado para a v2.0.

**Fuso do servidor**: o cron usa o horario do servidor. Confira com `timedatectl`; se estiver em UTC, `0 23` roda as 20:00 em Brasilia (ou ajuste o fuso do servidor para `America/Sao_Paulo`).

**logrotate**: use `deploy/logrotate/metricas_jca` (cobre todos os logs do app); instalacao e simulacao na secao 8c.

**Windows (dev)** com o Agendador de Tarefas:
```powershell
schtasks /Create /TN "MetricasJCA-Extracao" /SC DAILY /ST 23:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\dashboard\bin\extract_diaria.php"
schtasks /Create /TN "MetricasJCA-Verificacao" /SC DAILY /ST 23:30 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\dashboard\bin\extract_check.php"
```

**Migracao `origem_pi`**: bancos criados antes desta versao precisam da coluna `extracao_jira.origem_pi` (`VARCHAR(10) NULL`). Aplique `deploy/sql/04_migracao_origem_pi.sql` (idempotente, conta com `ALTER`) **antes** da primeira extracao; quem aplicar o novo `01_schema.sql` ja tem a coluna. Linhas antigas ficam `NULL` (carga anterior ao fallback). As views nao mudam. Ordem em producao: `01_schema.sql` (ou `04_`) -> dump de dados -> `02_views.sql` -> `03_triggers.sql`.

**Classes novas** (prefixo `srv`, namespace `App\Srv`, pasta `app/Srv/`, mapeadas pelo mesmo autoload `App\X\Y` -> `app/X/Y.php`; nomes de arquivo = nome da classe, sensiveis a maiusculas no Linux).

## 8c. Operacao e contencao de disco
Contexto: na noite de 15/11 a particao `/var` (4 GB) encheu (syslog e journal inundados por um agente de terceiros). O MySQL com `log_bin` ligado ESPERA espaco livre para gravar o binlog em vez de falhar ("Disk is full writing './binlog.000798'... Waiting for someone to free space") e travou o job noturno por ~17 h; o `flock -n` fez os demais workers serem ignorados. As salvaguardas abaixo detectam a repeticao ANTES de travar e tornam o problema visivel rapido.

**A. Pre-voo de disco (`extract_diaria.php`)**: antes do lock, da ordem e de qualquer acesso ao banco, mede os caminhos de `config/extracao.php` -> `disco.caminhos`. Se o livre de algum for menor que `disco.min_livre_mb`, NAO executa, registra no log, notifica (log + e-mail) e sai com **codigo 5**. `--ignorar-disco` forca a execucao (registra "IGNORADO"). Com `--dry-run` so avisa que o pre-voo bloquearia. Caminho inexistente (Windows/dev) e ignorado com aviso.

**B. Verificador (`extract_check.php`, 23:30)**: mantem todos os alertas anteriores e acrescenta (sempre SOMENTE leitura no banco):
| Alerta | Chave de `config/extracao.php` | Padrao |
|--------|--------------------------------|--------|
| Ordem `processando` sem atualizacao ha mais de N min ("possivel travamento no banco ou no disco"; mostra id, `atualizado_em` e minutos) | `checagem_processando_min` | 30 |
| Ordem `pendente` do job noturno vencida ha mais de N min alem do `agendado_para` (nenhum worker a pegou) | `checagem_pendente_venc_min` | 15 |
| Disco: livre < `min_livre_mb` OU livre% < `min_livre_pct` (mostra caminho, MB e %) | `disco.caminhos`, `disco.min_livre_mb`, `disco.min_livre_pct` | `['/var']`, 500, 10 |
Sem conexao com o banco: registra, notifica e sai com 4. Todo alerta vai para o log, `data/notificacoes.log` e e-mail (`mail()`, se houver MTA). Exit 1 em qualquer alerta. Limite conhecido: se a PI estiver invalida (exit 2) o verificador nao chega a medir o disco.
*Interacao do alerta de `pendente`*: a ordem do job nasce com `agendado_para` = criacao + 60 min (para o worker de 1 min nao pega-la antes do claim local). As 23:30 ela ainda esta ~30 min no futuro, entao o alerta "vencida" NAO dispara e quem acusa e o alerta antigo "A ordem #X do job noturno ainda esta 'pendente'". A partir de 00:15 os dois disparam (mensagens diferentes, sem deduplicacao). O alerta novo cobre verificacoes tardias/manuais e ordens de dias anteriores esquecidas.

**C. Progresso por jornada** em `data/logs/extracao_diaria.log` (cada linha com hora; stdout com flush). Exemplo:
```
[2026-11-16 23:00:03] Jornada Secretaria / PI 4/26: buscando no Jira
[2026-11-16 23:03:41] Jornada Secretaria / PI 4/26: buscou 637 itens em 218.4s; gravando
[2026-11-16 23:03:44]   OK   Secretaria / PI 4/26: 637 itens, 12 apagados
```
Uma jornada "buscando" sem a linha "buscou" por muito tempo indica Jira lento; "buscou" sem "OK" indica gravacao travada (banco/disco).

**Codigos de saida** (`limpeza_var.sh`): 0 nada a fazer (inclui dry-run) | 1 acao executada | 2 erro | 3 ja em execucao. Os dos demais scripts estao na tabela da secao 8b.

**D. logrotate** (`deploy/logrotate/metricas_jca`: `/apps/metricas_jca/data/logs/*.log` e `data/notificacoes.log`; `su www-data www-data`, semanal, `maxsize 20M`, 8 rotacoes, `compress`/`delaycompress`, `copytruncate` pois os escritores mantem o arquivo aberto; o custo do `copytruncate` e poder perder linhas entre a copia e o truncamento):
```bash
sudo cp /apps/metricas_jca/deploy/logrotate/metricas_jca /etc/logrotate.d/metricas_jca
sudo chown root:root /etc/logrotate.d/metricas_jca ; sudo chmod 644 /etc/logrotate.d/metricas_jca
sudo logrotate -d /etc/logrotate.conf 2>&1 | grep -A12 metricas_jca    # simulacao (-d = debug, nao gira nada)
ls -la /apps/metricas_jca/data/logs/
```
Simule com o `logrotate.conf` completo, NAO com `logrotate -d /etc/logrotate.d/metricas_jca` sozinho: o snippet isolado da um falso "insecure permissions" porque falta o `su root syslog` global do `logrotate.conf`. Para girar de verdade uma vez: `sudo logrotate -f /etc/logrotate.conf` (gira tambem os logs do sistema).

**E. Contencao do /var (`deploy/cron/limpeza_var.sh` + `deploy/cron/metricas_jca_limpeza.cron`)**: paliativo (nao resolve o agente que inunda o log). Roda como root a cada 30 min. Por padrao e DRY-RUN (so mostra); `--executar` aplica. Niveis pelo uso % de `/var`:
| Nivel | Uso de /var | Acao |
|-------|-------------|------|
| 1 | >= 80% | so registra "atencao" |
| 2 | >= 88% | `journalctl --vacuum-size=50M`; `apt-get clean`; remove SOMENTE `syslog.N` e `syslog.N.gz` rotacionados com mais de 3 dias em `/var/log` e copias `syslog_AAAAMMDD_HHMMSS.log` com mais de 7 dias em `/apps/_diag` |
| 3 | >= 94% | alem do nivel 2: se `/apps` tiver >= 5 GB livres (cria `/apps/_diag` com `chmod 700` se faltar), copia `/var/log/syslog` para `/apps/_diag/syslog_AAAAMMDD_HHMMSS.log`, confere a copia (`cp` ok e tamanho igual) e so entao `truncate -s 0`. Se a copia falhar ou divergir, NAO trunca e registra erro (exit 2) |
Nunca toca em `/var/lib/mysql`, binlogs, `/var/log/mysql`, `/var/log/apache2`, `/var/lib/guardicore`, logs de terceiros (`gc-*`) nem em nada fora dessas listas; nao usa `rm -rf`. Limitacao do nivel 3: linhas gravadas entre a copia e o truncate se perdem. Parametros por variavel de ambiente (`LIMPEZA_NIVEL1_PCT`, `LIMPEZA_NIVEL2_PCT`, `LIMPEZA_NIVEL3_PCT`, `LIMPEZA_LOG`, `LIMPEZA_DIAG`, `LIMPEZA_APPS_MIN_GB` etc.; veja `--help`). Log: `/apps/metricas_jca/data/logs/limpeza_var.log` (dono `www-data` para o logrotate conseguir truncar).
```bash
sudo chmod 755 /apps/metricas_jca/deploy/cron/limpeza_var.sh
sudo /apps/metricas_jca/deploy/cron/limpeza_var.sh --help
sudo /apps/metricas_jca/deploy/cron/limpeza_var.sh            # DRY-RUN: confira o que ele faria
sudo cp /apps/metricas_jca/deploy/cron/metricas_jca_limpeza.cron /etc/cron.d/metricas_jca_limpeza
sudo chown root:root /etc/cron.d/metricas_jca_limpeza ; sudo chmod 644 /etc/cron.d/metricas_jca_limpeza
tail -n 20 /apps/metricas_jca/data/logs/limpeza_var.log       # apos 30 min
```
O arquivo em `/etc/cron.d` nao pode ter ponto no nome (por isso sem `.cron`) e leva o campo de usuario `root`. O servidor esta em UTC e a cada 30 min nao depende do fuso.

**F. Vhost de teste por IP:porta (sem DNS)** (`deploy/apache/metricas-teste-ip.conf`), usado ate o DNS de `metricas.cogna.com.br` existir:
- HTTP `:8088` (usuarios): telas de leitura; rotas que gravam ou disparam algo devolvem 403 antes do PHP.
- HTTPS `:8443` (administrador): as mesmas rotas pedem Basic Auth; certificado autoassinado do Ubuntu (`ssl-cert-snakeoil`).
- Bloqueadas: `/painel`, `/baseline/*`, `/capacity/*`, `/settings*`, `/extracao/*` e `/metrics` (confirmado em `public/index.php`, `public/views` e `assets/js`: so a pagina `/painel` e o modal "Extrair" as usam; as telas publicas usam `/filtros`, `/graficos/*` e `/extract` simples). `/extract` NAO e bloqueada por inteiro porque `/metricas/epicos` e `/metricas/sustentacao` leem o cache por `GET /extract`; qualquer metodo diferente de GET/HEAD ou qualquer query string (`force`, `jql`, `sprints`, inclusive codificados) e bloqueada no HTTP e pede senha no HTTPS (sem isso dispararia o Jira).
```bash
sudo a2enmod ssl rewrite headers        # auth_basic, authn_file e authz_user ja vem habilitados
sudo htpasswd -c /etc/apache2/.htpasswd_metricas <usuario>
sudo chown root:www-data /etc/apache2/.htpasswd_metricas ; sudo chmod 640 /etc/apache2/.htpasswd_metricas
sudo ufw status                          # se ativo: liberar 8088 e 8443 SO da sub-rede da VPN
sudo ufw allow from <sub-rede-vpn> to any port 8088 proto tcp
sudo ufw allow from <sub-rede-vpn> to any port 8443 proto tcp
sudo cp /apps/metricas_jca/deploy/apache/metricas-teste-ip.conf /etc/apache2/sites-available/
sudo a2ensite metricas-teste-ip
sudo apache2ctl configtest
sudo systemctl restart apache2           # 1a vez: o Listen novo exige restart; depois, reload basta
```
Bateria de testes por `curl` (ajuste `<ip>`; HTTPS com `-k`; `-w "%{http_code}"`):
| Chamada | Esperado |
|---------|----------|
| `http://<ip>:8088/`, `/filtros`, `/graficos/velocidade?jornada=...&versao=...`, `/extract` | 200 |
| `http://<ip>:8088/painel`, `/settings`, `/baseline/list`, `/capacity/plan`, `/metrics`, `/extracao/catalogo`, `/extracao/fila` | 403 |
| `curl -X POST http://<ip>:8088/extracao/agendar`; `POST /extract`; `GET /extract?force=1` | 403 |
| `https://<ip>:8443/painel`, `/extracao/catalogo`, `/settings` sem credencial | 401 |
| `https://<ip>:8443/painel` com `-u <usuario>:<senha>` | 200 |
| `https://<ip>:8443/` e `/filtros` sem credencial | 200 |
URLs: usuarios `http://<ip>:8088/`; administrador `https://<ip>:8443/painel`. Quando o DNS existir: `sudo a2dissite metricas-teste-ip`, remover os `Listen 8088/8443` (o arquivo todo) e ativar o vhost definitivo. Observacao: o vhost ja foi provado num httpd de teste (Apache 2.4.58 do XAMPP, portas de teste); no Apache 2.4.41 do servidor ainda nao.

**Verificacao rapida de disco**:
```bash
df -h /var /apps
ls -la /apps/metricas_jca/data/logs/ /apps/_diag
journalctl --disk-usage
sudo du -xh --max-depth=1 /var | sort -h | tail
```
## 9. Smoke test

Navegador (VPN) e `curl -I`:
1. `http://metricas.cogna.com.br/` responde 301 para `https://metricas.cogna.com.br/`.
2. `https://metricas.cogna.com.br/` 200; no HTML, links `/assets/...` e `/metricas/...` (sem `/dashboard`) e `window.APP_BASE = ""`.
3. `/assets/vendor/chart.umd.min.js` e `/assets/css/app.css` 200.
4. `/metricas/velocidade?jornada=Secretaria%20e%20Gestor%20de%20Unidades&versao=PI%204/26` renderiza o grafico.
5. `/graficos/velocidade?jornada=Secretaria%20e%20Gestor%20de%20Unidades&versao=PI%204/26`: totais 607/637/592 (escopo inicial/final/concluido), media 197,33 (dump de 13/11/2026; muda com novos dados e com a data). `/graficos/previsibilidade?...` 458 comprometidos, 329 entregues, 71,8%. `/graficos/plan-real?...` deve responder 200 (e o canario da collation das views: 500 com `Illegal mix of collations` indica views criadas em `utf8mb4_0900_ai_ci`, ver secao 4).
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
- Salvaguardas operacionais (secao 8c) NAO executadas no Ubuntu: `limpeza_var.sh` (testado so em dry-run e em pastas temporarias no Git Bash, sem `flock`, `journalctl` nem `apt-get`), `cron.d`, `logrotate` (sem `logrotate` na maquina de desenvolvimento; validado por leitura) e o vhost `metricas-teste-ip.conf` (provado no Apache 2.4.58 do XAMPP, nao no 2.4.41 do servidor). `mail()` com MTA real tambem nao foi testado.
- PHP 7.4: testado localmente com o PHP 7.4.33 (lint de todos os .php, boot via servidor embutido e comparacao das respostas HTTP com o PHP 8.2.12). Nao testado: o PHP 7.4.3-4ubuntu2.28 real do servidor, Apache com mod_php/php-fpm e o worker (`bin/queue_worker.php`) com chamadas reais ao Jira em 7.4.
- Deploy automatizado (secao 12): testado so localmente (`deploy\teste_deploy.ps1`, Git Bash 5.3 contra arvore falsa, ssh/scp substituidos por stubs). Donos e modos sao testados com stubs de `chown`/`chmod` (registram a chamada; `chown` pode simular falha). NAO testado: execucao real contra o servidor, prompts de senha, `sudo`/`chown` reais, restauracao real de dono e modo pelo `tar -xzp` como root (NTFS nao guarda modo Unix), `flock` real (o Git Bash nao tem; o teste usa o lock por diretorio), conferencia de dono/modo do script (`stat`, `chmod 600`) com outro usuario local e com o scp real (no teste, `stat`/`chmod` e o link sao simulados por stubs; o NTFS nao guarda modo Unix), `df` real do `/tmp` do servidor, `curl` contra o vhost real, Ubuntu 20.04/bash 5.0/GNU tar 1.30/PHP 7.4 reais.

## 12. Deploy automatizado

Substitui a copia manual (secao 2). Dois scripts: `deploy\deploy.ps1` (Windows) monta o pacote e chama `deploy/remoto/aplicar_release.sh` (servidor, via `sudo`).

### Pre-requisitos

- Windows: PowerShell 5.1, `git`, `ssh` e `scp` do OpenSSH do Windows, PHP em `C:\xampp\php\php.exe` (ou `JCA_PHP`). Opcional: PHP 7.4 de teste em `%TEMP%\php74\php.exe` (usado por `deploy\lint_php74.ps1`).
- Servidor: usuario com `sudo`, `unzip`, `curl`, `tar`, `flock` (util-linux), `php` 7.4 e `/apps` com espaco livre (o script exige 1 GB livres em `/apps/_deploy`).
- O deploy envia o COMMIT da referencia (padrao `HEAD`), nao o working tree. Faca commit antes. Os arquivos de deploy precisam estar commitados.

### Primeira vez

1. `Copy-Item deploy\deploy.local.ps1.exemplo deploy\deploy.local.ps1` e preencha `$JcaDeployAlvo = 'usuario@servidor'` (arquivo ignorado pelo git). Alternativas: `-Alvo` ou a variavel de ambiente `JCA_DEPLOY_ALVO`.
2. `/apps/_deploy` (tmp, backups e `deploy.log`; dono `root:root`, modo 750) e criado pelo proprio script na primeira execucao, pois ele roda com `sudo`. Se preferir criar antes: `sudo install -d -m 750 -o root -g root /apps/_deploy /apps/_deploy/tmp /apps/_deploy/backups`. Nada e gravado em `/var` nem em `/tmp`, exceto o zip e o script, que passam por `/tmp` com nome aleatorio (GUID) e sao apagados no fim. Se o `ssh` cair antes do fim, eles ficam em `/tmp/jca_aplicar_<guid>.sh` e `/tmp/jca_release_*_<guid>.zip`; apague a mao.
3. Faca primeiro um `-DryRun` (abaixo).

### Uso diario

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File deploy\deploy.ps1
```

Fluxo: confere working tree e `origin/main` (avisos), lint PHP local do pacote, monta o zip (`git archive` com `core.autocrlf=false`, exclusoes de `deploy/excluir.txt` e `data/` inteiro), verifica CRLF e segredos, calcula o sha256 do zip e do script remoto, pede confirmacao e conecta tres vezes: 1 `ssh` curto que mede o espaco livre de `/tmp` (aborta com codigo 10, antes do envio, se nao couber zip + script + 1 MB; `-SemChecagemEspaco` pula), 1 `scp` (zip + script) e 1 `ssh -t`. Neste ultimo, ANTES do `sudo`, o servidor confere que o script (e o zip) existe, nao e link simbolico, e arquivo regular e pertence ao usuario do ssh; so entao o proprio usuario aplica `chmod 600` (o `scp` cria o arquivo com o modo local filtrado pelo umask do servidor, tipicamente 664, o que a conferencia anterior reprovava) e o modo e conferido (tem de ser 600, sem escrita de grupo/outros); por fim o sha256 do script tem de ser o calculado no Windows (passado como argumento do ssh, canal separado do `scp`). Em `/tmp` (sticky) um arquivo 600 do usuario nao pode ser trocado por outros entre a conferencia e o `sudo bash`, por isso nao ha copia intermediaria e nada e criado em `/apps`. Se algo nao bater, nada e executado (codigo 22) e a mensagem diz qual condicao falhou: `script remoto ausente`, `e link simbolico`, `nao e arquivo regular`, `dono do script remoto X, esperado Y`, `chmod 600 falhou ... modo atual M`, `modo do script remoto M, esperado 600 apos chmod` ou `hash do script remoto obtido <12 hex> esperado <12 hex>`. Quando o modo original nao era 600, sai um `AVISO: modo original ... era 664, ajustado para 600` (informativo). Com senha, o `ssh` pede a senha a cada conexao e depois a do `sudo`; com chave, so a do `sudo`.

Parametros: `-Alvo`, `-DryRun`, `-Rollback`, `-Timestamp AAAAMMDD-HHMMSS`, `-Referencia` (commit, tag ou branch), `-SemTestes` (pula so o lint local), `-Forcar` (aceita working tree sujo), `-LimparCache`, `-ManterCache`, `-SomentePacote` (monta e verifica, nao conecta), `-Sim` (sem confirmacao), `-SemChecagemEspaco`, `-Saida`, `-SmokeUrl`, `-SmokeHost`.

### Dry-run

`deploy\deploy.ps1 -DryRun` faz tudo local e, no servidor, so lista o que seria modificado (`modificado`), criado (`novo`) ou ignorado (`ignorado`). No servidor ele nao cria nada em `/apps/metricas_jca` nem em `/apps/_deploy` (sem `mkdir`, sem lock, sem backup, sem log). O unico efeito e um diretorio temporario `run.XXXXXX` ao lado do zip em `/tmp` (no `-Rollback -DryRun`, em `$TMPDIR` ou `/tmp`) para extrair o pacote, removido ao sair, alem do proprio zip e script enviados (apagados no fim). Sem lock, um dry-run pode rodar ao mesmo tempo que um deploy; a lista reflete o momento da leitura.

### O que o servidor faz

Confere o sha256 antes de extrair; extrai em `/apps/_deploy/tmp`; recusa pacote com `config/db.php`, `config/secrets.local.php`, `data/`, `.git`, `.kiro`, `.agents`, caminho com `..` ou CR em `.sh`/`deploy/`; confere se ha espaco para extrair (2x o tamanho descompactado + 10 MB); so aplica `app bin config public deploy db` e lista pelo nome (`ignorado`, ate 50, com `[AVISO]`) os arquivos do pacote fora dessas pastas, como `.htaccess` da raiz; compara byte a byte e ignora arquivos identicos (idempotente); cria backup so dos arquivos que serao sobrescritos em `/apps/_deploy/backups/<AAAAMMDD-HHMMSS>.tar.gz` (guarda os ultimos 10; manifesto interno com arquivos modificados `M`, novos `N` e diretorios criados `D`); copia; ajusta dono `root:www-data` e modo (755 para diretorios novos e `.sh`, 644 para os demais) so no que tocou; garante `data/cache` como `www-data`; cada `chown`/`chmod` que falhar gera um `[AVISO]` com o nome do arquivo e uma linha no `deploy.log`; `php -l` em cada `.php` aplicado; limpa o cache (abaixo); smoke test (`/filtros` e `/graficos/produtividade` devem dar 200 em `http://localhost`; ajuste com `-SmokeUrl`/`-SmokeHost`). Se o lint ou o smoke falhar, restaura o backup sozinho (rollback automatico). Um `flock` impede duas execucoes ao mesmo tempo.

### Rollback

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Rollback                       # backup mais recente
powershell -NoProfile -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Rollback -Timestamp 20261117-193000
```

Restaura os arquivos modificados, apaga os criados pelo deploy e remove os diretorios novos que ficaram vazios, depois roda lint e smoke. Antes disso cria um backup "pre-rollback" (e entra na contagem dos 10), que permite desfazer o rollback. `-DryRun -Rollback` so lista.

### Codigos de saida

Remoto (`aplicar_release.sh`): 0 ok ou nada a aplicar; 1 erro inesperado; 2 argumento, hash, caminho ou conteudo invalido (nada alterado); 3 lint ou smoke falhou e o rollback automatico concluiu; 4 rollback falhou (intervencao manual; o backup fica preservado); 5 outra execucao em andamento; 6 deploy (ou rollback) concluido e validado, mas algum `chown`/`chmod` falhou. O `deploy.ps1` devolve `20 + codigo remoto`, 10 se o `/tmp` do servidor nao tiver espaco ou o `scp` falhar, 1 para erro local e 2 para uso invalido ou cancelamento.

### Decisao sobre falhas de dono/permissao

Uma falha de `chown`/`chmod` nao dispara rollback: o lint e o smoke ja validaram o codigo, e reverter por causa do dono trocaria um problema pequeno por outro deploy. O script imprime `[AVISO]` por arquivo, um aviso final destacado com o total e termina com **codigo 6** (`deploy.ps1`: 26), para nao parecer sucesso limpo. Corrija com `sudo chown root:www-data <arquivo>` e `sudo chmod 644 <arquivo>` (755 para `.sh` e diretorios) e confira com `ls -l`.

### Decisao sobre o cache da Visao Geral

O cache (`data/cache/*.vgc`) ja se invalida sozinho: `GraficosController::ARQUIVOS_VERSAO` lista os arquivos cujo `md5_file` entra na chave, e ha TTL de 900 s. Mesmo assim, o risco de um arquivo de `app/`, `config/` ou `db/` fora dessa lista alterar o resultado deixaria dados velhos por ate 15 min. **Decisao: o deploy executa `sudo -u www-data php bin/limpar_cache.php` quando algum arquivo aplicado esta em `app/`, `config/` ou `db/`**, depois do lint e antes do smoke. Custo: a primeira requisicao fica fria (~1,8 s) ate o cron `aquecer_cache.php` reaquecer. Falha na limpeza so gera aviso. `-LimparCache` forca; `-ManterCache` desliga.

### O que o deploy NAO faz

- Migracoes de banco (`deploy/sql/*`, `db/*`): aplique manualmente.
- Cron (`/etc/cron.d`, crontab do `www-data`), logrotate, vhost Apache e modulos.
- `config/db.php`, `config/secrets.local.php` e qualquer coisa em `data/` (dados, logs, ordens, cache, JSONs do Painel). Arquivos versionados novos em `data/` (ex.: `data/baselines/*.json`) precisam de copia manual.
- Remover no servidor arquivos apagados do repositorio (nao ha `--delete`, por seguranca).
- Reiniciar o Apache (mod_php le os `.php` a cada requisicao; mudanca de `php.ini` ou de modulo e manual).

### Mudanca de schema

1. Aplique `deploy/sql/*` (ou o script de migracao) ANTES, com a conta DBA, em horario sem extracao.
2. Faca o deploy do codigo depois.
3. O rollback do codigo NAO desfaz o banco. Planeje migracoes compativeis com o codigo antigo (adicione colunas/views sem remover as antigas) para que o rollback continue valido.

### Teste local

`powershell -NoProfile -ExecutionPolicy Bypass -File deploy\teste_deploy.ps1` (opcional `-Grupo A` ou `-Grupo B`). Grupo A: pacote real, CRLF, segredos, ssh/scp falsos (nomes com GUID, hash do script no comando, medicao de `/tmp`) e o comando remoto de conferencia do script executado no Git Bash (hash certo executa; A8: modo 664/666 e corrigido para 600; ausente, link, dono, falha de chmod, modo e hash errado nao executam e cada um diz qual condicao falhou). Grupo B: `aplicar_release.sh` no Git Bash contra uma arvore falsa em `%TEMP%` (dry-run sem criar nada, deploy, nomes ignorados, idempotencia, rollback automatico e manual, hash errado, lock, poda, caminhos perigosos, smoke com servidor PHP embutido, donos e modos com stubs de `chown`/`chmod`, falha de `chown` com saida 6, backup so com arquivos novos, espaco insuficiente). Limitacao: o Git Bash nao tem `flock` nem `sudo` reais e o NTFS nao guarda dono/modo Unix; o teste usa o lock por diretorio, `PULAR_SUDO` e stubs. Sem bash, o grupo B e pulado e o script sai com codigo 3 (nao e sucesso total).

## 13. PROXIMO PASSO: GitHub Actions com runner self-hosted

Somente plano; nada disto esta implementado nem ativo.

1. Instalar um runner self-hosted Windows numa maquina da rede com acesso ssh ao servidor.
2. Gerar um par de chaves SSH sem senha para o runner, autorizar a chave publica no usuario de deploy e guardar o alvo (`usuario@servidor`) em segredo do repositorio (`JCA_DEPLOY_ALVO`). Nenhum host ou usuario vai para o YAML.
3. Tornar o `sudo` nao interativo de forma restrita: instalar `aplicar_release.sh` em `/apps/_deploy/bin/` com dono `root` e uma regra de sudoers que permita so esse script (nunca `NOPASSWD` curinga sobre `/tmp`). Ajustar `deploy.ps1` para enviar o pacote para um diretorio e chamar o script fixo. O fluxo manual atual reduz o risco do `/tmp` (nome GUID e conferencia de tipo, dono e sha256 do script antes do `sudo`, secao 12), mas isso nao substitui o script fixo de dono `root` para um `sudo` nao interativo.
4. Workflow manual (`workflow_dispatch`) com entradas `referencia` e `dry_run`, executando `deploy/deploy.ps1 -Sim`. Esboco em `deploy/ci/deploy.yml.exemplo` (NAO copiar para `.github/workflows` antes de cumprir os itens acima).
5. Proteger o environment `homologacao` com aprovacao manual e restringir quem pode disparar o workflow.

## 14. Padronizacao e hook de pre-commit

Padrao do repositorio: texto em UTF-8 sem BOM, fim de linha LF, regras em `.editorconfig` e `.gitattributes` (`* text=auto eol=lf`; binarios marcados como `binary`).

Ativar o hook (uma vez por clone):

```
git config core.hooksPath .githooks
```

Rodar os testes (cada `bin/teste_*.php` num processo PHP separado; exit 0 so se todos passarem):

```
php bin/teste_todos.php --lista      # mostra os testes
php bin/teste_todos.php --rapido     # = --sem-banco, so testes sem gravacao no projeto
php bin/teste_todos.php --sem-banco
php bin/teste_todos.php              # usa o banco local (so SELECT)
php bin/teste_todos.php --job-noturno   # inclui teste_job_noturno (exige mysqld descartavel)
```

O hook `.githooks/pre-commit` olha so os arquivos staged e bloqueia o commit quando:

- um `.php` tem erro de sintaxe (`php -l`; usa `JCA_PHP`, depois `php` no PATH, depois `C:/xampp/php/php.exe`);
- o conteudo tem padrao de segredo (token Atlassian, chave privada, senha ou token literal). A mensagem mostra arquivo e linha, nunca o valor;
- entra um arquivo que nao deve ser versionado: `config/db.php`, `config/secrets.local.php`, `deploy/deploy.local.ps1`, `.kiro/settings/mcp.json` ou `data/config/*.json` com cara de credencial;
- ha `.php` em `app/`, `bin/`, `config/` ou `public/` e `bin/teste_todos.php --rapido` falha.

O hook so avisa (sem bloquear) quando o conteudo staged tem CRLF. `JCA_PULAR_TESTES=1` pula so os testes. Para ignorar o hook inteiro, de forma consciente: `git commit --no-verify`.

No Linux/macOS o hook precisa de permissao de execucao (`git update-index --chmod=+x .githooks/pre-commit`). O Git for Windows executa o hook sem esse bit.
