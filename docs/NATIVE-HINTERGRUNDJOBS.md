# Hintergrundaufgaben ohne zusätzliche CMS-Laufzeit

## Hosting-Zeitpläne aus dem Installer

Nach der Einrichtung zeigt der Installer die konkreten privaten Aufrufe und
Zeitmuster für cPanel/Plesk und bietet einen Textdownload an. Den PHP-CLI-Pfad im
Assistenten passend zum Hosting wählen. Die Aufgabe beim Hoster eintragen und
anhand eines tatsächlichen erfolgreichen Laufs prüfen. Eine gespeicherte
Fälligkeit im Manager startet noch keinen Hostingjob.
[Einrichtung und alle unterstützten Modi](HOSTING-ZEITPLAENE.md).

Der gesonderte Bankmodus `--fints` verwendet dieselbe private Konfiguration,
den eigenen `fints_actor` und die bestehenden FinTS-Abrufpläne alle fünf Minuten.
Er akzeptiert keine Versand-, Veröffentlichungs- oder Zahlungsmodi. Ein fehlender
Zugang bleibt deaktiviert; notwendige Bankfreigaben werden im Bankdialog bearbeitet.

Der private Einstieg `vereinsverwaltung/bin/native-delivery-worker.php` delegiert
an `native/Integration/Jobs`. Die ursprünglichen Fachmodule behalten ihre
Warteschlangen, IDs, Originalbelege, Freigaben und aktuellen Rechteprüfungen.
Es entsteht keine zweite Finanz- oder Mitgliederverwaltung.

Der generierte Hosting-Aufruf verwendet
`--config=/ABS/DVM/private/worker.php`. Die Konfiguration und ihre optional
referenzierte Umgebung liegen privat mit Dateirechten 0600. Die darin genannte
Vereinszuordnung und HTTPS-Domain müssen dem aktuellen eigenen Verein entsprechen.
Die Schemaaktualisierung läuft ausdrücklich vor dem Worker, niemals im Webaufruf.
Die additive Identitäts-Schemaversion ist **4**.

| Aufrufoption | Funktion |
|---|---|
| Ohne Transportoption | Redaktion planen, Kontovorgänge vorbereiten, abgelaufene private Codes verwerfen; keine Mail, kein Postfachabruf und kein Push |
| `--fints` | Nur freigegebene eigene FinTS-Umsatzabrufe; eigener `fints_actor`, aktuelle Finanzrechte und private Bankkonfiguration |
| `--send` | Eigene Newsletter-DOI/-Ausgaben sowie ursprüngliche Antrags-, Zugangs-, Willkommens-, Vorstands-, Einladungs-, Serienmail-, Dokumenten-, Gutschein- und Rechnungsaufträge |
| `--receive` | Ausdrücklich eingerichtetes eigenes Rechnungs-IMAP-Postfach; Originalnachricht und Anlagen erhalten, keine Buchung oder Zahlung |
| `--push` | Ursprüngliche eigene Chat- und Manager-Benachrichtigungen mit den aktuellen Empfängerrechten und Geräteabonnements |
| `--publish` | Fällige, ausdrücklich geplante eigene Websitefassungen veröffentlichen; aktuelle Kontorechte und unveränderte Originalversion erneut prüfen |
| `--meta --send` | Nur bereits freigegebene eigene Facebook-/Instagram-Aufträge; getrennt von allen übrigen Modi und unabhängig vom Mailversand |
| `--stadtlandfunk --send` | Nur eigene originalgebundene persönliche Terminaufträge, dauerhaftes Foto vor einzelnem Anlageversuch; getrennt von allen übrigen Modi und unabhängig von ausgeschaltetem Mailversand |
| `--mail-replies` | Optionales Antwortpostfach lesen, Originalbytes verschlüsselt erhalten, echte Antworten eindeutig zuordnen und bei aktiviertem Direktmodus über den ursprünglichen Social-Anbieter senden |
| `--social-check` | Nur fällige private Meta-/StadtLand.Funk-Aktivitätsabrufe; getrennt von Versand, Veröffentlichung, Push, Postfach und Fixture |

Der Meta-Modus verändert keine Newsletter, Erinnerungen, Redaktionsvorschläge,
persönlichen StadtLand.Funk-Aufträge oder ursprünglichen Fachqueues. Auch ohne
`--send` bereitet er solche Vorgänge nicht vor. Erst `--meta --send` transportiert
bereits ausdrücklich geplante eigene Meta-Aufträge. `KULTURHUS_DISABLE_WEBSITE=1`
und eine laufende Wiederherstellung sperren den Transport; ausgeschalteter
Mailversand ist kein Hindernis. Vereinsfilter und Auftragsart gelten vor dem
Limit. Die Betriebshistorie nennt Facebook/Instagram und behauptet keinen
Mailversand. Der private Hosting-Aufruf lautet beispielsweise:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --meta --send --limit=20
```

28 isolierte MySQL-/Transport-/CLI-Verträge prüfen den Ablauf mit deaktivierter
Mail, einen fortgesetzten Instagram-Container und unveränderten Originalbytes
aller übrigen Datensätze. Dabei erfolgt kein echter Anbietertransport. Weitere
Video-/Moderations-/Webhook- und Live-Abnahmen bleiben offen.

Unter **Social → Kommentare → Optionen** den Abrufplan pro Kanal ausdrücklich
aktivieren. Meta nutzt einen eigenen API-Prüfabstand (60 bis 3600 Sekunden).
StadtLand.Funk wählt für jede einzelne Stunde des Tageszeitraums eine neue
Zufallsminute; Standard ist 08:00–22:00 Uhr in Europe/Berlin. Tagesbeginn und
Tagesende lassen sich im Manager ändern. Pro Stunde erfolgt höchstens ein
Abgleich. Verpasste Stunden werden nach einem Neustart nicht gesammelt nachgeholt.
Meta verwendet sein eigenes Intervall rund um die Uhr, auch nachts. Der Worker
muss dafür jede Minute gestartet werden. Die zufällige Minute liegt innerhalb
der ersten 59 Minuten einer Stunde und lässt damit eine Minute Spielraum für
den Hosting-Aufruf. Anbieterpausen haben weiterhin Vorrang.

`Retry-After` verschiebt den nächsten Abruf einschließlich längerer Anbieterpausen.
Rechteentzug stoppt und pausiert den Plan. Kontoänderungen und Trennen pausieren
nur den betroffenen Kanal. Ein teilweise unterstützter Abgleich bleibt prüfbar;
bestätigte verfügbare Inhalte dürfen trotzdem in die private Übersicht gelangen.

Auf dem Hosting den getrennten privaten Dienst beispielsweise jede Minute aufrufen:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --social-check
```

Der minutenweise Worker prüft die gespeicherte Fälligkeit; er ruft StadtLand.Funk
dadurch nicht jede Minute ab. Ohne Hosting-Zeitplan entsteht kein automatischer
Abruf. Die Anwendung verwendet PHP-cURL und vorhandene PHP-Erweiterungen,
keinen Browserprozess und keine Cloud-Hilfsdatei. Historie und CLI-Ausgabe enthalten
nur Kanalzustände, keine Kommentartexte, Passwörter oder Tokens. Antworten beim
Anbieter haben eigene bewusst bestätigte Antwortwege mit unabhängiger Ergebnisprüfung; normale Abrufe antworten nichts.

`--limit` begrenzt die native Zustellqueue und den Website-Publikationslauf auf
1 bis 200 Aufträge. Die ursprünglichen
Mailqueues behalten ihre eigenen 20er-Grenzen. Entwicklungssperren verhindern
Transport und lassen die ausstehenden Zustellaufträge unverändert. Unklare
Zustellungen werden nicht automatisch wiederholt.

Website-Veröffentlichungen sind unabhängig von Mailtransport. Ohne `--publish`
bleiben fällige Veröffentlichungspläne unverändert; `KULTURHUS_DISABLE_WEBSITE=1`
sperrt sie auch bei gesetzter Option. Der Lauf wählt den eigenen Verein bereits
vor dem Limit, dem Claim und der Wiederaufnahme unterbrochener Pläne. Er verwendet
die bestehende `Cms/PublicationSchedule` mit Versions-/Hashbindung, aktuellen
Kontosperren und Rollen sowie Abgleich nach einem Prozessabbruch. Neuere private
Entwürfe bleiben bei Konflikten unveröffentlicht. Meta läuft getrennt; der
Fixture-Einstieg kann diese Option nicht aktivieren. Die Betriebshistorie zeigt
die tatsächliche Aufgabenwahl und kennzeichnet Konflikte oder Sperren zur Prüfung.

Erste freigegebene künftige Veranstaltungen bereiten die ursprünglichen
Manager-Pushhinweise nativ vor. Private Entwürfe, Anbieterimporte, Altbestände,
Archivquellen, spätere Opt-ins und Wiederveröffentlichung erzeugen keine erneute
Ankündigung. Absage, Rückzug, Archivierung und veröffentlichte Terminänderungen
wirken auf die ursprünglichen ausstehenden Hinweise. Tatsächlicher Push benötigt
weiterhin den getrennten `--push`-Lauf. Der Dispatcher prüft das eigene öffentliche
Original, Rechte, Opt-in und Gerät vor dem Transport erneut. 44 native Prüfungen
und fünf ursprüngliche Benachrichtigungs-/Push-/Protokollsuiten belegen den lokalen
Ablauf ohne Anbietertransport; [Details](NATIVE-VERANSTALTUNGEN.md).

Jeder private Worker bearbeitet ausschließlich seinen Verein, auch beim
Markieren früher unterbrochener Aufträge. Gemeinsame Postfächer und Zwecke bleiben
maßgeblich: `membership`, `invitations`, `invoices`, `contributions`, `reminders`,
`newsletter`, `password_reset` und `mfa`. Fehlende eingerichtete Zugänge werden
nicht durch ein anderes Postfach ersetzt.

Die Beitragsautomatik benötigt zusätzlich einen ausdrücklich eingetragenen
`worker_actor` und `automation_start` in der privaten Worker-Konfiguration
oder die entsprechenden privaten Variablen `VEREIN_WORKER_ACTOR` und
`VEREIN_AUTOMATION_START`. Der Zugang muss aktuell eigene Finanzschreibrechte
besitzen. Der Lauf verwendet den heutigen Berliner Kalendertag; ältere Tage
werden nicht automatisch nachgeholt. Ohne `--send` bleibt es bei einer Vorschau.
Ein vollständig erfolgreicher Tageslauf wird dauerhaft vermerkt. Fachfehler
bleiben prüfbar; wiederholte Vorbereitung nutzt die ursprünglichen Idempotenzregeln.
Archivierte Beitrags-PDFs, Familienzahler und tatsächliche Mahnintervalle bleiben erhalten.

Ein Newsletter-Opt-in im bestätigten Mitgliedsantrag erzeugt eine eigene native
DOI-Anforderung. Die Antragsbestätigung gewährt kein Newsletter-Abonnement.
Einmaliger Originalverweis, vorhandene gültige DOI-Aufträge und spätere Sperren
bleiben erhalten. Nur die Vorbereitung darf zweimal verzögert wiederholt werden;
eine unklare Zustellung erhält keine automatische Wiederholung.

Unter **Website → Vereinsdaten & Mail → Hintergrundaufgaben** stehen die letzten
sechs eigenen Läufe pro Seite. Statusfilter und gezählte Seiten machen den
gesamten aufbewahrten Bestand erreichbar. Die private Historie speichert Aufgabenwahl, Zeiten, Status und
ausgewählte Zähler, keine Empfänger, Tokens, Nachrichtentexte oder Fehlerdetails.
Die letzten 1000 abgeschlossenen Läufe bleiben erhalten; offene Läufe werden
nicht automatisch gelöscht oder erneut gestartet. Ein nach 15 Minuten noch
offener Lauf wird zur Prüfung angezeigt. Abgewiesene Mailantworten zählen
eben fehlgeschlagenen und unklaren Übergaben als prüfbedürftig. Presseversand
und Tarifabrechnung haben eigene zutreffende Aufgabenbezeichnungen. Der Abschluss eines Laufs ersetzt
nicht den Zustellnachweis seiner einzelnen Warteschlangen.

Die MySQL-Suite prüft 67 Abläufe einschließlich des tatsächlichen privaten
CLI-Einstiegs. Acht ursprüngliche Fachsuiten für Anträge, Einladungen, Serienmails,
Beiträge, Rechnungspostfach, Push, Rechnungen und Gutscheine bestehen ebenfalls.
Alle Transporte sind lokale Testadapter. Reale Anbieter-/Postfachabnahme,
vollständige Altbestandsübernahme und weitere Website-/Systemjobs bleiben offen.
Die Publikationssuite prüft außerdem 70 Abläufe mit echten parallelen Prozessen,
Abbruchwiederaufnahme und einem lokalen Zeitlauf gegen eigene synthetische Daten.

Der getrennte `--mail-replies`-Worker verwendet dieselbe private Vereinsdatei.
Er wird nur bei aktiviertem [Mailmodul](OPTIONALES-MAILMODUL.md) und Antwortabruf
tätig. Keine Kombination mit anderen Transportmodi oder Fixture. Höchstens 25
Mails pro Lauf; originale Nachrichten und Postfachflags bleiben unverändert.
Der direkte Modus sendet neue menschliche Antworten ohne Entwurfsübernahme in
den Kommentar bzw. das private Facebook-/Instagram-Gespräch. Versandaufträge sind
dauerhaft mit genau einer Originalmail verbunden. Aktuelle Rechte, Original,
Anbieterbindung und Meta-Antwortfenster werden erneut geprüft. Kein automatischer
zweiter Versuch nach unklaren oder abgelehnten Ergebnissen; frühere private Mails
werden beim Aktivieren nicht nachträglich gesendet. Entwicklungs- und Restore-
Sperren gelten weiterhin. Begrenzte Versandzähler stehen in der Betriebshistorie.
Hosting-Zeitplan beispielsweise alle fünf Minuten, genaue Ziel-/Postfachbindung
und aktuelle Rechte vor jeder Verarbeitung erneut prüfen.
