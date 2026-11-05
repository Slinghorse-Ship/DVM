# Native Betriebsgrenzen und technische Bereinigung

Stand: 16. Oktober 2026. Dieser Nachweis beschreibt drei gezielte
Betriebskorrekturen. Die Gesamtprüfung des endgültig zusammengesetzten Pakets
und die übrigen offenen Plattformfunktionen bleiben eigene Abnahmen.

## Abgetrennte Pushprozesse

Der noch ansprechbare frühere Chat-/Umfragecontroller kann einen eigenen
Pushprozess starten. Sowohl `WebPushDelivery::kick` als auch dessen tatsächlicher
`bin/push-worker.php` verwenden jetzt die native `OperationGate` der festen
privaten Installation. Der Kindprozess hält seine eigene Lesesperre bis zum
Ende; die Sperre des auslösenden Webaufrufs reicht dafür nicht aus.

`upgrade-hold`, `restore-hold` und `recovery-hold` verhindern Start und Versand.
Eine laufende Sicherung oder Paketwartung verhindert ebenfalls einen neuen
Prozess; ein laufender Pushprozess verhindert umgekehrt den exklusiven
Snapshot-/Upgradebeginn. Der bestehende private native Delivery-Worker bleibt
der gemeinsame reguläre Betriebsweg. Alte POST-Aktionen werden nicht in neue
Nachrichten umgedeutet oder gelöscht.

## Dokumente vor dem Einlesen begrenzen

Der gemeinsame serverseitige Leser `LiveUploads::bytes` öffnet nur lokale
reguläre Dateien, prüft ihre tatsächliche Größe vor einer Inhaltsallokation und
liest höchstens diese Größe plus ein Kontrollbyte. Er vergleicht danach
Dateiidentität, Größe und Änderungsdaten. Eine veränderte, leere, verlinkte oder
zu große Quelle wird abgewiesen; die Quelle wird nicht umgeschrieben.

Die vorhandenen Fachgrenzen gelten bereits vor dem Einlesen: Dokumente und
Prüfanhänge 10 MiB, Inventar-/frühere Chatanhänge 2 MiB, Mitgliederdateien 5 MiB
und die bisherigen kleineren Staginggrenzen. Mehrere Dokumente bleiben auf
höchstens zehn Dateien mit insgesamt 20 MiB begrenzt; vor jeder nächsten Datei
wird auch das verbleibende Gesamtbudget geprüft. Bestehende eigene Titel,
Zuordnungen und akzeptierte Stagingdateien werden dadurch nicht ersetzt.

Der native Fotoimport behält seine eigenen 256-/512-MiB-Eingangsgrenzen und
HEIC-/RAW-Verarbeitung. Die Korrektur fügt keine Pflichtangaben oder neue
Uploadbestätigung hinzu.

## Optionale vereinseigene Bereinigung

Die private lokale Systemaufgabe **Abgelaufene eigene Anmeldedaten bereinigen**
ist standardmäßig ausgeschaltet. Sie verwendet die aktuellen ursprünglichen
Kontenverwaltungsrechte und die freigegebene persönliche Zeitplanverantwortung.
`Auth\Retention::prune` darf ausschließlich im privaten CLI laufen und entfernt
pro Aufruf insgesamt höchstens hundert eigene technische Sitzungs-, Einmal-Token-
und Anmelde-Challengezeilen. Das Ende ihrer Gültigkeit beziehungsweise ihr
ausdrücklicher Verbrauch/Widerruf muss mindestens 90 Tage zurückliegen.

Der Verein folgt immer der ursprünglichen Account-/Personenzuordnung. Aktuelle,
erst kürzlich abgelaufene und fremde Zeilen bleiben unverändert. Die Auswahl und
Entfernung sind eine kurze gemeinsame Transaktion; aktuelle Rechte werden vor
Auswahl und Abschluss erneut geprüft. Scheduler-Heartbeats liegen außerhalb
dieser Transaktion. Ein später SQL-Fehler nimmt den gesamten Block zurück.

Konten, Passworthashes, MFA-/Recoverydaten, Zustellhistorien, API-Zugänge und
API-Idempotenzbelege sind kein Bereinigungsziel. Insbesondere würde das Löschen
von Idempotenzbelegen die sichere Wiederholung alter Änderungsanfragen gefährden.

## Instanzweite Login-Drosseln getrennt behandeln

`native_auth_limits` enthält globale Hashkennungen ohne Vereinsbesitz. Deshalb
darf die vereinseigene Systemaufgabe diese Tabelle nicht bereinigen.
Für den Hostbetreiber gibt es einen ausdrücklich getrennten privaten Einstieg:

```sh
php vereinsverwaltung/bin/native-auth-retention.php \
  --config /ABS/INSTALLATION/private/worker.php --instance-scope
```

Der Aufruf benötigt die eigene aktuelle private ursprüngliche Instanz,
POSIX-Eigentümerprüfung, ursprüngliche Worker-/Umgebungs-/Schlüsselbindungen und
eine eigene gemeinsame Betriebssperre. Die festen Originaldateien müssen zum
aktuellen Prozessbenutzer gehören und private Rechte besitzen. Eine Vereinsrolle
ersetzt diese Hostautorität nicht. Die aktuelle Konfiguration wird vor und
während der kurzen Entfernung erneut geprüft; kein Schlüssel und keine
Datenbankangabe wird ausgegeben.

Je Aufruf werden höchstens hundert Login-Drosseln entfernt, deren Ablauf bereits
mindestens 90 Tage zurückliegt. Aktive Sperren, Versuchsstände und sämtliche
Account-/Vereinsdaten bleiben erhalten. Die ausdrückliche erneute Ausführung
bearbeitet nur die übrigen geeigneten Zeilen. Es gibt keinen automatischen
Webaufruf und keinen entsprechenden Vereinszeitplan. Alle drei Wartungs-/
Wiederherstellungssperren verhindern diesen Operatorlauf.

Auth-Schema **7** ergänzt ausschließlich vier Ablaufindizes im bestehenden
Installations-/Upgradeweg. Webaufrufe führen keine DDL aus und schreiben keine
früheren Konten-/Sitzungsdaten für die Wartung um.

## Prüfung und Abgrenzung

```sh
bash tools/ci/local-native.sh --core native-auth-retention native-runtime-boundaries
bash tools/ci/local-native.sh --core native-auth native-chat-push
```

Die Retentionsprüfung umfasst 35 Bedingungen mit echter frischer MySQL-Datenbank,
wirklichem SQL-Abbruch nach einer ersten Entfernung, aktuellem Rechteentzug,
vollständigen Originalbytevergleichen, begrenzter Fortsetzung und tatsächlichem
privatem Operator-CLI. Die Instanzprüfung verwendet eigene synthetische private
Originaldateien; sie ersetzt keinen vollständigen Paketupgrade-Nachweis.

Die Betriebsgrenzenprüfung umfasst 31 Bedingungen ohne Datenbank oder Anbieter:
echter alter Push-CLI bei allen drei Holds, tatsächliche gegenseitige Dateisperren,
ein harmloser eigener Startprozess und echte Multipartübertragung über PHP.
64-MiB-Uploads werden unter 32-MiB-PHP in Staging, Einzel-/Mehrfachdokumentaktion
und Inventaraktion abgewiesen, mit erhaltenem Korrekturtext und vorherigen
Originalbytes. Ein zusätzliches 24-MiB-Dokumentpaket prüft die 20-MiB-Sammelgrenze
unter 64-MiB-PHP. Der echte Managerbootstrap und zulässige Dateiinhalte benötigen
selbst Speicher; daraus wird keine 32-MiB-Mindestzusage für volle Uploadpakete
abgeleitet. Die HTTP-Fixture führt die tatsächlichen Aktionsdateien bis zur
abgewiesenen Persistenzgrenze aus; sie ist keine vollständige Zwei-App-UI-Abnahme.

Lizenzierte Original-ERiC-Laufzeit sowie Xcode-/physische iPhone-, iPad- und
Android-Geräteabnahmen bleiben getrennte offene externe Nachweise.

## Aktuelle kuratierte Paketzusammensetzung

Der eigene unveränderliche Export auf dem abgeschlossenen CMS-Erweiterungsstand
besteht die beiden neuen Gruppen (35/31 Bedingungen) sowie die angrenzenden
ursprünglichen Auth-/Chat-Push-Gruppen (120/27 Bedingungen). Der getrennte reale
Paketupgrade-Nachweis besteht **152 Bedingungen mit 96 tatsächlichen HTTP-Schritten**:
altes Paket installieren, gefüllte Originale sichern, echte SIGKILL-Grenzen bei
Anwendungswechsel und Rollback, denselben Vorgang wiederaufnehmen, wartende
Zustellbelege erhalten, ganzen Installer samt Offlineabhängigkeiten verifizieren
und verschlüsseltes aktuelles Backup in einen unabhängigen privaten Root
wiederherstellen. Auth-Schema 7 entsteht ausschließlich im Wartungsweg.

Der erste kombinierte Testaufruf bestand seine vier Fachgruppen; der nachfolgende
Upgrade-Fixtureaufbau benötigte den ursprünglichen Gitkontext und die lokalen
Offlineabhängigkeiten. Nach deren Vorbereitung wurde ausschließlich die noch
offene Upgradegruppe vollständig ausgeführt und bestanden. Dies ist weiterhin
kein Abschluss der noch offenen P-09/P-12/P-22/P-24/P-25-Gesamtprüfung.

- `kulturhus-runtime-boundaries-isolated-domain.log`, SHA-256 `8c95be16f632e0f3ce66b2941393a8b0a368c71e73b03a1f786f6255627c6ed6`.
- `kulturhus-runtime-boundaries-isolated-upgrade.log`, SHA-256 `8d6d90a94d1bea13ee7d60b6f26ca3dec3be0e06a7d82a9979ec34883f145207`.
