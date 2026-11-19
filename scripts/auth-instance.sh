#!/usr/bin/env bash
# Lokale Auth-Instanz fuer die Entwicklungsschule (ADR 0002 "Grundsatz", ADR 0006, Ticket #4).
# Vorlage fuer die spaetere Provisionierung pro Schule.
#
#   scripts/auth-instance.sh setup        Schluessel, .env, DB + DB-Nutzer, Migrationen, API-Config (idempotent)
#   scripts/auth-instance.sh start|stop|status
#   scripts/auth-instance.sh create-user --email=... --password=...
#   scripts/auth-instance.sh drop-db      Datenbank und DB-Nutzer entfernen (nur Tests/Neuanfang)
#
# Der Auth-Checkout wird nur gelesen (AUTH_SERVICE_PATH). Die Instanz laeuft aus einer Kopie unter
# AUTH_INSTANCE_DIR (ausserhalb beider Repos), weil der Dienst seinen privaten Schluessel fest unter
# <Wurzel>/auth_private.pem erwartet. Schluessel und .env werden nie ins Repo geschrieben.
set -euo pipefail

API_DIR="$(cd "$(dirname "$0")/.." && pwd)"

SCHOOL_SLUG="${AUTH_SCHOOL_SLUG:-dev-schule}"
SCHOOL_NAME="${AUTH_SCHOOL_NAME:-Dev-Schule}"
INSTANCE_DIR="${AUTH_INSTANCE_DIR:-$HOME/.teacheros/auth/$SCHOOL_SLUG}"
PORT="${AUTH_PORT:-8100}"
TOKEN_ISSUER="${AUTH_TOKEN_ISSUER:-https://auth.$SCHOOL_SLUG.teacheros.test}"
TOKEN_AUDIENCE="${AUTH_TOKEN_AUDIENCE:-teacheros:$SCHOOL_SLUG}"
DB_HOST="${AUTH_DB_HOST:-127.0.0.1}"
DB_PORT="${AUTH_DB_PORT:-3306}"
DB_NAME="${AUTH_DB_NAME:-teacheros_auth}"
DB_USER="${AUTH_DB_USER:-teacheros_auth}"
DB_PASSWORD="${AUTH_DB_PASSWORD:-teacheros_auth}"
DB_ROOT_USER="${AUTH_DB_ROOT_USER:-root}"
DB_ROOT_PASSWORD="${AUTH_DB_ROOT_PASSWORD:-teacheros-root}"   # Wert aus docker-compose.yml
REDIS_MODE="${AUTH_REDIS_MODE:-fake}"                         # fake = ohne Redis, kein Rate Limit
API_ENV_FILE="${API_ENV_FILE:-$API_DIR/.env}"

APP_DIR="$INSTANCE_DIR/app"
PUBLIC_KEY="$INSTANCE_DIR/auth_public.pem"
PID_FILE="$INSTANCE_DIR/server.pid"
LOG_FILE="$INSTANCE_DIR/server.log"

die() { echo "Fehler: $*" >&2; exit 1; }
log() { echo "[auth-instance] $*"; }

# Pfade fuer PHP/Konfiguration in Windows-Schreibweise (C:/...), sonst unveraendert.
native() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi; }

command -v php >/dev/null || die "php nicht im PATH (PHP >= 8.4 mit sodium, openssl, pdo_mysql)"
: "${AUTH_SERVICE_PATH:?AUTH_SERVICE_PATH muss auf den jwt-auth-service-Checkout zeigen}"
[ -f "$AUTH_SERVICE_PATH/public/index.php" ] || die "AUTH_SERVICE_PATH ist kein jwt-auth-service: $AUTH_SERVICE_PATH"

if [ -z "${OPENSSL_CONF:-}" ]; then
  conf="$(dirname "$(command -v php)")/extras/ssl/openssl.cnf"
  if [ -f "$conf" ]; then OPENSSL_CONF="$(native "$conf")"; export OPENSSL_CONF; fi
fi

# DB-Verwaltung als Root ueber PDO (kein mysql-Client noetig). Argument: create | drop
db_admin() {
  ROOT_USER="$DB_ROOT_USER" ROOT_PW="$DB_ROOT_PASSWORD" H="$DB_HOST" P="$DB_PORT" \
  N="$DB_NAME" U="$DB_USER" PW="$DB_PASSWORD" ACTION="$1" php -r '
    $pdo = new PDO("mysql:host=".getenv("H").";port=".getenv("P"), getenv("ROOT_USER"), getenv("ROOT_PW"),
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $n = getenv("N"); $u = getenv("U");
    foreach ([$n, $u] as $id) if (!preg_match("/^[A-Za-z0-9_]+$/", $id)) { fwrite(STDERR, "ungueltiger Name\n"); exit(1); }
    $q = fn($v) => $pdo->quote($v);
    if (getenv("ACTION") === "create") {
      $pdo->exec("CREATE DATABASE IF NOT EXISTS `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      foreach (["localhost", "127.0.0.1"] as $host) {
        $acct = "`$u`@" . $q($host);
        $pdo->exec("CREATE USER IF NOT EXISTS $acct IDENTIFIED BY " . $q(getenv("PW")));
        $pdo->exec("ALTER USER $acct IDENTIFIED BY " . $q(getenv("PW")));
        $pdo->exec("GRANT ALL PRIVILEGES ON `$n`.* TO $acct");
      }
    } else {
      $pdo->exec("DROP DATABASE IF EXISTS `$n`");
      foreach (["localhost", "127.0.0.1"] as $host) $pdo->exec("DROP USER IF EXISTS `$u`@" . $q($host));
    }'
}

rand_hex() { php -r 'echo bin2hex(random_bytes(24));'; }

# Setzt KEY=WERT in einer .env-Datei (ersetzt vorhandene Zeile, sonst anhaengen).
set_env_key() {
  local file="$1" key="$2" value="$3" tmp
  tmp="$(mktemp)"
  { grep -v "^${key}=" "$file" 2>/dev/null || true; echo "${key}=${value}"; } > "$tmp"
  cat "$tmp" > "$file"; rm -f "$tmp"
}

sync_app() {
  mkdir -p "$APP_DIR"
  # Code-Kopie des Checkouts; Schluessel, .env und Laufzeitdaten der Instanz bleiben erhalten.
  (cd "$AUTH_SERVICE_PATH" && tar cf - \
    --exclude=./.git --exclude=./tests --exclude=./graphify-out --exclude=./.graphify \
    --exclude=./auth_private.pem --exclude=./.env --exclude=./debug --exclude=./storage \
    --exclude=./tmp --exclude=./cache --exclude=./uploads .) | (cd "$APP_DIR" && tar xf -)
}

generate_keys() {
  if [ -s "$APP_DIR/auth_private.pem" ]; then log "Schluesselpaar vorhanden, bleibt unveraendert"; return; fi
  KEY_PRIV="$(native "$APP_DIR")/auth_private.pem" KEY_PUB="$(native "$PUBLIC_KEY")" php -r '
    $k = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);
    if ($k === false) { fwrite(STDERR, "Schluessel konnte nicht erzeugt werden: " . openssl_error_string() . "\n"); exit(1); }
    openssl_pkey_export($k, $priv);
    file_put_contents(getenv("KEY_PRIV"), $priv);
    file_put_contents(getenv("KEY_PUB"), openssl_pkey_get_details($k)["key"]);' || die "Schluesselerzeugung fehlgeschlagen"
  chmod 600 "$APP_DIR/auth_private.pem" 2>/dev/null || true
  log "RS256-Schluesselpaar erzeugt"
}

write_env() {
  local env="$APP_DIR/.env"
  if [ -f "$env" ]; then
    # Einladung (Ticket #23) und Anmeldename (G6, Ticket #27) sind pro Instanz zuschaltbar; auch vorhandene Instanzen bekommen sie.
    set_env_key "$env" INVITE_ENABLED true
    set_env_key "$env" USERNAME_LOGIN_ENABLED true
    log ".env vorhanden, bleibt unveraendert (Secrets bleiben stabil; INVITE_ENABLED=true, USERNAME_LOGIN_ENABLED=true gesetzt)"
    return
  fi
  cp "$AUTH_SERVICE_PATH/.env.example" "$env"
  set_env_key "$env" APP_ENV dev
  set_env_key "$env" APP_DOMAIN "auth.$SCHOOL_SLUG.teacheros.test"
  set_env_key "$env" REGISTRATION_DISABLED true
  set_env_key "$env" INVITE_ENABLED true
  set_env_key "$env" USERNAME_LOGIN_ENABLED true
  set_env_key "$env" DB_HOST "$DB_HOST"
  set_env_key "$env" DB_PORT "$DB_PORT"
  set_env_key "$env" DB_NAME "$DB_NAME"
  set_env_key "$env" DB_USER "$DB_USER"
  set_env_key "$env" DB_PASSWORD "$DB_PASSWORD"
  set_env_key "$env" DB_SOCKET ""
  set_env_key "$env" TOKEN_ISSUER "$TOKEN_ISSUER"
  set_env_key "$env" TOKEN_AUDIENCE "$TOKEN_AUDIENCE"
  # TOTP ist Pflicht fuer Mitarbeitende und Admins (ADR 0002 B3, Ticket #15): ohne MFA kaemen sie nicht an fachliche Routen.
  set_env_key "$env" USE_MFA true
  set_env_key "$env" MFA_ISSUER "\"$SCHOOL_NAME\""
  set_env_key "$env" MFA_SECRETBOX_ACTIVE_KEY_VERSION 1
  set_env_key "$env" MFA_SECRETBOX_KEY_V1_BASE64 "$(php -r 'echo base64_encode(random_bytes(32));')"
  set_env_key "$env" MAIL_MODE null
  set_env_key "$env" MAIL_BRAND_NAME "\"$SCHOOL_NAME\""
  set_env_key "$env" MAIL_BRAND_SIGNATURE "\"dein $SCHOOL_NAME-Team\""
  set_env_key "$env" FRONTEND_BASE_URL "http://localhost:5173"
  set_env_key "$env" CORS_ENABLED true
  set_env_key "$env" CORS_ALLOWED_ORIGINS "http://localhost:5173"
  set_env_key "$env" AUTH_COOKIE_SECURE false
  set_env_key "$env" INTERNAL_NOTIFICATION_SECRET "$(rand_hex)"
  set_env_key "$env" AUTH_SERVER_WEBHOOK_SECRET "$(rand_hex)"
  set_env_key "$env" INTERNAL_NOTIFICATION_LEGACY_MODE false
  set_env_key "$env" INTERNAL_NOTIFICATION_REPLAY_DIR "$(native "$INSTANCE_DIR")/replay"
  chmod 600 "$env" 2>/dev/null || true
  log ".env erzeugt: $env"
}

write_api_config() {
  if [ ! -f "$API_ENV_FILE" ]; then
    if [ -f "$API_DIR/.env.example" ]; then cp "$API_DIR/.env.example" "$API_ENV_FILE"; else : > "$API_ENV_FILE"; fi
  fi
  set_env_key "$API_ENV_FILE" AUTH_PUBLIC_KEY_PATH "$(native "$PUBLIC_KEY")"
  set_env_key "$API_ENV_FILE" AUTH_TOKEN_ISSUER "$TOKEN_ISSUER"
  set_env_key "$API_ENV_FILE" AUTH_TOKEN_AUDIENCE "$TOKEN_AUDIENCE"
  set_env_key "$API_ENV_FILE" AUTH_SERVICE_URL "http://127.0.0.1:$PORT"
  # Gemeinsames Secret der internen Aufrufe (Einladung): aus der Instanz-.env, nie ins Repo.
  local secret; secret="$(sed -n 's/^AUTH_SERVER_WEBHOOK_SECRET=//p' "$APP_DIR/.env" | tail -n 1)"
  set_env_key "$API_ENV_FILE" AUTH_SERVICE_WEBHOOK_SECRET "$secret"
  log "API-Config aktualisiert: $API_ENV_FILE"
}

cmd_setup() {
  mkdir -p "$INSTANCE_DIR/replay"
  sync_app
  generate_keys
  write_env
  db_admin create || die "Datenbank/Nutzer konnten nicht angelegt werden (MariaDB auf $DB_HOST:$DB_PORT, Root-Zugang?)"
  log "Datenbank $DB_NAME und Nutzer $DB_USER bereit"
  (cd "$APP_DIR" && php -d display_errors=0 -d log_errors=0 setup/run.php --mode=migrate) || die "Migration fehlgeschlagen"
  write_api_config
  log "Fertig. Start mit: scripts/auth-instance.sh start"
}

running() { [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; }

cmd_start() {
  [ -f "$APP_DIR/.env" ] || die "Instanz nicht eingerichtet: zuerst setup ausfuehren"
  if running; then log "laeuft bereits (PID $(cat "$PID_FILE"))"; return; fi
  local app; app="$(native "$APP_DIR")"
  local args=(-d display_errors=0 -S "127.0.0.1:$PORT" -t "$app/public" "$app/public/index.php")
  if [ "$REDIS_MODE" = "fake" ]; then
    args=(-d "auto_prepend_file=$(native "$API_DIR")/scripts/auth-redis-stub.php" "${args[@]}")
  fi
  (cd "$APP_DIR" && AUTH_APP_ROOT="$app" nohup php "${args[@]}" > "$LOG_FILE" 2>&1 & echo $! > "$PID_FILE")
  for _ in $(seq 1 40); do
    if curl -sf "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then log "Auth-Instanz laeuft auf http://127.0.0.1:$PORT"; return; fi
    sleep 0.25
  done
  die "Start fehlgeschlagen, siehe $LOG_FILE"
}

cmd_stop() {
  if running; then kill "$(cat "$PID_FILE")" 2>/dev/null || true; fi
  # Git Bash/Windows: die PID aus $! ist nur die MSYS-PID, deshalb Listener am Port beenden
  if command -v taskkill >/dev/null 2>&1; then
    local pid
    for pid in $(netstat -ano | awk -v p="127.0.0.1:$PORT" '$2 == p && $3 == "0.0.0.0:0" {print $5}' | sort -u); do
      taskkill //PID "$pid" //F //T >/dev/null 2>&1 || true
    done
  fi
  rm -f "$PID_FILE"
  log "gestoppt"
}

cmd_status() {
  if running; then log "laeuft (PID $(cat "$PID_FILE")) auf Port $PORT"; else log "gestoppt"; exit 1; fi
}

cmd_create_user() { (cd "$APP_DIR" && php -d display_errors=0 -d log_errors=0 bin/create-user.php "$@"); }

case "${1:-}" in
  setup) cmd_setup ;;
  start) cmd_start ;;
  stop) cmd_stop ;;
  status) cmd_status ;;
  create-user) shift; cmd_create_user "$@" ;;
  drop-db) db_admin drop ;;
  *) echo "Verwendung: $0 {setup|start|stop|status|create-user|drop-db}" >&2; exit 2 ;;
esac
