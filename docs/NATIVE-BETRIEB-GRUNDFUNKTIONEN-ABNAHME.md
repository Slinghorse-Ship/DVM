# Native Zugänge, interne Daten und Hintergrundbetrieb

Softwareabgleich am 15. Oktober 2026 für K-22, K-23 und K-32. Kein Altimport
und kein paralleler Nachbau der ursprünglichen Fachverwaltung.

| Ursprüngliche Funktion | Nativer Ersatz und geprüfter Vertrag |
|---|---|
| Geschützter Verwaltungszugang | ProductionSite/EmbeddedManager, ausdrückliche native Laufzeit, eigene HTTPS-Hostbindung; abweichende Authentifizierungsprovider werden abgewiesen |
| Persönliche Konten | Native Anmeldung, ursprüngliche Person/Profilbindung, Bestätigung, Einladungen, Sperren, Passwort-/Mailaktionen, aktuelle Rollen und versionsgebundene technische Kontorechte |
| Interne Datencontainer | Eigene private CMS-/Auth-/Zustelltabellen; Original-IDs, Versionen, Referenzen und aktuelle Vereinsrechte bleiben an den jeweiligen Fachablauf gebunden; private Medien werden nicht durch versteckte öffentliche Links ersetzt |
| Ursprüngliche Fachwarteschlangen | Anträge, DOI/Newsletter, Zugang/Willkommen, Einladungen, Serienmail, Dokumente, Gutscheine, Rechnungen, Beiträge, Mahnungen, Postfach und Geräte-Push verwenden ihre ursprünglichen Aufträge |
| Planung und Social | Getrennte private Modi für eigene Website-Veröffentlichungen, Meta, persönliche StadtLand.Funk-Aufträge, Veranstaltungsabgleich, Aktivitätsabrufe und direkte Mailantworten; aktuelle Rechte und Originale vor Übergabe erneut prüfen |
| Betriebsanzeige | Sechs gezählte eigene Läufe je Seite, Statusfilter, alle aufbewahrten Originale erreichbar; abgewiesene Antworten und unterbrochene Läufe erfordern Prüfung, keine automatische Neuübergabe |
| Sicherung und Wiederherstellung | distribution/recover-native.php mit privater STDIN-Konfiguration, verschlüsseltem Archiv, Prüfung, neuer eigener Ziel-Datenbank, erhaltender Wiederaufnahme und ausdrücklicher Betriebsfreigabe; vorhandene Installer-/Recoveryverträge |

Der private CLI-Worker und die private Wiederherstellung sind im Uploadpaket.
Die Hosting-Zeitplanung wird ausdrücklich eingerichtet; eine laufende Website
ersetzt keinen Cron-Aufruf. Optionen und Sperren stehen in
[NATIVE-HINTERGRUNDJOBS.md](NATIVE-HINTERGRUNDJOBS.md). Diese Wege verwenden die eigene native Laufzeit. Anbieterergebnis und einzelne Zustellquittung
bleiben im zuständigen Fachbereich; ein erfolgreicher Worker beweist keinen
physischen Empfang auf einem Telefon.

## Gemeinsame lokale Prüfung

```sh
DVM_WORKER_HISTORY_BROWSER=1 DVM_CHAT_PUSH_BROWSER=1 bash tools/ci/local-native.sh --core production-runtime native-auth native-member-accounts native-account-mail native-account-identity native-delivery native-original-jobs native-reminders native-publication native-meta-worker native-event-worker native-chat-push native-mail-direct native-worker-history
```

Alle 14 Gruppen bestanden gemeinsam. Darin sind tatsächliche persönliche
Kontowechsel, ursprüngliche Jobaufträge, 73 Job-/CLI-Verträge, 70 Publikations-
verträge, 31 Meta-Worker-Verträge, 177 direkte Mailverträge und 38 Push-
Browserbedingungen mit zwei Formularaktionen enthalten. Zwei echte parallele
Pushworker und ein tatsächlicher Prozessabbruch prüfen die Wiederaufnahme.

Die neue reine Laufhistorie verwendet 43 eigene Originale plus einen fremden
Bestand, sechs Einträge auf acht Seiten, vier Statusfilter und aktuellen
Rechteentzug. 19 Fachbedingungen und 74 zusätzliche reale Browserbedingungen prüfen
beide JavaScript-Modi, 16 Breiten-/Zoomzustände, echte GET-Seitenwechsel und
keinen geschäftlichen POST.
SHA-256 gemeinsamer 14er-Lauf: 36ea48e7bf01ba9ee0561877488b555c13c0180bb49fdb574959d9b1b93ad8c9.
SHA-256 abschließender Beschriftungs-/Browserlauf: ad06bf061d79d0b5638f16887fc7f0fce4f1da9cd609de7fa7da9c27f93a255c. Die visuelle Nachkontrolle fand eine zu schmale
Tabellenbeschriftung bei 200 Prozent; ein eigener Containerstil und eine
zusätzliche Beschriftungsprüfung sichern die Korrektur. Ganze ursprüngliche
Fach-, Rechte-, Zustell- und Schlüsselbytes bleiben erhalten.

Der vollständige unveränderte 245-Gruppen-Lauf auf 206d5fc ist separat bestanden
(Log-SHA-256 d41cece40252843db87dd50ea55750e24da3247fb55bc30a7db669a088b31dc8).
Spätere Quellen zählen nicht rückwirkend hinein. Paketgeneration 2026101517,
247 registrierte Gruppen, keine neue Tabelle. Reale Kundenkonten, Hosting-Cron
und physische Geräte sind eigene Einrichtungs-/Liveabnahmen. Die spätere
anbieterübergreifende Statusseite bleibt ausdrücklich ein eigener letzter Auftrag.
