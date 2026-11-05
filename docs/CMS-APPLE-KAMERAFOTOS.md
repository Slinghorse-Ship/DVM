# HEIC, HEIF und Kamera-RAW

Fotos wie gewohnt einzeln oder gemeinsam auswählen oder in den Album-Upload
ziehen. Apple HEIC/HEIF und unterstützte Kamera-RAW-Dateien werden auf dem Gerät
automatisch in JPEG umgewandelt. Währenddessen erscheint ein kurzer Fortschritt
mit **Abbrechen**. Anschließend nutzt jedes Modul seinen bisherigen Uploadweg.
Für die Umwandlung sind keine Rechte-, Credit- oder Lizenzangaben nötig.
Die [optional aktivierbare Bildprüfung](CMS-BILDPRUEFUNG.md) bleibt unabhängig.

Die gemeinsame Dateiauswahl gilt in beiden Redaktionen und im Hauptmanager:
Mediathek, Alben, Beiträge, Social-Fotoauswahl, Newsletterbilder, Logo,
Vorstandsunterschrift, Chat-Anhänge, Belege, Dokumente und Inventarfotos sowie
weiteren Formularen, die Fotos akzeptieren. Generierte Canvas-PNGs und reine
PDF-, CSV-, Zertifikats- oder Videoeingaben erhalten keine Kameraformat-Auswahl.
Neue oder per Navigation eingefügte Formulare werden automatisch angeschlossen.
Auch ein erst später aktiviertes Fotofeld erhält seinen freiwilligen Editor.
Ausblenden des Bereichs beendet eine laufende Kameraformat-Konvertierung und
startet keinen nachträglichen Upload; wiederholte Aktivierung vervielfacht weder
Formatliste noch Werkzeuge.
Der aktive Newsletter-/Presse-/Social-Baustein-Editor bietet ebenfalls
[direkte Einzel-/Mehrfachuploads](CMS-BAUSTEIN-FOTOUPLOAD.md), einschließlich
Storyfolien und freiwilliger Bearbeitung, ohne ungespeicherte Texte zu verlieren.

Ein defektes Foto verhindert die übrige Auswahl nicht. Es erscheint eine kurze
Meldung mit höchstens drei Dateinamen. Ein gewöhnlicher JPEG-, PNG- oder
Dokumentupload lädt keinen Kamera-Decoder. Dateisignaturen werden geprüft:
HEIC unter falschem JPEG-Namen und ein JPEG unter HEIC-Namen werden unterschieden.
Abbrechen oder Wechseln der Ansicht beendet die Umwandlung und startet keinen
Upload. Bereits bestätigte Uploads bleiben erhalten.

## Verarbeitung und Grenzen

Die lokal mitgelieferten Decoder arbeiten in Hintergrundprozessen des Browsers.
Sie brauchen weder CDN noch externe Bilddienste oder freigeschaltete PHP-
Shellfunktionen. Die Anwendung behält ihre strenge Skriptrichtlinie. Nur der
isolierte RAW-Worker erlaubt die vom Decoder benötigten dynamischen Aufrufhilfen;
beide Decoder-Worker dürfen keine weiteren Worker erzeugen.

HEIC/HEIF verwendet libheif, RAW verwendet LibRaw 0.22.1. DNG einschließlich
gängiger Apple-ProRAW-Dateien und gebräuchliche CR2/CR3, NEF/NRW, ARW, RW2,
ORF, RAF, PEF und weitere LibRaw-Kameraformate werden erkannt. Eine nicht
unterstützte Kamera-/Kompressionsvariante nutzt, sofern vorhanden, ihre größte
ausdrücklich gespeicherte JPEG-Vorschau. Das gilt auch für JPEG-XL-komprimierte
DNGs. Der Dateiname erhält dafür `-vorschau`, auch nach optionaler Bearbeitung.
Die Vorschau muss mindestens 512 Pixel an einer Seite haben und darf höchstens
8 MiB beziehungsweise 16 Megapixel groß sein. Ein kleines Kameravorschaubild
wird nicht als fertiges Foto hochgeladen. Verweise, JPEG-Abmessungen und
Orientierung werden vor der Verarbeitung geprüft; es wird nicht nach beliebigen
JPEG-Bytes in der Datei gesucht. Die Ausrichtung wird genau einmal angewendet.
Fehlt eine passende Vorschau, erscheint eine kurze Fehlermeldung. Vollständige
Sensorentwicklung für jeden neuen RAW-Codec ist damit nicht zugesichert.

Neue Kameraquellen dürfen höchstens 256 MiB und 120 Megapixel haben. Die Ausgabe
hat höchstens 2400 Pixel an der längsten Seite und 1,7 MB; im Chat höchstens
2000 Pixel, damit die vorhandene Vier-Megapixel-Grenze eingehalten wird. RAW
wird zunächst in halber Sensorauflösung entwickelt. JPEG-Qualität und Größe
werden bei Bedarf weiter angepasst. Moderne Browser mit WebAssembly, Worker
und OffscreenCanvas sind erforderlich. Ein tatsächlicher Apple-Gerätetest ist
weiterhin Teil der Geräteabnahme; Chromium ersetzt diesen Nachweis nicht.
Ohne JavaScript funktionieren die bisherigen kompatiblen JPEG-/PNG-Uploads.

Bei neu ausgewählten Kameraformaten wird die erzeugte JPEG-Datei hochgeladen;
eine zusätzliche Aufbewahrung des HEIC-/RAW-Sensororiginals ist damit nicht
eingeführt. Gespeicherte Originale, frühere Freigaben, Bildpixel und private
Rechtenachweise werden nicht verändert. Gewöhnliche PDF-, JPEG- und PNG-
Auswahldateien werden durch die Kamerakonvertierung nicht verändert.

## Nachweise und Wartung

Eigene synthetische HEIC-/DNG-Dateien werden im Browser tatsächlich decodiert,
auf JPEG-Abmessungen/Farbpixel geprüft und als echte Multipart-Anfragen
übertragen. Mobile Auswahl, Teilfehler, Abbruch, Ansichtswechsel, Größenlimit,
Dateisignaturen, unveränderte JPEG-/PDF-Bytes und ausschließlich lokale
Decoder-Anfragen sind in `photo-import-browser.mjs` geprüft. Die Actions führen
diese Prüfung nach Installation von Chromium aus; `bootstrap.sh --verify` ebenso.

`photo-import-app-browser.mjs` prüft zusätzlich die echten Formulare beider
Anwendungen und Speicherung eigener Album-/Beitragsbilder sowie Dokumente.
Der zugehörige Helfer schreibt ausschließlich eigene synthetische Entwürfe und
weist jede vorherige außenliegende Datenbankzeile und Bilddatei unverändert nach.
Die zusätzliche eigene DNG-1.7-Datei enthält tatsächlich verlustlos JPEG-XL-
komprimierte Sensordaten; deren unabhängige Rückdecodierung ist bytegleich.
Ihre beiden JPEG-Vorschauen sind getrennte eigene Farbtestbilder, kein Nachweis
für die Farbwiedergabe einer echten Apple-Kamera. 57 Kamera-, 75 Editor- und
25 tatsächliche Formularverträge bestehen. 16 neue Mediathekbilder und zwei
Dokumente werden unabhängig nach Speicherung auf Abmessungen, Originalpixel
und unveränderte frühere Originale geprüft.

Feste Versionen, Original-NPM-Hashes, Quellarchive, Lizenztexte und Bauanweisungen
sind im Offline-Paket unter `vereinsverwaltung/resources/photo-import/`
enthalten. [Decoderquellen und Wiederbau](../vereinsverwaltung/resources/photo-import/README.md).
Die 100-%-Abnahme des gesamten Produkts ist durch diesen Zwischenstand nicht
erreicht: Die weitere Bildeditor-/Toucharbeit und die übrigen Registerpunkte
werden fortgesetzt.
