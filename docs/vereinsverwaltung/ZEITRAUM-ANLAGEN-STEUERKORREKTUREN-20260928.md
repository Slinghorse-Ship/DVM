# Anlagenbezogene Steuerkorrekturen nach Sphärenwechsel – 28.09.2026

## Geschlossene Lücke

Die bisher kontenweite Sperre für außerbilanzielle Abschreibungskorrekturen nach
einem belegten Sphärenwechsel ist durch eine geprüfte Auswahl **pro Gegenstand**
ergänzt. Das betrifft den datierten Gesamtverein mit steuerlicher Nebenrechnung.
Es ändert weder die unterstützten Abschreibungsmethoden noch die übrigen
Exportprofile und stellt noch keinen vollständigen Bilanzrelease dar.

Die Grundlage bleibt die getrennte [Steuer-/XML-Überleitung](ZEITRAUM-SPHAEREN-STEUERXML-20260928.md).
Die bereits fachlich geprüfte steuerliche Abschreibung wird hier als Quelle
einer ausdrücklich begründeten Nichtabziehbarkeit verwendet. Das Programm
entscheidet nicht selbst, ob und nach welcher Vorschrift ein Betrag abzugsfähig
ist. Die bestehende Bestätigung der steuerlichen Grundlage bleibt erforderlich.

## Berechnung und Begrenzungen

- Die Auswahl liefert die tatsächliche steuerliche Abschreibung des
  steuerpflichtigen Bereichs, nicht die ursprüngliche Buch-AfA und nicht die
  Abschreibung anderer Vereinsbereiche.
- Gegenstand, Originalplan/-nachweis, Steuer-Rechenstand und tatsächlicher
  Abschlusszeitraum sind an den ausgewählten Betrag gebunden. Eine Quelle aus
  dem Vorzeitraum oder aus einem anderen Rechenstand ist kein gültiger Ersatz.
- Auch andere Anlagen auf demselben Abschreibungskonto sind einzeln auswählbar.
  Deren gegebenenfalls abweichende steuerliche Nutzungsdauer wird berücksichtigt.
  Mehrere Buchhaltungskonten mit demselben SKR-Code werden nicht erraten: Die
  zusätzliche Quelle verweist auf die konkrete Anlage, nicht auf ein erfundenes
  Journal-Konto.
- Ganze Beträge und mehrere Teilkorrekturen sind möglich, zusammen höchstens bis
  zur tatsächlichen steuerlichen Abschreibung der Anlage. Nullbeträge, negative
  Hinzurechnungen, eine Verwendung als steuerfreie Einnahme und doppelte
  Ausschöpfung werden abgewiesen.
- Die ursprünglichen AfA-Zeilen betroffener Konten dürfen daneben nicht nochmals
  korrigiert werden. Damit wird derselbe Aufwand nicht über zwei Quellen erhöht.
- Die Korrektur verändert ausschließlich das außerbilanzielle Steuerergebnis.
  Originaljournal, Buchwerte, Steuer-Anlagenwerte, interne Kapitalbewegungen und
  ursprüngliche XML-Dokumente bleiben erhalten. Im Folgezeitraum wird die
  Hinzurechnung nicht nochmals gebucht und nicht in den Anlagenwert übernommen.
- Historische Rechenstände ohne diese neue Quelle behalten ihre ursprüngliche
  Struktur und Prüfsumme. Zusätzliche Einzelquellen werden erst beim ausdrücklichen
  Verwenden einer solchen Korrektur in den neuen Stand aufgenommen.

Die Python-Ausgabe rekonstruiert die Einzelquellen aus den zuvor unabhängig
nachgerechneten Original- und Steuerplänen erneut. Auch eine neue Prüfsumme über
manipulierte Beträge, Namen, Anlagenkennungen oder Steuerwertnachweise genügt nicht.
In der amtlichen Nebenrechnungs-Fußnote erscheinen Gegenstandsname und korrigierter
Betrag, keine internen Quellenkennungen. Die Einordnung als Original-Gesamt-B/GuV
mit gesonderter Ergebnisermittlung folgt weiterhin der
[amtlichen E-Bilanz-FAQ, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).

## Bedienung

Unter „Für die Buchhaltung: Korrekturen erfassen“ heißt die Auswahl jetzt
„Beleg oder Gegenstand“. Bei Anlagen steht der Name mit „Abschreibung
(steuerlich)“ und dem verfügbaren Betrag. Nach einer Teilkorrektur wird der noch
verfügbare Rest angezeigt; vollständig ausgeschöpfte Quellen sind nicht mehr
angeboten. Nicht unterstützte konkurrierende Nutzungsdauer-Änderungen bereits
wechselnder Anlagen werden nicht angeboten.

Eine neue Korrektur ist zunächst ein Entwurf. Vorherige Freigaben berechtigen
nicht zum Senden des neuen Standes. Auch der Folgeabschluss verlangt eine erneute
Prüfung seines Vortragsbezugs. Das Aufheben einer Korrektur erzeugt einen neuen
Prüfstand; frühere Freigaben und vorbereitete XML-Dateien werden nicht gelöscht.

## Weiter offen

Andere Anlagenmethoden und Konten-/Bewertungssonderfälle, gemischte/taggenaue
Sphärenwechsel, Wechsel zusammen mit Abgängen, weitere datierte Betriebsprofile,
vollständige Kontenabdeckung sowie Übermittlungs-/Gesamtabnahme und Veröffentlichung.
Diese Erweiterung automatisiert keine rechtliche Entscheidung über Abzugsverbote.
Kulturhus bleibt EÜR; keine Freischaltung in Produktion.

## Verifikation

Reine PHP-Prüfungen decken zulässige Voll-/Teilbeträge, Mischanlagen, Ist-Steuerwerte,
unveränderte Buchführung, bisherige Nachweise, Folgezeiträume und manipulierte
Quellen ab. Die gespeicherte MySQL-Prüfung umfasst Anzeige, Berechtigungen,
Rollback abgewiesener Änderungen, neue Vorstandsfreigabe, unveränderte Altstände,
erstmalige und nachfolgende XML-Erzeugung sowie das Aufheben einer Korrektur.

Schreibende Prüfungen verwenden ausschließlich die dauerhaft freigegebene
synthetische MySQL-Datenbank. Kein öffentlicher Testendpunkt, keine Produktivdaten,
keine zusätzliche Sicherung und keine echte Mail, Bank- oder Steuerübermittlung.
Native ERiC-Prüfungen verwenden ausschließlich Validierungsflag 2 ohne Zertifikat.
Dies ist weder eine echte Übermittlungsabnahme noch der nachgelagerte vollständige
Desktop-/Handy-/Rollen-Nutzertest.

### Abschließender Prüflauf

- Alle sechs Gruppen auf dem Webserver bestanden ohne PHP-Diagnose: 424 neue
  reine Einzelquellprüfungen, 37 neue gespeicherte Prüfungen (zuzüglich der
  bisherigen Sphären-/Bereichs-/Quellkette), 45 bestehende datierte Steuerprüfungen,
  58 Kalender-Steuerprüfungen, 32 Gesamtvereins- plus 61 Nebenrechnungsprüfungen
  und 188 Laufzeit-Fingerprint-Prüfungen.
- Alle 28 Python-E-Bilanz-Testprogramme erneut bestanden; der Sphärentest enthält
  jetzt elf Testmethoden mit zusätzlichen Erst-/Folge-, Mischanlagen- und
  gezielt manipulierten Quellenfällen. 554 PHP-Laufzeit-/Ansichts-/Migrationsdateien
  bestehen die Syntaxprüfung.
- **22 neue native ERiC-Validierungen mit Code 0:** neun Anlagen-Wechselfälle mit
  gleichzeitiger gewöhnlicher Steuer-AfA, jeweils erster und folgender Zeitraum;
  zwei Fälle mit ausschließlich vorperiodischer Korrektur sowie die zwei
  unveränderten XML-Dateien aus dem gespeicherten MySQL-Ablauf. Kein Versand.
- Die produktive Datenbank wurde nicht verbunden; geschützte Produktivdateien
  sind unverändert und die temporären Serverdateien entfernt. Die synthetische
  Datenbank wurde wie vereinbart ohne zusätzliche Sicherung weiterverwendet.

Webserver-Nachweis:
`audit/ebilanz-mysql-host-20260918-1c435a7fc15c715a.json`.
Quellpaket-SHA256:
`0cd7b9969d45372759cfff8d52aa0703c1c207643472b04c6135bdf7f1395b05`.
Native XML-Nachweise: `audit/ebilanz-asset-expense-native-20260928.json`.
Diese lokalen Betriebsnachweise enthalten keine echte Abgabe; sie werden nicht
als vollständige Konten-/Sonderfallabnahme oder allgemeine Releasefreigabe gewertet.
