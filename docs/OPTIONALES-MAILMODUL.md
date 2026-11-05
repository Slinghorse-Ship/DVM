# Optionales Mailmodul für Gespräche

**Social → Kommentare oder Nachrichten → Optionen → Mailmodul** öffnet die
Einstellungen in einem eigenen Fenster; ungesendete Gesprächsantworten bleiben
erhalten. Das Modul ist standardmäßig aus. Zieladresse und Antwortpostfach
hinterlegen, IMAP-Abruf und **Mailantworten direkt im zugehörigen Gespräch senden**
aktivieren. Neue Einstellungen bieten den Direktmodus; bestehende ältere
Einstellungen erhalten ihren bisherigen privaten Modus, bis der aktuelle
Verantwortliche den Direktmodus auswählt. Historische private Mailantworten
werden dadurch nicht nachträglich veröffentlicht.

Der integrierte Manager verwendet das bestehende SMTP-Postfach für
**Vereinsnachrichten** (`invitations`); das eigenständige native Websiteportal
seinen Newsletter-SMTP-Zugang. `Reply-To` verweist auf das Antwortpostfach.
Passwörter bleiben verschlüsselt und werden nie im Formular angezeigt. Abgewiesene
Einstellungen behalten Adressen und ursprünglichen Bearbeitungsstand;
**Gespeicherte Einstellungen prüfen** zeigt den tatsächlichen Stand getrennt. Leer
lassen erhält das Passwort für denselben Server/Benutzer; geänderte Zugänge
benötigen ein neues Passwort. Keine Altzugänge oder Empfänger importieren.

Im gewählten Gespräch **E-Mail → Mail vorbereiten** hält Original und eigene
optionale Notiz privat fest. Die Notiz startet leer. **Mail senden** benötigt die
bewusste Bestätigung und leitet die genaue Fassung an die konfigurierte Person
weiter. Die Person antwortet gewöhnlich auf diese Mail. Der Abruf ordnet ihre
Antwort über den eigenen zufälligen Message-ID-/In-Reply-To-/References-Bezug,
Absender, Zielpostfach, Verein und ursprüngliche Anbieterbindung zu.

Im Direktmodus erscheint die echte Antwort im richtigen Chat und wird sofort
über den passenden Anbieter gesendet: **Facebook-/Instagram-Kommentare**, private
**Facebook-/Instagram-Nachrichten** oder **StadtLand.Funk-Kommentare**.
StadtLand.Funk hat hier keine privaten Nachrichten. Keine Entwurfsübernahme und
keine vorgefertigten Antworten. Mehrere echte Antworten im selben Gespräch
bekommen eigene einmalige Versandaufträge; bestehende manuelle Entwürfe bleiben
bytegleich. Häufige Gmail/Apple-/Thunderbird-/Outlook-Zitate und Signaturen werden
vom neuen Antworttext getrennt; genaue ursprüngliche MIME-Bytes und vollständiger
Mailtext bleiben separat verschlüsselt erhalten. Neue Antworten oberhalb des
Zitats schreiben; rein zitierte oder zu lange Antworten werden nicht versendet.

Aktuelle Rechte des Konfigurationsverantwortlichen, ursprüngliche Frage,
Anbieterbindung und das normale **24-Stunden-Fenster** privater Meta-Nachrichten
werden vor dem Versand erneut geprüft. Der vorhandene API-Adapter kontrolliert
die zurückgelesene Kennung und den echten Antworttext; bei privaten Meta-Nachrichten
auch Absender, Empfänger und Zeitpunkt. Ein dauerhaft gespeicherter Versandbeginn
verhindert doppelte Sends bei parallelen Abrufen und Neustarts. Unklare Ergebnisse
werden nicht wiederholt. Die Ansicht zeigt **Gesendet**, **Nicht gesendet**,
**Abgelehnt** bzw. **Ergebnis unklar**; keine behauptete Zustellung ohne Nachweis.

SMTP-Annahme der ursprünglichen Weiterleitung bedeutet keine Lesebestätigung.
Fremde, automatische und mehrdeutige Rückantworten werden nicht übernommen.
Der eindeutige unmittelbare `In-Reply-To`-Bezug hat Vorrang vor älteren
`References`. Auch die Antwort auf eine bereits empfangene menschliche Mail
bleibt im gleichen ursprünglichen Gespräch. Mehrere unmittelbare Bezüge werden
abgewiesen; ein unbekannter Zwischenbezug benötigt eindeutige ältere Referenzen.
Der zufällige Mailbezug wird nur an das konfigurierte Ziel verschickt; Absender-
header sind kein allgemeiner kryptografischer Identitätsnachweis. Anhänge werden
nicht ausgeführt oder als Vereinsbelege übernommen. Abschalten erhält alle
ursprünglichen Mails und schon gesendeten Chatantworten.

IMAP verwendet TLS 993 mit Zertifikatsprüfung, `OP_READONLY` und `FT_PEEK`, ändert
keine Flags und löscht keine Mail. Bis zu 25 Originalmails und 25 Social-Aufträge
pro Lauf, höchstens 2 MB je ursprünglicher Mail und 20 KB lesbarer Text. UIDVALIDITY-
Wechsel duplizieren nichts; wiederverwendete Kennungen mit anderen Bytes ersetzen
kein Original. Ein Abruffehler erhält den zuletzt bestätigten Cursor.

Die optionale **PHP-IMAP-Erweiterung** fehlt in der aktuellen PHP-8.4-Cloud.
Für Live-Betrieb sind Hosting-IMAP, echte SMTP-/Postfachzugänge, aktuelle Meta-
Berechtigungen und ein privater Zeitplan einzurichten. Entwicklungs- und Restore-
Sperren bleiben wirksam; hier wurde kein echter Anbieter-/Mailauftrag ausgeführt.

Delivery-Schema **6** enthält die in Version 5 ergänzten dauerhaften Mailversandzuordnungen und eigene
Antwortslots ausdrücklich über den bestehenden Installer-/SchemaRegistry-Weg.
Vorhandene Originale bleiben erhalten, auch bei wiederholter Wartung. Keine DDL
im normalen Webrequest. Hosting-Cron beispielsweise alle fünf Minuten:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --mail-replies
```

Separater Lauf für den konfigurierten Verein; keine Kombination mit anderen
Worker-/Fixture-Modi. Die Betriebshistorie speichert begrenzte Status-/Versandzähler
und keine Texte, Adressen oder Zugangsdaten.

Nachweis: **177** direkte MySQL-/MIME-/Anbieterverträge mit allen fünf Antwortwegen,
mehreren Antworten, parallelem zweitem Zugang, vorhandenen Entwürfen, Neustarts,
Fenstern, Original-/Rechtewechseln und fehlendem Nachweis. **112** tatsächliche
Browserprüfungen bei 1440/820/390/320 Pixeln, Touch, Tastatur, 200 % Zoom und ohne
JavaScript; eine private Einstellungsspeicherung, keine Live-Transporte. Frühere
**142** Mailverträge und **69** Browserprüfungen mit 15 privaten Speicherungen
bestehen weiter. Originale, Mailbytes, Konten, Dokumente, Medien, Freigaben und
Jobs werden nach Browserabläufen unabhängig auf unveränderte Bytes geprüft.

Alle 82 nativen Suiten bestehen, einschließlich Version-4-auf-5-Upgrade mit
bytegleichen bestehenden Antworten sowie verschlüsselter Wiederherstellung der
dauerhaften Mailzuordnungen. Die abschließenden 177 direkten Verträge prüfen
zusätzlich das Abschalten und den Wechsel des Konfigurationsverantwortlichen:
Auch ein vorbereiteter Auftrag darf deren aktuelle Rechte nicht umgehen.

Lange Mailverläufe verwenden für Kommentare und private Nachrichten die gemeinsame
40er-Seitenwahl. Frühere eigene Antworten bleiben erreichbar; bereits enthaltene
Anbieteroriginale werden nicht doppelt gezeigt. 55 Listen-/Verlaufsverträge und
132 zusätzliche tatsächliche Browserprüfungen mit je 70 ursprünglichen Antworten
bestehen. Der aktuelle Gesprächszeitpunkt bleibt auch bei einem älteren
Teilanbieterabruf an die erhaltene neueste native Nachricht gebunden.
[Verträge und Grenzen](SOCIAL-GESPRAECHSLISTE.md).
