# ERiC-Produktlaufzeit im DVM-Paket

DVM enthält die unveränderten amtlichen Linux-x86_64-Programmbibliotheken und
Plugins aus ERiC 44.3.6.0, zusammen mit der Lizenz. Die integrierte Weitergabe
richtet sich nach den [ERiC-Vertragsbedingungen](ERIC-LIZENZ.md).
Der vollständige Entwickler-SDK, Header, Beispiele, Entwicklerhandbücher und
Schemadokumentationsarchive werden nicht mitgegeben. Pflichtinformationen bleiben
erhalten; die DVM-Lizenz ersetzt die Bedingungen von ERiC nicht.

## Herkunft und Integrität

Die Bibliotheken wurden aus dem signaturgeprüften Original
`ERiC-44.3.6.0-Linux-x86_64.jar` entnommen. Dessen SHA256 lautet
`e36df8b02045ff3449eb23e086a39f5455b709c967e708eae44f27099ce4de95`.
Die ursprünglichen Bibliotheksbytes werden nicht geändert. Der
[Herkunftsnachweis](../distribution/packages/linux-runtime/provenance/eric-runtime-44.3.6.0.json)
nennt jede Datei mit Länge und SHA256.

| DVM-Produktlaufzeit | Bytes | SHA256 |
|---|---:|---|
| ERiC-44.3.6.0-DVM-runtime-linux-amd64.tar.gz | 515721865 | `8920de055ff2a5ce50db09209d5a7c672fd904dac1f4f2dd4d404bf6d43b6fb0` |

Die Laufzeit wird in 16 Teilen von höchstens 32 MiB gespeichert.
Das [Paketmanifest](../distribution/packages/linux-runtime/manifest.json)
enthält die Prüfsumme jedes Teils und des Gesamtarchivs. Diese Hashes dokumentieren
die tatsächlichen Paketbytes und werden nicht als vom ELSTER-Server
veröffentlichte Prüfsummen ausgegeben. Die unveränderte
[Lizenz einschließlich der Pflichtinformationen](../distribution/packages/linux-runtime/LICENSES/ERiC-44.3.6.0/lizenz.pdf)
ist enthalten.

## Für eine Installation vorbereiten

Im vollständigen Quellpaket:

```sh
php distribution/prepare-runtimes.php --target=/PRIVAT/NEUE-DVM-ZUSATZLAUFZEITEN --components=eric
```

Das neue private Ziel erhält `runtime/eric-44.3.6.0/lib/` mit 225 Bibliotheken und
Plugins sowie die Lizenzinformationen. Es enthält keine Entwicklerhandbücher,
SDK-Beispielverzeichnisse, Header oder Zertifikate.
[Einrichtung und weitere Komponenten](ZUSATZLAUFZEITEN.md).

Das ältere Werkzeug `materialize-eric-sdk.py` bleibt als kompatibler Dateiname
vorhanden, rekonstruiert jetzt aber ausschließlich das oben genannte
**DVM-Laufzeitarchiv**:

```sh
python3 vereinsverwaltung/tools/materialize-eric-sdk.py --output /PRIVAT/eric-runtime-archive
```

Es stellt keinen SDK wieder her. Jeder Teil und das Gesamtarchiv werden geprüft;
vorhandene passende Dateien bleiben erhalten, abweichende werden nicht
überschrieben. Benötigte Entwicklerschemata oder Handbücher beschafft der
Entwickler selbst über ELSTER und bewahrt sie privat auf.

## Betrieb und Grenzen

Python 3.11 oder neuer und passende Systembibliotheken sind für den vorhandenen
Adapter erforderlich. Konfiguration, Hersteller-ID, Formularkatalog und
Zertifikate bleiben privat. Der Laufzeitcheck startet nur die Bibliothek:

```sh
VEREIN_ERIC_RUNTIME=/PRIVAT/eric.json php vereinsverwaltung/tools/check-eric-runtime.php
```

Das Programmpaket heißt 44.3.6.0; seine Kernbibliothek meldet 44.3.4.0. Das ist der
amtliche Paketinhalt. Windows und macOS benötigen eigene passende Originale.
Die aktuelle Mindestversion und die vertraglichen Pflichtinformationen sind vor
Nutzung zu prüfen. Ein erfolgreicher Bibliotheksstart ist keine Freigabe eines
Steuerformulars oder einer produktiven Übermittlung.
