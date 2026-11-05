# Konto- und Sicherheitsmails nach lokaler Mailvorprüfung

Der eigenständige native Worker verwendet das eingerichtete gemeinsame Postfach
jetzt auch für die ausdrücklich benannten Passwort-Reset- und MFA-Versandzwecke.
Bisher wurden diese Zwecke trotz eingerichteten Postfachs abgewiesen.
Unbekannte Zwecke bleiben abgewiesen; die vorhandene Postfachverwaltung und
aktuelle Empfänger-/Konten-/Sitzungsprüfungen bleiben erhalten.

Bekannte Fehler vor Beginn von SMTP behalten die ursprüngliche Reset-Anfrage
oder den Sicherheitscode in der Queue. Originales Chiffrat, Token/Challenge,
Empfänger und Ablaufzeit bleiben erhalten. Nach Korrektur kann derselbe Auftrag
einmal fortgesetzt werden. Der Worker meldet solche Aufträge als `held`,
zusätzlich zu seinen unveränderten bisherigen Ergebnisfeldern. Jeder Lauf
prüft weiterhin höchstens 20 ursprüngliche Aufträge.

Ungültige Nachrichten oder inzwischen abgelaufene Anfragen werden gestoppt und
ihre privaten Versanddaten entfernt. Begonnene unklare Transportversuche werden
weiterhin nicht automatisch wiederholt. Keine automatische Konto-, Passwort-,
Adress-, Faktor-, Rollen- oder Sitzungsänderung durch lokale Mailvorprüfung.

13 neue Fach-/Originalprüfungen grün: ursprüngliches gemeinsames Postfach für
Reset/MFA, tatsächlich fehlende private Konfiguration, originaler Reset und
geschützter Sicherheitscode bleiben erhalten, einmalige lokale Fortsetzung,
kein erneuter Versand, ungültige Nachrichten und Ablauf während Vorprüfung.
Konten, Personen, Rechte und Sitzungen bytegleich verglichen.
Sechs native Gruppen einschließlich neuer Prüfung, bestehender Reset-, MFA-,
Adress-, Postfach- und Kontobediendurchläufe grün. Tatsächliche Adressbearbeitung
mit 100 Browserprüfungen/24 POSTs und Kontobedienung mit 167 Browserprüfungen/
16 Formularversuchen sowie 200 HTTP-/Originalprüfungen erneut bestanden.
Keine Schemaänderung, kein SMTP-Testversand und keine reale Mail.

Weitere Konten-/Fach-/UI-Aufgaben bleiben separat offen.
