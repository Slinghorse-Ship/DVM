# Veranstaltungsindex bei Schemawartung erhalten

Bisher hat `EventIndex::install()` jeden vorhandenen veröffentlichten Termin aus
der Originalfassung neu in den Katalog geschrieben. Dabei wurden bestehende
Kalendersequenzen und technische Änderungszeitpunkte ersetzt. Auch eine wiederholte
oder fortgesetzte Wartung war deshalb keine reine Ergänzung fehlender Einträge.

Die Wartung wählt jetzt ausschließlich veröffentlichte Originale ohne vorhandenen
Indexeintrag und fügt deren Verweis direkt hinzu. Vorhandene Einträge werden weder
zurückgezogen noch ersetzt. Ein unveröffentlichter Entwurf wird nicht verwendet.
Die normale bewusste Veröffentlichung bleibt derselbe eigene Vorgang. Bei einem
konkurrierenden doppelten Eintrag greift weiterhin die eindeutige Datenbankgrenze;
ein Wiederholen der Wartung übernimmt den inzwischen bestehenden Eintrag.

Fünf Gruppen bestehen gemeinsam: `native-events`, `native-event-publications`,
`native-event-workflows`, `native-event-programme`, `native-event-worker`. Neue
Bedingungen erhalten einen ursprünglichen höheren Kalenderzähler, Zeitstempel mit
Mikrosekunden, die vollständige Absagenotiz sowie jede Originalseite und Revision.
Ein absichtlich fehlender Index wird aus der tatsächlich veröffentlichten Fassung
ergänzt; eine weitere Wartung erhält anschließend auch diesen Eintrag unverändert.

SHA-256 des vollständigen Fünf-Gruppen-Logs:
`120ae72d9658ed09265eb4a1f19994bd25d20926c269b8abd96159842e119b33`.

Die vorhandene eigene Cloud-Manager-Datenbank wurde außerdem ausdrücklich im
CLI-Wartungsweg aktualisiert: CMS 14→17, Kommunikation 4→18, Delivery 6→13,
Identity 4→8, Chat 1→3, Workflows 1→2 und neuer Status 1; Auth 6 und POS 1 bleiben.
Der Vergleich über die ursprünglichen Spalten erhält alle ursprünglichen Zeilen
und Werte aus 337 Tabellen sowie drei vorhandene Schlüssel. Neue Tabellen und
Spalten sind davon getrennt. Keine neue Befüllung, Anmeldung, Nachricht oder
Veröffentlichung durch die Wartung. Der normale Wiederaufnahmestart bleibt ohne
Schemaänderung. Die eigenständige CMS-Demo verwendet weiterhin ihre eigene
Modulgrenze und bekommt keine vollständige Managerinstallation darübergelegt.

Zusätzlich bestehen alle 232 nativen Installerbedingungen der neuen Paketfassung.
Die tatsächlich gestartete vorhandene Cloud-Vorschau besteht Anmeldung mit dem
vorhandenen nativen Testkonto und die Prüfung des installierten Anwendungsschemas
über die neue Statusoberfläche; kein externer Abruf und keine neue Befüllung.
SHA-256 des Installerlogs:
`25c1dee010cfea474b7808b008784c70008ff2a13d267d153bfac7ee5c959369`.
