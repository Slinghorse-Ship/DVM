# Abnahme der Konto- und Portalgrundfunktionen

Der vereinbarte Funktionsabgleich K-21 und K-33 ist als Softwarefunktion vollständig
implementiert. Die bisherige pauschale Restnotiz wird durch folgende konkreten
Verträge ersetzt. Die Abnahme betrifft die benötigten ursprünglichen Funktionen,
native Konten und beide Kontozugänge. Weitere Plattformfunktionen
und Altbestandimporte gehören gemäß Website-Abnahme nicht dazu.

| Benötigte Funktion | Vorhandener Fachweg und aktuelle Prüfung |
| --- | --- |
| Passwortanmeldung, geschütztes Portal und Abmelden | `Auth/Service`, `Web/Account`, gemeinsame `ManagerSession`; `native-auth`, `native-member-bank`. |
| Eigene persönliche Konten und einmalige Einrichtung | `Integration/MemberAccounts`, verschlüsselte Einladungen; `native-member-accounts`. |
| Getrennte Familienidentitäten bei gemeinsamem Postfach | Persönliche ursprüngliche Konten und eindeutige eigene Login-Namen; `native-member-accounts`, `native-membership-family`, `native-email-address-change`. |
| Vergessenes Passwort und einmalige Rücksetzung | Eigener Reset-/Mailweg, ursprüngliche Faktoren bleiben erhalten; `native-auth`, `native-account-mail`, `native-account-preflight`, `native-account-acknowledgement`. |
| Eigenen Benutzernamen bearbeiten | Aktuelles Passwort, ursprüngliche Kontoversion, erhaltene Fehlereingabe; `native-account-identity`, `native-account-panels`. |
| Passwort selbst ändern | Aktuelles Passwort, ursprünglicher Kontodienst und Sitzungsbehandlung; `native-auth`, tatsächliche `native-account-panels`-Browser. |
| Login-E-Mail bestätigt ändern oder abbrechen | Ursprüngliches Postfach bis zur Bestätigung erhalten, einmaliger eigener Code, doppelte Ansprüche und echte gleichzeitige Prozesse geprüft; `native-email-address-change`. |
| Authenticator und Wiederherstellungscodes | Eigene Einrichtung, Bestätigung, Anmeldung, einmalige Recoverycodes und Ausschalten; `native-auth`, tatsächliche `native-account-panels`-Browser. |
| E-Mail als zweiter Faktor | Eigenes Konto, eigener bestätigter Schritt, getrennte Bankbestätigung; `native-email-factor`, `native-account-panels`, `native-member-bank`. |
| Sitzungsdauer je Gerät | 8 Stunden, 24 Stunden, 30 Tage oder die vorhandene Abmeldeoption; `native-session-duration`, native und integrierte Kontoformulare. |
| Aktive eigene Sitzungen vollständig anzeigen und beenden | Sechs je Seite, aktuelle Anmeldung gesondert, gezielter und gemeinsamer Einzug, sofortiger Zugangsverlust; `native-account-sessions` und getrennte tatsächliche Sitzungsbrowser. |
| Nutzerbindung, aktuelle Rollen und Sperren | Persönliche Profilzuordnung, eigene technische Kontorechte und aktueller Rollenentzug; `native-member-accounts`, `native-role-administration`, `native-member-bank`. |
| Fehler-, Formularschutz-, Touch- und Tastaturbedienung | Beide Zugänge, erhaltene Vorschläge/Versionen, keine erneute Ausgabe von Passwörtern/Codes; `native-account-panels`, `native-email-address-change`, getrennte Sitzungsbrowser. |

Die aktuellen Prüfungen laufen auf unverändertem `94dec9e`. Ein vollständiger
gezielter Lauf aller 14 genannten nativen Gruppen endet mit
`{"suites":14,"failed":[],"live_transport":false}`. Sein SHA-256:
`29fd9c3005d8bf7d876790ee00af9f094ef1a2299a4cd1fe08ec05633df771aa`.

Enthalten sind 167 tatsächliche Konto-Browserprüfungen und 100 tatsächliche
E-Mail-Wechsel-Browserprüfungen. Die Sitzungsbrowser verlangen absichtlich eigene
frische Konten und laufen separat: 51 tatsächliche Browserprüfungen, eigenes Ende
`{"suites":1,"failed":[],"live_transport":false}` und SHA-256
`72812a90d2c927597fbfa13626d0f8d66d0c0b26665f3c1a8efe8d75891987f8`.
Ein früherer Lauf mit unzulässig zusammen aktivierten Browsermodi gilt nicht als
bestanden. Die beiden erfolgreichen Läufe sind einzeln nachvollziehbar.

Die nativen Prüfungen belegen zusätzlich 202 Konto-/HTTP-Verträge im ersten
Konto-Browsermodus, 238 im eigenen Sitzungsmodus und die getrennten vorhandenen
Sitzungs-, Familien-, Rollen- und Bankverträge. Diese gemischten Prüfzahlen werden
nicht zu einer künstlichen Gesamtprozentzahl addiert. Originalprofile, Faktoren,
Rollen, Schlüssel und gespeicherte Medien werden in den Prüfungen erhalten.
Es wurden ausschließlich eigene synthetische Konten verwendet; kein realer
Mail-, Bank- oder Zahlungsauftrag wurde ausgelöst.

K-21 und K-33 werden damit auf `implementiert` gesetzt. Andere Fachbereiche, die
abschließenden Apps und die gemeinsame Statusseite behalten ihren eigenen Stand.
Es gibt keine neue Laufzeitgeneration, Schemaänderung oder zusätzliche Testsuite.
