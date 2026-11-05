# Pressefotos mit eigenen Presserechten

Ein Konto mit Schreibrecht für Presse kann neue Fotoanlagen einzeln oder gemeinsam
hinzufügen. Zusätzliche Website-Rechte sind dafür nicht erforderlich. Das gilt
auch bei ausschließlich lesendem Website-Zugang. Vorhandene eigene ausgewählte
Anlagen bleiben erhalten; der allgemeine Website-Bildbestand wird nur mit seinem
eigenen Leserecht angezeigt. Lesende Pressekonten erhalten keinen Uploadweg.

Die private Speicherung und genaue ursprüngliche Ausgabe werden gemeinsam
festgehalten. Abgewiesene Speicherung rollt neue Kopien zurück. Aktuell entzogene
Presserechte verhindern weitere Uploads. Der interne Adapter erteilt weder
Website-Veröffentlichung noch Website-Verwaltung oder Rechte für andere Module.
Downloads bleiben an die konkrete ursprüngliche Pressefassung gebunden.

HEIC und RAW werden auch ohne JavaScript automatisch in ein passendes JPEG
umgewandelt. Der gemeinsame native Medienweg verwendet dabei die bereits
mitgelieferten lokalen HEIC-/LibRaw-Codecs. JPEG, PNG und WebP behalten ihren
bisherigen Weg. Kameravorlagen werden nicht geändert; Zwischenkopien bleiben
privat und werden nach Erfolg und Fehler entfernt. Die Vorbereitung erzeugt
höchstens 1.600 Pixel an der langen Seite, ohne EXIF-/Standortdaten.

Für die zusätzliche Serverumwandlung werden Node.js und PHP `proc_open` benötigt;
`VEREIN_PHOTO_NODE` kann auf den eingerichteten Node-Pfad zeigen. Die Cloud besitzt
diese Laufzeit bereits. Der übliche Browserweg bereitet Kameraformate weiterhin
lokal vor und benötigt diese zusätzliche Hostingfähigkeit nicht. Es werden keine
Decoderpakete zur Laufzeit heruntergeladen und keine externen Bilddienste aufgerufen.
Kameradateien unterstützen inzwischen bis 256 MiB je Datei und 512 MiB
Originale je Mehrfachanfrage; 120 Megapixel bleiben die Decodergrenze. Normale
Rasterbilder und vorbereitete Bilder behalten ihren bisherigen 40-MiB-Stapel.
[Große Kameraoriginale und aktueller Hostingweg](GROSSE-KAMERAORIGINALE.md).

Die tatsächlichen Presserechte ohne Website-Zugang, reine Website-Leserechte,
Rückrollen, frühere Originalbytes, Rechteentzug und Kameradateien sind geprüft.
44 Browserprüfungen, vier echte HEIC-/RAW-Mehrfachuploads und 24 mobile
Schriftansichten bestehen in beiden Zugängen mit und ohne JavaScript. Eine echte
18-Megapixel-RAW-Datei über 35 MB wird ohne Änderung der Quelle vorbereitet.
Zehn betroffene native Gruppen bestehen; bisherige Pressefotos zusätzlich mit
105 Browserprüfungen und 21 lokalen Formularaktionen. Keine reale Mail.

21 aktuelle Fachprüfungen einschließlich Browser bestehen. Neuinstallation 231,
verschlüsselte Wiederherstellung 217 und Paketupgrade 146 Prüfungen grün,
einschließlich 90 Browserprüfungen, 93 Schritten und 16 Statusaktionen.
Die tatsächliche Relokation erhält beide konvertierten Kameraoriginale bytegleich
und ihre genaue ursprüngliche Pressefassung. Abschließende Paketprüfung erfolgt
vor dem Feature-Push; kein neuer vollständiger 232-Lauf behauptet.
