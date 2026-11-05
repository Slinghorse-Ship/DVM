# Begrenzte Protokolle eigener synthetischer MySQL-Instanzen

Die lokalen nativen Tests scheiterten trotz bestandener Fachgruppen an einem
vollen Cloud-Dateisystem. Die ausschließlich synthetische MySQL-Instanz auf
127.0.0.1:13371 hatte 63 Binärprotokolle mit insgesamt 59.782.588.475 Bytes.
Vor der Bereinigung waren ausschließlich System- und `kh_native_`-Datenbanken
vorhanden, ohne Replikate oder eigene Replikationskanäle. Nur abgeschlossene alte
Binärprotokolle wurden entfernt; das aktive Protokoll und sämtliche Tabellen
blieben erhalten. Danach standen rund 28 GB wieder zur Verfügung.

Für diese bereits bestehende eigene Testinstanz sind eine Stunde Aufbewahrung
und eine Zielgröße von 100 MiB je Protokolldatei über MySQLs `SET PERSIST`
gesetzt. MySQL hält dabei zusammenhängende Transaktionen zusammen. Neue eigene
Testcontainer erhalten dieselben Serverargumente in den Actions-, Cloud- und
ursprünglichen Regressionseinrichtungen. Die unveränderte, per Digest fixierte
MySQL-Version, Netzbindung und Schutzregeln für vorhandene Container bleiben
bestehen. Kein Container wurde gelöscht, gestoppt oder neu befüllt.

Die sechs tatsächlichen Startskript-Tests mit eigener Docker-Befehlsnachbildung
prüfen nun auch die genaue Position der Serverargumente nach dem Image. Die
reale laufende Testinstanz bestätigt die Werte; ein frischer synthetischer
`native-shared`-Lauf besteht. Es wurde keine GitHub Action gestartet.

Die wegen Speichermangels abgebrochenen 236-Läufe werden nicht als Abnahme
gezählt. Ein neuer vollständiger Lauf beginnt wieder bei der ersten Gruppe auf
unverändertem `9143a29` in einem vorhandenen Checkout unter `/tmp`. Frühere
Einzelerfolge ersetzen dessen vollständiges Endergebnis nicht.

Vollständige Prüfläufe werden mit ihrem Quellstand und eigenem Protokoll
nachgewiesen. Ein erfolgreicher Einzelbereich ersetzt keinen vollständigen Lauf.
