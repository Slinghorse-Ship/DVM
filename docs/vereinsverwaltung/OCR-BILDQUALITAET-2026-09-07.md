# OCR-Bildqualität und produktive Laufzeit

Stand 07.09.2026. Aktiv ist Tesseract 5.5.3 mit den gepinnten `tessdata_best`-Modellen für Deutsch und Englisch. Vorhandener PDF-Text wird zuerst direkt gelesen; Fotos und Scan-PDFs verwenden OCR. Kein kostenpflichtiger OCR-Dienst und keine Übertragung von Rechnungen an externe OCR-Anbieter.

## Entscheidung und Vergleich

Der [ausgeführte Enginevergleich](OCR-ENGINE-VERGLEICH-2026-09-07.md) umfasst 112 lokale Messläufe mit Tesseract, PaddleOCR, PP-Structure und ppu-paddle-ocr. Paddle wurde tatsächlich ausprobiert, nicht nur recherchiert. Die getrennte native Netcup-Probe ist für die Hostingentscheidung maßgeblich: Die best-Modelle schließen bei einer der Originalrechnungen die zuvor fehlende Steuerbetrags-Erkennung. Zwei Bildrechnungen benötigen dort rund vier beziehungsweise sechs Sekunden, kumulativer Child-MaxRSS etwa 172,4 MiB. Das ist keine Garantie für beliebige Rechnungen.

Die offiziellen Modelle tauschen Laufzeit gegen etwas höhere Genauigkeit. Für unsere geprüften Vorlagen ist dieser Mehrbedarf auf dem Webspace vertretbar. Die umfangreicheren Paddle-/ppu-Laufzeiten lagen unter Windows bereits beim RSS oberhalb der bestehenden 1-GiB-Sandboxgrenze; sie werden nicht produktiv installiert. [Tesseract best-Modelle](https://tesseract-ocr.github.io/tessdoc/Data-Files-in-tessdata_best.html)

## Bildvorbereitung und Grenzen

- JPEG/PNG werden anhand der EXIF-Orientierung gedreht; transparente Flächen werden weiß, Metadaten gelangen nicht in das Arbeitsbild.
- PDF-Rasterung strebt 300 dpi an, höchstens 3508 Pixel längste Kante und 15 Millionen Ausgabepixel. Größere Papierformate erhalten innerhalb derselben Grenzen weniger dpi.
- Fotos werden mit Lanczos auf höchstens 3508 Pixel verkleinert. Fehlende Details kleiner oder unscharfer Fotos werden nicht erfunden.
- Eingangsgrenzen: 10 MiB, höchstens sechs PDF-Seiten beziehungsweise 20 Millionen Bildpixel. Arbeitsspeicher-, CPU-, Zeit- und Ausgabelimits bleiben erhalten.
- Tesseract und PDF-/HEIC-Decoder laufen im bestehenden isolierten Prozess. Keine Netzwerkfreigabe, keine Erweiterung der Sandbox-Lesewurzeln für die neuen Modelle.

300 dpi ist auch der Ausgangspunkt der [offiziellen Qualitätsdokumentation](https://github.com/tesseract-ocr/tessdoc/blob/main/ImproveQuality.md). Schiefe Zeilen, komplexe Tabellen und unlesbare Vorlagen bleiben mögliche Fehlerquellen. Mengen, Preise und Firmen werden als prüfbare Vorschläge übernommen, nicht als automatische Buchung.

## Private Installation und Rücknahme

Modelle aus `tesseract-ocr/tessdata_best`, Commit `e12c65a915945e4c28e237a9b52bc4a8f39a0cec`, inklusive Lizenz:

| Modell | Bytes | SHA256 |
|---|---:|---|
| Deutsch | 8.628.461 | `8407331d6aa0229dc927685c01a7938fc5a641d1a9524f74838cdac599f0d06e` |
| Englisch | 15.400.601 | `8280aed0782fe27257a68ea10fe7ef324ca0f8d85bd2fd145d1c2b560bcb66ba` |

Die privaten Modelldateien wurden vor und nach dem Upload geprüft. Der Runtime-Manifest enthält ihre Hashes; die private Konfiguration verweist relativ auf `ocr-5.5.3/tessdata-best-e12c65a91594`, damit SSH-Chroot und PHP-FPM dieselben Dateien auflösen. Ursprüngliche fast-Modelle bleiben für eine Rücknahme vorhanden. Exakte alte Konfiguration und Runtime-Manifest sind im privaten `rollback-tessdata-best-20260907-03` gesichert. Keine Systembibliotheken wurden ersetzt.

Erste Aktivierungsversuche wurden vor Abschluss der Prüfung automatisch zurückgenommen; erst der erfolgreiche CLI- und Webtest aktivierte die neue Konfiguration dauerhaft. Tatsächlicher Nachweis: `.local/tesseract-best-production.json`.

## Ausgeführte Prüfungen

- Elf Bildvorbereitungs- und fünf Dateiformatfälle: Orientierung, Transparenz, Grenzen, falsche Dateiformate.
- Bestehende und zusätzliche Parserfälle: Briefkopf, Lieferant statt Empfänger, E-Mail/Telefon, mehrere Bankverbindungen, gültige Prüfsummen, widersprüchliche Zuordnungen, vollständige Positionen und unveränderte Centdifferenzen.
- Nach Aktivierung über beide tatsächlichen Serverwege PHP-CLI und CGI/FastCGI: private Originalrechnung als PNG mit zwei Positionen, korrektem Gesamt- und Steuerbetrag; zweite PDF mit drei Positionen und korrektem Betrag; synthetisches HEIC mit zwei Positionen, korrektem Betrag und JPEG-Vorschau. Kein Original verändert und keine Buchung oder Mail erzeugt.
- Webzeiten dieses abschließenden einzelnen Funktionstests: PNG 4,11 s, Text-PDF 0,15 s, HEIC 1,50 s. Die vollständigen Vergleichsbedingungen stehen im separaten Enginebericht.

## HEIC

Native Erweiterung `pillow-heif 1.7.0` mit `libheif 1.23.3`, SHA-geprüfte private Bibliotheken. Nur einzelne Fotos; Sequenzen, Mehrfachbilder und übergroße Bilder werden abgewiesen. Das Original bleibt erhalten, die private JPEG-Vorschau ist höchstens 1600 Pixel groß und enthält keine Metadaten. Dokumentberechtigungen gelten auch für die Vorschau. Dies betrifft die geschützten Dokument-/Rechnungseingänge, nicht öffentliche Galerie-, Unterschrifts- oder Logouploads. Zusätzlicher früherer Nachweis: `.local/heic-native-web-check.json`.
