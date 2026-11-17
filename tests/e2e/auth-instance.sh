#!/usr/bin/env bash
# E2E-Test fuer scripts/auth-instance.sh (Ticket #4). Benoetigt MariaDB (root) und den Auth-Checkout.
# Nutzt eine Wegwerf-Instanz (eigener Ordner, DB, Port) und beruehrt die echte Dev-Instanz nicht.
#   AUTH_SERVICE_PATH=D:/Programming/jwt-auth-service tests/e2e/auth-instance.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
export AUTH_SERVICE_PATH="${AUTH_SERVICE_PATH:?AUTH_SERVICE_PATH setzen}"
export AUTH_INSTANCE_DIR="$TMP/instance"
export API_ENV_FILE="$TMP/api.env"
export AUTH_DB_NAME=teacheros_auth_e2e AUTH_DB_USER=teacheros_auth_e2e AUTH_DB_PASSWORD=e2e-pass
export AUTH_PORT=8101
SCRIPT="$ROOT/scripts/auth-instance.sh"

cleanup() { "$SCRIPT" stop >/dev/null 2>&1 || true; "$SCRIPT" drop-db >/dev/null 2>&1 || true; rm -rf "$TMP"; }
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

"$SCRIPT" setup >/dev/null || fail "setup"
key1="$(cksum < "$AUTH_INSTANCE_DIR/app/auth_private.pem")"
"$SCRIPT" setup >/dev/null || fail "zweites setup nicht gefahrlos"
[ "$key1" = "$(cksum < "$AUTH_INSTANCE_DIR/app/auth_private.pem")" ] || fail "Schluessel wurde beim zweiten setup ersetzt"

grep -q '^AUTH_TOKEN_AUDIENCE=teacheros:dev-schule$' "$API_ENV_FILE" || fail "aud nicht in API-Config"
grep -q '^AUTH_TOKEN_ISSUER=' "$API_ENV_FILE" || fail "iss nicht in API-Config"
pub="$(sed -n 's/^AUTH_PUBLIC_KEY_PATH=//p' "$API_ENV_FILE")"
[ -s "$pub" ] || fail "oeffentlicher Schluessel fehlt: $pub"
grep -q 'PRIVATE' "$pub" && fail "privater Schluessel in der API-Config"
# TeacherOS-Instanzen schalten Einladung und Anmeldename zu (Tickets #23, #27); auch beim zweiten setup.
grep -q '^INVITE_ENABLED=true$' "$AUTH_INSTANCE_DIR/app/.env" || fail "INVITE_ENABLED nicht gesetzt"
grep -q '^USERNAME_LOGIN_ENABLED=true$' "$AUTH_INSTANCE_DIR/app/.env" || fail "USERNAME_LOGIN_ENABLED nicht gesetzt"

"$SCRIPT" start >/dev/null || fail "start"
"$SCRIPT" create-user --email=e2e@example.test --password='E2e-Passwort-123' >/dev/null || fail "create-user"

resp="$(curl -s -X POST "http://127.0.0.1:$AUTH_PORT/login" -H 'Content-Type: application/json' \
  -d '{"email":"e2e@example.test","password":"E2e-Passwort-123"}')"
php -r '
  $r = json_decode($argv[1], true);
  $jwt = $r["accessToken"] ?? $r["token"] ?? $r["access_token"] ?? null;
  if (!$jwt) { fwrite(STDERR, "kein Token: $argv[1]\n"); exit(1); }
  [$h, $p, $s] = explode(".", $jwt);
  $d = fn($x) => base64_decode(strtr($x, "-_", "+/"));
  $c = json_decode($d($p), true);
  $ok = openssl_verify("$h.$p", $d($s), file_get_contents($argv[2]), OPENSSL_ALGO_SHA256) === 1;
  if (!$ok) { fwrite(STDERR, "Signatur ungueltig\n"); exit(1); }
  if (($c["aud"] ?? null) !== "teacheros:dev-schule") { fwrite(STDERR, "aud falsch\n"); exit(1); }
  if (($c["iss"] ?? null) !== $argv[3]) { fwrite(STDERR, "iss falsch\n"); exit(1); }
' "$resp" "$pub" "$(sed -n 's/^AUTH_TOKEN_ISSUER=//p' "$API_ENV_FILE")" || fail "JWT-Pruefung"

# Anmeldung ueber das Feld `login` (G6): E-Mail wie Anmeldename, hier die E-Mail des angelegten Kontos.
resp="$(curl -s -X POST "http://127.0.0.1:$AUTH_PORT/login" -H 'Content-Type: application/json'   -d '{"login":"e2e@example.test","password":"E2e-Passwort-123"}')"
echo "$resp" | grep -q '"token"' || fail "Login ueber das Feld login: $resp"
# Eine unbekannte Kennung ohne @ (Anmeldename) wird wie ein falsches Passwort beantwortet, nicht mit 400/500.
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:$AUTH_PORT/login" -H 'Content-Type: application/json'   -d '{"login":"gibt.es.nicht","password":"E2e-Passwort-123"}')"
[ "$code" = "401" ] || fail "unbekannter Anmeldename: erwartet 401, war $code"
echo "OK"
