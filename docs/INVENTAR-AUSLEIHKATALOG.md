# Vollständige kurze Ausleihübersicht

Inventar → Ausleihen zeigt sechs ganze gespeicherte Vorgänge je Seite.
Ausleihnummer, Entleiher, Einsatz, Status, Ausgabe-/Rückgabedatum und noch offene
Mengen behalten ihre ursprüngliche Bedeutung. Direkte Seitenwahl und
Vorherige/Nächste erreichen auch früheste Vorgänge. Nummern-/Namens-/Einsatzsuche,
Kontakt, Status, offene und überfällige Ausleihen verwenden dieselben SQL-Regeln
wie bisher. Gehaltene, ausgegebene und stornierte Mengen werden nicht umgedeutet.

Originalansicht, ganze Vereinbarung, Positionen und ursprünglicher Verlauf
bleiben erreichbar. Der bestehende Rücklink führt zur gewählten Seite und
Auswahl zurück; erfolgreiche bestehende Ausleihaktionen erhalten ebenfalls
diesen Listenkontext. Die Ansicht funktioniert mit und ohne JavaScript,
auf Handy-/Tabletbreiten und mit doppelter Schrift.

`loansPage()` zählt die vollständige passende Auswahl in MySQL und lädt genau
sechs ganze Originale im selben Lesestand. Die frühere vollständige `loans()`-
API bleibt für Kalender, Auswertungen und CSV erhalten. Beide benutzen
gemeinsame Fachfilter, Reihenfolge und die korrekte Gruppierung über Verein,
Vorgang und zugehörigen eindeutigen Kontaktnamen. Aktuelle Vereins- und
Ausleihrechte werden vor Ausgabe erneut geprüft. Keine Schemaänderung.

Abnahme: 44 Fach-/Originalprüfungen einschließlich 19 bestehender
Bewegungsverträge, 81 Browserprüfungen, 24 Handy-/Tablet-Schriftansichten,
admin und Inventarleser mit/ohne JavaScript, null POSTs. 503 tatsächliche
Vorgänge auf 84 Seiten, jeder genau einmal; sechs bzw. fünf gewählte vollständige
Zeilen und zehn Filterkombinationen exakt mit der früheren API verglichen.
Tatsächlich reservierter, ausgegebener, überfälliger und stornierter Bestand;
ganze Vereinbarungen und Stornogründe im echten Original geprüft.
Der erste synthetische Vereinbarungstext überschritt die bestehende Textgrenze;
nach Einhaltung des unveränderten Fachlimits ist die gesamte Gruppe grün.

Sechs betroffene native Gruppen zusammengenommen grün, darunter 81
Gegenstandskatalog- und 88 Bewegungsverlauf-Browserprüfungen sowie die
gemeinsamen HSE-Dokumentverträge. Vier ursprüngliche Inventar-/Ausleihinhalt-/
Kalender-/Anfangsbestandsgruppen grün. Ganze ursprüngliche Vorgänge,
Positions-Snapshots, Bestände, Bewegungen, Verläufe und Finanzzeilen bleiben
beim Lesen unverändert. Kein neuer Bestand, keine Nachricht oder Zahlung.
Andere Aufenthalts-/Lagerortansichten und weitere vollständige Fach-/UI-Abnahme
bleiben gesondert offen; kein vollständiger neuer 210-Suiten-Lauf behauptet.
