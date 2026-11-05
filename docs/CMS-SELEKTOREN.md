# Inhalte gezielt auswählen

Unter **Website → Inhaltsliste → Spalten & Filter** stehen einzelne Bedingungen
und ein freier Suchausdruck zur Wahl. Beide verwenden dieselben geprüften
Feldtypen und Rechte. Die Liste zeigt zehn Treffer; eigene Ansichten speichern
Spalten, Bedingungen und Sortierung. Ein ungültiger Ausdruck bleibt zur Korrektur
stehen und zeigt keine ungefilterte Ersatzliste.

Während eine neue Trefferliste lädt, bleiben deren bisherigen Speicher- und
Stapelaktionen gesperrt. Bei Verbindungsfehlern oder Zeitüberschreitung werden
dieselbe Ansicht und ihre Eingaben wieder bedienbar; der Fokus kehrt zum
auslösenden Knopf zurück. Gespeichert wird die anschließend sichtbare Suchansicht.

```text
custom.beitrag >= 12.500001 AND (status = "draft" OR title contains_ci "Sommer")
custom.termine >= "2026-10-15T14:30"
custom.verknuepfungen.title contains_ci "Kultur"
custom.gruppen.betraege < -4.000001
```

Texte, Kennungen und Datumswerte stehen in doppelten Anführungszeichen; darin
gelten JSON-Escapes. Dezimalzahlen bleiben exakte Zeichenfolgen und werden nie
über einen Float umgerechnet. `true`, `false` und `null` sind eigene Werte.
`AND` bindet stärker als `OR`; Klammern setzen Gruppen. Die symbolischen
Operatoren `=`, `!=`, `>`, `>=`, `<`, `<=`, `*=` und `^=` entsprechen `eq`, `ne`,
`gt`, `gte`, `lt`, `lte`, `contains` und `starts_with`. `IN ["a", "b"]` wählt aus
bis zu 100 typgeprüften Werten. `contains_ci` und `starts_with_ci` ignorieren
Groß-/Kleinschreibung; Prozentzeichen und Unterstriche bleiben gewöhnlicher Text.

Datumsfelder verwenden `JJJJ-MM-TT`, lokale Datumzeiten `JJJJ-MM-TTTHH:MM`.
`created_at`, `updated_at` und `published_at` verwenden UTC im Format
`JJJJ-MM-TTTHH:MM:SSZ`. Bei Veranstaltungen ist `event_start` die gespeicherte
lokale Beginnzeit und `event_day` deren Tag. `event_start_utc` und `event_end`
verwenden die gespeicherten UTC-Zeitpunkte. Datums-/Zeit-, Integer- und
Dezimalfelder erlauben Bereichsvergleiche.

Mehrfachfelder treffen zu, wenn ein Mitglied den Vergleich erfüllt. `ne` bedeutet,
dass kein Mitglied dem Wert entspricht. `eq null` bezeichnet eine leere Liste;
`ne null` eine gefüllte. Unterfelder eines Repeaters verwenden ihre echten
typisierten Werte, auch bei verschachtelten Gruppen. Unterschiedliche Bedingungen
können verschiedene Einträge derselben Gruppe erfüllen. Für eine Sortierung nach
Mehrfachwerten wird ausdrücklich **Kleinster Wert**, **Größter Wert** oder
**Anzahl** gewählt. Ganze Datensatzgruppen erlauben ihre Anzahl; kleinster und
größter Wert benötigen ein skalares Unterfeld. Einzelwerte brauchen diese
Zusammenfassung nicht.

Eine Seitenreferenz lässt sich über `custom.<feld>.<zielfeld>` verfolgen,
eine Elternseite über `parent_id.<zielfeld>`. Auch PageTable und Referenzen in
Datensätzen funktionieren. Am Ziel gelten dessen aktuelle Seitenrechte und die
aktuellen sowie ursprünglich gespeicherten Feldrechte. Fremde Vereine und
Papierkorbinhalte liefern keine Relationstreffer. Geschützte Felder können weder
über Trefferzahlen noch über die Reihenfolge ausgeforscht werden. Passwort- und
Kommentarfelder sind nicht auswählbar. Kommentarbeiträge bleiben im eigenen
Gesprächsbereich.

Der native Vertrag erlaubt bis zu 20 Bedingungen, drei Gruppenebenen, drei
verfolgte Seitenverknüpfungen und drei Sortierfelder. Der Ausdruck umfasst
höchstens 16 KiB; ein Feldpfad höchstens 800 Bytes. Die Inhaltsliste erlaubt acht kurze Spalten; vollständige
Seitenkörper werden dort nicht als freie Spalte oder Filter angeboten.
Alle Trefferzahlen verwenden dieselben Bedingungen wie ihre begrenzte Seite.

Die API verwendet denselben Ausdruck:

```json
{
  "expression": "custom.beitrag >= 12.500001 AND title contains_ci \"Sommer\"",
  "order": [{"field": "custom.beitrag", "direction": "desc"}],
  "page": 1,
  "limit": 10
}
```

Dieser Leseaufruf geht an `POST /api/v1/pages/query`. Alternativ bleibt die
bestehende typisierte `selectors`-Liste möglich. Beides gleichzeitig wird
abgewiesen. Eine Sitzung benötigt ihren CSRF-Token; ein ausdrücklich berechtigter
Bearer-Zugang verwendet seinen eigenen Gültigkeits- und Vereinsnachweis.
Der Leseaufruf erstellt keinen Änderungsauftrag und veröffentlicht keine Inhalte.

Die Inhaltsliste begrenzt jeden textuellen Anzeigewert bereits in SQL auf 1.000
Unicodezeichen. Vergleiche und Sortierschlüssel verwenden weiterhin den ganzen
berechtigten Originalwert. Trefferzahl, stabile Reihenfolge und begrenzte Seite
entstehen in einem gemeinsamen MySQL-Fensterquery; ein Leseschloss hält die
Modell-/Publikationsgeneration währenddessen fest. Das native JSON-Dokument der
aktuellen Seite wird innerhalb dieser Auswahl einmal projiziert. Dabei entsteht
kein dauerhafter Inhaltscache. Öffentliche Originale und geerbte Rechte behalten
jeweils ihre eigene Quelle.

MySQL kürzt lange TEXT-Sortierschlüssel sonst über `max_sort_length`. Nur eine
Sortierung nach entsprechend langen Text-, URL- oder HTML-Werten erhöht deshalb
für diesen einen Leseaufruf `max_sort_length` auf höchstens 4 MiB und den
inkrementell beanspruchten `sort_buffer_size` auf höchstens 256 MiB, sofern die
bereits eingerichteten Sitzungswerte kleiner sind. Das ist ein serverseitiges
Worstcase-Budget für bis zu drei vollständige erlaubte Schlüssel, keine
PHP-Antwortgröße. Dezimalzahlen, Zähler, Kennungen, Zeitwerte und kurze native
Titel-/Adress-/Vorlagenschlüssel benötigen diesen Weg nicht. Beide ursprünglichen
Sitzungswerte werden in `finally` wiederhergestellt, auch bei fehlgeschlagenem SQL
oder einem verweigerten zweiten `SET`. Verweigert MySQL eine notwendige Einstellung,
schlägt die Suche geschlossen fehl; sie liefert keine ungenau sortierte Ersatzliste.

`native-selector-window` prüft lange Unicode-Anzeigewerte, die tatsächliche
Reihenfolge am Ende erlaubter HTML-Werte, alle drei Lesewege und die SQL-/SET-
Fehlerpfade in echten Unterprozessen mit 16 MiB PHP-Speicher. Der zusätzliche Lauf
`DVM_SELECTOR_LOAD=1 bash tools/ci/local-native.sh --core native-selector-relations`
erzeugt ausschließlich eigene synthetische Daten. Im ruhigen Einzellauf wurden
2.500 Entwürfe mit 94.980.280 gespeicherten Bytes, 1.250 echten Relations-/Mehrfach-
Treffern und der letzten Zehnerseite in 1,272 Sekunden bei 2 MiB PHP-Spitze gelesen.
Alle eigenen Original-/Modellrevisionsbytes und Sitzungswerte blieben exakt.
Dies ist ein einzelner lokaler Speicher-/Laufzeitnachweis, keine Aussage über
parallelen Produktivdurchsatz.

## Auslieferungsabnahme

Die kuratierte Auslieferungsfassung besteht elf Fachgruppen mit 781 Bedingungen:
Vorlagen-/Feldrechte, typisierte Suche, Grammatik, Relationen, Feldtypen,
Frontendrenderer, gespeicherte Listen, Antwortbudget, API und lange Sortierwerte.
Alle drei echten Lesewege bleiben auch mit 16 MiB PHP-Speicher begrenzt.

Die tatsächlichen Browserläufe bestehen 1.688 native und 1.696 integrierte
Bedingungen in zusammen 96 Ansichten. Geprüft sind JavaScript und Bedienung ohne
JavaScript, sechs Bildschirmgrößen, 16/32-Pixel-Schrift, echte Formulare, AND/OR,
Relationen/Mehrfachwerte, exakte Dezimalzahlen, ungültige menschliche Eingaben,
gespeicherte Ansichten, veraltete Versionen und begrenzte Stapelvorbereitung.
Ein abgebrochener GET erhält alte Treffer und Eingaben, sperrt während des Ladens
veraltete Aktionen und stellt danach den Tastaturfokus wieder her.
425 ursprüngliche Tabellen, Felddefinitionen, fremde Suchansichten, Schlüssel
und Mediendateien bleiben exakt; keine externen Anfragen oder JavaScriptfehler.

P-09 ist damit als Softwarevertrag implementiert. Die oben ausgewiesene lokale
Einzellaufmessung ist eine getrennte begrenzte Lastprobe; eine parallele
Produktivlastmessung wird daraus nicht abgeleitet.
