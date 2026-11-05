# Gesicherter Dienst für endgültiges Bildlöschen

`Cms/MediaRemoval` kann ein eigenes entferntes Bild nach Ablauf seiner
unveränderlichen Mindestfrist endgültig löschen. Der native Dienst prüft
aktuelle Medienverwaltungsrechte, Verein, Originalversion und Vorgangskennung.
Vor der gespeicherten Entscheidung und erneut unmittelbar vor dem Dateischritt
prüft ein eigener serialisierbarer Vorgang sämtliche globalen Verwendungen.
Neue Bezüge und unlesbare Quellen blockieren das Löschen.

`beginPurge` speichert zuerst den Zustand `purging` und seine unveränderliche
Historie. Erst nach diesem Datenbankabschluss darf `finishPurge` Dateien
entfernen. Jede Fortsetzung besitzt erneut eigene aktuelle Prüfungen und
Quellsperren. `purge` führt beide Schritte aus. Eine alte übergeordnete
Transaktion kann weder Entscheidung noch Dateischritt übernehmen.

Der private Dateidienst löscht ausschließlich unveränderte, im ursprünglichen
Plan verzeichnete Dateien. Zusätzliche oder veränderte Bytes, belegte aktive
Ziele, Verknüpfungen und konkurrierende frühere Dateivorgänge werden erhalten
und blockieren den Vorgang. Nach einer bestätigten Teilstörung dürfen bereits
gelöschte Originaldateien fehlen; übrige Dateien müssen weiterhin genau zu
ihrem ursprünglichen Hash und Umfang passen. Kein Wiederherstellungsweg
behauptet danach eine vollständige Originalrückkehr.

Originale Bilddatensätze, Kennungen, Zeitstempel, Rechtefassungen,
verschlüsselte Nachweise, Entfernungsvorgänge und private Plandateien bleiben
unverändert erhalten. Erst der bestätigte Zustand `purged` gibt die genaue
Quote der vier bekannten Bildvarianten frei. Auch ein älterer tatsächlicher
Datenbanksnapshot erhält hierbei aktuelle gesperrte Zustände. Ein Abbruch vor
diesem Abschluss bleibt belastet und kann bewusst fortgesetzt werden.

205 Fach-/MySQL-/Dateiverträge mit zehn ausschließlich neuen eigenen
Prüfbildern bestätigen sechs vollständige Löschungen, einen tatsächlichen
PHP-Prozessabbruch nach Dateilöschung vor Abschluss sowie eine echte
Dateisystem-Teilstörung. Ein unabhängiger SQL-Schreiber wird während des
Dateischritts gesperrt. Unlesbare oder neue Quellen zwischen Entscheidung und
Fortsetzung verhindern weiteren Dateiverlust. Alle früheren vollständigen
Zeilen, Primärkennungen, Gesamtzahlen, Veröffentlichungen, privaten Originale,
Nachweise und Quarantänepläne bleiben geschützt.

21 betroffene native Suiten bestehen. CMS bleibt 16; 129 Suiten sind
registriert. Verschlüsselte Wiederherstellung erhält auch vollständige
endgültige Zustände und bestätigte laufende Entscheidungen. Frühere private
Bildadressen zeigen ihren tatsächlichen Zustand ohne defekte Vorschau.
Die ausdrücklich bestätigten mobilen Löschen-/Fortsetzen-Formulare sind im
folgenden Zwischenstand geprüft; [Bedienung](CMS-BILDLOESCHEN-BEDIENUNG.md).
Keine automatische Löschung.
