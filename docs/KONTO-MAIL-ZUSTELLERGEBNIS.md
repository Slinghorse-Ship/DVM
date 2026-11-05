# Gespeicherte Konto-Zustellergebnisse erhalten

Reset- und Sicherheitsmail-Worker melden einen tatsächlich in MySQL gespeicherten
„gesendet“-Status jetzt korrekt zurück, auch wenn die Schreibbestätigung verloren
ging. Bisher konnte das Ergebnis als „unklar“ zurückgegeben werden, obwohl der
ursprüngliche erfolgreiche Status bereits dauerhaft gespeichert war.

Die Ausnahmebehandlung liest ausschließlich die eigene ursprüngliche Mail bzw.
den eigenen ursprünglichen Sicherheitscode mit gleichem Verein und gleicher ID.
„Gesendet“, gesetzter Versandzeitpunkt und bereits entfernte private Versanddaten
müssen den gespeicherten Abschluss bestätigen. Es wird weder ein neuer Token
noch eine zweite Zustellung erzeugt. Die erfolgreiche ursprüngliche Zeile bleibt
bytegleich erhalten.

Wenn das Schreiben tatsächlich scheitert, bleibt das bisherige vorsichtige
„unklar“ erhalten. Der verschlüsselte ursprüngliche Auftrag wird nicht ersetzt
und nicht erneut versendet. Konto, Person, Rechte und Sitzungen bleiben erhalten.
Bekannte lokale Vorprüfungen verwenden weiterhin die separat geprüfte Vormerkung.

12 neue Fach-/Originalprüfungen grün: echte akzeptierte MySQL-UPDATEs mit
verlorener Bestätigung und echte abgelehnte Schreibversuche, jeweils für Reset-
und Sicherheitsmails. Keine wiederholte Zustellung; erfolgreiche und unklare
ursprüngliche Zeilen sowie alle Konten-/Personen-/Rechte-/Sitzungszeilen verglichen.
Sechs native Gruppen einschließlich bestehender Kontomail-Vorprüfung, Reset,
MFA, Adresswechsel und Kontobedienung grün. 100 tatsächliche Adress-Browserprüfungen
mit 24 POSTs und 176 ursprüngliche Konten-/HTTP-/Originalprüfungen erneut grün.
Keine Schemaänderung und keine reale Mail.
