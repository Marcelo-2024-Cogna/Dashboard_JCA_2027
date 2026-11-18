<#
.SYNOPSIS
  Testes locais (sem servidor) do deploy automatizado.
.DESCRIPTION
  Grupo A: empacotador (deploy.ps1) e verificacoes do pacote, com stubs de ssh/scp.
  Grupo B: deploy\remoto\aplicar_release.sh executado com o Git Bash contra uma
           arvore falsa em $env:TEMP (nunca toca no servidor nem no repo real).
  Imprime PASSOU/FALHOU por caso. Sai com 0 so se tudo passou; com 3 se o bash
  nao foi encontrado (casos B pulados: NAO e sucesso total).
.PARAMETER Grupo
  A, B ou Todos (padrao).
.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File deploy\teste_deploy.ps1 -Grupo A
#>
param(
    [ValidateSet('A', 'B', 'Todos')][string]$Grupo = 'Todos'
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Raiz = Split-Path -Parent $PSScriptRoot
$Deploy = Join-Path $PSScriptRoot 'deploy.ps1'
$script:Pass = 0
$script:Fail = 0
$script:Pulados = 0
$script:Utf8 = New-Object System.Text.UTF8Encoding($false)

# ---------------------------------------------------------------- utilitarios
function Afirmar([bool]$Cond, [string]$Msg) { if (-not $Cond) { throw $Msg } }

function Caso([string]$Id, [string]$Nome, [scriptblock]$Corpo, [bool]$PrecisaBash = $false) {
    if ($Grupo -ne 'Todos' -and -not $Id.StartsWith($Grupo)) { return }
    if ($PrecisaBash -and -not $script:Bash) {
        Write-Host ("PULADO  {0} {1} (bash nao encontrado)" -f $Id, $Nome) -ForegroundColor Yellow
        $script:Pulados++
        return
    }
    try {
        & $Corpo
        Write-Host ("PASSOU  {0} {1}" -f $Id, $Nome) -ForegroundColor Green
        $script:Pass++
    } catch {
        Write-Host ("FALHOU  {0} {1}: {2}" -f $Id, $Nome, $_.Exception.Message) -ForegroundColor Red
        $script:Fail++
    }
}

function Write-Texto([string]$Caminho, [string]$Texto) {
    $dir = Split-Path -Parent $Caminho
    if (-not (Test-Path -LiteralPath $dir)) { $null = New-Item -ItemType Directory -Path $dir -Force }
    [System.IO.File]::WriteAllText($Caminho, $Texto, $script:Utf8)
}

function Get-HashArvore([string]$Dir) {
    $base = (Resolve-Path -LiteralPath $Dir).Path.TrimEnd('\')
    $linhas = New-Object System.Collections.Generic.List[string]
    foreach ($i in (Get-ChildItem -LiteralPath $base -Recurse -Force)) {
        $rel = $i.FullName.Substring($base.Length + 1).Replace('\', '/')
        if ($i.PSIsContainer) { $linhas.Add("D $rel") }
        else { $linhas.Add("F $rel " + (Get-FileHash -LiteralPath $i.FullName -Algorithm SHA256).Hash) }
    }
    $arr = $linhas.ToArray()
    [Array]::Sort($arr, [StringComparer]::Ordinal)
    return ($arr -join "`n")
}

function New-ZipFalso([string]$Caminho, $Arquivos) {
    if (Test-Path -LiteralPath $Caminho) { Remove-Item -LiteralPath $Caminho -Force }
    $z = [System.IO.Compression.ZipFile]::Open($Caminho, 'Create')
    try {
        foreach ($nome in $Arquivos.Keys) {
            $e = $z.CreateEntry($nome)
            $s = $e.Open()
            try { $b = $script:Utf8.GetBytes([string]$Arquivos[$nome]); $s.Write($b, 0, $b.Length) } finally { $s.Dispose() }
        }
    } finally { $z.Dispose() }
}

function Get-ZipNomes([string]$Zip) {
    $z = [System.IO.Compression.ZipFile]::OpenRead($Zip)
    try { return @($z.Entries | ForEach-Object { $_.FullName }) } finally { $z.Dispose() }
}

function Get-ZipBytes([string]$Zip, [string]$Nome) {
    $z = [System.IO.Compression.ZipFile]::OpenRead($Zip)
    try {
        $e = $z.GetEntry($Nome)
        $ms = New-Object System.IO.MemoryStream
        $s = $e.Open(); try { $s.CopyTo($ms) } finally { $s.Dispose() }
        return $ms.ToArray()
    } finally { $z.Dispose() }
}

function Get-Sha([string]$Arq) { (Get-FileHash -LiteralPath $Arq -Algorithm SHA256).Hash.ToLowerInvariant() }

function Remove-Seguro([string]$Caminho) {
    if ($Caminho -and $Caminho.StartsWith($script:TempBase, [System.StringComparison]::OrdinalIgnoreCase) -and
        (Split-Path -Leaf $Caminho) -like 'jca_teste_*') {
        Remove-Item -LiteralPath $Caminho -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# Roda um powershell filho (para ter exit code e PATH proprios).
function Invoke-Ps([string[]]$Argumentos, [hashtable]$Env = @{}) {
    $salvo = @{}
    foreach ($k in $Env.Keys) { $salvo[$k] = [Environment]::GetEnvironmentVariable($k); [Environment]::SetEnvironmentVariable($k, $Env[$k]) }
    try {
        $out = & powershell -NoProfile -ExecutionPolicy Bypass -File @Argumentos 2>&1
        $rc = $LASTEXITCODE
    } finally {
        foreach ($k in $Env.Keys) { [Environment]::SetEnvironmentVariable($k, $salvo[$k]) }
    }
    return [pscustomobject]@{ Codigo = $rc; Saida = (($out | ForEach-Object { "$_" }) -join "`n"); Linhas = @($out | ForEach-Object { "$_" }) }
}

# ---------------------------------------------------------------- ambiente
$script:TempBase = [Environment]::GetEnvironmentVariable('TEMP')
$T = Join-Path $script:TempBase ('jca_teste_' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$null = New-Item -ItemType Directory -Path $T -Force

function Find-Bash {
    $cands = New-Object System.Collections.Generic.List[string]
    if ($env:JCA_BASH) { $cands.Add($env:JCA_BASH) }
    $g = Get-Command git -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($g) { $cands.Add((Join-Path (Split-Path -Parent (Split-Path -Parent $g.Source)) 'bin\bash.exe')) }
    $cands.Add('C:\Program Files\Git\bin\bash.exe')
    $cands.Add('C:\Program Files (x86)\Git\bin\bash.exe')
    foreach ($c in $cands) {
        if ($c -and (Test-Path -LiteralPath $c)) {
            $cyg = Join-Path (Split-Path -Parent (Split-Path -Parent $c)) 'usr\bin\cygpath.exe'
            if (Test-Path -LiteralPath $cyg) { return @{ Bash = $c; Cygpath = $cyg } }
        }
    }
    return $null
}

function ConvertTo-Misto([string]$Caminho) {
    if ($script:Cygpath) { return (& $script:Cygpath -m -l $Caminho) }
    return $Caminho.Replace('\', '/')
}

$b = Find-Bash
$script:Bash = $null; $script:Cygpath = $null
if ($b) { $script:Bash = $b.Bash; $script:Cygpath = $b.Cygpath }
$ShMisto = ConvertTo-Misto (Join-Path $PSScriptRoot 'remoto\aplicar_release.sh')
$PhpLocal = 'C:\xampp\php\php.exe'
if (-not (Test-Path $PhpLocal)) { $PhpLocal = (Get-Command php -ErrorAction SilentlyContinue | Select-Object -First 1).Source }
$PhpMisto = ConvertTo-Misto $PhpLocal

Write-Host "Raiz do repo : $Raiz"
Write-Host "Pasta de teste: $T"
Write-Host ("Bash          : " + $(if ($script:Bash) { $script:Bash } else { 'NAO ENCONTRADO (casos B serao pulados)' }))
Write-Host "PHP           : $PhpLocal"
Write-Host ''

try {

# ================================================================ GRUPO A
# Repo temporario: copia do working tree (tracked + novos nao ignorados), com
# core.autocrlf=true para provar a estrategia anti-CRLF. Nunca altera o repo real.
$RepoT = Join-Path $T 'repo'
if ($Grupo -ne 'B') {
    $null = New-Item -ItemType Directory -Path $RepoT -Force
    # nomes com acento saem do git entre aspas e com escapes octais: decodifica para UTF-8
    $lista = @()
    foreach ($ln in (@(& git -C $Raiz ls-files) + @(& git -C $Raiz ls-files -o --exclude-standard))) {
        $s = "$ln"
        if ($s.StartsWith('"') -and $s.EndsWith('"')) {
            $s = $s.Substring(1, $s.Length - 2)
            $bytes = New-Object System.Collections.Generic.List[byte]
            for ($i = 0; $i -lt $s.Length; $i++) {
                if ($s[$i] -eq '\' -and $i + 3 -lt $s.Length + 0 -and $s.Substring($i + 1, 3) -match '^[0-7]{3}$') {
                    $bytes.Add([Convert]::ToByte($s.Substring($i + 1, 3), 8)); $i += 3
                } elseif ($s[$i] -eq '\' -and $i + 1 -lt $s.Length) {
                    $bytes.Add([byte][char]$s[$i + 1]); $i++
                } else { $bytes.AddRange([System.Text.Encoding]::UTF8.GetBytes([string]$s[$i])) }
            }
            $s = [System.Text.Encoding]::UTF8.GetString($bytes.ToArray())
        }
        $lista += $s
    }
    foreach ($rel in ($lista | Sort-Object -Unique)) {
        $orig = Join-Path $Raiz $rel
        if ([System.IO.File]::Exists($orig)) {
            $dest = Join-Path $RepoT $rel
            $d = Split-Path -Parent $dest
            if (-not (Test-Path -LiteralPath $d)) { $null = New-Item -ItemType Directory -Path $d -Force }
            Copy-Item -LiteralPath $orig -Destination $dest
        }
    }
    # armadilhas versionadas a force: nada disto pode entrar no pacote
    Write-Texto (Join-Path $RepoT 'config\db.php') "<?php // falso`n"
    Write-Texto (Join-Path $RepoT 'config\secrets.local.php') "<?php // falso`n"
    Write-Texto (Join-Path $RepoT 'data\x.json') "{}`n"
    Write-Texto (Join-Path $RepoT '.kiro\x.txt') "x`n"
    Write-Texto (Join-Path $RepoT '.agents\x.txt') "x`n"
    $ea = $ErrorActionPreference
    & git -C $RepoT init -q 2>&1 | Out-Null
    & git -C $RepoT config core.autocrlf true 2>&1 | Out-Null
    & git -C $RepoT add -A 2>&1 | Out-Null
    & git -C $RepoT add -f config/db.php config/secrets.local.php data/x.json .kiro/x.txt .agents/x.txt 2>&1 | Out-Null
    & git -C $RepoT -c user.name=teste -c user.email=teste@example.invalid commit -q -m teste 2>&1 | Out-Null
    $ErrorActionPreference = $ea
}

$PacotesA = Join-Path $T 'pacotesA'
$script:ZipReal = $null
$null = $null

Caso 'A1' 'pacote real: exclusoes e entradas obrigatorias' {
    $r = Invoke-Ps @($Deploy, '-RepoRaiz', $RepoT, '-SomentePacote', '-SemTestes', '-Saida', $PacotesA)
    Afirmar ($r.Codigo -eq 0) ("deploy.ps1 -SomentePacote saiu com " + $r.Codigo + ": " + $r.Saida)
    $zip = $r.Linhas[-1].Trim()
    Afirmar (Test-Path -LiteralPath $zip) "zip nao encontrado: $zip"
    $script:ZipReal = $zip
    $nomes = Get-ZipNomes $zip
    foreach ($proib in 'config/db.php', 'config/secrets.local.php', 'data/x.json', '.kiro/x.txt', '.agents/x.txt',
                       'jql-jira.bash', 'virtualHost.bash', 'bin/seed_catalogo.php', 'bin/seed_sustentacao.php') {
        Afirmar (-not ($nomes -contains $proib)) "pacote contem $proib"
    }
    foreach ($pref in 'data/', '.git/', '.agents/', '.kiro/', 'dashboard-v1.0/', 'Plataforma-Engenharia-Performance/') {
        Afirmar (-not ($nomes | Where-Object { $_.StartsWith($pref) })) "pacote contem entradas em $pref"
    }
    Afirmar ($nomes -contains 'public/index.php') 'falta public/index.php'
    Afirmar ($nomes -contains 'deploy/remoto/aplicar_release.sh') 'falta deploy/remoto/aplicar_release.sh'
    Afirmar (-not ($nomes | Where-Object { $_.Contains('\') })) 'nome com barra invertida'
    Afirmar ($nomes -contains 'public/.htaccess' -or -not (Test-Path (Join-Path $Raiz 'public\.htaccess'))) 'public/.htaccess foi excluido indevidamente'
}

Caso 'A2' 'zero byte CR em deploy/** e *.sh (autocrlf=true no repo temporario)' {
    Afirmar ([bool]$script:ZipReal) 'A1 nao gerou o zip'
    $nomes = Get-ZipNomes $script:ZipReal | Where-Object { -not $_.EndsWith('/') -and ($_.StartsWith('deploy/') -or $_.EndsWith('.sh')) }
    Afirmar (@($nomes).Count -gt 5) 'poucos arquivos de deploy no zip'
    foreach ($n in $nomes) {
        Afirmar (-not ((Get-ZipBytes $script:ZipReal $n) -contains 13)) "CR em $n"
    }
}

Caso 'A3' 'mutacao: .sh com CR e rejeitado' {
    $zip = Join-Path $T 'a3.zip'
    New-ZipFalso $zip ([ordered]@{ 'public/index.php' = "<?php`n"; 'app/x.php' = "<?php`n"; 'config/visao_geral.php' = "<?php`n";
                                   'deploy/remoto/aplicar_release.sh' = "#!/bin/bash`r`necho x`r`n" })
    $p = & { . $Deploy; Test-Pacote $zip }
    Afirmar (@($p | Where-Object { $_ -match 'CR \(CRLF\)' }).Count -ge 1) ('mensagem de CR ausente: ' + ($p -join '|'))
}

Caso 'A4' 'mutacao: config/db.php e token no pacote sao rejeitados' {
    $zip1 = Join-Path $T 'a4a.zip'
    New-ZipFalso $zip1 ([ordered]@{ 'public/index.php' = "<?php`n"; 'app/x.php' = "<?php`n"; 'config/visao_geral.php' = "<?php`n";
                                    'deploy/remoto/aplicar_release.sh' = "#!/bin/bash`n"; 'config/db.php' = "<?php`n" })
    $p1 = & { . $Deploy; Test-Pacote $zip1 }
    Afirmar (@($p1 | Where-Object { $_ -match 'proibido' }).Count -ge 1) 'db.php nao foi rejeitado'
    $zip2 = Join-Path $T 'a4b.zip'
    $tok = 'ATA' + 'TT' + '3xFfGF0abcdefghijkl12345'
    New-ZipFalso $zip2 ([ordered]@{ 'public/index.php' = "<?php`n"; 'app/x.php' = "<?php // $tok`n"; 'config/visao_geral.php' = "<?php`n";
                                    'deploy/remoto/aplicar_release.sh' = "#!/bin/bash`n" })
    $p2 = & { . $Deploy; Test-Pacote $zip2 }
    Afirmar (@($p2 | Where-Object { $_ -match 'segredo' }).Count -ge 1) 'token nao foi detectado'
    Afirmar (-not (($p2 -join '|').Contains($tok))) 'a mensagem vazou o valor do segredo'
    $zip3 = Join-Path $T 'a4c.zip'
    New-ZipFalso $zip3 ([ordered]@{ 'public/index.php' = "<?php`n"; 'app/x.php' = "<?php`n"; 'config/visao_geral.php' = "<?php`n";
                                    'deploy/remoto/aplicar_release.sh' = "#!/bin/bash`n"; 'deploy/k.txt' = ('-----BEGIN RSA PRIV' + "ATE KEY-----`n") })
    $p3 = & { . $Deploy; Test-Pacote $zip3 }
    Afirmar (@($p3 | Where-Object { $_ -match 'segredo' }).Count -ge 1) 'chave privada nao foi detectada'
}

Caso 'A5' 'sem alvo: erro claro e codigo != 0' {
    if (Test-Path (Join-Path $PSScriptRoot 'deploy.local.ps1')) { Write-Host '   (deploy.local.ps1 existe neste computador; caso valido so sem ele)'; return }
    $r = Invoke-Ps @($Deploy, '-RepoRaiz', $RepoT, '-SemTestes') @{ JCA_DEPLOY_ALVO = $null }
    Afirmar ($r.Codigo -ne 0) 'saiu com 0'
    Afirmar ($r.Saida -match '-Alvo' -and $r.Saida -match 'JCA_DEPLOY_ALVO' -and $r.Saida -match 'deploy\.local\.ps1') ('mensagem incompleta: ' + $r.Saida)
}

# stubs de ssh/scp
$Stubs = Join-Path $T 'stubs'
$null = New-Item -ItemType Directory -Path $Stubs -Force
[System.IO.File]::WriteAllText((Join-Path $Stubs 'scp.cmd'), "@echo %*>>`"%JCA_STUB_DIR%\scp.log`"`r`n@exit /b 0`r`n")
[System.IO.File]::WriteAllText((Join-Path $Stubs 'ssh.cmd'), "@echo %*>>`"%JCA_STUB_DIR%\ssh.log`"`r`n@exit /b 0`r`n")

function Invoke-ComStubs([string[]]$Extra) {
    foreach ($l in 'scp.log', 'ssh.log') { Remove-Item (Join-Path $Stubs $l) -ErrorAction SilentlyContinue }
    $args2 = @($Deploy, '-RepoRaiz', $RepoT, '-SemTestes', '-Sim', '-Saida', (Join-Path $T 'pacotesA6')) + $Extra
    $r = Invoke-Ps $args2 @{ JCA_STUB_DIR = $Stubs; PATH = ($Stubs + ';' + $env:PATH) }
    [string[]]$scpLog = if (Test-Path (Join-Path $Stubs 'scp.log')) { @(Get-Content (Join-Path $Stubs 'scp.log')) } else { @() }
    [string[]]$sshLog = if (Test-Path (Join-Path $Stubs 'ssh.log')) { @(Get-Content (Join-Path $Stubs 'ssh.log')) } else { @() }
    return [pscustomobject]@{ R = $r; Scp = $scpLog; Ssh = $sshLog }
}

Caso 'A6a' 'stubs: deploy = 1 scp (2 arquivos) + 1 ssh -t com sudo' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Scp.Count -eq 1) ('chamadas scp: ' + $x.Scp.Count)
    Afirmar ($x.Scp[0] -match 'jca_release_.*\.zip' -and $x.Scp[0] -match 'jca_aplicar_.*\.sh' -and $x.Scp[0] -match 'usuario@servidor:/tmp/') ('scp: ' + $x.Scp[0])
    Afirmar ($x.Ssh.Count -eq 1) ('chamadas ssh: ' + $x.Ssh.Count)
    Afirmar ($x.Ssh[0] -match '(^| )-t ' -and $x.Ssh[0] -match 'sudo bash /tmp/jca_aplicar_') ('ssh: ' + $x.Ssh[0])
    Afirmar (-not ($x.Ssh[0] -match '--dry-run')) 'dry-run indevido'
}

Caso 'A6b' 'stubs: -DryRun repassa --dry-run' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-DryRun')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo)
    Afirmar ($x.Ssh.Count -eq 1 -and $x.Ssh[0] -match '--dry-run') ('ssh: ' + ($x.Ssh -join '|'))
}

Caso 'A6c' 'stubs: -Rollback envia so o script' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-Rollback')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Scp.Count -eq 1 -and $x.Scp[0] -match 'jca_aplicar_' -and -not ($x.Scp[0] -match 'jca_release_')) ('scp: ' + ($x.Scp -join '|'))
    Afirmar ($x.Ssh.Count -eq 1 -and $x.Ssh[0] -match '--rollback') ('ssh: ' + ($x.Ssh -join '|'))
}

Caso 'A6d' 'alvo invalido e recusado sem chamar ssh/scp' {
    $x = Invoke-ComStubs @('-Alvo', 'x; rm -rf')
    Afirmar ($x.R.Codigo -ne 0) 'aceitou alvo invalido'
    Afirmar ($x.Scp.Count -eq 0 -and $x.Ssh.Count -eq 0) 'chamou ssh/scp'
}

# ================================================================ GRUPO B
$EnvBase = @{
    PHP_BIN = $PhpMisto; PULAR_SUDO = '1'; PULAR_CHOWN = '1'; PULAR_SMOKE = '1'; MIN_LIVRE_MB = '1'
    MSYS_NO_PATHCONV = '1'; MSYS2_ENV_CONV_EXCL = 'APP_DIR;DEPLOY_DIR;SMOKE_URL'
}
$script:Seq = 0

function Invoke-Remoto([hashtable]$Env, [string[]]$Argumentos) {
    $todo = @{}
    foreach ($k in $EnvBase.Keys) { $todo[$k] = $EnvBase[$k] }
    foreach ($k in $Env.Keys) { $todo[$k] = $Env[$k] }
    $salvo = @{}
    foreach ($k in $todo.Keys) { $salvo[$k] = [Environment]::GetEnvironmentVariable($k); [Environment]::SetEnvironmentVariable($k, $todo[$k]) }
    try {
        $out = & $script:Bash $ShMisto @Argumentos 2>&1
        $rc = $LASTEXITCODE
    } finally {
        foreach ($k in $todo.Keys) { [Environment]::SetEnvironmentVariable($k, $salvo[$k]) }
    }
    return [pscustomobject]@{ Codigo = $rc; Saida = (($out | ForEach-Object { "$_" }) -join "`n") }
}

# Arvore falsa: devolve App/Deploy (formato C:/...) e os caminhos Windows
function New-Arvore {
    $script:Seq++
    $base = Join-Path $T ("b{0}" -f $script:Seq)
    $app = Join-Path $base 'app_dir'
    $dep = Join-Path $base 'deploy_dir'
    $null = New-Item -ItemType Directory -Path $app, $dep -Force
    Write-Texto "$app\app\a.php" "<?php echo `"velho`";`n"
    Write-Texto "$app\bin\b.php" "<?php echo 'b';`n"
    Write-Texto "$app\bin\limpar_cache.php" "<?php exit(0);`n"
    Write-Texto "$app\config\db.php" "<?php // db falso - nao tocar`n"
    Write-Texto "$app\config\secrets.local.php" "<?php // segredo falso - nao tocar`n"
    Write-Texto "$app\config\visao_geral.php" "<?php return [];`n"
    Write-Texto "$app\data\cache\.gitkeep" ""
    Write-Texto "$app\data\logs\x.log" "log existente`n"
    Write-Texto "$app\data\config\pi.json" "{`"a`":1}`n"
    Write-Texto "$app\public\index.php" "<?php echo 'ok';`n"
    return @{ Base = $base; AppW = $app; DepW = $dep; App = (ConvertTo-Misto $app); Dep = (ConvertTo-Misto $dep) }
}

function New-ZipOk([string]$Caminho, $Extra = @{}) {
    $f = [ordered]@{
        'app/a.php' = "<?php echo `"novo`";`n"
        'app/novo.php' = "<?php echo 2;`n"
        'deploy/remoto/x.sh' = "#!/bin/bash`necho x`n"
        'config/visao_geral.php' = "<?php return [];`n"
        'LICENSE' = "licenca`n"
    }
    foreach ($k in $Extra.Keys) { $f[$k] = $Extra[$k] }
    New-ZipFalso $Caminho $f
    return (Get-Sha $Caminho)
}

function Invoke-Deploy($A, [string]$Zip, [string]$Sha, [string[]]$Opcoes = @(), [hashtable]$Env = @{}) {
    $e = @{ APP_DIR = $A.App; DEPLOY_DIR = $A.Dep }
    foreach ($k in $Env.Keys) { $e[$k] = $Env[$k] }
    $zipM = ConvertTo-Misto $Zip
    return (Invoke-Remoto $e (@($Opcoes) + @($zipM, $Sha)))
}

function Get-Backups($A) { @(Get-ChildItem -LiteralPath (Join-Path $A.DepW 'backups') -Filter '*.tar.gz' -ErrorAction SilentlyContinue) }

Caso 'B0' 'bash -n no aplicar_release.sh' {
    $out = & $script:Bash -n $ShMisto 2>&1
    Afirmar ($LASTEXITCODE -eq 0) ("erro de sintaxe: " + ($out -join ' '))
    Afirmar (-not ([System.IO.File]::ReadAllBytes((Join-Path $PSScriptRoot 'remoto\aplicar_release.sh')) -contains 13)) 'CR no aplicar_release.sh'
} $true

Caso 'B1' 'dry-run nao altera nada' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha @('--dry-run')
    Afirmar ($r.Codigo -eq 0) ("codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'APP_DIR mudou no dry-run'
    Afirmar ((Get-Backups $a).Count -eq 0) 'criou backup no dry-run'
    Afirmar (-not (Test-Path (Join-Path $a.DepW 'deploy.log'))) 'escreveu deploy.log no dry-run'
    Afirmar ($r.Saida -match 'app/a\.php' -and $r.Saida -match 'app/novo\.php') ('lista incompleta: ' + $r.Saida)
} $true

Caso 'B2' 'deploy aplica so o esperado e preserva data/ e segredos' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $prot = @('config\db.php', 'config\secrets.local.php', 'data\cache\.gitkeep', 'data\logs\x.log', 'data\config\pi.json', 'bin\b.php', 'public\index.php', 'config\visao_geral.php')
    $h0 = @{}; foreach ($p in $prot) { $h0[$p] = Get-Sha (Join-Path $a.AppW $p) }
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 0) ("codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-Content -Raw (Join-Path $a.AppW 'app\a.php')) -match 'novo') 'app/a.php nao foi atualizado'
    Afirmar (Test-Path (Join-Path $a.AppW 'app\novo.php')) 'app/novo.php nao foi criado'
    Afirmar (Test-Path (Join-Path $a.AppW 'deploy\remoto\x.sh')) 'deploy/remoto/x.sh nao foi criado'
    Afirmar (-not (Test-Path (Join-Path $a.AppW 'LICENSE'))) 'LICENSE (fora das pastas) foi aplicado'
    foreach ($p in $prot) { Afirmar ((Get-Sha (Join-Path $a.AppW $p)) -eq $h0[$p]) "$p foi alterado" }
    $bk = Get-Backups $a
    Afirmar ($bk.Count -eq 1) ('backups: ' + $bk.Count)
    $lst = (& $script:Bash -c "tar --force-local -tzf '$(ConvertTo-Misto $bk[0].FullName)'") -join '|'
    Afirmar ($lst -match 'app/a\.php' -and $lst -match '\.deploy/manifesto\.txt') "conteudo do backup: $lst"
    Afirmar (-not ($lst -match 'app/novo\.php')) 'backup contem arquivo novo'
    $man = (& $script:Bash -c "tar --force-local -xzOf '$(ConvertTo-Misto $bk[0].FullName)' .deploy/manifesto.txt") -join '|'
    Afirmar ($man -match 'M app/a\.php' -and $man -match 'N app/novo\.php') "manifesto: $man"
    Afirmar ((Get-Content -Raw (Join-Path $a.DepW 'deploy.log')) -match 'DEPLOY concluido') 'deploy.log sem a linha de conclusao'
    Afirmar ($r.Saida -match 'Cache de resposta limpo') 'cache nao foi limpo (app/ mudou)'
} $true

Caso 'B3' 'idempotencia: repetir nao faz nada' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $null = Invoke-Deploy $a $zip $sha
    $n1 = (Get-Backups $a).Count
    $h = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 0) ("codigo " + $r.Codigo)
    Afirmar ($r.Saida -match 'Nada a aplicar') ('saida: ' + $r.Saida)
    Afirmar ((Get-Backups $a).Count -eq $n1) 'criou backup novo'
    Afirmar ((Get-HashArvore $a.AppW) -eq $h) 'arvore mudou'
} $true

Caso 'B4' 'falha de lint: rollback automatico restaura byte a byte (exit 3)' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip @{ 'app/quebrado.php' = "<?php echo ;`n" }
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 3) ("esperado 3, obtido " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'arvore diferente do estado anterior'
    Afirmar (-not (Test-Path (Join-Path $a.AppW 'app\quebrado.php'))) 'quebrado.php ficou'
    Afirmar (-not (Test-Path (Join-Path $a.AppW 'deploy\remoto'))) 'diretorio novo ficou'
    Afirmar ((Get-Content -Raw (Join-Path $a.DepW 'deploy.log')) -match 'ROLLBACK automatico concluido') 'log sem rollback'
} $true

Caso 'B5' 'smoke com servidor embutido: 200 ok; 500 reverte; Host enviado' {
    $a = New-Arvore
    $porta = 0
    foreach ($p in (Get-Random -Minimum 18100 -Maximum 18900), 18901, 18902) {
        try { $l = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $p); $l.Start(); $l.Stop(); $porta = $p; break } catch { }
    }
    Afirmar ($porta -gt 0) 'nenhuma porta livre'
    $web = Join-Path $a.Base 'web'
    $null = New-Item -ItemType Directory -Path $web -Force
    $flag = (Join-Path $a.AppW 'app\http500.php').Replace('\', '/')
    $hostsLog = (Join-Path $web 'hosts.log').Replace('\', '/')
    Write-Texto "$web\router.php" ("<?php`n`$u = parse_url(`$_SERVER['REQUEST_URI'], PHP_URL_PATH);`nfile_put_contents('$hostsLog', (`$_SERVER['HTTP_HOST'] ?? '').`"\n`", FILE_APPEND);`n" +
        "if (file_exists('$flag')) { http_response_code(500); echo 'erro'; return; }`n" +
        "if (`$u === '/filtros' || `$u === '/graficos/produtividade') { echo 'ok'; return; }`nhttp_response_code(404);`n")
    $proc = Start-Process -FilePath $PhpLocal -ArgumentList @('-S', "127.0.0.1:$porta", '-t', $web, "$web\router.php") -WindowStyle Hidden -PassThru
    try {
        $ok = $false
        for ($i = 0; $i -lt 30; $i++) {
            try { $null = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$porta/filtros" -TimeoutSec 2; $ok = $true; break } catch { Start-Sleep -Milliseconds 300 }
        }
        Afirmar $ok 'servidor php embutido nao subiu'
        $envS = @{ PULAR_SMOKE = '0'; SMOKE_URL = "http://127.0.0.1:$porta"; SMOKE_HOST = 'jca.teste.local' }
        $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
        $r = Invoke-Deploy $a $zip $sha @() $envS
        Afirmar ($r.Codigo -eq 0) ("deploy com smoke 200: codigo " + $r.Codigo + ": " + $r.Saida)
        Afirmar ($r.Saida -match 'Smoke OK: /filtros' -and $r.Saida -match 'Smoke OK: /graficos/produtividade') 'smoke nao rodou nas duas rotas'
        Afirmar ((Get-Content -Raw "$web\hosts.log") -match 'jca\.teste\.local') 'cabecalho Host nao chegou ao servidor'
        # segundo deploy: o codigo novo "quebra" o servidor (500) -> rollback -> 200
        $antes = Get-HashArvore $a.AppW
        $zip2 = Join-Path $a.Base 'r2.zip'
        New-ZipFalso $zip2 ([ordered]@{ 'app/a.php' = "<?php echo `"v3`";`n"; 'app/http500.php' = "<?php echo 1;`n" })
        $r2 = Invoke-Deploy $a $zip2 (Get-Sha $zip2) @() $envS
        Afirmar ($r2.Codigo -eq 3) ("esperado 3 com smoke 500, obtido " + $r2.Codigo + ": " + $r2.Saida)
        Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'arvore nao foi restaurada apos smoke 500'
    } finally {
        if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue }
    }
} $true

Caso 'B6' 'pacotes com db.php/secrets/data/.. sao recusados (exit 2)' {
    $a = New-Arvore
    $antes = Get-HashArvore $a.AppW
    foreach ($ruim in 'config/db.php', 'config/secrets.local.php', 'data/x.json', '../fora.php') {
        $zip = Join-Path $a.Base 'ruim.zip'
        $sha = New-ZipOk $zip @{ $ruim = "<?php echo 'x';`n" }
        $r = Invoke-Deploy $a $zip $sha
        Afirmar ($r.Codigo -eq 2) ("$ruim : esperado 2, obtido " + $r.Codigo + ": " + $r.Saida)
        Afirmar ((Get-HashArvore $a.AppW) -eq $antes) "$ruim : arvore mudou"
    }
    Afirmar (-not (Test-Path (Join-Path $a.Base 'fora.php'))) 'escreveu fora de APP_DIR'
    # CR em .sh tambem e recusado
    $zip = Join-Path $a.Base 'cr.zip'
    $sha = New-ZipOk $zip @{ 'deploy/remoto/y.sh' = "#!/bin/bash`r`necho y`r`n" }
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 2 -and $r.Saida -match 'CR') ("CR em .sh: codigo " + $r.Codigo)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'CR: arvore mudou'
} $true

Caso 'B7' '--rollback restaura byte a byte; timestamp inexistente = exit 2' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 0) ("deploy: codigo " + $r.Codigo + ": " + $r.Saida)
    $depois = Get-HashArvore $a.AppW
    Afirmar ($depois -ne $antes) 'deploy nao mudou nada'
    $rd = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback', '--dry-run')
    Afirmar ($rd.Codigo -eq 0 -and (Get-HashArvore $a.AppW) -eq $depois) 'rollback --dry-run alterou algo'
    $rb = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback')
    Afirmar ($rb.Codigo -eq 0) ("rollback: codigo " + $rb.Codigo + ": " + $rb.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'rollback nao restaurou byte a byte'
    Afirmar ((Get-Backups $a).Count -eq 2) ('esperado backup do deploy + pre-rollback; obtido ' + (Get-Backups $a).Count)
    $bad = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback', '20200101-000000')
    Afirmar ($bad.Codigo -eq 2) ('timestamp inexistente: codigo ' + $bad.Codigo)
    $bad2 = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback', 'abc')
    Afirmar ($bad2.Codigo -eq 2) ('timestamp invalido: codigo ' + $bad2.Codigo)
} $true

Caso 'B8' 'hash errado aborta sem extrair nem alterar' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $null = New-ZipOk $zip
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip ('a' * 64)
    Afirmar ($r.Codigo -eq 2) ("codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'arvore mudou'
    Afirmar (@(Get-ChildItem -LiteralPath (Join-Path $a.DepW 'tmp') -Force).Count -eq 0) 'sobrou algo em tmp/'
    Afirmar ((Get-Backups $a).Count -eq 0) 'criou backup'
} $true

Caso 'B9' 'segundo deploy simultaneo e recusado pelo lock (exit 5)' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $saidaBg = Join-Path $a.Base 'bg.txt'
    $salvo = @{}
    $envBg = @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep; DEPLOY_TESTE_ESPERA_S = '8' }
    foreach ($k in $EnvBase.Keys) { $envBg[$k] = $EnvBase[$k] }
    foreach ($k in $envBg.Keys) { $salvo[$k] = [Environment]::GetEnvironmentVariable($k); [Environment]::SetEnvironmentVariable($k, $envBg[$k]) }
    try {
        $p = Start-Process -FilePath $script:Bash -ArgumentList @($ShMisto, (ConvertTo-Misto $zip), $sha) -WindowStyle Hidden -PassThru -RedirectStandardOutput $saidaBg
        $null = $p.Handle
    } finally {
        foreach ($k in $envBg.Keys) { [Environment]::SetEnvironmentVariable($k, $salvo[$k]) }
    }
    $achou = $false
    for ($i = 0; $i -lt 40; $i++) {
        if ((Test-Path (Join-Path $a.DepW 'deploy.lock.d')) -or (Test-Path (Join-Path $a.DepW 'deploy.lock'))) { $achou = $true; break }
        Start-Sleep -Milliseconds 250
    }
    Afirmar $achou 'o primeiro deploy nao tomou o lock'
    $r2 = Invoke-Deploy $a $zip $sha
    $null = $p.WaitForExit(60000)
    Afirmar ($r2.Codigo -eq 5) ("segundo: esperado 5, obtido " + $r2.Codigo + ": " + $r2.Saida)
    Afirmar ($r2.Saida -match 'lock') 'mensagem de lock ausente'
    Afirmar ($p.ExitCode -eq 0) ("primeiro deploy terminou com " + $p.ExitCode)
    Afirmar (Test-Path (Join-Path $a.AppW 'app\novo.php')) 'primeiro deploy nao aplicou'
    Afirmar (-not (Test-Path (Join-Path $a.DepW 'deploy.lock.d'))) 'lock nao foi liberado'
} $true

Caso 'B10' 'poda mantem 10 backups e preserva arquivos fora do padrao' {
    $a = New-Arvore
    $bdir = Join-Path $a.DepW 'backups'
    $null = New-Item -ItemType Directory -Path $bdir -Force
    for ($i = 1; $i -le 12; $i++) { Write-Texto ("$bdir\202001{0:00}-000000.tar.gz" -f $i) "x" }
    Write-Texto "$bdir\nao_apagar.txt" "x"
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $r = Invoke-Deploy $a $zip $sha @() @{ MANTER_BACKUPS = '10' }
    Afirmar ($r.Codigo -eq 0) ("codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-Backups $a).Count -eq 10) ('backups restantes: ' + (Get-Backups $a).Count)
    Afirmar (Test-Path "$bdir\nao_apagar.txt") 'apagou arquivo fora do padrao'
    Afirmar (-not (Test-Path "$bdir\20200101-000000.tar.gz")) 'nao apagou o mais antigo'
} $true

Caso 'B11' 'caminhos perigosos sao recusados (exit 2)' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $casos = @(
        @{ APP_DIR = '/'; DEPLOY_DIR = $a.Dep },
        @{ APP_DIR = '/var/x'; DEPLOY_DIR = $a.Dep },
        @{ APP_DIR = '/apps/..'; DEPLOY_DIR = $a.Dep },
        @{ APP_DIR = $a.App; DEPLOY_DIR = '/tmp/x' },
        @{ APP_DIR = $a.App; DEPLOY_DIR = '/var/lib/x' },
        @{ APP_DIR = $a.App; DEPLOY_DIR = ($a.App + '/dentro') },
        @{ APP_DIR = 'relativo/x'; DEPLOY_DIR = $a.Dep }
    )
    foreach ($c in $casos) {
        $r = Invoke-Remoto $c @((ConvertTo-Misto $zip), $sha)
        Afirmar ($r.Codigo -eq 2) ("APP_DIR=" + $c.APP_DIR + " DEPLOY_DIR=" + $c.DEPLOY_DIR + ": esperado 2, obtido " + $r.Codigo + ": " + $r.Saida)
    }
    Afirmar (-not (Test-Path (Join-Path $a.App 'dentro'))) 'criou diretorio dentro de APP_DIR'
} $true

} finally {
    Remove-Seguro $T
}

Write-Host ''
Write-Host ("Resumo: {0} passou, {1} falhou, {2} pulado(s)." -f $script:Pass, $script:Fail, $script:Pulados)
if ($script:Fail -gt 0) { exit 1 }
if ($script:Pulados -gt 0) { Write-Host 'ATENCAO: casos pulados (bash ausente); isto NAO e sucesso total.' -ForegroundColor Yellow; exit 3 }
exit 0
