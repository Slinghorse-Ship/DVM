> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Geprüfte hybride Ausgangsrechnung – 09.09.2026

## Umsetzung und Grenzen

Aus einer im Vereinsmanager ausgegebenen Originalrechnung entsteht zusätzlich eine hybride PDF-Datei. Die vorhandenen PDF-Seiten bleiben erhalten; ihre unkomprimierten Seiteninhalte und ausgelesenen Texte werden vor/nach Einbettung verglichen. Rechnungsnummer, Datum, Empfänger, Mengen, Einheiten, Nettobeträge, Umsatzsteuer und Bruttobeträge stammen aus dem bei Ausgabe eingefrorenen Rechnungsstand. Der Überweisungszweck ist die ausgegebene Rechnungsnummer. Spätere Zahlungen ändern diese Rechnung nicht und erzeugen beim wiederholten Abruf keine zweite hybride Datei.

Unterstützt: im Programm ausgegebene deutsche EUR-Rechnungen, positive Positionen mit 0/7/19 Prozent, maximal 50 Positionen; bei 0 Prozent ein gespeicherter Steuerbefreiungsgrund. Bei Bruttobeträgen wird der exakte Nettopositionsbetrag als Preis für die tatsächliche Basismenge verwendet. So bleibt zum Beispiel eine Position mit 3 Tagen und 357 Euro brutto bei 300 Euro netto plus 57 Euro Steuer; es entsteht keine zusätzliche Rundung durch einen neu berechneten Nettostückpreis.

**Inlandsprüfung:** Das bisherige Ausgangsrechnungsschema speichert keine verlässlichen Länderfelder. Deshalb darf `DE` nicht stillschweigend aus den freien Adressfeldern angenommen werden. Vor Erstellung zeigt die Oberfläche beide Originalanschriften und verlangt die bewusste Auswahl, dass beide in Deutschland liegen; keine Vorauswahl. Die serverseitige Bestätigung ist an den SHA256 des eingefrorenen Rechnungsstands einschließlich Original-PDF-Hash gebunden und wird mit handelndem Konto/Zeitpunkt im Snapshot dokumentiert. Fremde Länderangaben, wenn sie künftig im Quellstand enthalten sind, übersteuern diese Auswahl und sperren den Inlandsexport. Eine Formatprüfung allein bestätigt keine tatsächliche Anschrift. Amsterdam ohne Bestätigung, mit Nein-Auswahl oder einer zu einem anderen Original gehörenden Bestätigung wird abgewiesen; Postleitzahlen werden nicht als Länderbeweis verwendet.

Manuell erfasste Altbelege, hochgeladene Fremdrechnungen, Beiträge, Spenden, Stornos/Gutschriften und internationale Rechnungen erhalten diesen Ausgangsexport nicht. Vorhandene XRechnung wird unverändert angeboten. Der neue Export ist **EN16931**, keine als XRechnung gekennzeichnete CII-Datei. Die vom Mustang-Validator zusätzlich ausgegebenen Hinweise auf XRechnung-/Peppol-Pflichtfelder sind im vollständigen Bericht erhalten; eine B2G-/Peppol-Konformität wird damit nicht behauptet.

## Verarbeitung

1. `OutgoingEInvoice::hybridSource()` prüft Finanzschreibrecht, Verein, Belegversion, ausgegebene Rechnung sowie Originaldatei/Hash und gespeicherten PDF-Snapshot.
2. `CiiInvoice` erstellt UN/CEFACT CII mit EN16931-Guideline aus diesem Stand. Fehlende Originalangaben werden nicht durch neue Einstellungen ersetzt.
3. `build-hybrid-invoice.py` ergänzt mit Factur-X 6.8 die Datei `factur-x.xml`, XMP-Erweiterungsschema, PDF/A-3b-Metadaten und einen sRGB-OutputIntent. `/AFRelationship` ist `/Alternative`. PDF/A wird hier zunächst nur als Kandidat erzeugt.
4. Mustang CLI 2.26.0 prüft lokal XSD und Schematron sowie mit eingebautem **veraPDF** die PDF/A-Konformität. PHP verlangt sowohl XML/PDF/Gesamtergebnis `valid` als auch `flavour=3b`, positive Prüfanzahl, leere Fehlprüfliste und `isCompliant=true`. Ein bloßes Gesamtergebnis genügt nicht.
5. Nur danach speichert `saveHybrid()` die geprüfte Datei als neues Dokument am selben Einnahmebeleg. Im unveränderten Snapshot stehen Originaldokument-ID, Rechnungs-ID/-Version, Original-/XML-/Ausgabe-SHA256, Validator-/Pakethash, Zeitpunkt und vollständiger Prüfbericht. Änderungen während der Prüfung brechen die Speicherung ab.

Keine Forderung, Finanzbuchung, Mail oder Push wird hierbei erzeugt. Geänderte/archivierte vorhandene Hybriddateien werden beim erneuten Abruf nicht stillschweigend als weiterhin geprüft zurückgegeben.

## Sicherheits- und Laufzeitgrenzen

- Nur intern ausgegebene PDFs; keine allgemeine Konvertierung für öffentliche Uploads.
- Verschlüsselte, interaktive, bereits hybride und mit Formular-/Signaturcontainern versehene PDFs werden abgewiesen. Keine Signaturen werden entfernt oder neu behauptet.
- Kein Remote-Saxon-Service: `check_schematron=False` im Python-Paket ist absichtlich gesetzt, weil die vollständige Prüfung anschließend lokal im Java-Validator erfolgt. Es werden keine Rechnungen an einen Drittanbieter übertragen.
- Prozessaufrufe als Argumentlisten, kein Shell-String. Private zufällige Arbeitsverzeichnisse, exklusive Sperre, 30/90 Sekunden Prozessgrenzen, Ausgabegrößengrenze, Java-Heap 256 MB; endgültige Datei erst nach Prüfung.
- Runtime-Konfiguration prüft Hashes von Java/Python/Validator und des privaten Paketbaums. Paket-Bytecode-Caches werden nicht verwendet: Python sucht sie unter einem neuen privaten Cachepräfix und schreibt durch `-B` keine Caches.
- XML wird ohne DTD/externe Entitäten verarbeitet. Java lässt lokale Ressourcen aus Dateien/JARs zu, keine externen HTTP-Schemas/Stylesheets.

## Installation reproduzieren

Alle Bibliotheken in ein **separates privates Verzeichnis** installieren; keine bestehende Python-/Java-Laufzeit ersetzen. `vereinsverwaltung/requirements-hybrid.txt` hält die tatsächlich geprüften Versionen fest. `factur-x`/`pypdf` sind BSD-lizenziert, Mustang ist Apache-2.0; das JAR enthält zusätzliche Bibliotheken einschließlich veraPDF. Paket-Lizenzdateien und JAR-LICENSE/NOTICE müssen mit dem privaten Laufzeitpaket erhalten bleiben.

Mustang CLI 2.26.0 stammt aus dem [offiziellen GitHub-Release](https://github.com/ZUGFeRD/mustangproject/releases/tag/core-2.26.0). Geprüfter SHA256:

```
42d7868cb68264874a7b8cab4c3587b03b23ccc7cd72373da917f66758bb9736
```

Lokale Vorbereitung:

```sh
python -m pip install --target PRIVATE/packages -r vereinsverwaltung/requirements-hybrid.txt
mkdir PRIVATE/work
php vereinsverwaltung/tools/configure-hybrid-runtime.php PRIVATE/runtime.json JAVA PRIVATE/mustang.jar PYTHON PRIVATE/packages PRIVATE/work
```

Der Konfigurationshelfer überschreibt keine vorhandene Datei und akzeptiert nur den oben festgehaltenen offiziellen JAR-Hash. Nach Updates erneut Testfälle und Validatoren prüfen, anschließend eine neue Konfiguration erzeugen. `VEREIN_HYBRID_RUNTIME` wird erst nach erfolgreicher Prüfung des betreffenden Servers auf die Konfiguration gesetzt. Ohne diese Variable ist der Download nicht aktiv; die Oberfläche meldet die fehlende Einrichtung.

### Netcup geprüft, Aktivierung durch Releaseverantwortlichen

Die private Vorbereitung und **echte synthetische CLI-Prüfung** auf Netcup sind abgeschlossen. `.local/hybrid-einvoice/host-manifest.json` dokumentiert Pfade und Prüfergebnis. Keine Aktivierungsvariable wurde gesetzt und keine Vereinsdatenbank verwendet.

- Privates Ziel (SSH-Chroot): `/tmp/kulturhus-hybrid-einvoice/20260909-2.26.0`.
- CLI-Konfiguration: `runtime-ready-cli.json` in diesem Ziel.
- Web/FPM-Konfiguration: `/var/www/vhosts/hosting247793.af94e.netcup.net/tmp/kulturhus-hybrid-einvoice/20260909-2.26.0/runtime-web.json`.
- `packages/`: zehn offizielle, gepinnte Linux-CPython-3.11-Wheels; vor Entpacken gegen `manifest.json` gehasht. Lizenzdateien in den Paketmetadaten bleiben erhalten; `mustang-licenses.txt` bewahrt 23 Lizenz-/Notice-Ressourcen aus dem offiziellen JAR.
- `java-private` und `python-private`: eigene selbstlokalisierende Wrapper. Quellvorlagen sind `tools/hybrid-java-hosting.sh` und `tools/hybrid-python-hosting.sh`. Bei Übertragung Unix-Zeilenenden und Modus 0700 verwenden. Nach inhaltlichen Änderungen Hashkonfiguration neu erzeugen.
- Java kommt aus der bestehenden privaten KoSIT-Java-17-Laufzeit. Python bleibt die vorhandene Python-3.11.2-Binärdatei. Der neue Python-Wrapper erhält den vom Programm gesetzten Paketpfad und verwendet die bereits für Java vorhandene private `libpthread.so.0`-Kompatibilitätsbibliothek. Ein erster Direktaufruf ohne diesen Bibliothekspfad scheiterte korrekt; nach Wrapper-Einrichtung bestanden Konvertierung und vollständige Prüfung.
- Die als Zwischenstufe entstandene `runtime-cli.json` zeigt noch auf die rohe Python-Binärdatei und ist **nicht** die fertige Laufzeit. Nur `runtime-ready-cli.json` bzw. `runtime-web.json` verwenden.
- Der geprüfte Hostlauf brauchte 5,04 Sekunden mit PHP CLI **8.5.9**, Java-Heap 256 MB. Ergebnis: XML/PDF `valid`, veraPDF `3b`, 2325 Assertions und `isCompliant=true`. Host-PDF und Prüfbericht wurden privat zurückgeladen; PDF mit Poppler gerendert und visuell geprüft.
- **Vor Webaktivierung** muss der Releaseverantwortliche einen synthetischen Test im tatsächlichen PHP-FPM-8.3-Kontext durchführen (Pfade, open_basedir, Prozessrechte). Danach kann `VEREIN_HYBRID_RUNTIME` auf das Webmanifest zeigen. CLI-Erfolg allein wird nicht als FPM-Test ausgegeben.
- Root führte anschließend den tatsächlichen synthetischen Webprozess-Test aus: **PHP 8.3.33 CGI**, 5,08 Sekunden, XML/PDF gültig, veraPDF 3b mit 2325 Assertions. Nachweis `.local/hybrid-web-runtime-result.json`; Prüfhelfer danach entfernt. Auch dieser Lauf schrieb keine Vereins-/Versanddaten. Die Aktivierung bleibt dem gemeinsamen Release nach Inlandsfix/Regression vorbehalten.

Die temporären Validator-Arbeitsverzeichnisse werden nach jedem Lauf entfernt. Unter `qa/` bleiben ausschließlich die fiktiven Original-/Ergebnisdateien sowie der echte Bericht und `result.json` für die Freigabeprüfung. Die Installation ersetzt keine bestehende Python-/Java-Laufzeit.

## Prüfungen und Nachweise


`vereinsverwaltung/tests/hybrid-einvoice-reuse.php` bestand zusätzlich vier Wiederverwendungsprüfungen mit einem echten synthetischen Export: derselbe belegte Originalstand bleibt idempotent; ein Altbestand ohne gespeicherte Inlandsbestätigung wird weder wiederverwendet noch als geprüfter Download angeboten; ein archivierter Export wird nicht wiederverwendet. Der Altbestandsfall wird ausschließlich in der Dateidatenbank-In-Memory-Testdatenbank simuliert.

`vereinsverwaltung/tests/hybrid-einvoice.py PRIVATE_QA_DIR` bestand fünf Gruppenprüfungen zum echten Container, Dateiname, MIME, AF-Beziehung, XMP, RGB-Profil und der Abweisung aktiver/verschlüsselter/bereits hybrider/Formular-PDFs. Die ausgegebene PDF wurde mit Poppler gerendert und visuell geprüft. Das bestehende UBL-/KoSIT-Verfahren bestand `outgoing-einvoice.php` (20 Prüfungen) und `outgoing-einvoice-extended.php` (44 einschließlich bestehender Finanzabläufe). Sämtliche neun PHP-Dateien der Erweiterung bestanden zusätzlich Syntaxprüfungen unter PHP 8.3.

Die Rohartefakte liegen privat unter `.local/hybrid-einvoice`: fiktives Original, CII, hybride PDF und vollständiger echter Prüfbericht. Kein Versand und keine Änderung echter Vereinsdaten fand für diese Tests statt.

## Bedienhinweis für das Handbuch

Bei einer ausgegebenen Ausgangsrechnung steht im Einnahmebeleg zusätzlich „Rechnung als ZUGFeRD-PDF“. „ZUGFeRD-PDF prüfen und erstellen“ prüft und speichert die Datei. Danach erscheint „ZUGFeRD-PDF herunterladen“. Empfänger, Rechnung und Zahlungszuordnung werden dabei nicht erneut angelegt. Für Empfänger mit Leitweg-ID/XRechnungsvorgabe bleibt „XRechnung vorbereiten“ der gesonderte vorhandene Weg.

## Geprüfte Primärquellen

- [FeRD: ZUGFeRD 2.5.2 / Factur-X 1.09.2](https://www.ferd-net.de/en/downloads/publications/details/zugferd-252-english) – aktuelles Release, Rückwärtskompatibilität und Inhalte des Releasepakets. Die hier getestete Pipeline wird konkret durch ihre Bibliotheks- und Validatorversion belegt, nicht pauschal als Unterstützung aller Profile/Erweiterungen dieses Releases bezeichnet.
- [FNFE-MPE: Factur-X](https://fnfe-mpe.org/factur-x/) – gemeinsamer Standard und technische Ressourcen.
- [Factur-X Python-Projekt](https://github.com/akretion/factur-x) – API, Lizenz und Trennung lokaler XSD-Prüfung von optionaler Remote-Schematron-Prüfung. Der Remote-Aufruf wird hier nicht genutzt.
- [Mustang 2.26.0 PDFValidator](https://github.com/ZUGFeRD/mustangproject/blob/core-2.26.0/validator/src/main/java/org/mustangproject/validator/PDFValidator.java) – Einbindung echter veraPDF-Prüfung und PDF/A-3-Ergebnis.
- [veraPDF: CLI-Validierung](https://docs.verapdf.org/cli/validation/) – PDF/A-Profile und Bedeutung eines explizit konformen Prüfergebnisses.
