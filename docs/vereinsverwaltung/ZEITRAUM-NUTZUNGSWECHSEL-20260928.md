# Datierte Änderungen der Anlagennutzung

Stand: 28.09.2026. Entwicklung, **kein allgemeiner Bilanzrelease**.
Kulturhus bleibt EÜR. Produktion, Bank und ELSTER wurden nicht geändert.

## Eingabe und Wirkung

Unter „Vereinsbereiche prüfen“ steht bei einem zugeordneten Gegenstand
„Nutzung ab einem Datum ändern“. Eingetragen werden Monatsbeginn, neue Anteile
im bisherigen Nutzungsmaßstab und ein Nachweis. Es dürfen mehrere Änderungen
im Abschlusszeitraum erfasst werden. Hinzukommende oder ausscheidende Bereiche
sind möglich, sofern die steuerliche Sphäre dieselbe bleibt.

Die ursprünglichen Anfangsbestände bleiben bestehen. Anschaffungskosten,
kumulierte Abschreibung und Restbuchwert des Nutzungswechsels werden separat
ausgewiesen. Die gegenläufigen Werte heben sich innerhalb des Gesamtvereins auf.
Das ist weder ein neuer Gegenstand noch ein weiterer Aufwand oder Erlös.

Die Aufteilung der eindeutig verknüpften Original-Abschreibungsbuchungen und
Restbuchwertabgänge wird beim Nutzungswechsel automatisch angepasst. Hierbei
ändert sich ausschließlich der Bereichsnachweis, nicht das Ursprungsjournal.
Eine uneindeutige oder nicht passende Originalzeile wird nicht erraten.
Verkaufserlöse und zugehörige Zahlungszuordnungen bleiben gesondert abzustimmen.

Ein Nutzungswechsel benötigt eine neue ausdrückliche Vorstandsbestätigung.
Eine spätere Berichtigung hängt einen Prüfstand an; sie löscht keine alte
Freigabe. Abhängige Bereichs- und Steuerfreigaben verlieren ihre Aktualität.
Der Folgezeitraum übernimmt die **letzten** Nutzungsanteile und tatsächlichen
Bereichs-Endwerte. Der Nutzungswechsel selbst wird nicht nochmals gebucht.

Arbeitsunterlagen enthalten Datums-/Anteilsnachweise in `anlagennutzung.json`
und separate Umbuchungsspalten für Kosten, Abschreibung und Buchwert in
`anlagennutzung.csv`. Ein Arbeitsunterlagenexport ist kein amtlicher Export.

## Rechen- und Nachweisvertrag

- Unveränderter Originalplan und belegter unmittelbar vorheriger Anlagenstand.
- Kumulative Cent-Aufteilung statt unabhängig gerundeter Monatsanteile.
- Die zeitlichen Abschnitte werden aus dem **ursprünglichen Wirtschaftsjahr**
  abgeleitet. Ein Nutzungswechsel startet insbesondere keine neue degressive
  Abschreibungsperiode und keine neue GWG-Wahl.
- Lineare, GWG- und degressive Originalpläne sowie Vollabgang, Teilabgang,
  Verkauf und Software-Lizenzende behalten ihren eigenen Originalnachweis.
- Jeder Kosten-/AfA-/Buchwert-Umbuchungsbetrag summiert sich im Verein zu null.
  Bereichsbilanzen erhalten nur den internen Anlagen-/Kapitalgegenposten.
  Original-Soll-/Haben-Umsätze werden nach Eliminierung vollständig abgestimmt.
- Ergebnisabgleich baut Anlagenbewegungen erneut aus Originalplan, Datum,
  Anteilen und Vorzeitraum auf. Ein neu gehashter erfundener Gesamtwert genügt
  nicht. Das gleiche gilt für den Bereichsvortrag.
- Schreibrecht, Vereinsbindung, Versionsprüfung, Produktionssperre und
  Vorstandskontrolle bleiben bestehen. EÜR erhält keine Schreibaktion.
- Historien werden beim Lesen weiterhin vollständig validiert. Inhaltlich
  identische Original-/Vortragsarrays werden lediglich innerhalb dieses einen
  Lesevorgangs gemeinsam im Speicher gehalten. Keine dauerhafte Zwischenspeicherung
  von Berechtigungen oder Freigaben, keine Kürzung gespeicherter Historien.

## Grenzen

Dies ist die **analytische Nutzung innerhalb derselben steuerlichen Sphäre**.
Es sind noch keine steuerlichen Sphärenwechsel oder taggenauen Nutzungswechsel
innerhalb eines Monats. Nicht unterstützte Fälle bleiben gesperrt; sie werden
nicht auf den Monatsbeginn oder einen anderen steuerlichen Bereich umgedeutet.

Eine Änderung steuerlicher Verhältnisse kann zusätzliche Bewertung und
Umsatzsteuerprüfung erfordern. Beispielsweise unterscheidet
[§ 13 KStG](https://www.gesetze-im-internet.de/kstg_1977/__13.html) beim Beginn
oder Erlöschen einer Steuerbefreiung verschiedene Wertansätze und erfasst auch
Teilbereiche. Die hier implementierte interne Nutzungsverteilung ersetzt diese
steuerliche Beurteilung nicht und bestätigt keine unveränderte Vorsteuerberechtigung.

Weiter offen für den allgemeinen Release: tatsächliche steuerliche
Sphärenwechsel, vollständige Konten-/Anlagen-/Steuersonderfälle, amtliche datierte
Ausgabe und Übermittlungsrückmeldungen sowie Gesamtabnahme und Veröffentlichung.
Die anschließende Gesamtprüfung des Vereinsmanagers wird noch nicht ausgelöst.

## Prüfstand

Die Rechenprüfungen verwenden ausschließlich synthetische Daten. Service-,
Rollen-, Historien- und Vortragsprüfungen laufen auf der freigegebenen
isolierten MySQL-Testdatenbank. Keine echten Mails, Bankaufträge oder Steuerabgaben.
Abschließende Nachweise vom 28.09.2026:

- Acht bestandene Gruppen unter PHP 8.3 / MySQL 8.4 im Hostbericht
  `audit/ebilanz-mysql-host-20260918-9b0079747545a3c4.json`:
  Datumsrechnung (1.266), gespeicherter Nutzungswechsel (35 plus wiederverwendete
  Basisprüfungen), Kalender-Anlagenbereiche, Zeitraum-Bereiche,
  Vereinsergebnis (2.705), steuerliche Überleitung, Zeitraum-Steuerfreigaben
  und Schema-Wiederherstellung. Paket-SHA-256:
  `e5d7c85902fda2d5b6cddaf0a033040309adb3b7f83754cb5dfe6a61e8dfc096`.
- Nach der abschließenden Beschriftungs-/Formularkorrektur erneuter Hostlauf
  `audit/ebilanz-mysql-host-20260918-7657be7a086e1fc5.json`:
  1.266 Rechenprüfungen und 37 gespeicherte Nutzungswechselprüfungen sowie
  wiederverwendete 96 Bereichs-, 33 Abschreibungs- und 43 Quellbelegprüfungen.
  Beide Gruppen bestanden, keine PHP-Diagnose. Paket-SHA-256:
  `86553c7fdfcf905681d7e4e52c9848aad82958b29d5d44e21bf379eddcc3d2a3`.
- Spitzenverbrauch des gespeicherten Gesamtablaufs: 65.011.712 Bytes (62 MiB),
  bei unverändertem PHP-Limit von 128 MiB. Die zuvor gefundenen Fehler
  (fehlende Perioden-ID in einer Closure und mehrfach dekodierte Historien)
  sind korrigiert; fehlgeschlagene frühere Berichte bleiben als solche erhalten.
- 1.195 PHP-Dateien ohne Syntaxfehler; abschließend geänderte Views nochmals
  einzeln geprüft. 20 Python-E-Bilanz-Testgruppen und 16 JavaScript-Prüfungen
  der Quellenansicht bestanden. Bestehende gemeinsame Anlagen-/Teilabgangs-
  und Abgangsrechentests bestanden ebenfalls.
- Tatsächlich durch PHP gerenderte synthetische Eingabe in 390 × 844 und
  1.280 × 900 geprüft: Datumsfeld, benannte Anteile, Nachweis und Speichern
  lesbar, keine horizontale Überbreite. Alte Nutzung hat ein „bis“-Datum;
  nach einer Datumsänderung wird keine irreführende pauschale Ersatzzuordnung
  mehr angeboten. Das war eine agentengestützte Sichtprüfung, kein Nutzertest
  mit einer älteren Person und keine Gesamtprüfung aller Menüpunkte.

Die Browservorschau hat nichts abgesendet. Speicherung, Rollen, Versionskonflikte,
Berichtigung, unveränderte Originalbuchungen, Folgemonats-/Folgejahreswerte und
Steuerarbeitsunterlagen wurden separat gegen das synthetische MySQL geprüft.
Beide erfolgreichen Hostberichte bestätigen: keine Verbindung zur produktiven
Datenbank, geschützte Produktivdateien unverändert, private Testdateien danach
entfernt. Die dauerhafte synthetische Testdatenbank bleibt verfügbar; kein neuer
Backup-/Wiederherstellungszyklus. Berichte liegen lokal unter `audit/`, nicht als
Veröffentlichungsnachweis einer allgemeinen Bilanzfunktion.
