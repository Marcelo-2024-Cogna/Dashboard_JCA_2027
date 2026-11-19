<#
.SYNOPSIS
  Deploy do Metricas Jeito Cogna Agil para o servidor de homologacao.
.DESCRIPTION
  Monta o pacote (git archive da referencia, respeitando deploy\excluir.txt),
  verifica CRLF e segredos, envia por scp (1 conexao) e executa
  deploy\remoto\aplicar_release.sh por ssh com sudo (1 conexao). Funciona com
  senha (ssh/scp interativos) ou chave. Nao grava host/usuario em nenhum arquivo.

  O alvo vem de (nesta ordem): -Alvo, variavel de ambiente JCA_DEPLOY_ALVO,
  arquivo deploy\deploy.local.ps1 (ignorado pelo git; modelo em
  deploy\deploy.local.ps1.exemplo).

  O pacote sai do COMMIT da referencia, nao do working tree. Faca commit antes.

  Codigos de saida: 0 ok; 1 erro local (git, lint, pacote); 2 uso invalido;
  10 falha no scp; 20+N falha remota com codigo N do aplicar_release.sh
  (21 erro, 22 invalido, 23 rollback automatico, 24 rollback falhou, 25 lock,
  26 aplicado, mas com falha de dono/permissao em algum arquivo);
  sem alvo/sem confirmacao: 2.
.PARAMETER Alvo          host ou usuario@host.
.PARAMETER DryRun        monta o pacote e pede ao servidor so a lista do que mudaria.
.PARAMETER Rollback      restaura o backup mais recente (ou o de -Timestamp).
.PARAMETER Timestamp     AAAAMMDD-HHMMSS do backup (com -Rollback).
.PARAMETER Referencia    commit, tag ou branch (padrao HEAD).
.PARAMETER SemTestes     pula o lint PHP local (o lint remoto sempre roda).
.PARAMETER Forcar        permite working tree sujo (avisa em vez de abortar).
.PARAMETER LimparCache   forca a limpeza do cache de resposta.
.PARAMETER ManterCache   nao limpa o cache.
.PARAMETER SomentePacote monta e verifica o pacote, imprime o caminho e termina.
.PARAMETER Sim           nao pede confirmacao.
.PARAMETER SemChecagemEspaco  pula a consulta previa (1 ssh extra) ao espaco livre de /tmp no servidor.
.PARAMETER Saida         pasta dos pacotes (padrao data\deploy\pacotes; ignorada pelo git).
.PARAMETER SmokeUrl      URL base do smoke test no servidor (padrao http://localhost).
.PARAMETER SmokeHost     cabecalho Host do smoke test (opcional).
.PARAMETER RepoRaiz      uso interno do teste: raiz do repositorio (padrao: este repo).
.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File deploy\deploy.ps1 -Alvo usuario@servidor -DryRun
#>
[CmdletBinding()]
param(
    [string]$Alvo,
    [switch]$DryRun,
    [switch]$Rollback,
    [string]$Timestamp,
    [string]$Referencia = 'HEAD',
    [switch]$SemTestes,
    [switch]$Forcar,
    [switch]$LimparCache,
    [switch]$ManterCache,
    [switch]$SomentePacote,
    [switch]$Sim,
    [switch]$SemChecagemEspaco,
    [string]$Saida,
    [string]$SmokeUrl,
    [string]$SmokeHost,
    [string]$RepoRaiz
)

$ErrorActionPreference = 'Stop'
$script:Raiz = Split-Path -Parent $PSScriptRoot
if (-not $RepoRaiz) { $RepoRaiz = $script:Raiz }
Add-Type -AssemblyName System.IO.Compression.FileSystem

# ---------------------------------------------------------------- utilitarios
function Write-Info($m)  { Write-Host "[INFO] $m" }
function Write-Aviso($m) { Write-Host "[AVISO] $m" -ForegroundColor Yellow }
function Write-Erro($m)  { Write-Host "[ERRO] $m" -ForegroundColor Red }

function Stop-Deploy([string]$Mensagem, [int]$Codigo = 1) {
    Write-Erro $Mensagem
    exit $Codigo
}

function Resolve-Ferramenta([string]$Nome) {
    $c = Get-Command $Nome -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $c) { Stop-Deploy "Ferramenta '$Nome' nao encontrada no PATH. Instale-a (git; OpenSSH do Windows para ssh/scp)." 1 }
    return $c.Source
}

# Executa o git no repositorio; devolve as linhas de saida e grava $script:GitCodigo.
function Invoke-Git([string[]]$Argumentos) {
    $antes = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $saida = & $script:Git -C $RepoRaiz @Argumentos 2>&1
        $script:GitCodigo = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $antes
    }
    return ,@($saida | ForEach-Object { "$_" })
}

function Get-Sha256([string]$Arquivo) {
    return (Get-FileHash -LiteralPath $Arquivo -Algorithm SHA256).Hash.ToLowerInvariant()
}

# ---------------------------------------------------------------- configuracao
function Get-ConfigLocal {
    $cfg = @{}
    $arq = Join-Path $PSScriptRoot 'deploy.local.ps1'
    if (Test-Path -LiteralPath $arq) {
        . $arq
        if (Get-Variable -Name JcaDeployAlvo -ErrorAction SilentlyContinue) { $cfg.Alvo = $JcaDeployAlvo }
        if (Get-Variable -Name JcaSmokeUrl -ErrorAction SilentlyContinue)   { $cfg.SmokeUrl = $JcaSmokeUrl }
        if (Get-Variable -Name JcaSmokeHost -ErrorAction SilentlyContinue)  { $cfg.SmokeHost = $JcaSmokeHost }
        if (Get-Variable -Name JcaPhpLint -ErrorAction SilentlyContinue)    { $cfg.PhpLint = $JcaPhpLint }
    }
    return $cfg
}

function Test-Alvo([string]$Valor) {
    return ($Valor -match '^[A-Za-z0-9._-]+(@[A-Za-z0-9._-]+)?$') -and (-not $Valor.StartsWith('-'))
}

# ---------------------------------------------------------------- git
function Test-ArvoreLimpa {
    # so linhas de status (o stderr do git, como avisos de CRLF, vem misturado)
    $sujo = @(Invoke-Git @('status', '--porcelain', '--untracked-files=no') | Where-Object { $_ -match '^[ MADRCUT?!]{2} ' })
    if ($sujo.Count -eq 0) { return $true }
    $msg = "Working tree com alteracoes nao commitadas em arquivos versionados ($($sujo.Count)). O pacote sai do COMMIT da referencia, nao do disco."
    if ($Forcar) { Write-Aviso "$msg (seguindo por causa de -Forcar)"; return $true }
    Stop-Deploy "$msg Faca commit/stash ou use -Forcar." 1
}

function Test-HeadEmOrigin([string]$Sha) {
    $null = Invoke-Git @('rev-parse', '--verify', '--quiet', 'origin/main')
    if ($script:GitCodigo -ne 0) { Write-Aviso 'origin/main nao encontrado localmente; nao foi possivel conferir se a referencia foi enviada ao remoto.'; return }
    $null = Invoke-Git @('merge-base', '--is-ancestor', $Sha, 'origin/main')
    if ($script:GitCodigo -ne 0) {
        Write-Aviso 'A referencia NAO esta em origin/main (push pendente ou origin/main desatualizado; nao foi feito fetch).'
    } else {
        Write-Info 'A referencia esta contida em origin/main (conforme o ultimo fetch).'
    }
}

# ---------------------------------------------------------------- pacote
function Get-Pathspecs {
    $lista = New-Object System.Collections.Generic.List[string]
    $arq = Join-Path $script:Raiz 'deploy\excluir.txt'
    if (-not (Test-Path -LiteralPath $arq)) { Stop-Deploy "deploy\excluir.txt nao encontrado." 1 }
    foreach ($linha in (Get-Content -LiteralPath $arq -Encoding UTF8)) {
        $l = $linha.Trim()
        if ($l -eq '' -or $l.StartsWith('#')) { continue }
        if ($l.StartsWith('+ ')) { continue }   # excecoes: data/ ja e excluido por inteiro
        if ($l.StartsWith('- ')) { $l = $l.Substring(2).Trim() }
        if ($l.StartsWith('/')) {
            $p = $l.Substring(1)
            if ($p.EndsWith('/')) { $p = $p + '**' }
        } else {
            $p = '**/' + $l
        }
        $lista.Add(":(exclude,glob)$p")
    }
    # fixos: dados e arquivos pessoais/locais nunca vao
    foreach ($p in 'data/**', 'deploy/deploy.local.ps1', 'jql-jira.bash', 'virtualHost.bash', '*.code-workspace') {
        $lista.Add(":(exclude,glob)$p")
    }
    return $lista.ToArray()
}

function New-Pacote([string]$Sha, [string]$PastaSaida) {
    if (-not (Test-Path -LiteralPath $PastaSaida)) { $null = New-Item -ItemType Directory -Path $PastaSaida -Force }
    $ts = Get-Date -Format 'yyyyMMdd-HHmmss'
    $zip = Join-Path $PastaSaida ("jca_release_{0}_{1}.zip" -f $ts, $Sha.Substring(0, 7))
    $args2 = @('-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=zip', '-o', $zip, $Sha, '--', '.') + (Get-Pathspecs)
    $saida = Invoke-Git $args2
    if ($script:GitCodigo -ne 0 -or -not (Test-Path -LiteralPath $zip)) {
        Stop-Deploy ("git archive falhou: " + ($saida -join ' ')) 1
    }
    return $zip
}

function Read-EntradaBytes($Entrada) {
    $ms = New-Object System.IO.MemoryStream
    $s = $Entrada.Open()
    try { $s.CopyTo($ms) } finally { $s.Dispose() }
    return $ms.ToArray()
}

# Devolve a lista de problemas (vazia = pacote ok). Nunca imprime valores de segredos.
function Test-Pacote([string]$Zip) {
    $problemas = New-Object System.Collections.Generic.List[string]
    $proibidosExatos = @('config/db.php', 'config/secrets.local.php', 'bin/seed_catalogo.php', 'bin/seed_sustentacao.php',
                         'jql-jira.bash', 'virtualHost.bash', 'deploy/deploy.local.ps1')
    $proibidosPrefixo = @('data/', '.git/', '.kiro/', '.agents/', 'Plataforma-Engenharia-Performance/', 'dashboard-v1.0/')
    $extTexto = @('.php', '.json', '.md', '.txt', '.sh', '.ps1', '.conf', '.cron', '.sql', '.example', '.exemplo', '.yml', '.bash')
    $padroes = @{
        'token Atlassian'  = ('ATA' + 'TT' + '[A-Za-z0-9_=-]{10,}')
        'chave privada'    = ('BEGIN (RSA |EC |OPENSSH |DSA )?PRIV' + 'ATE KEY')
    }
    $obrigatorios = @('public/index.php', 'config/visao_geral.php', 'deploy/remoto/aplicar_release.sh')
    $nomes = New-Object System.Collections.Generic.HashSet[string]
    $total = 0
    $arq = [System.IO.Compression.ZipFile]::OpenRead($Zip)
    try {
        foreach ($e in $arq.Entries) {
            $n = $e.FullName
            if ($n.EndsWith('/')) { continue }
            $total++
            $null = $nomes.Add($n)
            if ($n.Contains('\')) { $problemas.Add("Nome com barra invertida no zip: $n") }
            if ($proibidosExatos -contains $n) { $problemas.Add("Pacote contem arquivo proibido: $n") }
            foreach ($p in $proibidosPrefixo) {
                if ($n.StartsWith($p, [System.StringComparison]::OrdinalIgnoreCase)) { $problemas.Add("Pacote contem caminho proibido: $n") }
            }
            $ehScript = $n.EndsWith('.sh') -or $n.StartsWith('deploy/')
            $ext = [System.IO.Path]::GetExtension($n).ToLowerInvariant()
            $ehTexto = $extTexto -contains $ext
            if (($ehScript -or $ehTexto) -and $e.Length -le 5MB) {
                $bytes = Read-EntradaBytes $e
                if ($ehScript -and ($bytes -contains 13)) {
                    $problemas.Add("Pacote contem CR (CRLF) em ${n}; verifique .gitattributes/core.autocrlf.")
                }
                if ($ehTexto) {
                    $texto = [System.Text.Encoding]::UTF8.GetString($bytes)
                    foreach ($k in $padroes.Keys) {
                        if ($texto -cmatch $padroes[$k]) { $problemas.Add("Possivel segredo ($k) em ${n}.") }
                    }
                }
            }
        }
    } finally {
        $arq.Dispose()
    }
    foreach ($o in $obrigatorios) {
        if (-not $nomes.Contains($o)) {
            $dica = ''
            if ($o -like 'deploy/remoto/*') { $dica = ' (os arquivos de deploy precisam estar commitados na referencia)' }
            $problemas.Add("Entrada obrigatoria ausente no pacote: $o$dica")
        }
    }
    if (-not ($nomes | Where-Object { $_.StartsWith('app/') })) { $problemas.Add('Entrada obrigatoria ausente no pacote: app/') }
    $script:PacoteArquivos = $total
    return @($problemas)
}

function Get-PhpLocal {
    $cfg = $script:ConfigLocal
    foreach ($c in @($env:JCA_PHP, $cfg.PhpLint, 'C:\xampp\php\php.exe')) {
        if ($c -and (Test-Path -LiteralPath $c)) { return $c }
    }
    $g = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($g) { return $g.Source }
    return $null
}

# Lint do conteudo do PACOTE (extraido numa pasta temporaria), nao do working tree.
function Invoke-LintLocal([string]$Zip, [string]$PastaTmp) {
    $php = Get-PhpLocal
    if (-not $php) { Stop-Deploy 'PHP local nao encontrado (defina JCA_PHP ou use -SemTestes).' 1 }
    $dest = Join-Path $PastaTmp ('lint_' + [guid]::NewGuid().ToString('N'))
    try {
        [System.IO.Compression.ZipFile]::ExtractToDirectory($Zip, $dest)
        $arquivos = @(Get-ChildItem -LiteralPath $dest -Filter *.php -Recurse -File)
        $falhas = 0
        foreach ($f in $arquivos) {
            $saida = & $php -l $f.FullName 2>&1
            if ($LASTEXITCODE -ne 0) {
                $falhas++
                Write-Erro ("Lint falhou em " + $f.FullName.Substring($dest.Length + 1))
                $saida | ForEach-Object { Write-Host "  $_" }
            }
        }
        Write-Info ("Lint PHP local ($php): {0} arquivo(s), {1} falha(s)." -f $arquivos.Count, $falhas)
        if ($falhas -gt 0) { Stop-Deploy 'Lint PHP local falhou; deploy abortado.' 1 }
    } finally {
        if ((Test-Path -LiteralPath $dest) -and $dest.StartsWith($PastaTmp)) { Remove-Item -LiteralPath $dest -Recurse -Force -ErrorAction SilentlyContinue }
    }
    # PHP 7.4 de teste (le o working tree do repo deste script)
    $lint74 = Join-Path $PSScriptRoot 'lint_php74.ps1'
    $php74 = Join-Path $env:TEMP 'php74\php.exe'
    if ((Test-Path -LiteralPath $lint74) -and (Test-Path -LiteralPath $php74) -and ($RepoRaiz -eq $script:Raiz)) {
        Write-Info 'Lint PHP 7.4 (deploy\lint_php74.ps1; le o working tree, nao o pacote)...'
        & powershell -NoProfile -ExecutionPolicy Bypass -File $lint74 -Php $php74
        if ($LASTEXITCODE -ne 0) { Stop-Deploy 'Lint PHP 7.4 falhou; deploy abortado.' 1 }
    } else {
        Write-Aviso 'PHP 7.4 de teste ($env:TEMP\php74\php.exe) ou lint_php74.ps1 ausente; so o PHP local foi usado.'
    }
}

# Copia o script remoto sem CR (so LF) para a pasta de envio.
function Copy-ScriptRemoto([string]$Destino) {
    $origem = Join-Path $script:Raiz 'deploy\remoto\aplicar_release.sh'
    if (-not (Test-Path -LiteralPath $origem)) { Stop-Deploy 'deploy\remoto\aplicar_release.sh nao encontrado.' 1 }
    $bytes = [System.IO.File]::ReadAllBytes($origem)
    if ($bytes -contains 13) {
        Write-Aviso 'aplicar_release.sh local tem CR; enviando copia normalizada para LF.'
        $bytes = [byte[]]@($bytes | Where-Object { $_ -ne 13 })
    }
    [System.IO.File]::WriteAllBytes($Destino, $bytes)
}

function Get-MensagemCodigo([int]$Codigo) {
    switch ($Codigo) {
        0 { return 'Concluido com sucesso (ou nada a aplicar).' }
        1 { return 'Erro inesperado no servidor.' }
        2 { return 'Argumento, hash, caminho ou conteudo do pacote invalido. Nada foi alterado.' }
        3 { return 'Lint ou smoke test falhou no servidor; o rollback automatico foi concluido (estado anterior restaurado).' }
        4 { return 'ROLLBACK FALHOU. Intervencao manual necessaria; o backup foi preservado em /apps/_deploy/backups.' }
        5 { return 'Outro deploy esta em andamento no servidor (lock). Tente novamente depois.' }
        6 { return 'Deploy APLICADO e validado, mas chown/chmod falhou em algum arquivo (ver os [AVISO] acima). Corrija dono/modo manualmente; nao houve rollback.' }
        255 { return 'Falha de conexao/autenticacao ssh (ou sudo recusado).' }
        default { return "Codigo remoto inesperado: $Codigo." }
    }
}

# Comando executado no servidor (ssh). Antes do sudo, confere que o script enviado
# e arquivo regular, nao e link simbolico, pertence ao usuario do deploy, nao e
# gravavel por grupo/outros e tem o sha256 calculado aqui no Windows.
# Sem aspas duplas (o PowerShell 5.1 as remove ao chamar programas nativos).
function New-ComandoRemoto([string]$RemotoScript, [string]$RemotoZip, [string]$ShaScript, [string[]]$Opcoes) {
    $verif = 'f=' + $RemotoScript + '; h=' + $ShaScript + '; ' +
             '[ -f $f ] && [ ! -L $f ] && [ -O $f ] && [ -z $(find $f -maxdepth 0 -perm /022) ] && ' +
             '[ $(sha256sum -- $f | awk ''{print $1}'') = $h ] || ' +
             '{ echo ERRO: script remoto ausente, link, de outro dono, gravavel por outros ou com hash diferente. Nada foi executado. >&2; exit 2; }; '
    $limpeza = 'rm -f ' + $RemotoScript + $(if ($RemotoZip) { ' ' + $RemotoZip } else { '' })
    return ($verif + 'sudo bash ' + $RemotoScript + ' ' + ($Opcoes -join ' ') + '; rc=$?; ' + $limpeza + '; exit $rc')
}

# ---------------------------------------------------------------- principal
function Invoke-Principal {
    $script:ConfigLocal = Get-ConfigLocal
    $cfg = $script:ConfigLocal

    # validacoes de entrada (antes de qualquer acao)
    if ($Referencia -notmatch '^[A-Za-z0-9._/@~^-]+$' -or $Referencia.StartsWith('-')) { Stop-Deploy "Referencia invalida: $Referencia" 2 }
    if ($Timestamp -and $Timestamp -notmatch '^[0-9]{8}-[0-9]{6}$') { Stop-Deploy "Timestamp invalido (use AAAAMMDD-HHMMSS): $Timestamp" 2 }
    if ($Timestamp -and -not $Rollback) { Stop-Deploy '-Timestamp so vale com -Rollback.' 2 }
    if ($LimparCache -and $ManterCache) { Stop-Deploy 'Use -LimparCache ou -ManterCache, nao os dois.' 2 }
    if ($Rollback -and $SomentePacote) { Stop-Deploy '-Rollback e -SomentePacote nao combinam.' 2 }
    if (-not $SmokeUrl -and $cfg.SmokeUrl)   { $script:SmokeUrl = $cfg.SmokeUrl }
    if (-not $SmokeHost -and $cfg.SmokeHost) { $script:SmokeHost = $cfg.SmokeHost }
    if ($SmokeUrl -and $SmokeUrl -notmatch '^https?://[A-Za-z0-9._:/-]+$') { Stop-Deploy "SmokeUrl invalida: $SmokeUrl" 2 }
    if ($SmokeHost -and $SmokeHost -notmatch '^[A-Za-z0-9._:-]+$') { Stop-Deploy "SmokeHost invalido: $SmokeHost" 2 }

    $alvoFinal = $null
    if (-not $SomentePacote) {
        $alvoFinal = $Alvo
        if (-not $alvoFinal) { $alvoFinal = $env:JCA_DEPLOY_ALVO }
        if (-not $alvoFinal) { $alvoFinal = $cfg.Alvo }
        if (-not $alvoFinal) {
            Stop-Deploy ("Alvo do deploy nao informado. Use -Alvo usuario@servidor, ou a variavel de ambiente JCA_DEPLOY_ALVO, " +
                         "ou crie deploy\deploy.local.ps1 a partir de deploy\deploy.local.ps1.exemplo.") 2
        }
        if (-not (Test-Alvo $alvoFinal)) { Stop-Deploy "Alvo invalido (use host ou usuario@host, sem espacos nem opcoes): $alvoFinal" 2 }
    }

    $script:Git = Resolve-Ferramenta 'git'
    if (-not $SomentePacote) { $ssh = Resolve-Ferramenta 'ssh'; $scp = Resolve-Ferramenta 'scp' }

    $ts = Get-Date -Format 'yyyyMMdd-HHmmss'
    if (-not $Saida) { $Saida = Join-Path $script:Raiz 'data\deploy\pacotes' }
    if (-not (Test-Path -LiteralPath $Saida)) { $null = New-Item -ItemType Directory -Path $Saida -Force }
    $envio = Join-Path $Saida "envio_$ts"

    $zipEnvio = $null
    $sha = ''
    $sha256 = ''
    $tamanho = 0
    if (-not $Rollback) {
        $r = Invoke-Git @('rev-parse', '--verify', "$Referencia^{commit}")
        if ($script:GitCodigo -ne 0) { Stop-Deploy "Referencia nao encontrada: $Referencia" 1 }
        $sha = $r[0].Trim()
        Write-Info "Referencia: $Referencia ($($sha.Substring(0, 7)))"
        $null = Test-ArvoreLimpa
        Test-HeadEmOrigin $sha

        $zip = New-Pacote $sha $Saida
        $problemas = @(Test-Pacote $zip)
        if ($problemas.Count -gt 0) {
            $problemas | ForEach-Object { Write-Erro $_ }
            Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
            Stop-Deploy 'Pacote reprovado na verificacao; nada foi enviado.' 1
        }
        if ($SemTestes) { Write-Aviso 'Lint PHP local pulado (-SemTestes).' } else { Invoke-LintLocal $zip $Saida }
        $sha256 = Get-Sha256 $zip
        $tamanho = (Get-Item -LiteralPath $zip).Length
        Write-Info ("Pacote: {0} ({1} arquivos, {2:N0} bytes, sha256 {3})" -f $zip, $script:PacoteArquivos, $tamanho, $sha256)
        if ($SomentePacote) { Write-Output $zip; exit 0 }
        $null = New-Item -ItemType Directory -Path $envio -Force
        $zipEnvio = Join-Path $envio ("jca_release_{0}_{1}_{2}.zip" -f $ts, $sha.Substring(0, 7), [guid]::NewGuid().ToString('N'))
        Move-Item -LiteralPath $zip -Destination $zipEnvio
    }

    $null = New-Item -ItemType Directory -Path $envio -Force
    # nomes imprevisiveis (GUID): ninguem consegue pre-criar o arquivo em /tmp
    $id = [guid]::NewGuid().ToString('N')
    $scriptEnvio = Join-Path $envio "jca_aplicar_$id.sh"
    Copy-ScriptRemoto $scriptEnvio
    $shaScript = Get-Sha256 $scriptEnvio
    $remotoScript = "/tmp/jca_aplicar_$id.sh"
    $remotoZip = $null
    if ($zipEnvio) { $remotoZip = '/tmp/' + [System.IO.Path]::GetFileName($zipEnvio) }

    # comando remoto (todos os valores ja validados por regex)
    $opcoes = New-Object System.Collections.Generic.List[string]
    if ($DryRun) { $opcoes.Add('--dry-run') }
    if ($Rollback) {
        $opcoes.Add('--rollback')
        if ($Timestamp) { $opcoes.Add($Timestamp) }
    } else {
        if ($LimparCache) { $opcoes.Add('--limpar-cache') }
        if ($ManterCache) { $opcoes.Add('--manter-cache') }
        if ($SmokeUrl)  { $opcoes.Add('--smoke-url'); $opcoes.Add($SmokeUrl) }
        if ($SmokeHost) { $opcoes.Add('--smoke-host'); $opcoes.Add($SmokeHost) }
        $opcoes.Add($remotoZip)
        $opcoes.Add($sha256)
    }
    $cmd = New-ComandoRemoto $remotoScript $remotoZip $shaScript @($opcoes)

    $modo = if ($Rollback) { 'ROLLBACK' } elseif ($DryRun) { 'DRY-RUN (so simula)' } else { 'DEPLOY' }
    Write-Host ''
    Write-Host "Alvo:       $alvoFinal"
    Write-Host "Modo:       $modo"
    if (-not $Rollback) {
        Write-Host "Referencia: $Referencia ($($sha.Substring(0, 7)))"
        Write-Host "SHA256:     $sha256"
        Write-Host ("Tamanho:    {0:N0} bytes" -f $tamanho)
    }
    Write-Host ''
    if (-not $DryRun -and -not $Sim) {
        $resp = Read-Host 'Continuar? (S/N)'
        if ($resp -notmatch '^[sSyY]') { Stop-Deploy 'Cancelado pelo usuario.' 2 }
    }

    $arquivosEnvio = @($scriptEnvio)
    if ($zipEnvio) { $arquivosEnvio = @($zipEnvio, $scriptEnvio) }
    if ($SemChecagemEspaco) {
        Write-Aviso 'Checagem de espaco livre em /tmp do servidor pulada (-SemChecagemEspaco).'
    } else {
        Write-Info 'Conferindo o espaco livre em /tmp do servidor (ssh)... digite a senha se solicitado.'
        $precisa = $tamanho + (Get-Item -LiteralPath $scriptEnvio).Length + 1MB
        $df = @(& $ssh -o ConnectTimeout=15 $alvoFinal 'df -Pk /tmp | awk ''NR==2{print $4}''' 2>&1 | ForEach-Object { "$_".Trim() })
        $livreKb = $df | Where-Object { $_ -match '^[0-9]+$' } | Select-Object -Last 1
        if ($livreKb) {
            $livre = [int64]$livreKb * 1024
            if ($livre -lt $precisa) {
                Stop-Deploy ("Espaco insuficiente em /tmp no servidor ({0:N0} bytes livres; o envio precisa de {1:N0}). Nada foi enviado." -f $livre, $precisa) 10
            }
            Write-Info ("/tmp do servidor: {0:N0} bytes livres (envio: {1:N0})." -f $livre, $precisa)
        } else {
            Write-Aviso 'Nao foi possivel medir o espaco livre de /tmp no servidor; seguindo.'
        }
    }
    Write-Info 'Enviando arquivos (scp)... digite a senha se solicitado.'
    $scpArgs = @('-o', 'ConnectTimeout=15') + $arquivosEnvio + @("${alvoFinal}:/tmp/")
    & $scp @scpArgs
    if ($LASTEXITCODE -ne 0) { Stop-Deploy "scp falhou (codigo $LASTEXITCODE). Verifique rede, usuario/senha e o alvo." 10 }

    Write-Info 'Executando no servidor (ssh; confere o script e roda com sudo)... digite a senha do ssh/sudo se solicitado.'
    & $ssh -t -o ConnectTimeout=15 $alvoFinal $cmd
    $rc = $LASTEXITCODE
    Remove-Item -LiteralPath $envio -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host ''
    $msg = Get-MensagemCodigo $rc
    if ($rc -eq 0) { Write-Info $msg } else { Write-Erro "Codigo remoto ${rc}: $msg" }
    if ($rc -eq 0) { exit 0 }
    if ($rc -ge 1 -and $rc -le 6) { exit (20 + $rc) }
    exit 20
}

if ($MyInvocation.InvocationName -ne '.') { Invoke-Principal }
