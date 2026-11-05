# Vereinschat: vollständiger Softwareabgleich K-34

Stand 15. Oktober 2026. Die benötigten ursprünglichen Chatfunktionen sind im
neuen CMS verbunden. Beide Zugänge verwenden in der gemeinsamen Browserabnahme
bestätigte persönliche Konten und echte Authentifizierungssitzungen. Der native
Zugang weist anonyme Anfragen ab; eine reine synthetische Rollenwahl genügt dem
Prüfaufbau nicht mehr. Eigene Kontoschlüssel und freier lokaler Port ersetzen
bestehende Entwicklungsdaten und Sitzungen.

| Funktion | Verbindlicher Nachweis |
|---|---|
| Eigene Unterhaltungen | Gezählt sechs je Seite, echte Suche und direkte Seite; tatsächliche neue Unterhaltung mit exakt zwei ausgewählten Personen, ohne vorgefertigte Nachricht |
| Nachrichten und Antworten | Zwanzig genaue Originale je Ausschnitt, selbst verfasster Text, ursprünglicher Antwortbezug, Bearbeitungs-/Versionsschutz und erhaltende Fehlerkorrektur |
| Mitgliedschaften | Aktuelle eigene Lese-/Schreibrechte, ursprüngliche Teilnehmer, bewusstes Hinzufügen und Austritt mit anschließend gesperrten Anhängen; Dienste-/UI-Verträge |
| Anhänge | Atomare Mehrfachauswahl, einzelne private Originaldownloads/HEAD, unveränderter Antwortbezug; echte JPEG-/HEIC-/RAW-Verarbeitung im gemeinsamen Fotoimport |
| Umfragen | Eigene Frage und Optionen, ursprüngliches Ende, Anlegen/Ändern/Schließen, eine genaue Stimme, Konfliktkorrektur und absichtlich aktualisierte Originalversion |
| Hinweise und Geräte | Opt-in, Stummschaltung und vorhandene persönliche Geräteverwaltung; aktueller ursprünglicher Empfänger-/Abonnement-/Lesestand vor privater Übergabe |
| Wiederaufnahme | Echte angenommene COMMIT-/HTTP-Verluste, identische Wiederholung, zwei parallele Worker, tatsächlicher Prozessabbruch und verschlüsselt erhaltende Relokation |
| Mobile Bedienung | Beide Zugänge mit/ohne JavaScript, 320/390/820 Pixel und doppelte Schrift; ergänzte Such-/Anlageprüfung auch 1440 Pixel, mindestens 44 Pixel hohe Sendeaktion |

## Ein gemeinsamer abgeschlossener Lauf

```sh
DVM_CHAT_CATALOGUE_BROWSER=1 DVM_CHAT_PUSH_BROWSER=1 DVM_CHAT_FILES_BROWSER=1 DVM_CHAT_POLL_FORMS_BROWSER=1 bash tools/ci/local-native.sh --core native-chat native-chat-workflows native-chat-catalogue native-chat-push native-chat-files native-chat-poll-forms
```

Alle sechs Gruppen bestehen gemeinsam: **312 Fach-/Originalbedingungen** und
**360 tatsächliche Browserbedingungen**, 74 Formular-/HTTP-Aktionen und
92 Ansichten. Die vier neu angelegten Unterhaltungen besitzen im unabhängigen
Datenbankabgleich genau ihre ausgewählten Personen und keine erfundene Nachricht.
Die bisherige persönliche Antwort erscheint genau einmal mit ihrer Originalreferenz.
Alle außerhalb des eigenen Prüfvorgangs liegenden vollständigen Originale bleiben
unverändert. Der erste Zwischenlauf deckte eine Veränderung am bestehenden
synthetischen Administratorgrant im Prüfaufbau auf; die endgültige Fassung legt
einen eigenen isolierten Einrichter an und besteht den vollständigen Byteabgleich.

SHA-256 des abgeschlossenen gemeinsamen Logs:
`0e91d8610cf684803f790978ffc7800359eedadcd8daff545136a7a1b928a62f`.

Die Geräteabnahme verwendet lokale kontrollierte Transporte. Tatsächliche
Anbieterzustellung und Anzeige auf physischen Geräten folgen nicht aus einer
angenommenen Übergabe; sie bleiben kundenbezogene Liveprüfungen. Spätere Apps
haben einen eigenen Auftrag. Keine Nachricht oder Pushanfrage an reale Dienste.

K-34 ist im vereinbarten Softwareumfang implementiert. Generation 2026101517,
247 registrierte Gruppen, keine Produktionscode- oder Schemaänderung in dieser
Abnahme. Weitere Social-/Managerbedienung, Apps und abschließende gemeinsame
Statusseite sind getrennt offen.

Bedienwege: [Suche und Antworten](VEREINSCHAT-SUCHE-ANTWORTEN.md),
[Mehrfachanhänge](VEREINSCHAT-MEHRFACHANHAENGE.md),
[Umfragekorrektur](VEREINSCHAT-UMFRAGEN-FEHLERKORREKTUR.md),
[Gerätehinweise](VEREINSCHAT-GERAETEPUSH.md).
