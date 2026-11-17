#!/usr/bin/env bash
# E2E-Test fuer den ersten Schuladmin (Ticket #25, Spec #10): echte Wegwerf-Auth-Instanz + echte API-Datenbank.
#   CLI-Einladung -> Einladungstoken aus der Mail-Queue der Auth-Instanz -> POST /acceptInvite
#   -> POST /login -> GET /api/me (Konto aktiv, Rechte des Schuladmins) -> Audit-Eintraege.
# Benoetigt MariaDB mit Root-Zugang (wie scripts/auth-instance.sh) und den Auth-Checkout:
#   AUTH_SERVICE_PATH=D:/Programming/jwt-auth-service tests/e2e/first-admin-invite.sh
# Nicht abgedeckt, bis der Anmeldedienst G6 (Einladung ohne E-Mail, Einladungscode) liefert: der Durchlauf per Anmeldename und Code.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
export AUTH_SERVICE_PATH="${AUTH_SERVICE_PATH:?AUTH_SERVICE_PATH setzen}"
export AUTH_INSTANCE_DIR="$TMP/instance"
export API_ENV_FILE="$TMP/api.env"
export AUTH_DB_NAME=teacheros_auth_e2e_admin AUTH_DB_USER=teacheros_auth_e2e_admin AUTH_DB_PASSWORD=e2e-pass
export AUTH_PORT="${AUTH_PORT:-8102}"
API_PORT="${API_PORT:-8103}"
API_DB="teacheros_test_e2e_$(php -r 'echo bin2hex(random_bytes(3));')"
SCRIPT="$ROOT/scripts/auth-instance.sh"
EMAIL=admin@e2e.test
PASSWORD='E2e-Passwort-123'
API_PID=""

# DB-Zugaenge der API-Testdatenbank (wie tests/Support/HttpTestApp.php)
api_env() {
  export DB_HOST="${TEST_DB_HOST:-127.0.0.1}" DB_PORT="${TEST_DB_PORT:-3306}" DB_USER="${TEST_DB_USER:-teacheros}" DB_PASSWORD="${TEST_DB_PASSWORD:-teacheros}" DB_NAME="$API_DB"
  export APP_ENV=test LOG_LEVEL=off CORS_ENABLED=0
}
api_env

drop_api_db() {
  H="$DB_HOST" P="$DB_PORT" U="$DB_USER" PW="$DB_PASSWORD" N="$API_DB" php -r '
    $pdo = new PDO("mysql:host=".getenv("H").";port=".getenv("P"), getenv("U"), getenv("PW"));
    $pdo->exec("DROP DATABASE IF EXISTS `".getenv("N")."`");' >/dev/null 2>&1 || true
}
cleanup() {
  [ -z "$API_PID" ] || kill "$API_PID" >/dev/null 2>&1 || true
  if command -v taskkill >/dev/null 2>&1; then
    for pid in $(netstat -ano | awk -v p="127.0.0.1:$API_PORT" '$2 == p && $3 == "0.0.0.0:0" {print $5}' | sort -u); do
      taskkill //PID "$pid" //F //T >/dev/null 2>&1 || true
    done
  fi
  "$SCRIPT" stop >/dev/null 2>&1 || true
  "$SCRIPT" drop-db >/dev/null 2>&1 || true
  drop_api_db
  rm -rf "$TMP"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
json() { php -r '$j = json_decode($argv[1], true); $v = $j; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_scalar($v) ? (string) $v : json_encode($v);' "$1" "$2"; }

# 1. Wegwerf-Auth-Instanz und API-Datenbank
"$SCRIPT" setup >/dev/null || fail "setup der Auth-Instanz"
"$SCRIPT" start >/dev/null || fail "start der Auth-Instanz"
php "$ROOT/setup/run.php" --mode=migrate >/dev/null || fail "Migration der API-Datenbank"

# API-Konfiguration aus der von setup geschriebenen Datei (Schluessel, iss/aud, Auth-URL, Webhook-Secret)
set -a; . "$API_ENV_FILE"; set +a
api_env # die Vorlage aus .env.example darf die Test-DB nicht ueberschreiben
[ -n "${AUTH_SERVICE_WEBHOOK_SECRET:-}" ] || fail "AUTH_SERVICE_WEBHOOK_SECRET fehlt in der API-Config"

# 2. CLI-Einladung
out="$(cd "$ROOT" && php bin/invite-first-admin.php --email="$EMAIL" 2>&1)" || fail "CLI-Einladung: $out"
echo "$out" | grep -q "Eingeladen" || fail "unerwartete CLI-Ausgabe: $out"
second="$(cd "$ROOT" && php bin/invite-first-admin.php --email="$EMAIL" 2>&1)" || fail "erneute Einladung ohne Option: $second"
echo "$second" | grep -q "Erneut eingeladen" || fail "erneute Einladung nicht erkannt: $second"

# 3. Token der neuesten Einladungsmail aus der Queue der Auth-Instanz (das erste Token ist nach der erneuten Einladung ungueltig)
queue() {
  H="$DB_HOST" P="$DB_PORT" N="$AUTH_DB_NAME" U="$AUTH_DB_USER" PW="$AUTH_DB_PASSWORD" php -r '
    $pdo = new PDO("mysql:host=".getenv("H").";port=".getenv("P").";dbname=".getenv("N").";charset=utf8mb4", getenv("U"), getenv("PW"));
    foreach ($pdo->query("SELECT payload_json FROM email_queue ORDER BY created_at, id")->fetchAll(PDO::FETCH_COLUMN) as $json) {
      $mail = json_decode($json, true);
      if (preg_match("/einladung\?token=([0-9a-f]+)/", $mail["htmlBody"] ?? "", $m)) { echo $m[1], "\n"; }
    }'
}
tokens="$(queue)"
[ "$(echo "$tokens" | wc -l)" -eq 2 ] || fail "zwei Einladungsmails erwartet, Queue: $tokens"
old_token="$(echo "$tokens" | head -n 1)"
token="$(echo "$tokens" | tail -n 1)"

AUTH="http://127.0.0.1:$AUTH_PORT"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$AUTH/acceptInvite" -H 'Content-Type: application/json' -d "{\"token\":\"$old_token\",\"password\":\"$PASSWORD\"}")"
[ "$code" = 400 ] || fail "ersetztes Token muss abgelehnt werden, war HTTP $code"

# 4. Annahme, Login (vorher kein Login)
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$AUTH/login" -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")"
[ "$code" = 401 ] || fail "vor der Annahme darf kein Login moeglich sein, war HTTP $code"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$AUTH/acceptInvite" -H 'Content-Type: application/json' -d "{\"token\":\"$token\",\"password\":\"$PASSWORD\"}")"
[ "$code" = 200 ] || fail "acceptInvite: HTTP $code"
resp="$(curl -s -X POST "$AUTH/login" -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")"
jwt="$(php -r '$r = json_decode($argv[1], true); echo $r["accessToken"] ?? $r["token"] ?? $r["access_token"] ?? "";' "$resp")"
[ -n "$jwt" ] || fail "kein Token im Login: $resp"

# 5. API: erste Anmeldung macht das Konto aktiv
(cd "$ROOT" && nohup php -S "127.0.0.1:$API_PORT" -t public public/index.php >"$TMP/api.log" 2>&1 & echo $! > "$TMP/api.pid")
API_PID="$(cat "$TMP/api.pid")"
for _ in $(seq 1 40); do curl -s "http://127.0.0.1:$API_PORT/api/health" >/dev/null 2>&1 && break; sleep 0.25; done
me="$(curl -s "http://127.0.0.1:$API_PORT/api/me" -H "Authorization: Bearer $jwt")"
[ "$(json "$me" account.status)" = active ] || fail "Konto nicht aktiv: $me"
echo "$me" | grep -q '"accounts.invite"' || fail "Rechte des Schuladmins fehlen: $me"

# 6. Audit: wer, was, ohne E-Mail
audit="$(H="$DB_HOST" P="$DB_PORT" N="$API_DB" U="$DB_USER" PW="$DB_PASSWORD" php -r '
  $pdo = new PDO("mysql:host=".getenv("H").";port=".getenv("P").";dbname=".getenv("N").";charset=utf8mb4", getenv("U"), getenv("PW"));
  echo implode(",", $pdo->query("SELECT CONCAT(actor_type, \":\", action) FROM audit_log ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
  echo "|", $pdo->query("SELECT COUNT(*) FROM audit_log WHERE CONCAT_WS(\" \", action, object_id, IFNULL(context, \"\")) LIKE \"%@%\"")->fetchColumn();')"
[ "$audit" = "cli:account.invited,cli:role.assigned,cli:account.reinvited,account:account.activated|0" ] || fail "Audit-Eintraege unerwartet: $audit"
echo "OK"
