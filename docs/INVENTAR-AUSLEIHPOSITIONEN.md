# Ausleihen mit mehreren Positionen vorbereiten

„Weitere Position“ und „Entfernen“ funktionieren mit und ohne JavaScript.
Ohne JavaScript wird das eingegebene Formular erneut angezeigt. Kontakt,
Verwendungszweck, Termine, Vereinbarung, Bestandsauswahl und Mengen bleiben
erhalten. Das Vorbereiten reserviert keinen Bestand und erzeugt keine Ausleihe.
Erst „Ausleihe reservieren“ bestätigt die vollständige Reservierung.

Der ursprüngliche Vorgangsschlüssel und die ausgewählten Bestandsversionen
bleiben beim Vorbereiten und bei Fehlerkorrekturen erhalten. Eine veraltete
Auswahl bleibt erkennbar; die Bestandsversion wird nicht unbemerkt erneuert.
Die tatsächliche Bestandsprüfung erfolgt beim Bestätigen. Der bestehende
Schutz gegen doppelte Reservierungen gilt auch bei wiederholten HTTP-Anfragen.

Eine bis höchstens 50 Positionen sind erlaubt; die letzte Position bleibt
erhalten. Entfernen betrifft nur die bewusst gewählte Position. Fremde oder
malformierte Entwurfsziele und fehlende aktuelle Ausleih-Schreibrechte werden
abgewiesen. Alle Seiten verwenden die bestehenden CSRF- und Kontoprüfungen.
Keine Schemaänderung und keine externen Vorgänge.

Abnahme: 33 Fach-/Originalprüfungen inklusive 19 bestehender Bewegungsverträge,
38 tatsächliche Browserprüfungen, zwölf Handy-/Tabletansichten bei 320, 390 und
820 Pixeln mit normaler und doppelter Schrift. Mit und ohne JavaScript: mehrere
Positionen hinzufügen, leere Position entfernen, tatsächliche ungültige
Rückgabefrist berichtigen und beide Originale bewusst reservieren. Neun echte
POSTs einschließlich zwei HTTP-Wiederholungen: genau zwei Ausleihen, vier
Originalpositionen und nur bestätigte Mengen. Kontakte, Orte und Finanzzeilen
unverändert. Drei ursprüngliche Inventar-/Ausleihinhalt-/Formulargruppen grün.
