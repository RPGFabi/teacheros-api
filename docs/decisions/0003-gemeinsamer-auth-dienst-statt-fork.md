---
status: accepted
date: 2026-11-17
---

# Gemeinsamer jwt-authservice statt Fork oder Dauer-Branch

TeacherOS nutzt den bestehenden `jwt-authservice` unverändert als gemeinsamen Dienst (eine Instanz pro Schulinstanz, ADR 0002). Die Erweiterungen G1–G5 werden allgemein formuliert und abwärtskompatibel im Auth-Repo entwickelt: je Lücke ein Branch aus `dev`, Release `v1.2.0` (G5, G4) und `v1.3.0` (G3, G1, G2); `v1.1.0` ist bereits vergeben. TeacherOS legt die Auth-Version über einen Tag im Release-Manifest fest (ADR 0006). Gründe: Sicherheitskritischer Code existiert nur einmal, und Fixes wirken für EAW und TeacherOS. G1–G5 sind auch für EAW nützlich. Schulspezifische Logik liegt ohnehin im TeacherOS-Backend (B1–B7). Source of Truth 17.9 verbietet eine zweite parallele Auth-Plattform.

## Considered Options

- **Dauerhafter Branch `teacheros`:** abgelehnt. Er entfernt sich vom Hauptstand, Sicherheitsfixes müssen doppelt eingespielt werden, und unklar bleibt, welcher Stand deployt ist.
- **Kopie oder Fork `teacheros-auth`:** abgelehnt. Bei einem Entwickler würden Sicherheitsfixes doppelt gepflegt oder vergessen, und der Fork widerspricht 17.9. Der Gewinn an Kontrolle ist gering, weil die TeacherOS-Fachlogik nicht in den Auth-Dienst gehört.

## Auslöser für eine Neubewertung

Ein Fork wird erst neu bewertet, wenn eine TeacherOS-Anforderung nachweislich nicht abwärtskompatibel für EAW umsetzbar ist. Das erfordert eine dokumentierte Revision der Source of Truth (17.9).
