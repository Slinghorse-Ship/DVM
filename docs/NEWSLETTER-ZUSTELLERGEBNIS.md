# Gespeichertes Zustellergebnis nach verlorener Datenbankbestätigung

Wenn der Zustell-Worker „gesendet“ tatsächlich in MySQL speichert, aber die
Bestätigung dieses COMMITs verliert, meldet er jetzt ebenfalls „gesendet“.
Früher meldete er in diesem Fall „unklar“, obwohl der ursprüngliche Auftrag
bereits erfolgreich abgeschlossen war. Die bisherige Sperre gegen erneutes
Senden blieb dabei aktiv; geändert ist die korrekte Ergebnisrückmeldung.

Der Worker liest ausschließlich den ursprünglichen Auftrag desselben Vereins,
Zwecks, Deduplizierungsschlüssels und Versuchszählers. Gespeicherter Status und
Transportreferenz müssen den abgeschlossenen Versuch bestätigen. Er wiederholt
keinen Transport und verändert den erfolgreich gespeicherten Auftrag nicht.
Fehlt dieser Nachweis, bleibt das bisherige vorsichtige Verhalten erhalten.
Die Korrektur gilt für die gemeinsame Zustell-Outbox, auch für Presse und
Social-Aufträge; sie verändert keine Anbieter- oder Mailkonfiguration.

Die neue Prüfung verwendet einen tatsächlich akzeptierten MySQL-COMMIT mit
verlorener Bestätigung und einen tatsächlich zurückgerollten COMMIT. Der zweite
bleibt „unklar“ und wird nicht erneut versendet. Abgelaufene DOI-Schlüssel
werden vor dem Transport abgewiesen. Ein eigener PHP-Worker wird wirklich mit
SIGKILL beendet; sein verschlüsselter ursprünglicher Auftrag bleibt erhalten,
wird nach Ablauf der Schutzfrist zur Prüfung markiert und nicht wiederholt.

32 Fach-/Originalprüfungen einschließlich 24 bestehender Zustellverträge grün.
Neun betroffene native Gruppen einschließlich der neuen Recovery-Prüfung,
öffentlichen DOI-/Abmeldewegen, Presse, Erneuerungen, Newsletterhistorie,
Periodenausgaben und Meta-Karussell-/Videozustellung grün. Öffentliche Newsletter-
Formulare zusätzlich mit 59 tatsächlichen Browserprüfungen und vier Formular-
POSTs geprüft; 371 zugehörige HTTP-/Originalprüfungen grün. Keine realen Mails
oder Social-Veröffentlichungen. Keine Schemaänderung.

Ergänzung: Lokale Fehler vor Beginn des SMTP-Transports sind inzwischen für
die gemeinsame Zustell-Outbox umgesetzt und geprüft; [Vorprüfung](MAIL-VORPRUEFUNG.md).
Diese Prüfung behauptet keine reale Anbieterzustellung oder vollständige Gesamtumsetzung.
