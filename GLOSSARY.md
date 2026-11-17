# TeacherOS

Schulplattform für den integrierten Unterrichts-, Leistungs- und Organisationsalltag allgemeinbildender Schulen. Jede Schule wird als eigene, vollständig getrennte Schulinstanz betrieben.

## Identität und Zugang

**Schulinstanz**:
Der vollständig getrennte Betrieb von TeacherOS für genau eine Schule, mit eigenen Daten, Konten und Zugängen.
_Avoid_: Mandant, Tenant, Installation

**Person**:
Die fachliche Identität eines Menschen an einer Schule (z. B. Lehrkraft, Schüler/in, Erziehungsberechtigte/r) mit dauerhafter ID; existiert auch ohne Konto.
_Avoid_: User, Benutzer, Profil

**Konto**:
Der Anmeldezugang einer Person an genau einer Schulinstanz. Eine Person hat an einer Schulinstanz höchstens ein Konto, auch wenn sie mehrere Rollen innehat.
_Avoid_: Account, User, Login, Benutzerkonto

**Rolle**:
Eine benannte Funktion, die ein Konto an der Schulinstanz innehat (z. B. Lehrkraft, Erziehungsberechtigte/r, Schuladmin). Ein Konto kann mehrere Rollen haben; jede Rolle wird einzeln vergeben und beendet, ohne das Konto oder andere Rollen zu berühren.
_Avoid_: Gruppe, Typ
