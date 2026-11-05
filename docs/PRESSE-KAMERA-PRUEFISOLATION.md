# Eigene temporäre Dateien in der Presse-Kameraprüfung

Die Presse-Uploadprüfung vergleicht vor und nach echter HEIC-/RAW-Konvertierung
die temporären Kameraverzeichnisse. Im gemeinsamen Systemverzeichnis können
andere gleichzeitig laufende Prüfungen währenddessen neue eigene Konvertierungen
beginnen oder beenden. Eine geänderte Gesamtliste beweist dort keinen
zurückgebliebenen Zwischenstand der untersuchten Konvertierung.

Der lokale/native CI-Runner gibt ausschließlich `native-press-upload-rights`
ein eigenes frisches kurzes temporäres Verzeichnis mit privaten Rechten.
Der kurze Pfad lässt Platz für die längenbegrenzten Chromium-Sockets. Der Runner
merkt sich ausschließlich seine eigenen zufällig angelegten Verzeichnisse und
räumt sie beim Abschluss auf. Der PHP-Hauptprozess erhält `sys_temp_dir`, seine Unterprozesse erben
`TMPDIR`. Die Umgebung des Runners und der nachfolgenden Gruppen wird nicht
verändert. Die bestehenden Vorher-/Nachher-Vergleiche und der echte Fehlerweg
für ungültiges RAW bleiben erhalten. Eine zusätzliche Prüfung bestätigt die
tatsächliche Bindung des Verzeichnisses an diesen Lauf.

Zwei tatsächlich gleichzeitig gestartete Läufe verwenden unterschiedliche
Datenbanken und temporäre Verzeichnisse. Der erste besteht 21 Fachverträge;
der zweite besteht 22 Fach-/44 Browserprüfungen, vier wirkliche Mehrfachuploads
und 24 mobile Schriftansichten in beiden Zugängen mit und ohne JavaScript. Originalbilder, frühere Ausgaben und Dateien
bleiben geschützt. Es wird kein fremdes temporäres Verzeichnis bereinigt.

Diese Änderung betrifft den Prüfrunner und seine eigene Presseprüfung. Es gibt
keine neue Laufzeitgeneration, Tabelle, Testsuite, GitHub-Action oder
Veröffentlichung. Die installierte Fotoverarbeitung bleibt unverändert.
