# Persönlichen Benutzernamen bearbeiten

Unter **Mein Konto & Sicherheit** → **Persönlichen Benutzernamen bearbeiten**
kann jede angemeldete Person ihren eigenen Login-Namen ändern. Aktuelles Passwort
eingeben und **Benutzernamen speichern** wählen. Erlaubt sind bis zu 90
Kleinbuchstaben, Zahlen, Punkte und Bindestriche; das erste Zeichen muss ein
Buchstabe oder eine Zahl sein. Namen werden kleingeschrieben und außen getrimmt.
Ein bereits vergebener Name kann nicht übernommen werden.

Bei der nächsten Anmeldung funktioniert der neue Name. Die bisherige eindeutige
E-Mail-Anmeldung bleibt verfügbar. Ein gemeinsames Familienpostfach wählt weiterhin
keine Person automatisch aus: Jede Person verwendet ihren eigenen Benutzernamen.
Der vorherige Name wird durch diese Änderung nicht als weiterer Login-Alias geführt.

Das gespeicherte Konto und seine Versionsnummer bilden die Grundlage der Änderung.
Hat ein anderer Tab inzwischen Konto oder Sicherheitseinstellungen geändert, wird
die alte Fassung abgelehnt. Der Vorschlag und seine ursprüngliche Version bleiben
im Fehlerformular erhalten; das Passwort muss neu eingegeben werden. Aktuellen
Kontostand neu laden und die Änderung erneut prüfen.

Passwort, bestätigte E-Mail-Adresse, Authenticator, Wiederherstellungscodes,
E-Mail-Faktor, persönliche Vereinszuordnung und Modulrechte bleiben erhalten.
Bestehende Geräte bleiben angemeldet. Eine zuvor vorbereitete sensible Aktion
muss den neuen Kontostand erneut bestätigen. Gesperrte oder widerrufene Sitzungen
können keine Namen ändern.

Fünf Passwortprüfungen innerhalb von 15 Minuten sind kontoweit erlaubt, auch
über mehrere Geräte. Erfolgreiche Anfragen zählen mit. Falsche Passwörter,
Namenskollisionen und Versionskonflikte verbrauchen einen Versuch; der Zähler
bleibt auch bei einem abgelehnten Speichervorgang erhalten. Das Kontoschema ist
Version **6** und wird ausdrücklich über das bestehende Installer-Register
eingerichtet. Normale Webanfragen führen keine Schemaänderung aus.

Die private Änderungshistorie bindet bisherigen und neuen Namen an die konkrete
Kontoversion. Sie enthält keine Passwörter oder Faktor-Geheimnisse. Kontoseiten
und die technische Kontoverwaltung verwenden die lokal mitgelieferten
Bootstrap-6-Alpha-1-Assets und ihre tatsächlichen Formular-/Tabellenklassen.

37 MySQL-/Dienstverträge und 60 Browserprüfungen mit acht Speicherungen bestehen.
Nachweise liegen in `tests/native-login-names.php` und
`tests/native-login-names-browser.mjs` unter `vereinsverwaltung/`. Die befüllte
Browserprüfung verwendet eigene synthetische Personen in beiden Zugängen,
1440/820/390 Pixel, Tastatur, Speicherung ohne JavaScript, konkurrierende Tabs,
CSRF-Ablehnung und tatsächliche Anmeldung mit dem neuen Namen. Anschließende
Datenbankprüfungen vergleichen Konto-, Passwort-, Faktor-, Personen- und
Profiloriginale. Reale Altidentitäten und ihre Übernahme bleiben eigene Arbeit.
