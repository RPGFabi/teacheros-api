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

## Weiterführend

- [Architektur](architecture.md) · [Roadmap](roadmap.md) · [Entscheidungen](decisions/) · [Glossar](../GLOSSARY.md)
