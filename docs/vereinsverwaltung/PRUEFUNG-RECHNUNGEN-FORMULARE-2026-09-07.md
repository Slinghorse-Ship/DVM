# Prüfung: Rechnungen, Formulare und Inventar

Geprüfter Ausgangsstand: Git `cc5bcf4`, Datenbankschema 53. Die Erweiterungen ergänzen Schema 54–56: Erkennungsergebnisse, geprüfte Firmenbankdaten und Positionszuordnungen sowie der beim Reservieren festgehaltene Satzinhalt.

## Abgedeckte Abläufe

- Rechnungseingang: vorhandenes PDF beziehungsweise Bild aus dem Mailanhang erkennen; Original und E-Mail-Verknüpfung erhalten; Daten prüfen; Firma einschließlich Bankdaten bewusst anlegen; mehrere Positionen mehreren Gegenständen zuordnen; synthetische Buchung mit Summen- und Steuerkontrolle speichern.
- Inventar: echter Liveupload beim Mengenartikel und Einzelstück, Erkennung vor der Dateiablage, Datum und geprüfte Stückangaben übernehmen, Original ablegen und erneut erkennen. Fehlerhafter Upload bleibt korrigierbar; Sitzungsschlüssel und Datei bleiben gültig. Gegenstandsentwürfe überstehen die Dateiablage einschließlich Checkboxen, Radiobuttons und Mehrfachauswahl. Versionskonflikte zeigen die früheren Angaben.
- Globale Formulare: fehlende oder ungültige Felder werden rot, mit konkretem Hinweis und Fehlerübersicht angezeigt. Fokus und Tastaturzugriff funktionieren; korrigierte Felder verlieren ihren Hinweis einzeln. Tatsächlich abgewiesener Server-POST ohne native Prüfung getestet. Rechte-, Versand- und Versionsprobleme werden nicht als erfundene Pflichtfeldfehler markiert.
- Inventarinhalt: vier Sätze mit je vier Füßen werden als 16 Bestandteile angezeigt. Ausleihen halten den Satzinhalt bei der Reservierung fest. Rückgabe eines vollständigen und eines unvollständigen Satzes führt zu einem gesperrten Satz, keinem automatisch gebuchten Verlust. Historische Ausleihen erhalten keine erfundenen rückwirkenden Inhaltsangaben.
- Nicht gebuchte Eingangsrechnungen beziehungsweise reine E-Mails lassen sich über sichtbare Aktionen ablegen und wiederherstellen. Originaldateien bleiben erhalten; geschützte oder gebuchte Belege erhalten keine unzulässige Löschaktion.

## Ergebnisse


Alle schreibenden Geschäftsabläufe verwendeten die synthetische lokale Datenbank. Auf dem Hosting wurden ausschließlich private synthetische Dateien zur Laufzeitprüfung verwendet. Keine Testmails an Mitglieder oder Vorstand, keine Testbuchungen und keine Testzahlungen in der Produktivdatenbank.

Für die Veröffentlichung prüft der Deploymenthelfer den bisherigen Livecode gegen den Git-Ausgangsstand. Die Migration läuft zuerst zweimal auf einer privaten Livekopie; Fingerabdrücke aller bisherigen Spalten und Datensätze müssen unverändert bleiben. Die eigentliche additive Migration prüft dasselbe unter Schreibsperre sowie Fremdschlüssel und Datenbankintegrität. Anschließend werden die veröffentlichten Dateien byteweise mit dem Commit abgeglichen. Eine laufende Datenbank wird bei einem Dateirollback nicht durch ein altes Backup ersetzt.
