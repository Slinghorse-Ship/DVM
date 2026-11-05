# Inhaltslisten und persönliche Suchansichten

In der Redaktion öffnet **Website → Inhalte suchen und filtern** eine gezählte
Liste mit zehn Treffern pro Seite. Die Auswahl aus bis zu acht Spalten, zwanzig
UND-/ODER-Bedingungen und einer Sortierung bleibt eingeklappt, bis sie gebraucht
wird. Vor-/Zurücklinks und die direkte Seitenwahl erhalten die aktuelle Abfrage.
Der erste Wert öffnet den ursprünglichen Inhalt; auch ein leerer Wert hat einen
bedienbaren Link. Ein Rücklink führt unmittelbar zur Seitenübersicht.

## Persönliche Ansichten

Eine eigene Suchansicht speichert Spalten, typisierte Filter und Sortierung unter
einem Namen. Höchstens fünfzig Ansichten gehören jeweils genau einem aktuellen
Vereinskonto. Andere Konten desselben Vereins können auch eine bekannte ID nicht
öffnen. Aktualisierung und Entfernen prüfen die ursprüngliche Version. Entfernen
blendet die Ansicht aus; die vorhandenen Definitionsrevisionen bleiben erhalten.

Listen lesen ausschließlich gewählte skalare SQL-Projektionen. Große
Inhaltstexte, Passwortwerte, Mehrfachwerte und verschachtelte Datensätze gelangen
nicht in die Ergebnismenge. Dezimalzahlen werden exakt sortiert; literale
Unicode-Suchwerte bleiben Daten. Feld- und Operatornamen stammen aus dem
bestehenden typisierten Selektorvertrag. Es gibt keine frei ausgeführten SQL- oder
JSON-Pfade. Der Dienst unterstützt bis zu drei Sortierschlüssel; die kompakte
Oberfläche zeigt eine Sortierauswahl.

Aktuelle Vereins-, Seiten- und Vorlagenrechte gelten bereits vor Zählung und
Seitengrenze. Eine verborgene Seite verrät dadurch weder ihren Titel noch ihre
Existenz über eine gefilterte Trefferzahl. Ein ausschließlich als
`display=hidden` definierter Vorlagenkontext ist eine Anzeige-/Bearbeitungsregel
und wird nicht als zusätzliche rollenabhängige Lesesperre ausgegeben.

## Geprüfte Stapelauswahl

Personen mit Veröffentlichungsrecht können ein bis fünfundzwanzig Originale der
aktuellen Trefferseite wählen. Die bestätigte Aktion bereitet ausschließlich den
bereits vorhandenen wiederaufnehmbaren Papierkorbstapel vor. Sie verschiebt noch
keine Seite. Vor dem Einfrieren prüft derselbe Clubmutex erneut die aktuellen
Filter, vollständigen Lese-/Veröffentlichungsrechte, eindeutigen IDs und genauen
Originalversionen. Die eigentliche Ausführung und ihr Fortschritt gehören zum
separaten Stapelablauf. Fremde, veraltete oder inzwischen unpassende Originale
werden nicht stillschweigend ausgelassen.

## Prüfung und Grenzen

Die ursprüngliche gemeinsame Abnahme besteht 31 Fachbedingungen und 1.475
Browserbedingungen in 48 tatsächlichen Ansichten: Verwaltung und Leser, sechs
Größen, normale/doppelte Schrift sowie JavaScript an/aus. Sie verwendet den normalen
Managerzugang, echte private Sitzungen, Formulare, Suchfelder,
UND-/ODER-Abfragen, persönliche Ansichten, CSRF-/Versionsfehler und eine
vorbereitete Zweierauswahl. 397 ursprüngliche Geschäftstabellen, beide
Schlüssel, Medien und fremde Suchansichten bleiben exakt. Keine externe Anfrage
und kein JavaScriptfehler. Nachweis:
`/tmp/kulturhus-content-lists-browser-final.log`, SHA-256
`272b2d7d423bb2bac469dd5098b7f7310d5b99f2b1dde8bfc943ddaeedb11ae5`.

Die danach ergänzten Vorlagenfilter bestehen getrennt 37 Fachbedingungen,
einschließlich SQL-Zählung vor Pagination, direkter unberechtigter
Stapelkennung und sofortiger Änderung der Trefferzahl nach einer berechtigten
Policyfreigabe. Dabei bleiben die Originalseiten unverändert. Nachweis:
`/tmp/kulturhus-content-lists-policy-regression.log`.
Die frühere Browserabnahme wird nicht rückwirkend als Prüfung dieser späteren
Änderungen ausgegeben.

Der abschließende neue Stand ist in beiden tatsächlichen Apps freigegeben:
37 Fachbedingungen und zusammen 96 Ansichten mit 3.050 Browserbedingungen.
Integrierter Manager: 48 Ansichten und 1.527 Bedingungen; eigenständiger nativer
Router: 48 Ansichten und 1.523 Bedingungen. Die echten UND-/ODER-Filter,
persönlichen Ansichten, aktuelle Seitenrechte, CSRF-/Versionsfehler und native
Formulare laufen jeweils vollständig. Beide Apps bereiten genau zwei gewählte
Originalversionen als fertigen Stapel vor; sie verschieben keine Originalseite.
Je 398 ursprüngliche Geschäftstabellen, Definitionen, fremde Suchansichten,
beide Schlüssel und Medien bleiben exakt. Keine externe Anfrage und kein
JavaScriptfehler. Auch der direkte Einstieg, Rücklink und die Beschriftung bei
leerem erstem Spaltenwert gehören zum jetzigen Quellstand.

- `/tmp/kulturhus-content-lists-integrated-complete.log`, SHA-256
  `5f691e37a5a85c400ede57fd4a0f5f843b1afc7e55c5a0e6e45ae15f35cffb1a`.
- `/tmp/kulturhus-content-lists-native-final.log`, SHA-256
  `1d7eef89532067059b3a820ad4e8dcc312d13efdfcf0f07266570cc148d0f14d`.

Die allgemeine Selektorgrammatik bleibt separat unter P-09.
