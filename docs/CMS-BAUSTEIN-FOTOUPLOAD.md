# Fotos direkt in Inhaltsbausteine einfügen

Im Newsletter-, Presse- und Social-Entwurf **Inhaltsbausteine** öffnen und unter
**Fotos hinzufügen** ein oder mehrere Fotos auswählen. Die Bilder werden direkt
hochgeladen und als Bildbausteine eingefügt. In Social-Storys entsteht je Foto
eine eigene frei bearbeitbare Folie. Ungespeicherte Texte und Titel bleiben
erhalten; **Entwurf speichern** sichert anschließend ihre genaue Bildzuordnung.

Die gemeinsame Auswahl wandelt HEIC/HEIF und unterstützte Kamera-RAW-Dateien
automatisch um. Der optionale **Foto bearbeiten …**-Schritt funktioniert hier
ebenfalls. Quelle, Credit und Lizenz sind für den Upload nicht erforderlich;
die [globale optionale Prüfung](CMS-BILDPRUEFUNG.md) greift erst vor Verwendung.
Neue Bilder erhalten keine erfundenen Rechte oder Nachweise.

Bis zu 50 Fotos können in einem Schritt ausgewählt werden; der Editor behält
seine Grenze von 100 Bausteinen beziehungsweise zehn Storyfolien. Beschädigte
Dateien verhindern die übrigen Uploads nicht. Serverseitig abgelehnte Dateien
werden mit höchstens drei Dateinamen gemeldet. **Abbrechen** stoppt die weitere
Auswahl; bereits angenommene Fotos bleiben erhalten. Ein unklarer oder
unterbrochener letzter Upload wird nicht automatisch wiederholt: Die Meldung
verweist zur Prüfung in die Mediathek. Ansichtswechsel beendet lokale Vorgänge.

Die bestehenden Redaktions- und Mediathek-Uploadrechte bleiben erforderlich.
Lesezugänge und entzogene Rechte erhalten keine Uploadsteuerung. Jede tatsächliche
Anfrage prüft CSRF, aktuelle Rechte, Verein und gegebenenfalls den genauen Entwurf.
Die Bilder bleiben private Mediathekdateien, bis ein vorhandener Freigabe- und
Veröffentlichungsweg sie verwendet. Ohne JavaScript bleiben gespeicherte Bausteine
erhalten; dieser direkte Editor-Upload setzt JavaScript voraus.

`native-editor-upload.php` prüft 43 tatsächliche MySQL-/Rechte-/Originalverträge.
`editor-upload-app-browser.mjs` prüft 86 Verträge beider aktiven Anwendungen mit Touch bei
320 Pixeln, gemischten Kameraformaten, freiwilliger Bearbeitung, Teilfehlern,
CSRF, unterbrochener Anfrage, Abbrechen und echter Entwurfsspeicherung.
Der zugehörige Helfer prüft unabhängig gespeicherte Bildmaße/Farbpixel, genaue
Zuordnung und jede vorherige außenliegende Datenbankzeile samt Bilddatei.
18 neue Mediathekbilder und acht tatsächliche Entwurfsspeicherungen sind geprüft;
sämtliche früheren Originalrevisionen und Bilddateien bleiben unverändert.
Diese Zwischenstufe schließt die weiteren Baustein-/UI- und Registerarbeiten
nicht pauschal ab.
