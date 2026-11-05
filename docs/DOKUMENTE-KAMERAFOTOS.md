# Kameraquellen in Dokumenten und Anhängen

Neue HEIC/HEIF- und RAW-Auswahlen werden vor der bisherigen Dateiprüfung mit dem
vorhandenen lokalen Kameracodec vorbereitet. Die Dokumentdatenbank erhält eine
JPEG-Ableitung mit passender `.jpg`-Endung. Das gilt für zentrale Dokumente,
Dateiversionen, Rechnungseingang, Inventarrechnungen, Inventarfotos, manuelle
Finanzbelege und GEMA-Anhänge. Die Chat-Anhangverarbeitung hat denselben
Kameraeingangsweg und behält ihre eigene Rasterprüfung. Logos und
Unterschriften unterstützen zusätzlich den Serverweg ohne JavaScript; ihre
bisherige sichtbare Browservorschau und ausdrückliche Bestätigung bleiben erhalten.
CSV-/Mitgliederimporte und ausdrücklich auf PDF beschränkte Nachweise werden
dadurch nicht zu Bildimporten.

| Bereich | Kameraquelle | Gespeicherte Ausgabe |
| --- | --- | --- |
| Dokumente, Dateiversionen, Rechnungseingang, GEMA, Finanzbeleg | bis 256 MiB je Quelle, 512 MiB zusammen | bisherige 10 MiB je Datei; Dokumentauswahl höchstens 10 Dateien und 20 MiB zusammen |
| Inventarfoto, älterer Vereinschat, Logo, Unterschrift | dieselben Kameraeingangsgrenzen | bisherige 2 MiB je Datei und unveränderte fachliche Bildregeln |
| Nativer Chat | dieselben Kameraeingangsgrenzen | bisherige 8 MiB je Datei, höchstens 20 Anhänge, bestehende Raster-/Pixelprüfung |

Normale bereits zulässige JPEG-, PNG- und Dokumentdateien werden im neuen
Dokumentenadapter nicht neu encodiert. Alte Dateien und historische Revisionen
werden nicht verändert. Der native Chat behält seine bisherige obligatorische
Rasteraufbereitung auch für normale Bilder bei. Kameradateinamen und der
begrenzte TIFF/DNG-Headerprobe unterstützen auch eine DNG-Quelle ohne Endung.
Alle bisherigen decoderseitigen Zeit-, Pixel- und Ausgabebegrenzungen gelten weiter.

Die Quelldatei wird pfadbasiert geprüft und an den Decoder übergeben. PHP liest
nur die begrenzte JPEG-Ableitung in den Speicher. Dokumente speichern als
getrennte Herkunftsdaten den ursprünglichen Dateinamen, Quelltyp, Byteumfang und
SHA-256 sowie den SHA-256 der Ableitung. Der native Chat hält diesen Nachweis
privat neben der gespeicherten Ableitung. Diese Herkunftsdaten sind keine
dauerhaft herunterladbare RAW-Originaldatei.

Ein direkter Formularupload behält die Quelle nur während des PHP-Uploads.
Ein vorbereiteter JavaScript-Upload sichert Kameraquelle und Ableitung privat,
an Konto, Formularaktion, Feld und Token gebunden. Die Quelle bleibt für
Korrektur und Wiederaufnahme bis zum bestätigten Speichern, Entfernen oder
Ablauf nach einer Stunde verfügbar. Ein erfolgreicher Abschluss entfernt die
verbrauchten Stagingdateien. Eine beschädigte oder ausgetauschte Quelle sperrt
die Wiederaufnahme. Das Abrufen eines OCR-Snapshots verbraucht keine Datei.

`data-photo-server` verhindert bei den ausdrücklich servernormalisierten
Dokument-/Anhangfeldern eine vorzeitige automatische Browserkonvertierung.
Damit erhält der Server tatsächlich die ausgewählte Kameraquelle und kann ihre
Herkunft selbst prüfen. Das Attribut erweitert keine Serverberechtigung und
schaltet keine Uploadprüfung ab. Die vorhandenen Werkzeuge zum Bearbeiten und
Anlegen eigener Bildkopien bleiben getrennt erhalten.

Die vorhandene Installationskonfiguration schreibt `upload_max_filesize=256M`,
`post_max_size=520M` und `max_file_uploads=50`. Die zusätzlichen 8 MiB lassen
Platz für Multipartfelder bei einer 512-MiB-Auswahl. Die Anwendung prüft trotzdem
ihre kleineren Bereichs- und Ausgabelimits. Bei bestehenden Installationen muss
der Webspace diese Werte und passende vorgelagerte Requestgrenzen tatsächlich
übernehmen; eine Änderung innerhalb eines laufenden PHP-Requests kann die
bereits erfolgte HTTP-Uploadbegrenzung nicht ersetzen.

Die lokalen Prüfungen verwenden ausschließlich selbst erzeugte HEIC-/DNG-
Dateien, synthetische Konten und eine frische MySQL-Datenbank. Der große
48-Megapixel-Nachweis enthält 96.000.472 Bytes; ein kalter PHP-Prozess unter
64 MiB bereitet ihn zu einer 30.690-Byte-JPEG-Datei auf. Der echte
Produktionscontroller wird zusätzlich mit demselben strengen Speicherlimit und
den Uploadwerten aus `Installer\Deployment::UPLOAD_INI` geprüft. Der
CLI-Testserver erhält diese Werte als Startoptionen, da er `.user.ini` nicht
auswertet. Es werden keine Mails, Zahlungen oder fremden Dienste aufgerufen.

```sh
/workspace/.setup/bin/php vereinsverwaltung/tests/camera-document-uploads.php
bash tools/ci/local-native.sh --core native-camera-document-uploads
DVM_CAMERA_DOCUMENTS_BROWSER=1 bash tools/ci/local-native.sh --core native-camera-document-uploads
```

Die Browserprobe umfasst die echte Dokument-/Rechnungsoberfläche des integrierten
Managers und den nativen Chat durch beide vorhandenen Dispatcher. Die eigenständige
CMS-Oberfläche besitzt keinen alten Dokument-/Finanzroutenbereich; dafür wird kein
abweichender Ersatzcontroller als Nachweis verwendet.

## Abgenommene Auslieferungsfassung

Die getrennte, von der laufenden UI-Übersetzung unabhängige Paketfassung besteht
34 reine Kamerabedingungen, 32 bestehende native Chatdatei- und 31 Laufzeitgrenzen-
Bedingungen sowie 16 echte Datenbankbedingungen. Die vollständige Browserfolge
besteht 58 Bedingungen in sechs tatsächlichen Upload-/Chatwegen.

Geprüft sind der echte große Upload ohne JavaScript, Rechnungseingang,
zweiteilige Mehrfachauswahl, JavaScript-Quelldatei/Staging, Fehlerkorrektur mit
demselben Token ohne erneute Dateiübertragung und native Chat-Anhänge in beiden
Webversionen mit und ohne JavaScript. 429 ursprüngliche Tabellen, 230 vorhandene
Datenzeilen und sieben Schlüssel-/Dateiobjekte bleiben exakt; externe Anfragen
und JavaScriptfehler: null. Neue gespeicherte Herkunftsnachweise entsprechen den
tatsächlich ausgewählten Quelldateien. Der kalte große Decodierlauf benötigt
18 MiB PHP-Spitzenspeicher und liefert 30.690 JPEG-Bytes.

Paketgeneration 2026101541, 298 registrierte Kerngruppen. Diese Softwareabnahme
ersetzt keine physische Geräteprüfung oder Übernahme der Hostinggrenzen beim
Kunden.
