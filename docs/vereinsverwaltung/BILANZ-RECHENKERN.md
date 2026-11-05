# Bilanzrechnung – lokaler Pilot, 15.09.2026

Kulturhus bleibt bei EÜR; Lohnabrechnung ist für den aktuellen Einsatz nicht
beauftragt. Kein Produktiv-Deploy und keine Änderung der Live-Steuereinstellungen.

## Implementiert

- Getrenntes MySQL-Bilanzjournal (Migration 088), keine Umdeutung der EÜR.
- Cent-genaue doppelte Buchungen; jede Buchung und die Eröffnung müssen
  ausgeglichen sein. Fehlendes Kapital wird niemals als Differenz erfunden.
- Bilanz, GuV, Ergebnis je Vereinsbereich, Summen-/Saldennachweis.
- Forderungen, Verbindlichkeiten, private Vorleistungen, Sachanlagen,
  gebuchte Abschreibungen, Rückstellungen und Rechnungsabgrenzung sind getrennte
  Bestands-/Erfolgskonten. Zahlung einer bereits eingebuchten Rechnung
  beeinflusst nicht nochmals das Ergebnis.
- Storno als erhaltene Gegenbuchung; keine Bearbeitung/Löschung von Buchungen.
- Versionsprüfung, Mandantentrennung, Finanzberechtigung, CSRF im Controller.
- Festschreibung nur durch aktuellen Vorstand/Superuser nach ausdrücklichen
  Prüfungen; laufendes Jahr gesperrt. Hashgebundener Jahresstand; Vortrag des
  Ergebnisses auf ein benanntes Kapitalkonto, ohne erneuten Vorjahresertrag.
- Exakte Wiederherstellung der neuen MySQL-Tabellen im synthetischen Test.
- Jahresunterlagen enthalten bei Abschlussart Bilanz das Bilanzjournal.
  EÜR-Unterlagen bleiben unverändert. Zahlungsüberschuss wird für kein Jahr
  als Bilanzgewinn in die steuerliche Formularzuordnung übernommen.
- Lohnsteuer- und ELStAM-Karten werden bei ausdrücklich bestätigtem
  „keine Beschäftigten mit individuellem Lohnsteuerabzug“ ausgeblendet.
  Historische Formulare bleiben abrufbar; unbekannte Angaben werden nicht
  automatisch zu „Nein“.
- Quellgebundene Rechnungs-, Zahlungs- und Abschreibungsübernahme (Migration 092).
  Daten werden serverseitig gelesen und vor der Buchung erneut geprüft. Eindeutige
  Quellenbindungen verhindern dieselbe Übernahme mit neuer Vorgangskennung.
- Geprüfte Positionsbeträge und SKR42-Referenzen 2025/2026 werden übernommen;
  ohne Vorsteuerabzug bleibt der Bruttobetrag Aufwand/Anschaffung. Bei ausdrücklich
  geklärtem Abzug werden Nettobetrag und Vorsteuer getrennt gebucht, ebenso die
  Umsatzsteuer auf Ausgangsrechnungen. Sammel-/ungeklärte Sphären sind gesperrt.
- Private Vorleistungen benötigen ihr tatsächliches Zahlungsdatum. Eine spätere
  private Zahlung wird als Umbuchung zur Mitgliedsverbindlichkeit erfasst. Die
  Erstattung gleicht diese aus, ohne zusätzlichen Aufwand.
- Anschaffungen erfordern passende eigene Gegenstände und bestätigte Buchwertpläne.
  Die Summe der Buchwertgrundlagen muss genau dem Anschaffungsbetrag entsprechen.
  Doppelte Gegenstandszuordnung und doppelte Jahresabschreibung werden abgewiesen.
- Bankübernahme nur für vollständig zugeordnete Umsätze mit bereits gebuchten
  Rechnungen. Teilzahlungen und Zahlung eines Vorjahrespostens sind getestet.
  Der Jahressaldo wird mit den Geldkonten und deren Bewegungen verglichen.
- Serielle Journal-Schreibvorgänge je Verein, Versionsprüfung nach Zeilensperre,
  atomare Übernahme aller Teilbuchungen samt Quellenbindung und Audit.
- Jahresfestschreibung prüft fehlende, geänderte oder stornierte Quellen. Der
  festgeschriebene Stand enthält Quellen-Snapshots. Keine stille Reparatur.
- Steuerliche **Ergebnis-Arbeitsrechnung** je Sphäre aus dem Bilanzjournal plus
  ausdrücklich erfassten Hinzurechnungen/Abzügen. Ungeklärte Korrekturen bleiben
  offen. Keine Behauptung einer vollständigen steuerlichen Bilanzüberleitung.
- Zusätzlicher Konten-, Quellen- und Abschreibungsnachweis im Steuerpaket.
- Belegbezogene Oberfläche mit Betrag, privatem Zahler und Originalbeleg-Link.
  Datumsfelder gehören zum tatsächlichen Speicherformular; geänderte Daten
  können keine alte Vorschaufreigabe verwenden. Fachlicher Editor ist eingeklappt.

## Bewusste Freigabegrenze

Schreibzugriff zusätzlich nur bei `VEREIN_BALANCE_WORKSPACE=1` und
Vereinseinstellung `accounting_basis=balance`. Der Schalter ist standardmäßig
aus. Er ist **keine** fachliche Freigabe für den Produktivbetrieb. Während der
Erprobung nur synthetische lokale Daten verwenden. Kein Aufruf an die Bank
oder ELSTER durch den Bilanzrechner.

## Nachträgliche Belegkorrektur – lokaler Stand Schema 93

- Geprüfte Buchungsvorschau und Originalbuchungen werden bei der Erstübernahme
  aufbewahrt. Eine Korrektur hängt Gegenbuchungen und neue Buchungen an; keine
  vorhandene Journalzeile wird überschrieben. Erfassungszeit, Bearbeiter und
  Korrekturgrund bleiben im Verlauf. Storno des alten Standes erfolgt am alten
  Buchungsdatum im weiterhin offenen Jahr; die neue Buchung erhält das geprüfte
  Leistungs-/Zahlungsdatum. Das ist kein Wiederöffnen abgeschlossener Perioden.
- Vorschau umfasst die Rechnung sowie die bereits übernommenen zugehörigen
  Zahlungen und Abschreibungen. Auch inzwischen entfernte Zahlungszuordnungen
  werden über den früheren Quellennachweis gefunden. Geänderte Bankzuordnungen
  können separat über denselben geprüften Korrekturablauf übernommen werden.
- Vorsteuer-/Kosten-/Sphärenänderungen und bloße Belegbeschreibungen werden
  unterschieden durch ihre tatsächlichen Buchungszeilen, nicht durch Schätzungen.
  Eine Korrektur erzeugt keine Zahlung an die Bank und keine doppelte Ausgabe.
- Speicherung von Gegenbuchungen, Neubuchungen, aktuellem Quellenverweis,
  Historie und Audit erfolgt in einer MySQL-Transaktion. Parallel bestätigte
  identische Vorschauen führen nur zu einer Korrektur. Fehler bei einer abhängigen
  Buchung rollen den gesamten Vorgang zurück.
- Hashverkettete Fassungen werden zusammen mit Original- und Stornozeilen geprüft
  und im Quellenexport aufbewahrt. Das erkennt Bestandsabweichungen; es ist keine
  kryptografische Absicherung gegen einen Administrator, der Daten **und** sämtliche
  Hashes gezielt manipuliert, und keine pauschale GoBD-Zertifizierung.
- Grenzen: nur offene Kalenderjahre, maximal 100 betroffene Quellen und 1.000
  Fassungen je Quelle. Eine geänderte Erstattungsperson nach Zahlung, abweichende
  festgestellte Abschreibungsgrundlagen, stornierte Bankumsätze, Jahreswechsel und
  Altübernahmen ohne gespeicherte Vorschau werden nicht automatisch umgeschrieben.
  Veränderte tatsächliche Quellen bleiben bis zur Klärung als Abweichung sichtbar.
- Das Formular zeigt vorher/nachher und die betroffenen Belege. Grund und erneute
  Prüfung sind Pflicht; übernommene Journalzeilen führen zu „Belegänderung prüfen“
  statt zu einem losgelösten Einzelstorno. Frühere Fassungen bleiben einsehbar.

Grundlage für die Beibehaltung des Ursprungsinhalts:
[§ 146 Abs. 4 AO](https://www.gesetze-im-internet.de/ao_1977/__146.html)
(abgerufen 15.09.2026).

## Noch zu implementieren und zu prüfen

Aktualisierung 15.09.2026: Der erste begrenzte eigene XBRL-Generator und die
dokumentierte Steuerwertrechnung bestehen inzwischen lokale ERiC-Prüfungen;
siehe [EBILANZ-EIGENER-GENERATOR.md](EBILANZ-EIGENER-GENERATOR.md). Die folgenden
Punkte beziehen sich weiterhin auf die vollständige fachliche/produktive Abdeckung.

1. Über die oben beschriebenen offenen Standardfälle hinausgehende Korrekturen.
   Andere als belegte Jahresbeiträge und freie Geldspenden, ungeklärte Bankbewegungen, Rückzahlungen,
   Anzahlungen und jahresübergreifende private Vorleistungen benötigen noch
   eigene sichere Quellenadapter. Nicht still als Standardrechnung buchen.
2. Vollständige jahresabhängige Zuordnung der Konten zur XBRL-Taxonomie,
   Handels-/Steuerbilanz-Überleitung, amtlicher Anlagenspiegel einschließlich
   vollständigem Anlagenverzeichnis, eigene XBRL-Erzeugung und Versandablauf.
   Bereits getestet: native ERiC-Prüfung zweier amtlicher Beispiele (Körperschaft
   mit Geschäftsbetrieb, Anlagenspiegel) sowie Ablehnung zweier Fehlerbeispiele.
   Das ist kein Test einer aus Vereinsdaten erzeugten E-Bilanz. Interne
   Verrechnungs-/Personenkonten ohne geprüfte SKR42-Zuordnung bleiben unkodiert.
3. Fachliche Behandlung von besonderen Bewertungsfällen, Kapitalfehlbetrag,
   steuerlichen und handelsrechtlichen Bewertungsunterschieden, unterjährigen
   Eintritten/Wechsel der Gewinnermittlung und abweichenden Wirtschaftsjahren.
   Negative Aktiv-/Fremdkapitalsalden bleiben sichtbar und blockieren die
   Festschreibung bis zur geprüften Umbuchung; es wird nichts verrechnet.
4. Korrektur abgeschlossener Jahre mit berichtigten Abschlüssen und zugehörigen
   Erklärungen. Bisher nur Storno im offenen Jahr; kein stilles Wiederöffnen.
5. Vollständige HTTP-Ende-zu-Ende-Abnahme mit allen Korrektur- und Sonderfällen;
   die bisherige Browserabnahme prüft gerenderte Seiten mit synthetischen Daten.

`ready_for_tax_export` bleibt daher stets `false`. Ein festgeschriebenes
Journal bedeutet insbesondere noch keine gültige E-Bilanz.
Vollständigkeit/Bewertung müssen im Pilot explizit
bestätigt und mit Nachweisen dokumentiert werden. Bestehende Bilanzjahre
bleiben lesbar, auch wenn später wieder EÜR gewählt wird; keine Datenlöschung.

## Tests

```text
php vereinsverwaltung/tests/balance-calculation.php
php vereinsverwaltung/tests/balance-tax-reconciliation.php
# ausschließlich isolierter MySQL-Testcontainer:
php vereinsverwaltung/tests/balance-ledger.php
php vereinsverwaltung/tests/balance-sources.php
php vereinsverwaltung/tests/balance-corrections.php
php vereinsverwaltung/tests/balance-noninvoice-sources.php
php vereinsverwaltung/tests/balance-asset-schedule.php
# optionale synthetische Renderdateien unter .local:
node vereinsverwaltung/tests/balance-browser.cjs
```

Das Service-Testprogramm aktiviert den Pilotschalter nur für seinen eigenen
Prozess und verwendet zufällige MySQL-Testdatenbanken. Keine echten Vereins-
oder Bankdaten. Browserprüfungen sind statische Render-/Layouttests, keine
Produktions-HTTP-Abnahme.

Fachliche Prüfreferenzen (keine automatische Entscheidung über die konkrete
Bilanzierungspflicht eines Vereins): [§ 246 HGB](https://www.gesetze-im-internet.de/hgb/__246.html),
[§ 252 HGB](https://www.gesetze-im-internet.de/hgb/__252.html),
[§ 5b EStG](https://www.gesetze-im-internet.de/estg/__5b.html).

## Fortsetzung: Beiträge, Geldspenden und Anlagenverzeichnis

Entwicklungsstand 15.09.2026, weiterhin nur synthetische MySQL-Tests / Schema 93.

- Echte Jahresbeiträge werden aus der vorhandenen Jahresforderung übernommen:
  ursprünglicher Beitragssatz samt Version, Beitragsjahr, Zahler und abgedeckte
  Mitglieder. Die heutige Beitragsordnung ersetzt den historischen Satz nicht.
  Eine Familienforderung wird einmal gebucht. Vorab ausgestellte Forderungen
  erscheinen nur im Beitragsjahr. Ein ausdrücklich bestätigtes Entstehungsdatum
  ist erforderlich; der Jahresanfang wird nicht als Rechtsgrund erfunden.
- Bei eindeutiger, umsatzsteuerfreier Ein-Positions-Zuordnung wird das geprüfte
  Jahreskonto 40000 / ideeller Bereich verwendet. Widersprechende bestehende
  Kontierungen, fehlende Herkunftsangaben und Sonderbeiträge werden abgewiesen.
  Die Beitragsforderung benötigt keine fremde Lieferanten-PDF. Ein Zahlungseingang
  vermindert nur die Forderung, auch bei Teilzahlung und Vortrag ins Folgejahr.
- Freie Geldspenden werden über die tatsächlich zugeordneten Zahlungseingänge
  erfasst, nicht über einen unbezahlten Spenden-Beleg als Forderung. Einmalige
  Sachfrage: alle zugeordneten Spenden ohne besondere Zweckbindung/Gegenleistung?
  Nur bei ausdrücklichem Ja: geprüftes Jahreskonto 40400 / ideeller Bereich.
  Keine erfundene Umsatzsteuer, keine doppelte Erfassung eines Restbetrags.
  Zweckgebundene Spenden, verbindliche Spendenzusagen, Sach-/Aufwandsspenden,
  Rückzahlungen und getrennte Bescheinigungs-Unterkonten brauchen weiterhin
  gesonderte Adapter. Die neue Übernahme stellt keine Zuwendungsbestätigung aus.
- Änderungen einer Geldspende berücksichtigen zusammenhängende Teilzahlungen
  anhand alter und aktueller Zuordnungen. Vorschau, Gegenbuchungen, neue Fassungen
  und Hashverlauf sind atomar; eine misslungene Folgebuchung rollt alles zurück.
  Bei anderen betroffenen Jahren wird nicht automatisch korrigiert.
- Sach- und Aufwandsspenden aus ihren eigenen Tabellen blockieren jetzt eine
  unzutreffende Vollständigkeitsbestätigung; sie werden nicht als Bargeld erfasst.
- Das neue Anlagenverzeichnis enthält beleggebundene Anschaffungen, ursprüngliche
  Anschaffungskosten, Zugänge, kumulierte und laufende Abschreibung sowie
  Anfangs-/Endbuchwerte. Anschaffung im laufenden Jahr beginnt mit null Euro
  Jahresanfangsbestand, nicht mit dem Kaufpreis. Vortrag erhält Bruttokosten
  und kumulierte Abschreibung. Die Summen werden je Anlagenkonto abgestimmt.
- Fehlende Jahresabschreibung blockiert das Festschreiben auch dann, wenn noch
  gar kein AfA-Jahresdatensatz angelegt wurde. Nicht beleggebundene Anlagenbewegungen,
  fehlende Anfangsnachweise, Abgänge und nicht unterstützte Umbuchungen bleiben
  Prüfprobleme. Sie werden nicht mit einem automatischen Ausgleich verdeckt.
- Der beim Abschluss geprüfte Anlagenstand wird im gehashten Abschluss-Snapshot
  aufbewahrt. Spätere Inventarumbenennungen ändern ihn nicht. Ältere Abschlüsse
  ohne diese Aufstellung werden als solche kenntlich gemacht, nicht rückwirkend
  ergänzt. JSON, CSV und die lesbare Zusammenfassung gehören zum Steuer-Arbeitspaket.

Grenze: unterstützt sind eigene rechnungsgebundene Anlagen mit dem vorhandenen
linearen Monatsplan. Dies ist noch **kein vollständiger amtlicher Anlagenspiegel**
mit Abgängen, Umbuchungen, Sonderabschreibungen und Taxonomie-Zuordnung.
`ready_for_xbrl` und `ready_for_tax_export` bleiben `false`.

Abnahme: 708 Prüfungen in 15 Testgruppen; darin 65 neue Nicht-Rechnungs-Prüfungen,
23 Anlagenrechnungs-Prüfungen und 13 zusätzliche Quellen-/AfA-Integrationstests.
Außerdem Syntaxprüfung aller 13 geänderten/neuen PHP-Dateien dieses Schrittes.
Keine echte Übermittlung und keine Produktionsdaten; HTTP-Ende-zu-Ende-Abnahme
ist weiterhin offen. Bestehende EÜR-, Freigabe-, Steuer- und Anlagenbelegtests liefen mit.

Fortsetzung: Die aufbewahrten Anlagenwerte werden nun in `BalanceTaxValues`
mitgehasht und vom eigenen Generator in die amtliche Bruttotabelle übersetzt.
Die interne Kennzeichnung `ready_for_xbrl=false` bleibt absichtlich unverändert:
erst die zusätzliche Profil-, Steuerwert-, Konten- und Anlagenprüfung im Generator
entscheidet über die Erstellung eines **Testentwurfs**, nicht über eine Abgabe.
Unterstützt sind Ausstattung, Büroeinrichtung und erworbene Software mit gleichem
linearem Handels-/Steuerplan. Details und unverändert offene Sonderfälle stehen
in EBILANZ-EIGENER-GENERATOR.md. Produktiv bleibt die Bilanzfunktion unverändert gesperrt.
