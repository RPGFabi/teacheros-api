---
status: accepted
date: 2026-11-17
supersedes: ADR-0005
---

# Repositories nur je deploybarer Einheit: API, Auth, Web

Es gibt genau drei Repositories, weil es genau drei getrennt deployte Einheiten gibt: `teacheros-api`, `jwt-auth-service` und `teacheros-web`. `teacheros-web` enthält die Capacitor-Hülle für iOS und Android (Source of Truth 17.16 Nr. 3). Ein eigenes Plattform- oder Doku-Repo gibt es nicht.

Das übergreifende Material liegt in `teacheros-api`, weil dort die API-Verträge entstehen: Source of Truth, ADRs (`docs/decisions/`), `GLOSSARY.md`, OpenAPI-Verträge, Release-Manifest (`releases/<version>.json`) und die lokale `docker-compose.yml`. Die Issues aller drei Repos werden dort zentral geführt und über die Labels `repo:api`, `repo:web` und `repo:auth` zugeordnet.

Das bisherige Repo `teacheros-mobile` ist archiviert. Gemeinsame TypeScript-Typen liegen im Web-Repo unter `src/shared/`. Die maßgebliche Schnittstelle zwischen API und Web ist der OpenAPI-Vertrag, kein gemeinsames Paket.
