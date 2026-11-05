# Gespeicherte Lastschriftaufträge vollständig finden

Unter Finanzen → Lastschriften zeigt der Auftragskatalog sechs gespeicherte
Originale je Seite. Direkte Seitenwahl, Suche nach Auftragskennung, Einzugstag
oder Bankreferenz und Statusfilter erreichen auch Aufträge jenseits der früheren
100-Einträge-Grenze. Prozentzeichen und Unterstriche sind gewöhnliche Suchzeichen.

Ein geöffnetes Original behält Suche, Status und Seite im Rückweg. Die Liste
ist während der Einzelansicht eingeklappt. Die Bedienung funktioniert ohne
JavaScript, auf dem Handy und bei doppelter Schriftgröße. Anzeigen, Suchen und
Seitenwechsel erstellen keinen Auftrag und bestätigen keine Zahlung.

Der Dienst prüft bei jedem Zugriff aktuelle Zahlungsrechte und den eigenen
Verein. Er zählt sämtliche passenden Aufträge, lädt genau sechs Zusammenfassungen
und öffnet keine verschlüsselten Bank-/XML-Snapshots. Die ältere `orders()`-Methode
behält ihren bisherigen Vertrag; die echte Oberfläche verwendet `ordersPage()`.
Keine Schemaänderung oder Umschreibung früherer Aufträge.

Lokale Abnahme: 22 Fach-/Originalprüfungen und 41 tatsächliche Browserprüfungen,
103 Originalaufträge auf 18 Seiten, jedes genau einmal, zwölf Handy-/Tablet-
Schriftansichten mit und ohne JavaScript. Fremde Vereine, Rechteentzug, fehlerhafte
Such-/Seitenwerte und leere Ergebnisse geprüft; sämtliche ursprünglichen Aufträge
und Finanzzeilen bleiben unverändert. Der bestehende SEPA-Fachtest sowie die
nativen Einstellungen und Mitgliedsbankdatenprüfungen bestehen ebenfalls.
Keine Bankverbindung, Einreichung oder Zahlung ausgelöst. Die noch auf 500
Rechnungen begrenzte neue Einzugsauswahl ist inzwischen durch einen vollständigen
[kurzen Rechnungskatalog](SEPA-RECHNUNGSAUSWAHL.md) ersetzt.
