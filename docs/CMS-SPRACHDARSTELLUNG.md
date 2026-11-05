# Veröffentlichte Sprachinhalte in den Website-Ansichten

Nachrichten, Veranstaltungen, Alben und die Startseite verwenden für jede
gewählte Sprache ausschließlich die aktuell freigegebene Übersetzung der
tatsächlich veröffentlichten Originalfassung. Ein späterer Original- oder
Übersetzungsentwurf ersetzt sie nicht. Fehlt eine gültige Übersetzung, gelten
die eingerichteten Rückfallsprachen.

Die Nachrichtendetailansicht lädt beim Prüfen der Originalveröffentlichung den
übersetzten Text weiter. Übersichten, aktuelle Nachrichten auf der Startseite
und Veranstaltungskarten verwenden dieselbe veröffentlichte Sprachfassung.
Karten, Rückwege, Seitenwechsel und Programmfilter behalten den bestätigten
Sprachpfad. Veranstaltungseinträge geben diesen Pfad auch in ihren strukturierten
Daten aus.

Die Programmsuche berücksichtigt die tatsächlich veröffentlichten Textfelder
der gewählten Sprache und ihrer Rückfälle: Titel, Kurztext, Inhalt, Ort und
Adresse. Derselbe Filter gilt für Trefferzahl und angezeigte Seite. Prozent,
Unterstriche und SQL-Sonderzeichen bleiben wörtlicher Suchtext.

Die Startseite unterstützt auch übersetzte Hero-Texte, Plakattexte,
Schwerpunkte und Abschnittsüberschriften. Bereiche, Reihenfolge, ursprüngliche
Kennungen, Dateien, Verweise und geschäftliche Daten bleiben beim Original.

Eine aktuelle Anbieterabsage überlagert weiterhin den geschäftlichen
Veranstaltungsstatus. Übersetzte Texte können weder Absagen zurücknehmen noch
Ticketpreise oder Zahlungsdaten ersetzen. Vorhandene öffentliche Absagehinweise
bleiben auf ihrer tatsächlich erhaltenen Originaladresse erreichbar, auch wenn
die zugehörige normale CMS-Veröffentlichung zurückgezogen wurde.

Das Zurückziehen einer Übersetzung entfernt den bisherigen übersetzten
Detailpfad und lässt Übersichten auf den aktuellen Originalrückfall wechseln.
Eine zurückgezogene Originalveröffentlichung verschwindet aus den Übersichten.
Deaktivierte Sprachen liefern auch aus bereits gefüllten Websitecaches keine
vorherigen Sprachseiten mehr.

`native-language-renderers` prüft die tatsächlichen Website-Renderer einschließlich
Home, Nachrichten, Programm, Alben, Filter, ursprünglicher Medienbytes,
verschiedener Sprachen im selben Site-Objekt sowie späterer Freigabeentzüge.
Die bestehenden Home-, Nachrichten-, Veranstaltungs- und Albumverträge werden
daneben weiterhin geprüft.

Der kombinierte lokale Nachweis vom 15. Oktober 2026 umfasst sieben grüne
Suiten: Sprachrenderer 55, Sprachverwaltung 96, Home 57, Nachrichten 62,
Veranstaltungsansichten 21, Alben 35 und Veranstaltungskatalog 60 Bedingungen.
Protokoll: `/tmp/kulturhus-language-publication-complete-final.log`, SHA256
`5b9cf0c750cab1e66b8c801e1b884d9561d15497531cb9efc7a9d6470b5a1916`.
Später ergänzte Vorlagenrechte und allgemeine Sprachsuche haben eigene Nachweise;
dieser Lauf belegt deren spätere Änderungen nicht rückwirkend.

Die anschließende gemeinsame Sprachabnahme besteht 127 Fachbedingungen sowie
2061 Browserbedingungen in 144 echten Ansichten. Beide Anwendungsrouter und die
generische Sprachsuche sind enthalten; Originale und Schlüssel bleiben exakt.
Protokoll: `/tmp/kulturhus-languages-release-candidate.log`, SHA256
`13a8a3a2644c1962489da8f27f233eec47456f1524c165a89f573d3ed7a7ea95`.
