# Websitecache und eigene Cache-API

Unter **Einstellungen → Cache** sieht die technische Websiteverwaltung höchstens
zehn Einträge pro Seite. Bereich auswählen, abgelaufene Einträge entfernen,
einzelne Einträge oder markierte Gruppen leeren und Seiten wechseln funktionieren
auch ohne JavaScript. Die Ansicht zeigt ausschließlich Metadaten; gespeicherte
Werte, private Dateipfade und fremde Vereine werden nicht ausgegeben.

Die Oberfläche folgt der persönlichen deutschen oder englischen Managersprache,
einschließlich Datum in Europe/Berlin, Zahlenformat, Aktionen und Fehlermeldungen.
Bereiche, Schlüssel und Markierungen bleiben ursprüngliche Angaben. Die
Cachepflege versendet keine Nachricht und verändert keine ursprünglichen
Websiteinhalte, Rechnungen oder Mitgliedsdaten.

Bei einer abgewiesenen Anfrage bleiben die ursprünglich eingereichten Angaben
nach aktueller Rechte- und Formularprüfung schreibgeschützt sichtbar. CSRF und
Cachewerte werden nicht in diese Originalansicht übernommen. Ein unbestätigter
Auftrag wird nicht automatisch wiederholt. **Cache erneut öffnen** liest den
aktuellen Stand für einen danach bewusst bestätigten neuen Auftrag. Entzogene
Rechte bleiben HTTP403; ungültige Eingaben HTTP422; ein nicht verfügbarer
Cachespeicher HTTP503 ohne privaten Pfad oder internen Laufzeitfehler.

`native-cache-interface` prüft die tatsächliche Sprach-, Fehler- und
Wiederaufnahmebedienung mit eigenen ursprünglichen Cachedateien und aktuellen
Vereinsrechten. Seine befüllte Browserprüfung lässt sich gezielt aufrufen:

```sh
DVM_CACHE_INTERFACE_BROWSER=1 bash tools/ci/local-native.sh --core native-cache-interface
```


Der persistente private JSON-Cache besitzt eine ausdrücklich vereinsgebundene
API: `Cache\Store(root, club)` mit `get`, `has`, `set`, `remember`, `delete`,
`invalidate(tag)`, `clear(namespace)`, `cleanup` und `listing`. Ablaufzeit,
Abhängigkeitsversionen und Markierungen sind eigene Verträge. `null` ist ein
zulässiger gespeicherter Wert; `has` unterscheidet ihn vom fehlenden Eintrag.
TTL 0 gilt bis zur Invalidierung, sonst sind 1 bis 2.592.000 Sekunden zulässig.

Standardgrenzen sind 256 Einträge, 32 MiB zusammen und 1 MiB je Eintrag. Übergroße
Renderantworten bleiben auslieferbar, ohne im Cache gespeichert zu werden.
Berechnung erfolgt außerhalb der Dateisperre; eine konkurrierende Invalidierung
verhindert, dass danach die alte Berechnung gespeichert wird. Austausch erfolgt
atomar, mit eigenen privaten Dateien. Bereinigung entfernt ausschließlich eigene
Cachedateien und alte eigene temporäre Schreibdateien. Quelldaten gehören nie
in diesen entbehrlichen Speicher.

Der tatsächliche Website-HTML-Cache verwendet dieselbe API mit einer Stunde
Ablaufzeit, aktuellem Veröffentlichungsgenerationsstand und den Markierungen
`website` und `page:<Original-ID>`. Sein Vereinsbereich ist die tatsächliche
native Vereinskennung. In Produktion entspricht der Verwaltungspfad exakt dem
privaten Laufzeitstamm `VEREIN_PRIVATE_ROOT/public-cache`, auch wenn Medien auf
einem getrennten Datenträger liegen. Synthetische Vorschauen verwenden ihren
eigenen vorhandenen Laufzeitstamm. Kein Pfad wird aus Formulareingaben gelesen.

## Abnahme

`native-cache` prüft echte Dateipersistenz einschließlich eines zweiten
PHP-Prozesses, Ablauf-/Nullwerte, Markierungen, Abhängigkeiten, aktuelle Rechte,
Vereinsgrenzen, Größenlimits, konkurrierende Invalidierung, Controller/CSRF und
die tatsächliche HTML-Anbindung. Im begrenzten sequenziellen API-Vergleich liefern 40 Aufrufe
gleiche HTML-Bytes; die gleiche echte Hash-/HTML-Renderberechnung sinkt von 40 Aufrufen auf einen.
Die gemessene Laufzeit ist ein lokaler Vergleich, keine allgemeine Lastzusage.

Die geschützte Browserabnahme wird mit `DVM_CACHE_BROWSER=1` zugeschaltet und
verwendet frische eigene Datenbanken, echte native Sitzungen, sechs Ansichtsgrößen,
normale/verdoppelte Schrift sowie JavaScript und native Formulare. Bestehende
Geschäftstabellen, Schlüssel und Originale werden dabei vollständig verglichen.
Die benachbarten Gruppen `native-publication`, `native-website-design` und
`native-public-albums` prüfen die vorhandenen öffentlichen Wege.

Der vollständige tatsächliche Browserlauf besteht mit 52 Fachbedingungen und
622 Browserbedingungen in 24 Ansichten. Er prüft echte native Formularaktionen,
Sitzungssperre, CSRF, Ablauf, Bereichsfilter, Markierungen und Originalbewahrung.
Sein vollständiges Protokoll `kulturhus-native-cache-browser-final.log` hat SHA-256
`8ad823217b5b040511ca4bc0a0d614f9a0de9bc14c3fff468f1c1933412481d0`.
Der Vergleich ist keine HTTP-Durchsatz- oder Mehrclient-Lastmessung.

Ein unabhängig aus dem Git-Index exportierter, während der Prüfung unveränderter
Cache-Stand besteht gemeinsam `native-cache`, `native-publication`,
`native-website-design`, `native-public-albums` und `native-installer`. Darin
sind ebenfalls 24 Browseransichten/622 Bedingungen, 384 ursprüngliche Tabellen
und 232 tatsächliche Installerbedingungen bestätigt. Der frühere Lauf im
gemeinsamen Entwicklungsstand enthielt 399 Tabellen; beide Zahlen gehören
zu ihren jeweiligen frischen Testbeständen. Keine fremden Funktionen werden
in den isolierten Cache-Stand hineingezählt.
Protokoll: `kulturhus-cache-isolated-feature-proof.log`, SHA-256 `d79d3165f7b73e7c64e6865cbbf784383d7e35f56289b10e623785eceff25df6`.
Der anschließende Dokumentationsabgleich ändert ausschließlich Dokumente;
Cache-Code, Browser und Fachprüfungen bleiben bytegleich. Inventar und
installierbares Endpaket werden danach erneut vollständig geprüft.
