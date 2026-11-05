# Mailfehler vor Beginn des Versands

Die gemeinsame native Zustell-Outbox unterscheidet jetzt lokale Vorprüfungen von einem
bereits begonnenen SMTP-Versuch. Bei gesperrtem Versand oder fehlender
Postfachkonfiguration bleibt der ursprüngliche Outbox-Auftrag vorgemerkt.
Er wird frühestens nach 60 Sekunden erneut geprüft. Empfänger, Nachricht,
DOI-Schlüssel und Deduplizierung bleiben unverändert. Sobald die Voraussetzung
erfüllt ist, verwendet die Zustellung denselben Auftrag statt einer zweiten Mail.

Eine ungültige Nachricht wird vor dem Aufbau von SMTP gestoppt und als
„Nachricht prüfen“ angezeigt. Ihr ursprünglicher privater Inhalt bleibt erhalten.
Fehler nach Beginn von SMTP bleiben dagegen vorsichtig als offenes Ergebnis
behandelt und lösen keine automatische zweite Zustellung aus. Bereits dauerhaft
gespeicherte gesendete Aufträge bleiben wie zuvor gegen Wiederholung geschützt.
Nach erfolgreicher Fortsetzung wird der alte Wartehinweis entfernt.

Newsletter- und Presseansichten zeigen kurze konkrete Statusangaben:
„Versand deaktiviert“, „Postfach einrichten“ oder „Nachricht prüfen“.
Die bekannten Fehler bleiben DomainException-Untertypen; bestehende lokale
Fehlerbehandlung bleibt kompatibel. Die reine `prepare()`-Vorbereitung öffnet
weder SMTP noch eine andere Netzwerkverbindung.

36 Fach-/Originalprüfungen inklusive 24 bestehender Zustellverträge grün.
Tatsächlich fehlende private Postfachkonfiguration, ungültiges MIME,
gesperrter realer Mailpfad und anschließende einmalige lokale Fortsetzung geprüft.
Originales Konfigurations-Chiffrat und Nachricht bleiben erhalten. 125 tatsächliche
Browserprüfungen, 48 mobile Schriftansichten, beide Zugänge, Admin/Lesekonto,
mit und ohne JavaScript; null POSTs und unveränderte Zustellungen, Token,
Empfänger, Quellen und private Einstellungen. Kein SMTP-Testversand.
Keine Schemaänderung und keine reale Anbieterzustellung.

Acht betroffene native Gruppen grün, darunter 159 tatsächliche Newsletter-
Historienprüfungen, 246 Presse-Browserprüfungen und 59 öffentliche Newsletter-
Browserprüfungen. 371 zugehörige DOI-/Abmelde-/HTTP-/Originalprüfungen bestanden.
K-14/K-15 sind im benannten nativen Softwareumfang implementiert. Dies behauptet
keine Einrichtung oder Zustellbarkeit eines echten Postfachs; dafür bleiben
Betriebsprüfung und abschließende gemeinsame Statusseite getrennt.
