# TeacherOS – Produktkonzept und Implementierungsspezifikation

**Stand:** 14.11.2026, **Revision 1** (Abschnitt 17.16) – konsolidierte Source of Truth nach 231 Grundsatzentscheidungen (Frage 232 bewusst vertagt)  
**Interner Projektname:** `TeacherOS` (vorerst beibehalten; kein finaler Außenauftritt)  
**Öffentliche Marke/Domain:** **offen**; bevorzugt eigenständiger Produktname mit `.de`-Domain  
**Status:** Verbindliche Produkt- und Architektur-Baseline für die Entwicklung; **kein** geprüftes Datenschutzkonzept, keine rechtliche Freigabe und keine Betriebsgarantie  
**Zielgruppe Version 1.0:** Allgemeinbildende Schulen in Baden-Württemberg mit Lehrkräften, Schulverwaltung, Schulleitung, Schülerinnen und Schülern und Erziehungsberechtigten  
**Priorisierung:** Entwicklungsinkremente nach Abhängigkeiten; **offizieller Release 1.0 erst nach Fertigstellung des definierten schulischen Alltagsbetriebs**. Frühere P0–P4-Phasen sind historische technische Planung, keine aktuelle Aussage zum Marktstart.

> **Historischer Leitgedanke (25.10., für die offizielle V1-Grenze überholt):** Zuerst ein kleines, im Unterricht wirklich nutzbares Produkt erstellen; danach gezielt erweitern. Die Architektur ermöglicht später mehrere Schulen als **getrennte Installationen** und eine modulare, schulweise Funktionsfreischaltung, **ohne** deren vollständige Funktionen schon im MVP zu bauen. Alle personenbeziehbaren Testdaten sind bis zur schulischen Freigabe synthetisch.

**Historisches Architektur-Update (24.–25.10.2026; durch Abschnitt 17 präzisiert):** Intern weiterhin **TeacherOS**; öffentliche Marke noch offen (gewünschte `.de`-Domain, Verfügbarkeit und Markenrechte zu prüfen). **Single-Tenant pro Schule** mit schulbezogener Subdomain und eigenen Daten/Secrets/Backups; Module pro Installation konfigurierbar, automatische Bereitstellung und Lizenz-/Kostenmanagement erst bei Wachstum. Details: **Abschnitte 4.4–4.7**. **Erweiterbarkeitsprinzip (codebasiert):** Neue Module und neue Methoden *innerhalb* eines Moduls sollen durch Implementieren kleiner Interfaces, Hinzufügen eines Manifests und Registrierung über generische Loader hinzukommen, **ohne vorhandene Module oder Kernabläufe umzuschreiben**; fachliche Einträge nutzen ein gemeinsames Metadatenschema (Fach/Allgemein, schulisch benannte Art, Zielgruppen-Sichtbarkeit), Testergebnisse erscheinen auf **ganze Prozent** gerundet. **Neuer Fachstand 13.11.2026:** Vertretungsplanung mit dynamischem Entwurf und manueller Sammelveröffentlichung, schulisch gewichtete Vertretungsquellen inklusive Minusstunden/Blockvertretung/Fachbefähigung, Raum- und Pausenaufsichtsoptimierung, Schulveranstaltungen, temporäre Unterrichtszuordnung, geschützte Alarmadapter sowie schuljahresbezogene Statistiken und **verlängerbare Lehrkräfte-Abwesenheiten**. Die vollständigen fachlichen Verträge und Beispiele stehen in **Abschnitt 16**.

> [!IMPORTANT]
> **Normative Geltung / Lesereihenfolge:** Dieses Dokument ist die einzige fachliche Source of Truth. **Abschnitt 17** enthält die im Dialog bis einschließlich Grundsatzfrage 231 neu beschlossenen und konsolidierten Vorgaben und **hat bei Widersprüchen Vorrang** vor den älteren Abschnitten 1–16. Die älteren Abschnitte bleiben zur Bewahrung fachlicher Details und Beispiele erhalten, sind aber nur insoweit verbindlich, wie sie Abschnitt 17 nicht widersprechen. Insbesondere ältere Aussagen wie „nur persönliche App als erster Produktstart“, „nur PWA“, „später Schulplattform“, „nur Sammelveröffentlichung“, „Alarmmodul zwingend separat extern“ und „eine gemeinsame Schule-übergreifende Anmeldung“ gelten **nicht** gegen die jüngeren Entscheidungen. Abschnitt 18 hält bewusst nicht spezifizierte Themen fest. Ein Entwickler darf aus einer offenen Frage keine stillschweigende Produktentscheidung ableiten.
>
> **Entscheidungsklassen:** **VERBINDLICH** = ausdrücklich vereinbarte Produktregel; **V1** = verpflichtender Umfang der offiziellen Version 1.0; **SPÄTER** = vorgesehene Entwicklung, nicht Bestandteil des V1-Abnahmeumfangs; **OFFEN/BEWUSST VERTAGT** = kein Beschluss, keine erfundene Default-Festlegung; **IMPLEMENTIERUNG** = technisch gestaltbar innerhalb der verbindlichen Leitplanken.

## 1. Produktziel, Grenzen und Grundentscheidungen

TeacherOS verknüpft Schüler- und Fachgruppendaten, Unterrichtsbeobachtungen, Leistungsnachweise, Stoffverteilungsplan, Unterrichtsstunden, Klassenbuch, Anwesenheit, Elternkommunikation, Formulare, Zahlungen sowie später Stundenplan- und Vertretungsplanung. Es soll wiederholte Dateneingaben vermeiden: Ein Unterrichtstermin dient als gemeinsamer Bezugspunkt für Planung, Anwesenheit, Beobachtung und Klassenbuch.

**Festgelegte Produktentscheidungen**

1. **Browser zuerst:** Desktop für große Tabellen, Bulk-Operationen, Planung, Administration und Auswertung; Smartphone für ICE, Einzelbeobachtung, Sitzplan, Anwesenheit, Nachrichten und Rückmeldungen. Tablet erhält eine anpassungsfähige Hybridansicht.
2. **Eine React-Anwendung, plattformgerechte Auslieferung:** Browser/PWA als gemeinsamer Ausgangspunkt; Windows primär als PWA, iOS/Android bei Bedarf als Capacitor-Wrapper für native Push-Funktionen. Keine zweite fachliche App schreiben. Weder PWA noch native App kann überall laute Notfallalarme garantieren.
3. **ICE-Schnellerfassung (bewusst benannt nach dem schnellen Zug ICE):** Möglichst jede Person in **5 Sekunden**, spätestens ungefähr **10 Sekunden** erfassen – nicht alle 10 Sekunden zufällig jemanden beobachten. Fehlende Beobachtungen müssen übersprungen werden können.
4. **Bewertungsskala:** Standardmäßig drei Stufen `+` (positiv), `=` (solide), `−` (Entwicklungsbedarf). **Keine Eingabe / nicht beobachtet ist weder `=` noch `−`**; die Kategorien sind konfigurierbar.
5. **Sitzplan optional:** ICE und Einzelmodus bieten Listen- und Sitzplanansicht; beide speichern in dasselbe Beobachtungsmodell.
6. **Menschen entscheiden:** Noten, Zeugnisformulierungen, schulische Maßnahmen, Vertretungen, Unterrichtsausfall und Notfallalarm-Freigaben werden nie ohne jeweils zuständige menschliche Kontrolle verbindlich.
7. **Kein Kontowächter:** SEPA-QR-Codes für Überweisungen, manuelle Dokumentation von Zahlungseingängen und Listen für berechtigte Kolleginnen und Kollegen.
8. **ASV-BW bleibt führend** für offizielle Stammdaten, soweit die Schule ASV nutzt; TeacherOS ist für die fachlichen Anwendungsdaten zuständig. Ein zunächst manueller, kontrollierter Export/Import genügt.
9. **Offizielle Schulprozesse nicht ungefragt ersetzen:** Persönliches Klassenbuch ≠ automatisch offizielles Klassenbuch; eine PWA ≠ sicherheitskritisches Alarmierungssystem.
10. **Interner Name und öffentlicher Markenauftritt getrennt:** Repository, interne Modulnamen, Dokumentation und Entwicklung behalten vorerst den Namen **TeacherOS**. Der öffentlich sichtbare Produktname ist **noch nicht entschieden**, weil `teacheros.de` nach bisherigem Eindruck nicht verfügbar ist; Verfügbarkeit und Markenrechte einer neuen `.de`-Domain vor Registrierung prüfen. Keine unbestätigte Domain als sicher frei darstellen.
11. **Single-Tenant-by-Deployment:** **Eine getrennte Installation pro Schule**, jeweils mit eigenem Backend, Datenbankzugang, Dateispeicher, Secrets und Backup-Bereich. Gemeinsamer Quellcode und versionierte Container-Images; perspektivisch automatisierte Bereitstellung vieler Schulinstanzen.
12. **Module konfigurierbar, keine Code-Forks:** `Core` ist verpflichtend; fachliche Module können für eine Schulinstanz aktiviert/deaktiviert und perspektivisch lizenziert werden. **Modullizenz ≠ Nutzerberechtigung**: Jede API prüft beides separat.
13. **Erweiterbarkeit ist eine harte Qualitätsanforderung:** Neue Fachmodule und zusätzliche Methoden innerhalb vorhandener Module (z. B. ICE, Checkliste, Kriterienraster, `best_of_n`, neuer Exportadapter) müssen über versionierte, getestete Erweiterungspunkte möglich sein. Keine zentralen `switch`-Monolithen, die bei jeder Ergänzung in vielen Modulen verändert werden müssen; **keine beliebige, ungeprüfte Codeausführung** durch Schulen.
14. **Einheitlicher pädagogischer Eintragskontext:** Schülerbezogene Einträge besitzen einen Fachbezug **oder** „Allgemein“, eine schulisch definierbare Eintragsart und ausdrücklich geregelte Sichtbarkeit für berechtigte LuL, die betroffenen SuS und berechtigte EZB. Beim Erfassen aus einer Unterrichtsstunde wird das Fach vorgegeben, ohne unzulässige Überschreibung. Neue Einträge sind standardmäßig intern und **nicht veröffentlicht**.
> **[Überholt durch 17.16 / Rev. 1]** Für Klassenbucheinträge gilt der Default *SuS + Eltern sichtbar* (17.16 Nr. 1).
15. **Testergebnisse auf ganze Prozent:** Punkte sind die Rechengrundlage; Prozentwerte werden für UI, PDF, Exportdarstellung und automatisch formulierte Rückmeldungen kaufmännisch auf **ganze Prozent** gerundet. Grenzwerte für Noten und Freischaltungen werden anhand der **exakten** Werte bzw. definierter Punktgrenzen geprüft; angezeigte 80 % bedeuten nicht zwingend mindestens 80,00 %.
16. **Versuche und Niveaus getrennt:** Niveaustufen sind schul-/fachspezifisch benennbar; ein Versuch speichert sein tatsächlich bearbeitetes Niveau. Best-of-X wird **pro Niveau** ausgewertet; keine verdeckte automatische Vergleichbarkeit unterschiedlicher Niveaus.
17. **Datengestützte, freigegebene Rückmeldungen:** Lehrkräfte wählen eine versionierte Textvorlage nach der Eingabe; das System ersetzt Platzhalter, speichert den endgültigen Text und veröffentlicht ihn erst nach expliziter Freigabe für die eingestellten Empfänger.
18. **Datenschutzversprechen überprüfbar halten:** Eine `.de`-Domain signalisiert die deutsche Zielgruppe, aber **weder deutschen Serverstandort noch DSGVO-Konformität**. Aussagen zu Hosting in Deutschland, Unterauftragsverarbeitern und Datenflüssen erst nach tatsächlicher technischer und vertraglicher Prüfung treffen.
19. **Vertretungsplanung als Human-in-the-Loop-Optimierung:** Personelle Ausfälle, Klassenveranstaltungen, Räume, Unterrichtsarten, Teamstunden und Pausenaufsichten gemeinsam behandeln; automatischer Vorschlag ist niemals eine autonome dienstliche Anordnung.
20. **Nur zwei Planansichten:** Ein frei bearbeitbarer **Entwurf** und eine getrennt gespeicherte, **veröffentlichte** Planversion. Jede manuelle Änderung validiert und reoptimiert die restliche betroffene Planung. Keine Benachrichtigung aus Entwürfen; **nur ausdrücklich manuelle Sammelveröffentlichung** erzeugt Mitteilungen.
> **[Überholt durch 17.16 / Rev. 1]** Veröffentlicht werden ausgewählte einzelne Stunden, Benachrichtigung sofort individuell (17.4, 17.16 Nr. 2).
21. **Abwesenheiten sind korrigier- und verlängerbar:** Eine Lehrkräfte-Abwesenheit kann über ihren bisherigen Endzeitpunkt hinaus verlängert, verkürzt oder berichtigt werden. Der bisherige Datensatz und seine Historie bleiben erhalten; betroffene zukünftige Stunden/Aufsichten werden im Entwurf neu berechnet, veröffentlichte Stände bleiben bis zur erneuten Freigabe bestehen.
22. **Räume und Personal als constrained resources:** Jede Raumzuweisung prüft Kapazität, Tätigkeit, Ausstattung, erforderliche Qualifikationen/Fachkräfte und Sicherheitsbedingungen. Bei Eignungsproblemen bevorzugt nach Regelwerk das freie und geeignete Stammklassenzimmer (`KlaZi`) suchen; sichere Alternativen und Raumtausch sind erlaubt, illegale Ausnahmen nicht.
23. **Schulveranstaltungen und statistische Wahrheit:** Klassenfahrten/Ausflüge/Projekttage belegen planmäßig Unterrichtszeit und binden Begleitpersonen; geleistete Veranstaltung, vertretener Unterricht, echter Ausfall und dienstrechtliche Anrechnung bleiben getrennte Sachverhalte. Lehrkräftefehlzeiten und Veranstaltungen verwenden schulisch anlegbare, historisierte Kategorien.
24. **Schuljahresbericht reproduzierbar:** Die tatsächliche Durchführung und veröffentlichte Planänderungen müssen eine nachvollziehbare Jahresstatistik mit CSV, XLSX und PDF ermöglichen. Personenbezogene Fehlzeitengründe sind nur für Berechtigte einsehbar.
25. **Zwei Benachrichtigungsklassen:** Normale Chat-/Klassenbuch-/Planänderungs-Mitteilungen sind von sicherheitskritischen Alarmen zu trennen; letztere benötigen einen geeigneten externen Alarmweg und dürfen nicht allein auf garantiert laute Smartphone-Pushs vertrauen.

**Nichtziele des ersten Releases:** vollständige Schulverwaltung, öffentliche Registrierung von Eltern, eigenständige native Neuentwicklungen für iOS/Android/Windows, automatisch vergebene Noten, direkte Bankanbindung, eigenständige Amok-/Brandalarmierung, automatische ASV-Echtzeitsynchronisation und eigener kompletter E-Mail-Dienst.

## 2. Historischer Entwicklungsphasenplan und Abhängigkeiten (bei Abweichung gilt Abschnitt 17)

**Prioritäten bedeuten Produktreife, nicht Wichtigkeit der Pädagogik.** Ein Baustein aus P3 kann vorgezogen werden, wenn die Schule ihn benötigt und seine technischen/rechtlichen Voraussetzungen erfüllt sind.

| Phase | Einsatz | Enthaltene Kernfunktionen | Noch bewusst nicht erforderlich | Grober **Zusatzaufwand** inkl. üblicher Tests |
|---|---|---|---|---:|
| **P0 – Core** | Einzelne Lehrkraft | Authentifizierung, Schuljahre, Klassen, Fachgruppen, SuS, CRUD, einfache CSV-Imports, Datenmigrationen, Berechtigungsgrundlagen, Backups | Elternkonten, komplexes RBAC-UI, Stundenplan-Solver | **20–35 h** |
| **P1 – Bewertung** | Einzelne Lehrkraft | Bewertungsraster, ICE, Einzelbeobachtung, Sitzplan, Kurznotizen, Beobachtungsvorschläge, Auswertungen, Grundnotenbuch mit Fach/Niveau/Punkten/Notenschlüssel, editierbare Feedbackvorlagen, dokumentierte Nachteilsausgleiche, Textentwürfe und Export | Stundenplan, offizielles Klassenbuch, Sekretariat, Push | **40–75 h** |
| **P2 – Lehrer-Cockpit** | Einzelne Lehrkraft | Persönlicher Stundenplan, Unterrichtstermine, Stoffverteilungsplan, persönliches Klassenbuch, eigene Anwesenheit, ausführliche Noten/Kompetenzanalyse, Klassenarbeiten, Feedback-PDF, Materialien | Schulweite Rollen/Elternzugänge, automatische Stundenpläne | **60–120 h** |
| **P3 – Schule & Portale** | Kollegium/Eltern/SuS/Sekretariat | ASV-Differenzimport, Schuljahreswechsel, Mehrbenutzerrechte, Schüler-/Elternkonten, Nachrichten, Push/Ruhezeiten, Formulare/Rückläufer, Krankmeldungen, Attestpflicht, Zahlungslisten | Automatische Lehrereinsatz-/Vertretungsoptimierung | **220–400 h** |
| **P4 – Planung** | Schulleitung/Planung | Kontingentstundentafel, Deputate, Lehrereinsatz, OR-Tools-Stundenplan, Vertretungen, Ausplanungen, Ausfall- und Personalprioritäten, Freigabe | Eigenständiges sicherheitskritisches Alarmsystem | **350–700 h** |

**Kumulierte Richtwerte:** P0+P1 ca. **60–110 h**; bis P2 **120–230 h**; bis P3 **340–630 h**; bis P4 **690–1.330 h**. Grundlage: vorhandene React-/PHP-Pakete, Claude Code und Graphify; Integration/Qualitätssicherung sind mitgeschätzt. Schulische Freigaben, Datenschutz-Folgenabschätzung falls notwendig, externe Audits, langfristige Wartung und mögliche Legacy-Integrationen sind nicht verlässlich darin abgebildet. **Hinweis zum Stand 25.10.2026:** Diese groben Zahlen stammen aus dem früheren Funktionsumfang. Die jetzt konkretisierten Erweiterungen (Methoden-Registry, niveaugebundene Mehrversuche, Notenschlüssel-/Feedback-Engine, Eintrags-/Publikationsrechte, Exportadapter) benötigen eine separate Aufwandsschätzung und sind in den Richtwerten noch nicht vollständig einkalkuliert.

### 2.1 Minimale Abhängigkeitskarte

```text
P0: Schule/Schuljahr ──> Klasse ──> Klassenmitgliedschaft ─┐
                       Fach ──> Fachgruppe ──> Mitgliedschaft ├──> P1: Beobachtung/ICE
                       Schüler-ID ──────────────────────────┘            │
                                                        Raster/Kriterien ├─> Auswertung/Textentwurf
                                                            Maßnahmen ───└─> Leistungsnachweis-Hinweise

P2: Wochenraster + Fachgruppe ─> Unterrichtstermin ─┬─> Klassenbuch
                                                   ├─> Anwesenheit
                                                   └─> Stoff-Ist/Material

P3: geprüfte Identitäten + Rechte ─┬─> Eltern-/Schülerportal/Kommunikation
                                  ├─> Formulare + Rückläufer + Zahlung
                                  └─> offizielle Fehlzeit/Attest/Sekretariat

P4: Unterrichtsbedarf + Lehrer + Räume + Zeitraster ─> Jahresplan ─┐
           Ausplanung + Ausfallregeln + Fairness + Verfügbarkeit ─────┴─> Vertretungsplan
```

**Architekturregel:** Abhängigkeiten zeigen nach innen auf gemeinsame Domänenobjekte, nicht horizontal auf fremde UI-Module. P1 darf z. B. nicht von einem im P2 entstehenden `lesson`-Datensatz abhängig sein: Beobachtungen erhalten zunächst ein Datum/eine Session; eine Unterrichtsstunde kann später optional verknüpft werden.

## 3. Vollständiger Funktionskatalog

Die folgende Liste ist bewusst umfassend; **Umsetzungstiefe und Zeitpunkt** stehen in der Spalte *Phase*. Die fachlichen Detail- und Akzeptanzkriterien folgen in den späteren Abschnitten.

### 3.1 Stammdaten, Identität, Klassen

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| CORE-01 | Schülerstammdaten | P0 | Eine Person erhält eine interne unveränderliche ID. Namen dürfen sich ändern, ohne Historie und Beobachtungen neu zuzuordnen. |
| CORE-02 | Klassen und Schuljahre | P0 | Klassen existieren pro Schuljahr; Mitgliedschaften haben Gültigkeitsdaten. Klassenwechsel überschreiben keine älteren Zuordnungen. |
| CORE-03 | Fächer und Fachgruppen | P0 | Bewertet wird in Fachgruppen/Kursen, nicht nur in Stammklassen. SuS dürfen mehreren Fachgruppen gleichzeitig angehören. |
| CORE-04 | Einfacher Schülerimport | P0 | CSV mit kontrollierter Spaltenzuordnung, Dry Run und Dublettenanzeige. Keine automatische Löschung beim Fehlen in einer Datei. |
| CORE-05 | Lehrkräfte/Deputate | P3/P4 | Lehrkräfte, Qualifikationen, Verfügbarkeit und Deputate sind getrennte Strukturen. Einschränkungen gelten zeitbezogen. |
| CORE-06 | ASV-BW-Import | P3 | Unterstütztes Exportformat einlesen und über stabile externe Kennungen abgleichen. Vor dem Commit wird eine Änderungsübersicht angezeigt. |
| CORE-07 | Schuljahreswechsel | P3 | Neue Klassen-, Kurs- und Schuljahresmitgliedschaften vorbereiten, Ausnahmen prüfen und freigeben. Wiederholer oder wechselnde Klassen werden nicht bloß anhand „+1 Jahrgang“ verschoben. |
| CORE-08 | Austritte und Rückkehr | P3 | Anmeldekonto zum Stichtag sperren und aktive Berechtigungen widerrufen. Daten unterliegen separaten Aufbewahrungs-/Löschregeln statt sofortigem Hard Delete. |
| CORE-09 | Schülerkonten/SSO | P3 | Vorhandenes schulisches Identitätssystem bevorzugen; ansonsten Einmalaktivierung statt gemeinsamer Standardpasswörter. Konten und fachliche Schüleridentitäten bleiben getrennt. |
| CORE-10 | Elternkonten | P3 | Ein eigenständiges Konto kann mit mehreren Kindern verbunden sein. Sorgerecht, Kontaktrecht und individuelle Datensicht dürfen nicht aus Name/Haushalt abgeleitet werden. |
| CORE-11 | Rollen/Delegation | P0 minimal, P3 voll | Rechte nach Rolle **und** tatsächlicher Zuständigkeit durchsetzen. Zugriffe auf sensible Datensätze serverseitig einzeln prüfen. |

### 3.2 Beobachtung und Leistungsbewertung

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| OBS-01 | Bewertungsraster | P1 | Raster pro Fach/Fachgruppe und Zeitraum mit Kriterien, Erklärung und drei Standardstufen. „Nicht beobachtet“ bleibt ein eigener Datenzustand ohne Leistungswert. |
| OBS-02 | Einzelmodus | P1 | Person wählen, ein oder mehrere Kriterien bewerten und Bemerkung anfügen. Ein Eintrag kann gezielt geändert oder zurückgenommen werden. |
| OBS-03 | ICE-Schnellerfassung | P1 | Am Stundenende zügig durch alle SuS gehen; Ziel 5–10 Sekunden je Person. Überspringen und Nachbearbeiten möglich, ohne implizite Bewertung. |
| OBS-04 | ICE im Sitzplan | P1 | Schüler per Platz auswählen und Bewertung auslösen. Daten bleiben identisch mit Listenmodus. |
| OBS-05 | Sitzplaneditor | P1 | Plätze/Tische konfigurieren, mehrfach speichern und den Schülern zuordnen. Nicht platzierte Personen bleiben über eine Liste erreichbar. |
| OBS-06 | Freitext/Kurzbausteine | P1 | Beobachtungen dürfen kurze sachliche Beispiele tragen. Arbeitsverhalten und Fachleistung bleiben getrennte Dimensionen. |
| OBS-07 | Beobachtungsfokus | P1 | SuS mit geringer Beobachtungsabdeckung, langer Beobachtungspause oder fachlichen Lücken vorschlagen. Die Lehrkraft kann den Vorschlag jederzeit ändern. |
| OBS-08 | Verteilung/Verlauf | P1 | Beobachtungen nach Kategorie, Zeitraum, Anzahl und Aktualität visualisieren. Bei geringer Datenbasis wird keine scheinbar präzise Gesamtbewertung ausgegeben. |
| OBS-09 | Notenbuch | P1 Basis, P2 erweitert | Leistungsnachweise mit Punkten, Notenschlüsseln, Teilbereichen und Gewichtungen dokumentieren. Endnoten sind kontrollierbare Vorschläge, keine rein automatische Festsetzung. |
| OBS-10 | Kompetenzanalyse | P2 | Aufgaben/Teilpunkte werden Kompetenzen zugeordnet. Auswertungen auf Individual- und Gruppenniveau nutzen korrekte Nenner und fehlende Ergebnisse. |
| OBS-11 | Schülerfeedback | P2 PDF, P3 digital | Individuelle Rückmeldung mit Aufgabenstärken, Lernzielen und Lehrerkommentar erstellen. Veröffentlichung erfolgt erst nach Freigabe und an die richtige Person. |
| OBS-12 | Zeugnistextentwürfe | P1 | Regelbasierte, nachvollziehbare Textvorschläge aus belegten Beobachtungen formulieren. Lehrkraft bestätigt und bearbeitet den endgültigen Text. |
| OBS-13 | Nachteilsausgleich | P1 Basis, P3 Verwaltung | Bereits beschlossene Maßnahmen mit Fach-/Prüfungsbezug und Gültigkeit abbilden. Erinnerung bei der Vorbereitung eines passenden Leistungsnachweises; keine automatische Diagnostik. |
| OBS-14 | Besondere Unterrichtshinweise | P2/P3 | Nur notwendige handlungsbezogene Hinweise berechtigten Lehrkräften anzeigen. Diagnosen und ärztliche Unterlagen nicht als allgemeine Profilfelder führen. |
| OBS-15 | Befristung/Überprüfung von Maßnahmen | P2/P3 | Ablauf, Verantwortlichkeit und Überprüfungstermin anzeigen. Veraltete Hinweise werden nicht ungeprüft auf neue Situationen übertragen. |
| OBS-16 | Weitere Bewertungsmethoden | P1 Basis, P2 erweitert | ICE/Einzel/wochenweise, Checkliste, Rubric, mündliche Abfrage, praktische Leistung, Kompetenzraster und Projekt als registrierte **Erfassungsstrategien** mit gemeinsamen Ergebnisverträgen; diagnostisch vs. benotet trennen. |
| OBS-17 | Individuelle Niveaustufen | P1 | Level-Sets und frei benennbare Niveaus je Schule/Fach (z. B. G/M/E oder F); offiziellen Bildungsgang/Niveaucode gesondert modellieren, nicht aus der Anzeige ableiten. |
| OBS-18 | Varianten und Versuche | P1 Datenmodell, P2 Workflow | Ein Leistungsnachweis kann Niveau-Varianten mit unterschiedlichen Maximalpunkten besitzen; SuS absolvieren null bis X Versuche, jeder mit eigenem Niveau, Datum, Status und Ergebnissen. |
| OBS-19 | Bestes Ergebnis je Niveau | P2 | Alle gültigen Versuche erhalten, **besten Versuch innerhalb eines Niveaus** nach festgelegter Vergleichsregel ableiten. Keine naive Auswahl über Niveaugrenzen hinweg; Aufstiegsregeln separat versionieren. |
| OBS-20 | Notenschlüssel-Editor | P1 | Notenschlüssel pro Fach/Niveau/Arbeit als versionierte Vorlage mit Prozent- oder Punktgrenzen, Auflösung der Note und Berechnungsregel; Punkte exakt berechnen, Prozent **als ganze Zahl** anzeigen. |
| OBS-21 | Leistungsarten und Gewichtungen | P1 Basis, P2 erweitert | Klassenarbeit, Test, Präsentation, Experiment, Projekt und schulisch benennbare Typen; getrennte Berechnungs- und Aggregationsregeln, endgültige Note mit Lehrkraftfreigabe. |
| OBS-22 | Feedbackvorlagen und Platzhalter | P1 | Persönliche Vorlagen mit `{thema}`, `{niveau}`, `{punkte}`, `{max_punkte}`, `{prozent}`, `{note}`, `{versuch}`, `{verbesserung_prozentpunkte}`; Auswahl, Vorschau und Bearbeitung direkt nach Erfassung. |
| OBS-23 | Ergebnisfreigabe und Portal | P1 Entwurf/PDF, P3 digital | Ergebnis und endgültig gerenderten Text nach expliziter Freigabe für betroffene SuS/EZB sichtbar machen; generische Push-Nachricht, keine Noten auf Sperrbildschirm. |
| OBS-24 | Niveaugebundene Mehrversuchsregeln | P2 | Strategien `single`, `best_of_n_per_level`, `sequential_levels`, `teacher_review` mit Grenzen/Freischaltschwellen; schulische Zulässigkeit separat prüfen. |
| OBS-25 | Projektformen / „Zeig, was du kannst!“ | P2 | Gruppenprojekt mit individuell zugeordneten Leistungen, gewähltem Fach und versionierter, schulrechtskonformer Bewertungsregel; Nachweise und Bescheinigungen aus Vorlagen. |
| OBS-26 | Zeugnisdaten und Adapter | P1 generisch, P3/P4 fachlich | Halbjahresnoten und editierte Texte über validierte CSV/XLSX/PDF-Vorbereitung; konkrete ASV-BW/WebUntis/weitere Adapter **nur nach bestätigtem Zielimportformat**, amtliche Vorlagen separat versionieren. |

### 3.3 Unterricht und Klassenbuch

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| LES-01 | Persönlicher Stundenplan | P2 | Wiederkehrende Stunden pro Wochentag und Zeitfenster erfassen oder importieren. Noch kein automatischer Solver erforderlich. |
| LES-02 | Unterrichtstermine | P2 | Aus dem Stundenplan konkrete Termine je Datum bilden und Feiertage/Ferien berücksichtigen. Jede Stunde besitzt eine eindeutige Identität und Zustand. |
| LES-03 | Stoffverteilungsplan | P2 | Lernbereiche mit Reihenfolge, Lernzielen und geplanten Stunden verwalten. Plan-/Ist-Fortschritt und Verschiebungen sichtbar machen. |
| LES-04 | Unterrichtsvorbereitung | P2 | Termin mit Ziel, Inhalt, Ablauf, Versuch, Material und Aufgaben verknüpfen. Geplante Inhalte können über mehrere Termine verteilt werden. |
| LES-05 | Materialverwaltung | P2 | Dateien und Links mit Einheiten verknüpfen, später NaWiShare-Referenzen nutzen. Schülerdaten nicht an NaWiShare übertragen. |
| LES-06 | Persönliches Klassenbuch | P2 | Behandelte Inhalte, Hausaufgaben und Besonderheiten aus dem Termin heraus speichern. Es ersetzt ohne Einführung/Abstimmung kein offizielles Klassenbuch. |
| LES-07 | Klassenbuchübersicht | P2 | Offen/gehalten/ausgefallen/verschoben sowie Dokumentationslücken filtern. Stunden nach Klasse, Lehrkraft, Fach und Thema durchsuchen. |
| LES-08 | Hausaufgaben | P2, P3 sichtbar | Aufgaben mit Fälligkeitsdatum und optionalem Material zuordnen. Externe Sichtbarkeit erfolgt erst mit passenden Zugangsrechten. |
| LES-09 | Klassenarbeiten-Kalender | P2, P3 gemeinsam | Tests und Arbeiten zeitlich planen und Konflikte markieren. Gemeinsame schulische Einschränkungen erst ab P3 durchsetzen. |
| LES-10 | Persönliche Anwesenheit | P2 | Zu einem Termin anwesend/fehlend/verspätet/ungeprüft dokumentieren. Diese Daten sind ohne schulweite Abstimmung keine automatische offizielle Fehlzeitenakte. |

### 3.4 Sekretariat, Krankmeldungen, Atteste

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| ATT-01 | Eltern-Krankmeldung | P3 | Sorgeberechtigte geben Zeitraum und notwendige Angaben über den geprüften Zugang an. Die Meldung ist **nicht** identisch mit einer Anwesenheitskontrolle. |
| ATT-02 | Offizielle Anwesenheitskontrolle | P3 | Lehrkraft bestätigt zum Unterrichtsbeginn den Anwesenheitsstatus. Nicht kontrolliert ist **nicht** „anwesend“ und nicht „fehlend“. |
| ATT-03 | Abgleich Meldung/Anwesenheit | P3 | Bestätigte Fehlzeiten mit vorliegenden Abwesenheitsmeldungen vergleichen. Ungeklärte Fälle entstehen nur aus tatsächlich festgestellter Abwesenheit. |
| ATT-04 | Meldung 1. Stunde | P3 | Ungeklärte Abwesenheiten nach definierter Kontrollfrist in eine Sekretariats-Queue geben. Dabei bestätigte Fehlzeiten von ausgebliebenen Lehrkraftkontrollen unterscheiden. |
| ATT-05 | Sekretariats-Dashboard | P3 | Kontaktversuche, Erledigungsstatus und Zuständigkeit verwalten. Offene Fälle nicht allein durch einen zugestellten Push als erledigt markieren. |
| ATT-06 | Attestpflicht | P3 | Schriftlich angeordnete Pflicht als zeitlich begrenzte schulische Maßnahme dokumentieren. Anordnung erfolgt durch zuständige Stelle und höchstens nach geltenden Vorgaben. |
| ATT-07 | Atteststatus | P3 | Offen/eingegangen/geprüft mit befugtem Zugriff speichern. Medizinische Details nur bei ausdrücklicher Notwendigkeit und gesondertem Schutz verarbeiten. |
| ATT-08 | Befreiung/Beurlaubung | P3 | Genehmigte Ausnahmen mit Zeitraum/Geltungsbereich verwalten. Anwesenheit und offizielle Schulbesuchspflicht korrekt unterscheiden. |
| ATT-09 | Fehlzeitenstatistik | P3 | Fehlstunden/Verspätungen/Entschuldigungsstatus getrennt auswerten. Sichtbarkeit ausschließlich für berechtigte Rollen. |

### 3.5 Kommunikation, Portale und Formulare

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| COM-01 | Sichere Nachrichten | P3 | Lehrkräfte, Eltern und berechtigte Stellen kommunizieren mit geprüften Empfängerbeziehungen. Sensible Inhalte bleiben im authentifizierten Portal. |
| COM-02 | Vorlagen/Seriennachrichten | P3 | Wiederverwendbare Texte und Gruppenversand mit Vorschau aller Empfänger. Jede Sendung muss freigegeben werden. |
| COM-03 | Gesprächsnotizen | P2 persönlich, P3 geteilt | Sachliche Gesprächsdokumentation, Vereinbarungen und Wiedervorlagen führen. Nur autorisierte Beteiligte erhalten Zugriff. |
| COM-04 | Elternsprechtag | P3 | Freie Slots zur Buchung anbieten und Doppelbuchungen verhindern. Stornierungen/Bestätigungen nachvollziehbar speichern. |
| COM-05 | Formularbaukasten | P3 | Fragen als Single-/Multiple-Choice, Text, Zahl, Datum, Bestätigung, bedingte Sichtbarkeit. Versionierte Formulare mit Frist, Zielgruppe und Antwortmodus. |
| COM-06 | Elternrückläufer | P3 | Kenntnisnahme, Zusagen, Rückfragen oder Angaben digital erfassen. Identifizierte Rückmeldung, anonyme Umfrage und rechtlich bindende Erklärung klar trennen. |
| COM-07 | Rücklaufübersicht/Erinnerungen | P3 | Fehlende Antworten zuordnen, Fristen/Status zeigen und Erinnerungen kontrolliert versenden. Verweigerte oder technisch ausgebliebene Antworten nicht erfinden. |
| COM-08 | Schülerumfragen | P3 | Unterrichts- oder Klassenarbeitsfeedback anonym oder personenbezogen konfigurieren. Anonymität darf nicht über indirekte Kennungen kompromittiert werden. |
| COM-09 | QR-Formularlinks | P3 | Sichere Formularaufrufe anbieten. Für rechtlich relevante personenbezogene Antworten ist eine angemessene Identitätsprüfung notwendig. |
| COM-10 | Benachrichtigungscenter | P3 | Ereignisse unabhängig von Push persistent im Nutzerpostfach anzeigen. Gelesen/ungelesen und technische Zustellstatus getrennt verwalten. |
| COM-11 | Web Push | P3 | PWA-Service-Worker, VAPID und Geräte-Abonnements implementieren. Nur generische Sperrbildschirmtexte ohne Noten/Gesundheitsdetails versenden. |
| COM-12 | Ruhezeiten und Sofortnachrichten | P3 | Normale Meldungen nach Benutzerzeitplan verzögern oder bündeln. Wichtige Nachrichten dürfen App-Ruhezeiten umgehen, nicht zuverlässig Geräte-Stummschaltung. |
| COM-13 | Notfallanbindung | Separates Schulprojekt | Anbindung an ein geprüftes schulisches Alarmierungssystem vorbereiten. TeacherOS-Web-Push darf keine alleinige Brand-/Gewaltalarmierung sein. |

### 3.6 Veranstaltungen und Zahlungen

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| EVT-01 | Veranstaltungen/Ausflüge | P3 | Veranstaltung mit Datum, Gruppen, Begleitpersonen und Informationen anlegen. Rückmeldungen, Zahlung, Ausplanung und Dokumente können dazugehören. |
| EVT-02 | Zahlungslisten | P3 | Lehrkräfte verwalten schulisch genehmigte Beiträge pro Vorgang/Kind. Listen haben Verantwortliche, Frist, Empfänger und Berechtigungen. |
| EVT-03 | SEPA-QR-Code | P3 | EPC-konformen GiroCode aus Kontoinhaber, IBAN, Betrag, Zweck generieren. TeacherOS löst keine Überweisung aus. |
| EVT-04 | Manuelle Zahlungseingänge | P3 | Offen, Teilzahlung, bezahlt, befreit, erstattet mit Betrag/Datum/Nachweisreferenz dokumentieren. Keine Bankanbindung oder automatische Zahlungsbehauptung. |
| EVT-05 | Abweichende Beiträge | P3 | Unterschiedliche Beträge und schutzbedürftige Ermäßigungsinformationen verwalten. Nur notwendige Personen erhalten Einsicht. |
| EVT-06 | Zahlungs- und Rücklaufexport | P3 | CSV/XLSX/PDF-Listen mit Berechtigungsprüfung bereitstellen. Exporte und deren Empfänger werden nach schulischer Vorgabe protokolliert. |

### 3.7 Stundenplan, Ausplanung und Vertretung

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| SCH-01 | Kontingentstundentafel | P4 | Je Schulart/Bildungsgang/Kohorte versionierte Vorgaben und schulinterne Verteilung erfassen. Stundenbedarf wird daraus abgeleitet; Vorgaben nicht hartcodieren. |
| SCH-02 | Unterrichtsbedarf | P4 | Anzahl Gruppen, Teilungen, Wahlpflicht, Förderangebote und Wochenstunden ermitteln. Ergebnis ist unabhängig vom fertigen Stundenplan prüfbar. |
| SCH-03 | Deputate/Lehrereinsatz | P4 | Lehrkräfte, Fachbefähigung, Ermäßigungen, Verfügbarkeit und Zuordnung zu Gruppen berücksichtigen. Abweichungen und Überlastungen anzeigen. |
| SCH-04 | Räume/Fachräume | P2 Stammdaten/P4 Solver | Räume mit Kapazität, Ausstattung, erlaubten Unterrichtsformen, Personal-/Qualifikationsanforderungen und gesperrten Zeiten verwalten; bei Ungeeignetheit automatisch sichere Ersatzräume einschließlich KlaZi prüfen. |
| SCH-05 | Jahresstundenplan-Solver | P4 | Unterrichtseinheiten konfliktfrei über Lehrkräfte, Klassen, Räume und Slots verteilen. Harte Regeln erzwingen, weiche Qualitätsziele bewerten. |
| SCH-06 | Kopplungen/Bänder/Teilgruppen | P4 | Parallel zu planende Kurse, Teilungen und gebundene Stunden modellieren. Anforderungen fließen direkt in den Solver ein. |
| SCH-07 | Editor/Varianten | P4 | Vorschläge vergleichen, Stunden fixieren und manuell verschieben. Jede Änderung wird auf Konflikte geprüft. |
| SCH-08 | Entwurf und Sammelveröffentlichung | P4 | Ausschließlich bearbeitbarer Entwurf und veröffentlichter Stand. Validierungs-/Freigabeprüfungen sind Aktionen, keine zusätzlichen Zustände. Nur explizite manuelle Gesamtveröffentlichung erzeugt Benachrichtigungen. |
| SCH-09 | Lehrkräfte-Ausplanung und Verlängerung | P3 Stammdaten/P4 Planung | Abwesenheiten mit Kategorie, Zeitraum, Sichtbarkeit und Historie erfassen, **verlängern, verkürzen, korrigieren**. Folgen für zukünftige Stunden, Aufsichten, Teamstunden und Entwurf automatisch neu ermitteln; keine Dubletten. |
| SCH-10 | Klassen-/Teilgruppen-Ausplanung | P4 | Klassen/Teilgruppen bei Ausflug/Veranstaltung ausplanen. Freigewordene Kapazitäten korrekt erkennen. |
| SCH-11 | Begleitpersonen | P4 | Begleitende Lehrkräfte einer Veranstaltung zuordnen. Auswirkungen auf deren sonstigen Unterricht in Vertretungsbedarf überführen. |
| SCH-12 | Vertretungssolver | P4 | Erforderliche Vertretungen nach zulässiger Verfügbarkeit und Regeln vorschlagen. Bei Unlösbarkeit Konflikt samt Begründung anzeigen. |
| SCH-13 | Faire Belastungsverteilung | P4 | Zusätzliche Vertretungen nach Deputat, bisherigen Einsätzen, tatsächlicher Zusatzbelastung und Schutzregeln ausgleichen. Interne Fairness nicht mit rechtlicher Mehrarbeitsabrechnung gleichsetzen. |
| SCH-14 | Vertretungsquellen-Reihenfolge | P4 | Schule legt die Quellenreihenfolge fest (z. B. Unterrichtsausfall, Reserve, Teamstunde, freie Stunde, Zusammenlegung). **Innerhalb geeigneter freier Lehrkräfte Minusstundensaldo priorisieren**, Fachbefähigung `aus/bevorzugt/zwingend`, fairer Personaleinsatz; zwingende dienstliche Grenzen haben Vorrang. |
| SCH-15 | Ausfallpriorisierung | P4 | Regelwerk, welche Angebote bei Engpässen unter Beachtung verbindlicher Pflichten eingeschränkt werden dürfen. Keine automatische pauschale Ausfallreihenfolge als angebliche Rechtsvorschrift. |
| SCH-16 | Planung, Veröffentlichung, Statistik | P4 | Entwurf beliebig optimieren; bei manueller Änderung restliche Planung sofort validieren und bedarfsweise neu lösen; veröffentlichte Version bleibt gültig. Nur manuelle Sammelveröffentlichung benachrichtigt Betroffene; tatsächliche Vertretung, Ausfall, Aufsicht und Veranstaltung getrennt erfassen. |

| SCH-17 | Teilgebundene Fachbefähigung | P4 | Qualifikationen und Unterrichtseignung je Fach/Tätigkeit zeitlich führen; Schule kann Ignorieren, Bevorzugen oder hartes Erfordernis wählen. Sicherheitsrechtliche Qualifikation bleibt unabhängig davon hart. |
| SCH-18 | Blockvertretung | P4 | Zusammenhängende Doppel-/Mehrfachstunden durch eine geeignete Lehrkraft bevorzugen; weiches Ziel unter Beachtung anderer Engpässe und Prioritäten. |
| SCH-19 | Dynamische Reoptimierung | P4 | Jede manuelle Änderung im Entwurf löst Konfliktprüfung für den gesamten betroffenen Zeitraum und auf Wunsch inkrementellen Solver-Lauf aus; manuell fixierte Auswahl bleibt Constraint. |
| SCH-20 | Raumersatz und Raumtausch | P4 | Freies geeignetes KlaZi bevorzugen, dann alternative Räume, benachbarte Räume, zulässigen Tausch und angepasste Unterrichtsform; vollständige Erklärung. |
| SCH-21 | Parallele Fachgruppen | P4 | Benachbarte Fachgruppen und gemeinsame Organisation prüfen, aber fachpraktische Tätigkeiten nur bei vorhandener erforderlicher Fachaufsicht zulassen. |
| SCH-22 | Pausenaufsichten | P4 | Pausenbereiche, Sichtachsen, Gefahrenpunkte und geeignete Aufsichtspersonen planen, vertreten und nur bei zulässiger Abdeckung zusammenlegen. |
| SCH-23 | Rückverfolgung der tatsächlichen Durchführung | P3/P4 | Sollstunden, veröffentlichte Planänderungen, stattgefundene Stunden und geleistete Aufsicht getrennt dokumentieren. |
| SCH-24 | Schulveranstaltungen im Stundenkonto | P3/P4 | Eine Klassenfahrt oder ein Projekttag ersetzt im Plan betroffene reguläre Unterrichtstermine; Lehrkraftbegleitung, entfallende Stunden und dienstrechtliche Anrechnung bleiben gesondert. |
| SCH-25 | Erklärbare Variantenplanung | P4 | Zulässige Alternative samt Änderungsdiff, betroffenen Personen, Fairness, Raumqualität, Unterrichtsabdeckung und Stabilitätsbewertung anzeigen. |

### 3.8 Einheitliche Einträge, Eintragsarten und Sichtbarkeit

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| ENT-01 | Fachbezug oder Allgemein | P0/P1 | Pädagogische schülerbezogene Einträge haben `subject_id` oder bewusst `scope=general`; Einträge aus Unterrichtsterminen übernehmen automatisch das Unterrichtsfach, ein zulässiger Fachwechsel muss bewusst und serverseitig geprüft erfolgen. |
| ENT-02 | Schulisch benannte Eintragsarten | P0 Vertrag/P1 UI | Schule kann z. B. „Mitarbeit“, „Lob“, „Ermahnung“, „Kurztest“ definieren; stabile technische Schlüssel von veränderlichen Labels trennen, Typen nach Nutzung nicht hart löschen. |
| ENT-03 | Getrennte Sichtbarkeit | P0 Modell/P1 Editor/P3 Portal | Zugriff für berechtigte Lehrkräfte sowie Veröffentlichung an betroffene SuS und berechtigte EZB getrennt steuern; intern als Default, `published_at` plus Freigabe-/Widerrufsstatus, Sensibilitätsregeln begrenzen Wahlfreiheit. |
| ENT-04 | Eintragstyp-Vorgaben | P1/P3 | Je Eintragsart Felder, Default-Sichtbarkeit, Geltungsbereich, erlaubte Methoden und Freigabeprozess hinterlegen; spätere Änderungen durch Versionierung schützen. |
| ENT-05 | Änderungs- und Veröffentlichungsverlauf | P1/P3 | Erstellen, Bearbeiten, Freigeben und Zurückziehen mit verantwortlicher Person, Zeitpunkt, Version und betroffenen Adressaten protokollieren; technische Zustellung ist keine bestätigte Kenntnisnahme. |

**Grenze des gemeinsamen Eintragsvertrags:** Gilt für pädagogische Schülerakteneinträge und entsprechende Feedback-/Klassenbuchbezüge. Zahlungsbuchungen, Authentifizierung, Notfallereignisse usw. haben eigene Domänenmodelle und dürfen nicht künstlich zu einem generischen Schüler-Eintrag reduziert werden. Einträge können per `student_id` Einzelpersonen oder über eine eigene Zuordnung mehreren Personen gelten; direkte Eltern-/Schülersicht erfordert stets ausdrückliche Zielperson-/Rechteprüfung.

### 3.9 Temporäre Unterrichtszuordnung und Anwesenheit

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| ATT-10 | Schüler temporär aufnehmen | P2/P3 | SuS zeitlich begrenzt aus eigenem regulärem Unterricht in eine andere Stunde übernehmen (Nachschreiben, Förderung, Prüfung); ursprünglicher Stundenplan bleibt bestehen. |
| ATT-11 | Präsenz an anderem Ort | P2/P3 | Ursprungskollege sieht nach Bestätigung „anwesend – anderer Unterricht“ und optional berechtigt Ort/aufnehmende Lehrkraft; **keine falsche Abwesenheit**. |
| ATT-12 | Unterrichtsbeginn-basierte Sekretariatsfrist | P3 | Bei tatsächlich ungeklärtem Fehlen nach bestätigter Kontrolle Meldung sofort oder `X` Minuten **nach Stundenbeginn**; Nachzügler/Bestätigungen vor Frist verhindern Meldung. |
| ATT-13 | Tagesabwesenheit vorläufig | P3 | Nicht anwesend führt zu vorläufigem Abwesenheitsfall für den Tag, nicht zu irreversibler Ganztagesfeststellung; spätere Anwesenheit, Entschuldigung und Zeitabschnitte korrigieren. |
| ATT-14 | Konflikt bei Doppelzuordnung | P2/P3 | Überschneidende temporäre Zuordnungen, doppelte Anwesenheitsbestätigungen und konkurrierende Stunden sichtbar machen und konfliktarm auflösen. |

### 3.10 Lehrkräfteabwesenheiten, Veranstaltungen und Analytics

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| HR-01 | Konfigurierbare Fehlzeitenarten | P3/P4 | Schule benennt Kategorien (z. B. Krankheit, Fortbildung, Dienstgang), legt Berechtigungen/Sichtbarkeit/Statistikzuordnung fest und historisiert Änderungen. |
| HR-02 | Abwesenheitszeitraum verlängern | P3 Modell/P4 Integration | Bestehende Abwesenheit verlängern, ohne zweite Fehlzeit anzulegen; zusätzliche betroffene Termine/Aufsichten erkennen, vollständige Änderungshistorie. |
| HR-03 | Abwesenheit verkürzen/berichtigen | P3/P4 | Endzeitpunkt vorziehen, Teilbereiche korrigieren; zukünftige Vertretungsbedarfe und deren Zuordnungen im Entwurf neu prüfen. |
| HR-04 | Mehrtägige und partielle Fehlzeiten | P3/P4 | Zeitfenster, ganztägig, einzelne Stunden, Wiederholung oder Zeitraum mit Ausnahmen; Kalender- und Zeitzonengrenzen explizit. |
| EVT-07 | Konfigurierbare Eventarten | P3 | Klassenfahrt, Exkursion, Projekttag, Fortbildung etc. mit schulisch definierten Kategorien, personal- und klassenbezogenen Auswirkungen. |
| EVT-08 | Teilnehmer-/Ressourcenbindung | P3/P4 | Begleitende Lehrkräfte, betroffene Lerngruppen, Räume und Zeitintervalle; entfallender Eigenunterricht gibt nicht gebundene Lehrkräfte ggf. frei. |
| ANA-01 | Schuljahresstatistik | P4 | Statistik über geplante, tatsächlich durchgeführte, vertretene, verlegte, anders durchgeführte und entfallene Stunden; Kategorien nach Ursache und Auswirkung trennen. |
| ANA-02 | Lehrkräfte-/Fach-/Klassenstatistik | P4 | Berechtigte Auswertungen nach Personal, Fach, Klassen, Aufsicht, Veranstaltungsart und Zeitraum. |
| ANA-03 | Wiederholbare Jahresberichte | P4 | Filter-/Regelversions-Snapshot zum Stichtag; korrigierte Jahresberichte als neue Revision, ältere weiter prüfbar. |
| ANA-04 | Exporte CSV/XLSX/PDF | P4 | Berechtigungsabhängige Rohdaten und aggregierte Statistiken, nachvollziehbare Zähler und Nenner, Dateiaudit. |

### 3.11 Geräte und Benachrichtigungen

| ID | Feature | Phase | Fachliche Anforderung |
|---|---|---|---|
| NOT-01 | Standardnachrichten | P3 | Chats, Klassenbuch, veröffentlichte Leistungs- und Vertretungsinformationen mit Empfängerscope und Ruhezeiten; bevorzugt gebündelt. |
| NOT-02 | Native Pushadapter | P3/P4 | Gleiche React-Anwendung optional über Capacitor für iOS/Android; Windows zunächst PWA; Kanalinterface kapselt plattformspezifische Zustellung. |
| NOT-03 | Kritischer Alarm | Separates Sicherheitsprojekt | Streng autorisierter Alarm mit Bestätigung/Eskalation und unabhängigem geprüften Alarmweg; lauten Ton oder DND-Bypass nie universell garantieren. |
| NOT-04 | Keine Entwurfs-Pushs | P4 | Solverläufe, Raumwechsel und manuelle Änderungen im Entwurf lösen keinerlei Kollegiumsbenachrichtigung aus; erst `SubstitutionPlanPublished` versendet einen gruppierten Änderungsdiff. |

## 4. Technische Zielarchitektur

### 4.1 Stack und Komponenten

| Schicht | Geplante Technologie | Zweck / Begründung |
|---|---|---|
| Frontend | React + TypeScript + Vite | Komponentisierte responsive Desktop-/Mobile-UI; vorhandene `@hoelzle/react-ui` wiederverwenden. |
| Backend | PHP 8.4+/8.5+, REST-API | Fachliche Geschäftslogik, Authentifizierung, Autorisierung, Validierung und Transaktionen; vorhandene PHP-Pakete wiederverwenden. |
| Primäre Datenbank | MariaDB | Relationale Konsistenz, Fremdschlüssel, Transaktionen, Indizes und versionierte Migrationen. |
| Async/Jobs | DB-Outbox + Worker; Redis optional | Versand, Exporte, Importjobs und Benachrichtigungen zuverlässig entkoppeln. Redis für Cache/Queues erst bei tatsächlichem Bedarf. |
| Dateispeicher | Privater, geschützter Storage | Material/Exports/ggf. Nachweise; nie unter frei auslieferbarem Webroot. |
| PWA / Windows | Manifest + Service Worker + Web Push (P3) | Windows primär installierbare PWA; Browser-Einschränkungen und Benachrichtigungseinstellungen respektieren; keine sensiblen Nutzdaten standardmäßig offline cachen. |
| Mobile Wrapper (optional) | Capacitor + native Pushadapter (P3/P4) | iOS/Android mit derselben React-Codebasis; keine duplizierte Fachlogik, lediglich plattformbezogene Adapter für Berechtigung, Token und Zustellung. **[Überholt durch 17.16 / Rev. 1]** Native Hülle für iOS/Android ist V1-Pflicht, nicht optional (17.11, 17.16 Nr. 4). |
| Optimierung | Python + OR-Tools CP-SAT (P4) | Komplexe Jahresstundenplan- und Tagesvertretungsmodelle als interner Dienst. |
| Deployment | Docker, **eigener Stack pro Schule**, getrenntes Dev/Test/Prod | Gleiche versionierte Images; pro Schule eigene Laufzeit, Datenbank, Secrets, Storage, Backups und Domain. |
| Tests | PHPUnit, React-Unit/Component-Tests, Playwright | Fachregeln, Berechtigungen und reale Bedienabläufe automatisiert absichern. |
| Codeverständnis | Claude Code + Graphify | Ticketweise Implementierung, Abhängigkeitsprüfung; **keine** automatische fachliche Abnahme. |

**Empfohlener Start: modularer Monolith statt Microservices.** Ein PHP-Backend mit klaren Fachmodulen, einer MariaDB und einer React-PWA ist für P0–P3 ausreichend. Später nur CPU-intensive/algorithmische Teile (Solver) und gegebenenfalls Queue-Worker auslagern. Jede Schule betreibt **eine eigene Instanz desselben modularen Monolithen**; kein gemeinsames mandantenübergreifendes Schülerdatenbankschema.

### 4.2 Modulstruktur (Vorschlag)
> **[Überholt durch 17.16 / Rev. 1]** Kein Monorepo: getrennte Repositories gemäß 17.12 / 17.16 Nr. 3. Die Ordnerstruktur innerhalb von `api/` bzw. `web/` bleibt als Vorschlag für das jeweilige Repository gültig.

```text
teacheros/
  apps/
    web/                       # React, TypeScript, Vite, PWA
      src/
        app/                   # Router, Navigation, Theme, Query-Client
        features/
          students/ classes/ groups/ assessments/ observations/
          seating/ grades/ measures/ lessons/ curricula/ attendance/
          messaging/ forms/ payments/ scheduling/ substitutions/
          events/ rooms/ supervision/ teacher-absences/ analytics/
        shared/
          api/ components/ hooks/ auth/ validation/
    api/                       # PHP
      src/
        Modules/
          Identity/ School/ Students/ TeachingGroups/
          Observation/ Assessment/ Measures/
          Curriculum/ Lessons/ Attendance/
          Communication/ Forms/ Payments/ Notifications/
          Events/ TeacherAbsences/ Rooms/ Supervision/ Analytics/
          Timetable/ SubstitutePlanning/
        Shared/
          Database/ Security/ Events/ Jobs/ Export/
      migrations/
      tests/
    solver/                    # Erst P4: OR-Tools in Python
      models/ year_timetable/ substitutions/ tests/
  packages/                    # Nur tatsächlich wiederverwendbare Pakete
  docs/
    decisions/                 # ADRs: Architekturentscheidungen
    api/                       # OpenAPI
    product/                   # Fachspezifikation, Phasen, Akzeptanzkriterien
  infra/                       # Docker-Compose, CI, Monitoring, Backup
    deployment/                # Schul-Templates und später Provisionierungsautomatik
    instances/                 # Keine echten Schul-Secrets ins Repository
```

**Modulkontrakte:** Jeder Modul-Service kapselt Regeln, validiert die Nutzerberechtigung und besitzt seine Tabellen/Repository-Adapter. Zwischen Modulen nach Möglichkeit über IDs, fachliche Services und veröffentlichte Domain-Events kommunizieren; kein direkter Zugriff eines Frontend-Features auf beliebige Tabellen.

### 4.3 Daten- und API-Konventionen

- Technische IDs: UUID/ULID oder stabile BigInt-IDs; **nie** Klassenname+Schülername als Primärschlüssel.
- Alle Datensätze mit `created_at`, `updated_at` und optional `version`; `created_by`, `updated_by` bei fachlich sensiblen Änderungen.
- Gültigkeitszeiträume als `valid_from`/`valid_until` (exclusive upper bound bevorzugt) und **von** Änderungszeitpunkten unterscheiden.
- Schuljahre, Kurszuordnungen, Maßnahmen und Regelkonfigurationen werden historisiert, statt rückwirkend überschrieben.
- Geld als ganzzahlige Cent-Beträge und ISO-Währung; Zeiten mit Zeitzonenregel (lokaler Schulkalender Europe/Berlin), API-Zeitstempel in UTC.
- Fremdschlüssel, Unique Constraints und Transaktionen, besonders für Import, Zahlungen, Veröffentlichungen, Jahreswechsel.
- Seitenweise APIs für große Listen (`limit`, `cursor` oder stabile Sortierung); serverseitige Suche/Filter, kein Komplettladen der Schule in den Browser.
- Dokumentierte Fehlercodes und OpenAPI-Vertrag; `Idempotency-Key` bei gefährlichen Wiederholungen (Import-Commit, Auslösung von Sendungen, Publikation).
- Optimistisches Sperren über Versionsnummer bzw. `If-Match`, um parallele Bearbeitungen nicht stillschweigend zu überschreiben.
- Dateiuploads: MIME-/Dateitypprüfung, Größenlimits, Malware-Scan falls vorgesehen, Virenschutz-/Quarantäneprozess, Zugriff stets über autorisierte Route.
- Bei Security-Ereignissen nachvollziehbare Metadaten loggen, aber keine Passwörter, Nachrichtentexte, medizinischen Notizen oder Push-Schlüssel in Logs schreiben.

### 4.3.1 Fachliche Erweiterungspunkte statt zentraler Spezialfälle

- Module veröffentlichen **Capabilities** (`assessments.write`, `feedback.publish`, `exports.report`), statt auf konkrete Implementierungs- oder Paketnamen angewiesen zu sein. Schnittstellen sind explizit und versioniert.
- Bewertungsmethode (`AssessmentMethod`), Versuchsregel (`AttemptSelectionStrategy`), Notenberechnung (`GradingPolicy`), Feedback-Renderer (`FeedbackTemplateRenderer`) und Zeugnisexport (`ReportExportAdapter`) sind **getrennte Strategien** mit gemeinsamem Datenvertrag. Erweiterungen werden über eine kontrollierte Registry registriert; verfügbare Methoden hängen von Modulfreischaltung, Schulkonfiguration und Benutzerrechten ab.
- Schuleigene **Konfigurationen/Vorlagen** dürfen ohne Codeänderung neue Eintragsarten, Kriterien, Niveau-Namen, Notenschlüssel, erlaubte Versuchsanzahl, Regeln und Textbausteine definieren. Ganz neue **Berechnungsalgorithmen** werden hingegen als geprüfte, versionierte Codekomponenten implementiert, getestet und ausgerollt – kein frei ausführbares Skript aus der Admin-Oberfläche.
- Neue Methoden dürfen bestehende historische Ergebnisse nicht neu interpretieren: Jeder Nachweis referenziert `method_key`, `method_version`, `policy_version`, `schema_version` sowie Snapshot der relevanten Bewertungsparameter; bestehende Einträge bleiben lesbar oder werden explizit migriert.
- Auch Erweiterungen erhalten dieselben nicht umgehbaren **Berechtigungen, Auditierung, Sensibilitätsstufen, Versionierung und Test-Hooks**; keine Plugins, die über direkten Datenbankzugriff fachliche Grenzen umgehen.


### 4.4 Instanz-, Domain- und Markenstrategie (verbindliche Architekturentscheidung)

**Technischer Arbeitstitel:** `TeacherOS` bleibt bestehen (`teacheros` als interner Repository-/Dienstname ist zulässig). **Öffentliche Marke:** noch offen; vor Vermarktung einen eigenständigen Namen mit verfügbarer, markenrechtlich geprüfter `.de`-Domain wählen. Der konkrete öffentliche Markenname ist **Konfiguration** und darf nicht hart in Anwendungscode, Mandantenkennung, Links oder Templates einprogrammiert werden. Ein späteres Rebranding soll keine Migration fachlicher Schülerdaten erfordern.

**Zielbild:** `schule-a.<marke>.de`, `schule-b.<marke>.de`, optional `admin.<marke>.de` für eine *separate* technische Verwaltung. Die Domains sind **Muster**, keine bereits registrierten Domains. `www.<marke>.de` ist die öffentliche Informationsseite; jede Schul-Subdomain zeigt auf eine eigenständige Anwendungsinstanz. Ein gemeinsamer Reverse Proxy darf anhand des Hostnamens routen, aber nicht selbst per Client-Parameter auswählen, auf welche Datenbank eine Anfrage zugreift.

| Ebene | Isolation / Ausführung pro Schule | Unbedingt beachten |
|---|---|---|
| DNS und TLS | Eigene Subdomain; pro Schule ein gültiges Zertifikat oder zentral verwaltete Zertifikate | Domainvalidierung und zuverlässige Zertifikatserneuerung; Wildcard-DNS ist möglich, keine Sicherheitsgrenze |
| React-PWA | Gleicher Buildstand, Auslieferung pro Schul-Ursprung | Service-Worker-Scope strikt hostbezogen, absolute Links aus vertrauenswürdiger Server-Konfiguration |
| PHP-API | Eigener Service/Container mit Schul-Konfiguration | Keine dynamische DB-Wahl anhand unvalidierter Header/URL-/Request-Parameter |
| MariaDB | Eigene **Datenbankinstanz** oder separat isolierter DB-Service mit eigenem Benutzer | Kein schulübergreifender DB-Account; klar getrennter Restore und Migrationen |
| Datei-/Export-Storage | Separates Volume/privater Bucket/Präfix mit eigenen Zugriffsdaten | Keine globalen Public URLs für personenbezogene Dateien |
| Redis/Queues/Jobs | Bevorzugt getrennte Instanz/Queue und Worker-Scope; erst bei Bedarf | Keys, Queue-Namen und Dead-Letter-Queues dürfen keine Schule vermischen |
| Auth/Cookies | Getrennte Sessions und schulspezifische Schlüssel | **Host-only-Cookies** ohne `Domain=.marke.de`, `Secure`, `HttpOnly`, `SameSite`; keine unkontrollierten Cross-School-SSO-Sessions |
| Push | Abos, Schlüssel und Zustellung instanzbezogen | Keine zentralen Push-Payloads mit Schülerdaten oder schulübergreifende Subscriber-Listen |
| Backups/Logs | Getrennte Backups, verschlüsselter Zugriff, schulbezogene Lösch-/Restore-Prozesse | Zentrales Monitoring nur mit notwendigen technischen Metadaten; Logs datensparsam |
| Solver (P4) | Eigener Job pro Schule oder isoliert ausgeführter Rechenauftrag | Ausreichende Ressourcen- und Job-Isolation; fremde Eingangsdaten nie im selben Job-Kontext mischen |

**Sicherheitsentscheidung:** Separate Subdomains sind nur Namen und **keine** technische Isolation. Ein Fehler in Reverse Proxy, Cluster-Rechten, gemeinsamem Operator-Zugang, Backup-Prozess oder Logging kann dennoch mehrere Schulen betreffen. In P0 mit 1 Testinstanz beginnen; vor dem Betrieb einer zweiten Instanz Isolation, Restore und Cross-School-Angriffe testen.

**Keine generelle Pflicht zu `school_id` in sämtlichen Fachtabellen:** Jede Produktionsdatenbank gehört genau einer Schule. Das Instanz-Metadatum `instance_id`/`school_code` wird in Konfiguration, Deploy-Manifest, technischem Betrieb und Auditkontext geführt. Bei einem späteren echten Shared-DB-Multi-Tenant-Ansatz müsste die Architektur neu bewertet werden; dieser Wechsel ist *kein* Ziel von P0.

**Domain und Datenschutzpositionierung:** `.de` ist als Außenauftritt gewünscht, **nicht** als Nachweis eines Datenstandorts. Datenschutzinformationen sollen den tatsächlich eingesetzten Hostingstandort, Backupstandort, Dienstleister, Datenflüsse und vertragliche Verantwortlichkeiten transparent ausweisen. „Serverstandort Deutschland“ nur bewerben, wenn dies einschließlich der hierfür relevanten Datenverarbeitung nachprüfbar ist; kein pauschales „100 % DSGVO-konform“.

### 4.5 Modularisierung, Feature-Freischaltung und spätere Lizenzierung

Die App bleibt **eine Codebasis** mit fachlich gekapselten Modulen. **Kein** `teacheros-schule-a`-Fork, keine unabhängigen Softwarestände aufgrund schulischer Sonderwünsche. Das Modulverzeichnis ist eine gemeinsame Registry aus Code und eindeutigen Modulkennungen; pro Instanz gilt eine **serverseitige Entitlement-Konfiguration**.

| Interne Modul-ID | Produktbereich | Abhängigkeiten | Geplante Phase |
|---|---|---|---|
| `core` | Stammdaten, Auth, Rollen-Grundlagen, Schuljahre, Fachgruppen | keine; immer aktiv | P0 |
| `observe` | ICE, Beobachtungen, Sitzplan, Auswertung | `core` | P1 |
| `grade` | Leistungen, Noten, Maßnahmen, Textbausteine und Feedback | `core` (optionale Integrationen zu `observe`) | P1/P2 |
| `class` | Persönlicher Stundenplan, Stoffverteilung, Klassenbuch | `core` | P2 |
| `attendance` | Anwesenheit, Krankmeldungen, Attestverwaltung | `core`; Unterrichtstermine aus `class` oder eigener Terminquelle | P2/P3 |
| `connect` | Eltern-/Schülerkommunikation, Postfach, Push | `core` + verifizierte Kontakte/Identitäten | P3 |
| `forms` | Umfragen und Elternrückläufer | `core`; `connect` nur für integrierten Versand erforderlich | P3 |
| `finance` | Zahlungslisten, SEPA-QR, manueller Status | `core`; optionale Verbindung zu `forms` | P3 |
| `planner` | Kontingent, Deputate, Jahresstundenplan | `core`, Planungsstammdaten, Solver | P4 |
| `substitute` | Ausplanungen, faire Vertretung, Prioritäts- und Freigaberegeln | `core`, gültiger Stundenplan aus `planner` **oder** freigegebenem Import | P4 |

**Fachliche Modulabhängigkeiten sind keine starren Paketbündel:** Wo sinnvoll, akzeptieren Module minimale Datenverträge statt fremde Vollmodule (z. B. `substitute` mit extern importiertem Stundenplan). Abhängigkeiten deklarieren, vor Aktivierung prüfen und bei Deaktivierung Warnungen/Blocker ausgeben.

**Prüfreihenfolge eines Requests:** `authentifiziert` → `Instanz/Scope bekannt` → `Modul-Entitlement aktiv` → `Operation laut Rolle/Objektbeziehung erlaubt` → `Fachregel erfüllt`. UI-Menüpunkte auszublenden genügt nicht: Routen, API, Jobs, Exporte und Events benötigen dieselbe Freischaltungs-/Rechteprüfung. Technische Administratoren einer Schule erhalten dadurch nicht automatisch Zugriff auf vertrauliche pädagogische Inhalte.

Beispiel für eine **instanzlokale** Konfiguration (kein öffentlicher Brandingname fest verdrahtet):

```yaml
instance:
  code: schule-a
  public_base_url: https://schule-a.<marke>.de
  display_name: Beispielschule
modules:
  core: true
  observe: true
  grade: true
  class: false
  attendance: false
  connect: false
  forms: false
  finance: false
  planner: false
  substitute: false
```

**P0 implementieren:** Registry, `ModuleGate`, versionierte Konfiguration/Entitlements, einfache Abhängigkeitsprüfung, serverseitige `403/404`-Strategie und Tests. **Nicht in P0:** Bezahlportal, Rechnungsstellung, verteilte Lizenzprüfung oder zentraler Lizenzserver. Die Freischaltungen können zunächst durch die Administratorin/den Administrator der Installation verwaltet werden; bei späteren kostenpflichtigen Lizenzen ist die technische und vertragliche Zuordnung zu klären.

**Daten nach Deaktivierung:** Nicht ungeprüft löschen. Der rechtmäßige Zugriff und die weitere Aufbewahrung richten sich nach Zweck, Rechtsgrundlage, Löschfristen, Exportpflichten und Vertragslage; Module dürfen Daten nicht aus bloßen Lizenzgründen vernichten. Vor Deaktivierung Abhängigkeiten, aktive Workflows und Aufbewahrung prüfen.

**Kostenmodell später:** Ein Grundpreis für Core/Instanz plus optionale Module/Module-Bundles ist denkbar; keine Preise festgelegt. Operative Kosten getrennt erfassen (DB-Grundlast, Backups, Dateiablage, Nachrichten-/Push-Aufwand, Solver-CPU und Support); deaktivierte Standardmodule sparen nicht automatisch proportional Infrastrukturkosten. Lizenzberechtigung und personenbezogene Zugriffsberechtigung bleiben strikt getrennt.

### 4.6 Reproduzierbares Deployment und späteres Hosting-Management

**Jetzt (P0):** Ein Compose-Stack für **eine** Entwicklungs-/Testschule mit synthetischen Daten, separatem `dev`/`test`/`prod`-Konfigurationssatz, versionierten DB-Migrationen, Secret-Injektion und dokumentiertem Backup-/Restore-Verfahren. Docker-Images sind ohne schulabhängige Codeänderungen erneut deploybar. Ein erster lokaler Schul-Code ist nur eine Konfigurationskennung, keine neue Codebasis.

**Bei zweiter Schule:** Ein Provisionierungsprozess erzeugt nach Freigabe Subdomain/TLS, eigenen App-Stack, DB, Zugänge, Schlüssel, Volumes, Backups und Monitoring. Ein neues Deployment nutzt die **gleiche geprüfte Releaseversion**; bei Fehlern wird nur die betroffene Schulinstanz zurückgenommen, soweit schema- und datenseitig möglich.

**Bei vielen Schulen:** Ein getrenntes **Control Plane** verwaltet rein technische Metadaten (`instance_id`, Domain, Tarif/Module, Version, Bereitstellungsstatus, Ressourcen, Wartungsfenster, Backupstatus); **keine** zentrale Schüler-/Noten-/Nachteilsausgleichdatenbank. Schulinstanzen sollen trotz Ausfall des Control Plane, soweit betrieblich möglich, weiterarbeiten können. Zentraladministrationsrechte minimal halten und protokollieren.

**Releasefolge:** CI-Tests → unveränderliches, signiertes/versioniertes Image → Testinstanz → Migration/Smoke-/Isolationstests → gestaffelter Rollout → Monitoring. Migrationen laufen **pro Instanz** mit eigenem Status; gleichzeitiges blindes Aktualisieren sämtlicher Schulen vermeiden. Restore und Rollback *real* prüfen, insbesondere bei nicht rückwärtskompatiblen Migrationen.

**Zusatzaufwand:** Modul-Gating im P0 grob **8–15 h**, falls bestehende Pakete es nicht bereits abdecken. Schulautomatisierung und Control Plane werden bewusst später geschätzt und entwickelt; nicht Teil des ersten persönlichen MVP.

### 4.7 Erweiterungsarchitektur: neue Module und Methoden (verbindlich)

**Ziel:** Von P0 an *ohne Code-Forks* um fachlich neue Module erweiterbar sein **und** neue Methoden in bestehenden Modulen ergänzen können, ohne den gesamten Daten-/API-/UI-Verbund anfassen zu müssen. Der modulare Monolith bleibt die Standardarchitektur; ein öffentlicher Drittanbieter-Plugin-Marktplatz ist ausdrücklich **kein** P0-Ziel.

**Verbindliche Ebenen**

1. **Module Registry:** Modul-ID, semantische Version, benötigte Capabilities, Abhängigkeiten, Routen, Berechtigungen, Migrationen, Navigationsbeiträge, Hintergrundjobs und Deaktivierungsregeln. `ModuleGate` wirkt auf API/Jobs/UI, und die Objektberechtigung wird zusätzlich geprüft.
2. **Method Registry innerhalb eines Moduls:** Verfahren sind typisierte Strategien (z. B. `assessment.method.ice.v1`, `assessment.method.rubric.v1`), nicht `if/else`-Verzweigungen über alle Screens. Verfahren deklarieren Eingabe- und Ausgabeschema, unterstützte Skalen/Niveaus, Datenmigrationen, Berechtigungen und verfügbare Bedienansichten.
3. **Gemeinsamer Ergebnisvertrag:** Fachliche Einträge enthalten Kontext, Quelle/Verfahren, Ergebnis, Evidenz, Autor, `subject_id` oder „Allgemein“, `entry_type_id`, Sichtbarkeit/Veröffentlichung und Version. Methoden dürfen zusätzliche typisierte Detailtabellen/JSON-Schemata besitzen, ohne Kernfelder zu duplizieren.
4. **Regelstrategien:** `grading`, `attempt_selection`, `level_advancement`, `aggregation`, `report_rendering`, `export` werden unabhängig voneinander ausgetauscht. Gleiche Rohdaten können unterschiedliche *zulässige* Ansichten ermöglichen, ohne Ergebnisse still neu zu bewerten.
5. **Adaptergrenze:** Drittsoftware (z. B. ASV-BW, andere Noten-/Zeugnissoftware, SSO) ausschließlich hinter getesteten Import-/Exportadaptern. Ein vorhandener CSV-Export eines Fremdsystems bedeutet **nicht**, dass dieses einen entsprechenden Import unterstützt.
6. **API/Events:** Versionierte OpenAPI-/JSON-Schema-Verträge, stabile Fehlercodes, kompatible Änderungen und versionierte Domain-Events mit Outbox. Event-Consumer ignorieren unbekannte optionale Felder; Breaking Changes erfordern neue Versionen und Migration.
7. **Frontend:** Module melden eigene Routen, Navigationsknoten und eingebettete `UI contributions` an (z. B. Tab „Bewerten“), statt zentrale Navigationsdateien manuell zu überladen. Methoden rendern über ein komponentisiertes Formular-/Erfassungs-Interface, mit sinnvoller Desktop-/Mobilvariante.
8. **Lifecycle:** Registrierung → Validierung → Aktivierung → Betrieb → Upgrade → Deaktivierung/Archivierung. Ein Deaktivieren darf nicht automatisch Pflichtdaten löschen oder historische Ansichten unlesbar machen.

**PHP-Pseudokontrakt (konzeptionell):**

```php
interface AssessmentMethod {
    public function key(): string;
    public function schemaVersion(): int;
    public function validateInput(array $input, AssessmentContext $ctx): ValidationResult;
    public function record(array $input, AssessmentContext $ctx): AssessmentEvidence;
}

interface AttemptSelectionStrategy {
    public function key(): string;
    public function selectPerLevel(AttemptCollection $attempts, PolicySnapshot $policy): LevelOutcomes;
}

interface GradingPolicy {
    public function calculate(ExactScore $score, PolicySnapshot $policy): GradeProposal;
}

interface ReportExportAdapter {
    public function validate(ReportBatch $batch): ValidationResult;
    public function export(ReportBatch $batch): ExportArtifact;
}
```

**Beispiel für zukünftige Erweiterung:** Neue Methode „mündliche Kurzabfrage“ erhält eine eigene `Method`-Implementierung, Eingabekomponente, deklarierte Kompetenzen und Tests. Die bestehende ICE-Logik, Klasse, Nutzerrechte, Auswertungs-API und der Zeugnistextgenerator müssen **nicht** methodenspezifisch umprogrammiert werden, sondern können über das gemeinsame Evidenz-/Ergebnisformat arbeiten. Falls eine neue Methode ein anderes Bewertungskonzept erfordert, wird ihre Aggregation ausdrücklich registriert statt stillschweigend auf Noten umgerechnet.

**Akzeptanz-/Architekturtest:** Ein Beispielmodul und eine neue Bewertungsmethode werden in CI als Test-Fixture registriert. Aktivierung, Berechtigungsablehnung, Datenspeicherung, Auswertung, Upgrade und Deaktivierung müssen funktionieren, ohne Kerncode zu ändern oder unautorisierte API-Zugriffe zu ermöglichen.

### 4.7.1 Konkretisierung: Open/Closed Principle und Interfaces statt Umbau (verbindlich)

**Die eigentliche Anforderung ist Erweiterbarkeit der Codebasis, nicht bloß schulseitige Konfigurierbarkeit.** Eine neue Methode soll als **eigene Klasse mit einem bestehenden Interface** integriert werden, ein neues Modul als **in sich abgeschlossenes Codepaket mit Modulvertrag**. Der vorhandene Core, seine Geschäftslogik und die Oberflächen bestehender Methoden bleiben dabei unverändert. Das ist das *Open/Closed Principle*: für Erweiterungen offen, für vermeidbare Änderungen an stabilen Komponenten geschlossen. Gemeinsame Funktionen werden **einmal zentral implementiert** und von Methoden/Modulen genutzt.

**Ziel-Kriterium:** Ein neuer Typ „mündliche Kurzabfrage“ oder „Lernzielkontrolle“ darf nicht erfordern, in `AssessmentService`, `GradeService`, `StudentService`, Routing, Navigation und Elternportal jeweils neue `if`-/`switch`-Zweige einzubauen. Es reicht, eine eigene Implementierung inklusive optionaler Eingabemaske, Registrierung und Tests hinzuzufügen. Ein völlig neues Datenkonzept benötigt unter Umständen bewusst eine eigene Migration, aber keine Neuschreibung bestehender Geschäftsregeln.

**Zentrale Verträge (klein und fachlich stabil, keine gigantische Basisklasse):**

| Vertrag / Extension Point | Verantwortung des Kerns | Beitrag einer Erweiterung |
|---|---|---|
| `ModuleInterface` | Module entdecken, prüfen, laden, aktivieren und sperren | Manifest, Abhängigkeiten, Services, API-Routen, Berechtigungen, Migrationen, UI-Beiträge registrieren |
| `AssessmentMethodInterface` | Methoden aufrufen, gemeinsame Ergebnisse speichern, Rechte prüfen | Methode deklarieren, Eingaben validieren und normalisierte Bewertungs-/Evidenzdaten erzeugen |
| `AttemptSelectionStrategyInterface` | Versuche eines Nachweises bereitstellen | z. B. „bestes gültiges Ergebnis **pro Niveau**“ festlegen |
| `GradingPolicyInterface` | Exakte Punkte-/Prozentwerte an Regel übergeben | Bewertungs-/Notenvorschlag mit versioniertem Notenschlüssel erzeugen |
| `FeedbackRendererInterface` | Platzhalterwerte und Freigaben bereitstellen | Vorlagen rendern, ohne Ergebnisse neu zu berechnen |
| `ExportAdapterInterface` | Freigegebene Exportdaten und Audit bereitstellen | Zielformat validieren, zuordnen und exportieren |
| `EntryTypeProviderInterface` (optional) | Fach/Allgemein, Sichtbarkeitsrechte und Historie zentral behandeln | Modulbezogene Eintragstypen und optionale Felder anbieten |

**PHP-Pseudocode: minimaler Modulvertrag.** Implementierungen werden mittels Dependency Injection instanziiert; Methoden können registriert werden, ohne zentrale Auswertungsdienste zu bearbeiten.

```php
interface ModuleInterface
{
    public function manifest(): ModuleManifest;
    public function register(ModuleRegistrar $registrar): void;
}

final class GradeModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'grade',
            version: '1.0.0',
            requires: ['core']
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->assessmentMethod(IceMethod::class);
        $registrar->assessmentMethod(RubricMethod::class);
        $registrar->attemptStrategy(BestPerLevelStrategy::class);
        $registrar->gradingPolicy(PointsThresholdGradingPolicy::class);
        $registrar->routeProvider(GradeRoutes::class);
        $registrar->permissionProvider(GradePermissions::class);
    }
}
```

Die Methoden implementieren einen **gemeinsamen Vertrag**. Die Eingabedaten dürfen nach Methode variieren; das validierte Ergebnis hält die für Auswertung, Zeugnistext und Elternansicht benötigten *gemeinsamen* Felder bereit. Ein diagnostisches Verfahren darf dabei eine Kompetenzrückmeldung ohne Note liefern.

```php
interface AssessmentMethodInterface
{
    public function key(): string;
    public function inputSchemaVersion(): int;
    public function validate(MethodInput $input): ValidationResult;
    public function capture(MethodInput $input, AssessmentContext $context): AssessmentEvidence;
}

// Erweiterung ohne Anpassung der vorhandenen ICE-/Rubric-Implementierungen:
final class OralQuizMethod implements AssessmentMethodInterface
{
    // Eigene Validierung und Erfassung nach obigem Vertrag.
}

// Generischer Aufruf im zentralen Anwendungsdienst:
$method = $methodRegistry->get($request->methodKey);
$evidence = $method->capture($validatedInput, $context);
$assessmentRepository->save($evidence);
```

*Codebeispiele sind bewusst konzeptionell:* `ModuleRegistrar`, DTOs, DI-Container und deren Typen werden im Projekt einheitlich definiert. Insbesondere Berechtigungsprüfung, Modul-Gating, Eingabevalidierung und Transaktion liegen **außerhalb** des frei austauschbaren Methodenaufrufs zentral im Application Service; die Skizze ersetzt diese Prüfungen nicht.

**Frontend analog (React/TypeScript):** Ein Modul liefert ein typisiertes `ModuleManifest` mit `routes`, `navigation`, `permissions` und optionalen `slots`. Eine Bewertungsmethode liefert ein `AssessmentMethodManifest` mit `methodKey`, `inputSchemaVersion` und einer passenden React-Eingabekomponente. Der gemeinsame Bewertungsbildschirm rendert die Komponente über die `methodKey → Component`-Registry; Ergebnisliste, Berechtigungsprüfung und Publikationsdialog bleiben einheitlich. **Keine** globale Datei, die bei jeder neuen Methode um weitere Sonderfälle wächst. Die Registry kann zur Build-Zeit über ein lokales Manifestverzeichnis (etwa Vite `import.meta.glob`) zusammengesetzt werden; Backend und Frontend erhalten stabile, aufeinander abgestimmte Methodenkennungen. Nur im geprüften Release enthaltene Komponenten sind ausführbar.

**Modul-Discovery:** Neue eigenständige Module legen ein Manifest an (`src/Modules/<Name>/module.php` bzw. Frontend-Manifest). Ein zentraler **generischer** Loader entdeckt und validiert diese Einträge, baut die Registries auf und aktiviert pro Schulinstanz nur lizenzierte/freigeschaltete Module. Eine neue Implementierung erfordert *keinen* zusätzlichen manuellen Eintrag in zehn Core-Dateien. Nicht jedes Modul muss einen eigenen Service/Container besitzen; der modulare Monolith und eine gemeinsame Codebasis bleiben erhalten.

**Gemeinsame Dienste, die nicht pro Modul neu geschrieben werden:** Identität und Rollen/Objektberechtigungen, Schüler- und Fachkontext, Eintragsmetadaten, Events/Outbox, Audit, Versionierung, Fehlerbehandlung, Notenschlüssel-/Prozentdarstellung, Veröffentlichungsworkflow und Export-Grundlagen. Diese Dienste sind über **kleine Capabilities/Interfaces** erreichbar, nicht per Zugriff auf interne Tabellen eines anderen Moduls. Gemeinsamer Code liegt nur dann in `Shared/Core`, wenn er tatsächlich fachübergreifend stabil ist.

**Ablauf beim Hinzufügen einer neuen Methode (Akzeptanzbeispiel):**

1. `OralQuizMethod` erstellen und `AssessmentMethodInterface` implementieren.
2. Validierung, normiertes Ergebnis und gegebenenfalls eigenes Detailschema/Migration implementieren.
3. React-Erfassungsansicht/Manifest für `oral_quiz` hinzufügen; kein bestehendes UI-Formular ändern.
4. Methode über den vorhandenen Registrar/Manifestmechanismus registrieren; Berechtigung und Freischaltbedingungen deklarieren.
5. Contract-Tests, Grade-/Feedback-Integration, Sichtbarkeit und Wiederherstellung alter Datensätze testen.

**Ablauf beim Hinzufügen eines neuen Moduls (Akzeptanzbeispiel):**

1. Neues Modulverzeichnis mit `ModuleInterface`, Manifest und fachlichen Services erzeugen (später über `make:module`-Scaffolder).
2. Erforderliche Core-Capabilities deklarieren und eigene Berechtigungen, API-Routen, Events, DB-Migrationen sowie React-Routen/Navigation registrieren.
3. Modul aktivieren; generischer Loader erkennt es, prüft Abhängigkeiten und sperrt Endpunkte/Jobs bei Deaktivierung.
4. Modul-Contract-Tests und Migrations-/Upgrade-Tests ausführen. **Andere fachliche Module bleiben unverändert.**

**Strenge Regeln:**

- **Dependency Inversion / keine Kreisabhängigkeiten:** Module hängen an `Contracts` und freigegebenen öffentlichen APIs, nicht an internen Klassen anderer Module; kein direkter SQL-Zugriff auf fremde Module.
- **Interface Segregation:** lieber mehrere kleine Verträge (`AssessmentMethod`, `GradingPolicy`, `ExportAdapter`) als ein universelles `PluginInterface`, das alles können muss.
- **Versionierte Datenverträge:** persistierte Einträge führen Methoden-/Schema-/Regelversionen; neue Methoden brechen keine historischen Bewertungen, Datenmigrationen sind explizit.
- **Verbindliche Tests:** Architekturtest verbietet unerwünschte Querverweise; Contract-Test einer neu registrierten Methode/eines Moduls wird ohne Änderungen am zentralen Anwendungscode ausgeführt.
- **Erweiterungsgrenze:** Es muss leicht sein, *eigene, geprüfte Module im Repository* hinzuzufügen. Ungeprüfte PHP-/JS-Dateien per Admin-Upload auszuführen oder ein Drittanbieter-Plugin-Ökosystem zu betreiben, ist **nicht** Ziel.
- **P0 bewusst schlank:** Implementiere zunächst `ModuleInterface`, `AssessmentMethodInterface`, zwei generische Registries, gemeinsame Ergebnis-/Eintrags-DTOs und einen Contract-Test. Weitere Schnittstellen bei Bedarf ergänzen, statt ein großes Plugin-Framework vorab zu programmieren.

### 4.8 Planungs-, Personal-, Raum- und Benachrichtigungs-Contracts (Neu, 13.11.2026)

Der gleiche Open/Closed-Anspruch aus 4.7 gilt für **Planungsheuristiken**, **harte Constraints**, **Raumersatzsuche**, **Abwesenheitsfolgen**, **Statistikbeiträge** und **Benachrichtigungskanäle**. Ein Eintrag in der Registry ist nur die Deklaration: Solver-Constraints müssen in die mathematische Problemformulierung übersetzt werden; eine nachträgliche reine `evaluate()`-Filterung reicht nicht aus.

| Erweiterungspunkt | Vertrag / Verantwortung | Beispiele |
|---|---|---|
| `SubstituteCandidateProvider` | Zulässige Kandidaten aus Quellen liefern, mitsamt Gründen und Verfügbarkeit | Ausfall des eigenen Unterrichts, Vertretungsreserve, Teamstunde, freie Stunde |
| `SubstituteRankingStrategy` | Kandidaten fachlich bewerten; keine harten Ausschlüsse umgehen | Fachbefähigung, Minusstundensaldo, Klassenkenntnis, Fairness |
| `PlanningConstraint` | Harte/weiche Nebenbedingungen validieren **und** in Solvermodell übertragen | Fachaufsicht, Lehrer-Doppelbelegung, Pausenaufsicht, Raumbelegung |
| `RoomFallbackStrategy` | Sichere, priorisierte Raumoptionen liefern | Ursprungsraum, KlaZi, Fachraum, freier Raum, Tausch |
| `BlockContinuityObjective` | Zusammenhängende Einsätze über mehrere Stunden bewerten | Gleiche Lehrkraft für eine Doppelstunde |
| `TeacherAbsenceImpactResolver` | Betroffene Stunden und Ressourcen bei geänderten Abwesenheitszeiträumen deterministisch berechnen | neue Lücke bei Verlängerung, Rückgabe bei Verkürzung |
| `PlanningChangeValidator` | Delta aus manuellen Änderungen auf übrige Entwurfszuweisungen prüfen | Konflikt, neue Freistunde, Blocklücke, Raumtausch |
| `StatisticsProvider` | Modulbezogene, berechtigungsgerechte Kennzahlen mit klaren Zählregeln liefern | Anwesenheit, Unterrichtsausfall, Lehrkräftefehlzeit, Events |
| `StatisticsExportAdapter` | Validierte Daten in neues Ausgabeschema rendern | Jahresbericht CSV/XLSX/PDF/behördliches Format |
| `NotificationChannel` | Technischen Zustellweg kapseln, ohne fachlichen Text oder Berechtigungen zu bestimmen | Web Push, APNs, FCM, externer Alarmprovider |
| `NotificationPolicy` | Ereignis zu Empfängergruppen, Priorität, Vertraulichkeit und Zeitplan auflösen | Chat, Klassenbuch, Planveröffentlichung, zugelassener Alarm |

**Beispiel-PHP-Pseudocode:**

```php
interface TeacherAbsenceImpactResolver
{
    public function calculateImpact(
        TeacherAbsence $before,
        TeacherAbsence $after,
        PlanningSnapshot $snapshot
    ): AbsenceImpactDelta;
}

interface PlanningConstraint
{
    public function key(): string;
    public function validate(PlanningContext $context): ConstraintValidation;
    public function compileToSolver(SolverModelBuilder $builder, PlanningContext $context): void;
}

interface RoomFallbackStrategy
{
    public function key(): string;
    /** @return list<RoomCandidate> */
    public function candidates(PlanningContext $context): array;
}
```

**Modulverantwortung:** Das `TeacherAbsences`-Modul besitzt Abwesenheiten und deren Kategorien; `Events` besitzt Veranstaltungen; `Rooms` verwaltet Raumprofile und Bedingungen; `Supervision` Pausenbereiche; `SubstitutePlanning` besitzt Entwürfe und Planversionen; `Analytics` liest ausschließlich freigegebene Datenprodukte/Projektionen dieser Module. Fachmodule dürfen keine Fremdtabellen schreibend manipulieren. `Core` stellt nur die stabilen Querschnittsverträge bereit, die tatsächlich von mehreren Modulen verwendet werden.

**Integrationsregel für manuelle Änderungen:** Ein `DraftAssignmentChanged` ist ein **internes Planungsereignis** und triggert Validierung/neue Berechnung im Entwurf; es ist **kein** Veröffentlichungsereignis und erzeugt keine Pushnachricht. Der manuell gesetzte Eintrag wird vorübergehend als Constraint für den nächsten Solve behandelt, kann aber durch die verantwortliche Person bewusst wieder gelöst werden.

**Architekturabnahme:** Eine neue Vertretungsquelle, ein neues Kriterium für freie Räume, eine weitere Fehlzeitenkategorie und ein zusätzlicher Statistikexport lassen sich ohne Änderungen an bereits existierenden Modul-Implementierungen integrieren. Explizite UI-Konfiguration und neue Datenmigrationen sind bei wirklich neuen Datenbedarfen erlaubt; aber keine verstreute Sonderfalllogik in `SubstitutePlanning`, `Timetable`, `Rooms`, `Analytics` und `Notifications`.

## 5. Datenmodell – Domänen und Tabellenentwurf

Die Tabellen sind **Zielmodell**, nicht Auftrag, alle schon in P0 anzulegen. In P0 nur benötigte Kerntabellen migrieren; Fremdschlüssel und spätere Erweiterungspunkte vorbereiten.

### 5.1 Schule, Personen, Mitgliedschaften

| Tabelle | Wesentliche Felder/Beziehungen | Phase |
|---|---|---|
| `schools` | `id`, Name, Schulart, Einstellungen; optional später Tenant-Root | P0 einfach |
| `school_years` | `id`, `school_id`, Bezeichnung, Beginn/Ende, Status | P0 |
| `students` | `id`, `school_id`, Stammdaten, `status`; fachliche Identität ≠ Login | P0 |
| `classes` | `id`, `school_year_id`, Bezeichnung, Jahrgang | P0 |
| `class_memberships` | `student_id`, `class_id`, `valid_from`, `valid_until` | P0 |
| `subjects` | `id`, Fachschlüssel/Name | P0 |
| `teaching_groups` | `id`, `school_year_id`, `subject_id`, Bezeichnung, ggf. Hauptklasse | P0 |
| `group_memberships` | `group_id`, `student_id`, Gültigkeit, Status | P0 |
| `users` | `id`, Anmeldestatus, Passwort-Hash falls lokal, verifizierte Login-Methode | P0 einzeln, P3 breit |
| `user_student_links` | `user_id`, `student_id` – **nicht** in `students` fest integrieren | P3 |
| `teachers` | `id`, ggf. `user_id`, schulische Personalkennung | P3/P4 |
| `teacher_group_assignments` | `teacher_id`, `group_id`, Gültigkeitszeitraum/Rolle | P3 |
| `guardians` | Kontakt-/Identitätsdaten von Erziehungsberechtigten | P3 |
| `student_guardians` | Beziehung, Kontaktbefugnis, verifizierter Berechtigungsumfang, Gültigkeit | P3 |
| `external_identities` | `source_system`, `source_school_id`, `external_key`, `entity_type`, `entity_id` | P3 |
| `import_batches` / `import_changes` | Quelle, Hash, Vorschau, Konflikte, Freigabe, Ergebnis | P0 einfach/P3 voll |
| `roles` / `role_assignments` | Rolle + Scope (Schule, Klasse, Gruppe, Kind), Zeitraum | P3 |
| `account_activations` / `sessions` | Einmaltoken-Hash/Ablauf; Sitzungswiderruf | P0/P3 |

**Besondere Constraints:** Eindeutigkeit externer Kennung je `(school_id, source_system, entity_type, external_key)`. Klassenzugehörigkeiten dürfen historisch mehrere Zeilen haben, Überschneidungen unterliegen expliziter schulischer Geschäftsregel. Ohne eindeutiges Match beim Import **Konflikt statt stiller Neuanlage/Dublette**.

### 5.2 Raster, Beobachtungen, Noten, Maßnahmen

| Tabelle | Wesentliche Felder/Beziehungen | Phase |
|---|---|---|
| `assessment_schemes` | Fachgruppe/Schuljahr, Version, Aktivstatus | P1 |
| `assessment_criteria` | `scheme_id`, Schlüssel, Titel, Beschreibung, Anzeigereihenfolge, optional Gewicht | P1 |
| `criterion_levels` | `criterion_id`, `code`, Label, Reihenfolge; z. B. `-`, `=`, `+` | P1 |
| `observation_sessions` | `group_id`, Datum/Uhrzeit, optional `lesson_id`, Modus `ice`/`single`, Autor | P1 |
| `observations` | `session_id`, `student_id`, `observed_at`, `note`, `created_by`, `version` | P1 |
| `observation_ratings` | `observation_id`, `criterion_id`, `level_id` | P1 |
| `seat_layouts` | `group_id`, Name, Raum optional, Version, aktiv | P1 |
| `seat_positions` | `layout_id`, `student_id` nullable, `x`, `y`, Tisch/Platz, Rotation | P1 |
| `assessments` | `group_id`, Typ, Datum, Maximalpunkte, Status/Veröffentlichung | P1/P2 |
| `assessment_tasks` | `assessment_id`, Aufgabe, Maximalpunkte, Kompetenz | P2 |
| `assessment_results` | `assessment_id`, `student_id`, abgeleitetes/ggf. freigegebenes Endergebnis, Version | P1/P2 |
| `assessment_task_results` | `assessment_result_id`, `assessment_task_id`, Punkte | P2 |
| `competencies` | Fach/Jahrgang, Kompetenzschlüssel, Beschreibung | P2 |
| `grade_schemes` | Basisvorlage (Fach/Schule/Niveau/Leistungsart) und eindeutige Kennung | P1/P2 |
| `grade_scheme_versions` | Unveränderliche Punkt-/Prozentgrenzen, Bewertungsregel, Rundungs-/Darstellungsregel | P1/P2 |
| `report_drafts` | Schüler, Fachgruppe, Zeitraum, Text, Quelle/Version, Freigabe | P1 |
| `student_measures` | `student_id`, Typ `nachteilsausgleich`/`attest_requirement`/etc., Gültigkeit, Status, issuing authority | P1/P3 |
| `measure_scopes` | `measure_id`, Fach/Gruppe/Leistungsart, konkrete Auswirkung | P1/P3 |
| `measure_documents` | geschütztes Dokument oder Referenz, Zugriffsklasse; nur falls erforderlich | P3 |

**Erweiterung des Leistungs-/Eintragsmodells (Auszug):**

| Tabelle | Wesentliche Felder/Beziehungen | Phase |
|---|---|---|
| `entry_types` / `entry_type_versions` | schulweit stabiler `key`, editierbarer `label`, Default-/erlaubte Sichtbarkeit, zulässige Methoden | P0 Schema/P1 UI |
| `entry_metadata` oder konsistenter gemeinsamer Embeddable | `student_id`, optional `lesson_id`, `subject_id` nullable mit explizitem Allgemein-Scope, `entry_type_id`, `created_by`, `version` | P1 |
| `entry_publications` | Audience `teachers/students/guardians`, Freigabestatus, Autor/Zeitpunkt, ggf. Widerruf; fachliche Berechtigung unabhängig prüfen | P1 Schema/P3 aktiv |
| `assessment_methods` | registrierte Kennung, Version, konfigurierbares Schema und UI-Capability; keine unkontrolliert hochgeladenen Skripte | P1 Basis/P2 Vielfalt |
| `assessment_types` | Schul-/Fachkonfiguration für Klassenarbeit, Test, Präsentation, Versuch, Projekt usw. | P1 |
| `level_sets` / `levels` | schul-/fachspezifische Namen, Reihenfolge und ggf. Zuordnung zu rechtlich definierten Niveaus | P1 |
| `student_subject_levels` | Gültigkeit einer fachlichen Niveauzuordnung; unabhängig vom Niveau des Versuchs | P1/P2 |
| `assessment_variants` | Niveau/Version, eigene Aufgaben und Maximalpunkte pro Variante | P1 |
| `assessment_policies` / `assessment_policy_versions` | Strategie (`single`, `best_of_n_per_level`, `sequential_levels`, `teacher_review`), max. Versuche, Freischalt- und Auswahlregeln | P1 Schema/P2 Verhalten |
| `assessment_attempts` | `assessment_id`, `student_id`, `variant_id`, tatsächliches `level_id`, Versuchsnr., Status, Zeitpunkt, Punkte, Version | P1 Datenmodell/P2 Wiederholung |
| `assessment_attempt_scores` | optional Punkte je Aufgabe/Kompetenz im konkreten Versuch | P2 |
| `assessment_level_outcomes` | bester **gültiger** Versuch je `(assessment_id, student_id, level_id)`, Strategie-/Policy-Version und Berechnungsnachweis | P2 |
| `feedback_templates` / `feedback_template_versions` | Vorlagentyp, Fach/Niveau/Leistungsart, Text, Platzhalter, Sichtbarkeit privat/Fachschaft/Schule | P1 privat/P3 geteilt |
| `feedback_drafts` / `feedback_publications` | Vorlagenauswahl, bearbeiteter gerenderter Text als Snapshot, Freigabe pro Audience, Zeitpunkt, Version | P1 Entwurf/P3 Portal |
| `report_export_adapters` / `report_export_jobs` | Adapterkennung, Version, Mapping, validierte Exportartefakte, Status/Protokoll | P1 generisch/P3 spezifisch |

**Modellierungsregel:** Nicht alle Fachobjekte in eine riesige `entries`-Tabelle pressen. Gemeinsame Pflichtfelder über ein geteiltes Schema/Value Object mit wiederverwendbarer Policy behandeln; fachliche Tabellen behalten ihre passende Struktur. Weder Fach-„Allgemein“ noch `not_observed` dürfen als Pseudo-Note `0` gespeichert werden.


**Wichtig:** In `observation_ratings` nur tatsächlich gesetzte Werte speichern. Eine übersprungene Person erzeugt **keinen Rating-Datensatz**; sie kann bei Bedarf in einer gesonderten Session-Fortschrittsliste als `skipped` ohne Wert erscheinen. Observation- und Notenwerte werden separat modelliert.

### 5.3 Unterricht und Anwesenheit

| Tabelle | Inhalt | Phase |
|---|---|---|
| `weekly_schedule_templates` | Wiederkehrende Zeitfenster/Lehrkraft/Fachgruppe/Raum | P2 |
| `lesson_occurrences` | konkrete Stunde mit Datum, Status, Lehrkraft/Gruppe/Raum | P2 |
| `curriculum_plans` / `curriculum_units` | Themenfolge, Sollstunden, Ziele, Schuljahr | P2 |
| `lesson_curriculum_links` | Termin ↔ Inhalt, tatsächlich bearbeitete Stundenanteile | P2 |
| `lesson_notes` | geplanter/durchgeführter Inhalt, Hausaufgabe, Status | P2 |
| `lesson_materials` | Datei-/Linkreferenzen zur Stunde | P2 |
| `attendance_checks` | Termin, kontrollierende Lehrkraft, bestätigt um, Vollständigkeit | P2/P3 |
| `attendance_records` | Schüler+Termin, `present/absent/late/exempt/unchecked`, Zeitpunkt | P2/P3 |
| `absence_notices` | Eltern-/Sekretariatsmeldung: Zeitraum, Quelle, Prüfung | P3 |
| `absence_cases` | ungeklärter Abwesenheitsfall; Zuständigkeit, Status, Kontaktprotokoll | P3 |
| `absence_evidence` | erforderliche Nachweise nur minimal und geschützt | P3 |
| `temporary_lesson_assignments` | Schüler, Quellstunde, Gaststunde, Zeitraum/Grund, aufnehmende Lehrkraft, Zuordnungsstatus, Version | P2/P3 |
| `presence_locations` / `presence_confirmations` | bestätigte physische Anwesenheit, aufnehmender Unterricht/Ort, bestätigende Lehrkraft und Zeitpunkt; keine bloße Planung als Präsenz | P2/P3 |
| `attendance_notification_rules` | Prüf-/Wartezeit je Schule bzw. Stundentyp, nur ungeklärte Fälle, versionierte Eskalation | P3 |

**Drei getrennte Ebenen:** `absence_notices` = „gemeldet“, `attendance_records` = „beobachtet“, `absence_cases` = „organisatorisch geklärt/ungeklärt“. Keine davon darf durch die andere automatisch ersetzt werden.

### 5.4 Kommunikation, Rückläufer, Zahlungen, Benachrichtigungen

| Tabelle | Inhalt | Phase |
|---|---|---|
| `conversations` / `conversation_members` | Dialog und berechtigte Teilnehmer | P3 |
| `messages` / `message_receipts` | Nachricht, Zustell-/Lesestatus, individuelle Empfänger | P3 |
| `form_templates` / `form_versions` | Formularvorlage und unveränderliche veröffentlichte Version | P3 |
| `form_questions` / `form_options` | Feldtyp, Validierung, Pflichtfeld, Bedingung | P3 |
| `form_assignments` | Zielgruppen, Fristen, Identifizierungsmodus | P3 |
| `form_submissions` / `form_answers` | Antworten, ggf. eindeutige Zuordnung und Abgabestatus | P3 |
| `school_events` / `event_participants` | Ausflüge, Klassen, Begleitpersonen, Zeiträume | P3/P4 |
| `school_event_categories` / `school_event_category_versions` | Schuleigene Kategorien, historische Schlüssel und statistische Gruppierung | P3 |
| `event_impacts` / `event_staff_assignments` | betroffene reguläre Stunden, Gruppen, Räume und tatsächlich gebundene Begleitpersonen | P3/P4 |
| `payment_campaigns` | Vorgang, Empfängerkonto, IBAN, Frist | P3 |
| `payment_obligations` | Sollbetrag je Kind, Ermäßigung, Status | P3 |
| `payment_entries` | bestätigte manuelle Eingänge, Rückzahlungen, Buchungs-/Belegreferenzen | P3 |
| `notifications` / `notification_deliveries` | fachliche Meldung vs. Geräteversuch | P3 |
| `push_subscriptions` | Gerät/Benutzer, Endpoint und Schlüsseldaten verschlüsselt/geschützt | P3 |
| `notification_preferences` | Ruhezeiten/Zeitzone/Ereignisklassen pro Nutzer | P3 |
| `outbox_events` / `job_runs` | zuverlässige asynchrone Verarbeitung, Status, Retry | P3 |

### 5.5 Stundenplan und Vertretung

| Tabelle | Inhalt | Phase |
|---|---|---|
| `curriculum_hour_quotas` | versioniertes Kontingent je Schulart/Kohorte/Fach | P4 |
| `teaching_demands` | Unterrichtsbedarf nach Gruppe, Fach, Umfang/Kopplung | P4 |
| `teacher_contracts` / `teacher_availabilities` | Deputate, Ermäßigungen, Verfügbarkeiten/Gültigkeiten | P4 |
| `rooms` / `room_resources` | Raum, Ausstattung, Kapazität, Sperrungen | P2/P4 |
| `room_conditions` / `room_condition_versions` | Bedingungsart, Parameter, Gültigkeit, Hart-/Weich-Klassifikation und Tätigkeitsscope | P4 |
| `class_home_rooms` | Stammklassenzimmer (`KlaZi`) je Klasse/Gültigkeit, ggf. Schuljahr | P2/P4 |
| `teacher_qualifications` / `teacher_eligibilities` | Fach- und Tätigkeitseignung, formaler Nachweis, Gültigkeit, geprüfte Zuständigkeit | P3/P4 |
| `supervision_areas` / `supervision_requirements` | Pausenbereich, Sichtachsen/Gefahren, Mindestanforderungen, mögliche Zusammenlegungen | P4 |
| `supervision_assignments` / `supervision_actuals` | geplante, vertretene und tatsächlich geleistete Aufsichten | P4 |
| `timetable_constraints` / `timetable_runs` | harte/weiche Regeln, Solver-Version, Gewichtung, Ergebnis | P4 |
| `timetable_versions` / `timetable_slots` | Jahresplanversionen und Zuordnungen; Jahresplanung kann eigene Review-Schritte besitzen, **Vertretungsplanung nur Entwurf/veröffentlicht** | P4 |
| `planning_absences` | Legacy-/Integrationssicht auf Lehrkraft- und Gruppenabwesenheiten; kanonische Lehrerabwesenheit in `teacher_absences` | P4 |
| `teacher_absences` | stabile Fehlzeit-ID, Lehrer-ID, Start/Ende, Bereich, Kategorie, Zugriffsklasse, Version/Verfasser | P3/P4 |
| `teacher_absence_revisions` | Before/After jeder Verlängerung, Verkürzung oder Korrektur; Grund, Autor, Zeitpunkt, Wirksamkeit | P3/P4 |
| `teacher_absence_categories` / `teacher_absence_category_versions` | Schuleigene stabile Kategorie, Label, Statistikgruppe, zulässige Sichtbarkeit und Aufbewahrung | P3 |
| `absence_impacts` | deterministisch ermittelte Auswirkung `(absence_id, occurrence_id, impact_type)` mit Eindeutigkeit/Version | P4 |
| `substitution_demands` | Lücke aus einem veröffentlichten Plan | P4 |
| `substitution_proposals` / `substitution_decisions` | Kandidaten, Scores, Ablehnung, Freigabe | P4 |
| `substitution_policy_versions` | schulisch beschlossene Quellenreihenfolge, Qualifikationsmodus, Minusstunden, Blockvertretung, Stabilität & Gültigkeit | P4 |
| `substitution_plan_drafts` / `draft_assignments` | Bearbeitbarer Plan, manuell gesetzte Constraints und Versionen; **keine externe Veröffentlichung** | P4 |
| `substitution_plan_publications` / `published_assignments` | unveränderliche veröffentlichte Vollsnapshots, Freigabe/Version, aktiver Zeiger | P4 **[Überholt durch 17.16 / Rev. 1]** Veröffentlichung erfolgt pro ausgewählter Stunde, nicht als Vollsnapshot des Gesamtentwurfs (17.16 Nr. 2). |
| `substitution_publication_diffs` | Betroffene, neue, veränderte und aufgehobene Zuordnungen, Dedup-Schlüssel für gebündelte Benachrichtigungen | P4 |
| `planning_actuals` | real durchgeführter Unterricht / Aufsicht / Veranstaltung und Korrekturrevisionen | P3/P4 |
| `teacher_workload_ledgers` | getrennte Organisation-, Tätigkeits- und rechtliche Anrechnungsdimensionen; kein pauschales Minusstundenkonto | P4 |
| `statistics_runs` / `statistics_snapshots` | Schuljahr, Regel-/Filterversion, Stichtag, Revision, Freigabe, Inhalts-Hash | P4 |
| `statistics_exports` | Exportadapter, Dateireferenz, Ersteller, Berechtigung, Zeit, Hash und Aufbewahrung | P4 |
| `teacher_substitution_loads` | abgeleitete zusätzliche Stunden/Quote im Bezugszeitraum | P4 |

### 5.6 Beispiel einer fachlichen P1-Struktur (kein vollständiges SQL)

```sql
CREATE TABLE observation_sessions (
  id BIGINT PRIMARY KEY,
  teaching_group_id BIGINT NOT NULL,
  author_user_id BIGINT NOT NULL,
  observed_on DATE NOT NULL,
  mode VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (teaching_group_id) REFERENCES teaching_groups(id)
);

CREATE TABLE observations (
  id BIGINT PRIMARY KEY,
  session_id BIGINT NOT NULL,
  student_id BIGINT NOT NULL,
  note TEXT NULL,
  observed_at DATETIME NOT NULL,
  version INT NOT NULL DEFAULT 1,
  FOREIGN KEY (session_id) REFERENCES observation_sessions(id),
  FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE observation_ratings (
  observation_id BIGINT NOT NULL,
  criterion_id BIGINT NOT NULL,
  level_id BIGINT NOT NULL,
  PRIMARY KEY (observation_id, criterion_id),
  FOREIGN KEY (observation_id) REFERENCES observations(id)
);
```

**Nicht im Beispiel enthalten, aber zwingend umzusetzen:** Indizes, weitere Fremdschlüssel, fachliche Gültigkeitsprüfung (`student` ∈ Fachgruppe zum Datum), Zuordnung `level` → `criterion`, Autorisierung, Änderungsprotokoll und atomare Schreibtransaktionen. Für 0 Bewertungen und reine Bemerkung reicht eine `observations`-Zeile ohne `observation_ratings`.

## 6. Wichtige Geschäftsprozesse und fachliche Regeln

### 6.1 ICE-Schnellerfassung – P1

**Anwendungsfall:** Nach einer 45-minütigen Stunde in einer Gruppe mit typischerweise 25–30 SuS alle durchgehen, um den tatsächlich gewonnenen Eindruck zeitsparend festzuhalten.

1. Fachgruppe öffnen und „ICE starten“ wählen; eine neue `observation_session` wird erzeugt (auch ohne `lesson_occurrence`).
2. Ansicht „Liste“ oder „Sitzplan“ wählen. Bei Listenmodus alphabetisch oder sitzplanorientiert vorgehen; nur aktive Mitglieder der Fachgruppe anzeigen.
3. Für die aktuelle Person nur die **wirklich beobachteten Kriterien** mit `+`, `=` oder `−` bewerten; der Rest bleibt leer.
4. „Weiter“, „Überspringen“, „Zurück“ und „Bemerkung“ verfügbar; optional bei kompletter Kurzeingabe automatisch weiterschalten. Kein Timer, der ungesehene Bewertungen erzwingt.
5. Erfasste Personen eindeutig markieren; Session-Fortschritt zählt **erfasst**, **übersprungen**, **noch offen** getrennt.
6. Abschluss zeigt alle SuS, inklusive offenen Personen und potenziellen Doppelerfassungen. Änderungen sind bis zur bestätigten Finalisierung möglich, spätere Änderungen bleiben nachvollziehbar.
7. Session wird gespeichert; ICE-Wertungen und Einzelbeobachtungen fließen in dieselbe Auswertung ein, bleiben über `mode` aber differenzierbar.

**UX-Ziele:** Bedienbar mit einer Hand auf dem Smartphone, große Touch-Flächen, möglichst keine Dialogkaskaden, verzögerungsfreie Rückmeldung, sichere Doppelklick-Behandlung. Ein 5-Sekunden-Ziel ist *Bedienbarkeit*, **keine** Verpflichtung, für jede Person fachlich eine Bewertung abzugeben.

**Akzeptanzkriterien:**
- Ein kompletter Durchlauf mit 25 SuS ist bei vorbereiteter Stunde mit je einem Klick/kurzen Auswahlaktionen pro Person praktisch in ca. 2–4 Minuten möglich, sofern tatsächlich ausreichende Beobachtungen vorliegen.
- Wird eine Person übersprungen, entsteht **keine** neutrale Leistung; spätere Statistik zählt sie nicht als beobachtet.
- Wechsel Liste ↔ Sitzplan während einer Session bewahrt Änderungen und Fortschritt.
- Ein Doppeltipp erzeugt keine doppelte Beobachtung; ein anderer Nutzer kann nicht unbefugt dieselbe Session ändern.

### 6.2 Beobachtungsvorschläge – P1

Ein transparentes Scoremodell priorisiert ausschließlich die **Notwendigkeit weiterer Beobachtung**, niemals automatisch eine schlechte Note. Vorschlag:

```text
need_score(student, group, criterion?) =
    w1 * geringe_Anzahl_letzte_8_Wochen
  + w2 * lange_Zeit_seit_letzter_Beobachtung
  + w3 * bisher_unbeobachtetes_Kriterium
  + w4 * Zufallskomponente_klein
  - w5 * bereits_für_diese_Stunde_vorgemerkt
```

- Zeitfenster und Gewichte als Einstellung, zunächst einfache Defaultwerte ohne „KI“.
- Beobachtungshäufigkeit getrennt nach **Terminen**, **Einzelereignissen** und **Kriterien** auswerten; drei Bewertungen in einer Stunde sind nicht drei unabhängige Unterrichtsgelegenheiten.
- Abwesende/ausgetretene Personen nicht vorschlagen, aber fehlende Abwesenheitsdaten nicht als Abwesenheit behandeln.
- Jede Vorschlagskarte zeigt „warum“ (z. B. „seit drei Wochen keine fachliche Beobachtung“).
- Keine automatischen Urteile über Verhalten, Diagnosen oder Leistungsfähigkeit aus dem Vorschlagsscore ableiten.

### 6.3 Noten, Nachteilsausgleich, Zeugnis – P1/P2

- **Bewertungskriterien** und **Leistungsnachweise** bleiben getrennt. Ein Mittelwert der Symbole `+`, `=`, `−` ist maximal ein Informationshinweis und keine automatisch gültige Zeugnisnote.
- Schriftliche Leistungsnachweise werden mit typisierten Ergebnissen, Punktehöchstwert, Notenschlüssel-Version, Rundungsregeln und optional Aufgabe-Kompetenz-Verknüpfung gespeichert.
- Versionierung schützt historische Ergebnisse: Eine nachträgliche Änderung eines Notenschlüssels darf alte Ergebnisse nicht stillschweigend anders darstellen.
- Nachteilsausgleiche mit `type`, `valid_from`, `valid_until`, `scope`, `approved_by`, `approved_at`, konkreter **Maßnahme** (z. B. Zeitverlängerung/Hilfsmittel) führen. Die Maßnahme darf nicht automatisch aus einer Diagnose hergeleitet werden.
- Bei neuer Klassenarbeit werden **gültige, für diese Leistungsart passende** Maßnahmen vor Veröffentlichung als organisatorische Checkliste angezeigt. Berechtigte Lehrkräfte erhalten die notwendige Handlungsinformation; andere Nutzer keine Diagnose/Begründung.
- Der Zeugnistextgenerator wählt regelbasierte Textbausteine, listet verwendete Beobachtungsquellen/Zeiträume und markiert schwache Datenbasis. Endgültiger Text ist editierbar und wird nicht automatisch an Schüler/Eltern veröffentlicht.
- Nachteilsausgleich als organisatorische Maßnahme gehört nicht ungewollt in den Zeugnistext; entsprechende Vorgaben sind separat zu prüfen.

### 6.3.1 Einträge: Fach, schuleigene Art und Veröffentlichung – P0/P1/P3

**Jeder schülerbezogene pädagogische Eintrag** (Beobachtung, Schülerakteintrag, Test-/Leistungsrückmeldung, ggf. individuelle Klassenbuchnotiz) erfüllt folgende Regeln:

1. **Fachkontext:** Entweder `subject_id` mit gültiger Fachzuordnung **oder** bewusst `GENERAL` („Allgemein“). Wird der Eintrag aus `lesson_occurrence` erzeugt, übernimmt die UI das dortige Fach, der Server validiert die Herkunft und erlaubt nur berechtigte fachliche Änderung; im Einzelmodus ohne Unterrichtstermin ist das Fach auszuwählen.
2. **Eintragsart:** `entry_type_id` verweist auf eine pro Schule definierte, versionierte Kategorie (z. B. Lob/Ermahnung/Mitarbeit/Kurztest). Kategorien können umbenannt werden, ohne die historische fachliche Bedeutung zu verlieren; bei bereits benutzten Typen Versionierung/Archiv statt hartem Delete.
3. **Sichtbarkeit:** `teachers`, `students`, `guardians` sind **getrennte Zielgruppen**. Ein „Lehrkräfte“-Haken erweitert niemals die Berechtigung auf alle LuL, sondern auf bereits berechtigte Personen im gültigen Scope. Kinder/Eltern können unterschiedliche Freigaben erhalten; besondere Maßnahmen dürfen nicht über diesen allgemeinen Schalter öffentlich gemacht werden.
4. **Default und Publikation:** Neue Einträge intern, unveröffentlicht; Sichtbarkeit für SuS/EZB setzt Freigaberecht, expliziten Veröffentlichungsakt und positive Prüfung der Schüler-/Sorgeberechtigtenzuordnung voraus. Rücknahme, Korrektur und erneute Veröffentlichung sind versioniert und auditiert.
> **[Überholt durch 17.16 / Rev. 1]** Gilt nicht für Klassenbucheinträge; dort Default *SuS + Eltern sichtbar* (17.16 Nr. 1). Private ICE-Beobachtungen bleiben immer privat.
5. **Auswertung/Filter:** Fach oder „Allgemein“, Art, Methode, Datum, Autor und tatsächliche Zielgruppen müssen filter- und exportierbar sein, soweit das Exportrecht besteht. Unterschiedliche Eintragsarten werden nicht ohne definierte Regel automatisch in eine Fachnote aggregiert.

**Beispiel eines Transportobjekts (API-Konzept):**

```json
{
  "student_id": "stu_10427",
  "lesson_id": null,
  "subject_id": "phy",
  "scope": "SUBJECT",
  "entry_type_key": "mitarbeit",
  "method_key": "assessment.method.single.v1",
  "visibility": {"teachers": true, "students": false, "guardians": false},
  "publication_status": "draft",
  "note": "Begründet eine Beobachtung am Versuch."
}
```

**Sichtbarkeit ist nicht Autorisierung:** Selbst bei `students=true` wird *nur* der betroffene, angemeldete Schüler autorisiert; `guardians=true` gilt ausschließlich für aktuell berechtigte EZB. Unzulässige sensible Daten bleiben auch bei technisch gesetztem Flag gesperrt.

### 6.3.2 Niveaus, Versuche und Notenschlüssel – P1/P2

1. **Konfigurierbare Niveaus:** `level_set` enthält schul-/fachbezogene Anzeigenamen wie G/M/E oder F; gegebenenfalls separate normative Codes. Schüler können je Fach eine zeitlich gültige Niveauzuordnung haben; der einzelne Versuch speichert unabhängig davon die *tatsächlich absolvierte Variante*.
2. **Variantenspezifische Maximalpunkte:** „Reelle Zahlen“ kann auf F mit 31 Punkten und M mit 35 Punkten angeboten werden. Ein Schüler darf mehrere Versuche derselben Niveaustufe haben; Versuche unterschiedlicher Niveaus sind keine gleichartige Messung.
3. **Best-of-X je Niveau:** Nur gültige Versuche desselben Niveaus vergleichen und alle Versuche aufbewahren. Die Definition von „best“ (`percentage`, fachlich zulässige `grade`, Punkte nur bei identischer Maximalpunktzahl) ist Teil der versionierten Regel; Gleichstände erhalten stabile, dokumentierte Tie-Break-Regeln.
4. **Weitere Strategien:** `single`, `best_of_n_per_level`, `sequential_levels` und `teacher_review`; konfigurierbar sind zulässige Reihenfolge, Anzahl je Niveau, Freischaltschwellen und finale Zusammenführung. Unterschiedliche Niveaus **niemals** ohne explizit freigegebene Strategie mit Prozenten gegeneinander ausspielen; die rechtliche Zulässigkeit des Verfahrens ist schulisch zu prüfen.
5. **Notenschlüssel:** Vorlagen pro Schule, Schulart, Fach, Niveau, Leistungsart oder konkreter Arbeit, mit versionierten Punkt-/Prozentgrenzen. Die Note eines Versuchs wird aus Punkten und exakt gültigem Regelstand berechnet; automatische Endnoten bleiben Vorschläge bis zur zulässigen fachlichen Freigabe.
6. **Punkte/Prozent – harte Rundungsregel:** Exakt gespeicherte Punkte (dezimal/feste Skalierung) und Maximum bilden den **ungekürzten** Quotienten. Die **Anzeige** verwendet kaufmännische Rundung auf **ganze Prozent** (z. B. `15/31 = 48 %`, `20/31 = 65 %`, `26/31 = 84 %`); alle Textvorlagen, Schüler-/Elternansichten und visuellen Berichte zeigen dieselben Ganzzahlprozente. Grenzen für Note/Freischaltung vergleichen den **ungerundeten** Wert oder entsprechend eindeutig abgeleitete ganzzahlige Punktgrenzen. **Anzeige 80 % kann bei exakt 79,6 % entstehen, ohne dass eine 80,00-%-Schwelle erreicht ist.**
7. **Statusmodell:** `planned`, `completed`, `excused_absent`, `rescheduled`, `invalidated`, `reviewed`, `published`; fehlender/entschuldigter Versuch ist **niemals** automatisch 0 Punkte. Unzulässige Zustandsübergänge sind zu verhindern.
8. **Berechnungstransparenz:** Jeder Endstand enthält verwendete Varianten, Versuch-IDs, Policy-/Schlüssel-Version, Rohpunkte, ungerundete Schwellenentscheidung und angezeigte gerundete Prozente. Lehrkräfte können die Formel sehen; eine begründete manuelle Abweichung ist möglich, ohne den berechneten Vorschlag zu überschreiben.

**Beispiel:** Versuch F1 `15/31 = 48 %`, F2 `24/31 = 77 %` → **F-Wert = Versuch F2**; M1 `18/35 = 51 %` → **M-Wert = Versuch M1**. Welches Niveau in eine eventuelle übergeordnete Fachbewertung eingeht, bleibt eine **separat** definierte, freigegebene schulische Regel.

### 6.3.3 Feedbacktext-Vorlagen und Veröffentlichung – P1/P3

- Lehrkraft kann nach Punkten/Niveau/Versuch **eine von mehreren persönlichen Vorlagen auswählen** und den endgültigen Text vor Freigabe bearbeiten. Später auch schulintern geteilte, versionierte Vorlagen; kein Zwang zu KI oder externem Versanddienst.
- Platzhalter-Engine mit streng erlaubten Feldern: `{thema}`, `{fach}`, `{niveau}`, `{punkte}`, `{max_punkte}`, `{prozent}`, `{note}`, `{versuch}`, `{bestes_ergebnis}`, `{verbesserung_prozentpunkte}`. Nicht verfügbare oder sensible Variablen führen zu markierter Vorschau/Validierungsfehlern, nicht zu falschen Aussagen.
- Beispielvorlage: `Du hast im Test „{thema}“ auf {niveau}-Niveau {punkte} von {max_punkte} Punkten erreicht. Das sind {prozent} %. Super, weiter so!` – Ton und Lob dürfen vom tatsächlichen Ergebnis und fachlicher Einschätzung abhängen; Lehrkraft wählt vor Veröffentlichung bewusst die passende Vorlage.
- `feedback_draft` wird nach Auswahl serverseitig aus **Snapshot** des Leistungsergebnisses und der **Template-Version** gerendert; `rendered_text` wird gespeichert. Spätere Änderungen an Punkten, Notenschlüssel oder Vorlage überschreiben eine bereits veröffentlichte Nachricht nicht stillschweigend.
- Sichtbarkeit je Zielgruppe: Lehrer intern sofort; **SuS und berechtigte EZB sehen die freigegebenen Punkte/Prozent/Note und den veröffentlichten Text direkt im Portal**, wenn die jeweiligen Publikationsrechte aktiviert wurden. Sonst bleiben Inhalte verborgen. Push signalisiert ausschließlich eine neue Rückmeldung, nicht deren sensiblen Inhalt.
- Veröffentlichungsfluss: `draft → reviewed → published → corrected/revoked` mit autorisierter Freigabe, Versionsabgleich und Audit. Klassenweise Bulk-Freigabe mit Schüler-/Empfänger-Vorschau und zurückgehaltenen Einzelfällen; In-App-Verfügbarkeit sofort nach Commit, Push-Zustellung nur best effort.

### 6.3.4 Zeugnisdaten, Dokumentvorlagen und Softwareadapter – P1/P4

- **Erster Schritt:** Notenvorschläge und Zeugnistextentwürfe pro Fach/Jahr exportierbar als CSV/XLSX; editierbare PDF/Word-Berichtsvorlagen sind interne Arbeitsdokumente, nicht automatisch amtliche Zeugnisse.
- **Erweiterung:** Adapter `ReportExportAdapter` nach identifizierter, dokumentierter Importfunktion der Zielsoftware (z. B. ASV-BW, WebUntis, weitere), inklusive Mappingvorschau, Schema-, Zeichen- und Rechteprüfung, Importabgleich soweit möglich. Keine imaginären APIs oder Importformate.
- **Später:** Eigene, versionierte Zeugnisvorlagen je Schulart, Jahrgang, Zeugnisart und Rechtsstand, gemeinsame Freigabe, Serienerstellung und Archivierungsregeln. Amtliche Formulare nur nach fachlicher/rechtlicher Abstimmung und Prüfung der Vorlagentreue.
- Neue Exportformate als **Adapter**, nicht als Sonderfälle im Zeugnisgenerator; Ausfall eines Adapters darf vorhandene fachliche Notendaten nicht verändern.


### 6.4 Unterrichtsstunde und Stoffverteilung – P2

Zustände einer Unterrichtsstunde beispielsweise:

```text
scheduled -> prepared -> held -> documented
                \-> cancelled
                \-> moved (neuer Termin mit Referenz)
```

- `scheduled` aus persönlichem Wochenraster; Ferien/Feiertage/Ausnahmen gelten für konkrete Termine.
- `held` ist eine bestätigte tatsächliche Durchführung; `documented` enthält finalen Klassenbucheintrag.
- Stoff-Ist-Fortschritt wird aus **bestätigter Bearbeitung**, nicht aus der bloßen Kalenderexistenz eines Termins berechnet.
- Ausgefallene Stunden bleiben auswertbar und können Themenverschiebungen auslösen; sie erscheinen nicht als gehalten.
- Im persönlichen Klassenbuch kann Dokumentation fehlen, ohne dass die Stundenplan- oder Beobachtungsdaten verloren gehen.
- Später dürfen offizielle Klassenbucheinträge erst nach Einigung über verbindliche Quelle und Zuständigkeit aus TeacherOS erzeugt werden.

### 6.5 Krankmeldung und Erststundenkontrolle – P3

**Zentraler Grundsatz:** `Eltern melden Abwesenheit` ≠ `Lehrkraft stellt Abwesenheit fest` ≠ `Schule bestätigt Entschuldigungsstatus`.

1. Eltern melden über verifiziertes Konto Zeitraum und den notwendigen Grund; School-Scope und Berechtigung prüfen.
2. Bei der ersten Unterrichtsstunde wird eine Lehrkraftkontrolle **aktiv bestätigt** (bei Fehlbedienung korrigierbar/protokolliert).
3. Für tatsächlich als `absent` erfasste SuS prüft ein Dienst, ob eine passende gültige Meldung/Entschuldigung vorliegt.
4. **Ungeklärte Abwesenheit** erzeugt einen Sekretariatsfall; die konkrete Meldefrist und Eskalation legt die Schule fest.
5. **Kontrolle fehlt:** separater Vorgang „Lehrkraft hat Anwesenheit nicht bestätigt“, nicht „alle Kinder fehlen“.
6. Sekretariat sieht Fälle, dokumentiert Versuche (telefonisch, Mitteilung), übernimmt Verantwortung und schließt mit Grund.
7. Spätere Krankmeldung aktualisiert den Fall, löscht seine Historie aber nicht.
8. Bei fehlender Technik muss es einen **verbindlichen Offline-/Telefon-Ersatzprozess** geben; Push allein ist keine Sicherheitsbestätigung.

**Fälle testen:** Kind kommt verspätet; Eltern melden erst später; Kind ist bei Veranstaltung statt Unterricht; Lehrkraft kontrolliert nicht; Teilgruppe fehlt; Datenbankverbindung bricht bei Bestätigung ab; Sekretariat bekommt Doppelalarm.

**Attestpflicht:** Nur dokumentierte Entscheidung der zuständigen Stelle; sichtbare Gültigkeit, Reichweite und Ende. BW-Schulbesuchsverordnung § 2 in der aktuellen Fassung sieht unter bestimmten Voraussetzungen eine schriftlich angeordnete Pflicht vor und begrenzt sie grundsätzlich höchstens bis zum Schuljahresende; die App stellt darüber **keine eigenständige Entscheidung** an.

### 6.6 Kommunikation, Formulare, Elternrückläufer – P3

- Nachrichten per `conversations`, `participants`, `messages`, `receipts`; Empfänger müssen **beim Versand** berechtigt sein. Beim späteren Lesen Berechtigung erneut prüfen.
- Bei Seriennachrichten vor Versand eine prüfbare Empfängerliste anzeigen. Keine Liste mit fremden Empfängeradressen offenlegen.
- Formulare besitzen **unveränderliche veröffentlichte Versionen**: Antworten bleiben an der zum Abgabezeitpunkt geltenden Frageversion, auch wenn Lehrkraft die Vorlage ändert.
- Antwortmodi: `identified` (z. B. Rückmeldung zu einem Kind), `anonymous` (echte anonyme Umfrage), `verified_declaration` (zusätzlich geeignete Identitäts-/Erklärungsprüfung). Nicht unter „anonym“ unsichtbar personenbezogene Gerätedaten zusammenführen.
- In einer Aktion (z. B. Ausflug) können Rücklauf, Dokument und Zahlung parallel laufen, bleiben aber getrennte Zustände.
- Rückläufe und Kenntnisnahmen sind nicht automatisch rechtswirksame Unterschriften; Anforderungen pro Erklärung durch Schule klären.
- Elternsprechtag-Slots mit serverseitig transaktional verhinderten Doppelbuchungen.

### 6.7 Zahlungslisten und SEPA-QR – P3

Zahlungsmodul ist **Buchungs-/Verwaltungshilfe**, kein Zahlungsdienst.

- Zahlungsforderung je `payment_obligation` mit Betrag, Fälligkeit, Empfängerkonto, individualisierter Zweckreferenz; Verwendungszweck vermeiden, der unnötige Schülerdaten preisgibt.
- QR-Code: EPC-QR für SEPA-Überweisung („GiroCode“) mit validierter IBAN, exaktem Kontoinhaber, Betrag und Zweck. Anzeige aller Daten neben dem QR-Code als Fallback.
- Kontoinhaber muss tatsächlich zum Konto passen; keine willkürlichen Angaben wie „Klasse 7b“ als Empfänger.
- Zahlungseingang wird erst nach manueller Prüfung als `confirmed` markiert; Teilbeträge, Barzahlung, Erstattung und Befreiung getrennt modellieren.
- Abweichende Einzelbeträge und Förder-/Ermäßigungsinformationen nur berechtigten Personen anzeigen.
- Für schulische Gelder ausschließlich durch die Schule freigegebene Zahlungswege/Konten konfigurieren; nicht automatisch ein Privatkonto der Lehrkraft vorschlagen.

### 6.8 Push, Ruhezeiten, Sofortmeldungen und Notfallgrenze – P3

- Push-Abo gerätespezifisch, nur nach Zustimmung und mit HTTPS/Service Worker; mit VAPID verschlüsselt über den jeweiligen Browser-Pushdienst.
- `notifications` sind die verlässliche fachliche Quelle; `notification_deliveries` dokumentieren Versuche, **nicht** garantierte Wahrnehmung.
- Fachliche Klassen: **normale Mitteilungen** (Chat, Klassenbuch, freigegebene Ergebnisse, Planänderung) und **Sicherheitsalarm** (eigenständige Berechtigung, unabhängige geprüfte Alarminfrastruktur). Technisch können normale Meldungen zusätzlich `NORMAL`/`IMPORTANT` unterscheiden; `EMERGENCY_EXTERNAL_ONLY` bleibt Sonderfall und darf nicht als gewöhnlicher Push behandelt werden.
- `NORMAL`: respektiert persönliche Ruhezeiten und Wochenendregeln; kann in Sammelhinweisen aufgehen.
- `IMPORTANT`: kann **TeacherOS-Ruhezeiten** umgehen, nur für berechtigte Absender, Rate Limits und Begründung. Smartphone-Stummschaltung/„Nicht stören“ lässt sich per PWA nicht zuverlässig übergehen.
- `EMERGENCY_EXTERNAL_ONLY`: maximal Auslöser/Anzeige einer **von der Schule geprüften separaten Alarminfrastruktur**; iOS Critical Alerts benötigen besondere Berechtigung/Genehmigung, Android-/Windows-Ton und Nicht-stören sind geräte-/einstellungsabhängig. **Niemals „immer laut“ versprechen.** Zustellung ≠ Hören ≠ persönliche Bestätigung.
- **Vertretungspläne:** Änderungen im Entwurf ausdrücklich nicht als Nachrichtenereignis veröffentlichen. Beim bewussten Sammel-Publish eine planbezogene, benutzerbezogene Änderungsliste bilden und bündeln, nicht pro Solverentscheidung pushen.
> **[Überholt durch 17.16 / Rev. 1]** Keine Bündelung: Teilveröffentlichung einzelner Stunden, sofortige individuelle Mitteilung (17.16 Nr. 2).
- **Transportadapter:** Browser-Web-Push, optional native Pushdienste für iOS/Android und ein separater externer Alarmanbieter implementieren `NotificationChannel`; keine direkte APNs-/FCM-Nutzung aus fachlichen Modulen.
- Sperrbildschirm-Payload generell neutral („Neue Nachricht in TeacherOS“), niemals Gesundheits-, Noten- oder Diagnoseinformationen.
- Push-Abonnements bei Logout, Geräteverlust oder Konto-Deaktivierung widerrufen; verwaiste Endpoints entfernen.

### 6.9 ASV-BW-Import und Schuljahreswechsel – P3

**Annahme:** Eine passende ASV-BW-Exportdatei steht der Schule autorisiert zur Verfügung; das verfügbare und rechtlich zulässige Feldschema wird vor Implementierung mit dem Sekretariat überprüft. Kein direkter Schreibzugriff auf ASV und keine private Shadow-Datenbank als führendes Stammsystem.

**Importablauf als transaktionaler Assistent:**

1. `Upload`: nur Admin/Sekretariat; Quelldatei sicher verarbeiten, Größe/Format prüfen, gehashten Importvorgang anlegen.
2. `Parse`: Header einem dokumentierten Importprofil zuordnen (Schlüssel-ID, Name, Klasse, Status, optionale Kontakte), Format- und Plausibilitätsvalidierung.
3. `Match`: zuerst `(source, school, externe ID)`; unsichere Name-/Geburtsdatumsähnlichkeiten **nur als manuelle Kandidaten**, nie für stillschweigendes Zusammenführen.
4. `Diff/Dry Run`: `new`, `update`, `unchanged`, `missing_in_import`, `conflict`; Auswirkungen auf aktive Klassen und Konten anzeigen.
5. `Review`: Probleme einzeln entscheiden und kritische Austritts-/Elternberechtigungsänderungen gesondert freigeben.
6. `Commit`: atomare fachliche Änderungen und Importprotokoll; beim zweiten Import derselben Datei keine Dubletten oder wiederholten Aktivierungen.
7. `Post-Checks`: Klassenzahlen und Fachgruppenmitgliedschaften prüfen, offene Konflikte anzeigen, Vorher-/Nachher-Bericht bereitstellen.

**Austritt nur bei bestätigter Quelle:** „Datensatz fehlt in importierter Datei“ kann Filterfehler bedeuten; niemals allein daraus Login sperren oder Schüler löschen. Eltern-Kind-Rechte nur nach bestätigter Zuordnung ändern.

**Schuljahreswechsel:** Neues Schuljahr und Klassen erstellen → ASV-Diff prüfen → Klassenmitgliedschaften mit Gültigkeitsdaten aktualisieren → Fachgruppen rekonstruieren → Schülerkonten behalten → ausgeschiedene Konten zum Stichtag deaktivieren → Noten/Beobachtungen dem alten Jahr zugeordnet lassen → Prüfung/Freigabe. Bei Rückkehr bestehende Person anhand sicherer Kennung reaktivieren.

### 6.10 Jahresstundenplan – P4

**Technik:** Python/OR-Tools CP-SAT im internen Solver-Service; PHP erstellt validierten Job mit Snapshot aus Bedarf/Lehrern/Räumen/Regelversion, persistiert Job-ID und verarbeitet Ergebnis nach Bestätigung. Solver bekommt keine unnötigen Schüler- oder Gesundheitsdaten.

**Entscheidungsvariablen (vereinfacht):** `x[u,t,r] ∈ {0,1}` bedeutet Unterrichtseinheit `u` liegt in Zeitfenster `t` in Raum `r`; wenn Lehrkraft noch nicht fixiert ist, weitere Dimension/Zuordnungsvariablen. Mit Kopplungen werden mehrere Einheiten als gemeinsamer Block modelliert.

**Harte Randbedingungen (Beispiele):**

- Vorgesehener Unterrichtsumfang pro Gruppe und Fach wird erfüllt oder als formal begründete Ausnahme gekennzeichnet.
- Keine Doppelbelegung von Lehrkraft, Lerngruppe, notwendiger Teilgruppe oder Raum zur selben Zeit.
- Lehrkraft verfügbar, zugelassen/qualifiziert; Raumkapazität/Ausstattung und verbindliche Sperrzeiten eingehalten.
- Wochenraster, Pausen, Blockdauer, parallele Bänder, Teilungen und festgesetzte Termine berücksichtigt.
- Gesetzliche, dienstrechtliche und verbindlich beschlossene Regeln dürfen **nicht** gegen Strafpunkte verhandelbar werden.

**Weiche Ziele (Beispiele):** hohe Fachraumquote (Zielwert z. B. 90 %, nicht Zwang), wenige Hohlstunden, gleichmäßige Fächerverteilung, nicht unnötig lange Schultage, Lehrkräftewünsche, günstige Raumwege. Alle Gewichte als **versionierte Konfiguration**; Qualitätsbericht mit tatsächlichen Werten je Variante.

**Solver-Ausgabe:** `FEASIBLE/OPTIMAL/INFEASIBLE/UNKNOWN`, Lösungsversion, Verletzungen weicher Ziele, verwendete Regelversion, Laufzeit. Bei Unlösbarkeit Fehlersuche mit minimaler Konfliktmenge/Regelhinweisen anbieten – niemals einen ungültigen Plan als fertig darstellen.

### 6.11 Tagesvertretung, Fairness, Ausfallprioritäten – P4 (aktualisiert 13.11.2026)

Tagesvertretung ist ein eigenständiges Optimierungsproblem gegenüber dem Jahresstundenplan. **Verbindlich gelten die ausführlichen Regeln in Abschnitt 16**, insbesondere 16.1–16.7. Für die Implementierung sind folgende Aussagen maßgebend:

1. Ausgangslage sind der reguläre Stundenplan, aktuell gültige veröffentlichte Vertretungen, Lehrkräfteabwesenheiten einschließlich **Verlängerungen**, Klassen-/Gruppenveranstaltungen, Raumbelegung und Aufsichtspflichten.
2. Der Solver erstellt und reoptimiert einen **Entwurf**. Er darf nicht ungefragt die letzte **veröffentlichte** Version verändern. Es gibt keine mehrstufige Statusmaschine für einzelne Vertretungen.
3. Die Schule konfiguriert die Reihenfolge von Vertretungsquellen. Zulässige Lehrkräfte mit ausgefallenem Eigenunterricht, Vertretungsreserven, ggf. Teamstunden und freien Stunden sind mögliche Quellen. **Unter den geeigneten freien Lehrkräften werden organisatorische Minusstunden bevorzugt**, ohne rechtswidrige Verrechnungen zu erzeugen.
4. Fachbefähigung ist je Schule/Fach/Tätigkeit `off`, `prefer` oder `required`; unabhängig davon bleiben gesetzliche oder sicherheitstechnische Voraussetzungen nicht umgehbar.
5. Solver optimiert **zusammenhängende Stunden**, weitere Unterrichtsgruppen, Räume, Pausenaufsichten, Wege und Planstabilität gemeinsam. Eine passende Doppelstundenvertretung ist ein gewichteter Vorteil; bestehende veröffentlichte Zuordnungen sind nicht automatisch fixiert.
6. **Jede manuelle Änderung** wird sofort validiert; der restliche betroffene Entwurf wird erneut geprüft und bei Bedarf neu optimiert. Manuell fixierte Entscheidungen sind Constraints, kein weiterer Status.
7. Ersatzraumstrategie: Ursprungsraum, **freies und geeignetes Stammklassenzimmer (KlaZi)**, weitere Fach-/Klassenräume, benachbarte Räume, zulässiger Raumtausch, bei Bedarf geplante Unterrichtsform anpassen. Hard Constraints dürfen nicht verletzt werden.
8. **Pausenaufsichten** müssen ebenfalls vertreten oder – bei geprüft ausreichender Aufsicht – zusammengelegt werden. Parallele Gruppen dürfen nur in zulässiger organisatorischer Kombination geführt werden; eine Fachkraft im Nachbarraum ersetzt nicht jede erforderliche Fachaufsicht.
9. Entwurfsänderungen erzeugen **keine** Pushnachrichten. Ein berechtigter Nutzer prüft vor Freigabe den Diff und veröffentlicht die **gesamte Planung manuell und atomar**. Betroffene erhalten danach gebündelte Änderungsnachrichten; alte Version bleibt revisionssicher.
> **[Überholt durch 17.16 / Rev. 1]** Ersetzt durch Teilveröffentlichung ausgewählter Stunden mit sofortiger individueller Mitteilung (17.4, 17.16 Nr. 2).
10. Tatsächlich gehaltene Stunden werden nicht durch spätere Solverläufe umgeschrieben. Soll, Veröffentlichungen und Ist-Daten sind eigene Wahrheiten und Grundlage der Jahresstatistik.

**Zielhierarchie:** (a) Sicherheit/Recht/Verfügbarkeit als nicht verhandelbare Constraints; (b) erforderliche Abdeckung und verantwortbare Aufsicht; (c) schulische Quellenreihenfolge und fachliche Qualität; (d) faire Belastung, Blockkontinuität, Raumqualität und stabile Planung. Die Schule darf Gewichte innerhalb zulässiger Bereiche konfigurieren. Konflikte werden mit begründeten Alternativen angezeigt und von einem Menschen entschieden.

## 7. Benutzeroberflächen und Interaktionsdesign

### 7.1 Desktop (Browser, große Datenmengen)

- Hauptnavigation mit Modulen; Verwaltungsseiten besitzen serverseitige Suche/Filter/Sortierung, Bulk-Aktionen, Export, Undo soweit möglich.
- Große Tabellen: Virtualisierung und/oder serverseitige Paginierung; Spaltenauswahl, gespeicherte Ansichten, Tastaturzugriff und Statusindikatoren.
- Notenbuch und Zahlungslisten mit editierbaren Tabellenzellen, klarer Speicherung/Fehleranzeige und Undo-Protokoll.
- Schuljahres-/ASV-Import als Wizard mit Vorher-/Nachher-Ansicht und explizitem Commit.
- Stundenplan- und Vertretungsplanung als getrennte Arbeitsflächen mit Konfliktliste und Varianten. **Vertretungsplanung hat nur Entwurf vs. veröffentlichte Version**: manuelle Änderung → sofortiger Folgecheck → Diff/Vorschau → ausdrückliche Sammelveröffentlichung. Lehrkräfte-Abwesenheiten können in derselben Maske verlängert/verkürzt werden.

### 7.2 Mobile/PWA

- Startseite „Meine nächste Unterrichtsstunde“ mit Aktionen **Anwesenheit**, **ICE**, **Einzelbeobachtung**, **Sitzplan**, **Klassenbucheintrag**.
- Touchflächen ausreichend groß; Bedienung im Sitzen/Stehen und möglichst mit einer Hand. In ICE möglichst eine Aktion pro SuS, Zurück/Überspringen jederzeit sichtbar.
- Schülerkarten mit knapper, berechtigter Handlungsinformation und Beobachtungspriorität; keine umfassenden Diagnosen/Leistungsakten auf Übersichtsbildschirmen.
- Eltern- und Schülerportal: Nachrichten, Rückläufer, Aufgaben, freigegebenes Feedback und Zahlungen mobil zuerst.
- Push opt-in je Gerät; installierte PWA unter Android unterstützt, iOS/iPadOS für Web-Push nur unter den jeweils geltenden Bedingungen (insbesondere zum Home-Bildschirm hinzugefügt). **Optionaler Capacitor-Wrapper** für iOS/Android mit gemeinsamen React-Screens und nativen Pushadaptern; Windows zunächst PWA. Gerätetests inklusive Hintergrundverhalten und Berechtigungswiderruf sind Pflicht.
- **Offline zunächst nur App-Shell, keine sensiblen Nutzdaten in Cache/LocalStorage/IndexedDB.** Später optional explizit genehmigter verschlüsselter Offline-Entwurfsmodus mit Ablaufzeit und Konfliktauflösung.

### 7.3 Zugänglichkeit

- Tastaturbedienung, Fokusreihenfolge, sichtbare Fokusmarkierung, Screenreader-Labels und ausreichende Kontraste.
- Status nicht nur über Farbe vermitteln; `+`, `=`, `−` mit Klartext zugänglich machen.
- Keine zwingenden Wischgesten oder winzigen Icons für sicherheits- bzw. bewertungsrelevante Aktionen.

## 8. API-Skizze und Ereignisverträge

Alle Routen sind Vorschläge und verwenden `/api/v1`. Server prüft Authentifizierung, School-/Group-Scope und objektbezogene Berechtigung; ein `studentId` im Request berechtigt niemals schon zum Zugriff.

### 8.1 Zentrale Endpunkte

| Method / Route | Phase | Zweck / wichtige Prüfungen |
|---|---|---|
| `GET /school-years`, `POST /school-years` | P0 | Schuljahr anzeigen/erstellen; Schreibrecht nur Administration. |
| `GET /students?class_id=...`, `POST /students` | P0 | Paginierte Schüler; Fachgruppen-/Klassenrechte prüfen. |
| `POST /students/imports/preview` | P0/P3 | Datei prüfen, Differenzen liefern; noch keine Daten ändern. |
| `POST /students/imports/{id}/commit` | P0/P3 | Idempotenter Commit nach autorisierter Freigabe. |
| `GET /teaching-groups/{id}/students` | P0 | Aktive Mitgliedschaften zum Zeitpunkt laden. |
| `GET /assessment-schemes?group_id=...` | P1 | Raster samt Version und Kriterien lesen. |
| `POST /observation-sessions` | P1 | ICE-/Einzel-Session mit Gruppe, Datum, Rasterversion starten. |
| `PUT /observation-sessions/{id}/students/{studentId}` | P1 | Ratings und Notiz atomar/versionsgeschützt setzen oder ändern. |
| `POST /observation-sessions/{id}/finalize` | P1 | Session überprüfen/abschließen; keine Pflicht zur Komplettbewertung. |
| `GET /teaching-groups/{id}/observation-coverage` | P1 | Zählwerte, letzte Beobachtung, Priorisierung. |
| `GET /students/{id}/performance?group_id=...` | P1 | Fachgebundene Auswertung mit autorisiertem Scope. |
| `GET/PUT /teaching-groups/{id}/seat-layouts/{layoutId}` | P1 | Sitzplan-Version laden/ändern. |
| `GET/POST /students/{id}/measures` | P1/P3 | Geschützte Maßnahmen; zuständige Stelle/Fristen prüfen. |
| `POST /assessments`, `POST /assessments/{id}/results` | P1/P2 | Leistungsnachweise erstellen und Ergebnisse speichern. |
| `GET /entry-types`, `POST /entry-types` | P1 | Schulspezifische, versionierte Arten abrufen/anlegen; Berechtigungen, Löschsperren. |
| `POST /entries`, `PATCH /entries/{id}` | P1 | Fach/Allgemein, schuleigene Art und sichere Sichtbarkeitsdefaults validieren; optional Stundenbezug. |
| `POST /entries/{id}/publish` | P3 | Für konkrete Zielgruppen autorisiert freigeben; Version, Empfängerzuordnung und Outbox prüfen. |
| `GET /assessment-methods`, `GET /assessment-policies` | P1/P2 | Verfügbare registrierte Methoden und versionierte Regeln anhand von Modul/Schulkonfiguration/Berechtigung liefern. |
| `POST /assessments/{id}/attempts` | P1/P2 | Versuch mit Variante/Niveau, Punkten, Status und Versionsbindung anlegen; Idempotenz. |
| `GET /assessments/{id}/level-outcomes?student_id=...` | P2 | Bestes gültiges Ergebnis **je Niveau** inklusive gewählter Strategie und berechneter Begründung. |
| `GET /grade-schemes`, `POST /grade-schemes/{id}/versions` | P1 | Notenschlüssel abrufen oder neue unveränderliche Version anlegen. |
| `GET /feedback-templates`, `POST /feedback-drafts` | P1 | Vorlage auswählen, Platzhalter validieren und vor Veröffentlichung gerenderten Text speichern. |
| `POST /feedback-drafts/{id}/publish` | P3 | Freigabe für SuS/EZB, Snapshot und Benachrichtigungs-Outbox in einer Transaktion. |
| `POST /reports/export-jobs` | P1/P3 | Gewählten validierten Exportadapter mit versioniertem Mapping starten, Ergebnisse nur Berechtigten anbieten. |
| `GET /lessons?from=...&to=...` | P2 | Termine samt Status und Unterlagen laden. |
| `POST /lessons/{id}/attendance-checks` | P2/P3 | Anwesenheitskontrolle bestätigen; Idempotenz/Version. |
| `POST /absence-notices` | P3 | Elternmeldungen zu berechtigtem Kind. |
| `GET /office/absence-cases` | P3 | Berechtigte Sekretariatsfälle lesen/filtern. |
| `POST /conversations/{id}/messages` | P3 | Berechtigten Empfänger ermitteln; Versand/Outbox. |
| `POST /forms/{id}/submissions` | P3 | Antworten zur veröffentlichten Formversion/Identität prüfen. |
| `POST /payments/{id}/entries` | P3 | Manuelle Zahlung unter Audit und Rollenprüfung buchen. |
| `POST /push/subscriptions` | P3 | Geräteabo registrieren; nur nach Einwilligung. |
| `POST /timetable-runs` | P4 | Versionierte Solver-Jobs erzeugen. |
| `POST /substitution-runs` | P4 | Vertretungsberechnung aus veröffentlichtem Snapshot. |
| `POST /substitution-plans/{id}/publish` | P4 | Freigabe mit Rechteprüfung, Versionswechsel und Outbox. |

**Zusätzliche Endpunkte für die schulweite Planung und Jahresauswertung (Zielvertrag P3/P4):**

| Method / Route | Zweck / Seiteneffekte |
|---|---|
| `POST /teacher-absences` | Abwesenheit mit Kategorie, Beginn/Ende, Berechtigung anlegen; Auswirkungen als Vorschau liefern. |
| `PATCH /teacher-absences/{id}` | **Bestehende Fehlzeit verlängern/verkürzen/korrigieren**, `If-Match`/Version prüfen, Revision + `TeacherAbsenceChanged` atomar persistieren. |
| `GET /teacher-absences/{id}/impact-preview` | Diff alter/neuer betroffener Unterrichts-/Aufsichtsslots, ggf. neu aufkommender Bedarf. |
| `GET/POST /teacher-absence-categories` | Schulische Kategorien verwalten, historisieren und Sichtbarkeit absichern. |
| `GET/POST /school-event-categories` | Schuleigene Veranstaltungstypen mit Statistikabbildung verwalten. |
| `POST /school-events/{id}/impact-preview` | Betroffene Klassen/Gruppen, Begleitpersonen und Stunden als Vorschau auswerten. |
| `POST /lessons/{id}/temporary-assignments` | SuS zeitlich in Gastunterricht übernehmen; Ursprungskonflikte ermitteln, Präsenz noch nicht behaupten. |
| `POST /temporary-assignments/{id}/confirm-presence` | Tatsächliche Anwesenheit beim aufnehmenden Kollegen bestätigen; Ursprung und Sekretariat synchronisieren. |
| `GET /substitution-plans/{date}/draft` | Bearbeitbaren aktuellen Entwurf holen, inklusive Abgleich zur aktiven Veröffentlichung. |
| `PATCH /substitution-plans/{date}/draft/assignments/{id}` | Manuelle Änderung und **sofortige Folgevalidierung**, ggf. inkrementelle Neuoptimierung, aber **kein Push**. |
| `POST /substitution-plans/{date}/draft/optimize` | Solver auf gültigem Snapshot ausführen; manuell fixierte Einträge und Konflikte beachten. |
| `GET /substitution-plans/{date}/publication-preview` | Vollständigen Versionsdiff, Empfänger und Warnungen ohne Veröffentlichung anzeigen. |
| `POST /substitution-plans/{date}/publish` | Mit expliziter Berechtigung/Bestätigung atomar neue Vollversion setzen und gebündelte Benachrichtigungen in Outbox einreihen. **[Überholt durch 17.16 / Rev. 1]** Statt Vollversion: Veröffentlichung ausgewählter Stunden; pro Stunde Wahl *unveröffentlicht / ungelöst / ausgefallen* (17.4, 17.16 Nr. 2). |
| `GET /planning/rooms/alternatives` | Geeignete Ersatzräume gemäß Raumregeln, KlaZi- und Tauschstrategien prüfen. |
| `GET /analytics/school-years/{id}/summary` | Aggregierte Auswertungen mit dokumentierten Zähler-/Nennerdefinitionen. |
| `POST /analytics/school-years/{id}/snapshots` | Freigegebene, reproduzierbare Statistikversion mit Regel-/Datenstand erzeugen. |
| `POST /analytics/school-years/{id}/exports` | Berechtigungsgerechten CSV-/XLSX-/PDF-Jahresbericht per Exportadapter erstellen. |

Alle schreibenden, solverauslösenden oder veröffentlichenden Endpunkte benötigen Autorisierung, fachliche Validierung, Versions-/Idempotenzschutz und Audit. Ein vorgeschlagener API-Vertrag ist keine Aussage über sofort vorhandene Implementierungen.

### 8.2 Beispiel: Beobachtungs-Update

```json
{
  "student_id": "stu_10427",
  "expected_version": 2,
  "ratings": [
    { "criterion_key": "mitarbeit", "level": "+" },
    { "criterion_key": "fachlichkeit", "level": "=" }
  ],
  "note": "Erklärt den Aufbau des Versuchs nachvollziehbar."
}
```

`experimentieren` fehlt bewusst: Für dieses Kriterium wurde in der Session **keine** Leistung bewertet. Der Vertrag muss festlegen, ob ein `PUT` die Bewertungen komplett ersetzt (empfohlen) oder Teilupdates erlaubt; mischt man beide Semantiken, entstehen leicht stille Datenverluste.

### 8.3 Domain-Events (versionierte Datenverträge)

| Event | Auslöser | Mögliche Listener |
|---|---|---|
| `ObservationRecorded.v1` | ICE/Einzelbeobachtung gespeichert | Beobachtungsabdeckung aktualisieren, Auswertung invalidieren |
| `AssessmentPublished.v1` | Freigegebene Leistungsrückmeldung | Portal sichtbar schalten, generische Benachrichtigung |
| `EntryPublished.v1` | Eintrag zielgruppenspezifisch freigegeben | Berechtigte persönliche Ansicht aktualisieren, ggf. neutrale Push-Meldung |
| `AssessmentAttemptRecorded.v1` | Neuer gültiger/ungeprüfter Versuch erfasst | Abgeleitetes bestes Ergebnis **pro Niveau** invalidieren und neu berechnen |
| `FeedbackPublished.v1` | Bewertungsrückmeldung als Text-Snapshot freigegeben | Persönliche Leistungsübersicht aktualisieren, neutrale Push-Meldung |
| `LessonDocumented.v1` | Klassenbuch abgeschlossen | Soll-/Ist-Fortschritt aktualisieren |
| `AbsenceNoticeSubmitted.v1` | Elternmeldung eingegeben | Sekretariats-/Abgleichprozess anstoßen |
| `AttendanceCheckConfirmed.v1` | Lehrkraftkontrolle abgeschlossen | Ungeklärte Abwesenheiten ermitteln |
| `FormSubmitted.v1` | Rücklauf eingereicht | Rücklaufquote aktualisieren, zuständige Stelle informieren |
| `MessageSent.v1` | Nachricht erfolgreich gespeichert | Web-Push-Job einreihen |
| `SchoolYearTransitionApproved.v1` | Jahresschritt freigegeben | Mitgliedschaften/Kontoberechtigungen aktualisieren |
| `TimetablePublished.v1` | Jahresplan freigegeben | Unterrichtstermine auf Planänderung abgleichen |
| `SubstitutionPlanPublished.v1` | **Manuelle Sammelveröffentlichung** eines vollständigen Plan-Snapshots | Diff zu Vorgängerversion, gebündelte Push-Nachrichten an tatsächlich Betroffene |
| `TeacherAbsenceChanged.v1` | Fehlzeit verlängert, verkürzt, berichtigt oder neu angelegt | Impact-Diff berechnen, Entwurf invalidieren/reoptimieren; **kein Kollegiums-Push** |
| `SchoolEventChanged.v1` | Schulveranstaltung/Teilnehmer/Zeitraum verändert | Gruppen-/Raum-/Lehrkraft-Belegungen und Vertretungsentwurf aktualisieren |
| `TemporaryLessonPresenceConfirmed.v1` | Aufnehmende Lehrkraft bestätigt tatsächliche Präsenz | Regulären Unterricht auf „an anderem Ort“ stellen, Sekretariatsfrist prüfen |
| `SubstitutionDraftRecalculated.v1` | Manuelle Änderung oder neuer Ausfall führt zu internem Replan | Nur Planungsansicht/Validierung und Konfliktliste aktualisieren; **niemals Push** |
| `PlanningActualRecorded.v1` | Durchgeführte Stunde/Aufsicht/Veranstaltung dokumentiert | Statistikprojektionen und Jahresbericht aktualisieren |
| `StatisticsSnapshotApproved.v1` | Berechtigte Person genehmigt Jahresstatistik-Revision | Geschützte Exportjobs vorbereiten; keine automatische externe Verteilung |

**Zuverlässigkeitsregel:** Bei Änderungen in derselben DB-Transaktion einen `outbox_events`-Datensatz schreiben; Worker verarbeitet mit Retries und deduplizierten Event-IDs. `at-least-once`-Zustellung unterstellen und Consumer idempotent programmieren. Niemals die fachliche Transaktion von einer erfolgreichen Push-Zustellung abhängig machen.

## 9. Sicherheit, Datenschutz und Rollen

### 9.1 Sensibilitätsstufen und Zugriffsmatrix

| Datenbereich | Einzelne LK (P1/P2) | Weitere Fach-LK | Klassenleitung | Sekretariat | Eltern/SuS |
|---|---|---|---|---|---|
| Stammdaten/Fachgruppe | Eigener Scope | Zugeordnete Gruppen | Berechtigte Klasse | Erforderlich für Aufgaben | Nur eigene Daten |
| Einzelbeobachtungen und interne Notizen | Eigener Scope | Nur bei expliziter Freigabe/Zuständigkeit | Nicht pauschal | Grundsätzlich nein | Grundsätzlich nein |
| Noten und Feedback | Eigener Scope | Zuständige Fachgruppe | Nur erforderliche/zulässige Sicht | Nur falls dienstlich erforderlich | Nur explizit veröffentlichtes eigenes Feedback |
| Nachteilsausgleich, Attestpflicht | Erforderliche **Maßnahme** im zuständigen Fach | Maßnahme nach Bedarf | Erforderliche Maßnahme | Attest-Verwaltungsstatus nach Aufgabe | Eigene Informationen im zulässigen Umfang |
| Krankmeldung / Fehlzeit | Für eigenen Unterricht notwendiger Status | Eigener Unterricht | Berechtigte Klasse | Bearbeitung | Nur eigene Meldungen |
| Gesprächsnotizen | Zuständig | Grundsätzlich nein | Nach Zweck und Berechtigung | Grundsätzlich nein | Kein pauschaler Einblick |
| Zahlungslisten | Listenverantwortliche | Freigegebene Mitverantwortliche | Falls berechtigt | Nach Zuständigkeit | Eigene Forderungen/Status |
| Personal-, Stundenplan- und Vertretungsdaten | Eigener Plan | Eigenen Plan | Ggf. Gruppenplan | Dienstlich benötigter Plan | Nur freigegebener relevanter Plan |

Diese Tabelle ist **nur ein Produktentwurf**; vor Produktivbetrieb müssen Schulleitung/Datenschutz die tatsächlichen Zuständigkeiten und berechtigten Zwecke pro Datenart festlegen. „Lehrerrolle“ allein darf kein schulweites Leserecht begründen.

### 9.2 Mindestmaßnahmen

- HTTPS/TLS, sichere Cookies (`HttpOnly`, `Secure`, `SameSite`), CSRF-Schutz bei Cookie-Sessions, Rate Limiting und Brute-Force-Abwehr.
- Lokale Passwörter ausschließlich mit Argon2id und geeigneten Parametern hashen; zeitlich begrenzte, gehashte Aktivierungs-/Reset-Tokens; SSO bevorzugen, wenn offiziell verfügbar.
- Konsequente Objekt-Autorisierung auf jedem CRUD-, Export-, Datei- und Websocket-/Push-bezogenen Endpunkt. Direkte ID-Manipulation darf keine fremden Kinder-/Lehrkraftdaten offenlegen (IDOR).
- Entzug von Berechtigungen/Schulabgang sperrt Sessions und Push-Abos; bereits ausgelieferte Dokumente können dadurch nicht automatisch zurückgeholt werden.
- Sorgfältige Abtrennung besonders schützenswerter Maßnahmen und Nachweise; sparsame Datenerhebung und restriktive Freitextführung.
- Lösch-/Aufbewahrungsregeln nach Datenart und Rechtsgrundlage, inklusive Backups und Exportkopien. Nicht pauschal sämtliche Daten bis zum Schulende aufbewahren und dann vollständig löschen.
- Protokollierung für Notenänderungen, Importe, Berechtigungswechsel, Atteststatus, Zahlungen und Freigaben; Zugriff auf Auditlogs selbst beschränken.
- Backups verschlüsseln, Restore regelmäßig **wirklich testen**; P0 bereits mit Revisions-/Notfallverfahren.
- PWA-Service-Worker darf authentifizierte Schülerakten standardmäßig nicht offline cachen; lokale Debug-/Analytics-Logs frei von personenbezogenen Rohdaten halten.
- Trennung von Entwicklungs-/Testdaten und produktiven Daten. Mit echten Schul-/Gesundheitsdaten niemals ungeprüft einen privaten Testserver oder externe Coding-/KI-Dienste beschicken.
- Externe Auftragsverarbeiter, Hosting, Aufbewahrung, Datenflüsse und mögliches Datenschutz-Folgenabschätzungsbedürfnis im schulischen Verfahren prüfen.

### 9.3 Sicherheitskritische Grenze für Anwesenheit und Notfall

Fehlende Erststunden-Kontrolle ist ein **eigener Alarmtyp** und darf nicht als bestätigte Schülerabwesenheit dargestellt werden. Externe Notfallalarmierung (Brand, Gewaltlage) erfordert schulisches Alarmkonzept, redundante Meldewege, regelmäßige Funktionstests und geeignete Lösung; Web Push im allgemeinen Lehrer-Cockpit genügt dafür ausdrücklich nicht.

## 10. Qualitätssicherung: Teststrategie und Abnahmekriterien

**Jedes Ticket liefert:** Anforderung → API/DB-Migration → Implementierung → automatisierte Tests → manuelles Akzeptanzszenario → Dokumentation/Changelog. Claude Code erzeugt Code und erste Tests, aber die fachliche Freigabe bleibt bei der Entwicklerin/dem Entwickler bzw. schulisch zuständigen Person.

| Testklasse | Werkzeug | Ziel |
|---|---|---|
| Unit/Fachlogik | PHPUnit, Frontend-Testframework | Skalen, Notenschlüssel, Datenabdeckung, Gültigkeitsregeln, Berechtigungspolitik |
| API/Integration | PHPUnit + Test-DB | Transaktionen, Foreign Keys, Import-Diffs, Idempotenz, Rechte/Scope |
| UI/E2E | Playwright | ICE, Sitzplan, Formulare, Anmeldung, Rückläufer, Import-Freigabe |
| Mobile-Bedienung | reale Android-/iOS-Geräte | Touch, Layout, Scrollen, Push-Anmeldung, PWA-Installation, Unterbrechungen |
| Sicherheits-Regression | automatisierte Negativtests + manuelle Review | IDOR, CSRF, XSS, fremde Gruppen, Session-Entzug, Exportrechte |
| Solver-Tests | OR-Tools + generierte Instanzen | Kein harter Konflikt, faire Kandidatenwahl, nachvollziehbare Unlösbarkeit |
| Betrieb | Backup-/Restore-/Update-Proben | Reproduzierbarer Deploy, Datenintegrität, Recovery und Rollback |

### 10.1 Muss-Tests für P0/P1

- [ ] **P0-01:** Identische CSV zweimal importiert → keine Dubletten/zweiten Accounts.
- [ ] **P0-02:** Zwei gleichnamige SuS erhalten getrennte interne IDs; nicht eindeutiger Import blockiert automatische Zuordnung.
- [ ] **P0-03:** SuS wechselt Klasse/Fachgruppe → alte Beobachtungen bleiben korrekt historisiert.
- [ ] **P0-04:** Unberechtigte Nutzer-/Gruppen-ID liefert 403/404 ohne personenbezogene Leckage.
- [ ] **P1-01:** ICE `skip` erzeugt keine neutrale Bewertung und erhöht die fachliche Beobachtungszahl nicht.
- [ ] **P1-02:** Ein Kriterium bewertet, andere leer → nur eine Rating-Zeile, keine impliziten Defaults.
- [ ] **P1-03:** Wechsel Sitzplan ↔ Liste erhält in Arbeit befindliche Einträge.
- [ ] **P1-04:** Doppeltes „Weiter/Speichern“ führt nicht zu doppelter Erfassung.
- [ ] **P1-05:** Änderungen an Raster-/Sitzplanversion zerstören keine alten Beobachtungen.
- [ ] **P1-06:** Positive Beobachtung bei ruhigem SuS möglich; bloß seltenes Melden führt nicht zwangsläufig zur Negativwertung.
- [ ] **P1-07:** Auswertungen unterscheiden Anzahl Beobachtungstermine, Ereignisse und Kriterienwerte.
- [ ] **P1-08:** Geringe Datenbasis erscheint als „wenig beobachtet“, nicht als ungerechtfertigt präzise Endnote.
- [ ] **P1-09:** Nachteilsausgleich erscheint nur im gültigen Fach-/Prüfungs- und Zeitbereich.
- [ ] **P1-10:** Zeugnisentwurf ist editierbar, speichert seine Quellenbasis und wird nicht automatisch veröffentlicht.
- [ ] **P1-11:** Mobile ICE-Bedienung für 25+ fiktive SuS in realistischer Unterrichtspause testen; keine gravierenden Bedienhindernisse.
- [ ] **P1-12:** Backup-Datei lässt sich in eine isolierte Testumgebung fehlerfrei zurückspielen.

### 10.1.1 Erweiterbarkeit, Eintragsarten und Notenlogik: zusätzliche Muss-Tests

- [ ] **EXT-01:** Neue Bewertungsmethode wird über Registry angemeldet, ohne ICE-Quellcode und zentrale Datenmodelle zu verändern; API-/UI-/Auswertungsvertrag bleibt stabil.
- [ ] **EXT-02:** Neues Fachmodul kann schulweise aktiviert, deaktiviert und auf eine neue Version aktualisiert werden; unbekannte oder deaktivierte API-Endpunkte/Jobs bleiben gesperrt.
- [ ] **EXT-03:** Versionswechsel einer Methode/Regel verändert veröffentlichte historische Ergebnisse nicht; Rückwärtslesbarkeit und Migration werden getestet.
- [ ] **ENT-01:** Fach wird beim Erstellen aus Unterrichtsstunde korrekt übernommen; ohne Stundenkontext kann bewusst „Allgemein“ gewählt werden; manipulierte fachfremde IDs werden abgelehnt.
- [ ] **ENT-02:** Schule kann Eintragsart umbenennen/ergänzen; bisherige Einträge bleiben richtig zugeordnet und archivierte Typen werden nicht neu verwendet.
- [ ] **ENT-03:** Neuer Eintrag wird nicht automatisch extern sichtbar; `students=true` und `guardians=true` wirken erst nach expliziter Freigabe und korrekter Berechtigung.
- [ ] **ENT-04:** Eltern ohne geprüfte Zuordnung, fachfremde Lehrkräfte oder Schüler eines anderen Accounts erhalten via API/Export keine fremden Einträge – auch bei manuell manipulierten Sichtbarkeitsflags.
- [ ] **GRADE-01:** `15/31` zeigt **48 %**, `20/31` zeigt **65 %**, `26/31` zeigt **84 %`; Ausgabe in UI, PDF, exportiertem Text und Portal ist identisch.
- [ ] **GRADE-02:** `79,6 %` wird als **80 %** angezeigt, besteht aber eine Freischalt-/Notenschwelle von exakt `80,00 %` nicht, sofern Schlüssel nicht ausdrücklich anders definiert.
- [ ] **GRADE-03:** Zwei F-Versuche werden innerhalb F verglichen; ein M-Versuch bleibt getrennt, und keine Methode markiert über Niveaugrenzen unbegründet eine „beste Note“.
- [ ] **GRADE-04:** Entschuldigter oder noch fehlender Versuch erzeugt keine 0-Punkte-Leistung; mehrmaliger Request erzeugt keine doppelte Version.
- [ ] **GRADE-05:** Änderung einer verwendeten Notenschlüssel- oder Vorlagenversion überschreibt bestehende freigegebene Note/Rückmeldung nicht.
- [ ] **FEED-01:** Drei Vorlagen verfügbar; Lehrkraft wählt, bearbeitet und veröffentlicht; Eltern/SuS sehen nur berechtigte, freigegebene Snapshots.
- [ ] **REP-01:** Nicht unterstütztes Zeugnis-Zielformat wird abgelehnt bzw. als generischer Export angeboten, aber niemals als bestätigter Import dargestellt.


### 10.2 Muss-Tests bei späteren Erweiterungen

- [ ] **P3-01:** Elternteil mit zwei Kindern sieht nur die freigegebenen Informationen dieser Kinder.
- [ ] **P3-02:** Entzug einer Elternberechtigung blockiert bestehende Sessions und künftige Abrufe.
- [ ] **P3-03:** Fehlende Anwesenheitskontrolle löst **keine** Meldung „Kind fehlt ungeklärt“ aus.
- [ ] **P3-04:** Tatsächlich fehlendes Kind ohne Meldung erstellt **genau einen** Sekretariatsfall trotz Retry.
- [ ] **P3-05:** Formularversion bleibt für bereits eingegangene Antworten stabil.
- [ ] **P3-06:** Anonyme Umfrage enthält keine ungewollte Rückzuordnung über UI/API/Export.
- [ ] **P3-07:** SEPA-QR kodiert dieselben validierten Werte, die in der Zahlungsübersicht stehen.
- [ ] **P3-08:** Manuelle Zahlung wird durch QR-Scan **nicht** automatisch als bezahlt geführt.
- [ ] **P3-09:** Ruhezeit verzögert normale Push; Sofortnachricht wird nur bei Berechtigung unmittelbar versucht.
- [ ] **P3-10:** Deaktivierter Schüleraccount kann kein persönliches Feedback mehr abrufen.
- [ ] **P4-01:** Jahresplan enthält keine Doppelbelegung für Raum, Klasse und Lehrkraft.
- [ ] **P4-02:** Unlösbares Solver-Szenario produziert einen erklärten Konflikt, keinen fehlerhaften Plan.
- [ ] **P4-03:** Klassen-/Lehrkräfte-Ausplanung zählt eine betroffene Stunde nicht doppelt.
- [ ] **P4-04:** Teilzeit-/Schutzvorgaben wirken als harte Grenzen vor Fairnesswerten.
- [ ] **P4-05:** Veröffentlichung eines Vertretungsplans ist atomar und protokolliert; alte Planversion bleibt nachvollziehbar.
> **[Überholt durch 17.16 / Rev. 1]** Atomar ist die jeweils gewählte Teilveröffentlichung, nicht der Gesamtentwurf (17.16 Nr. 2).

### 10.3 Neue verbindliche Abnahmetests – Planung, Fehlzeiten, Events und Statistik (13.11.2026)

- [ ] **SUB-06:** Durch manuelle Änderung einer Vertretung im Entwurf wird der restliche betroffene Tagesplan **sofort auf Konflikte geprüft**; ungeeignete Folgezuweisungen werden korrigiert oder markiert. Die manuelle Wahl bleibt erhalten, solange sie zulässig und vom Nutzer fixiert ist.
- [ ] **SUB-07:** 20 Solver-Iterationen, manuelle Verschiebungen und Raumwechsel im Entwurf erzeugen **null** Push-/E-Mail-/Portaländerungsbenachrichtigungen an Kollegium/SuS.
- [ ] **SUB-08:** Manuelle Sammelveröffentlichung erzeugt **genau eine neue aktive Vollversion**; Benachrichtigungen enthalten nur tatsächlich veränderte, relevante Zuordnungen. Ein erneuter identischer Publish/Retry erzeugt keine Doppelbenachrichtigung.
- [ ] **SUB-09:** Veröffentlichte zukünftige Vertretung darf bei Reoptimierung im **neuen Entwurf** anders besetzt werden; vergangene tatsächlich geleistete Stunde bleibt unverändert.
- [ ] **SUB-10:** Bei zwei zusammenhängenden Vertretungsstunden und gleichwertigen Kandidaten gewinnt nach aktivierter Gewichtung die durchgehende Fachlehrkraft; bei zwingender Gegenbedingung ist eine Aufteilung zulässig.
- [ ] **SUB-11:** Bei geeigneten freien Lehrkräften mit Salden −3/−1/+2 wird die mit −3 bevorzugt, sofern Schule diese Regel aktiviert hat; gesetzliche Schutzrechte und Fachpflichten bleiben vorrangig.
- [ ] **SUB-12:** Schulische Fachbefähigung `off`, `prefer`, `required` führt zu erwartbaren Kandidatenmengen/Sortierungen; zwingende Sicherheitsqualifikation lässt sich durch `off` nicht umgehen.
- [ ] **ABS-01:** Eine Lehrkraft ist Montag bis 3. Stunde fehlend; Verlängerung bis 6. Stunde erzeugt **zusätzliche Auswirkungen für Stunden 4–6** und neue Vertretungslücken, keine zweite Abwesenheits-ID und keine doppelten Stunden 1–3.
- [ ] **ABS-02:** Verlängerung eines mehrtägigen Ausfalls über ein Wochenende berücksichtigt nur tatsächlich geplante Unterrichts-/Aufsichtstermine, ohne pauschal jeden Kalendertag als ausgefallene Stunde zu zählen.
- [ ] **ABS-03:** Verkürzung/Berichtigung entfernt nicht mehr benötigte zukünftige Vertretungsbedarfe aus dem **Entwurf**; die veröffentlichte Planversion bleibt bis zum nächsten Publish unverändert.
- [ ] **ABS-04:** Zwei Bearbeiter verlängern gleichzeitig dieselbe Fehlzeit: veralteter `If-Match` wird abgewiesen und Konflikt angezeigt; Änderungsverlauf ist vollständig und zugriffsgeschützt.
- [ ] **ABS-05:** Verlängerung eines Ausfalls einer auf Klassenfahrt eingesetzten Lehrkraft erzeugt keine falsche zusätzliche doppelte Vertretung; bereits stattgefundene Stunden werden nicht rückwirkend neu geplant.
- [ ] **ROM-01:** Ein ungeeigneter Fachraum führt zu zulässiger KlaZi-Alternative; bei zu kleiner Kapazität wird auch KlaZi abgelehnt, obwohl er in der Suchreihenfolge höher steht.
- [ ] **ROM-02:** Parallele Technikgruppen dürfen bei fehlender Fachlehrkraft nur mit fachlich/sicherheitlich zulässiger Tätigkeit und geeigneter direkter Aufsicht kombiniert werden; reine Nachbarschaft von Räumen genügt nicht.
- [ ] **SUP-01:** Pausenaufsicht im Bereich A fällt aus; zulässiger Ersatz bzw. geprüfte Zusammenlegung mit Bereich B wird vorgeschlagen. Eine nicht einsehbare Gefahrenstelle verhindert die Zusammenlegung.
- [ ] **EVT-01:** Klassenfahrt belegt Unterrichtszeit der Klasse im Plan, blockiert Begleitpersonen und gibt andere Lehrkräfte mit ausschließlich entfallenden Eigenstunden potentiell frei. Unterrichtsausfall- und Veranstaltungsstunden werden nicht doppelt gezählt.
- [ ] **EVT-02:** Umbenennen einer schulischen Fehlzeiten-/Eventkategorie verändert historische Kategorienamen/Statistikzuordnungen eines bereits freigegebenen Schuljahrsberichts nicht.
- [ ] **ATT-15:** Temporär übernommener Schüler wird nach Präsenzbestätigung im Ursprung als „anwesend – anderer Unterricht“ angezeigt; keine ungeklärte Abwesenheitsmeldung. Ohne Bestätigung wird keine Anwesenheit behauptet.
- [ ] **ATT-16:** Unterricht beginnt 08:00, Frist `10` Minuten: ungeklärte Abwesenheit wird bei bestätigtem Fehlen ab 08:10 geprüft; Rückkehr oder nachgewiesener anderer Unterricht bis dahin verhindert die Meldung.
- [ ] **ANA-01:** Jahresstatistik zählt planmäßig angesetzte, veränderte und **tatsächlich gehaltene** Stunden nach dokumentierter Regel; zweimaliges Publish, Retry oder Abwesenheitsverlängerung verursacht keine Doppelzählung.
- [ ] **ANA-02:** CSV, XLSX und PDF aus demselben Statistik-Snapshot weisen identische Kennzahlen auf; Dateiinhalte referenzieren Schuljahr, Berechnungsversion, Stichtag und Exporterstellung.
- [ ] **ANA-03:** Nichtberechtigte Lehrkraft kann keine personenbezogenen Krankheits-/Fehlzeitstatistiken exportieren, auch nicht über selbst gewählte Filter oder API-Parameter.
- [ ] **NOT-05:** Native Android-/iOS-/Windows-PWA-Kanäle erhalten denselben fachlichen Benachrichtigungsvertrag; ein Geräte-Push gilt nicht als bestätigter menschlicher Alarmempfang.
- [ ] **EXT-04:** Neue `RoomFallbackStrategy`, `SubstituteRankingStrategy` und `StatisticsExportAdapter` lassen sich registrieren und gegen Contract-Tests prüfen, ohne den Kerncode zu editieren.

## 11. Umsetzung: Reihenfolge, Ticket-Schnitte und Definition of Done

### 11.1 Empfohlene Reihenfolge für die persönliche Nutzung

**Meilenstein M0 – Basis:** Repo/CI, PHP-API, React-Layout, Login, Datenbankmigration, Schuljahr/Schüler/Klassen/Fachgruppen, CSV-Import, Test-Daten-Generator, Backups und Rollen-Grundkonzept.

**M1 – Kernbewertung:** konfigurierbares Raster und Einzelbeobachtung; gemeinsame Eintragsmetadaten (Fach/Allgemein, schulspezifischer Typ, interne Default-Sichtbarkeit) und Methoden-Registry; Kriterium `nicht beobachtet` durch Null/fehlende Zeile abbilden. CRUD- und Berechtigungstests zuerst stabilisieren.

**M2 – ICE:** mobiler Expressfluss, Sitzplaneditor/-ansicht, Wechsel der Ansichten, Fortschritt, Notizen und Änderungskorrektur. Auf wirklichem Smartphone mit fiktiver Klasse von mindestens 25 SuS testen.

**M3 – Auswertungsnutzen:** Beobachtungsabdeckung, Priorisierungsvorschläge, Verlaufsdarstellung, fachlich getrennte Statistik und Export. Regeln für niedrige Datenbasis sichtbar machen.

**M4 – Zeugnis/Leistung:** Basis-Notenbuch mit Varianten/Niveaus, versionierten Notenschlüsseln, exakt berechneten und ganzzahlig angezeigten Prozenten, Feedbacktext-Vorlagen, beschlossenen Maßnahmen mit Zeitraum und geschütztem Zugriff, Klassenarbeits-Hinweise, regelbasierte Berichtsentwürfe. Das Datenmodell kann mehrere Versuche je Niveau speichern; volle Best-of-X-Workflows folgen ab P2. Erst nach erfolgter schulischer Datenschutzfreigabe echte Daten einführen.

**M5 – P2:** persönlicher Wochenstundenplan, konkrete Stunden, Stoffverteilung, Klassenbuch, eigene Anwesenheit und erweiterte Klassenarbeiten mit mehreren Versuchen pro Niveau, Kompetenz-/Rubric-Verfahren und aggregierbaren Leistungsarten. Weitere Module nach echtem Nutzungsfeedback priorisieren.

**Erst danach:** Kollegiums-Pilot (P3a), Eltern-/Schülerzugänge mit zielgruppengerechter Freigabe und Push sowie Sekretariatsverfahren (P3b), Schulplaner/Vertretung (P4).

### 11.2 Ticket-Vorlage

```markdown
## TOS-OBS-003 – ICE: Schüler überspringen

**Phase:** P1
**Ziel:** Ohne Beobachtungsgrundlage zur nächsten Person springen.
**Vorbedingungen:** Fachgruppe und offene Session vorhanden.
**Backend:** Session-Fortschritt ändern, aber keine Rating-Zeile erzeugen.
**Frontend:** Gut erreichbarer Button „Überspringen“ in Liste und Sitzplan.
**Akzeptanz:** Übersprungene SuS erscheinen als „unbewertet“, nicht `=`.
**Tests:** API-Integration, Playwright Liste/Sitzplan, Doppelklick-Retry.
**Datenschutz:** Kein zusätzliches Leistungsprofil durch Skip.
**Abhängigkeiten:** TOS-CORE-..., TOS-OBS-001.
**Definition of Done:** Tests grün, Code-Review, Doku aktualisiert.
```

### 11.3 Definition of Done pro Feature

- Datenbankmigration mit Rollbackstrategie und Testfixture vorhanden.
- API dokumentiert und Autorisierung serverseitig getestet.
- UI auf Desktop und relevantem Mobilgerät getestet, Lade-/Fehlerzustände vorhanden.
- Idempotenz, parallele Änderungen und Auditierung soweit für das Feature erforderlich geklärt.
- Keine unerwarteten personenbezogenen Daten in Logs, Push-Payload, Browser-Cache oder Exports.
- Fachliche Akzeptanzkriterien erfüllt; manuelle Freigabe für besonders kritische Abläufe dokumentiert.
- Backward Compatibility bzw. Migration vorhandener Daten geprüft.
- **Erweiterbarkeit:** Registrierte Methode/Modulversion, Input-/Output-Verträge und passende Erweiterungspunkttests vorhanden; keine unnötigen Querverweise auf konkrete andere Module.
- **Bewertungsdarstellung:** Prozent-Ausgabe ganzzahlig, interne Punkte/Schwellenentscheidung exakt und geprüft; Vorlagen/Exports nutzen dieselbe Formatierungsfunktion.

## 12. Historische Entscheidungs- und Risikoliste (Status anhand der Abschnitte 17–18 prüfen)

| Thema | Entscheidung vor der entsprechenden Phase | Risiko, wenn offen |
|---|---|---|
| ICE-Bewertungsdefinition | Mitarbeit fachbezogen exakt definieren; Arbeitsverhalten ggf. getrennt dokumentieren. | Ungerechte oder fachfremde Bewertung. |
| Notenschlüssel & Gewichtungen | Fach-/Schulartregeln, Punkt-/Prozentgrenzen und Gültigkeit festlegen; Prozent **nur für Anzeige** auf ganze Prozent runden, sofern nicht ausdrücklich eine andere rechtlich verbindliche Rechenregel existiert. | Falsche Leistungsdarstellung. |
| Niveau-/Versuchsverfahren | Definieren, welche Niveaus/Versuche eine Schule fachlich und rechtlich zulässt; `best_of_n` nur **innerhalb desselben Niveaus**, levelübergreifende Endwertung gesondert. | Ungültige Leistungsnachweise und scheinbar vergleichbare Ergebnisse. |
| Eintragsart/-sichtbarkeit | Schulisch benennbare Kategorien, Fach vs. Allgemein, LuL/SuS/EZB-Freigabe und sensible Grenzen festlegen. | Falsche Sichtbarkeit und mögliche Datenabflüsse. |
| Erweiterbarkeit durch Module und Methoden | Öffentliche interne Modul- und Methodenverträge mit Versionierung, Registry, Adaptertests und Upgradepfad verpflichtend. | Technische Sackgassen, Regressionen bei jedem neuen Bewertungsverfahren. |
| Zeugnisexport | Tatsächlich unterstützte Importformate von ASV-BW und Drittsoftware konkret verifizieren; ggf. nur generischer CSV-/XLSX-Export. | Fehlerhafte Importversprechen und Datenzuordnungen. |
| Regelung personenbezogener Nutzung | Schulische Freigabe, Gerät, Hosting, Dokumentationspflicht, Rechtsgrundlage und Datenschutz. | Unzulässige Verarbeitung echter Schülerdaten. |
| Schulische Benutzerverwaltung | SSO-Verfügbarkeit, Loginverfahren, Account-Support und Eigentümer der Konten. | Hoher Support/Identitätsverwechslung. |
| ASV-Export | Konkretes Feldschema, stabile externe ID, Importrechte. | Dubletten, fehlerhafte Klassenzuordnung. |
| Klassenbuch | Persönliche Dokumentation oder offiziell führendes Schulklassenbuch? | Widersprüchliche Datensätze. |
| Elternzugang | Authentifizierung, Sorgerechts-/Kontaktrechte und sichere Erstanmeldung. | Datenabfluss an falsche Personen. |
| Digitaler Rücklauf | Reine Information, Rückmeldung oder rechtlich relevante Erklärung? | Unwirksame Zustimmung. |
| Fehlzeitenprozess | Kontrollzeitpunkt, Sekretariatszuständigkeit, Ersatzprozess bei Ausfall. | Verzögerte Klärung, falsche Alarme. |
| Attest-/Nachteilsausgleichakten | Zweck, Berechtigung, Gültigkeit, notwendige Dokumente. | Hochsensibler Datenbestand ohne Schutz. |
| Zahlungsverkehr | Zugelassene Konten/Verantwortliche, Belege, Rückzahlungen. | Fehlerhafte Kontonutzung und Abrechnung. |
| Notfallalarm | Vorhandenes schulisches System und API-Schnittstellen. | Unzuverlässige sicherheitskritische Warnung. |
| Kontingentstundentafel | Bildungspläne, Schulart, Kohorte und Regelstand. | Falscher Unterrichtsbedarf. |
| Vertretungsregeln | Von Schulleitung freigegebene harte/weiche Bedingungen, Fachbefähigungsmodi, Minusstunden, Blockvertretung, Teamstunden, Ausfallhierarchie, Stabilitätsgewichte. | Unzulässige/ungerechte Personalentscheidung. |
| Entwurf/Veröffentlichung | Nur Entwurf und gültige veröffentlichte Vertretungsplanversion, manueller Sammel-Publish, automatische Folgeprüfung bei Änderung. | Benachrichtigungsflut, widersprüchliche Planstände. |
| Lehrkräfte-Abwesenheit verlängern | Verlängerung/Verkürzung gleicher Fehlzeit, Teilzeiträume, Kategorie, betroffene Unterrichts-/Aufsichtsslots, Versionsschutz. | Doppelter Vertretungsbedarf und unvollständige Nachplanung. |
| Räume, parallele Gruppen und Aufsichten | Raumprofile mit harten Anforderungen, KlaZi-Fallback, sichere Fachgruppenorganisation und Pausenaufsichtsbereiche. | Unzulässige Räume/Unterrichtsformen oder Aufsichtslücken. |
| Schulveranstaltungen und Statistik | Veranstaltungen als gebundene Tätigkeiten mit Auswirkungen auf Stunden, Personal und Jahresstatistik; schulisch versionierte Kategorien. | Fehlende oder doppelt gezählte Unterrichtsstunden. |
| Jahresstatistik-Export | Belastbare Ist-Erfassung, klare Kennzahldefinitionen, Berechtigungen, CSV/XLSX/PDF und Revisionssicherheit. | Nicht reproduzierbare oder datenschutzwidrige Berichte. |
| Betriebsmodell | NAS-Prototyp vs. freigegebener Schulbetrieb, Verantwortliche, Updates, SLA/Monitoring. | Langfristige Betriebs- und Sicherheitsrisiken. |
| Öffentlicher Produktname und Domain | **Intern TeacherOS beibehalten**; externe Marke später festlegen, verfügbare `.de`-Domain und Markenrechte prüfen. | Namenskonflikte, teures Rebranding und missverständliche Datenschutzwerbung. |
| Schulweise Isolation | **1 Deployment = 1 Schule** mit eigener DB, Dateiablage, Secrets, Sessions, Backup und Domain. | Schulübergreifende Datenexposition trotz unterschiedlicher Subdomains. |
| Module und Entitlements | `Core` zwingend, sonstige Module instanzweise freischalten; Gating zusätzlich zu Objektberechtigungen. | Lizenz-/Zugriffslücken und versteckte, aber per API nutzbare Funktionen. |
| Hosting- und Kostenmodell | Später Provisionierung, Control Plane und zentrale Releases; tatsächliche Hostingorte, Unterauftragnehmer und Kostenbasis nachweisen. | Teure manuelle Rollouts und unbelegte Datenschutzbehauptungen. |

## 13. Quellen, technische Referenzen und Verifikationspunkte

Diese Links dienen als **Implementierungs- und Prüfhinweise**, ersetzen keine schulische Rechtsberatung und keine Prüfung der jeweils geltenden Fassung vor dem Produktivbetrieb.

- **ASV-BW Exportformate/berichte:** https://asv.kultus-bw.de/%2CLde/Startseite/ASV-BW%2BSoftware/Berichte?REVERSE=false&SORT=2
- **BW Nachteilsausgleich (Schulamt Rastatt):** https://ra.schulamt-bw.de/%2CLde/Startseite/Unterstuetzung/Nachteilsausgleich
- **BW Ministerium – Nachteilsausgleich / Förderbedarf:** https://km.baden-wuerttemberg.de/de/schule/berufliche-bildung-1/schulartuebergreifende-themen/inklusion/nachteilsausgleich-und-deckung-des-foerderbedarfs
- **BW Schulbesuchsverordnung § 2 (geltende Fassung prüfen):** https://www.landesrecht-bw.de/bsbw/?docId=jlr-NNLBW00007D8DNN00000000010&query=JURISLINK%3A%22SchulBesV+BW+%C2%A7+2%22
- **OR-Tools Scheduling:** https://developers.google.com/optimization/scheduling
- **OR-Tools CP-SAT:** https://developers.google.com/optimization/cp/cp_solver
- **Web Push API (MDN):** https://developer.mozilla.org/en-US/docs/Web/API/Push_API
- **Service Worker (MDN):** https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API
- **PWA-Installierbarkeit (MDN):** https://developer.mozilla.org/de/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable

## 14. Historische MVP-Überlegung (NICHT der verbindliche Umfang von Version 1.0)

**Sofort implementieren:** eindeutige SuS-IDs, Schuljahre, Klassen/Fachgruppen, sichere Anmeldung, CSV-Import, Raster/Kriterien, **gemeinsame Eintragsmetadaten (Fach oder Allgemein, schuleigene Art, Sichtbarkeitsgrundmodell)**, **Modul- und Methodenregistrierung**, ICE, Einzelmodus, optionaler Sitzplan, Beobachtungsabdeckung, Notizen, Auswertung, Datensicherung und Testautomation. **P0 zusätzlich minimal:** Single-Tenant-Instanzkonfiguration, reproduzierbares 1-Schul-Deployment und serverseitige Modulfreischaltung (`core` + `observe`; `grade` bei Bedarf). Noten-Basis mit **Niveauvarianten, versioniertem Notenschlüssel, genauer Punkteberechnung und ganzzahliger Prozentanzeige**, auswählbare Feedbacktext-Vorlagen, Hinweise auf **bereits beschlossene** Nachteilsausgleiche und Zeugnistextentwürfe direkt in die erste Nutzversion aufnehmen.

**Nur architektonisch vorbereiten:** Mehrbenutzerrollen, Elternbeziehungen, externe ASV-IDs, Unterrichtstermin als späterer Link, **zielgruppenspezifische Portalveröffentlichung**, **mehrere Versuche/Best-of-X je Niveau**, komplexe Rubrics, versionierte Exportsystemadapter, Event-/Outbox-Schnittstellen, Planungsregelwerk, öffentliche `.de`-Marke als austauschbare Konfiguration, spätere schulweise Provisionierung und Modul-Lizenzverwaltung.

**Zunächst weglassen:** vollständige Schuljahresübernahme, Schüler-/Eltern-Passwortausgabe, offizielles Klassenbuch, schulweite Krankmeldung und Attestpflicht, Push, Zahlungen, automatische Stunden- und Vertretungsplanung sowie Notfallalarmierung. **Ebenfalls später:** öffentliches Branding, Lizenzabrechnung, Multi-School-Control-Plane und automatische Provisionierung neuer Schulinstanzen.

**Erstes Abnahmeziel:** Eine einzelne Lehrkraft erfasst nach einer Stunde im ICE-Modus alle *ausreichend beobachteten* SuS zügig, kann fehlende Beobachtungen ehrlich auslassen, sieht anschließend die Beobachtungsabdeckung und erhält am Halbjahresende eine prüfbare Grundlage für Fachbewertung und Beurteilungstext. Sie kann außerdem eine Niveaustufen-Arbeit mit Notenschlüssel anlegen, z. B. 15 von 31 Punkten zu **48 % Anzeige** auswerten, eine passende Feedbackvorlage auswählen und intern speichern; ohne Elternportal erfolgt noch keine externe Veröffentlichung. **Dieses Nutzenversprechen ist wichtiger als die Zahl gleichzeitig implementierter Module.**


## 15. Spezifikationsergänzung vom 25.10.2026

**Beschlossen / konkretisiert:** (a) fachlich gebundene oder allgemeine pädagogische Einträge, (b) schulisch benennbare Eintragsarten, (c) getrennte Sichtbarkeit für berechtigte Lehrkräfte/Schüler/Erziehungsberechtigte mit expliziter Freigabe, (d) Testergebnisse durchgängig **ganzzahlig in Prozent anzeigen**, ohne Schwellenwerte auf gerundeten Prozenten zu prüfen, (e) frei benennbare Niveaus und ein Versuch je Niveaustufe/Variante, (f) **bestes gültiges Ergebnis je Niveau**, (g) Notenschlüssel, persönliche auswählbare Textvorlagen und später direkte Eltern-/Schüleransicht, (h) **Erweiterbarkeit neuer Module und Methoden als verbindliche Architektur-/Testanforderung**, (i) Zeugnisexport über getrennte, verifizierte Adapter oder eigene Vorlagen in späterer Phase.

Die Umsetzung erfolgt **inkrementell**: P0 baut nur Registrierung, gemeinsame Kontrakte und fachlich notwendige Core-Felder; P1 macht diese für die persönliche Bewertung nutzbar; P2 erweitert Methoden-/Versuchslogik; P3/P4 ergänzt externe Publikation, Schulprozesse und amtliche Dokumente.

**Präzisierung zur Erweiterbarkeit:** Entscheidend ist **Code-Erweiterbarkeit durch Interfaces, Dependency Injection, einheitliche Registries und Frontend-Manifeste**. Eine zusätzliche Bewertungsmethode oder ein neues Modul soll überwiegend aus *neuen Dateien* bestehen, statt Änderungen an zentralen `switch`-Blöcken, bestehenden Methoden, API-/Navigationsmonolithen und fremden Services zu erzwingen. Detaillierte Verbindlichkeit und Beispielverträge siehe **4.7.1**.

## 16. Fachspezifikation vom 13.11.2026 (bei Widerspruch gelten spätere Festlegungen in Abschnitt 17)

**Gültigkeit:** Dieser Abschnitt präzisiert und ersetzt ältere, entgegenstehende Vorschläge zu Vertretungsstatus, Benachrichtigungen und Raum-/Fehlzeitenlogik in den früheren Kapiteln. Vor allem gilt für die **Tagesvertretung ausschließlich Entwurf vs. veröffentlicht**; es gibt keine zwingende vierstufige State Machine. Fachliche Einzelentscheidungen bleiben Schulkonfiguration, soweit rechtlich zulässig. Alle Namen und Beispielzahlen sind fiktiv.

### 16.1 Zielbild und gemeinsames Optimierungsmodell

**User Story:** Die Vertretungsplanerin öffnet morgens TeacherOS, erfasst Abwesenheiten oder verlängert eine bereits bestehende Fehlzeit, importiert/verändert eine Klassenveranstaltung, lässt den gesamten betroffenen Zeitraum neu berechnen, korrigiert einzelne Vorschläge und veröffentlicht **einmal** den geprüften Gesamtplan. TeacherOS erkennt selbstständig Auswirkungen auf Stunden, Räume, Pausenaufsichten, Lehrkräftebelastung und parallel laufende Gruppen.

**Planungseinheiten:** Unterrichtstermine/-blöcke, Lehrkräfte, Klassen/Teilgruppen, Räume, Tätigkeitsarten, Pausenaufsichten, besondere Ressourcen, schulische Veranstaltungen und Abwesenheitszeitfenster. Jede Einheit hat eine stabile ID, Zeitraum, Gültigkeitsstand und ggf. fachliche Constraints. Eine Unterrichtsstunde kann aus mehreren organisatorischen Teilen bestehen; keine Doppelzählung bei gekoppelten Gruppen.

**Zentrale Prinzipien:**

- **Zuerst harte Bedingungen**, dann Optimierungsziele. Recht, Aufsicht, Gesundheits-/Sicherheitsbedingungen, Personalverfügbarkeit, Ressourcen- und Gruppenkonflikte sind keine weichen Strafpunkte.
- **Gemeinsam statt sequentiell:** Personen, Unterrichtsform, Raum und Aufsicht im selben Problem optimieren; blockweiser Horizont mindestens für betroffenen Tag, konfigurierbar länger.
- **Zielgruppenrelevante Daten minimieren:** Dem Solver reichen Planungsfähigkeiten, Verfügbarkeit, Ressourceneignung und kategorisierte Einschränkungen; Diagnosen/medizinische Einzelgründe nicht übertragen.
- **Erklärbare Vorschläge:** Für jede Auswahl Grund, verglichene Alternativen, wesentliche Ausschlussgründe und zu erwartende Auswirkungen anzeigen.
- **Human-in-the-Loop:** Automatische Entwürfe sind Vorschläge. Dienstliche Zuweisungen, Raumfreigabe, Zusammenlegung und Ausfallentscheidungen bedürfen der berechtigten menschlichen Veröffentlichung.
- **Keine rückwirkende Umplanung erbrachter Tätigkeiten:** Fachliche Korrekturen an Ist-Daten sind möglich, aber explizite Revision mit Audit, nicht stilles Neuberechnen des vergangenen Zeitfensters.

### 16.2 Quellenreihenfolge, Fachbefähigung, Minusstunden und Blockbildung

**Konfigurierbare Kandidatenquellen (Beispielpriorität, keine verbindliche Rechtsreihenfolge):**

1. Lehrkraft, deren eigener Unterricht wegen Klassen-/Gruppenveranstaltung tatsächlich **entfällt** und die nicht selbst als Begleitung/anderweitig eingesetzt ist.
2. Ausgewiesene Vertretungsbereitschaft beziehungsweise schulische Reserve.
3. Lehrkraft aus einer Teamstunde, **wenn** ihre Herausnahme zulässig ist und der verbleibende Unterricht/Aufsicht weiterhin gewährleistet bleibt.
4. Lehrkraft mit freiem Zeitfenster; **innerhalb dieser Gruppe zuerst geringere/niedrigere organisatorische Stundensalden**, soweit geeignet und zulässig.
5. Weitere zulässige Organisationsmaßnahmen: andere Betreuung, Teilgruppen-/Raumlösung, Aufsicht/Unterrichtsform ändern.
6. Dienstrechtlich zulässige zusätzliche Unterrichtsleistungen nach gesonderter Prüfung.
7. Nicht abdeckbaren Bedarf als offenen Konflikt beziehungsweise begründungspflichtigen Ausfallvorschlag anzeigen.

**Kriterien innerhalb einer Quelle:** Fachbefähigung, Unterrichtserfahrung mit der Gruppe, bisherige Vertretungs- und Aufsichtsbelastung relativ zum Deputat, Wege, Stabilität, fachliche Qualität und Kontinuität. Die Quellenreihenfolge selbst ist durch die Schule änderbar, ebenso Gewichte und die Ein-/Ausschaltung von Strategien. Bestimmte Kriterien können **lexikografisch** geordnet sein; die UI muss anzeigen, ob ein Kriterium absolut oder nur gewichtet wirkt.

**Fachbefähigungsmodus pro Fach/Tätigkeit/Schule:** `off` (kein Präferenzbonus), `prefer` (Bonus), `required` (nur geeignete Kandidaten). `off` bedeutet **nicht**, dass eine Sicherheitsanforderung ausgeschaltet wird. Formale Lehrbefähigung, schulisch bestätigte Einsatzmöglichkeit und zusätzliche Aufsichts-/Maschinenqualifikation getrennt erfassen.

**Minusstunden:** Die Anwendung verwaltet einen **organisatorischen Saldo** für die schulische Fairness. Dazu gehören Kategorien der Entstehung (z. B. ausgefallene Eigenstunden wegen Veranstaltung, tatsächlich zusätzliche Vertretung). Dienstrechtlich anrechenbare Mehrarbeit und Arbeitszeitvorschriften sind eigene, geprüfte Berechnungsregeln. Niemals aus einem negativeren Saldo auf eine automatische Verpflichtung schließen. Bei Bedarf kann die Schule die Saldenpräferenz abschalten.

**Doppel-/Mehrfachstunden:** Versuche, aufeinanderfolgende betreuungspflichtige Stunden derselben Gruppe bei geeigneter Person zu bündeln. Bonus für einen durchgehenden Einsatz ist **weich**; darf kein Sicherheits-/Deputatproblem anderswo erzeugen. Für zwei Stunden einen Blockkandidaten gegen zwei Einzelkandidaten mit Gesamtscore vergleichen. Bei fachlicher Relevanz sind Facherhalt und Aufsicht höher zu bewerten als die bloße Vermeidung des Wechsels.

**Fairness über Zeit:** Historische Vertretungsstunden, Aufsichtseinsätze und tatsächlich geleistete Tätigkeiten in definierbaren Zeitfenstern zählen. Abwesenheiten aufgrund von Urlaub/Krankheit nicht unsachgemäß als „negativer Einsatz“ werten. Kein Schul- oder Kollegiums-Ranking außerhalb berechtigter Planungsansichten.

### 16.3 Änderbarer Vertretungsentwurf mit aktiver Sammelveröffentlichung

**Zwei Sichten – keine zusätzliche fachliche Statusmaschine:**

- `draft`: die vollständig bearbeitbare Arbeitsfassung. Mehrere Solverläufe, manuelle Zuweisungen, Versuche, Rücksetzungen, Austausch ganzer Blöcke und Umplanungen sind erlaubt. Es gibt dabei **keine Benachrichtigungen** an Kollegen/SuS/Eltern.
- `published`: die zuletzt ausdrücklich von Berechtigten **manuell veröffentlichte Vollversion**. Nur diese gilt für Dritte. Ein späterer Entwurf ändert daran nichts, bis er erneut gesammelt veröffentlicht wird.

**Manuelle Änderung eines Kollegen:** Wird beispielsweise eine Vertretung von Lehrkraft A auf B umgestellt, überprüft TeacherOS automatisch den **restlichen betroffenen Entwurf**: Ist B anderswo eingeteilt? Werden A dadurch neue Möglichkeiten eröffnet? Entsteht ein Blockbruch? Sind Fachaufsicht, Räume oder Pausenaufsichten betroffen? Der Solver korrigiert nicht fixierte Folgeteilzuweisungen oder zeigt Konflikte. Eine bewusst manuell gesetzte Zuweisung ist für diesen Lauf zunächst zu respektieren; der Nutzer kann die Fixierung lösen. **Fixierung ist ein Solver-Constraint, kein Status der Vertretung.**

**Änderungsstabilität:** Neue zulässige Lösung darf bereits veröffentlichte *zukünftige* Vertretungen wieder ändern. Die Schule steuert, wie teuer eine unnötige Planänderung ist (`flexibel`, `ausgewogen`, `stabil` oder Gewicht), sowie die höhere Änderungskosten kurz vor Stundenbeginn. Nicht jede mathematische Punktverbesserung soll eine bereits kommunizierte Zuweisung ersetzen.

**Sammelveröffentlichung (serverseitig atomar):**
> **[Überholt durch 17.16 / Rev. 1]** Abschnitt ersetzt durch Teilveröffentlichung gemäß 17.4 / 17.16 Nr. 2.

1. Entwurfs-Version und aller zugehörigen Regeln/Quellenstände einfrieren; Berechtigungen, Hard Constraints, offene Konflikte und den aktuellen Ist-Zeitpunkt prüfen.
2. Vollständigen Diff zur bisherigen `published`-Version erzeugen: hinzugefügt, verändert, aufgehoben, unverändert; betroffene Personen/Klassen/Räume ermitteln.
3. Preview mit Nutzer-/Raumauswirkungen und optional begründungspflichtigen Konflikten anzeigen; explizite **„Gesamten Plan veröffentlichen“**-Aktion verlangen.
4. Transaktional einen unveränderlichen Veröffentlichungssnapshot speichern, aktiven Versionszeiger umstellen und ein **einziges fachliches** `SubstitutionPlanPublished`-Ereignis einschließlich Diffreferenz in die Outbox schreiben.
5. Worker erstellt **pro Empfänger gebündelte** und deduplizierte Änderungsmitteilungen; keine Nachrichten für unveränderte Zuweisungen. Fehlgeschlagene Zustellungen werden nachverfolgt; erfolgreiche Push-Annahme ist keine Lesebestätigung.
6. Entwurf an neue gültige veröffentlichte Version anlehnen, damit umgehend weitergearbeitet werden kann. Bereits ausgeführte Unterrichtsstunden bleiben geschichtsfest.

**Parallelbearbeitung:** Versionen/optimistic locking, Besitz- oder Bearbeitungsindikator, Konfliktvergleich; kein stilles Überschreiben eines anderen Planers. Wiederholte Veröffentlichungsanfragen mit demselben Idempotency-Key dürfen weder Planversion noch Push duplizieren.

**Kein versteckter Auto-Publish:** Auch wenn eine Fehlzeit verlängert wird und der Solver eine Lösung findet, muss ein verantwortlicher Mensch **erneut gesammelt veröffentlichen**, bevor andere einen geänderten Plan sehen. Bis dahin zeigt die Planungsoberfläche den neu entstandenen Handlungsbedarf besonders deutlich an. Ein kurzfristiger ungeklärter Ausfall braucht einen organisatorischen Notfallprozess, nicht unbemerkt automatisch versendete Vertretungen.

### 16.4 Lehrkräfteabwesenheiten erfassen, verlängern, verkürzen und reoptimieren

**Verbindliches neues Feature: „Fehlzeit verlängern“.** Es darf nicht nötig sein, eine zweite Fehlzeit anzulegen oder die ursprüngliche abzuschließen, wenn derselbe zusammenhängende Ausfall länger dauert als zunächst bekannt. Beispiel: Krankmeldung Montag 1.–3. Stunde → neuer Endzeitpunkt Montag 6. Stunde; oder Montag–Mittwoch → verlängert bis Freitag. Die **ursprüngliche Fehlzeit-ID bleibt identisch**, eine neue Revision dokumentiert die Änderung.

**Fachliche Eingabefelder:** Lehrkraft, schuleigene Fehlzeitenkategorie, Start und Ende einschließlich exakter Uhrzeit bzw. ganztägiger Tage, optional schulbezogene Abwesenheitsart/Vertretungsrelevanz, nachgewiesene Einschränkungen nur falls erforderlich, Erfassender, Rechteklasse, optional interne Begründung. Intervalle bevorzugt als `[start, end)` in lokaler Schulzeitzone mit eindeutiger UTC-Speicherung. Fehlzeit darf Unterrichtsstunden, Teamstunden, Pausenaufsichten, Konferenzen, Räume/Planungsressourcen oder Schulveranstaltungen betreffen. Mehrtägige Zeitfenster mit individuell ausgenommenen Slots müssen modellierbar sein.

**Erlaubte Aktionen:** Neu erfassen; Endzeitpunkt nach hinten verschieben (**verlängern**); Endzeitpunkt nach vorne ziehen (**verkürzen**); Beginn/Kategorie/Fehler korrigieren; partielle Zeitfenster im Einzelfall auflösen; dokumentierte Fehlzeit nach befugter Prüfung stornieren. Eine echte neue, getrennte Fehlzeit wird als neuer Datensatz geführt. Überschneidende oder unmittelbar angrenzende Abwesenheiten derselben Person werden bei Anlage auf Dubletten geprüft und gegebenenfalls zur zusammenhängenden Verlängerung vorgeschlagen – keine ungefragte Zusammenlegung unterschiedlicher Gründe.

**Änderungsablauf bei Verlängerung:**

1. Bestehenden Datensatz und aktuelle Revision öffnen. Neuen Endzeitpunkt eingeben; Vorschau der zusätzlichen betroffenen Unterrichts-/Aufsichtsstunden und eventuellen Veranstaltungen anzeigen.
2. Änderung mit Optimistic Locking und rollenbezogener Berechtigung speichern. In derselben Transaktion `TeacherAbsenceChanged.v1` in die Outbox schreiben, alte/neue Werte und Änderungskontext geschützt auditieren.
3. `TeacherAbsenceImpactResolver` bildet die **Mengendifferenz der betroffenen Slots**: neue/weiterhin betroffene/entfallende Betroffenheiten. Deterministischer Eindeutigkeitsschlüssel etwa `(absence_id, occurrence_id, impact_type)` verhindert doppelten Vertretungsbedarf bei wiederholter Auswertung.
4. Prüfen, ob die Lehrkraft bereits als Vertretung in einem jetzt neu betroffenen Slot im bisherigen **veröffentlichten Plan** eingetragen ist. Das ist ein offener Konflikt für den Entwurf, keine heimliche Änderung der geltenden Veröffentlichung.
5. Bestehende Entwurfszuweisungen für betroffene Slots erneut validieren; neue Vertretungslücken erzeugen oder vorhandene Lücken ergänzen; bei Teamstunden, Blockvertretungen, Pausenaufsichten und gekoppelten Gruppen die Folgeeffekte einbeziehen.
6. Inkrementellen Solve für den betroffenen Tag/die betroffenen Tage anbieten oder nach Schulkonfiguration automatisch auslösen; weiter entfernte Stunden außerhalb des Wirkungsbereichs möglichst stabil halten.
7. Planungsverantwortliche sieht Vorher/Nachher, offene Konflikte, mögliche Ersatzkollegen, Räume und betroffene Aufsichten. Sie entscheidet im Entwurf und veröffentlicht die **gesamte aktualisierte Planung manuell**.
8. **Erst nach Veröffentlichung** werden Lehrkräfte, Klassen und andere Betroffene über Planänderungen benachrichtigt. Die administrative Speicherung einer sensiblen Fehlzeit ist kein Grund, den medizinischen Anlass im Kollegium sichtbar zu machen.

**Beispiel:** Lehrkraft A fehlt zunächst 1.–3. Stunde. Für Stunde 2 ist bereits B als Vertretung veröffentlicht. Verlängerung auf 1.–6. Stunde erzeugt für 4.–6. Stunde neue Bedarfe; die Stunde 2 bleibt **nicht doppelt**. Eine von A übernommene Pausenaufsicht wird ebenfalls berücksichtigt. Der Entwurf könnte C für die 4.–5. Stunde zusammenhängend und D für die 6. Stunde vorschlagen. B bleibt bis zur neuen Veröffentlichung gültig, soweit keine tatsächliche Unmöglichkeit entsteht; unvermeidliche akute Risiken werden im Planungsdashboard eskaliert.

**Verkürzung und Nachmeldung:** Kommt A früher zurück, werden künftige Vertretungsbedarfe und ihre Zuordnungen im **Entwurf** überprüft/entfernt, aber der veröffentlichte Plan nicht still geändert. Eine nachträglich gemeldete Abwesenheit oder Korrektur im bereits vergangenen Bereich betrifft den dokumentierten Ist-Verlauf und die Statistikrevision, **nicht** die rückwirkende Umplanung erbrachter Stunden.

**Sonderfälle:** An Wochenenden/Feiertagen keine künstlich ausgefallenen Unterrichtsstunden; laufende Klassenfahrt kann Begleitung und eigene reguläre Stunden betreffen, ohne zwei Bedürfnisse für dieselbe Unterrichtseinheit zu erzeugen; verschiedene Ausfallkategorien nicht überschreiben; überlappende Abwesenheiten verlangen Prüfung; mehrfaches Speichern idempotent; Konflikte bei paralleler Bearbeitung erkennen. Zugriffsrechte für Krankheitsdetails bleiben gegenüber normalen Vertretungsplanerrechten restriktiv.

**UI-Vorschlag:** In der Lehrkräfte-Ausfallliste „Verlängern“ direkt neben einem aktiven Eintrag, mit Kalender-/Stundenauswahl und Auswirkungsanzeige „3 weitere Unterrichtsstunden, 1 Pausenaufsicht, 2 bestehende Zuordnungen betroffen“. Neben dem Entwurf erscheint ein Hinweis „Neue Abwesenheitsauswirkungen – Plan noch nicht veröffentlicht“.

### 16.5 Raumprofile, KlaZi-Fallback, parallele Gruppen und Pausenaufsichten

**Raumprofil:** Stabile Raum-ID, Bezeichnung, Raumtyp, Standort/Flur/Gebäude, Klasse/KlaZi-Zuordnung, maximale Personenzahl (ggf. abhängig von Tätigkeit), Ausstattung und verfügbare besondere Ressourcen, barrierefreier Zugang, erlaubte Tätigkeiten und Fächer, Sperrzeiten, zuständige Fachkraft/Lehrbefähigung, erforderliche zusätzliche Aufsicht, sicherheitsbezogene Voraussetzungen. Andere verfügbare Ressourcen (z. B. Werkzeugsatz oder Laptopwagen) können über denselben Planungsansatz gebucht werden.

**Regelklassen:**

- `legal_safety_hard`: verbindliche rechtliche/sicherheitstechnische Bedingung; **nicht überschreibbar**.
- `school_hard`: verbindliche schulische Vorgabe; Abweichung nur nach berechtigter, nachvollziehbarer Änderung der Schulregel und erneuter Validierung, soweit zulässig.
- `soft`: Schule kann Gewichtung oder Reihenfolge einstellen, etwa möglichst gewohnten Raum oder Nachbarschaft.

**Ersatzraumstrategie (Standardvorlage):** (1) Ursprungsraum, falls wieder geeignet, (2) **KlaZi/Stammklassenzimmer der Klasse**, sofern frei, kapazitäts- und tätigkeitsgeeignet, (3) alternativ passender Fachraum, (4) freies geeignetes Klassenzimmer, (5) benachbarter geeigneter Raum zur organisatorischen Gruppenkopplung, (6) zulässiger Raumtausch, (7) **explizit genehmigte Änderung der Unterrichtsform** und erneute Suche. Jede Alternative ist eine vollwertig geprüfte Kombination aus Lehrkraft, Gruppe, Zeit, Raum und geplanter Tätigkeit; reine räumliche Freiheit genügt nicht.

**Parallele Technik- oder Fachgruppen:** Der Solver darf Optionen für parallele Gruppen im selben Gebäudeteil anbieten, z. B. fachpraktische Arbeit in Raum 1 unter Fachlehrkraft A und beaufsichtigte Theorieaufgaben in Raum 2 unter Lehrkraft B. Dabei sind Anwesenheit, Aufsicht, geforderte Fachkunde, Ausstattung, Tätigkeit und Sicht-/Laufwege **pro Gruppe** zu prüfen. Bei gefährlichen Maschinenarbeiten darf B nicht allein deshalb Fachaufsicht ersetzen, weil A nebenan unterrichtet. Eine geplante Rotation zwischen Praxis und Theorie benötigt klar geteilte, verantwortliche Aufsichtsphasen und explizite Freigabe.

**Pausenaufsichten als eigenständige planbare Bedarfe:** Aufsichtsbereich, Intervall, erforderliche Mindestzahl und Eignung der Aufsichtspersonen, Sichtachsen, Gefahrenstellen, besondere Schülerströme und benachbarte Bereiche modellieren. Bei Ausfall einer Aufsicht automatisch (1) zulässige Ersatzkraft vorschlagen, (2) Umverteilung prüfen, (3) **Zusammenlegung benachbarter Bereiche** nur vorschlagen, wenn ein geprüftes Aufsichtskonzept die Aufsicht weiterhin ermöglicht, (4) ungelösten Aufsichtskonflikt als hochprioritär anzeigen. Aufsicht kann die Verfügbarkeit einer Lehrkraft für Vertretung einschränken. Nicht geleistete Aufsichten dürfen statistisch nicht als durchgeführt erscheinen.

### 16.6 Schulveranstaltungen und die tatsächliche Anrechnung von Stunden

**Veranstaltungen sind vollwertige Kalender-/Planungsobjekte**, z. B. Klassenfahrt, Exkursion, Ausflug, Projekttag, Sportveranstaltung, Schulversammlung, Fortbildung, Prüfung. Jede Schule kann Kategorien/Unterkategorien anlegen, mit stabilem technischen Schlüssel, veränderlichem Namen, Gültigkeitszeitraum, Standardwirkungen auf Klassen/Lehrkräfte/Räume und Berechtigungsregeln.

**Veranstaltungsmodell:** Kategorie, Titel, Zeitraum, Ort, betroffene Klassen/Teilgruppen/SuS, gebundene Begleit-/Aufsichtspersonen, verantwortliche Organisatoren, benötigte Räume/Ressourcen, betroffene reguläre Unterrichtstermine, erwartete Auswirkungen, später tatsächliche Durchführung. Elternrückläufe/Zahlungen können optional referenziert werden, aber die Planungslogik ist nicht von aktivierter Finance-Komponente abhängig.

**„Klassenfahrt zählt als Stunde“ – fachlich präzisiert:** Die betroffene Unterrichtsstunde wird im Schulkalender nicht einfach als „ausgefallen“ verbucht, sondern als **durch Schulveranstaltung ersetzt/anderweitig erbracht** markiert. Für eine Klasse können z. B. 1.–6. Stunde durch den Veranstaltungstermin abgedeckt sein. Begleitende Lehrkräfte sind im gesamten relevanten Veranstaltungszeitraum gebunden und nicht für Vertretungen verfügbar. Fachlehrkräfte, deren einzige eigene Stunde bei der abwesenden Klasse entfallen würde, können für andere Vertretungen verfügbar werden, sofern sie nicht selbst an der Veranstaltung teilnehmen und keine sonstigen Pflichten haben.

**Strikte Trennung:** `scheduled_lesson`, `replaced_by_event`, `teacher_bound_to_event`, `actually_delivered_activity`, `legal_credit_rule`. Sechs ersetzte Unterrichtsstunden einer Klasse bedeuten **nicht automatisch** sechs anrechenbare Mehrarbeitsstunden für eine begleitende Lehrkraft. Offizielle Unterrichts-/Arbeitszeit-/Statistikregeln sind durch Schulträger/Schulleitung und geltendes Recht zu verifizieren. Keine pauschale Gleichsetzung von Abwesenheit der Klasse mit krankheitsbedingtem Unterrichtsausfall.

**Bei Änderungen:** Verschiebt sich eine Veranstaltung oder fällt eine Begleitperson aus, werden die betroffenen Gruppen, Lehrerressourcen, Raumbelegungen und Vertretungsbedarfe im Entwurf neu berechnet; bereits veröffentlichte Drittinformationen bleiben bis zur Sammelveröffentlichung stabil. Für akute organisatorische Situationen müssen Planer deutliche Warnungen sehen.

### 16.7 Temporäre Schülerzuordnung und zentrale Anwesenheit

**Anforderung:** Eine Lehrkraft kann einen fremden Schüler oder mehrere SuS für die Dauer einer Unterrichtsstunde/Prüfung zu sich aufnehmen, etwa zum **Nachschreiben**. Das ist keine dauerhafte Veränderung der Klassenzugehörigkeit oder des Stundenplans. Erfasst werden `student_id`, Quellstunde, Gaststunde, Beginn/Ende, Grund und verantwortliche aufnehmende Lehrkraft.

**Wahrheitsmodell:**

- `assigned_to_host_lesson` = geplante/organisatorische Zuordnung, **keine bestätigte Anwesenheit**.
- `present_confirmed` = die aufnehmende Lehrkraft hat den Schüler wirklich angetroffen.
- `present_elsewhere` = für die ursprüngliche Lehrkraft/Anwesenheitsansicht abgeleitete Statusanzeige; ggf. berechtigter Hinweis „bei Lehrkraft B in Raum 204“.
- `absent_unverified` = tatsächlich fehlend oder bei fehlender Klärung noch ungesichert; kein bewusst falscher Ganztages-Endstatus.

**Sekretariatslogik:** Bei bestätigtem Fehlen und ohne gültige Krank-/Beurlaubungsmeldung wird ein **vorläufiger Tagesfall** angelegt. Schule konfiguriert Benachrichtigung **sofort oder `X` Minuten nach Beginn der relevanten Unterrichtsstunde** (nicht X Minuten nach beliebigem Speichervorgang). Vor Auslösung prüft der Worker erneut gültige Elternmeldung, verspätetes Eintreffen und `present_elsewhere`. Bei zwischenzeitlich aufgenommener Präsenz entsteht kein neuer ungeklärter Fall. Ist das Sekretariat schon informiert, folgt bei Klärung eine Statusaktualisierung. Fehlende Lehrkraft-Anwesenheitskontrolle ist **ein eigener Fall**, nicht Schülerabwesenheit. Doppelte Fall- und Nachrichtenerzeugung durch Retries verhindern.

**Konflikte:** Zwei Lehrkräfte dürfen nicht ohne Hinweis denselben Schüler zeitgleich als bei sich anwesend bestätigen; der Ursprungskollege sieht zunächst „zugewiesen – Bestätigung offen“. Für aufeinanderfolgende Unterrichtsstunden enden temporäre Zuordnungen automatisch mit dem Zeitraum oder werden bewusst verlängert. Eltern sehen nur schulseitig für sie freigegebene Anwesenheitsinformationen, nicht pauschal den Aufenthaltsort beliebiger Dritter.

### 16.8 Benachrichtigungskanäle, Apps und kritischer Alarm

**Ein Codebestand:** React/TypeScript ist sowohl Browser-/Windows-PWA als auch die UI für optionale iOS-/Android-Apps (z. B. Capacitor). Plattformunterschiede leben ausschließlich in **Adaptern** für Push-Token, Berechtigung und native Benachrichtigung, nicht in separaten Versionen des Klassenbuchs, Chats oder Vertretungsmoduls. Die Schule kann Browser und Apps parallel anbieten.

**Normale Meldungen:** Chat, Klassenbuch, freigegebene Testergebnisse/Feedback, Vertretungsplanänderungen, Formulare; Berechtigung, Ruhezeiten und Empfängerregeln beachten. Ereignisse als persistente persönliche Nachrichten speichern; Push nur als Transport. Für Planänderungen ausschließlich **manuelle Sammelveröffentlichung** als Auslöser. Benachrichtigungen dürfen keine vertraulichen Namen/Noten/Krankheitsgründe auf Sperrbildschirmen verraten.

**Kritischer Alarm:** Separater Sicherheitsprozess mit stark begrenzten Auslöserechten, nachvollziehbarem Alarmtyp, zuständigen Empfängern, Bestätigungs-/Eskalationsstatus und Anbindung an einen schulisch **geprüften, unabhängigen Alarmweg**. iOS Critical Alerts erfordern spezielle Apple-Berechtigungen; Android und Windows erlauben unter Bedingungen besondere Benachrichtigungseinstellungen, aber es existiert **keine allgemeine Garantie für Ton trotz Lautlos/Nicht stören, Akku, Offline-Modus oder abgeschaltetem Gerät**. TeacherOS darf nicht als alleiniger Brand-/Gewaltalarm in Betrieb gehen. Testalarme, Geräteberechtigungsprüfung, quittierte Eingänge und definierte Fallbacks sind als gesondertes Sicherheitsprojekt zu planen.

**Versandarchitektur:** Fachmodul publiziert Event → `NotificationPolicy` ermittelt berechtigte Empfänger und Art → Outbox/Queue speichert Sendewunsch → `NotificationChannel` wählt Web Push / native iOS-/Android-Übertragung / zugelassenen externen Alarmanbieter → Zustellversuche und Nutzerbestätigung getrennt speichern. Ein Plugin darf aus einer normalen Klassenbuchnachricht **keinen** kritischen Alarm machen.

### 16.9 Jahresstatistik und Export nach Schuljahr

**Ziel:** Am Schuljahresende können Schulleitung/Verwaltung eine prüfbare Auswertung exportieren, ohne händisch Abwesenheiten, Unterrichtspläne, Stundenkonten, Events oder Pausenaufsichten zu zählen. Derselbe Bericht ist mit demselben Daten- und Regelstand reproduzierbar.

**Kategorien:**

- `TeacherAbsenceCategory`: Schule legt Fehlzeitengründe und neutrale Außenbezeichnungen an. Ein Kategorieeintrag besitzt stabile ID, versioniertes Label, mögliche übergeordnete Statistikgruppe, Gültigkeit, zulässigen Rollen-Scope und Schutzklassifikation. „Krankheit“ ist für gewöhnliche Vertretungsansichten ggf. nur „Abwesend“; individuelle Gesundheitsdetails sind nicht Teil der allgemeinen Statistik.
- `SchoolEventCategory`: frei benennbare Veranstaltungsarten mit Klassifikation, welche Gruppen/Lehrkräfte/Ressourcen gebunden sind und welche Unterrichtsstunden durch Events ersetzt wurden.
- Kategorien werden archiviert statt hart gelöscht. Historische Fälle speichern Kategorie- und Regelversion; spätere Umbenennungen ändern bereits veröffentlichte Berichte nicht still.

**Kennzahlen (jeweils mit explizit dokumentierter Definition, Zeitraum und Bezugsgröße):**

| Kennzahl | Was gezählt wird | Häufige Fehlinterpretation vermeiden |
|---|---|---|
| Geplanter Unterricht | Tatsächliche Solltermine inkl. Gruppe/Fach/Schuljahr | Gekoppelte Gruppen oder Terminrevisionen doppelt zählen |
| Tatsächlich regulär erteilt | Als stattgefunden bestätigte reguläre Termine | Veröffentlichung allein ist kein Ist-Nachweis |
| Vertretene Unterrichtsstunden | Tatsächlich durch andere LK gehaltene/organisierte Stunden nach Regel | Ersatzunterricht vs. reine Betreuung unterscheiden |
| Betreuung / fachfremder Einsatz | Eigene Tätigkeitsklasse mit Aufsicht und Fachzuordnung | Nicht als vollwertigen Fachunterricht ausgeben, wenn andere Tätigkeit |
| Entfallener Unterricht | Tatsächlich nicht erteilte Unterrichtseinheiten nach definierter Statistikregel | Klassenfahrt/Projekttag ungeprüft als „Ausfall“ zählen |
| Schulveranstaltungsstunden | Planmäßig/real durch Veranstaltung ersetzte Gruppenstunden | Mit gesetzlichen Lehrerdeputatsstunden gleichsetzen |
| Lehrkräftefehlzeiten | Betroffene Arbeitstage/Unterrichtseinheiten und Kategorien | Kalendertage, Unterrichtsstunden und Rechtsanrechnung vermischen |
| Zusätzliche Vertretungsbelastung | Tatsächlich geleistete, organisatorisch zusätzliche Einsätze | Alle ausgefallenen Eigenstunden als rückzahlbare Minuszeit werten |
| Pausenaufsichten | Geplant, vertreten, zusammengelegt, tatsächlich geleistet | Zusammengelegte Bereiche doppelt als zwei Personalstunden zählen |
| Raumänderungen | Verlegungen, Raumtausch, KlaZi-Fallback, fehlende geeignete Räume | Nur Solverentwürfe statt veröffentlichte oder tatsächliche Vorgänge zählen |

**Filterdimensionen:** Schuljahr, Halbjahr, Monat, Klasse, Fach, Unterrichtsart, Stufe/Niveau (nur soweit für die Kennzahl sinnvoll), Fehlzeitenkategorie, Veranstaltungskategorie, Lehrkraft, Gruppe, geplante/durchgeführte Stunde, Vertretungsart und Raum. Personenbezogene Auswertungen sind nur für entsprechende Aufgaben/Rollen zugänglich; kleine Gruppen, besonders schützenswerte Fehlzeitengründe und Exporte brauchen zusätzliche Schutzmaßnahmen.

**Datenquellen als drei getrennte Ebenen:** (1) **Originalplanung/Soll**, (2) **historische veröffentlichte Planversionen**, (3) **bestätigte tatsächliche Durchführung/Ist** inklusive nachträglich berechtigter Datenkorrekturen. Nur so bleiben spätere Umplanungen, Verlängerungen oder abgebrochene Veranstaltungen korrekt nachvollziehbar. Statistiken basieren bevorzugt auf bestätigten Ist-Daten und definierten Nachweislücken; unbekannte Durchführung wird als `unknown`/„nicht bestätigt“ ausgewiesen und **nicht** still in „durchgeführt“ verwandelt.

**Jahresabschluss:** Statistiklauf für Schuljahr mit Datenstand, Bewertungs-/Zählregeln und Filterversion starten → Widersprüche/unbestätigte Ist-Daten anzeigen → berechtigte Person prüft → revisionsgeschützten **Snapshot** freigeben → CSV/XLSX/PDF mit Kennzahlendefinitionen, Berichtstand, Revision und Exportzeit erzeugen. Nachträgliche Korrektur erstellt eine **neue** Statistikrevision; Vorversion bleibt lesbar und rechtlich erforderliche Aufbewahrung wird beachtet.

**Exporte:**

- **CSV (UTF-8):** maschinenlesbare tabellarische Auszüge, definierte Spalten-/Zeitzonen-/Trennzeichenprofile, dokumentierte Werte/Schlüssel.
- **XLSX:** mehrere Tabellenblätter (Übersicht, Fehlzeiten, vertretene/entfallene Stunden, Klassen/Fächer, Events, Pausenaufsichten, Raumänderungen, Datendefinitionen); Formeln sind nicht die alleinige Datenwahrheit.
- **PDF:** schulischer Jahresbericht mit Übersichten, Tabellen, aussagekräftigen Grafiken, Kontext und Quellen-/Revisionshinweisen.
- **Weitere Schnittstellen:** `StatisticsExportAdapter`; spezielle amtliche/kommunale Exportformate nur nach bestätigter Spezifikation ergänzen, keine universelle Kompatibilität behaupten.

**Keine automatische Veröffentlichung personenbezogener Jahresberichte.** Exporterstellung, Download und optionale Weitergabe werden berechtigt und protokolliert; sensible Dateien erhalten begrenzte Zugriffs-/Aufbewahrungsregeln.

### 16.10 Technische Datenflüsse und Nicht-Funktionales

```text
Lehrerabwesenheit erfasst / verlängert / verkürzt
  -> TeacherAbsenceChanged.v1 + Revision
  -> ImpactResolver (Slot-Differenz, kein doppelter Bedarf)
  -> Entwurf invalidieren + gesamtes betroffenes Zeitfenster prüfen
  -> Room-/Teacher-/Supervision-Constraints prüfen + Solver
  -> Vorschlagsvarianten + Begründungen + Konflikte
  -> Planer verändert Kollegen / Räume / Stunden
  -> sofortige Folgeprüfung + eventueller Re-Solve im Entwurf
  -> Plan-Diff / Auswirkungen / Empfänger-Vorschau
  -> MANUELLE Sammelveröffentlichung (atomar)
  -> neue Published-Vollversion + Outbox
  -> gebündelte neutrale Benachrichtigungen an Betroffene
  -> tatsächlichen Verlauf nach Durchführung dokumentieren
  -> Schuljahres-Analytics / Statistik-Snapshot / Export
```

**Technische Pflichtmerkmale:** deterministische IDs für Bedarf, stabile Event-IDs, at-least-once-Worker mit idempotenten Consumer-Verträgen, Versionierung von Regelwerken und Kategorien, konsistenter Schuljahresbezug, getrennte Rollen/Scopes, Transaktions-Outbox beim Publish und Fehlzeitwechsel, alte Reports lesbar, messbare Solver-Laufzeit und begrenzte Rechenressourcen pro Schulinstanz.

**Reoptimierung bei Änderung:** Ein standardisierter `PlanningChangeSet` beschreibt `teacher_absence_changed`, `assignment_replaced`, `event_changed`, `room_unavailable`, `supervision_staff_absent` etc. Der `ImpactResolver` ermittelt den minimal betroffenen Zeit-/Ressourcenbereich, der **Solver** betrachtet aber alle relevanten Abhängigkeiten. Die React-Oberfläche zeigt bis zum erfolgreichen Update einen aktualisierten Entwurfs-/Konfliktzustand statt so zu tun, als sei die manuelle Änderung bereits konfliktfrei.

**Keine versprochene Echtzeit bei Notfällen:** Datenbank- und PWA-Push sind nicht für garantierte Notfallzustellung konzipiert. Das Alarmmodul nutzt den geprüften externen Dienst und dessen Betriebsanforderungen; TeacherOS darf keine Sicherheit suggerieren, die Transport und Geräte nicht einhalten können.

### 16.11 Prioritäten und Entwicklungsreihenfolge dieser Ergänzungen

| Phase | Umzusetzen / vorzubereiten | Abgrenzung |
|---|---|---|
| **P0** | Modul-/Strategy-Registries, Event-/Outbox-Vertrag, Zeitintervall-/Versionierungskonvention, Scope-/Audit-Grundlagen | Noch keinen vollständigen Personal- oder Vertretungssolver bauen. |
| **P2** | Unterrichtstermine, Basisanwesenheit, temporäre Schülereinträge inkl. Gastunterricht, Raum-/KlaZi-Stammdaten in einfacher Form | Schulweiter Abgleich benötigt später entsprechende Rollen. |
| **P3** | Lehrkraft- und Event-Stammdaten, Kategorien, Abwesenheitsintervall samt **Verlängern/Verkürzen**, Bestätigungs-/Sekretariatsworkflow, Benachrichtigungscenter, normale Pushwege | Umfangreiche statistische Auswertung und Solver folgen P4. |
| **P4 Basis** | Volle Auswirkungsberechnung, zwei Planansichten, manueller Sammel-Publish, Fachbefähigungs- und Quellenregeln, Minusstundensaldo, Blockvertretung, betroffene Raum-/Aufsichtskonflikte | Automatische Entscheidungen bleiben Entwürfe. |
| **P4 Erweiterung** | Parallele Gruppen, komplexe Raumtausch- und Pausenbereichsoptimierung, komplette OR-Tools-Constraint-Repräsentation, Ist-/Jahresstatistiken CSV/XLSX/PDF | Vor produktivem Einsatz Sicherheits-/Rechtsprüfung und Schul-Freigabe. |
| **Separates Sicherheitsprojekt** | Schulisch geprüfte Alarmanbindung, kritische native Fähigkeiten wo genehmigt, Redundanz, Tests/Quittungen/Eskalationskonzept | Nie bloßes Umschalten von normalem Push auf `urgent=true`. |

**Abnahmekriterium für die Gesamterweiterung:** Man verlängert eine bestehende Lehrkräftefehlzeit um drei Unterrichtsstunden und eine Pausenaufsicht. TeacherOS ergänzt ohne Dubletten die Bedarfe, schlägt bevorzugt eine zulässige fachlich passende Doppelstundenvertretung mit passendem Stundenkonto vor, prüft bei Raumproblemen automatisch das freie KlaZi, validiert bei manueller Kollegenzuordnung den restlichen Entwurf, verschickt **keine** Benachrichtigung vor der **manuellen Sammelveröffentlichung** und zählt nach erfolgter Durchführung die Tätigkeiten korrekt für den Schuljahresbericht. Alle Schritte sind durch dokumentierte Regeln und Audit nachprüfbar.


---

## 17. Verbindliche konsolidierte Produkt- und Architekturentscheidungen (14.11.2026)

### 17.1 Regelstatus, Zweck und Vorrang

Dieser Abschnitt integriert die Antworten aus der Produktbefragung **bis einschließlich Frage 231** in das bestehende Produktkonzept. Die Nummerierung dient der Rückverfolgbarkeit, nicht als API- oder Ticket-ID. **Frage 232 (technische Ausfallsicherung beim vollständigen Serverausfall) wurde ausdrücklich nicht beantwortet** und ist kein Beschluss.

**Konfliktauflösung:** Neuere konkrete Antworten ersetzen ältere entgegenstehende Ideen, auch wenn diese in den Abschnitten 1–16 noch beispielhaft stehen. Explizite Verbote und Vertraulichkeitsgrenzen gelten unabhängig von Modulen, Rollen und Support. Eine technisch mögliche Funktion ist nicht automatisch freigegeben. Fachliche Beispielabläufe ohne expliziten Entscheidungsstatus sind keine zusätzlichen Muss-Anforderungen. Wo Regeln der Schule oder geltendes Recht noch zu überprüfen sind, darf das System keine ungeprüfte Rechtskonformität behaupten.

**Definition „Version 1.0“:** Offiziell als betriebsfähige Schulplattform veröffentlichte Produktversion; nicht gleichbedeutend mit dem ersten Entwicklungsinkrement oder einem internen Prototyp. Frühe funktionsfähige Module dürfen intern bzw. kontrolliert pilotiert werden, **aber die öffentliche V1-Freigabe erfolgt erst nach Abnahme des gesamten V1-Kerns**. Produktentwicklung erfolgt modular und fortlaufend.

### 17.2 Produkt, Zielmarkt und Lieferumfang

**VERBINDLICH – Zielmarkt V1:** Allgemeinbildende Grundschulen und weiterführende allgemeinbildende Schulen in **Baden-Württemberg** (einschließlich der im Schulalltag relevanten Grund-, Werkreal-/Haupt-, Real-, Gemeinschaftsschulen und Gymnasien). Berufliche Schulen und SBBZ sind nicht automatisch im V1-Abnahmeumfang. Andere Bundesländer sind **SPÄTER** vorgesehen; die Architektur muss regionale Regelwerke, Bewertungsmodelle, Schulformen und Verwaltungsschnittstellen trennbar halten, ohne die fremden Regelwerke bereits zu implementieren. **ASV-BW** ist zunächst die maßgebliche offizielle Stammdatenschnittstelle.

**V1 – vollständig integrierter schulischer Alltagsbetrieb:**

1. Schulorganisation, Schuljahre, Klassen, Kurse, Gruppen, Personal-/Schülerstammdaten, ASV-BW-Import, Konten, Rollen und Berechtigungen.
2. Unterrichtsverteilung, (automatisierbare) Stundenplanerstellung mit manueller Bearbeitung, Vertretungsplanung und Pausenaufsichten.
3. Offiziell verwendbares digitales Klassenbuch einschließlich Unterrichtsdokumentation, Anwesenheit, Fehlzeiten-/Entschuldigungsprozessen und sinnvoller Mobilansicht.
4. Leistungsbewertung, pädagogische Erfassung (einschließlich privater ICE-Beobachtungen), Niveaustufen, Versuche, Notenschlüssel und schulisch zulässige Veröffentlichungen.
5. Portale für Lehrkräfte/Schulverwaltung, Schüler und Erziehungsberechtigte sowie schulische Nachrichten.
6. Schulveranstaltungen, Raumverwaltung/-planung und kollisionsbewusste organisatorische Abläufe.
7. Grundlegende Statistiken, Berichte und berechtigungsgeprüfte CSV/XLSX/PDF-Exporte.

**SPÄTER bzw. außerhalb des verpflichtenden V1-Kerns:** Voll ausgebauter frei konfigurierbarer Analyse-/Dashboard-Baukasten, vollständiges dienst-/tarifrechtliches Deputats-/Mehrarbeitskonto, dediziertes schulweites Notfall-/Alarmmodul, umfassende externe Integrationen und abschließende **Zeugnisproduktion** als rechtsgültige Dokumente. Die jeweiligen Domänen-/Schnittstellen sollen architektonisch vorbereitet bleiben. Einzelne Basisfunktionen dieser Domänen können bereits V1 für benötigte Prozesse erforderlich sein; daraus folgt **keine** Vollimplementierung des späteren Ausbaus. Zentrale Provisionierung, Release- und Sicherheitsgrundlagen sind hingegen **Betriebsvoraussetzungen** der V1-Architektur, nicht automatisch auf später verschiebbar.

**Entwicklungs-/Release-Strategie (216–220):** Modulweise inkrementell implementieren; komplette V1 erst offiziell starten, wenn V1-Kern durchgängig integriert, geprüft und abgenommen ist. Tests neuer Funktionen finden in **zentralen TeacherOS-Testumgebungen** statt, Schulen erhalten keine eigenen Testinstanzen. Gestaffelte Freischaltung ausgewählter Produktivschulen ist nach zentraler Prüfung zulässig. Ein gemeinsamer Produktstandard, keine schulindividuellen Code-Forks. Einsehbare Roadmap mit Funktionswünschen und Bewertungen; endgültige Priorisierung bei TeacherOS. Zentrale Feature-Flags und schuleigene Konfigurationen sind Mittel zur Variation, nicht unterschiedliche Produkte.

**Oberfläche (227):** Einheitliche Navigation und grundlegende Bedienlogik für alle Schulformen in V1; Schulartunterschiede in Modulen/Einstellungen. **SPÄTER optional** schulformspezifische vorkonfigurierte Profile. Keine schon jetzt versprochene vollständige UI-Umgestaltung pro Schule.

### 17.3 Gemeinsames Unterrichts-, Zeit- und Stammdatenmodell

**Eine konkrete Unterrichtsstunde besitzt genau eine dauerhafte ID**, unabhängig von der Planung, Vertretung, Klassenbucheintragung oder Statistik. Fächer, Klassen, Kurse, schuljahresübergreifende und klassenübergreifende Gruppen, temporäre Gruppen, mehrere Lehrkräfte und historische Mitgliedschaften sind abbildbar. Unterrichtstermine verbinden Soll-Plan, veröffentlichte Änderung und tatsächlich dokumentierte Durchführung, ohne diese Sachverhalte zu vermischen.

**Schuljahre:** Laufendes Jahr, vorbereitbares Folgejahr und historische Jahre sind gleichzeitig zugänglich. Übernahme zum Schuljahreswechsel ist assistiert, mit Vorschau und Freigabe; nur passende Einstellungen/Organisation übernehmen. Offizielle Klassen-/Schülerzuordnung verbleibt unter ASV-BW-Führung.

**Zeitsystem:** Jede Schule kann Unterrichtsblöcke frei benennen und Zeiten/Längen konfigurieren, beispielsweise „Ankommenszeit“ statt „1. Stunde“. Mehrere parallele Zeitraster für Klassen/Schulstufen/Gruppen sind zulässig; Kollisionen werden nach **tatsächlichen Uhrzeitintervallen**, nicht bloß nach Blocknummer, geprüft. Pausenaufsichten sind planbare und vertretbare Zeit-/Ressourcenblöcke. Raumkapazität, Ausstattung, Eigenschaften, Belegung, Sicherheit und reale zeitliche Überschneidungen werden gemeinsam geprüft.

**Unterrichtsverteilung:** Lehrer-Fach-Gruppen-Zuordnung kann manuell, importiert oder optimiert entstehen und benötigt fachliche Freigabe. Sie und die zeitliche Stundenplanung sind normalerweise eigenständige Schritte, können aber optional gemeinsam optimiert werden. Lehrkräfte können während einer schulisch festgelegten Frist per Formular harte und weiche Wünsche einreichen; nach Fristende darf die Stundenplanung die Angaben weiterhin bearbeiten.

### 17.4 Stundenplanung, Solver und gültige Pläne

**Stundenpläne:** Manuell per Drag/Drop, Import oder Solver erzeugbar. Der Solver muss einen schulisch einstellbaren Katalog aus harten Regeln und weichen, gewichteten Präferenzen unterstützen; rechtlich/sicherheitsbezogen zwingende harte Regeln sind nicht durch Gewichte aushebelbar. Vor Freigabe werden Konflikte/Abweichungen transparent angezeigt. Lange Berechnungen laufen in separaten Jobs/Workern mit Fortschritts-/Statusanzeige; begrenzte Laufzeit und bestmöglicher gefundener zulässiger Zwischenstand sind erlaubt, **ohne fälschlich globale Optimalität zu behaupten**.

**Versionierte Veröffentlichung:** Der reguläre Stundenplan wird mit Gültigkeitsbeginn veröffentlicht. Eine neue dauerhafte Planung ist eine neue, historisierte Version; frühere Stunden und bereits geschlossene Klassenbucheinträge werden nicht rückwirkend überschrieben. Kurzfristige Einzeländerungen laufen durch die Vertretungsplanung.

**Vertretung:** Eine Zuweisung gilt fachlich immer für **eine konkrete Unterrichtsstunde**. Auch eine lange Vertretungsserie erzeugt einzelne Stundenzuordnungen, keine pauschale Dauerberechtigung auf alle betroffenen Klasseninformationen. Bei längerer Abwesenheit entscheidet die verantwortliche Planung, ob stundenweise Vertretung oder ein neuer, versionierter Stundenplan sinnvoll ist; keine automatische Umschaltung nach festem Schwellenwert.

**Planungsentwurf und Veröffentlichungsstand:** Alle Solver- und manuellen Vorschläge verbleiben zunächst im Entwurf. Änderungen führen zur Neubewertung betroffener Stunden/Räume/Personal-/Aufsichtskonflikte. Bestehende veröffentlichte Vertretungen werden bei neuer Unmöglichkeit als ungültig/konfliktbehaftet erkennbar; sie dürfen nicht stillschweigend unverändert als korrekt gelten. Die Planenden entscheiden über die Veröffentlichung. Für nicht lösbare Bedarfe sind zulässige Teilpläne und klar benannte offene Fälle anzuzeigen.

**WICHTIGE PRÄZISIERUNG zur alten Formulierung „atomarer Sammel-Publish“:** Man kann **ausgewählte einzelne Stunden** aus einem Entwurf veröffentlichen, ohne alle restlichen Änderungen mitzuveröffentlichen. Für jede offene Stunde wird explizit gewählt: unveröffentlicht lassen, als ungelöst veröffentlichen oder als ausgefallen veröffentlichen. Noch nicht veröffentlichte Stunden zeigen im normalen Plan weiterhin den Regelstundenplan und erhalten keinen automatisch erfundenen „in Bearbeitung“-Hinweis. Die **einzelne ausgewählte Veröffentlichung** muss in sich konsistent sein; die frühere Forderung, immer den gesamten Entwurf als Vollversion zu publizieren, ist überholt.

**Bereits veröffentlichter Unterrichtsausfall:** Die Entscheidung aus der Befragung lautet: Ein veröffentlichter Ausfall ist endgültig und darf nicht später stillschweigend wieder als planmäßige Stunde eingesetzt werden. Das ist als starke fachliche Sperre zu implementieren, nicht durch automatische Solveränderungen zu unterlaufen. Für rechtliche Korrekturpflichten ist in Abschnitt 18 ein gezielt begrenzter Prüfpunkt dokumentiert; **keine stillschweigende Ausnahme erfinden**.

**Änderungskonkurrenz:** Der Solver darf während der Berechnung überholte Ausgangsdaten verwenden, sein Ergebnis ist dann aber vor Übernahme gegen die aktuelle Datenlage neu zu prüfen. Verschiedene Planende können unterschiedliche Stunden bearbeiten; Konflikte sind sichtbar. Beim Publizieren zählt der überprüfte Zustand zum Klickzeitpunkt. Später auftretende Änderungen werden als neue Konflikte weiterbehandelt. Harte Regeln dürfen auch durch manuelle Zuordnung nicht umgangen werden.

**Benachrichtigungen:** Veröffentlichte Änderungen lösen **sofortige individuelle Mitteilungen** an tatsächlich Betroffene aus; nicht erst einen späteren Sammelversand. **Aus Entwürfen geht keine Mitteilung aus.** Der veröffentlichte Plan und die nachfolgende tatsächliche Durchführung bleiben getrennt; jede Stunde hat nachvollziehbare Zustände wie durchgeführt, ausgefallen oder noch unbekannt.

### 17.5 Klassenbuch, Unterrichtsdokumentation und Anwesenheit

**Offizieller schulweiter Prozess im V1-Kern:** Unterrichtstermin ist Anker; Klassenbuch-/Anwesenheits-/Lehrstoffdaten sind fachlich eigene, aber verknüpfte Objekte. Schließen einer unterrichteten Stunde ist nur erlaubt, wenn **Anwesenheit und Lehrstoff** erfasst sind, und grundsätzlich nur durch eine tatsächlich an dieser Stunde beteiligte unterrichtende Lehrkraft. Gemeinsames Teamteaching hat **einen** gemeinsamen Klassenbucheintrag und **einen** Abschluss; beteiligte Lehrkräfte dürfen ihn bearbeiten, ihre individuelle Teilnahme/Arbeitszeit bleibt getrennt.

**Vertretungslehrkraft:** Zugriff ausschließlich auf die von ihr tatsächlich übernommene Stunde und dafür erforderliche aktuelle Schülerliste, Anwesenheit, Notizen und Klassenbuchdokumentation. Keine daraus abgeleiteten dauerhaften Noten- oder Zukunftszugriffe. Hinweise der ursprünglich geplanten Lehrkraft gehören zur Vertretungszuweisung; sie werden **nicht** automatisch als Lehrstoff ins Klassenbuch kopiert.

**Ist statt Plan:** Verspätungen und vorzeitiges Verlassen mit Uhrzeiten erfassbar und korrigierbar. Entschuldigungen verantworten Klassenleitung und berechtigtes Sekretariat gemäß schulischem Prozess. Nicht erfasste Anwesenheit bedeutet **nicht**, dass jemand abwesend war. Bei temporärem Unterricht in einer anderen Klasse/Gruppe liegt die Anwesenheitsverantwortung für diese Stunde bei der aufnehmenden Lehrkraft. Bei eintägigen Schulveranstaltungen erfolgt Anwesenheit am Veranstaltungstermin statt als scheinbare Fehlzeit in ausgefallenen Regelstunden; bei mehrtägigen Veranstaltungen initiale und bei Bedarf nachgeführte Kontrollen.

**Schließung/berichtigte Historie:** Bereits geschlossene Unterrichtsstunden schützen gegen widersprüchliche spätere „Ausfall“-Einträge. Korrekturen erfordern eine dokumentierte, berechtigte Wiederöffnung/administrative Berichtigung, ohne fremde Tätigkeit als Handlung der ursprünglichen Lehrkraft auszugeben. Technisch aufbewahrte, überholte Ereignisse dürfen Statistiken nicht verfälschen.

### 17.6 Pädagogische Einträge, ICE und Zugriffsschutz

**ICE – absolute Privatgrenze:** Rohbeobachtungen und private ICE-Aufzeichnungen sind **nur für die erzeugende Lehrkraft** sichtbar. Keine Einsicht durch Kollegen, Klassenleitung, Schulleitung, normale Admins, Betreiber-Support, Eltern, Schüler oder schulweite Export-/Statistikberechtigungen. Das gilt auch für technische Supporttokens und normale Rollen-/Permission-Eskalation. Keine automatische Veröffentlichung, Übertragung in schulweite Daten oder nachträgliche Sichtbarmachung durch eine globale Rolle. Noten und andere ausdrücklich freigegebene Fachobjekte sind von privaten ICE-Rohdaten zu trennen. Die alte allgemeine Formulierung, dass Admin „alles“ lesen könne, umfasst **ICE niemals**.

**Allgemeine pädagogische Einträge:** Schulisch anpassbare Kategorien, fachgebunden oder „Allgemein“. Berechtigte Mitarbeitende können Schülerbeobachtungen anlegen; Sichtbarkeit nach gültigen Schüler-/Lehrerzuordnungen und expliziten Freigaben. Fach- und aktuell zuständige Klassenlehrkräfte können nach Berechtigung lesen. Für identischen Text über mehrere SuS werden separate personenbezogene Einträge angelegt. Ersteller und aktuell berechtigte Klassenleitung dürfen Einträge in vorgesehenem Rahmen bearbeiten/entfernen; **ausschließlich der ursprüngliche Ersteller darf die Schüler-/Elternsichtbarkeit ändern**. Für allgemeine Klassenbucheinträge gilt die im neueren Fachentscheid festgelegte standardmäßige Schüler-/Elternsichtbarkeit; es darf keine allgemeine Veröffentlichungspflicht auf private ICE-Notizen übertragen werden. Erstellungs- und Änderungszeitpunkt sind Pflicht, eine vollständige Zeichen-für-Zeichen-Revisionshistorie fachlicher Texte ist **nicht** pauschal gefordert (gesetzliche Verpflichtungen vorbehalten).

**Zugriffsdauer:** Regelzugriff auf Klassen, Gruppen und SuS besteht anhand **aktueller Unterrichts- oder Aufgaben-Zuordnung**. Endet diese, endet der reguläre Zugriff. Ausnahmen für eigene bereits verfasste Einträge sind eng, zweckbezogen und nach Berechtigungsregeln möglich. Historische stabile Schüler-IDs bleiben zur Reproduzierbarkeit erhalten, berechtigen aber nicht zum historischen Vollzugriff.

### 17.7 Leistungsbewertung und Zeugnisse

**Leistungsbewertung:** Gemeinsames Fach-/Gruppennotenbuch, mehrere berechtigte Lehrkräfte arbeiten darin; Leistungsnachweise, einzelne Autorenschaften und Zuständigkeiten bleiben nachvollziehbar. Punkte und Ergebnisse werden intern exakt berechnet, in der Oberfläche Prozentwerte auf **ganze Prozent** gerundet. Grenzwertentscheidungen verwenden die exakten Werte und geltenden Punkt-/Notenregeln. Pro Leistungsnachweis können Bewertungsmodus für mehrere Versuche (bester/Durchschnitt/erster/letzter/manuell) und Niveaus G/M/E bzw. schulspezifische Niveaus festgelegt werden. Niveauverlauf und erlaubte Stufenwechsel erfolgen nach pro Leistung definierter Regel (sequenziell oder frei) mit manuell und/oder regelbasiert unterstützter Freigabe. Keine verdeckte Gleichsetzung inkompatibler Niveaus. Summe der Punkte genügt; eine verpflichtende Speicherung jeder Einzelaufgabe ist **nicht** beschlossen. Notenschlüssel und niveauabhängige Ausnahmen sind versions- und gültigkeitsbezogen.

**Elternwunsch Ziffernnote:** Schule konfiguriert, ob und wie optional eine numerische Note separat angefordert wird (z. B. Portal, Lehrkrafterfassung, Verwaltungsfreigabe). Der geltende schulweite Jahresmodus steuert die zulässigen Vorgänge; keine ungeprüfte universelle Regel für alle Schularten. Eine bereits veröffentlichte Bewertung wird bei späterer Korrektur oder Notenschlüsseländerung bewusst berichtigt und erneut veröffentlicht, nicht heimlich überschrieben.

**Zeugnisse:** Fachliche Erfassung, Berechnung und vorbereitende Ausgaben sind Teil des Bewertungskontexts. **Als langfristiges Ziel beschlossen** ist eine vollständige Zeugniserstellung. Für V1 ist die finale rechtsgültige Zeugnisproduktion **nicht ausdrücklich als Muss aus der V1-Auswahlliste abgeleitet**; bis zur rechtlichen, organisatorischen und formatbezogenen Freigabe werden vorbereitende Zeugnisdaten/Exporte erstellt. Angestrebte direkte ASV-BW-Anbindung für Noten und Zeugnisse ist später möglich; zunächst strukturierte CSV/XLSX-Exporte. Eine „ASV-kompatible“ Datei darf nur so heißen, wenn das konkrete Importformat tatsächlich geprüft wurde.

### 17.8 ASV-BW-Import, Schuljahreswechsel und externe Schnittstellen

**ASV-BW ist führend** für offizielle Schüler-/Klassenstammdaten, soweit von der Schule verwendet. Import ist kontrolliert: vollständiger oder partieller Umfang; vorab Vorschau; bestehende IDs gegen externe Zuordnung abgleichen; neue, aktualisierte, deaktivierte und datierte künftige Änderungen unterscheiden. Unklare Zuordnungen werden manuell aufgelöst; **alle Konflikte müssen vor dem atomaren Commit geklärt sein**. Maximal ein aktiver Importentwurf pro Schule, aber berechtigte Mitarbeitende dürfen gemeinsam daran arbeiten. Hochgeladene neuere Datei darf Entwurf aktualisieren und muss gegen aktuellen Live-Bestand neu validiert werden. Ein beliebiger berechtigter Prüfer darf nach vollständiger Prüfung committen. Bei technischem Fehler bleibt Entwurf erhalten, Wiederholung muss idempotent sein. Übernommene Änderungen sind unter Schutz bestehender fachlicher Datensätze kontrolliert zurücknehmbar. Ein Standard-Benachrichtigungskreis pro Schule kann durch ausführende Person je Import angepasst werden.

**Integrationen:** Schulbezogene gemeinsame Integrationsinfrastruktur für authentifizierte APIs, Webhooks und Adapter. Fachmodule verwenden wiederverwendbare sichere Verträge; keine unkontrollierte Fremdcode-Installation. Anbindung für andere Bundesländer und ggf. zusätzliche externe Dienste später. Die Entscheidung für die **Integrationsarchitektur** ist keine Zusage, dass jede einzelne Fremd-API in V1 implementiert wird.

### 17.9 Benutzerkonten, Rechte, Eltern und Support

**Identität:** **Getrenntes Konto pro Schule** einschließlich eigenem Login- und TOTP-Setup auch dann, wenn eine Person an zwei Schulen tätig ist. Es gibt keine automatische schulübergreifende Benutzeridentität oder Freigabe. Bestehenden `jwt-authservice` als Authentifizierungsbasis verwenden; keine zweite parallele Auth-Plattform schaffen. JWT-Signaturen können pro Schulinstanz lokal geprüft werden; bei Auth-Service-Ausfall funktionieren bestehende gültige Tokens bis zu deren Ablauf, neue Anmeldung/Erneuerung nicht. `isInactive` ist **bei jedem geschützten Request** anhand aktueller serverseitiger Kontoinformation zu prüfen; ein gültiges Token hebt eine Deaktivierung nicht auf.

**MFA:** TOTP für schulische Mitarbeitende/Administration verpflichtend, für Eltern und SuS optional; Auth-Service bei Bedarf um gehashte Recovery-Codes und verwaltetes TOTP-Reset erweitern. Dauerhafter Session-Widerruf, Force-MFA und gesondertes Deaktivierungs-/Reaktivierungsverhalten sind vorzusehen. Passkeys und SSO sind mögliche spätere Erweiterungen, nicht V1-Voraussetzung.

**Berechtigungen:** Vorhandenes **PHP-Permissionsmodul** wiederverwenden (mehrere Rollen, additive einzelne Permissions, rollenbasierte Verwaltung); nur bei fehlenden benötigten Kontext-/Objekt-/Zeit-Scopes gezielt erweitern. Keine zweite konkurrierende Permission-Engine. Unterscheide `roles.manage`, `roles.assign` und `permissions.assign`; nicht-administrative Berechtigungsverwalter dürfen eigene Rechte nicht direkt erweitern. Ein Admin darf nach Beschluss administrativ Rollen verwalten und sich über diese Rechte zuweisen, **aber auch Admin bleibt von privaten ICE-Daten ausgeschlossen**. Letztes aktives Schuladmin-Konto darf nicht versehentlich entfernt werden. Initialer Admin durch Einladung an definierte E-Mail; weitere Admins können von bestehenden Admins eingesetzt werden. Zentraler dokumentierter Notfall-Recovery-Zugang nur bei fehlenden/unerreichbaren aktiven Admins; begründete und personenbezogen nachvollziehbare Wiederherstellung, keine pauschale Dauertür.

**Eltern/SuS:** Elternkonten mit schulisch konfigurierbaren Aktivierungs-/Prüfverfahren (verifizierte E-Mail, Schulcode, manuelle oder geeignete Identifikation). Elternrechte sind pro Kind und Erziehungsberechtigtem festgelegt, grundsätzlich gleich, aber mit gezielten Ausnahmen. Schulleitung/ermächtigtes Sekretariat verwaltet diese Rechte. Rechteänderungen wirken **sofort bei neuen API-Anfragen** auch in laufenden Sitzungen; betroffene Eltern erhalten eine geeignete Benachrichtigung, vertrauliche interne Begründungen verbleiben intern. Befristete Rechte enden automatisch; zuständiges Personal wird informiert.

**Betreiber-Support:** TeacherOS verfügt dauerhaft nur über technische Hosting-/Monitoringrechte. Einblick in fachliche Schuldaten erfolgt **nur per schulisch autorisiertem, zeitlich begrenztem, widerrufbarem Supporttoken**, gebunden an authentifizierte Supportperson und Modul sowie Lese-/Schreibumfang, vollständig auditiert. Kein Supporttoken darf ICE-Rohbeobachtungen freigeben.

### 17.10 Veranstaltungen, Räume, Deputat und Statistik

**Schulveranstaltungen:** Eigenes Fachmodul für Planung, Freigabe, betroffene Schüler/Klassen/Lehrkräfte, Zeiträume und Räume. Auswirkungen auf Regelstunden, Vertretungen, Anwesenheit, Aufsichten und Zeit-/Statistikdaten werden deterministisch und ohne doppelte Zählung berechnet. Zeitlich veränderbare Lehrkräfteabwesenheiten werden **im selben historischen Datensatz** verlängert, verkürzt, berichtigt. Schulisch einstellbare Fehlzeitkategorien und zeitliche Gültigkeiten, sensible Gründe nie allgemein in Vertretungsplänen exponieren.

**Räume:** Zentraler Ressourcenbestand, gemeinsame Belegungsprüfung für Regelstundenplan, Vertretung, Veranstaltungen und Aufsichten. Geeignete Alternativen oder Raumtausch als Planungsmöglichkeiten, keine automatische Umgehung zwingender Sicherheits-/Ausstattungsregeln.

**Zeit-/Deputatskonten:** Langfristig getrennte Konten für **tatsächlich durchgeführten Unterricht** und **dienst-/tarifrechtlich relevante Anrechnung/Mehrarbeit**, einschließlich Soll-Pensum, Reduktionen, Ausfall, Vertretung, Teamteaching, Aufsicht und Veranstaltungen nach konfigurierbaren regionalen Regeln. Im V1-Kern nur diejenigen Basisinformationen erfassen, die für Planung, Anwesenheit und Basisstatistiken erforderlich sind; die **vollständige** dienstrechtliche Kontoführung ist **SPÄTER**.

**Statistik:** Reproduzierbare schuljahresbezogene Daten aus **veröffentlichten UND tatsächlich dokumentierten** Stunden, Vertretungen, Ausfällen, Aufsichten, Veranstaltungen und legitimen personellen Abwesenheiten. Schon V1 braucht Basisauswertungen/CSV/XLSX/PDF. Später: frei konfigurierbare KPIs, ausführliche Analysen und eigene bzw. geteilte Dashboards. Persönliche, rollenbezogene und individuell freigegebene Dashboards dürfen **niemals** zusätzliche Fachzugriffe erzeugen. Schulweite Exporte unterliegen Berechtigungen, **ICE immer ausgenommen**. Kein Export darf einen nicht dokumentierten Ist-Zustand als gewiss darstellen.

### 17.11 Nachrichten, Dateien, Benachrichtigungen, mobile Bedienung

**Kommunikation:** Integrierte 1:1-, Gruppen- und Schulnachrichten sind eigenständige bewusste Benutzerkommunikation. Systembenachrichtigungen (Vertretung, Rechte, Import, Aufgabe, Freigabe) sind davon getrennt. Fachliche Dateien werden nur im jeweiligen Kontext des Moduls verwaltet; keine universelle gemeinsame Dateiablage als V1-Muss.

**Oberfläche und Geräte:** Gemeinsame Web-Frontend-Codebasis mit deutlich abgegrenzten Lehrkräfte-/Verwaltungs-, Schüler- und Elternportalen; responsive Desktop/Tablet/Mobilansichten. Für iOS/Android **native App-Hülle** über das gemeinsame Webfrontend (z. B. Capacitor) zur Nutzung nativer Push-Funktionen und Benachrichtigungstöne. PWA allein ist nicht ausreichend als Produktannahme für verlässliche Push-/Alarmfunktionen. Android/iOS-Betriebssystemgrenzen sind stets zu beachten.

**Offline:** Begrenztes Lesen bereits geladener eigener Unterrichts-/Stundenplandaten und lokale Erfassung von Anwesenheit bzw. Lehrstoff zur späteren Synchronisierung. Schreibkonflikte, Datenspeicherung und Geheimnisschutz müssen sicher behandelt werden. Keine generelle Offline-Verwaltung/Administration.

**Notfall-/Alarmfunktion (SPÄTER):** Geplantes dediziertes schulweites Modul mit gezielter Warnung, Push/Ton, Quittungen und Zustellstatus sowie zentraler Sicherheitsliste, in der Lehrkräfte Schüler als sicher/fehlend/noch nicht geprüft kennzeichnen. Keine Garantie für vollständige Geräte-/Netzzustellung, keine alleinige gesetzliche oder schulische Notfallkette. Früher vorgeschlagene externe geprüfte Alarmadapter sind mögliche technische Integration, **nicht als einzig zulässiges Architekturmodell endgültig beschlossen**.

### 17.12 Technische Architektur, Repositories und zentrale Plattform

**Zentrale Betreiberplattform, getrennte Schuldaten (164–172, 221–231):** TeacherOS betreibt die Plattform zentral, bevorzugt auf **gemieteten professionellen Servern in Deutschland**, selbst verwaltete Anwendungen und Datenbanken. Jede Schule erhält **eigene Domain/Subdomain, eigenen Backenddienst, eigene Datenbank**, logische Trennung der Zugangs-/Datei-/Secrets-/Backupbereiche. Physische Server dürfen geteilt sein. Schule betreibt/patcht keine eigenen Server als V1-Standard. Zentrale Control Plane übernimmt Einrichtung, Konfiguration, Modulfreigabe, Feature-Flags, Rollout, Monitoring und Betrieb. Schulinstanzen werden möglichst automatisiert provisioniert und auf Server verteilt; wachsende Serverkapazitäten werden kontrolliert integriert. Keine vorab verpflichtende Kubernetes-/Managed-Cloud-Festlegung.

**Autonomie der Schulinstanz:** Ausfall der zentralen **Verwaltungs**plattform darf den normalen schulischen Betrieb bereits aktivierter Funktionen **nicht** stoppen. Schulinstanz speichert hierfür erforderliche Freischalt-/Konfigurationszustände lokal; zentrale neue Freigaben, Deployments und Supportkoordination können pausieren. Separat gilt die dokumentierte Teilverfügbarkeit des Auth-Services. Keine Live-Verbindung zur Control Plane bei jeder schulischen Fachanfrage verlangen.

**Backend:** Ein **modularer Monolith pro Schule** mit klaren Fachgrenzen, versionierten APIs und Ereignisverträgen; rechenintensive Worker/Solver getrennt. Async-Auftragswarteschlange für Solver, große Exporte und andere lange Operationen, Fortschritt, Fehler, idempotente Wiederholbarkeit; keine ungeprüften externen Schulerweiterungen. Alle modulbezogenen Rechte und Mandanten-/Kontext-Scopes auf API-/Datenzugriffsebene durchsetzen.

**Repositories (221):** **Getrennte Repositories** für Frontend, Backend, Solver und wiederverwendbare gemeinsame Bibliotheken/Verträge. Keine Monorepo-Pflicht. Jedes Repository erhält eigene Entwicklung/CI-Prüfung und kann getrennt versioniert werden.

**Releases (222):** Ein zentrales Release-Manifest pinnt eine **gemeinsam getestete konkrete Kombination** aus Frontend-, Backend-, Solver- und Bibliotheksversionen. Integrationstests vor produktiver Freigabe. Releases gestaffelt auf Schulinstanzen ausrollen, zentrale Tests vor Freischaltung, Monitoring und Stopp bei Fehlern. Keine unkoordinierten beliebigen Kombinationen produktiv bereitstellen. Reguläre Updates möglichst ohne spürbare Unterbrechung, Migrationsschritte soweit möglich kompatibel; unvermeidbare Unterbrechungen vorher ankündigen und bevorzugt außerhalb der Unterrichtszeiten legen (226).

**Schulspezifische Einstellungen:** Zeitlich versioniert und mit Gültigkeitsbeginn, damit historische Ergebnisse reproduzierbar bleiben. Modulaktivierung durch Zentrale ist von Benutzerpermissions und schulischen Facheinstellungen unabhängig. Eine Feature-Flag-Freischaltung gewährt niemals eigenständig Zugang zu Objektdaten.

**Integrationen:** Einheitliches schulbezogenes API-/Webhook-/Adaptermodell; vertraglich versioniert. Wo eine bestehende Fremdsoftware Schnittstellenfähigkeit nicht garantiert, Implementierung erst nach Nachweis des Formats.

### 17.13 Sicherer Betrieb, Audit, Datenschutz und Datenlebenszyklus

**Audit (225):** **Gemeinsamer datensparsamer Audit-Dienst je Schulinstanz** für administrative und sicherheitsrelevante Vorgänge (wer, wann, welche Handlung, welches Objekt, soweit erforderlich Änderungskontext). Keine pauschale Volltextkopie fachlicher Schülerdaten oder lückenlose generelle Klick-/Lesesurveillance. Fachliche Historien erfüllen ihre eigenen Änderungsanforderungen; besonderer Schutz für Supportzugriffe, Rechteänderungen, Veröffentlichung und Importe.

**Aufbewahrung und Löschung:** Zentrale Kategorisierung von Datentypen und nachweisbar zutreffenden, historisierbaren Aufbewahrungsregeln je Schule/Jurisdiktion, gesteuerte Archivierung/Löschung und entsprechende Auditierung. Konkrete gesetzliche Fristen müssen geprüft werden; dieses Dokument setzt keine pauschalen Fantasiewerte.

**Backups:** Verschlüsselt und automatisiert, Wiederherstellung einzelner Schulinstanzen, geeignete zeitpunktbezogene Wiederherstellung soweit realisierbar, regelmäßige Recovery-Proben. Monitoring zentral über Infrastruktur, Schulinstanzen, Datenbanken, Aufträge, Solver und Benachrichtigungs-/Alarmwege unter Datensparsamkeit; zentrale Statusseite sowie zielgerichtete Störungsmeldungen an betroffene Schulen.

**Wartung:** Reguläre Releases möglichst unterbrechungsfrei; falls technische Unterbrechung nötig: angekündigte Wartungsfenster, gesicherte Schreibtransaktionen, kein inkonsistenter Halbzustand. **Das konkrete Hochverfügbarkeits-/Failover-Konzept bei vollständigem Serverausfall ist nicht beschlossen** (Frage 232 vertagt). Ebenso wurden keine verbindlichen SLA-/RPO-/RTO-Zahlen beschlossen.

**Rechts- und Freigabevorbehalt:** Realbetrieb mit Schüler-/Personaldaten erst nach nachgewiesener rechtlicher, datenschutzrechtlicher und schulorganisatorischer Zulässigkeit. Serverstandort, Verträge, verantwortliche Stelle, Schutzniveau, nationale Regelwerke, Notfallrecht und ASV-Formate sind vor Produktivfreigabe tatsächlich zu verifizieren; die technische Soll-Spezifikation ersetzt diese Nachweise nicht.

### 17.14 Verbindliche Qualitäts- und Abnahmekriterien

1. **Isolation:** Zwei Schulen können weder über Login/Token, direkte API, Rollen, Support oder Export gegenseitig auf Daten zugreifen. Identische E-Mail-Adressen erzeugen nicht automatisch ein gemeinsames Konto.
2. **ICE-Sperre:** Selbst Schuladmin, fachlicher Support und schulweiter Statistikexport können fremde private ICE-Rohbeobachtungen nicht lesen.
3. **Plan-/Ist-Treue:** Ein Unterrichtstermin behält die ID über Versionen/Vertretungsänderungen. Veröffentlichte, tatsächlich durchgeführte, geschlossene und ausgefallene Stunden bleiben in Plan, Klassenbuch und Statistik unterscheidbar.
4. **Vertretungsrechte:** Fachkraft für eine Einzelvertretungsstunde kann diese Stunde bearbeiten, hat aber nicht deshalb das gesamte Notenbuch/weitere Stunden offen.
5. **Teilveröffentlichung:** Nur ausdrücklich gewählte Stunden werden veröffentlicht; andere Entwurfsstände bleiben intern. Veröffentlichung benachrichtigt individuell ohne künstlichen Sammel-Timer.
6. **Import:** ASV-BW-Konflikte verhindern Commit, bis sie geklärt sind; wiederholter Commit erzeugt keine Duplikate und überschreibt keine geschützte Unterrichtshistorie.
7. **Stundenschluss:** Ohne erfasste Anwesenheit und Lehrstoff keine normale Schließung; Teamteaching nur ein gemeinsamer Eintrag mit separater Anwesenheit der Lehrkräfte.
8. **Rollen/Rechte:** Inaktive Konten werden bei API-Request geblockt, Elternrechte wirken nach Änderung unmittelbar, Support kann ausschließlich freigegebene Scopes und Zeitfenster nutzen.
9. **Solver:** Keine harte Regelverletzung durch automatische oder manuelle Veröffentlichung; überholtes Rechenergebnis muss vor Übernahme gegen aktuelle Daten geprüft werden.
10. **Release:** Jede produktive Schule nutzt ein getestetes zentrales Versionsmanifest; Ausfall der Control Plane behindert die bereits laufenden Fachmodule nicht.
11. **Versionsgrenze:** Offizielles 1.0-Release nur, wenn alle V1-Module aus 17.2 einschließlich grundlegender Statistiken und Portale integriert und im End-to-End-Betrieb abgenommen wurden.

### 17.15 Entscheidungsmatrix und frühere Aussagen, die ausdrücklich ersetzt wurden

| Thema | Bisheriger oder potenziell missverständlicher Stand in Abschnitten 1–16 | Verbindlicher neuer Stand |
|---|---|---|
| Marktstart | Persönliche ICE-/Noten-App als erstes vollständiges Produkt; P0–P4 gleich Releases | Persönliche App kann Inkremement sein, **V1 = integrierter schulweiter Alltagsbetrieb** |
| Plattform | Anfangs ggf. selbst betriebenes NAS und „später“ Multi-School | **Zentraler TeacherOS-Betrieb**, separates Backend/DB pro Schule, gemietete professionelle Server |
| Benutzeridentität | Optional gemeinsame Identität / schuleübergreifende Auth-Modelle | **Ein Account und ein TOTP je Schule**, auch bei derselben Person |
| Mobile App | Primär PWA; nativer Wrapper nur „bei Bedarf“ | **Gemeinsames Frontend plus native Hülle für iOS/Android**, wegen Push-/Benachrichtigungsanforderungen |
| Vertretungspublikation | Gesamter Entwurf als atomare Sammelveröffentlichung; Benachrichtigungen gebündelt | **Ausgewählte Stunden getrennt veröffentlichen**, je betroffene Person unmittelbar informieren |
| Fachzugriff | Rollen oder globale Adminrechte wirken allgemein | **Aktuelle Zuordnung + Objekt-/Zeitkontext**, ICE immer ausschließlich Autor |
| Sondermodule | Zeugnisse, frei definierbare Analytics, volle Deputatslogik, Alarm teils vermischt | **Langfristiges Ziel**, nicht automatisch vollständiger V1-Kern; Basisanforderungen von V1 trennen |
| Erweiterung | Unterschiedliche Schulversionen denkbar | **Ein Produkt, schulische Settings/Module/Flags, keine Forks** |
| UI | Schulformspezifische UI denkbar | **Einheitliche UI in V1**, Profile optional später |
| Geografie | Lokaler Einzelschuleinsatz | **Allgemeinbildende Schulen BW in V1**, andere Länder später architektonisch möglich |
| Repositories | Monorepo-/Codeorganisation offen | **Getrennte Repositories**, gemeinsames geprüftes Release-Manifest |
| Testbetrieb | Eigene Schul-Testinstanzen oder Tests in Produktivmodus denkbar | **Zentrale TeacherOS-Testumgebungen** für neue Funktionen |
| Control Plane | Dauernde zentrale Lizenz-/Kontrollverbindung denkbar | **Schulbetrieb bleibt bei Ausfall der Control Plane funktionsfähig** |
| Auth-Ausfall | Auth-Service möglicherweise für jeden Request nötig | **Lokale JWT-Prüfung bis Tokenablauf**, serverseitig `isInactive`/Permissions weiter aktuell |
| Audit | Modulindividuelle Logs | **Gemeinsamer datensparsamer Audit-Dienst je Schule** |
| Wartung | Regelmäßige Ausfallfenster oder garantierter Zero-Downtime-Betrieb | **Möglichst ohne Unterbrechung**, nötige Wartungsfenster angekündigt |

### 17.16 Revision 1 – bestätigte Nachentscheidungen (14.11.2026)

Diese Revision hat Vorrang vor 17.1–17.15, soweit sie diese konkretisiert, und vor allen älteren Abschnitten. Betroffene Altstellen sind mit **[Überholt durch 17.16 / Rev. 1]** markiert.

1. **VERBINDLICH – Sichtbarkeit von Klassenbucheinträgen:** Die erstellende Lehrkraft stellt je Eintrag ein, wer ihn sieht: *SuS*, *Eltern/EZB*, *beide* oder *keiner*. **Default: beide.** Sichtbarkeit bleibt Zielgruppe, nicht Autorisierung (nur betroffene SuS bzw. aktuell berechtigte EZB). Nur der Ersteller darf die Sichtbarkeit ändern (17.6). Private ICE-Beobachtungen sind davon ausgenommen und immer nur für die Autorin/den Autor sichtbar. Die Regel „neue Einträge standardmäßig unveröffentlicht“ gilt weiterhin für sonstige pädagogische Einträge, nicht für Klassenbucheinträge.
2. **VERBINDLICH – Vertretungsveröffentlichung:** Alle älteren Aussagen zu „atomarer Sammelveröffentlichung des Gesamtentwurfs“ und „gebündelten Benachrichtigungen“ (1 Nr. 20, 5.5, 6.8, 6.11 Nr. 9, 8.1, 10.2 P4-05, 16.3) sind durch 17.4 ersetzt: Teilveröffentlichung ausgewählter Stunden, in sich konsistent und atomar je Veröffentlichungsvorgang, sofortige individuelle Mitteilung an Betroffene. Datenmodell und API sind entsprechend pro Stunde zu entwerfen.
3. **VERBINDLICH – Repositories:** Getrennte Repositories für **Frontend** (React-Webapp inkl. Capacitor-Hülle), **Backend/API** (modularer Monolith; alle Fachmodule in einem Repository), **Auth** (bestehender `jwt-authservice`) und – ab Stundenplanung – **Solver**. Innerhalb eines Repositorys liegt der gesamte Code der jeweiligen Komponente. Ort von gemeinsamen API-Verträgen (OpenAPI) und Release-Manifest per ADR festlegen.
4. **VERBINDLICH – Native App-Hülle:** iOS/Android erhalten eine native Hülle (z. B. Capacitor) über das gemeinsame Webfrontend; eine reine PWA genügt nicht, weil Benachrichtigungstöne, Kanäle mit hoher Priorität und – für das spätere Alarmmodul – das Übersteuern von Stumm-/Nicht-stören-Einstellungen nur nativ erreichbar sind. Betriebssystemgrenzen bleiben bestehen (iOS Critical Alerts benötigen eine gesonderte Apple-Berechtigung; Android benötigt die vom Nutzer erteilte „Nicht stören“-Zugriffsberechtigung). Das Alarmmodul selbst bleibt **SPÄTER** (17.11), die native Hülle ist V1.
5. **VERBINDLICH – Datenbank:** **MariaDB** ist die primäre Datenbank (vorhandene Infrastruktur und Pakete). 18.1 ist insoweit erledigt.
6. **Bestehende Pakete:** `jwt-authservice`, PHP-Permissionsmodul und `@hoelzle/react-ui` liegen lokal beim Entwickler unter `D:\Programming\Components`. Erster Implementierungsschritt: diese Pakete gegen 17.9 prüfen (Objekt-/Zeit-Scopes, `isInactive` bei jedem Request, TOTP-Recovery/-Reset, Session-Widerruf, Trennung `roles.manage`/`roles.assign`/`permissions.assign`) und Lücken als gezielte Erweiterung dokumentieren (ADR), keine Parallel-Engine.
7. **SPÄTER – ASV-BW-Import (bewusst zurückgestellt):** Rechercheergebnis 14.11.2026: Für ASV-BW existiert keine öffentlich dokumentierte Import-/Export-Schnittstellenspezifikation (die XML/XSD-Schnittstelle betrifft nur ASV Bayern). Marktüblich liefert der Drittanbieter eine eigene **`.exf`-Exportformatdatei**, die die Schule über die ASV-BW-Exportformatbibliothek importiert; die ASV erzeugt daraus CSV/Excel mit festgelegten Feldern und Reihenfolge. TeacherOS folgt diesem Muster: eigenes, versioniertes Importprofil plus mitgelieferte `.exf`-Datei. **Vor Umsetzung von der Schule zu beschaffen:** verfügbare Felder der Exportformatbibliothek (insbesondere stabile Schüler-ID), ggf. bestehende `.exf`-Datei eines anderen Anbieters, kleiner anonymisierter Beispielexport, Erlaubnis zum Test der eigenen `.exf`-Datei. Bis dahin Stammdaten über einfachen CSV-Import (P0) mit synthetischen Daten.
8. **VERBINDLICH – Testbetrieb bis zur Freigabe:** Entwicklung und Tests (auch im realen Unterricht) ausschließlich mit synthetischen Daten aus einem Testdaten-Generator (realistische Klassen mit 25–30 SuS, Eltern, Noten, Stundenplan). Echte Schüler-/Personaldaten erst nach Freigabe gemäß 17.13 (in BW u. a. VwV „Datenschutz an öffentlichen Schulen“: Genehmigung der Schulleitung, Eintrag im Verzeichnis von Verarbeitungstätigkeiten, TOMs, Einbindung des Datenschutzbeauftragten, ggf. Auftragsverarbeitungsvertrag). Freigabeunterlagen werden parallel zur Entwicklung vorbereitet; Freigabe so formulieren, dass Updates ohne Änderung von Zweck, Datenarten oder Hosting keine Neugenehmigung auslösen (mit Datenschutzbeauftragtem abstimmen).

---

## 18. Bewusst nicht vertiefte Punkte und Grenzen der Spezifikation

Dieser Abschnitt ist **kein Backlog vermeintlich vergessener Grundsatzfragen**. Die Befragung wurde nach 231 Entscheidungen ausdrücklich beendet, weil weitere Detailabfragen keinen ausreichenden Produktnutzen mehr brachten. Alle nachstehenden Punkte sind entweder Implementierungsfreiheit, externe Verifikation oder bewusst spätere Produktarbeit. Entwickler dürfen sie **nicht ohne Kennzeichnung** in verbindliche fachliche Regeln umdeuten.

### 18.1 Bewusst offengehaltene technische Implementierungsdetails

- **Hosting-/Failover-Tiefe:** Vollständiger Serverausfall, warmes oder aktives Failover, Redundanzgrad, Replikationsverfahren und Orchestrierungsprodukt (**Frage 232 vertagt**). Verbindlich sind bislang lediglich Backups, automatisierte Provisionierung, Kontrolle/Monitoring und möglichst geringe Update-Unterbrechung.
- **Nichtfunktionale Zielzahlen:** Noch keine fixierten Verfügbarkeits-SLAs, RPO/RTO, Antwortzeiten pro Modul, Maximallasten, konkrete Serveranzahl, Kosten-/Kapazitätsformel oder verbindliche Anzahl gleichzeitiger Schulen.
- **Werkzeugauswahl:** ~~Konkrete Datenbankengine~~ (entschieden: MariaDB, 17.16 Nr. 5), Queue, Container-/Orchestrierungsstack, Observability-Produkte, Solver-Bibliothek, Cloud-Anbieter und iOS-/Android-Wrapper-Implementierung sind nicht endgültig aus dieser Befragung abgeleitet. Bereits vorhandene technische Referenzen sind Vorschläge, keine stillschweigenden Produktbeschlüsse.
- **API/Schema:** Tabellen- und Klassennamen, genaue REST-Endpunkte, Nachrichtenpayloads, Indexstrategien, Revisions-/Cachingverfahren, Retry-Timer, Tokenlaufzeiten, Queue-/Lock-Semantik, Konflikt-UI und Dateiformate innerhalb der vereinbarten Verträge sind Engineering-Entscheidungen.
- **Rollout im Detail:** Exakte Anzahl der gestaffelten Schulen, Wartungsuhrzeiten, Testmatrix, Auslieferungsfrequenz und Rollbackmechanik sind festzulegen, wenn Infrastruktur/Team bekannt sind.
- **Anwendungsdesign:** Pixelgenaue Screens, Farben, Navigationstexte, Dashboards, Tabellen-Spalten und Ausnahme-Dialoge sind kein Teil der 231 Entscheidungen; maßgeblich bleiben Bedienbarkeit, Pflichtprozesse und Rechte.

### 18.2 Externe fachliche/rechtliche Verifikation – keine implizite Entscheidung

- **Schulrecht BW / weitere Länder:** Verbindliche Leistungs-/Zeugnisregeln je Schulform, rechtliche Anforderungen an Klassenbuch, Fehlzeiten, Elternrechte, Aufsichten und Deputat sind vor Einsatz gegen aktuelle Normen zu prüfen. Das Regelwerk muss zulässige regionale Konfigurationen erzwingen.
- **ASV-BW:** Formate, unterstützte Export-/Importfelder, tatsächlich verfügbare APIs und zertifizierte Anbindungen müssen technisch nachgewiesen werden; keine ungeprüfte Kompatibilitätszusage. Stand der Recherche und Vorgehen (`.exf`-Exportformat): siehe 17.16 Nr. 7.
- **Datenschutz:** Rechtsgrundlagen, Auftragsverarbeitung, Speicherfristen, Löschkonzept, TOMs, Datenschutz-Folgenabschätzung soweit nötig, Minderjährigen-/Elternrechte und Prüfung von Dienstleistern sind keine durch dieses Dokument erteilte Freigabe.
- **Notfallkommunikation:** Betriebssystem-Push-Grenzen, Alarmkanäle, Zustell-/Quittungssemantik und schulische Notfallplanung erfordern gesonderte Sicherheitsfreigabe; kein garantierter Alarm durch einen bloßen App-Button.
- **Zahlungen und rechtsverbindliche Formulare:** Umfang und Rechtswirkung einzelner Workflows hängen von schulischer Freigabe und geltendem Recht ab; bisherige Produktideen zu SEPA-QR und Rückmeldungen sind keine Erlaubnis zum Bankzugriff oder zur universellen elektronischen Unterschrift.

### 18.3 Bewusst verschobene Produktausbaustufen

- Vollständige rechtsgültige **Zeugnisproduktion** und nachweislich kompatible direkte Zeugnis-/Notenübertragung an ASV-BW.
- Voll ausgebautes **Deputats-, Arbeitszeit- und Mehrarbeitskonto** mit rechtskonformer Anrechnung.
- Frei konfigurierbare **Analyse-/Dashboardplattform** über V1-Basisberichte hinaus.
- Dediziertes **Notfall- und Alarmierungsmodul** inklusive Sicherheitsliste, verlässlicher organisatorischer Freigabe und sicherem Betrieb.
- Zusätzliche **Bundesländer**, berufliche Schulen und SBBZ sowie optional schulformspezifische Standardprofile.
- Weitergehende externe **Integrationen, SSO/Passkeys** und weitere Schulsoftware-Schnittstellen.

### 18.4 Wenige bewusst markierte Prüf- und Auslegungspunkte

1. **Veröffentlichter Ausfall vs. Berichtigungspflicht:** Fachentscheidung = veröffentlichter Unterrichtsausfall ist endgültig. Ob und wie offensichtlich fehlerhafte Verwaltungsangaben rechtlich korrekt berichtigt werden müssen, wurde **bewusst nicht als Sonderfall ausdiskutiert**. Implementierung darf keinen stillen Rückbau ermöglichen; ein später dokumentierter, geprüfter Korrekturprozess könnte erforderlich werden.
2. **Zeugnisumfang V1:** Vollständige Zeugniserstellung ist als Ziel festgelegt; die explizite V1-Auswahl benennt Leistungsbewertung und Portale, nicht rechtssichere Finale. Daher als **SPÄTER** markiert, bis eine separate Releaseumfang-Entscheidung dies erweitert. Keinesfalls bereits V1 als rechtssicheres Zeugnisprodukt bewerben.
3. **Alte allgemeine Eintrags-Defaults vs. Klassenbuchsichtbarkeit:** Privat erfasste Beobachtungen sind stets privat; allgemeine Klassenbuch-/Schüler- bzw. Elternfreigaben müssen modulspezifisch greifen. Allgemeine Alttexte „alles zunächst nicht veröffentlicht“ sind für konkret neu beschlossene Klassenbucheinträge **nicht pauschal gültig**.
4. **Entwicklungstaktung:** Schulweiter Release 1.0 ist verbindlich, der erste private Prototyp darf enger sein. Historische P0–P4-Aufgabenfolgen sind zulässige Umsetzungsschnitte, jedoch kein Ersatz für den V1-Abnahmeumfang.

### 18.5 Umgang mit weiteren Entscheidungen

Nur eine **materielle** Lücke, die die Sicherheit, Rechtskonformität oder eine bereits vereinbarte Benutzerfunktion tatsächlich verhindert, ist vor Umsetzung zu eskalieren. Sonstige technische Details dokumentiert das Engineering-Team als nachvollziehbare **Architecture Decision Records (ADRs)** mit Alternativen, Risiken, Tests und Kompatibilität zum vorliegenden Dokument. Fachliche Änderungen an den Abschnitten 17–18 benötigen bewusste Entscheidung und dokumentierte Revision mit Datum; keine stillschweigende Verschiebung des V1-Kerns oder Ausweitung von ICE-Zugriffsrechten. Dieser Stand ist die **Baseline** für Tickets, API-Verträge, Implementierung, Tests und Codex-Arbeitsaufträge.
