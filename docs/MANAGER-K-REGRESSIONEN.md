# Gemeinsame K- und Manager-Regressionen

Der aktuelle Abnahmestand steht in den beiden Umsetzungsregistern. Dieses
Dokument beschreibt die wiederholbaren Prüfwege. Historische Erfolgszahlen
ersetzen keinen Lauf auf dem aktuellen Quellstand.

## Kerngruppen

```sh
bash tools/ci/local-native.sh --core
```

Der Runner legt für jede registrierte Gruppe eine eigene synthetische
MySQL-Datenbank an und entfernt sie anschließend. Vorbereitete Vereine,
Entwicklungskonten und Schlüssel sind keine Testdatenquelle. Die bestehenden
Transportverbote gelten auch für Kindprozesse. Der abschließende Datensatz
nennt die tatsächliche Gruppenzahl und sämtliche fehlgeschlagenen Gruppen.
Nach jeder vollständig ausgeführten Gruppe einschließlich ihrer Bereinigung
stehen zusätzlich `completed_suite`, `exit_code` und `cleanup_complete` im
Protokoll. Ein abgebrochener Lauf ersetzt keinen vollständigen Erfolgsnachweis.

Bei einer legitimen Schemaerweiterung werden die vollständigen früheren Zeilen
und die genaue erwartete Schemaänderung getrennt geprüft. Das Standardverhalten
von `ownOriginalDatabaseHash` schützt weiterhin die Metadaten aller Tabellen.
Eine neue Tabelle ist kein Grund, ursprüngliche Spalten, Schlüssel oder Werte
aus dem Schutz auszunehmen. Die Paketupgrade-Prüfung verwendet zusätzlich eine
frisch installierte, unabhängige Referenz für die deklarierten Ergänzungen.

## Website- und Medienbedienung

```sh
env -u DVM_K_BROWSER_CASE DVM_K_LEGACY_BROWSER=1 \
  bash tools/ci/local-native.sh --core native-k-browser
```

Die Gruppe erzeugt zwei neue Anwendungen mit dynamischen lokalen Ports,
getrennten Datenbanken und vier unabhängigen Originalschlüsseln. Verwaltung und
Lesekonto melden sich in beiden Anwendungen über die tatsächlichen persönlichen
Passwortformulare an. Es gibt keinen Rollenwähler oder zusätzlichen Login-Endpunkt.
Die ursprünglichen Browserverträge laufen gegen diesen eigenen Bestand:

| Ablauf | Browsergruppe |
|---|---|
| Startseite, private Vorschau, Veröffentlichung und Wiederherstellung | `native-home-layout-browser` |
| Website-PDFs, Mehrfachupload, private Zuordnung und öffentlicher Download | `website-documents-app-browser` |
| Öffentliche Alben und Originalbilder | `public-albums-app-browser` |
| Albensuche, Seitenfenster und versionsgeschützte Reihenfolge | `album-catalogue-app-browser` |
| Bildangaben | `native-media-metadata-browser` |
| Bildfreigabe und aktuelle Rechte | `native-media-policy-browser` |
| Speichergrenzen | `native-media-quota-browser` |
| Ursprüngliche Bildverwendung | `native-media-usage-browser` |
| Website-Fußzeile | `native-website-footer-browser` |
| Veranstaltungsfeeds und HTTP-Verträge | `native-feed-http-browser` |
| Originalbilder in Veranstaltungsfeeds | `native-feed-images-browser` |

Reine Leseabläufe erhalten sämtliche ursprünglichen Zeilen und erlauben keine
zusätzlichen Einfügungen. Schreibabläufe prüfen jeweils ihre genaue eigene
Änderung, öffentliche Originale und unbeteiligte Nachbarn. Quelle, Konfiguration
und Originalschlüssel bleiben während des gesamten Laufs gebunden.

Vor den Browserabläufen erzwingt die Gruppe die PHP-Sitzungsbereinigung. Zwei
eigene Konten melden sich über die tatsächlichen Passwortformulare an.
Nur die eigene lokale Sitzungsdatei des Lesekontos wird um 30 Minuten gealtert;
ein Verwaltungsabruf löst die Bereinigung aus. Das weiterhin gültige Lesekonto
muss danach die geschützte Übersicht erreichen. Vollständige ursprüngliche
Datenbankzeilen und Kontonachweise bleiben dabei unverändert. Dieser Vertrag
läuft mit `DVM_K_SESSION_GC=1` auch ohne Browser. Er verlängert keine fachliche
Anmeldedauer und umgeht weder Ablauf noch Widerruf.

Die lokalen Prüfrouter liefern ihre unveränderten, ausdrücklich gebundenen
statischen Dateien nach denselben Ursprungs- und Pfadprüfungen aus, bevor sie
die übrigen Fachmodule laden. Jede Fachanfrage durchläuft weiterhin den
vollständigen tatsächlichen Controller. Ein früherer abgebrochener Lauf auf
dem langsameren Router zählt nicht als vollständiger Erfolgsnachweis.

Der Ergebnisdatensatz muss `completed_browser_cases:11` und
`diagnostic_case:null` sowie `failed_browser_cases:[]` enthalten. Fehler werden
pro Ablauf festgehalten; die übrigen unabhängigen Abläufe werden weiter geprüft.
Jeder einzelne Fehler lässt die gesamte Gruppe fehlschlagen. Der ausdrücklich wählbare
Diagnosefilter `DVM_K_BROWSER_CASE` akzeptiert registrierte, kommagetrennte
Ablaufnamen und ist kein vollständiger Durchlauf. Der allgemeine Browserrunner
entfernt diesen Diagnosefilter selbständig.

Mit `DVM_K_BROWSER_PROOF_DIR` lassen sich Bildschirmbilder, Überlaufgeometrie
und HTTP-Protokolle in einem vorher angelegten privaten Verzeichnis aufbewahren.
Zugangsdaten, Sitzungsspeicher und Schlüssel werden nicht dorthin kopiert. Die
eigenen Laufzeitbestände werden auch nach einem Fehler entfernt.

## Vollständiges rollenabhängiges Menü

```sh
env -u DVM_MANAGER_CONTROLS_FAST \
  -u DVM_MANAGER_CONTROLS_PROFILE_ONLY \
  -u DVM_MANAGER_CONTROLS_TRANSITION_ONLY \
  DVM_MANAGER_CONTROLS_BROWSER=1 \
  bash tools/ci/local-native.sh --core native-manager-controls
```

Die Routen stammen aus dem tatsächlichen Menü von Verwaltung, Mitglied und
Redaktion. Sechs Bildschirmgrößen, zwei Schriftgrößen und beide JavaScript-Modi
gehören zur vollständigen Matrix. Hinzu kommen die echte Bootstrap-Navigation,
Touch- und Tastaturaktionen, Fehlerkorrektur, geschützte ungespeicherte Eingaben
und das befüllte Mitgliedsprofil bei zweifachem Zoom. Die Diagnoseabkürzungen
ersetzen diese Matrix nicht.

```sh
env -u DVM_APPEARANCE_ACTIONS_ONLY DVM_APPEARANCE_BROWSER=1 \
  bash tools/ci/local-native.sh --core native-appearance
```

Die persönliche Ansichtsprüfung umfasst Studio, Fokus und Kompakt. Bei
ausgeblendeter Tablet-Navigation muss der tatsächliche Inhaltsbereich die ganze
Lesebreite erhalten, auch mit verdoppelter Schrift und ohne JavaScript. Eine
schmale Spalte ohne horizontalen Überlauf genügt dieser Bedingung nicht.

Die abschließende gemeinsame Fachbrowserprüfung ist über
`bash tools/ci/local-native.sh --browsers` erreichbar. Sie enthält auch die
Website-/Mediengruppe und die echte Webspace-Browserinstallation.

## Unveränderte öffentliche Bildangaben

Eine private Metadatenänderung erhöht den aktuellen Cache-/Rechtezähler einmal.
Die Browserprüfung bindet genau diesen Wechsel und vergleicht alle übrigen
Routingfelder sowie jedes andere Byte der veröffentlichten Ausgabe unverändert.
Bildtexte, Fotocredits, ursprüngliche Pixel, Freigabefassungen und verschlüsselte
Nachweise werden unabhängig davon geprüft. Die zugehörigen öffentlichen
Vorher-/Nachher-Ausgaben lassen sich im privaten Belegverzeichnis aufbewahren.

Bei JavaScript-freien Uploads wartet die Bedienprüfung auf die tatsächliche
Navigation, sämtliche Stylesheets und Schriften, bevor sie Breiten misst.
Eine noch nicht gestaltete Zwischenansicht ist kein fertiger Layoutzustand.

## Vergrößerte Schrift und Schaltflächen

Bei doppelter Schrift bleibt die feste Navigation in Studio auf höchstens
320 Pixel und in Kompakt auf höchstens 280 Pixel begrenzt. Die Bankübersicht
ordnet ihre Statuskarten nach der tatsächlichen verfügbaren Breite an;
lange Bezeichnungen bleiben innerhalb ihrer eigenen Karte lesbar.

Bootstrap liefert die Schaltflächenregeln in einer CSS-Schicht. Die ältere
ungeschichtete allgemeine Linkfarbe darf diese Farben bei Schaltflächen nicht
überschreiben. Die gemeinsame Brücke übernimmt die jeweiligen Bootstrap-Farben
für normalen, schwebenden, aktiven und deaktivierten Zustand. Die tatsächliche
Galeriebedienung misst zusätzlich in beiden JavaScript-Modi bei allen gewählten
Breiten und Schriftgrößen mindestens 4,5:1 Textkontrast für Normal-, Hover- und
Tastaturzustand. Der Tastaturfokus muss dabei tatsächlich sichtbar sein.

Der Menütest sammelt unabhängige Layout- und Kontrollfehler über die gesamte
Matrix. Jeder Befund lässt den abschließenden Lauf fehlschlagen; er wird nicht
als bestandene Bedingung gezählt. Navigations- oder Laufzeitfehler brechen weiter
sofort ab. Ursprüngliche Geschäftsdaten und Schlüssel werden auch bei einer
fehlgeschlagenen Browserprüfung im abschließenden Schutzblock verglichen.
