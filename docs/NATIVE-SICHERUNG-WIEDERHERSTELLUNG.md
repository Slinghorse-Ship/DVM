# Native Sicherung und Wiederherstellung

`distribution/recover-native.php` sichert eine vollständig installierte native
Anwendung einschließlich logischer MySQL-Datenbank, privater Medien, Schlüssel
und Laufzeitkonfiguration. Alle Archiveinträge einschließlich Manifest werden
mit AES-256 verschlüsselt. Ein zufälliger Schlüssel aus 32 Bytes wird als
64 Hexzeichen in einer privaten Datei mit Rechten 0600 hinterlegt und getrennt
vom Archiv gesichert. Keine Passwörter oder Schlüssel in Kommandozeilenargumente.

```sh
php distribution/recover-native.php --help
php distribution/recover-native.php --config-stdin --backup < /private/backup.json
php distribution/recover-native.php --config-stdin --verify < /private/verify.json
php distribution/recover-native.php --config-stdin --restore < /private/restore.json
php distribution/recover-native.php --config-stdin --restore --resume < /private/restore.json
php distribution/recover-native.php --config-stdin --release < /private/release.json
```

Die Beispielpfade stehen für Betreiberdateien außerhalb des Webroots/Checkouts,
mit privaten Verzeichnis- und Dateirechten. Das JSON enthält:

| Vorgang | Angaben |
|---|---|
| backup | `root`, `archive`, `key_file`, `dsn`, `username`, `password` |
| verify | `archive`, `key_file` |
| restore | `target`, `archive`, `key_file`, `dsn`, `username`, `password`, optional `hosts` |
| release | `root`, `dsn`, `username`, `password`, `reviewed: true` |

Ein Backup benötigt ein neues Archivziel in einem privaten Verzeichnis außerhalb
der Installation. Die native Frontcontroller-Laufzeit und der private native
Delivery-Worker nehmen dieselbe Betriebssperre: aktive Zugriffe verhindern den
Sicherungslauf, neue Zugriffe erhalten währenddessen einen Wartungshinweis.
Direkt gestartete alte Finanz-/Cron-/SQL-Prozesse müssen separat stillgelegt
werden. Diese Sperre kann fremde Datenbankverbindungen nicht anhalten.

Restore benötigt die verifizierte Quell-/Paketfassung der Sicherung, ein neues
Ziel außerhalb des Checkouts und eine leere eigene MySQL-Datenbank. Jede
Archivdatei, jeder Pfad und jeder SHA-256-Wert wird vor Ziel-/Datenbankschreibzugriffen
geprüft. Daten werden anschließend stückweise extrahiert und nochmals geprüft.
Die logische Datenbank erhält Binärdaten, berechnete Spalten und Trigger.
Schlüssel bleiben erhalten; private Pfade und die neue Datenbankverbindung
werden ausdrücklich umgezogen. Archivierte PHP-Konfiguration wird dabei nicht
ausgeführt. Die ursprüngliche Installation wird nicht verändert.

Nach dem SQL-Import werden alte Auth-Sitzungen, Challenges und API-Zugriffstoken
widerrufen. Alte E-Mail-Anmeldecodes sowie Passwortzurücksetzungs- und
Einladungstoken sind verbraucht. Alle bisherigen Wiederherstellungscodes erhalten
einen eigenen Widerrufszeitpunkt; ihre ursprünglichen Hashes und
Verbrauchszeitpunkte bleiben erhalten. Sitzungsgebundene Nachweise etwa für eine
E-Mail-Adressänderung bleiben als Original erhalten; ihre alte Sitzung ist ungültig.
Die authentifizierte Wiederaufnahme verwendet für diese Prüfung genau einen festen
Zeitpunkt und denselben gespeicherten Zählerbericht. Nach einem angenommenen COMMIT
mit verlorener Antwort wird der bereits gespeicherte Bericht wieder gelesen.
Ein späteres `--restore --resume` eines abgeschlossenen Vorgangs entwertet keine
inzwischen neu ausgegebenen Zugangscodes.

Passwort-Hashes, konfigurierte Authenticator-Schlüssel, persönliche Kontofassungen
und OAuth-/Fachoriginale werden nicht zurückgesetzt. Für jeden konfigurierten
Authenticator wird ausschließlich dessen Wiedergabeschutz auf mindestens
`intdiv(security_review_at, 30) + 1` vorgerückt; bereits höhere Zähler bleiben
erhalten. Dadurch funktionieren beim Wiederherstellungszeitpunkt noch zeitgültige
alte Codes nicht erneut. Einen neuen angezeigten Code gegebenenfalls abwarten:
Mit demselben Authenticator ist nach höchstens 60 Sekunden wieder ein neuer
unverbrauchter Zeitschritt verfügbar. Eine nur begonnene Einrichtung erhält
keinen solchen Zählervorschub.

Zuvor laufende
Zustellungen werden als unklar markiert. `private/recovery-hold` verhindert
sämtliche Planung und Zustellung des nativen Delivery-Workers. Der Betreiber
prüft Website, Anmeldung, Originale, Kontozuordnung, Zugänge, offene Zahlungen
und Zustellergebnisse. Erst `--release` mit `reviewed: true` und erneuter
Integritäts-/Datenbankprüfung gibt die Hintergrundverarbeitung frei. Die
Freigabe selbst sendet nichts; unklare Aufträge werden nicht automatisch
wiederholt. Unabhängige Anbieterabgleiche bleiben erforderlich.

## Persönliche Zugangscodes nach verlorenem Authenticator übergeben

Diese private Betreiberfunktion ist ausschließlich nach einer vollständig
abgeschlossenen, mit dem Archivschlüssel authentifizierten Wiederherstellung
verfügbar. Sie besitzt keine Webroute und keine allgemeine Kontoadministrator-
Freigabe. Die Betriebsfreigabe `--release` ersetzt keine persönliche
Identitätsprüfung. Ein gesperrtes, unbestätigtes, vereinsfremdes oder inzwischen
anders zugeordnetes Konto kann keine Codes erhalten. Das bestehende persönliche
Passwort bleibt für die Anmeldung erforderlich.

Zuerst anhand der genauen ursprünglichen Konto-ID den aktuellen Stand privat
ansehen:

```sh
php distribution/recover-native.php --config-stdin --inspect-restore-account < /private/account-inspect.json
php distribution/recover-native.php --config-stdin --restore-account-codes < /private/account-codes.json
```

Beide JSON-Konfigurationen enthalten `root`, `dsn`, `username`, `password`,
`key_file` und `account_id`. Die Ausgabe des ersten Befehls enthält ausschließlich
Prüfmetadaten einschließlich des ursprünglichen Anmeldenamens und der Kontoversion.
Nach tatsächlicher persönlicher Identitätsprüfung benötigt der zweite Befehl
zusätzlich genau diese Angaben:

| Angabe | Bedeutung |
|---|---|
| `original_alias` | Unveränderter persönlicher Benutzername; ohne Benutzernamen die ursprüngliche E-Mail-Adresse |
| `account_version` | Ganze Zahl aus der aktuellen privaten Kontoabfrage |
| `identity_reviewed` | Ausdrücklich `true` nach bewusster persönlicher Identitätsprüfung |
| `request_key` | Einmal gewählte 32 Hexzeichen; bei verlorener Antwort denselben Schlüssel verwenden |

Zehn neue Codes stehen ausschließlich in einer privaten Datei mit Rechten 0600
unter `private/restore-account-codes/<Vorgang>/output/`. Die JSON-Antwort nennt
deren Pfad und Metadaten. Codes niemals auf die Konsole, in Logs, Tickets oder
unverschlüsselte Nachrichten kopieren. Die Datei persönlich über einen geeigneten
privaten Weg übergeben und sicher verwahren. Der verschlüsselte Übergabenachweis
bindet dieselben zehn Codes an den konkreten Restorevorgang und das genaue Konto.
Wiederholung desselben Auftrags erzeugt keine weiteren Codes und kann eine fehlende
Ausgabedatei mit genau denselben Bytes wiederherstellen. Ein anderer Auftrag für
dasselbe Konto wird abgewiesen. Konto- oder Faktoränderungen verlangen eine erneute
Prüfung; der Dienst überschreibt keine neueren persönlichen Zugangscodes.

Zum Anmelden das eigene Passwort und **einen** der neuen Codes verwenden. Wenn
der verlorene Authenticator anschließend bewusst ersetzt werden soll, im
persönlichen Konto das Passwort und **einen weiteren unbenutzten Code** zur
Deaktivierung angeben; danach den eigenen neuen Authenticator normal einrichten.
Der Betreiber löscht oder ersetzt dabei keinen Authenticator-Schlüssel. Die
ersten bereits verwendeten Codes bleiben Einmalcodes.

Der gesamte Ordner `private/restore-account-codes/` ist von Betriebssicherungen
und Wiederherstellungskopien ausgeschlossen. Die Rohcodedatei ist kein dauerhaft
mitwandernder Bestandteil eines Backups. Der Restore erhält dagegen die bisherigen
Codehashes und deren Verbrauchszeitpunkte in der Datenbank. Eine spätere bewusste
persönliche Faktoränderung folgt dem normalen Kontoablauf. Die
Sicherheitsprüfung gehört zum abgeschlossenen authentifizierten Restore; eine
zusätzliche Laufzeit-Epochendatei wird nicht eingeführt.

## Umfang der Betriebssicherung

Grenzen dieses ersten nativen Ablaufs: 100.000 Dateien, insgesamt 3 GB,
128 MiB je Datei, SQL-Anweisungen/Zeilen höchstens 32 MiB. Ausgelagerte absolute
Laufzeit-/Schlüsselpfade werden abgewiesen und benötigen separate Sicherungen.
PHP-Sitzungen, private Cache-/PDF-Tempdateien und Laufzeitsperren werden
ausgelassen; Abhängigkeitsdateien wie `composer.lock` bleiben enthalten.
Unterbrochene Wiederherstellungen hinterlassen ausschließlich das eigene neue
Ziel und die eigene neue Datenbank; `restore-progress.json` dokumentiert den
Vorgang. Mit `--restore --resume` denselben begonnenen Vorgang fortsetzen.
Archivschlüssel, Archivbytes, Paketfassung, Ziel, ursprüngliche Konfiguration,
Datenbankname und Datenbankserver müssen zum gespeicherten eigenen Nachweis passen.
Eine neue Installation bleibt weiterhin auf ein neues Ziel und eine leere eigene
Datenbank beschränkt. Alte einfache Fortschrittsmarker ohne diesen Nachweis werden
nicht als Erlaubnis für eine Datenbankfortsetzung übernommen.
Der eigene [Paketupgrade-Weg](PAKET-UPGRADE.md) ist inzwischen umgesetzt.

Vor einer Betriebssicherung eine begonnene Paketaktualisierung abschließen oder
zurücksetzen. Die Sicherung erfasst den aktuellen Programm-/Daten-/Schlüsselstand;
private Upgrade-Schlüssel, Upgradesitzungen, alte Rückkehrdateien und abgeschlossene
Betriebsjournale werden ausgelassen. Damit blockiert eine tatsächliche
Wiederherstellung an einem neuen Speicherort keine späteren Paketaktualisierungen.
Die ursprünglichen aktiven Zusatzdateien und Schlüssel bleiben enthalten.
Eine aktuelle Probe prüft Upgrade, verschlüsseltes Archiv und echten MySQL-Umzug.

Die frühere Grundabnahme von `tests/native-recovery.php` umfasste 142 Prüfungen mit wirklichem verschlüsseltem
Archiv, frischer MySQL-Datenbank, gespeicherten Originalen, privater
Schlüsselentschlüsselung, Triggerwirkung und tatsächlich gerenderter nativer
Dokumentseite. Falscher Schlüssel, beschädigte Bytebeweise, belegtes Ziel und
vorhandene Datenbanken werden geprüft. Es werden keine externen Mails oder
Veröffentlichungen ausgelöst.

## Unterbrochenen Vorgang fortsetzen

Der neue Fortschritt ist mit einem eigenen, aus dem separat aufbewahrten
Archivschlüssel abgeleiteten Schlüssel authentifiziert. Bereits vollständig
geschriebene Originale werden unverändert beibehalten. Der noch nicht fertige
Dateianfang wird stückweise gegen genau das gleiche Archivoriginal geprüft,
bevor weitere Bytes angehängt werden. Veränderte oder fremde Bytes bleiben
unangetastet. ZIP-Streams dürfen kleinere Blöcke als angefordert liefern.

Eine unterbrochene SQL-Einspielung besitzt einen eigenen Datenbanknachweis aus
dem ursprünglich leeren Ziel. Bei Wiederaufnahme werden ausschließlich die
bereits angelegten Tabellen aus der exakt verifizierten SQL-Tabellenliste dieses
eigenen Vorgangs zurückgenommen und erneut eingespielt. Eine fremde Tabelle,
ein anderer Vorgang oder ein fehlender Nachweis hält die Fortsetzung an.

`private/restore-hold` bleibt bis zum geprüften Abschluss bestehen. Website und
Worker beachten diese Sperre bereits vor dem App-Laden beziehungsweise der
Verarbeitung. Eine Betriebsfreigabe vor diesem Abschluss ist gesperrt. Danach
bleibt die getrennte `private/recovery-hold` für die ausdrückliche Betreiberprüfung
erhalten. Der private Abschlussnachweis ermöglicht die erneute Abfrage nach
einer verlorenen Antwort; Daten werden dabei nicht nochmals eingespielt.
Er wird bei einer späteren Betriebssicherung als abgeschlossener Vorgang ausgelassen.

Die frühere Abnahme mit 27 Fach-/MySQL-/CLI-Verträgen prüfte echte SIGKILL-Abbrüche während
Dateikopie, SQL-Import und nach angenommener Abschlussaufzeichnung. Geschützte
Dateianfänge, fremde Tabellen/Marker/Ziele/Verbindungen, ursprüngliche Schlüssel,
Binärdaten, verschlüsselte Aufträge, Authentifizierungswiderruf und unveränderte
Quelldaten wurden tatsächlich geprüft. Damals bestanden zehn betroffene native Suiten,
darunter die 142 Wiederherstellungs-, 138 Paketupgrade-, 222
Neuinstallations- und 52 HTTP-Installer-Verträge. Damals waren 173 native Suiten
registriert; der frühere vollständige 172-Suiten-Lauf gehört zum Paketupgrade-
Feature und wird nicht als neuer 173-Suiten-Lauf bezeichnet.

## Aktuelle Softwareabnahme der Anmeldesicherheit nach Restore

Acht gezielt geprüfte Gruppen bestehen insgesamt 700 Suitenbedingungen:

| Gruppe | Bedingungen |
|---|---:|
| Private CLI, Dateien, Kryptographie und Original-Prüforakel | 63 |
| Echte Installation, verschlüsseltes Backup, Restore und persönliche Codeübergabe | 76 |
| Normale Authentifizierung einschließlich konkurrierender Faktoren | 120 |
| Begrenzte Bereinigung alter Anmeldedaten | 35 |
| E-Mail-Faktor und getrennte Familienkonten | 44 |
| Persönliche Anmeldenamen in beiden Apps | 112 |
| Abbruch und Wiederaufnahme der Wiederherstellung | 29 |
| Breite Wiederherstellung ursprünglicher Fachbestände | 221 |

Die 63 reinen Bedingungen enthalten die bisherigen 50 Prüfungen und 13 neue
Prüfungen des Metadaten-Prüforakels. Dessen Standardverhalten bleibt mit drei
unveränderten Vergleichswerten aus dem vorherigen Quellstand geschützt.
Die erlaubte Entfernung des eigenen temporären Restore-Markers wird getrennt
vom unveränderten Schema und Inhalt aller ursprünglichen Tabellen geprüft.
Ein tatsächlicher MySQL-Vergleich mit dem alten und neuen Markerformat belegt
diese Abgrenzung; der Produktcode wurde wegen dieses Prüforakels nicht geändert.

Die tatsächliche Abnahme bestätigt unter anderem SIGKILL mit Signal 9 nach
angenommenem COMMIT, verlorene Antworten, zurückgewiesene COMMITs, unveränderte
Passwörter und Faktor-Schlüssel, erhaltene Codehashes und Verbrauchszeitpunkte,
alle übrigen ursprünglichen Fachbestände sowie die drei privaten Schlüssel.
Ein vor dem Backup tatsächlich nutzbarer API-Token erhält nach Restore 401,
auch wenn zusätzlich eine gültige neue Sitzung mitgeschickt wird. Neue private
Codes funktionieren mit dem persönlichen Passwort genau einmal; ein weiterer
Code erlaubt den bewussten persönlichen Authenticator-Wechsel. Abgeschlossene
Wiederaufnahmen entwerten diese neuen Codes nicht erneut. Die Prüfungen verwenden
ausschließlich eigene neue MySQL-Bestände und Dateien; sie senden keine echten
Mails, Anbieteranfragen oder Zahlungen. Eine erneute vollständige Paketabnahme
aller Plattformbereiche wird damit nicht behauptet.
