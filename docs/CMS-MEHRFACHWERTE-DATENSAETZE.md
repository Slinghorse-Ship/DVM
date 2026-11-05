# Mehrfachwerte, Datensätze und Seitentabellen

Die Feldverwaltung bietet zusätzlich zu den bisherigen zwölf Typen Datum mit
Uhrzeit, ein geschütztes Passwort, eine PDF-Verknüpfung, wiederholbare Datensätze
und eine Seitentabelle. Mehrfachwerte lassen sich für die gewöhnlichen Feldtypen
einschalten. Datensätze und Seitentabellen verwalten ihre Einträge selbst.

In der Feldverwaltung werden für einen Datensatz vorhandene Vereinsfelder
ausgewählt. Eine Seitenvorlage kann dasselbe Datensatzfeld wie andere Zusatzfelder
verwenden. Unterstützt werden bis zu 20 Unterfelder, drei Datensatzebenen und
höchstens 50 geordnete Einträge je Sammlung. Ein aufgelöstes Datensatzfeld enthält
höchstens 100 Feldzuordnungen; der gesamte Seiteninhalt bleibt auf 2 MiB begrenzt.
Eigene kürzere Eintragsgrenzen sind möglich. Eine Seitentabelle speichert echte,
vereinseigene Seitenreferenzen; sie kopiert keine fremden Seiteninhalte.

Die Redaktion zeigt bestehende und neue Einträge als kurze aufklappbare Karten.
„Weiterer Eintrag“ ergänzt echte typisierte Felder. Die Pfeiltasten ändern deren
Reihenfolge; die Seite wird erst mit ihrer normalen Speicheraktion gespeichert.
„Beim Speichern entfernen“ funktioniert auch ohne JavaScript. Ein freier neuer
Eintrag ermöglicht auch das Hinzufügen mit einem gewöhnlichen HTML-Formular.
Unbenutzte neue Einträge lösen keine Pflichtfehler aus. Feldbedingungen gelten
innerhalb des jeweiligen Datensatzes und erhalten ihre bisherigen Angaben.

Jeder gespeicherte Datensatz erhält eine eigene Kennung und Versionsnummer.
Änderungen werden gegen den tatsächlichen vorherigen Seitenstand geprüft;
veraltete Datensatzversionen werden zurückgewiesen. Die Verwaltung kann die
Bearbeitung zusätzlich auf ausgewählte Vereinsmitglieder einschränken und
Einträge ausschließlich intern führen. Diese Auswahl erweitert keine
allgemeinen Website-Rechte. Geschützte Einträge dürfen durch eine unberechtigte
Speicheranfrage weder geändert, ausgelassen noch verschoben werden. Normale
Änderungen an anderen Angaben bleiben möglich. Ein internes Bild oder PDF wird
durch seine bloße Zuordnung zu einem internen Eintrag nicht öffentlich verfügbar.

Passwörter werden ausschließlich als Einwegprüfsumme gespeichert. Das
Eingabefeld bleibt nach dem Speichern leer; eine leere Eingabe erhält den
gespeicherten Wert. Ein optionales Passwort kann ausdrücklich entfernt werden.
Weder die Website noch die Formularansicht oder der historische Vergleich zeigen
die Prüfsumme. Auch die redaktionelle API lässt Passwortwerte aus; ein erneutes
Speichern dieses Datensatzes erhält die ausgelassenen Geheimnisse. Neue
vorgefertigte Prüfsummen werden nicht als Passworteingabe akzeptiert.

Die PDF-Verknüpfung verwendet die bestehende unveränderliche Website-Dokumentablage
und deren gezählte Suche mit jeweils 25 Metadatentreffern. Newsletter-Anhänge
werden nicht als Website-Dokumente angeboten oder übernommen. Ein öffentlicher
Download benötigt eine aktuelle veröffentlichte Originalrevision mit genau
passender Dokumentkennung, Dateigröße, Dateiname und Prüfsumme. Allgemeine
Dateiformate und logische ersetzbare Dateiversionen sind ein eigener weiterer
Ausbau; dieses Feature behauptet dafür keine Fertigstellung.

Selektoren unterstützen Sammlungseinträge und verschachtelte Datensatzpfade,
beispielsweise `custom.team.name`. Mehrfachwerte unterstützen `eq`, `ne`, `in`
und bei Text `contains`/`starts_with`; `eq`/`ne` mit `null` prüfen die leere
Sammlung. Eine mehrdeutige Sortierung nach einer ganzen Sammlung wird abgewiesen.
Passwortfelder sind nicht abfragbar. Die öffentliche Suche und JSON-Projektion
lassen interne Einträge, verborgene Felder und Passwortwerte aus.

Historische Wiederherstellung erhält die damaligen typisierten Werte und
Feld-/Vorlagendefinitionen. Datensatzrechte werden gegen den aktuellen Entwurf
geprüft, geänderte Datensätze erhalten neue Zähler. Das veröffentlichte Original
bleibt unverändert. Eine inzwischen geänderte aktuelle Felddefinition verlangt
vor einer neuen Veröffentlichung ein bewusstes Prüfen und erneutes Speichern.
Der Vergleich stellt Sammlungen und verschachtelte Angaben typisiert dar.

## Prüfung

`native-typed-fields` verwendet ausschließlich eine frische synthetische
MySQL-Datenbank. `DVM_TYPED_FIELDS_BROWSER=1` ergänzt einen eigenen lokalen
HTTP-Prozess mit den tatsächlichen `ContentModel`-/`Pages`-Diensten, CSRF-Prüfung
und lokalem Bootstrap. Chromium prüft 1280, 768 und 320 Pixel, vergrößerte Schrift,
echte Metadatensuche und Speicheranfragen, Originalkennungen, Reihenfolge,
geschützte Einträge sowie das Hinzufügen ohne JavaScript. Kein realer
Anbietertransport wird ausgeführt. Die Domainprüfungen kontrollieren außerdem
historische Typwechsel, unveränderte Veröffentlichungen, Medien-/PDF-Freigaben,
verschachtelte Fremdvereinsverweise und die redigierten API-Projektionen.

`DVM_TYPED_FIELDS_APP_BROWSER=1` ergänzt beide tatsächlichen App-Einstiege:
`native/Web/router.php` sowie `public/index.php` mit der integrierten
Manageroberfläche. Die Prüfung installiert dafür eine zweite, vollständig neue
eigene Datenbank mit `SchemaRegistry` und echten Native-Auth-Sitzungen. Sie
verwendet keinen Ersatz-Speicherhandler. Der Browser prüft beide Apps mit und
ohne JavaScript sowie 24 Kombinationen aus Breite und Schriftgröße. Zehn echte
POST-Anfragen prüfen Speicherung, CSRF, Reihenfolge und den Originalschutz
eingeschränkter Datensätze. Der vorhandene Richtexteditor erhält ohne JavaScript
seinen gespeicherten Inhalt; einfache neue Datensatzangaben bleiben bearbeitbar.

Abnahme vom 15. Oktober 2026: 45 Domainbedingungen sowie zwei zusätzliche
Originalbedingungen beim isolierten Browserlauf, 40 isolierte Browserbedingungen
und 196 Browserbedingungen über beide echten Apps sind grün. Sichtbare
Sammlungsaktionen erreichen mindestens 44 × 44 Pixel. Der anschließende Vergleich
bestätigt unveränderte Inhalte in 397 nicht beteiligten Tabellen, beiden
Originalschlüsseln, der veröffentlichten Originalseite und dem Original-PDF.
Die gemeinsame Regression von 14 angrenzenden Gruppen ist nach der
Vorlagenrichtlinien-Integration erneut grün, einschließlich des tatsächlichen
500-Seiten-Kopierablaufs. Die eigene Policy-Abnahme bleibt gesondert dokumentiert.

- Domain-/Browserlauf: `/tmp/kulturhus-typed-fields-touch-compact-final.log`, SHA-256
  `52ada5a56fb7e4db77eb6811a913d89f2c2321b5d29a50ab017a16b50b9ab681`.
- Beide tatsächlichen Apps: `/tmp/kulturhus-typed-fields-real-apps-fifth.log`,
  SHA-256 `f91036dc3d4188a975e76ffa6c213d5276c17095491ee0e747344632a1342348`.
- Gemeinsame 14er-Regression:
  `/tmp/kulturhus-typed-fields-policy-adjacent-final.log`; der abschließende Lauf
  meldet `suites: 14, failed: []`.

Diese Abnahme ersetzt keinen Nachweis für allgemeine ersetzbare Dateien,
öffentliche Kommentar-Feldtypen, frei programmierbare Fieldtype-Plugins oder die
noch separat zu prüfenden Vorlagenrichtlinien.
