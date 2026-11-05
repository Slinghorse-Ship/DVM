# Anlagenverkauf und Ausgangsrechnungen – Entwicklungsstand 18. September 2026

Fortsetzung 20.09.2026: [Verkauf bei getrennten Buch-/Steuerwerten](STEUERLICHER-ANLAGENVERKAUF-20260920.md).
Diese Ergänzung hebt die nachfolgenden allgemeinen Freigabegrenzen nicht auf.

**Nicht produktiv veröffentlicht. Nicht vollständig abgenommen.** Kein Zugriff auf die
produktive Datenbank, keine Steuerübermittlung, Bankaktion oder E-Mail-Zustellung.
Die separate, ausdrücklich freigegebene Testdatenbank wurde gesichert, verwendet und
anschließend mit Daten-/Strukturvergleich wiederhergestellt; Einzelheiten unten.

## Implementiert

- Eigene ausgegebene Ausgangsrechnungen können als Originalbeleg der Bilanzquelle dienen.
  Abgleich mit Ausgabeverlauf, Beträgen/Steuerpositionen, Leistungsdatum, Dokumentzuordnung
  und tatsächlichen PDF-Bytes. Beliebige erzeugte Vorschauen reichen nicht aus.
  Gutschriften/Stornos bleiben ausdrücklich getrennt zu klären. Zahlung und Mailzustand
  verändern den ursprünglichen Rechnungsnachweis nicht.
- Vollständiger Verkauf eines einzelnen beweglichen Gegenstands über eine eigene,
  ausgegebene Ein-Positions-Rechnung. Nur 06300/06500/06700, Inland, 19 % oder bereits
  im Original belegte Steuerbefreiung; keine automatische Entscheidung über Steuerfreiheit.
- Zeitanteilige Abschreibung einschließlich Abgangsmonat, getrennter Restbuchwert,
  Nettoerlös und Umsatzsteuer. Gewinn-/Verlustkonten werden aus dem Restwert berechnet:
  48450/48490 mit 48550 oder 68850/68890 mit 68950. Kontierungsänderung wird protokolliert;
  ausgegebene Preise, Steuer und PDF werden nicht geändert. Geldbewegung bleibt separat.
- Neue MySQL-Migration 094: vereinsgebundene eindeutige Verbindung von Jahresabgang
  und Verkaufsrechnung. Anlage-/Rechnungsquellen müssen beide unverändert sein.
  Die eindeutige Rechnungszuordnung und Jahresfeststellung werden gemeinsam gespeichert.
- Bedienoberfläche, CSV-/JSON-Nachweise und E-Bilanz-Generator erweitert. Physischer
  Inventarbestand wird nicht durch eine bloße Buchwertfeststellung verändert.
- SKR42-Zuordnung Revision 6; zusätzliche Verkaufscodes nur mit abgestimmtem
  Einzelanlagenverzeichnis. Gegenläufige GuV-Konten werden in den amtlichen
  Abzugspositionen korrekt vorzeichenbehandelt, ohne den Journalstand umzuschreiben.

## Nachgewiesene Prüfungen und offene Abnahme

- Vor Ausfall von Docker: 58 MySQL-Prüfungen des neuen Ausgangsrechnungsadapters
  (2025/2026), zusätzlich vorhandene Quellen-, Umsatzsteuer- und Ausgangsrechnungs-
  Sicherheitsprüfungen erfolgreich. Audit `mysql-suite-20260916-041916.json` enthält
  auch einen getrennten fehlgeschlagenen Anlagenabgang-Test: die Schemaobergrenze
  war noch 93. Im Code auf 94 korrigiert, **Wiederholung steht aus**.
- Am 18. September: 96 Python-Testmethoden mit tatsächlichen PHP-Rechenkern-Fixtures
  erfolgreich: 57 Generator, 10 Ausmusterung, 10 degressive AfA, 10 Umsatzsteuer,
  9 Anlagenverkauf. Native lokale PHP-CLI statt Docker; keine Datenbankverbindung.
- Neun synthetische Verkaufsfälle mit nativer ERiC-Bibliothek validiert, alle Code 0:
  Gewinn/Verlust mit 19 % und ohne USt, Verkauf zum Buchwert, voll abgeschriebener
  Gegenstand, GWG, degressive Abschreibung und Folgejahr. Verfälschte Bilanzsumme
  wurde erwartungsgemäß zurückgewiesen. Protokoll `audit/ebilanz-sale-native-20260918.json`.
  Ausschließlich Prüfflag 2; kein Zertifikat, Versand oder Transferticket.
- PHP-Syntax der neuen/geänderten Kernklassen und Formulare sowie JavaScript-Syntax
  geprüft. Dies ersetzt keine Funktions-/Browserprüfung.
- **Noch nicht vollständig durchgelaufen:** ursprüngliche gesamte Docker/MySQL-Suite,
  überarbeitete Browserformulare und vollständige HTTP-/Berechtigungsabnahme dieses
  Stands. Die unten dokumentierten Serverprüfungen sind erfolgreich, ersetzen jedoch
  nicht die Gesamtprüfung. Docker Desktop bricht am lokalen
  `sailor-ingest.sock` ab. Kein Werksreset, keine Löschung von Docker-Daten.
- Die zusätzliche Kandidaten-Vorauswahl des Leistungsdatums wurde inzwischen in
  beiden Jahren in der Server-MySQL-Prüfung erfolgreich geprüft.

## Fortsetzung: isolierte Webserver- und MySQL-Abnahme

- SSH funktioniert. Produktiver Release bleibt `20260915-tax-usability-9eb73a3f`;
  keine Umschaltung und keine Freischaltung der E-Bilanz-Echtabgabe.
- Neun Verkaufsfälle auch über HTTPS/PHP 8.3.33/FastCGI, Python und Linux-ERiC
  erfolgreich validiert: jeweils Code 0, `sent=false`, `transport_attempted=false`,
  kein Datenbankzugriff. Nicht authentifizierter Testaufruf wurde mit 404 abgewiesen.
  Nachweis: `audit/ebilanz-sale-webserver-20260918.json`.
- Auf MySQL 8.4.11: `balance-issued-invoices.php` mit 60 Prüfungen erfolgreich;
  einschließlich Leistungsdatum-Vorauswahl, Rechte, unveränderliche Original-PDFs
  und Zahlung ohne doppelte Einnahme. Die im Verkaufstest neu gesetzten Steuer-
  einstellungen verloren zunächst die synthetische Steuernummer. Nur Testfixture
  korrigiert: vorhandene Einstellungen werden jetzt ergänzt statt zurückgesetzt.
- `asset-sale.php`: **140 kombinierte Rechnungs-/Verkaufsprüfungen erfolgreich**
  (die 60 Rechnungsprüfungen sind darin enthalten, nicht zusätzlich zu zählen).
  Vier vollständige Verkaufsszenarien: Gewinn/Verlust, jeweils 19 % oder belegte
  0 %. Kontierung, Monats-AfA, Buchwertabgang, Berechtigungen, Belegerhalt,
  Quellenabweichungen, Zahlungszuordnung und doppelte Verwendung geprüft.
- `mysql-balance-schema-restore.php`: vier Prüfungen des wiederhergestellten
  Bilanzschemas mit Migration 094 erfolgreich. Daten, Spaltenmetadaten, Schlüssel,
  CHECK-Regeln und Trigger werden verglichen. MySQL ergänzt bei `history_sha256`
  nach Restore nur die zuvor implizite Angabe `CHARACTER SET utf8mb4` im
  `SHOW CREATE TABLE`-Text. Der Test normalisiert ausschließlich diese anhand
  von `information_schema.COLUMNS` belegte Schreibweise; keine materielle
  Definition wird übergangen. Sieben zusätzliche reine PHP-Negativ-/Formatprüfungen.
- `asset-plan-concurrency.php`: 24 Prüfungen konkurrierender Planänderungen,
  Jahresfeststellungen und Ausmusterungen erfolgreich. Das ist **noch kein**
  separater Paralleltest zweier Anlagenverkäufe mit derselben Rechnung.
- Bezeichnungen in Bilanzbericht und Abgabevorschau unterscheiden jetzt
  „Verkauft“ und „Ausgemustert“, gemeinsame Spalte „Restwert beim Abgang“.
  Neun reine PHP-Berichtsprüfungen und Syntaxprüfungen erfolgreich;
  keine Aussage über abgeschlossene Browser-/Bedienabnahme.

### Erhaltung und Grenzen der Serverprüfung

Ein eigener privater CLI-Testadapter nutzt ausschließlich `k15753_migrate` mit
dem dafür vorgesehenen Datenbankbenutzer, hält über den ganzen Lauf eine
exklusive Testsperre und akzeptiert keine produktive Verbindungszeichenfolge.
Die ursprünglichen **150 Tabellen / 1.393 Datensätze** werden vor jedem Lauf
serverseitig verschlüsselt gesichert und authentifiziert geprüft. Nach jedem Lauf
wurden alle ursprünglichen Daten, Strukturen und Trigger erfolgreich verglichen.
Keine Datenbankinhalte oder Sicherungsschlüssel wurden auf den PC heruntergeladen.

Da nur eine freigegebene Testdatenbank verfügbar ist, führt dieser Adapter den
synthetischen Restore **in derselben Datenbank** durch und vergleicht vor/nach
dem Restore alle Werte und Strukturen. Er ersetzt weder einen Restore in eine
zusätzliche leere Datenbank noch die unveränderte vollständige Docker-Testsuite.
Unveränderte Test-Assertions und Anwendungsklassen; nur Testdatenbank-Zuteilung
und Restore-Ziel sind im privaten Adapter angepasst.

Erfolgreicher abschließender Lauf:
`audit/ebilanz-mysql-host-20260918-0cd61b810c6774e6.json`.
Parallelitätsprüfung:
`audit/ebilanz-mysql-host-20260918-fc771f189980c9ba.json` (enthält zusätzlich
den damals noch fehlgeschlagenen textuellen Schemavergleich, kein vollständig
grüner Gesamtlauf). Frühere fehlgeschlagene Läufe bleiben als Nachweis erhalten.
Temporäre Serverdateien/-endpunkte sind entfernt; verschlüsselte Sicherungen
bleiben privat auf dem Webspace. Geschützte Produktivdateien unverändert.

ERiC-Code 0 belegt die technische Validierung dieser synthetischen Fälle, nicht
die materielle steuerliche Richtigkeit, vollständige SKR42-Abdeckung oder eine
erfolgreiche Verarbeitung einer Echtabgabe. Diese bleibt gesperrt.

## Nächste Schritte nach Wiederherstellung von Docker

1. `run-mysql.ps1 -Tests @('asset-sale.php','asset-disposal.php','balance-issued-invoices.php',
   'balance-sources.php','balance-corrections.php','balance-vat-sources.php',
   'outgoing-invoice-safety.php','mysql-backup-package.php')` vollständig ausführen.
2. Verkauf, Entsorgung, kein Abgang, Fehlererhalt, Monats-/Restwertanzeige sowie
   deaktivierte irrelevante Pflichtfelder im Browser bei 390/1280 px prüfen.
3. Zusätzlich zur bestandenen Server-Wiederherstellung: vollständigen Paket-Restore,
   konkurrierende Verkaufszuordnung und späten Rollback nach fehlgeschlagener
   Jahresfeststellung testen; anschließend vollständige HTTP-/Berechtigungsabnahme.
4. Weitere offene Fälle entwickeln: Teilabgänge/Umbuchungen, Entnahmen/Schenkungen,
   Versicherungsersatz, getrennte Handels-/Steuerwerte, Sammelposten und Sonder-AfA,
   weitere Konten-/Überleitungsfälle, Mehrbetrieb/organisationsweite Bilanzierung.

Eine Veröffentlichung erst nach diesen jeweiligen Freigabeschritten; keine pauschale
Reparatur oder Überschreibung vorhandener produktiver Journal-/Originaldaten.
