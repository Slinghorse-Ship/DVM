# Bilder archivieren und zurückholen

Unter **Website → Galerie → Alle Bilder** ein Bild öffnen und **Bild archivieren**
wählen. **Bild zurückholen** stellt dasselbe Bild wieder als aktuell dar. In der
Bildsuche unter **Bildrechte & Archiv → Bildauswahl** zwischen allen, aktuellen
und archivierten Bildern wechseln. Die Auswahl lässt sich mit Suchbegriff und
Rechtefilter kombinieren; zwölf Bilder pro Ergebnisseite.

Archivieren ordnet die Mediathek. Bestehende Veröffentlichungen, Entwürfe,
Freigaben, Albumzuordnungen, Dateivarianten und private Nachweise bleiben erhalten.
Die bisherige Auswahl **Alle Bilder** enthält weiterhin beide Zustände. Der
belegte Fotospeicher bleibt gleich; Archivieren ist keine Speicherbereinigung.
Bildrechte und Einwilligungen behalten ihren eigenen Zustand und Verlauf.

Der **Archivverlauf** bleibt zunächst eingeklappt und zeigt höchstens zehn
ursprüngliche Änderungen pro Seite mit dem damaligen Namen und Zeitpunkt.
Leseberechtigte sehen Zustand und Verlauf; Ändern verlangt aktuelle
Website-Bearbeitungsrechte. Eine inzwischen veraltete Formularversion wird
abgewiesen. Wiederholtes Speichern desselben Zustands erzeugt keinen neuen
Verlaufseintrag. Formularbedienung funktioniert auch ohne JavaScript.

CMS-Schema 13 ergänzt nur `khcms_media_archives` und
`khcms_media_archive_history`. Das Upgrade erzeugt für vorhandene Bilder keine
Archivzustände oder rückwirkenden Verlaufseinträge. Die tatsächliche
Installer-Upgradeprüfung schützt die vorhandenen Datenbankoriginale und alle
vier Bildvarianten mit ihren vollständigen Bytes.

47 isolierte MySQL-/Rechte-/Controller-/Originalprüfungen und 125 tatsächliche
Browserverträge bestehen, einschließlich drei Bildschirmbreiten, Tastatur,
200 % Zoom und JavaScript-freier Bedienung. 16 tatsächliche Archiv-/Rückhol-
POSTs erhalten sämtliche vorherigen Datenbankzeilen und gespeicherten Dateien.
Der separate
App-Vertrag `media-archive-app-browser.mjs` arbeitet mit frischen eigenen Fotos
und einer lokalen eigenen Veröffentlichung in beiden Redaktionszugängen. Seine
Vorbereitung und abschließende Originalprüfung liegen in
`tests/support/media-archive-app-fixture.php`: sämtliche vorherigen
Datenbankzeilen, Veröffentlichungen und gespeicherten Bilddateien werden
vollständig verglichen. Kein Anbieter-, Mail- oder Live-Veröffentlichungsaufruf.

Referenzsichere endgültige Löschung, Aufbewahrung, verwaiste Dateien und weitere
Medienquoten bleiben eigene offene Arbeit. Eine leere sichtbare Verwendungsansicht
ist keine Löschfreigabe; historische und verschlüsselte Originale bleiben geschützt.
