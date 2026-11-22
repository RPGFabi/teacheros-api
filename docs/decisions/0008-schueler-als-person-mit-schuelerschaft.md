---
status: accepted
date: 2026-11-22
---

# Schüler/in ist eine Person mit eigener Schülerschaft, keine eigene Identität

Für #19 führen wir eine allgemeine **Person** (dauerhafte ID, Name) und daneben die **Schülerschaft** als eigene Eigenschaft dieser Person (1:1, mit Austritt und Rückkehr). Die Source of Truth (5.1) führt dagegen `students` als eigene fachliche Identität. Wir weichen davon ab, weil das Glossar die Person als Identität für alle Menschen an der Schule definiert und dieselbe Person später zugleich Lehrkraft oder Erziehungsberechtigte/r sein kann. Mitgliedschaften und alle späteren Fachdaten (Beobachtungen, Noten) verweisen auf die Person, nicht auf die Schülerschaft.

## Considered Options

- **Eigene Identität `students`** (wie in der Source of Truth): einfacher für M0. Lehrkräfte (`teachers`, ADR 0007) und Erziehungsberechtigte (`guardians`) bekämen dann aber je eine eigene Identität, und ein Mensch in zwei Funktionen wäre zwei Datensätze ohne Verbindung.
- **Person mit Personenart** (ein Feld „Schüler/in | Lehrkraft | …“): schließt mehrere Funktionen einer Person aus.

## Consequences

- Wenn Lehrkräfte Personen bekommen, hängen die Lehrkraft-Zuordnungen aus ADR 0007 an dieselbe Personen-Tabelle; eine zweite Identität entsteht nicht.
- Die Verknüpfung Konto ↔ Person ist nicht Teil von #19, sie kommt mit #29 (Kartendruck).
- Rechte heißen fachlich nach der Funktion (`students.view`, `students.manage`), nicht nach der Person.
