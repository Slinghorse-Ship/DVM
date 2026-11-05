# Newsletter-Anmeldung: Datenschutz in Fehlerfällen

Nach einer gültigen Newsletteranmeldung erscheint immer dieselbe neutrale
Bestätigung. Sie verrät nicht, ob die Adresse bereits bestätigt ist, noch auf
Bestätigung wartet oder neu ist. Das gilt auch, wenn die Bestätigungsmail wegen
eines technischen Problems noch nicht vorbereitet werden konnte.

Ungültige Adressen, fehlende Themen oder Einwilligung lassen sich weiterhin
direkt im Formular korrigieren. Ein abgelaufenes Formular bleibt geschützt.
Honeypot und IP-/Adressquoten verhindern zusätzliche Datenzugriffe; bestätigte
Anmeldungen und vorhandene private DOI-Originale werden nicht überschrieben.

Technische Vorbereitungsfehler erzeugen intern ausschließlich den festen Code
`newsletter_signup_preparation_failed`. Adressen, Tokens, SQL-Parameter und
Exceptiontexte gehören weder in die öffentliche Antwort noch in diesen Logeintrag.
Eine positive Formularantwort ist keine Versandbestätigung. Der bestehende
Bestätigungs- und Zustellablauf bleibt für tatsächlich vorbereitete Aufträge maßgeblich.

Der tatsächliche Vorhervergleich mit 26 Bedingungen zeigt den Fehler in beiden
Apps. Nach der Korrektur bestehen 70 Bedingungen, darunter ein wirklich clubfremdes
Logo und ein MySQL-SIGNAL bei einem Schreibzugriff. Bestätigte, wartende und neue
Adressen erhalten pro App bytegleiche HTML-Antworten mit HTTP 200. Tatsächliche
DOI-Vorbereitung, reine Eingabekorrektur, CSRF 403, Quoten und 429 ursprüngliche
Tabellen sowie private Schlüssel und Bilder bleiben geprüft. Fünf Nachbarn
bestehen 542 Bedingungen; echte Mails werden bei diesen Prüfungen nicht gesendet.
