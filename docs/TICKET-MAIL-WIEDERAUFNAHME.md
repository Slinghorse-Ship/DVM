# Originale Ticket- und Gutscheinmails fortsetzen

Bekannte Fehler vor Beginn von SMTP halten die ursprüngliche Bestätigung
vorgemerkt. Nach Postfachkorrektur wird dieselbe Bestellung einmal zugestellt.
Ungültige lokale Nachrichten werden gestoppt. Unklare begonnene SMTP-Versuche
bleiben gegen automatische Wiederholung gesperrt.

Ein tatsächlich gespeicherter „gesendet“-Status wird bei verlorener
Datenbankbestätigung jetzt gelesen und unverändert erhalten. Bisher konnte die
Ticketzustellung diesen erfolgreichen Status als „unklar“ überschreiben.
Nur derselbe Verein und dieselbe ursprüngliche Bestellung werden geprüft;
kein zweiter Transport zum Ermitteln des Ergebnisses. Der bestehende gemeinsame
MySQL-Lock schützt gegen gleichzeitigen Versand oder Änderungen der Bestellung.

Bezahlstatus, Eintritts-QR, Gutschein-QR, Restguthaben, Bestellhistorie und
Finanzbuchungen bleiben erhalten. Die Mail enthält weiterhin den privaten Link
zum aktuellen gemeinsamen Bestellstand, keine dauerhaft gültigen QR-Anhänge.
Die Hintergrundlauf-Historie speichert begrenzte `held`-Zähler auch für Konto-
und Sicherheitsmails, ausschließlich Zahlen und keine privaten Mailinhalte.

11 neue Fach-/Originalprüfungen grün: tatsächlich kanonisch bestätigte lokale
gemischte Zahlungen, fehlendes gemeinsames Postfach, einmalige Fortsetzung,
wirklich akzeptiertes MySQL-UPDATE mit verlorener Bestätigung, unveränderte
gesendete Originale, ungültige Nachricht und unklarer Transport ohne Wiederholung.
Sämtliche vorherigen Zahlungs-, QR-, Gutschein-, Historien- und Finanzzeilen
bytegleich verglichen. Sieben betroffene native Gruppen einschließlich der neuen
Prüfung, bestehender Ticketzustellung, Zahlungen, Kasse/Gutscheinen, Erstattung,
ursprünglicher Jobs und Veranstaltungsworker grün. Kassen-/QR-Browser mit 99
Prüfungen und Erstattungsbrowser mit 45 Prüfungen erneut grün.
Keine Schemaänderung, keine reale Zahlung und keine Mail oder Veröffentlichung.
