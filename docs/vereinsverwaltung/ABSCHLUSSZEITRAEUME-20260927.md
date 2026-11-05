# Explizite Abschlusszeiträume – Rechenkern, Journal und Arbeitsunterlagen

Stand 27.09.2026. **Nicht mit einer vollständig angebundenen Verwaltung
abweichender Wirtschaftsjahre verwechseln.** Keine Produktivfreigabe.

## Umgesetzt

`BalancePeriod` prüft tatsächlichen Beginn und tatsächliches Ende, einschließlich
gültiger Kalendertage, maximal zwölf Monaten, lückenloser Folge und getrennten
Identitäten für mehrere Abschlüsse im selben Kalenderjahr. Die Identität enthält
beide Datumsgrenzen, nicht nur das Abschlussjahr.

`BalanceCalculation::build` kann einen expliziten Zeitraum erhalten. Alle
Buchungsdaten werden daran geprüft und die Grenzen bleiben im Ergebnis erhalten.
Die vorhandenen Kalenderjahres-Aufrufer behalten unveränderte Ergebnisfelder;
historische Berechnungsnachweise werden nicht allein durch die Erweiterung verändert.

`BalancePeriodAssets` berechnet aus dem unveränderten Originalplan lineare
Abschreibung und bestätigte GWG für vollständige Monate. Anfangskosten,
Zugänge, kumulierte Abschreibung und Schlusswerte bleiben getrennt. Aufeinander
folgende Rumpfzeiträume setzen weder Nutzungsdauer noch Cent-Rundung zurück.
Vollständig abgeschriebene, aber noch vorhandene Anlagen bleiben mit ihren
historischen Anschaffungskosten nachgewiesen.

Die reine Datums-/Rechenprüfung entscheidet **nicht**, ob der Verein ein
Wirtschaftsjahr umstellen darf. Die gesetzlichen Voraussetzungen für Rumpfjahre
und gegebenenfalls das Einvernehmen mit dem Finanzamt müssen gesondert beurteilt
werden; siehe [§ 8b EStDV](https://www.gesetze-im-internet.de/estdv_1955/__8b.html).

## Prüfungen

103 synthetische Zeitraum-/Anlagenprüfungen: Jahreswechsel, zwei Abschlusszeiträume
mit Endjahr 2026, Rumpfjahr, Überschneidung, Lücke, Schaltjahr, falsche Daten,
Grenztage, Cent-Rundung bei allen elf Monatsaufteilungen und unveränderte Originalpläne.
Zusätzlich 91 bestehende Bilanzrechenprüfungen und 38 Steuerwert-/Vortragsprüfungen
bestanden. Keine Datenbank erforderlich, keine Buchungen oder Übermittlung.

Der neue Datumsrechenkern gehört zur Laufzeitbindung einer Abgabefreigabe.
Eine geänderte/fehlende Datei erzwingt erneute Prüfung; 120 Fingerprint-Prüfungen
bestanden. PHP und Python verweigern ausdrücklich periodisierte Werte im noch
kalendergebundenen Export. Ein anderer Zeitraum darf nicht unbemerkt zu einem
Kalenderjahresabschluss werden.

Zusätzlicher Lauf auf dem Webserver in der dauerhaft freigegebenen synthetischen
Umgebung: `audit/ebilanz-mysql-host-20260918-6a85e812b73ce7d0.json`, Quellpaket
`bf6ac32eb8331f416faf4178741f4a6dc35566fff864d42338a695b102c07a88`.
Alle acht Testgruppen bestanden: Zeitraumrechnung, bestehende Bilanzrechnung,
Steuerwerte, Abgabeworkflow, Korrekturabschlüsse, kombinierter Gesamtverein,
Jahresmapping und Laufzeitbindung. Eingebettete Basisprüfungen werden nicht als
zusätzliche unabhängige Sonderfälle gezählt. Keine neue Sicherung/Rücksicherung,
keine produktive Datenbank oder echte Übermittlung; private Testdateien entfernt.

## Ergänzung: Speicherung, Oberfläche und Arbeitsunterlagen

Migration 102 ergänzt `balance_periods` und `balance_period_events`. Ein Zeitraum
wird durch `(club_id, YYYYMMDD-YYYYMMDD)` identifiziert. Die bisherigen
Kalenderjournale bleiben unverändert; weder ihre Primärschlüssel noch alte
Freigabe-Hashes werden umgeschrieben. Definition und Änderungen sind durch native
MySQL-UPDATE-/DELETE-Sperren geschützt. Änderungen werden fortlaufend angehängt,
mit Prüfsummen verkettet und beim Lesen erneut berechnet.

Der neue `BalancePeriodLedger` unterstützt geprüfte Anfangsbestände, explizite
Jahres-SKR42-Konten, manuelle Buchungen, vollständige Stornobuchungen und die
Festschreibung des **Buchungsstands** durch einen berechtigten Vorstand.
Laufende Zeiträume können nicht festgeschrieben werden. Der Verein wird vor
jedem Schreibvorgang gesperrt; Rolle, Abschlussart, Version und Quellstand werden
innerhalb dieses Vorgangs geprüft. Wiederholte oder veraltete Formulare buchen
nicht doppelt. Bei entfallener Vorstandsberechtigung kann ein neuer Vorstand
denselben Abschluss erneut bestätigen; Beträge, Abschlussidentität und alte
Prüfungen bleiben erhalten.

Vorträge folgen tatsächlichen Datumsgrenzen. Zwei aufeinanderfolgende Zeiträume
mit Endjahr 2026 behalten getrennte Identitäten und Ergebnisse. Ein vorhandener,
geprüfter Kalenderabschluss kann ausdrücklich als Vorgänger übernommen werden;
anschließend verweigert der alte Jahresdialog das Anlegen konkurrierender Jahre.
Eine spätere Änderung des Vorgängers blockiert den ungeprüften Vortrag.

`views/Finance/Balance/balance-periods.php` ist über den Bilanzdialog erreichbar und bindet
Speicherung, Buchungsdaten und Anzeige an dieselbe Zeitraum-ID. Leser erhalten
keine Schreibformulare; der Kassenwart kann ohne Vorstandsberechtigung nicht
festschreiben. Fehlgeschlagene Eingaben bleiben erhalten. Die manuelle
Buchungsoberfläche ist noch **keine** vollständige, automatisch aus Belegen
geführte Bedienung für unerfahrene Kassenwarte.

`BalancePeriodExport` erzeugt einen geschützten ZIP-Download mit Journal,
Kontensalden, vollständigem Prüfverlauf und Dateiprüfsummen. Das Steuer-Arbeitspaket
nimmt jeden im ausgewählten Jahr **endenden** Zeitraum vollständig auf, unter
`abschlusszeitraeume/<period_id>/`. Es schneidet ihn nicht in Kalenderjahre und
zählt den Vortrag nicht nochmals als Ertrag. Die vorhandenen Zahlungslisten
bleiben ausdrücklich gesonderte Kalenderauswertungen. Bei EÜR werden keine
Bilanzzeiträume in das Paket eingefügt.

Diese Arbeitsunterlagen sind **kein amtlicher Steuerexport / XBRL-Datensatz**.
`tax_export_ready` bleibt falsch. Der bestehende E-Bilanz-Workflow verweigert es,
einen gespeicherten abweichenden Zeitraum als Kalenderjahr zu übermitteln.
Zusätzlich zu `VEREIN_BALANCE_WORKSPACE=1` benötigt der neue Schreibpfad
`VEREIN_BALANCE_PERIODS=1` und ausdrücklich `VEREIN_MODE=synthetic`.

### Nachweise dieser Ergänzung

- 75 neue MySQL-/Controller-/Export-/Oberflächenprüfungen bestanden.
- Zusätzlich 103 Zeitraum-/Anlagenrechenprüfungen, 47 bestehende Journalprüfungen,
  59 Korrekturworkflow-Prüfungen, 61 Abgabeworkflow-Prüfungen und 124
  Laufzeitbindungsprüfungen bestanden. Dies sind Assertions, keine vollständige
  steuerliche Sonderfallabdeckung.
- Alle 15 geänderten/neuen PHP-Dateien syntaxgeprüft.
- Lokale synthetische HTML-Sichtprüfung im Desktop-Browser und mit 390-Pixel-
  Viewport: lesbare Datumsgrenzen, umbrechender Downloadknopf, kein horizontaler
  Seitenüberlauf; Formularfelder ungefähr 50 Pixel hoch. Kein echter iPhone-
  oder Nutzertest, keine vollständige Manager-Abnahme.
- Abschließender Webserverlauf:
  `audit/ebilanz-mysql-host-20260918-01d07c1556e0c4c9.json`;
  Quellpaket `50c19b9274c9c076006f9f73e36f0013be21a02fd819fb2da5c1c7637e2390fb`.
  Alle sechs Gruppen erfolgreich, keine PHP-Warnungen. Nur freigegebene
  synthetische MySQL-Datenbank; keine produktive Verbindung, Mail oder Abgabe.
  Geschützte Produktivdateien unverändert, temporäre Serverdateien entfernt.

## Noch notwendige Integration

Aktualisierung: Die [Rechnungs-/Zahlungsübernahme](ZEITRAUM-BELEGUEBERNAHME-20260927.md)
und [lineare/GWG-Abschreibungsintegration](ZEITRAUM-ABSCHREIBUNG-20260927.md) sind
inzwischen ergänzt. Die nachfolgenden Angaben dokumentieren den früheren
Stand von Migration 102; die übrigen Bereichs-, Steuer-, Korrektur- und Sonderfall-
Anbindungen bleiben erforderlich.

Das bisherige Kalenderjournal identifiziert einen Jahresstand weiterhin mit
`(club_id, tax_year)`. Das genügt nicht für zwei Rumpf-/Normalabschlüsse mit
demselben Endjahr. Eine bloße neue Jahresbeschriftung wäre daher falsch.

Die explizite Speicherung, manuelle Buchung, Oberfläche, unveränderte Vortragskette
und Arbeitsunterlagen sind jetzt angebunden. Noch erforderlich sind die automatische
Beleg-/Zahlungsübernahme, periodengebundene Abschreibungsläufe sowie die Bindung
aller Bereichs-, Steuerüberleitungs- und Korrekturprüfstände an die betreffende
Periode. Ein geänderter Vorzeitraum wird gesperrt, aber noch nicht über einen
periodisierten Korrekturabschluss abgeglichen. Danach muss der Generator die
Zeiträume in XBRL-Kontexten, Stichtag, Vergleichsperiode, Kontenjahrgang und
Taxonomieauswahl unabhängig nachprüfen. Diese weiteren Abläufe müssen ebenfalls
über Speicherung, Oberfläche und amtlichen Export gemeinsam abgenommen werden.

Degressive Jahresentscheidungen, Anlagenabgänge, Sphären-/Nutzungswechsel und
angebrochene Monate werden durch diesen Baustein noch nicht abgewickelt.
Diese Fälle bleiben ausdrücklich gesperrt, statt alte Kalenderjahreswerte zu
übernehmen. Der bestehende Kalenderjahres-Workflow ist weiter regressionsgeprüft;
die allgemeine Vereinsbilanz und ihre Gesamtabnahme sind damit **nicht fertig**.

Keine Änderung der Vereinsbasis: Kulturhus bleibt EÜR. Keine vorgezogene
Manager-Gesamtprüfung und keine Veröffentlichung.
