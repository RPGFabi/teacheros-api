# TeacherOS – Arbeitsumgebung

TeacherOS ist eine schulische Webplattform für Unterrichts- und Leistungsverwaltung, browser-first, mit nativer Hülle für iOS/Android und einer getrennten Instanz pro Schule. Maßgeblich ist die [Source of Truth](TeacherOS_Source_of_Truth_2026-11-14_rev1.md), bei Widersprüchen gilt deren Abschnitt 17.

## Repositories (ADR 0006)

Lokal liegen alle Repos unter `D:\Programming\TeacherOS\apps\` bzw. `D:\Programming\jwt-auth-service`.

| Repo | Inhalt |
|---|---|
| `teacheros-api` | PHP-Backend (modularer Monolith) **und** übergreifendes Material: Source of Truth, ADRs, Glossar, OpenAPI-Verträge, Release-Manifest, `docker-compose.yml`, zentrale Issues |
| `teacheros-web` | React + Vite Client inkl. Capacitor-Hülle (iOS/Android) |
| `jwt-auth-service` | Gemeinsamer Auth-Dienst (auch EAW), eine Instanz pro Schule |

## Erste Schritte

1. `scripts/bootstrap.sh` installiert die Abhängigkeiten von API und Web.
2. `docker compose up -d` im API-Repo startet MariaDB.
3. Umgebungsvariablen aus den `.env.example`-Dateien übernehmen.

## Lokale Auth-Instanz (Dev-Schule)

Jede Schule hat eine eigene `jwt-auth-service`-Instanz mit eigener Datenbank, eigenem RS256-Schlüsselpaar und eigenem `iss`/`aud` (ADR 0002 „Grundsatz“). Für die Entwicklungsschule richtet `scripts/auth-instance.sh` sie reproduzierbar ein (`iss=https://auth.dev-schule.teacheros.test`, `aud=teacheros:dev-schule`, `REGISTRATION_DISABLED=true`, Mail-Branding „Dev-Schule“). Das Skript ist die Vorlage für die spätere Provisionierung pro Schule.

Voraussetzungen: PHP >= 8.4 (sodium, openssl, pdo_mysql) im `PATH`, MariaDB auf `127.0.0.1:3306` mit Root-Zugang (Standard `root`/`teacheros-root`, wie in `docker-compose.yml`) und ein Auth-Checkout mit installierten Abhängigkeiten (`composer install`).

```bash
export AUTH_SERVICE_PATH=/d/Programming/jwt-auth-service   # Checkout, wird nur gelesen
scripts/auth-instance.sh setup          # Schlüssel, .env, DB teacheros_auth + Nutzer, Migrationen, API-Config
scripts/auth-instance.sh start          # PHP-Server auf http://127.0.0.1:8100 (stop / status analog)
scripts/auth-instance.sh create-user --email=lehrer@example.test --password='...'
curl -s -X POST http://127.0.0.1:8100/login -H 'Content-Type: application/json'   -d '{"email":"lehrer@example.test","password":"..."}'   # liefert das JWT (aud=teacheros:dev-schule)
```

- `setup` ist gefahrlos wiederholbar: vorhandene Schlüssel und `.env` bleiben unverändert, DB und Nutzer werden nur angelegt bzw. angeglichen, Migrationen laufen idempotent.
- Die Instanz läuft aus einer Kopie des Checkouts unter `~/.teacheros/auth/dev-schule/` (Variable `AUTH_INSTANCE_DIR`), weil der Dienst seinen privaten Schlüssel fest unter `<Wurzel>/auth_private.pem` erwartet. Schlüssel und `.env` liegen damit außerhalb beider Repos; nichts davon wird committet. Nach Änderungen am Auth-Checkout `setup` erneut ausführen und neu starten.
- Der öffentliche Schlüssel (`auth_public.pem` im Instanzordner) sowie `iss`/`aud` landen in der gitignorierten `.env` der API: `AUTH_PUBLIC_KEY_PATH`, `AUTH_TOKEN_ISSUER`, `AUTH_TOKEN_AUDIENCE`, `AUTH_BASE_URL` (Vorlage in `.env.example`). Das Backend prüft Signatur, `iss` und `aud` damit gegen **seine** Schule.
- Ohne Redis (lokal nicht vorhanden) wird das Rate Limiting der Auth-Instanz durch `scripts/auth-redis-stub.php` ersetzt (`AUTH_REDIS_MODE=fake`, Standard). Mit Redis (`docker compose --profile optional up -d`) `AUTH_REDIS_MODE=real` setzen und `REDIS_*` in der Instanz-`.env` anpassen.
- Weitere Variablen: `AUTH_PORT` (8100), `AUTH_SCHOOL_SLUG`/`AUTH_SCHOOL_NAME`, `AUTH_DB_*`, `AUTH_DB_ROOT_USER`/`AUTH_DB_ROOT_PASSWORD`, `API_ENV_FILE`.
- Test: `AUTH_SERVICE_PATH=... tests/e2e/auth-instance.sh` richtet eine Wegwerf-Instanz ein, legt ein Konto an, loggt sich ein und prüft Signatur, `iss` und `aud` des JWT.
- Auf Rechnern mit Docker bleibt `docker-compose.yml` die Referenz für MariaDB (und optional Redis); das Skript funktioniert mit jeder erreichbaren MariaDB.

## Weiterführend

- [Architektur](architecture.md) · [Roadmap](roadmap.md) · [Entscheidungen](decisions/) · [Glossar](../GLOSSARY.md)
