# Bestehende native Installation aktualisieren

Das Upgrade ersetzt das installierte Programm und seine festgelegten PHP-Bibliotheken.
Verein, Konten, Rechte, Inhalte, ursprüngliche Schlüssel, private Konfiguration,
Originalmedien und ausstehende Jobs bleiben erhalten. Es richtet keinen zweiten
Administrator ein und versendet oder veröffentlicht nichts.

Auf der Entwicklungsmaschine das Upload-Paket vorbereiten:

```sh
php distribution/upgrade-native.php --build=/absoluter/neuer/upgrade-ordner
```

Für ein ebenfalls zu aktualisierendes optionales OCR-Paket `--with-ocr` ergänzen.
Ohne diese Option bleiben bereits vorhandene Dateien unter `app/runtime/` erhalten.
Die vorhandene private OCR-/FinTS-Konfiguration wird nicht ersetzt. Tatsächliche
Kompatibilität der optionalen Binärlaufzeit mit dem jeweiligen Hosting weiterhin
separat prüfen.

`private/upgrade-package/` in das bestehende private Installationsverzeichnis und
`web/upgrade/` in dessen öffentlichen Domain-Webroot hochladen. Andere bestehende
Dateien nicht mit dem Upload ersetzen. `UPGRADE-KEY.txt` bleibt beim Betreiber;
sein Inhalt gehört weder in Git noch in URLs, Logs oder den öffentlichen Webroot.
Den öffentlichen `upgrade/`-Pfad im Hosting direkt als Verzeichnis erreichbar machen:
bei Apache steht er wie `setup/` vor der Frontcontroller-Rewrite-Regel. Bei anderen
Webservern dieselbe Ausnahme konfigurieren.

Auf der eigenen HTTPS-Domain `/upgrade/` öffnen und den privaten Schlüssel eingeben.
**Aktualisieren** startet, **Stand prüfen** zeigt den gespeicherten Stand und
**Fortsetzen** führt denselben Vorgang weiter. Mit JavaScript folgen die Schritte
automatisch; **Nach diesem Schritt anhalten** pausiert nach dem laufenden Schritt.
Ohne JavaScript führt jeder Formularaufruf einen Schritt aus. Ein Browserabbruch
verliert den Fortschritt nicht. Für die Rückkehr die Bestätigung im aufgeklappten
Abschnitt auswählen und **Bisherigen Stand wiederherstellen** verwenden.

Alternativ auf dem eigenen Server das zum Upload-Paket gehörende CLI-Werkzeug:

```sh
php distribution/upgrade-native.php --config-stdin
php distribution/upgrade-native.php --config-stdin --resume
php distribution/upgrade-native.php --config-stdin --rollback
php distribution/upgrade-native.php --config-stdin --status
```

JSON ausschließlich über stdin: `target` und `token`. Das Werkzeug verwendet die
schon installierte private Datenbankverbindung; keine erneuten Konto-/Vereinsangaben.
Eine ältere CLI-Installation ohne `private/app-files.json` benötigt einmal zusätzlich
`previous_source`: das vollständige geprüfte Quellpaket, dessen Manifesthash im
bisherigen `installation.json` steht. Dieses Paket wird nur gelesen; es dient der
Prüfung des bereits installierten Programms. Bestehende Browserinstallationen besitzen
das benötigte Inventar bereits. Neue CLI-Installationen speichern es ebenfalls.
Eine ältere CLI-Installation einmal mit diesem Nachweis beginnen; anschließend kann
auch der Browser denselben Vorgang fortsetzen. Das ist keine Altbestandsübernahme.

Die Wartungssperre bleibt nach einem Prozessabbruch bestehen. Der Website-Einstieg
prüft sie vor dem Laden von `app/`; deshalb liefert auch ein Abbruch zwischen den
beiden Verzeichniswechseln einen Wartungsstatus statt eines PHP-Fehlers. Der originale
Versandschlüssel wird währenddessen im privaten Vorgangsverzeichnis aufbewahrt.
Dadurch können auch vorherige Worker nach Verlust einer Prozesssperre nicht senden.
Es wird kein Ersatzschlüssel erzeugt. Bei Abschluss oder Rückkehr kehrt exakt der
vorherige Schlüssel an seinen bisherigen Pfad zurück.

Das alte Programm und ein mit AES-256 verschlüsselter MySQL-Snapshot bleiben im
privaten Vorgangsverzeichnis. Fortschritt und ursprüngliche Nachweise sind an Ziel,
Paket, Datenbankserver, Datenbank und eigene Schlüssel gebunden. Eine ausdrückliche
Rückkehr vor dem Abschluss stellt auch bereits aktualisierte Tabellen wieder her;
eine unterbrochene Rückkehr setzt ausschließlich diesen eigenen Vorgang fort.
Nach dem endgültigen Abschluss ist die Rückkehr gesperrt, damit danach entstandene
Betriebsdaten nicht verworfen werden. Eine allgemeine Wiederherstellung verwendet
weiterhin den separaten Wiederherstellungsweg.

Nach Abschluss `web/upgrade/` und das hochgeladene `private/upgrade-package/` entfernen.
Die privaten Rückkehrdateien erst nach eigener Abnahme entfernen. Zugang und Schlüssel
sind nicht für eine dauerhafte öffentliche Verwaltungsroute gedacht.

Installierbare Paketgenerationen werden in `Installer/PackageVersion.php` erhöht;
normale Git-Zwischenstände und Releases sind davon unabhängig. Ein bereits neueres
Paket kann nicht durch eine ältere Generation überschrieben werden.

Die Regression verwendet den tatsächlich früher gepushten Quellstand `7ec486a`,
einschließlich dessen Inventar und PHP-Archiven. Der synthetische Runner stellt ihn
aus dem lokalen Git-Objekt bereit; dafür vollständige Checkout-Historie verwenden.
Kein Netzwerkzugriff und keine realen Transporte erfolgen durch diese Prüfung.

```sh
bash tools/ci/local-native.sh --core native-package-upgrade
DVM_UPGRADE_BROWSER=1 bash tools/ci/local-native.sh --core native-package-upgrade
```

Der tatsächliche eigene Fach-/MySQL-/HTTP-Lauf besteht mit 138 Verträgen.
90 Browserprüfungen bei drei Breiten, tatsächlicher doppelter Schrift und mit/ohne
JavaScript umfassen 91 echte Upgrade-Schrittaufrufe und 16 Statusanfragen,
einschließlich verlorener bereits angenommener Schritt-/Abschlussantwort.
Die allgemeine Produktparität und übrige Grundabläufe bleiben separat offen.

Der Gesamtlauf aller 172 nativen Suiten und acht Kalendergruppen ist grün.
Nach den abschließenden Hosting-/Sicherungsanpassungen bestehen zusätzlich
die fünf betroffenen Suiten einschließlich Neuinstallation 222, tatsächlichem
HTTP-Installer 52 und verschlüsselter Wiederherstellung 142 erneut.
Die tatsächliche Sicherung eines vollständig aktualisierten Programms und
Wiederherstellung an einem neuen Speicherort ist Teil der Upgrade-Prüfung.
Private Upgrade-Schlüssel, Sitzungen, alte Rückkehrdateien und abgeschlossene
Betriebsjournale gehören nicht in diese aktuelle Betriebssicherung; ursprüngliche
aktive Daten, Zusatzdateien und Schlüssel bleiben darin enthalten.
