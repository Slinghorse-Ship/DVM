# Rechnungserkennung, Firmenbankdaten und Positionszuordnung – lokale Prüfung

Stand 07.09.2026. Ausschließlich synthetische lokale Daten unter 127.0.0.1:8091, keine Livekontakte, Mails oder Zahlungen.

## Umsetzung

Die bestehende Rechnungseingangsmaske bleibt links, Original PDF/Bild rechts. Der neue explizite Erkennungsknopf ruft InvoiceRecognition per JSON auf und lässt den Entwurf erhalten. Jeder Header-Vorschlag ist bearbeitbar und wird nur über seinen eigenen Übernahmeknopf in das Zielfeld kopiert. Eine ausgewählte Firma bleibt geschützt. Im neuen Firmenkontakt-Dialog sind Anschrift sowie IBAN/BIC prüfbare Vorschläge. Migration 055 speichert Bankangaben nur bei der atomaren Neuanlage; Wiederholung mit abweichender Bankangabe wird abgewiesen. Keine bestehende Bankverbindung wird aktualisiert.

InvoicePositionReview normalisiert 1–50 ausdrücklich bestätigte Brutto-Positionen in die vorhandenen accounting_positions. Die Bruttosumme muss dem Gesamtbetrag entsprechen. Eine eingegebene Steuer-Gesamtsumme muss der vorhandenen positionsweisen Accounting-Rundung entsprechen. Keine stillen Ausgleichsbeträge oder automatische Kontierung. Unklare OCR-Netto-/Bruttoangaben bleiben leer; XML-Zeilen können nur bei eindeutigem EUR-Rechnungskopf mit geeigneten Werten als Rechenvorschlag übernommen werden. Nicht unterstützte Steuersätze, Rabatte/Sonderfälle erfordern fachliche Klärung.

Jede Position darf mehrere Inventargegenstände erhalten, insgesamt höchstens 100 Zuordnungen. Beziehungen werden beim selben Finanz-Commit geprüft und in invoice_position_items gespeichert; alle Original→Inventarlinks werden dedupliziert. Bestand, Stückwert und Anschaffungsbeleg ändern sich nicht. Zuordnungen sind am gespeicherten Finanz-/Eingangsbeleg und im Belegpaket unter inventory_positions sichtbar. Historische Beschreibungen sind Erfassungssnapshots.

## Ausgeführte Prüfungen

| Suite | Ergebnis | Umfang/Grenze |
| --- | --- | --- |
| invoice-recognition-workflow.php | Exit 0, 19 Prüfungen | Bank-/Mandant-/Leserechte, ungültige IBAN, geänderter Replay, 2 Accountingpositionen/3 Gegenstandslinks, Summe/Steuer/Bestätigung, Fremd-/Stale-Item, atomarer Fehler |
| invoice-inbox-guided.php | Exit 0, 21 Prüfungen | bestehende Erfassung, EUR-/Gutschriftvorschläge, Replay/Originalversion |
| invoice-inbox-items.php | Exit 0, 14 Prüfungen | bestehende Mehrfachzuordnung, keine Preis-/Bestandsänderung |
| invoice-supplier-create.php | Exit 0, 15 Prüfungen | Firmenanlage ohne Mitglied/Mail, Rechte, Idempotenz |
| finance-bundle.php | Exit 0, 11 Prüfungen | bestehende Originalausgabe/Hash/Filter/Rechte |
| .local/recognition-ui-complete.cjs | Exit 0, echter lokaler Browser | Text-PDF sowie JPG-Scan mit Native-Tesseract; Header bewusst übernehmen, neue Firma inkl Anschrift/IBAN/BIC, Positionsentwurf bearbeiten, zwei Artikel an erster Position/einer an zweiter; speichern |
| .local/recognition-ui-readback.php | Exit 0 | Nach Browser-POST exakt 1 Ausgabe, 2 Accountingpositionen, 23800 Cent brutto/3800 Cent Steuer und 3 Positionslinks |

Zusätzlich im Browser eine absichtlich veraltete Dokumentversion gesendet: Server verweigert, beide Positionsentwürfe bleiben mit Beträgen erhalten, nach Korrektur des Testversionsfeldes erfolgreicher Save. Mobile 390 Pixel nach vollständig geschlossener Sidebar ohne horizontalen Überlauf. Rechte und Bankmutation wurden in isolierten PHP-Tests geprüft, nicht gegen echte Konten.

Die letzte JPG-Prüfung bestätigte im gespeicherten Recognition-Ergebnis method=ocr, state=ready, items=2, mime=image/jpeg. Text-PDF zuvor method=text. Originale wurden für diese Funktionsrunde mit dem echten Documents-Uploadservice als lokale Fixtures angelegt; der neue Browserteil beginnt am Rechnungseingangsdetail.

## Bildbeleg und Grenzen

Handbuchfigur finanzen-rechnungsfirma ist ein echter lokaler Dialogausschnitt mit drei Markierungen, erfundener Firma/Adresse und bewusst leerem Bankfeld; keine Mitgliedsnamen oder echten Kontodaten. figures.json enthält Quelle, Prüfsumme und Datenschutzprüfung. Kapitel 07 erklärt die Schritte einschließlich Mehrdeutigkeit und Rundungsgrenzen. Kein Handbuchbuild durch diesen Agenten.

OCR ist keine formale E-Rechnungsvalidierung, Echtheitsprüfung, steuerliche Freigabe oder Zahlungsfreigabe. Die bestehende KoSIT-Prüfung bleibt ein getrennter Vorgang. Parser/Runtime sind Eigentum des Identity-/Root-Auftrags; deren ausführliche Prozess-/Ressourcentests liegen separat.


## Nachtrag: Texterkennung direkt am Inventargegenstand

`inventory-item-invoices.php`, `inventory-invoice-recognition.js` und der LiveUpload-ready-Hook erlauben Erkennung vor der Dokumentablage und am vorhandenen Original. Die ursprüngliche Token-/Key-Bindung wird auch nach einem fehlgeschlagenen Upload-Speichern wiederhergestellt. Backendprüfungen dazu gehören zum Identity-Auftrag (`runStaged` / `runInventory`), einschließlich direktem Item-Link oder exakt passender `source_entry`.

Browser tatsächlich mit Menge und getrenntem Einzelstück durchgeführt: JPG auswählen (LiveUpload), gestagte unveränderte Datei per nativer OCR lesen, Datum/Stückwert bewusst übernehmen, falsches Uploadziel serverseitig ablehnen, restored Token erneut erkennen, richtiges Original einmal speichern und am vorhandenen Dokument erneut erkennen. Entwurfsfelder bleiben nach dem Dokument-POST erhalten. Solange noch Uploads offen sind, blockiert der Gegenstandsspeicherknopf mit dem Hinweis auf die zuerst nötige Dateiablage. Bestandsmenge bleibt unverändert; kein Finanzbeleg oder Mail wird erzeugt.

Der Tabentwurf enthält typgerechte Werte einschließlich Checkbox/Radio `.checked` und Mehrfachauswahl. Bei Versionsabweichung erscheint eine Warnung mit den früheren Entwurfsangaben; sie werden nicht automatisch in den neueren Gegenstand eingetragen. Ungültige optionale Stückwerte verwenden die gemeinsame rote Inline-Fehleranzeige. Fehlerhafte Erkennung zeigt den konkreten Workerhinweis.
