# Datierte Bereichsbilanzen nach Anlagenwechseln – 28.09.2026

Nachfolgende Erweiterung: [Steuerüberleitung und XML](ZEITRAUM-SPHAEREN-STEUERXML-20260928.md).
Die unten beschriebene damalige Exportgrenze ist für Original-Gesamt-B/GuV mit
Nebenrechnung inzwischen erweitert; übrige Profile und Sonderfälle bleiben offen.

## Implementierter Zusammenhang

Der in der Anlagenquelle nachgewiesene Wechsel ist jetzt mit der gespeicherten
Bereichsaufteilung verbunden. Das ist eine **interne Buchwertüberleitung**, noch
keine amtliche Steuerwert-/Kapitalüberleitung und kein allgemeiner Bilanzrelease.
Kulturhus bleibt EÜR; die vorhandenen Produktions- und Übermittlungssperren bleiben.

- Der Originalplan bleibt unverändert. Der aktuelle Bereich wird aus Anfang,
  tatsächlichen Bewegungen und Schlusszustand abgeleitet – nicht durch Ändern der
  ursprünglichen Sphäre am Gegenstand.
- Originale Anschaffungskosten, kumulierte Abschreibung und Buchwert wechseln
  gemeinsam. Der Nettobuchwert hat eine gleich große interne Kapitalgegenbuchung.
  Die Bewegungen heben sich im Gesamtverein vollständig auf, einschließlich ihrer
  Soll-/Haben-Umsätze. Es gibt weder eine zweite Anschaffung noch eine fingierte
  Zahlung, zusätzliche Einnahme oder Änderung des Originaljournals.
- Nach dem ganzen steuerlichen Bereichswechsel darf die tatsächliche Nutzung auf
  mehrere Betriebe **innerhalb des neuen Bereichs** verteilt werden. Weitere
  monatliche Nutzungsänderungen sind möglich. Die Summe ihrer AfA muss mit der
  bereits gebuchten AfA der jeweiligen Sphäre übereinstimmen.
- Ein eindeutiger Zielbetrieb wird beim Vorbereiten übernommen. Zwischen mehreren
  Betrieben wird nicht geraten. Fehlende Ziele bleiben bearbeitbare Entwürfe und
  sperren die Freigabe. Eine unzugeordnete Anschaffungsrechnung wird nicht allein
  aufgrund des Anlagenwechsels einem Betrieb zugeschlagen.
- Berichtigung der Bereichsaufteilung verlangt erneute Freigabe. Alte Nachweise
  bleiben erhalten. Im nächsten, gegebenenfalls kurzen Zeitraum werden gerundete
  Anschaffungskosten, kumulierte AfA, Buchwerte und Bereichskapital genau übernommen;
  die vergangenen Wechsel werden nicht erneut als Bewegungen gebucht.
- Der Ergebnisabgleich rekonstruiert die Bewegungen aus den ursprünglichen
  Bewertungsnachweisen. Manipulierte oder fehlende Bewegungen werden auch dann
  abgewiesen, wenn ein Aufrufer geänderte Ergebnisse neu gehasht hat.

## Oberfläche und Arbeitsunterlagen

Die Bereichsseite zeigt die bereits bestätigten Wechsel optional mit Datum,
Zielbereich und Buchwert. Bei mehreren Betrieben wird nur die Nutzung verteilt;
die fachlich bestätigte Steuerbewertung wird nicht erneut eingegeben. Die Eingabe
ist serverseitig an die tatsächlich geltende Bereichsfolge gebunden. Leserechte,
Mandantenbindung, Versionsprüfung und bestehende Formularschutzmechanismen bleiben.

Zusätzlich zu Originaljournal, vollständigem Prüfverlauf und Anlagenverzeichnis:

- `bereichsbilanzen.csv`: unveränderte Konten mit Anfang, Soll, Haben und Ende je
  Bereich, einschließlich der intern abgestimmten Kapitalbeträge.
- `anlagen-umbuchungen.csv`: datierte Brutto-/AfA-/Buchwertbewegungen je Betrieb
  und die zugehörige interne Kapitalzuführung/-minderung.
- `anlagennutzung.csv` und `.json`: genaue Nutzungsanteile und Originalanlagen.
- `anlagen-bereichswechsel.csv` und `anlagen-bereichswerte.csv`: die gesonderten
  steuerlichen Werte aus der Anlagenquelle, nicht mit Buchwerten vermischt.

Alle diese Dateien bleiben Arbeitsunterlagen (`tax_export_ready=false`).

## Noch nicht durch diesen Schritt erledigt

1. Amtliche Steuerbilanz-/Kapitalüberleitung und Brutto-Anlagenspiegel der echten
   Wechsel einschließlich unabhängiger Rekonstruktion im XML-Generator. Die
   bisherigen Sperren in den Steueradaptern und im Generator bleiben aktiv.
2. Vorher abweichende Steuerpläne, andere AfA-Methoden, gleichzeitige anteilige
   Nutzung in unterschiedlichen Sphären, taggenaue Wechsel und Abgänge zusammen
   mit Wechseln. Geteilte Nutzung mehrerer Betriebe derselben Sphäre ist davon
   zu unterscheiden und im neuen Bereichsweg berücksichtigt.
3. Vollständige Konten-/Steuersonderfälle, noch fehlende datierte Betriebsprofile,
   Übermittlungs- und Gesamtabnahme sowie Veröffentlichung.

## Prüfungen

Die neuen Tests unterscheiden reine Arithmetik und den tatsächlich gespeicherten
MySQL-Ablauf. Der erste Lauf fand eine zu weitgehende Testannahme: Die
Anschaffungsrechnung war noch keinem Bereich zugeordnet. Der Test ergänzt jetzt
diese ausdrückliche Zuordnung, statt die Schutzprüfung im Programm zu entfernen.
Nachweis des ersten, **nicht erfolgreichen** Gesamtlaufs:
`audit/ebilanz-mysql-host-20260918-ecd309525d024209.json`.

Der gezielte Wiederholungslauf bestand:

- 1.074 zusätzliche Rechenprüfungen, dazu die bestehenden 106 Prüfungen der
  steuerlichen Wechselrechnung. Auch kleine Centbeträge, historische
  Originalpläne, fehlende Ziele, neu gehashte falsche Werte und Folgevorträge.
- 39 neue gespeicherte Bereichsprüfungen, dazu 44 Prüfungen der Anlagenquelle und
  die darin enthaltenen 43 Rechnungs-/Zahlungsprüfungen. Freigabe, Berichtigung,
  verteilte Nutzung nach Rückkehr, Arbeitsunterlagen, Oberflächen-Rendering,
  Rechte und exakter kurzer Folgezeitraum wurden durchlaufen.
- 182 Prüfungen des Laufzeit-Fingerprints. Neue Berechnungsquellen können nicht
  geändert oder entfernt werden, ohne vorbereitete Übermittlungsstände ungültig
  zu machen.

Nachweis: `audit/ebilanz-mysql-host-20260918-326c04884c2dee68.json`, Quellpaket
`64b2425ed8228ad73a6a49c1e8df069bccfb27c52fc02506abe9090fffc26cd4`.
Die Abschlussprüfung nach der letzten kleinen Entwurfs-Korrektur wird separat
unten protokolliert. Der vorangehende breite Lauf bestand außerdem die bestehenden
Bereichs-, Nutzungswechsel- und Gesamtvereins-Abgabegruppen; sein insgesamt
fehlgeschlagener Status bleibt wegen des beschriebenen Testfehlers erhalten.

Alle 27 vorhandenen Python-E-Bilanz-Testprogramme sind erfolgreich. Die 550
PHP-Laufzeit-/Ansichts-/Migrationsdateien bestehen die Syntaxprüfung; die zwei
Node-Testprogramme bestehen 80 Feldanzeige- und 16 Vorschauprüfungen. Diese
automatisierten Prüfungen sind kein beobachteter Nutzertest auf Desktop/Handy.

Produktive Datenbank nicht verbunden, produktive Einstiegdateien unverändert,
keine öffentliche Testadresse, temporäre Serverdateien entfernt. Ausschließlich
die dauerhaft freigegebene synthetische Datenbank verwendet; keine zusätzliche
Sicherung, keine echte Mail, Banktransaktion oder ELSTER-Übermittlung. Die bereits
bestehenden Workflowtests verwenden simulierte Transportantworten.

## Abschließender Lauf

Alle **sieben** Testgruppen auf dem Webserver bestanden am abschließenden
Quellstand: neue Sphärenaufteilung und gespeicherte Bereichsabläufe, bisherige
gemeinsame Anlagennutzung, reine Nutzungsrechnung, gespeicherte Nutzungsänderungen
einschließlich steuerlicher Nebenrechnung, datierter Gesamtvereins-Abgabeablauf
und Laufzeit-Fingerprint. Die letzte Korrektur stellt sicher, dass ein noch nicht
zugeordneter **anderer** Gegenstand nicht die automatische Aufwandsaufteilung der
gerade bearbeiteten, vollständig zugeordneten Anlage unterdrückt.

Nachweis: `audit/ebilanz-mysql-host-20260918-0f71c58bcb4dd782.json`.
Quellpaket-SHA256:
`d31bcc8d278e89c99c69e3eb6c33c1f23f4d6f0f861d867d2ba829f73525c5ef`.
`passed=true`, `production_database_connected=false`, unveränderte geprüfte
Produktivdateien, entfernte temporäre Testdateien, `backup_created=false`.

Kein echter ERiC-Testversand für Sphärenwechsel und keine Gesamt-Releasefreigabe.
Die oben aufgeführten fachlichen und technischen Grenzen bleiben ausdrücklich
offen. Die nachgelagerte vollständige Manager-Bedienprüfung wird deshalb nicht
vorzeitig ausgelöst.
