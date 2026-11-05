# Kompakte vollständige Kalender-Teilnehmerauswahl

Interne Veranstaltungen, externe Termine, Mitgliederversammlungen,
Vorstandssitzungen und sonstige Tätigkeiten zeigen sechs passende eigene Personen
je Seite. Die Suche behandelt Sonderzeichen wörtlich. Direktes Öffnen einer Seite
und Vor-/Zurückblättern erhalten Titel, Zeiten, Ort, Beschreibung, Bericht,
Quellenlink, ursprüngliche Terminversion und Auswahl über alle Seiten.

„Diese Seite auswählen“ ergänzt nur die angezeigten Treffer. „Alle verfügbaren
Teilnehmer auswählen“ übernimmt bewusst die vollständige zulässige Gruppe,
unabhängig von der aktuellen Suchseite. „Gesamte Auswahl aufheben“ leert sie.
„Nur ausgewählte Personen anzeigen“ macht auch frühere ausgewählte Personen
wieder auffindbar. Eine JavaScript-freie Bedienung verwendet dieselben Formulare.
Mit JavaScript aktualisiert sich der Auswahlzähler unmittelbar beim Ankreuzen.

Mitgliederversammlungen bieten ausschließlich aktuelle aktive und passive
Mitglieder an. Vorstandssitzungen bieten ausschließlich die vier aktuell
bestätigten Ämter an. Die ursprünglichen Speicherregeln prüfen die Zulässigkeit
noch einmal. Ein Wechsel der Terminart setzt die frühere Gruppenauswahl zurück;
eigene Titel und weitere Terminangaben bleiben erhalten. Ohne JavaScript dient
„Teilnehmer aktualisieren“ demselben Zweck. Ein unmittelbarer Speicherversuch nach
einem nicht geprüften Typwechsel verlangt ausdrücklich erneute Prüfung.

Die Auswahl liegt in einem einzigen JSON-Formularfeld; höchstens sechs sichtbare
Kontrollkästchen kommen hinzu. Damit endet die Erreichbarkeit nicht mehr beim
1.000. Kontakt, und die Auswahl scheitert nicht an PHPs Grenze für einzelne
Formularfelder. Der ursprüngliche Terminspeicher akzeptiert jetzt bis zu 10.000
Teilnehmer. Darüber wird ausdrücklich eine Aufteilung verlangt; keine stille
Abschneidung. Die Gruppenauswahl liest ausschließlich Kennungen, die Seiten lesen
nur sechs Namen/Kennungen/Versionen. Zugangsrechte werden nach dem Lesen erneut
geprüft. Keine zusätzliche Tabelle oder Freigabe und kein Mailversand beim
Suchen, Auswählen oder Speichern des Termins.

## Abnahme

Die eigene synthetische Datenbank enthält 1.003 aktive Kontakte mit umfangreichen
privaten Notizen, tatsächlich vorhandene fremde und archivierte Kontakte sowie
eigene bestätigte Vorstandsämter. Ein tatsächlich
aufgenommener Termin enthält alle 1.003 unveränderten Kontaktkennungen und bleibt
während der weiteren Browserbedienung als ursprünglicher Datensatz erhalten.
Weitere mit und ohne JavaScript tatsächlich gespeicherte Termine enthalten genau
die bewusst auf verschiedenen Seiten gewählten Personen. Abgewiesene Termine
behalten Eingaben und Auswahl. Ein konkurrierendes oder nicht mehr zulässiges
Original bleibt durch die bestehenden Versions- und Berechtigungsprüfungen
geschützt.

Lesende Browseraktionen werden mit einem vollständigen Hash aller vorhandenen
Geschäftszeilen verglichen; ausgenommen sind ausschließlich die drei eigenen
Test-Anmeldesitzungen. Bewusste Terminaufnahmen verändern keine Kontaktprofile,
Mitgliedschaften, Zahlungen, Aufteilungen oder Buchungspositionen. Die private
HTTP-Laufzeit verwendet 64 MiB; daraus folgt keine Aussage über einen gesamten
Controller unter 16 MiB. Schriftansichten bei 320/390/820 Pixeln und 100/200 Prozent
bleiben ohne horizontales Scrollen; Schaltflächen und ankreuzbare Beschriftungen
haben mindestens 44 Pixel Touchhöhe. Lese- und reine Finanzrollen erhalten keine
Terminbearbeitung.

Die fünf betroffenen nativen Fachgruppen und acht ursprünglichen Kalendergruppen
bestehen. Die frühere manuelle Kalender-Browserbegleitung wurde auf dieselbe
serverseitige Bedienung umgestellt; ihre Syntax ist geprüft. Die tatsächliche
integrierte Browserabnahme läuft über `native-calendar-people`.

30 Fachprüfungen und 90 tatsächliche Browserprüfungen bestehen: 46 lesende und
44 speichernde Prüfungen, zusammen 33 POSTs und 24 mobile Schriftansichten. Die
JavaScript-Variante weist den ungültigen Zeitraum schon lokal ab; ohne JavaScript
ist die Ablehnung eine tatsächliche Serveraktion. Die Kontaktauswahl für Tarife
und Geld-/Sachspenden besteht separat erneut mit 197 Fach- und 54 Browserprüfungen.
Die getrennte Empfängerwahl für Einladungen und Sammeldruck bleibt das nächste
Feature; daraus wird kein Abschluss des gesamten Kalenders oder Produkts abgeleitet.
