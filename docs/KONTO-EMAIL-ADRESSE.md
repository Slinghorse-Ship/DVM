# Persönliche Login-E-Mail ändern

Unter **Dein Konto → Login-E-Mail-Adresse ändern** die neue Adresse und das
aktuelle Passwort eingeben. Der eigene Sicherheitsmail-Worker sendet einen
achtstelligen Code an die neue Adresse. Er gilt 15 Minuten und funktioniert
einmal. Erst **Neue Login-Adresse bestätigen** übernimmt die Adresse.

Neuladen zeigt die bereits angenommene Anfrage wieder und fordert keinen
weiteren Code an. Während der Anfrage stehen nur Bestätigung und
**Anfrage beenden** im geöffneten Bereich. Der Abbruch ändert keine Adresse.
Eine neue Anfrage ersetzt nur den vorherigen eigenen Code derselben Anmeldung.
Falsche Eingaben behalten den Vorschlag und die eingereichte Kontoversion;
Passwörter und Codes werden nicht erneut ausgegeben.

Der Vorgang ändert ausschließlich den persönlichen Loginzugang. Kontaktdaten
im Mitgliederprofil, Presseempfänger und Newsletterabonnements sind getrennte
Fachvorgänge. Person, Vereinszuordnung, Rechte, Benutzername, Passwort,
Authenticator und Wiederherstellungscodes bleiben erhalten. Bei bestehender
E-Mail-Zwei-Faktor-Anmeldung folgt deren bestätigte Adresse dem Loginwechsel.
Die Zwei-Faktor-Anmeldung bleibt verpflichtend.

Nach Bestätigung enden alle früheren Anmeldungen und alte offene Reset-/
Einrichtungslinks. Das bestätigende Gerät erhält eine neue Anmeldung mit seiner
bisherigen Dauer. Familien dürfen weiterhin eine Mailbox teilen, wenn beide
Personen im selben Verein eigene persönliche Benutzernamen haben.
Fremde Vereinszuordnungen oder mehrdeutige Zugänge ohne Benutzername werden
abgewiesen. Gleichzeitige Ansprüche auf dieselbe alleinige Adresse nehmen
genau einen Wechsel an.

Der gespeicherte Code gehört zur eigenen Kontoversion und konkreten Anmeldung.
Eine andere Sitzung kann ihn nicht bestätigen oder abbrechen. Geänderte,
gesperrte oder abgelaufene Konten werden vor Versand und Bestätigung erneut
geprüft. Die Mail bleibt verschlüsselt in der vorhandenen Sicherheitscode-
Warteschlange. Bei unbekannter Zustellung wird sie nicht automatisch wiederholt.
Ein tatsächlich empfangener gültiger Code kann trotzdem die Adresse bestätigen.
Die normalen Versandvoraussetzungen und privaten Worker gelten weiterhin.

Beide Kontozugänge verwenden denselben tatsächlichen Kontodienst. Der Bereich
ist über `/account?section=email` erreichbar, mit und ohne JavaScript.
Erfolgreiche Formulare kehren per Redirect zur GET-Ansicht zurück, sodass Reload
keinen zweiten Auftrag auslöst. Sechs kurze Kontobereiche zeigen jeweils nur eine
Einstellung; sofortiges Scrollen erhält stabile Touchziele. Der Managerknopf
hat lesbaren weißen Text.

35 Fach-/MySQL-Prüfungen bestehen, einschließlich zwei tatsächlicher
konkurrierender Prozesse, Familienpostfach, MFA-Erhaltung, abgelaufenen Codes,
Sperre, späterer Änderung, Abbruch und unklarer Transportantwort.
100 tatsächliche Touch-Browserprüfungen mit 24 Formularaktionen bestehen über
beide Kontofassaden, mit/ohne JavaScript, 320/390/820 Pixel und doppelte Schrift.
Vorher zusätzlich 88 Tastaturprüfungen. Die integrierte Browserprüfung verwendet
die tatsächliche Manager-Kontofassade und Sitzung in einer eigenen isolierten
HTTP-Fixture; sie ist kein Nachweis für den produktiven HTTPS-Hostingzugang.
Codezustellung verwendet ausschließlich einen eigenen Fake-Transport.

Die verschlüsselte Wiederherstellung erhält vollständige bestätigte Kontenzeilen
und genaue offene Codeoriginale. Die dabei widerrufenen alten Sitzungen können
offene Anfragen nicht übernehmen. Keine neue Tabelle oder Modulversion:
Auth 6, Kommunikation 14, Paketgeneration 2026101310; 181 Suiten registriert.
Kein neuer vollständiger Lauf aller 181 Suiten und keine Gesamtabnahme behauptet.

```sh
DVM_EMAIL_ADDRESS_BROWSER=1 bash tools/ci/local-native.sh --core native-email-address-change
DVM_ACCOUNT_BROWSER=1 bash tools/ci/local-native.sh --core native-account-panels
DVM_ACCOUNT_SESSIONS_BROWSER=1 bash tools/ci/local-native.sh --core native-account-panels
bash tools/ci/local-native.sh --core native-auth native-email-factor native-session-duration native-login-names native-member-bank native-member-accounts native-membership native-membership-family native-account-mail native-account-identity
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```
