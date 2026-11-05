# Mitgliedsantrag: Benachrichtigung bei lokaler Mailstörung

Ein neuer Antrag erzeugt weiterhin genau einen Hinweis pro eigener
Vorstandsadresse. Fehlt das konfigurierte Postfach oder ist der Versand vor
SMTP deaktiviert, bleibt derselbe Hinweis vorgemerkt. Nach Behebung wird er
mit derselben Antragskennung und Empfängeradresse einmal versendet. Vor jeder
Fortsetzung werden offener Antrag und aktuelle Vorstandszuordnung geprüft.

Eine lokal ungültige Nachricht wird mit dem vorhandenen Zustand `skipped`
angehalten. Ein tatsächlich begonnener Versand mit unbekanntem Ergebnis bleibt
`uncertain` und wird nicht automatisch wiederholt. Tatsächlich gespeichertes
`sent` mit Versandzeitpunkt wird bei verlorenem UPDATE-Acknowledgement am
ursprünglichen Vereins-/Antrags-/Adressschlüssel und Erstellzeitpunkt erkannt.
Die Fehlerbehandlung überschreibt ausschließlich noch `sending`.

Zehn neue Prüfungen verwenden tatsächliche eigene Anträge, bestätigte
Vorstandszuordnung und einen realen PDO-Schreibvorgang mit anschließendem
Bestätigungsverlust. Ganze Antrags-/Personen-/Vorstands-/Auditzeilen werden
unverändert verglichen. Die ursprüngliche Benachrichtigungsgruppe und native
Original-Job-Gruppe mit 73 Prüfungen bestehen ebenfalls. Ausschließlich lokale
Capturing-Callbacks; keine echte Mail und keine Schemaänderung.
