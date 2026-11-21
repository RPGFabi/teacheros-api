---
status: accepted
date: 2026-11-21
---

# Lehrkraft-Zuordnung hängt vorerst am Konto, nicht an einer Person

Lehrkraft-Zuordnungen und Klassenleitungen (#18) verweisen auf ein **Konto** mit der Standardrolle Lehrkraft, obwohl das Glossar die **Person** als fachliche Identität führt, die auch ohne Konto existiert. Eine Personen-Tabelle für Lehrkräfte sieht die Source of Truth erst in P3/P4 vor (`teachers` mit optionalem `user_id`, 5.1). Sie jetzt einzuführen, hätte diesen Umfang in M0 vorgezogen, ohne dass eine Lehrkraft ohne Konto heute gebraucht wird. Sobald es Personen für Lehrkräfte gibt, werden die Zuordnungen auf die Person umgehängt. Das ist eine Datenmigration, keine Änderung der fachlichen Regeln.

## Considered Options

- **Person sofort einführen:** passt zum Glossar, zieht aber Personenpflege, Import und Verknüpfung mit Konten nach M0 vor.
- **Eigenes Recht statt Rolle** (z. B. `teaching.assignable`, an jede Rolle vergebbar): flexibler für eigene Rollen wie „Referendar/in“, vermischt aber das Recht, etwas zu tun, mit der Eigenschaft, Lehrkraft zu sein. Abgelehnt: Wer unterrichtet, bekommt zusätzlich die Standardrolle Lehrkraft.

## Consequences

- Rolle und Kontostatus werden nur beim **Anlegen** einer Zuordnung geprüft (Rolle Lehrkraft, Konto nicht `deaktiviert`). Wird später die Rolle entzogen oder das Konto deaktiviert, bleiben die Zuordnungen als Historie bestehen.
- Eine Lehrkraft ohne Konto lässt sich bis zur Einführung der Person nicht zuordnen.
