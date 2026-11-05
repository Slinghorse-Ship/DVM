# Lokales Bildstudio

Der Einstieg `../image-studio.js` hält Dokument, Folienwechsel, Undo/Redo,
Pointer-/Tastaturaktionen, Export und den gemeinsamen Seitenlebenszyklus.
Er enthält keine Pixelkorrektur oder Ebenen-Zeichenvorschrift.

| Datei | Aufgabe |
|---|---|
| `media.js` | Eigene Bildquellen laden, Pixelgrenze, Belichtung/Gamma/Farbkorrektur/Schärfe, begrenzter privater Cache und Abbruch |
| `clones.js` | Manuelle runde Klonstempel aus unveränderten korrigierten Quellpixeln, einstellbare weiche Ränder mit kleinem Maskenausschnitt, begrenzte Prozentwerte und beschriftete HTML-Bedienung |
| `clone-painter.js` | Lokale originale Fotoansicht für Quelle und ausgerichtete Finger-/Stift-/Maus-/Tastaturstriche; begrenzte Entwürfe, einzelne Rücknahme, Abbruch und bewusstes Anwenden |
| `renderer.js` | Textumbruch, Fotoausschnitt und Spiegelung, Formen/Zeichnungen/QR/Unkenntlichmachung, getrennte Auswahl- und Sicherheitshilfen |
| `controls.js` | Beschriftete HTML-Eingaben und Ebenenliste; Änderungen laufen durch den gemeinsamen Undo/Redo-Weg |
| `../image-export.js` | Begrenzte Ausgabemaße und tatsächliches PNG-/JPEG-/WebP-Encoding |
| `../../ImageStudioAssets.php` | Gemeinsame feste Dateizuordnung und Ladereihenfolge beider Manager-Einstiege |

Die Module sind lokale klassische Skripte mit kleinen unveränderlichen
Factory-Schnittstellen. Jeder Editor hat eigene Caches, Abort-Signal und Zustand.
Gespeicherte JSON-Dokumente bleiben im selben Schema; Backend-Rechte, CSRF,
Versionsbindung und Originaldateien werden weiterhin serverseitig geprüft.
`controls` schreibt weder Medien noch Veröffentlichungsjobs. Die Zeichenlogik
exportiert die Hilfslinien nur in der Vorschau. Kein externer Bilddienst.

Die Browserprüfungen liegen in `tests/image-{export,studio,tools}-browser.*`
und `tests/editor-lifecycle-browser.cjs`; vom Projektstamm aus ausführen.
`tests/native-photo-clones-browser.mjs` prüft beide Manager-Zugänge bei drei
Breiten, tatsächliche gerenderte/exportierte/gespeicherte Klonpixel und den
Originalschutz. Das Servermodul `Communication/PhotoClones` prüft dieselben
Grenzen: 64 Stempel je Foto, 256 je Dokument, Radius 1–25 Prozent. Alte Dokumente
erhalten keine neue leere Stempelliste. [Bedienung](../../../../../docs/BEDIENUNG.md#website-und-bildstudio).

`tests/photo-soft-clones-browser.mjs` und `tests/native-photo-soft-clones.php`
prüfen weiche Ränder in einer ausschließlich neuen isolierten CI-Datenbank.
Fehlende Randweichheit behält alte harte Pixel und Dokumentfelder unverändert;
explizite Werte liegen zwischen 0 und 100. Die sechs lokalen Werkzeugeingaben
gehören nicht zum Speicherformular: Anwenden prüft sie einzeln, während Export
und Speichern die bereits gültige Gestaltung verwenden. Der neue tatsächliche
Browserweg läuft ausdrücklich auch in GitHub Actions.

`tests/photo-clone-painter-browser.mjs` und `tests/native-photo-clone-painter.php`
ergänzen den ausschließlich neuen isolierten Finger-/Stiftweg. Die Fotoansicht
verwendet höchstens 1024 Pixel an der längeren Seite; gespeicherte Prozentwerte
bleiben auf das unveränderte Original bezogen. Striche bleiben bis zum bewussten
Anwenden außerhalb des Editor-Dokuments. Ein eigener AbortController entfernt
alle Ereignisse beim Schließen; die drei lokalen Canvas-Puffer werden geleert.
Das gemeinsame Editor-Signal beendet auch eine noch ladende Fotoansicht.
[Bedienung](../../../../../docs/BEDIENUNG.md#website-und-bildstudio).
