<#
.SYNOPSIS
  Gera o dump SOMENTE DE DADOS do banco local para levar a producao.

.DESCRIPTION
  Saida: data\deploy\dados_<aaaammdd>.sql  (pasta ignorada pelo git - contem dados reais).

  TABELAS INCLUIDAS (dados de negocio, na ordem de dependencia das FKs):
    configuracao_pi, jornada, times, times_composicao, sprints,
    extracao_jira, baseline, baseline_ajuste,
    produtividade_historico, sustentacao_historico
    - baseline_ajuste entra para PRESERVAR a trilha de auditoria da baseline
      (a tabela e append-only: "nunca apagar linhas").
  TABELAS EXCLUIDAS (operacionais, nascem vazias em producao):
    extracao_agendamento  -> fila de extracao (ordens pendentes/historico local)
    extracao_log          -> log de execucao da fila (depende da fila)
  VIEWS (vw_*) nao sao dumpadas: sao recriadas por deploy\sql\02_views.sql.
  TRIGGERS ficam de fora (--skip-triggers): sao criados por deploy\sql\03_triggers.sql.

  ORDEM CORRETA EM PRODUCAO:
    01_schema.sql  ->  importar ESTE dump  ->  02_views.sql  ->  03_triggers.sql
  (o trigger de auditoria e AFTER UPDATE em baseline; importar antes dele garante
   que nenhuma linha falsa seja gerada em baseline_ajuste).

  SENHA: nunca fica no script. Informe por -Senha, pela variavel de ambiente
  METRICAS_DB_PASS ou digite quando solicitado. A senha e passada ao mysqldump
  por um arquivo de opcoes temporario, apagado ao final.

  BINARIO: o mysqldump do XAMPP (C:\xampp\mysql\bin) e MariaDB 10.4 e nao
  autentica contas caching_sha2_password do MySQL 8.4 local. Por isso o padrao
  e o mysqldump do MySQL Server 8.4; se nao existir, usa o do XAMPP. Use
  -MysqlDump para forcar um caminho.

.EXAMPLE
  $env:METRICAS_DB_PASS = '<senha da conta de aplicacao local>'; .\deploy\dump_dados.ps1
#>
[CmdletBinding()]
param(
    [string]$Usuario   = 'metricas_jca',
    [string]$Senha     = $env:METRICAS_DB_PASS,
    [string]$Banco     = 'metricas_agil',
    [string]$DbHost    = '127.0.0.1',
    [int]   $Porta     = 3306,
    [string]$MysqlDump = ''
)

$ErrorActionPreference = 'Stop'

$raiz = Split-Path -Parent $PSScriptRoot
$tabelas = @(
    'configuracao_pi', 'jornada', 'times', 'times_composicao', 'sprints',
    'extracao_jira', 'baseline', 'baseline_ajuste',
    'produtividade_historico', 'sustentacao_historico'
)

# --- binario do mysqldump ---------------------------------------------------
if (-not $MysqlDump) {
    $candidatos = @(
        'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe',
        'C:\xampp\mysql\bin\mysqldump.exe'
    )
    $MysqlDump = $candidatos | Where-Object { Test-Path $_ } | Select-Object -First 1
}
if (-not $MysqlDump -or -not (Test-Path $MysqlDump)) {
    throw "mysqldump nao encontrado. Informe -MysqlDump <caminho>."
}

# --- senha (nunca hardcoded) --------------------------------------------------
if (-not $Senha) {
    $sec = Read-Host -AsSecureString "Senha da conta '$Usuario' ($DbHost)"
    $Senha = [System.Net.NetworkCredential]::new('', $sec).Password
}

# --- destino ----------------------------------------------------------------
$destinoDir = Join-Path $raiz 'data\deploy'
New-Item -ItemType Directory -Force $destinoDir | Out-Null
$arquivo = Join-Path $destinoDir ("dados_{0}.sql" -f (Get-Date -Format 'yyyyMMdd'))

# --- arquivo de opcoes temporario com a credencial ----------------------------
$opts = Join-Path $env:TEMP ("metricas_dump_{0}.cnf" -f ([guid]::NewGuid().ToString('N')))
$senhaEsc = $Senha.Replace('\', '\\').Replace('"', '\"')
$cnf = "[client]`nuser=$Usuario`npassword=`"$senhaEsc`"`nhost=$DbHost`nport=$Porta`n"
[System.IO.File]::WriteAllText($opts, $cnf, (New-Object System.Text.UTF8Encoding($false)))

try {
    $dumpArgs = @(
        "--defaults-extra-file=$opts",
        '--no-create-info',
        '--skip-triggers',
        '--complete-insert',
        '--default-character-set=utf8mb4',
        '--single-transaction',      # consistencia sem LOCK TABLES (InnoDB)
        '--skip-add-locks',
        '--no-tablespaces',          # nao exige o privilegio PROCESS
        "--result-file=$arquivo",
        $Banco
    ) + $tabelas

    & $MysqlDump @dumpArgs
    if ($LASTEXITCODE -ne 0) { throw "mysqldump falhou (exit code $LASTEXITCODE)." }
}
finally {
    Remove-Item $opts -Force -ErrorAction SilentlyContinue
}

# --- resumo -------------------------------------------------------------------
$f = Get-Item $arquivo
"Arquivo : $($f.FullName)"
"Tamanho : {0:N1} MB ({1} bytes)" -f ($f.Length / 1MB), $f.Length
"Comandos INSERT por tabela (extended-insert: 1 INSERT = varias linhas):"
$contagem = @{}
Select-String -Path $arquivo -Pattern '^INSERT INTO `([a-z_]+)`' -Encoding utf8 |
    ForEach-Object { $contagem[$_.Matches[0].Groups[1].Value]++ }
foreach ($t in $tabelas) { "  {0,-26} {1}" -f $t, ($(if ($contagem.ContainsKey($t)) { $contagem[$t] } else { 0 })) }
