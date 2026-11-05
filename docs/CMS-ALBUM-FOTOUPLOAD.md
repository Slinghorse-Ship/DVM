# Fotos sicher einem Album hinzufügen

Unter **Website → Fotogalerie → Album** neue Bilder auswählen.
Mit JavaScript bis zu 50 Fotos nacheinander speichern; die Ansicht bleibt
bedienbar und ungespeicherte Angaben bleiben erhalten. **Upload abbrechen**
stoppt die Auswahl. Höchstens sechs private Öffnen-Links pro Seite, mit direkter
Seitenwahl. Nicht bestätigte Speicherung mit **Uploads prüfen** ausschließlich
lesen. Erst eine genaue aktuelle Albumfassung gibt weitere Uploads frei; bei
einer Folgeänderung **Album neu laden** bewusst auswählen. Ungespeicherte
Eingaben behalten ihren vorhandenen Verwerfenschutz. Die gemeinsame
lokale Umwandlung unterstützt Apple HEIC/HEIF und die unterstützten Kamera-RAW-
Formate; der freiwillige Fotoeditor bleibt verfügbar.

Ohne JavaScript einzelne oder bis zu zehn JPG-/PNG-/WebP-Dateien auswählen und
**Bilder hochladen** drücken; zusammen höchstens 40 MB, pro Bild höchstens 12 MB.
Die vollständige Auswahl und genau eine neue private Albumfassung werden
gemeinsam gespeichert. Eine ungültige Datei oder überschrittene Grenze nimmt
den gesamten Vorgang zurück. Die Fehlermeldung und das Albumformular bleiben
sichtbar; eine korrigierte neue Auswahl lässt sich dort direkt speichern.
Dateien nach einem Fehler erneut auswählen. Ein Album umfasst höchstens 100
Bilder. Lange Titel und Controls passen auch bei 200 % Zoom auf ein Smartphone.

Ein neues Foto und seine Zuordnung zum Album werden gemeinsam gespeichert.
Ändert jemand das Album während der Verarbeitung, bleibt diese Änderung erhalten
und der Upload meldet den Versionskonflikt. Das abgewiesene neue Foto hinterlässt
keinen privaten Restbestand und verbraucht kein Fotokontingent. Bereits gespeicherte
Bilder, Beschriftungen, Tags, Reihenfolge und frühere Veröffentlichungen bleiben
erhalten. Eine gewöhnliche Anfrage benötigt keine selbst festgelegte Bildkennung.

Der Fachvertrag `native-album-upload` prüft 102 Fälle mit tatsächlichen lokalen
HTTP-Multipart-Uploads und unabhängig geprüften gespeicherten PNG-Pixeln. Eine
zweite MySQL-Verbindung hält die Vereinssperre, während der Upload seine vier
Varianten erzeugt; anschließend wird eine eigene gleichzeitige Albumänderung
wirklich gespeichert. Der folgende HTTP-409-Konflikt rollt Bildzeile und neue
Dateien zurück. Die Quotendifferenz bleibt null. CSRF, alte Versionen, ungültige
Bilddaten, doppelte oder ungültige Kennungen, fehlende Bearbeitungsrechte und
fremde Alben werden ohne Teiländerungen abgewiesen. Sämtliche früheren
Datenbankzeilen und Revisionen außerhalb des ausdrücklich bearbeiteten eigenen
Testalbums sowie dessen frühere Veröffentlichung und Originaldateien bleiben
exakt erhalten. Keine neue DDL, kein Altimport und keine externen Anfragen.

Die zusätzlichen echten Mehrfachanfragen prüfen zwei und zehn akzeptierte
Dateien, ungültige zweite Bilder, elf Dateien, eine einzelne Kennung für einen
Stapel, eine alte Version, falsche Caption-Typen, CSRF und Leserrechte. Ein
wirklicher 45-MiB-Stapel nimmt auch die vorher verarbeiteten Bilder zurück;
ein Album mit 99 tatsächlich gespeicherten Fotos weist zwei weitere Bilder
ohne Teiländerung ab und nimmt genau das letzte freie Bild an.

62 tatsächliche Browserverträge beider Anwendungen prüfen den JavaScript-freien
Mehrfachweg bei 320/390/820 Pixeln, Touchzielen und 200 % Zoom sowie die sichtbare
Fehlerkorrektur mit erfolgreicher erneuter Auswahl. Sechzehn neue private Fotos,
genaue Dateinamen, Reihenfolge, unabhängige Geometrie/Farbpixel, vier Varianten
und Quote sind bestätigt. Jede frühere Zeile, Revision, Veröffentlichung und
Datei sowie alle eigenen Ausgangsbytes bleiben erhalten.

Alle acht betroffenen Fachsuiten bestehen. Die 25 tatsächlichen Kamera-/Album-
Browserverträge bestehen erneut in beiden Anwendungen: sechzehn neue private
Fotos, darunter je drei wirkliche Albumzuordnungen, und zwei neue Dokumente.
Die unabhängige Prüfung erhält jede frühere Datenbankzeile und Bilddatei.

Identifizierte Einzeluploads bestätigen nur das genaue neue Foto und die
gemeinsam gespeicherte Albumfassung. Der private GET-/HEAD-Prüfweg gleicht die
ursprüngliche Fassung und den exakten Anhang mit der unveränderlichen Revision
ab. Nur der ursprüngliche eigene Nutzer mit aktuellen Bearbeitungsrechten erhält
das Ergebnis; POST wird vor jeder Verarbeitung abgewiesen. Eine spätere Änderung
bleibt erkennbar und ersetzt die ursprüngliche Uploadfassung nicht. Die Prüfung
schreibt nichts, wiederholt keinen Upload und ist nicht zwischengespeichert.
46 tatsächliche App-Verträge bestätigen dies in beiden Anwendungen, einschließlich
korrekt übersetzter privater Links und einer wirklichen späteren Albumänderung.
Zwei neue Fotos mit unabhängig geprüften Pixeln und genau vier Varianten erhalten
alle früheren Zeilen, Revisionen, Veröffentlichungen und Dateien.

113 tatsächliche Browserverträge prüfen die kompakte Albumbedienung beider
Anwendungen bei 320/390/820 Pixeln und 200 % Zoom. Tatsächlich gespeicherte
Uploads vor verlorener Bestätigung, korrigierbarer Lesefehler, Abbruch ohne
Folgeupload, genaue aktuelle und später veränderte Albumfassungen sowie frühe
Dateiauswahl vor dem echten Uploadasset sind bestätigt. Titel, Kurzbeschreibung,
RichText und ursprüngliche Bildbeschriftung/Tags bleiben ungespeichert erhalten.
Zweiundzwanzig neue private Fotos, vierundzwanzig neue private Revisionen und
alle früheren Zeilen/Revisionen/Veröffentlichungen/Dateien unabhängig geprüft.
104 Mediathek-, 25 Kamera- und 62 JavaScript-freie App-Verträge bestehen erneut.

Die Album-Bildliste zeigt sechs Fotos pro Seite, mit direkter Seitenwahl oben
und unten. Reihenfolge, Beschriftungen und Tags bleiben erhalten; Pfeile beziehen
sich auf das vollständige Album. Gewöhnliche Speicherungen kehren zur gewählten
Bilderseite zurück. **Albumfotos anzeigen** öffnet nach bestätigten Uploads
bewusst die passende neue Bilderseite und schützt ungespeicherte Eingaben.

**Bilder aus der Mediathek hinzufügen** nutzt dieselbe vollständige Auswahl wie
der Seiteneditor: mit JavaScript Suche, sechs Treffer bzw. vier ausgewählte
Fotos pro Seite und Undo, ohne JavaScript eine sechszeilige Mehrfachauswahl.
Vorhandene Albumfotos sind ausgeschlossen. Der geschlossene Bereich lädt keine
Vorschaubilder und liest keine Variantenbeschreibungen des gesamten Bestands.
Eine abgewiesene native Zuordnung erhält gültige eigene Auswahlen zur Korrektur.

81 gemeinsame Fachverträge prüfen alle 100 Originalfotos auf 17 Seiten,
Rollen, fremde Vereine, genaue globale Verschiebepositionen und reine Lesewege.
Eine tatsächliche vollständige Albumansicht mit 1.006 Bibliotheksfotos und
21.427.970 Bytes weiteren Entwurfsinhalten benötigt 4 MiB unter einem echten
16-MiB-Prozesslimit; mehr als 50 MiB ungenutzte Variantenmetadaten bleiben in
der Datenbank. 85 tatsächliche App-Verträge beider Anwendungen mit und ohne
JavaScript bei 320/390/820 Pixeln und 200 % Zoom erhalten alle Originale.
Vier private Speicherungen und zwei tatsächlich abgewiesene Zuordnungen geprüft;
Originalpixel, frühere Veröffentlichung und sämtliche vorherigen Zeilen/Dateien
bleiben erhalten. 216 gemeinsame Seitenauswahl-, 115 kompakte Albumupload- und
64 JavaScript-freie Albumupload-Verträge bestehen erneut.

Die Albumübersicht zeigt höchstens 25 Alben. Die direkte Seitenwahl oben und
unten erreicht den vollständigen Bestand; Suche berücksichtigt Titel, Adresse,
Kurztext, Bildbeschreibungen und Schlagwörter als literalen Unicode-Suchtext.
Statusfilter unterscheiden Entwürfe und veröffentlichte Alben mit neuen privaten
Änderungen. Kurze Zeilen und 44-Pixel-Bedienung passen auch bei 200 % Zoom ohne
seitliches Scrollen. Sortieren kehrt zur gefilterten Übersichtsseite zurück.

Sortierung liest ausschließlich kleine ursprüngliche Schlüssel. Veröffentlichte
Titel/Positionen und numerische/gemischte Titel behalten die bisherige Reihenfolge;
private Änderungen werden dabei nicht veröffentlicht. Suchtexte werden pro Album
verarbeitet, vollständige Seiten-/Revisions-/Varianteninhalte nicht geladen.
46 Fachverträge mit 505 Alben und echter Übersicht samt Sortierung unter einem
16-MiB-Prozesslimit (4 MiB bei 21.426.035 Bytes weiteren Entwürfen) bestehen.
87 tatsächliche App-Verträge mit und ohne JavaScript prüfen alle 53 eigenen
Alben, Caption-/Statusfilter, 320/390/820 Pixel, 200 % Zoom, echte globale
Seitengrenzen und abgewiesene alte Stände. Jede frühere Seite/Fotodatei/Revision/
Veröffentlichung bleibt bytegleich; vier eigene unabhängige Sortierfassungen
ändern keinen Inhalt. 85 vollständige Album-Bildlistenverträge bestehen erneut.

Die öffentliche Galerie zeigt sechs veröffentlichte Alben mit jeweils drei
Originalvorschauen. **Alle Bilder** öffnet das vollständige Album mit sechs Fotos
pro Seite. Die direkte Auswahl oben und unten erreicht jedes Original ohne lange
Bildlisten. Die Lightbox unterstützt Tastatur; ohne JavaScript führen eigene
Links zum großen Originalbild. Private Änderungen an Titel, Vorlage, Beschriftung,
Alttext oder Credit ersetzen keine frühere Veröffentlichung.

Die öffentliche Auswahl liest nur kleine ursprüngliche Sortierschlüssel und
jeweils eine Albumfassung. Eine zweite vollständige Fotoausgabe vor der eigentlichen
Galerie entfällt. Seitenangaben werden geprüft und auf die letzte tatsächliche
Seite begrenzt; kanonische URLs und integrierte Formulare behalten diese Seite.
Vereinsnamen brechen in schmalen Kopf-/Fußbereichen um; der Kopfbereich überdeckt
bei Smartphone-/Tabletbreiten keine Seitenwahl mehr.

35 Fachverträge prüfen alle 100 Originale auf 17 Seiten und tatsächliche 4-MiB-
Website unter 16 MiB bei 21.426.035 Bytes weiterer veröffentlichter Originale.
131 tatsächliche App-Verträge beider öffentlichen Zugänge mit/ohne JavaScript,
320/390/820 Pixeln und 200 % Zoom, Lightbox/Tastatur, echter PNG-Ausgabe, HEAD und
falschen Seitenangaben bestehen. Jede vorherige Datenbankzeile/Revision/Fassung/
Fotodatei und jeder Quellpixel bleiben erhalten; kein Schreib- oder Außenaufruf.
13 betroffene native Suiten inklusive 99 nun registrierter Galeriefälle bestehen.

Gemeinsame öffentliche Formular-/UI-Grundlage, vollständiger Medienlebenszyklus
und übrige Register-/Geräteabnahme bleiben offen.
