# Ursprüngliche Mitglieds- und Beitragsmails fortsetzen

Bekannte lokale Mailfehler erhalten die vorbereitete manuelle Konto-Zugangsmail
und die automatische Willkommensmail. Konto, Benutzername, einmaliger
Einrichtungslink und verschlüsselte ursprüngliche Einladung werden nicht neu
angelegt. Nach Postfachkorrektur läuft derselbe Auftrag einmal weiter.

Die Beitragsrechnung bleibt ein eigener Versand. Ein eindeutig ungesendeter
Rechnungsauftrag wartet nach bestätigter Willkommensmail weiter; die Begrüßung
wird dabei nicht wiederholt. Auch ein nicht übergebener eigener Rechnungssender
gilt als fehlende Konfiguration. Bereits gespeichertes „gesendet“ bleibt bei
verlorener MySQL-Schreibbestätigung erhalten; kein Überschreiben als „unklar“.

Ungültige ungesendete Nachrichten werden angehalten. Für manuelle Zugangsmails
wird die vorhandene Datenbankregel genutzt: Versand deaktiviert und Prüfung
erforderlich; die Oberfläche zeigt „Nachricht prüfen“. Es wird kein unzulässiger
neuer Status eingeführt. Unklare begonnene Versuche werden nicht automatisch
wiederholt. Aufnahme-, Mitgliedschafts-, Finanz- und Original-PDF-Daten bleiben
unverändert. Keine Schemaänderung.

15 neue Fach-/Originalprüfungen grün: ursprüngliche manuelle Einladung, tatsächliche
Vorstandsaufnahme, getrennte Willkommensmail/Beitragsrechnung, lokale Vormerkung,
einmalige Fortsetzung und wirklich gespeichertes Rechnungs-UPDATE mit verlorener
Bestätigung. Private Einladung, Token, Konten, Mitgliedschaft, Buchungen und
Original-PDF verglichen. Vier native Gruppen einschließlich neuer Prüfung,
bestehender Mitgliedskonten, ursprünglicher Jobs und Antragskatalog grün.
Antragsverwaltung erneut mit 66 Browserprüfungen, 16 mobilen Schriftansichten,
zwei tatsächlichen Formular-POSTs und 503 Originalanträgen geprüft.
Drei ursprüngliche Willkommen-/Kontoanlage-/Provisionierungsgruppen grün.
Keine reale Mail, Veröffentlichung oder Zahlung.
