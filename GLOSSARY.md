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

**Anmeldename**:
Die an der Schulinstanz eindeutige Kennung, mit der sich ein Konto anmeldet; zugleich der Name, unter dem das Konto im Anmeldedienst erscheint. Ohne eigene Vergabe ist er die E-Mail-Adresse; Konten ohne E-Mail-Adresse (typisch Schüler/innen) melden sich nur mit ihm an.
_Avoid_: Username, Login, Benutzername

**Einladung**:
Der einzige Weg, auf dem an einer Schulinstanz ein Konto entsteht. Das Konto ist bis zur Annahme `eingeladen`; die Person nimmt an, indem sie ihr Passwort setzt, und das Konto wird bei der ersten Anmeldung `aktiv`. Eine erneute Einladung macht die vorige ungültig.
_Avoid_: Registrierung, Provisionierung

**Einladungscode**:
Ein kurzer, einmal angezeigter Code, mit dem eine Person ohne E-Mail-Adresse ihre Einladung annimmt; er wird auf einer ausgedruckten Einladungskarte (Text und QR-Code) weitergegeben. Eine erneute Einladung macht ihn ungültig.
_Avoid_: PIN, Zugangscode, Passwort

**Schuladmin**:
Eine Rolle mit allen Rechten an der Schulinstanz, außer dem Zugriff auf private ICE-Daten. Der erste Schuladmin wird von der Plattform eingeladen, weitere setzen bestehende Schuladmins ein.
_Avoid_: Admin, Administrator, Superuser

**Audit-Eintrag**:
Ein datensparsamer Nachweis einer administrativen oder sicherheitsrelevanten Handlung: wer, wann, welche Handlung, an welchem Objekt. Er enthält keine Kopie fachlicher Daten.
_Avoid_: Log, Protokoll, History
