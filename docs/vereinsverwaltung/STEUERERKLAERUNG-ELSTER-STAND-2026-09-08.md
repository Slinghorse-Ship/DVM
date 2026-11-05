# Steuerunterlagen, SKR42 und ELSTER: geprüfter Stand

Die technische ELSTER-Schnittstelle und der aktuelle ERiC-Status sind in
[ELSTER-ERIC-INTEGRATION.md](ELSTER-ERIC-INTEGRATION.md) beschrieben. Dort
stehen auch die Regeln für Hersteller-ID, Testmerker und private Laufzeit.

**Historischer Prüfstand, teilweise überholt:** Die anschließende Umsetzung erweitert Jahresangaben, Vorschau, unveränderliche Arbeitsstände und die Nacharbeitsliste. Der aktuelle lokal geprüfte Funktionsstand steht in [Steuer-Vorbereitung: Umsetzung](STEUER-VORBEREITUNG-UMSETZUNG-2026-09-08.md). Die weiterhin geltenden Grenzen zu Steuerberechnung, amtlichen Formularen und Übermittlung bleiben bestehen.

Stand: 8. September 2026. Read-only-Prüfung des lokalen Anwendungscodes, der vorhandenen Tests und Dokumentation; keine Liveprüfung, Steuerentscheidung oder Übermittlung. Die beschriebene Funktion bereitet Arbeitsunterlagen vor. Sie erstellt bislang keine ausgefüllte Körperschaftsteuererklärung.

## Kontierung und Auswertungsbasis

- Der interne Kontenplan ist **SKR42, Katalogbasis 2026**. Belegpositionen speichern Konto, Kontobezeichnung, Sphäre, BU-Schlüssel und `catalog_year`. Sphären: 1 ideeller Bereich, 2 Vermögensverwaltung, 3 Zweckbetrieb, 4 wirtschaftlicher Geschäftsbetrieb, 9 Sammelposten. Historische Rechnungen behalten ihr Originaldatum; die verwendete Basis 2026 wird nicht als historischer Jahreskatalog ausgegeben. Quellen: [TaxCatalog.php:7](../../vereinsverwaltung/src/Finance/Tax/TaxCatalog.php), [Accounting.php:39](../../vereinsverwaltung/src/Finance/Accounting/Accounting.php).
- `TaxPreparation::build()` erlaubt **ein bis drei Kalenderjahre**, ab 2000 bis einschließlich laufendem Jahr. Grundlage sind erfasste Kontotransaktionen nach **Zahlungsdatum**. Offene Rechnungen sind keine Zahlung. Der berechnete Überschuss ist Einnahmen minus Ausgaben, kein ermitteltes zu versteuerndes Einkommen. Quellen: [TaxPreparation.php:19](../../vereinsverwaltung/src/Finance/Tax/TaxPreparation.php), [FinanceReports.php:63](../../vereinsverwaltung/src/Finance/Accounting/FinanceReports.php).
- Das Journal übernimmt Konto und Sphäre der zugeordneten Belegpositionen, einschließlich anteiliger Zahlungen. Die Einnahmen-/Ausgabenübersicht gliedert dagegen nach **Belegarten** und nicht zugeordneten Zahlungen. Eine Ergebnisrechnung je Sphäre oder eine Zuordnung zu amtlichen Steuerkennziffern fehlt. Quellen: [FinanceReports.php:118](../../vereinsverwaltung/src/Finance/Accounting/FinanceReports.php), [FinanceReports.php:174](../../vereinsverwaltung/src/Finance/Accounting/FinanceReports.php).
- `fiscal_start` ist in den Steuereinstellungen vorhanden, wird von dieser Auswertung aber **nicht verwendet**. Sie bleibt kalenderjährlich. Bescheidart, Veranlagungszeitraum und Förderungszweck werden als Stammdaten mitgegeben; daraus entstehen keine ausgefüllten Formularantworten. Quellen: [SettingsSchema.php:27](../../vereinsverwaltung/src/Settings/SettingsSchema.php), [TaxPreparation.php:29](../../vereinsverwaltung/src/Finance/Tax/TaxPreparation.php).

## Vorhandene Vorschau und Exporte

Das Paket enthält je Jahr Zahlungsjournal als CSV, Einnahmen-/Ausgabenauswertung als JSON und Geldkontenbestände einschließlich Anfangsbestand. Hinzu kommen Vereins-/Steuerdaten, Tätigkeitsbericht, Erläuterung und höchstens zehn ausgewählte hochgeladene Finanz-PDFs mit insgesamt maximal 10 MB. Der Tätigkeitsbericht verwendet abgeschlossene Termine, vergangene nicht abgesagte Website-Termine sowie zugängliche veröffentlichte Protokolle. Quellen: [TaxPreparation.php:29](../../vereinsverwaltung/src/Finance/Tax/TaxPreparation.php), [ActivityReport.php:12](../../vereinsverwaltung/src/Governance/ActivityReport.php).

Die **vollständige Paketvorschau** zeigt diese Arbeitsunterlagen und Links zu den PDF-Anlagen. Sie ist keine KSt-1-/Anlage-Gem-Abgabevorschau. Beim Speichern wird aus dem aktuellen Datenstand ein geschütztes ZIP mit Snapshot erstellt; eine ältere Vorschau bindet den Inhalt nicht. Quellen: [tax-preview.php:2](../../vereinsverwaltung/views/Finance/Tax/tax-preview.php#L2), [TaxPreparation.php:48](../../vereinsverwaltung/src/Finance/Tax/TaxPreparation.php).

Der getrennte **DATEV-Zahlungsstapel** unterstützt den Zeitraum 2026, SKR42 und fünfstellige Sachkonten; Sphäre wird als KOST1 ausgegeben. Er ist kein ELSTER-Import. Historische DATEV-Zeiträume bleiben gesperrt. Quelle: [DatevExport.php:15](../../vereinsverwaltung/src/Finance/Accounting/DatevExport.php).

## Noch nicht implementiert

- Jahresabhängige KSt-1-/Anlage-Gem-Felder, Erklärungsspeicherung und Zuordnung von Vereinsdaten/SKR42-Konten zu amtlichen Kennziffern sowie Auswahl und Befüllung weiterer benötigter Anlagen.
- Steuerliche Ergebnisermittlung, Mittelverwendungs-/Rücklagenrechnung und vollständige Vermögensdarstellung einschließlich Forderungen, Verbindlichkeiten und Sachvermögen. Kontostände ersetzen diese Angaben nicht.
- ERiC-Anbindung, amtlicher ELSTER-Datensatz, ERiC-Plausibilitätsprüfung, Zertifikats-/Signaturverarbeitung, Übermittlung und automatische Verarbeitung eines Übermittlungsprotokolls.

Im geprüften Quellcode gibt es `TaxPreparation`, aber keine `CorporationTax`- oder ERiC-Implementierung. Die Controlleraktionen sind `tax-preview` und `tax-bundle`. **Ein ELSTER-Entwicklerzugang schaltet deshalb keine fertige Integration frei**; eine solche müsste mit den entsprechenden Jahresformaten, Prüfungen und Übermittlungsabläufen erst entwickelt werden. Der vorhandene ELSTER-Einstieg ist ausschließlich ein externer Link. Quellen: [index.php:336](../../vereinsverwaltung/public/index.php#L336), [tax-preparation.php:2](../../vereinsverwaltung/views/Finance/Tax/tax-preparation.php#L2), [TaxPreparation.php:44](../../vereinsverwaltung/src/Finance/Tax/TaxPreparation.php).

## Nachweis und Grenzen der Prüfung

Die vorhandenen [Steuerpakettests](../../vereinsverwaltung/tests/tax-preparation.php) prüfen Summen, Kontostände, CSV-Schutz, ZIP, Rechte, Mandantentrennung, Wiederholungsschutz und Jahresgrenzen. Alle 19 Prüfungen wurden anschließend lokal erfolgreich ausgeführt. Sie weisen keine amtliche Formularvalidierung oder ELSTER-Abgabe nach. Das [Handbuch, Kapitel 19](../../vereinsverwaltung/resources/handbook.html#kapitel-19) beschreibt Vorbereitung und separate Arbeit in ELSTER entsprechend. Ein erfolgreich erstelltes Paket bescheinigt weder vollständige Buchführung noch eine vollständige oder abgegebene Steuererklärung.

Die [offizielle ELSTER-Entwicklerseite](https://www.elster.de/eportal/infoseite/entwickler), abgerufen am 8. September 2026, nennt Registrierung, Zugang zum Entwicklerbereich, ERiC-Download und eine gesondert zu beantragende Hersteller-ID. ERiC übernimmt Plausibilitätsprüfung und Übermittlung der von der Anwendung gelieferten Steuerdaten. Die Oberfläche und fachliche Aufbereitung müssen in unserer Anwendung erstellt werden.
