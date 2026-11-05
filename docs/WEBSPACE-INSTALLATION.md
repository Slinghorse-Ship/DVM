# DVM auf PHP-Webspace einrichten

Die Datenbank bleibt **MySQL**. Der native Browserweg benötigt keine zweite
Website-Datenbank. Die Browser-Einrichtung benötigt keine Shell auf dem Hosting.

Für die erste Veröffentlichung wird ein vollständiges Installationspaket
ausgeliefert. Alle Datenbankschritte und Moduldefinitionen sind darin enthalten;
der Assistent führt sie automatisch bis zum aktuellen Stand aus. Separate
Migrationsdateien müssen weder heruntergeladen noch manuell ausgeführt werden.
Die Aufteilung dieser Dateien im Quellcode dient der internen Verwaltung.

Neue Bündel und Installationen ergänzen `.user.ini` mit
`upload_max_filesize=256M`, `post_max_size=520M`, `max_file_uploads=50` und
`max_input_vars=8192` für vollständige Rechnungsformulare mit bis zu 50 Positionen.
Eine vorhandene eigene `.user.ini` bleibt unverändert. Übernimmt der Webspace
diese Werte nicht automatisch, die entsprechenden PHP-Einstellungen beim Hoster
setzen. Ein abgeschnittener Rechnungsentwurf wird vor einer neuen Ausgabe
abgewiesen; vollständige Originaleingaben im Browser behalten.
Damit passen auch mehrere große Kameraoriginale in den Server-Fotoweg;
[Größen, Speicher und tatsächliche Prüfung](GROSSE-KAMERAORIGINALE.md).
Damit passen auch größere gespeicherte MP4-Reels und Mehrfachauswahlen durch
die PHP-Anfrage. Das fachliche Videolimit bleibt 100 MiB. Bereits vorhandene
ausdrückliche eigene Werte bleiben erhalten; PHP-FPM/CGI und Hostingvorgaben
bestimmen, welche Werte tatsächlich gelten. Der lokale Upload wurde oberhalb
der früheren 2-MiB-Datei-/8-MiB-Formulargrenzen tatsächlich geprüft.

Optional können HEIC-/RAW-Dateien zusätzlich ohne JavaScript auf dem Server
vorbereitet werden. Dafür Node.js mit ausführbarem `proc_open` einrichten;
`VEREIN_PHOTO_NODE` benennt bei Bedarf den Node-Pfad. Die gebündelten Codecs
werden ausschließlich lokal verwendet. Normale Fotos und die übliche lokale
Browservorbereitung benötigen diese zusätzliche Laufzeit nicht.
[Grenzen und Originalschutz](PRESSE-FOTOUPLOAD-MODULRECHTE.md).

## Voraussetzungen

Bereits eingerichtete Installationen verwenden den eigenen
[Paketupgrade-Assistenten](PAKET-UPGRADE.md), einschließlich gespeicherter
Fortsetzung und ausdrücklicher Rückkehr. Die Neuinstallation verlangt weiterhin
eine neue leere Datenbank.

- PHP ab 8.3; `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `gd`, `intl`,
  `dom`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, `fileinfo`.
- MySQL ab 8.0.16, eigene leere Datenbank, InnoDB, UTF-8 sowie Rechte für Tabellen,
  Indizes, Änderungen und die vorhandenen fachlichen Schutztrigger.
- HTTPS auf einer eigenen Domain. Nur `web/` darf öffentlich sein; `app/` und
  `private/` liegen außerhalb und sind für PHP lesbar beziehungsweise schreibbar.
  `open_basedir` muss diese eigenen Verzeichnisse zulassen.
- Apache mit freigegebenem Rewrite oder entsprechender Hosting-/Nginx-
  Frontcontroller: Domain-Anfragen an `web/index.php`; `/setup/` bleibt bis zum
  Abschluss als wirklicher Ordner erreichbar.

Ein reiner statischer Webspace oder ein Tarif ohne diese PHP-/MySQL-/Dateifunktionen
kann die Anwendung nicht ausführen. Die Prüfung zeigt fehlende Voraussetzungen;
sie schwächt weder Paketprüfsummen noch Zugangs-/Originalschutz ab. MariaDB ist
nicht Teil der vereinbarten Datenbankunterstützung.

## Upload-Paket erzeugen

Auf der Entwicklungsmaschine den vollständigen Offline-Bestand prüfen und dann:

```sh
php distribution/build-webspace.php --target=/ABS/NEUES-DVM-UPLOADPAKET
```

Der Builder prüft den versionierten Quell-/Bibliotheksbestand und entpackt die
benötigten PHP-Bibliotheken mit dem pfad-/byteprüfenden Entpacker. Lokale Testdatenbanken, Tests und Mockups gehören nicht in das Upload-Paket.
Das Grundpaket enthält keine Linux-Binärdateien. Für das optionale OCR-Paket
weiter unten denselben Builder mit `--with-ocr` verwenden. Bibliothekslizenzen
bleiben enthalten.

Ergebnis: `app/`, `private/`, `web/`, kurzer Hinweis und `SETUP-KEY.txt`.
Die Schlüsseldatei ist nur für den Betreiber; nicht öffentlich hochladen.
Die drei Verzeichnisse nebeneinander platzieren, Domain auf `web/` legen.
Heißt der öffentliche Hosting-Ordner bereits `httpdocs`, `htdocs` oder `public_html`,
`web/` entsprechend umbenennen; `app/` und `private/` bleiben seine privaten Nachbarn.
Private Verzeichnisse: 0700; private Dateien: 0600. Bei FTP/SFTP diese Rechte
erhalten; der PHP-Benutzer muss Eigentümer sein.

## Im Browser

1. `https://DEINE-DOMAIN/setup/` öffnen; Voraussetzungen kontrollieren.
2. Schlüssel aus `SETUP-KEY.txt`, Vereinsdaten, Zugang zur leeren Datenbank und
   ein Administratorkonto mit eigenem Passwort eintragen.
3. Je Anfrage werden höchstens 200 Paketdateien geprüft, dann gewählte Zusatzfunktionen, die Datenbank und
   die gemeinsamen nativen Module. Fortschritt bleibt privat verschlüsselt;
   Passwörter stehen weder in Antworten noch in URL oder Browser-Speicher.
4. Bei Unterbrechung mit gleichem Schlüssel „Begonnene Einrichtung fortsetzen“
   verwenden. Erneutes Beginnen ersetzt keinen vorhandenen Vorgang. Fremde
   nicht leere Datenbanken werden abgewiesen. Ein DDL-Abbruch während der ersten
   Baseline-Anlage ist noch kein allgemein abgenommener Restoreweg.
5. Nach Abschluss bei `/login/` anmelden. Der Installationsnachweis sperrt weitere
   Einrichtung; gespeicherte Setup-Zugangsdaten und Schlüsselprüfwert werden
   entfernt. Danach `web/setup/` und lokale `SETUP-KEY.txt` entfernen.

Konten, Auth-/Privat-/Bankdatenschlüssel, Vereinsdaten und alle Modulversionen
nutzen dieselbe Initialisierung wie der native CLI-Installer. Es werden keine
synthetischen Kontakte, Veranstaltungen oder Demobenutzer angelegt. Anbieter-
zugänge und Versand bleiben bis zur ausdrücklichen Einrichtung deaktiviert.

## Automatische Aufgaben im Hosting

Im Assistenten unter **Automatische Aufgaben im Hosting einrichten** den eigenen
PHP-CLI-Pfad auswählen. Nach Abschluss erscheinen die konkreten Zeitmuster und
Aufrufe für diese Installation; die Anleitung lässt sich herunterladen.
Das richtet noch keinen Cronjob beim Hoster ein und bestätigt keinen Lauf.

In cPanel die benötigten **Cron-Jobs**, in Plesk die **Geplanten Aufgaben** mit
den kopierten Befehlen einrichten. Mail, Veröffentlichung, Bank, Postfach,
Systemaufgaben und Status verwenden getrennte Modi. PHP ab 8.3 mit den erforderlichen
CLI-Erweiterungen und der Installationsbenutzer sind erforderlich.
`private/worker.php` bleibt geschützt; die Befehle enthalten keine Zugangsdaten.
Die Anleitung kann mit der privaten CLI-Hilfe später erneut erzeugt werden.

Einen bewussten Testlauf und einen regulären Lauf bei geschlossenem Manager prüfen.
Erst tatsächliche erfolgreiche Läufe bestätigen den Hintergrundbetrieb.
[Einrichtung, alle Modi und Wiederaufnahme](HOSTING-ZEITPLAENE.md).

## Zusatzfunktionen

Bootstrap **6.0.0-alpha.1** wird einschließlich Bundle-Lizenzen und Prüfsummen
im Upload-Paket mitgeliefert. Manager- und Kontoseiten laden CSS und ES-Modul
vom eigenen Webspace; dafür sind weder CDN-Zugriff noch npm auf dem Hosting nötig.
Die gemeinsame Zuordnung liegt unter `native/Web/BootstrapAssets.php`, die
Originaldateien samt Herkunft unter `public/vendor/bootstrap/`.

Meta- und StadtLand.Funk-Kontoverwaltung sowie persönliche PHP-HTTP-Abrufe sind
im Paket enthalten. Konten nach der Installation unter Website-Verbindungen
einrichten; für automatische Kommentar-/Nachrichtenprüfung den getrennten
`--social-check`-Zeitplan und die Kanalpläne im Social Hub verwenden.
[Aufruf und unterschiedliche Abrufzeiten](NATIVE-HINTERGRUNDJOBS.md).
Gespeicherte Passwörter/Tokens liegen verschlüsselt in MySQL; die vorhandene
private Initialisierung erstellt den Schlüssel außerhalb des Webbereichs.

### FinTS im bestehenden Assistenten

Die festgelegte Bibliothek `nemiah/php-fints` **4.1.0** mit ihrem geprüften
Commit wird bereits entpackt ausgeliefert. Unter „FinTS und OCR einrichten“
FinTS wählen, bestätigten HTTPS-Endpunkt der Bank, achtstellige BLZ, eigene
25-stellige Registrierungs-ID der Deutschen Kreditwirtschaft und Produktversion
(höchstens fünf Zeichen) eintragen. Die bewusste Bestätigung ist erforderlich.
Es gibt keine allgemeine mitgelieferte Bankzulassung.

Die Einrichtung prüft die tatsächlich installierte Bibliothek und erstellt
`private/banking/fints.json` sowie einen eigenen dauerhaften 32-Byte-Schlüssel
`fints-state.key` mit 0600. `VEREIN_BANKING_ROOT` und `VEREIN_FINTS_ROOT`
zeigen auf dasselbe private Verzeichnis; Browserdialog und vorhandener Zeitplan
verwenden dieselbe Verbindung. Wiederaufnahme erhält den Schlüssel und ersetzt
keine abweichende bestehende Konfiguration. Bankkennung/PIN/TAN werden erst im
Bankdialog eingegeben; der Installer öffnet keine Bankverbindung und löst weder
Abruf noch Zahlung aus. Ohne diese Auswahl bleibt FinTS unkonfiguriert.
Eine spätere private Ersteinrichtung ist mit dem vorhandenen
`app/banking/configure-fints.php` und Metadaten auf stdin möglich; Betreiber mit
reinem FTP-Zugang können die privaten Dateien kontrolliert vorbereiten. Reale
Bankfreigabe, TAN-Verfahren und Kontorechte müssen separat abgenommen werden.

Die [Zusatzlaufzeit-Anleitung](ZUSATZLAUFZEITEN.md) beschreibt die getrennte
Vorbereitung und Nachrüstung von OCR, KoSIT und ERiC, einschließlich privater
Konfiguration und Vertragsbedingungen.

### Optionales OCR-/PDF-Paket

Auf der Entwicklungsmaschine mit demselben Builder:

```sh
php distribution/build-webspace.php --target=/ABS/NEUES-DVM-UPLOADPAKET --with-ocr
```

Die vorhandenen offline geprüften Archive werden vor dem Upload entpackt:
Python 3.11.2/ReportLab 4.4.9, Tesseract 5.5.3, PDFium 5.13.0, Pillow 12.3.0,
HEIC-Decoder und Deutsch-/Englischmodelle. Erforderliche Skripte, Prüfsummen,
Lizenzen und zugehörige Quellarchive einschließlich der GPL-HEIC-Bestandteile
bleiben enthalten. Java/KoSIT/Mustang und Cloud-Konfigurationen werden
nicht benötigt. Private Laufzeit liegt unter `app/runtime/`; ihre einzelnen
Bytes stehen im selben `private/app-files.json` wie die Anwendung.

Auf dem Hosting: **Linux x86_64**, ausführbare private Dateien, PHP-Prozessfunktionen
einschließlich `proc_open`, **Landlock ABI mindestens 6 und seccomp**. FTP/SFTP
muss die Ausführungsrechte der Binärdateien erhalten. Der Assistent zeigt Paket,
Architektur und Prozessfunktionen an. Bei gewähltem OCR-Schritt prüft er vor
der Datenbankeinrichtung Interpreter/Modulversionen, Modellhashes und die echte
Erkennung einer ausschließlich synthetischen Bildrechnung mit Nummer, Datum und
Betrag. Die bestehende Sandbox bleibt verpflichtend. Erst nach erfolgreicher
Prüfung werden `VEREIN_PYTHON` und `VEREIN_OCR_RUNTIME` in der privaten Laufzeit
aktiviert. Es entstehen weder Managerbelege noch Finanzbuchungen.

Bei fehlenden Voraussetzungen den Hosting-Zugang korrigieren und fortsetzen,
oder OCR abwählen und „OCR-Auswahl im begonnenen Vorgang übernehmen“ verwenden.
Diese Änderung ist nur im Zusatzfunktionsschritt vor der Datenbankeinrichtung
mit demselben Einrichtungsschlüssel erlaubt; Vereins-/Kontodaten bleiben erhalten.
Das Grundpaket funktioniert weiterhin ohne Prozessfunktionen. XML-Rechnungsdaten
benötigen keine OCR-Laufzeit. Das optionale Linux-Paket ist keine Behauptung,
dass jeder Hostingtarif diese Kernel-/Ausführungsrechte bereitstellt.

Der gemeinsame `RuntimeInstaller::configureOcr()` unterstützt für kontrollierte
CLI-/Containerumgebungen auch den bestehenden fixierten Docker-Weg. Der
Browser-Assistent installiert keinen Docker-Daemon und weicht bei fehlendem
Landlock nicht auf eine ungeschützte Ausführung aus. Weitere Rechnungsvarianten,
produktive Hostingabnahme und E-Rechnungs-/Video-/Steuerlaufzeiten bleiben offen.

Die gemeinsame Statusseite steht unter **Einstellungen → Systemstatus**.
Sie startet ohne öffentliche Freigabe und ohne automatische Prüfungen.
Ein eigener Zeitplan alle fünf Minuten kann nach bewusster Aktivierung aufrufen:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/service-status-worker.php --config /ABS/DVM/private/worker.php
```

Der Statusworker verwendet dieselbe private Installationseinstellung, verarbeitet
aber ausschließlich Verbindungs-/Laufzeitnachweise. Er akzeptiert keine Versand-
oder Veröffentlichungsoptionen und beachtet Upgrade-/Wiederherstellungssperren.
Für reinen Webspace ohne CLI-Zeitplan bleibt „Prüfen“ im berechtigten Manager
verfügbar. Das Öffnen einer Statusansicht startet keinen Anbieterabruf.
[Prüfumfang, Verlauf und öffentliche Auswahl](SYSTEMSTATUS-VERFUEGBARKEIT.md).

Das Upload-Paket enthält den privaten CLI-Versandworker unter
`app/vereinsverwaltung/bin/native-delivery-worker.php`. Der Hosting-Zeitplan kann
ihn mit `--config=/ABS/DVM/private/worker.php` aufrufen. Ohne `--send` werden nur
vorbereitete Vorgänge verarbeitet; Transport bleibt ausgeschaltet. Erst nach
Einrichtung der gemeinsamen Postfächer und Versandzwecke wird `--send` ergänzt.
Facebook-/Instagram-Veröffentlichungen laufen getrennt mit `--meta --send`; dieser
Modus benötigt keinen aktivierten Mailversand und bereitet keine anderen Queues
vor. Persönliche Termine nutzen getrennt `--stadtlandfunk --send`. Beide Modi
beachten die Veröffentlichungssperre und den aktuellen eigenen Verein.
Die Konfiguration bleibt außerhalb des Webbereichs (0600). Die native Kontoanlage
verwendet persönliche Benutzernamen, einmalige Einladungen und bestehende
Mitgliedschafts-/Familienbindungen. Willkommensmail und archivierte Beitrags-PDF
nutzen getrennte Versandzwecke; unklare Zustellungen werden nicht erneut versandt.
Passwort-Reset verwendet ebenfalls eine verschlüsselte Einmal-Link-Warteschlange
und den getrennten Versandzweck `password_reset`. Abgelaufene, ersetzte oder
gesperrte Aufträge werden verworfen; unklare Zustellungen werden nicht wiederholt.
E-Mail-MFA verwendet den separaten Zweck `mfa`, achtstellige Einmalcodes und
Bestätigung des eigenen Passworts. Dafür den privaten Worker mindestens jede
Minute einplanen. Gerätewahl und persönliche Profil-/Rollen-/Sperrzuordnung
verwenden die persönliche DVM-Anmeldung. Ursprüngliche Mail-/Rechnungs-/Beitragsjobs
sind angeschlossen; `--receive` und `--push` aktivieren die getrennten eigenen
Postfach- und Pushwege. Die ausdrückliche Beitragsautomatik und Betriebsanzeige
sind in [Hintergrundaufgaben](NATIVE-HINTERGRUNDJOBS.md) beschrieben.

Der PHP-Browser-Installer benötigt kein `proc_open`. PDF-/OCR-/Video- und amtliche
Steuerwerkzeuge haben eigene Programm-/Plattformvoraussetzungen. Geplante Jobs
benötigen einen Hosting-Zeitplan und den privaten Worker. Die Anleitungen für
[Paketupgrade](PAKET-UPGRADE.md) und [Sicherung/Wiederherstellung](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md)
beschreiben die jeweiligen Voraussetzungen und Prüfgrenzen. Ein CLI-Recovery
ist kein Nachweis eines Browser-Recovery des Upload-Pakets.

## Website in Suchmaschinen

Unter **Website → Vereinsdaten & Verbindungen → Website in Suchmaschinen**
die öffentliche Indexierung einschalten, sobald die Inhalte bereitstehen. Eine
neue Installation bleibt zunächst gesperrt. Die Freigabe gilt nur für die
konfigurierte HTTPS-Domain im nativen Produktionsbetrieb. Entwicklungsseiten,
Suchergebnisse, Anmeldungen, interne Bereiche und Tokenwege bleiben ausgeschlossen.
„Nicht in Suchmaschinen indexieren“ gilt weiterhin je veröffentlichter Seite;
eine Änderung im Entwurf wirkt erst nach Veröffentlichung.

`/sitemap/` und `/sitemap.xml` verwenden veröffentlichte Originale einschließlich
ihres Veröffentlichungsdatums. Private Entwürfe und ausschließlich für RSS
freigegebene Beiträge erscheinen darin nicht. Die Suchmaschinenwahl hat eine
eigene Version und Historie; Vereinsdaten und freigegebene Bildfassungen bleiben
bei ihrer Änderung erhalten. Der CMS-Schemastand dafür ist **5**.

## Entwicklungsprüfung

`native-webspace-installer.php` prüft die echte HTTP-Einrichtung auf synthetischem
MySQL mit abgeschalteten Prozess-/Shell-Funktionen, geprüftem Upload, Wiederaufnahme,
privaten Schlüsseln, eigenem Verein und Abschluss-Sperre. Mit
`DVM_INSTALLER_BROWSER=1` wird derselbe Ablauf im wirklichen Chromium ausgeführt;
1440/820/390 Pixel, Tastatur, Fortschritt, Abschluss und native Anmeldung sind geprüft.
Dieser Test läuft auch in der GitHub-Prüfgruppe und im Git-eigenen Cloud-Startskript.

## Optionale serverseitige Fotoaufbereitung

Das Paket enthält beide lokalen Foto-Serverprogramme und ihre Codecs samt
korrespondierenden Quellen. Für große Fotos ohne Browservorbereitung benötigt
es Node.js ab 22 im Hosting-PATH oder den privaten Betreiberwert
VEREIN_PHOTO_NODE sowie PHP-Prozessfunktionen. Setup und privater Systemstatus
zeigen die Voraussetzungen getrennt von einer tatsächlichen Funktionsprüfung.
Kleine Fotos und die Browservorbereitung bleiben unabhängig davon nutzbar.
PHP-Anforderungsspeicher, nativer HTTP-Prozessspeicher und Node-Speicher werden
getrennt betrachtet. [Bedienung und Grenzen](CMS-FOTOS-SERVERVORBEREITUNG.md).
