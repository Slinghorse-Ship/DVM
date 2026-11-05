# Kurze ursprüngliche Vermögensübersicht mit ganzer Wertsumme

Inventar → Vereinsvermögen zeigt sechs vollständige Gegenstände pro Seite.
Eigentum, Verantwortung, gesamte Menge, dokumentierter Stückwert und ursprünglicher
Wert im Vereinsvermögen bleiben erhalten. Die Summe oben umfasst den vollständigen
gefilterten Bestand, nicht nur die gerade sichtbaren sechs Einträge. Direkte
Seitenwahl erreicht sämtliche Gegenstände; berechtigte Originalansicht und
bestehender Rücklink behalten Seite und Vermögensfilter.

Kategorie, Verantwortung und Lagerort verwenden die bisherigen Fachregeln.
Fremde Leihgaben, Handkassen, unbekannte Preise, ausdrücklich erfasste Stückpreise
und gemeinsame Rechnungsbeträge bleiben getrennt. Die volle centgenaue Verteilung
einer Rechnung wird vor der Seitenauswahl berechnet, einschließlich des genauen
Anteils einer sichtbaren Kategorie. Teilbestand oder ausdrückliche Stückpreise
lassen keinen alten vollständigen Rechnungsbetrag doppelt bestehen.

`assetsPage()` liest die vollständigen kleinen Mengen-/Bewertungsfakten und
zugehörige belegte Rechnungsgruppen, danach nur sechs ganze Gegenstandskörper.
Ein eigener MySQL-Lesestand verbindet Summe und Originale. Die bisherige
`assetsOverview()`-API bleibt erhalten. Der strenge MySQL-Modus bleibt aktiv;
die bisher ungültige Gruppierung nur nach Gegenstand ist über Verein,
Gegenstand und eindeutigen Verantwortungsnamen korrigiert. Keine Schemaänderung.
Der kleine Bewertungsbestand bleibt im PHP-Speicher; keine allgemeine SQL-/
Hochlastabnahme behauptet.

Finanzleserechte reichen wie bisher auch ohne Inventar-Bearbeitungsrecht.
Reine Finanzansichten zeigen keine unberechtigten Inventarlinks. Aktuelle
Finanzrechte und dieselbe Vereinszuordnung werden vor Ausgabe erneut geprüft.
Die Ansicht funktioniert ohne JavaScript sowie auf Handy-/Tabletbreiten mit
doppelter Schrift und mindestens 44 Pixel hohen Bedienelementen.

Abnahme: 59 Fach-/Originalprüfungen einschließlich 40 bestehender
Gegenstands-/Bewegungsverträge, 75 Browserprüfungen, 24 mobile Schriftansichten,
admin und reine Finanzrolle mit/ohne JavaScript, null POSTs. 1.014 Originale
auf 169 Seiten; alle unveränderten ausgewählten ganzen Zeilen, Mengen, Preise,
Filter, unbekannten Werte und gesamte Summe mit der bisherigen API verglichen.
Fünf betroffene native Gruppen zusammengenommen einschließlich 81 bestehender
Gegenstandskatalog-Browserprüfungen grün. Vier ursprüngliche Rechnungs-/Wert-/
Ansichts-/Lebenszyklus-/Kontierungsgruppen grün; der Rechnungswerttest ist zusätzlich
um vier direkte Verträge für neue kurze Seiten und ganze centgenaue gemeinsame
Werte erweitert und erneut grün. Keine Preise, Buchungen oder Bestände geändert.
Übrige Aufenthalts-/Lagerort-/Fach-/UI-Varianten bleiben gesondert offen.
