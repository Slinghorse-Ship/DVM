# Registrierte native Feldtypen und Eingabefelder

P-02/P-04 ergänzen geprüfte lokale Feldtypen und getrennte Eingabefelder. Die
native Typgrundlage, Feld-/Datensatzrechte, Versionen und Referenzprüfungen
bleiben verbindlich. Eine Erweiterung kann keinen PHP-Upload, freien SQL-Typ,
ausführbaren Modelltext oder fremden Formularendpunkt deklarieren.

## Bedienung

„Felder & Vorlagen“ zeigt sechs Originale, Suche, Zählung und Seitennavigation.
Erst ein ausgewähltes Original oder „Neues Feld/Neue Vorlage“ erhält ein
Bearbeitungsformular. Auswahl und Suche überleben normale POST/Redirect/GET-
und SPA-Antworten in beiden Managerzugängen.

Aktive geprüfte Typen erscheinen in „Feldtyp“, kompatible Eingabefelder unter
„Eingabefeld“. Ein fehlender optionaler Providerkey in einem älteren Formular
behält die ursprüngliche Bindung. Explizites `null` entfernt diese Bindung. Eine
bereits gebundene native Datengrundlage wird nicht umgedeutet; dafür ist ein
neues Feld erforderlich.

Bei geänderter oder fehlender Providerfassung bleibt der gespeicherte Wert im
nativen schreibgeschützten Control verfügbar. Ein gewöhnliches Speichern
anderer Angaben bewahrt den ausgelassenen Originalwert und dessen ursprüngliche
Definition. Der private Bereich „Typfassungen prüfen“ zeigt höchstens zehn
Unterschiede mit gespeicherter und aktueller Identität. Nur eine Person mit
`website.manage`, `website.edit` und sämtlichen aktuellen sowie gespeicherten
Seiten-/Feld-/Datensatzrechten kann ausgewählte Pfade bewusst übernehmen.
Die ersetzenden Bindungen stammen ausschließlich vom aktuellen Servermodell.
GET liest nur; ein veralteter Versionsstand liefert HTTP 409 und einen
bedienbaren eigenen Korrekturweg. Bereits veröffentlichte Revisionen bleiben
bei der privaten Übernahme unverändert.

## Lokale Registrierung

Ein geprüftes Hostmodul registriert in `initialize` über
`$context->fieldtypes()->register($name, new Fieldtypes\Definition(...))` oder
`->control($name, new Fieldtypes\Control(...))`. Die Registrierung ist auf die
aktuelle Anfrage und den tatsächlichen Modulverein begrenzt. Native und
integrierte Clubkennungen werden über die feste Manageridentität zugeordnet.

`Definition(label, storageType, version, normalizer)` akzeptiert die nativen
skalaren sowie Seiten-/Bild-/Dateireferenzgrundlagen. Passwort-, Kommentar-,
Repeater- und Seitentabellentexte werden keinem solchen Callback übergeben.
Native Mehrfachwerte und Repeater enthalten reguläre einzelne registrierte
Felder. Der Normalizer erhält `(nativeValue, definition, forSearch)` und muss
einen nativen Wert zurückgeben. Eingabe und Ergebnis werden kanonisch geprüft;
abschließend prüfen DataModel/Pages erneut Rechte, Originalreferenzen,
Datensatzversionen und Größen. Ein Fehler rollt die gesamte Seitenänderung
zurück.

`Control(label, storageTypes, version, renderer, parser?)` trennt Darstellung
und Formulartransport vom Speichervertrag. Der Renderer erhält
`(definition, exactInputName, nativeValue, readonly)`. Seine begrenzte HTML-
Ansicht darf ausschließlich den exakt gebundenen Eingabenamen senden. Formen,
Submitaktionen, Skripte, fremde Ressourcen, Ereignisattribute und Dateiuploads
sind ausgeschlossen. Schreibgeschützte Controls müssen deaktiviert sein.
Parser erhalten ausschließlich tatsächlich gesendete, aktuell und historisch
bearbeitbare Werte. Fehlende Controls werden nicht in leere Werte umgewandelt.

Callbacks müssen statische Closures aus realen, nicht verlinkten lokalen
Dateien sein. Geschlossene einfache Capturewerte sind begrenzt; Objekte,
Datenbankhandles und unprüfbare Laufzeitquellen sind ausgeschlossen. Alle
Callbackdateien müssen zum geprüften Modulquellensatz gehören. Ergänzende
Helfer und Assets deklarieren Module über `Extensions\SourceFiles`.

## Fassungen und Rechte

Gespeichert wird die geschlossene Identität
`{owner,name,version,source_hash}` getrennt für Feldtyp und Eingabefeld. Der
Hash bindet die vollständige Modulfreigabe und den tatsächlichen Callbackcode
einschließlich einfacher Capturewerte. Freigabe, Modulstatus und exakte Quellen
werden vor und nach jedem Callback frisch geprüft. Ein Widerruf oder geänderter
Helfer sperrt auch bereits aufgelöste Handles. Initialisierungsfehler entfernen
sämtliche Registrierungen dieses Moduls.

Unveränderte Originalwerte werden bei Veröffentlichung, Kopie, bestätigtem
kanonischem Neuimport und gewöhnlichen Änderungen anderer Angaben nicht erneut
normalisiert. Das gilt ausdrücklich für nicht idempotente Normalizer. Eine
ausdrücklich übernommene neue Feldtypbindung normalisiert ihren ausgewählten
Originalwert genau einmal und erzeugt eine private `fieldtypes-review`-
Revision. Reine Eingabefeldwechsel verändern keinen gespeicherten Wert.

Selektoren verwenden denselben Normalizer mit `forSearch=true`; textuelle
Teilstrings sind begrenzter Suchtext, keine vollständigen URLs oder aktuellen
Auswahloptionen. SQL bleibt geschlossen und parametergebunden. Aktuelle und
gespeicherte Typbindung müssen bereits vor COUNT/LIMIT übereinstimmen. Bei
widerrufenem Code läuft kein Callback und die kanonische Queryguard liefert
keine Treffer. Öffentliche Darstellung verwendet gespeicherte native Werte
unter den bestehenden Feld-/Seiten-/Kanalrechten, keine Editorcallbacks.

## Abnahme

`native-fieldtypes` prüft Registrierung, Quellbindung, Callbackfehler,
Clubzuordnung, aktuelle Freigaben und wirkliche Pages/DataModel-Abläufe in einer
isolierten MySQL-Datenbank. `DVM_FIELDTYPES_BROWSER=1` startet eine weitere eigene
frische Datenbank mit tatsächlichen authentifizierten GET/POST/CSRF-Wegen in
beiden Anwendungen, jeweils mit und ohne JavaScript. Die normale Actionsrunde
prüft 390px; `DVM_FIELDTYPES_LAYOUT=1` ergänzt 320/390/1024px und 200% Text.
Originaltabellen, bestehende Revisionen und Schlüssel werden bytegenau
gegengemessen. Die Testumgebung führt keine externen Aktionen aus.

## Kuratierte gemeinsame Auslieferung

Der unveränderliche Export auf dem abgeschlossenen API-/Routing-/Datei-/Konten-/Transfer-/Kommentarstand besteht 16 angrenzende Fachgruppen. Die bisherige geschlossene Querygrammatik bleibt erhalten; der eigene P-09-Selektor-/Leistungsausbau gehört nicht zu diesem Paket. Eine anschließende Compositionprüfung besteht 38 tatsächliche API-/Exportbedingungen und 79 Transferbedingungen. Nicht portable lokale Typ-/Inputfield-/Rendererbindungen werden bereits vor der Projektion ausdrücklich abgewiesen, einschließlich verschachtelter Originaldefinitionen. Es entsteht kein Teilpaket, Originale und Normalizerzählung bleiben exakt. Native Modelle bleiben übertragbar.

Die abschließende Feldtypenprüfung besteht 90 Fach-, 103 Katalog- und **1340 Browserbedingungen in 96 Ansichten mit zwölf realen POSTs** in beiden Apps mit und ohne JavaScript, bei 320/390/1024 Pixeln und einfacher/doppelter Schrift. 415 ursprüngliche Geschäftstabellen, acht ursprüngliche Revisionen, Medien und Schlüssel bleiben exakt. Ein realer zu langer Handykatalog wurde durch sechs vollständige Einträge pro SQL-Seite und passende Abstände korrigiert; menschliche Originale werden nicht gekürzt. Die gesonderte Fassungsauswahl behält ihre eigene Zehnergrenze.

Die bereits eingefrorene vollständige Rendererabnahme besteht 500 Fach- und 1094 Browserbedingungen in 224 Ansichten. Der neue kuratierte Zusammensetzungsnachweis besteht zusätzlich 80 Rendererbedingungen und 166 tatsächliche Aktionsbedingungen mit 20 geschützten POSTs für beide Apps/JavaScriptwege, echte News-/Programmlisten, Pagination und Jahresfilter. Hier bleiben 418 ursprüngliche Tabellen und Schlüssel exakt. Der neue optionale Rendererparameter erhält den bereits ausgelieferten positionalen Kommentar-Linkparameter; Produktaufrufe binden die beiden Optionen ausdrücklich über ihre Namen. Keine externen Aktionen und keine JavaScriptfehler.

- `kulturhus-extensible-isolated-domain.log`, SHA-256 `595fc87aa9169677162a9f82d7b23cc69c1b1c2b875ed8d7439b4394095e6abd`.
- `kulturhus-extensible-isolated-transfer-composition.log`, SHA-256 `a723e43b665f53deec416346b83c4a15e78614b37e231a2fdd0d4217edc8797e`.
- `kulturhus-extensible-isolated-fieldtypes-browser3.log`, SHA-256 `e651a4300ec3d25b25122336e8b2aebd089941314728d3b79eb8a9734464cd50`.
- `kulturhus-extensible-isolated-renderers-browser.log`, SHA-256 `5013067c5b3643b2a158af36ae0978bbcb8b599ec1c3255c2595612714150312`.
