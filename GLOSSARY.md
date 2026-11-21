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

**Standardrolle**:
Eine Rolle, die es an jeder Schulinstanz gibt (Schuladmin, Schüler/in, Lehrkraft, Erziehungsberechtigte/r, Sekretariat, Schulleitung) und die nicht gelöscht werden kann. Die Rechte von Schuladmin und Schüler/in liegen fest, die der übrigen Standardrollen legt die Schule fest. Daneben kann eine Schule eigene Rollen anlegen.
_Avoid_: Systemrolle, Grundrolle

**Recht**:
Die Erlaubnis, eine bestimmte Art von Handlung an der Schulinstanz auszuführen (z. B. Konten einladen). Ein Konto erhält Rechte über seine Rollen und über Einzelrechte; ob es auf ein bestimmtes Objekt zugreifen darf, entscheidet zusätzlich seine Zuordnung.
_Avoid_: Permission, Berechtigung, Befugnis

**Einzelrecht**:
Ein Recht, das einem einzelnen Konto abweichend von seinen Rollen zusätzlich gewährt oder entzogen wird. Konten mit der Rolle Schuladmin oder Schüler/in haben keine Einzelrechte.
_Avoid_: Override, Sonderrecht, Ausnahme

**Anmeldename**:
Die an der Schulinstanz eindeutige Kennung, mit der sich ein Konto anmeldet; zugleich der Name, unter dem das Konto im Anmeldedienst erscheint. Ohne eigene Vergabe ist er die E-Mail-Adresse; Konten ohne E-Mail-Adresse (typisch Schüler/innen) melden sich nur mit ihm an.
_Avoid_: Username, Login, Benutzername

**Einladung**:
Der einzige Weg, auf dem an einer Schulinstanz ein Konto entsteht. Das Konto ist bis zur Annahme `eingeladen`; die Person nimmt an, indem sie ihr Passwort setzt, und das Konto wird bei der ersten Anmeldung `aktiv`. Eine erneute Einladung macht die vorige ungültig.
_Avoid_: Registrierung, Provisionierung

**Deaktivierung**:
Die Sperre eines aktiven Kontos durch die Verwaltung: Das Konto kann sich nicht mehr anmelden, laufende Sitzungen enden sofort. Konto, Person, Rollen und Daten bleiben erhalten; das Konto ist `deaktiviert`. Die **Reaktivierung** hebt die Sperre auf und stellt den vorigen Zugang wieder her, ohne alte Sitzungen zurückzubringen.
_Avoid_: Sperrung, Löschung, inaktiv

**Einladungscode**:
Ein kurzer, einmal angezeigter Code, mit dem eine Person ohne E-Mail-Adresse ihre Einladung annimmt; er wird auf einer ausgedruckten Einladungskarte (Text und QR-Code) weitergegeben. Eine erneute Einladung macht ihn ungültig.
_Avoid_: PIN, Zugangscode, Passwort

**Anmeldedienst**:
Der getrennte Dienst je Schulinstanz, der Anmeldung, Passwörter, TOTP und Einladungen verwaltet.
_Avoid_: Auth-Server, Identity Provider

**Schuladmin**:
Eine Rolle mit allen Rechten an der Schulinstanz, außer dem Zugriff auf private ICE-Daten. Der erste Schuladmin wird von der Plattform eingeladen, weitere setzen bestehende Schuladmins ein.
_Avoid_: Admin, Administrator, Superuser

**Audit-Eintrag**:
Ein datensparsamer Nachweis einer administrativen oder sicherheitsrelevanten Handlung: wer, wann, welche Handlung, an welchem Objekt. Er enthält keine Kopie fachlicher Daten.
_Avoid_: Log, Protokoll, History

## Betrieb

**Modul**:
Ein abgegrenzter Produktbereich von TeacherOS mit fester Kennung (z. B. `observe` für Beobachtungen). `core` (Stammdaten, Konten, Rollen) gehört zu jeder Schulinstanz und ist immer aktiv.
_Avoid_: Feature, Plugin, Paket

**Modulfreigabe**:
Die Festlegung, ob ein Modul an einer Schulinstanz ab einem Zeitpunkt läuft. Sie wird lokal gespeichert, nie überschrieben, sondern um eine neue Version mit Gültigkeitsbeginn ergänzt, und vom Betreiber gesetzt, nicht von der Schule. Eine Modulfreigabe gewährt kein Recht und keinen Zugriff auf Objekte.
_Avoid_: Lizenz, Feature-Flag, Entitlement, Aktivierung

## Stammdaten

**Schuljahr**:
Ein Zeitraum von einem ersten bis zu einem letzten Kalendertag, in dem die Schule ihren Unterricht organisiert. Schuljahre einer Schulinstanz überschneiden sich nie, dürfen aber Lücken haben. Die Bezeichnung (z. B. „2026/27“) ergibt sich aus dem Zeitraum. Ob ein Schuljahr _laufend_, _kommend_ oder _vergangen_ ist, folgt allein aus dem Datum und wird nie von Hand umgeschaltet. _Laufend_ ist das Schuljahr, in das der heutige Tag fällt, oder, wenn der heutige Tag in einer Lücke zwischen zwei Schuljahren liegt, das nächste kommende. Vor dem ersten Schuljahr ist keines laufend. Ein Schuljahr mit Klassen bleibt dauerhaft bestehen.
_Avoid_: Jahr, Periode, Saison

**Klasse**:
Eine Schülergruppe mit Klassenstufe und Bezeichnung (z. B. Stufe 7, „7a“), die zu genau einem Schuljahr gehört; die Bezeichnung ist innerhalb des Schuljahres eindeutig. Dieselbe Bezeichnung im Folgejahr ist eine neue Klasse, nicht dieselbe Klasse ein Jahr später.
_Avoid_: Stammklasse, Lerngruppe, Kurs

**Klassenstufe**:
Die Stufe einer Klasse innerhalb der Schullaufbahn (z. B. 7). Sie wird bei der Klasse festgehalten und nie aus der Bezeichnung gelesen oder beim Schuljahreswechsel einfach hochgezählt.
_Avoid_: Jahrgang, Stufe, Niveau

**Archivierung**:
Eine Klasse, an der bereits etwas hängt, wird archiviert statt gelöscht: Sie bleibt mit ihrer Historie lesbar und ihre Bezeichnung belegt, ist aber für neue Zuordnungen nicht mehr wählbar. Sie lässt sich wiederherstellen. Nur was noch nirgends verknüpft ist, wird endgültig gelöscht.
_Avoid_: Deaktivierung, Soft Delete, Ausblenden
