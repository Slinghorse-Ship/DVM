# Öffentliche Website-Suche in der gewählten Sprache

Die allgemeine Suche ist in beiden Webanwendungen unter `/search` erreichbar.
Mit `/search?lang=en&q=Suchtext` bleibt eine eingerichtete, aktive Website-Sprache
beim Formular, Ergebnislink, Seitenwechsel, Sprachwechsel und im Footer erhalten.
Die kanonische Suchadresse bleibt ebenfalls auf dieser tatsächlich vorhandenen
Route. Es wird keine fiktive Inhaltsseite unter `/en/search` verwendet.

Treffer stammen aus der tatsächlich veröffentlichten Originalfassung und den
dazu passenden veröffentlichten Übersetzungen. Die eingerichtete Sprachkette
bestimmt fehlende Texte. Neuere Original- und Übersetzungsentwürfe ändern weder
Treffer noch Ergebnisadressen. Eine neue Originalveröffentlichung entfernt eine
nicht mehr passende alte Übersetzung sofort aus der Suchprojektion.

Gesucht wird nach wörtlichem Unicode-Text in Titel, Kurztext, Inhalt, endgültig
übersetzten Inhaltsabschnitten und öffentlichen Zusatzfeldern, einschließlich
sichtbarer verschachtelter Datensätze. Bei Veranstaltungen kommen Ort und
Adresse, bei der Startseite die eingerichteten sichtbaren Layouttexte hinzu.
Passwörter und private Datensätze bleiben ausgeschlossen. SQL- und
Regex-Sonderzeichen erhalten ihre wörtliche Bedeutung.

Die öffentliche Oberfläche zeigt 20 Treffer pro Seite und die vollständige
Trefferzahl. Sie liest Metadaten in 100er Gruppen und projiziert jeweils eine
Originalfassung, ohne sämtliche Originalinhalte im PHP-Speicher zu sammeln.
Der bestehende erste-50-Treffer-Dienst bleibt kompatibel. Ein Veröffentlichungs-
oder Rechtewechsel während des Lesens verlangt eine konsistente neue Prüfung;
aktive Vorlagen- und geerbte Seitenregeln gelten bereits vor der Auswahl.

Die Formulare und Seitenwechsel funktionieren ohne JavaScript. Die echte
standalone HTTP-Abnahme prüft außerdem HEAD, ETag, 304, abgelehnte Schreibzugriffe
und fehlerhafte Sprach-/Filterwerte. Die Sprachbrowsermatrix prüft beide echten
Anwendungsrouter einschließlich des 20-plus-1-Seitenwechsels.

Lokale Fachprüfungen:

```sh
bash tools/ci/local-native.sh --core native-public-search-languages native-public-search native-public-router
```

`native-public-search` führt auch einen eigenen PHP-Prozess mit 16 MiB
Speichergrenze aus, der über 16 MiB veröffentlichte Originalinhalte einschließlich
der echten Sprachprojektion vollständig durchsucht. Die Prüfungen verwenden
ausschließlich eigene synthetische MySQL-Daten und lokale HTTP-Verbindungen.

Der letzte frische Suchlauf vom 15. Oktober 2026 besteht 56 Sprachsuchbedingungen,
49 Suchbedingungen einschließlich beider Speicherprozesse und 115
PublicRouter-Bedingungen. Drei Suiten, keine Fehler.
Protokoll: `/tmp/kulturhus-public-search-release-candidate.log`, SHA256
`59d2e93d3d4b9587795a7f19f1e56a05a32673618888469889ccbaca6a49e585`.

Die abschließende gemeinsame Sprachbrowsermatrix besteht 144 tatsächliche
Ansichten, 2061 Browserbedingungen und 127 Sprach-Fachbedingungen. Beide
Suchrouter sind darin mit echten Veröffentlichungen, 20-plus-1-Seitenwechsel,
privaten Folgedrafts und ungültigen Sprachen geprüft. 391 ursprüngliche Tabellen
und beide Schlüssel bleiben exakt; externe Anfragen und JavaScriptfehler: null.
Protokoll: `/tmp/kulturhus-languages-release-candidate.log`, SHA256
`13a8a3a2644c1962489da8f27f233eec47456f1524c165a89f573d3ed7a7ea95`.
