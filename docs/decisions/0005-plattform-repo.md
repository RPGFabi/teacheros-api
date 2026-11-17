---
status: superseded by ADR-0006
date: 2026-11-17
---

# Plattform-Repo für Verträge, Release-Manifest und Dokumentation

Der Ordner `TeacherOS/` wird zum Repository `teacheros-platform`. Er enthält die Source of Truth, die ADRs (`docs/decisions/`), `GLOSSARY.md`, die OpenAPI-Verträge, das Release-Manifest (`releases/<version>.json`) und die lokale `docker-compose.yml`. Damit ist die Frage aus Source of Truth 17.16 Nr. 3 beantwortet. Die Anwendungen (`apps/teacheros-web` inkl. Capacitor-Hülle, `apps/teacheros-api`) bleiben eigene Repositories und werden im Plattform-Repo per `.gitignore` ausgeschlossen. Der Auth-Dienst bleibt im eigenen Repo `jwt-auth-service`.

Das Release-Manifest legt exakte Tags je Komponente fest, z. B. `{"web": "v0.1.0", "api": "v0.1.0", "auth": "v1.1.0"}` (17.12). Lokal bindet `docker-compose.yml` den Auth-Checkout über `AUTH_SERVICE_PATH` ein und nutzt eine eigene Datenbank `teacheros_auth` im gemeinsamen MariaDB-Container.
