# Lokale Rechnungserkennung

Stand: 7. September 2026. Die Erkennung liest bestehende Rechnungsoriginale aus dem Rechnungseingang sowie autorisierte Rechnungsuploads im Inventar. Mailanhänge werden nicht erneut hochgeladen. XML und eingebettete strukturierte E-Rechnungen behalten ihren eigenen Prüfweg.

## Datenfluss

`InvoiceRecognition` prüft Finanzrechte, Dokumentrechte, Mandant und aktuelle Dateiversion. Im Inventar kommen Inventarrechte und die Verknüpfung zum Gegenstand beziehungsweise Anschaffungsbeleg hinzu. `LiveUploads::snapshot` liest einen noch nicht gespeicherten Upload ausschließlich aus der aktuellen Sitzung mit Benutzer, Uploadkennung, Formularschlüssel, Aktion, Feld, Ablaufzeit und SHA-256. Dieser Zugriff verbraucht den Upload nicht.

Der PHP-Prozess übergibt ausschließlich eine private Kopie und eine feste Laufzeitkonfiguration an `tools/recognize-invoice.py`. PDFium liest PDF-Text; Seiten ohne ausreichenden Text werden gerastert und mit Tesseract erkannt. Pillow decodiert ausschließlich das zum geprüften MIME passende JPEG- oder PNG-Format. Das Ergebnis enthält ungeprüfte Vorschläge. Weder Erkennung noch Vorschau ändern Original, Kontaktdaten, Inventarbestand oder Buchungen.

Gespeicherte Erkennungsergebnisse sind an Dokumentversion, SHA-256 und Parser-Version gebunden. Eine erneute Erkennung identischer Originale kann das Ergebnis wiederverwenden. Nach Änderungen an Positionsdaten oder Gegenstandszuordnungen muss die Prüfung erneut bestätigt werden. Firmenbankdaten werden nur bei bewusster Anlage der neuen Firma übernommen, vorhandene Daten werden nicht ersetzt.

## Laufzeit auf dem Webhosting

Die zusätzliche private Laufzeit liegt unter `/tmp/kulturhus-verein/ocr-5.5.3`. Sie verwendet die vorhandene Python-3.11-Laufzeit, ergänzt aber eigene Module vor deren Suchpfad. Bestehende PDF-Erstellung und ihre Bibliotheken werden nicht überschrieben.

| Bestandteil | Version |
| --- | --- |
| Tesseract | 5.5.3, selbst aus dem offiziellen Release gebaut |
| pypdfium2 | 5.13.0, Linux x86_64 manylinux-Wheel |
| Pillow | 12.3.0, CPython 3.11 manylinux 2.27/2.28-Wheel |
| Sprachen | Deutsch und Englisch |

Tesseract wurde mit `BUILD_TRAINING_TOOLS=OFF`, `BUILD_TESTS=OFF`, `DISABLE_CURL=ON`, `DISABLE_ARCHIVE=ON`, `OPENMP_BUILD=OFF` und `GRAPHICS_DISABLED=ON` gebaut. Der eigene ELF-Interpreter und ein absoluter RPATH verweisen auf das mitgelieferte Bibliotheksverzeichnis; das Hosting stellt kein verlässliches `/proc/self/exe` für `$ORIGIN` bereit. Ein separates `python-compat` ergänzt `libpthread.so.0`; die bestehende Python-Laufzeit bleibt erhalten. Das Paket enthält Herkunftsnachweise, Lizenzdateien und ein SHA-256-Manifest für jede Datei.

Quellen: [Tesseract 5.5.3](https://github.com/tesseract-ocr/tesseract/releases/tag/5.5.3), [pypdfium2](https://pypi.org/project/pypdfium2/5.13.0/), [Pillow 12.3.0](https://pypi.org/project/pillow/12.3.0/).

Die private JSON-Konfiguration wird über `VEREIN_OCR_RUNTIME` oder relativ zur Anwendung unter `.local/vereinsverwaltung/ocr-runtime.json` gefunden. Beispiel:

```json
{
  "python": "/tmp/kulturhus-verein/ocr-5.5.3/run-python",
  "tesseract": "/tmp/kulturhus-verein/ocr-5.5.3/bin/tesseract",
  "tessdata": "/tmp/kulturhus-verein/ocr-5.5.3/tessdata",
  "language": "deu+eng",
  "require_sandbox": true,
  "sandbox_read_roots": [
    "/tmp/kulturhus-verein/python",
    "/tmp/kulturhus-verein/ocr-5.5.3"
  ]
}
```

Konfiguration und übergeordnetes Verzeichnis bleiben privat. Neben der JSON-Datei liegt die private Prozesssperre. Nur ein OCR-Worker läuft gleichzeitig; weitere Anfragen erhalten sofort eine verständliche Meldung. Die Sperre wird auch bei Fehlern freigegeben.

## Begrenzung und Isolation

- Höchstens 10 MB, sechs PDF-Seiten oder 20 Millionen Bildpixel; Raster höchstens 2200 Pixel an der längsten Seite; höchstens 180.000 Textzeichen.
- Feste Argumentliste ohne Shell-Auswertung; keine Verwendung hochgeladener Dateinamen als Befehle. Bereinigte Prozessumgebung ohne Mail-, Bank- oder Anwendungsschlüssel.
- Privater Arbeitsordner mit Modus 0700. Ressourcen- und Zeitlimits im Worker, höchstens 22 Sekunden pro Tesseract-Aufruf und 95 Sekunden im aufrufenden PHP-Prozess.
- Linux x86_64, Landlock ABI mindestens 6 und seccomp sind zwingend. Vor nativer Dokumentverarbeitung werden Netzwerk, Zugriffe auf fremde Dateien, externe Schreibzugriffe und Metadatenänderungen gesperrt. Lesbar sind nur die ausdrücklich erlaubten Laufzeitverzeichnisse und die Workerprogramme; beschreibbar ist nur der eigene Arbeitsordner.
- Kindprozesse erben die Einschränkungen. Die Landlock-Signalschranke erlaubt den Abbruch eigener Kindprozesse bei Zeitüberschreitung und verhindert Signale an fremde Prozesse.
- `require_sandbox: false` ist ausschließlich für ausdrücklich konfigurierte lokale Windows-Tests zulässig. Linux bricht ohne Sandbox ab.

`tools/probe_invoice_ocr_sandbox.py` prüft die Isolation mit synthetischen Dateien. Der verbotene Lesetest öffnet lediglich die Datei und liest keine Bytes. Bei Laufzeit- oder Hostingwechsel müssen Versionsprüfung, Sandboxprobe, echter Text-PDF-/Scan-PDF-/JPG-/PNG-Test sowie Timeout- und Sperrtest erneut erfolgen. Eine erfolgreiche Installation allein ist kein Funktionsnachweis.

## Grenzen

OCR erkennt nicht jede Gestaltung zuverlässig. Firmenkopf und Rechnungsempfänger, mehrere IBAN, unterschiedliche Steuersätze, schlecht lesbare Scans und unklare Tabellen benötigen Prüfung am Original. Die Oberfläche bietet manuelle Korrektur. Unklare Positionen erzeugen keine automatischen Buchungen oder erfundenen Ausgleichspositionen.
