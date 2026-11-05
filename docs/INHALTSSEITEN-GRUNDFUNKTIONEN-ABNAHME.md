# Inhaltsseiten: verbindliche Softwareabnahme K-08

Stand 15. Oktober 2026. Neue Inhalte entstehen im eigenen CMS; keine alten
Vereinsinformationen, Dateien oder URLs werden aus alten Beständen importiert.

| Benötigte Funktion | Eigene Umsetzung und Nachweis |
|---|---|
| Vereinsinformationen und eigene Verlinkungen | Standardseiten mit Titel, Adresse, formatiertem Hauptinhalt, eigener Einleitung, sicheren Links und vorhandenen Dokumentverweisen; dieselbe öffentliche Originalprojektion wie die übrige Website |
| Modulare Inhalte | Bis zu 20 versionierte Text-/Bildabschnitte mit Reihenfolge, eigenen Beschriftungen, Credits und optionalen Schaltflächen; tatsächliches Einfügen, Sortieren und ausdrückliches Entfernen |
| Kurze Bearbeitung | Direkter Sprung zur Abschnittsauswahl, einzeln geöffnete nummerierte Karten und erkennbarer zusätzlicher Platz; übrige Websitebereiche bleiben in einem eigenen aufklappbaren Menü erreichbar |
| Gespeicherte Inhalte lesen | Persönliche Lesekonten erreichen die vollständige private Vorschau einschließlich aller aktuellen Abschnitte und ihrer geschützten Bilder, ohne Bearbeitungsformular |
| Entwurf und Veröffentlichung | Private Änderungen bewahren die genaue öffentliche Fassung, veröffentlichte Version, Medienbytes und öffentliche Generation |
| Fehler und Wiederaufnahme | Tatsächlicher Versionskonflikt, falsches CSRF und korrigierbarer ungültiger Link; vorgeschlagene Werte und ursprüngliche Version bleiben erhalten |
| Andere ungespeicherte Eingaben | Der direkte Sprung erhält den Hauptinhalt. Abbrechen einer formularübergreifenden Speicherbestätigung behält beide Vorschläge ohne POST. Nach ausdrücklicher Freigabe erfolgt genau eine eigene Speicheranfrage |
| Handy, Tablet und Tastatur | Beide Zugänge bei 1440/820/390/320 Pixeln, native aufklappbare Karten, Tastaturfokus und JavaScript-freie Bedienung; doppelte Vergrößerung bei 320 Pixeln |

## Tatsächliche Prüfung

```sh
DVM_STANDARD_PAGES_BROWSER=1 bash tools/ci/local-native.sh --core native-standard-pages
```

Der abgeschlossene Lauf besteht **236 Browserverträge und 20 bewusste private
Speicherungen** an acht eigenen Seiten. Je Zugang erfolgen neun fachliche
Abschnittsspeicherungen und eine ausdrücklich bestätigte formularübergreifende
Speicherung. Das Abbrechen löst keine Speicheranfrage aus. Die realen persönlichen
Lesekonten öffnen jeweils die vollständige private Vorschau und ein tatsächliches
Bild. Öffentliche Vergleichsseiten liefern wirklich HTTP 200; gleiche Fehlerseiten
werden nicht als erhaltene Veröffentlichung akzeptiert.

Die Auswahl mit zwei ursprünglichen Abschnitten und einem zusätzlichen Platz
bleibt bei allen vier normalen Breiten unter 1000 Pixeln; ohne JavaScript bei
320 Pixeln und doppelter Vergrößerung unter 1800 Pixeln. Die eigentlichen
Bearbeitungsfelder öffnen sich gezielt. Diese Grenzen werden nicht pauschal auf
beliebig lange kundeneigene Inhalte oder zwanzig gleichzeitig geöffnete Karten
übertragen.

SHA-256 des vollständigen lokalen Browserlogs:
`4df60cf454f06ec6abbb6b3aa382a77d8fcf28eb8d32d2bf7088d7e3c61038c4`.

Acht native Fachgruppen bestehen zusätzlich: Inhaltsabschnitte, Bildfokus/-zuschnitt,
Website-Arbeitsbereich, Produktionsredaktion, gespeicherte Beitragsansicht,
Standardseiten, Navigation und Vorlagenauswahl. SHA-256 dieses abgeschlossenen Logs:
`830db915e372f36d41195dd8970ab466e83571c6fe030969a6dc21a74fb6a139`.
Die Tests verwenden eigene frische Datenbanken, persönliche Einladungen und
Sitzungen, separate freie Ports und private Medien außerhalb beider Webroots.
Vorhandene Entwicklungsdaten und Schlüssel werden nicht verwendet oder ersetzt.

Genaue Titel, Haupttexte, Felder, Sektionenkennungen, Medienzuordnungen, Beschriftungen,
Fotobytes, veröffentlichte Originale, private Schlüssel und sämtliche nicht zum
bewusst bearbeiteten Seitenbestand gehörenden Tabellenzeilen bleiben geschützt.
Keine echte Zustellung, Zahlung oder Veröffentlichung außerhalb der lokalen
Prüfbestände. Generation 2026101516, keine neue Tabelle, 245 native Gruppen.

K-08 ist damit im vereinbarten Softwareumfang implementiert. Die übrigen
Manager-/Fachbereiche, Apps und abschließende gemeinsame Statusseite behalten
separate Aufgaben. Der vollständige unveränderte 243-Lauf auf `4bb4f54` wird
nicht rückwirkend um diese späteren Änderungen erweitert.
