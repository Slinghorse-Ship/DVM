# Bestätigungslink bei lokaler Mailstörung erhalten

Ein noch unbestätigter Mitgliedsantrag behält bei einem bekannten Fehler vor
SMTP seine ursprüngliche Bestätigungsmail. Bisher wurden auch in diesem Fall
das Ergebnis als „unklar“ markiert und der noch ungesendete Link gelöscht.
Jetzt bleiben Antrag, Einwilligung, Themenwahl, Formularschlüssel und Link
unverändert vorgemerkt. Nach Behebung wird derselbe Link einmal versendet.

Nach bestätigtem Versand wird die vorübergehende rohe Versandkopie des Tokens
wie zuvor entfernt; der vorhandene Hash ermöglicht die wirkliche Bestätigung.
Diese legt genau einen Vorstands-Antrag an. Wiederholte Bestätigung erzeugt
keinen zweiten Antrag und erteilt keine automatische Newsletter-Zustimmung.

Ein tatsächlich gespeicherter „gesendet“-Status bleibt bei verlorener
MySQL-Schreibbestätigung erhalten. Ungültige Nachrichten oder während der
Vorprüfung abgelaufene Links werden ungesendet gestoppt. Begonnene unklare
SMTP-Versuche bleiben gegen automatische Wiederholung geschützt.

11 neue Fach-/Originalprüfungen grün, einschließlich tatsächlichem akzeptiertem
MySQL-UPDATE mit verlorener Bestätigung und wirklichem Ablauf während lokaler
Vorprüfung. Ursprüngliche Bestätigungszeilen, Token, Nutzereingaben und
Vorstands-/PDF-Originale verglichen. Drei native Gruppen einschließlich
öffentlicher Familienaufnahme und ursprünglicher Jobs grün; 292 Familien-/HTTP-/
Originalprüfungen und 67 tatsächliche Browserprüfungen mit sieben Formularversuchen
erneut bestanden. Die ursprüngliche Bestätigungsgruppe ist ebenfalls grün.
Keine Schemaänderung und keine reale Mail.
