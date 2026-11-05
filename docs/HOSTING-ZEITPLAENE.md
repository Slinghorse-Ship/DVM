# Automatische Aufgaben auf dem Hosting

DVM benötigt einen serverseitigen Zeitplan, damit freigegebene Aufgaben auch bei
geschlossenem Browser laufen. Der Installer zeigt nach der Einrichtung die
passenden Aufrufe für die eigene Installation und bietet sie als Textdownload an.
Die PHP-CLI-Auswahl legt noch keinen Zeitplan beim Hoster an. Ein eingetragener
Zeitplan ist erst nach einem beobachteten erfolgreichen Lauf geprüft.

## PHP und privater Speicher

Die erzeugten Cron-Aufrufe gelten für Linux-/Unix-Hosting.

Im Installer unter **Automatische Aufgaben im Hosting einrichten** den vom Hoster
bestätigten PHP-CLI-Pfad auswählen. PHP ab 8.3 und die erforderlichen Erweiterungen
müssen auch für die CLI verfügbar sein. Die PHP-Version der Website und die des
Zeitplans können beim Hoster unterschiedlich eingestellt sein.

Übliche Beispiele sind `/opt/plesk/php/8.3/bin/php` für Plesk und
`/opt/cpanel/ea-php83/root/usr/bin/php` für cPanel. Diese Beispiele bestätigen keine
Verfügbarkeit auf einem bestimmten Hosting. Mit `php` verwendet der Zeitplan die
PHP-Version seines eigenen Suchpfads; einen bestätigten absoluten Pfad bevorzugen.

Die Aufgabe als Benutzer der DVM-Installation ausführen. `app/` und `private/`
liegen außerhalb des öffentlichen Webroots. `private/worker.php` enthält die
private Vereins- und Datenbankzuordnung; die Datei bleibt mit 0600 geschützt.
Die kopierbaren Aufrufe enthalten ausschließlich Pfade und feste Aufgabenoptionen.
Zugangsdaten gehören weder in Befehle noch in URLs.

## cPanel und Plesk

In **cPanel → Cron-Jobs** das angegebene Zeitmuster und den kopierten Aufruf in die
jeweiligen Felder übernehmen. `* * * * *` bedeutet jede Minute,
`*/5 * * * *` alle fünf Minuten.

In **Plesk → Geplante Aufgaben** eine Aufgabe für den richtigen Hostingbenutzer
anlegen, **Befehl ausführen** wählen und den vollständigen kopierten Aufruf mit dem
gewünschten Zeitmuster eintragen. Die Alternative „PHP-Skript ausführen“ benötigt
denselben Interpreter, den Skriptpfad und die getrennten Argumente aus der Anleitung.
Nicht mehrere Betriebsmodi zu einem gemeinsamen Aufruf zusammenfassen.

Nur die benötigten Aufgaben einrichten. Die unterstützten Modi sind:

| Aufgabe | Zeitmuster | Einstieg und Optionen |
| --- | --- | --- |
| Interne Vorbereitung | Jede Minute | `native-delivery-worker.php`, ohne Transportoption |
| Freigegebene Vereinsmail | Jede Minute | `native-delivery-worker.php --send` |
| Geplante Websitebeiträge | Jede Minute | `native-delivery-worker.php --publish` |
| Facebook-/Instagram-Aufträge | Jede Minute | `native-delivery-worker.php --meta --send` |
| StadtLand.Funk-Aufträge | Jede Minute | `native-delivery-worker.php --stadtlandfunk --send` |
| Social-Aktivitäten | Jede Minute | `native-delivery-worker.php --social-check` |
| Rechnungspostfach | Alle fünf Minuten | `native-delivery-worker.php --receive` |
| Optionales Antwortpostfach | Alle fünf Minuten | `native-delivery-worker.php --mail-replies` |
| Freigegebene Pushhinweise | Jede Minute | `native-delivery-worker.php --push` |
| Veranstaltungsanbieter | Alle fünf Minuten | `native-delivery-worker.php --events` |
| Freigegebene Pressemitteilungen | Jede Minute | `native-delivery-worker.php --press --send` |
| Fällige Tarifabrechnungen | Alle fünf Minuten | `native-delivery-worker.php --catalog-billing` |
| Bankumsätze über FinTS | Alle fünf Minuten | `native-delivery-worker.php --fints` |
| Aktivierte lokale Systemaufgaben | Jede Minute | `native-system-worker.php` |
| Aktivierte Verbindungsprüfung | Alle fünf Minuten | `service-status-worker.php` |

Alle Zeilen benötigen zusätzlich die eigene private `--config`-Zuordnung. Für
den vollständigen Pfad und die korrekte Schreibweise die Installer-Ausgabe
verwenden. Beispiel für einen getrennten Bankabruf:

```sh
/PFAD/ZUR/PHP-CLI /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --fints
```

## Freigaben und Verantwortliche

Mail, Push und Anbieter müssen mit ihren eigenen Konten eingerichtet sein.
Veröffentlichungen bearbeiten ausschließlich fällige, weiterhin freigegebene
Originale. Bei minütlichem Start wartet ein Auftrag gegebenenfalls bis zum
nächsten erfolgreichen Lauf. Der Kalender allein startet keinen Hostingjob.
Die Social-Aktivitätsprüfung beachtet zusätzlich die Abrufpläne der Kanäle.

Für Tarifabrechnung den eigenen `catalog_actor`, für Veranstaltungsabgleich den
`event_actor` und für Bankabrufe den `fints_actor` in `private/worker.php`
konfigurieren. Es sind bereits vorhandene persönliche Akteure mit aktuellen
Rechten des eigenen Vereins. Die Beitragsautomatik verwendet gesondert
`worker_actor` und `automation_start`; ihre Fälligkeiten folgen Europe/Berlin.
Die Auswahl eines Verantwortlichen erzeugt keine neue Berechtigung.

Der Bankjob verwendet ausschließlich die private Bankkonfiguration unter
`private/banking/` und die bestehenden FinTS-Abrufpläne. Ohne eingerichteten Zugang
bleibt der Abruf deaktiviert. Bankfreigaben werden im Bankdialog bearbeitet.
Abruf und Überweisung bleiben getrennte Vorgänge. Der Bankjob akzeptiert keine
Versand-, Veröffentlichungs- oder Zahlungsoptionen.

## Anleitung erneut erzeugen

Die private Hilfe liest keine Zugangsdaten, startet keinen Fachjob und richtet
keinen Hosting-Zeitplan ein. Sie kann nach Umzug oder verlorenem Download mit dem
aktuellen Installationspfad erneut erzeugt werden:

```sh
/PFAD/ZUR/PHP-CLI /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --schedule-help --config=/ABS/DVM/private/worker.php --php=/PFAD/ZUR/PHP-CLI
```

## Tatsächlichen Betrieb prüfen

Einen benötigten Aufruf im Hosting bewusst starten und seine Rückgabe prüfen.
Versand- und Veröffentlichungsjobs können bei eingerichteten Freigaben echte
Aktionen ausführen. Den folgenden regulären Lauf bei geschlossenem Manager
beobachten. Ein aktives Symbol im Hosting genügt nicht als Nachweis.

Die eigene Historie steht unter **Website → Vereinsdaten & Mail →
Hintergrundaufgaben**. Bankabrufe zusätzlich im Bankbereich prüfen, lokale
Aufgaben unter **Einstellungen → Systemverwaltung** und Verbindungsprüfungen unter
**Einstellungen → Systemstatus**. Der Abschluss eines Hintergrundlaufs ersetzt
nicht das einzelne Zustell-, Veröffentlichungs- oder Bankergebnis.

Datensicherung und Aufbewahrung nach dem eigenen Sicherungsplan zusätzlich
einrichten. [Sicherung und Wiederherstellung](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md),
[Fachjobs und Freigaben](NATIVE-HINTERGRUNDJOBS.md),
[Installation](WEBSPACE-INSTALLATION.md).
