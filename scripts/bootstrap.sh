#!/usr/bin/env bash
# Installiert die Abhaengigkeiten von API und Web. Erwartet beide Repos nebeneinander unter apps/.
set -euo pipefail

API_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WEB_DIR="$API_DIR/../teacheros-web"

printf '\n[1/2] API-Abhaengigkeiten installieren...\n'
(cd "$API_DIR" && composer install)

printf '\n[2/2] Web-Abhaengigkeiten installieren...\n'
(cd "$WEB_DIR" && npm install)

printf '\nSetup abgeschlossen.\n'
