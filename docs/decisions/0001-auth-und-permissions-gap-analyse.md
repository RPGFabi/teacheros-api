# ADR 0001 – Gap-Analyse: jwt-authservice und PHP-Permissions gegen Source of Truth 17.9

**Status:** Vorgeschlagen (2026-11-17)
**Bezug:** Source of Truth 17.9, 17.16 Nr. 6 (erster Implementierungsschritt), 17.14 Nr. 1/2/8
**Geprüft:** `D:\Programming\jwt-auth-service` (README, Routen, Repository, Migrationen), `D:\Programming\Components\PHP\PHP-Permissions` (README, Quellcode-Signaturen). Nicht geprüft: `React-Auth`, `React-Permissions`, `React-UI` (folgt mit dem Frontend-Setup).

## Entscheidung

Beide Pakete werden wiederverwendet. Es entsteht **keine** Parallel-Engine. Lücken werden als gezielte Erweiterung in den jeweiligen Paket-Repositories geschlossen. Solange eine Lücke offen ist, wird sie im TeacherOS-Backend hinter einem Interface gekapselt, damit später ohne Umbau getauscht werden kann.

## Ergebnis je Anforderung

### jwt-authservice

| Anforderung (17.9) | Stand | Bewertung |
|---|---|---|
| Lokale JWT-Prüfung (RS256, `iss`/`aud`/`typ`/`exp`) | Token-Vertrag ADR 0012 vorhanden, eine Instanz bedient genau eine App (`TOKEN_AUDIENCE`) | **Erfüllt.** Pro Schulinstanz eine Auth-Instanz oder getrennte Audience nötig (Frage F1) |
| Getrenntes Konto und TOTP pro Schule | Eine Instanz pro App, Konten pro Instanz | **Erfüllt**, wenn pro Schule eine eigene Auth-Instanz/-DB läuft (F1) |
| TOTP | `MfaService`, Challenge, Setup vorhanden | **Erfüllt** |
| Gehashte Recovery-Codes | Tabelle `user_recovery_codes`, Code-Hash, Einmalverwendung, Regenerierung | **Erfüllt** |
| Verwaltetes TOTP-Reset (durch Admin) | Kein interner Endpunkt gefunden | **Lücke G1** |
| Force-MFA für Mitarbeitende/Admin (Eltern/SuS optional) | Keine rollenabhängige MFA-Pflicht im Auth-Dienst | **Lücke G2** (Pflicht kann auch im App-Backend durchgesetzt werden: Token-Claim `usedMFA` ist vorhanden) |
| Dauerhafter Session-Widerruf | Logout und Force-Password-Reset widerrufen Sessions; Refresh-Rotation mit Diebstahlerkennung | **Teilweise.** Gezielter Widerruf einzelner Nutzer ohne Passwort-Reset fehlt (`disable-login` entfernt Tokens, sperrt aber den Login) – **Lücke G3** |
| Deaktivierung/Reaktivierung | `users.is_active`, `/internal/users/disable-login`; keine Reaktivierung dokumentiert | **Lücke G4** (Reaktivierungs-Endpunkt) |
| `isInactive` bei **jedem** geschützten Request | Access-Token enthält keinen Aktiv-Status; Token bleibt bis `exp` gültig | **Gilt für das TeacherOS-Backend, nicht für das Paket:** Backend führt eigene Kontotabelle mit `is_inactive` und prüft sie je Request (siehe unten) |
| Initialer Admin per Einladung an E-Mail | `bin/create-user.php` (CLI) und Registrierung mit E-Mail-Bestätigung | **Teilweise.** Einladungsfluss liegt im TeacherOS-Backend (Einladungstoken → Registrierung/Passwort setzen) |
| Notfall-Recovery-Zugang Admin | Nicht vorhanden | Betrifft Control Plane, nicht das Paket; eigener ADR vor M0-Abschluss |

### PHP-Permissions (`UserPermissionService`)

| Anforderung (17.9) | Stand | Bewertung |
|---|---|---|
| Mehrere Rollen je Nutzer, additive/entfernte Einzelrechte | `setUserAccess(roleIds, overrides)`, `EffectivePermissions::resolve(added, removed)` | **Erfüllt** |
| Wildcard `*` kontrolliert | `grantWildcard`, `addPermissionToRole` lehnt `*` ab | **Erfüllt** |
| Trennung `roles.manage` / `roles.assign` / `permissions.assign` | Rechte sind nur Strings, `createRole`/`setUserAccess` prüfen keinen Aufrufer | **Lücke P1:** Trennung gehört in eine Schicht über dem Paket (`AccessAdministration`-Service im Backend), die den Aufrufer prüft |
| Verwalter darf eigene Rechte nicht erweitern | Nicht abgebildet | **Lücke P2**, gleiche Schicht wie P1 |
| Letztes aktives Schuladmin-Konto schützen | Nicht abgebildet | **Lücke P3**, Backend-Invariante mit Test |
| Objekt-/Zeit-/Kontext-Scopes (aktuelle Unterrichtszuordnung, Vertretung nur für eine Stunde, befristete Elternrechte) | Nur globale Rechtestrings, keine Scopes, kein Gültigkeitsende | **Lücke P4 (zentral).** Siehe Entscheidung unten |
| Modullizenz ≠ Nutzerberechtigung | Nicht Teil des Pakets | Wird im Backend als separater `ModuleGate` vor der Permission-Prüfung umgesetzt |
| ICE-Ausschluss auch für Admin/Wildcard | `*` gewährt per Definition alles | **Lücke P5 (sicherheitskritisch):** ICE darf nicht über Permissions, sondern nur über `author_user_id = aktueller Nutzer` im Repository-Zugriff lesbar sein. `*` greift dort nicht. Test aus 17.14 Nr. 2 ist Pflicht |

## Entscheidung zu Scopes (P4)

Permissions bleiben die *grobe* Frage („darf dieser Nutzer Anwesenheit führen?“). Die *Objektfrage* („für diese Klasse, jetzt, in dieser Stunde?“) entscheidet im Backend ein `AccessPolicy`-Interface pro Fachmodul, das auf Zuordnungsdaten (Unterrichtszuordnung, Vertretungszuweisung je Stunde, Elternrecht je Kind mit `valid_until`) arbeitet. Das Paket wird dafür **nicht** erweitert. Begründung: Scopes hängen am TeacherOS-Fachmodell, das generische Paket soll fachfrei bleiben. Das erfüllt „nur bei fehlenden Scopes gezielt erweitern“, ohne eine zweite Engine zu bauen, weil `can()` weiterhin die einzige Rechte-Auskunft bleibt und `AccessPolicy` sie nur ergänzt, nie ersetzt.

## Konsequenzen

- Backend-Ordner `Shared/Security` enthält: `CurrentUser` (aus JWT + lokaler Kontotabelle), `ActiveAccountMiddleware` (`is_inactive` je Request), `ModuleGate`, `AccessAdministration` (P1–P3), `AccessPolicy` (P4).
- Paket-Änderungen (G1–G4) werden als Issues in `jwt-authservice` geführt; sie blockieren M0 nicht, weil Deaktivierung, Rollen und MFA-Pflicht im Backend durchgesetzt werden können.
- Pflichttests vor M0-Abschluss: Schulisolation (17.14 Nr. 1), ICE-Sperre auch für `*` (Nr. 2), inaktives Konto mit gültigem Token wird geblockt (Nr. 8).

## Entschiedene Fragen (2026-11-17)

- **F1:** Ja – eine Auth-Instanz pro Schulinstanz (eigene DB, eigenes Schlüsselpaar, eigene `TOKEN_AUDIENCE`).
- **F2:** Lücken werden zuerst präzise spezifiziert: siehe [ADR 0002](0002-auth-und-rechte-luecken-spezifikation.md). Dort zusätzlich gefunden: G5 (Einladungsfluss). Korrektur zu oben: `/refresh` prüft `is_active` bereits.
