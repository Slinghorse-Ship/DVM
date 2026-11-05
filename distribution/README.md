# DVM installieren und aktualisieren

Diese Anleitung richtet sich an die Person, die DVM auf dem Webspace betreut.
Für Funktionsübersicht und den kurzen Installationsablauf siehe
[Projektbeschreibung](../README.md).

DVM verwendet PHP ab 8.3, eine eigene HTTPS-Domain und eine neue, leere
MySQL-Datenbank ab 8.0.16. Die benötigten Erweiterungen, Datenbankrechte und
Webserverregeln stehen in der
[Hosting-Anleitung](../docs/WEBSPACE-INSTALLATION.md#voraussetzungen).
Bestehende DVM-Installationen verwenden den
[Paketupgrade-Assistenten](../docs/PAKET-UPGRADE.md).

## Empfohlener Weg: Upload-Paket und Browser-Assistent

### Paket auf einem Rechner mit PHP vorbereiten

Im vollständigen Repository ausführen:

```sh
php distribution/install.php --verify
php distribution/build-webspace.php --target=/ABSOLUTER/PFAD/dvm-upload
```

Der Zielpfad muss absolut sein, außerhalb des Repositorys liegen und neu oder
leer sein. Sein übergeordneter Ordner muss bereits existieren. `--verify` prüft
Dateibestand, Bytezahlen und SHA-256 der mitgelieferten Quellen und Bibliotheken.
Die Paketerstellung verändert keine bestehende Installation.

Für das optionale Linux-OCR-/PDF-Paket denselben Builder verwenden:

```sh
php distribution/build-webspace.php --target=/ABSOLUTER/PFAD/dvm-upload-mit-ocr --with-ocr
```

Dieses Zusatzpaket benötigt auf dem Hosting weitere Architektur-, Prozess- und
Sandboxfunktionen. Die Voraussetzungen sind in der
[OCR-/PDF-Anleitung](../docs/WEBSPACE-INSTALLATION.md#optionales-ocr-pdf-paket)
beschrieben. Experimentelle Videoerstellung und serverseitige Fotoaufbereitung
haben ebenfalls eigene Laufzeitvoraussetzungen.

OCR, KoSIT und ERiC können auch getrennt für bestehende Installationen vorbereitet
werden. Befehle, private Pfade und Prüfungen stehen in
[Zusatzlaufzeiten](../docs/ZUSATZLAUFZEITEN.md).

### Verzeichnisse richtig hochladen

```text
dvm/
├── app/       Programm und mitgelieferte Bibliotheken; privat
├── private/   Konfiguration, Schlüssel, Medien und Sitzungen; privat
└── web/       Öffentlicher Domainordner
```

`app/` und `private/` müssen außerhalb des öffentlichen Domainordners liegen.
Bei festem Hostingnamen kann `web/` etwa in `httpdocs`, `htdocs` oder
`public_html` umbenannt werden; die beiden privaten Verzeichnisse bleiben seine
Nachbarn. Der PHP-Benutzer benötigt Zugriff auf alle drei Verzeichnisse.
Private Verzeichnisse verwenden 0700, private Dateien 0600. Die
[Hosting-Anleitung](../docs/WEBSPACE-INSTALLATION.md#upload-paket-erzeugen)
erklärt die Rechte und `open_basedir`.

`SETUP-KEY.txt` bleibt beim Betreiber und wird nicht öffentlich hochgeladen.
Das Paket enthält die PHP-Abhängigkeiten; auf dem Hosting sind für diesen
Installationsweg weder Composer noch SSH oder Docker erforderlich.

### Einrichtung abschließen

1. `https://DEINE-DOMAIN/setup/` öffnen.
2. Den lokalen Einrichtungsschlüssel, Vereinsdaten, Zugang zur leeren Datenbank
   und ein Administratorkonto mit eigenem Passwort eingeben.
3. Voraussetzungen und Fortschritt prüfen. Der Assistent wendet alle
   Datenbankschritte selbst an; einzelne SQL-Dateien müssen nicht nachgeladen
   oder manuell ausgeführt werden.
4. Bei Unterbrechung mit demselben Schlüssel den begonnenen Vorgang fortsetzen.
5. Nach erfolgreichem Abschluss bei `/login/` anmelden; anschließend den
   öffentlichen `setup/`-Ordner und die lokale `SETUP-KEY.txt` entfernen.

Neue Websites beginnen mit der allgemeinen Vorlage **Klar**. Weitere allgemeine
[Website-Vorlagen](../docs/CMS-WEBSITE-VORLAGEN.md) können im Manager ausgewählt
werden. Vereinsinhalte werden anschließend selbst angelegt.

## Verbindungen und automatische Aufgaben

Vor der Einrichtung benötigte Schlüssel und Kennungen anhand der
[API-/Herstellerübersicht](../docs/VERBINDUNGEN-UND-ZUGANGSDATEN.md) beschaffen.
Sie unterscheidet Softwareregistrierungen von privaten Vereinszugängen und
nennt die offiziellen Anbieteranleitungen.

Der Installer stellt die Anwendung bereit. Eigene Mailkonten, Bankzugänge,
Facebook-/Instagram- oder Eventbrite-Verbindungen werden anschließend mit den
jeweiligen Rechten eingerichtet. FinTS benötigt die eigene Registrierung und ein
unterstütztes Bank-/TAN-Verfahren; der Assistent verlangt keine PIN oder TAN.
Amtliche Steuerprüfung und -übermittlung benötigen zusätzlich die private
ERiC-Laufzeit, Herstellerkonfiguration und das eigene Zertifikat.

Für Hintergrundaufgaben den PHP-CLI-Pfad des Hosters prüfen. Der Assistent zeigt
die passenden Zeitmuster und Befehle; diese in Plesk unter **Geplante Aufgaben**
oder in cPanel unter **Cron-Jobs** eintragen. Versand, Postfachabruf,
Veröffentlichung und Status haben getrennte Aufgaben. Ein erfolgreicher
Einrichtungsschritt legt noch keinen Zeitplan beim Hoster an.
[Alle Aufgaben und ihre Einrichtung](../docs/HOSTING-ZEITPLAENE.md).

Die Anwendung besitzt einen eigenen verschlüsselten Sicherungs- und
Wiederherstellungsweg. Die Sicherungsstrategie legt der Betreiber fest; eine
vorhandene Hosting-Sicherung über Plesk kann dafür verwendet werden.
Schlüssel und private Konfiguration bleiben bei Updates erhalten.

## Alternative für erfahrene Betreiber: CLI-Installation

Auf einem geeigneten System mit PHP und Zugriff auf die neue MySQL-Datenbank:

```sh
php distribution/install.php --verify
php distribution/install.php --help
php distribution/install.php --config-stdin < /ABSOLUTER/PRIVATER/PFAD/einrichtung.json
```

Die private JSON-Datei enthält `target`, `dsn`, `username`, `password`,
`club_id`, `club_name`, `hosts`, `admin_email` und `admin_password`.
`hosts` enthält erlaubte HTTPS-Domains ohne Schema, Port oder Pfad.
`target` ist ein neues privates Ziel außerhalb des Checkouts. Der optionale Wert
`scheduler_php` benennt den geprüften PHP-CLI-Pfad des Hosters.
Die vollständigen Regeln und den gleichwertigen direkten Einstieg zeigt
[install-native.php](install-native.php).

Mit `--resume` lässt sich ein eigener begonnener Vorgang fortsetzen:

```sh
php distribution/install.php --config-stdin --resume < /ABSOLUTER/PRIVATER/PFAD/einrichtung.json
```

Zugangsdaten werden über STDIN übergeben. Die Konfigurationsdatei gehört
außerhalb des Repositorys und des öffentlichen Webbereichs; Zugangsdaten nicht
in Befehlsargumente schreiben. Vorhandene Datenbanken und Installationen werden
nicht als Neuinstallation ersetzt.

## Paketpflege

Das Repository liefert die geprüften PHP-Bibliotheken unter `packages/php-runtime`
und die getrennten optionalen Linux-Laufzeiten mit. Die Installation benötigt
keinen Composer-Download. Lizenzen und Herkunftsnachweise bleiben im Paket.

Quelländerungen benötigen ein erneuertes `source-files.json`. Quellen und Manifest
bleiben während einer tatsächlichen Installerprüfung unverändert.
Die dokumentierte Entwicklungsumgebung und ihre Prüfungen stehen unter
[Neustart aus Git](../docs/CLOUD-NEUSTART-AUS-GIT.md).
