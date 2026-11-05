# E-Rechnungen: Erkennung und vollständige Regelprüfung

Die XML-Uploadprüfung allein bestätigt nur sichere Wohlgeformtheit. `EInvoiceInspection` erkennt die vollständigen Root-QNames von UBL Invoice, UBL CreditNote und CII. Fehlende Pflichtangaben sind dabei ausdrücklich nicht geprüft. Auch ein leeres Invoice-Element darf niemals als fachlich gültig erscheinen.

`EInvoiceValidator` führt auf ausdrücklichen POST einen echten KoSIT-Prozess aus. Die lokal erprobte Kombination ist KoSIT 1.6.3, XRechnung-Konfiguration 3.0.2 vom Release 2026-08-31 und Temurin JRE17. Die offizielle Konfiguration enthält XSD und Schematron; das ausgeführte Szenario wird am Ergebnis angezeigt. Ein EN16931-Szenario ist nicht automatisch ein bestandener XRechnung-CIUS-Test.

## Betriebsvertrag

`VEREIN_KOSIT_RUNTIME` zeigt auf eine ausschließlich vom Betreiber beschreibbare JSON-Datei außerhalb des Webroots. Keine Oberfläche zur Änderung von Befehlen, Pfaden oder Prüfregeln. Inhalt mit absoluten Pfaden:

```json
{
  "java": "/private/jre/bin/java",
  "jar": "/private/kosit/validator.jar",
  "repository": "/private/kosit/config",
  "scenarios": "/private/kosit/config/scenarios.xml",
  "workdir": "/private/kosit/work",
  "validator_sha256": "799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9",
  "configuration_sha256": "bee80026032a6ba589bd6de9799db5f7071dc8a59a48779dfce8985fe5dbe0b6"
}
```

Konfigurationshash mit `EInvoiceValidator::configurationHash(realpath($repository))` berechnen und nach vertrauenswürdiger Installation festlegen. Er umfasst alle relativen Dateinamen und SHA-256-Prüfsummen, sortiert und plattformunabhängig mit Schrägstrichen. Änderungen führen zur Ablehnung, nicht zu stillschweigend neuen Regeln. Keine Symlinks im Regelverzeichnis.

Offizielle Artefakte:

- [KoSIT JAR 1.6.3](https://github.com/itplr-kosit/validator/releases/download/v1.6.3/validator-1.6.3-standalone.jar), SHA-256 oben.
- [XRechnung-Konfiguration](https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v2026-08-31/xrechnung-3.0.2-validator-configuration-2026-08-31.zip), ZIP-SHA-256 `2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8`.
- [Temurin17 Linux x64 JRE](https://github.com/adoptium/temurin17-binaries/releases/download/jdk-17.0.20.1%2B1/OpenJDK17U-jre_x64_linux_hotspot_17.0.20.1_1.tar.gz), SHA-256 `0b2b640e3046b64c8ec504de0ab9d91bb5610182bda21fad454681ce54d45a62` laut [Hersteller-Prüfsumme](https://github.com/adoptium/temurin17-binaries/releases/download/jdk-17.0.20.1%2B1/OpenJDK17U-jre_x64_linux_hotspot_17.0.20.1_1.tar.gz.sha256.txt).

Hostingnachweis durch Root: Java nicht im PATH; proc_open vorhanden, PHP8.5.9 mit128 MB. Das ist noch kein Nachweis verfügbarer JVM-Ressourcen. Die portable Linux-JRE muss separat im privaten Verzeichnis installiert und mit synthetischen Rechnungen tatsächlich geprüft werden. Der lokale Windows-Lauf ist kein Live-Lauf.

## Ausführung und Schutz

Ein dokumentleseberechtigter Finanzschreiber startet die Prüfung. Dokumentversion und SHA müssen zum aktuellen Original passen. Es gibt keine automatische Zahlung oder Freigabe und keine Übermittlung an externe Validator-Dienste.

Ein nichtblockierendes flock im gemeinsamen privaten Arbeitsverzeichnis erlaubt genau einen Prozess gleichzeitig. Argumentarray umgeht die Shell. Heaplimit256 MB, Timeout90 Sekunden, privates zufälliges Arbeitsverzeichnis und Bereinigung im finally. Die PHP-Ausführungszeit wird für diesen ausdrücklichen Lauf auf120 Sekunden gesetzt. Eingabe maximal10 MB, XMLReader höchstens200000 Knoten/Tiefe 64; DTD und Entitäten abgelehnt. JVM-Resolver erlauben keine externe DTD, Schema-/Stylesheet-Ressourcen nur aus Dateien. Ausschließlich vertrauenswürdige lokale KoSIT-Szenarien; keine öffentliche Daemon-Schnittstelle.

KoSIT-Exitcode und `rep:assessment` müssen übereinstimmen; der SHA-256 im unveränderten XML-Bericht muss zum Original passen. Prozess-/Konfigurationsfehler erzeugen keinen gültigen Prüfstatus. Migration 46 speichert Ergebnisse unveränderbar mit Dateiversion, Originalhash, Akteur, Zeitpunkt, Regelwerk, JAR-/Konfigurationshash und Originalbericht. Neue Dateiversionen zeigen alte Ergebnisse ausdrücklich als veraltet. Das Ergebnis gilt nur für den dokumentierten Regelstand, nicht pauschal für zukünftige Standards.

Das Belegpaket enthält die Prüfberichte und deren damalige Originalversionen, jeweils mit SHA und Zuordnung. Bestehende Dokumentberechtigungen bleiben Voraussetzung. Es wird keine GoBD-Zertifizierung behauptet.

## Reproduzierbare Prüfungen


Weitere Fälle: Berechtigungen und fremder Verein, stale POST, Wechsel der Dateiversion, Hash-/Exit-Widerspruch, Sperre paralleler Prozesse, unveränderliche Historie, alte Originale und Berichte im Paket. `tests/einvoice-browser.cjs` prüft den ausdrücklichen Knopf, Status nach Reload und geöffneten Bericht bei1280/390 px auf einer eigenen synthetischen Sitzung.

## Primäre Dokumentation

[KoSIT Validator](https://github.com/itplr-kosit/validator), [CLI und Exitcodes](https://github.com/itplr-kosit/validator/blob/main/docs/cli.md), [XRechnung-Konfiguration](https://github.com/itplr-kosit/validator-configuration-xrechnung). Stand 07.09.2026. Der Validator allein enthält keine fachlichen Regeln; diese kommen aus der separat versionierten Konfiguration.

Prüfbelege: 46 kombinierte PHP-Prüfungen, Exit0 (13 bestehende XML-Archivprüfungen, 13 Formaterkennung/Rechte, 20 Validator-/Prozess-/Exportfälle). Beide echten Browserläufe bestanden: gültiges Original sowie neue fehlerhafte Dateiversion, jeweils POST, Reload, aufgeklappte Prüfhinweise, Desktop1280 und Mobil390px ohne Überlauf/Browserfehler. Zusätzliche Ressourcenbegrenzung: stdout, stderr und Berichte zusammen16MB, während jedes Pollingdurchlaufs geprüft; Größen- und Zeitüberschreitung mit eigenen harmlosen Prozessen nachgewiesen. Kein solcher Abbruch wird als Rechnungsfehler oder gültige Prüfung gespeichert.
