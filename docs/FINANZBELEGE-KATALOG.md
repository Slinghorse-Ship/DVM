# Ganze Belegliste und vollständigen CSV-Export bedienen

Finanzen → Belege, Beitragsbelege und Geldspenden zeigen sechs vollständige
Originale je Seite. Suche, Status, Belegart, Datum, Konto und Zahlungsrichtung
verwenden dieselben Fachfilter wie bisher. Sortierung nach Datum, Nummer, Name
oder Betrag erhält auch das bisherige PHP-Verhalten bei Zahlen als Namen,
Groß-/Kleinschreibung und Unicode. Die angezeigten Summen beziehen sich auf
sämtliche gefilterten Belege; Teilzahlungen und Archiv behalten ihre Bedeutung.

Direkte Seitenwahl erreicht Belege jenseits der bisherigen 1.000er-Grenze.
Ein Original wird direkt geladen; Zurück führt zur ursprünglichen Seite,
Suche, Sortierung und Auswahl. Andere Finanzansichten laden keine unbenutzte
vollständige Belegliste mehr. Fehlerhafte Filter und Seiten sind ausdrücklich
korrigierbar und erscheinen nicht als erfundene Nullsummen.

Weitere Filter und Sortierung sind auch ohne JavaScript bedienbar. Mit
JavaScript lassen sich einzelne ursprüngliche Angaben aus-/einblenden;
ohne JavaScript erscheinen alle Angaben und kein unwirksames Auswahlwerkzeug.
Bedienelemente haben mindestens 44 Pixel Höhe; die echte Ansicht ist auf
Handy-/Tabletbreiten und mit doppelter Schrift geprüft.

`entriesPage()` liest einen kleinen vollständigen Index aus Sortier- und
Zahlungsfakten, danach nur sechs vollständige Originale. Ein eigener MySQL-
Lesestand hält Index, Summen und Originale zusammen; bestehende Transaktionen
werden weiterverwendet. Aktuelle Finanzrechte und Vereinszuordnung werden erneut
geprüft. Die frühere `entries()`-API behält ihre bisherige Begrenzung für andere
Aufrufer. Der kleine Index bleibt im PHP-Speicher; keine allgemeine SQL-/
Hochlastabnahme behauptet.

CSV liest nun alle passenden Originale mit einem ungebufferten MySQL-Cursor.
Die ursprünglichen 13 Spalten, Reihenfolge, BOM, Dezimalwerte und Schutz gegen
Formeltext bleiben erhalten. Der ursprüngliche PDO-Modus wird vor weiteren
Abfragen wiederhergestellt. Die Ausgabe wird weiterhin als vollständige
Dateizeichenfolge zurückgegeben; kein beliebig großer Streaming-Download
behauptet. Der echte Download wird wie bisher privat als Dokument abgelegt.

Abnahme: 70 Fach-/Originalprüfungen einschließlich 24 bestehender
Sachspendenverträge; 115 tatsächliche Browserprüfungen, vier komplette
CSV-Downloads und 24 Handy-/Tablet-Schriftansichten mit und ohne JavaScript.
1.010 aktive Originale auf 169 Seiten, jedes genau einmal. Acht Sortierfälle,
alle tatsächlichen Status-/Konto-/Richtungs-/Datumsfilter und vollständige
Summen mit der alten Fach-API verglichen. Aktuelle Rechteentziehung und
Finanzleserechte ohne Mitgliederzugriff geprüft. Ein echter angenommener
Lese-COMMIT mit anschließendem Vereinswechsel auf einer zweiten Verbindung
liefert keine Daten des vorherigen Vereins.

Acht betroffene native Gruppen zusammengenommen grün, einschließlich erneutem
Sachspendenbrowser mit 91 Prüfungen; sieben ursprüngliche Finanz-/Kontierungs-/
Zahlungs-/SEPA-/Navigationsgruppen grün. Originale Belege, Transaktionen,
Zuordnungen, Gegenbuchungen, Positionen und Sachspenden bleiben unverändert.
Keine echte Zahlung, Mail oder Bankeinreichung. Keine Schemaänderung.

Die Finanzquellenauswahl des Inventars und andere gesonderte alte Listen bleiben
weiter abzugleichen. Der vorherige komplette 206-Suiten-Lauf gilt für den dort
dokumentierten unveränderten Stand; kein vollständiger neuer 207-Suiten-Lauf.
