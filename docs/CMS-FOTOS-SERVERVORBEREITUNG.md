# Große Fotos ohne Browservorbereitung

Große neue JPEG-, PNG- und WebP-Fotos werden bei Bedarf auch auf dem Server
vorbereitet. Die Fotoauswahl bietet außerdem die bereits unterstützten HEIC-,
HEIF- und Kamera-RAW-Formate an. Kleine passende Quellen passieren die zusätzliche
Vorstufe bytegleich; die vorhandene sichere Medienspeicherung bleibt zuständig.

Einzel- und Mehrfachupload verwenden dieselben Rechte und Quoten wie bisher.
Eine aktivierte Rechteprüfung verhindert die einfache Fotoauswahl nicht.
Neue Fotos bleiben privat. Fehlgeschlagene atomare Zuordnungen hinterlassen
keine halben Medienbestände. Allgemeine Dateiversionen und die Zuordnung von
Dokument-/Chatdateien behalten ihre bestehenden Regeln.

## Hosting

Die optionale Servervorbereitung benötigt Node.js ab Version 22 und die
PHP-Funktionen proc_open, proc_get_status, proc_terminate und proc_close.
Node wird im Hosting-PATH gesucht. Alternativ legt der private Betreiberwert
VEREIN_PHOTO_NODE die ausführbare Datei fest. Uploads bestimmen diesen Pfad nicht.

Das Webspace-Paket enthält die beiden Foto-Serverprogramme, die lokalen Codecs
sowie Lizenz-, Herkunfts-, Quell- und Wiederaufbauunterlagen. Node selbst wird
vom Hosting bereitgestellt. Setup und privater Systemstatus zeigen die
Voraussetzungen zusammengeklappt an. Vorhandene Dateien gelten dort ausdrücklich
als ungeprüfte Voraussetzungen; sie erzeugen keinen falschen Laufzeitnachweis.
Kleine Fotos und die Browservorbereitung bleiben unabhängig davon nutzbar.

Die zusätzliche Rastervorstufe begrenzt Quellen auf 256 MiB, 64 Megapixel und
12000 Pixel je Kante. Mehrfachquellen haben ein 512-MiB-Budget. Das bestehende
40-MiB-Limit gilt weiterhin für vorbereitete atomare Bildgruppen. Die Vorstufe
orientiert JPEGs anhand EXIF und erhält PNG-/WebP-Alpha; Metadaten werden vor
der kanonischen Speicherung entfernt. Ein eigener hängender Decoder wird nach
45 Sekunden beendet und aufgeräumt.

Das PHP-Anforderungslimit begrenzt den Zend-Speicher. Der native Speicher des
HTTP-Prozesses und der separate Node-/Codec-Prozess kommen hinzu. Messungen
dieser Größen werden getrennt ausgewiesen. Die Node-Rastervorstufe begrenzt
WASM-Speicher auf 512 MiB und JS-Old-Space auf 128 MiB; ihr Diskcache ist aus.

## Prüfung

Die Gruppe native-raster-photo-fallback prüft erzeugte eigene Originale,
Kaltprozesse mit PHP 64 MiB, EXIF, Alpha, Quoten, Fehler, atomare Rücknahme und
Originalschutz. DVM_RASTER_PHOTOS_BROWSER=1 aktiviert beide Apps mit/ohne
JavaScript und absichtlich nicht verfügbarer Browserkonvertierung.
DVM_RASTER_WEBSPACE_HTTP=1 aktiviert zusätzlich den echten WebBundle-Bau,
eine frische HTTP-Einrichtung, persönliche Anmeldung und einen großen
No-JavaScript-Upload im tatsächlich installierten Produktionscontroller.

Der lokale vollständige Browser-Runner setzt beide Flags gemeinsam und führt
diese Gruppe einmal aus. DVM_RASTER_PROOF_DIR kann ein bestehendes eigenes
privates Verzeichnis für dauerhaft erhaltene HTTP-/Inputmanifest-Belege angeben.

## Tatsächliche Abnahme am 18. Oktober 2026

Die unveränderten Prüffassungen bestehen 251 Fach-/HTTP- und 78 Browserbedingungen
mit 20 tatsächlichen POSTs und 16 Schrift-/Breitenansichten. Ein echtes WebBundle
wurde per HTTP installiert, persönlich angemeldet und mit einer eigenen
192.072.924-Byte-PNG (8000 × 6000 Pixel) ohne JavaScript bedient. Alle 429 früheren
Tabellen, ursprünglichen Medien und Schlüssel bleiben geschützt. Es gibt keine
externen Versand-, Zahlungs- oder Veröffentlichungsaktionen.

Der installierte PHP-HTTP-Prozess meldet 30.412.800 Byte Zend-Spitze und
247.940 KiB native RSS-Spitze; das sind unterschiedliche Messgrößen. Die separaten
Decoder-Kaltprozesse benötigen bei den eigenen 48-MP-Testbildern rund 270 bis
848 MiB RSS. Vor allem die große kaum komprimierte RGBA-PNG benötigt entsprechend
Host-RAM zusätzlich zu PHP. Das PHP-Limit von 64 MiB ersetzt diese Voraussetzung
nicht. Browserkonvertierung und kleine passende Fotos benötigen diese optionale
Servervorstufe nicht. Die konkrete Auswahl ist keine allgemeine Durchsatzzusage.

256 Installer- und 55 Webspace-Installerbedingungen mit gesperrten Shellfunktionen
sowie elf angrenzende Fachgruppen bestehen ebenfalls. [Quellfassungen, getrennte
Messwerte und Log-SHA-256](pruefbelege/K27-FOTOS-2026-10-18.json).
