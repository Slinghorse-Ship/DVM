# Persönliche Konten und ursprüngliche Vereinsrechte

Unter **Benutzerkonten** (`/accounts`) verwaltet die technische Administration
Einladungen und den Anmeldestatus. Unter **Einstellungen → Rollen & Konten →
Person öffnen → Profil und Kontozugang** liegen ursprüngliche Vereinsrolle,
Profilzuordnung und ausdrücklich bestätigte technische Verwaltungsrechte.
Passwort, bestätigte Login-E-Mail, Benutzername, zweiter Faktor und eigene
Sitzungen bleiben die persönlichen Abläufe unter **Mein Konto**.

## Kurze Listen und gezielte Auswahl

Die Kontenliste zeigt sechs Treffer pro Seite. Name, E-Mail beziehungsweise
Benutzername und die Zustände **Aktiv**, **Einladung offen** oder **Gesperrt**
werden vor Zählung und Seitenauswahl gefiltert. Ein geöffnetes Konto bleibt
unabhängig von seinem Listenfenster gezielt erreichbar. Einladungen wählen aus
höchstens sechs eigenen aktiven Personen mit offenem oder noch keinem Konto.
Bereits eingerichtete persönliche Zugänge werden nicht erneut eingerichtet.

Rollen- und Profilauswahl zeigen ebenfalls sechs Vorschläge und gegebenenfalls
das bereits gewählte Original zusätzlich. Eine Suche ohne Treffer entfernt die
gewählte Zuordnung nicht. **Auswahl anzeigen** sucht ausschließlich: Vorschläge,
ursprüngliche Profilbindung, separate Konto-/Rollenversionen und eine bereits
gesetzte ausdrückliche Bestätigung bleiben erhalten. Erst **Zugriffsrechte
speichern** führt die ursprüngliche Fachänderung aus. Das funktioniert ohne
JavaScript. Fremde Konten, Rollen und Profile gelangen in keine Auswahl.

## Einladungen und Fehlerkorrektur

Ein neuer Einladungslink wird nur in seiner erfolgreichen Antwort angezeigt;
der allgemeine Kontenaufruf zeigt ihn später nicht erneut. Dieser manuelle
Vorgang versendet keine E-Mail. Für Mitglieder bestehen die getrennten nativen
Zugangs-/Familien- und optionalen Zustellabläufe weiter.

**Einladung erneuern** ist eine bewusste Neuausgabe für ein noch offenes Konto:
Die Kontoidentität bleibt, der vorherige Einladungslink endet. Jeder neue
Verwaltungsauftrag enthält die erwartete ursprüngliche Kontoversion. Doppelte,
veraltete oder gleichzeitig abgeschickte Formulare können einen bereits
ausgegebenen Link nicht still ersetzen. Eine erneute bestätigte Einladung
benötigt den neu geöffneten Kontostand.

Ein Fehler erhält gültige Vorschläge und die abgesendete Originalversion.
**Aktuellen Stand neu öffnen** verwirft die alte Versionsannahme ausdrücklich.
Bei einem unbestätigten internen Ergebnis meldet die HTTP-Verwaltung 503 mit
sicherem Text und erhält das Korrekturformular. Zuerst den aktuellen Kontostand
prüfen; eine möglicherweise bereits bestätigte Änderung nicht durch einen
ungeprüften neuen Auftrag ersetzen. Geschäftsdaten und Einmaltokens unterliegen
der ursprünglichen nativen Transaktion.

## Identität und Sicherheitsgrenzen

Kontosperren ziehen die ursprünglichen aktiven Anmeldungen sofort ein.
Wiederfreigeben stellt eine widerrufene Anmeldung nicht wieder her. Ein
Verwaltungszugang sperrt sich nicht selbst. Mitgliedschaft, Beiträge, Profile und
Vereinsämter behalten ihre eigenen Fachdienste und Originale. Eine geänderte
Profilzuordnung benötigt eine ausdrückliche Bestätigung; das persönliche Profil
eines anderen Kontos kann nicht übernommen werden. Technische Verwaltung ist
eine separate bestätigte Änderung und ersetzt keine persönlichen Vorstandsstimmen.

Die vorhandenen Dienste für Passwort-/E-Mail-/Benutzernamenänderung, Einladungen,
Reset, Authenticator, E-Mail-MFA, Wiederherstellungscodes, Geräte-/Sitzungswahl,
DB-Sitzungshashes und persistente Anmeldedrosselung bleiben maßgeblich. Die neue
Verwaltung erhält keinen administrativen Weg zum Überschreiben persönlicher
Passwörter oder MFA-Geheimnisse. Listen- und Auswahl-DTOs enthalten weder Passwortdigests noch
TOTP-/Recovery-/Einmalkennungen oder private Profilnotizen.

## Lokale Prüfung

`native-account-administration` verwendet ausschließlich neu angelegte
synthetische MySQL-Datenbanken, ursprüngliche Konten/Profile/Rollen und eigene
Schlüssel. Der Fachweg prüft vollständige erreichbare Fenster, aktuelle Rechte,
Fremdschutz, erhaltene Fehlerwerte, echte SQL-Unterbrechung, Rollback und zwei
tatsächlich parallele Einladungsprozesse. Der Browserteil wird mit
`DVM_ACCOUNT_ADMIN_BROWSER=1` oder dem lokalen `--browsers`-Lauf aktiviert.
Er prüft die tatsächliche eigenständige Redaktion und den Hauptmanager samt
ursprünglichem Rollen-/Identitäts-POST-Dispatch, JavaScript an/aus, 390/820/1440
Pixel, einfache/doppelte Schrift, Touchziele, reale Formularaktionen, Konflikte
und gezielte Wiederaufnahme. Lokale HTTP-Adapter verwenden native Anmeldungen;
reale HTTPS-Webspace- und Postfachzustellung bleiben Betriebsnachweise.

Die Abschlussprüfung vom 15. Oktober 2026 bestand 51 Fachbedingungen und 192
Browserbedingungen an beiden tatsächlichen Anwendungseinstiegen: 24
Listenansichten, zwei zusätzliche Handyansichten der Profilzuordnung und 22
ursprüngliche Formular-POSTs. Alle ursprünglichen Zeilen aus 422 bestehenden
Geschäftstabellen und beide privaten Schlüssel blieben exakt erhalten. Die
vorhandenen registrierten Prüfungen für Anmeldung (120), Benutzernamen (37) und
Kontenidentität (31) bestanden ebenfalls; der Identitätsnachweis wurde nach der
Umstellung auf das responsive Formular erneut bestanden. Reale Provider wurden
in keinem dieser Nachweise angesprochen.

## Getrennte kuratierte Auslieferung

Eine unveränderliche Exportfassung besteht fünf Fachgruppen für Kontenverwaltung, Authentifizierung, originale Profilzuordnung, Benutzernamen und Rollenverwaltung. Die Kontenverwaltung enthält darin 49 Fachbedingungen ohne Browser. Eine zweite Exportfassung auf dem bereits abgeschlossenen Datei-/API-/Routingstand besteht zusätzlich 192 Browserbedingungen mit 24 Listenansichten, zwei Handy-Profilkarten und 22 tatsächlichen POSTs in beiden Apps mit und ohne JavaScript; dadurch insgesamt 51 Kontenbedingungen. Alle 417 verglichenen ursprünglichen Tabellen und beide privaten Schlüssel bleiben exakt. Eigene Konten-/Einladungsänderungen werden von diesen unveränderten ursprünglichen Geschäftsdaten getrennt geprüft. Keine externe Nachricht wird gesendet.

- `kulturhus-accounts-isolated-domain.log`, SHA-256 `5783954cc1a79e49d0b04432d05c72cadc74c7f9be01586c0b344a464d0dc1df`.
- `kulturhus-accounts-isolated-browser.log`, SHA-256 `74ee66ca237633ec9d79787454ad5f423ef62fb8960b1d91f5534c2080b46ff0`.
