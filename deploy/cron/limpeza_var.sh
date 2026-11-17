#!/bin/bash
# =====================================================================
#  limpeza_var.sh - contencao PALIATIVA do /var (Metricas Jeito Cogna Agil)
#
#  Por que existe: na noite de 15/11 a particao /var (4 GB) encheu (syslog e
#  journal inundados por um agente de terceiros). O MySQL, com log_bin ligado,
#  ESPERA espaco livre para gravar o binlog em vez de falhar e travou o job
#  noturno por ~17 h. Este script libera espaco ANTES de chegar a 100%.
#  NAO resolve a causa (o agente que inunda o log): e um paliativo.
#
#  Instalacao e testes: deploy/LEIAME.md, secao 8c. Agendamento (root, 30 min):
#  deploy/cron/metricas_jca_limpeza.cron.
#
#  Uso:  limpeza_var.sh [--executar] [--help]
#        Sem --executar roda em DRY-RUN: so mostra o que faria.
#
#  Niveis pelo uso % de /var (df --output=pcent):
#    1 (>= 80%) so registra "atencao".
#    2 (>= 88%) journalctl --vacuum-size=50M, apt-get clean, remove somente
#               syslog.N / syslog.N.gz ROTACIONADOS com mais de 3 dias em
#               /var/log e copias syslog_AAAAMMDD_HHMMSS.log com mais de 7 dias
#               em /apps/_diag.
#    3 (>= 94%) alem do nivel 2: se /apps tiver >= 5 GB livres, copia
#               /var/log/syslog para /apps/_diag, CONFERE a copia (cp ok e
#               tamanho igual) e so entao faz truncate -s 0 do syslog. Se a
#               copia falhar, NAO trunca. Limitacao: linhas gravadas entre a
#               copia e o truncate se perdem; se o syslog crescer durante a
#               copia o tamanho diverge e o script aborta sem truncar.
#
#  NUNCA toca em: /var/lib/mysql, binlogs, /var/log/mysql, /var/log/apache2,
#  /var/lib/guardicore, logs de terceiros (gc-*) nem em nada fora das listas
#  acima. Nao usa rm -rf. A unica remocao e "find -maxdepth 1 -type f -name
#  PADRAO -mmin +N -delete" nos dois diretorios fixos, com variaveis validadas.
#
#  Codigos de saida: 0 nada a fazer (inclui dry-run) | 1 acao executada |
#                    2 erro | 3 ja em execucao
# =====================================================================
set -u

# --- Parametros (variaveis de ambiente, com padrao) --------------------
LIMPEZA_NIVEL1_PCT="${LIMPEZA_NIVEL1_PCT:-80}"
LIMPEZA_NIVEL2_PCT="${LIMPEZA_NIVEL2_PCT:-88}"
LIMPEZA_NIVEL3_PCT="${LIMPEZA_NIVEL3_PCT:-94}"
LIMPEZA_VAR="${LIMPEZA_VAR:-/var}"
LIMPEZA_LOG_DIR="${LIMPEZA_LOG_DIR:-/var/log}"
LIMPEZA_APPS="${LIMPEZA_APPS:-/apps}"
LIMPEZA_DIAG="${LIMPEZA_DIAG:-/apps/_diag}"
LIMPEZA_SYSLOG="${LIMPEZA_SYSLOG:-/var/log/syslog}"
LIMPEZA_LOG="${LIMPEZA_LOG:-/apps/metricas_jca/data/logs/limpeza_var.log}"
LIMPEZA_LOCK="${LIMPEZA_LOCK:-/var/lock/metricas_jca_limpeza.lock}"
LIMPEZA_JOURNAL_MAX="${LIMPEZA_JOURNAL_MAX:-50M}"
LIMPEZA_SYSLOG_DIAS="${LIMPEZA_SYSLOG_DIAS:-3}"
LIMPEZA_DIAG_DIAS="${LIMPEZA_DIAG_DIAS:-7}"
LIMPEZA_APPS_MIN_GB="${LIMPEZA_APPS_MIN_GB:-5}"

EXECUTAR=0
ACOES=0
ERROS=0
LOG_PRONTO=0

ajuda() {
    cat <<'TXT'
Uso: limpeza_var.sh [--executar] [--help]

Contencao paliativa do /var (evita que o disco encha e trave o MySQL com
log_bin ligado). SEM --executar apenas MOSTRA o que faria (dry-run).

Niveis pelo uso % de /var:
  1 (>= 80%)  so registra "atencao"
  2 (>= 88%)  journalctl --vacuum-size=50M; apt-get clean; remove syslog.N e
              syslog.N.gz rotacionados com mais de 3 dias em /var/log e copias
              syslog_AAAAMMDD_HHMMSS.log com mais de 7 dias em /apps/_diag
  3 (>= 94%)  alem do nivel 2: se /apps tiver >= 5 GB livres, copia
              /var/log/syslog para /apps/_diag, confere a copia (cp ok e
              tamanho igual) e so entao trunca o syslog (truncate -s 0)

Nunca toca em /var/lib/mysql, binlogs, /var/log/mysql, /var/log/apache2,
/var/lib/guardicore, logs de terceiros (gc-*) nem em nada fora das listas acima.

Variaveis de ambiente (padrao): LIMPEZA_NIVEL1_PCT=80 LIMPEZA_NIVEL2_PCT=88
  LIMPEZA_NIVEL3_PCT=94 LIMPEZA_VAR=/var LIMPEZA_LOG_DIR=/var/log
  LIMPEZA_APPS=/apps LIMPEZA_DIAG=/apps/_diag LIMPEZA_SYSLOG=/var/log/syslog
  LIMPEZA_LOG=/apps/metricas_jca/data/logs/limpeza_var.log
  LIMPEZA_LOCK=/var/lock/metricas_jca_limpeza.lock LIMPEZA_JOURNAL_MAX=50M
  LIMPEZA_SYSLOG_DIAS=3 LIMPEZA_DIAG_DIAS=7 LIMPEZA_APPS_MIN_GB=5
Ganchos de TESTE: LIMPEZA_USO_PCT_FAKE=N (simula o uso % de /var),
  LIMPEZA_APPS_LIVRE_GB_FAKE=N (simula o livre de /apps em GB),
  LIMPEZA_PULAR_SISTEMA=1 (nao chama journalctl/apt-get),
  LIMPEZA_SEM_LOCK=1 (nao usa flock).

Codigos de saida: 0 nada a fazer (inclui dry-run) | 1 acao executada |
                  2 erro | 3 ja em execucao
TXT
}

# --- Log (stdout + arquivo; falha ao gravar o arquivo NUNCA derruba) ----
preparar_log() {
    local dir
    dir="$(dirname -- "$LIMPEZA_LOG")"
    if [ -n "$dir" ] && [ ! -d "$dir" ]; then
        if mkdir -p -- "$dir" 2>/dev/null; then
            chown www-data:www-data -- "$dir" 2>/dev/null || true
            chmod 775 -- "$dir" 2>/dev/null || true
        fi
    fi
    if [ ! -e "$LIMPEZA_LOG" ]; then
        if { : >> "$LIMPEZA_LOG"; } 2>/dev/null; then
            # dono www-data: o logrotate (su www-data www-data) precisa truncar o arquivo
            chown www-data:www-data -- "$LIMPEZA_LOG" 2>/dev/null || true
            chmod 664 -- "$LIMPEZA_LOG" 2>/dev/null || true
        fi
    fi
    if [ -w "$LIMPEZA_LOG" ]; then LOG_PRONTO=1; fi
}

log() {
    local nivel="$1"
    shift
    local linha
    linha="$(date '+%Y-%m-%d %H:%M:%S') [$nivel] $*"
    echo "$linha"
    if [ "$LOG_PRONTO" -eq 1 ]; then
        { printf '%s\n' "$linha" >> "$LIMPEZA_LOG"; } 2>/dev/null || true
    fi
}

erro() {
    log erro "$*"
    ERROS=$((ERROS + 1))
}

# --- Validacao de numeros ----------------------------------------------
numero_ok() {
    case "$1" in
        '' | *[!0-9]*) return 1 ;;
    esac
    return 0
}

validar_config() {
    local par nome valor
    for par in LIMPEZA_NIVEL1_PCT LIMPEZA_NIVEL2_PCT LIMPEZA_NIVEL3_PCT LIMPEZA_SYSLOG_DIAS LIMPEZA_DIAG_DIAS LIMPEZA_APPS_MIN_GB; do
        nome="$par"
        valor="${!par}"
        if ! numero_ok "$valor"; then
            echo "Valor invalido em $nome='$valor' (esperado inteiro)" >&2
            return 1
        fi
    done
    if [ -z "$LIMPEZA_VAR" ] || [ -z "$LIMPEZA_LOG_DIR" ] || [ -z "$LIMPEZA_DIAG" ] || [ -z "$LIMPEZA_APPS" ] || [ -z "$LIMPEZA_LOG" ] || [ -z "$LIMPEZA_LOCK" ] || [ -z "$LIMPEZA_SYSLOG" ]; then
        echo "Variavel de caminho vazia (LIMPEZA_VAR/LOG_DIR/DIAG/APPS/SYSLOG/LOG/LOCK)" >&2
        return 1
    fi
    case "$LIMPEZA_JOURNAL_MAX" in
        [0-9]*[KMGT] | [0-9]*) ;;
        *) echo "Valor invalido em LIMPEZA_JOURNAL_MAX='$LIMPEZA_JOURNAL_MAX'" >&2; return 1 ;;
    esac
    return 0
}

# --- Medicoes -----------------------------------------------------------
uso_pct() {
    local v
    if [ -n "${LIMPEZA_USO_PCT_FAKE:-}" ]; then
        numero_ok "$LIMPEZA_USO_PCT_FAKE" || return 1
        echo "$LIMPEZA_USO_PCT_FAKE"
        return 0
    fi
    v="$(df --output=pcent "$LIMPEZA_VAR" 2>/dev/null | tail -n 1 | tr -dc '0-9')"
    numero_ok "$v" || return 1
    echo "$v"
}

apps_livre_gb() {
    local b
    if [ -n "${LIMPEZA_APPS_LIVRE_GB_FAKE:-}" ]; then
        numero_ok "$LIMPEZA_APPS_LIVRE_GB_FAKE" || return 1
        echo "$LIMPEZA_APPS_LIVRE_GB_FAKE"
        return 0
    fi
    b="$(df --output=avail -B1 "$LIMPEZA_APPS" 2>/dev/null | tail -n 1 | tr -dc '0-9')"
    numero_ok "$b" || return 1
    echo $((b / 1073741824))
}

# --- Acoes ---------------------------------------------------------------
# executar_cmd "descricao" comando args...  (dry-run: so descreve)
executar_cmd() {
    local desc="$1"
    shift
    local saida rc
    if [ "$EXECUTAR" -ne 1 ]; then
        log dry-run "faria: $desc"
        return 0
    fi
    if saida="$("$@" 2>&1)"; then
        log acao "$desc: ok"
        if [ -n "$saida" ]; then
            log info "$(printf '%s' "$saida" | tail -n 3 | tr '\n' ' ')"
        fi
        ACOES=$((ACOES + 1))
        return 0
    else
        rc=$?
        erro "$desc: falhou (exit $rc) $(printf '%s' "$saida" | tail -n 2 | tr '\n' ' ')"
        return 1
    fi
}

# remover_antigos DIR PADRAO DIAS : apaga arquivos regulares de DIR (sem descer)
# cujo nome casa com PADRAO e com mais de DIAS dias. DIR so pode ser um dos dois
# diretorios fixos (LIMPEZA_LOG_DIR ou LIMPEZA_DIAG).
remover_antigos() {
    local dir="${1:-}" padrao="${2:-}" dias="${3:-}"
    local minutos lista n

    if [ -z "$dir" ] || [ -z "$padrao" ] || [ -z "$dias" ]; then
        erro "remover_antigos: parametro vazio; nada removido"
        return 1
    fi
    case "$dir" in
        /) erro "remover_antigos: recusado diretorio '/'"; return 1 ;;
        /*) ;;
        *) erro "remover_antigos: diretorio nao absoluto '$dir'; nada removido"; return 1 ;;
    esac
    case "$dir" in
        /var/lib/mysql* | /var/log/mysql* | /var/log/apache2* | /var/lib/guardicore*)
            erro "remover_antigos: diretorio protegido '$dir'; nada removido"
            return 1
            ;;
    esac
    if [ "$dir" != "$LIMPEZA_LOG_DIR" ] && [ "$dir" != "$LIMPEZA_DIAG" ]; then
        erro "remover_antigos: '$dir' fora da lista permitida; nada removido"
        return 1
    fi
    case "$padrao" in
        */* | gc-* | *mysql* | *binlog* | *guardicore*)
            erro "remover_antigos: padrao recusado '$padrao'; nada removido"
            return 1
            ;;
    esac
    if ! numero_ok "$dias"; then
        erro "remover_antigos: dias invalido '$dias'; nada removido"
        return 1
    fi
    if [ ! -d "$dir" ]; then
        log info "diretorio '$dir' inexistente; nada a remover ($padrao)"
        return 0
    fi

    minutos=$((dias * 1440))
    lista="$(find "$dir" -maxdepth 1 -type f -name "$padrao" -mmin +"$minutos" -print 2>/dev/null)"
    if [ -z "$lista" ]; then
        log info "nada a remover em $dir ($padrao, > $dias dias)"
        return 0
    fi
    n="$(printf '%s\n' "$lista" | wc -l | tr -dc '0-9')"
    if [ "$EXECUTAR" -ne 1 ]; then
        printf '%s\n' "$lista" | while IFS= read -r f; do log dry-run "removeria: $f"; done
        return 0
    fi
    if find "$dir" -maxdepth 1 -type f -name "$padrao" -mmin +"$minutos" -delete 2>/dev/null; then
        printf '%s\n' "$lista" | while IFS= read -r f; do log acao "removido: $f"; done
        ACOES=$((ACOES + n))
        return 0
    fi
    erro "remover_antigos: find -delete falhou em $dir ($padrao)"
    return 1
}

nivel2() {
    if [ "${LIMPEZA_PULAR_SISTEMA:-0}" = "1" ]; then
        log info "journalctl/apt-get pulados (LIMPEZA_PULAR_SISTEMA=1)"
    else
        if command -v journalctl >/dev/null 2>&1; then
            executar_cmd "journalctl --vacuum-size=$LIMPEZA_JOURNAL_MAX" journalctl "--vacuum-size=$LIMPEZA_JOURNAL_MAX"
        else
            log info "journalctl ausente; pulado"
        fi
        if command -v apt-get >/dev/null 2>&1; then
            executar_cmd "apt-get clean" apt-get clean
        else
            log info "apt-get ausente; pulado"
        fi
    fi
    remover_antigos "$LIMPEZA_LOG_DIR" 'syslog.[0-9]*' "$LIMPEZA_SYSLOG_DIAS"
    remover_antigos "$LIMPEZA_DIAG" 'syslog_[0-9]*_[0-9]*.log' "$LIMPEZA_DIAG_DIAS"
}

nivel3() {
    local gb nome destino tam_orig tam_copia tam_agora
    if [ ! -d "$LIMPEZA_APPS" ]; then
        erro "nivel 3 nao aplicado: $LIMPEZA_APPS inexistente"
        return 1
    fi
    if ! gb="$(apps_livre_gb)"; then
        erro "nivel 3 nao aplicado: nao foi possivel medir o livre de $LIMPEZA_APPS"
        return 1
    fi
    if [ "$gb" -lt "$LIMPEZA_APPS_MIN_GB" ]; then
        erro "nivel 3 nao aplicado: $LIMPEZA_APPS com ${gb} GB livres (minimo $LIMPEZA_APPS_MIN_GB GB)"
        return 1
    fi
    if [ ! -d "$LIMPEZA_DIAG" ]; then
        if [ "$EXECUTAR" -ne 1 ]; then
            log dry-run "faria: mkdir -p $LIMPEZA_DIAG e chmod 700"
        else
            if mkdir -p -- "$LIMPEZA_DIAG" 2>/dev/null && chmod 700 -- "$LIMPEZA_DIAG" 2>/dev/null; then
                log acao "criado $LIMPEZA_DIAG (chmod 700)"
            else
                erro "nivel 3 nao aplicado: nao foi possivel criar $LIMPEZA_DIAG"
                return 1
            fi
        fi
    fi
    if [ ! -f "$LIMPEZA_SYSLOG" ]; then
        log info "$LIMPEZA_SYSLOG inexistente; nada a truncar"
        return 0
    fi
    tam_orig="$(stat -c %s -- "$LIMPEZA_SYSLOG" 2>/dev/null || echo 0)"
    if [ "$tam_orig" -eq 0 ]; then
        log info "$LIMPEZA_SYSLOG ja esta vazio; nada a truncar"
        return 0
    fi
    nome="syslog_$(date +%Y%m%d_%H%M%S).log"
    destino="$LIMPEZA_DIAG/$nome"
    if [ "$EXECUTAR" -ne 1 ]; then
        log dry-run "faria: copiar $LIMPEZA_SYSLOG (${tam_orig} bytes) para $destino, conferir o tamanho e truncar o original"
        return 0
    fi
    if cp -- "$LIMPEZA_SYSLOG" "$destino" 2>/dev/null; then
        tam_copia="$(stat -c %s -- "$destino" 2>/dev/null || echo -1)"
        tam_agora="$(stat -c %s -- "$LIMPEZA_SYSLOG" 2>/dev/null || echo -2)"
        if [ "$tam_copia" -gt 0 ] && [ "$tam_copia" -eq "$tam_agora" ]; then
            if truncate -s 0 -- "$LIMPEZA_SYSLOG" 2>/dev/null; then
                log acao "syslog copiado para $destino (${tam_copia} bytes) e truncado"
                ACOES=$((ACOES + 1))
                return 0
            fi
            erro "truncate de $LIMPEZA_SYSLOG falhou; a copia $destino foi mantida"
            return 1
        fi
        erro "copia nao confere (copia=${tam_copia} bytes, syslog=${tam_agora} bytes): syslog NAO truncado; copia $destino mantida"
        return 1
    fi
    erro "cp de $LIMPEZA_SYSLOG para $destino falhou: syslog NAO truncado"
    # remove so a copia parcial criada agora (nome conhecido, dentro de LIMPEZA_DIAG)
    case "$nome" in
        syslog_[0-9]*_[0-9]*.log)
            if [ -n "$LIMPEZA_DIAG" ] && [ -f "$destino" ]; then
                rm -f -- "$destino" 2>/dev/null || true
            fi
            ;;
    esac
    return 1
}

# --- Argumentos -----------------------------------------------------------
for arg in "$@"; do
    case "$arg" in
        --executar) EXECUTAR=1 ;;
        --help | -h) ajuda; exit 0 ;;
        *) echo "Opcao invalida: $arg" >&2; ajuda >&2; exit 2 ;;
    esac
done

if ! validar_config; then
    exit 2
fi

preparar_log

# --- Lock -------------------------------------------------------------------
if [ "${LIMPEZA_SEM_LOCK:-0}" != "1" ]; then
    if command -v flock >/dev/null 2>&1; then
        if ! exec 9>"$LIMPEZA_LOCK"; then
            erro "nao foi possivel abrir o lock $LIMPEZA_LOCK"
            exit 2
        fi
        if ! flock -n 9; then
            log aviso "ja existe uma execucao em andamento (lock); saindo"
            exit 3
        fi
    elif [ "$EXECUTAR" -eq 1 ]; then
        erro "flock ausente: recusando executar sem lock"
        exit 2
    else
        log aviso "flock ausente (dry-run segue sem lock)"
    fi
fi

MODO="dry-run"
if [ "$EXECUTAR" -eq 1 ]; then MODO="executar"; fi

if ! USO="$(uso_pct)"; then
    erro "nao foi possivel medir o uso de $LIMPEZA_VAR"
    exit 2
fi

if [ "$USO" -ge "$LIMPEZA_NIVEL3_PCT" ]; then
    NIVEL=3
elif [ "$USO" -ge "$LIMPEZA_NIVEL2_PCT" ]; then
    NIVEL=2
elif [ "$USO" -ge "$LIMPEZA_NIVEL1_PCT" ]; then
    NIVEL=1
else
    NIVEL=0
fi

log info "modo=$MODO $LIMPEZA_VAR em ${USO}% (nivel $NIVEL; limites $LIMPEZA_NIVEL1_PCT/$LIMPEZA_NIVEL2_PCT/$LIMPEZA_NIVEL3_PCT)"

case "$NIVEL" in
    0)
        log ok "uso de $LIMPEZA_VAR abaixo de ${LIMPEZA_NIVEL1_PCT}%; nada a fazer"
        ;;
    1)
        log atencao "$LIMPEZA_VAR em ${USO}% (>= ${LIMPEZA_NIVEL1_PCT}%): so registrando"
        ;;
    2)
        log alerta "$LIMPEZA_VAR em ${USO}% (>= ${LIMPEZA_NIVEL2_PCT}%): nivel 2"
        nivel2
        ;;
    3)
        log alerta "$LIMPEZA_VAR em ${USO}% (>= ${LIMPEZA_NIVEL3_PCT}%): nivel 3 (inclui o nivel 2)"
        nivel2
        nivel3
        ;;
esac

if [ "$ERROS" -gt 0 ]; then
    log erro "terminou com $ERROS erro(s)"
    exit 2
fi
if [ "$EXECUTAR" -eq 1 ] && [ "$ACOES" -gt 0 ]; then
    log info "terminou: $ACOES acao(oes) executada(s)"
    exit 1
fi
log info "terminou: nada executado"
exit 0
