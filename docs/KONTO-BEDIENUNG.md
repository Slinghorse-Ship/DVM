# Persönliche Kontoeinstellungen

Die Kontoseite zeigt sechs kurze native aufklappbare Bereiche: Login-E-Mail,
Benutzername, Passwort, zweiter Faktor, aktuelles Gerät und Sitzungen/Abmelden. Es öffnet sich
jeweils ein Bereich. Touch und Tastatur funktionieren auch ohne JavaScript.
Eingegebene Angaben bleiben beim Wechsel zwischen den Bereichen erhalten.

`/account?section=email`, `username`, `password`, `security`, `device` oder `sessions`
öffnet die entsprechende Einstellung direkt. Nach dem Absenden öffnet sich
der zum tatsächlichen Vorgang gehörende Bereich. Fehlermeldungen erhalten über
den nativen Formularanker unmittelbar den Tastaturfokus. Bei einer erfolglosen
Benutzernameänderung bleiben Vorschlag und ursprüngliche Kontoversion sichtbar.

Passwörter und eingegebene Sicherheitscodes werden nicht erneut ausgegeben.
Bei einer abgewiesenen Anmeldung bleibt der eingegebene Loginname erhalten.
Neue Authenticator-Schlüssel erscheinen ausschließlich im eigenen vorgesehenen
Einrichtungsschritt; Recoverycodes ausschließlich nach der eigenen erfolgreichen
Bestätigung. Die Sicherheitsgruppe bleibt dafür offen. Ein Neuladen zeigt die
Codes nicht erneut. Der bestehende persönliche Fachprozess entscheidet weiterhin
über Konto, aktuelle Rechte, Passwort, Faktoren und Anmeldedauer.

Die lokale Bootstrap-Version 6.0.0-alpha.1 und eigenen Formularanpassungen gelten
für alle Bereiche. Beschriftungen umbrechen auf schmalen Ansichten; Felder,
Knöpfe und aufklappbare Überschriften behalten lesbare Schrift und Touchziele.

Die Sitzungsgruppe enthält inzwischen eine kurze vollständige Liste aktiver
eigener Anmeldungen und gezieltes Beenden einer anderen Anmeldung.
[Eigene Sitzungen](KONTO-SITZUNGEN.md).

Der bestätigte Login-E-Mail-Wechsel ergänzt einen eigenen Code-/Abbruchweg.
[Login-E-Mail ändern](KONTO-EMAIL-ADRESSE.md). Die Kontoseite scrollt sofort;
Formularziele bewegen sich bei Touch nicht durch eine Scrollanimation.

## Eigener Profileinstieg im Vereinsmanager

Unter **Vereinsportal → Mein Profil** erscheint ausschließlich das dem aktuellen
Zugang ausdrücklich zugeordnete Mitgliederprofil. Der Profil-Link öffnet diesen
bestehenden Datensatz; persönliche Namen werden unverändert angezeigt.
Ohne Zuordnung erscheint ein entsprechender Hinweis und kein fremdes Ersatzprofil.
„Meine Angaben ändern“ folgt weiterhin der tatsächlichen aktuellen Berechtigung.

Dieser persönliche Einstieg und die äußere Kontenüberschrift folgen Deutsch/
Englisch beziehungsweise dem konfigurierten Sprachrückfall. Die bestehenden
Konto- und Profilformulare behalten ihre eigenen Werte, Originalkennungen,
Fehlermeldungen und Wiederaufnahmewege. Eine übersetzte Beschriftung erweitert
keine Vorstands-, Signatur- oder sonstigen Zugriffsrechte.

## Frühere Nachweise

`native-account-panels` verwendet den tatsächlichen synthetischen nativen
HTTP-Router mit frischer isolierter MySQL-Datenbank. Drei neue eigene Personen
teilen eine Mailbox, erhalten aber getrennte persönliche Loginnamen.
134 HTTP-/Datenbankverträge für Bereichslinks, Formularschutz, Benutzername-
korrektur, alte Version und Passwortfehler. Mit `DVM_ACCOUNT_BROWSER=1`
zusätzlich 167 tatsächliche Browserverträge mit/ohne JavaScript bei
320/390/820 Pixeln und 200 % Zoom sowie abschließend 158 Datenbankverträge.
Zwei tatsächliche Authenticator-Einrichtungen samt zehn Recoverycodes je Person;
fehlerhafte Deaktivierung erhält den Faktor und öffnet beide nötigen Gruppen.

Alle früheren Konten, Personen, Rechte, Sitzungen, Seiten, Revisionen und
Veröffentlichungen bleiben bytegleich. Auch eigene ursprüngliche Mailbox,
Passwortbytes, Profile, Sperrstatus und Rechte bleiben unverändert; sämtliche
neuen Kontenzeilen gehören ausschließlich den drei ausdrücklich gewählten
synthetischen Personen. Privater Schlüssel und Originalbild unverändert.

`account-correction-app-browser.mjs` prüft 99 weitere tatsächliche Verträge
beider eingerichteter Zugänge für Anmeldung, Einladung und Reset bei denselben
Breiten/Zoomstufen. Vier falsche CSRF-Posts werden vor dem Authentifizierungs-
dienst abgewiesen; keine Anmeldung, Mail oder Kontoänderung. Beide öffentlichen
Galerie-Fixtures bestätigen unveränderte Tabellen, Gesamtzeilenzahlen und Dateien.
119 native Suiten registriert; weitere Kontofunktionen und Geräteabnahme bleiben
im Umsetzungsregister offen.
