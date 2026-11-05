# Große Handyfotos einfach auswählen

Die vorhandene gemeinsame Fotoauswahl bereitet große JPEG-, PNG- und WebP-Fotos
automatisch auf dem eigenen Gerät vor. Es gibt keinen zusätzlichen Freigabeschritt
und keine neuen Pflichtangaben. Einzel- und Mehrfachauswahl, bestehende HEIC-/RAW-
Konvertierung und der freiwillige Fotoeditor verwenden denselben Ablauf.

Die tatsächlichen Dateibytes entscheiden über das Format. Große Bilder werden
an die vorhandenen Seiten- und Bytegrenzen angepasst. Standardmäßig gilt eine
längste Seite von 2400 Pixeln und höchstens 1,7 MB; der private Chat verwendet
2000 Pixel. Verkleinern hält Seitenverhältnis, ursprüngliche Farben und transparente
Pixel fest. JPEG-Ausrichtung wird beim Vorbereiten einmal angewendet. Format und
vorbereiteter Dateiname passen zusammen, auch bei falsch deklarierter Endung.

Bereits passende kleine Dateien bleiben unverändert: dieselben Dateiobjekte,
Bytes, Namen und Zeitstempel. HEIC-/RAW-Namenskorrekturen folgen weiterhin dem
bereits vorhandenen echten Dateityp. PDF und sonstige direkte Dokumenttypen werden
durch die normale Rastervorbereitung nicht verändert.

Die Verarbeitung läuft im vorhandenen lokalen Worker. Währenddessen verhindert
der vorhandene Ablauf einen unvollständigen Upload und stellt danach die bisherigen
Eingabe- und Knopfzustände wieder her. **Abbrechen** beendet auch eine laufende
gewöhnliche Fotovorbereitung. Eigener Text bleibt erhalten, die ursprüngliche
rohe Referenz wird nicht überschrieben; es wird keine vorbereitete Auswahl erneut
ausgelöst und nichts hochgeladen. Teilfehler bleiben wie bei HEIC/RAW sichtbar.

Die gemeinsamen Eingaben umfassen Website/Galerie/Kommunikation, Belege,
Dokumente, Inventar, Chat, weitere Belegbereiche, Signaturen und Logos.
Die vorhandenen optionalen Rechteprüfungen bleiben getrennt vom bloßen Upload.
Diese Vorbereitung braucht JavaScript. Die vorhandenen direkten Serverwege
für unterstützte Originale bleiben einschließlich ihrer überprüften Grenzen
verfügbar; ohne JavaScript wird keine neue Browserkonvertierung behauptet. Fehlen
die lokalen Worker-/Canvas-APIs, behalten normale unterstützte Bilder ihren
bisherigen direkten Originalweg, statt durch die neue Vorbereitung blockiert
zu werden.

98 tatsächliche Browserprüfungen bestehen. Synthetische große Rasterbilder
über 18 Megapixel werden anhand unabhängig ausgelesener Farben, transparenter
Pixel, tatsächlicher MIME-Typen und Größen geprüft. Ein gewöhnliches JPEG mit
EXIF-Orientierung 6 wird richtig ausgerichtet. Kleine Originale bleiben bytegleich.
Eine reale gemischte Multipartübertragung erhält fünf JPEG-/PNG-/WebP-/HEIC-/RAW-
Dateien und den genauen eigenen Text. Zehn unterschiedliche gemeinsame
Eingabetypen verarbeiten ein echtes großes Foto an ihre jeweilige Grenze.
Laufender Abbruch, vorhandene RAW-Abbrüche, verborgene Felder, Teilfehler und
unveränderte Kameraoriginale sind geprüft. 111 bestehende Fotoeditorprüfungen
bestätigen Auswahl, Bearbeitung, Export und genau einen bewusst ausgelösten Upload.

Die tatsächlichen Presse-/Chat-Browserwege und betroffenen nativen Upload-,
Original-, Ausrichtungs-, Metadaten- und optionalen Rechteprüfungen bestehen
zusätzlich. Keine neue Tabelle oder Modulversion: CMS 17, Chat 2,
Kommunikation 14, Paketgeneration 2026101313; weiterhin 183 registrierte Suiten.
Kein neuer vollständiger Lauf aller 183 Suiten und kein Gesamtabschluss behauptet.

```sh
bash tools/ci/local-native.sh --core native-photo-import native-image-orientation native-editor-upload native-album-upload native-page-photo-upload native-page-photo-status native-upload-outcomes
# Die gemeinsamen Foto- und Editor-Browserprüfungen sind in --browsers enthalten.
```
