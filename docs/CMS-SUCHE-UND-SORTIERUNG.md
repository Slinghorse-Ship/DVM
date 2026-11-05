# Seiten suchen und sortieren

**Website → Seiten** bietet Suche, Veröffentlichungsfilter und Sortierung nach
Titel, Bearbeitungsdatum oder jedem deklarierten Zusatzfeld. Zahlen werden
numerisch, Datumsfelder chronologisch und formatierte Felder nach ihrem
sichtbaren Text sortiert. Leere Angaben stehen zuletzt. Bei gleichem Wert
entscheidet die Seitenkennung, damit Folgeseiten keine doppelten Treffer haben.

Die Liste liest höchstens 25 Seiten aus MySQL. Eine separate Zählung mit denselben
Filtern bestimmt die tatsächliche Trefferzahl. Suche, Status und Sortierung bleiben
beim Seitenwechsel erhalten; eine zu große Seitenzahl führt zur letzten vorhandenen
Seite. Veröffentlichte Inhalte mit neuem Entwurf erscheinen weiterhin im Filter
„Veröffentlicht“ und zusätzlich unter „Mit Entwurfsänderungen“.

Die vorhandene Suche über Titel, Adresse, Kurztext, sichtbaren Seiteninhalt und Ort
bleibt einschließlich feldübergreifender Suchtexte erhalten. Groß-/Kleinschreibung
wird dabei ignoriert. Prozentzeichen, Unterstriche und SQL-ähnlicher Text sind
gewöhnliche Suchzeichen. Originalveröffentlichungen werden dadurch nicht geändert.

## Veranstaltungen und Übersicht

**Website → Veranstaltungen** verwendet dieselbe geprüfte Suche und SQL-Zählung
mit höchstens 25 Treffern pro Seite. Archiv, kommende Termine, Jahr, Status und
Sortierung bleiben beim Seitenwechsel erhalten. Der gesamte heutige Tag gehört
nach Vereinszeit `Europe/Berlin` zu „Aktuell & kommend“. Undatierte Termine bleiben
in dieser Auswahl enthalten und stehen bei beiden Datumsrichtungen zuletzt.
Die Suche berücksichtigt zusätzlich die gespeicherte Straßenanschrift. Eigene
Veranstaltungsvorlagen und importierte Termine verwenden denselben Ablauf.

Die Jahrauswahl liest ausschließlich verschiedene gespeicherte Veranstaltungsjahre
aus MySQL. Jedes vorhandene vierstellige Jahr ist wählbar; die alte Beschränkung auf
1900–2299 entfällt. Ein Veranstaltungsdatum auf einer normalen Seite erzeugt kein
Veranstaltungsjahr. Der Eventbrite-Abgleich bleibt eine ausdrückliche Aktion.

Die **Website-Übersicht** zählt Inhaltstypen und Veröffentlichungszustände direkt in
MySQL und lädt genau acht zuletzt bearbeitete Seiten. Alle fünf Bereichskarten
zeigen ihre Anzahl. Menüwerte stammen aus der veröffentlichten Originalfassung
einschließlich gespeicherter Sichtbarkeitsänderungen; der Startseitenlink zählt
weiterhin nicht mit. Der Galerie-Container ist weiterhin kein Fotoalbum.

## Gemeinsame native Abfrage

`Cms/QueryField` liefert dieselbe geprüfte Feldprojektion für Filter und Sortierung.
`Cms/Selectors` verbindet Bedingungen als AND sowie Gruppen `all` (AND) und `any`
(OR). `Cms/QueryOrder` übersetzt höchstens drei Sortierfelder mit ausdrücklicher
Leerwertposition. `Pages::search()` und `Pages::count()` verwenden denselben Filter;
`Web/Website/EditorialSearch` übernimmt gemeinsame Filter, Zählung, Begrenzung und
Seitenzahl. `PageSearch` und `EventSearch` ergänzen ihre eigenen Fachfilter und
Sortiermöglichkeiten. `PageOverview`, `Cms/PageSummary`, `Cms/EventYears` und
`Cms/Navigation::count()` liefern die begrenzte Übersicht und Metadaten.

```php
$filter = [
    ['field' => 'template', 'op' => 'eq', 'value' => 'vereinsseite'],
    ['any' => [
        ['field' => 'custom.beitrag', 'op' => 'gte', 'value' => '12.500001'],
        ['all' => [
            ['field' => 'custom.hinweis', 'op' => 'contains_ci', 'value' => 'Treffen'],
            ['field' => 'published', 'op' => 'eq', 'value' => true],
        ]],
    ]],
];
$order = [
    ['field' => 'custom.beitrag', 'direction' => 'desc', 'nulls' => 'last'],
    ['field' => 'title', 'direction' => 'asc', 'case_fold' => true],
];
$total = $pages->count($filter);
$rows = $pages->search($filter, 25, 25, $order);
```

Basisfelder sind `title`, `slug`, `template`, `parent_id`, `excerpt`, `body`,
`event_location`, `event_start`, `event_day`, `renderer`, `published`, `status`
sowie die abgeleiteten `search_text` und `event_search_text`. `event_day` ist ein
typisiertes Datum; fehlende oder leere Veranstaltungsangaben sind Nullwerte.
`created_at`, `updated_at`, `published_at` sind zusätzliche
Sortierfelder. Frei deklarierte Felder verwenden `custom.<schlüssel>`.
`status` kennt `draft`, `published`, `unpublished_changes`.

Alle Feldtypen erlauben `eq`, `ne`, `in`. Text, mehrzeiliger und formatierter Text
erlauben `contains`, `starts_with` sowie deren ausdrücklich gewählte Varianten
`contains_ci`, `starts_with_ci`. Die bisherigen Operatoren bleiben groß-/
kleinschreibungsabhängig. Integer, Dezimalzahl und Datum erlauben außerdem
`gt`, `gte`, `lt`, `lte`. `in` enthält 1–100 typgeprüfte Werte. Nullwerte benötigen
einen eigenen `eq`-/`ne`-Filter und bleiben von `false` und der Zeichenfolge
`"null"` unterscheidbar. Dezimalzahlen werden ohne Floatumwandlung verglichen.

Das Filterbudget umfasst insgesamt 20 Bedingungen und maximal drei ineinander
verschachtelte Gruppen. Leere Gruppen, unbekannte Eigenschaften und unpassende
Operatoren werden abgewiesen. SQL-Feldnamen und Ausdrücke sind im Code festgelegt;
JSON-Pfade und Werte bleiben gebundene Parameter. Der Vereinsfilter steht immer
außerhalb der Auswahlgruppen. Aktuelle Rechte gelten auch für Zählabfragen.

Ohne Sortierangabe bleibt die bisherige Reihenfolge nach Adresse und Kennung.
Eine Änderung des Feldtyps deutet ältere gespeicherte Werte nicht stillschweigend
um: Suchbedingungen berücksichtigen deren abweichenden Typ nicht; bei Sortierung
gelten sie als leer. Gespeicherte veröffentlichte Originale bleiben erhalten.

`native-cms-queries.php` prüft 89 Verträge mit einer frischen MySQL-Datenbank,
einschließlich exakter großer Dezimalzahlen, Gruppen, Rechteentzug, Typwechsel,
stabiler Seitengrenzen und unveränderter Originalveröffentlichung.
`native-cms-queries-browser.mjs` prüft 97 tatsächliche Abläufe bei 1440/820/390
Pixeln, einschließlich Tastatur, Bootstrap-Auswahl, langen Feldbezeichnungen,
realen Filterlinks und Originalbytes. `tools/cloud/bootstrap.sh --verify` richtet
ausschließlich eigene synthetische Inhalte für diese Prüfungen ein.

`native-editorial-lists.php` prüft zusätzlich 50 tatsächliche MySQL-/Dienstverträge,
einschließlich 69 Veranstaltungen, eigener Vorlage, unbekannter Daten, Tagesgrenzen,
Statusänderungen, Menüübersteuerung, verschiedener Tabellen-Kollationen und
Originalrechten. Ein eigener Unterprozess mit 16 MB Speicherlimit liest bei knapp
20 MB weiteren gespeicherten Entwürfen die Übersicht und eine Veranstaltungsseite
mit 4 MB maximalem PHP-Speicher. Das ersetzt noch keine Hochlastabnahme.
Der vorhandene `native-website-workspace.php`-Bestand mit 56 Verträgen läuft jetzt
ebenfalls im gemeinsamen CI. `native-editorial-lists-browser.mjs` prüft 158 echte
Filter-/Seitenlinks, Übersichtszahlen und alle acht Website-Untermenüs bei
1440/820/390 Pixeln, einschließlich Tastatur-Reihenfolge, sichtbarer Bootstrap-
Checkboxen und Menüzeilen bei eingeblendeter Tablet-Seitenleiste. Originalbytes,
Anbieter- und Inhaltszustände bleiben erhalten.

Die aktuelle gemeinsame native Ausdruckssprache, typisierte Mehrfach- und
Relationsfilter sowie konfigurierbare gespeicherte Inhaltslisten stehen unter
[Inhalte gezielt auswählen](CMS-SELEKTOREN.md). Sie verwenden dieselben
Rechte- und Originalverträge; die frühere Aufzählung dieser Funktionen als
allgemein fehlend beschreibt den historischen Stand dieses Nachweises.

Seiten-/Bildverknüpfungen, Elternwahl und das vollständige Seitenverzeichnis der
separaten Redaktion lesen ebenfalls jeweils höchstens 25 Metadatensätze. Die
gespeicherte Auswahl bleibt beim Suchen erhalten. [Bedienung und Nachweise](CMS-VERKNUEPFUNGEN.md).
