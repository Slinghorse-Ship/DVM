# Datierte Gesamtvereinsausgabe

Stand: 28.09.2026. Generatorweiterentwicklung, **kein vollständiger Produktivrelease**.
Kulturhus bleibt EÜR. Keine produktiven Daten, echten Mails, Bankaufträge oder
Steuerübermittlungen verwendet.

## Umgesetzt

- Vollständige Bilanz, GuV und Betriebsvermögensvergleich eines **nicht
  steuerbegünstigten** Gesamtvereins für tatsächliche Abschlusszeiträume.
  Alle vier Bereiche bleiben erhalten. Kapitalzuführungen, Entnahmen und interne
  Kapitalumbuchungen werden zeilenbezogen geprüft und nicht zu Erträgen gemacht.
  Steuerliche Kapitalbewegungen ohne eigenes Datum verwenden das tatsächliche
  Periodenende, nicht pauschal den 31. Dezember.
- Für **steuerbegünstigte** Vereine: unveränderte Gesamt-Buchbilanz und Gesamt-GuV
  mit steuerlicher Nebenrechnung sämtlicher wirtschaftlicher Geschäftsbetriebe.
  Keine erfundene steuerliche Teilbilanz. Steuerkorrekturen verändern weder die
  Gesamt-Buchwerte noch steuerfreie Ergebnisbereiche. Die Nebenrechnung enthält
  bewertungsbedingte Unterschiede sowie einzeln belegte außerbilanzielle
  Hinzurechnungen und Kürzungen.
- Tatsächliche Abschluss- und Vorzeiträume, auch zwei Abschlüsse im selben
  Kalenderjahr. Vollständige ursprüngliche Anlagenpläne, Abgänge, Verkäufe,
  Nutzungsdauern und getrennte Buch-/Steuervorträge bleiben gebunden.
- Python rechnet Buchführung, Anlagen, Steueränderungen, Kapitalvergleich und
  Nebenrechnung unabhängig aus den ursprünglichen Buchungszeilen nach.
  Ein neu berechneter Hash allein legitimiert keine geänderten Summen.
- Der interne Buchwertteil und die Rechenbelege der Nebenrechnung lassen sich
  nicht als eigenständige steuerpflichtige Gesamtbilanz ausgeben. Ein Datum
  darf nicht durch bloße Jahresangabe oder ein unpassendes Profil ersetzt werden.
- Die amtliche Nebenrechnung verwendet für automatisch erzeugte Kontobezeichnungen
  einen Bindestrich statt U+00B7. ERiC hatte den Mittelpunkt in XHTML-Tabellen
  abgewiesen. Die ursprünglichen gespeicherten Bezeichnungen bleiben unverändert.
- Der Freigabe-Fingerprint umfasst jetzt auch alle neuen Zeitraum-Exportmodule,
  Anlagenberechnungen und den Nutzungswechsel-Rechenkern. Eine spätere Änderung
  entwertet frühere Prüfungen; der Importgraph wird zusätzlich automatisch geprüft.

## Prüfungen

Die neuen PHP-Grenztests prüfen 24 Gesamtvereins- und 35 Nebenrechnungsbedingungen.
Weitere erfolgreiche PHP-Gruppen: datierte Generatorgrenze (12), datierte Anlagen
(20), datierte Steuerwerte (41), bisheriger Gesamtverein (30), Kapitalzuordnung
(145), Kapitalvortrag (219), Freigabe-Fingerprint (168).

Native, ausschließlich lokale ERiC-Validierung mit Verarbeitungsflag 2:

| Ausgabe | Positive Fälle | Ergebnis |
| --- | ---: | --- |
| Datierter separater Betrieb einschließlich Anlagen | 14 | ERiC-Code 0 |
| Datierter nicht steuerbegünstigter Gesamtverein | 4 | ERiC-Code 0 |
| Datierter steuerbegünstigter Gesamtverein mit Nebenrechnung | 6 | ERiC-Code 0 |
| Bisherige Kalenderausgabe | 22 | ERiC-Code 0 |

Die absichtlich verfälschte Kalendersumme wurde weiterhin abgewiesen. Kein
Zertifikat und keine Übermittlungsfunktion wurden verwendet. Die neuen positiven
Nebenrechnungsfälle enthalten alle vier Bereiche, zwei steuerpflichtige Betriebe,
sieben Anlagen, abweichende Steuerwerte und einen kurzen Folgezeitraum.

Lokale, ignorierte Nachweisdateien:

- `audit/ebilanz-period-club-native-20260928.json`
- `audit/ebilanz-calendar-after-period-club-20260928.json`

Alle 24 Python-Regressionsgruppen des Generators bestanden im gemeinsamen
Nachlauf. Der Abhängigkeitsnachweis prüfte 83 Fingerprint-Dateien und 17 lokale
Python-Module. Alle neun geänderten/neuen PHP-Dateien sind syntaxfehlerfrei.

Auch der Webserver-Nachlauf in der dauerhaft freigegebenen synthetischen
Testdatenbank bestand: neun Testgruppen, keine PHP-Diagnostik. Darunter die neuen
Generatorgrenzen, bisherige Gesamt-/Nebenrechnungs-Abgabe, datierte Steuerprüfungen
einschließlich Kalenderübergang, Korrekturabgaben und Freigabe-Fingerprint.
Die Workflowtests verwenden den echten Generator, aber ausdrücklich einen
simulierten Transport, nicht das Finanzamt. Nachweis:
`audit/ebilanz-mysql-host-20260918-9668b0c7af308381.json`.
Keine produktive Datenbankverbindung; geschützte Produktivdateien blieben
hashidentisch. Der zufällig benannte private Testordner wurde entfernt. Die
synthetische Testdatenbank bleibt nutzbar; keine erneute Sicherungs-/Restore-Runde.

## Weiterhin verbindliche Release-Sperren

Diese Änderung allein verbindet datierte Erklärungen **noch nicht** mit dem
gespeicherten Abgabeauftrag, dessen Oberfläche, Korrekturidentität, Übermittlung
und Rückmeldungen. `BalanceTaxFiling::calendarSourceOnly` bleibt bestehen;
ein datierter Abschluss darf nicht durch den alten Kalenderweg gesendet werden.

Ebenfalls noch offen sind insbesondere:

- Übernahme eines ursprünglich kalendergebundenen Abschlusses in den datierten
  amtlichen Export einschließlich aller bisherigen Originalnachweise. Der bereits
  geprüfte Arbeitsunterlagen-Übergang ersetzt diese Exportprüfung nicht.
- Datierte kombinierte Gesamt-/Teilbilanzvariante mit gesondert bilanzierten
  Betrieben; sie ist nicht identisch mit der hier geprüften Nebenrechnungsvariante.
- Echte steuerliche Anlagen-Sphärenwechsel und die vollständige vereinbarte
  Konten-, Anlagen- und Steuersonderfallabdeckung.
- Durchgängige abschließende Webserver-/Abgabeprüfung des endgültigen Gesamtstands,
  Veröffentlichung und danach die beauftragte rollenbezogene Gesamt-UI-Prüfung.

Kein bestehender Testversand und kein einzelner erfolgreicher ERiC-Lauf wird als
Abnahme dieser noch fehlenden Integration gewertet. Produktionsschalter bleiben
unverändert; keine Veröffentlichung und kein Auslösen der Nach-Release-Automation.
