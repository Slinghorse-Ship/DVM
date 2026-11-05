# Abnahme der Medien- und Fotoeditorgrundfunktionen

K-27 umfasst Bildoptimierung, Galerie und Rechte-/Creditfelder einschließlich der
beauftragten einfachen Fotoauswahl, Kameraformate und optionalen Fotobearbeitung.
Diese benötigten Softwarefunktionen sind implementiert. Es wird keine vollständige
zusätzliche Medienplattform als Voraussetzung eingebunden.

| Benötigte Funktion | Fachweg und aktueller Nachweis |
| --- | --- |
| Einzel-/Mehrfachauswahl und automatische Vorbereitung | Gemeinsame Fotoimport-Eingaben, lokale HEIC-/RAW-Codecs und normale große Handyfotos; 98 Fotoimport-Browserprüfungen, tatsächliche neue 48-MP-Mehrfachanfragen in beiden Oberflächen. |
| Kompakte Galerie und vollständige Medienauswahl | Gezählt, gesucht, geblättert, eigene Rollen, ursprüngliche Beschriftungen; `native-gallery-originals` mit 397 Browserprüfungen und 64 Ansichten. |
| Orientierung, Varianten und unveränderte frühere Pixel | Eigener serverseitiger Medienweg, acht ursprüngliche Kameradrehungen, geschützte private/publicierte Varianten; `native-media-edit`, `native-image-attribution`, vollständiger 236-Lauf und neue Originalhashes. |
| Alttext, Credit, Quelle, Lizenz, Einwilligung und Ablauf | Versionierte eigene Metadaten und unveränderliche veröffentlichte Angaben; `native-media-metadata`, `native-media-policy`, `native-media-usage`, `native-image-attribution`. |
| Optional aktivierbare Rechtevorgaben | Eigene Konfiguration und aktuelle Fachprüfung, normale Auswahl im freigegebenen Zustand ohne zusätzliche Pflichtschritte; dieselben Medien-/Verwendungs-/Metadatengruppen. |
| Zuschnitt, Seitenverhältnis, Drehen, Spiegeln und Zoom | Gemeinsamer optionaler Editor; 111 tatsächliche Editor-Browserprüfungen, eigene Pixel und Übernahme/Abbruch. |
| Helligkeit, Kontrast, Sättigung, Temperatur, Schärfe und Filter | Lokale Pixeloperationen des vorhandenen Editors, ohne verpflichtenden externen Dienst; dieselben Editorprüfungen. |
| Stift, Linie, Pfeil, Rechteck, eigener Text und deckende Unkenntlichmachung | Begrenzte ursprüngliche Markierungen und tatsächlicher Export; Editorprüfungen und eigene native Bearbeitung. |
| Kopierretusche, weiche Übernahme, Pinsel und Reparatur | `native-photo-soft-clones`, `native-photo-clone-painter`, `native-photo-healing`; 80, 43 und 31 tatsächliche Browserprüfungen, echte eigene Speicherungen und Originalschutz. |
| Undo/Redo, Reset, Abbrechen, Touch und Tastatur | Ursprünglicher Editorzustand und eigener Export; Editor-/Retuschebrowser, schmale und vergrößerte Ansichten. |
| Große Originale und Fehler-/Wiederaufnahmewege | [48-MP-/96-MB-Abnahme](GROSSE-KAMERAORIGINALE.md): kalter Codec, echte große Multipartanfragen, atomarer Rollback, genaue Wiederholung und unveränderte frühere Geschäftszeilen. |

Die neun aktuellen Mediengruppen enden gemeinsam mit
`{"suites":9,"failed":[],"live_transport":false}`. Zusätzlich bestehen die acht
unmittelbar vom Kameraweg betroffenen Gruppen und die getrennten Fotoimport- und
Fotoeditor-Browser. Die realen großen Uploads wurden mit und ohne JavaScript im
nativen und eingebetteten Zugang ausgeführt. Die gebündelten Decoder und ihre
zugehörigen Quellen behalten ihre bisherigen geprüften Hashes.

K-27 wird auf `implementiert` gesetzt. Weitere Fachbereiche, Apps und gemeinsame
Statusseite behalten ihre eigenen Aufgaben. Es wurde kein reales Foto importiert,
kein externer Bilddienst aufgerufen und keine Veröffentlichung ausgelöst.
