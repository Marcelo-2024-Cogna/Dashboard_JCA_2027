#!/usr/bin/env bash
# =====================================================================
#  aplicar_release.sh - aplica um pacote (zip) de release no servidor.
#
#  Executado NO SERVIDOR, com sudo, pelo deploy/deploy.ps1 (nao precisa
#  ser chamado a mao, mas pode).
#
#  Uso:
#    aplicar_release.sh [--dry-run] [--limpar-cache|--manter-cache]
#                       [--smoke-url URL] [--smoke-host HOST] ZIP SHA256
#    aplicar_release.sh [--dry-run] --rollback [AAAAMMDD-HHMMSS]
#
#  Codigos de saida:
#    0 ok / nada a aplicar     1 erro inesperado
#    2 argumento, hash, caminho ou conteudo invalido (nada alterado)
#    3 lint ou smoke falhou; rollback automatico concluido
#    4 rollback falhou (intervencao manual; backup preservado)
#    5 outra execucao em andamento (lock)
#    6 deploy/rollback CONCLUIDO e validado, mas chown/chmod falhou em algum
#      arquivo (cada um sai como [AVISO]); sem rollback, corrigir a mao
#
#  Variaveis sobrescreviveis (principalmente para teste):
#    APP_DIR (/apps/metricas_jca)   DEPLOY_DIR (/apps/_deploy)
#    MANTER_BACKUPS (10)            PHP_BIN (php)
#    PULAR_SUDO=1  nao usa "sudo -u" ao limpar o cache
#    PULAR_CHOWN=1 nao executa chown
#    PULAR_SMOKE=1 nao executa o smoke test
#    SMOKE_URL (http://localhost)   SMOKE_HOST (cabecalho Host opcional)
#    MIN_LIVRE_MB (1024)            WEB_GRUPO / WEB_USUARIO (www-data)
#    DEPLOY_TESTE_ESPERA_S          SO PARA TESTE: dorme N s apos o lock
#
#  --dry-run: nao cria nada em APP_DIR nem em DEPLOY_DIR (sem mkdir, lock,
#  log ou backup). Unico efeito: um diretorio temporario run.XXXXXX ao lado do
#  ZIP (ou em $TMPDIR/tmp, no --rollback), para extrair o pacote; removido ao sair.
#
#  NUNCA grava em /var nem /tmp (so o zip chega la, de passagem).
#  NUNCA toca em data/ (exceto garantir o dono de data/cache), em
#  config/db.php nem em config/secrets.local.php.
#  Cron, vhost e banco de dados NAO sao alterados.
# =====================================================================
set -u -o pipefail

APP_DIR=${APP_DIR:-/apps/metricas_jca}
DEPLOY_DIR=${DEPLOY_DIR:-/apps/_deploy}
MANTER_BACKUPS=${MANTER_BACKUPS:-10}
PHP_BIN=${PHP_BIN:-php}
PULAR_SUDO=${PULAR_SUDO:-0}
PULAR_CHOWN=${PULAR_CHOWN:-0}
PULAR_SMOKE=${PULAR_SMOKE:-0}
SMOKE_URL=${SMOKE_URL:-http://localhost}
SMOKE_HOST=${SMOKE_HOST:-}
MIN_LIVRE_MB=${MIN_LIVRE_MB:-1024}
WEB_GRUPO=${WEB_GRUPO:-www-data}
WEB_USUARIO=${WEB_USUARIO:-www-data}
DEPLOY_TESTE_ESPERA_S=${DEPLOY_TESTE_ESPERA_S:-}

RE_TS='^[0-9]{8}-[0-9]{6}$'
RE_BACKUP='^[0-9]{8}-[0-9]{6}\.tar\.gz$'

RUN=""
STAGE=""
META=""
LOCK_FALLBACK=0
LOCK_DIR=""
LOG_ATIVO=0
AVISOS_PERM=0
LIMPEZA_CACHE_FEITA=0
BACKUP_NOME=""
BACKUP_ARQ=""

# ---------------------------------------------------------------- mensagens
info()  { printf '[INFO] %s\n' "$*"; }
aviso() { printf '[AVISO] %s\n' "$*"; }
erro()  { printf '[ERRO] %s\n' "$*" >&2; }

registrar() {
  # grava no deploy.log (so quando o log esta ativo, ou seja, fora do dry-run)
  [ "$LOG_ATIVO" = "1" ] || return 0
  printf '%s | %s | %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${SUDO_USER:-${USER:-desconhecido}}" "$*" >> "$DEPLOY_DIR/deploy.log" 2>/dev/null
  return 0
}

# ---------------------------------------------------------------- validacao
# caminho_sob PREFIXO CAMINHO: CAMINHO precisa estar estritamente dentro de PREFIXO
caminho_sob() {
  local prefixo=$1 caminho=$2
  [ -n "$prefixo" ] || return 1
  case "/$caminho/" in */../*) return 1 ;; esac
  case "$caminho" in
    "$prefixo"/?*) return 0 ;;
  esac
  return 1
}

# validar_base ROTULO VALOR: caminho absoluto seguro, fora de /var e /tmp
validar_base() {
  local rotulo=$1 v=$2 sem n
  if [ -z "$v" ]; then erro "$rotulo vazio."; return 1; fi
  if ! [[ $v =~ ^(/|[A-Za-z]:/)[A-Za-z0-9._/:-]+$ ]]; then
    erro "$rotulo invalido (use caminho absoluto com letras, numeros, . _ - /): $v"; return 1
  fi
  case "/$v/" in */../*|*//*) erro "$rotulo nao pode conter '..' nem '//': $v"; return 1 ;; esac
  case "$v" in */) erro "$rotulo nao pode terminar em '/': $v"; return 1 ;; esac
  case "$v" in
    /var|/var/*|/tmp|/tmp/*) erro "$rotulo nao pode ficar em /var nem /tmp: $v"; return 1 ;;
  esac
  sem=${v#[A-Za-z]:}
  sem=${sem#/}
  n=$(printf '%s' "$sem" | awk -F/ '{print NF}')
  if [ "$n" -lt 2 ]; then erro "$rotulo muito raso (minimo 2 niveis): $v"; return 1; fi
  return 0
}

# rel_valido CAMINHO: caminho relativo, sem '..', sem barra invertida, sem controle
rel_valido() {
  local p=$1
  [ -n "$p" ] || return 1
  case "$p" in /*|*\\*) return 1 ;; esac
  [[ $p =~ ^[A-Za-z]: ]] && return 1
  case "/$p/" in */../*|*//*) return 1 ;; esac
  [[ $p == *[[:cntrl:]]* ]] && return 1
  return 0
}

# negado CAMINHO: arquivos que o deploy jamais pode escrever ou apagar
negado() {
  case "$1" in
    config/db.php|config/secrets.local.php) return 0 ;;
    data|data/*|.git|.git/*|.kiro|.kiro/*|.agents|.agents/*) return 0 ;;
  esac
  return 1
}

uso() {
  cat <<'EOF'
Uso:
  aplicar_release.sh [--dry-run] [--limpar-cache|--manter-cache]
                     [--smoke-url URL] [--smoke-host HOST] ZIP SHA256
  aplicar_release.sh [--dry-run] --rollback [AAAAMMDD-HHMMSS]
EOF
}

# tem_cr ARQUIVO: verdadeiro se o arquivo contem byte 13 (CR)
tem_cr() { ! tr -d '\r' < "$1" | cmp -s - "$1"; }

# ---------------------------------------------------------------- limpeza
limpeza() {
  local rc=$?
  if [ -n "$RUN" ] && [[ ${RUN##*/} == run.* ]] && { [ "$DRY" = "1" ] || caminho_sob "$DEPLOY_DIR/tmp" "$RUN"; }; then
    rm -rf -- "$RUN"
  fi
  if [ "$LOCK_FALLBACK" = "1" ] && [ -n "$LOCK_DIR" ] && caminho_sob "$DEPLOY_DIR" "$LOCK_DIR"; then
    rm -f -- "$LOCK_DIR/pid" 2>/dev/null
    rmdir -- "$LOCK_DIR" 2>/dev/null
  fi
  exit "$rc"
}

# ---------------------------------------------------------------- argumentos
MODO=deploy
DRY=0
ROLLBACK_TS=""
CACHE=auto
ZIP=""
SHA=""
POS=0

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY=1 ;;
    --rollback)
      MODO=rollback
      if [ $# -gt 1 ] && [[ $2 != --* ]]; then ROLLBACK_TS=$2; shift; fi
      ;;
    --limpar-cache) CACHE=sim ;;
    --manter-cache) CACHE=nao ;;
    --smoke-url)  [ $# -gt 1 ] || { erro "--smoke-url exige um valor."; exit 2; }; SMOKE_URL=$2; shift ;;
    --smoke-host) [ $# -gt 1 ] || { erro "--smoke-host exige um valor."; exit 2; }; SMOKE_HOST=$2; shift ;;
    -h|--help) uso; exit 0 ;;
    -*) erro "Opcao desconhecida: $1"; uso; exit 2 ;;
    *)
      POS=$((POS + 1))
      if [ "$POS" = "1" ]; then ZIP=$1; elif [ "$POS" = "2" ]; then SHA=$1; else erro "Argumentos demais."; uso; exit 2; fi
      ;;
  esac
  shift
done

validar_base APP_DIR "$APP_DIR" || exit 2
validar_base DEPLOY_DIR "$DEPLOY_DIR" || exit 2
[ "$APP_DIR" != "$DEPLOY_DIR" ] || { erro "APP_DIR e DEPLOY_DIR nao podem ser iguais."; exit 2; }
case "$DEPLOY_DIR/" in "$APP_DIR"/*) erro "DEPLOY_DIR nao pode ficar dentro de APP_DIR."; exit 2 ;; esac
[[ $MANTER_BACKUPS =~ ^[0-9]+$ ]] && [ "$MANTER_BACKUPS" -ge 1 ] || { erro "MANTER_BACKUPS invalido: $MANTER_BACKUPS"; exit 2; }
[[ $MIN_LIVRE_MB =~ ^[0-9]+$ ]] || { erro "MIN_LIVRE_MB invalido: $MIN_LIVRE_MB"; exit 2; }
[[ $WEB_GRUPO =~ ^[a-z_][a-z0-9_-]*$ && $WEB_USUARIO =~ ^[a-z_][a-z0-9_-]*$ ]] || { erro "WEB_GRUPO/WEB_USUARIO invalido."; exit 2; }
[[ $SMOKE_URL =~ ^https?://[A-Za-z0-9._:/-]+$ ]] || { erro "SMOKE_URL invalida: $SMOKE_URL"; exit 2; }
SMOKE_URL=${SMOKE_URL%/}
if [ -n "$SMOKE_HOST" ] && ! [[ $SMOKE_HOST =~ ^[A-Za-z0-9._:-]+$ ]]; then erro "SMOKE_HOST invalido."; exit 2; fi
if [ -n "$ROLLBACK_TS" ] && ! [[ $ROLLBACK_TS =~ $RE_TS ]]; then erro "Timestamp invalido (esperado AAAAMMDD-HHMMSS): $ROLLBACK_TS"; exit 2; fi
if [ -n "$DEPLOY_TESTE_ESPERA_S" ] && ! [[ $DEPLOY_TESTE_ESPERA_S =~ ^[0-9]{1,3}$ ]]; then erro "DEPLOY_TESTE_ESPERA_S invalido."; exit 2; fi
if [ "$MODO" = "deploy" ] && { [ -z "$ZIP" ] || [ -z "$SHA" ]; }; then erro "Informe ZIP e SHA256."; uso; exit 2; fi
if [ "$MODO" = "rollback" ] && [ -n "$ZIP" ]; then erro "--rollback nao recebe ZIP."; uso; exit 2; fi
[ -d "$APP_DIR" ] || { erro "APP_DIR nao existe: $APP_DIR"; exit 2; }
for ferramenta in tar gzip sha256sum cmp find awk sort date mktemp cp mkdir chmod; do
  command -v "$ferramenta" >/dev/null 2>&1 || { erro "Ferramenta ausente no servidor: $ferramenta"; exit 2; }
done
if [ "$MODO" = "deploy" ]; then
  command -v unzip >/dev/null 2>&1 || { erro "Ferramenta ausente no servidor: unzip"; exit 2; }
  [[ $SHA =~ ^[0-9a-f]{64}$ ]] || { erro "SHA256 invalido (64 hex minusculos)."; exit 2; }
  [ -f "$ZIP" ] || { erro "Pacote nao encontrado: $ZIP"; exit 2; }
fi
command -v "$PHP_BIN" >/dev/null 2>&1 || { erro "PHP nao encontrado: $PHP_BIN"; exit 2; }

# ---------------------------------------------------------------- preparo
trap limpeza EXIT
if [ "$DRY" != "1" ]; then
  mkdir -p "$DEPLOY_DIR/tmp" "$DEPLOY_DIR/backups" || { erro "Nao foi possivel criar $DEPLOY_DIR."; exit 1; }
  chmod 750 "$DEPLOY_DIR" "$DEPLOY_DIR/tmp" "$DEPLOY_DIR/backups" 2>/dev/null || aviso "chmod 750 falhou em $DEPLOY_DIR (ou subpastas)."
fi

# lock: flock quando existe; senao mkdir (fallback so para teste local). Dry-run nao usa lock.
if [ "$DRY" = "1" ]; then
  :
elif command -v flock >/dev/null 2>&1; then
  exec 9>"$DEPLOY_DIR/deploy.lock" || { erro "Nao foi possivel abrir o lock."; exit 1; }
  if ! flock -n 9; then erro "Outro deploy esta em andamento (lock). Tente novamente depois."; exit 5; fi
else
  aviso "flock nao encontrado; usando lock por diretorio (aceitavel so em teste local)."
  LOCK_DIR="$DEPLOY_DIR/deploy.lock.d"
  if mkdir "$LOCK_DIR" 2>/dev/null; then
    LOCK_FALLBACK=1
    echo "$$" > "$LOCK_DIR/pid"
  else
    erro "Outro deploy esta em andamento (lock). Tente novamente depois."; exit 5
  fi
fi
[ "$DRY" = "1" ] || LOG_ATIVO=1
if [ -n "$DEPLOY_TESTE_ESPERA_S" ]; then sleep "$DEPLOY_TESTE_ESPERA_S"; fi

# espaco livre (KB) do diretorio, ou do ancestral mais proximo que exista
livre_kb_de() {
  local d=$1
  while [ ! -d "$d" ] && [ "$d" != "/" ] && [ "$d" != "." ]; do
    case "$d" in */*) d=${d%/*}; [ -n "$d" ] || d=/ ;; *) d=. ;; esac
  done
  df -Pk "$d" 2>/dev/null | awk 'NR==2{print $4}'
}

livre_kb=$(livre_kb_de "$DEPLOY_DIR")
if [[ ${livre_kb:-} =~ ^[0-9]+$ ]]; then
  if [ $((livre_kb / 1024)) -lt "$MIN_LIVRE_MB" ]; then
    erro "Espaco livre insuficiente em $DEPLOY_DIR ($((livre_kb / 1024)) MB; minimo $MIN_LIVRE_MB MB)."; exit 2
  fi
else
  aviso "Nao foi possivel medir o espaco livre em $DEPLOY_DIR."
fi

criar_run() {
  local base
  if [ "$DRY" = "1" ]; then
    # dry-run: nada em APP_DIR/DEPLOY_DIR; area temporaria ao lado do ZIP (removida ao sair)
    if [ "$MODO" = "deploy" ]; then base=${ZIP%/*}; [ "$base" != "$ZIP" ] || base=.; else base=${TMPDIR:-/tmp}; fi
    RUN=$(mktemp -d -p "$base" run.XXXXXX) || { erro "Nao foi possivel criar diretorio temporario em $base."; exit 1; }
  else
    RUN=$(mktemp -d -p "$DEPLOY_DIR/tmp" run.XXXXXX) || { erro "Nao foi possivel criar diretorio temporario."; exit 1; }
    caminho_sob "$DEPLOY_DIR/tmp" "$RUN" || { erro "Diretorio temporario fora do esperado: $RUN"; exit 1; }
  fi
  META="$RUN/meta"
  STAGE="$RUN/stage"
  mkdir -p "$META" "$STAGE" "$META/man/.deploy" || { erro "Nao foi possivel preparar $RUN."; exit 1; }
}

# ---------------------------------------------------------------- verificacoes
lint_lista() {
  # lint_lista ARQUIVO_COM_LISTA: php -l em cada .php da lista (relativos a APP_DIR)
  local f saida falhou=0 n=0
  while IFS= read -r f; do
    case "$f" in *.php) ;; *) continue ;; esac
    [ -f "$APP_DIR/$f" ] || continue
    n=$((n + 1))
    if ! saida=$("$PHP_BIN" -l "$APP_DIR/$f" 2>&1); then
      erro "Lint falhou em $f: $saida"
      falhou=1
    fi
  done < "$1"
  info "Lint PHP: $n arquivo(s) verificados."
  return $falhou
}

smoke() {
  if [ "$PULAR_SMOKE" = "1" ]; then aviso "Smoke test pulado (PULAR_SMOKE=1)."; return 0; fi
  command -v curl >/dev/null 2>&1 || { erro "curl nao encontrado para o smoke test."; return 1; }
  local rota codigo tentativa ok=0
  local -a cab=()
  [ -z "$SMOKE_HOST" ] || cab=(-H "Host: $SMOKE_HOST")
  for rota in /filtros /graficos/produtividade; do
    ok=0
    for tentativa in 1 2 3; do
      codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${cab[@]}" "$SMOKE_URL$rota" 2>/dev/null)
      if [ "$codigo" = "200" ]; then ok=1; break; fi
      sleep 1
    done
    if [ "$ok" != "1" ]; then erro "Smoke test falhou em $rota (HTTP ${codigo:-sem resposta})."; return 1; fi
    info "Smoke OK: $rota (HTTP 200)."
  done
  return 0
}

limpar_cache() {
  if [ ! -f "$APP_DIR/bin/limpar_cache.php" ]; then aviso "bin/limpar_cache.php nao existe; cache nao limpo."; return 0; fi
  local rc
  if [ "$PULAR_SUDO" = "1" ]; then
    "$PHP_BIN" "$APP_DIR/bin/limpar_cache.php"; rc=$?
  else
    sudo -u "$WEB_USUARIO" "$PHP_BIN" "$APP_DIR/bin/limpar_cache.php"; rc=$?
  fi
  if [ "$rc" != "0" ]; then aviso "Limpeza de cache falhou (codigo $rc); o TTL de 15 min cobre."; return 0; fi
  LIMPEZA_CACHE_FEITA=1
  info "Cache de resposta limpo."
  return 0
}

# falha de chown/chmod: [AVISO] por arquivo, contada; o deploy termina com exit 6
perm_falhou() {
  AVISOS_PERM=$((AVISOS_PERM + 1))
  aviso "$*"
  registrar "AVISO: $*"
}

# ajustar_modo MODO ALVO / ajustar_dono DONO:GRUPO ALVO (ALVO dentro de APP_DIR)
ajustar_modo() {
  chmod "$1" -- "$2" 2>/dev/null || perm_falhou "chmod $1 falhou em ${2#"$APP_DIR"/}"
  return 0
}
ajustar_dono() {
  [ "$PULAR_CHOWN" = "1" ] && return 0
  chown "$1" -- "$2" 2>/dev/null || perm_falhou "chown $1 falhou em ${2#"$APP_DIR"/}"
  return 0
}

garantir_cache_dono() {
  # unico acesso a data/: garantir o dono de data/cache (se existir)
  [ "$PULAR_CHOWN" = "1" ] && return 0
  if [ -d "$APP_DIR/data/cache" ]; then
    ajustar_dono "$WEB_USUARIO:$WEB_GRUPO" "$APP_DIR/data/cache"
  fi
  return 0
}

# encerra com 6 se alguma permissao falhou, senao com 0
sair_ok() {
  if [ "$AVISOS_PERM" -gt 0 ]; then
    aviso "=============================================================="
    aviso "$AVISOS_PERM falha(s) de dono/permissao (chown/chmod). O resultado foi aplicado e validado,"
    aviso "mas confira com 'ls -l' os arquivos listados acima e corrija a mao. Saida 6."
    aviso "=============================================================="
    registrar "CONCLUIDO COM $AVISOS_PERM AVISO(S) de dono/permissao"
    exit 6
  fi
  exit 0
}

# ---------------------------------------------------------------- backup
listar_backups() {
  local f n
  for f in "$DEPLOY_DIR"/backups/*.tar.gz; do
    [ -f "$f" ] || continue
    n=${f##*/}
    [[ $n =~ $RE_BACKUP ]] && printf '%s\n' "$n"
  done | LC_ALL=C sort
}

podar_backups() {
  local total apagar n
  total=$(listar_backups | wc -l); total=$((total + 0))
  [ "$total" -gt "$MANTER_BACKUPS" ] || return 0
  apagar=$((total - MANTER_BACKUPS))
  while IFS= read -r n; do
    [[ $n =~ $RE_BACKUP ]] || continue
    caminho_sob "$DEPLOY_DIR/backups" "$DEPLOY_DIR/backups/$n" || continue
    rm -f -- "$DEPLOY_DIR/backups/$n"
    info "Backup antigo removido: $n"
  done < <(listar_backups | head -n "$apagar")
  return 0
}

# criar_backup LISTA_M LISTA_N LISTA_D: define BACKUP_NOME e BACKUP_ARQ
criar_backup() {
  local lm=$1 ln=$2 ld=$3 esperado parcial final contagem
  BACKUP_NOME=$(date -u +%Y%m%d-%H%M%S)
  while [ -e "$DEPLOY_DIR/backups/$BACKUP_NOME.tar.gz" ]; do sleep 1; BACKUP_NOME=$(date -u +%Y%m%d-%H%M%S); done
  final="$DEPLOY_DIR/backups/$BACKUP_NOME.tar.gz"
  parcial="$final.parcial"
  caminho_sob "$DEPLOY_DIR/backups" "$parcial" || return 1
  {
    awk '{print "M " $0}' "$lm"
    awk '{print "N " $0}' "$ln"
    awk '{print "D " $0}' "$ld"
  } > "$META/man/.deploy/manifesto.txt" || return 1
  tar --force-local -czf "$parcial" -C "$APP_DIR" -T "$lm" -C "$META/man" .deploy/manifesto.txt || { rm -f -- "$parcial"; return 1; }
  esperado=$(wc -l < "$lm"); esperado=$((esperado + 1))
  contagem=$(tar --force-local -tzf "$parcial" | wc -l); contagem=$((contagem + 0))
  if [ "$contagem" != "$esperado" ]; then
    erro "Backup inconsistente (esperado $esperado entradas, obtido $contagem)."
    rm -f -- "$parcial"; return 1
  fi
  mv -- "$parcial" "$final" || { rm -f -- "$parcial"; return 1; }
  BACKUP_ARQ="$final"
  info "Backup criado: $final"
  return 0
}

# restaurar BACKUP_ARQ: restaura os M, remove os N e os diretorios novos vazios
restaurar() {
  local bk=$1 man="$META/rb_manifesto.txt" rm_="$META/rb_M.txt" rn="$META/rb_N.txt" rd="$META/rb_D.txt" f d
  tar --force-local -xzOf "$bk" .deploy/manifesto.txt > "$man" || { erro "Manifesto ilegivel em $bk."; return 1; }
  awk '$1=="M"{sub(/^M /,""); print}' "$man" > "$rm_"
  awk '$1=="N"{sub(/^N /,""); print}' "$man" > "$rn"
  awk '$1=="D"{sub(/^D /,""); print}' "$man" | LC_ALL=C sort -r > "$rd"
  while IFS= read -r f; do
    rel_valido "$f" && ! negado "$f" || { erro "Manifesto com caminho invalido: $f"; return 1; }
  done < <(cat "$rm_" "$rn" "$rd")
  if [ -s "$rm_" ]; then
    tar --force-local -xzpf "$bk" -C "$APP_DIR" -T "$rm_" || { erro "Falha ao extrair o backup."; return 1; }
  fi
  while IFS= read -r f; do
    caminho_sob "$APP_DIR" "$APP_DIR/$f" || { erro "Caminho fora de APP_DIR: $f"; return 1; }
    rm -f -- "$APP_DIR/$f"
  done < "$rn"
  while IFS= read -r d; do
    caminho_sob "$APP_DIR" "$APP_DIR/$d" || continue
    rmdir -- "$APP_DIR/$d" 2>/dev/null
  done < "$rd"
  garantir_cache_dono
  info "Arquivos restaurados: $(wc -l < "$rm_" | tr -d ' ') ; removidos: $(wc -l < "$rn" | tr -d ' ')."
  [ "$LIMPEZA_CACHE_FEITA" != "1" ] || limpar_cache
  cat "$rm_" > "$META/rb_lint.txt"
  lint_lista "$META/rb_lint.txt" || { erro "Lint falhou apos o rollback."; return 1; }
  smoke || { erro "Smoke falhou apos o rollback."; return 1; }
  return 0
}

# ---------------------------------------------------------------- rollback
fluxo_rollback() {
  local alvo bk lista f
  criar_run
  lista=$(listar_backups)
  if [ -z "$ROLLBACK_TS" ]; then
    alvo=$(printf '%s\n' "$lista" | tail -n 1)
    alvo=${alvo%.tar.gz}
    [ -n "$alvo" ] || { erro "Nao ha backups em $DEPLOY_DIR/backups."; exit 2; }
  else
    alvo=$ROLLBACK_TS
  fi
  bk="$DEPLOY_DIR/backups/$alvo.tar.gz"
  [ -f "$bk" ] || { erro "Backup nao encontrado: $alvo"; exit 2; }
  info "Backup selecionado: $alvo"
  tar --force-local -xzOf "$bk" .deploy/manifesto.txt > "$META/sel_manifesto.txt" || { erro "Backup ilegivel: $alvo"; exit 2; }
  awk '$1=="M"{sub(/^M /,""); print}' "$META/sel_manifesto.txt" > "$META/sel_M.txt"
  awk '$1=="N"{sub(/^N /,""); print}' "$META/sel_manifesto.txt" > "$META/sel_N.txt"
  while IFS= read -r f; do
    rel_valido "$f" && ! negado "$f" || { erro "Manifesto com caminho invalido: $f"; exit 2; }
  done < <(cat "$META/sel_M.txt" "$META/sel_N.txt")
  info "Seriam restaurados: $(wc -l < "$META/sel_M.txt" | tr -d ' ') arquivo(s); removidos: $(wc -l < "$META/sel_N.txt" | tr -d ' ') arquivo(s) novo(s)."
  sed 's/^/  restaura  /' "$META/sel_M.txt"
  sed 's/^/  remove    /' "$META/sel_N.txt"
  if [ "$DRY" = "1" ]; then info "Dry-run: nada foi alterado em APP_DIR nem em DEPLOY_DIR (nada criado; temporario removido ao sair)."; exit 0; fi

  # backup "pre-rollback" para permitir desfazer o rollback
  : > "$META/pre_M.txt"; : > "$META/pre_N.txt"; : > "$META/pre_D.txt"
  while IFS= read -r f; do
    if [ -e "$APP_DIR/$f" ]; then echo "$f" >> "$META/pre_M.txt"; fi
  done < "$META/sel_N.txt"
  while IFS= read -r f; do
    if [ -e "$APP_DIR/$f" ]; then
      echo "$f" >> "$META/pre_M.txt"
    else
      echo "$f" >> "$META/pre_N.txt"
      calc_dirs_novos "$f" >> "$META/pre_D.txt"
    fi
  done < "$META/sel_M.txt"
  LC_ALL=C sort -u -o "$META/pre_D.txt" "$META/pre_D.txt"
  criar_backup "$META/pre_M.txt" "$META/pre_N.txt" "$META/pre_D.txt" || { erro "Falha ao criar o backup pre-rollback."; exit 1; }
  registrar "ROLLBACK iniciado para $alvo (pre-rollback=$BACKUP_NOME)"
  if restaurar "$bk"; then
    podar_backups
    registrar "ROLLBACK concluido para $alvo"
    info "Rollback concluido."
    sair_ok
  fi
  registrar "ROLLBACK FALHOU para $alvo"
  erro "Rollback falhou. Backup preservado: $bk (pre-rollback: $BACKUP_NOME). Intervencao manual necessaria."
  exit 4
}

# calc_dirs_novos ARQ: imprime diretorios pais de ARQ que ainda nao existem em APP_DIR
calc_dirs_novos() {
  local d=${1%/*}
  [ "$d" != "$1" ] || return 0
  while [ ! -d "$APP_DIR/$d" ]; do
    printf '%s\n' "$d"
    case "$d" in */*) d=${d%/*} ;; *) break ;; esac
  done
  return 0
}

# ---------------------------------------------------------------- deploy
reverter_automatico() {
  aviso "$1; revertendo automaticamente."
  registrar "FALHA: $1"
  if restaurar "$BACKUP_ARQ"; then
    registrar "ROLLBACK automatico concluido ($BACKUP_NOME)"
    erro "Deploy revertido automaticamente. O estado anterior foi restaurado."
    exit 3
  fi
  registrar "ROLLBACK automatico FALHOU ($BACKUP_NOME)"
  erro "Rollback automatico FALHOU. Backup preservado: $BACKUP_ARQ. Intervencao manual necessaria."
  exit 4
}

fluxo_deploy() {
  local real n f primeiro dest d
  real=$(sha256sum -- "$ZIP" | awk '{print $1}')
  if [ "$real" != "$SHA" ]; then erro "SHA256 do pacote nao confere (esperado $SHA, obtido $real). Nada foi extraido."; exit 2; fi
  info "SHA256 conferido."
  criar_run

  unzip -Z1 -- "$ZIP" > "$META/nomes.txt" 2>/dev/null || { erro "Nao foi possivel listar o zip."; exit 2; }
  while IFS= read -r n; do
    case "$n" in */) continue ;; esac
    rel_valido "$n" || { erro "Entrada invalida no pacote: $n"; exit 2; }
    if negado "$n"; then erro "Pacote contem caminho proibido: $n"; exit 2; fi
  done < "$META/nomes.txt"

  local bytes_desc kb_run
  bytes_desc=$(unzip -Zt -- "$ZIP" 2>/dev/null | awk 'NR==1{print $3}')
  kb_run=$(livre_kb_de "$RUN")
  if [[ ${bytes_desc:-} =~ ^[0-9]+$ && ${kb_run:-} =~ ^[0-9]+$ ]]; then
    # extracao (1x) + backup (ate 1x) + folga de 10 MB
    if [ $((kb_run / 1024)) -lt $((bytes_desc * 2 / 1048576 + 10)) ]; then
      erro "Espaco livre insuficiente para extrair o pacote ($((kb_run / 1024)) MB livres; precisa ~$((bytes_desc * 2 / 1048576 + 10)) MB). Nada foi alterado."; exit 2
    fi
  else
    aviso "Nao foi possivel medir o espaco para extrair o pacote."
  fi
  unzip -q -o -- "$ZIP" -d "$STAGE" || { erro "Falha ao extrair o pacote."; exit 1; }
  if [ -n "$(find "$STAGE" -type l 2>/dev/null | head -n 1)" ]; then erro "Pacote contem link simbolico."; exit 2; fi
  (cd "$STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort) > "$META/todos.txt"

  : > "$META/M.txt"; : > "$META/N.txt"; : > "$META/ident.txt"; : > "$META/ignorados.txt"
  while IFS= read -r f; do
    rel_valido "$f" || { erro "Arquivo invalido no pacote: $f"; exit 2; }
    negado "$f" && { erro "Pacote contem caminho proibido: $f"; exit 2; }
    primeiro=${f%%/*}
    case "$primeiro" in
      app|bin|config|public|deploy|db) [ "$f" != "$primeiro" ] || { echo "$f" >> "$META/ignorados.txt"; continue; } ;;
      *) echo "$f" >> "$META/ignorados.txt"; continue ;;
    esac
    case "$f" in
      *.sh|deploy/*)
        if tem_cr "$STAGE/$f"; then erro "Arquivo com CR (CRLF) no pacote: $f"; exit 2; fi ;;
    esac
    dest="$APP_DIR/$f"
    if [ -L "$dest" ] || [ -d "$dest" ]; then erro "Destino invalido (link ou diretorio): $f"; exit 2; fi
    if [ -e "$dest" ]; then
      if cmp -s -- "$STAGE/$f" "$dest"; then echo "$f" >> "$META/ident.txt"; else echo "$f" >> "$META/M.txt"; fi
    else
      echo "$f" >> "$META/N.txt"
    fi
  done < "$META/todos.txt"

  local nm nn ni ng
  nm=$(wc -l < "$META/M.txt" | tr -d ' '); nn=$(wc -l < "$META/N.txt" | tr -d ' ')
  ni=$(wc -l < "$META/ident.txt" | tr -d ' '); ng=$(wc -l < "$META/ignorados.txt" | tr -d ' ')
  info "Resumo: $nm modificado(s), $nn novo(s), $ni identico(s), $ng ignorado(s)."
  sed 's/^/  modificado  /' "$META/M.txt"
  sed 's/^/  novo        /' "$META/N.txt"
  if [ "$ng" != "0" ]; then
    aviso "$ng arquivo(s) do pacote FORA das pastas aplicadas (app bin config public deploy db) NAO serao publicados:"
    head -n 50 "$META/ignorados.txt" | sed 's/^/  ignorado    /'
    [ "$ng" -le 50 ] || aviso "  ... e mais $((ng - 50)) ignorado(s)."
  fi
  if [ "$nm" = "0" ] && [ "$nn" = "0" ]; then info "Nada a aplicar."; exit 0; fi
  if [ "$DRY" = "1" ]; then info "Dry-run: nada foi alterado em APP_DIR nem em DEPLOY_DIR (nada criado: sem mkdir, lock, log ou backup; a extracao temporaria ao lado do zip e removida ao sair)."; exit 0; fi

  cat "$META/M.txt" "$META/N.txt" | LC_ALL=C sort > "$META/aplicar.txt"
  : > "$META/D.txt"
  while IFS= read -r f; do calc_dirs_novos "$f" >> "$META/D.txt"; done < "$META/aplicar.txt"
  LC_ALL=C sort -u -o "$META/D.txt" "$META/D.txt"

  criar_backup "$META/M.txt" "$META/N.txt" "$META/D.txt" || { erro "Falha ao criar o backup; nada foi copiado."; exit 1; }
  registrar "DEPLOY iniciado sha256=$SHA M=$nm N=$nn backup=$BACKUP_NOME"
  podar_backups

  # aplica
  while IFS= read -r f; do
    d=${f%/*}
    if ! mkdir -p -- "$APP_DIR/$d" || ! cp -f -- "$STAGE/$f" "$APP_DIR/$f"; then
      reverter_automatico "Falha ao copiar $f"
    fi
  done < "$META/aplicar.txt"

  # donos e permissoes, so no que esta execucao tocou
  while IFS= read -r d; do
    [ -n "$d" ] || continue
    ajustar_modo 755 "$APP_DIR/$d"
    ajustar_dono "root:$WEB_GRUPO" "$APP_DIR/$d"
  done < "$META/D.txt"
  while IFS= read -r f; do
    case "$f" in *.sh) ajustar_modo 755 "$APP_DIR/$f" ;; *) ajustar_modo 644 "$APP_DIR/$f" ;; esac
    ajustar_dono "root:$WEB_GRUPO" "$APP_DIR/$f"
  done < "$META/aplicar.txt"
  garantir_cache_dono

  lint_lista "$META/aplicar.txt" || reverter_automatico "Lint PHP falhou"

  # cache: limpa automaticamente se mudou app/, config/ ou db/ (ver LEIAME, secao 12)
  local limpar=0
  case "$CACHE" in
    sim) limpar=1 ;;
    nao) limpar=0 ;;
    *) if grep -Eq '^(app|config|db)/' "$META/aplicar.txt"; then limpar=1; fi ;;
  esac
  if [ "$limpar" = "1" ]; then limpar_cache; else info "Cache mantido."; fi

  smoke || reverter_automatico "Smoke test falhou"

  registrar "DEPLOY concluido sha256=$SHA M=$nm N=$nn backup=$BACKUP_NOME"
  info "Deploy concluido. Backup: $BACKUP_NOME"
  sair_ok
}

if [ "$MODO" = "rollback" ]; then fluxo_rollback; else fluxo_deploy; fi
exit 1
