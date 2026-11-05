# Jahrespflege ab 2027

Stand 03.10.2026. Arbeitsstand im Worktree `eb9a/Kulturhus`, Branch
`layoutauswahl`. Das Ziel bleibt die allgemeine Vereinsbilanz und anschließend
die vollständige technische Prüfung des Vereinsmanagers auf Desktop und Handy.
Diese Erweiterung ist eine Vorbereitung der Jahrespflege, keine Bilanzfreigabe.
Kulturhus bleibt EÜR; Produktivdaten und Produktivdateien werden nicht verändert.

## Amtliche Veröffentlichungen

Die vier regulären XBRL-Quellen 6.7, 6.8, 6.9 und 6.10 sind getrennt importiert
und mit Originalquelle, Datum, Modulprüfsummen und Metadatenprüfsumme gebunden.
Die Jahresübersicht im gespeicherten Kontenimport zeigt sie der Administration.
Die [amtliche Jahreszuordnung](https://www.esteuer.de/) bezieht sich auf den
**Beginn des Wirtschaftsjahrs**, auch bei einem vom Kalenderjahr abweichenden Jahr:

| Taxonomie | Datum | Regulär ab Beginnjahr | Ausdrücklich optional für Beginnjahr | Stand im Programm |
| --- | --- | ---: | ---: | --- |
| 6.7 | 01.04.2023 | 2024 | 2023 | Quellen verfügbar; Ausgabeadapter nicht abgenommen |
| 6.8 | 01.04.2024 | 2025 | 2024 | Quellen verfügbar; Ausgabeadapter nicht abgenommen |
| 6.9 | 01.04.2025 | 2026 | 2025 | Kontenregeln und lokale ERiC-Prüfung für 2025/2026 |
| 6.10 | 01.04.2026 | 2027 | 2026 | Kontenvergleich für 2027 vorbereitet; native Ausgabe nicht abgenommen |

Für ein 2025 beginnendes Wirtschaftsjahr ist somit 6.8 regulär und 6.9 optional.
Die vorhandenen 2025-Regeln verwenden weiterhin die zugelassene 6.9. 6.10 ersetzt
keine 2025-Grundlage. Quellenverfügbarkeit erlaubt weder automatisch einen
XML-Export noch eine Abgabe. Auch ein installiertes 6.7-/6.8-Prüfmodul ersetzt
keine Abnahme des eigenen Ausgabeadapters. Unbekannte Folgejahre werden nicht
durch eine Vermutung über die neueste Version freigegeben.

Die [DATEV-Bilanz-Veröffentlichung](https://www.datev.de/web/de/shop/produkt-details/12901)
bot am 03.10.2026 weiterhin den Stand Januar 2026 an. Der Downloadprozess darf
diese Quelle nicht unter 2027 speichern. Der vorhandene Ablauf zeigt stattdessen
das angebotene Jahr und ermöglicht eine erneute Prüfung.

Die [amtliche Taxonomie 6.10](https://www.esteuer.de/) vom 01.04.2026 gilt
grundsätzlich für Wirtschaftsjahre, die nach dem 31.12.2026 beginnen. Laut dort
veröffentlichter Planung wird die Testübermittlung voraussichtlich im November
2026, die Echtübermittlung im Mai 2027 durch entsprechende ERiC-Releases möglich.
Diese Ankündigung ist kein Nachweis eines bereits installierten Prüfers.

Die verwendete Quelle ist das reguläre
[XBRL-Paket 6.10](https://www.esteuer.de/download/taxonomie_20260615/germany-gaap-taxonomy-v6.10-2026-04-01-xbrl.zip).
Die ebenfalls angebotene Modernisierungsvorschau wird nicht verwendet.
SHA-256 des Originalarchivs:
`de00ceaad0e532d238dea24a48160ec335422a3a71c38dd03c7b2b34b32fff76`.

## Implementierter Jahresablauf

1. Vorhandenes Jahr auswählen und die unmittelbar folgende DATEV-Ausgabe abrufen.
   Download und PDF-Import prüfen Jahr, Variante, Originalbytes und Quelle.
2. Beim Vergleich 2026 → 2027 ausdrücklich die geprüfte Taxonomie 6.10 auswählen.
   Die Weboberfläche und das Wartungsprogramm verwenden dieselbe gepflegte
   Jahresauswahl. Andere Zukunftsjahre werden nicht stillschweigend freigegeben.
3. Quellbedeutung und gedruckten Abschlussposten mit dem Vorjahr vergleichen.
   Unveränderte Zwecke können über die geprüfte Taxonomieüberleitung erhalten
   bleiben. Nummern, ähnliche Texte und Bilanzsalden ersetzen keinen Nachweis.
4. Geänderte Zwecke, neue Konten, Fußnoten und bestehende Lücken zur Prüfung
   vorlegen. Die Prüfdatei ist an genau dieses Original, den Programmstand und
   das Jahrespaket gebunden. Ein geänderter Paketstand benötigt einen neuen Vergleich.
5. Ein isoliertes Kandidatenpaket erstellen. Es enthält getrennte Laufzeitregeln
   für historische und neue Taxonomien sowie die benötigte 6.10-Quelle und Überleitung.
   Bestehende Definitionen, Jahreskataloge und Buchungen bleiben unverändert.

Das 2027-Paket hat den Zustand `catalog_preparation`. Es besitzt bewusst keine
geprüfte native ERiC-Version. Es erlaubt den Kontenvergleich und die Vorbereitung
eines Zuordnungspakets. XML-Ausgabe mit 6.10, native Prüfung und Steuerabgabe
bleiben gesperrt. Ein JSON-Eintrag mit einer erfundenen Prüfernummer kann diese
Grenze nicht aufheben. Die Oberfläche und der Paketnachweis nennen diese Grenze.

## Wiederverwendung vorhandener Zwecke

`taxonomy_purposes.py` prüft Originaldefinition, Zielposition, Referenzen,
Präsentationshierarchie, Berechnungsgewichte und Anlagenhierarchie gegen beide
amtlichen Taxonomien. Alte Zweckdefinitionen werden nicht um neue Zielversionen
erweitert: Ihre Prüfsummen in historischen Jahresständen bleiben gültig.
Die Überleitung liegt als eigenes, quellengebundenes Prüfartefakt vor.

314 von 320 bestehenden Zwecken sind technisch unverändert überleitbar.
Sechs werden durch den Vergleich zurückgehalten:

| Zweck | Amtliche Änderung | Verhalten |
| --- | --- | --- |
| Roh-, Hilfs- und Betriebsstoffe; Sachspenden im Vorrat | Zusätzliche Berechnungspositionen für Emissionsrechte | Vorherige Summenzuordnung nicht automatisch für 2027 übernehmen |
| Fertige Erzeugnisse und Waren | Zusätzliche Berechnungspositionen für Emissionsrechte | Geänderte Taxonomiestruktur ausdrücklich prüfen |
| Sonstige Betriebssteuern; sonstige Steuern der Vorjahre; Auflösung sonstiger Steuerrückstellungen | Neue Kennzeichnung „Rechnerisch notwendig, soweit vorhanden“ | Geänderte Referenzen ausdrücklich prüfen |

Diese sechs Zwecke sind weder verschwunden noch einer Ersatzposition zugeordnet.
Ihre alten 6.9-Zuordnungen bleiben erhalten. Für 6.10 stehen sie erst nach einer
eigenen fachlichen und technischen Anpassung zur Verfügung. Auch die neue
Grundsteuerposition verlangt eine belegte Zweckregel; eine allgemeine Steuerzeile
darf sie nicht automatisch ersetzen.

## Abnahmegrenzen

Die gezielte Prüfung dieses Quellstands besteht: elf Pythonprogramme, zwei
PHP-Prüfprogramme und die native Windows-Prüfung (14 Programme). Die native
Prüfung nimmt drei 6.9-Fälle an und weist eine absichtlich verfälschte Ausgabe
erwartungsgemäß ab. Die Quellen sind während des Laufs unverändert geblieben.
Der getrennte isolierte MySQL-Lauf `f3ccf507b074b425` besteht mit vier Programmen:
94 Import-/Ansichtsprüfungen, 21 Taxonomiequellenprüfungen, zehn Prüfungen der
tatsächlichen PHP/Python/PDF-Kette und 260 Prüfungen der Laufzeitbindung.
Geschützte Produktivdateien sind unverändert, die temporäre Testkopie ist entfernt.
Die [Nachweisdatei](TAXONOMIEN-6.7-6.10-2026-10-03.json) bindet diese Ergebnisse
an die jeweiligen Quellen und erhält frühere Fehl- und Zwischenstände getrennt.
Es ist kein neuer vollständiger Lauf aller 164 MySQL-/47 Offlineprogramme.

Eine echte 2027-DATEV-PDF-Abnahme fehlt mangels veröffentlichter Jahresquelle.
Die positive 2027-Prozessprüfung verwendet ausdrücklich synthetische Kataloge;
sie beweist die Wartungskette, nicht den Inhalt einer amtlichen 2027-Ausgabe.
Als Gegenprobe wird die tatsächliche, unveränderte 2026-PDF mit Zieljahr 2027
eingelesen: Dieser Versuch muss zurückgewiesen werden.

Die allgemeine Bilanzabnahme bleibt zusätzlich durch die in
[der Lückenmatrix](BILANZ-LUECKENMATRIX-2026-10-03.md) erfassten Bewertungs-,
Anlagen- und Kombinationsfälle offen. Dazu gehören Grundstücke, Gebäude und
Finanzanlagen, Wertminderung/Wertaufholung, weitere Abgänge, angebrochene Monate
und die durchgängige amtliche Übermittlungs-/Rückmeldeprüfung.

Die vollständige UI-Prüfung folgt der allgemeinen Bilanzabnahme. Einzelne
Ansichtsprüfungen der Jahrespflege werden nicht als Abnahme aller Menüpunkte
und Rollen dargestellt. Keine Teilabnahme aktiviert die Bilanz in Kulturhus.
