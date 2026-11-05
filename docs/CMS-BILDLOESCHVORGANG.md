# Geschützter Datenbankvorgang für Bildänderungen

`Cms/MediaRemovalGuard` verbindet die letzte vollständige Verwendungsprüfung und
die anschließende Datenbankänderung in einem eigenen Vorgang. Der Aufrufer kann
kein früher angezeigtes leeres Ergebnis als Freigabe übernehmen. Die aktuellen
Medienverwaltungsrechte, Vereinszuordnung und exakte Bildzeile werden erneut
geprüft; verwendete oder unvollständig prüfbare Bilder bleiben gesperrt.

Der Vorgang verwendet MySQL `SERIALIZABLE`. Die geprüften Quellen halten dabei
Zeilen- und Bereichssperren bis zum Abschluss der Änderung. Ein anderer
Fachbereich oder ein direkter SQL-Schreiber kann währenddessen keinen neuen
Bezug in diesen Quellen einfügen oder einen früheren Bezug umstellen. Eine
bereits laufende äußere Transaktion mit unbekanntem früherem Stand wird
abgewiesen. Die Standardisolation der Verbindung bleibt erhalten.

Die interne Änderungsfunktion erhält die genau gesperrte eigene Bildzeile. Ihr
Ergebnis wird erst nach gemeinsamer Bestätigung zurückgegeben; ein Fehler rollt
die Datenbankänderungen vollständig zurück und gibt die Sperren frei. Dieser
Baustein sichert die Datenbank. Dateiquarantäne, endgültige Löschoberfläche,
Aufbewahrung und Wiederherstellung folgen als weitere Umsetzung.

124 Fach-/MySQL-Verträge prüfen den frischen Abgleich, aktuelle Rechte,
Vereinstrennung, fehlende Kennungen, äußere Transaktionen, tatsächliche
Änderungsbestätigung und Rücknahme sowie eine zwischenzeitlich neu hinzugefügte
Quelle und unlesbare Originale. Drei unabhängige echte PHP-/MySQL-Schreiber
prüfen den Parallelfall: tatsächliche Social-Speicherung, zusätzlicher direkter
Bezug und Änderung einer früheren Quelle. Alle früheren Datenbankzeilen,
Gesamtzeilenzahlen und ursprünglichen Bildvarianten bleiben unverändert;
neue kontrollierte Testdaten entstehen ausschließlich in frischen isolierten
Datenbanken. Acht betroffene native Suiten bestehen. CMS bleibt 14 ohne DDL.
