# Dateien und Bildfassungen

Die Website-Medienverwaltung bietet unter „Dateien und Bildfassungen“ eine
persönlich filterbare Ablage für Dokumente, Archive, Audio/Video und Bilder.
Die Liste zeigt zehn Einträge pro Seite. Suche, Schlagwort, Typ und „Meine
Uploads“ lassen sich kombinieren. Einzel- und Mehrfachauswahl funktionieren
auch ohne JavaScript; eine Auswahl darf bis zu zehn Dateien und Bilder mischen.
Die optionale Rechteprüfung bleibt von der Annahme normaler Uploads getrennt.
HEIC/HEIF und unterstützte Kamera-RAWs verwenden den vorhandenen Fotoimport.
Bereits im neuen CMS vorhandene Bilder lassen sich über eine eigene Suche mit
25 Treffern pro Seite unverändert als Bildfolge speichern. Originalkennung und
Pixel bleiben erhalten; ein Bild gehört dabei höchstens zu einer Bildfolge.

Eine Datei hat eine dauerhafte Kennung und unveränderliche Fassungen. „Datei
ersetzen“, Metadatenänderungen und „Wiederherstellen“ erzeugen jeweils eine neue
Fassung. Alte Dateibytes, Namen, Tags und Fassungskennungen bleiben erhalten.
Der Verlauf zeigt zehn Fassungen pro Seite. Bestehende Website-PDFs behalten
Kennung, Dateispeicher und frühere Verknüpfungen.

Ein Dateifeld speichert die Kennung einer konkreten Dateifassung. Eine neue
Fassung ändert eine bereits freigegebene Website-Seite daher nicht automatisch.
Der gemeinsame Dateiauswahldialog sucht Dateinamen, Titel und Schlagwörter und
bietet 25 Treffer pro Seite. Eine früher gewählte Fassung bleibt auswählbar,
auch wenn sie nicht zum aktuellen Suchfilter passt.

## Berechtigungen und Auslieferung

Lesende können aktive Dateien herunterladen, Redakteure hochladen und Fassungen
bearbeiten. Nutzungsfreigaben, Speichergrenze, Aufbewahrung und Entfernen erfordern
Website-Verwaltung. Jede Änderung prüft die aktuelle Rolle und die aktuelle
Fassungsnummer erneut. Bildangaben prüfen zusätzlich die unabhängige aktuelle
Metadatenversion der Bildverwaltung; ein außerhalb der Dateifolge geändertes Bild
kann nicht mit einem veralteten Formular überschrieben werden.

Allgemeine Dateien liegen privat außerhalb des Webroots. Erlaubt sind die
explizit geprüften Dokument-, Archiv-, Audio- und Videoformate; Programmdateien
werden nicht angenommen. Dateien werden grundsätzlich als Download mit
`application/octet-stream`, `attachment`, `nosniff` und einer gesperrten
Inhaltsrichtlinie ausgeliefert. Archive werden nicht entpackt oder ausgeführt.
Grenzen: 25 MiB pro allgemeiner Datei, insgesamt 50 MiB allgemeine Dateien pro
Auswahl; Fotos verwenden zusätzlich die vorhandenen Bildgrenzen.

Öffentliche Downloads benötigen eine tatsächliche freigegebene Website-Originalseite
mit genau dieser Dateifassung. Interne Repeater-Einträge, private Feldrechte,
versteckte Felder, Papierkorb und nicht für die Website freigegebene Nachrichten
vermitteln keinen öffentlichen Dateizugriff. Ein späterer Widerruf von Feld- oder
Dateinutzungsrechten greift auch bei älteren veröffentlichten Originalen sofort.
Die ursprünglichen freigegebenen Fassungen werden dafür nicht umgeschrieben.

## Bilder und Aufbewahrung

Bildfassungen verwenden die vorhandenen, sicheren Bildoriginale und deren
Vorschaubilder. Das System legt keine zweite Bilddecodierung oder zweite Kopie
aller Pixel an. Beim Ersetzen oder Wiederherstellen bleiben aktuelle Sperren,
Einwilligungswiderrufe, engere Kanäle und frühere Ablaufgrenzen wirksam. Erst eine
bewusste aktuelle Nutzungsänderung kann diese aufheben.

Eine gespeicherte Bildfolge schützt alle ursprünglichen Bildfassungen vor der
vorhandenen Bildbereinigung. Die Website-Verwaltung kann die Bildfolge ausdrücklich
„auflösen“. Dabei bleiben die einzelnen Bildoriginale und sämtliche Fassungsdaten
unverändert; die bisherige Bildverwaltung entscheidet weiterhin anhand echter
Verwendungen und ihrer Aufbewahrung, ob ein einzelnes Bild entfernt werden darf.
Eine aufgelöste Bildfolge kann nur reaktiviert werden, wenn **alle** ursprünglichen
Bildfassungen aktiv und hashgleich vorhanden sind. Auflösen und Reaktivieren
haben eigene Versionsprüfung und unveränderlichen Personen-/Zeitverlauf.

Allgemeine Dateien lassen sich erst entfernen, wenn keine gespeicherte Seite,
Originalfassung, Übersetzung oder laufende Kopierquelle sie verwendet. Entfernen
hält die Bytes privat zurück. Die ursprüngliche Aufbewahrungsfrist wird beim
Entfernen festgehalten und durch spätere Einstellungen nicht verkürzt.
Wiederherstellen prüft die ursprünglichen Dateibytes. Endgültiges Löschen benötigt
eine ausdrückliche Bestätigung nach Fristablauf und erneuter Verwendungsprüfung.
Ein unterbrochener Löschvorgang lässt sich mit seinem ursprünglichen Plan fortsetzen.
Historische Fassungen zählen bei der Speichergrenze nur ihre tatsächlich einmal
aufbewahrten Bytes; erst abgeschlossene physische Löschung gibt diese frei.

Die Speicherprüfung zeigt ältere, nicht zugeordnete eigene Uploads in Zehnerschritten.
Eine Wiederherstellung prüft den ursprünglichen Hash, kopiert die ausgewählte Datei
in eine neue logische Datei und erhält die gefundene Quelle. Wiederholtes Bestätigen
legt keine zweite Datei an. Geordnete Speichersegmente erlauben das vollständige
Weiterblättern auch über große Bestände, ohne einen festen 50.000-Einträge-Abbruch.

## Prüfung

Die Fachsuite `native-file-versions` verwendet ausschließlich eigene frische
MySQL-Testbestände, echte lokale Uploadbytes und tatsächliche HEIC-/RAW-Originale.
Sie prüft unveränderliche Fassungen, parallele Bearbeitung, Rollen und Fremdvereine,
öffentliche Originalbindungen, aktuelle Rechtewiderrufe, Bildfolge-Auflösung,
Aufbewahrung, unterbrochene Löschung sowie wiederaufnehmbare Speicherinventur.
Die zusätzliche Browserabnahme öffnet die normalen nativen und integrierten
Manager-Einstiege mit echten nativen Anmeldesitzungen, mit und ohne JavaScript.
Der abschließende gemeinsame Lauf besteht 94 Fachbedingungen und 494
Browserbedingungen über 48 Datei-/Bildansichten in beiden echten Anwendungen,
mit und ohne JavaScript. 64 tatsächliche Formular-POSTs prüfen Metadaten,
Byteersatz, historische Wiederherstellung, Entfernen/Löschen, Bildfolgen und die
Auswahl bereits vorhandener nativer Bilder. Zusätzlich verweigern echte
HTTP-Korrekturwege veraltete Fassungsnummern, erhalten die eigenen Eingaben und
beide ursprünglichen Versionsnummern und verlangen bewusstes Neuladen.
405 übrige Tabellen, beide Schlüssel, die freigegebene Originalseite und das
ursprüngliche PDF bleiben unverändert; kein externer Abruf und kein
JavaScriptfehler. Zwölf bestehende Nachbargruppen zu PDFs, Referenzen, Bildern,
Aufbewahrung, Bereinigung, Quoten und typisierten Feldern bestehen ebenfalls.
Die eigenständige lesende Gegenprüfung des neuen Speichercursors und der
Bildfolgenzustände meldet keinen weiteren bestätigten Fehler. Das ersetzt keine
reale Betreiber- oder Geräteabnahme.

## Getrennte Auslieferungsabnahme

Die kuratierte Fassung besteht 94 Dateivertrags-, 53 allgemeine Datei-API-, 116 HTTP- mit 57 echten Zwei-App-Anfragen, 95 vorhandene API-, 66 ursprüngliche PDF-, 150 Feldrechte-, 174 Medienverwendungs- und 256 Installationsbedingungen. Der erste Lauf endet anschließend vor dem Upgrade an der fehlenden Git-Metadatenumgebung des Quellarchivs; die bereits abgeschlossenen Fachgruppen werden dadurch nicht als vollständiger Achtgruppenlauf ausgegeben. Der gezielt mit den vorhandenen lokalen Git-Objekten wiederholte Upgrade besteht separat 152 Bedingungen und 96 HTTP-Schritte. Es wurde kein Produktfehler durch einen gelockerten Test übergangen.

Eine zweite unveränderliche Exportfassung ergänzt die feste native Stylesheet-Registrierung und besteht 494 Browserbedingungen in 48 Ansichten mit 64 echten POSTs beider Apps, mit und ohne JavaScript. Alle 400 verglichenen ursprünglichen Tabellen, beide privaten Schlüssel, die Originalpublikation und das ursprüngliche PDF bleiben exakt. Die frühere Eigentümerabnahme und zwölf angrenzende Gruppen sind zusätzliche getrennte Belege.

- `kulturhus-files-isolated-feature.log`, SHA-256 `70b098f029651f8609a6c0594a9077b70bd3b3ce456ff26b91288a319a5731bd`.
- `kulturhus-files-isolated-upgrade.log`, SHA-256 `8d6d90a94d1bea13ee7d60b6f26ca3dec3be0e06a7d82a9979ec34883f145207`.
- `kulturhus-files-isolated-browser.log`, SHA-256 `d89a991f4e3d346065ea72472cbb5e9a70dea211fa2a7e851b9f3c28798e7dc1`.
- `kulturhus-file-versions-real-apps-approved-final.log`, SHA-256 `8af5bc6e80c7e670e53f5344da88661e6afbbd7c57ed0dbfa2385a5513325c06`.
- `kulturhus-file-versions-adjacent.log`, SHA-256 `897418c76db26d27b32721bcf5cebbde095397767c972e5f533b741962705149`.
