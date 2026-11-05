# Private Linux-Laufzeiten

Dieses Paket enthält die vorhandenen, getrennten Laufzeiten für PDF-Erstellung, Rechnungserkennung und E-Rechnungsprüfung. Für die Installation werden weder pip, apt, ein externer OCR-Dienst noch ein eigener Java-Download benötigt. Die Archive enthalten keine Zugangsdaten, Vereinsdaten, Sitzungen oder Konfigurationen des bisherigen Servers.

## Vorbereitung für neue und bestehende DVM-Installationen

Der gemeinsame CLI-Einstieg ist `distribution/prepare-runtimes.php` mit den
Gruppen `ocr`, `kosit` und `eric`. Er bereitet Programme und Lizenzquellen privat
vor und aktiviert keine Dienste. Anschließende Konfiguration und Prüfungen:
[Zusatzlaufzeiten](../../../docs/ZUSATZLAUFZEITEN.md).

ERiC wird nur zusammen mit DVM unter dem Softwareherstellervertrag verteilt.
Das vollständige Entwickler-SDK, Header, Beispiele sowie Entwickler- und
Schemadokumentationsarchive gehören nicht zum Paket. Lizenz und vorgeschriebene Informationen bleiben erhalten.
[ERiC-Bedingungen](../../../docs/ERIC-LIZENZ.md).

## Installation und Verzeichnisvertrag

Der übergeordnete Installer liest `manifest.json`, prüft zuerst jede Teilstück-Prüfsumme und anschließend die Prüfsumme des zusammengefügten Artefakts. Die Reihenfolge von `parts` ist verbindlich. Eine Datei ist höchstens 44 MiB groß. `install.target` ist relativ zum gewählten privaten Laufzeitverzeichnis. Die Archive enthalten nur normale Dateien und Verzeichnisse; interne Links der Java-Lizenzen wurden zu normalen Dateien aufgelöst. Ausführungsrechte der Binärdateien bleiben erforderlich.

Der technische Konfigurator für einen bereits vollständig vorbereiteten Bestand
einschließlich Hybrid-/Mustang-Dateien lautet:

```php
$environment = RuntimeInstaller::configure($runtimeRoot, $releaseRoot, $privateDataDir);
```

Der Gesamtbestand des Quellpakets umfasst folgende Verzeichnisse; der neue
Vorbereitungsbefehl wählt daraus nur die angeforderten Gruppen:

| Unterverzeichnis | Inhalt |
| --- | --- |
| `python/` | Python 3.11.2, ReportLab 4.4.9, pypdf 6.10.0, Pillow 9.4.0, charset-normalizer 3.5.1, Vera-Schriften und benötigte native Bibliotheken |
| `ocr-5.5.3/` | Tesseract 5.5.3, PDFium 5.13.0, Pillow 12.3.0, HEIC-Decoder und Deutsch-/Englisch-Modelle aus `tessdata_best` |
| `kosit-1.6.3-xrechnung-20260831/` | Temurin Java 17.0.20.1+1, KoSIT 1.6.3 und XRechnung-Konfiguration 3.0.2 vom 31.08.2026 |
| `hybrid-2.26.0/` | Mustang 2.26.0 einschließlich veraPDF sowie die getrennten Factur-X-Pythonpakete |

Dieser vollständige Konfigurator ist kein allgemeiner Nachinstallationsbefehl.
Der aktuelle OCR-Assistent verwendet `configureOcr()`; KoSIT wird getrennt nach
der zentralen Anleitung verbunden. Hybrid-/Mustang-Einrichtung bleibt separat.

Die vom vollständigen Konfigurator zurückgegebenen Variablen sind `VEREIN_PYTHON`, `VEREIN_OCR_RUNTIME`, `VEREIN_KOSIT_RUNTIME` und `VEREIN_HYBRID_RUNTIME`. Der Installer speichert sie in seiner privaten Konfiguration. Die drei JSON-Konfigurationen liegen unter `$privateDataDir/runtime-config/`. Wrapper, Pfade und Hashkonfigurationen werden für den gewählten Zielpfad neu erstellt; historische Hostingpfade werden nicht übernommen. Abweichende vorhandene Konfigurationen werden nicht überschrieben.

Die PDF-Laufzeit verwendet ReportLab 4.4.9 entsprechend `vereinsverwaltung/requirements-pdf.txt`. Der vorherige Stand 3.6.12 erzeugte bei bestimmten Vera-Bold-Zeichen widersprüchliche Glyphenbreiten und bestand deshalb die echte PDF/A-Prüfung nicht. Die aktualisierte, weiterhin eigenständige Python-3.11-Laufzeit wurde mit neu erzeugten Originalrechnungen gegen Mustang und veraPDF geprüft; die Validatorprüfung wurde nicht gelockert. `provenance/pdf-reportlab-upgrade.json` enthält den nachvollziehbaren Versions- und Hashnachweis.

## Voraussetzungen und Abschlussprüfung

- Linux x86_64, PHP ab 8.3 mit `proc_open` und den vom Hauptinstaller geprüften Erweiterungen.
- Für OCR: Landlock ABI mindestens 6 sowie seccomp. Das sind Kernelvoraussetzungen; der Installer kann sie nicht aus einem Archiv nachinstallieren. Die Sandbox bleibt verpflichtend.
- Beschreibbare private Arbeitsverzeichnisse, genügend Speicherplatz für entpackte Pakete und zeitweilige Dokumente; keine Ablage im öffentlich ausgelieferten Webverzeichnis.
- Der Webprozess muss die privaten Interpreter starten dürfen. CLI- und Webkontext können unterschiedliche Pfadbeschränkungen haben.

`RuntimeInstaller::preflight()` prüft während der Einrichtung den tatsächlichen PDF-Interpreter, Modulversionen, Landlock-Version und Java-Start. Die native Paketabnahme verwendet die Installer-/Recovery-Suiten mit `DVM_INSTALLER_OCR_PROOF=1` und synthetischen PDF-/OCR-/XML-Daten; siehe [aktuelle Abnahme](../../ABNAHME.md). Die Anwendung prüft jede neue ZUGFeRD-Datei außerdem mit Mustang und veraPDF, bevor sie als gültiger Download gespeichert wird.

Die aktuelle installierte Rechnungs-/PDF-Laufzeit wird mit den nativen Installer-,
Integrations- und Wiederherstellungssuiten sowie eigenen synthetischen Originalen
geprüft. Maßgeblich sind die tatsächlichen Laufzeitnachweise des jeweiligen
Paketstands. Die [Paketabnahme](../../ABNAHME.md) beschreibt die aktuellen Aufrufe.


Tesseracts im ursprünglichen Build enthaltener ELF-Interpreterpfad wird beim Aufruf nicht verwendet: Die aktive Konfiguration setzt den mitgelieferten Loader und seinen Bibliothekspfad ausdrücklich. PDF-, OCR- und Hybrid-Pythonpakete müssen getrennt bleiben. Ihre unterschiedlich geprüften Versionen dürfen nicht zu einem gemeinsamen `site-packages`-Verzeichnis zusammenkopiert werden.

## Lizenzen und Quellpakete

`LICENSES/` bewahrt die Lizenz-/Notice-Dateien der Pythonpakete, die vollständigen Debian-Lizenztexte für die enthaltenen Bibliotheksfamilien sowie die ergänzenden GNU-Lizenztexte. Die nativen Laufzeitarchive enthalten zusätzlich ihre eigenen Lizenzdateien; Java enthält seinen vollständigen `legal/`-Baum. `provenance/` hält ursprüngliche Artefaktnachweise und die Dateihashes der neu verpackten Laufzeiten fest. Debian-Paketmetadaten dienen als Herkunftsnachweis der Copyright-Texte und ersetzen keine Versionsbehauptung über die eingefrorenen Binärdateien.

Die **Linux-Binärwheel von pillow-heif 1.7.0 ist laut ihrer eigenen `LICENSES_bundled.txt` GPLv2-lizenziert**, weil sie x265 enthält. Der Python-Quellcode allein steht unter BSD-3-Clause. Mitgeliefert werden deshalb vollständige, lokal verfügbare Quellarchive:

- pillow-heif 1.7.0 einschließlich `libheif/build_libs.py`, Setup- und CI-Bauskripten;
- libheif 1.23.3, libde265 1.1.2 und x265 4.2 aus genau den Release-URLs, die dieses Bauskript verwendet;
- ReportLab 4.4.9, charset-normalizer 3.5.1, pypdf 6.10.0, Pillow 9.4.0, Tesseract 5.5.3 und die XRechnung-Konfiguration.

Die HEIC-Bauskripte dokumentieren Compileroptionen und die Zusammenstellung der Codec-Bibliotheken. Die Quellarchive werden bei einer normalen Installation nicht kompiliert oder ausgeführt. Ihre URLs dienen der Herkunftsdokumentation; alle angegebenen Quellarchive befinden sich bereits in `sources/`. `source_ids` verknüpft Binärkomponenten mit diesen lokalen Archiven. Die einzelnen Bestandteile behalten ihre jeweiligen Lizenzbedingungen; dieses Paket vergibt keine einheitliche neue Lizenz an fremde Bibliotheken.

Windows-Installer, Paddle-/ppu-Benchmarkumgebungen, private Testrechnungen und alte Serverkonfigurationen sind nicht enthalten.
