# ADR 0002 – Spezifikation der Auth- und Rechte-Lücken

**Status:** Angenommen (2026-11-17)
**Bezug:** ADR 0001, Source of Truth 17.9, 17.12, 17.14 Nr. 1/2/8
**Geltung:** Teil A ändert das Repository `jwt-authservice`. Teil B wird im TeacherOS-Backend (`Shared/Security`) gebaut.

## Grundsatz: eine Auth-Instanz pro Schule (F1 entschieden)

Jede Schulinstanz erhält eine eigene `jwt-authservice`-Instanz mit:

- eigener Datenbank,
- eigenem RS256-Schlüsselpaar,
- eigenem `TOKEN_ISSUER` (`https://auth.<schul-subdomain>`) und `TOKEN_AUDIENCE` (`teacheros:<schul-slug>`),
- `REGISTRATION_DISABLED=true`, weil Konten nur über Einladung entstehen (G5).

Das Backend prüft `iss`, `aud` und die Signatur mit dem öffentlichen Schlüssel **seiner** Schule. Ein Token von Schule A scheitert an Schule B also schon an der Signatur, nicht erst an `aud`. Das ist die doppelte Absicherung für 17.14 Nr. 1. Dieselbe E-Mail-Adresse an zwei Schulen ergibt zwei unabhängige Konten mit je eigenem TOTP.

Die Provisionierung (Schlüssel erzeugen, Instanz anlegen, Migration ausführen) übernimmt später die Control Plane. Bis dahin genügt ein Skript in `scripts/`.

## Gemeinsame Regeln für alle neuen internen Endpunkte (Teil A)

- Gleiches Signaturverfahren wie `/internal/users/delete`: `X-Internal-Timestamp`, `X-Internal-Nonce` und HMAC-Header mit `AUTH_SERVER_WEBHOOK_SECRET`, dazu Replay-Schutz.
- Der Body enthält immer `event`. Ein falsches `event` führt zu 400.
- `public_user_id` muss eine UUID sein. Ein unbekanntes Konto führt zu 404 `{"error", "code": "user_not_found"}`. Ausnahme ist G5.
- Alle Endpunkte sind **idempotent**. Ein wiederholter Aufruf ändert den Zustand nicht weiter und liefert 200.
- **Wer** eine Aktion auslösen darf, prüft und protokolliert ausschließlich das TeacherOS-Backend (Permission und Audit). Der Auth-Dienst vertraut der Signatur, prüft aber keine Fachrollen.
- Jede Aktion steht im CHANGELOG, wird als SemVer-Minor-Version veröffentlicht und braucht PHPUnit-Tests nach dem bestehenden Muster in `tests/`.
- Bestehende Aufrufer (EAW-Shop) verhalten sich unverändert. Neue Felder sind optional und haben abwärtskompatible Defaults.

---

## Teil A – Erweiterungen in `jwt-authservice`

### G1 – TOTP durch die Verwaltung zurücksetzen

**Anlass:** Eine Lehrkraft hat ihr Gerät und ihre Recovery-Codes verloren (17.9: „verwaltetes TOTP-Reset“).

`POST /internal/users/mfa-reset`
```json
{ "event": "user.mfa_reset", "public_user_id": "<uuid>" }
```
**Wirkung (in einer Transaktion):**
1. `mfa_secret`, `mfa_enabled_at` und `mfa_last_used_slot` werden auf `NULL` gesetzt, `mfa_failed_attempts` auf `0`, `mfa_locked_until` auf `NULL`.
2. Alle offenen Recovery-Codes gelten als verbraucht (`used_at = NOW()`).
3. Alle vertrauenswürdigen Geräte werden widerrufen.
4. Alle Refresh-Tokens werden widerrufen (`revokeAllRefreshTokens`).
5. Eine Sicherheitsmail geht über die Queue an das Konto. Dafür wird der bestehende Vorlagenmechanismus `safeMfaMail` mit dem Ereignis `reset_by_admin` erweitert.

**Antwort:** `200 {"ok": true, "public_user_id": "...", "mfa_was_enabled": bool}`

**Folge:** Ist für das Konto `mfa_required` gesetzt (G2), muss es bei der nächsten Anmeldung TOTP neu einrichten.

**Tests:**
- Nach dem Reset ist der Login ohne TOTP-Challenge möglich.
- Alte Recovery-Codes funktionieren nicht mehr.
- Eine alte Refresh-Session liefert 401.
- Ein zweiter Aufruf liefert 200 mit `mfa_was_enabled=false`.

### G2 – MFA-Pflicht pro Konto absichern

**Anlass:** TOTP ist für Mitarbeitende und die Administration Pflicht, für Eltern und SuS optional (17.9).

**Aufteilung:**
- Das TeacherOS-Backend **erzwingt** die Pflicht (siehe B3).
- Der Auth-Dienst verhindert nur, dass ein Pflichtkonto seinen Schutz selbst wieder abbaut.

**Migration `0004_mfa_required.php`:** `users.mfa_required_at DATETIME NULL`.

`POST /internal/users/mfa-requirement`
```json
{ "event": "user.mfa_requirement", "public_user_ids": ["<uuid>", "..."], "required": true }
```
Höchstens 200 IDs pro Aufruf. `required: false` setzt `mfa_required_at = NULL`.

**Verhalten bei `mfa_required_at IS NOT NULL`:**
- `DELETE /mfa` antwortet mit `403 {"code": "mfa_required"}`. TOTP lässt sich nur noch über G1 entfernen.
- Vertrauenswürdige Geräte überspringen die TOTP-Challenge **nicht**. Die neue Konfiguration `MFA_TRUSTED_DEVICE_FOR_REQUIRED` steht standardmäßig auf `false`. In der Login-Logik wird `trustedDevices->consume()` für solche Konten übersprungen.
- `GET /mfa` liefert zusätzlich `"required": true`, damit das Frontend die Einrichtung anstoßen kann.

**Tests:**
- Ein Pflichtkonto kann MFA nicht selbst deaktivieren.
- Ein vertrauenswürdiges Gerät führt bei einem Pflichtkonto trotzdem zur Challenge.
- Ein Konto ohne Pflicht verhält sich wie bisher (Regressionstest für EAW).

### G3 – Sitzungen gezielt widerrufen, ohne den Login zu sperren

**Anlass:** „Dauerhafter Session-Widerruf“ (17.9), etwa bei einem verlorenen Gerät oder einer Rechteänderung, nach der sich jemand neu anmelden soll. Bisher gibt es das nur zusammen mit einer Login-Sperre oder einem Passwort-Reset.

`POST /internal/users/revoke-sessions`
```json
{ "event": "user.revoke_sessions", "public_user_ids": ["<uuid>"], "include_trusted_devices": true }
```
**Wirkung:**
- Alle Refresh-Tokens werden widerrufen.
- Bei `include_trusted_devices` (Default `true`) werden auch die vertrauenswürdigen Geräte widerrufen.
- Login, Passwort und MFA bleiben unverändert.

**Antwort:** `200 {"ok": true, "affected": int}`

**Grenze:** Bereits ausgestellte Access-Tokens bleiben bis zu ihrem `exp` gültig (höchstens `TOKEN_REPLAYTIME`, 900 s). Das Backend schließt dieses Fenster sofort über B2.

**Test:** Nach dem Widerruf liefert `/refresh` 401, ein neuer Login gelingt.

### G4 – Login sperren mit Grund und wieder freigeben

**Anlass:** Deaktivierung und Reaktivierung, etwa bei Austritt und Rückkehr (CORE-08, 17.9). Bisher speichert `disable-login` fest den Grund `self_deleted`, und eine Reaktivierung fehlt.

**G4a – Grund übergeben:** `POST /internal/users/disable-login` erhält das optionale Feld `reason`. Erlaubt sind `self_deleted` (bleibt Default, damit EAW unverändert funktioniert), `left_school`, `admin_locked` und `security_lock`. Andere Werte führen zu 400.

**G4b – Reaktivierung:** `POST /internal/users/enable-login`
```json
{ "event": "user.enable_login", "public_user_id": "<uuid>" }
```
**Wirkung:**
- `is_active = 1`, `disabled_at`, `disabled_reason` und `disabled_by` werden auf `NULL` gesetzt.
- Es werden keine Sitzungen wiederhergestellt, und MFA bleibt unverändert.
- Bei `confirmed_email = 0` antwortet der Dienst mit `409 {"code": "email_unconfirmed"}`.
- Bei `disabled_reason = self_deleted` antwortet er mit `409 {"code": "self_deleted"}`, weil eine Selbstlöschung nicht durch die Verwaltung rückgängig gemacht werden darf.

**Tests:**
- Sperren und Freigeben im Wechsel, danach ist der Login wieder möglich.
- Bei einer Selbstlöschung lehnt `enable-login` ab.
- `disable-login` ohne `reason` speichert weiterhin `self_deleted`.

### G5 – Konten per Einladung anlegen

**Anlass:** „Initialer Admin durch Einladung an definierte E-Mail“ (17.9). Auch Lehrkräfte und Eltern entstehen so, weil die Registrierung pro Schule abgeschaltet ist. Bisher gibt es nur `bin/create-user.php`, das ein Klartext-Passwort verlangt.

`POST /internal/users/invite`
```json
{ "event": "user.invite", "email": "...", "username": "optional" }
```
**Wirkung bei neuer Adresse:**
- Es entstehen `credentials` und `users` mit einem nicht benutzbaren Zufalls-Passworthash, `confirmed_email = 0` und `is_active = 0`.
- Ein Einladungstoken wird gehasht gespeichert. Der Mechanismus des erzwungenen Resets wird wiederverwendet, das Token ist `INVITE_TOKEN_DURATION_HRS` gültig (Default 72).
- Die Mail `auth_invite` geht über die Queue, mit einem Link auf `<FRONTEND>/einladung?token=...`.

**Wirkung bei bestehender, noch nicht angenommener Einladung:** Es wird ein neues Token erzeugt, das alte ist ungültig, und die Mail wird erneut versendet (erneutes Einladen).

**Wirkung bei bestehendem aktivem Konto:** Es wird keine Mail versendet. Die Antwort enthält `created: false, already_active: true`.

**Antwort:** `200 {"ok": true, "public_user_id": "...", "created": bool, "already_active": bool}`. Eine Kontoenumeration ist hier kein Risiko, weil der Endpunkt nur intern und signiert erreichbar ist.

**Annahme der Einladung:** Der neue Endpunkt `POST /acceptInvite` erwartet `{token, password}` mit Rate-Limit-Regel `reset_password`. Er prüft das Passwort mit der bestehenden `validatePassword`-Regel und setzt dann in einer Transaktion `confirmed_email = 1`, `is_active = 1` und den Passworthash. Das Token wird verbraucht. Die Antwort enthält kein Access-Token, die Person meldet sich danach normal an und richtet bei einer Pflicht TOTP ein.

**Zuschaltbar pro Instanz (Nachtrag, Ticket #23):** Die Einladung ist nur aktiv, wenn die Auth-Instanz `INVITE_ENABLED=true` hat (Standard `false`, damit andere Verbraucher des gemeinsamen Dienstes wie EAW sie nicht ungewollt bekommen). Ohne den Schalter sind `POST /internal/users/invite` und `POST /acceptInvite` 404 und `auth_invite` fehlt im Testmail-Katalog. Die lokale und jede TeacherOS-Instanz setzt ihn (`scripts/auth-instance.sh`). Eine Instanz ohne Weg zur Kontoanlage (`REGISTRATION_DISABLED=true`, Einladung aus) beantwortet jede Anfrage mit 500 und einem Konfigurationsfehler im Log (fail-closed), außer sie ist mit `ACCOUNT_CREATION_CLI_ONLY=true` ausdrücklich als reine CLI-Instanz gesetzt; Konten entstehen dann nur über `bin/create-user.php`.

**Tests:**
- Einladen, Annehmen und Login funktionieren durchgängig.
- Ein abgelaufenes oder schon verwendetes Token führt zu 400.
- Erneutes Einladen macht das alte Token ungültig.
- Ein aktives Konto erhält keine Mail.

### G6 – Anmeldename und Einladung ohne E-Mail (Nachtrag, Ticket #10)

**Anlass:** Schüler/innen haben oft keine E-Mail-Adresse und melden sich mit einem Anmeldenamen an (GLOSSARY „Anmeldename“). Bisher meldet `/login` nur per E-Mail an, `credentials.email` ist Pflicht und `users.username` ist ein nicht eindeutiger Anzeigename.

**Entscheidung:** Der vorhandene `users.username` **ist** der Anmeldename. Wir führen keine eigene Spalte ein, weil Anzeige- und Anmeldename gleich sein sollen und `username` heute nur beim Anlegen gesetzt wird (Standard: die E-Mail).
- **Schalter pro Instanz:** `USERNAME_LOGIN_ENABLED` (Standard `false`). EAW bleibt bei der Anmeldung per E-Mail, TeacherOS-Instanzen setzen ihn (`scripts/auth-instance.sh`).
- **Eindeutigkeit:** Migration 0005 legt einen UNIQUE-Index auf `users.username` an (ohne Groß-/Kleinschreibung über die `_ci`-Collation). Bei vorhandenen Duplikaten lässt sie den Index aus und warnt. Bei eingeschaltetem Schalter ohne Index antwortet die Instanz mit 500 (fail-closed).
- **Regeln:** Ein frei vergebener Anmeldename hat 3–64 Zeichen aus `a-z 0-9 . _ -` und kein `@`. Ein `@` ist nur erlaubt, wenn der Name gleich der eigenen E-Mail ist.
- **Login:** `POST /login` liest die Kennung aus `login` oder, wie bisher, aus `email`. Mit `@` wird per E-Mail gesucht, sonst per `username` (nur bei eingeschaltetem Schalter). Antwort und Laufzeit bei unbekannter Kennung sind unverändert.
- **E-Mail optional:** `credentials.email` wird nullbar. Mail-Wege (Passwort-Reset, Benachrichtigungen) behandeln ein Konto ohne Mail wie eine unbekannte Adresse. `changeEmail` darf eine Mail ergänzen. Die Registrierung verlangt weiter eine Mail.
- **Einladung:** `{"event":"user.invite","email":"optional","username":"optional"}`, mindestens eins von beiden. Ohne `username` ist er die E-Mail. Ist der Anmeldename vergeben, kommt `409 {"code":"username_taken"}`. Ohne E-Mail wird keine Mail versendet, und die Antwort enthält einmalig `invite_code` (Crockford-Base32, etwa 60 Bit, gruppiert). Gespeichert wird nur der Hash, die Gültigkeit entspricht `INVITE_TOKEN_DURATION_HRS`, und erneutes Einladen macht den alten Code ungültig.
- **Annahme:** `POST /acceptInvite` nimmt `{token | code, password}` an, mit derselben Rate-Limit-Regel wie bisher.

**Ergänzungen aus der Umsetzung (Ticket #27):** Der Anmeldename wird getrimmt und kleingeschrieben gespeichert, und die Einladungsantwort enthält ihn als `username`. Ein ungültiger Name ergibt `400 {"code":"invalid_username"}`. Eine Einladung ohne E-Mail bei ausgeschaltetem Schalter ergibt `400 {"code":"username_login_disabled"}`, weil sich ein solches Konto nie anmelden könnte. Ein eigenes `email_taken` kennt der Anmeldedienst nicht: Eine bekannte E-Mail führt wie in G5 zu einer erneuten Einladung, die Eindeutigkeit prüft die API lokal. `credentials.email` wird in einer eigenen Migration 0006 nullbar. `/updatePassword` verlangt weiter eine E-Mail.

**Ergänzungen aus der Umsetzung (API, Web und Anmeldedienst, Tickets #26–#28):**
- **Normalisierung:** Die API schreibt E-Mail und Anmeldename vor jedem Aufruf des Anmeldedienstes klein (Web-Endpunkt und CLI gleich), geprüft an einer Stelle.
- **Erneute Einladung statt Konflikt:** Wird die E-Mail eines noch nicht angenommenen (`eingeladen`) Kontos erneut eingeladen, ist das eine erneute Einladung desselben Kontos (`201`, kein zweites Konto, gefahrlos bei Doppelklick). `email_taken` gibt es nur bei einem Konflikt mit einem anderen Konto, nämlich wenn die E-Mail einem Konto mit abweichendem Anmeldenamen gehört; `username_taken`, wenn der Anmeldename einem anderen Konto gehört.
- **Fehler des Anmeldedienstes:** Nur Netzwerkfehler, Zeitüberschreitung, 5xx und unlesbare Antworten sind `502 auth_unavailable`. Fachliche 4xx des Dienstes mit `code` (`username_taken` 409, `invalid_username` 400, `username_login_disabled` 400, `account_disabled` 409) gibt die API mit demselben Status und Code weiter; ein 400 ohne Code wird `400 invalid_request`. Die CLI beendet sich bei fachlichen Fehlern mit Exit 3, bei `auth_unavailable` mit 4.
- **`/acceptInvite`:** Token bzw. Code werden vor dem Passwort geprüft. 400 trägt zusätzlich `code`: `invalid_invite` (unbrauchbar, abgelaufen, benutzt) oder `weak_password` (die Einladung bleibt dann gültig); die Meldungstexte sind unverändert.
- **Ablaufdatum:** Die Einladungsantwort des Anmeldedienstes enthält bei neuer oder erneuerter Einladung `expires_at` (ISO 8601, UTC). Die API reicht es als `invite_expires_at` durch, ohne es zu speichern; die Einladungskarte zeigt „gültig bis“, die CLI gibt es mit dem Code aus.
- **Audit:** Ersetzt die CLI eine nie angenommene Einladung, bekommt das gelöschte Konto einen eigenen Eintrag `account.invitation_withdrawn` (Objekt = altes Konto, ohne E-Mail).

**Bewusst nicht Teil von G6:** Sammel-Einladung für ganze Klassen und Passwort-Reset durch die Schule ohne E-Mail. Für beide gibt es eigene Issues.

### Nicht Teil der Erweiterung (bewusst)

- **Notfall-Recovery-Zugang für Administratoren** (17.9): Er gehört zur Control Plane und zu B4, nicht in den Auth-Dienst. Technisch ist er eine G5-Einladung an eine dokumentierte Person, ausgelöst durch die Zentrale mit Begründung und Audit.
- **Passkeys und SSO:** SPÄTER (17.9).

---

## Teil B – Durchsetzung im TeacherOS-Backend

Alles liegt in `src/Shared/Security`. Die Pflichttests laufen gegen eine echte MariaDB.

### B1 – `isInactive` bei jedem geschützten Request

Die lokale Tabelle `accounts` enthält `public_user_id` (eindeutig), `status` (`invited`, `active` oder `inactive`), `inactive_since`, `sessions_valid_after` und `mfa_required`. Eine `AuthenticationMiddleware` arbeitet in dieser Reihenfolge:

1. Sie prüft das JWT lokal: RS256, `typ=access`, `iss`, `aud` und `exp`.
2. Sie lädt `accounts` über `sub`. Fehlt der Eintrag, folgt 401.
3. Bei `status != active` folgt `401 {"code": "account_inactive"}`, auch wenn das Token noch gültig ist.

Es gibt **keinen** Cache über den Request hinaus (17.9).

**Pflichttest:** Ein Konto mit gültigem Token wird deaktiviert, der nächste Request liefert 401.

### B2 – Sofortige Wirkung von Sitzungswiderruf und Rechteänderungen

Tokens mit `iat <= accounts.sessions_valid_after` werden mit `401 {"code": "session_revoked"}` abgelehnt (`iat` und Grenze haben Sekundenauflösung: ein Token aus der Sekunde der Deaktivierung kann vor ihr entstanden sein, Gleichheit gilt deshalb als widerrufen; ein Token aus der Sekunde danach ist gültig). Das Backend setzt das Feld bei:

- Widerruf (zusammen mit G3),
- Deaktivierung (zusammen mit G4a),
- TOTP-Reset (zusammen mit G1).

Normale Rechte- und Elternrechteänderungen brauchen das **nicht**, weil Rechte bei jedem Request frisch aus der Datenbank kommen (17.9: „sofort bei neuen API-Anfragen“).

**Reihenfolge bei kombinierten Aktionen:** Zuerst wird lokal `sessions_valid_after` gesetzt, danach der Auth-Dienst aufgerufen. Schlägt der Aufruf fehl, bleibt der lokale Schutz bestehen. Ein Outbox-Job wiederholt den Aufruf idempotent.

**Pflichttest:** Nach dem Widerruf liefert das alte Token sofort 401.

**Nachtrag (Klärung zu Ticket #12, 2026-11-18) – Deaktivierung und Reaktivierung:**
- **Wirkung:** Die Deaktivierung (Glossar) sperrt nur `aktive` Konten. Wer ein nur eingeladenes Konto deaktiviert, erhält 409 `account_not_active`. Rollen und Einzelrechte bleiben erhalten, die Reaktivierung stellt sie unverändert wieder her. Das Widerrufen fachlicher Zuordnungen (CORE-08) gehört zu B6, eine geplante Deaktivierung zum Stichtag zum Schuljahreswechsel. Beides ist nicht Teil von #12.
- **Recht und Regeln:** Neues Recht `accounts.deactivate` für Deaktivieren und Reaktivieren. Analog zu P2 deaktiviert oder reaktiviert man nur Konten, deren effektive Rechte man alle selbst besitzt (403 `permission_not_held`), sodass nur Schuladmins Schuladmins deaktivieren. Ein Wildcard-Konto ist ausgenommen. Sich selbst deaktivieren ergibt 409 `self_deactivation`. P3 gilt über `ensureAdminRemains` (409 `last_admin`).
- **Grund:** fest `admin_locked` („Von der Verwaltung gesperrt“), ohne Freitext. Er wird lokal gespeichert, an G4a gespiegelt und im Audit-Eintrag `account.deactivated` festgehalten. Bei der Reaktivierung wird er entfernt.
- **Deaktivieren:** Zuerst lokal (Status, Grund, `inactive_since`, `sessions_valid_after`), danach `disable-login`. Scheitert der Aufruf, bleibt die lokale Sperre bestehen, und das Konto trägt die Markierung „Abgleich offen“. Lehnt der Anmeldedienst fachlich ab (4xx mit Code, z. B. `user_not_found`), bleibt die Markierung ebenfalls bestehen und der Code wird am Konto festgehalten (`login_sync_error`, in der Lesesicht), damit die Oberfläche den Grund nennen kann; Nichterreichbarkeit setzt keinen Code. Ein Verwalter mit `accounts.deactivate` holt den Abgleich über „Abgleich wiederholen“ nach (fachliche Ablehnungen gibt die API dann mit Status und Code weiter und aktualisiert den gespeicherten Code). Erfolg und Reaktivierung leeren den Code. Die automatische Outbox ist zurückgestellt (#39).
- **Reaktivieren:** umgekehrte Reihenfolge. Zuerst `enable-login` synchron, erst bei Erfolg lokal `aktiv`, eine offene Markierung entfällt. Fachliche Ablehnungen des Dienstes (`self_deleted`, `email_unconfirmed`) gibt die API als 409 weiter, Nichterreichbarkeit als 502 `auth_unavailable`. Ein halber Zustand (Dienst freigegeben, lokal noch deaktiviert, etwa wenn das lokale Schreiben nach `enable-login` scheitert) gibt nie mehr Zugang als vorher, weil das Konto lokal gesperrt bleibt; eine Wiederholung der Reaktivierung heilt ihn, weil `enable-login` idempotent ist. `sessions_valid_after` bleibt stehen, alte Tokens bleiben ungültig.
- **Sichtbarer Grund:** 401 `account_inactive` enthält `reason`. Der Anmeldedienst nennt beim Login eines gesperrten Kontos nach korrektem Passwort `code: "account_disabled"` und `reason`, zusätzlich zur unveränderten Meldung (Erweiterung von G4, abwärtskompatibel für EAW). Das Web zeigt bei `admin_locked` „Ihr Konto wurde von der Verwaltung gesperrt. Bitte wenden Sie sich an Ihre Schule.“, sonst „Ihr Konto ist gesperrt.“
- **Rechte der Lesesicht (Review #12):** Das Kontodetail (`GET /api/accounts/{id}/access`) verlangt `accounts.view` und eines von `roles.manage`, `roles.assign`, `permissions.assign`, `accounts.deactivate`. Deaktivieren, Reaktivieren und „Abgleich wiederholen“ verlangen zusätzlich zu `accounts.deactivate` auch `accounts.view`, weil ihre Antworten die Lesesicht des Kontos sind. Rollen und Rechte (`roles`, `permissions`, `role_actions`, `permission_actions`) enthält die Lesesicht nur, wenn der Akteur eines der drei Verwaltungsrechte hat; sonst nur `account` (mit „Abgleich offen“, Grund und Zeitpunkt der Deaktivierung) und `account_actions`.
- **Audit:** `account.deactivated` (`{reason}`) und `account.reactivated`. Das Nachholen des Abgleichs erzeugt keinen Audit-Eintrag.

**Nachtrag (Klärung zu Ticket #16, 2026-11-19) – Sitzungswiderruf und TOTP-Zurücksetzung:**
- **Rechte:** Zwei neue Rechte, `accounts.revoke_sessions` (Sitzungswiderruf durch die Verwaltung) und `accounts.reset_mfa` (TOTP-Zurücksetzung), getrennt, weil die Zurücksetzung den Schutz eines Kontos schwächt. Beide verlangen zusätzlich `accounts.view` und gelten analog zu P2 nur für Konten, deren effektive Rechte man alle selbst besitzt (403 `permission_not_held`).
- **Zustände:** Der Widerruf gilt nur für `aktive` Konten, die Zurücksetzung für `aktive` und `deaktivierte` (Bereinigung vor einer Reaktivierung); sonst 409 `account_not_active`. Die Zurücksetzung ist auch ohne eingerichtetes TOTP erlaubt (G1 ist idempotent); ob TOTP eingerichtet ist, zeigt das Kontodetail nicht.
- **Eigenes Konto:** Keine eigene TOTP-Zurücksetzung über die Verwaltung (409 `self_mfa_reset`). Den eigenen Widerruf gibt es nur auf „Mein Konto“, ohne besonderes Recht; der Verwaltungsweg auf sich selbst ergibt 409 `self_revocation`. Er beendet alle Sitzungen, auch die aktuelle (B2 kann sie nicht verschonen); das Web meldet danach ab. Das Backend versucht G3 dabei sofort bis zu dreimal und antwortet danach 502 `auth_unavailable`.
- **Ablauf:** Zuerst lokal `sessions_valid_after`, dann G3 (`include_trusted_devices: true`) bzw. G1. Die Zurücksetzung schließt den Widerruf ein.
- **Offene Aufrufe:** „Abgleich offen“ ist eine Menge offener Aufrufe je Konto (Sperre, Sitzungswiderruf, TOTP-Zurücksetzung), jeder mit eigenem Ablehnungscode. „Abgleich wiederholen“ holt alle in dieser Reihenfolge nach und verlangt `accounts.view` und eines der drei Konto-Rechte. Die automatische Outbox bleibt #39; das Outbox-Kriterium aus #16 ist dorthin verschoben.
- **Umsetzung der Menge (Ticket #47):** Tabelle `account_open_calls` (eine Zeile je Konto und Aufruf, `rejection_code`), jeder Aufruf ein Handler in fester Reihenfolge (`OpenCalls::standardHandlers()`, heute nur `disable_login`); Widerruf und Zurücksetzung ergänzen ihren Handler ohne Schemaänderung. „Abgleich wiederholen“ lässt eine Ablehnung die folgenden Aufrufe nicht hindern und endet bei Nichterreichbarkeit; die Lesesicht nennt die Menge als `account.open_calls`, die Felder `account.login_sync_pending`/`login_sync_error` bleiben für die Sperre.
- **Lücke bei offenem Aufruf:** B2 sperrt nur Tokens bis zur Grenze; solange G3 oder G1 fehlt, leben die Refresh-Tokens beim Anmeldedienst und könnten neue Tokens erzeugen. Deshalb lehnt das Backend, solange für ein Konto ein Widerruf oder eine Zurücksetzung offen ist, **jedes** Token dieses Kontos mit 401 `session_revoked` ab. Der erste Request mit einem neuen Token versucht, die offenen Aufrufe nachzuholen; gelingt es, ist das Konto sofort nutzbar.
- **Umsetzung des Widerrufs durch die Verwaltung (Ticket #48):** `POST /api/accounts/{id}/revoke-sessions` mit `accounts.revoke_sessions` und `accounts.view`; Regeln und Reihenfolge wie oben, 409 `self_revocation` und 409 `account_not_active`. Der Handler `revoke_sessions` steht in `OpenCalls::standardHandlers()` hinter der Sperre. Die Sperre aller Tokens bei offenem Aufruf gilt für jeden Handler, der `TokenBlockingCall` trägt (auch #49); ein Token vor der Sitzungsgrenze wird ohne Nachholversuch abgelehnt, ein Token danach löst ihn aus. Der Baustein `SessionRevocation::revoke` nimmt eine Versuchszahl entgegen (der eigene Widerruf, #50, nimmt drei). Das Nachholen beim ersten Request ändert die Sitzungsgrenze nicht: das Token, das den Versuch auslöst, bleibt gültig, weil G3 nur Refresh-Tokens widerruft und das Token erst nach der Grenze ausgestellt wurde.
- **Umsetzung der TOTP-Zurücksetzung durch die Verwaltung (Ticket #49):** `POST /api/accounts/{id}/reset-mfa` mit `accounts.reset_mfa` und `accounts.view`; Regeln und Reihenfolge wie oben (409 `self_mfa_reset`; 409 `account_not_active` nur für noch nicht angenommene Konten). Der Handler `reset_mfa` (`TokenBlockingCall`) steht in `OpenCalls::standardHandlers()` hinter `revoke_sessions`; der Ablauf (lokale Sitzungsgrenze, offener Aufruf und Audit in einer Transaktion, danach G1) teilt sich den Baustein `SessionTermination` mit dem Widerruf. G1 widerruft auch die Refresh-Tokens, ein zusätzlicher G3 entfällt. Ein Pflichtkonto (#15) richtet TOTP danach neu ein: neue Tokens tragen `usedMFA=false`, fachliche Routen antworten 403 `mfa_enrollment_required`.
- **Audit:** `account.sessions_revoked` (`{initiated_by: "admin" | "self"}`) und `account.mfa_reset` (ohne Details; `mfa_was_enabled` ist bei gescheitertem Abgleich unbekannt). Das Nachholen erzeugt keinen Audit-Eintrag.

### B3 – MFA-Pflicht erzwingen

Für Konten mit `mfa_required = true` (Rollen mit dem Merkmal `requires_mfa`, also alle Mitarbeitenden- und Admin-Rollen) wird ein Token mit `usedMFA = false` bei fachlichen Routen mit `403 {"code": "mfa_enrollment_required"}` abgelehnt.

Erlaubt bleiben nur `GET /api/me` und die Routen, die das Frontend zur Einrichtung über den Auth-Dienst braucht. Das Flag wird über G2 an den Auth-Dienst gespiegelt.

**Pflichttest:** Eine Lehrkraft ohne TOTP erhält 403, nach der Einrichtung und erneutem Login hat sie Zugriff.

**Nachtrag (Umsetzung Ticket #15, 2026-11-19):**
- Die Prüfung läuft nach den 401-Prüfungen von B1/B2 und vor jeder Rechteprüfung. Ausgenommen ist nur `GET /api/me`; es nennt `session.mfa_enrollment_required`.
- Spiegeln wie bei der Deaktivierung: zuerst lokal (`mfa_required`, Markierung `mfa_sync_pending` in derselben Transaktion), nach dem Commit `mfa-requirement`. Ein Fehler lässt die lokale Durchsetzung bestehen; die Markierung wird beim nächsten Abgleich nachgeholt, automatisch später über die Outbox (#39).
- Fällt die Pflicht weg, meldet die API `required: false`; ein eingerichtetes TOTP bleibt aktiv.

### B4 – Rechteverwaltung über `PHP-Permissions` (P1–P3)

Der `AccessAdministration`-Service ist der **einzige** Weg zu `UserPermissionService::createRole/updateRole/deleteRole/setUserAccess`. Er führt zu jeder Aktion einen Audit-Eintrag.

| Aktion | benötigte Permission |
|---|---|
| Rolle anlegen, ändern oder löschen | `roles.manage` |
| Rolle einem Konto zuweisen oder entziehen | `roles.assign` |
| Einzelrechte hinzufügen oder entfernen | `permissions.assign` |

**Invarianten** (Verstoß ergibt 403 bzw. 409):
- **P2 – keine Selbsterweiterung:** Ohne Wildcard-Admin-Rolle kann niemand sich selbst Rollen oder Rechte geben. Niemand kann anderen Rechte geben, die er selbst nicht hat. Admins dürfen sich über Rollen Rechte zuweisen (17.9).
- **P6 – exklusive Schülerrolle (Nachtrag, Ticket #10):** Ein Konto mit der Rolle Schüler/in hat keine weitere Rolle und keine Einzelrechte (keine Overrides); die Rechte der Schülerrolle selbst sind nicht über die Rechteverwaltung änderbar. Verstoß ergibt 409 `{"code": "student_role_exclusive"}`. Durchgesetzt im `AccessAdministration`-Service.
- **P3 – letzter Admin:** Entzieht eine Aktion dem letzten **aktiven** Konto die Schuladmin-Rolle oder deaktiviert sie es, wird sie mit `409 {"code": "last_admin"}` abgelehnt. Die Prüfung läuft in derselben Transaktion mit `SELECT ... FOR UPDATE`.

**Nachtrag (Klärung zu Ticket #11, 2026-11-18):**
- **Standardrollen:** Das Setup legt idempotent `schuladmin` (System-Rolle mit Wildcard), `schueler` (geschützt, Rechte kommen aus dem Code), `lehrkraft`, `erziehungsberechtigte`, `sekretariat` und `schulleitung` an. Die letzten vier haben frei änderbare Rechte und starten leer. Keine Standardrolle ist löschbar. Wer `schuladmin` oder `schueler` bearbeitet oder eine Standardrolle löscht, erhält 409 `role_protected`. Selbst angelegte Rollen erhalten einen zufälligen, unveränderlichen Schlüssel. Beim tatsächlichen Anlegen schreibt das Setup `role.created` mit Akteur `cli`.
- **Löschen:** Eine Rolle, die noch einem Konto zugewiesen ist, wird nicht gelöscht: 409 `role_in_use`. Das Cascade des Pakets greift damit nie.
- **„Erfordert MFA“** ist ein Merkmal der Rolle, das die Anwendung neben dem Paket führt. Es ist faktorneutral (V1: TOTP, später Passkeys). Fest an bei `schuladmin`, `lehrkraft`, `sekretariat` und `schulleitung`; fest aus bei `schueler` und `erziehungsberechtigte`. Selbst angelegte Rollen sind standardmäßig an und abschaltbar. `accounts.mfa_required` ist das ODER über alle Rollen des Kontos und wird in derselben Transaktion neu berechnet, sobald sich Rollen oder das Merkmal ändern. Spiegeln (G2) und Erzwingen (B3) gehören nicht zu #11.
- **P2 im Detail** (alles 403; ein Konto mit Wildcard ist ausgenommen):
  - *Rolle bearbeiten:* Hinzufügen darf man nur Rechte, die man selbst effektiv besitzt (`permission_not_held`). Eine Rolle, die man selbst innehat, darf man nicht erweitern (`self_escalation`). Entfernen ist immer erlaubt.
  - *Rolle vergeben und entziehen (symmetrisch):* nur, wenn man **alle** Rechte der Rolle selbst besitzt (`permission_not_held`). Sich selbst eine Rolle geben ergibt `self_escalation`. Deshalb vergeben und entziehen nur Schuladmins die Rolle Schuladmin.
  - *Einzelrecht gewähren, entziehen oder zurücksetzen:* nur, wer das Recht selbst besitzt (`permission_not_held`). Das Zurücksetzen eines entzogenen Einzelrechts gibt das Recht über die Rolle zurück und zählt als Gewähren. Sich selbst Einzelrechte geben ergibt `self_escalation`.
- **Schuladmin ohne Einzelrechte:** Konten mit der Rolle Schuladmin haben keine Einzelrechte. Wer eines setzt oder einem Konto mit Einzelrechten die Rolle gibt, erhält 409 `admin_overrides_forbidden`. Ein Schuladmin ohne `roles.assign` würde P3 sonst aushebeln.
- **P3** zählt nur Konten mit Status `aktiv`. Ein nur eingeladener zweiter Admin hebt die Sperre nicht auf. Die Prüfung ist ein wiederverwendbarer Baustein von `AccessAdministration`, den später auch die Deaktivierung nutzt.
- **Lesen:** Rollen, Rechte-Katalog und die Zuweisungen eines Kontos sind mit einem der drei Verwaltungsrechte lesbar, ein `roles.view` gibt es nicht. Der Katalog führt zu jedem Recht einen deutschen Namen und eine Gruppe.
- **Schnittstelle:** Einzeloperationen pro Rolle und pro Einzelrecht, kein Gesamt-Ersetzen der Zuweisungen eines Kontos.
- **Audit-Handlungen:** `role.created`, `role.updated` (hinzugefügte und entfernte Rechte), `role.deleted`, `role.assigned`, `role.revoked`, `permission.granted`, `permission.withdrawn`, `permission.override_cleared`. Details: Rollenschlüssel und Rollenname zum Zeitpunkt der Handlung bzw. Rechte-Schlüssel und Modus.

**Pflichttests:**
- Ein Verwalter mit nur `roles.assign` kann keine Rolle ändern.
- Ein Verwalter kann sich selbst kein Recht geben.
- Der letzte Admin kann nicht entfernt werden, auch nicht bei zwei gleichzeitigen Requests.

### B5 – Modulfreigabe getrennt von Rechten

`ModuleGate::require('observation')` prüft die lokal gespeicherte Modulfreigabe der Instanz und läuft **vor** der Permission-Prüfung. Ein inaktives Modul führt zu `403 {"code": "module_disabled"}`.

Eine Modulfreigabe gewährt selbst keinen Zugriff auf Objekte (17.12). Die Freigaben liegen lokal, damit ein Ausfall der Control Plane den Betrieb nicht stoppt.

### B6 – Objekt-, Zeit- und Zuordnungs-Scopes (P4)

Das Interface `AccessPolicy` hat eine Implementierung pro Fachmodul:
```php
interface AccessPolicy {
    /** @return bool Darf $user die Aktion $action auf $resource zum Zeitpunkt $at ausführen? */
    public function allows(CurrentUser $user, string $action, ResourceRef $resource, \DateTimeImmutable $at): bool;
}
```
**Reihenfolge der Prüfung:**
1. `ModuleGate`
2. `UserPermissionService::can()` (die grobe Frage)
3. `AccessPolicy::allows()` (die Objektfrage)

Alle drei müssen zustimmen. Policies lesen nur Zuordnungen, die zum Zeitpunkt `$at` gültig sind: Unterrichtszuordnung, Klassenleitung, Vertretung **je Unterrichtsstunde** und Elternrecht je Kind mit `valid_from` und `valid_until`. Wildcard-Rechte heben eine Policy-Ablehnung **nicht** auf.

**Pflichttests:**
- Nach dem Ende einer Zuordnung ist kein Zugriff mehr möglich.
- Eine Vertretungslehrkraft sieht nur ihre Stunde und nicht das Notenbuch (17.14 Nr. 4).
- Ein abgelaufenes Elternrecht führt zu 403.

### B7 – ICE-Sperre (P5, sicherheitskritisch)

Private ICE-Rohdaten sind **ausschließlich** über ein `IceObservationRepository` erreichbar. Jede Abfrage enthält zwingend die Bedingung `author_account_id = :current_account`, ohne Parameter, der sie abschaltet.

- Es gibt keine Permission `ice.read_all` und keinen Support- oder Export-Pfad.
- Schulweite Exporte und Statistiken verwenden eine eigene Lesesicht, die die ICE-Tabellen nicht einbindet.
- Der Wildcard `*` hat hier keine Wirkung, weil die Prüfung an der Autorenschaft hängt und nicht an einer Permission.

**Pflichttests (17.14 Nr. 2):**
- Ein Schuladmin mit `*` liest fremde ICE-Daten nicht: Er erhält eine leere Liste bzw. 404.
- Ein Supporttoken liest fremde ICE-Daten nicht.
- Der schulweite Export enthält keine ICE-Daten.

---

## Reihenfolge

1. **B1, B2, B4, B5, B7** im Backend. Sie blockieren nichts, weil sie ohne Änderungen am Auth-Dienst testbar sind. Die Test-Seam für den `AuthServiceClient` ist ein HTTP-Stub an der Netzwerkgrenze (`tests/Support/AuthStub.php`, über `AUTH_SERVICE_URL` erreichbar) statt Interface und Fake: Die API-Tests laufen per `php-cgi` in eigenen Prozessen, ein Fake im Testprozess wäre dort nicht erreichbar, und der Stub prüft zugleich Signatur und Fehlerverhalten (Timeout, 5xx, unlesbare Antwort) wie im Betrieb.
2. **G5 und G4** im Auth-Dienst, weil sie für das erste echte Konto nötig sind.
3. **G3, G1 und G2**, danach B3 scharf schalten.
4. **B6**, sobald die ersten Fachzuordnungen (Fachgruppe, Klassenleitung) existieren.

## Risiken

- **Zeitfenster bei Access-Tokens:** Ohne B2 bleibt ein widerrufenes Token bis zu 15 Minuten gültig. B2 ist deshalb Pflicht und nicht optional.
- **Doppelte Kontoführung** (`accounts` lokal und `users` im Auth-Dienst): Maßgeblich für den Status ist das Backend. Der Auth-Dienst spiegelt den Status, damit kein neuer Login möglich ist. Ein Abgleichsjob meldet Abweichungen, korrigiert sie aber nicht stillschweigend.
- **Änderungen am Auth-Dienst betreffen den EAW-Shop:** Alle Felder haben abwärtskompatible Defaults und Regressionstests sind Pflicht.
