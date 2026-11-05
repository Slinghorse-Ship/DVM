# Dokumente auswählen, zuordnen und bearbeiten

Der gemeinsame Dokumenthelfer verwendet Bootstrap 6 auch ohne JavaScript.
Titel, Dokumentart, Datum, Ordner, Status, Notiz und Vorgangszuordnung haben
beschriftete Eingaben. Die ursprüngliche Vorgangszuordnung bleibt vorbelegt.
Interne Nachweisarten können beim Hochladen nicht als eigene Originale ausgegeben
werden. Dateiaktionen und beschriftete Checkboxzeilen haben mindestens 44 Pixel
große Touchziele; Texteingaben verwenden mindestens 16 Pixel.

Mit JavaScript wird eine ausgewählte Datei zunächst privat und sitzungsgebunden
zwischengespeichert. **Entfernen** verwirft diese temporäre Datei. Erst das
ausdrückliche Speichern legt das fachliche Dokument an. Ohne JavaScript bleibt
der ursprüngliche Datei- und Zuordnungsweg verfügbar. Geschützte Originaldateien
behalten ihre bestehenden Rechte und unveränderten Bytes.

Die Vorgangssuche zeigt sechs aktuelle Treffer pro Seite und die bereits
ausgewählten Zuordnungen zusätzlich. Suche, Vorgangsart, vorherige/nächste und
direkte Seiten funktionieren mit und ohne JavaScript. Bei mehreren Uploadzielen
bleibt jede Datei ihrem gewählten Ziel zugeordnet. Auch das einfache Formular
erhält ausgewählte Dateien privat über Suchanfragen und ungültige Seiteneingaben
hinweg; vorbereitete Dateien lassen sich vor dem Speichern einzeln entfernen.
Die bestehenden Rechte und die tatsächliche Dateifassung werden beim fachlichen
Speichern weiter geprüft. Bedienung: Handbuch Kapitel 11.

139 tatsächliche Browserprüfungen decken die befüllten Upload- und
Änderungsformulare bei 1440, 820 und 390 Pixeln mit und ohne JavaScript ab:
Tastaturauswahl, Touch-Abbruch dreier eigener temporärer Uploads, beschriftete
Controls, ursprünglicher Titel/Datum/Vorgang, 200 % Zoom, lesbarer Vereinskopf,
geschützter Download und verweigerter Mitgliederzugriff. Anschließend werden
Dokumente, Dateien, Verknüpfungen, Historie, Kontakte, Kalender und HSE-Originale
nochmals verglichen. Es erfolgt keine fachliche Speicherung und kein Anbieteraufruf.

```sh
KH_NATIVE_TEST_CONFIG=.local/native-cms-integration/integrated-manager.json \
  php vereinsverwaltung/tests/support/document-controls-browser-fixture.php prepare
node vereinsverwaltung/tests/native-document-controls-browser.mjs
```

Die fünf bestehenden MySQL-Suiten `documents.php`,
`document-link-form-state.php`, `document-catalog-save-roundtrip.php`,
`document-protection.php` und `document-batch.php` bestehen ebenfalls.
Weitere Fachzustände und Geräteprüfungen bleiben im Umsetzungsregister.

Persönliche Dokumentfreigaben haben eine eigene vollständige Personensuche und
separate Seiten für gespeicherte Empfänger. Beide zeigen sechs Einträge pro
Seite und erhalten den gewählten Empfänger, die Freigabe-/Rücknahmeentscheidung
und die ursprünglich geprüften Dokument-/Dateiversionen. Eine aktuelle Fassung
muss nach einem Versionskonflikt bewusst neu geöffnet und geprüft werden.
Archivierte Empfänger bleiben für die ausdrückliche Rücknahme erreichbar.

Die Übersicht **Vorgänge ohne Datei** zeigt sechs zugängliche Originalvorgänge
je Seite. Name-/Ordnersuche, Vorgangsart und Berechtigungsbereich wirken
serverseitig mit und ohne JavaScript; fehlerhafte Kriterien bleiben sichtbar.
Geschützte Ordner blockieren nicht die übrigen erlaubten Treffer. Schreibende
öffnen einen Upload mit ursprünglichem Vorgang und Belegdatum, Lesende dessen
Ablage. Das Ablegen ergänzt nur die Dokumentzuordnung, keine Ausgabe/Zahlung.
