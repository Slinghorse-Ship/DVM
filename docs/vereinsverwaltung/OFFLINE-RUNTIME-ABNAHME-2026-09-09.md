> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Offline-Laufzeiten: Abnahme am 09.09.2026

## Umfang und Trennung

Diese Prüfung verwendet die frisch offline installierte Anwendung `install-a` in WSL Ubuntu 24.04, PHP 8.3, Linux x86_64 und Landlock ABI 7. Es wurden ausschließlich synthetische Dokumente und flüchtige Dateidatenbank-Testdatenbanken verwendet. Kein Versand, Bankaufruf, Import echter Vereinsdaten oder Eingriff auf dem Live-Webspace fand statt.

Die erste vollständige Offline-Installation verwendete zunächst das bisherige PDF-Paket mit ReportLab 3.6.12. Nach dem unten beschriebenen Fehler wurde **nur in dieser synthetischen Installation** ReportLab durch die später paketierte Version 4.4.9 samt charset-normalizer 3.5.1 ersetzt. Alle nachfolgend als bestanden aufgeführten Hybrid-Prüfungen beziehen sich auf diese aktualisierte Laufzeit in `install-a`. Die zusätzliche, vollständig neue Installation `install-b` und deren unabhängige Endprüfungen führt der Releaseverantwortliche durch; sie werden hier nicht vorweggenommen.

## Tatsächliche Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| Original-PDF mit Text | Erkennung bereit; zwei Artikelpositionen und korrekter Gesamtbetrag; 1,671 s |
| Gescannte PDF | Tesseract-OCR bereit; zwei Artikelpositionen und korrekter Gesamtbetrag; 11,476 s |
| JPG | Tesseract-OCR bereit; zwei Artikelpositionen und korrekter Gesamtbetrag; 7,932 s |
| PNG | Tesseract-OCR bereit; zwei Artikelpositionen und korrekter Gesamtbetrag; 3,919 s |
| iPhone-HEIC | Tesseract-OCR bereit; zwei Artikelpositionen und korrekter Gesamtbetrag; 3,818 s |
| HEIC-Vorschau | JPEG-Vorschau erzeugt, 67.973 Bytes |
| Originalschutz der Erkennung | Alle fünf Quelldateien unverändert |
| Echte OCR-Sandbox | Landlock ABI 7 und seccomp aktiv; Lesen/Schreiben außerhalb des erlaubten Bereichs, Socket-Erstellung und chmod abgewiesen; Schreiben im eigenen Arbeitsverzeichnis erlaubt |
| UBL/KoSIT | 20 Tests bestanden; echte KoSIT-Prüfung einschließlich gemischter 7/19-Prozent-Rechnung, Zahlungsreferenz und Idempotenz; Prozess beendet mit Status 0 |
| ZUGFeRD/PDF-A | 39 Tests bestanden; echte Mustang-/veraPDF-Prüfung, 7/19 Prozent, Bruchmengen, explizite Steuerbefreiung, Inlandsnachweis, Rechte, unverändertes Original und Wiederverwendung |
| Separater ausgelieferter Hybrid-Smoke | PDF/A-3b und EN16931 gültig, 2.471 VeraPDF-Assertions; Sonderzeichen, Original-/XML-Hash, Idempotenz und echte Ablehnung eines falschen Steuerbetrags bestanden; 0 Versanddatensätze; 74,301 s |
| Allgemeiner PDF-Renderer | 17 Tests bestanden: A4, eingebettetes Bild, Sonderzeichen, Mehrseitigkeit, Kopf-/Fußzeilen und Überlaufbegrenzung |
| PDF-Vorgangsnachweise | 7 Tests bestanden: lange Tabellen/Vermerke, erhaltene letzte Zeilen, wiederholte Überschriften, sichere Textausgabe, keine abschließende Leerseite |
| Bildsichtprüfung | Das tatsächlich validierte synthetische ZUGFeRD-PDF mit Poppler gerendert und geprüft: Rechnungstabelle, Beträge, Überweisungszweck, Umlaute/Eurozeichen und Fußzeile lesbar, ohne Überlagerung |

Laufzeiten sind Einzelmessungen auf einem gleichzeitig für weitere Installationstests genutzten Rechner, keine garantierten Hosting-Laufzeiten. Der gemeldete PHP-Heap-Höchststand der Erkennungsprüfung betrug 10 MiB; das ist **nicht** der Gesamtverbrauch der getrennten nativen OCR-Prozesse.

## Behobener Bibliotheksfehler

ReportLab 3.6.12 schrieb für ein verwendetes Zeichen in der eingebetteten Schrift `BitstreamVeraSans-Bold` die Glyphenbreite `695.8008` ins PDF-Dictionary, während das Schriftprogramm `837.890625` enthielt. VeraPDF beanstandete deshalb ISO 19005-3:2012, Abschnitt 6.2.11.5, Test 1. Das CII-XML war gültig; Mustang meldete auf oberster Ebene trotzdem `valid`. Der bereits vorhandene strengere Anwendungsgate prüfte zusätzlich das tatsächliche PDF-A-Ergebnis und verhinderte richtigerweise die Speicherung als gültige Hybridrechnung.

Das PDF-Paket enthält jetzt ReportLab **4.4.9** und die reine Python-Abhängigkeit **charset-normalizer 3.5.1**. Python 3.11.2, pypdf 6.10.0, Pillow 9.4.0 sowie die separat isolierten OCR-/Hybridpakete bleiben auf ihren festgeschriebenen Versionen. Die fehlerhafte ältere ReportLab-Implementierung und ihr nativer Beschleuniger sind im neuen PDF-Paket ersetzt. Der Validator wurde nicht gelockert; die Inhaltsströme der ausgegebenen Originalrechnung bleiben beim Anfügen des XML unverändert.

Wheels und vollständige Quellpakete stammen von PyPI und wurden gegen die SHA256-Werte der jeweiligen veröffentlichten Paketmetadaten geprüft. Lizenztexte und Quellpakete liegen im Offline-Paket. `RuntimeInstaller::preflight()` erwartet ausdrücklich die neuen Versionen.

## Auslieferungsnachweis

- PDF-Artefakt: `distribution/packages/linux-runtime/blobs/pdf-runtime-linux-amd64.tar.gz`
- Größe: **20.274.621 Bytes**
- SHA256: `6c403e45f1a72ce1106d45c658b8f31c33ccfd148229ea4f69fed92586d44dd5`
- Runtimepaket: 20 Komponenten, 10 Quellarchive; jedes Teilstück kleiner als 45 MiB.
- Alle Komponenten-, Teilstück- und Quellhashes sowie die Zuordnung der Quellarchive wurden geprüft. Laufzeitarchive enthalten reguläre Dateien/Verzeichnisse, keine symbolischen Links, Hardlinks, Pfadtraversierungen, privaten Konfigurationen oder Python-Bytecodecaches.
- Nachvollziehbarer Upgrade-Nachweis: `distribution/packages/linux-runtime/provenance/pdf-reportlab-upgrade.json`.

Wiederholbare Befehle nach einer vollständigen frischen Installation:

```sh
DVM_INSTALLER_OCR_PROOF=1 KH_NATIVE_CI=1 KH_NATIVE_CI_PASSWORD=synthetic-ci-only php vereinsverwaltung/tests/ci-native.php native-installer
php distribution/tests/hybrid-runtime-smoke.php /ABS/INSTALLATION
```

Der zweite Test verwendet ausschließlich Dateidatenbank `:memory:` und die installierten privaten Interpreter. Er erfordert weder Konto-Zugang noch E-Mail-Empfänger oder API-Registrierung. Die privaten Arbeitsdateien der Renderer/Validatoren werden vom regulären Code aufgeräumt.

Die lokale Paketabnahme ersetzt nicht die Prüfung von PHP-Prozessrechten, Kernel-Sandbox und privaten Pfaden auf einem anderen Zielhost. Die vorherige Live-PDF-Laufzeit wurde in diesem Auftrag nicht verändert; ihre gesonderte Aktualisierung und Prüfung liegt beim Releaseverantwortlichen.
