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

Jede Schule hat eine eigene `jwt-auth-service`-Instanz mit eigener Datenbank, eigenem RS256-Schlüsselpaar und eigenem `iss`/`aud` (ADR 0002 „Grundsatz“). Für die Entwicklungsschule richtet `scripts/auth-instance.sh` sie reproduzierbar ein (`iss=https://auth.dev-schule.teacheros.test`, `aud=teacheros:dev-schule`, `REGISTRATION_DISABLED=true`, `INVITE_ENABLED=true`, `USERNAME_LOGIN_ENABLED=true`, Mail-Branding „Dev-Schule“). Das Skript ist die Vorlage für die spätere Provisionierung pro Schule.

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
- Der öffentliche Schlüssel (`auth_public.pem` im Instanzordner) sowie `iss`/`aud` landen in der gitignorierten `.env` der API: `AUTH_PUBLIC_KEY_PATH`, `AUTH_TOKEN_ISSUER`, `AUTH_TOKEN_AUDIENCE`, `AUTH_SERVICE_URL` und `AUTH_SERVICE_WEBHOOK_SECRET` für die signierten internen Aufrufe (Vorlage in `.env.example`). Das Backend prüft Signatur, `iss` und `aud` damit gegen **seine** Schule.
- Ohne Redis (lokal nicht vorhanden) wird das Rate Limiting der Auth-Instanz durch `scripts/auth-redis-stub.php` ersetzt (`AUTH_REDIS_MODE=fake`, Standard). Mit Redis (`docker compose --profile optional up -d`) `AUTH_REDIS_MODE=real` setzen und `REDIS_*` in der Instanz-`.env` anpassen.
- Weitere Variablen: `AUTH_PORT` (8100), `AUTH_SCHOOL_SLUG`/`AUTH_SCHOOL_NAME`, `AUTH_DB_*`, `AUTH_DB_ROOT_USER`/`AUTH_DB_ROOT_PASSWORD`, `API_ENV_FILE`.
- Test: `AUTH_SERVICE_PATH=... tests/e2e/auth-instance.sh` richtet eine Wegwerf-Instanz ein, legt ein Konto an, loggt sich ein und prüft Signatur, `iss` und `aud` des JWT.
- Auf Rechnern mit Docker bleibt `docker-compose.yml` die Referenz für MariaDB (und optional Redis); das Skript funktioniert mit jeder erreichbaren MariaDB.

## Erster Schuladmin (Ticket #25)

Eine neue Schulinstanz hat kein Konto. Der Betreiber lädt den ersten Schuladmin per CLI ein (Auth-Instanz mit `INVITE_ENABLED=true` und API-Config `AUTH_SERVICE_URL`, `AUTH_SERVICE_WEBHOOK_SECRET`, siehe oben):

```bash
php bin/invite-first-admin.php --email=admin@schule.example [--login-name=name] [--allow-additional-admin]
```

- Die Person erhält die Mail mit Link auf `<FRONTEND>/einladung?token=…`, setzt dort ihr Passwort (direkt beim Anmeldedienst) und meldet sich an; dabei wird das Konto `aktiv`.
- Nur mit `--login-name` (ohne `--email`, Ticket #28) geht keine Mail raus: der Befehl gibt den Einladungscode **einmal** auf der Konsole aus (`Einladungscode (wird nur jetzt angezeigt): XXXX-XXXX-XXXX`), die Person tippt ihn auf `<FRONTEND>/einladung` ein. Die Auth-Instanz braucht dafür `USERNAME_LOGIN_ENABLED=true` (sonst `400 username_login_disabled`, Exit 4).
- Ist schon ein **aktiver** Schuladmin vorhanden, lehnt der Befehl ab (Exit 3, `admin_exists`), außer mit `--allow-additional-admin`. Ein nur eingeladener Admin lässt sich ohne Option erneut einladen, auch an eine andere Adresse: die frühere Einladung wird dann beim Anmeldedienst gelöscht und lokal ersetzt.
- Exit-Codes: 0 ok, 2 Aufruf falsch, 3 fachlich abgelehnt (`admin_exists`, `already_active`, `account_inactive`, `username_taken`, `email_taken`, dazu die fachlichen Ablehnungen des Anmeldedienstes wie `invalid_username`, `username_login_disabled`, `account_disabled`, `invalid_request`), 4 Anmeldedienst nicht verfügbar (`auth_unavailable`: Netzwerkfehler, Zeitüberschreitung, 5xx, unlesbare Antwort; es bleibt kein halber Zustand, Wiederholung ist gefahrlos).
- Nach dem Code gibt die CLI `Gueltig bis: <ISO 8601>` aus, sobald der Anmeldedienst ein Ablaufdatum nennt.
- Audit-Einträge (`account.invited`/`account.reinvited`, `role.assigned`, später `account.activated`) tragen den Akteur `cli` und keine E-Mail-Adresse. Wird eine nie angenommene Einladung ersetzt, bekommt das gelöschte Konto einen eigenen Eintrag `account.invitation_withdrawn` (Objekt = altes Konto, dazu `role.revoked`).

Tests: HTTP und CLI laufen gegen den **Auth-Stub** (`tests/Support/AuthStub.php`, `php -S` an der Netzwerkgrenze, über `AUTH_SERVICE_URL` erreichbar). Er prüft die Signatur wie der echte Dienst, zeichnet Aufrufe auf (`calls()`, `inviteCalls()`) und antwortet nach Skript (`script('invite', ['status' => 500])`, `['delay' => 2.0]` für Timeouts (der API-Timeout im Test ist 0,5 s; `calls()` danach abwarten, `php -S` bedient nacheinander), `['raw' => '<html>']` für eine unlesbare Antwort, `['body' => [...]]` für `already_active`, `username`, `invite_code`, `expires_at`, fachliche Fehler mit `code`). `AuthStub::env()` liefert die Umgebung für `HttpTestApp::cli()`. E2E gegen eine echte Wegwerf-Instanz: `AUTH_SERVICE_PATH=... tests/e2e/first-admin-invite.sh`.

## Konten im Web (Ticket #26)

Schuladmins (Recht `accounts.view` bzw. `accounts.invite`, nicht die Rolle) verwalten Einladungen über die API; alle Endpunkte brauchen ein gültiges Token. Fehler mit Code antworten als `{"error": "...", "code": "..."}` (zentral im `ApiRouter`, nicht pro Endpunkt).

| Endpunkt | Recht | Antwort |
|---|---|---|
| `GET /api/accounts` | `accounts.view` | `200 {"accounts": [{id, email, login_name, status, invited_at}]}` (`invited_at` ISO 8601 UTC oder `null`) |
| `POST /api/accounts/invitations` `{email?, login_name?}` (mindestens eins) | `accounts.invite` | `201 {"account": {...}, "invite_code": null, "invite_expires_at": "…"}`; ohne E-Mail steht der Code einmalig in `invite_code` |
| `POST /api/accounts/{id}/reinvite` | `accounts.invite` | `200`, gleiche Form; `{id}` ist die `public_user_id` |

- Codes: `403 forbidden`; `400 identifier_required`/`invalid_email`/`invalid_username`/`username_login_disabled`/`invalid_request`; `404 account_not_found`; `409 already_active`/`account_inactive`/`account_disabled`/`username_taken`/`email_taken`; `502 auth_unavailable` (nichts wird lokal angelegt, Wiederholung ist gefahrlos). `502` gibt es nur bei Netzwerkfehler, Zeitüberschreitung, 5xx und unlesbarer Antwort des Anmeldedienstes; seine fachlichen 4xx mit `code` gibt die API mit Status und Code weiter (ein 400 ohne Code wird `invalid_request`).
- E-Mail und Anmeldename normalisiert die API auf Kleinschreibung (Web und CLI gleich).
- Die E-Mail eines noch nicht angenommenen Kontos erneut einzuladen ist eine erneute Einladung desselben Kontos (`account.reinvited`); mit anderem Anmeldenamen als gespeichert ergibt es `email_taken`.
- Audit-Eintrag (`account.invited`/`account.reinvited`) mit Akteur `account` und dem einladenden Konto, ohne E-Mail. `invite_code` reicht die API nur aus dem Ergebnis des Anmeldedienstes durch und speichert ihn nicht (weder Konto noch Audit).
- Einladen ohne E-Mail (Ticket #28): `{"login_name": "schueler.eins"}` legt ein Konto ohne E-Mail an. Erneut einladen eines Kontos ohne E-Mail liefert einen neuen Code und macht den vorigen ungültig. `invite_expires_at` (ISO 8601, UTC) nennt, bis wann die Einladung gilt; die API reicht es vom Anmeldedienst durch, ohne es zu speichern (`null`, wenn er keins nennt). Die Einladungskarte zeigt es als „gültig bis“.
- Die Web-Seite zeigt den Code auf einer druckbaren Einladungskarte (10 × 3 cm, QR-Code auf `<Web-Origin>/einladung?code=…`, A4-Raster 2 × 9 über `@media print`). E2E: `tests/e2e/first-admin-invite.sh` spielt auch den Durchlauf per Anmeldename und Code durch (CLI und API, Anmeldedienst mit G6).

## Rollen im Web (Ticket #32)

Lesender Teil der Rollenverwaltung (ADR 0002 B4, Nachtrag #11). Beide Endpunkte brauchen ein gültiges Token und **eines** der drei Verwaltungsrechte `roles.manage`, `roles.assign` oder `permissions.assign` (ein `roles.view` gibt es nicht); sonst `403 {"code": "forbidden"}`.

| Endpunkt | Antwort |
|---|---|
| `GET /api/permissions` | `200 {"permissions": [{key, name, group}]}`: der Rechte-Katalog mit deutschem Namen und Gruppe, in Anzeigereihenfolge |
| `GET /api/roles` | `200 {"roles": [{id, key, name, description, permissions, all_permissions, requires_mfa, protection, account_count, actions, permission_actions}], "create": {allowed, code, reason, permission_actions}}` (Aktionen: siehe „Aktionen auf der Rollenseite“ bei Ticket #33) |

- `protection` (Tabelle `role_settings`, Enum `RoleProtection`): `wildcard` (Schuladmin, alle Rechte), `protected` (Schüler/in, Rechte fest aus dem Code), `standard` (übrige Standardrollen: Rechte änderbar, nicht löschbar), `custom` (selbst angelegt). `requires_mfa` ist bei Standardrollen fest.
- Wildcard-Rollen liefern `all_permissions: true` und in `permissions` den aufgelösten Katalog; `*` verlässt die API nie. `account_count` ist die Zahl der Konten mit der Rolle. Reihenfolge: Standardrollen fest (Schuladmin, Schüler/in, Lehrkraft, Erziehungsberechtigte/r, Sekretariat, Schulleitung), danach eigene Rollen nach Name.
- Das Setup (`setup/migrate/0008_standard_roles.php`) legt die sechs Standardrollen idempotent an; die letzten vier starten ohne Rechte, bestehende Rollen bleiben unverändert. Die Migration enthält die Definitionen (Schlüssel, Name, Beschreibung, Schutzstufe, MFA) fest und nutzt keinen veränderlichen Anwendungscode; `StandardRoles` kennt sie nur für die Laufzeit (Anzeigereihenfolge). Fehlt einer schon vorhandenen Rolle nur die Zeile in `role_settings`, wird sie nachgetragen. Nur wenn die **Rolle selbst** neu angelegt wird, entsteht ein Audit-Eintrag `role.created` (Akteur `cli`, Objekt = Rolle, Kontext Schlüssel und Name); Schuladmin legt schon Migration 0004 an, dafür gibt es keinen.
- Jede Rolle hat eine Zeile in `role_settings` (Migration 0008, `createRole`). Fehlt sie, ist das ein Fehler im Bestand: `RoleSettingsRepository::get`, `RoleOverview::list` und damit Bearbeiten, Löschen und die Rollenliste werfen eine `LogicException` (`500`), statt die Rolle stillschweigend als „eigene Rolle mit MFA“ zu behandeln. Nur `MfaRequirement::recomputeFor` behandelt eine Rolle ohne Merkmale vorsichtig als „erfordert MFA“.
- Rechte-Katalog: `PermissionCatalog::entries()` (Schlüssel, Name, Gruppe); neue Rechte gehören dort hinein. Der Web-Navigationspunkt „Rollen“ erscheint nur mit einem der drei Rechte (aus `GET /api/me`).

## Rollen an Konten vergeben (Ticket #34)

Kontodetail und Rollenvergabe (ADR 0002 B4, Nachtrag #11). Lesen mit `accounts.view` **und** einem der drei Verwaltungsrechte (Review #11, Q25: wer Konten nicht sehen darf, bekommt auch keine Kontodaten), Vergeben und Entziehen nur mit `roles.assign`; sonst `403 forbidden`. Alle Änderungen laufen ausschließlich über `AccessAdministration::assignRole/revokeRole` (eine Transaktion mit Sperre auf dem Zielkonto: Prüfung, Änderung, `accounts.mfa_required` neu, Audit). Die Regeln (P2, P6) stehen in `RoleAssignmentRules` und gelten für Ausführung und Lesesicht gleichermaßen. Nur `GET` und `POST`, weil der CORS-Standard nur diese Methoden zulässt.

| Endpunkt | Antwort |
|---|---|
| `GET /api/accounts/{id}/access` | `200` Zuweisungs-Lesesicht (unten); `404 account_not_found` |
| `POST /api/accounts/{id}/roles` `{role_id}` | vergibt **eine** Rolle additiv; `200` mit der neuen Lesesicht; `400 role_required`, `404 role_not_found` |
| `POST /api/accounts/{id}/roles/{roleId}/revoke` | entzieht **eine** Rolle, andere Rollen und Einzelrechte bleiben; `200` mit der neuen Lesesicht |

- Die Schreibwege (`roles.assign`, `permissions.assign`) verlangen `accounts.view` nicht, antworten aber mit derselben Lesesicht. Deshalb enthält `account` dann nur `{id, mfa_required}`; `email`, `login_name` und `status` liefert die Lesesicht nur Betrachtern mit `accounts.view` (und der CLI). Das Web zeigt die Detailansicht nur mit `accounts.view` und einem Verwaltungsrecht.
- Lesesicht: `account {id, email, login_name, status, mfa_required}`, `roles [{id, key, name, protection, requires_mfa}]`, `permissions [{key, name, group, effective, sources}]` und `role_actions [{role_id, role_key, role_name, action: assign|revoke, allowed, code, reason}]` (eine Zeile je Rolle der Schulinstanz, für den **Betrachter**; `code`/`reason` nennen, warum eine Aktion gesperrt ist). `sources` nennt die Herkunft eines Rechts: `{kind: "role", role_id, role_key, role_name}`, `{kind: "individual"}` (Einzelrecht gewährt) oder `{kind: "withdrawn"}` (Einzelrecht entzogen, dann `effective: false`). Rechte ohne Herkunft fehlen; `*` erscheint nie.
- P2 (403): ohne Wildcard erhält man sich selbst keine Rolle (`self_escalation`) und vergibt wie entzieht eine Rolle nur, wenn man **alle** ihre Rechte effektiv besitzt (`permission_not_held`; die Rolle Schuladmin also nur als Schuladmin). Ein entzogenes Einzelrecht zählt als nicht besessen.
- 409: `student_role_exclusive` (Schüler/in nur ohne weitere Rolle und Einzelrechte, und umgekehrt), `admin_overrides_forbidden` (Rolle Schuladmin an ein Konto mit Einzelrechten).
- Vergeben einer vorhandenen und Entziehen einer nicht vorhandenen Rolle ist ein No-op ohne Audit-Eintrag (`200`).
- Audit: `role.assigned` / `role.revoked`, Objekt = Konto, Akteur = Verwalter, Kontext `{role: <Schlüssel>, name: <Name>}`. `grantInitialAdmin` und `revokeAllRoles` berechnen `mfa_required` ebenfalls neu (`MfaRequirement::recomputeFor`: ODER über `role_settings.requires_mfa` aller Rollen; Rolle ohne Merkmale zählt als „erfordert MFA“).
- `revokeAllRoles` (ersetzte, nie angenommene Einladung) prüft P3 über `ensureAdminRemains` (als Erstes, Rolle vor Konto) und schreibt je entzogener Rolle `role.revoked` `{role, name}` und je zurückgesetztem Einzelrecht `permission.override_cleared` `{permission, mode}`.
- P3 (#35, siehe unten) steht in `RoleAssignmentRules::refusal()` beim Entziehen; Einzelrechte (#36, siehe unten) füllen die Herkünfte `individual`/`withdrawn` der Lesesicht.

## Einzelrechte (Ticket #36)

Ein Verwalter mit `permissions.assign` gewährt, entzieht oder setzt Einzelrechte eines Kontos zurück (Glossar: Einzelrecht; ADR 0002 B4, Nachtrag #11). Alle Änderungen laufen über `AccessAdministration::grantPermission/withdrawPermission/clearPermissionOverride` (eine Transaktion mit Sperre auf dem Zielkonto: Prüfung, Änderung, Audit). Die Regeln stehen in `PermissionOverrideRules` und gelten für Ausführung und Lesesicht gleichermaßen. Je Recht einzeln, kein Gesamt-Ersetzen; nur `POST` (CORS).

| Endpunkt (Body `{permission}`) | Wirkung |
|---|---|
| `POST /api/accounts/{id}/permissions/grant` | Einzelrecht `added`: das Recht gilt zusätzlich zu den Rollen (ersetzt ein entzogenes) |
| `POST /api/accounts/{id}/permissions/withdraw` | Einzelrecht `removed`: das Recht gilt trotz Rolle nicht |
| `POST /api/accounts/{id}/permissions/clear` | Einzelrecht entfernt: es gilt wieder, was die Rollen ergeben |

Alle drei antworten `200` mit der neuen Lesesicht von `GET /api/accounts/{id}/access`; Wiederholen ist ein No-op ohne Audit-Eintrag.

- Nur mit `permissions.assign`, sonst `403 forbidden` (Route). `400 permission_required` (kein Recht angegeben), `400 unknown_permission` (nicht im Katalog, auch `*`), `404 account_not_found`.
- P2 (403, Wildcard-Konten und CLI ausgenommen): am eigenen Konto `self_escalation`; sonst nur Rechte, die der Verwalter selbst **effektiv** besitzt (`permission_not_held`), für alle drei Handlungen. Das Zurücksetzen eines entzogenen Einzelrechts zählt als Gewähren. Prüfreihenfolge: Katalog, Selbst, Besitz, dann 409.
- 409 (nur Gewähren und Entziehen; Zurücksetzen räumt immer auf): `student_role_exclusive` (Konto mit Rolle Schüler/in), `admin_overrides_forbidden` (Konto mit Rolle Schuladmin).
- Lesesicht um `permission_actions [{permission, name, group, action: grant|withdraw|clear, allowed, code, reason}]` erweitert: je Katalogrecht die eine Aktion für den **Betrachter** (`clear`, wenn das Konto ein Einzelrecht dazu hat, sonst `withdraw`, wenn das Recht über eine Rolle gilt, sonst `grant`); `code`/`reason` nennen, warum sie gesperrt ist. Die Herkünfte `individual`/`withdrawn` in `permissions[].sources` sind jetzt befüllt.
- Audit: `permission.granted` / `permission.withdrawn` / `permission.override_cleared`, Objekt = Konto, Akteur = Verwalter, Kontext `{permission, mode}` (`added`/`removed`; beim Zurücksetzen der zurückgesetzte Modus).
- Web: Abschnitt „Einzelrechte“ in `AccountDetail` (alle Katalogrechte mit Herkunft und der einen Aktion; gesperrte Aktionen deaktiviert mit Begründung); `AccountsPage`-Prop `canAssignPermissions` (Recht `permissions.assign` aus `GET /api/me`).

## Rollen anlegen, bearbeiten, löschen (Ticket #33)

Schreibwege der Rollenverwaltung (ADR 0002 B4, Nachtrag #11). Alle brauchen ein gültiges Token und **`roles.manage`** (ein Konto mit nur `roles.assign` erhält `403 forbidden`). Die Änderung läuft ausschließlich über `AccessAdministration` (`createRole`, `updateRole`, `deleteRole`), je in einer Transaktion samt Audit-Eintrag. Nur `GET` und `POST`, weil der CORS-Standard nur diese Methoden zulässt (wie bei #34); Ändern und Löschen sind deshalb eigene POST-Routen.

| Endpunkt | Body | Antwort |
|---|---|---|
| `POST /api/roles` | `{name, description?, permissions?, requires_mfa?}` (Standard: keine Rechte, MFA an) | `201 {"role": {…}}` (Zeile wie in `GET /api/roles`) |
| `POST /api/roles/:id/update` | beliebige der Felder; nicht genannte bleiben unverändert (`description: null` oder `""` leert sie) | `200 {"role": {…}}` |
| `POST /api/roles/:id/delete` | – | `200 {"deleted": true, "id": …}` |

- Den Rollenschlüssel (`r_` + 16 Hex-Zeichen) vergibt die API zufällig; er ist unveränderlich und taucht nur in `key` der Antwort auf, nicht in der Oberfläche. Name 1 bis 120, Beschreibung bis 500 Zeichen, Rechte nur aus dem Katalog (kein `*`).
- Alle Meldungen der API sprechen mit „Sie“.
- Fehler: `400 invalid_request` (Eingabe), `404 role_not_found`; `403 permission_not_held` (P2: ohne Wildcard nur Rechte hinzufügen, die man effektiv besitzt), `403 self_escalation` (eine selbst innegehabte Rolle erweitern; Entfernen von Rechten ist immer erlaubt; Wildcard-Konten sind ausgenommen); `409 role_protected` (Schuladmin/Schüler/in bearbeiten, Standardrolle löschen, MFA-Merkmal einer Standardrolle auf einen anderen Wert setzen; derselbe Wert ist unschädlich), `409 role_in_use` (Löschen einer noch zugewiesenen Rolle). Reihenfolge: 404, `role_protected`, `self_escalation`, `permission_not_held`.
- Ändert sich das MFA-Merkmal einer eigenen Rolle, berechnet `MfaRequirement::recomputeFor()` `accounts.mfa_required` aller Konten mit der Rolle in derselben Transaktion neu (ODER über alle Rollen des Kontos; Rollen ohne Merkmale zählen als MFA-pflichtig).
- Löschen sperrt die Rollenzeile (`SELECT … FOR UPDATE`); gleichzeitige Zuweisungen warten über den Fremdschlüssel: Entweder sieht das Löschen die Zuweisung (`role_in_use`) oder die Zuweisung scheitert an der gelöschten Rolle.
- Audit (Objekt `role` = Rollen-ID): `role.created` und `role.deleted` mit `{role, name}`; `role.updated` mit `{role, name}` plus, soweit zutreffend, `old_name`, `description_changed`, `added` und `removed` (kommagetrennte Rechte-Schlüssel) und `requires_mfa`. Ein Aufruf ohne tatsächliche Änderung schreibt nichts.
- Aktionen auf der Rollenseite (Review #11): Die Regeln stehen in `RoleManagementRules` (Schutzstufen, P2, `roles.manage`) und gelten für die Schreibwege (`AccessAdministration`) wie für die Lesesicht (`RoleManagementView`), nach dem Muster von `role_actions` und `permission_actions`. `GET /api/roles` ergänzt jede Rolle um `actions [{action: edit|delete|set_mfa, allowed, code, reason}]` und `permission_actions [{permission, allowed, code, reason}]` (je Recht, das die Rolle noch nicht hat: darf der Betrachter es hinzufügen? `role_protected`, `self_escalation`, `permission_not_held`) und liefert `create {allowed, code, reason, permission_actions}` für eine neue Rolle. Ohne `roles.manage` ist alles gesperrt (`code: forbidden`). Die Antworten von `POST /api/roles` und `…/update` enthalten dieselben Felder in `role`.
- Web: Seite „Rollen“ mit Anlegen-/Bearbeiten-Formular (gruppierte Checkboxen aus `GET /api/permissions`) und Löschen mit Bestätigung. Welche Aktion erlaubt ist, übernimmt die Seite aus `actions`, `permission_actions` und `create` (keine Nachrechnung im Client); nicht erlaubte Aktionen (ohne `roles.manage`, geschützte Rolle, Standardrolle, noch zugewiesene Rolle, nicht besessenes Recht, selbst innegehabte Rolle erweitern) bleiben sichtbar, aber deaktiviert, mit der Begründung der API; die API entscheidet endgültig.

## Letzter Schuladmin bleibt erhalten (Ticket #35)

P3 (ADR 0002 B4, Nachtrag #11): Wer dem letzten Konto mit Status `active` die Rolle Schuladmin entzieht, erhält `409 last_admin`, auch bei sich selbst. Ein nur eingeladener oder deaktivierter zweiter Admin zählt nicht. Die Lesesicht (`role_actions`) sperrt „Entziehen“ dann mit `code: last_admin` und Begründung; die Web-Kontodetailansicht zeigt den Knopf deaktiviert.

- Baustein: `AccessAdministration::ensureAdminRemains(array $leavingIds)` (wirft `last_admin`). Für spätere Aufrufer wie die Deaktivierung von Konten: in der Transaktion der Aktion als **erstes** aufrufen, mit den Konten, die als aktive Schuladmins ausscheiden würden. Konten ohne aktive Admin-Rolle ändern nichts und werden nie abgelehnt. Die Regel selbst steht in `LastAdminGuard`; `RoleAssignmentRules` nutzt sie für Ausführung (sperrend) und Lesesicht (nicht sperrend).
- Sperrstrategie: Die Zeile der Rolle Schuladmin in `permission_roles` ist der Mutex über den Kreis der Admins (`SELECT … FOR UPDATE`), danach liest die Prüfung die aktiven Admins ebenfalls mit `FOR UPDATE` (neuester Stand, auch in laufender Transaktion). Reihenfolge immer: Rolle (Mutex), dann Zielkonto; `changeRole` sperrt deshalb die Rolle vor dem Konto, beim Vergeben wie beim Entziehen. Der Verlierer eines Rennens erhält `409 last_admin` oder, wenn der Gewinner ihm die Rolle schon genommen hat, `403 permission_not_held`.
- Test der Nebenläufigkeit (`tests/Security/LastAdminTest.php`): zwei echte php-cgi-Prozesse gleichzeitig (`HttpTestApp::requestConcurrently`) gegen dieselbe Test-DB; ein Test-Trigger verzögert das Löschen der Rollenzuweisung um 1,5 s und macht das Fenster zwischen Prüfung und Commit groß. Ohne Sperre scheitert der Test reproduzierbar (beide Requests `200`, kein Admin übrig).

## Konto deaktivieren (Ticket #41)

Ein Verwalter mit `accounts.deactivate` deaktiviert ein **aktives** Konto (Glossar: Deaktivierung; ADR 0002 B1/B2, Nachtrag #12). `POST /api/accounts/{id}/deactivate` (ohne Body, nur `POST` wegen CORS) antwortet mit der Lesesicht des Kontos; die Sperre greift beim nächsten Request, auch mit noch gültigem Token. Dieses Ticket sperrt nur lokal; das Spiegeln an den Anmeldedienst (`disable-login`) folgt in #42.

- Wirkung (`AccessAdministration::deactivateAccount`, eine Transaktion): `accounts.status = inactive`, `inactive_reason = admin_locked` (Migration 0009, Enum `InactiveReason`), `inactive_since` und `sessions_valid_after` = jetzt, Audit `account.deactivated` `{reason}`. Rollen und Einzelrechte bleiben.
- Token (`AuthenticationMiddleware`): nicht aktives Konto `401 account_inactive` mit `reason` (`admin_locked`, sonst `null`); danach B2: `iat < sessions_valid_after` (oder fehlendes `iat`, fail-closed) ergibt `401 session_revoked`. Die Grenze bleibt auch nach einer späteren Reaktivierung stehen.
- Regeln in `AccountDeactivationRules` (Ausführung und Lesesicht, Reihenfolge): `403 forbidden` (kein `accounts.deactivate`, schon die Route), `409 self_deactivation`, `403 permission_not_held` (P2: der Akteur ohne Wildcard besitzt nicht alle effektiven Rechte des Ziels; ein Wildcard-Ziel deaktivieren daher nur Schuladmins), `409 account_not_active` (eingeladen oder schon deaktiviert), `409 last_admin` (P3 über `LastAdminGuard`, derselbe Baustein wie `ensureAdminRemains`). Unbekanntes Konto `404 account_not_found`. Über HTTP trifft `last_admin` nur beim Rennen zweier Admins, die einander gleichzeitig deaktivieren.
- Sperrreihenfolge: Mutex der Schuladmins, Zielkonto, dann die Admin-Zeilen (in der Regelprüfung). `tests/Accounts/AccountDeactivationTest.php` prüft die Rennen mit zwei php-cgi-Prozessen und einem Trigger, der das Deaktivieren um 1,5 s verzögert.
- Lesesicht: `account` enthält (mit `accounts.view`) zusätzlich `inactive_since` und `inactive_reason`; neu `account_actions [{action: "deactivate", allowed, code, reason}]` für den Betrachter (auch fehlendes Recht, `code: forbidden`). Die Kontenliste (`GET /api/accounts`) nennt `inactive_since` für deaktivierte Konten.
- Andockpunkt #42: `AccountAccessController::deactivate` ruft `deactivateAccount` auf, das erst nach dem lokalen Commit zurückkehrt; dort folgt `disable-login`. Das Web zeigt „Deaktivieren …“ mit Bestätigungsdialog in der Kontodetailansicht, gesperrte Aktion bleibt sichtbar mit der Begründung der API.

## Weiterführend

- [Architektur](architecture.md) · [Roadmap](roadmap.md) · [Entscheidungen](decisions/) · [Glossar](../GLOSSARY.md)
