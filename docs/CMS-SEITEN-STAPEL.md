# Wiederaufnehmbare Seitenstapel

Unter **Stapelweise aufräumen** lassen sich Seiten, Beiträge, Veranstaltungen und
Alben in den Papierkorb verschieben oder privat zurückholen. Der Papierkorb hat
auch einen direkten Zugang zum stapelweisen Zurückholen. Die Auswahl liest nur
25 Metadaten pro Trefferseite; Suche und Zählung verwenden dieselben Bedingungen.

Einzelne Inhalte dieser Trefferseite oder **alle Suchtreffer** bewusst auswählen
und **Stapel vorbereiten** bestätigen. Alle Suchtreffer umfasst bis zu 5000
Originale über die Trefferseiten hinweg. Die Vorbereitung hält Kennungen und
aktuelle gespeicherte Versionsstände dauerhaft fest; sie verändert noch keine Seite.
Es gibt keine automatische Erweiterung auf später hinzugekommene Treffer.

**Bis zu 25 Inhalte fortsetzen** führt begrenzte Schritte aus. Jeder Originalschritt
und sein Fortschritt werden in derselben MySQL-Transaktion gesichert. Bei einer
Unterbrechung oder verlorenen Browserantwort den Stapel über **Letzte Stapel**
neu öffnen. Bereits ausgeführte Schritte werden nicht erneut angewendet. Ein alter
oder gleichzeitig gesendeter Fortschrittsstand wird abgelehnt.

Geänderte Originale, Unterseiten und typisierte Verweise halten den Stapel mit
einem konkreten Hinweis an. Die ursprünglich gewählte Seitenversion bleibt
verbindlich. Verknüpfungen zuerst korrigieren und dann fortsetzen. Eine neuere
Seitenfassung kann nicht durch bloßes Wiederholen überschrieben werden. Sie muss
entweder in einem neuen Stapel bewusst ausgewählt oder als Konflikt ausdrücklich
**unverändert übersprungen** werden. Übersprungene Kennungen und Gründe bleiben
im Stapelverlauf erhalten. Es gibt keine stillschweigende Konfliktüberspringung.

**Stapel abbrechen** lässt bereits ausgeführte Schritte bestehen und verändert die
restliche Auswahl nicht. Ein abgebrochener oder abgeschlossener Stapel wird nicht
weitergeführt. Rechte werden in jedem Schritt aktuell geprüft: Papierkorb benötigt
Veröffentlichungsrechte, privates Zurückholen Bearbeitungsrechte. Die normalen
Original-, Referenz-, Archiv- und Veröffentlichungsregeln gelten unverändert.
Alle Formulare verwenden die lokal vorhandenen Bootstrap-6-Steuerelemente und
funktionieren auch ohne JavaScript.

`Cms/PageBatches` und `Web/Website/PageBatchPanel` trennen Fachregeln und Oberfläche.
Schema-Version 7 wird ausdrücklich über `Installer/SchemaRegistry` eingerichtet;
kein DDL im Webrequest und kein Release-/Cloud-Veröffentlichungsschritt für Git-Pushes.

## Nachweise und Grenzen

35 tatsächliche MySQL-/Dienst-/Controllerprüfungen mit 501 Originalseiten,
Wiederaufnahme über eine neue Verbindung, unveränderten Originalbytes, begrenzten
Schritten, konkurrierenden Prozessen, dauerhafter Überspringung, Abbruch,
Rechteentzug, äußeren Rollbacks und aktuellen Referenzblockern bestehen.
136 tatsächliche Browser-/HTTP-Prüfungen beider Zugänge bei 1440/820/390 Pixeln
mit 58 gespeicherten Stapelschritten bestehen. Vollauswahl über Trefferseiten,
41-Inhalte-Stapel in mehreren Schritten, echte Browser-Wiederaufnahme, Abbruch,
unabhängige Originalbearbeitung, Konflikt/Überspringung, Tastatur und JavaScript-
freie Formulare sind geprüft. Anschließende Datenbankprüfung erhält alle
Originalinhalte und deren private Rückkehr.

Diese Stapel bearbeiten gespeicherte Originale einzeln. Große Teilbaumkopien,
Veröffentlichungsstapel, automatisches Auflösen zyklischer Verweise und dauerhaftes
Löschen benötigen eigene fachliche Abläufe. Referenzblocker werden nicht umgangen.
Weitere Grenzen des [Papierkorbs](CMS-PAPIERKORB.md) gelten weiterhin.
