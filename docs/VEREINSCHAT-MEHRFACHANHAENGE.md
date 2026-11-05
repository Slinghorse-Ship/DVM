# Mehrere private Chat-Anhänge

Eine Nachricht im Vereinschat kann ein bis zwanzig Fotos, PDFs oder UTF-8-
Textdateien enthalten. Die Auswahl gehört zu einem gemeinsamen menschlichen
Nachrichtentext, Antwortbezug und Sendevorgang. Einzeldateien und bisherige
Nachrichten bleiben weiterhin erreichbar. Es gibt keine vorgefertigte Antwort.

Große normale Handyfotos, HEIC und unterstützte RAW-Dateien werden über den
gemeinsamen lokalen Fotoimport vorbereitet. Auch das mehrfache Chat-Dateifeld
verwendet dabei seine kleinere Zielgröße von 2000 Pixeln. Kleine JPEGs mit EXIF-
Ausrichtung werden serverseitig richtig gedreht. Die bestehenden Dateigrenzen
bleiben acht MiB je Datei und vier Megapixel je vorbereitetem Chatbild. Bilder
werden als saubere Pixeldateien gespeichert; PDF und Text bleiben bytegleich.

Die Auswahl zeigt die tatsächliche Dateianzahl und eine kurze begrenzte Liste.
Eine empfangene Nachricht zeigt zunächst vier Dateikarten, danach eine eigene
aufklappbare Auswahl. Auch große Gruppen haben einen begrenzten Bild-/Dateibereich,
damit sie den Gesprächsverlauf nicht über viele Bildschirmseiten ausdehnen.
Jede einzelne Datei lässt sich unabhängig herunterladen; Bildvorschau und Download
prüfen die aktuelle eigene Unterhaltung und die konkrete Position der Datei.

Die ganze Auswahl wird atomar gespeichert. Ist eine Datei ungültig oder ein
Antwortbezug nicht mehr berechtigt, entstehen weder eine Teilnachricht noch
Teilbenachrichtigungen. Unangenommene temporäre Kopien werden entfernt. Ein
bereits angenommener COMMIT mit verlorener Antwort behält dagegen alle Dateien.
Eine Wiederholung mit derselben ursprünglichen Kennung bestätigt dasselbe Original;
anderer Text, Reihenfolge, Namen oder Dateiinhalte dürfen sie nicht übernehmen.
Auch zwei tatsächlich parallele PHP-Prozesse erzeugen nur eine vollständige Gruppe.

Mit JavaScript bleiben vorbereitete Dateien und persönlicher Text nach verlorenem
HTTP-Ergebnis für den ursprünglichen Sendevorgang erhalten. Ohne JavaScript ist ein
normaler Multipart-Mehrfachupload möglich; bei einer abgewiesenen Auswahl bleiben
Text, ursprüngliche Kennung und Antwortbezug erhalten. Dateien müssen dann erneut
ausgewählt werden. HEIC-/RAW-Vorbereitung benötigt den vorhandenen lokalen
Browserworker. Die PHP-Uploadkonfiguration des Hosters gilt weiterhin für die
gesamte Anfrage; der ausgelieferte Installer verwendet 128 MiB und 50 Dateien.

32 Fachprüfungen, 87 tatsächliche Browserprüfungen und 21 zusätzliche Original-
prüfungen aus den vier Bedienabläufen bestehen. Acht Formularaktionen, 24 Handy-/
Tablet-/200-Prozent-Schriftansichten, gemischte tatsächliche JPEG-/PNG-/HEIC-/RAW-/
Textübertragung, private HEAD/Downloadwege und exakte angenommene HTTP-Wiederholung
sind geprüft. Frühere vollständige Datenbankoriginale und private Dateibytes
bleiben dabei exakt. Bestehende Gesprächssuche, Antwortkorrektur, Gruppenrechte,
Umfragen und tatsächlicher Geräte-Push sind zusätzlich geprüft; der gemeinsame
Fotoimport besteht weiterhin 98 Browserprüfungen.

Chat 3 ergänzt eine zunächst leere Tabelle für zusätzliche Dateien. Die bisherige
erste Dateizeile bleibt unverändert. Kommunikation 15, CMS 17, Auth 6,
Delivery 11, Identity 4 und Workflows 2 bleiben erhalten. Paketgeneration
2026101315; 185 native Suiten registriert. Kein vollständiger neuer Lauf aller
185 Suiten oder reale Geräte-/App-SDK-Abnahme wird behauptet.

```sh
DVM_CHAT_FILES_BROWSER=1 bash tools/ci/local-native.sh --core native-chat-files
DVM_CHAT_CATALOGUE_BROWSER=1 DVM_CHAT_PUSH_BROWSER=1 bash tools/ci/local-native.sh --core native-chat-catalogue native-chat-push native-chat native-chat-workflows
```
