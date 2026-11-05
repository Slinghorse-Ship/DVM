# Ursprüngliche Veranstaltungseinladungen fortsetzen

Ein bekannter lokaler Fehler vor SMTP hält die Veranstaltungseinladung
vorgemerkt. Empfänger, menschlicher Betreff/Text, Originaltermin, Inhaltskennung
und Formularschlüssel bleiben erhalten. Nach Behebung wird dieselbe Einladung
einmal fortgesetzt. Ungültige ungesendete Nachrichten verwenden den vorhandenen
gestoppten Zustand; unklare begonnene Versuche werden nicht wiederholt.

Ein wirklich gespeicherter Versandabschluss wird bei verlorener MySQL-
Schreibbestätigung gelesen und unverändert erhalten. Die gleiche ursprüngliche
Kombination aus Verein, Termin, Inhalt und Empfänger ist erforderlich.
Kein zweiter Transport zum Ermitteln eines Ergebnisses und kein Umschreiben
eines bestätigten Erfolgs. Vor jeder Fortsetzung gelten die bisherigen aktuellen
Absenderrechte sowie Termin-/Empfängerprüfungen.

10 neue Fach-/Originalprüfungen grün: lokale Vormerkung, wirkliche Fortsetzung,
akzeptiertes UPDATE mit verlorener Bestätigung, unveränderte abgeschlossene
Originale, ungültige Nachricht, unklarer Transport ohne Wiederholung und echte
Absendersperre vor dem Versand. Kontakte und ursprüngliche Einladungstexte
bytegleich verglichen. Drei native Gruppen einschließlich ursprünglicher Jobs
und persönlicher Planung grün; ursprüngliche Kalender-Einladungsgruppe grün.
Keine Schemaänderung, keine reale Mail oder Veröffentlichung.
