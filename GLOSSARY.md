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

**Sitzungswiderruf**:
Das sofortige Beenden aller laufenden Sitzungen eines Kontos, durch die Verwaltung oder durch die Person selbst. Anmeldung, Passwort und TOTP bleiben unverändert; eine neue Anmeldung ist sofort möglich.
_Avoid_: Logout, Abmelden, Session-Kill

**TOTP-Zurücksetzung**:
Das Entfernen von TOTP und Recovery-Codes eines Kontos durch die Verwaltung, etwa nach Verlust des Geräts; sie schließt einen Sitzungswiderruf ein. Gilt für das Konto eine MFA-Pflicht, richtet es TOTP bei der nächsten Anmeldung neu ein. Die eigene TOTP-Zurücksetzung über die Verwaltung gibt es nicht.
_Avoid_: MFA-Reset, 2FA löschen

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

**Schuleinstellung**:
Ein Wert, den die Schule selbst für ihre ganze Schulinstanz festlegt, gleich für alle Schuljahre (z. B. wie viele Lehrkräfte eine Fachgruppe höchstens zugleich hat). Wird ein Wert verschärft, bleibt Bestehendes unberührt; die Regel gilt nur für Neues. Anders als die Modulfreigabe setzt sie die Schule, nicht der Betreiber.
_Avoid_: Konfiguration, Option

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

**Fach**:
Ein Unterrichtsfach der Schulinstanz (z. B. Mathematik), unabhängig vom Schuljahr. Kürzel (z. B. „M“) und Bezeichnung werden beim Anlegen vergeben; das Kürzel ist an der Schulinstanz eindeutig und danach unveränderlich, die Bezeichnung bleibt änderbar.
_Avoid_: Unterrichtsfach, Kurs, Lernbereich

**Fachgruppe**:
Eine Schülergruppe, die in genau einem Schuljahr in genau einem Fach gemeinsam unterrichtet und bewertet wird, gebildet aus Schülerinnen und Schülern einer oder mehrerer Klassen desselben Schuljahres (z. B. „AES 1“ und „AES 2“ aus 7a–7c). Die Bezeichnung ist frei gewählt und innerhalb des Schuljahres eindeutig.
_Avoid_: Kurs, Lerngruppe, Teilgruppe

**Schüler/in**:
Eine Person, die an der Schulinstanz als Schüler/in geführt wird, mit Vor- und Nachnamen und optional Geburtsdatum. Namen dürfen sich ändern; die Person, ihre Mitgliedschaften und alles, was an ihr hängt, bleiben dieselben. Sie ist _aktiv_ oder _ausgetreten_ und braucht kein Konto. Endgültig gelöscht wird sie nur, solange sie noch nie eine Mitgliedschaft hatte.
_Avoid_: Schüler, SuS (im Modell), Student, Kind

**Klassenmitgliedschaft**:
Die Festlegung, dass eine Schüler/in für einen Gültigkeitszeitraum einer Klasse angehört. An jedem Tag gehört sie höchstens einer Klasse an. Was schon gegolten hat, wird beendet, nie gelöscht oder rückwirkend verändert; nur eine Mitgliedschaft, die noch nie gegolten hat, darf geändert oder gelöscht werden.
_Avoid_: Klassenzugehörigkeit, Zuordnung, Einschreibung

**Klassenwechsel**:
Der Übergang einer Schüler/in in eine andere Klasse desselben Schuljahres zu einem Stichtag, frühestens heute: Die bisherige Klassenmitgliedschaft endet am Vortag, die neue beginnt am Stichtag. Die alte bleibt als Historie bestehen.
_Avoid_: Umzug, Versetzung, Umbuchung

**Fachgruppenmitgliedschaft**:
Die Festlegung, dass eine Schüler/in für einen Gültigkeitszeitraum einer Fachgruppe angehört, mit denselben Regeln für Historie wie die Klassenmitgliedschaft. Eine Schüler/in kann zugleich mehreren Fachgruppen angehören, auch desselben Fachs, derselben Fachgruppe aber nicht mit sich überschneidenden Zeiträumen. Am ersten Tag muss die Schüler/in einer der Klassen der Fachgruppe angehören; ein späterer Klassenwechsel beendet die Fachgruppenmitgliedschaft nicht.
_Avoid_: Kursbelegung, Kurszugehörigkeit, Zuordnung

**Austritt**:
Das Ausscheiden einer Schüler/in aus der Schule mit ihrem letzten Tag als Stichtag, frühestens heute, auch im Voraus: Alle ihre Mitgliedschaften enden am Stichtag, ab dem Folgetag ist sie _ausgetreten_; ihre Daten bleiben erhalten. Ob sie _aktiv_ oder _ausgetreten_ ist, folgt allein aus dem Datum. Ein geplanter Austritt lässt sich bis zum Stichtag zurücknehmen; beendete Mitgliedschaften bleiben dabei beendet. Die **Rückkehr** macht eine ausgetretene Schüler/in ab ihrem ersten Tag zurück (frühestens heute) wieder _aktiv_, ohne alte Mitgliedschaften wiederzubeleben; neue Mitgliedschaften beginnen frühestens an diesem Tag. Eine Schüler/in ohne laufende Mitgliedschaft (etwa in den Ferien) ist nicht ausgetreten.
_Avoid_: Abmeldung, Löschung, Abgang, Deaktivierung

**Lehrkraft**:
Ein Konto mit der Standardrolle Lehrkraft; eigene Rollen machen ein Konto nicht zur Lehrkraft. Zugeordnet werden kann eine Lehrkraft, solange ihr Konto nicht deaktiviert ist. Wird ihr die Rolle entzogen oder das Konto deaktiviert, bleiben ihre bisherigen Zuordnungen als Historie bestehen. Sobald es Personen für Lehrkräfte gibt, hängt die Zuordnung an der Person statt am Konto.
_Avoid_: Lehrer/in, LK, Personal

**Lehrkraft-Zuordnung**:
Die Festlegung, dass eine Lehrkraft für einen Gültigkeitszeitraum eine Fachgruppe unterrichtet. Mehrere Lehrkräfte können zugleich derselben Fachgruppe zugeordnet sein, dieselbe Lehrkraft aber nicht mit sich überschneidenden Zeiträumen. Was schon gegolten hat, wird beendet, nie gelöscht oder rückwirkend verändert; nur eine Zuordnung, die noch nie gegolten hat, darf gelöscht werden.
_Avoid_: Lehrauftrag, Zuweisung, Unterrichtsverteilung

**Klassenleitung**:
Die Lehrkraft-Zuordnung einer Lehrkraft zu einer Klasse statt zu einer Fachgruppe, mit denselben Regeln für Gültigkeitszeitraum und Historie. Eine Klasse kann zugleich mehrere Klassenleitungen haben.
_Avoid_: Klassenlehrer/in, Stammklassenleitung

**Gültigkeitszeitraum**:
Die Kalendertage vom ersten bis einschließlich zum letzten Tag, an denen eine Zuordnung oder Mitgliedschaft gilt; ein offenes Ende heißt „bis zum Ende des Schuljahres“. Der Zeitraum liegt immer innerhalb des Schuljahres der Fachgruppe bzw. Klasse.
_Avoid_: Laufzeit, Periode, Zeitraum

**Archivierung**:
Eine Klasse, ein Fach oder eine Fachgruppe, an der bereits etwas hängt, wird archiviert statt gelöscht: Sie bleibt mit ihrer Historie lesbar und ihre Bezeichnung bzw. ihr Kürzel belegt, ist aber für neue Zuordnungen nicht mehr wählbar. Sie lässt sich wiederherstellen. Nur was noch nirgends verknüpft ist, wird endgültig gelöscht.
_Avoid_: Deaktivierung, Soft Delete, Ausblenden
