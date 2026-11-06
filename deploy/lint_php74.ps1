<#
.SYNOPSIS
  Roda `php -l` (PHP 7.4) em todos os .php de app/, public/, config/, bin/ e db/.
.DESCRIPTION
  Exclui config/secrets.local.php e config/db.php (segredos). Imprime as falhas e o
  total. Sai com codigo 1 se houver alguma falha.
.PARAMETER Php
  Caminho do php.exe 7.4 (padrao: $env:TEMP\php74\php.exe).
.EXAMPLE
  powershell -NoProfile -File deploy\lint_php74.ps1
#>
param(
    [string]$Php = (Join-Path $env:TEMP 'php74\php.exe')
)

if (-not (Test-Path $Php)) {
    Write-Host "php.exe nao encontrado: $Php"
    exit 2
}

$root = Split-Path -Parent $PSScriptRoot
$excluir = @('secrets.local.php', 'db.php')
$arquivos = foreach ($d in 'app', 'public', 'config', 'bin', 'db') {
    $p = Join-Path $root $d
    if (Test-Path $p) {
        Get-ChildItem -Path $p -Filter *.php -Recurse -File |
            Where-Object { -not (($_.DirectoryName -eq (Join-Path $root 'config')) -and ($excluir -contains $_.Name)) }
    }
}

$falhas = 0
$total = 0
foreach ($f in $arquivos) {
    $total++
    $saida = & $Php -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $falhas++
        Write-Host "FALHA: $($f.FullName)"
        $saida | ForEach-Object { Write-Host "  $_" }
    }
}

& $Php -v | Select-Object -First 1 | ForEach-Object { Write-Host $_ }
Write-Host "Total: $total arquivo(s); falhas: $falhas"
if ($falhas -gt 0) { exit 1 }
exit 0
