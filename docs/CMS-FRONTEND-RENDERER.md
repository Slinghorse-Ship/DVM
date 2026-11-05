# Registrierte Frontenddarstellungen

Eine Seitenvorlage behält ihren nativen Inhaltstyp und kann zusätzlich eine
geprüfte Frontenddarstellung erhalten. Das betrifft die öffentliche Ausgabe;
Originalinhalte, Feldtypen, Veröffentlichungsrechte und Seitenfassungen bleiben
native CMS-Daten. Ein Wechsel der aktuellen Vorlageneinstellung verändert keine
bereits veröffentlichte Originalfassung. Die geänderte Vorlage wird beim normalen
Seitenspeichern aufgenommen und muss erneut veröffentlicht werden.

## Bedienung

Die Vereinsadministration installiert und aktiviert unter **Einstellungen →
Erweiterungen** das mitgelieferte Modul **Website-Darstellungen**. Unter **Felder &
Vorlagen → Vorlagen** die gewünschte Vorlage öffnen. Im geschlossenen Bereich
**Frontenddarstellung** stehen Leseseite, Kartenübersicht, Geteilte Ansicht und
Titel im Fokus zur Auswahl. **Grundlayout verwenden** entfernt die zusätzliche
Bindung für künftige Seitenfassungen. Die Auswahl verwendet normale beschriftete
Formularfelder und funktioniert ohne JavaScript.

Eine deaktivierte, entfernte oder geänderte Erweiterung wird als derzeit nicht
verfügbare gespeicherte Darstellung angezeigt. Andere Vorlagenangaben können
weiter geändert werden, ohne die alte Bindung zu verlieren. Eine neue Bindung
kann nur auf einen aktuell verfügbaren Renderer zeigen. Eine Veröffentlichung
mit nicht mehr verfügbarer Darstellung liefert keine ersatzweise konvertierte
Ausgabe; ihre Originaldaten bleiben erhalten. Eine neue Veröffentlichung wird in
diesem Zustand bereits mit einer korrigierbaren Meldung abgewiesen. Entwurf,
Versionsnummer und bisherige Veröffentlichung bleiben erhalten. Nach expliziter Modulaktualisierung
die neue Darstellung auswählen, betroffene Seiten neu speichern und nach Prüfung
veröffentlichen.

## Geprüfte Host-Erweiterungen

Zusätzliche Renderer kommen aus dem privaten, überprüften Host-Katalog des
bestehenden Erweiterungssystems. Es gibt keinen PHP-Upload, keine Dateipfadauswahl
und keine ausführbaren Vorlagenwerte in der Weboberfläche. Ein Host-Modul
registriert während `Plugin::initialize()` beispielsweise:

```php
$context->renderers()->register('leseseite', new
    \Kulturhus\Native\TemplateRenderers\Definition(
        'Leseseite', '1.0.0', ['title', 'excerpt', 'body', 'fields'],
    ));
```

Die deklarativen Komponenten sind `title`, `excerpt`, `body`, `fields`, `media`,
`documents`, `sections` und `listing`; die festen Layouts heißen `stack`, `cards` und `split`.
`listing` komponiert vorhandene native Nachrichten-/Programmlisten. Die feste
Option `listing: 'auto'` wählt den Nachrichtenbereich bei `aktuelles` und das
Programm bei `veranstaltungen`. Geprüfte Host-Definitionen können stattdessen
`news`, `events` oder `none` fest vorgeben. Freies SQL, frei formulierte Selektoren
und PHP aus Vorlageneingaben sind dafür nicht verfügbar. Nachrichten behalten
ihre echten Zwölferseiten, das Programm seine Zwanzigerseiten, Suche, Jahr,
Sortierung, Archiv und korrekte letzte Seite. Bestehende anonyme Feld-, Vorlagen-,
Veröffentlichungs- und Sprachprüfungen bleiben wirksam. Die Formulare und
Seitenwechsel folgen der URL-Regel der aktuellen Liste; Titel und Seitengerüst
erscheinen jeweils einmal.
Geprüfter Host-PHP-Code kann stattdessen eine statische lokale Closure als
`render` angeben. Sie erhält ausschließlich einen `RenderContext` und liefert
einen Inhaltsbereich innerhalb des gemeinsamen öffentlichen Layouts. Eigene
Dokumenthüllen, `<main>`, Inline-Skripte und unbeabsichtigte Ausgaben werden
abgewiesen. Ein Inhaltsfragment ist auf 2 MiB begrenzt. Fehlermeldungen enthalten
keine ursprünglichen PHP-Diagnosen.

Der Kontext enthält eine geschlossene Projektion der aktuellen öffentlichen
Seitenfassung, ausdrücklich ausgewählte Sprache und Inhaltssprache sowie den
bereits geprüften URL-Kontext. Aktuelle und ursprünglich gespeicherte Feldrechte
gelten kumulativ. Passwortwerte und Hashes, private Repeater-Einträge,
Feld-/Vorlagendefinitionen, Dateisystempfade und interne Medien-/Dateinachweise
gehören nicht zum DTO. `text()` maskiert Text, `component()` liefert vorhandene
Host-Bausteine, `image()` liefert nur Varianten dieser aktuell öffentlichen Seite,
`link()` nur öffentliche Zielseiten mit den geltenden Sprach-/URL-Regeln. Es gibt
keinen Datenbank-, Pages- oder privaten Request-Zugriff im Kontext.

Host-Code bleibt überprüfter vertrauenswürdiger PHP-Code und keine Sandbox.
Module müssen alle verwendeten Hilfsdateien und Stylesheets durch das optionale
Interface `Extensions\SourceFiles::sourceFiles()` vor der Freigabe deklarieren.
Sie müssen unter dem Modulverzeichnis liegen. Der private Host-Katalog prüft
zusätzlich Eigentümer, Dateirechte, Symlinks und Lage außerhalb des Webroots.
PHP-Callbackdateien und Renderer-Stylesheets müssen zum freigegebenen Quellensatz
gehören. Die Quelldateien und die geschlossene Rendererdefinition gehen in den
Fingerprint ein; Code- oder Hilfsdateiänderungen benötigen eine ausdrückliche
Modulaktualisierung. Bereits gespeicherte Bindungen werden dabei nicht umgeschrieben.

Optionale lokale CSS-Dateien sind auf vier Dateien mit je 64 KiB begrenzt.
Externe Importe und Ressourcen-URLs sind ausgeschlossen. Sie werden unter einem
festen `/extensions/<modul>/renderers/<renderer>/<hash>/<name>`-Pfad nur bei
aktueller Freigabe ausgeliefert. GET und HEAD bleiben lesend; alte Hashes und
entzogene Freigaben liefern keine Datei.

## Cache und Wiederaufnahme

Jede Ausgabe prüft aktuelle Modulfreigabe, ursprünglichen freigebenden Zugang,
Abhängigkeiten und Quellfingerprints. Der Cachekey enthält die genaue
Rendererbindung, aktuelle Modulkonfiguration und Renderer-Codefassung neben den
bestehenden Veröffentlichungs-, Sprach-, Design- und URL-Bindungen. Nach einem
Cachetreffer werden die Rechte erneut geprüft. Deaktivierung oder Quelländerung
entzieht somit auch bereits warme Ausgaben und Stylesheets. Fehlgeschlagene
Initialisierung entfernt alle teilweise registrierten Renderer dieses Moduls.
Es gibt keine statische globale Registrierung zwischen Vereinen.
Eine fehlende deklarierte Hilfsdatei sperrt nur das betroffene Modul. Die
Erweiterungsverwaltung bleibt für Deaktivierung erreichbar. Nach Wiederherstellung
exakt der bereits geprüften Dateien kann die Administration die frühere Bindung
wieder aktivieren. Abweichende Dateien benötigen die normale ausdrückliche
Aktualisierung; die ursprüngliche Vorlagenbindung wird dabei nicht ersetzt.

Lokal werden ausschließlich frische synthetische Datenbanken verwendet:

```sh
bash tools/ci/local-native.sh --core native-template-renderers native-extensions
DVM_TEMPLATE_RENDERERS_BROWSER=1 bash tools/ci/local-native.sh --core native-template-renderers
```

Die Fachabnahme prüft Originalfassungen, private Folgedrafts, Feldrechte,
Quelldrift, ausdrückliches Upgrade, Rechteentzug, Cache und Assetzugriff. Die
Browserabnahme verwendet beide echten Router und angemeldeten Editoren, normale
und abgewiesene Speichervorgänge, vier Layouts, veröffentlichte Übersetzungen,
vier Breiten, normale und verdoppelte Schrift sowie Betrieb ohne JavaScript.

## Kuratierte gemeinsame Auslieferung

Der unveränderliche Export auf dem abgeschlossenen API-/Routing-/Datei-/Konten-/Transfer-/Kommentarstand besteht 16 angrenzende Fachgruppen. Die bisherige geschlossene Querygrammatik bleibt erhalten; der eigene P-09-Selektor-/Leistungsausbau gehört nicht zu diesem Paket. Eine anschließende Compositionprüfung besteht 38 tatsächliche API-/Exportbedingungen und 79 Transferbedingungen. Nicht portable lokale Typ-/Inputfield-/Rendererbindungen werden bereits vor der Projektion ausdrücklich abgewiesen, einschließlich verschachtelter Originaldefinitionen. Es entsteht kein Teilpaket, Originale und Normalizerzählung bleiben exakt. Native Modelle bleiben übertragbar.

Die abschließende Feldtypenprüfung besteht 90 Fach-, 103 Katalog- und **1340 Browserbedingungen in 96 Ansichten mit zwölf realen POSTs** in beiden Apps mit und ohne JavaScript, bei 320/390/1024 Pixeln und einfacher/doppelter Schrift. 415 ursprüngliche Geschäftstabellen, acht ursprüngliche Revisionen, Medien und Schlüssel bleiben exakt. Ein realer zu langer Handykatalog wurde durch sechs vollständige Einträge pro SQL-Seite und passende Abstände korrigiert; menschliche Originale werden nicht gekürzt. Die gesonderte Fassungsauswahl behält ihre eigene Zehnergrenze.

Die bereits eingefrorene vollständige Rendererabnahme besteht 500 Fach- und 1094 Browserbedingungen in 224 Ansichten. Der neue kuratierte Zusammensetzungsnachweis besteht zusätzlich 80 Rendererbedingungen und 166 tatsächliche Aktionsbedingungen mit 20 geschützten POSTs für beide Apps/JavaScriptwege, echte News-/Programmlisten, Pagination und Jahresfilter. Hier bleiben 418 ursprüngliche Tabellen und Schlüssel exakt. Der neue optionale Rendererparameter erhält den bereits ausgelieferten positionalen Kommentar-Linkparameter; Produktaufrufe binden die beiden Optionen ausdrücklich über ihre Namen. Keine externen Aktionen und keine JavaScriptfehler.

- `kulturhus-extensible-isolated-domain.log`, SHA-256 `595fc87aa9169677162a9f82d7b23cc69c1b1c2b875ed8d7439b4394095e6abd`.
- `kulturhus-extensible-isolated-transfer-composition.log`, SHA-256 `a723e43b665f53deec416346b83c4a15e78614b37e231a2fdd0d4217edc8797e`.
- `kulturhus-extensible-isolated-fieldtypes-browser3.log`, SHA-256 `e651a4300ec3d25b25122336e8b2aebd089941314728d3b79eb8a9734464cd50`.
- `kulturhus-extensible-isolated-renderers-browser.log`, SHA-256 `5013067c5b3643b2a158af36ae0978bbcb8b599ec1c3255c2595612714150312`.
