# Videospeicher und Aufbewahrungsregeln

Im gespeicherten Social-Beitrag **Videofassungen → Videospeicher** öffnen.
Die Anzeige nennt die genaue Anzahl eigener Quellen, belegten Speicher und den
verbleibenden Platz. Mit Verwaltungsrechten lässt sich die Grenze als ganze Zahl
von 0 bis 102400 MiB speichern. **0** bedeutet unbegrenzt. Beschriftungen und
Bedienhilfen folgen der persönlichen Sprache Deutsch oder Englisch.

Gezählt werden unveränderliche Quelldateien. Eine neue Freigabefassung derselben
Quelle belegt keine zusätzlichen Bytes. Eine kleinere Grenze entfernt keine
vorhandene Quelle; sie kann weitere Uploads stoppen. Erst das vollständig
bestätigte endgültige Löschen gibt die Quotenbytes einer Quelle frei.

Wird eine MP4 wegen der Grenze abgelehnt, bleiben die ausgewählte Datei und ihr
ursprünglicher Vorgang erhalten. Die Speichergrenze lässt sich im selben Beitrag
erhöhen. Anschließend **Ursprüngliche Videofassung erneut speichern** wählen. Die
Wiederholung verwendet dieselben ursprünglichen Bytes. Beitragstext und weitere
Bearbeitungen bleiben geöffnet. Die Speicheranzeige wird nach der bestätigten
Regeländerung und nach einem bestätigten Upload aktualisiert.

**Videoaufbewahrung** bestimmt die Mindesttage für künftige Entfernungen,
standardmäßig 30, wählbar 0–3650. Eine Änderung verkürzt keine bereits gespeicherte
Frist. Entfernen, Zurückholen und endgültiges Löschen sind eigene bewusste
Aktionen im [Video-Papierkorb](SOCIAL-VIDEO-PAPIERKORB.md).

Nach einer unklaren Speicherantwort **Gespeicherte Regel prüfen** wählen.
Die Prüfung liest nur den eigenen ursprünglichen Vorgang. **Ursprüngliche Regel
erneut speichern** wiederholt genau dessen ursprünglichen Zahlenwert und dessen
Version. Wiederholungen legen keine zweite Regelversion an. Während eine
Speicherung läuft, dürfen weitere Zahlen eingegeben werden: Die Bestätigung des
ersten Vorgangs bestätigt dessen gespeicherten Wert; spätere Eingaben bleiben
als Entwurf erhalten. Eine weitere Änderung muss bewusst gespeichert werden.

Ohne JavaScript öffnen Speicherung und Prüfung ein eigenes Fenster; der offene
Beitrag bleibt im ursprünglichen Fenster. Fehler erhalten den eingegebenen Wert,
die ursprüngliche Version und den Vorgang. Nach einer positiven reinen Prüfung
**Weitere Änderung vorbereiten** wählen, um die erhaltenen weiteren Eingaben mit
der zuvor bestätigten Version erneut bewusst zu speichern. Eine inzwischen von
jemand anderem geänderte Regel wird dabei nicht automatisch übernommen.

Speichern benötigt aktuelle Verwaltungsrechte. Eigene ursprüngliche Ergebnisse
lassen sich mit weiterhin vorhandenen Leserechten prüfen. Das gewährt keinen
Zugriff auf die aktuelle geschützte Aufbewahrungsregel. Wiederhergestellte Rechte
werden durch einen neuen Prüfabruf erkannt; ungespeicherte Eingaben bleiben
lokal erhalten. Ein Vereinswechsel verhindert die Ausgabe früherer Vereinsdaten.

Die tatsächliche Dateigröße wird vor Abschluss jedes Uploads gegen die aktuelle
Grenze geprüft. Dieselbe Vereinsperre verbindet Regeländerung, Bestand und neue
Quelle. Parallele Uploads können den Rest nicht gemeinsam überschreiten. Regel,
unveränderliche Geschichte und persönlicher Speichernachweis werden zusammen
angenommen. Verlorene COMMIT-/HTTP-Bestätigungen und Prozessabbrüche lassen sich
anhand des ursprünglichen Nachweises auflösen.

Die vollständige Erstinstallation enthält alle benötigten Tabellen automatisch.
Es gibt keinen gesonderten Migrationsdownload und keine Schemaänderung bei
normalen Fachaufrufen. Die aktuellen Schemafassungen bleiben unverändert.

Die isolierten Fach- und Browserprüfungen umfassen echte AVC-/AAC-Dateien,
Originalbytes, parallele PHP-Schreiber, SQL-Abbrüche, verlorene Bestätigungen,
Rechteänderungen, DE/EN, beide Manager-Zugänge und Bedienung ohne JavaScript.
Telefon-/Tabletbreiten, vergrößerte Schrift und Tastaturbedienung werden geprüft.
Weitere Video-Verwaltungsoberflächen und tatsächliche Geräte-/Anbieterabnahmen
bleiben eigene Registerpunkte.
