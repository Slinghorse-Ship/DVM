# Seiten und Bilder verknüpfen

Frei deklarierte Seiten- und Bildfelder sowie die Elternseite verwenden dieselbe
Suche in beiden Redaktionszugängen. Seiten lassen sich über Titel oder Adresse,
Bilder über Beschreibung oder ursprünglichen Dateinamen finden. Mit **Suchen**
oder der Eingabetaste bestätigen; **Weitere Treffer** und **Vorherige Treffer**
blättern jeweils 25 Ergebnisse weiter.

Die gespeicherte Auswahl bleibt erhalten, auch wenn sie außerhalb der aktuellen
Trefferliste liegt. Erst eine bewusst gewählte andere Option ändert das Feld;
anschließend den Entwurf speichern. Suchtext und Seitenwechsel zählen nicht als
ungespeicherte Inhaltsänderung. Leere Pflichtfelder bleiben Pflichtfelder. Eine
inzwischen fehlende Verknüpfung erscheint mit einem Hinweis und ihrer bisherigen
Kennung; sie wird beim Lesen nicht automatisch ersetzt oder gelöscht.

Die Elternauswahl enthält die aktuelle Seite nicht. Die bestehenden Speicherregeln
prüfen weiterhin Verein, Feldtyp, Zyklen, aktuelle Rechte und Originalversion.
Eine Suche veröffentlicht nichts und verändert keine Bilder oder Anbieterjobs.

Die separate Redaktion zeigt unter **Deine Seiten** alle eigenen Inhalte in
25er-Seiten mit Gesamtzahl und Entwurfs-/Veröffentlichungszustand. Beim Blättern
bleibt der gerade geöffnete Editor erhalten. Eine Seite öffnen erhält die aktuelle
Verzeichnisseite; kein Inhalt verschwindet durch die Begrenzung der geladenen Liste.

## Gemeinsame Module und Nachweise

`Cms/ReferenceCatalog` zählt und liest ausschließlich die benötigten Metadaten in
MySQL. Der Vereinsfilter gilt für Treffer und zusätzlich gelesene ausgewählte
Kennungen. Suchzeichen einschließlich `%`, `_`, Anführungszeichen und SQL-ähnlichem
Text bleiben gewöhnlicher Text. Die feste Sortierung endet mit der Kennung;
ungültige Art, Kennung, Suchlänge oder Seitenzahl werden abgewiesen. Das private
HTTP-Ziel erlaubt ausschließlich GET/HEAD, verwendet aktuelle Leserechte und
liefert keine Dateipfade oder Inhaltskörper.

`Web/ReferencePicker` liefert native Bootstrap-Auswahlfelder und eine gemeinsame
Bedienung; `Web/assets/reference-picker.js` verwirft überholte Antworten und bricht
ausstehende Suchen bei Navigation ab. Eine während der Anfrage geänderte Auswahl
wird nicht durch die ältere Antwort überschrieben. `Web/Website/PageSidebar`
übernimmt das vollständige begrenzte Seitenverzeichnis der separaten Redaktion.

`native-reference-search.php` prüft 70 MySQL-/Dienstverträge. Ein eigener Prozess
mit 16 MB Speicherlimit lädt Auswahlfelder, Suchseite und Seitenverzeichnis bei
über 20 MB weiteren gespeicherten Entwürfen mit 2 MB maximalem PHP-Speicher.
`native-reference-search-browser.mjs` prüft 79 reale Abläufe einschließlich einer
bewussten privaten Speicherung und ihrer neuen Originalversion;
`native-reference-directory-browser.mjs` weitere 50 Abläufe der separaten
Redaktion. Beide Browser verwenden 1440/820/390 Pixel, tatsächliche HTTP-Antworten,
native Auswahl und unveränderte veröffentlichte Originalbytes.

`tools/cloud/bootstrap.sh --verify` richtet ausschließlich eigene synthetische
Fixtures ein und führt beide Browser aus. Die 165 vorhandenen Teilbaum- und
260 Einzelkopier-Browserverträge bleiben eigenständige Regressionen. Abhängige
[Pflicht-/Sichtregeln](CMS-FELDBEDINGUNGEN.md) sind inzwischen ebenfalls implementiert.
Mehrfach-/Relationsfelder, vollständige Accessibility- und Hochlastabnahme bleiben
im Register eigene offene Arbeiten.
