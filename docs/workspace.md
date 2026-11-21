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

- MFA ist eingeschaltet (`USE_MFA=true`, eigener Schlüssel `MFA_SECRETBOX_KEY_V1_BASE64`, `MFA_ISSUER` = Schulname), weil Mitarbeitende und Admins ohne TOTP keine fachlichen Routen erreichen (Ticket #15). Eine vor #15 eingerichtete Instanz hat noch `USE_MFA=false`: in ihrer `.env` (Instanzordner) die drei Werte nachtragen oder `.env` löschen und `setup` erneut ausführen.
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

## Modulfreigaben (Ticket #13)

Welche Module an der Schulinstanz laufen, legt der Betreiber per CLI fest (bis es die Control Plane gibt); `core` ist immer aktiv:

```bash
php bin/module.php list                                   # Module mit Zustand jetzt
php bin/module.php enable observe [--valid-from=2026-12-01T00:00:00+01:00]
php bin/module.php disable observe [--valid-from=...]
php bin/module.php history observe                        # alle Versionen
```

- Ohne `--valid-from` gilt die Änderung sofort; ein Beginn in der Vergangenheit wird abgelehnt. Jede Änderung ist eine neue Version (nichts wird überschrieben) mit Audit-Eintrag `module.enabled`/`module.disabled` (Akteur `cli`).
- Exit-Codes: 0 ok, 2 Aufruf falsch, 3 fachlich abgelehnt (`unknown_module`, `core_always_active`, `valid_from_in_past`, `already_enabled`, `already_disabled`).
- Fachrouten: `ModuleGate::route('observe', [RequirePermission::for(...)])`; ein inaktives Modul antwortet `403 module_disabled` vor jeder Rechteprüfung. `GET /api/me` liefert `modules`, das Web blendet Menüpunkte inaktiver Module aus.

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

Ein Verwalter mit `accounts.deactivate` deaktiviert ein **aktives** Konto (Glossar: Deaktivierung; ADR 0002 B1/B2, Nachtrag #12). `POST /api/accounts/{id}/deactivate` (ohne Body, nur `POST` wegen CORS) antwortet mit der Lesesicht des Kontos; die Sperre greift beim nächsten Request, auch mit noch gültigem Token. Die lokale Sperre kommt zuerst; der Abgleich mit dem Anmeldedienst (`disable-login`) steht unten (Ticket #42). Die Schreibwege dieses Abschnitts verlangen neben `accounts.deactivate` auch `accounts.view` (Review #12, siehe „Rechte der Lesesicht“ unten).

- Wirkung (`AccessAdministration::deactivateAccount`, eine Transaktion): `accounts.status = inactive`, `inactive_reason = admin_locked` (Migration 0009, Enum `InactiveReason`), `inactive_since` und `sessions_valid_after` = jetzt, Audit `account.deactivated` `{reason}`. Rollen und Einzelrechte bleiben.
- Token (`AuthenticationMiddleware`): nicht aktives Konto `401 account_inactive` mit `reason` (`admin_locked`, sonst `null`); danach B2: `iat <= sessions_valid_after` (Gleichheit widerruft, `iat` und Grenze haben Sekundenauflösung; oder fehlendes `iat`, fail-closed) ergibt `401 session_revoked` (`Account::revokes`). Die Grenze bleibt auch nach einer späteren Reaktivierung stehen.
- Regeln in `AccountDeactivationRules` (Ausführung und Lesesicht; Recht, nicht an sich selbst und P2 samt Meldungstexten im gemeinsamen Kern `AccountActionRules`; Recht, Fehlercode und Meldungstexte je `AccountAction`; Reihenfolge): `403 forbidden` (kein `accounts.deactivate`, schon die Route), `409 self_deactivation`, `403 permission_not_held` (P2: der Akteur ohne Wildcard besitzt nicht alle effektiven Rechte des Ziels; ein Wildcard-Ziel deaktivieren daher nur Schuladmins), `409 account_not_active` (eingeladen oder schon deaktiviert), `409 last_admin` (P3 über `LastAdminGuard`, derselbe Baustein wie `ensureAdminRemains`). Unbekanntes Konto `404 account_not_found`. Über HTTP trifft `last_admin` nur beim Rennen zweier Admins, die einander gleichzeitig deaktivieren.
- Sperrreihenfolge: Mutex der Schuladmins, Zielkonto, dann die Admin-Zeilen (in der Regelprüfung). `tests/Accounts/AccountDeactivationTest.php` prüft die Rennen mit zwei php-cgi-Prozessen und einem Trigger, der das Deaktivieren um 1,5 s verzögert.
- Lesesicht: `account` enthält (mit `accounts.view`) zusätzlich `inactive_since` und `inactive_reason`; neu `account_actions [{action: "deactivate", allowed, code, reason}]` für den Betrachter (auch fehlendes Recht, `code: forbidden`). Die Kontenliste (`GET /api/accounts`) nennt `inactive_since` für deaktivierte Konten.

## Deaktivierung mit dem Anmeldedienst abgleichen (Ticket #42)

„Abgleich“ heißt im Code und in den Texten durchgehend das Melden der lokalen Deaktivierung an den Anmeldedienst (`OpenCalls`: `attempt`, `resync`; Feld `account.open_calls` und für die Sperre `login_sync_pending`, Aktion `resync_login`, Endpunkt `resync-login`). Nach dem lokalen Commit ruft `AccessAdministration::deactivateAccount` (der Service, wie `reactivateAccount`) `OpenCalls::attempt` auf: `AuthServiceClient::disableLogin` sendet `POST /internal/users/disable-login` (Ereignis `user.self_deleted` wie bisher, `reason` = gespeicherter `inactive_reason`, V1 `admin_locked`). Der Aufruf gilt nur bei `200` mit `{"ok": true}` als Erfolg.

- **Offene Aufrufe (Ticket #47, ADR 0002 Nachtrag #16):** „Abgleich offen“ ist eine **Menge offener Aufrufe je Konto**, Tabelle `account_open_calls` (Migration 0018; eine Zeile je Konto und Aufruf `operation`, dazu `rejection_code`). Es gibt `disable_login`, seit #48 `revoke_sessions` und seit #49 `reset_totp`; sie kamen ohne Schemaänderung dazu: jeder Aufruf ist ein `OpenCallHandler` (`operation`, `isObsolete`, `execute`), und `OpenCalls::standardHandlers()` führt sie in der festen Reihenfolge des Nachholens (Sperre, Sitzungswiderruf, TOTP-Zurücksetzung). Wer einen Aufruf einführt, trägt ihn in derselben Transaktion wie die lokale Wirkung ein (`AccountRepository::addOpenCall`), nur der erfolgreiche Aufruf nimmt ihn heraus. So geht die Markierung auch bei einem Absturz zwischen Commit und Aufruf nicht verloren. Migration 0018 übernimmt die alten Spalten `accounts.login_sync_pending` (0010) und `login_sync_error` (0011) einmalig (nur im Lauf, der die Tabelle anlegt) und **behält** sie (ADR 0004, Expand: das vorige Backend liest sie noch; das neue liest und schreibt sie nicht mehr, sie sind ein veralteter Stand). TODO: eine spätere Contract-Migration entfernt sie. Lesesicht: `account.open_calls` (mit `accounts.view`; `[{operation, rejection_code}]` in fester Reihenfolge, `[]` ohne offenen Aufruf), daneben unverändert `account.login_sync_pending` und `account.login_sync_error` für die Sperre; Kontenliste: `login_sync_pending` (nur bei deaktivierten Konten true). Die Reaktivierung nimmt nur die Sperre heraus.
- **Fachliche Ablehnung (Review #12):** Lehnt der Dienst einen Aufruf mit einem Code ab (4xx, z. B. `user_not_found`), bleibt er offen und der Code steht an seiner Zeile (`rejection_code`, `AccountRepository::rejectOpenCall`); die Lesesicht nennt ihn in `account.open_calls` (für die Sperre auch als `account.login_sync_error`), das Web zeigt einen verständlichen Text. Nichterreichbarkeit (Netz, Zeitüberschreitung, 5xx, unlesbare Antwort, fehlende Konfiguration) setzt keinen Code und lässt einen früheren stehen. „Abgleich wiederholen“ gibt die Ablehnung mit Status und Code weiter und aktualisiert den gespeicherten Code; Erfolg und Reaktivierung (der Sperre) nehmen den Aufruf samt Code heraus.
- **Ausfall:** Netzfehler, Zeitüberschreitung, 5xx, unlesbare Antwort, fehlende Konfiguration und fachliche Ablehnungen (z. B. 404 `user_not_found`, dann mit `login_sync_error`) machen die Sperre nicht rückgängig; die Antwort ist `200` mit der Lesesicht (`login_sync_pending: true`, Audit `account.deactivated` wie immer).
- **Abgleich wiederholen:** `POST /api/accounts/{id}/resync-login` (eines der Konto-Rechte `PermissionCatalog::ACCOUNT_ACTION_RIGHTS`, heute `accounts.deactivate`, `accounts.revoke_sessions` und `accounts.reset_mfa`; sonst 403) holt **alle** offenen Aufrufe in fester Reihenfolge nach (`OpenCalls::resync`) und antwortet mit der Lesesicht. Wie die anderen Schreibwege verlangt der Endpunkt zusätzlich `accounts.view`. Ein erfolgreicher Aufruf verlässt die Menge, ein abgelehnter behält seinen Code und hindert die folgenden nicht, Nichterreichbarkeit beendet den Lauf. Geworfen wird der Fehler des ersten gescheiterten Aufrufs. Fehler: `409 sync_not_pending` (nichts offen oder alles hinfällig), `404 account_not_found`, bei Ausfall `502 auth_unavailable` (Aufruf bleibt offen), fachliche Ablehnung des Dienstes mit dessen Status und Code. Kein Audit-Eintrag. Aufrufe und Markierungen laufen in einer Transaktion mit gesperrter Kontozeile, damit eine gleichzeitige Reaktivierung (#43, sperrt dieselbe Zeile) nicht dazwischenfunkt. Idempotent, weil die Aufrufe es sind. Das automatische Nachholen per Outbox bleibt #39.
- Lesesicht: `account_actions` enthält `resync_login {allowed, code, reason}` nur, solange ein Aufruf offen ist (`allowed` = Betrachter hat eines der Konto-Rechte). Die Kontodetailansicht selbst verlangt `accounts.view` plus eines der drei Verwaltungsrechte oder `accounts.deactivate` (Route `/access`, siehe unten).
- Tests: `tests/Accounts/DisableLoginCallTest.php` (HTTP gegen MariaDB und den Auth-Stub, `AuthStub::disableLoginCalls()`, Skripte für Timeout, 5xx, unlesbare Antwort, 404). `tests/Accounts/OpenCallsTest.php` belegt die Menge (Lesesicht, mehrere Aufrufe, Reihenfolge, Ablehnung je Aufruf) mit eigenen Handlern über `tests/Support/open-calls.php`.

## Konto reaktivieren (Ticket #43)

`POST /api/accounts/{id}/reactivate` (ohne Body, Recht `accounts.deactivate`) hebt die Deaktivierung eines Kontos auf (Glossar: Reaktivierung; ADR 0002 B2-Nachtrag #12, G4b) und antwortet mit der Lesesicht. Die Reihenfolge ist umgekehrt zur Deaktivierung: `AccessAdministration::reactivateAccount` sperrt die Kontozeile (`AccountRepository::lock`, wie der Abgleich), prüft die Regeln, ruft **zuerst synchron** `AuthServiceClient::enableLogin` (`POST /internal/users/enable-login`, Ereignis `user.enable_login`, Erfolg nur bei `200` mit `{"ok": true}`) und setzt erst dann lokal `active` (`AccountRepository::reactivate`: `inactive_reason`, `inactive_since` und der offene Aufruf `disable_login` entfallen) und schreibt Audit `account.reactivated`. `sessions_valid_after` bleibt: Tokens von vor der Deaktivierung bleiben `401 session_revoked`, die Person meldet sich neu an.

- Fehler: fachliche Ablehnungen des Anmeldedienstes mit dessen Status und Code (`409 self_deleted`, `409 email_unconfirmed`, `404 user_not_found`), Ausfall, Zeitüberschreitung, 5xx, unlesbare Antwort oder fehlende Konfiguration `502 auth_unavailable`. In allen Fällen ändert sich lokal nichts (kein Audit-Eintrag, Markierung unverändert); erneutes Reaktivieren ist gefahrlos, weil `enable-login` idempotent ist.
- Regeln in `AccountReactivationRules` (Ausführung und Lesesicht, Schwester von `AccountDeactivationRules`, P2 über `ActorPermissions::holdsAllRightsOf`): `403 forbidden`, `409 self_reactivation`, `403 permission_not_held`, `409 account_not_inactive` (aktiv oder nur eingeladen). Unbekanntes Konto `404 account_not_found`. P3 entfällt, Reaktivieren verringert den Kreis der Schuladmins nie. Über HTTP ist `self_reactivation` nicht erreichbar (ein deaktiviertes Konto kommt nicht durch die Anmeldung); der Test ruft den Dienst über `tests/Support/reactivate-as.php`.
- Sperren: eine Transaktion mit gesperrter Kontozeile, auch während des Aufrufs an den Anmeldedienst; ein gleichzeitiges „Abgleich wiederholen“ (`OpenCalls`, dieselbe Zeile) wartet und findet dann ein aktives Konto (`409 sync_not_pending`). Der Schuladmin-Mutex wird nicht gebraucht. Scheitert nach erfolgreichem `enable-login` noch das lokale Schreiben, entsteht ein halber Zustand, der nie mehr Zugang gibt als vorher: das Konto bleibt lokal deaktiviert (Middleware sperrt weiter), und die Wiederholung heilt ihn, weil `enable-login` idempotent ist.
- Lesesicht: `account_actions` enthält `reactivate {allowed, code, reason}` nur bei deaktivierten Konten (bei aktiven und eingeladenen fehlt die Aktion); `resync_login` bleibt, solange der Abgleich offen ist.
- Tests: `tests/Accounts/AccountReactivationTest.php` (HTTP gegen MariaDB und den Auth-Stub, `AuthStub::enableLoginCalls()`, Skript-Kind `enable-login`), darunter der Pflichttest zu alten und neuen Tokens.

## Sitzungswiderruf durch die Verwaltung (Ticket #48)

Ein Verwalter mit `accounts.revoke_sessions` beendet alle Sitzungen eines **aktiven** Kontos (Glossar: Sitzungswiderruf; ADR 0002 B2, Nachtrag #16), etwa nach Geräteverlust. `POST /api/accounts/{id}/revoke-sessions` (ohne Body) antwortet mit der Lesesicht des Kontos. Die Route verlangt `accounts.revoke_sessions` **und** `accounts.view` (403 `forbidden`); das Recht steht im Katalog (Gruppe Konten) und in `PermissionCatalog::ACCOUNT_ACTION_RIGHTS`, ein Konto mit nur diesem Recht und `accounts.view` darf auch das Kontodetail lesen und „Abgleich wiederholen“.

- **Regeln** (`AccountRevocationRules`, eine Quelle für Ausführung und Lesesicht; Kern `AccountActionRules`): Recht (403 `forbidden`), eigenes Konto (409 `self_revocation`, dafür gibt es „Mein Konto“, #50), P2 (403 `permission_not_held`, Wildcard ausgenommen), Status (409 `account_not_active`: nur `aktive` Konten). Kein P3: der Widerruf nimmt keinem Admin Rolle oder Status.
- **Ablauf** (`SessionRevocation::revoke`, der wiederverwendbare Baustein, auch für den eigenen Widerruf #50): in einer Transaktion mit gesperrter Kontozeile die Sitzungsgrenze `sessions_valid_after` (`AccountRepository::revokeSessions`), der offene Aufruf `revoke_sessions` (`SessionEndingCall::revokeSessions()`) und der Audit-Eintrag `account.sessions_revoked` (`{initiated_by: "admin"}`; `RevocationInitiator::Owner` schreibt `self`); nach dem Commit G3 (`AuthServiceClient::revokeSessions`, `POST /internal/users/revoke-sessions`, Ereignis `user.revoke_sessions`, `include_trusted_devices: true`, Erfolg nur bei `200` mit `{"ok": true}`) über `OpenCalls::attempt`. `revoke(..., $attempts)` versucht G3 bis zu `$attempts`-mal und liefert `null`, wenn der Dienst bestätigt hat, sonst den Grund als `CodedException`: `AuthServiceUnavailableException` (nur dann wird wiederholt) oder die fachliche Ablehnung mit Status und Code (nicht wiederholt). #50 nimmt 3 Versuche und antwortet bei Nichterreichbarkeit 502 `auth_unavailable`, bei Ablehnung mit deren Status und Code; die Verwaltung versucht einmal und ignoriert den Grund (200 mit Lesesicht). `$precondition` prüft in der Transaktion die Regeln des Weges (die Verwaltung: `AccountRevocationRules`). Der Aufrufer darf keine Transaktion offen haben.
- **Ausfall:** Wie bei der Sperre bleibt der Widerruf bestehen (Antwort `200`, `account.open_calls` nennt `revoke_sessions`, bei fachlicher Ablehnung mit `rejection_code`); „Abgleich wiederholen“ holt ihn nach.
- **Sperre aller Tokens bei offenem Aufruf** (`TokenBlockingCall`, `OpenCalls::blocksTokens`, `AuthenticationMiddleware`): B2 sperrt nur Tokens bis zur Sitzungsgrenze, solange G3 fehlt, leben aber die Refresh-Tokens beim Anmeldedienst und könnten neue Tokens erzeugen. Deshalb lehnt die Middleware (nach den Prüfungen von Status und Grenze, vor MFA und Rechten) **jedes** Token eines Kontos mit offenem blockierenden Aufruf mit `401 session_revoked` ab, auch Tokens aus neuen Anmeldungen. Ein Token **nach** der Grenze löst vorher die Selbstheilung aus (`OpenCalls::releaseTokens`: nur die `TokenBlockingCall`s werden nachgeholt, nicht `disable_login`, danach das Konto frisch gelesen). Gelingen sie, setzt sie in derselben Transaktion die Sitzungsgrenze auf den Nachholzeitpunkt: auch das auslösende Token ist dann widerrufen (401 `session_revoked`) und die Person meldet sich neu an; bei einem Pflichtkonto nach TOTP-Zurücksetzung hat diese Anmeldung kein TOTP mehr und bekommt 403 `mfa_enrollment_required`, bis es neu eingerichtet ist. Scheitert ein Aufruf, bleibt er offen, die Grenze unverändert und die Antwort 401. Ein Token vor der Grenze wird wie bisher ohne Versuch abgelehnt. Das Nachholen schreibt keinen Audit-Eintrag. Die Zeile des Kontos ist während des Aufrufs gesperrt (die Selbstheilung läuft unter der Zeilensperre); gleichzeitige Requests warten und finden den Aufruf dann erledigt. Hinweis: Bei dauerhaftem Ausfall kostet jeder Request des Kontos mit neuem Token bis zu die Zeitüberschreitung des Anmeldedienstes (`AUTH_SERVICE_TIMEOUT_SECONDS`); das betrifft nur dieses Konto, bis die Outbox (#39) es nachholt.
- **Wiederverwendung (#49, #50):** Ein weiterer blockierender Aufruf (die TOTP-Zurücksetzung, #49, `SessionEndingCall`) ist ein `OpenCallHandler`, der zusätzlich `TokenBlockingCall` implementiert und in `OpenCalls::standardHandlers()` steht; Sperre und Selbstheilung gelten dann ohne weitere Änderung. Widerruf und Zurücksetzung teilen sich die Klasse `SessionEndingCall` (Name und Aufruf sind der einzige Unterschied).
- **Lesesicht:** `account_actions` enthält `revoke_sessions {allowed, code, reason}` immer (wie `deactivate`; bei nicht aktiven Konten gesperrt mit `account_not_active`).
- Tests: `tests/Accounts/SessionRevocationTest.php` (HTTP gegen MariaDB und den Auth-Stub, `AuthStub::revokeSessionsCalls()`; `tests/Support/revoke-sessions.php` ruft den Baustein mit Versuchszahl auf).

## TOTP-Zurücksetzung durch die Verwaltung (Ticket #49)

Ein Verwalter mit `accounts.reset_mfa` setzt das TOTP eines Kontos zurück (Glossar: TOTP-Zurücksetzung; ADR 0002 Nachtrag #16), etwa wenn Gerät und Recovery-Codes verloren sind. `POST /api/accounts/{id}/reset-totp` (ohne Body) antwortet mit der Lesesicht des Kontos. Die Route verlangt `accounts.reset_mfa` **und** `accounts.view`; das Recht steht im Katalog (Gruppe Konten, „TOTP von Konten zurücksetzen“) und in `PermissionCatalog::ACCOUNT_ACTION_RIGHTS` (Kontodetail lesen, „Abgleich wiederholen“).

- **Regeln** (`AccountTotpResetRules`, Kern `AccountActionRules`): Recht (403 `forbidden`), eigenes Konto (409 `self_totp_reset`), P2 (403 `permission_not_held`), Status (409 `account_not_active` nur für noch nicht angenommene Konten: erlaubt für `aktive` und `deaktivierte`, etwa zur Bereinigung vor einer Reaktivierung). Immer erlaubt, auch ohne eingerichtetes TOTP (G1 ist idempotent); das Kontodetail zeigt nicht, ob TOTP eingerichtet ist. Kein P3.
- **Ablauf:** Die Zurücksetzung schließt den Sitzungswiderruf ein und teilt dessen Baustein: `SessionTermination::terminate` (auch Grundlage von `SessionRevocation::revoke`) setzt in einer Transaktion mit gesperrter Kontozeile `sessions_valid_after`, den offenen Aufruf `reset_totp` (`SessionEndingCall::resetTotp()`, ein `TokenBlockingCall` in `OpenCalls::standardHandlers()` hinter `revoke_sessions`) und den Audit-Eintrag `account.totp_reset` (ohne Details); nach dem Commit G1 (`AuthServiceClient::resetMfa`, `POST /internal/users/mfa-reset`, Ereignis `user.mfa_reset`, `public_user_id`; G1 widerruft auch die Refresh-Tokens, ein zusätzlicher G3 entfällt). Ausfall, Sperre aller Tokens, Selbstheilung beim ersten Request (die danach auch das auslösende Token widerruft) und „Abgleich wiederholen“ wie beim Widerruf. Ein offener Widerruf, dessen Nachholversuch an der Erreichbarkeit scheitert, hält den Lauf an, bevor die Zurücksetzung drankommt.
- **Pflichtkonto (#15):** Das Konto muss TOTP danach neu einrichten; bis dahin trägt jedes neue Token `usedMFA=false` und fachliche Routen antworten 403 `mfa_enrollment_required`.
- **Lesesicht:** `account_actions` enthält `reset_totp {allowed, code, reason}` immer, hinter `revoke_sessions` (bei noch nicht angenommenen Konten gesperrt mit `account_not_active`).
- Tests: `tests/Accounts/TotpResetTest.php` (HTTP gegen MariaDB und den Auth-Stub, `AuthStub::mfaResetCalls()`, Skript-Kind `mfa-reset`).

## Eigener Sitzungswiderruf auf „Mein Konto“ (Ticket #50)

Jedes Konto beendet seine eigenen Sitzungen, auch die aktuelle, ohne besonderes Recht (ADR 0002, Nachtrag #16). `POST /api/me/revoke-sessions` (ohne Body, `OwnAccountController`) verlangt ein gültiges Token (wie alle fachlichen Routen auch die MFA-Pflicht, B3) und antwortet mit `{"revoked": true}`.

- **Ablauf:** derselbe Baustein wie bei der Verwaltung (`SessionRevocation::revoke` mit `RevocationInitiator::Owner`, Audit `account.sessions_revoked` mit `{initiated_by: "self"}`, Akteur das Konto selbst), aber mit bis zu **drei** sofortigen Versuchen bei G3. Es gibt keine Vorbedingung außer dem aktiven Konto (ein nicht aktives Konto kommt gar nicht durch die Authentifizierung).
- **Ausfall:** Scheitern alle drei Versuche, bleibt der Widerruf offen (Sitzungsgrenze gesetzt, Aufruf `revoke_sessions` offen, jedes Token des Kontos mit 401 `session_revoked` abgelehnt, Selbstheilung beim ersten Request mit einem Token nach der Grenze, das dabei selbst widerrufen wird) und die Antwort ist `502 {"code": "auth_unavailable"}` mit dem Hinweis, sich später erneut anzumelden oder an die Schule zu wenden. Lehnt der Anmeldedienst fachlich ab (4xx mit Code), wiederholt das Backend nicht: die Antwort trägt dessen Status und Code, der Widerruf bleibt ebenso offen. Das Web meldet in beiden Fällen ab.
- Tests: `tests/Accounts/OwnSessionRevocationTest.php`.

## Rechte der Lesesicht von Konten (Review #12)

- `GET /api/accounts/{id}/access` verlangt `accounts.view` **und** eines von `roles.manage`, `roles.assign`, `permissions.assign`, `accounts.deactivate`, `accounts.revoke_sessions`, `accounts.reset_mfa` (`PermissionCatalog::ACCOUNT_DETAIL_READERS`); sonst `403 forbidden`. Der Verwalter, der nur Konten deaktiviert, sieht so das Konto samt `account_actions`. Das Web (`canViewAccess` in `App.tsx`) bietet die Detailansicht mit derselben Bedingung an.
- `deactivate`, `reactivate` und `resync-login` verlangen zusätzlich zu `accounts.deactivate` auch `accounts.view` (`403 forbidden`), weil ihre Antworten die Lesesicht des Kontos sind; so erfährt der Verwalter aus der `deactivate`-Antwort, dass der Abgleich offen ist.
- Die Lesesicht (GET und die Antworten der Schreibwege) enthält `roles`, `permissions`, `role_actions` und `permission_actions` nur, wenn der Akteur mindestens eines der drei Verwaltungsrechte hat (oder der Betreiber ist); sonst nur `account` und `account_actions`. Die Web-Detailansicht blendet die Abschnitte Rollen, Effektive Rechte und Einzelrechte dann aus.
- Tests: `tests/Accounts/AccountDeactivationTest.php` (Abschnitt „Rechte der Lesesicht und der Schreibwege“).

## Schuljahre (Ticket #54)

Erstes Stammdaten-Ticket (Spec #17; Glossar: Schuljahr). Führt die Rechtegruppe „Stammdaten“ ein: `school_years.view` („Schuljahre ansehen“) und `school_years.manage` („Schuljahre verwalten“). Die Standardrollen haben sie nicht voreingestellt, Schuladmin hat sie über die Wildcard. Die Routen gehören zu `core` und laufen über `ModuleGate::route()` (Anmeldung, Modulprüfung, dann Recht); `manage` schließt `view` nicht ein.

| Endpunkt | Recht | Body | Antwort |
|---|---|---|---|
| `GET /api/school-years` | `school_years.view` | – | `200 {"school_years": [{id, name, starts_on, ends_on, status}]}`, nach Beginn aufsteigend |
| `POST /api/school-years` | `school_years.manage` | `{starts_on, ends_on}` (`JJJJ-MM-TT`, beide Tage inklusive) | `201 {"school_year": {…}}` (Zeile wie in der Liste) |

- **Bezeichnung** wird aus dem Zeitraum erzeugt (`SchoolYearSpan::name()`): Jahr des Beginns, Schrägstrich, letzte zwei Ziffern des Endjahres (14.09.2026 bis 29.07.2027 ergibt „2026/27“). Ein mitgeschicktes `name` wird ignoriert.
- **Status** (`running`, `upcoming`, `past`) wird bei jeder Abfrage aus dem heutigen Datum (Europa/Berlin) abgeleitet (`SchoolYearStatus::derive`), nie gespeichert. Laufend ist das Schuljahr, das heute enthält, sonst (heute in einer Lücke zwischen zwei Schuljahren) das nächste kommende. Liegt heute vor dem ersten Schuljahr, läuft keines und das erste ist kommend; nach dem letzten ist alles vergangen. Höchstens eines läuft.
- **Fehler:** `400 invalid_request` (kein echter Kalendertag im Format `JJJJ-MM-TT`), `422 invalid_period` (Ende nicht im Kalenderjahr nach dem Beginn), `409 school_year_overlap` (Überschneidung, auch bei nur einem gemeinsamen Tag; Lücken sind erlaubt), `503 school_years_busy` (Sperre nicht binnen 10 s frei), `403 forbidden`, `401`.
- **Speicherung:** Tabelle `school_years` (Migration 0013: `id` UUID als stabile Kennung, `starts_on`, `ends_on`). Prüfung auf Überschneidung, Insert und Audit laufen unter der benannten MySQL-Sperre `teacheros.school_years` in einer Transaktion (zwei gleichzeitige Anfragen setzen sich nicht beide durch).
- **Audit:** `school_year.created` (Objekt `school_year`, Objekt-ID = Kennung, Kontext `name`).
- Code: `src/Modules/SchoolYears/` (`SchoolYearRoutes`, `SchoolYearsController`, `SchoolYears`, `SchoolYearRepository`, `SchoolYear`, `SchoolYearRef`, `SchoolYearSpan`, `SchoolYearStatus`). Tests: `tests/SchoolYears/`; die echten Routen hängt `tests/Support/module-probe-routes.php` zusätzlich unter das nicht freigegebene Modul `observe`, um „Modulprüfung vor Rechteprüfung“ zu beweisen.
- Web: Seite „Schuljahre“ (`src/school-years/`, Menüpunkt `#/schuljahre` nur mit `school_years.view`, Formular nur mit `school_years.manage`), Liste mit Status-Badge, Vorschau der Bezeichnung, auf dem Smartphone als Karten.

## Klassen (Ticket #55)

Zweites Stammdaten-Ticket (Spec #17; Glossar: Klasse, Klassenstufe). Neue Rechte in der Gruppe „Stammdaten“: `classes.view` („Klassen ansehen“) und `classes.manage` („Klassen verwalten“). Standardrollen haben sie nicht voreingestellt, Schuladmin über die Wildcard. `classes.view` setzt `school_years.view` nicht voraus, `manage` schließt `view` nicht ein. Die Routen gehören zu `core` und laufen über `ModuleGate::route()`. Das Ticket nennt keine Pfade; gewählt wurden `/api/classes` mit Schuljahr als Query beziehungsweise Body-Feld.

| Endpunkt | Recht | Body / Query | Antwort |
|---|---|---|---|
| `GET /api/classes?school_year_id=<Kennung>` | `classes.view` | Query `school_year_id` (Pflicht) | `200 {"classes": [{id, grade_level, name, school_year: {id, name}}]}`, sortiert nach Klassenstufe, dann natürlich nach Bezeichnung (7a, 7b, …, 10a) |
| `GET /api/classes/school-years` | `classes.view` | – | `200 {"school_years": [{id, name, starts_on, ends_on, status}]}`, nach Beginn aufsteigend; dieselbe Zeile und derselbe Status wie `GET /api/school-years`, aber ohne `school_years.view`, damit die Seite „Klassen“ mit nur `classes.view` ein Schuljahr wählen kann |
| `POST /api/classes` | `classes.manage` | `{school_year_id, grade_level, name}` | `201 {"class": {…}}` (Zeile wie in der Liste) |

- **Klassenstufe** `grade_level`: ganze Zahl (JSON-Zahl, kein Text) von 1 bis 13. **Bezeichnung** `name`: nach dem Trimmen 1 bis 20 Zeichen; gespeichert wird die getrimmte Fassung. Getrimmt wird Unicode-bewusst (Leerzeichen jeder Art wie das geschützte Leerzeichen, Zeilenumbrüche, unsichtbare Zeichen wie Zero-Width-Space und BOM am Rand); eine Bezeichnung nur aus Leerraum oder mit ungültigem UTF-8 ist `400 invalid_request`. Mit ext-intl wird vor Speichern und Vergleich nach NFC normalisiert („7ä“ als ein Zeichen und als „a“ plus Trema ist dieselbe Bezeichnung); ohne ext-intl (in `composer.json` nicht zwingend verlangt) unterbleibt die Normalisierung, Trimmen und Groß-/Kleinschreibung gelten trotzdem. Die Bezeichnung wird nicht gegen die Klassenstufe geprüft („7c“ in Stufe 9 ist erlaubt).
- **Eindeutigkeit:** Die Bezeichnung ist innerhalb des Schuljahres eindeutig, Groß-/Kleinschreibung egal („7a“ = „7A“); „7ä“ ist eine andere Bezeichnung. In einem anderen Schuljahr darf dieselbe Bezeichnung erneut vorkommen (neue Klasse). Anlegen geht in jedem Schuljahr (laufend, kommend, vergangen).
- **Schuljahr:** Jede Klassenantwort trägt Kennung und Bezeichnung des Schuljahres mit. Die Zugehörigkeit ist unveränderlich (Fremdschlüssel auf `school_years`; ein Schuljahr mit Klassen lässt sich nicht löschen).
- **Fehler:** `400 invalid_request` (fehlende oder ungültige Schuljahr-Kennung, Klassenstufe oder Bezeichnung; fehlendes `school_year_id` in der Liste), `404 school_year_not_found` (auch wenn das Schuljahr zwischen Prüfung und Anlegen gelöscht wird: der Fremdschlüssel-Fehler 1452 wird zu diesem Code, nicht zu 500), `409 class_name_taken`, `403 forbidden`, `401`; hinter nicht freigegebenem Modul `403 module_disabled` vor der Rechteprüfung.
- **Vorauswahl im Web** („laufendes Schuljahr, sonst zuletzt vergangenes, sonst das nächste kommende“) leitet sich aus dem `status` der Schuljahre ab (`running`, sonst das letzte `past`, sonst das erste `upcoming`), es gibt keine zweite Regel.
- **Speicherung:** Tabelle `classes` (Migration 0014: `id` UUID, `school_year_id`, `grade_level`, `name`, `name_key` = kleingeschriebene Bezeichnung, binär verglichen; eindeutiger Schlüssel `(school_year_id, name_key)` sichert die Eindeutigkeit auch bei gleichzeitigen Anfragen). Anlegen und Audit laufen in einer Transaktion.
- **Audit:** `class.created` (Objekt `class`, Objekt-ID = Kennung, Kontext nur `name`; Klassenstufe und Schuljahr stehen nicht im Kontext, Audit trägt nur Kennung und Bezeichnung).
- Code: `src/Modules/Classes/` (`ClassRoutes`, `ClassesController`, `Classes`, `ClassRepository`, `ClassInput`); Schuljahre und Status kommen aus `SchoolYears` (neu: `find`), Kennungen aus `Shared\Uuid`. Tests: `tests/Classes/`; die Routen hängt `tests/Support/module-probe-routes.php` zusätzlich unter `observe` (`/api/test/observe-classes`). Der Test-Harness (`HttpTestApp`) setzt jetzt `QUERY_STRING` aus der URI.
- Bearbeiten, Archivieren, Wiederherstellen und Löschen von Klassen: Abschnitt „Klassen verwalten (Ticket #57)“.
- Web: Seite „Klassen“ (`src/classes/`, Menüpunkt nur mit `classes.view`, Formular nur mit `classes.manage`, Schuljahr-Auswahl) und der Abschnitt „Klassen“ in der Detailansicht eines Schuljahres.

## Schuljahr bearbeiten und löschen (Ticket #56)

Dritter Teil der Stammdaten (Spec #17). Kein neues Recht und keine Migration: beide Wege verlangen `school_years.manage` (sonst `403 forbidden`, Modulprüfung zuerst, Routen über `ModuleGate::route()`). Schuljahre werden nie archiviert. Nur GET/POST (CORS), darum `POST …/update` und `POST …/delete` wie bei den Rollen.

| Endpunkt | Recht | Body | Antwort |
|---|---|---|---|
| `POST /api/school-years/{id}/update` | `school_years.manage` | `{starts_on, ends_on}` (wie beim Anlegen) | `200 {"school_year": {id, name, starts_on, ends_on, status}}`; die Bezeichnung folgt dem neuen Zeitraum, die Kennung bleibt |
| `POST /api/school-years/{id}/delete` | `school_years.manage` | – | `200 {"deleted": true, "id": "<Kennung>"}` |

- **Ändern:** dieselben Regeln wie beim Anlegen (Tage im Format `JJJJ-MM-TT`, Ende im Kalenderjahr nach dem Beginn, keine Überschneidung mit *anderen* Schuljahren; der eigene alte Zeitraum zählt nicht). Ein unveränderter Zeitraum ist erlaubt. Ein Schuljahr mit Klassen darf bearbeitet werden.
- **Löschen:** abgelehnt, sobald das Schuljahr Klassen hat, **alle** Klassen des Schuljahres zählen, auch archivierte (Fremdschlüssel `classes.school_year_id` sichert es zusätzlich ab).
- **Fehler:** `400 invalid_request`, `422 invalid_period`, `404 school_year_not_found`, `409 school_year_overlap`, `409 school_year_has_classes` (Löschen mit Klassen), `409 school_year_in_use` (Auffangnetz: der Fremdschlüssel schlug zwischen Prüfung und Löschen zu, z. B. durch eine gleichzeitig angelegte Klasse), `409 <Code eines Moduls>` (Einwand einer angemeldeten Prüfung), `503 school_years_busy`, `403 forbidden`, `401`; hinter nicht freigegebenem Modul `403 module_disabled`.
- **Erweiterbare Prüfung** (Source of Truth: Erweiterbarkeit): Module mit Bezug auf Schuljahre (#18, #19, …) hängen ihren Einwand gegen Ändern und Löschen an, ohne `SchoolYears` zu ändern. Interface `SchoolYearGuard` (`objectionToUpdate(year, newSpan)`, `objectionToDeletion(year)`, je `?SchoolYearObjection{code, message}`) und die Registry `SchoolYearGuards` (siehe „Erweiterungspunkte“ unten); der erste Einwand gewinnt und wird zu `409` mit seinem Code und seiner Meldung (`SchoolYearGuards::assertUpdatable/assertDeletable`, die Ablehnung baut `SchoolYearObjection::exception()`). Die Prüfungen laufen unter der Schuljahr-Sperre in der Transaktion der Änderung. Die Klassen-Sperre ist selbst so angemeldet (`Modules/Classes/ClassesSchoolYearGuard`, Code `school_year_has_classes`); ihr Einwand gegen das Ändern ist leer. Die Test-Prüfung steht in `tests/Support/module-probe-routes.php` und lehnt Aktionen an genau den Schuljahren ab, die der Test in der Tabelle `test_school_year_blocks` einträgt (`HttpTestApp::blockSchoolYear($id, 'update'|'delete')`); ohne Eintrag hat sie nichts einzuwenden. Die Registry selbst ist ohne Datenbank in `tests/SchoolYears/SchoolYearGuardsTest.php` getestet.
- **Audit:** `school_year.updated` (Kontext `old_name`, `name`) und `school_year.deleted` (Kontext `name`), Objekt `school_year`, Objekt-ID = Kennung. Auch ein unveränderter Zeitraum schreibt einen Eintrag.
- Tests: `tests/SchoolYears/SchoolYearsUpdateDeleteTest.php`.
- Web: Schaltflächen „Bearbeiten“ und „Löschen“ (mit Bestätigung) je Zeile der Seite „Schuljahre“, nur mit `school_years.manage`; Ablehnungen werden mit verständlichem Text angezeigt.

## Klassen verwalten (Ticket #57)

Viertes Stammdaten-Ticket (Spec #17; Glossar: Klasse, Archivierung). Alle Aktionen brauchen `classes.manage`, laufen über `ModuleGate::route()` (Modulprüfung vor dem Recht) und sind wegen CORS (nur GET/POST) als `POST /api/classes/:id/<aktion>` gebaut. Jede Klassenantwort trägt jetzt zusätzlich `archived` (bool): `{id, grade_level, name, archived, school_year: {id, name}}`.

| Endpunkt | Recht | Body / Query | Antwort |
|---|---|---|---|
| `POST /api/classes/:id/update` | `classes.manage` | `{grade_level, name}` (optional `school_year_id`, nur zur Kontrolle) | `200 {"class": {…}}` |
| `POST /api/classes/:id/archive` | `classes.manage` | – | `200 {"class": {…, "archived": true}}` |
| `POST /api/classes/:id/restore` | `classes.manage` | – | `200 {"class": {…, "archived": false}}` |
| `POST /api/classes/:id/delete` | `classes.manage` | – | `200 {"deleted": true, "id": "<Kennung>"}` |
| `GET /api/classes?school_year_id=…&include_archived=1` | `classes.view` | Filter `include_archived` (`1` oder `true`) | wie bisher; **ohne den Filter keine archivierten Klassen** |
| `GET /api/classes/selectable[?school_year_id=…]` | `classes.view` | optional Schuljahr | `200 {"classes": [...]}`: nur wählbare (nicht archivierte) Klassen, eines Schuljahres oder aller (dann nach Beginn des Schuljahres, darin nach Klassenstufe und natürlicher Bezeichnung) |

- **Bearbeiten:** Prüfreihenfolge: unbekannte Klasse (404 `class_not_found`), archivierte Klasse (409 `class_archived`), erst dann die Eingabe (400 `invalid_request`, auch abweichendes `school_year_id`); eine archivierte Klasse meldet also immer `class_archived`, auch bei ungültiger Eingabe. Klassenstufe und Bezeichnung nach denselben Regeln wie beim Anlegen (400 `invalid_request`, 409 `class_name_taken`; die eigene Bezeichnung in anderer Schreibung oder nur die Stufe zu ändern ist erlaubt). Das Schuljahr ist nicht änderbar: ein abweichendes `school_year_id` im Body ergibt 400 `invalid_request`. `name_key` wird mitgepflegt, die alte Bezeichnung ist danach wieder frei.
- **Archivieren:** geht mit und ohne Verknüpfungen. Eine archivierte Klasse bleibt lesbar (nur mit Filter in der Liste), ihre Bezeichnung bleibt im Schuljahr belegt (Anlegen und Umbenennen auf diese Bezeichnung: 409 `class_name_taken`), sie ist schreibgeschützt (Bearbeiten: 409 `class_archived`) und nicht wählbar. Erneut archivieren: 409 `class_already_archived`.
- **Wiederherstellen:** macht die Klasse wieder änderbar und wählbar, ohne erneute Eindeutigkeitsprüfung (die Bezeichnung war belegt). Nicht archivierte Klasse: 409 `class_not_archived`.
- **Löschen:** endgültig, nur wenn die Verknüpfungsprüfung nichts findet (auch bei archivierten Klassen); sonst 409 `class_linked` mit Hinweis auf Archivieren in der Meldung, die die Verknüpfung benennt. Danach ist die Bezeichnung wieder frei.
- **Fehler gemeinsam:** 404 `class_not_found`, 403 `forbidden`, 401; hinter nicht freigegebenem Modul 403 `module_disabled` vor der Rechteprüfung.
- **Erweiterbare Verknüpfungsprüfung:** Interface `ClassLinkGuard` (`label()`, `isLinked(classId)`) und die Registry `ClassLinkGuards` (gleiches Muster wie bei den Schuljahren, siehe „Erweiterungspunkte“). Spätere Module (#18, #19) melden ihre Prüfung in ihrer Modul-Registrierung an (`$points->get(ClassLinkGuards::class)->register(...)`); `Classes::delete` fragt über `ClassLinkGuards::assertDeletable` alle angemeldeten Prüfungen ab und bleibt unverändert (409 `class_linked`). Ohne Anmeldung gilt eine Klasse als unverknüpft. Die Tests melden eine Test-Verknüpfung über `tests/Support/module-probe-routes.php` an (Tabelle `test_class_links`, `HttpTestApp::linkClass`).
- **Wählbare Klassen für spätere Zuordnungen:** `GET /api/classes/selectable` beziehungsweise `Classes::selectable(?schoolYearId)`; neue Zuordnungen dürfen nur solche Klassen verwenden. Bestehende Zuordnungen an eine später archivierte Klasse bleiben lesbar.
- **Speicherung:** Migration 0016 ergänzt `classes.archived_at` (NULL = aktiv). Aktionen sperren die Zeile (`SELECT … FOR UPDATE`) und laufen samt Audit in einer Transaktion.
- **Audit:** `class.updated` (Kontext `old_name`, `name`), `class.archived`, `class.restored`, `class.deleted` (Kontext je `name`; Objekt `class`, Objekt-ID = Kennung). Wie bei `school_year.*` nur Kennung und Bezeichnung, nicht Klassenstufe oder Schuljahr.
- Code: `src/Modules/Classes/` (neu: `ClassChange` mit den Regeln für Stufe und Bezeichnung, von `ClassInput` mitgenutzt; `ClassLinkGuard`, `ClassLinkGuards`). Tests: `tests/Classes/ClassLifecycleTest.php`, `tests/Classes/ClassLinkGuardsTest.php`.
- Web: Klassenliste mit Schalter „Archivierte anzeigen“, Bearbeiten, Archivieren, Wiederherstellen, Löschen mit Bestätigung; archivierte Klassen sind markiert.

## Fächer anlegen und auflisten (Ticket #62)

Erstes Ticket der Fächer und Fachgruppen (Spec #18; Glossar: Fach). Ein Fach gilt für die ganze Schulinstanz, unabhängig vom Schuljahr. Neue Rechte in der Gruppe „Stammdaten“: `subjects.view` („Fächer ansehen“) und `subjects.manage` („Fächer verwalten“). Standardrollen haben sie nicht voreingestellt, Schuladmin über die Wildcard; `manage` schließt `view` nicht ein. Die Routen gehören zu `core` und laufen über `ModuleGate::route()` (Modulprüfung vor dem Recht). Pfade sind vom Ticket nicht vorgegeben; gewählt wurde `/api/subjects`.

| Endpunkt | Recht | Body | Antwort |
|---|---|---|---|
| `GET /api/subjects` | `subjects.view` | – | `200 {"subjects": [{id, code, name}]}`, natürlich nach Kürzel sortiert (D1, d2, D10), Groß-/Kleinschreibung egal |
| `GET /api/subjects/selectable` | `subjects.view` | – | wie die Liste: die für neue Zuordnungen wählbaren Fächer (vorerst alle) |
| `POST /api/subjects` | `subjects.manage` | `{code, name}` | `201 {"subject": {id, code, name}}` |

- **Kürzel** `code`: nach Unicode-bewusstem Trimmen und NFC 1 bis 10 Zeichen, ohne Leerraum im Inneren, nur Buchstaben (auch Umlaute), Ziffern, `-` und `_`. Die Schreibung bleibt wie eingegeben („Ev“ bleibt „Ev“). Eindeutig ohne Rücksicht auf Groß-/Kleinschreibung („Ev“ = „EV“), „Ü“ und „U“ sind verschiedene Zeichen. Nach dem Anlegen unveränderlich (es gibt keinen Bearbeiten-Weg).
- **Bezeichnung** `name`: 1 bis 60 Zeichen, getrimmt und NFC-normalisiert wie die Klassenbezeichnung (gemeinsame Regeln in `Shared\Text\Text`, von `ClassChange` und `SubjectInput` genutzt); muss nicht eindeutig sein.
- **Fehler:** `400 invalid_request` (Kürzel oder Bezeichnung fehlt oder ist ungültig, mit verständlicher Meldung), `409 subject_code_taken` (Kürzel schon vergeben), `403 forbidden`, `401`; hinter nicht freigegebenem Modul `403 module_disabled` vor der Rechteprüfung.
- **Speicherung:** Tabelle `subjects` (Migration 0019: `id` UUID, `code`, `code_key` = kleingeschriebenes Kürzel, binär verglichen, `name`; der eindeutige Schlüssel `code_key` sichert die Eindeutigkeit auch bei gleichzeitigen Anfragen, der Dienst übersetzt den Fehler 1062 in `subject_code_taken`). Die Migration ist gefahrlos wiederholbar (`IF NOT EXISTS`) und hängt nicht von ihrer Nummer ab. Anlegen und Audit laufen in einer Transaktion.
- **Audit:** `subject.created` (Objekt `subject`, Objekt-ID = Kennung, Kontext nur `code` und `name`).
- **Ausgabetyp:** Das Modul liefert `Subject` (`id`, `code`, `name`, `toArray()`/`JsonSerializable`) statt eines Array-Shapes; Eingaben prüft `SubjectInput`.
- **Wählbare Fächer für spätere Zuordnungen (Fachgruppen, Tickets #63/#64):** `Subjects::selectable(): list<Subject>` beziehungsweise `GET /api/subjects/selectable`. Vorerst sind es alle Fächer; kommt später das Archivieren, filtert diese eine Stelle. Neue Zuordnungen dürfen nur solche Fächer verwenden.
- Code: `src/Modules/Subjects/` (`SubjectsModule`, `SubjectRoutes`, `SubjectsController`, `Subjects`, `SubjectRepository`, `SubjectInput`, `Subject`). Tests: `tests/Subjects/`; die Routen hängt `tests/Support/module-probe-routes.php` zusätzlich unter `observe` (`/api/test/observe-subjects`).
- Web: Seite „Fächer“ (`src/subjects/`, Menüpunkt `#/faecher` nur mit `subjects.view`, Formular nur mit `subjects.manage`), Fehler werden mit verständlichem Text angezeigt.

## Erweiterungspunkte und Modul-Anmeldung (Review #17)

Ein Muster für alle Erweiterungshaken (Source of Truth 4.7.1: Erweiterbarkeit, Dependency Injection, einheitliche Registries). Aktuell zwei Haken, beide nach demselben Schema benannt (`<Objekt>Guard` = Interface, `<Objekt>Guards` = Registry):

| Haken | Interface | Registry | Gefragt von |
|---|---|---|---|
| Schuljahr ändern/löschen | `SchoolYearGuard` | `SchoolYearGuards` | `SchoolYears::update/delete` |
| Klasse löschen (Verknüpfung) | `ClassLinkGuard` | `ClassLinkGuards` | `Classes::delete` |

- **Instanzbasiert, per Konstruktor injizierbar:** Eine Registry (Basis `Shared\Extension\GuardRegistry`, eigenes typisiertes `register()`) ist eine gewöhnliche Instanz ohne statischen Zustand. Der Dienst bekommt sie im Konstruktor (`new SchoolYears(guards: …)`, `new Classes(linkGuards: …)`, Standard: leere Registry). Gefragt wird in der Reihenfolge der Anmeldung.
- **Eine Anmeldung je Modul:** Jedes Modul hat eine Klasse `<Modul>Module implements Bootstrap\ModuleRegistration` mit `register(ApiRouter, ExtensionPoints)`: dort meldet es seine Routen am Router und seine Prüfungen an den Registries anderer Module an (`$points->get(SchoolYearGuards::class)->register(new ClassesSchoolYearGuard())`). `ExtensionPoints` (`Shared\Extension`) hält je Registry-Klasse genau eine Instanz der Anwendung und legt sie bei Bedarf an; der Kern kennt keine Registry. `AppBootstrap::registerRoutes` führt nur die Liste der Module (`SchoolYearsModule`, `ClassesModule`, `SubjectsModule`); ein neues Modul trägt sich dort mit einer Zeile ein und registriert alles Weitere selbst. Ganz ohne Eintrag im Kern ginge es nur mit automatischer Erkennung; die ist bewusst nicht gebaut.
- **Controller sind Instanzen mit Dienst im Konstruktor** (`SchoolYearsController(SchoolYears)`, `ClassesController(Classes)`), die Routen verweisen auf `[$controller, 'methode']`. Das Modul baut den Controller mit den Registries der Anwendung (`<Modul>Module::controller($points)`, auch von den Probe-Routen der Tests genutzt).
- **Tests ohne Tarnung:** Registries werden ohne Datenbank direkt getestet (`SchoolYearGuardsTest`, `ClassLinkGuardsTest`: Reihenfolge, erster Einwand gewinnt, eigene Instanz je Test). Für den HTTP-Weg (php-cgi läuft im eigenen Prozess) meldet `tests/Support/module-probe-routes.php` (die Datei bekommt Router und `ExtensionPoints` der Anwendung) Test-Prüfungen an, deren Verhalten der Test über Tabellen steuert (`test_school_year_blocks`, `test_class_links`).
- **Gemeinsame Helfer:** Transaktion und Namenssperre: `Shared\Database\Transactions::run/underLock` (von Schuljahren und Klassen genutzt; ältere Stellen wie `AccountInvitations`, `ModuleEntitlements`, `AccessAdministration` haben noch eigene Varianten und wurden nicht angefasst). MySQL-Fehlernummern: `Shared\Database\MysqlError` (1062, 1451, 1452).

## Typen und Namen für Schuljahr und Klasse (Ticket #69)

Review-Reste aus #17, reines Refactoring: JSON-Antworten und Fehlercodes bleiben unverändert.

- **Eigene Typen für Ausgaben statt Array-Shapes:** `SchoolYear` (Kennung, Bezeichnung, Beginn, Ende, `SchoolYearStatus`) und `SchoolYearRef` (nur Kennung und Bezeichnung, aus `SchoolYear::ref()`) in `src/Modules/SchoolYears/`; `SchoolClass` (Kennung, Klassenstufe, Bezeichnung, archiviert, `SchoolYearRef`) in `src/Modules/Classes/` (`Class` ist in PHP ein reserviertes Wort). `SchoolYears` und `Classes` liefern diese Typen; erst die Controller wandeln sie mit `toArray()` in die unveränderte JSON-Form (`{id, name, starts_on, ends_on, status}` bzw. `{id, grade_level, name, archived, school_year: {id, name}}`). Die Zeilen der Repositories bleiben Speicherform und damit Arrays.
- **`SchoolYearGuard` bekommt ein `SchoolYear`:** `objectionToUpdate(SchoolYear $year, SchoolYearSpan $newSpan)` und `objectionToDeletion(SchoolYear $year)` (vorher `array $year`); wer einen Einwand anmeldet, liest `$year->id` statt `$year['id']`.
- **Umbenennung:** `SchoolYearPeriod` heißt `SchoolYearSpan` (Glossar: Schuljahr, _Avoid_ „Periode“); `$period` ist `$span`, `$newPeriod` ist `$newSpan`. Der Fehlercode `invalid_period` bleibt, er ist Teil der API.
- **Web:** `PeriodForm` heißt `SchoolYearForm`; `SchoolYearsPage` hält nur Zustand und Schreibzugriffe und setzt `SchoolYearList` (Tabelle), `SchoolYearDetail` (Detailansicht mit den Klassen) und `SchoolYearForm` (Anlegen/Bearbeiten) zusammen, alle in `src/school-years/`.
- `ClassChange` bleibt unverändert (Umbenennung gehört zu #64).
- Tests: `tests/SchoolYears/SchoolYearTest.php`, `tests/Classes/SchoolClassTest.php` (JSON-Form), `tests/SchoolYears/SchoolYearSpanTest.php` (vorher `SchoolYearPeriodTest`); die Endpunkt-Tests sichern die unveränderten Antworten.

## MFA-Pflicht für Mitarbeitende (Ticket #15)

ADR 0002 B3 und G2. Ein Konto mit `accounts.mfa_required` (ODER über das Merkmal „Erfordert MFA“ seiner Rollen, siehe Ticket #33/#34) erhält mit einem Token `usedMFA=false` auf **jeder** fachlichen Route `403 {"code": "mfa_enrollment_required"}`, vor der Rechteprüfung, nach den 401-Prüfungen (`account_inactive`, `session_revoked`). Die Pflicht hängt am Konto, nicht an Route oder Portal: ein Konto mit Lehrkraft- und Elternrolle braucht MFA auch für Elternfunktionen.

- Durchsetzung in `AuthenticationMiddleware::handle` (Standard für alle Routen). Nur `GET /api/me` nutzt `handleAllowingMfaEnrollment` und liefert zusätzlich `session.mfa_enrollment_required` (`mfa_required && !used_mfa`). Die Einrichtung selbst läuft beim Anmeldedienst (`POST /mfa/setup`, `POST /mfa/setup/confirm`); die API muss dafür nichts freigeben.
- Spiegeln (G2): `MfaRequirement::recomputeFor` setzt `accounts.mfa_sync_pending` (Migration 0017), wenn sich der Wert ändert. Nach dem Commit meldet `MfaRequirementSync::pushPending()` **alle** offenen Konten per `POST /internal/users/mfa-requirement` (`{"event":"user.mfa_requirement","public_user_ids":[…],"required":bool}`, je Wert, höchstens 200 IDs) und leert die Markierung nur, wo der gemeldete Wert noch gilt. Aufgerufen von `AccessAdministration` (wenn es die Transaktion selbst öffnet) und `AccountInvitations` (erster Schuladmin, ersetzte Einladungen).
- Ein Fehler beim Spiegeln (Ausfall, Zeitüberschreitung, 5xx, unlesbare Antwort, fachliche Ablehnung) ändert lokal nichts: Rollenänderung und Durchsetzung gelten, die Markierung bleibt, und der nächste Abgleich (nach jeder weiteren Aktion der Rechteverwaltung) meldet das Konto erneut. Ein Wiederholen ohne Anlass ist Ticket #39 (Outbox). Die Migration markiert beim Anlegen der Spalte alle bestehenden Pflichtkonten als offen.
- Wegfall der letzten Pflichtrolle meldet `required: false`; ein eingerichtetes TOTP bleibt aktiv (die API ruft kein `mfa-reset`).
- Web: `App` zeigt bei `session.mfa_enrollment_required` oder einer `403 mfa_enrollment_required` mitten in der Sitzung statt der Portale die Seite „Zweiten Faktor einrichten“ (`src/auth/MfaEnrollmentPage.tsx`): QR-Code und Schlüssel, Code bestätigen, Wiederherstellungscodes, danach Abmelden mit Hinweis und neue Anmeldung mit TOTP-Challenge. Ist TOTP schon eingerichtet (`409 mfa_already_enabled`), nur neu anmelden.
- Tests: `tests/Security/MfaEnforcementTest.php` (HTTP gegen MariaDB und den Auth-Stub, `AuthStub::mfaRequirementCalls()`, Skript-Kind `mfa-requirement`), E2E in `tests/e2e/first-admin-invite.sh` (Schuladmin richtet TOTP ein, meldet sich mit Code an). Testaufbau mit `HttpTestApp::assignRole` berechnet `mfa_required` nicht neu; Konten aus dem Testaufbau sind deshalb ohne Pflicht.

## Weiterführend

- [Architektur](architecture.md) · [Roadmap](roadmap.md) · [Entscheidungen](decisions/) · [Glossar](../GLOSSARY.md)
