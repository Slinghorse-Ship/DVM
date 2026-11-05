# Native Chat: ursprünglicher technischer Abgleich

Aktueller verbindlicher Softwarestand vom 15. Oktober 2026:
[VEREINSCHAT-GRUNDFUNKTIONEN-ABNAHME.md](VEREINSCHAT-GRUNDFUNKTIONEN-ABNAHME.md).
Zwanzig Nachrichten je Ausschnitt, gezählte Suche, private Mehrfachanhänge,
HEIC/RAW-Fotoimport, aktuelle persönliche Anmeldung und Geräte-Push sind inzwischen
verbunden. Die folgende Beschreibung vom 8. Oktober ist ein historischer
Entwicklungsstand; damalige offene Einzelanhang-/Push-/Anmeldungswege sind durch
die aktuelle Abnahme ersetzt. Sie definiert keine zusätzlichen Produktaufträge.

Stand: 8. Oktober 2026. Die neue Chatoberfläche und ihre Dienste wurden eigens implementiert. Der bestehende projektinterne Chat diente ausschließlich zum Funktionsabgleich. Keim früheren CMS-/Fremdchatcode wurde übernommen. Die Chatdienste benötigen nur PDO-MySQL, `Shared\Access`/`Shared\Db`, PHP Fileinfo/mbstring und für Bilder GD. Website-, Medien-, Dokumenten-, Mail- und Pushdienste sind keine Pflichtabhängigkeiten.

## Abgleich mit dem vorhandenen Chat

Read-only untersucht wurden `src/ClubChat.php`, `src/ChatPolls.php`, `public/chat.js`, `views/Communication/Chat/chat.php`, `views/Communication/Chat/chat-messages.php`, `views/Communication/Chat/chat-push.php` und der Umfragecontroller. Es wurden keine Produktionsdaten oder Livekonten gelesen.

| Funktion des lokalen Bestands | Neuer nativer Stand |
| --- | --- |
| Gesprächsliste, Suche, neue Unterhaltung, ungelesene Zähler | Implementiert: schmale Gesprächsliste, Filter, 2–50 Teilnehmer und serverseitige Vereins-/Mitgliedschaftsprüfung. |
| Nachrichten senden/bearbeiten, ältere Nachrichten | Implementiert: idempotentes Senden, optimistische Bearbeitung, Revisionen; neueste 100 Nachrichten und rückwärts blätterbarer Verlauf statt unzugänglichem Ende nach 200 Nachrichten. |
| Antwortbezug | Ergänzt: unveränderliche threadgebundene Referenz auf eine bestehende Nachricht, sichtbarer Ursprungsausschnitt. Der Ausschnitt folgt späteren Bearbeitungen des Ursprungstextes. |
| Teilnehmer ansehen/hinzufügen, Unterhaltung verlassen | Implementiert; Einladende sehen den Hinweis auf Zugriff auf die bisherige Historie. Austritt entzieht auch Dateizugriff; Eigentumsübergabe bleibt bestehen. |
| Umfragen erstellen, wählen/ändern, schließen, Ergebnisse | Jetzt tatsächlich bedienbare UI auf `Chat\Polls`: 2–10 Optionen, optionales Ende, eine veränderbare Stimme, Ergebnisbalken; keine Namensliste der Abstimmenden. |
| PDF-/Bildanhang | Implementiert mit eigener privater Ablage; zusätzlich WebP und UTF-8-Text. Ein Anhang je Nachricht, maximal 8 MiB, Bilder maximal 4 Megapixel. Bilder werden neu encodiert und können über eine geschützte Route angezeigt werden. PDF/Text werden ausschließlich heruntergeladen. |
| Automatische Aktualisierung | Implementiert über lokale Abfrage alle zehn Sekunden. Nachrichten-/Umfragebereich aktualisiert sich; Composer bleibt erhalten. Begonnene Bearbeitung oder Stimmwahl blockiert Ersetzung, auch wenn die Eingabe erst während der laufenden Abfrage beginnt. Historische Ausschnitte werden nicht gegen den neuesten Verlauf ersetzt. |
| Push-Einstellungen/Geräte | Eigenes Opt-in, Stummschaltung je Thread, lokale Gerätedatensätze, vorbereiteter Subscription-Speicher und deduplizierte Outbox. Systemhinweise im geöffneten Manager nach ausdrücklicher Browsererlaubnis; auch außerhalb des Chatmoduls. |
| Push bei geschlossener Anwendung | **Noch nicht angebunden.** Kein VAPID-Schlüsselmanagement, kein Transport, keine externe Registrierung oder Zustellung. Die Oberfläche und `status()` melden diesen Zustand ausdrücklich. `chat-worker.js` ist ein optionaler generischer Empfangsadapter, wird in der Demo weder registriert noch über `PushManager.subscribe()` verbunden. |

Die Messengeroberfläche besteht aus Verlauf, Composer unten sowie bei Bedarf geöffneten Bereichen für Datei, Umfrage, Teilnehmer und Einstellungen. Nachrichten und Dateinamen werden als Text maskiert. Die Browserbenachrichtigung enthält ausschließlich einen allgemeinen Hinweis, keinen Namen oder Nachrichtentext.

## Integrationsvertrag

`Chat\Schema::install(PDO)` vor Nutzung der Erweiterungen einmal ausführen. Die Installation verwendet nur `CREATE TABLE IF NOT EXISTS`; keine vorhandenen Nachrichten werden gelöscht oder umgeschrieben. Zu den ursprünglichen Tabellen kommen `native_chat_replies`, `native_chat_files`, `native_chat_alert_preferences`, `native_chat_mutes`, `native_chat_devices` und `native_chat_outbox`.

| API | Verhalten |
| --- | --- |
| `Web\ChatView(PDO,Access,privateRoot,csrf)` | Privater Dateispeicher außerhalb des Webroots, unabhängig vom CMS-Medienspeicher. |
| `manager(id='',before=PHP_INT_MAX): string` | Messenger-HTML. Optionalen GET-Cursor `chat_before` als zweiten Parameter reichen. |
| `action(post,files): string` | Prüft CSRF und liefert Thread-ID bzw. leeren Wert nach Austritt. Nachrichten-Uploadfeld heißt `chat_file`; HTTP-Upload muss `UPLOAD_ERR_OK` und `is_uploaded_file()` erfüllen. |
| `status(id=''): array` | `actor_id`, Thread-IDs/ungelesene Zähler, Änderungsfingerabdruck, lokale Benachrichtigungseinstellungen und generische Ereignisreferenzen. Keine Nachrichteninhalte, Subscription-Endpoints oder kryptographischen Geräteschlüssel. Auch ohne ausgewählten Chat für den globalen Poller verwendbar. |
| `attachment(thread,seq): array` | Autorisierte Datei mit `path,name,mime,bytes,sha256`. Der Router muss jeden Abruf neu durch diese Methode prüfen. |
| `Workspace::send(id,body,key,?replyTo=null)` | Bestehende drei Parameter bleiben kompatibel; Antwortziel muss im selben Thread liegen. |
| `Workspace::history(id,before=PHP_INT_MAX)` | Neueste maximal 100 Nachrichten vor dem Cursor, chronologisch sortiert. |
| `Attachments::send(id,body,key,sourcePath,originalName,?replyTo=null)` | Eigener lokaler Dienst. Dekodierung/Prüfung erfolgt vor der Transaktion, Mitgliedschaft wird in der Transaktion erneut geprüft. Datei-Metadaten, Nachricht, Antwortbezug und Outbox entstehen gemeinsam. Bei Fehler/Wiederholung wird die vorbereitete Zufallsdatei entfernt. |
| `Notifications::state()/set(enabled,expectedVersion)` | Versioniertes Opt-in; Ausschalten storniert ausstehende Hinweise. |
| `Notifications::muted(id)/mute(id,muted)` | Mitgliedschaftsgeschützte Stummschaltung; sie storniert bisher ausstehende Threadhinweise. Aufheben erzeugt keine alten Ereignisse neu. |
| `Notifications::device(randomKey,enabled,?subscription)` | Lokaler Geräte-/optional vorbereiteter Abonnementdatensatz. Kein Netzwerkaufruf. Schlüssel wird gehasht gespeichert; höchstens 20 Geräte. |
| `Notifications::disableDevices()/queue()` | Geräte abmelden und ausstehende Hinweise stornieren; Queue bleibt bei jedem Abruf an aktuelles Leserecht und Mitgliedschaft gebunden. |

Root integriert `/chat-status?item=...`, `/chat-file/{thread}/{seq}` und `/chat-image/{thread}/{seq}`. Downloads benötigen `Content-Disposition: attachment`, `nosniff`, `no-store` und Sandbox-CSP. Nur neu encodierte PNG/JPG/WebP-Dateien dürfen über die Bildroute inline ausgeliefert werden. Die Dateien liegen als zufällige `.bin`-Dateien außerhalb des Webroots. Kein Originalpfad erscheint in der Chat-HTML-Ausgabe.

`chat.js` arbeitet ohne externes Netzwerkziel. Die lokale Browserentscheidung ist an den aktuellen Actor und einen zufälligen Geräteschlüssel gebunden; der SHA-256-Abgleich muss genau dieses Gerät als aktiv bestätigen. Ein anderes aktives Gerät reaktiviert keine abgemeldete Browserinstanz. Der globale Poller beendet Anfragen bei HTTP 403/422. Mitgliedschafts-/Leserechtsprüfung bleibt serverseitig maßgeblich.

## Nachweise

Alle PHP-Prüfungen verwenden die eigene Fixture `.local/native-cms-integration/chat.json` über `KH_NATIVE_TEST_CONFIG`; keine Änderungen an der Demo auf 8107 oder der gemeinsamen Browserfixture auf 8108 durch diese Tests. Neue Tests erzeugen pro Lauf separate synthetische Vereine/Accounts und Dateien. Kein Reset und kein Löschen einer Datenbank.

- [native-chat.php](../vereinsverwaltung/tests/native-chat.php): **69 bestehende Prüfungen**, darunter vier echte Zwei-Prozess-Fälle für Nachrichtenedits, Sendewiederholung, Stimmänderung und Umfragewiederholung.
- [native-chat-workflows.php](../vereinsverwaltung/tests/native-chat-workflows.php): **75 zusätzliche Prüfungen** für Antwortbezug, Dateien/Integrität/Vereinsgrenzen/Austritt, Wiederholung und Rollback, Umfrage-UI-Aktionen, Rechte, Stummschaltung, Opt-in/Opt-out, Geräte, Outbox und 105 Nachrichten über mehrere Verlaufsseiten.
- [native-chat-client.cjs](../vereinsverwaltung/tests/native-chat-client.cjs): **11 isolierte Chromium-DOM-Prüfungen**, vollständig abgefangene lokale Antworten ohne echten Webserver oder Pushdienst. Nachweis für globale Hinweise, kein automatisches Permission-Prompt, Gerätededuplizierung/-abmeldung, Pollingstopp und Schutz gegen eine während Fetch begonnene Eingabe. Diese Tests ergänzen den separaten HTTP-/Browserlauf des Integrationsagenten.

```powershell
$env:KH_NATIVE_TEST_CONFIG='C:/Users/wehla/.codex/worktrees/eb9a/Kulturhus/.local/native-cms-integration/chat.json'
& '.local/php-8.5.10/php.exe' -d extension=pdo_mysql vereinsverwaltung/tests/native-chat.php
& '.local/php-8.5.10/php.exe' -d extension=pdo_mysql vereinsverwaltung/tests/native-chat-workflows.php
node vereinsverwaltung/tests/native-chat-client.cjs
```

## Verbleibende Grenzen

- Geschlossene Browser benötigen einen eingerichteten Pushtransport samt VAPID, Registrierung, Zustellfehler-/Abonnementbereinigung und tatsächlichen Gerätetests. Der lokale Outboxstatus behauptet ausdrücklich keine Zustellung. Opt-in und Geräteaktivierung sind separate Zustände; nach Geräteabmeldung dürfen spätere Nachrichten weiterhin lokal vorgemerkt werden, solange das globale Opt-in aktiv bleibt.
- PDF-Dateien werden auf MIME, Header/Ende und offensichtliche aktive Schlüsselwörter geprüft. Das ist **kein vollständiger PDF-Sanitizer und kein Virenscanner**; verschleierte Inhalte können nicht zuverlässig ausgeschlossen werden. Deshalb ausschließlich Download, keine PDF-Einbettung oder Ausführung. Eine spätere Anforderung an garantiert passive PDF-Inhalte benötigt einen eigenen vertrauenswürdigen Konvertierungs-/Prüfdienst.
- Ein Prozessabbruch zwischen vorbereiteter Dateianlage und Datenbank-Commit kann eine private, unreferenzierte Datei hinterlassen. Normale Fehler und idempotente Wiederholungen werden bereinigt; ein zeitgesteuerter Abgleich/Retention-Job fehlt noch.
- Keine Bestandsmigration, keine Ende-zu-Ende-Verschlüsselung, keine Präsenzanzeige/WebSockets, keine Moderations-/Löschregeln. Nachrichtenrevisionen bleiben erhalten. Browserbetrieb mit dauerhaft vielen Gesprächen benötigt ergänzende Last-/Rate-Limit-Abnahme.
- Neue Benutzerkonten und Produktionsanmeldung sind gesonderte Arbeit. Die lokale Auswahl synthetischer Demorollen ersetzt keine echte Identitätsprüfung.
