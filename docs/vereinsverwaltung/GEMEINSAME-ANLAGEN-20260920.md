# Gemeinsam genutzte Anlagen – Entwicklung und Teilabnahme

Fortsetzung 27.09.2026: [belegte gemeinsame Anlagenabgänge und Folgejahre](GEMEINSAME-ANLAGEN-ABGAENGE-20260927.md).
Die dort beschriebenen Voll-/Teilabgänge ersetzen die entsprechende frühere
Einschränkung unten. Sphärenübergreifende Nutzung und Nutzungswechsel bleiben offen.

Stand 20.09.2026. **Kein Produktivrelease und keine vollständige allgemeine
Vereinsbilanz-Abnahme. Kulturhus bleibt EÜR.**

## Umgesetzter Umfang

Lineare, sofort abgeschriebene (GWG) und degressive Anlagen ohne Abgang können
innerhalb ihrer bereits feststehenden steuerlichen Sphäre
mehreren Betrieben zugeordnet werden, beispielsweise einer Küche zwei getrennten
Verkaufsbetrieben. Der Gegenstand bleibt einmal im Original-Anlagenverzeichnis.
Die Aufteilung ist ein zusätzlicher interner Nachweis; sie erzeugt weder neue
Gegenstände noch zusätzliche Abschreibung, Journalbuchungen oder Steuervorteile.

- Je Gegenstand: Nutzungsmaßstab (Stunden, Tage, Nutzungen oder Flächeneinheiten),
  positive ganze Einheiten je Betrieb und ein Nachweis. Keine Schätzung durch das
  Programm. Die bestehenden Konten und steuerlichen Vereinsbereiche bleiben fest.
- Erwerbswerte, kumulierte Abschreibung und Buchwerte werden in ganzen Cent
  aufgeteilt. Eine monotone Höchstzahlverteilung verhindert, dass ein bereits
  zugeteilter kumulierter Cent im Folgejahr wieder verloren geht. Laufende
  Abschreibungsanteile entstehen aus der Differenz der kumulierten Werte, nicht
  aus einer unabhängigen Rundung jedes Jahres. Gleichstände werden anhand der
  sortierten Betriebskennung deterministisch entschieden.
- Alle acht Bewegungswerte stimmen centgenau mit dem Original überein. Die
  Buchwerte und die Abschreibung jedes Betriebs müssen außerdem mit dessen
  tatsächlicher Journalaufteilung übereinstimmen. Abweichungen bleiben als
  sichtbarer Entwurf erhalten; Freigabe und Export werden gesperrt.
- Die steuerpflichtige Zusammenführung und die Gesamtvereinsbilanz enthalten
  weiterhin die Originalanlage einmal, nicht einen vervielfachten Gegenstand
  pro Betrieb. Abweichende Steuerwerte werden auf der bestehenden Originalanlage
  berechnet; die Nutzungsaufteilung ist keine eigenständige Steuerwertberechnung.
- Folgejahresübernahme bindet Erwerbsnachweis, vollständigen Abschreibungsplan,
  Konto, Sphäre, Maßstab und Gewichte.
  Eröffnungswerte entsprechen den Vorjahresschlusswerten. Eine stille Änderung
  der bisherigen Nutzung oder eine überholte Vorjahresfreigabe ist ausgeschlossen.
- Schema 4 ergänzt die bestehende Aufteilung additiv; ohne Anlagenanteile bleiben
  die bisherigen Schemata 1–3 erhalten. MySQL-Revisionskette, Rollen, Vereinsgrenzen,
  EÜR-Sperre, Versionsschutz und ausdrückliche Vorstandsfreigabe bleiben wirksam.
  Änderungen entziehen die bisherige Freigabe und damit die Bereitschaft zur
  Übermittlung einer darauf beruhenden Erklärung.
- Die Arbeitsunterlagen enthalten `bereiche/anlagenanteile.csv`; ein unabhängiger
  Python-Nachweis berechnet die Aufteilung für den E-Bilanz-Generator erneut.
  Beide Rechenwege sind im Laufzeit-Fingerprint enthalten.

## Bedienung

Unter **Vereinsbereiche & Betriebe → Gemeinsam genutzte Gegenstände** einen
Gegenstand öffnen, die belegten Nutzungsanteile eintragen und speichern.
Die Anzeige nennt beispielsweise „2 von 3 Nutzungsstunden“ sowie die jeweiligen
Eurobeträge. Bestehende Aufteilungen sind zunächst zusammengefasst; Bearbeiten
ist aufklappbar. Fehlerhafte Eingaben bleiben erhalten, ohne eine veraltete
Version automatisch zu übernehmen. Die endgültige Vorstandsfreigabe bleibt
im gemeinsamen letzten Prüfschritt der Bereichsaufteilung.

Heuristische Sichtprüfung der synthetischen Teilmaske: 1280 × 900 und 390 × 844,
17-Pixel-Grundschrift, Schaltflächen über 50 Pixel hoch, responsive Tabellen,
kein horizontaler Seitenüberlauf. Das ist weder ein echter Nutzertest mit einem
65-jährigen Menschen noch die nach dem vollständigen Release fällige Prüfung
aller Menüpunkte. Es wurden keine produktiven Formulare abgeschickt.

## Grenzen – weiterhin zu entwickeln

- Nutzung über verschiedene steuerliche Sphären hinweg; Vermögenszuordnung und
  Kostenverteilung dürfen nicht gleichgesetzt werden. Ein Nutzungsanteil alleine
  ist keine ausreichende Regel für eine steuerliche Vermögensüberführung.
- Wechsel der Nutzungsanteile nach einer bereits übernommenen Jahresfreigabe.
- Gemeinsam genutzte Anlagen mit Verkauf oder Voll-/Teilabgang. Sobald diese
  Aufteilung verwendet wird, benötigt das gesamte beteiligte Anlagenverzeichnis
  unterstützte Anlagen ohne Abgang: linear auf 01350/06300/06500, degressiv auf
  06300/06500 oder bestätigte GWG auf 06700. Andere Anlagenfälle bleiben gesperrt.
- Abweichende Wirtschaftsjahre, Korrekturabschlüsse, laufende rückzahlbare
  Verrechnungen und die vollständige Konten-/Steuersonderfallabdeckung bleiben
  separate Release-Voraussetzungen.

Fachlicher Bezug für belegte Nutzungsmaßstäbe und die Trennung von Kosten und
Vermögenszuordnung: [AEAO zu § 64, insbesondere Nr. 6, Stand 2025](https://ao.bundesfinanzministerium.de/ao/2025/Abgabenordnung/Zweiter-Teil/Dritter-Abschnitt/Paragraf-64/ae-64.html).
Das ersetzt keine vollständige Prüfung aller Rechtsänderungen und Spezialfälle.

## Erweiterung: GWG und degressive Originalpläne

Die Nutzungsteilung übernimmt nur die bereits bestätigte Abschreibungsart am
Originalgegenstand. Sie berechnet keine neuen Anschaffungskosten-Grenzen für
Teilanteile. Insbesondere wird ein zu teurer Gegenstand durch die Aufteilung
nicht zu mehreren GWG. Nettobetrag, Steueranteile, selbstständige Nutzbarkeit
und der bestätigte Verzicht auf den Sammelposten bleiben am gesamten Original
gebunden. Grundlage des bestehenden GWG-Modells: [§ 6 Abs. 2 EStG](https://www.gesetze-im-internet.de/estg/__6.html).

Degressive Pläne behalten Satz, Beginn, Nutzungsdauer und die ausdrücklich
bestätigte Wechselregel zur linearen Abschreibung. Es gelten unverändert die
vorhandenen geprüften Anschaffungsjahre 2025/2026; die Erweiterung schaltet keine
neuen Jahreskataloge frei. Grundlage des bestehenden Modells:
[§ 7 Abs. 2 und 3 EStG](https://www.gesetze-im-internet.de/estg/__7.html).

PHP und der unabhängige Python-Generator berechnen die Jahreswerte des ganzen
Originalgegenstands vor der Nutzungsaufteilung erneut. Auch in sich ausgeglichene,
aber vom Plan abweichende Werte werden abgelehnt. Für GWG wird ausdrücklich das
Aufwandskonto 62600 statt 62200 abgestimmt. Vollständig abgeschriebene Gegenstände
bleiben mit Anschaffungskosten und kumulierter Abschreibung im Verzeichnis;
im Folgejahr wird keine zweite Abschreibung erzeugt.

Die Teilmaske zeigt die festgelegte Abschreibung in Alltagssprache und bietet
keinen neuen Methodenwechsel an. Quellenjournal und Anlagenplan bleiben unverändert.
Abweichende Steuerpläne für GWG/degressive Gegenstände werden hierdurch nicht
neu freigeschaltet.

## Prüfungen der Methoden-Erweiterung

- 5.740 PHP-Rechenprüfungen bestanden, einschließlich GWG-Grenze am ganzen
  Gegenstand, Methoden-/Nachweisfehlern, Folgejahren, interner Mittelübertragung
  und centgenauer vollständiger Abschreibung. Reine Rechentests bis 2031 prüfen
  den Methodenswitch und Endwerte; sie schalten keine Erklärungsjahre nach 2026 frei.
- Sechs unabhängige Python-Testmethoden für die gemeinsame Nutzung, darunter
  neun Varianten je Exportprofil. Mit den sechs vorhandenen Regressionstestgruppen
  für Vereine, Gesamtverein, kombinierten Export, degressive AfA, Kapitalvortrag
  und interne Mittelübertragung: 117 bestandene Testmethoden.
- Native ERiC-Prüfung ohne Versand: 26 SGEB-, 26 kombinierte und 28
  Gesamtvereins-Fälle, alle Code 0. Je Profil vier neue Varianten (GWG/degressiv,
  jeweils Anschaffungsjahr und Folgejahr). Nachweise:
  `audit/ebilanz-shared-methods-{sgeb,combined,whole}-native-20260920.json`.
- 885 PHP-Dateien ohne Syntaxfehler. 92 Fingerprint-Assertions sowie 45
  Fingerprint-Dateien und 13 Python-Abhängigkeiten geprüft. Acht bestehende reine
  PHP-Regressionsprogramme zusätzlich bestanden (802 Assertions).
- Abschließender isolierter MySQL-Lauf mit sechs Testprogrammen bestanden:
  123 gemeinsame-Anlagen-Workflowprüfungen, 105 Methoden-Workflowprüfungen
  (einschließlich 62 vorhandener Übermittlungsprüfungen mit Offline-Simulator),
  5.740 Rechenprüfungen, 83 GWG-, 82 degressive und sechs Schemaprüfungen.
  Nachweis: `audit/ebilanz-mysql-host-20260918-232d025f368d4c9b.json`.
  Der Datumspräfix stammt vom vorhandenen Operator; der Lauf erfolgte am 20.09.
  Die vorherigen 150 Tabellen/1.393 Datensätze der sekundären Datenbank wurden
  vorher serverseitig verschlüsselt gesichert und nachher samt Definitionen und
  Triggern identisch wiederhergestellt. Keine Verbindung zur Produktivdatenbank;
  geschützte Produktivdateien unverändert, privater Testbereich entfernt.
- Neue Methodenbeschriftungen im tatsächlichen PHP/MySQL-Rendering geprüft.
  Keine zusätzliche visuelle Desktop-/Handy-Abnahme dieses Schritts und kein
  echter Nutzertest. Die frühere Sichtprüfung unten betrifft den linearen Stand.
- Vier vorgelagerte Diagnose-Läufe waren nicht grün. Der neue Testaufbau wurde
  korrigiert: fehlender Einzelbestand, Variablenüberschreibung beim Einbinden der
  Ansicht, zwingender neuer Entwurf nach geänderter Quellfreigabe und gesperrte
  Nullbuchung beim voll abgeschriebenen GWG. Keine dieser Schutzprüfungen wurde
  abgeschwächt; Ablehnungen werden ausdrücklich geprüft. Auch die Diagnose-Läufe
  endeten mit identischer Rücksicherung und unveränderten Produktivdateien.
- Quelleninventur einschließlich des neuen Workflowtests aktualisiert:
  2.258 Dateihashes und statische Abhängigkeiten geprüft.

Diese Erweiterung ist keine vollständige Vereinsbilanz-Abnahme. Keine echte
Steuerübermittlung, keine Umstellung von Kulturhus auf Bilanzierung und keine
vorgezogene Gesamtprüfung aller Menüpunkte.

## Technische Prüfungen der ursprünglichen linearen Erweiterung

- `balance-shared-assets.php`: 5.481 Assertions, überwiegend systematische
  Rundungs-/Monotonieprüfungen; nicht 5.478 verschiedene Buchhaltungsfälle.
- `ebilanz-shared-assets-test.py`: vier Testmethoden mit 15 positiven
  Profil-/Variantenkombinationen und negativen Typ-/Quellen-/Sphärenprüfungen.
- Unabhängige Python-Regressionen: Builder, SGEB, Gesamtverein, kombiniert,
  Kapitalvortrag, Mittelübertragung, Nutzungsaufteilung und Steueranlagen:
  186 Testmethoden bestanden.
- Native ERiC-Prüfung ohne Zertifikat und ohne Versand: 22 SGEB-,
  22 kombinierte und 24 Gesamtvereins-Fälle, jeweils Code 0. Fünf neue Varianten
  je Profil: gemeinsame Anlage, abweichende Steuerwerte, Folgejahr sowie
  das Zusammenspiel mit interner Mittelübertragung und dessen Folgejahr.
  Nachweise: `audit/ebilanz-shared-assets-{sgeb,combined,whole}-native-20260920.json`.
- Vollständiger isolierter MySQL-Lauf mit sechs Testprogrammen erfolgreich,
  einschließlich Mittelübertragung, Kapitalvortrag, Bereichsaufteilung und
  kombinierten Buch-/Steueranlagen. Nachweis:
  `audit/ebilanz-mysql-host-20260918-242882b574c48bac.json`.
  Die vorhandenen 150 Tabellen mit 1.393 Zeilen der sekundären Datenbank wurden
  zuvor serverseitig verschlüsselt gesichert und danach in Inhalt, Definitionen
  und Triggern identisch wiederhergestellt. Produktivdateien unverändert;
  privates Testverzeichnis anschließend entfernt.
- Nachlauf nach Controller-, Typ- und Beschriftungsänderungen erfolgreich:
  `audit/ebilanz-mysql-host-20260918-2c02eec27f741430.json`.
  123 Workflow-Assertions (darunter 90 bestehende Steueranlagenprüfungen),
  5.478 Rechenprüfungen und sechs Schemarücksicherungsprüfungen. Erneut
  verschlüsselte Sicherung, identische Wiederherstellung der vorhandenen
  sekundären Datenbank, unveränderte Produktivdateien und privater Testabbau.
- Die danach ergänzten drei Assertions und zwei Varianten zur Kombination
  mit Mittelübertragungen wurden lokal in PHP, unabhängig in Python und nativ
  mit ERiC geprüft; sie erforderten keine weitere Änderung des Anwendungscodes.
- PHP-Syntaxprüfung für 884 Quell-, Ansichts- und Testdateien bestanden.
  Quelleninventur: 2.117 Einträge einschließlich Installationsquellen;
  Laufzeitabhängigkeiten: 45 Fingerprint-Dateien und 13 Python-Module geprüft.
  Die bestehende Regression für den Fingerprint umfasst 92 bestandene Assertions.

Kein echter Versand, keine Bankaufträge, keine echten Mitgliedsdaten auf dem PC,
keine Veröffentlichung. Die Nachprüfung des gesamten Vereinsmanagers bleibt
an die noch ausstehende vollständige Bilanzabnahme und Veröffentlichung gebunden.
