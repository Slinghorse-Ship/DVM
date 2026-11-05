> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Rechnungsjahr, Kontierung und Touchvorschau

Stand: 8. September 2026. Lokal geprüft und anschließend live veröffentlicht; Laufzeitnachweis unten.

## Verhalten

- Das ursprüngliche Rechnungsdatum bestimmt das Rechnungsjahr, unabhängig von der Version des internen Kontenplans. Im Rechnungseingang zeigt ein Hinweis das Jahr an und folgt manuellen sowie durch Erkennung übernommenen Datumsänderungen.
- Die bisherige Jahresgleichheitsprüfung beim Speichern entfällt. Konten werden weiterhin gegen den vorhandenen SKR42-Katalog 2026 geprüft; `catalog_year` bleibt ausdrücklich 2026. Es werden keine historischen Originalkontenpläne vorgetäuscht und keine Datumswerte umgeschrieben.
- Bei der erstmaligen bestätigten Zuordnung eines hochgeladenen Rechnungsdokuments zu einer Ausgabe folgt das Dokumentdatum dem Rechnungsdatum. Nur eine unveränderte automatische Eingangsablage wird in den passenden Finanzjahresordner verschoben. Manuelle, Inventar- und GEMA-Ablagen bleiben bestehen. Vorher/Nachher werden protokolliert; Originaldatei und Maildaten bleiben erhalten.
- Die Kurzhilfe erklärt alle Sphären 1, 2, 3, 4 und 9 direkt und beschreibt Steuerkonto und BU. Zweck und Kostenart allein rechtfertigen keine steuerliche Einordnung.
- Ein reproduzierter Vorschlagsfehler wird geschlossen: Nicht veranstaltungsgebundene Rechnungszwecke teilten bisher bei gleicher Kostenart dieselbe Historie. Solange kein strukturierter ursprünglicher Zweck vorliegt, bleiben automatische Vorschläge für diese Fälle offen. Freitextnotizen werden nicht als Identität geparst. Für Veranstaltungsrechnungen bleiben eindeutig passende Lieferant-, Positions-, Kostenart-, Zweck- und Veranstaltungsverknüpfungen nutzbar. BU wird weiterhin nicht automatisch übernommen.
- PDF- und Bildvorschauen unterstützen Zweifingerzoom um den gemeinsamen Fingermittelpunkt und anschließendes Verschieben mit einem Finger. Maus, Tastatur, Seitenwahl und Zoomtasten bleiben verfügbar. Änderungen betreffen nur die Vorschau. PDF-Renders werden seriell zusammengefasst, die bisherigen Zoom- und Canvasgrenzen bleiben bestehen.

## Grenzen

Der DATEV-Export bleibt beim unterstützten Zahlungszeitraum 2026. Ein altes Rechnungsdatum mit tatsächlicher Zahlung 2026 ist von einem historischen DATEV-Stapel 2022 zu unterscheiden. Die verwendete Kontenplanbasis wird nicht als historischer Jahreskatalog ausgegeben. Sondersteuersätze und weitere BU-Fälle werden durch diese Änderung nicht ergänzt.

Eine dauerhafte Erweiterung der Vorschläge benötigt strukturiert gespeicherte, validierte Rechnungszwecke. Historische Freitextnotizen sind kein verlässlicher Backfill. Automatische Sphären- oder Vorsteuerentscheidungen allein aus Begriffen wie Inventar, GEMA oder Versicherung sind nicht vorgesehen.

## Prüfung

- Backend: alte Rechnungsdaten, gültige/gesperrte Konten, Rollen und Mandanten, idempotente Speicherung, DATEV-Jahresgrenzen, Dokumentablage und Audit-Rollback.
- Echter lokaler Browserablauf mit synthetischem Beleg vom 4. April 2022: Kontierung, Fehler-POST mit Entwurfserhalt, erfolgreiche Speicherung, Rechnungs-/Dokumentjahr 2022 bei Kontenplanbasis 2026, korrekter Jahresordner und Wiederholschutz.
- PDF.js-Canvas und Bild mit echten emulierten Zweifingergesten auf Desktop und bei 390 Pixel Breite: Vergrößern, Verkleinern, Zoommittelpunkt, Verschieben, Abbruch, Seitenwechsel, Tasten, kein horizontaler Seitenüberlauf.
- Keine Testmails, Änderungen realer Rechnungen oder Zahlungen. Schema 62 bleibt unverändert.

## Technische Stellen

`src/Accounting.php`, `src/InvoiceAccountingSuggestions.php`, `src/Documents.php`, `views/Finance/Invoices/invoice-document-year.php`, `public/invoice-document-year.js`, `views/Finance/Invoices/invoice-accounting-help.php`, `views/Finance/Accounting/accounting-help.php`, `views/Settings/positions-detail.php`, `public/preview-pan.js`, `public/application-pdf.js`, `public/image-preview.js` sowie die zugehörigen Preview-Views, CSS und Regressionstests.

## Veröffentlichung und Live-Nachweis

- Laufzeitcommit `fd0af302d8880a0ea659d6b6a6b20fc32f80a4cc`, Ausgangsbasis `d678fe9`. Vorab stimmten alle betroffenen vorhandenen Live-Dateien mit der Git-Basis überein. 21 private beziehungsweise öffentliche Dateiziele wurden veröffentlicht und bytegenau zurückgelesen.
- Ohne Datenbankmigration, Schema weiterhin 62. Geschützte Dateisicherung und Dateidatenbank-Snapshot vor Veröffentlichung. Der gemeinsame Laufzeitzugang wurde während des Austauschs gesperrt; Bestandsanfragen erhielten eine begrenzte Auslaufzeit und die Workersperre wurde geprüft. Das ist keine vollständige FPM-Prozessmessung.
- Tatsächliche PHP-FPM-Laufzeit 8.3.33: historische Positionsvalidierung für 2022 mit Kontenplanbasis 2026, Jahreshinweis und alle fünf Kurzhilfe-Sphären erfolgreich geprüft. Datenbankintegrität `ok`, keine Fremdschlüsselfehler; Prüfzugriff mit `query_only`, keine Finanzdatenänderung.
- 15 öffentliche Assets entsprechen den Git-Bytes. Startseite, Anmeldung, Galerie und Programmarchiv lieferten HTTP 200.
- Authentifizierte Live-Sichtprüfung in einem getrennten Tab: bestehendes Rechnungsdatum 2022-04-04, Anzeige „Rechnungsjahr 2022“, geladener PDF-Canvas, Touch-Anleitung und Kurzhilfe vorhanden. Der echte Beleg blieb „Rechnung noch nicht erfasst“; kein Speichern, keine Zuordnung und keine Zahlung im Test.
- Der tatsächliche Speicherdurchlauf und die emulierten Touchgesten wurden lokal mit synthetischen Daten geprüft. Ein physisches iPhone wurde nicht verwendet. Keine Testmails versendet.
