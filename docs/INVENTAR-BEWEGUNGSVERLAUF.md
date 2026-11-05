# Vollständiger Bewegungsverlauf im Inventar

„Bewegungsverlauf ansehen“ am eigenen Gegenstand zeigt sechs Originale pro
Seite und zählt sämtliche passenden Bewegungen. Direkte Seitenwahl sowie
vorherige/nächste Seite erreichen auch ältere Einträge jenseits der früheren
1.000-Zeilen-Grenze. Der Filter enthält eigene aktive und archivierte Lagerorte.
Ein gewechselter Lagerort öffnet die erste passende Seite; der Gegenstand
bleibt ausgewählt. Die normale Gegenstandsansicht beginnt mit geschlossenem
Verlauf. Gewählte Seiten und Filter öffnen ihn unmittelbar.

Kurze Karten zeigen den ursprünglichen Vorgang, Europe/Berlin-Zeit, Lagerort,
vier Mengenänderungen, menschlichen Vermerk und die bearbeitende Person.
Vorhandene Finanz- und Handkassenangaben bleiben an die ursprünglichen Rechte
gebunden. Ausgeblendete Preisdaten werden weiterhin auf jeder Tiefe aus den
Verlaufsangaben entfernt. Keine Historie oder Buchung wird umgeschrieben.

„CSV exportieren“ enthält jetzt alle Bewegungen des ausgewählten Gegenstands
und Lagerorts. Der Abruf erfolgt in höchstens 200 Zeilen je Abschnitt über die
originale absteigende Sequenz; die frühere stillschweigende Grenze entfällt.
Bestehende Spalten, UTC-Zeitangaben, UTF-8-Kennung, Trennzeichen und Schutz vor
Formeltext bleiben erhalten. Der Export wird wie zuvor als eigenes erzeugtes
Dokument abgelegt; reine Inventar-Leserechte erlauben den Download. Der
bestehende unabhängige Dienst `movements()` behält seine bisherige Grenze für
andere Aufrufer. Die echte Oberfläche und der vollständige Export verwenden
die neuen Wege. Die Export-API liefert weiterhin einen vollständigen CSV-String;
dies ist keine allgemeine Hochlast- oder Streaming-Download-Abnahme.

Lokal geprüft: 1.003 tatsächliche Lagerbewegungen, alle genau einmal auf 168
Seiten. Sechs Originalangaben pro Seite stimmen vollständig mit dem bisherigen
Dienst überein. Ganze Zählung, Lagerort-/Vereinsgrenzen, ungültige Seiten und
aktueller Rechteentzug geprüft. 19 Fachprüfungen, eine ganze UI-Originalprüfung
und 88 Browserprüfungen, Verwaltung und reine Leserechte, mit und ohne
JavaScript; vier vollständige Downloads und 24 Ansichten bei 320/390/820 Pixeln
mit einfacher/doppelter Schrift. Alle ursprünglichen Bestands-, Bewegungs-,
Historien- und Finanzzeilen bleiben bei der Browserbedienung unverändert.

Die neue native Suite und sieben ursprüngliche Gruppen bestehen:
`inventory.php`, `inventory-purchase-units.php`, `inventory-unit-correction.php`,
`inventory-archive.php`, `inventory-deletion.php`, `inventory-initial-stock.php`
und `inventory-loan-contents.php`. Keine Schemaänderung, externe Nachricht,
Veröffentlichung oder Zahlung. Quellinventar und Offline-Paket werden vor
Commit und Push geprüft; keine neuen GitHub-Actions oder Releases.
