# OCR-Enginevergleich: tatsächlich ausgeführte CPU-Prüfungen

Stand 07.09.2026. Entscheidung auf Basis dieser Stichprobe: Tesseract auf dem vorhandenen Host beibehalten, für Bildrechnungen die dort zusätzlich geprüften best-Modelle verwenden. Vorhandenen PDF-Text weiterhin zuerst direkt lesen. PaddleOCR und ppu sind tatsächlich getestet worden; sie werden durch diese Untersuchung nicht produktiv aktiviert.

## Vergleichsbedingungen

Windows x86_64, keine GPU. Acht identische Eingaberaster: fünf synthetische Varianten aus Text-PDF, Scan-PDF, JPEG, PNG und HEIC; eine synthetische Tabelle mit ausdrücklich getrennten Netto-/Steuer-/Bruttowerten; zwei autorisierte private Originalrechnungen. Die PDF-Rasterung entspricht 300 dpi mit maximal 3508 Pixeln langer Kante. HEIC wurde lokal in Pixel umgewandelt, kein externer OCR-Dienst verwendet.

Die Inferenz-Zeitfenster der Agenten überlappten sich nicht. Nach Entdeckung einer Beeinflussung der ersten Tesseract-Reihe durch die laufende Paketinstallation wurde diese Reihe verworfen und vollständig ohne Installation wiederholt. Sie bleibt privat zur Nachvollziehbarkeit erhalten. Das Betriebssystem wurde nicht exklusiv reserviert; Werte sind lokale Vergleichsmessungen, keine allgemeingültigen Hardware-Benchmarks.

Tesseract: erster gemessener CLI-Job plus drei weitere Jobs je Raster; jeder Job startet wie im Produkt einen neuen Prozess. Deshalb kein vorgetäuschter persistenter „warmer Enginezustand“. Tabelle: Median der drei Folgejobs. PaddleOCR/PP-Structure: Modellinitialisierung und erste synthetische Inferenz separat, danach drei Läufe je Raster in derselben Engineinstanz. ppu: erster Lauf und drei Wiederholungen je Raster, Cache für Erkennungsergebnisse deaktiviert. Alle angeforderten CPU-Threadzahlen: eins; tatsächlich beobachtete Prozess-Threadzahlen wurden zusätzlich gemessen.

## Windows: Laufzeit je Raster

| Vorlage | Tesseract fast | ppu V6 Small | Paddle V5 Mobile | PP-Structure CPU |
|---|---:|---:|---:|---:|
| Original A | 1.61 s | 13.37 s | 13.89 s | 22.23 s |
| Original B | 2.31 s | 18.37 s | 22.27 s | 26.89 s |
| HEIC-Testbild | 0.66 s | 6.31 s | 5.81 s | 4.70 s |
| JPEG-Testbild | 0.76 s | 10.20 s | 5.70 s | 5.67 s |
| Text-PDF als Raster | 1.06 s | 8.57 s | 8.72 s | 8.92 s |
| PNG-Testbild | 0.87 s | 6.97 s | 5.65 s | 5.13 s |
| Scan-PDF als Raster | 1.75 s | 10.54 s | 9.90 s | 9.16 s |
| Explizite Nettotabelle | 0.97 s | 6.72 s | 10.88 s | 10.69 s |

| Engine | Peak Prozessbaum-RSS | Windows-VMS/Commit | beobachtete Threads | Modelldateien |
|---|---:|---:|---:|---:|
| Tesseract 5.5.3 fast | 145,1 MiB | 134,8 MiB | 4 | bestehender Sprachmodellbestand |
| ppu-paddle-ocr 6.4.3, V6_SMALL/ONNX | 3,42 GiB | siehe privates windows-memory.json | 24 | 31,35 MB |
| PaddleOCR 3.2.0 / PaddlePaddle 3.2.0, PP-OCRv5 mobile + Latin | 2332,1 MiB | 2984,1 MiB | 23 | 13,16 MB |
| PP-StructureV3, unten beschriebene CPU-Konfiguration | 2867,5 MiB | 3785,6 MiB | 46 | 306,83 MB gemeinsam genutzter Modellordner |

Windows `psutil.vms` bezeichnet Commit und ist nicht identisch mit dem Linux-Adressraumlimit RLIMIT_AS. RSS enthält bei den persistenten Engines den über mehrere Vorlagen angewachsenen Speicherbestand. Die kleine Modelldateigröße ist kein Maß für den tatsächlichen Arbeitsspeicherbedarf.

PaddleOCR: API-Modellinitialisierung aus vorhandenem Cache 5,40 s, erste synthetische Inferenz 9,66 s. PP-Structure: API-Initialisierung 15,04 s, erste synthetische Inferenz 20,96 s einschließlich noch erforderlicher kleiner lazy Modellinitialisierungen. Diese API-Zeiten schließen Python-Importzeit nicht ein. ppu-Initialisierung 2,16 s, erster gemessener HEIC-Rasterlauf 16,28 s. Modelldownloadzeiten gehören nicht zur warmen Inferenz.

Die anfängliche Paddle-Probe ohne MKL-DNN wurde bei über 170 CPU-Sekunden im synthetischen Warmup abgebrochen. Die oben gemessene funktionierende Variante verwendet MKL-DNN. Das war keine produktive Änderung.

## Erkennungsqualität und Rechnungsinterpretation getrennt

Alle Engines erhielten dieselben Pixel. Für Paddle wurde aus erkannten Boxen eine allgemeine Zeilenordnung über vertikale Mittelpunkte und horizontale Position hergestellt. Keine Sonderfälle für Lieferanten oder Rechnungsnummern. Anschließend wurde jeder Rohtext mit exakt demselben eingefrorenen `fields_from_text`-Snapshot ausgewertet; SHA liegt privat unter `parser-snapshot.json`.

- Alle acht Vorlagen: Tesseract fast, ppu und Paddle mit räumlicher Zeilenordnung sowie PP-Structure mit räumlicher Zeilenordnung liefern die korrekten Positionsanzahlen und Mengen/Einzelpreise. Original A hat zwei Positionen, Original B drei. Fünf Formatvarianten und die Nettotabelle haben jeweils zwei.
- Die reinen Paddle-Segmenttexte ohne Boxenauswertung liefern bei den beiden Originalen und der Nettotabelle null fachliche Positionen. Mit Boxenauswertung werden daraus korrekte Positionen. Ein Vergleich nur über die Zahl erkannter Zeichen hätte diesen entscheidenden Integrationsschritt übersehen.
- Netto und Brutto der beiden Originale werden korrekt erkannt. In Original A fehlt beim gemeinsamen Parser mit Tesseract-fast- und ppu-Text der Steuerbetrag; mit Paddle-Zeilenordnung wird er erkannt. Das ist ein konkreter Unterschied dieser Stichprobe, kein allgemeiner Genauigkeitssieg.
- PP-Structure liefert für Original A zusätzlich eine echte HTML-Tabelle mit Kopfzeile und zwei korrekten Datenzeilen. Bei Original B und der synthetischen Nettotabelle liefert das gewählte kleine Layoutmodell keine HTML-Tabelle; die Positionen stammen dort aus der räumlich geordneten OCR. Deshalb kein belegter pauschaler Tabellenvorteil dieser Konfiguration.
- Die im Original A vorhandene Differenz von einem Cent zwischen Positionssumme und gedrucktem Endbetrag bleibt erhalten. Kein Benchmark hat sie künstlich „korrigiert“.

Rohtext, räumlicher Text und fachliche Interpretation sind privat getrennt gespeichert. Bloße Zahlpräsenz und OCR-Konfidenz werden nicht als korrekte Buchung oder fertige Finanzzuordnung ausgegeben. Die Erkennung speichert weiterhin keine eigenständige Buchung.

## PP-Structure-Konfiguration und begrenzte lokale Anpassung

PaddleX 3.2.1: PP-DocLayout-S, dieselben mobilen OCR-Modelle, SLANet_plus für Tabellenstruktur sowie die beiden RT-DETR-L-Tabellenzellenmodelle. Formel-, Siegel-, Diagramm- und Regionserkennung ausgeschaltet.

Die Paketversion initialisierte PP-Chart2Table trotz ausgeschalteter Diagrammerkennung unbedingt. Dieser Download wurde abgebrochen. Ausschließlich in der isolierten Benchmark-venv wurde die Initialisierung an das bereits vorhandene `use_chart_recognition` gebunden. Keine Modelle oder Inferenzergebnisse wurden simuliert, keine Produktdatei geändert. Die Anpassung mit Vorher-/Nachher-SHA steht in `local-compatibility-patch.json`. Die Messung ist ausdrücklich diese leicht angepasste CPU-Konfiguration, kein unveränderter Standardbenchmark aller PP-Structure-Modelle.

## Getrennte tatsächliche Netcup-Prüfung

Die folgenden Werte stammen aus Roots nativer PHP-FPM-/Linux-Probe, nicht aus der Windows-Messung:

| Hostvariante | Original A | Original B | gemessener kumulativer Child-MaxRSS |
|---|---:|---:|---:|
| PDF-Textweg | ca. 0,15 s | siehe nativen Einzelbericht | ca. 69 MiB bei A |
| Tesseract fast, Bild | 2,35 s | 3,11 s | ca. 164,5 MiB |
| Tesseract best, Bild (zwei Läufe) | 4,26 / 3,96 s | 6,47 / 6,37 s | ca. 172,4 MiB |

Best erreicht im nativen Vergleich alle fünf festgelegten Zahlenprüfpunkte für A und alle neun für B; insbesondere wird auch der vorher fehlende Steuerbetrag A erkannt. Diese Prüfpunkte sind keine Vollständigkeitsgarantie jeder Rechnung. Die Firmenbezeichnung war in den Rohtexten vorhanden; eine fehlende Feldzuordnung ist eine gesonderte Parserfrage.

Der produktive Worker behält seine Linux-Sandbox und das 1-GB-Limit für virtuellen Speicher. Die Windows-Paddle-/ppu-Messungen liegen bereits beim RSS deutlich darüber und belegen keine passende Hostlaufzeit. Keine produktive Paddle-Installation und keine Lockerung dieses Limits. Die native best-Probe ist der entscheidende Nachweis für den gewählten Releaseweg; Windows- und Hostzeiten dürfen nicht direkt als Enginegeschwindigkeitsvergleich vermischt werden.

## Artefakte und Quellen

Private Dateien unter `.local/paddle-ocr-benchmark/`: `packages.lock.txt`, `model-manifest.json`, `*-results-private.json`, `*-parsed-private.json`, `*-metadata.json`, `layout_adapter.py`, `parser-snapshot.json`, `local-compatibility-patch.json`. ppu und native Hostproben unter `.local/ppu-paddle-benchmark/`, insbesondere `host-tesseract.json` und `host-tesseract-best.json`. Keine Originalrechnungen oder deren Inhalte in Git übernommen.

Offizielle Primärquellen: [Paddle CPU-Installation](https://www.paddleocr.ai/latest/en/version3.x/paddlepaddle_installation.html), [PP-StructureV3](https://github.com/PaddlePaddle/PaddleOCR/blob/main/docs/version3.x/pipeline_usage/PP-StructureV3.en.md), [PaddleOCR-Lizenz](https://github.com/PaddlePaddle/PaddleOCR/blob/main/LICENSE), [Tesseract Qualitätsverbesserung](https://github.com/tesseract-ocr/tessdoc/blob/main/ImproveQuality.md), [tessdata_best-Abwägung](https://tesseract-ocr.github.io/tessdoc/Data-Files-in-tessdata_best.html).
