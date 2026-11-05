# Kostenanlagen: gespeicherter Abschluss und XML

Stand 03.10.2026, auf Basis `499441c`, Worktree `eb9a/Kulturhus`, Branch
`layoutauswahl`. Der normale E-Bilanz-Ablauf für die drei nachfolgend belegten
Kostenanlagen funktioniert in der synthetischen MySQL-Umgebung. Dieser Nachweis
ist **keine allgemeine Vereinsbilanz-, Produktiv- oder UI-Freigabe**.

## Umgesetzter Ablauf

`02150` (unbebaute Grundstücke), `02350` (Grundstückswerte eigener Gebäude) und
`09200` (festverzinsliche Wertpapiere) werden aus dem ursprünglichen Kostenplan
und den bestätigten Buch-/Steuerereignissen unabhängig rekonstruiert. Der normale
Generator verarbeitet Jahres- und datierte Abschlüsse, den vollständigen nicht
steuerbegünstigten Verein und gemischte Verzeichnisse mit den bisherigen Anlagen.
Keine Testumschaltung oder Ersetzung des Generators ist dafür erforderlich.

Die ursprünglichen Anschaffungskosten, Wertminderungen, Zuschreibungen und
wiederholten Teil-/Vollabgänge bleiben getrennt erhalten. Buch- und Steuerwerte
werden einschließlich unmittelbarer Vorträge abgestimmt. Der Anlagenspiegel
prüft die amtlichen Spaltengleichungen; Zuschreibungen erscheinen separat und
werden nicht als Währungsdifferenz ausgegeben. Die außerplanmäßige Abschreibung
`62300` verwendet die hierfür geprüfte Position `tan.otherMisc`, nicht die für
lineare/degressive AfA bestimmte Position `tan.other`.

Die Zuordnung ist an den ursprünglichen Bewertungsnachweis und die tatsächlichen
Gegenkonten gebunden. Ein manuell ausgewähltes neues XML-Ziel reicht nicht aus.
Gemeinsame Abgangsnummern bei Kosten- und gewöhnlichen Anlagen werden anhand
der getrennten Originalkonten abgestimmt. Unbenutzte, vollständig bewegungslose
Konten erzeugen keinen künstlichen Gegenstand. Unbelegte aktive Anlagenbewegungen
bleiben gesperrt.

Die gespeicherte Jahresstruktur verwendet den Betrag aus `cost_plan`, ohne ein
zweites Pflichtfeld für dieselben Anschaffungskosten. Bei datierten Korrekturen
werden vollständige, genau verknüpfte Originalstornos aus den Bruttobewegungen
herausgerechnet; die Journalzeilen bleiben erhalten. Unterschiedliche Buch-/
Steuerbewertungen müssen in der Abgabeansicht ausdrücklich geprüft werden und
sind dort mit ihren Ereignissen sichtbar.

## Nachweise

Die [maschinenlesbare Zusammenfassung](KOSTENANLAGEN-XML-2026-10-03.json) bindet
die eingefrorenen Berichte und 1.334 Anwendungsquellen an ihre Prüfsummen.

- 162 Kostenanlagen-XML-Fälle bestehen mit dem installierten nativen ERiC:
  Jahres-/Zeitraumabschlüsse, vollständiger Verein, gemischte Verzeichnisse,
  Wertminderung, Steuerentscheidung, Zuschreibung, wiederholte Abgänge,
  unveränderter Vortrag und vorübergehende Finanzanlagenminderung nur im Buch.
- Die **zwei tatsächlich aus MySQL erzeugten XML-Dateien** für den Jahres- und
  korrigierten Zeitraumabschluss bestehen ebenfalls nativ mit Code `0`.
- Der letzte MySQL-Lauf `06873c6a7b367dcb` besteht mit drei Programmen:
  31 Kostenanlagen-/XML-, 23 Zeitraum-/XML- und 280 Laufzeitfingerprint-Prüfungen.
  Geschlossene Originaljournale bleiben erhalten; Leser und andere Vereine
  erhalten keine Erzeugungsberechtigung.
- Die vier Programme des abschließenden lokalen Bindungslaufs bestehen,
  darunter acht XML-Integrationsprüfungen und die Inventarprüfung. Die bisherigen
  nativen Kontrollfälle bestehen mit 22 Positivfällen und einer erwarteten
  Abweisung (`610001002`).

Alle nativen Aufrufe verwenden ausschließlich Prüf-Flag `2`, ohne Zertifikat,
Drucker oder Versand. Die Datenbankläufe verwenden die dedizierte synthetische
Sekundärdatenbank; keine Produktivverbindung und kein erneutes Backup. Geschützte
Serverdateien bleiben unverändert, temporäre Testkopien sind entfernt. Frühere
fehlgeschlagene Berichte werden nicht überschrieben oder zu einem nachträglich
bestandenen Gesamtlauf umgedeutet.

## Verbleibende Grenzen

`09000` bleibt im XML gesperrt, bis Wertpapierart und steuerlicher Zweck aus
Originalnachweisen geklärt sind. Gemeinsame Kostenanlagenaufteilung,
zusammengesetzte Buch-/Steuerausgabe und weitere Bewertungsfamilien benötigen
ihre eigene Umsetzung und Abnahme. Jahreskorrekturen der Kostenbewertung sind
noch nicht allgemein freigegeben. Die allgemeine Fach-/Übermittlungsabnahme und
die vollständige UI-Prüfung aller Rollen bleiben offen. Kulturhus bleibt EÜR;
es wurde keine produktive Bilanzfunktion aktiviert.

Die Quellen 6.7, 6.8, 6.9 und 6.10 sind getrennt verfügbar. Die native Ausgabe
dieses Pfads bleibt bei **6.9 für 2025/2026**. Die historische Zweckregistry
Revision 19 und die 6.10-Überleitung bleiben unverändert; die neuen Bedingungen
gelten ausschließlich für die belegten Kostenanlagen.

Die [DATEV-Produktseite](https://www.datev.de/web/de/shop/produkt-details/12901)
zeigt am 03.10.2026 weiterhin den SKR42 mit Stand Januar 2026. Die echte
2027-PDF ist im geprüften Jahrespaket nicht vorhanden. Taxonomie 6.10 ist bereits
[amtlich veröffentlicht](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Einkommensteuer/2026-06-08-ebilanz-taxonomien-6-10.html);
eine freigegebene 2027-Ausgabe benötigt weiterhin Originalkontenplan,
Jahresprüfung und eigenen Ausgabe-/ERiC-Nachweis.
