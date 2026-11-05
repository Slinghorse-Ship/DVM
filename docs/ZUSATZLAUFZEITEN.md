# OCR, XRechnung/KoSIT und ERiC einrichten

OCR erkennt Text aus Bildern. KoSIT prüft strukturierte XRechnung-XML-Dateien.
ERiC ist die amtliche ELSTER-Laufzeit für die gesondert eingerichtete Steuerprüfung
und Übermittlung. Die drei Programme werden unabhängig voneinander eingerichtet.

Diese Anleitung gilt für eine neue oder bestehende eigene DVM-Installation.
Die Linux-x86_64-Artefakte liegen im vollständigen Quellpaket unter
`distribution/packages/linux-runtime/`. Das Standard-Dockerimage enthält sie
nicht. Der neue Vorbereitungsbefehl prüft die vorhandenen Offline-Pakete und
entpackt nur die gewählten Gruppen. Er lädt nichts herunter, startet keine
Programme und verändert keine bestehende Installation.

| Gruppe | Zweck | Zusätzliche Voraussetzung |
|---|---|---|
| `ocr` | Texte aus Bildern und gescannten Rechnungen erkennen | Linux x86_64, PHP-Prozessfunktionen, Landlock ABI 6 und seccomp |
| `kosit` | XRechnung nach dem mitgelieferten Regelstand prüfen | Java 17 und passende XRechnung-Regeln; im Paket enthalten |
| `eric` | Amtliche ELSTER-Laufzeit verwenden | Gültige Herstellerregistrierung, passende Formularstände und ERiC-Pflichtinformationen |

Vorbereiten und Aktivieren sind getrennt. Eigene Kennungen und Zertifikate
werden nicht aus einer anderen Installation übernommen. Die Programme benötigen
ein privates Dateisystem mit passenden Lese- und Ausführungsrechten.

## Gemeinsame Vorbereitung

Die folgenden Befehle verwenden Linux-/Unix-Pfade. Dafür wird ein geeigneter
Arbeitsplatz oder ein CLI-Zugang beim Hoster benötigt; bei reinem FTP-Hosting
übernimmt die technische Betreuung diese Schritte. Die Zielprogramme benötigen
Linux x86_64. Im vollständigen entpackten Quellpaket mit PHP ab 8.3 und den
[Installer-Erweiterungen](WEBSPACE-INSTALLATION.md#voraussetzungen):

```sh
php distribution/prepare-runtimes.php --target=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN --components=ocr,kosit,eric
```

Der Elternordner muss bestehen. Der Zielordner muss neu sein und außerhalb des
Checkouts sowie des öffentlich erreichbaren Webroots liegen. Für eine einzelne
Gruppe beispielsweise `--components=kosit` verwenden. Wurde OCR schon mit
`--with-ocr` eingerichtet, für die weitere Vorbereitung nur `kosit,eric` wählen. Bestehende Ziele werden
nicht überschrieben. Nach einem abgebrochenen Versuch einen neuen Zielpfad
wählen und den unvollständigen Bestand zuerst prüfen.

Das Ergebnis enthält:

- `runtime/`: die ausgewählten Programme, Modelle und Prüfregeln;
- `distribution/`: erhaltene Lizenztexte, Herkunftsnachweise und zugehörige
  Quellarchive der gewählten Komponenten;
- `runtime-files.json`: Bytezahlen, SHA256, Dateirechte und Paketbindung;
- `README.txt`: Hinweise zur anschließenden Einrichtung.

Die ERiC-Auswahl verwendet das geprüfte DVM-Laufzeitpaket mit den unveränderten
Originalbibliotheken und Plugins. Das vollständige Entwickler-SDK ist nicht enthalten. Entwicklerhandbücher, Schemadokumentation,
SDK-Beispielverzeichnisse und Zertifikate gelangen nicht in das vorbereitete
Laufzeitverzeichnis. Das Ergebnis ist kein unabhängig weiterlizenzierbares SDK;
[ERiC-Bedingungen](ERIC-LIZENZ.md) gelten weiterhin.

## OCR einrichten

Für eine **neue** Webspace-Installation ist der vorhandene Assistent der kürzeste
Weg. Statt des separaten Vorbereitungsbefehls das komplette Upload-Paket bauen:

```sh
php distribution/build-webspace.php --target=/ABS/NEUES-DVM-UPLOADPAKET --with-ocr
```

Hochladen, OCR im HTTPS-Assistenten auswählen und seine tatsächliche
Funktionsprüfung abschließen. Dieses Flag installiert weder Java/KoSIT noch ERiC.
[Ausführliche Voraussetzungen](WEBSPACE-INSTALLATION.md#optionales-ocr-pdf-paket).

Bei einer bestehenden Installation kann die technische Betreuung die bereits
vorbereiteten OCR-Dateien mit dem vorhandenen Konfigurator verbinden:

```sh
php -r 'require "distribution/src/RuntimeInstaller.php"; echo json_encode(RuntimeInstaller::configureOcr($argv[1], $argv[2], $argv[3]), JSON_PRETTY_PRINT).PHP_EOL;' \
  /PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime /PFAD/DVM/app /PFAD/DVM/private
```

Er prüft Interpreter, Module, Modellhashes und Sandboxvoraussetzungen und erstellt
private Konfigurationen. Die ausgegebenen Werte `VEREIN_PYTHON` und
`VEREIN_OCR_RUNTIME` anschließend in der privaten Umgebung des PHP-Webprozesses
und der betroffenen Worker hinterlegen. Unterschiedliche vorhandene
Konfigurationsdateien werden nicht überschrieben. Keine bestehende Datenbank
neu installieren. Eine echte synthetische Erkennungsprüfung im späteren
Webkontext bleibt erforderlich.

## XRechnung mit KoSIT einrichten

Die Paketgruppe enthält KoSIT 1.6.3, Java 17.0.20.1+1 und die
XRechnung-Konfiguration 3.0.2 vom 31.08.2026. Nach Vorbereitung prüfen:

```sh
/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/kosit-1.6.3-xrechnung-20260831/java-private -version
```

Eine neue private Konfiguration erzeugen; der Ausgabeordner und der
Arbeitsordner benötigen 0700:

```sh
php distribution/configure-kosit.php \
  --java=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/kosit-1.6.3-xrechnung-20260831/java-private \
  --jar=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/kosit-1.6.3-xrechnung-20260831/validator.jar \
  --repository=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/kosit-1.6.3-xrechnung-20260831/config \
  --workdir=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/kosit-1.6.3-xrechnung-20260831/results \
  --output=/PRIVAT/kosit.json
```

Das Werkzeug kontrolliert Pfade und KoSIT-Pakethash und bindet die Konfiguration
an den Inhalt der Regeln. Es schreibt eine neue Datei mit 0600, führt aber keine
Rechnungsprüfung aus. `VEREIN_KOSIT_RUNTIME=/PRIVAT/kosit.json` im PHP-Webprozess
und den betroffenen Jobs setzen. Anschließend eine ausschließlich synthetische
XRechnung mit dem konfigurierten Validator prüfen. Ein Java-Versionsaufruf allein
bestätigt keine fachliche Rechnungsprüfung. Neue Regelstände getrennt abnehmen.

## ERiC einrichten

Die Bibliotheken liegen nach der Vorbereitung unter
`runtime/eric-44.3.6.0/lib/libericapi.so`. Sie allein ersetzen weder die eigene
Herstellerregistrierung noch Formularkatalog, Zertifikat oder Produktivfreigabe.

### Vorbereitung

1. Die für den eigenen Produktbetrieb gültige Herstellerregistrierung klären.
   ELSTER verwendet fünf Ziffern; eine FinTS-Produkt-ID ist eine andere Kennung.
2. Aktuelle freigegebene Mindestversion im eigenen ELSTER-Entwicklerzugang prüfen.
   Die enthaltene Version 44.3.6.0 ist ein festgelegter Paketstand, kein Updater.
3. Die Datenschutzinformationen und bestätigte Kenntnisnahme gemäß § 5 sowie die
   übrigen Betreiberpflichten vor ERiC-Nutzung organisatorisch einrichten.
4. Die Programmbibliotheken außerhalb des Webroots vorbereiten. Native Laufzeit,
   Python, Plugins und eventuell erforderliche Systembibliotheken müssen vom
   späteren PHP-Webprozess erreichbar und ausführbar sein.

### Formularkatalog

Die vollständige Entwicklerdokumentation mit den passenden amtlichen Schemata
selbst über ELSTER beziehen und privat aufbewahren. Der Compiler verarbeitet
diese lokalen Originale; DVM verteilt die Dokumentationsarchive nicht:

```sh
python3 vereinsverwaltung/tools/compile-eric-forms.py \
  --documentation /PRIVAT/ERiC/Dokumentation \
  --output /PRIVAT/eric/form-catalog.json --years 2025 \
  --periodic-years 2025 2026 --messages --registration
```

Das sind die vorhandenen Compileroptionen, keine Freigabe zusätzlicher Jahre.
Jeder Zeitraum benötigt seinen tatsächlichen passenden Schema- und Laufzeitstand.

### Private Konfiguration

Eine neue JSON-Datei mit 0600 anlegen und ihren absoluten Pfad als
`VEREIN_ERIC_RUNTIME` im PHP-Webprozess und in den betroffenen privaten Jobs setzen.
Erforderliche Angaben:

| Feld | Bedeutung |
|---|---|
| `version` | Konfigurationsformat 1 |
| `manufacturer_id` | Eigene gültige fünfstellige Hersteller-ID |
| `library` | Absoluter Pfad zu `lib/libericapi.so` |
| `scratch` | Privates beschreibbares Arbeitsverzeichnis mit 0700 |
| `catalog` | Absoluter Pfad zum selbst erzeugten Formularkatalog |
| `command` | Argumentliste aus absolutem Python-Interpreter und absoluter `eric-bridge.py` |
| `profiles` | Passende Katalogprofile ohne die umfangreichen `types` |
| `production_enabled` | Bei Ersteinrichtung `false` |
| `production_versions` | Bei Ersteinrichtung leere Liste |

Auch `transport_enabled` der Profile bleibt bis zur gesonderten Prüfung aus.
Bei einer Produktivinstallation gehört die Konfiguration zum eigenen Verein;
vorhandene Bindungen und Originalfelder erhalten. Änderungen in neuen privaten
Versionsverzeichnissen vorbereiten, keine aktive Bibliothek unter gleichem Pfad
überschreiben.

Die Hersteller-ID kann ein berechtigter technischer Verwalter unter
**Einstellungen → Steuern** hinterlegen. Ohne expliziten Laufzeitpfad verwendet
DVM `VEREIN_PRIVATE_ROOT/runtime-config/eric.json`. Eine neue Kennung installiert
keine Bibliothek und aktiviert keine Übermittlung.

Windows benötigt passende amtliche Windows-Bibliotheken und zusätzlich
`windows_process_command` mit dem absoluten Python-Interpreter. Das enthaltene
Linux-Paket ist dafür nicht geeignet.

### Prüfen und freigeben

```sh
VEREIN_ERIC_RUNTIME=/PRIVAT/eric.json php vereinsverwaltung/tools/check-eric-runtime.php
```

Dieser Check prüft Initialisierung und Version, ohne Steuer-XML, Zertifikat oder
Versand. Anschließend passende synthetische Formulare, PDF-Ausgaben und die
vereinbarte Testübermittlung getrennt abnehmen. Erst danach gegebenenfalls eine
Produktivfreigabe pro unterstützter Datenart erteilen. Historische Prüfungen
anderer Installationen ersetzen diese Schritte nicht.

Für [E-Bilanz](EBILANZ-UMGEBUNGEN.md) gelten eigene fachliche Grenzen.
Ein Bibliotheksstart ist keine Formularprüfung oder erfolgreiche Abgabe.
Testübermittlungen sind ebenfalls externer Versand und benötigen ausdrücklich
synthetische Daten, den passenden Testmerker und eine gesonderte Freigabe.
Arbeitsunterlagen und ein Transferticket bestätigen keine steuerfachliche Anerkennung.

Zertifikate über den Steuerbereich einrichten und ihr Passwort getrennt privat
aufbewahren. Lokale Entwicklung kann mit `VEREIN_ERIC_DISABLE_TRANSPORT=1`
Übermittlungen ausdrücklich sperren. Den tatsächlichen Zertifikats-,
Formular- und Versandweg vor einer Freigabe gesondert prüfen.

## Docker

Die Vorbereitung auf dem Host und die Einrichtung im Container sind getrennte
Schritte. Dateien in das private persistente Volume kopieren oder dort einen
geeigneten privaten Mount vorsehen. Erst am endgültigen Containerpfad die
Konfigurationen erzeugen; Hostpfade funktionieren nicht automatisch im Container.
Der PHP-Benutzer muss die Programme und privaten Arbeitsordner erreichen können.
Ein Image-Neustart installiert oder aktiviert keine Zusatzpakete im bestehenden
Volume. Der bestehende Einrichtungsassistent darf für eine Nachrüstung keine
neue Datenbankinitialisierung ausführen.

Für OCR gelten auch im Container die Kernel- und Sandboxanforderungen. Einen
Docker-Socket nicht pauschal an die Webanwendung durchreichen. Die
[Docker-Anleitung](../docker/dvm/README.md) beschreibt Grundinstallation,
Persistenz und Updates. Ein automatischer Nachinstallationsassistent im Manager
ist mit diesen CLI-Werkzeugen noch nicht verbunden.

## Paketinhalt und Lizenzen

Das Quellpaket enthält die geprüften Laufzeitarchive einschließlich der zugehörigen
Lizenztexte, Herkunftsnachweise und erforderlichen Quellarchive. Eigene API-Token,
FinTS-Produktkennungen, Hersteller-IDs und Steuerzertifikate werden nicht mitgegeben.
Das [Paketmanifest](../distribution/packages/linux-runtime/manifest.json) bindet die
Archivteile und Gesamtdateien mit SHA256.

ERiC 44.3.6.0 wird als DVM-Produktlaufzeit in 16 Teilen ausgeliefert. Sie enthält
225 unveränderte amtliche Bibliotheken/Plugins und die Pflichttexte; kein vollständiges
Entwickler-SDK und keine Entwickler- oder Schemadokumentationsarchive.
[Herkunft und Dateiprüfsummen](../distribution/packages/linux-runtime/provenance/eric-runtime-44.3.6.0.json),
[Vertrag und Datenschutzpflichten](ERIC-LIZENZ.md). Eine aktuelle Mindestversion
muss der verantwortliche Hersteller im eigenen ELSTER-Entwicklerzugang prüfen.

## Abschluss prüfen

| Funktion | Nachweis auf der eigenen Installation |
|---|---|
| OCR | Eine ausschließlich synthetische Bildrechnung wird tatsächlich erkannt; die Schutzprüfung bleibt aktiv. |
| XRechnung/KoSIT | Eine synthetische gültige Rechnung wird geprüft und eine fachlich ungültige Variante mit verständlichem Bericht abgewiesen. |
| ERiC | Lokale Initialisierung und Version funktionieren. Formularprüfung, Zertifikat und ausdrücklich freigegebener Versand werden getrennt geprüft. |

Die Variablen müssen im **PHP-Webprozess und den betroffenen Jobs** gelten, nicht
nur im eigenen Terminal. Nach einem Prozessneustart den Systemstatus prüfen.
Eine grüne Konfigurationsanzeige ersetzt keine tatsächliche Funktionsprüfung.
