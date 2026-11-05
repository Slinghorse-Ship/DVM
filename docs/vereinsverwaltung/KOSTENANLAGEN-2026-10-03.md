# Grundstücke und Finanzanlagen: belegter Bewertungsverlauf

Stand 03.10.2026, aufbauend auf `3f49c68`. Kulturhus bleibt EÜR. Der neue
Bewertungsweg ist keine allgemeine Bilanz- oder Übermittlungsfreigabe.

Die Originalausgaben SKR42 2025 und 2026 wurden für `02150`, `02350`, `09000`
und `09200` sowie die getrennten Bewertungsgegenkonten überprüft. Unbebaute
Grundstücke und Grundstückswerte unter eigenen Gebäuden bleiben von Gebäuden,
Rechten und Substanzverzehr getrennt; allgemeine und festverzinsliche Wertpapiere
bleiben von Beteiligungen und Darlehen getrennt. Die Quellprüfung in
`resources/accounting/reviews/cost-assets.json` erweitert ausschließlich die
Anlagenbewertung, nicht die freigegebenen XBRL-Kontenzwecke der Revision 19.

Die unveränderte Anschaffungsrechnung, ihr Dokument und die tatsächlichen
Dokumentbytes bilden den Ausgangspunkt. Bestätigte, datierte Wertminderungen,
Wertaufholungen und mehrere belegte Teilabgänge ohne Erlös bilden einen
unveränderten Bewertungsverlauf. Buch- und Steuerwerte bleiben getrennt.
Eine vollständige Ausbuchung entfernt Anschaffungskosten und beide Restwerte;
eine Wertaufholung kann die verbleibenden ursprünglichen Kosten nicht überschreiten.
Für nicht planmäßig abzuschreibende Anlagen wird keine Nutzungsdauer erfunden.

Jahresabschluss, beliebige zulässige Tagesgrenzen eines Abschlusszeitraums,
Periodenberichtigung mit erhaltenem Original und Storno sowie Übergänge vom
Kalenderabschluss in einen Folgezeitraum sind umgesetzt. Die steuerliche
Überleitung berechnet die belegten Unterschiede und übernimmt den ursprünglichen
Steuerwert einmal. Sie verändert weder Anschaffungsplan noch Buchjournal.
Geführte Formulare und CSV-Arbeitsunterlagen enthalten beide Wertseiten und
die tatsächlichen Dokumentidentitäten. Lesen und Export benötigen keine Schreibsperre.

Der eingefrorene MySQL-Lauf `56c23c66ef70f8b3` besteht mit neun ausgewählten
Programmen. Die drei neuen vollständigen Abläufe bestehen 26 Jahres-, 20 Perioden-
und acht Kalender-Perioden-Prüfungen. Mitgeprüft wurden bestehende Steuer-,
Gesamtvereins-, Kalenderbrücken-, Nutzungsaufteilungs- und Laufzeitbindungen.
Produktivdateien sind unverändert, die temporäre Kopie ist entfernt, es wurde
keine Produktivdatenbank verbunden und keine zusätzliche Sicherung erstellt.
Die vorherigen fehlgeschlagenen Berichte bleiben unverändert fehlgeschlagen.

`audit/bilanz-cost-runtime-check-20261003.json` besteht mit zwölf lokalen
Programmen auf einem unveränderten Quellstand. Darin besteht der bisherige
Taxonomie-6.9-Export mit 22 positiven ERiC-Fällen; ein absichtlich verändertes
XML wird mit `610001002` abgewiesen. Nur Prüfmodus `flags=2`, kein Zertifikat
und kein Versand. Diese XML-Fälle geben die neuen Kostenanlagen nicht frei.

Offen bleiben die XML-Abbildung des neuen Bewertungsverlaufs einschließlich
amtlicher Zuschreibungsspalten, seine geprüfte gemeinsame Nutzungsaufteilung,
die Berichtigung gespeicherter Jahresbewertungen und zusätzliche Anlagenfamilien.
Gebäude, Firmenwert, Sammelposten, Darlehen und Beteiligungsfälle sind durch
diese vier Konten nicht implementiert. Die vollständige allgemeine Bilanzabnahme
und anschließende Gesamt-UI-Prüfung bleiben erforderlich.
