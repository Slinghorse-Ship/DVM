# Vereinfachte Finanzbedienung und Profilexport

Stand: lokal umgesetzt und mit ausschließlich synthetischen Daten geprüft. Veröffentlichung und abschließende Livekontrolle übernimmt die gemeinsame Releaseprüfung.

## Rechnungseingang

- Titel, Dateiname, Absender und Betreff sind über die Rechnungssuche auffindbar. Jahresfilter und feste Seiten mit höchstens zwölf Rechnungen begrenzen die sichtbare Liste. Alle verwendeten Zeilen durchlaufen zuerst die bestehenden Dokumentrechte.
- Die Detailansicht führt zur vorherigen Suche, Jahresauswahl und Seite zurück. Ein einzelner Hauptknopf pro Rechnung öffnet die Prüfung; Entfernen und Wiederherstellen bleiben im vorhandenen Detailbereich.
- Der Dateiupload und E-Mails ohne Beleg sind eigene aufklappbare Abschnitte. Der Uploadlink öffnet den passenden Abschnitt direkt.
- E-Mails ohne Beleg werden mit zehn Einträgen pro Seite serverseitig durchsucht. Ältere Nachrichten jenseits der bisherigen 200-Zeilen-Grenze bleiben erreichbar. Original, Ablagegrund und Rechte bleiben erhalten.

## Beleg manuell erfassen

- Drei Abschnitte: Worum geht es; Originaldatei; Betrag und Zuordnung. Einzige primäre Speicheraktion am Formularende.
- Ein optionales Original wird unmittelbar nach der Auswahl über den vorhandenen Liveupload übertragen. Beim Speichern werden Beleg, Original und Verknüpfung in derselben Finanztransaktion angelegt. Fällt eine spätere Zuordnung aus, werden alle Teile zurückgerollt.
- Zulässig sind PDF, XML, JPG, PNG und HEIC/HEIF bis 10 MB. Bestehende Dateiprüfung, private und sitzungsgebundene Uploadablage, CSRF und Finanz-/Dokumentrechte gelten weiter. Ein Original aus dem Rechnungseingang kann nicht gleichzeitig durch einen zweiten neuen Upload ersetzt werden.
- Nach serverseitigem Eingabefehler bleiben Text und erfolgreich gestagte Datei erhalten. Nach einer Stunde muss ein abgelaufener Upload erneut gewählt werden.
- Je Position stehen Bezeichnung und Bruttobetrag zuerst. Die steuerlichen Felder sind in dieser manuellen Ansicht aufklappbar und zeigen ihren Stand in der Zusammenfassung. Eingeklappte Werte werden mitgespeichert. Andere Positioneneditoren behalten ihre bisherige Anordnung.

## Mitglieder und Kontakte als Excel

- „Auswahl als Excel“ ergänzt „Als CSV“ in der vorhandenen Profil-Auswahl. Filter, ausgewählte IDs, ausgeblendete Kontaktdatenspalten und maximale Anzahl von 100 Profilen bleiben maßgeblich.
- Registry prüft dieselben Rechte und jedes gewählte Profil erneut. Fremde, archivierte und nicht zugängliche Profile sowie unbekannte Spalten und Formate werden zurückgewiesen.
- XLSX enthält ausschließlich Textzellen mit fixierter, filterbarer Kopfzeile. Führende Nullen, Unicode und mehrzeilige Adressen bleiben erhalten; Formeltext wird keine ausführbare Formel. Keine Makros oder externen Verknüpfungen. Der genaue Download wird wie bisher als geschützter Export archiviert.
- Der Export ist eine Kontaktdatenliste, kein vollständiges Backup von Mitgliedschaften, Familien oder Beiträgen.

## Evidenz

- `tests/finance-intake.php`: 27 Prüfungen unter PHP 8.3.6 und 8.5.10. Beinhaltet atomaren Rollback, unveränderte Originalbytes, Replay, Uploadrechte und Zugriff auf mehr als 200 E-Mails.
- `tests/contact-spreadsheet.php`: 25 Prüfungen unter PHP 8.3.6 und 8.5.10. Einschließlich XML-Paket, ausgewählte Spalten/Zeilen, führende Nullen, Formelneutralität, Rechte, Archivierung und bestehendem CSV-Verhalten.
- `tests/finance-intake-browser.cjs`: 24 echte Browserprüfungen mit eigener synthetischer Datenbankmandantschaft. Suche über Seiten, Rückweg, 390-Pixel-Darstellung, hochgeladene Datei bei Fehlersave, Korrektur und Wiederholung, eingeklappte Sphäre sowie unveränderter Zahlungsbestand.
- `tests/contact-spreadsheet-browser.cjs`: tatsächlicher Excel- und CSV-Download aus gefilterter mobiler Kontaktliste. Excel anschließend mit openpyxl geöffnet; genau eine ausgewählte Person, vier sichtbare Spalten und Textzellen bestätigt.
- Bestehende Regressionen unter PHP 8.3: 60 Finance-, 74 Dokument-, 22 Rechnungseingang- und 8 Liveuploadprüfungen bestanden. Dokumenttest verwendet explizit `/usr/bin/python3` mit ReportLab.
- Synthetische Finanz-Screenshots: `.local/member-profile-fixtures/member-profile-ee38c8f5a6/`; Excel-Screenshots und Downloads: `.local/member-profile-fixtures/member-profile-e11e42763d/`. Kein Livezugriff, keine Mail, kein Pushversand und keine tatsächliche Zahlung während dieser Prüfungen.
- Handbuchkapitel 4, 7 und 9 enthalten die neuen Schrittfolgen. Der Gesamtbuild erfolgt gemeinsam mit den übrigen UI-Kapiteln.
