# Gefährdungsbeurteilung und Gefährdungsprüfung

Die **Gefährdungsbeurteilung** enthält die Bewertung, festgelegte Maßnahmen,
Dokumentfassungen, fachliche Freigaben und Unterschriften.
Die **Gefährdungsprüfung** enthält eigene Umsetzungsaufträge mit einer konkreten
Vorgabe, einer zuständigen Person, Frist, Rückmeldung und Abnahme.

Unter Organisation → Gefährdungsprüfung einen Prüfauftrag erteilen. Titel,
Vorgabe und Person sind erforderlich; die Frist ist optional. Texte werden von
Menschen eingegeben. Ein Auftrag kann auch ohne Beurteilungsbezug angelegt werden.
In einer Beurteilung öffnet „Prüfauftrag zu dieser Fassung erteilen“ denselben
Ablauf mit genau dieser ursprünglichen Dokumentfassung als Bezug.

Die zuständige Person kann die Umsetzung beginnen und mit eigenem Text sowie
tatsächlichem Umsetzungstag zur Abnahme melden. Die Verwaltung nimmt die
Umsetzung ab oder gibt eine konkrete Nacharbeitsvorgabe ein. Diese erscheint
direkt im Auftrag. Eine abgenommene Prüfung lässt sich mit neuer Vorgabe erneut
zur Nacharbeit öffnen. Ein Abbruch benötigt einen Grund.

Für Erteilung, Änderung der Vorgabe, Abnahme, Nacharbeit und Abbruch gelten aktuelle
Dokumentenschreibrechte. Ein Vereinsmitglied ohne HSE-Leserecht sieht und
bearbeitet ausschließlich seine zugewiesenen Aufträge. Eine Zuweisung gibt
keinen Zugriff auf andere Aufträge oder vollständige Beurteilungen. Inaktive
Konten verlieren den Zugriff; eine Neuzuweisung beendet den persönlichen Zugriff
der bisher zuständigen Person und verlangt eine neue Umsetzungsrückmeldung.
Frühere Rückmeldungen bleiben im Verlauf erhalten.

Auftragsänderungen, Rückmeldungen und Abnahmen erzeugen eigene unveränderliche
Verlaufsstände. Sie ändern keine Beurteilungsfassung, Freigabe, Unterschrift oder
Datumsangabe im Gefährdungsregister. Auch spätere Beurteilungsänderungen wechseln
den ursprünglichen Bezug eines vorhandenen Auftrags nicht. Der Bezug enthält
Dokument-ID, ursprüngliche Fassung, Titel und SHA-256 des ursprünglichen Snapshots.

Suche, Status und „Meine Aufträge“ begrenzen die Übersicht auf sechs Aufträge
pro Seite. Ein geöffneter Auftrag steht oben; die Übersicht ist einklappbar.
Der Verlauf zeigt ebenfalls sechs Stände pro Seite. Veraltete Formulare behalten
ihre Eingaben und ursprüngliche Version; „Aktuellen Stand öffnen“ lädt den
gegenwärtigen Auftrag ausdrücklich neu. Wiederholtes Speichern einer bereits
angenommenen Erteilung legt keinen zweiten Auftrag an.

Der Kalender hat eine eigene Quelle **Gefährdungsprüfungen**. Offene Fristen
bleiben bis zur Abnahme oder zum Abbruch sichtbar. Beurteilungsfristen bleiben
eine getrennte Quelle und werden dabei nicht als erledigt eingetragen.

Die native Installation und das vollständige Paketupgrade installieren
Workflow-Schema **2** mit `dwf_inspections` und `dwf_inspection_versions`.
Vorhandene Beurteilungstabellen bleiben erhalten. Der Browser installiert kein
Schema beim bloßen Öffnen einer Seite. Die verschlüsselte Gesamtsicherung
enthält Aufträge, ursprüngliche Bezüge und alle Verlaufsstände.


Die operative Oberfläche verwendet eigene ausdrücklich deklarierte HSE-Texte in
DE/EN. Ursprüngliche Titel, Vorgaben, Rückmeldungen, Personennamen und
Beurteilungsbezüge werden nicht übersetzt. Zahlenähnliche Texte wie „0“ bleiben
sichtbar. Die Eingabegrenzen zählen Unicode-Zeichen: 240 für Titel und Suche,
8000 für Vorgaben und Rückmeldungen. Die Prüfung erfolgt beim Speichern; eine
abgewiesene Eingabe wird zur Korrektur vollständig angezeigt.

Der Verlauf zeigt zusätzlich zu Vorgabe und Rückmeldung auch ursprüngliche
Titel, Zuständigenkennungen, Fristen, Status, Umsetzungstage und den fest
gebundenen Beurteilungsbezug. Zeitangaben sind zur persönlichen Sprache mit
expliziter Zeitzone Europe/Berlin formatiert. Fehlende Personennamen werden als
solche gekennzeichnet; vorhandene Namen behalten ihren Originaltext.

Abgewiesene Auftragsänderungen haben einen eigenen Konflikttyp. Der HTTP-Adapter
unterscheidet 409 für konkurrierende Originalstände, 422 für ungültige Eingaben
und 403 für fehlende Rechte. Nur das tatsächlich betroffene Formular übernimmt
seine abgewiesenen Werte und seine ursprüngliche Version; andere Formulare werden
nicht mit fremden Rückmeldungen vorbelegt. Eine ungültige Suche behält die
Korrekturwerte und zeigt bis zur Berichtigung keine unpassenden Treffer.
[Schrittweise Bedienung](vereinsverwaltung/handbuch/12-fristen-nachweise.md).


Die Beurteilungsübersicht und ihre Versions-/Aktivitätslisten haben inzwischen
eine eigene Seitennavigation mit 25 Originaleinträgen. Vollständige historische
Formularinhalte und veröffentlichte Vorlagen werden erst bei ausdrücklichem
Öffnen geladen; frühere Daten und gebundene Umsetzungsaufträge bleiben erhalten.
Die vollständige Bedienung steht im [Dokumentenhandbuch](vereinsverwaltung/handbuch/11-dokumente.md).
Weitere Schreib-, Unterschrifts-, Erinnerungs- und Gefährdungsregisteroberflächen
bleiben gesonderte Bedienabnahmen.


Workflow-Schema3 ergänzt ausschließlich das private Anlegeprotokoll für neue
Arbeitsordner, Vorlagen und Beurteilungsdokumente. Die beiden eigenständigen
operativen Tabellen aus Workflow-Schema2 und ihre ursprünglichen Aufträge bleiben
erhalten. Das ausdrückliche additive Modulupgrade legt keine alten
Anlegebestätigungen an. Das Manager-Basisschema bleibt114. Bedienung und
Wiederaufnahme stehen im [Dokumentenhandbuch](vereinsverwaltung/handbuch/11-dokumente.md).
