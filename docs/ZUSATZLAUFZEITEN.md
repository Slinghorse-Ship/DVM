# OCR, KoSIT und ERiC ergänzen

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

## 1. Gewählte Pakete offline vorbereiten

Im vollständigen entpackten Quellpaket mit PHP ab 8.3 und den
[Installer-Erweiterungen](WEBSPACE-INSTALLATION.md#voraussetzungen):

```sh
php distribution/prepare-runtimes.php --target=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN --components=ocr,kosit,eric
```

Der Elternordner muss bestehen. Der Zielordner muss neu sein und außerhalb des
Checkouts sowie des öffentlich erreichbaren Webroots liegen. Für eine einzelne
Gruppe beispielsweise `--components=kosit` verwenden. Bestehende Ziele werden
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

## 2. OCR verbinden

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

## 3. KoSIT verbinden

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

## 4. ERiC verbinden

[Vertragsbedingungen und Pflichtinformationen](ERIC-LIZENZ.md) vor Nutzung
umsetzen. Die Programmbibliothek liegt nach Vorbereitung unter:

```text
/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN/runtime/eric-44.3.6.0/lib/libericapi.so
```

Den benötigten Formularkatalog aus selbst bezogenen passenden amtlichen Schemata
erzeugen. Private Laufzeitdatei, eigene Hersteller-ID, Python-Adapter und
Formularprofile einrichten; `VEREIN_ERIC_RUNTIME` auf diese private Datei setzen.
Produktivfreigaben und Profiltransporte bleiben bei Ersteinrichtung aus.
[Schrittfolge und Konfigurationsfelder](vereinsverwaltung/ELSTER-ERIC-INTEGRATION.md).

Der lokale Bibliothekscheck verwendet kein Zertifikat und sendet keine Daten:

```sh
VEREIN_ERIC_RUNTIME=/PRIVAT/eric.json php vereinsverwaltung/tools/check-eric-runtime.php
```

Für tatsächliche Abgaben sind zusätzlich ein eigenes geeignetes Zertifikat und
die getrennte Prüfung/Freigabe der jeweiligen Datenart erforderlich. Die aktuelle
Mindestversion im eigenen ELSTER-Entwicklerzugang prüfen.

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
