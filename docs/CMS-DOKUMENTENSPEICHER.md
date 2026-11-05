# Optionale Speichergrenze für Website-PDFs

`Cms/DocumentQuota` erweitert den nativen Website-PDF-Upload um eine optionale
Vereinsgrenze. Der Ausgangszustand ist **ohne Grenze**; ein gewöhnlicher Einzel-
oder Mehrfachupload benötigt keine neue Bestätigung oder Rechteangabe.
Die Grenze betrifft ausschließlich neue Kopien nativer Website-PDFs.
Die bereits vorhandenen Grenzen einzelner PDF-Dateien und einer Uploadgruppe
bleiben die gleichen. Interne Vereinsdokumente, Chatdateien und andere Medien
haben eigene fachliche Speicherwege.

Eine ausdrückliche Verwaltungsentscheidung kann 0 bis 102400 ganze MiB setzen.
0 bedeutet ohne Speichergrenze. Jede gespeicherte Entscheidung hat eine
eindeutige Version und unveränderliche Regelgeschichte. Alte Formulare,
ungültige Werte und aktuelle Rechte-/Vereinswechsel überschreiben keine Regel.
Die [mobile Einstellung](CMS-DOKUMENTENSPEICHER-BEDIENUNG.md) ist jetzt unter
der Speicherprüfung angebunden.

Nutzung wird serverseitig aus den originalen gespeicherten Dateigrößen des
eigenen Vereins gezählt. Private und veröffentlichte Originale sind enthalten.
Es werden keine ganzen PDF-Dateien oder Nachweise für die Zählung geladen.
Eine Grenze unterhalb der bisherigen Nutzung ergibt null verbleibende Bytes;
sie löscht und verändert keine vorhandene Datei, Zuordnung oder Veröffentlichung.

Der gemeinsame Vereinssperrvorgang schützt Regeländerung und neue Uploads.
Aktuelle sperrende Abfragen berücksichtigen unabhängig bereits bestätigte
Regeln und Originale auch innerhalb einer älteren Repeatable-Read-Sicht.
Eine ausgewählte Mehrfachgruppe wird zusammen geprüft und gespeichert;
ein Fehler entfernt ausschließlich ihre neuen Kopien und schreibt keine
Teilgruppe in die Mediathek. Die gemeinsame Bild-/PDF-Transaktion behält ihre
atomare Zuordnung und räumt bei Fehlern nur die neu erzeugten Dateien auf.

CMS 17 installiert zwei leere Tabellen für aktuelle Regel und Regelgeschichte.
Ein früheres Schema ohne beide Tabellen bleibt beim normalen Lesen und Upload
ohne Grenze; es gibt keine Web-DDL oder erfundene historische Entscheidungen.
Eine unvollständige Installation verlangt die Fertigstellung im Installer.
Beide ursprünglichen CMS-14-Entwicklungsbestände sind unverändert geprüft.

114 Fach-/MySQL-/Dateiverträge prüfen echte Einzel-/Mehrfachuploads, zwei
gleichzeitige native Uploadprozesse, komplette Ablehnung einer übergroßen
Gruppe, ältere Datenbanksichten, aktuelle Rechte-/Vereinswechsel, unveränderliche
Versionen und gemeinsame Bild-/PDF-Rücknahme. Alle vorherigen vollständigen
Zeilen, Primärkennungen, Gesamtzahlen, Veröffentlichungen, Original-PDFs,
Pixel, verschlüsselten Belege und Schlüssel bleiben exakt erhalten.
21 betroffene native Suiten bestehen. Ein tatsächliches CMS-16-Installerupdate und eine tatsächliche verschlüsselte
Wiederherstellung erhalten die ursprünglichen Dateien und gefüllten Regeln;
diese Prüfungen müssen mit dem endgültigen Offline-Inventar bestehen.

Weitere Medienquoten, nicht zugeordnete Dateien,
zusätzliche Formate/Video und übrige Registeraufgaben bleiben offen.
