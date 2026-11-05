# Lokale Vereinsbilanz und durchgehende Prüfung

Die lokale Vereinsverwaltung verwendet ausschließlich die permanent synthetische
MySQL-Datenbank und vier erfundene Rollen. Das echte Kulturhus bleibt EÜR.
Die lokale Anwendung erlaubt XML-Erzeugung und native Prüfung, aber keinen
Versand, Zertifikatszugriff oder Abruf von Finanzamtsrückmeldungen.

## Behobener Rechenengpass

Bei längeren Vortragsketten wurden die unveränderten Anlagenarten und ihre
Original-Jahreskontenpläne sehr häufig neu gelesen und gehasht. `BalanceSourceRead`
begrenzt diese Quellprüfung auf einen synchronen, rein lesenden Rechenaufruf.
Alle Buchungs-, Aufteilungs-, Bewertungs- und Vortragsprüfungen laufen weiterhin
mit ihren vollständigen Eingaben. Berichte werden nicht zwischengespeichert.
Der Kontext endet auch bei einer Ausnahme; der nächste Aufruf prüft die
Originalquellen erneut.

Der tatsächliche Quellstand rekonstruiert einen gespeicherten synthetischen
Vortrag mit identischer Prüfsumme. Die lokale Messung sank von 31,18 Sekunden
auf unter eine halbe Sekunde, bei 22 MiB Spitzenspeicher. Das ist eine Messung
dieses konkreten Falls, keine allgemeine Laufzeitgarantie.
14 Lebensdauerprüfungen und neun Prüfungen veränderter vollständiger Eingaben
bestehen. Eine geänderte Original-Jahresquelle wird auch nach einem zuvor
erfolgreichen Aufruf zurückgewiesen. Nachweise:
`audit/bilanz-source-read-pure-20261005.json` und
`audit/bilanz-source-catalog-change-20261005.json`.

Der alte Lauf `3c49ba24888824f0` wurde gezielt beendet. Sein Checkpoint mit
14 bestandenen Programmen bleibt unvollständig. Der getrennte Wiederherstellungs-
nachweis bestätigt zweimal fehlende Testverbindungen, unveränderte geschützte
Produktivdateien und die Entfernung der temporären Kopie.

## Vollständiger Rechen- und XML-Nachweis

Der vollständige Lauf `a1c457a220dea06f` endet mit 176 bestandenen und zwei
fehlgeschlagenen Programmen. Die beiden Fehler bleiben in seinem Originalbericht:
eine falsche Testannahme über eine absichtlich fehlende Zeitraum-Gesamtsumme und
die Auslassung der vorhandenen Rechnungsverfahrensbeschreibung in der privaten
Testkopie. Der erste Reparaturlauf mit noch fehlendem GET-Testkontext bleibt
ebenfalls als fehlgeschlagen erhalten. Der korrigierte Nachlauf
`61eee3777525ea45` besteht mit allen acht Programmen und fünf nativen XML-Dateien.
Rechenmodell, Tabellen und amtliche Prüfregeln wurden dabei nicht abgeschwächt.

Die Menüberechnung liest die Rechte derselben Person auf derselben Verbindung
jetzt einmal innerhalb eines einzigen lesenden Aufrufs. Der Kontext endet bei
Rückkehr und Ausnahme. Direkte Seiten-, Service- und Schreibprüfungen lesen
weiterhin die aktuellen Rechte. 13 eigene Lebensdauerprüfungen decken auch
Entzug, verschiedene Personen und Verbindungen sowie den Redaktionszugang ab.
Die PDO-Option für gefundene Zeilen verwendet unter PHP 8.5 die neue Konstante
und unter PHP 8.3 dieselbe bisherige Option.

Der Lauf `0b4eaa430c279b80` besteht anschließend mit allen 18 gezielt betroffenen
MySQL-Programmen und fünf erneut erzeugten XML-Dateien: Jahr, Zeitraum,
zusammengesetzter Verein sowie gemeinsame Nutzung für Jahr und Zeitraum.
Alle fünf Dateien bestehen bytegenau die native ERiC-Prüfung mit Flag 2 und
Code 0. Zusätzlich bestehen alle 54 Offline-Programme auf dem unveränderten
aktuellen Quellstand. Der zusammengesetzte Nachweis bindet die vollständigen
Vorgängerberichte, die drei genau geprüften Menü-/PDO-Änderungen und die
vollständige Inventur von 173 Bilanzprogrammen:
`audit/manager-navigation-source-coverage-20261005.json`.

## Laufende private Anwendung

Die Adresse lautet `http://127.0.0.1:8091/vereinsverwaltung/`.
PHP läuft in einer privaten, quellengeprüften Kopie neben der ausschließlich
synthetischen Datenbank. HTTP wird über die vorhandene SSH-Verbindung nur an
die lokale Adresse weitergereicht. Damit entfällt die einzelne Netzwerkfahrt
für jede SQL-Abfrage. Es gibt weiterhin genau einen Besitzer der Testdatenbank.

Die installierte Linux-ERiC-Version wird nicht ausgeführt: Ihre Versionspaarung
ist nicht im geprüften Jahrespaket freigegeben. Stattdessen erzeugt und prüft
der bereits abgenommene Windows-Adapter die Aufträge über einen authentifizierten
privaten Kanal. Die beiden Grenzen erlauben nur Status, XML-Erzeugung und
Prüfung. Versand, Zertifikate, Druckvorschau und beide Rückmeldeaktionen werden
vor einem SDK-Aufruf zurückgewiesen. Alle fünf XML-Dateien bestehen auch diesen
tatsächlichen Anwendungskanal. Der Testbetrieb erhält einen eigenen privaten
Bankdatenschlüssel und eine leere Zertifikatsablage außerhalb des Webbereichs;
echte Bank- oder Zertifikatsdaten werden nicht übernommen.

Alle 61 HTTP-Prüfungen bestehen, einschließlich vollständigem HTML, eingebundenen
Dateien, Cookie-Grenzen, abgewiesenen privaten Pfaden und falschem Host.
Der gespeicherte Bilanzablauf besteht mit 14 Prüfungen über die tatsächlichen
Formulare: vollständige Anfangsbestände, ausgeglichene Erlös-/Aufwandsbuchungen,
Festschreibung, genauer Vortrag und laufende Buchung im offenen Folgejahr.
Der erfundene Abschluss 2025 enthält 1.200 EUR Aktiva und Passiva sowie
200 EUR Ergebnis. 2026 übernimmt genau 1.200 EUR; eine danach gespeicherte
10-EUR-Ausgabe ergibt 1.190 EUR Bilanzsumme und -10 EUR Ergebnis.
Ein vollständiger Vereinsentwurf wird erzeugt, nativ mit Code 0 geprüft und
mit genau derselben angezeigten SHA-256-Prüfsumme als XML heruntergeladen.
Die tatsächlichen Schritte und Dateien sind unter
`.local/running-tested-manager/balance-flow-2026-10-05T02-46-05-947Z/report.json`
gespeichert. Es werden keine Daten des echten Kulturhus verwendet.

Das zweiseitige Arbeits-PDF verwendet genau den gespeicherten Bilanzinhalt.
Die Darstellung hält GuV und Kontennachweis zusammen, druckt die GuV-Summe
einmal nach allen vier Bereichen und wiederholt Jahr und Seitenzahl im Fuß.
Die ursprünglichen PDF-Bytes bleiben separat erhalten; die Layoutbearbeitung
bindet den unveränderten HTML-Inhalt:
`audit/manager-pdf-layout-edit-20261005.json`.


Der frühere schwache HTTP-Nachweis mit 22 Prüfungen wird nicht
als Abnahme verwendet; die danach sichtbaren Fehlerberichte bleiben erhalten.
Der lokale Neustart erfolgt mit `.local/start-bilanz-test.ps1`; er prüft die
Quellen und Nachweise einschließlich genau der vier korrigierten Programmdateien
und setzt die Datenbank nicht zurück. Eine bereits laufende geprüfte Anwendung
wird erkannt, ohne einen zweiten Datenbankbesitzer zu starten. Der abschließende
Betriebsnachweis steht in `audit/manager-finished-operational-20261005.json`;
er bindet die Rechentests, die Rollenabdeckung, den gespeicherten Bilanzablauf,
die tatsächliche native XML-Prüfung und die visuelle Kontrolle beider PDF-Seiten.

## Jahrespflege

Die amtlichen Taxonomiequellen 6.7, 6.8, 6.9 und 6.10 sind vorhanden.
6.8 gehört regulär zu 2025, 6.9 zu 2026 und 6.10 zu 2027;
die jeweilige Folgeversion kann bereits im Vorjahr genutzt werden.
Der vorhandene native Bilanzprüfer ist für 6.9 und 2025/2026 abgenommen.
Die Vorbereitung von 2027 ersetzt weder eine tatsächliche Jahres-PDF noch die
Abnahme des 6.10-Adapters und passenden ERiC.

Die amtliche Seite [Steuer Elektronisch](https://www.esteuer.de/) kündigt
6.10-Testunterstützung für November 2026 und Echtfälle für Mai 2027 an.
Die [DATEV-Ausgabe SKR42 Bilanz](https://www.datev.de/web/de/shop/produkt-details/12901)
weist derzeit Januar 2026 aus. Eine spätere 2027-Ausgabe wird anhand ihres
tatsächlichen Jahres und Inhalts geprüft; die bisherige PDF wird nicht umbenannt.

Die fachlichen Grenzen der [Bilanz-Lückenmatrix](BILANZ-LUECKENMATRIX-2026-10-03.md)
bleiben bestehen. Dieser lokale Betriebsnachweis ist keine allgemeine steuerliche
Freigabe für alle Anlagen- und Bewertungsfälle.
