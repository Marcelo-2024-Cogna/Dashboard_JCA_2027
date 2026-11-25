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
.PARAMETER Casos
  Ids dos casos a rodar (ex.: -Casos B16,B17); o ambiente de cada caso e criado do zero.
.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File deploy\teste_deploy.ps1 -Grupo A
#>
param(
    [ValidateSet('A', 'B', 'Todos')][string]$Grupo = 'Todos',
    [string[]]$Casos = @()
)

$ErrorActionPreference = 'Continue'
$Casos = @($Casos | ForEach-Object { $_ -split ',' } | Where-Object { $_ })  # -File entrega 'B1,B2' como uma string so
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
    if ($Casos.Count -gt 0 -and $Casos -notcontains $Id) { return }
    if ($PrecisaBash -and -not $script:Bash) {
        Write-Host ("PULADO  {0} {1} (bash nao encontrado)" -f $Id, $Nome) -ForegroundColor Yellow
        $script:Pulados++
        return
    }
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        & $Corpo
        Write-Host ("PASSOU  {0} {1} ({2:N1} s)" -f $Id, $Nome, $sw.Elapsed.TotalSeconds) -ForegroundColor Green
        $script:Pass++
    } catch {
        Write-Host ("FALHOU  {0} {1}: {2} ({3:N1} s)" -f $Id, $Nome, $_.Exception.Message, $sw.Elapsed.TotalSeconds) -ForegroundColor Red
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
[System.IO.File]::WriteAllText((Join-Path $Stubs 'ssh.cmd'), "@echo %*>>`"%JCA_STUB_DIR%\ssh.log`"`r`n@if defined JCA_STUB_DF echo %JCA_STUB_DF%`r`n@exit /b 0`r`n")

function Invoke-ComStubs([string[]]$Extra, [hashtable]$EnvExtra = @{}) {
    foreach ($l in 'scp.log', 'ssh.log') { Remove-Item (Join-Path $Stubs $l) -ErrorAction SilentlyContinue }
    $args2 = @($Deploy, '-RepoRaiz', $RepoT, '-SemTestes', '-Sim', '-Saida', (Join-Path $T 'pacotesA6')) + $Extra
    $envStub = @{ JCA_STUB_DIR = $Stubs; PATH = ($Stubs + ';' + $env:PATH) }
    foreach ($k in $EnvExtra.Keys) { $envStub[$k] = $EnvExtra[$k] }
    $r = Invoke-Ps $args2 $envStub
    [string[]]$scpLog = if (Test-Path (Join-Path $Stubs 'scp.log')) { @(Get-Content (Join-Path $Stubs 'scp.log')) } else { @() }
    [string[]]$sshLog = if (Test-Path (Join-Path $Stubs 'ssh.log')) { @(Get-Content (Join-Path $Stubs 'ssh.log')) } else { @() }
    return [pscustomobject]@{ R = $r; Scp = $scpLog; Ssh = $sshLog }
}

Caso 'A6a' 'stubs: deploy = 1 scp (2 arquivos) + ssh de espaco + ssh -t com sudo, nomes com GUID e hash do script' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor') @{ JCA_STUB_DF = '99999999' }
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Scp.Count -eq 1) ('chamadas scp: ' + $x.Scp.Count)
    Afirmar ($x.Scp[0] -match 'jca_release_.*_[0-9a-f]{32}\.zip' -and $x.Scp[0] -match 'jca_aplicar_[0-9a-f]{32}\.sh' -and $x.Scp[0] -match 'usuario@servidor:/tmp/') ('scp: ' + $x.Scp[0])
    Afirmar ($x.Ssh.Count -eq 2) ('chamadas ssh: ' + $x.Ssh.Count)
    Afirmar ($x.Ssh[0] -match 'df -Pk /tmp') ('1o ssh deveria medir /tmp: ' + $x.Ssh[0])
    Afirmar ($x.Ssh[1] -match '(^| )-t ' -and $x.Ssh[1] -match 'sudo bash /tmp/jca_aplicar_[0-9a-f]{32}\.sh') ('ssh: ' + $x.Ssh[1])
    Afirmar ($x.Ssh[1] -match 'h=[0-9a-f]{64}; ' -and $x.Ssh[1] -match '-L \$p' -and $x.Ssh[1] -match 'stat -c %U' -and $x.Ssh[1] -match 'chmod 600' -and $x.Ssh[1] -match 'sha256sum') ('ssh sem checagem do script: ' + $x.Ssh[1])
    Afirmar (-not ($x.Ssh[1] -match '--dry-run')) 'dry-run indevido'
    Afirmar (-not ($x.Ssh[1] -match 'remocao')) ('flag de remocao indevida por padrao (a remocao e o padrao do servidor): ' + $x.Ssh[1])
    $x2 = Invoke-ComStubs @('-Alvo', 'usuario@servidor') @{ JCA_STUB_DF = '99999999' }
    $n1 = [regex]::Match($x.Scp[0], 'jca_aplicar_[0-9a-f]{32}').Value; $n2 = [regex]::Match($x2.Scp[0], 'jca_aplicar_[0-9a-f]{32}').Value
    Afirmar ($n1 -ne $n2) 'nome do script repetiu entre execucoes'
}

Caso 'A6b' 'stubs: -DryRun repassa --dry-run' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-DryRun')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo)
    Afirmar ($x.Ssh.Count -eq 2 -and $x.Ssh[1] -match '--dry-run') ('ssh: ' + ($x.Ssh -join '|'))
}

Caso 'A6c' 'stubs: -Rollback envia so o script' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-Rollback')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Scp.Count -eq 1 -and $x.Scp[0] -match 'jca_aplicar_' -and -not ($x.Scp[0] -match 'jca_release_')) ('scp: ' + ($x.Scp -join '|'))
    Afirmar ($x.Ssh.Count -eq 2 -and $x.Ssh[1] -match '--rollback') ('ssh: ' + ($x.Ssh -join '|'))
}

Caso 'A6g' 'stubs: -SemRemocao, -PermitirRemocaoGrande e -LimiteRemocao sao repassados; combinacoes invalidas recusadas sem ssh/scp' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-SemRemocao', '-SemChecagemEspaco')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Ssh.Count -eq 1 -and $x.Ssh[0] -match '--sem-remocao ' -and -not ($x.Ssh[0] -match 'permitir')) ('ssh: ' + ($x.Ssh -join '|'))
    Afirmar ($x.R.Saida -match 'Remocao:\s+DESLIGADA') ('a confirmacao nao mostra a remocao desligada: ' + $x.R.Saida)
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-PermitirRemocaoGrande', '-LimiteRemocao', '90', '-SemChecagemEspaco')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Ssh[0] -match '--permitir-remocao-grande --limite-remocao 90 ' -and -not ($x.Ssh[0] -match 'sem-remocao')) ('ssh: ' + ($x.Ssh -join '|'))
    foreach ($ruim in @(@('-SemRemocao', '-PermitirRemocaoGrande'), @('-Rollback', '-SemRemocao'), @('-LimiteRemocao', '-5'))) {
        $x = Invoke-ComStubs (@('-Alvo', 'usuario@servidor') + $ruim)
        Afirmar ($x.R.Codigo -eq 2) (($ruim -join ' ') + ': esperado 2, obtido ' + $x.R.Codigo + ': ' + $x.R.Saida)
        Afirmar ($x.Scp.Count -eq 0 -and $x.Ssh.Count -eq 0) (($ruim -join ' ') + ': chamou ssh/scp')
    }
}

Caso 'A6e' 'stubs: -SemChecagemEspaco faz so o ssh de execucao' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor', '-SemChecagemEspaco')
    Afirmar ($x.R.Codigo -eq 0) ('codigo ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.Ssh.Count -eq 1 -and $x.Ssh[0] -match 'sudo bash') ('ssh: ' + ($x.Ssh -join '|'))
}

Caso 'A6f' 'stubs: /tmp do servidor sem espaco para o pacote: aborta antes do scp (exit 10)' {
    $x = Invoke-ComStubs @('-Alvo', 'usuario@servidor') @{ JCA_STUB_DF = '100' }
    Afirmar ($x.R.Codigo -eq 10) ('esperado 10, obtido ' + $x.R.Codigo + ': ' + $x.R.Saida)
    Afirmar ($x.R.Saida -match 'Espaco insuficiente em /tmp') 'mensagem ausente'
    Afirmar ($x.Scp.Count -eq 0) 'chamou scp mesmo sem espaco'
    Afirmar ($x.Ssh.Count -eq 1) ('esperado so o ssh de medicao: ' + $x.Ssh.Count)
}

# stubs de sudo/chown/chmod para bash (grupo A7 e B12+); LF obrigatorio
$StubsSh = Join-Path $T 'stubs_sh'
$null = New-Item -ItemType Directory -Path $StubsSh -Force
Write-Texto (Join-Path $StubsSh 'sudo') "#!/bin/bash`nexec `"`$@`"`n"
Write-Texto (Join-Path $StubsSh 'chown') "#!/bin/bash`necho `"chown `$*`" >> `"`$JCA_STUB_LOG`"`nif [ -n `"`${JCA_CHOWN_FALHA:-}`" ] && [[ `"`$*`" == *`"`$JCA_CHOWN_FALHA`"* ]]; then exit 1; fi`nexit 0`n"
Write-Texto (Join-Path $StubsSh 'chmod') "#!/bin/bash`necho `"chmod `$*`" >> `"`$JCA_STUB_LOG`"`nexec /usr/bin/chmod `"`$@`"`n"
$StubsShU = if ($script:Cygpath) { (& $script:Cygpath -u $StubsSh) } else { $StubsSh.Replace('\', '/') }

# stubs de stat/chmod (A7, A8): o NTFS nao guarda dono nem modo Unix
$st = Join-Path $T 'stubs_a8'
$null = New-Item -ItemType Directory -Path $st -Force
Write-Texto (Join-Path $st 'stat') ("#!/bin/bash`nif [ `"`$1`" = -c ] && [ -n `"`${JCA_MODOF:-}`" ]; then`n  case `"`$2`" in`n    %U) if [ -n `"`${JCA_DONO:-}`" ]; then echo `"`$JCA_DONO`"; exit 0; fi ;;`n    %a) cat `"`$JCA_MODOF`"; exit 0 ;;`n  esac`nfi`nexec /usr/bin/stat `"`$@`"`n")
Write-Texto (Join-Path $st 'chmod') ("#!/bin/bash`nif [ -n `"`${JCA_CHMOD_FALHA:-}`" ]; then exit 1; fi`nif [ `"`$1`" = 600 ] && [ -z `"`${JCA_CHMOD_NOOP:-}`" ]; then echo 600 > `"`$JCA_MODOF`"; fi`nexit 0`n")
$stU = if ($script:Cygpath) { (& $script:Cygpath -u $st) } else { $st.Replace('\', '/') }

Caso 'A7' 'comando remoto: executa com hash certo; recusa script adulterado (sem executar)' {
    $sc = Join-Path $T 'a7_script.sh'
    Write-Texto $sc "#!/bin/bash`necho EXECUTOU `$*`n"
    $hash = Get-Sha $sc
    $scM = ConvertTo-Misto $sc
    $cmdOk = & { . $Deploy; New-ComandoRemoto $scM $null $hash @('--teste') }
    $modo7 = Join-Path $T 'a7_modo.txt'; [System.IO.File]::WriteAllText($modo7, "755`n")
    [Environment]::SetEnvironmentVariable('JCA_MODOF', (ConvertTo-Misto $modo7))
    $pre = 'PATH="' + $stU + ':' + $StubsShU + ':$PATH"; '
    $salvo = [Environment]::GetEnvironmentVariable('MSYS_NO_PATHCONV')
    try {
        [Environment]::SetEnvironmentVariable('MSYS_NO_PATHCONV', '1')
        $cmdOk = $pre + $cmdOk
        $o1 = (& $script:Bash -c $cmdOk 2>&1) -join '|'; $rc1 = $LASTEXITCODE
        $apagou = -not (Test-Path -LiteralPath $sc)
        Write-Texto $sc "#!/bin/bash`necho EXECUTOU `$*`n"
        $cmdRuim = & { . $Deploy; New-ComandoRemoto $scM $null ('0' * 64) @('--teste') }
        $o2 = (& $script:Bash -c ($pre + $cmdRuim) 2>&1) -join '|'; $rc2 = $LASTEXITCODE
        $cmdSem = & { . $Deploy; New-ComandoRemoto (ConvertTo-Misto (Join-Path $T 'nao_existe.sh')) $null $hash @('--teste') }
        $o3 = (& $script:Bash -c ($pre + $cmdSem) 2>&1) -join '|'; $rc3 = $LASTEXITCODE
    } finally { [Environment]::SetEnvironmentVariable('MSYS_NO_PATHCONV', $salvo); [Environment]::SetEnvironmentVariable('JCA_MODOF', $null) }
    Afirmar ($rc1 -eq 0 -and $o1 -match 'EXECUTOU --teste') ("hash certo deveria executar: rc=$rc1 $o1")
    Afirmar $apagou 'script nao foi apagado no fim'
    Afirmar ($rc2 -eq 2 -and -not ($o2 -match 'EXECUTOU') -and $o2 -match 'ERRO') ("hash errado deveria abortar com 2 sem executar: rc=$rc2 $o2")
    Afirmar ($rc3 -eq 2 -and -not ($o3 -match 'EXECUTOU')) ("script ausente deveria abortar: rc=$rc3 $o3")
} $true

Caso 'A8' 'conferencia remota: modo 664/666 do scp e corrigido; ausente, link, dono, chmod, modo e hash dizem qual falhou' {
    $modof = Join-Path $T 'a8_modo.txt'
    $salvoNp = [Environment]::GetEnvironmentVariable('MSYS_NO_PATHCONV')
    # roda New-ComandoRemoto no Git Bash; $Cfg: Modo, Dono, Hash, ChmodFalha, ChmodNoop, Ausente, Link, Zip, ZipDono
    function Rodar([hashtable]$Cfg) {
        $sc = Join-Path $T 'a8_script.sh'
        Remove-Item -LiteralPath $sc -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath ($sc + '.lnk') -Force -ErrorAction SilentlyContinue
        $real = Join-Path $T 'a8_real.sh'
        Write-Texto $real "#!/bin/bash`necho EXECUTOU `$*`n"
        $hash = Get-Sha $real
        if ($Cfg.Link) {
            [Environment]::SetEnvironmentVariable('MSYS', 'winsymlinks:lnk')
            try { & $script:Bash -c ("cd '" + (ConvertTo-Misto $T) + "' && ln -s a8_real.sh a8_script.sh") 2>&1 | Out-Null } finally { [Environment]::SetEnvironmentVariable('MSYS', $null) }
        } elseif (-not $Cfg.Ausente) {
            Copy-Item -LiteralPath $real -Destination $sc
        }
        $zip = $null
        if ($Cfg.Zip) { $zp = Join-Path $T 'a8_pacote.zip'; Write-Texto $zp "zip`n"; $zip = ConvertTo-Misto $zp }
        $hashUsado = if ($Cfg.Hash) { $Cfg.Hash } else { $hash }
        $cmd = & { . $Deploy; New-ComandoRemoto ((ConvertTo-Misto $T) + '/a8_script.sh') $zip $hashUsado @('--teste') }
        [System.IO.File]::WriteAllText($modof, "$($Cfg.Modo)`n")
        $vars = @{ JCA_MODOF = (ConvertTo-Misto $modof); JCA_DONO = $Cfg.Dono; JCA_CHMOD_FALHA = $Cfg.ChmodFalha; JCA_CHMOD_NOOP = $Cfg.ChmodNoop; MSYS_NO_PATHCONV = '1' }
        if ($Cfg.Link) { $vars.MSYS = 'winsymlinks:lnk' }
        foreach ($k in $vars.Keys) { [Environment]::SetEnvironmentVariable($k, $vars[$k]) }
        try {
            $o = (& $script:Bash -c ('PATH="' + $stU + ':' + $StubsShU + ':$PATH"; ' + $cmd) 2>&1) -join '|'
            $rc = $LASTEXITCODE
        } finally {
            foreach ($k in $vars.Keys) { [Environment]::SetEnvironmentVariable($k, $null) }
            [Environment]::SetEnvironmentVariable('MSYS_NO_PATHCONV', $salvoNp)
        }
        return @{ Rc = $rc; Saida = $o; Sumiu = -not (Test-Path -LiteralPath $sc) }
    }
    foreach ($modo in '664', '666') {
        $r = Rodar @{ Modo = $modo }
        Afirmar ($r.Rc -eq 0 -and $r.Saida -match 'EXECUTOU --teste') ("modo ${modo}: deveria executar: rc=$($r.Rc) $($r.Saida)")
        Afirmar ($r.Saida -match "modo original do script remoto era $modo, ajustado para 600") ("modo ${modo}: sem aviso: $($r.Saida)")
        Afirmar $r.Sumiu "modo ${modo}: script nao foi apagado"
    }
    $r = Rodar @{ Modo = '600'; Zip = $true }
    Afirmar ($r.Rc -eq 0 -and $r.Saida -match 'EXECUTOU' -and -not ($r.Saida -match 'AVISO')) ("modo 600 com zip: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; Dono = 'outro' }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'dono do script remoto outro, esperado \S+\.' -and -not ($r.Saida -match 'EXECUTOU')) ("dono: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; Zip = $true; Dono = 'outro' }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'dono do script remoto outro' -and -not ($r.Saida -match 'EXECUTOU')) ("dono com zip: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; Link = $true }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'script remoto e link simbolico' -and -not ($r.Saida -match 'EXECUTOU')) ("link: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; Hash = ('0' * 64) }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'hash do script remoto obtido [0-9a-f]{12} esperado 000000000000\. ' -and -not ($r.Saida -match 'EXECUTOU')) ("hash: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; Ausente = $true }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'script remoto ausente' -and -not ($r.Saida -match 'EXECUTOU')) ("ausente: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; ChmodFalha = '1' }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'chmod 600 falhou em \S+, modo atual 664' -and -not ($r.Saida -match 'EXECUTOU')) ("chmod falhou: rc=$($r.Rc) $($r.Saida)")
    $r = Rodar @{ Modo = '664'; ChmodNoop = '1' }
    Afirmar ($r.Rc -eq 2 -and $r.Saida -match 'modo do script remoto 664, esperado 600 apos chmod' -and -not ($r.Saida -match 'EXECUTOU')) ("modo apos chmod: rc=$($r.Rc) $($r.Saida)")
} $true

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
        if ($todo.ContainsKey('JCA_STUB_PATH') -and $todo['JCA_STUB_PATH']) {
            # o bash.exe do Git poe /usr/bin na frente do PATH: os stubs entram aqui, dentro do bash
            $out = & $script:Bash -c 'PATH="$JCA_STUB_PATH:$PATH"; exec bash "$@"' _ $ShMisto @Argumentos 2>&1
        } else {
            $out = & $script:Bash $ShMisto @Argumentos 2>&1
        }
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

# ---- auxiliares da remocao de orfaos (manifesto do ultimo deploy)
function Get-ManPath($A) { Join-Path $A.DepW 'manifesto_atual.txt' }
function Get-ManLinhas($A) { $p = Get-ManPath $A; if (Test-Path -LiteralPath $p) { @(Get-Content -LiteralPath $p | Where-Object { $_ -ne '' }) } else { @() } }
function Set-ManLinhas($A, [string[]]$Linhas) { [System.IO.File]::WriteAllText((Get-ManPath $A), (($Linhas -join "`n") + "`n"), $script:Utf8) }
function Get-ManSha($A) { $p = Get-ManPath $A; if (Test-Path -LiteralPath $p) { Get-Sha $p } else { 'AUSENTE' } }
function Get-TarLista([string]$Tar) { @(& $script:Bash -c "tar --force-local -tzf '$(ConvertTo-Misto $Tar)'") }
function Get-TarTexto([string]$Tar, [string]$Membro) { (& $script:Bash -c "tar --force-local -xzOf '$(ConvertTo-Misto $Tar)' $Membro") -join "`n" }
function Write-Php([string]$Arq, [string]$N) { Write-Texto $Arq ("<?php echo '$N';`n") }
$PkgBase = @('app/a.php', 'app/novo.php', 'config/visao_geral.php', 'deploy/remoto/x.sh')
$ExtraVelhos = @{ 'app/velho.php' = "<?php echo 'v';`n"; 'app/Srv/x.php' = "<?php echo 'x';`n"; 'app/Srv/Sub/y.php' = "<?php echo 'y';`n"; 'app/Mantem/m.php' = "<?php echo 'm';`n" }
# servidor "depois de um deploy anterior": arquivos antigos que o pacote novo nao tem + o manifesto desse deploy
function Initialize-Velhos($A) {
    foreach ($k in $ExtraVelhos.Keys) { Write-Texto (Join-Path $A.AppW ($k.Replace('/', '\'))) $ExtraVelhos[$k] }
    Set-ManLinhas $A ($PkgBase + @($ExtraVelhos.Keys))
}

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
    Afirmar (@(Get-ChildItem -LiteralPath $a.DepW -Force).Count -eq 0) ('dry-run criou algo em DEPLOY_DIR: ' + ((Get-ChildItem -LiteralPath $a.DepW -Force | ForEach-Object Name) -join ','))
    Afirmar (@(Get-ChildItem -LiteralPath $a.Base -Force | Where-Object { $_.Name -like 'run.*' }).Count -eq 0) 'sobrou diretorio temporario run.* ao lado do zip'
    Afirmar ($r.Saida -match 'nada criado') 'resumo do dry-run nao diz o que cria'
    # DEPLOY_DIR inexistente: dry-run nao pode cria-lo
    $a2 = New-Arvore
    Remove-Item -LiteralPath $a2.DepW -Recurse -Force
    $zip2 = Join-Path $a2.Base 'r.zip'; $sha2 = New-ZipOk $zip2
    $r2 = Invoke-Deploy $a2 $zip2 $sha2 @('--dry-run')
    Afirmar ($r2.Codigo -eq 0) ("dry-run sem DEPLOY_DIR: codigo " + $r2.Codigo + ": " + $r2.Saida)
    Afirmar (-not (Test-Path -LiteralPath $a2.DepW)) 'dry-run criou DEPLOY_DIR'
    $r3 = Invoke-Remoto @{ APP_DIR = $a2.App; DEPLOY_DIR = $a2.Dep } @('--rollback', '--dry-run')
    Afirmar ($r3.Codigo -eq 2 -and -not (Test-Path -LiteralPath $a2.DepW)) ('rollback dry-run sem backups: codigo ' + $r3.Codigo)
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
    Afirmar ($r.Saida -match 'ignorado\s+LICENSE') ('arquivo ignorado sem nome no resumo: ' + $r.Saida)
    Afirmar ($r.Saida -match 'PRIMEIRA EXECUCAO' -and (Get-ManLinhas $a).Count -eq 4) ('primeira execucao deveria inicializar o manifesto com os 4 arquivos aplicados: ' + ((Get-ManLinhas $a) -join '|'))
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
    for ($i = 0; $i -lt 240; $i++) {
        if ((Test-Path (Join-Path $a.DepW 'deploy.lock.d')) -or (Test-Path (Join-Path $a.DepW 'deploy.lock'))) { $achou = $true; break }
        Start-Sleep -Milliseconds 250
    }
    Afirmar $achou 'o primeiro deploy nao tomou o lock'
    $r2 = Invoke-Deploy $a $zip $sha
    $null = $p.WaitForExit(300000)
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

# ---- permissoes/donos com stubs de chown/chmod (o Git Bash nao e root)
function Get-StubEnv([string]$Log, [string]$Falha = '') {
    return @{ JCA_STUB_PATH = $StubsShU; JCA_STUB_LOG = (ConvertTo-Misto $Log); PULAR_CHOWN = '0'; JCA_CHOWN_FALHA = $Falha }
}

Caso 'B12' 'donos e modos (stubs): chown/chmod so no que foi tocado, sem -R; rollback reajusta data/cache' {
    $a = New-Arvore
    $log = Join-Path $a.Base 'stub.log'

    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $r = Invoke-Deploy $a $zip $sha @() (Get-StubEnv $log)
    Afirmar ($r.Codigo -eq 0) ("deploy: codigo " + $r.Codigo + ": " + $r.Saida)
    $l = Get-Content -Raw -LiteralPath $log
    foreach ($f in 'app/a.php', 'app/novo.php', 'deploy/remoto/x.sh') {
        Afirmar ($l -match [regex]::Escape("chown root:www-data") -and $l -match ("chown root:www-data [^\r\n]*" + [regex]::Escape($f))) "sem chown em $f"
    }
    Afirmar ($l -match 'chmod 755 [^\r\n]*deploy/remoto/x\.sh' -and $l -match 'chmod 644 [^\r\n]*app/novo\.php') 'modos 755 (.sh) / 644 (demais) nao aplicados'
    Afirmar ($l -match 'chmod 755 [^\r\n]*deploy/remoto\r?\n') 'diretorio novo sem chmod 755'
    Afirmar ($l -match 'chown www-data:www-data [^\r\n]*data/cache') 'data/cache sem chown www-data'
    Afirmar (-not ($l -match 'config/db\.php|secrets\.local|config/visao_geral\.php|data/logs|bin/b\.php|public/index\.php')) 'chown/chmod tocou arquivo que nao era do deploy'
    Afirmar (-not ($l -match ' -R ')) 'usou chown/chmod recursivo'
    Remove-Item -LiteralPath $log -Force
    $rb = Invoke-Remoto (@{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } + (Get-StubEnv $log)) @('--rollback')
    Afirmar ($rb.Codigo -eq 0) ("rollback: codigo " + $rb.Codigo + ": " + $rb.Saida)
    Afirmar ($rb.Saida -match 'Rollback concluido' -and -not ($rb.Saida -match 'falhou em')) ('rollback com aviso de permissao: ' + $rb.Saida)
    $l2 = Get-Content -Raw -LiteralPath $log
    Afirmar ($l2 -match 'chown www-data:www-data [^\r\n]*data/cache') 'rollback nao reajustou o dono de data/cache'
} $true

Caso 'B13' 'falha de chown: [AVISO] por arquivo, deploy aplicado e saida 6' {
    $a = New-Arvore
    $log = Join-Path $a.Base 'stub.log'
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $r = Invoke-Deploy $a $zip $sha @() (Get-StubEnv $log 'app/novo.php')
    Afirmar ($r.Codigo -eq 6) ("esperado 6, obtido " + $r.Codigo + ": " + $r.Saida)
    Afirmar ($r.Saida -match '\[AVISO\] chown root:www-data falhou em app/novo\.php') ('aviso por arquivo ausente: ' + $r.Saida)
    Afirmar ($r.Saida -match '1 falha\(s\) de dono/permissao') 'aviso final destacado ausente'
    Afirmar ((Get-Content -Raw (Join-Path $a.AppW 'app\a.php')) -match 'novo') 'deploy nao foi aplicado'
    Afirmar ((Get-Content -Raw (Join-Path $a.DepW 'deploy.log')) -match 'CONCLUIDO COM 1 AVISO') 'deploy.log sem o aviso'
    Afirmar ((Get-Backups $a).Count -eq 1) 'backup ausente'
} $true

Caso 'B14' 'backup so com arquivos novos (tar -T vazio): cria, tem manifesto e o rollback remove' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'
    New-ZipFalso $zip ([ordered]@{ 'app/so_novo.php' = "<?php echo 1;`n"; 'bin/outro_novo.php' = "<?php echo 2;`n" })
    $sha = Get-Sha $zip
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 0) ("deploy: codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar (Test-Path (Join-Path $a.AppW 'app\so_novo.php')) 'arquivo novo nao foi criado'
    $bk = Get-Backups $a
    Afirmar ($bk.Count -eq 1) ('backups: ' + $bk.Count)
    $lst = @(& $script:Bash -c "tar --force-local -tzf '$(ConvertTo-Misto $bk[0].FullName)'")
    Afirmar ($lst.Count -eq 1 -and $lst[0] -match 'manifesto\.txt') ('backup so deveria ter o manifesto: ' + ($lst -join '|'))
    $man = (& $script:Bash -c "tar --force-local -xzOf '$(ConvertTo-Misto $bk[0].FullName)' .deploy/manifesto.txt") -join '|'
    Afirmar ($man -match 'N app/so_novo\.php' -and -not ($man -match '(^|\|)M ')) "manifesto: $man"
    $rb = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback')
    Afirmar ($rb.Codigo -eq 0) ("rollback: codigo " + $rb.Codigo + ": " + $rb.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'rollback nao devolveu a arvore ao estado anterior'
} $true

Caso 'B15' 'espaco insuficiente (MIN_LIVRE_MB enorme) aborta com 2 sem criar nada' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    $antes = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha @() @{ MIN_LIVRE_MB = '999999999' }
    Afirmar ($r.Codigo -eq 2 -and $r.Saida -match 'Espaco livre insuficiente') ("codigo " + $r.Codigo + ": " + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $antes) 'arvore mudou'
} $true



# ---- remocao de orfaos (manifesto do ultimo deploy)
# Custo: cada execucao do script remoto no Git Bash leva dezenas de segundos (cada fork e lento
# neste Windows). Por isso o servidor e o manifesto "de um deploy anterior" sao semeados direto
# (Initialize-Velhos), em vez de rodar um deploy extra; a primeira execucao real e coberta em B2/B16/B18.

Caso 'B16' 'primeira execucao (sem manifesto): nao remove nada e inicializa o manifesto, tambem com "Nada a aplicar"; -SemRemocao nao cria manifesto' {
    $a = New-Arvore
    Write-Php "$($a.AppW)\app\legado_antigo.php" 'l'
    Write-Php "$($a.AppW)\app\so_servidor.php" 's'
    $h0 = @{}; foreach ($p in 'app\legado_antigo.php', 'app\so_servidor.php') { $h0[$p] = Get-Sha (Join-Path $a.AppW $p) }
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    # (1) dry-run na primeira execucao: avisa e nao grava
    $rd = Invoke-Deploy $a $zip $sha @('--dry-run')
    Afirmar ($rd.Codigo -eq 0 -and $rd.Saida -match 'PRIMEIRA EXECUCAO' -and $rd.Saida -match 'NADA sera removido') ('dry-run primeira: ' + $rd.Saida)
    Afirmar (-not (Test-Path -LiteralPath (Get-ManPath $a))) 'dry-run gravou o manifesto'
    # (2) -SemRemocao aplica o pacote e NAO cria manifesto
    $r3 = Invoke-Deploy $a $zip $sha @('--sem-remocao')
    Afirmar ($r3.Codigo -eq 0 -and $r3.Saida -match 'DESLIGADA' -and $r3.Saida -match ', 0 removido\(s\)') ('sem-remocao: ' + $r3.Codigo + ': ' + $r3.Saida)
    Afirmar (-not (Test-Path -LiteralPath (Get-ManPath $a))) '--sem-remocao criou o manifesto'
    Afirmar (Test-Path (Join-Path $a.AppW 'app\novo.php')) '--sem-remocao nao aplicou o pacote'
    # (3) servidor ja igual ao pacote (o caso real do usuario): "Nada a aplicar" e mesmo assim inicializa o manifesto
    $r4 = Invoke-Deploy $a $zip $sha
    Afirmar ($r4.Codigo -eq 0 -and $r4.Saida -match 'Nada a aplicar' -and $r4.Saida -match 'PRIMEIRA EXECUCAO') ('nada a aplicar + primeira: ' + $r4.Codigo + ': ' + $r4.Saida)
    Afirmar ($r4.Saida -match ', 0 removido\(s\)' -and -not ($r4.Saida -match 'Removidos: ')) 'removeu algo na primeira execucao'
    $m = @(Get-ManLinhas $a); Afirmar ((($m | Sort-Object) -join '|') -eq (($PkgBase | Sort-Object) -join '|')) ('manifesto inicial: ' + ($m -join '|'))
    Afirmar (-not ((Get-ManLinhas $a) -contains 'LICENSE')) 'manifesto contem arquivo fora das pastas aplicadas'
    Afirmar ((Get-Backups $a).Count -eq 1) ('so o deploy com mudancas cria backup: ' + (Get-Backups $a).Count)
    foreach ($p in $h0.Keys) { Afirmar ((Get-Sha (Join-Path $a.AppW $p)) -eq $h0[$p]) "$p (so do servidor) foi tocado" }
} $true

Caso 'B17' 'deploy sem os arquivos X: remove X (e diretorios vazios), grava no backup, atualiza o manifesto; arquivos so-do-servidor intactos' {
    $a = New-Arvore
    Initialize-Velhos $a
    # so do servidor (nao estao em manifesto nenhum)
    Write-Php "$($a.AppW)\app\so_servidor.php" 's'
    Write-Php "$($a.AppW)\config\extra_servidor.php" 'e'
    Write-Php "$($a.AppW)\public\so_servidor.php" 'p'
    Write-Php "$($a.AppW)\app\Mantem\do_servidor.php" 'd'
    $sv = @{}; foreach ($p in 'app\so_servidor.php', 'config\extra_servidor.php', 'public\so_servidor.php', 'app\Mantem\do_servidor.php', 'config\db.php', 'config\secrets.local.php', 'data\config\pi.json', 'data\logs\x.log') { $sv[$p] = Get-Sha (Join-Path $a.AppW $p) }
    $conteudos = @{}; foreach ($k in $ExtraVelhos.Keys) { $conteudos[$k] = Get-Sha (Join-Path $a.AppW ($k.Replace('/', '\'))) }
    $man1 = Get-ManSha $a
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/novo2.php' = "<?php echo 2;`n" }
    $r2 = Invoke-Deploy $a $zip2 $sha2
    Afirmar ($r2.Codigo -eq 0) ("deploy: " + $r2.Codigo + ": " + $r2.Saida)
    Afirmar (-not ($r2.Saida -match 'PRIMEIRA EXECUCAO')) 'disse primeira execucao com manifesto existente'
    foreach ($k in $ExtraVelhos.Keys) { Afirmar (-not (Test-Path -LiteralPath (Join-Path $a.AppW ($k.Replace('/', '\'))))) "$k nao foi removido" }
    Afirmar (-not (Test-Path -LiteralPath (Join-Path $a.AppW 'app\Srv'))) 'app/Srv (vazio) nao foi removido'
    Afirmar (Test-Path -LiteralPath (Join-Path $a.AppW 'app\Mantem\do_servidor.php')) 'app/Mantem (nao vazio) foi removido'
    Afirmar (Test-Path -LiteralPath (Join-Path $a.AppW 'app\novo2.php')) 'app/novo2.php nao foi criado'
    foreach ($p in $sv.Keys) { Afirmar ((Get-Sha (Join-Path $a.AppW $p)) -eq $sv[$p]) "$p (so do servidor) foi tocado" }
    Afirmar ($r2.Saida -match ', 4 removido\(s\)') ('resumo sem "4 removido(s)": ' + $r2.Saida)
    foreach ($k in $ExtraVelhos.Keys) { Afirmar ($r2.Saida -match ('removido\s+' + [regex]::Escape($k))) "resumo nao lista $k" }
    Afirmar ($r2.Saida -match 'Removidos: 4 arquivo\(s\); diretorios que ficaram vazios removidos: 2') ('mensagem de remocao: ' + $r2.Saida)
    $m2 = Get-ManLinhas $a
    Afirmar ($m2.Count -eq 5 -and $m2 -contains 'app/novo2.php' -and -not ($m2 -contains 'app/velho.php') -and -not ($m2 -contains 'app/so_servidor.php')) ('manifesto novo: ' + ($m2 -join '|'))
    $bks = @(Get-Backups $a); Afirmar ($bks.Count -eq 1) ('backups: ' + $bks.Count)
    $lst = Get-TarLista $bks[0].FullName
    foreach ($k in $ExtraVelhos.Keys) { Afirmar ($lst -contains $k) "backup nao contem o removido $k" }
    Afirmar ($lst -contains '.deploy/manifesto_anterior.txt') ('backup sem o manifesto anterior: ' + ($lst -join '|'))
    $mb = Get-TarTexto $bks[0].FullName '.deploy/manifesto.txt'
    Afirmar ($mb -match '(?m)^R app/velho\.php$' -and $mb -match '(?m)^R app/Srv/Sub/y\.php$' -and $mb -match '(?m)^P 1$') ("manifesto do backup: $mb")
    $ant = Get-TarTexto $bks[0].FullName '.deploy/manifesto_anterior.txt'
    Afirmar ((($ant -split "`n") | Where-Object { $_ }).Count -eq 8 -and $ant -match 'app/velho\.php') ('manifesto anterior no backup: ' + $ant)
    # o conteudo guardado no backup e o do arquivo removido
    $dir = Join-Path $a.Base 'extr'; $null = New-Item -ItemType Directory -Path $dir -Force
    & $script:Bash -c "tar --force-local -xzf '$(ConvertTo-Misto $bks[0].FullName)' -C '$(ConvertTo-Misto $dir)' app/velho.php"
    Afirmar ((Get-Sha (Join-Path $dir 'app\velho.php')) -eq $conteudos['app/velho.php']) 'conteudo do removido no backup difere'
    Afirmar ((Get-Content -Raw (Join-Path $a.DepW 'deploy.log')) -match 'REMOVIDOS 4 arquivo') 'deploy.log sem a linha de remocao'
    Afirmar ($man1 -ne (Get-ManSha $a)) 'manifesto nao mudou'
} $true

Caso 'B18' '--rollback restaura os removidos byte a byte e o manifesto anterior (e volta a "sem manifesto" no rollback da primeira execucao)' {
    $a = New-Arvore
    $zip1 = Join-Path $a.Base 'r1.zip'; $sha1 = New-ZipOk $zip1
    $r1 = Invoke-Deploy $a $zip1 $sha1
    Afirmar ($r1.Codigo -eq 0 -and $r1.Saida -match 'PRIMEIRA EXECUCAO') ("deploy 1 (primeira execucao): " + $r1.Codigo + ": " + $r1.Saida)
    Initialize-Velhos $a
    $h1 = Get-HashArvore $a.AppW; $m1 = Get-ManSha $a
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/novo2.php' = "<?php echo 2;`n" }
    $r2 = Invoke-Deploy $a $zip2 $sha2
    Afirmar ($r2.Codigo -eq 0 -and $r2.Saida -match ', 4 removido\(s\)') ("deploy 2: " + $r2.Codigo + ": " + $r2.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -ne $h1 -and $m1 -ne (Get-ManSha $a)) 'deploy 2 nao mudou arvore e manifesto'
    $rb = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback')
    Afirmar ($rb.Codigo -eq 0) ("rollback: " + $rb.Codigo + ": " + $rb.Saida)
    Afirmar ($rb.Saida -match 'dos quais 4 removidos pelo deploy') ('contagem de restaurados: ' + $rb.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $h1) 'rollback nao restaurou a arvore (incluindo os removidos) byte a byte'
    Afirmar ((Get-ManSha $a) -eq $m1) 'rollback nao restaurou o manifesto anterior'
    Afirmar ($rb.Saida -match 'Manifesto de deploy anterior restaurado') ('mensagem do manifesto: ' + $rb.Saida)
    # rollback do deploy 1 (primeira execucao: nao havia manifesto): volta a "sem manifesto"
    $bk1 = ((Get-Backups $a | Sort-Object Name | Select-Object -First 1).BaseName) -replace '\.tar$', ''
    $rb1 = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback', $bk1)
    Afirmar ($rb1.Codigo -eq 0) ("rollback do deploy 1: " + $rb1.Codigo + ": " + $rb1.Saida)
    Afirmar ((Get-ManSha $a) -eq 'AUSENTE' -and $rb1.Saida -match 'Manifesto de deploy removido') 'rollback da primeira execucao nao removeu o manifesto'
} $true

Caso 'B19' 'falha de lint apos a remocao: rollback automatico restaura o removido byte a byte e o manifesto' {
    $a = New-Arvore
    Initialize-Velhos $a
    $h1 = Get-HashArvore $a.AppW; $m1 = Get-ManSha $a
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/quebrado.php' = "<?php echo ;`n" }
    $r2 = Invoke-Deploy $a $zip2 $sha2
    Afirmar ($r2.Codigo -eq 3) ("esperado 3, obtido " + $r2.Codigo + ": " + $r2.Saida)
    Afirmar ($r2.Saida -match 'Removidos: 4 arquivo') 'a remocao nao aconteceu antes do lint (teste sem efeito)'
    Afirmar ($r2.Saida -match 'Lint falhou em app/quebrado\.php') 'lint nao falhou onde deveria'
    Afirmar ((Get-HashArvore $a.AppW) -eq $h1) 'arvore diferente do estado anterior (removidos nao voltaram byte a byte)'
    Afirmar ((Get-ManSha $a) -eq $m1) 'manifesto mudou apesar do rollback'
    Afirmar ((Get-Content -Raw (Join-Path $a.DepW 'deploy.log')) -match 'ROLLBACK automatico concluido') 'log sem rollback automatico'
    Afirmar ((Get-Backups $a).Count -eq 1) 'esperado o backup do deploy que falhou'
} $true

Caso 'B20' 'dry-run lista "removeria" e nao altera nada (nem manifesto, nem DEPLOY_DIR)' {
    $a = New-Arvore
    Initialize-Velhos $a
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/novo2.php' = "<?php echo 2;`n" }
    $hA = Get-HashArvore $a.AppW; $hD = Get-HashArvore $a.DepW
    $r = Invoke-Deploy $a $zip2 $sha2 @('--dry-run')
    Afirmar ($r.Codigo -eq 0) ("dry-run: " + $r.Codigo + ": " + $r.Saida)
    foreach ($k in $ExtraVelhos.Keys) { Afirmar ($r.Saida -match ('removeria\s+' + [regex]::Escape($k))) "dry-run nao lista 'removeria $k'" }
    Afirmar ($r.Saida -match 'novo\s+app/novo2\.php' -and $r.Saida -match ', 4 removido\(s\)' -and -not ($r.Saida -match '\sremovido\s+app/')) 'lista de modificados/novos/removidos incorreta'
    Afirmar ((Get-HashArvore $a.AppW) -eq $hA) 'dry-run alterou APP_DIR'
    Afirmar ((Get-HashArvore $a.DepW) -eq $hD) 'dry-run alterou DEPLOY_DIR (manifesto, backups ou log)'
} $true

Caso 'B21' 'manifesto adulterado (db.php, data/x, ../x, ...) nao remove nada e aborta com 2; -SemRemocao nao le o manifesto' {
    $a = New-Arvore
    Write-Texto "$($a.AppW)\data\x" "dado`n"
    Write-Texto "$($a.Base)\x" "fora de APP_DIR`n"
    Write-Php "$($a.AppW)\app\velho.php" 'v'
    $ruins = @('config/db.php', 'config/secrets.local.php', 'data/x', '../x', 'app/../../x', '/etc/passwd', 'app\x.php', 'app', 'lib/x.php', 'app//x.php')
    Set-ManLinhas $a (@('app/velho.php') + $ruins)
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/novo2.php' = "<?php echo 2;`n" }
    $h = Get-HashArvore $a.AppW; $fora = Get-Sha (Join-Path $a.Base 'x'); $m0 = Get-ManSha $a
    $r = Invoke-Deploy $a $zip2 $sha2
    Afirmar ($r.Codigo -eq 2) ("esperado 2, obtido " + $r.Codigo + ": " + $r.Saida)
    Afirmar ($r.Saida -match '10 entrada\(s\) recusada\(s\)') ('contagem de entradas recusadas: ' + $r.Saida)
    foreach ($ruim in $ruins) {
        $esp = $ruim.Replace('\', '\\')   # printf %q duplica a barra invertida
        Afirmar ($r.Saida.Contains("entrada recusada: $esp")) ("a entrada '$ruim' nao foi recusada: " + $r.Saida)
    }
    Afirmar (-not ($r.Saida -match 'entrada recusada: app/velho\.php')) 'recusou a entrada legitima'
    Afirmar ((Get-HashArvore $a.AppW) -eq $h) 'a arvore mudou (algo foi removido ou aplicado)'
    Afirmar ((Get-Backups $a).Count -eq 0) 'criou backup'
    Afirmar ((Get-Sha (Join-Path $a.Base 'x')) -eq $fora) 'tocou em arquivo fora de APP_DIR'
    foreach ($p in 'config\db.php', 'config\secrets.local.php', 'data\x', 'app\velho.php') { Afirmar (Test-Path -LiteralPath (Join-Path $a.AppW $p)) "$p sumiu" }
    Afirmar ((Get-ManSha $a) -eq $m0) 'o abort alterou o manifesto'
    # --sem-remocao nao le o manifesto: o mesmo manifesto adulterado nao impede o deploy
    $rs = Invoke-Deploy $a $zip2 $sha2 @('--sem-remocao')
    Afirmar ($rs.Codigo -eq 0 -and (Test-Path -LiteralPath (Join-Path $a.AppW 'app\velho.php')) -and (Get-ManSha $a) -eq $m0) ('--sem-remocao com manifesto adulterado: ' + $rs.Codigo + ': ' + $rs.Saida)
} $true

Caso 'B22' 'trava: mais de 60 orfaos (padrao), limite configuravel, menos de 50% do manifesto; -PermitirRemocaoGrande ultrapassa' {
    $zip = Join-Path $T 'b22.zip'; $sha = New-ZipOk $zip
    # (a) padrao 60: manifesto com 62 orfaos (inexistentes no servidor); tambem fere os 50%
    $a = New-Arvore
    Set-ManLinhas $a ($PkgBase + @(1..62 | ForEach-Object { 'app/orfao{0:000}.php' -f $_ }))
    $h = Get-HashArvore $a.AppW; $hm = Get-ManSha $a
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 2 -and $r.Saida -match '62 orfao\(s\) para remover \(limite 60\)') ('padrao 60: ' + $r.Codigo + ': ' + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $h -and (Get-ManSha $a) -eq $hm) 'a trava alterou arvore ou manifesto'
    Afirmar ((Get-Backups $a).Count -eq 0) 'a trava criou backup'
    # (b) so a quantidade: 3 orfaos reais; limite 2 aborta, limite 3 (igual) remove
    $b = New-Arvore
    foreach ($i in 1..3) { Write-Php "$($b.AppW)\app\o$i.php" "o$i" }
    Set-ManLinhas $b ($PkgBase + @('app/o1.php', 'app/o2.php', 'app/o3.php'))
    $hb = Get-HashArvore $b.AppW
    $rb = Invoke-Deploy $b $zip $sha @('--limite-remocao', '2')
    Afirmar ($rb.Codigo -eq 2 -and $rb.Saida -match 'limite 2' -and -not ($rb.Saida -match '50%')) ('limite 2: ' + $rb.Codigo + ': ' + $rb.Saida)
    Afirmar ((Get-HashArvore $b.AppW) -eq $hb) 'limite 2 alterou a arvore'
    $rb3 = Invoke-Deploy $b $zip $sha @('--limite-remocao', '3')
    Afirmar ($rb3.Codigo -eq 0 -and -not (Test-Path (Join-Path $b.AppW 'app\o1.php')) -and -not (Test-Path (Join-Path $b.AppW 'app\o3.php'))) ('limite 3 deveria remover: ' + $rb3.Codigo + ': ' + $rb3.Saida)
    # (c) 50%: manifesto com 9 arquivos, pacote com 4 (5 orfaos, abaixo do limite); -PermitirRemocaoGrande ultrapassa
    $c = New-Arvore
    foreach ($i in 1..5) { Write-Php "$($c.AppW)\app\p$i.php" "p$i" }
    Set-ManLinhas $c ($PkgBase + @(1..5 | ForEach-Object { "app/p$_.php" }))
    $hc = Get-HashArvore $c.AppW
    $rc = Invoke-Deploy $c $zip $sha
    Afirmar ($rc.Codigo -eq 2 -and $rc.Saida -match 'menos de 50% dos 9' -and -not ($rc.Saida -match 'limite 60')) ('50%: ' + $rc.Codigo + ': ' + $rc.Saida)
    Afirmar ((Get-HashArvore $c.AppW) -eq $hc) '50% alterou a arvore'
    $rcg = Invoke-Deploy $c $zip $sha @('--permitir-remocao-grande')
    Afirmar ($rcg.Codigo -eq 0 -and -not (Test-Path (Join-Path $c.AppW 'app\p1.php')) -and -not (Test-Path (Join-Path $c.AppW 'app\p5.php'))) ('50% com permitir: ' + $rcg.Codigo + ': ' + $rcg.Saida)
} $true

Caso 'B23' '-SemRemocao: nao remove e mantem o manifesto como esta; combinacoes invalidas recusadas' {
    $a = New-Arvore
    Initialize-Velhos $a
    $m1 = Get-ManSha $a
    $zip2 = Join-Path $a.Base 'r2.zip'; $sha2 = New-ZipOk $zip2 @{ 'app/novo2.php' = "<?php echo 2;`n" }
    $r = Invoke-Deploy $a $zip2 $sha2 @('--sem-remocao')
    Afirmar ($r.Codigo -eq 0 -and $r.Saida -match 'DESLIGADA') ('sem-remocao: ' + $r.Codigo + ': ' + $r.Saida)
    foreach ($k in $ExtraVelhos.Keys) { Afirmar (Test-Path -LiteralPath (Join-Path $a.AppW ($k.Replace('/', '\')))) "$k foi removido com --sem-remocao" }
    Afirmar (Test-Path (Join-Path $a.AppW 'app\novo2.php')) 'o deploy normal nao foi aplicado'
    Afirmar ((Get-ManSha $a) -eq $m1) 'o manifesto mudou com --sem-remocao'
    Afirmar ($r.Saida -match ', 0 removido\(s\)') 'resumo sem a contagem'
    $x = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--sem-remocao', '--permitir-remocao-grande', (ConvertTo-Misto $zip2), $sha2)
    Afirmar ($x.Codigo -eq 2) ('combinacao invalida: ' + $x.Codigo)
    $y = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback', '--sem-remocao')
    Afirmar ($y.Codigo -eq 2) ('--sem-remocao com --rollback: ' + $y.Codigo)
} $true

Caso 'B24' 'so remocoes (servidor ja igual ao pacote): rmdir de vazios, nao vazio fica, pasta-raiz e dir vazio nao relacionado ficam; rollback devolve tudo' {
    $a = New-Arvore
    # servidor identico ao pacote (nada modificado nem novo): so ha orfaos
    Write-Texto "$($a.AppW)\app\a.php" "<?php echo `"novo`";`n"
    Write-Texto "$($a.AppW)\app\novo.php" "<?php echo 2;`n"
    Write-Texto "$($a.AppW)\deploy\remoto\x.sh" "#!/bin/bash`necho x`n"
    foreach ($p in 'app\Srv\Sub\y.php', 'app\Srv\x.php', 'app\Keep\z.php', 'db\unico.php') { Write-Php (Join-Path $a.AppW $p) 'q' }
    Write-Php "$($a.AppW)\app\Keep\so_servidor.php" 's'
    $null = New-Item -ItemType Directory -Path "$($a.AppW)\app\vazio_antigo" -Force
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    Set-ManLinhas $a ($PkgBase + @('app/Srv/Sub/y.php', 'app/Srv/x.php', 'app/Keep/z.php', 'db/unico.php'))
    $sv = Get-Sha "$($a.AppW)\app\Keep\so_servidor.php"
    $h0 = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 0 -and -not ($r.Saida -match 'Nada a aplicar')) ("deploy: " + $r.Codigo + ": " + $r.Saida)
    Afirmar ($r.Saida -match 'Resumo: 0 modificado\(s\), 0 novo\(s\)') ('esperado so remocoes: ' + $r.Saida)
    Afirmar (-not (Test-Path "$($a.AppW)\app\Srv")) 'app/Srv e app/Srv/Sub (vazios) deveriam ter sido removidos'
    Afirmar ((Test-Path "$($a.AppW)\app\Keep\so_servidor.php") -and (Get-Sha "$($a.AppW)\app\Keep\so_servidor.php") -eq $sv) 'app/Keep (nao vazio) deveria ficar intacto'
    Afirmar (-not (Test-Path "$($a.AppW)\app\Keep\z.php")) 'app/Keep/z.php (orfao) deveria sumir'
    Afirmar ((Test-Path "$($a.AppW)\db") -and @(Get-ChildItem "$($a.AppW)\db" -Force).Count -eq 0) 'a pasta-raiz db deveria ficar (vazia)'
    Afirmar (Test-Path "$($a.AppW)\app\vazio_antigo") 'dir vazio sem relacao com a remocao foi apagado'
    Afirmar ($r.Saida -match 'Removidos: 4 arquivo\(s\); diretorios que ficaram vazios removidos: 2') ('contagem: ' + $r.Saida)
    Afirmar ((Get-ManLinhas $a).Count -eq 4) 'manifesto novo deveria ter so os 4 do pacote'
    # rollback devolve tudo, inclusive os diretorios
    $rb = Invoke-Remoto @{ APP_DIR = $a.App; DEPLOY_DIR = $a.Dep } @('--rollback')
    Afirmar ($rb.Codigo -eq 0) ('rollback: ' + $rb.Codigo + ': ' + $rb.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $h0) 'rollback nao devolveu a arvore (arquivos e diretorios) ao estado anterior'
} $true

Caso 'B25' 'orfao que virou diretorio: aborta com 2 sem remover nada' {
    $a = New-Arvore
    $zip = Join-Path $a.Base 'r.zip'; $sha = New-ZipOk $zip
    Write-Php "$($a.AppW)\app\o1.php" 'o'
    $null = New-Item -ItemType Directory -Path "$($a.AppW)\app\virou_dir.php" -Force
    Set-ManLinhas $a ($PkgBase + @('app/o1.php', 'app/virou_dir.php'))
    $h = Get-HashArvore $a.AppW
    $r = Invoke-Deploy $a $zip $sha
    Afirmar ($r.Codigo -eq 2 -and $r.Saida -match 'nao e arquivo regular') ('diretorio no lugar do orfao: ' + $r.Codigo + ': ' + $r.Saida)
    Afirmar ((Get-HashArvore $a.AppW) -eq $h) 'removeu algo apesar do abort'
} $true

Caso 'B26' 'validar_base (extraida do script) com caminhos de estilo Linux' {
    $txt = [System.IO.File]::ReadAllText((Join-Path $PSScriptRoot 'remoto\aplicar_release.sh'))
    $m = [regex]::Match($txt, '(?ms)^validar_base\(\) \{.*?^\}')
    Afirmar $m.Success 'funcao validar_base nao encontrada no script'
    $aceitos = @('/apps/metricas_jca', '/apps/_deploy', '/apps/a/b/c', 'C:/x/y')
    $recusados = @('/var/x/y', '/var/lib', '/tmp/x/y', '/apps/../x', '/apps/x/..', '/apps//x', '/apps', '/apps/', '/apps/x/', '/', '', 'relativo/x', '/apps/a b/c', '/apps/a;b/c')
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append("erro() { :; }`n" + $m.Value + "`n")
    [void]$sb.Append("t() { if validar_base T `"`$1`" >/dev/null 2>&1; then echo `"OK|`$1`"; else echo `"NO|`$1`"; fi; }`n")
    foreach ($c in ($aceitos + $recusados)) { [void]$sb.Append("t '" + $c + "'`n") }
    $sh = Join-Path $T 'validar_base_teste.sh'
    Write-Texto $sh $sb.ToString()
    $salvo = [Environment]::GetEnvironmentVariable('MSYS_NO_PATHCONV')
    try { [Environment]::SetEnvironmentVariable('MSYS_NO_PATHCONV', '1'); $out = @(& $script:Bash (ConvertTo-Misto $sh) 2>&1 | ForEach-Object { "$_" }) } finally { [Environment]::SetEnvironmentVariable('MSYS_NO_PATHCONV', $salvo) }
    foreach ($c in $aceitos) { Afirmar ($out -contains "OK|$c") "deveria ACEITAR '$c': $($out -join ' ')" }
    foreach ($c in $recusados) { Afirmar ($out -contains "NO|$c") "deveria RECUSAR '$c': $($out -join ' ')" }
} $true

} finally {
    Remove-Seguro $T
}

Write-Host ''
Write-Host ("Resumo: {0} passou, {1} falhou, {2} pulado(s)." -f $script:Pass, $script:Fail, $script:Pulados)
if ($script:Fail -gt 0) { exit 1 }
if ($script:Pulados -gt 0) { Write-Host 'ATENCAO: casos pulados (bash ausente); isto NAO e sucesso total.' -ForegroundColor Yellow; exit 3 }
exit 0
