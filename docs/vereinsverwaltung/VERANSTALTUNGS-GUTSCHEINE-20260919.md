> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Persönliche Veranstaltungsgutscheine

Stand: 20.09.2026. Aktueller isolierter Release: `20260920-event-vouchers-0808e04b`. Die Erstveröffentlichung `20260920-event-vouchers-172759e3` ist unten historisch dokumentiert.

Live: https://www.kulturhus.de/vereinsverwaltung/?view=portal&tab=vouchers

## Wiederzuteilung nach Löschen – Korrektur vom 20.09.2026

Nach dem Löschen des letzten Einzelgutscheins wird nun auch die zugehörige Zuteilung
gesperrt. Damit kann dieselbe Leistung erneut vergeben werden. Drei bereits vorhandene
Zuteilungen mit ausschließlich gelöschten Einzelgutscheinen werden ebenfalls korrekt
erkannt, ohne ihre Historie oder alten Codes umzuschreiben. Neue Zuteilungen erhalten neue
QR-Codes. Noch gültige oder bereits eingelöste Teile verhindern weiterhin eine doppelte
Zuteilung; bloß fehlende Datensätze gelten nicht als gelöschte Gutscheine.

Nur `EventVouchers.php` und `EventVoucherTickets.php` wurden gegenüber dem vorherigen
Live-Release geändert. Keine Migration, automatische Wiederzuteilung, Einlösung oder
Mail. 1.962 weitere Dateien unverändert. Sechs Testsuiten auf dem exakten Release bestanden,
darunter 113 kombinierte Gutschein-/Wiederzuteilungsprüfungen und ein konkurrierender
Zuteilungstest. Die sekundäre Datenbank wurde identisch wiederhergestellt.
Verschlüsselte Code-/Datenbanksicherung vor Veröffentlichung geprüft. Nachweis:
`audit/event-voucher-regrant-live-20260920.json`.

## Bedienung

Vereinsportal → Gutscheine. Der Vorstand wählt einen bestehenden Kalender- oder Website-Termin, dann die Mitglieder und die gewünschten Leistungen. Essen, Getränke und eine frei benannte weitere Leistung können unabhängig voneinander Stückzahlen oder Euro-Guthaben erhalten. Leere Mengen bleiben unberücksichtigt. Keine automatische Zuteilung an alle Mitglieder.

Mitglieder sehen nur ihre persönlichen Gutscheine samt Restbestand. Ein Ausdruck oder die persönliche Mail genügt bei der Ausgabe. Einlösen dürfen der aktuelle Vorstand/Superuser und ausdrücklich für diese Veranstaltung ausgewählte aktive Helferzugänge. Helfer erhalten dadurch keine Mitgliederverwaltungs- oder Versandberechtigung.

„Per Mail senden“ zeigt zuerst Empfänger und alle noch offenen persönlichen Gutscheine der Veranstaltung. „Mail senden“ stellt genau eine Nachricht in den bestehenden Worker-Versand. Verwendet werden der vorhandene Einladungs-Mailkanal und das bestehende ApplicationMail-Design einschließlich K-Bild. Versand erfolgt einzeln an die beim jeweiligen Mitglied gespeicherte Adresse, nicht automatisch an Familienmitglieder. Fehlende Adressen blockieren nur den Mailversand, nicht den Gutschein.

Ein erneuter ausdrücklich ausgelöster Versand enthält dieselben Codes und erzeugt kein Guthaben. „Versendet“ bezeichnet den erfolgreichen Transportaufruf, nicht eine bestätigte Zustellung im Postfach. Ein unklarer Transportausgang wird nicht automatisch wiederholt; der Vorstand prüft die Zustellung und beendet den alten Versandvorgang vor einem neuen Versuch.

## Gemeinsame persönliche Dokumentenablage

Seit `20260920-event-vouchers-7cd48828` führt der Button **Meine Gutscheine** in neuen Gutscheinmails direkt zu `?view=portal&tab=documents#meine-gutscheine`, nicht mehr in die Gutscheinverwaltung. Auch der Textteil der Mail verwendet diesen Link. Bereits zugestellte Nachrichten bleiben unverändert. Abnahme: `audit/ebilanz-mysql-host-20260918-9e454262a84fd5c0.json` und `audit/event-voucher-mail-link-live-20260920.json`; keine erneute echte Zustellung ausgelöst.

Seit `20260920-event-vouchers-5c4a1455` stehen persönliche Gutscheine zusätzlich unter **Vereinsportal → Mein Profil & Belege → Meine Belege**, neben Beitragsrechnungen und freigegebenen Bescheinigungen. Pro Veranstaltung erscheint ein Eintrag mit Datum, Leistungsbezeichnungen, Restmengen und direktem PDF-Download. Zuteilung genügt; Mailversand ist keine Voraussetzung.

Die Dokumentenansicht liest dieselben Gutscheine, sie legt keine zweite Kopie des Guthabens oder veraltete gültige PDFs in der Datenbank an. Downloads werden aus dem aktuellen offenen Bestand erzeugt. Einlösungen reduzieren die Anzeige, gesperrte/gelöschte Karten werden nicht als offene Gutscheine angeboten. Geschlossene, abgesagte oder nicht prüfbare Veranstaltungen erhalten keinen PDF-Link. Die Veranstaltungsansicht bleibt zum Anzeigen der einzelnen Codes erreichbar.

Die Identitätsprüfung ist dieselbe wie bei persönlichen Belegen: explizite aktive Profilzuordnung, niemals E-Mail-Abgleich. Auch Vorstand und Helfer sehen hier ausschließlich eigene Gutscheine. Ein technischer Admin ohne Profilzuordnung erhält keine fremden Belege. Die Rechte in der separaten Gutscheinverwaltung bleiben unverändert.

Abnahme: `audit/ebilanz-mysql-host-20260918-e72a065f3774ae16.json`: 27 zusätzliche Dokumentenprüfungen, 84 Gutscheinprüfungen, 34 Sammelversandprüfungen und Upgrade-Test erfolgreich. Sekundärdatenbank mit 150 Tabellen/1.393 Datensätzen exakt wiederhergestellt. Live nur drei Anwendungsdateien geändert, keine Schema-/Datenmigration und keine echten Mails. Angemeldete Live-Dokumentenansicht geprüft; Admin ohne persönliches Profil zeigt keine Mitgliedsgutscheine.

## Bearbeitbare Mailvorlage und Namensbaustein

Ergänzung `20260920-event-vouchers-a8699d76`: Die große HTML-Überschrift verwendet jetzt den aufgelösten Betreff der gespeicherten Vorlage; sie ist nicht länger fest „Deine Gutscheine“. Der Hinweis auf Originalgröße/100 Prozent entfällt im HTML- und Textteil der Mail. Der eigentliche PDF-Anhang und seine Druckvorgaben bleiben unverändert.

Neu: `Hallo {{VORNAME}},` für eine Anrede mit Vornamen, auch im Betreff und Sammelversand. `{{NAME}}` bleibt der vollständige gespeicherte Name. Kontakte haben bislang ein gemeinsames Namensfeld: Ohne Komma wird dessen erster durch Leerraum abgetrennter Teil verwendet (Bindestrichnamen bleiben zusammen), bei „Nachname, Vorname“ der Teil nach dem Komma. Das ist keine allgemeine Erkennung beliebiger Namens-/Titelformate; die persönliche Vorschau muss bei uneindeutigen Namen geprüft werden. Bestehende gespeicherte Vorlagen wurden nicht automatisch verändert.

51 lokale Rendering-Prüfungen und sechs Server-Testgruppen bestanden, darunter 50 Vorlagenprüfungen, 86 Gutscheinprüfungen, 34 Sammelversand-, 27 Dokumenten- und 14 Worker-Prüfungen sowie der Upgrade-Test. Separates MySQL-Testsystem vollständig wiederhergestellt: `audit/ebilanz-mysql-host-20260918-0d8c80a32846cac0.json`. Synthetische mobile/Desktop-Oberfläche bei 390/1280 px geprüft. Veröffentlichung betrifft nur `EventVoucherMail.php` und `event-voucher-mail-template.php`; 1.962 andere Release-Dateien identisch. Keine produktiven Vorlagen-/Gutscheinänderungen und kein echter Test- oder Wiederholungsversand. Code und Datenbank vorher verschlüsselt gesichert. Nachweis: `audit/event-voucher-mail-personalization-live-20260920.json`.

Seit `20260920-event-vouchers-9ac17485`: **Veranstaltung → Mailvorlage bearbeiten**. Betreff und Anschreiben werden je Veranstaltung gespeichert und für Einzel- wie Sammelversand verwendet. Beispiel: `Hallo {{NAME}},` wird beim Versand zum Namen des angesprochenen Mitglieds aufgelöst. Weitere erlaubte Bausteine: `{{VERANSTALTUNG}}`, `{{DATUM}}`, `{{UHRZEIT}}`, `{{ORT}}`. Direkt am Formular stehen Beispiel und Bedienhinweise. Nach dem Speichern zeigt die persönliche Versandvorschau den aufgelösten Betreff und Text; der Sammelversand enthält eine Beispielvorschau.

Nur Vorstand/Superuser darf Vorlagen bearbeiten. Speicherung im bestehenden `club_settings`-Bereich `event_voucher_mail:<event-id>`, mit Version, `settings_revisions` und Audit-Ereignis; keine neue Tabelle oder Migration. Vereinsbindung, Veranstaltungsschutz, CSRF, transaktionale Wiederholungssperre und Versionsprüfung bleiben aktiv. Unbekannte Platzhalter, überlange/ungültige Eingaben und mehrzeilige Betreffzeilen werden abgewiesen. Es gibt keine ausführbare Vorlagensprache; Text wird im Maildesign HTML-escaped.

Speichern versendet nichts. Neue Vorschau-Hashes enthalten die konkrete Vorlage; eine inzwischen geänderte Vorlage erfordert vor dem Einreihen eine neue Prüfung. Bereits bestätigte Aufträge verwenden dagegen den eingefrorenen Text, nicht spätere Vorlagenänderungen. Der Worker prüft weiterhin aktuelle Empfänger, Berechtigungen, Veranstaltung und Gutscheinbestand. Vorlagenlose alte Aufträge bleiben mit ihrem ursprünglichen Snapshot kompatibel. Persönliche QR-Karten, PDF-Anhang, Design und Dokumentenlink sind nicht durch die Vorlage überschreibbar. Neue Texte führen nicht automatisch zur Wiederholung bereits versendeter Gutscheine.

Abnahme: `audit/ebilanz-mysql-host-20260918-05b51eac23fb6210.json` mit 47 Vorlagen-, 86 Gutschein-, 34 Sammelversand-, 27 Dokumenten-, 14 Worker-Prüfungen plus Upgrade-Test. Geprüft sind unter anderem Rechte, doppelte/stale Speicherung, Transaktionsrollback, HTML-/Header-Escaping, personalisierte Texte bei gemeinsamer E-Mail-Adresse, identischer PDF-Anhang, eingefrorene Aufträge und Altauftrags-Kompatibilität. Sekundärdatenbank mit 150 Tabellen/1.393 Datensätzen identisch wiederhergestellt. Browserprüfung mit künstlichen Daten: 1280/390 px, Formularinhalt, Namenshilfe, abgesendete Formularparameter an einen lokalen Recorder und kein horizontaler Überlauf. Screenshots visuell geprüft.

Veröffentlicht wurden vier Anwendungsdateien; 1.960 andere Release-Dateien unverändert. Live-Vorlagenformular samt Anleitung angemeldet gelesen, keine echte Vorlage gespeichert und keine Mail versendet. Code/DB zuvor verschlüsselt gesichert, danach Hauptwebsite, Mailinitialisierung und Worker geprüft. Nachweis: `audit/event-voucher-mail-template-live-20260920.json`. Bilanz-Freischaltung und produktive Vereinsdaten bleiben unverändert.

## Website-Veranstaltungen im Mail-Worker


Der ursprüngliche Worker verwendete `new WebsiteCalendar($club)` ohne Fetch-Adapter. Dadurch scheiterte der Website-Terminabruf vor dem Mailtransport mit fehlender zentraler Browser-Anmeldung. Der Fehler wurde mit Testdaten reproduziert und korrigiert. Die native Prüfung auf dem Live-Webserver konnte den blockierten Auftrag einschließlich unverändertem Fingerprint, Empfänger und PDF-Anhang erfolgreich vorbereiten, ohne ihn zu senden oder seinen Status zu ändern.


Gestoppte Aufträge werden unter **Mailversand → Versandvorgang beenden** beendet und danach mit einer neuen Vorschau ausdrücklich eingereiht. „Vorgemerkt“ bedeutet Warteschlange, „Versendet“ erfolgreichen Transportaufruf, nicht bestätigten Postfacheingang. Der Worker verarbeitet bis zu 20 vorgemerkte Gutscheinmails je Lauf. Seitenaufrufe können ihn frühestens alle fünf Minuten starten; der separate Fünf-Minuten-Cron bleibt für zuverlässige Verarbeitung ohne Besucher empfohlen. Der vorhandene exklusive Worker-Lock verhindert parallele Gesamtläufe.

## Sammelversand

Seit `20260920-event-vouchers-334b51da` führt **An alle Mitglieder senden** zur gemeinsamen Empfängervorschau. Eine Bestätigung stellt getrennte persönliche Nachrichten mit jeweils eigenem PDF in die Outbox. Nur aktuelle Mitglieder mit offenen Gutscheinen und gültiger Adresse werden berücksichtigt. Bereits vorgemerkte oder unverändert versendete Gutscheine werden nicht erneut eingereiht; blockierte oder unklare vorherige Sendungen müssen zunächst geprüft und beendet werden. Weder Gutscheine noch Guthaben werden durch den Versand neu angelegt.

Abnahme: `audit/ebilanz-mysql-host-20260918-859b7345d48267ee.json`, 34 Sammelversandprüfungen zusätzlich zum bestehenden Gutschein-/Upgrade-Test. Vorschauänderungen, konkurrierende Einzelaufträge, doppelte Bestätigung und partieller Datenbankfehler sind abgedeckt; Fehler rollen die gesamte neue Sammelwarteschlange zurück. Kein echter Sammelversand durch den Test.

## Daten und Grenzen

- Stückzahlen als Ganzzahlen, Eurobeträge als Cent; keine Gleitkomma-Buchungen.
- MySQL-Transaktionen, Zeilensperren, Versionsprüfung und an Akteur/Inhalt gebundene Vorgangskennungen schützen vor parallelem oder wiederholtem Einlösen.
- Identität ist die Mitglieds-ID, nicht die E-Mail-Adresse. Gemeinsame Postfächer führen nicht zu gemeinsamen Guthaben.
- Abgesagte, entfernte, geschlossene oder nicht verlässlich abrufbare Veranstaltungen blockieren Zuteilen, Einlösen und Mailversand. Eigene Bestände bleiben einsehbar.
- Vorstand kann Restguthaben mit Grund sperren oder eine irrtümliche Einlösung einmalig mit Grund zurücknehmen. Der Verlauf bleibt erhalten.
- Kein öffentliches Einlöse-API, keine Bankbuchung, Auszahlung oder automatische steuerliche Behandlung. Codes sind kein Ersatz für die Anmeldung des ausgebenden Helfers.
- Gespeichert werden Personen-/Veranstaltungsbezug, Leistung, Restbestand, Bewegungen, Helferrechte, wiederholbare Vorgänge und persönliche Versandnachweise. Keine zusätzlichen Adress- oder Bankdaten im Gutscheinmodul. Ein allgemeines Lösch-/Aufbewahrungskonzept für diese neuen Datensätze ist vor breiter Nutzung festzulegen; keine automatische Löschung von Nachweisen.

## Persönliches Druck-PDF im Mailversand

Seit `20260920-event-vouchers-e6640a00` hängt „Mail mit PDF senden“ zusätzlich zu den eingebetteten QR-Bildern eine persönliche PDF-Datei an (`Gutscheine-JJJJ-MM-TT.pdf`). Alle offenen Karten der ausgewählten Person und Veranstaltung stammen aus derselben bereits berechtigungsgeprüften, vor Versand nochmals abgeglichenen Vorschau. Druckansicht und Mail nutzen denselben PDF-Renderer; der Vergleichstest bestätigt identische PDF-Bytes. Bis zu 200 offene Karten pro persönlicher Mail, passend zum bestehenden PDF-Limit.

Die HTML-Mail verwendet das vorhandene Kulturhus-K-Bild, Creme-/Grüntöne, persönliche Anrede, Veranstaltungsangaben, einzelne QR-Karten und den Portal-Button. Der Plaintext enthält ebenfalls Codes und Anhanghinweis. Das PDF ist ein normaler herunterladbarer Anhang, die QR-Bilder bleiben inline. Scheitert die PDF-Erstellung, wird vor dem Transport abgebrochen und der Versand als blockiert angezeigt. Es wird keine Mail ohne den angekündigten Anhang gesendet.

Abnahme: Upgrade-Test und 84 Gutscheinprüfungen auf der gesicherten sekundären MySQL-Datenbank; Wiederherstellung identisch. Nachweis `audit/ebilanz-mysql-host-20260918-0821b8992f5cf095.json`. MIME-Prüfung mit installiertem Transport bestätigt PDF plus Inline-Bilder und unveränderte gewöhnliche Anhänge. Synthetischer PDF-Anhang visuell geprüft; Mailvorschau zeigt Essen, Getränk und eine eigene sonstige Bezeichnung. Mobile Prüfung mit 390 px ohne horizontalen Überlauf. Angemeldete Live-Ansicht zeigt den Anhang und „Mail mit PDF senden“. Es wurde keine echte Testmail versandt und kein echter Gutschein verändert. Die tatsächliche Darstellung einzelner Mailprogramme wurde in diesem Schritt nicht in einem zugestellten Postfach geprüft.

Nur drei Anwendungsdateien geändert; 1.959 andere Dateien unverändert. Keine Schemaänderung, Gutscheinverlauf unverändert. Vorherige Code-/Laufzeit- und Datenbanksicherung unter `/tmp/kulturhus-backups/20260920-event-vouchers-e6640a00/` verschlüsselt und geprüft. Detaillierter Nachweis: `audit/event-voucher-mail-pdf-live-20260920.json`.

## Erweiterung: Einzelkarten, QR und Druck-PDF

- Jede Stückleistung hat eine eigene dauerhafte Identität und einen eigenen QR-Code: zwei Getränke ergeben zwei einzeln einlösbare Gutscheine. Euro-Guthaben bleibt ein teilbarer Gutschein.
- Jede Karte nennt Veranstaltung, Datum, Mitglied, Leistung und Menge/Betrag. Die frei eingegebene Bezeichnung für „Sonstiges“ erscheint ebenfalls im PDF und in der Mail.
- „Gutscheine drucken“ bei einer Person öffnet nur deren Karten. „Druck-PDF · 85 × 55 mm“ erzeugt A4-Bögen mit maximal zehn Karten pro Seite, Schnittmarken und eingebetteten Schriften. In Originalgröße (100 %) drucken. Es werden nur offene Karten ausgegeben.
- „Alle Mitglieder auswählen“ und „Auswahl aufheben“ steuern die Auswahl vor der Zuteilung. Bestehende Zuteilungen derselben Leistung werden weiterhin gegen Doppelvergabe geschützt.
- „QR-Code scannen“ benötigt Kamerafreigabe und einen angemeldeten berechtigten Helfer. Ein USB-Scanner kann das Suchfeld benutzen. Der Scan sucht lediglich die Karte; erst „Einlösen“ verbraucht sie. QR-Codes fremder Veranstaltungen werden zurückgewiesen. Keine Übermittlung der Kamerabilder an externe Dienste.
- „Gutschein löschen“ macht genau diese Karte ungültig und blendet sie aus; der bisherige Verlauf bleibt erhalten. Eine erneute Einlösung desselben Codes verbraucht keinen anderen Gutschein.
- Persönliche Mails enthalten die einzelnen QR-Codes als eingebettete PNG-Bilder. Kein externer QR-Dienst und keine öffentliche Abrufadresse mit Mitgliedsdaten.

Komponenten-Version 2 ergänzt ausschließlich `event_voucher_tickets` (Migration 096). Der Live-Kern bleibt auf Schema 91. Bestehende Gutschein-Elterndatensätze, Bewegungen, Vorgänge, Helfer und Versandhistorie wurden per Zeilen-Prüfsummen unverändert nachgewiesen; die vorhandenen zwei Getränkestücke erhielten je eine Einzelkarte. Eine teilweise verbrauchte Alt-Zuteilung und die Rücknahme alter Einlösungen sind durch den Upgrade-Test abgedeckt.

Abnahme des exakten Release: `audit/ebilanz-mysql-host-20260918-81ca61e42909b80e.json`, Upgrade-Prüfung plus 79 Gutscheinprüfungen erfolgreich. Die sekundäre Testdatenbank wurde identisch wiederhergestellt. Zusätzlich: QR-Rundlauf durch jsQR, simulierte Kamera-/USB-Bedienung, 85×55-mm-PDF mit zehn Karten pro A4-Seite, lange Namen/Bezeichnungen, beide gerenderten PDF-Seiten visuell geprüft und zehn QR-Codes aus dem PDF decodiert. Der Webserver erzeugt das PDF mit künstlichen Daten; MIME-Prüfung bestätigt eingebettete QR-Bilder sowie unveränderte normale Dateianhänge. Kein realer Testversand, keine echte Einlösung und kein Testgutschein für ein Mitglied. Eine physische Handykamera wurde noch nicht praktisch getestet.

Veröffentlicht nach geprüfter verschlüsselter Code-/Datenbanksicherung unter `/tmp/kulturhus-backups/20260920-event-vouchers-df02be48/`. 1.851 andere Dateien blieben unverändert. Angemeldete Live-Sichtprüfung: zwei unterschiedliche QR-Karten mit geladenen Bildern, Name, Datum und Leistung; separate persönliche Druckansicht mit PDF-Link, ohne Einlösen-Schaltflächen und ohne horizontalen Überlauf. Hauptwebsite, unveränderter Geschäftsbestand, Mailinitialisierung ohne Versand und Worker-Status `ok` geprüft. Nachweis: `audit/event-voucher-tickets-live-20260920.json`.

## Prüfung

`vereinsverwaltung/tests/event-vouchers.php`: 60 bestandene Prüfungen auf MySQL 8.4.11 / PHP 8.3.33. Enthalten sind zwei konkurrierende Einlöseprozesse, Fremdvereins-/Mitgliederrechte, Rechteentzug, identische Mail-Wiederholungen, veraltete Empfängerdaten, unklare Versandantwort, Kalender-/Website-Termine, Abbruch ohne Teilzuteilung und ein vollständiger MySQL-Backup-Restore.

Nachweis: `audit/ebilanz-mysql-host-20260918-c820f5759ee5713e.json` (historischer Dateinamenspräfix des Testoperators; ausgeführt für Gutscheinprüfungen). Die vorhandene sekundäre Datenbank wurde serverseitig verschlüsselt gesichert und mit identischen 150 Tabellen/1.393 Datensätzen sowie identischem Schema/Triggern wiederhergestellt. Der Test verband sich nicht mit der produktiven Datenbank. Privates Testverzeichnis wieder entfernt. Die verschlüsselte Wiederherstellungssicherung bleibt serverseitig erhalten.

Playwright/Edge prüft echte, serverseitig mit künstlichen Mitgliedern gerenderte Vorlagen: Desktop 1280 px, Mobil 390 px, eigene Mitgliedersicht, Filter/Checkbox-Erhalt, Sprung zur Zuteilung und Druckansicht. Kein externer Browser-Netzwerkzugriff. Mailtests verwenden ausschließlich einen Transport-Rekorder bzw. einen absichtlich fehlschlagenden Transport; es wurde keine echte Mail versendet.

## Veröffentlichung

Neue Dateien: `src/EventVouchers.php`, `src/EventVoucherMail.php`, `src/EventVoucherActions.php`, `views/Events/event-vouchers.php`, `public/event-vouchers.css`, `public/event-vouchers.js`, `migrations/mysql/095_event_vouchers.sql`.


Im Entwicklungsstand wird die Erweiterung regulär über Migration 095 eingebunden. Der Live-Kern bleibt bewusst auf Schema 91: `EventVoucherSchema::install()` hat ausschließlich die sechs neuen Gutscheintabellen und den eigenen Versionsnachweis `event_voucher_schema` (Version 1, SQL-Prüfsumme) angelegt. Keine vorhandenen Geschäftsdatensätze, Konten oder Steuermigrationen werden dabei verändert. Eine spätere reguläre Migration 095 erkennt dieselbe Erweiterung wieder. Keine Dateidatenbank-Unterstützung.

Für den Live-Release wurden nur die Gutscheinänderungen auf den zuvor ausgelesenen produktiven Code angewandt. 1.845 weitere Dateien blieben nach Hash-/Symlinkvergleich unverändert. Die zentrale `Database.php` bleibt exakt auf dem bisherigen Live-Stand. Der neue Worker ist während des atomaren Releasewechsels auch mit dem vorherigen Bootstrap kompatibel.

Die exakte isolierte Serverversion hat erneut alle 60 Prüfungen bestanden; Nachweis `audit/ebilanz-mysql-host-20260918-104ca293271a7193.json`. Die anfänglich fehlende Website-Brücke im privaten Testverzeichnis wurde vor dem erfolgreichen Wiederholungslauf aus dem installierten Servercode ergänzt. Die produktive Datenbank wurde für diese Tests nicht verwendet.

Vor der Änderung wurden Code und Laufzeitkonfiguration als AES-256-verschlüsseltes ZIP und die vollständige Vereinsdatenbank als authentifiziert verschlüsselter KHSQL-Stream gesichert und geprüft: `/tmp/kulturhus-backups/20260920-event-vouchers-172759e3/`. Es wurden keine unverschlüsselten SQL-Dateien angelegt und keine echten Mitgliedsdaten heruntergeladen. Alter Release und Rückschaltkonfiguration bleiben für die Wiederherstellung verfügbar; eine Rückschaltung benötigt keine Löschung der additiven Gutscheintabellen.

Nach Veröffentlichung geprüft:

- Angemeldeter Browser: neuer Menüpunkt „Vereinsportal → Gutscheine“, sieben echte Veranstaltungen für 2026, keine vorhandenen Gutscheine. Es wurden keine Testgutscheine für echte Mitglieder erzeugt.
- CSS/JavaScript über HTTPS mit erwarteter Prüfsumme, schmale Ansicht ohne horizontalen Überlauf; öffentliche Hauptwebsite antwortet erfolgreich.
- Persönlicher Mailkanal lässt sich mit bestehender privater Konfiguration initialisieren, ohne eine Mail zu senden.
- Regulärer Worker-Lauf vom 20.09.2026, 05:35:17 MESZ: `status=ok`, `consecutive_failures=0`, `voucher_sent=0`.
- Unverändert: 31 Mitgliedschaften, 25 Inventardatensätze, fünf Finanzbelege, 17 Dokumente, zwei Tätigkeitsbericht-Freigaben sowie die geprüfte Einstellungen-Prüfsumme. Keine Bank-/Bilanzfreischaltung.

**Nicht den gesamten gegenwärtigen Arbeitsbaum nach live kopieren:** er enthält weiterhin unabhängig davon noch nicht abgenommene Bilanzentwicklungen. Bestehende GitHub-Backup-/Installationspakete wurden in diesem Veröffentlichungsschritt nicht aktualisiert.
