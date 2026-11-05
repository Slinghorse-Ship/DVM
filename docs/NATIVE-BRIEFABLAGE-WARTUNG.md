# Native Briefablage prüfen und korrigieren

Der private Wartungsbefehl ordnet bereits nativ erzeugte, falsch abgelegte Briefe
der Ausgangsablage ihres ursprünglichen Dokumentjahres zu. Er verwendet die
bestehenden Dokumentrechte und die normale Metadatenänderung. Inhalte, PDF-Dateien,
Dateifassungen, Titel, Notizen, Schutzbereiche und frühere Historie bleiben erhalten.
Pro verschobenem Brief entstehen eine neue Metadatenversion und die dazugehörigen
normalen Historien- und Auditereignisse.

## Aufruf im eigenen Betrieb

Den Aufruf mit den vorhandenen privaten Laufzeitvariablen vorbereiten:
`VEREIN_DATABASE_USER`, `VEREIN_DATABASE_PASSWORD` und `VEREIN_PRIVATE_ROOT`.
Das private Verzeichnis muss bereits existieren, außerhalb des öffentlichen
Webroots liegen und Modus 0700 besitzen. Vorschläge, Vorgangsstände und native
SQL-Sicherungen werden dort unter `maintenance/letter-outbox/` mit Modus 0600
aufbewahrt. Zugangsdaten gehören in die private Laufzeit, nicht in Argumente,
öffentliche Verzeichnisse, Git oder Konsolenausgaben.

```sh
php app/vereinsverwaltung/bin/repair-letter-outbox.php --db='mysql:host=127.0.0.1;dbname=EIGENE_DATENBANK;charset=utf8mb4' --actor=EIGENE_AKTOR_ID
php app/vereinsverwaltung/bin/repair-letter-outbox.php --db='mysql:host=127.0.0.1;dbname=EIGENE_DATENBANK;charset=utf8mb4' --actor=EIGENE_AKTOR_ID --plan
```

Der erste Aufruf zeigt die erlaubten Kandidaten. `--plan` schreibt einen neuen
privaten Vorschlag und gibt dessen genauen Pfad aus. Für den vorhandenen aktiven
Aktor gelten sämtliche ursprünglichen Dokument- und Zuordnungsrechte.
Verborgene Briefe werden auch bei größeren Beständen nicht mitgezählt.
Der Vorschlag bindet Datenbank, Server, Aktor, Verein und die vollständigen
ursprünglichen Dokument-/Datei-/Historienfingerabdrücke.

Den zurückgegebenen privaten Pfad verwenden:

```sh
php app/vereinsverwaltung/bin/repair-letter-outbox.php --db='mysql:host=127.0.0.1;dbname=EIGENE_DATENBANK;charset=utf8mb4' --actor=EIGENE_AKTOR_ID --status=/ABS/PRIVATE/maintenance/letter-outbox/VORGANG.json
php app/vereinsverwaltung/bin/repair-letter-outbox.php --db='mysql:host=127.0.0.1;dbname=EIGENE_DATENBANK;charset=utf8mb4' --actor=EIGENE_AKTOR_ID --apply=/ABS/PRIVATE/maintenance/letter-outbox/VORGANG.json
```

`VORGANG.json` steht für den tatsächlich zurückgegebenen Namen. Der Vorgang
verlangt eine eigene MySQL-Verbindung ohne eine bereits laufende Transaktion.
Vor der Änderung wird eine neue native SQL-Sicherung erzeugt und nach ihrem
Originalhash geprüft. Die gesamte ausgewählte Gruppe einschließlich neuer
Zielordner und Auditereignisse wird atomar gespeichert. Veraltete Vorschläge,
geänderte Sicherungen, fremde Zugänge, Symlinks und verlorene Rechte werden
abgewiesen. Eine sonstige Normalisierung ursprünglicher Texte führt ebenfalls
zur Rücknahme der gesamten Gruppe.

## Unterbrechung und Fortsetzung

Nach einem Abbruch denselben `--apply`-Aufruf mit demselben privaten Vorschlag
wiederholen. Der gespeicherte Vorgang unterscheidet den unveränderten Ausgangsstand
von einer bereits vollständig gespeicherten Verschiebung. Vor dem Commit werden
alle Datenbankänderungen zurückgenommen; nach einem bestätigten ursprünglichen
Commit wird ausschließlich der private Abschluss nachgetragen. Ein späterer
legitimer Metadatenstand wird durch die Wiederholung eines fertigen Vorgangs
nicht zurückgesetzt.

Bei einem widersprüchlichen oder zwischenzeitlich veränderten Stand bleiben die
Originale unverändert. Den aktuellen Stand fachlich prüfen und gegebenenfalls
einen neuen Vorschlag erstellen. Die native SQL-Sicherung ist für die gesonderte
Wiederherstellung in eine eigene leere Datenbank bestimmt. Private Vorgangsstände
und Sicherungen nach der eigenen betrieblichen Aufbewahrungsregel verwalten.

Je Vorschlag werden höchstens 250 erlaubte Briefe ausgewählt. `remaining` nennt
die weitere erlaubte Anzahl. Nach erfolgreicher Bearbeitung einen weiteren neuen
Vorschlag erstellen, bis beide Zahlen null sind. Eigene Uploads, andere erzeugte
Nachweise, bewusst manuell abgelegte Briefe, verknüpfte oder gelöschte Vorgänge
und andere Vereine bleiben außerhalb dieser Korrektur.

## Wiederholbare lokale Prüfung

```sh
bash tools/ci/local-native.sh --core native-letter-outbox-repair
```

Die Gruppe verwendet ausschließlich eigene frische MySQL-Daten und private
Dateien. Sie prüft echte CLI-Aufrufe, die tatsächliche Rücklesung des nativen
SQL-Snapshots in eine getrennte leere Datenbank, vollständige Originalzeilen,
Dateibytes, Rechte, Historien, Status, verzögerte Wiederholung, Pfad- und
Versionskonflikte sowie 251 erlaubte und einen geschützten Nachbarn.
Zwei eigene Prozesse werden am tatsächlichen Vor- und Nach-Commit-Punkt mit
`SIGKILL` beendet und über denselben gespeicherten Vorgang wieder aufgenommen.
Es werden keine realen Briefe, Nachrichten, Zahlungen oder Anbieterjobs ausgelöst.
