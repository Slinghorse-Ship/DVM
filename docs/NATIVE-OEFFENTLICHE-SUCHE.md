# Vollständige öffentliche Website-Suche

`/search` und `/suche/` durchsuchen alle aktuell veröffentlichten eigenen
Website-Inhalte. Pro Ergebnisseite erscheinen höchstens 20 Treffer mit
Gesamtzahl, aktueller Seite, kurzen Ausschnitten und dem ursprünglichen Link.
Die Suche endet nicht mehr nach den ersten 50 Treffern. Die bisherige
`Feeds::search()`-Dienstschnittstelle liefert weiterhin ihre ersten 50 Treffer.

Suchbegriffe sind wörtliche, groß-/kleinschreibungsunabhängige Unicode-Texte;
auch sichtbare Inhaltsabschnitte nehmen teil. Private Bearbeitungen, fremde
Vereine und Beiträge ausschließlich für RSS erscheinen nicht. Titel und
Ausschnitte bleiben in HTML inert. Eine leere Suche listet keinen Bestand.
Ungültige Seiten-/Suchparameter werden abgelehnt; eine zu große gültige Seite
öffnet die tatsächliche letzte Trefferseite.

Der Dienst liest 100 Metadaten pro Schritt und jeweils ein öffentliches
Original. Er hält nur die gewählte Ergebnisportion und die letzte Portion
für die Seitenkorrektur im Speicher. Gleichzeitige Veröffentlichungen lösen
eine erneute Abfrage über eine aktuelle Generation aus. Aktuelle Leserechte
werden vor jedem Schritt und vor der Ausgabe überprüft. Ein neuer Suchindex
oder eine vollständige Hochlastabnahme ist damit nicht behauptet.

Kompakte Treffer und Seitenwahl oberhalb und unterhalb der Liste sparen
Scrollwege. Suchformulare, Zurück/Weiter und Seitenzahlen funktionieren mit
Touch, Tastatur und ohne JavaScript. Die Seitenwahl behält den genauen Begriff;
ein neuer Suchbegriff startet auf Seite 1. Die Suchseite verzichtet auf
animiertes Scrollen, damit native Fokus-/Touch-Navigation zuverlässig endet.

48 MySQL-/Dienstverträge prüfen 175 echte öffentliche Originale, gleiche
Zeitstempel über mehrere Metadatenportionen, private Fassungen, Originalbytes,
Rechteentzug und Veröffentlichung während einer Suche. Ein tatsächlicher
16-MB-Prozess durchsucht mehr als 16 MB ursprüngliche öffentliche Payloads.
333 tatsächliche Browser-/HTTP-Prüfungen verwenden 67 eigene öffentliche Originale je lokaler
Anwendung, vier Breiten bis 320 Pixeln, JavaScript an/aus, Touch, Tastatur,
200 % Zoom und echte HEAD-/304-/Fehlerantworten. Sie sendet keine fachlichen
Schreibvorgänge oder externen Aktionen. Die private Prüfsumme kontrolliert die
Originale beider Anwendungen, einschließlich Dokumenten und Freigaben im
integrierten Manager. Zwei Installer-Fälle rufen die tatsächlich installierten
Produktionswege auf.

```sh
php vereinsverwaltung/tests/ci-native.php native-public-search native-feeds native-public-router native-feed-http native-news-channels native-content-sections
KH_NATIVE_TEST_CONFIG=.local/native-cms-integration/integrated-manager.json php vereinsverwaltung/tests/support/public-search-browser-fixture.php prepare
KH_NATIVE_TEST_CONFIG=/workspace/.setup/native-demo.json php vereinsverwaltung/tests/support/public-search-browser-fixture.php prepare
node vereinsverwaltung/tests/native-public-search-browser.mjs
```

Die Browserhilfen akzeptieren ausschließlich die ausdrücklich eigene
synthetische Umgebung. Neue Originale sind lokale Prüfinhalte; es gibt keinen
Altimport oder Anbietertransport.
