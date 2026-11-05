# Öffentliche Feed-Verträge

RSS, Kalender und Veranstaltungs-JSON verwenden einen starken SHA-256-ETag
über die tatsächlichen öffentlichen UTF-8-Bytes. `Cache-Control: public,
max-age=0, must-revalidate` verlangt vor erneuter Verwendung eine Abfrage:
eine veröffentlichte Änderung oder Absage wird sofort berücksichtigt. Eine
private Bearbeitung verändert die öffentliche Fassung und ihren ETag nicht.

`If-None-Match` unterstützt schwache Tags, gültige Listen und `*` für eine
vorhandene Darstellung. Ein Treffer liefert 304 ohne Body; HEAD liefert die
gleichen Metadaten und die genaue GET-Bytezahl ohne Body. Ungültige Header
werden nicht als Treffer gewertet. Fehlende oder private Termine bleiben 404,
auch bei `*`. Ein bloßes Veröffentlichungsdatum wird nicht als verlässlicher
Änderungszeitpunkt für die gesamte Darstellung ausgegeben.

Nur `/feeds/events.json` und `/feeds/veranstaltungen/` erlauben anonyme
Browserabrufe von anderen Websites. CORS erlaubt GET, HEAD und OPTIONS sowie
`If-None-Match` und macht den ETag lesbar. Es erlaubt keine Zugangsdaten.
OPTIONS liefert 204 ohne Body, Content-Length oder Darstellungs-ETag. Der
Produktionscontroller behält seine HTTPS-/Hostprüfung und lässt OPTIONS nur
für diese beiden öffentlichen JSON-Wege durch. Private Vorschauen, Manager,
RSS und andere Endpunkte bekommen diese Freigabe nicht.

Die vorhandene JSON-Version 1, nativen IDs, Kalender-UIDs und ursprünglichen
Veröffentlichungen bleiben erhalten. Die benötigten nativen Feed-Auswahlen,
Zeitanker und Downloads sind geprüft; die gesamte Produktabnahme läuft weiter.

## Große Ausgaben und Kalenderdownloads

Beide öffentlichen HTTP-Einstiege schreiben den vollständigen Feed zunächst
in einen privaten temporären Stream. PHP hält höchstens 100 Metadaten und ein
Inhaltsoriginal; JSON und ICS sammeln keine vollständige Inhaltsliste mehr im
Speicher. RSS all.rss.xml erhält weiterhin sämtliche eigenen öffentlichen
RSS-Inhalte, News und kombinierter RSS die neuesten 50 passenden Originale.
Veröffentlichungszeit und native ID ordnen gleichzeitige RSS-Einträge stabil;
Termine verwenden UTC-Beginn und native ID, undatierte Originale folgen zuletzt.
JSON enthält sie mit null-Zeit, ICS enthält ausschließlich datierte Termine.

Die vollständige Darstellung muss die erneute aktuelle Rechte-, Vereins- und
Generationsprüfung bestehen, bevor HTTP-Bytes gesendet werden. Änderungen
starten die Darstellung neu; ein Vereinswechsel bricht sie ab, auch bei gleichen
Generationszahlen. ETag und Content-Length entstehen über alle tatsächlichen
Bytes. GET sendet anschließend 64-KiB-Blöcke; HEAD und 304 laden den Body nicht
wieder in den PHP-Speicher. Temporäre Dateien werden danach geschlossen und
entfernt. Benötigt wird ausreichender beschreibbarer temporärer Speicher für
die vollständige Ausgabe; es gibt keinen dauerhaften öffentlichen Feedcache.
Die internen bisherigen Stringmethoden bleiben für ihre bestehenden Aufrufer
erhalten; wer einen großen String ausdrücklich abruft, benötigt dessen Speicher.

„Termin speichern“ liefert für den gewählten ursprünglichen Termin eine echte
Attachment-Datei `termin-{native-id}.ics`. Die alten Aliaswege bleiben gültig.
Private, fremde, fehlende und undatierte Termine liefern 404 ohne Download oder
Darstellungs-ETag; `If-None-Match: *` macht daraus keine leere Kalenderdatei.
HEAD enthält dieselben Download-/Darstellungsheader ohne Body. Gespeicherte
UTC-Anker unterscheiden beide Stunden der Herbstumstellung; Frühlingslücken
und ungültige Schalttage werden beim Speichern abgewiesen. Ein Ende wird nicht
erfunden. Auch große Unicode-Kalendertexte werden ohne Zeichenarray bei
höchstens 75 Bytes gefaltet und korrekt entfaltet.

63 zusätzliche Verträge bedienen 246 eigene öffentliche Originale einschließlich
180 großer Termine und 60 RSS-only-Beiträge über einen tatsächlichen HTTP-
Prozess mit `memory_limit=16M`. Die vollständigen JSON- und ICS-Dateien selbst
sind größer als 16 MiB; kein Eintrag wird abgeschnitten. Der gemessene PHP-
Spitzenspeicher beträgt 2 MiB für das 17.603.216-Byte-JSON und 4 MiB für den
18.939.354-Byte-Kalender bei 23.499.350 Bytes ursprünglichen öffentlichen Payloads. Unabhängige XML-/JSON-
und Kalenderprüfung, Cursorgrenzen/gleichzeitige Zeitwerte, Sommer-/Winter-
und Schaltjahrzeiten, GET/HEAD/304, temporäre Bereinigung und Originalbytevergleich
bestehen. Der erweiterte Browserlauf hat 108 Verträge einschließlich vier echter
mobiler ICS-Downloads bei 390/320 Pixeln in beiden Zugängen; die 25 ursprünglichen
CORS-Browserverträge bestehen ebenfalls. Elf betroffene native Suiten einschließlich
51 tatsächlicher Installer-/Upgrade- und 46 Produktionslaufzeit-Verträge sowie
6 Inventar-/9 Paketprüfungen bestehen. Es gibt keine externen Aktionen.

## Veröffentlichte Bilder und Terminangaben

JSON ergänzt `image` für das ursprüngliche Titelbild und `images` für alle
verfügbaren Bilder in der gespeicherten Reihenfolge. Jedes Bild enthält `url`,
`mime`, `width`, `height`, `bytes` und den ursprünglichen Alternativtext `alt`.
Die URL verwendet ausschließlich die konfigurierte Website-Origin und die
anonyme native Route `/media/{id}/large`. MIME, Maße und Bytezahl entsprechen
der tatsächlichen öffentlichen Bilddatei. Private Dateipfade und Verwaltungs-
metadaten werden nicht ausgegeben. Ohne Bild ist `image` null und `images` leer.
Der interne bestehende Drei-Argument-Aufruf ohne Mediendienst erhält weiterhin
seine bisherige Feldstruktur; beide HTTP-Einstiege verwenden den Mediendienst.

RSS erhält genau ein RSS-2.0-`enclosure` mit URL, MIME und Bytezahl des ersten
gespeicherten Bildes. Wenn dieses Original aktuell fehlt, entfällt das Enclosure;
JSON lässt das Titelbild null und erhält die übrigen verfügbaren Bilder. Ein
späteres Bild wird nicht stillschweigend zum ursprünglichen Titelbild gemacht.
RSS-only-Beiträge bleiben einschließlich ihrer Bilder im gewählten Kanal.

Der kombinierte RSS-Feed verwendet die tatsächliche gespeicherte Zeitzone,
Beginn, Ende, Ort, Anschrift, Status und Eintritt. Außerhalb von Europe/Berlin
steht die Zeitzone ausdrücklich dabei. Abgesagte und ausverkaufte Termine
geben keinen Ticketkauf-Link aus. Private Titel-/Zeit-/Bildänderungen verändern
die öffentliche Fassung und ihren Validator nicht. Die Veröffentlichungsgeneration
wird nach der vollständigen Bild-/Textausgabe erneut geprüft; ein gleichzeitiger
Rückzug startet die Darstellung neu. Aktuelle Leserechte bleiben erforderlich.

58 zusätzliche MySQL-/Darstellungs-/HTTP-Verträge prüfen Originalbilder,
private und fremde Dateien, fehlende Titelbilder, Terminangaben und einen
tatsächlichen Rückzug auf einer zweiten MySQL-Verbindung während der Ausgabe.
96 zusätzliche Browserverträge lesen beide Zugänge von einer unabhängigen
lokalen Website bei 390 und 320 Pixeln: originale Bildpixel/SHA, Alttexte,
Reihenfolge, tatsächliche HEADs und acht bedingte CORS-Preflights. Die ursprünglichen
25 CORS-Browserverträge bestehen weiterhin. Alle ursprünglichen Datenbank-
und Bildbytes bleiben erhalten; keine externe Veröffentlichung wird ausgeführt.
Neun betroffene native Suiten einschließlich 51 tatsächlicher Installer-/Upgrade-
und 46 Produktionslaufzeit-Verträge sowie 6 Inventar-/9 Paketprüfungen bestehen.

138 Prüfungen laufen über echte lokale HTTP-Verbindungen und MySQL, darunter
Absagen, unveränderte Originale und Rechteentzug. 25 tatsächliche Prüfungen
aus einem Browser auf einer anderen lokalen Website lesen sowohl den
integrierten Manager als auch die native Demo, einschließlich vier echter
Preflights. Sie führen keine fachlichen Schreibvorgänge oder externen Aktionen
aus. Acht zusätzliche Installer-Fälle prüfen den tatsächlich installierten
Produktionscontroller bei OPTIONS, POST, fremdem Host und fehlendem HTTPS.

```sh
php vereinsverwaltung/tests/ci-native.php native-feed-http native-feeds native-public-router native-news-channels native-indexing
php vereinsverwaltung/tests/ci-native.php native-feed-images native-event-catalogue
php vereinsverwaltung/tests/ci-native.php native-feed-stream
KH_NATIVE_TEST_CONFIG=/workspace/.setup/native-demo.json php vereinsverwaltung/tests/support/feed-images-browser-fixture.php prepare
KH_NATIVE_TEST_CONFIG=.local/native-cms-integration/integrated-manager.json php vereinsverwaltung/tests/support/feed-images-browser-fixture.php prepare
node vereinsverwaltung/tests/native-feed-images-browser.mjs
KH_NATIVE_TEST_CONFIG=.local/native-cms-integration/integrated-manager.json php vereinsverwaltung/tests/support/feed-http-browser-fixture.php prepare
node vereinsverwaltung/tests/native-feed-http-browser.mjs
```

Die Browserhilfen akzeptieren ausschließlich die eigenen synthetischen
Einrichtungen. Ihre privaten Prüfsummen liegen außerhalb von Git. Die Bildhilfe
legt ausschließlich eigene Testbilder und einen eigenen Termin mit privater
Folgebearbeitung an; sie verändert keinen vorhandenen Termin.
