# Sofortige Dateiübertragung

Dateiauswahl und Ablegen per Drag-and-drop starten die Übertragung mit Fortschritt,
Fehleranzeige und Wiederholung. Die bisherige HTML-Formularübertragung bleibt als
Fallback ohne JavaScript verfügbar. Galerie und Newsletter behalten ihre vorhandenen
direkten Bild-Uploads.

Inventaranhänge und GEMA-Rechnungsdateien werden mit der aktuell eingestellten
Zuordnung abgeschlossen. Die Dokumentablage überträgt sofort; Titel und Datum
werden je Datei bearbeitet. Jede Datei erhält ein Ablageziel. Ein Stapel kann bis zu zehn Ziele enthalten,
beispielsweise fünf Dateien für Rechnung A und fünf für Mitglied B. Markierte
Dateien lassen sich gemeinsam verschieben. Eine Zusammenfassung nach Zielen
muss vor dem Speichern ausdrücklich bestätigt werden. Weitere Dateien ergänzen den
Stapel. Jedes gespeicherte Dokument bleibt einzeln bearbeitbar. Mitgliederlisten und CSV-Dateien öffnen
die Vorschau; der eigentliche Datenimport bleibt eine eigene Aktion.
Chat, Protokolle, Dokumentversionen, Nachweise und Einstellungen übertragen sofort,
benötigen jedoch weiterhin das abschließende Senden/Speichern ihrer Fachaktion.

Zwischenablage: private Sitzungsverzeichnis-Unterordner, zufällige Dateinamen,
Sitzungs-, Benutzer-, Aktions- und Feldbindung, SHA-256-Prüfung, eine Stunde
Gültigkeit. Abgelaufene Dateien werden beim nächsten Upload bereinigt. Erfolgreich
zugeordnete oder ausdrücklich entfernte Dateien werden sofort aus der
Zwischenablage gelöscht. Maximal 20 offene Dateien bzw. 30 MB je Sitzung;
die vorhandenen engeren Grenzen der einzelnen Bereiche gelten zusätzlich.
CSRF, Berechtigungen und bisherige serverseitige Dateiprüfungen bleiben aktiv.
Die Zwischenablage führt keine Dateien aus und ersetzt keinen Virenscanner.

Validierung: `php tests/live-uploads.php` prüft Bindung, Ablauf, Manipulation und
Bereinigung. Lokale HTTP-/Browserprüfungen umfassen Mehrfachablage, Importvorschau,
Chat ohne automatisches Senden, Wiederholungen, fremde Sitzungen, abgewiesene
Skriptdateien, zusätzliche Uploadfelder und mobiles Drag-and-drop.
Die bestehenden Dokument-, Inventar-, Einstellungs-, Protokoll-, Chat- und
Mitgliederimporttests wurden ebenfalls ausgeführt. Keine echten Mails gesendet.
