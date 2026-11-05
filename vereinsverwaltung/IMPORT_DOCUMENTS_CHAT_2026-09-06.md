# Mitgliederimport, Mehrfachupload und Chat

## Mitgliederimport

Mitglieder → Excelimport: XLSX/CSV laden, Blatt und Überschriftszeile wählen, Felder zuordnen und vollständige Vorschau öffnen. Die Übernahme erfolgt erst mit dem abschließenden Formular. Grenze: 5 MB Datei, 32 MB entpackte XLSX, 1.000 Zeilen / 64 Spalten, 20 Blätter. XML-Deklarationen für Entitäten/DOCTYPE und externe Blattverknüpfungen werden nicht verarbeitet. Formeln und Excel-Fehler in zugeordneten Spalten blockieren die betroffene Zeile.

Name oder Vorname/Nachname, aktuelle/alte Mitgliedsnummer, E-Mail, Anschrift, Telefon, Geburtstag, Eintritt, Austritt und Status können zugeordnet werden. Eintritt nur als Jahr bleibt jahrgenau. Ohne Status gilt aktiv; mit Austrittsdatum ehemalig. Fehlender Eintritt benötigt eine ausdrücklich angegebene gemeinsame Vorgabe.

Nummern und Namen werden mit dem gesamten Vereinsbestand einschließlich Archiv abgeglichen; gemeinsame E-Mail-Adressen sind keine Identitätsgleichheit. Bestehende Mitgliedschaften bleiben unverändert. Kontakte können Mitglied werden, ihre Kontaktdaten und Familien bleiben erhalten; eine leere alte Mitgliedsnummer kann ergänzt werden. Neue Familien werden anschließend im Mitgliederprofil zugeordnet. Keine automatische Umnummerierung. Aktuelle neue Mitgliedschaften bereiten den vorhandenen Website-Kontoablauf ohne Zugangsmail vor; ehemalige Mitgliedschaften erhalten darüber keinen neuen Kontoauftrag.

Unklare Zeilen verhindern die gesamte Übernahme. Ein Hash bindet sie an den geprüften Bestand; unter einer Schreibtransaktion wird erneut geprüft und vollständig übernommen oder zurückgerollt. Vorschau in der geschützten Sitzung, Ablauf nach einer Stunde. Die Originaldatei wird nicht dauerhaft abgelegt.

## Dokumente

Dokumente → Datei hochladen: bis zu zehn Dateien, maximal 10 MB einzeln / 20 MB insgesamt, gemeinsame Zuordnung und Ordnerwahl. Für mehrere Dateien wird der Dateiname an den Titel angehängt. Bestehende Dateiinspektion und Bereichsrechte gelten für jede Datei. Alle Dokumente, Bytes, Zuordnungen und Historieneinträge werden atomar gespeichert. Dieselbe Formular-Anfrage erzeugt keine Duplikate. Neue Versionen bleiben bewusst Einzeldatei-Uploads.

## Chat

Der Ersteller einer Unterhaltung kann aktive Teilnehmer desselben Vereins ergänzen; die Oberfläche weist ausdrücklich auf den Zugriff auf den bisherigen Verlauf hin. Teilnehmer können ausschließlich eigene Nachrichten bearbeiten. Nachträgliche Bearbeitung ist gekennzeichnet und über Auditrevision plus Inhalt gegen konkurrierende Änderungen geschützt. Anhänge bleiben unverändert; Bearbeitung erzeugt keinen erneuten Versand.

Alle 15 Sekunden aktualisiert sich ausschließlich die Nachrichtenliste. Entwurf und gewählte Datei bleiben erhalten; bei geöffnetem Nachrichteneditor und im Hintergrund pausiert die Aktualisierung. Alte Seiten werden nicht automatisch ersetzt. Bei fehlender Verbindung oder abgelaufener Anmeldung bleibt der Entwurf stehen.

## Nachweise

Lokal bestanden: `tests/member-import.php`, `tests/document-batch.php`, `tests/documents.php`, `tests/chat-extensions.php`, `tests/portal-communication.php`. Browser geprüft: tatsächlicher Mehrfachupload synthetischer Textdateien, CSV-Upload und Feldvorschau, eigene Chatnachricht bearbeiten, Teilnehmer ergänzen, tatsächliches Nachladen und Erhalt eines eingegebenen Entwurfs. Mobile Erstansicht ohne überlagernde Navigation geprüft. Keine echten Mitgliederdaten importiert und keine realen Nachrichten versendet.

Für diese Änderungen ist keine Datenbankmigration notwendig; Schema bleibt 40.
