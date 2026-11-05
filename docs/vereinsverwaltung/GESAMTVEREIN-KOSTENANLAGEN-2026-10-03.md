# Originalbuchwerte im Gesamtvereinsabschluss

Die normale zusammengesetzte Jahresausgabe enthält jetzt die unveränderten
Buchwerte des Gesamtvereins und die getrennten Steuerwerte aller steuerpflichtigen
Geschäftsbetriebe. Automatische Kostenbewertungsunterschiede verändern den
Buchwertteil nicht mehr. Die Quellen, originalen Bewertungsbelege, Anfangswerte,
Abgänge und Gegenkonten bleiben erhalten.

Für `02150`, `02350` und `09200` bestehen 81 lokale Fälle mit Originalwerten,
Vortrag, gemischten Anlagen und internen Zeitraum-Buchwertteilen. Die 53 tatsächlich
exportierbaren zusammengesetzten Jahresfälle bestehen nativ mit ERiC-Code 0.
Die 28 internen Zeitraum-Buchwertteile sind keine einzeln exportierbaren
Gesamtabschlüsse. Der zunächst falsch als eigenständige Ausgabe geprüfte erste
interne Teil wurde nativ zurückgewiesen; dieser Bericht bleibt unverändert erhalten.
Eine XML-Freigabe für zusammengesetzte **Zeitraum-Kostenanlagen** folgt daraus nicht.

Weitere native Nachweise auf demselben Anwendungsstand: 162 Fälle der normalen
Kostenanlagen-Ausgabe sowie 22 bisherige allgemeine Fälle. Der absichtlich
veränderte Bilanzsaldo wurde zurückgewiesen. Kein echter Versand, kein Zertifikat,
keine produktive Umstellung von Kulturhus auf Bilanzierung.

Der vollständige Offline-Lauf mit 53 Programmen ist ausgewertet. Ein Cache-Test
hatte eine überholte Dateikopierliste ohne neue Kostenanlagen-Abhängigkeiten.
Sein tatsächlicher kopierter Anwendungsbaum wurde korrigiert; alle elf Prüfungen
dieses Programms und die Inventarprüfung bestehen im getrennten Nachlauf.
Der ursprüngliche Sammelbericht bleibt fehlgeschlagen. Außer dieser Testdatei
ist der geprüfte Anwendungsstand unverändert; die Nachweise werden nicht zu
einem erfundenen neuen Einzel-Sammellauf umgeschrieben.

Nachweise:

- `audit/ebilanz-cost-combined-export-native-20261003.json`
- `audit/ebilanz-cost-assets-whole-regression-native-20261003.json`
- `audit/bilanz-original-books-regression-native-20261003.json`
- `audit/bilanz-offline-complete-20261003.json`
- `audit/bilanz-r16-cache-fixture-repair-20261003.json`

Der Gesamt-MySQL-Lauf `2b24ecdf1f996286` läuft noch. Allgemeine Bilanzfreigabe,
vollständige UI-Abnahme und Produktivaktivierung sind nicht erfolgt. Die nächste
Ergänzung ist die belegte gemeinsame Nutzung von Kostenanlagen einschließlich
des unveränderten Vortrags und der zusammengesetzten Zeitraum-Ausgabe.
Weitere Bewertungsfamilien und die tatsächliche 2027-DATEV-Quelle bleiben offen.
