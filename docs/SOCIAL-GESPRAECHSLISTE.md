# Begrenzte private Gesprächsliste

Die Facebook-/Instagram-Nachrichtenliste zeigt sechs Zusammenfassungen pro Seite,
sortiert nach der letzten empfangenen Nachricht und eigener Kennung. „Weitere
Gespräche“, Suche und Plattformwahl funktionieren auch ohne JavaScript. Auf dem
Handy bleiben Liste und Verlauf getrennte Ansichten; direkte Links öffnen das
genaue ursprüngliche Gespräch unabhängig von der angezeigten Listenseite.

Gespeicherte native Gespräche bleiben auch nach 30 Tagen erreichbar. Der aktuelle
Anbieterabruf verwendet weiterhin sein begrenztes Zeitfenster, entfernt aber keine
älteren eigenen Nachrichten. Bei bearbeiteten Nachrichten bleibt die vorige genaue
Fassung verschlüsselt erhalten. Ein Kontowechsel erhält den vorherigen gebundenen
Verlauf separat und lenkt bestehende Versandaufträge nicht auf ein anderes Konto.
Die Grenzen von 5000 Nachrichten, 100 Bearbeitungen je Nachricht und 100 früheren
Verbindungen stoppen die Aktualisierung, bevor ein Original verworfen würde.

Die Liste entschlüsselt jeweils nur ein Original und behält höchstens sechs
Zusammenfassungen. Suche prüft alle gespeicherten Gespräche anhand der Namen,
aller aktuell lesbaren empfangenen/gesendeten Texte, originalgebundener sichtbarer
Mailantworten und Plattform; sie lädt keine vollständigen Verläufe
gemeinsam in den Speicher. Wegen der Verschlüsselung wird der Bestand dabei
schrittweise gelesen. Ein Volltextindex und eine Hochlastabnahme sind weitere
Arbeit. Der ausgewählte Verlauf verwendet weiterhin 40 ursprüngliche Nachrichten
pro Seite. Historische Fassungen werden erhalten; ein eigener Historieneditor
ist damit nicht eingeführt.
Fundstellen öffnen ihre tatsächliche aktuelle 40er-Seite mit Fokus und genauer
Texthervorhebung; [Bedienung und Grenzen](SOCIAL-GESPRAECHSSUCHE.md).

Früherer Nachweis: 55 MySQL-/Dienstverträge mit 61 Gesprächen, über 10 MB authentifiziert
verschlüsseltem synthetischem Nachrichtenbestand und einem tatsächlichen
16-MB-PHP-Prozess. 77 Browserprüfungen mit 55 gespeicherten Gesprächen bei
1440/820/390/320 Pixeln, JavaScript-freier Suche/Seitenwahl, Touch, Tastatur,
200 % Zoom, tiefen Links und anschließendem bytegleichem Originalvergleich.
Keine Fachspeicherung oder externe Aktion im Browser. Die bestehenden 112
direkten Mail- und 413 Chat-Browserprüfungen bestehen weiter.

Kommentare und private Nachrichten verwenden auch für noch nicht abgeglichene
Mailantworten dieselben 40er-Verlaufsseiten. Alle gespeicherten Antworten der
aktuellen Originalbindung werden über begrenzte Metadaten berücksichtigt; nur
die gewählten Mails werden entschlüsselt. Bereits im Anbieteroriginal enthaltene
bestätigte Antworten erscheinen einmal. Bis zu 5000 ursprüngliche Nachrichten
und 5000 Mailantworten ergeben höchstens 250 Seiten; eine überschrittene Grenze
verwirft keine gespeicherten Originale. Der Gesprächszeitpunkt wird aus den
erhaltenen aktuellen Originalen bestimmt und nicht durch einen älteren
Teilanbieterabruf zurückgesetzt.

132 tatsächliche Browserprüfungen mit je 70 ursprünglichen Mailantworten in
Kommentar- und privatem Nachrichtengespräch bei 1440/820/390/320 Pixeln, Touch,
Tastatur, 200 % Zoom und ohne JavaScript bestehen. Die 55 Dienstverträge prüfen
zusätzlich gesamte Mailhistorie, Mikrosekundenreihenfolge, Original-/Kontobindung,
bestätigte Dubletten und einen tatsächlichen 16-MB-Prozess bei mehr als 14 MB
Original-MIME. Der gemeinsame Datenbank-Bytevergleich der Browserhelfer liest
unbuffered jeweils eine Zeile; ein einzelnes verändertes Byte im Originalciphertext
jenseits der aktuellen Seite verändert den Nachweis. Anschließender Rollback
erhält das genaue Original. Keine echte Mail oder Anbieteraktion.
