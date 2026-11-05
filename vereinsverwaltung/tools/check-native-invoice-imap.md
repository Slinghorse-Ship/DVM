# Optionaler Test mit der nativen PHP-IMAP-Erweiterung

Dieser Test gehört **nicht** zum normalen Testlauf. Er benötigt eine ausdrücklich autorisierte Verbindung zu einem realen IMAP-Postfach. Er darf nicht unbeaufsichtigt aus CI, einem Scheduler oder einem Webrequest gestartet werden. Eine Freigabe für normale Unit-Tests ist keine Freigabe für diesen Test.

## Was der Test tut

Er legt einen neuen Ordner `Kulturhus-Native-Test-<zufällige Kennung>` an. In diesen Ordner schreibt er über IMAP APPEND zwei kleine selbst erzeugte Nachrichten mit demselben synthetischen PDF. Es werden keine SMTP-Nachrichten versandt. Bestehende Nachrichten werden nicht gelesen oder verändert; INBOX wird lediglich als Ausgangspunkt der Verbindung geöffnet.

Der produktive PHP-IMAP-Transport und der Rechnungseingangsdienst lesen ausschließlich den neuen Testordner. Die Vereinsdatenbank ist ein neu angelegtes MySQL-Schema mit zufälligem Namen auf einem separaten lokalen Testserver. Es wird vor der ersten IMAP-Verbindung angelegt und im normalen Fehler- und Erfolgsfall wieder entfernt. Der Test prüft den ersten Abruf, die Deduplizierung des PDFs, den zweiten Abruf nach dem gespeicherten UID, beide bytegenauen E-Mail-Originale, deren Verknüpfung mit dem Dokument, den PDF-Hash, unveränderte Nachrichtenflags und das Ausbleiben von Buchungen/Freigaben.

Dieser Test deckt den konkreten Unterschied zur Fake-Transport-Suite ab: PHP/c-client unterstützt das IMAP-Suchkriterium `UID` nicht. Die echte Suche muss UIDs über `SE_UID` liefern; ein Suchfehler darf nicht als leerer Posteingang gelten. Die unabhängige Fake-Suite `tests/invoice-mailbox.php` bleibt sinnvoll, beweist aber keine erfolgreiche native IMAP-Verbindung.

## Voraussetzungen und Start

- PHP mit IMAP, PDO MySQL, OpenSSL und den übrigen Voraussetzungen des Vereinsmanagers.
- Aktuelle zusammengehörige `src/`- und `migrations/`-Dateien.
- Separater lokaler MySQL-Testserver. Die drei Umgebungsvariablen `NATIVE_IMAP_TEST_MYSQL_DSN`, `NATIVE_IMAP_TEST_MYSQL_USER` und `NATIVE_IMAP_TEST_MYSQL_PASSWORD` müssen ausdrücklich gesetzt sein. Es gibt keinen Rückgriff auf `VEREIN_DATABASE_*` oder `KULTURHUS_DEMO_DATABASE`.
- Der Test-DSN hat ausschließlich die Form `mysql:host=mysql-test;charset=utf8mb4` oder `mysql:host=127.0.0.1;port=3306;charset=utf8mb4`; auch `localhost` ist erlaubt, der Port ist optional. Kein `dbname`, Socket oder weiterer DSN-Parameter wird akzeptiert. Der Server muss tatsächlich ein isolierter Testdienst sein; ein lokaler Hostname allein ist kein Isolationsnachweis.
- Das separate Testkonto benötigt Rechte zum Anlegen, Migrieren und Löschen ausschließlich der Testschemata `kh_native_imap_<32 zufällige Hexzeichen>`. Keine Produktionszugangsdaten verwenden. Der Lauf erstellt ein neues Schema ohne `IF NOT EXISTS`; vorhandene Schemata werden nicht wiederverwendet.
- Öffentlicher IMAP-Hostname mit gültigem TLS-Zertifikat auf Port 993; keine IP-Adresse, kein lokaler Mailserver.
- Konto mit Berechtigung, einen eigenen temporären Ordner anzulegen, Nachrichten hineinzuschreiben und diesen Ordner anschließend zu löschen.

Der einzige erlaubte Aufruf lautet:

```text
php vereinsverwaltung/tools/check-native-invoice-imap.php --create-temporary-imap-fixture
```

Das Programm erwartet genau ein JSON-Objekt über **Standard-Eingabe**, danach EOF:

```json
{
  "host": "imap.example.org",
  "username": "test@example.org",
  "password": "NUR_UEBER_GESCHUETZTE_STANDARDEINGABE",
  "email": "test@example.org",
  "allow_temporary_imap_write": true
}
```

Die Platzhalter sind nicht funktionsfähig. Der autorisierte Aufrufer muss die echten IMAP-Werte über eine geschützte stdin-Pipe und die MySQL-Testzugangsdaten über eine private Prozessumgebung bereitstellen. Keine Passwörter in Kommandoargumenten, Shell-Historie, eingecheckten Dateien oder Testberichten ablegen. Das Tool nimmt keine Datenbankpfade, bestehenden Datenbankschemata, existierenden Testordner, fremden Dateianhänge oder SMTP-Einstellungen entgegen. Die Testdatenbank wird vollständig vorbereitet, bevor eine IMAP-Verbindung aufgebaut wird.

Für den Abruf muss die gemeinsame Mail-/IMAP-Sperre innerhalb dieses einzelnen CLI-Prozesses freigegeben werden. Das Tool setzt deshalb seine eigene Laufzeit auf `production`, obwohl ausschließlich sein selbst erzeugtes MySQL-Testschema benutzt wird. Es lädt keinen Worker und ruft keinen SMTP-Adapter auf. Diese Umgebungswerte werden nicht in Dateien oder im Elternprozess geändert.

## Ergebnis und Aufräumen

Exitcode 0 und `result: passed` bedeuten einen erfolgreichen Lauf einschließlich beider Cleanups. JSON enthält Prüfbezeichnungen, Importzähler, die zufälligen Ordner- und Schemanamen sowie einen bereinigten Fehlerstatus; keine Zugangsdaten und keine vollständigen Serverfehlermeldungen. `cleanup` beschreibt den IMAP-Ordner, `database_cleanup` das MySQL-Testschema.

Im `finally`-Block wird nur der selbst angelegte Ordner gelöscht, nachdem Anzahl und Message-IDs ausschließlich eigene Testnachrichten erkennen lassen. Unerwartete Inhalte verhindern die Löschung. Anschließend wird unabhängig vom IMAP-Cleanup ausschließlich das in diesem Lauf erfolgreich angelegte MySQL-Schema gelöscht. Ein fehlgeschlagenes `CREATE DATABASE` berechtigt niemals zum Löschen des betreffenden Schemas.

Exitcode 1 oder ein unvollständiger Cleanup erfordert die manuelle Prüfung des im Ergebnis genannten Ordners beziehungsweise Schemas. Ein Prozessabbruch, Stromausfall oder erzwungenes Beenden kann ebenfalls einen Testordner oder ein Testschema zurücklassen. MySQL hält diese Daten auf Datenträgern, nicht nur im Arbeitsspeicher. Vor einer manuellen Löschung sicherstellen, dass das Ziel ausschließlich die synthetischen Testdaten dieses Laufes enthält. Bestehende Ordner und Schemata werden niemals als Testziele wiederverwendet.

Das Tool wurde aus einem erfolgreichen privaten nativen Test entwickelt. Die MySQL-Fassung muss getrennt von normalen Regressionstests mit einem ausdrücklich autorisierten Postfach geprüft werden. Eine Syntax- oder Opt-in-Prüfung belegt keine erfolgreiche native IMAP-Verbindung und keinen vollständigen MySQL-/IMAP-Cleanup.
