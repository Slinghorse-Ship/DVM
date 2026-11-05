# Große Kameraoriginale automatisch vorbereiten

Der Server-Kameraweg akzeptiert HEIC/HEIF und die vorhandenen RAW-Formate jetzt
bis 256 MiB je Original, passend zur bisherigen Browservorbereitung. Bis 512 MiB
Originale können gemeinsam ausgewählt werden. Die bisherige 40-MiB-Grenze gilt
weiter für normale Rasterdateien beziehungsweise die vorbereiteten Fotoausgaben;
sie darf nicht mehr die unkomprimierte RAW-Eingabe verhindern. Stückzahl, private
Speicherquote und weitere ursprüngliche Fachregeln bleiben erhalten.

Ein eigenes tatsächlich decodierbares 48-Megapixel-DNG enthält 96.000.472 Bytes.
Es wurde zuvor mit „Kamerafoto bis 40 MiB auswählen“ abgewiesen. Der aktuelle
Codec bereitet genau diese Datei in einem kalten PHP-Prozess unter 64 MiB vor;
PHP liest die Originaldatei dabei nicht vollständig in den Arbeitsspeicher.
Gebündelte lokale Codecs erzeugen höchstens 1600 Pixel lange JPEG-Ausgaben.
Originalbytes und Ausgangsdatei bleiben unverändert. Der übliche Browserweg
bereitet dieselben großen Originale weiterhin lokal vor und behält seine eigenen
Originalreferenzen; seine Ausgabe bleibt auf 2400 Pixel begrenzt.

Die tatsächlichen vollständigen HTTP-Controller werden mit 128 MiB geprüft.
Die 64-MiB-Aussage betrifft den kalten Codec, nicht den vollständigen Controller.
Mit und ohne JavaScript speichern beide Oberflächen zwei echte 48-Megapixel-
Dateien gemeinsam. Die JavaScript-freien Multipartanfragen enthalten tatsächlich
192.000.944 Quelldateibytes. Ihre genaue Wiederholung wird aus den unveränderten
lokalen Originalen gestreamt, einschließlich des ursprünglichen Formularschlüssels
und aller eingereichten Felder. Sie erzeugt keine zweite Ausgabe, Revision oder
Fotokopie. Die Browserwege speichern ihre tatsächlich vorbereiteten JPEG-Dateien.

Der alte Rasterstapeltest bleibt unverändert wirksam: fünf normale 9-MiB-Dateien
werden bei 40 MiB abgewiesen, mit bestätigtem Zurückrollen bereits vorbereiteter
Kopien. Ein später abgewiesener RAW-Zuordnungsprozess rollt die gesamte Auswahl
zurück. PHP und Node prüfen die Kamera-Eingabegrenze unabhängig. Der Photoshop-
vergleichbare optionale Editor, Rechtevorgaben und Fotoauswahl benötigen keinen
zusätzlichen Schritt für die neuen Größen.

Neue Installationen und Bündel erhalten `upload_max_filesize=256M`,
`post_max_size=520M`, `max_file_uploads=50`. Die zusätzlichen 8 MiB über dem
512-MiB-Dateibudget lassen Platz für Formularfelder und Multipartgrenzen.
Bestehende ausdrückliche Hostingdateien werden weiterhin erhalten. Die tatsächlichen
Hostingwerte entscheiden über bereits eingerichtete Server; passende Werte
gehören dort zur PHP-Konfiguration. Der eigene Cloud-Neustart hebt bekannte
kleinere Laufzeitwerte an und erhält höhere oder unbegrenzte eigene Werte.
Die eigene tatsächliche Laufzeit bestätigt 256M/520M/50 bei 128M Arbeitsspeicher.

61 Fach-/Originalprüfungen und 48 tatsächliche Browserprüfungen bestehen: sechs
wirkliche POSTs mit vier großen RAW-Multipartanfragen und zwei vorbereiteten
Browseranfragen, 24 Schriftansichten bei 320/390/820 Pixeln und 100/200 Prozent.
Vier neue Ausgaben mit acht Fotos bleiben nach genauer Wiederholung vier Ausgaben
mit acht Fotos. Frühere vollständige Medien-/Ausgabenzeilen und private Dateien
sowie alle unbeteiligten Fach-/Queue-/Rechtetabellen bleiben unverändert.

Acht unmittelbar betroffene native Gruppen sind grün. Die getrennte vollständige
Medienabnahme mit neun weiteren Gruppen, Galerie-/Retuschebrowsern sowie 98
Fotoimport- und 111 Fotoeditorprüfungen ist unter
[Mediengrundfunktionen](MEDIEN-GRUNDFUNKTIONEN-ABNAHME.md) belegt. Keine neue
Tabelle; Kommunikation bleibt 17. Generation 2026101510, 238 registrierte Suiten.
