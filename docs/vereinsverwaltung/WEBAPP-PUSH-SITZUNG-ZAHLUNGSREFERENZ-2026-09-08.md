> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Web-App, Chat-Mitteilungen, Anmeldung und Zahlungsreferenzen

Stand: 8. September 2026. Symbole, Push-Hilfe, Zahlungsreferenzen, Geräteauswahl, manuelle Zahlungserfassung, zusätzliche Benachrichtigungsthemen und die Versammlungsauswahl sind lokal getestet und live veröffentlicht.

## Kulturhus-Symbol auf Browser und Home-Bildschirm


- PNG mit 180 Pixeln für Apple, 192 und 512 Pixeln für das Appmanifest.
- Gesonderte maskierbare 512-Pixel-Fassung mit Schutzabstand für Android.
- SVG, 32-Pixel-PNG und ICO für Browser.
- Manifest und Icon-Verweise im globalen Seitenkopf, auch außerhalb des Chats.
- Bestehende App-Identität, Startadresse und Gültigkeitsbereich bleiben erhalten. Die öffentliche Website behält ihren eigenen Website-Einstieg.
- Nur das Laden des eigenen Manifests wird zusätzlich in den bestehenden Content-Security-Policies erlaubt. Keine Erweiterung auf fremde Hosts und kein Cache für private Seiten.

Die erzeugten Symbole wurden visuell geprüft. Das bisherige Apple-Touch-SVG wurde durch PNG ersetzt. Bereits auf dem Gerät gespeicherte Symbole können bis zur Aktualisierung durch das Betriebssystem den alten Stand zeigen.

Unabhängige Gegenprüfung: 70 lokale Menü-GETs mit echtem Chromium laden das Manifest ohne CSP-Block oder JavaScriptfehler (69 Ansichten HTTP 200; persönlicher Bankbereich für den nicht zugeordneten synthetischen Benutzer erwartbar HTTP 403). Der tatsächlich belegte Bereich im maskierbaren Symbol bleibt mit maximal 185,10 Pixeln Radius innerhalb der sicheren 204,8 Pixel. PNGs und ICO-Größen sowie MIME-Typen wurden geprüft.

## Chat-Mitteilungen

Auf iPhone/iPad benötigen Web-Push-Mitteilungen ab iOS/iPadOS 16.4 eine auf dem Home-Bildschirm installierte Web-App. Der normale Safari-Tab erhält eine konkrete Schrittfolge; der Aktivierungsknopf erscheint erst im passenden Kontext. Andere Browser werden anhand ihrer tatsächlich verfügbaren APIs beurteilt.

Die Freigabeabfrage wird unmittelbar vom Benutzerklick ausgelöst. Fehlende Freigabe, fehlende Anmeldung, Serverfehler und eine nicht fertig aktivierte Service-Worker-Registrierung haben unterschiedliche Meldungen. Die Wartezeit für die Aktivierung ist begrenzt. Ein schon vorhandenes Geräteabo wird bei einem Serverfehler nicht gelöscht. Ausschalten ist auch bei fehlendem Einrichtungsschlüssel möglich; fehlgeschlagene Abmeldungen werden nicht als erfolgreich angezeigt.

Geprüft: jeweils 21 Browser-/HTTP-/Datenbankfälle mit Edge, Chromium und Desktop-WebKit gegen isolierte lokale Testdaten; 12 Serverprüfungen einschließlich echter VAPID-Signatur und Verschlüsselung mit injiziertem Providertransport. Keine Live-Geräte angemeldet und keine Benachrichtigungen versandt. Android-/iPhone-Plattformzustände wurden emuliert; es gab keinen physischen Gerätetest. Installierte Firefox- und vollständige Chrome-for-Testing-Binärdateien ließen sich auf diesem Windows nicht starten. Desktop-WebKit ist kein Nachweis für einen echten iPhone-Push-Empfang.

Primärquellen: [Apple Web Push](https://developer.apple.com/documentation/usernotifications/sending-web-push-notifications-in-web-apps-and-browsers), [WebKit iOS/iPadOS Web Push](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/), [MDN App-Symbole](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/How_to/Define_app_icons).

## Zahlungsreferenz in Texten, PDF und XML

Neue Beitragsrechnungen und ihre Mailtexte nennen die Rechnungsnummer ausdrücklich als Verwendungszweck. Individuelle Einleitungstexte bleiben zusätzlich zum festen Beitragshinweis erhalten. Die neue Textvariable `VERWENDUNGSZWECK` entspricht der unveränderlichen internen Belegnummer. Vorschauen zeigen denselben Bezug; Spenden- und Lastschriftvorlagen erhalten keine zusätzliche Überweisungsaufforderung.

Die XRechnung enthält die interne Referenz zusätzlich als `PaymentID`. Eine bestätigte externe Rechnungsnummer bleibt separat unverändert. Ein Test exportiert diese Referenz und verwendet sie danach erfolgreich für die echte lokale automatische Zuordnung. Bestehende gespeicherte PDFs werden nicht neu geschrieben. Teilzahlungen, mehrere Rechnungen in einer Überweisung und mehrdeutige Treffer werden weiterhin konservativ zur Prüfung angeboten.

Geprüft: 9 neue Dokumentprüfungen, 16 E-Rechnungsprüfungen, 53 Abgleichfälle, 22 Altnummernfälle, 12 Lieferantenfälle, 45 Beitrags-/Erinnerungsprüfungen, 88 Einstellungsprüfungen sowie 83 Browser-/PDF-Prüfungen. Der zusätzliche externe KoSIT-Validator wurde mangels konfigurierter Laufzeit nicht ausgeführt; daraus wird keine vollständige externe XML-Konformitätszertifizierung abgeleitet. Finale Beitrags-PDF visuell geprüft. Keine Live-Rechnung erzeugt oder Nachricht versandt.

## Anmeldung auf diesem Gerät


Die native Challenge, Secure/HttpOnly/SameSite, Sperrprüfung, optional aktivierte Zwei-Faktor-Anmeldung und Abmeldung bleiben wirksam. Der HMAC-Schlüssel wird getrennt aus dem vorhandenen privaten Authentisierungssalz abgeleitet. Weder Sitzung noch Geheimnis werden in der Oberfläche offengelegt. Bei der Auswahl bis zur Abmeldung gibt es keine eigene Inaktivitätsfrist; browserseitige Cookiegrenzen und externe Serverbereinigung können trotzdem eine neue Anmeldung erfordern. Die bestehenden Sitzungsspeicherorte wurden nicht verlagert.

177 gezielte Prüfungen bestanden: Policy, echte PHP-Sitzungen und Browserneustart, tatsächlicher Controller, nativer Live-Core, SID-/MAC-Replay nach Verkürzung, CSRF, Sperren, getrennte Geräte, Speichern/Reload und 390-Pixel-Ansicht. Kein physischer iPhone-Lebensdauertest. Der native Core wurde unverändert gelesen; `sessionFingerprint=1` bedeutet Standard und hier anhand des Installationsdatums User-Agent-Prüfung ohne IP-Bindung.

## Finanzzuordnung und alte Zahlungen

Wichtiger Integrationsbefund: Der vorhandene FinTS-Teil ist vorbereitet; Enable Banking kann Bankumsätze anzeigen. In diesen Pfaden gibt es derzeit keinen produktiven Import in `finance_transactions`. Deshalb wäre die Aussage „alle angezeigten Bankumsätze sind bei uns dauerhaft archiviert“ falsch. Für einen späteren Import sind dauerhafte externe Referenzen, wiederholbare Übernahme ohne Duplikate, unveränderter Fortbestand bei fehlenden alten Feedzeilen und Abgleich mit schon manuell erfassten Zahlungen erforderlich. Dieser Prüflauf baut keine fingierte Bankintegration.

Tatsächlich manuell erfasste Zahlungen bleiben hingegen in der eigenen Vereinsdatenbank. An einer offenen Rechnung kann die erfolgte Zahlung mit Konto, tatsächlichem Datum und nun auch einem Teilbetrag dokumentiert werden. Die Zahlungszuordnung, Benutzerherkunft und Änderungen bleiben nachvollziehbar. Eine vorläufige „bezahlt“-Meldung ist keine Kontobuchung. Wenn ein passender Kontoumsatz bereits vorhanden ist, wird dieser zugeordnet statt ein zweiter Umsatz angelegt.

Reproduzierbarer Randfall behoben: Eine aus mehreren Teilzuordnungen bestehende Spende mit ausgestellter Bescheinigung konnte beim Gegenbuchen eine PDO-Fehlerseite auslösen. Der Dateidatenbank-Trigger schützte die Daten bereits. Nun prüft die Anwendung sämtliche betroffenen Zuordnungen vorab und erklärt die Sperre verständlich. Die Bescheinigung muss zuerst begründet storniert werden. 33 Bescheinigungs-/Stornoprüfungen und 53 Abgleichprüfungen bestanden.

Weitere gezielte Prüfungen bestanden: 13 Mehrfach-Inventarzuordnungen, 43 gemeinsam verwendete Rechnungs-/Inventarbezüge, 15 Inventarentwürfe, 62 Anschaffungsintegrationsfälle, GEMA-Details und 17 Spenden-Vorbereitungsfälle. Geprüfte Grenzen umfassen Mandant, Rolle, Versionskonflikt, wiederholte Anfrage und Originaldatei-Hash. Das ist eine Prüfung dieser vorhandenen Abläufe, keine Zusage über nicht vorhandene Bank- oder ELSTER-Übermittlung.

## Vorherige Veröffentlichung nachgeprüft

Live-Stand `ddfa4a10f515a07798dd34953dfd3796e4f15e13`: drei Laufzeitziele nach Upload bytegenau abgeglichen. Anschließend Auswahlfelder aller Gruppen mit angemeldetem Zugang gelesen: Beiträge = Beitragsrechnung / Beitragserinnerung / Beitrags-Lastschrift; Rechnungen = Rechnung / Rechnungserinnerung / Lastschriftvorankündigung; Spenden = Spendenbegleitschreiben / Sammelspendenbegleitschreiben. Keine Vorlage bei der Sichtprüfung gespeichert.


## Veröffentlichungen und Live-Abnahme dieser Erweiterung

- Symbole, Browser-Push-Hilfe und Zahlungsreferenzen: `ea41c200a48836202fa6740274817ed12b86e3e3`; 47 Laufzeitziele bytegenau abgeglichen, 18 öffentliche Assets mit HTTP 200, passendem MIME und Hash geprüft.
- Manuelle Teil-/Vollzahlung: `13688a3708a8db219425f1613110a24aae1374b6`; sechs Laufzeitziele bytegenau abgeglichen. In der angemeldeten Liveansicht steht die manuelle Zahlung an einem offenen Beleg bereit. Es fehlt derzeit ein eingerichtetes Buchhaltungskonto; deshalb wird „Konto anlegen“ angeboten. Die Briefpapier-IBAN ersetzt weder Anfangsbestand noch Startdatum eines Kontos. Es wurde keine Testzahlung live gebucht.
- Geräteauswahl: `bafd59b1da2b59159433c6a440f1946eec43a0ae`; acht Laufzeitdateien und ausschließlich die ergänzte private Sitzungskonfiguration geprüft. Neues anonymes Login-Cookie: Max-Age 2592000, Secure, HttpOnly, SameSite=Lax. Anonymer Managerzugriff bleibt gesperrt. Im angemeldeten Browser waren alle drei Optionen sichtbar. Die vorhandene 24-Stunden-Auswahl wurde unverändert über den nativen Controller gespeichert; Rückleitung und einmaliger Bestätigungstext sowie erneutes Öffnen ohne erneute Bestätigung funktionierten. Keine native Cookie-/CSRF-Werte ausgelesen.

## Zusätzliche Gerätebenachrichtigungen

Schema67 ergänzt getrennte persönliche Themenpräferenzen und eine deduplizierte Zustellwarteschlange sowie zwei Website-Erstveröffentlichungs-Tracker. Neue Themen sind ausdrücklich aus; Chat-Geräte bleiben unverändert. Die Themen gelten pro Benutzer, die Browserfreigabe pro Gerät. Keine automatische E-Mail oder Änderung eines Finanzvorgangs durch einen Hinweis.

Ereignisquellen: tatsächlicher neuer Rechnungsupload beziehungsweise Mailanhang; positiver gespeicherter Kontoumsatz; erste Veröffentlichung eines kommenden öffentlichen Termins; persönliche Dokumentfreigabe beziehungsweise abgelegte Ehrenamtsbestätigung; ausgestellte Beitragsrechnung und vollständige Zahlungszuordnung. Ein Archivimport, ein Eventbrite-Update, ein Entwurf, eine bloße „bezahlt“-Meldung oder eine Teilzahlung erzeugt keine entsprechende Vollzahlung-/Veröffentlichungsmeldung. Bestehende Veranstaltungen werden beim ersten Start stumm als Bestand markiert; spätere Opt-ins erhalten keine Historie.

Vor Zustellung werden aktive Identität, eigene Vereinszugehörigkeit, Themenauswahl und aktuelle Rechte geprüft. Rechnungen unter zusätzlichen geschützten Dokumentbereichen erfordern sämtliche Leserechte. Persönliche Dokumente binden an die Kontakt-ID; eine gleiche E-Mail-Adresse begründet keine Freigabe. Der Push-Payload enthält nur einen festen Typ, keine Namen, Beträge, Dokumenttitel, IDs oder fremden URLs. Der Serviceworker führt über eine feste Typ-Ziel-Liste in den passenden Bereich. An unklaren Providerergebnissen erfolgt kein blinder Wiederholungsversand.

Zusätzlich korrigiert: Nach Fertigstellung einer zunächst entworfenen Beitragsrechnung lädt das Mitgliederportal die zuletzt ausgestellte PDF-Fassung statt immer Revision1. Der Zahlungsstatus bleibt separat nachvollziehbar; es wird für Kulturhus kein steuerliches Mitgliedsbeitragszertifikat erzeugt. Amtliche Zuwendungsbestätigungen werden weiterhin ausdrücklich geprüft und unterschrieben; allein die Benachrichtigungsfunktion stellt keine Bescheinigung aus.

Prüfungen: 47 Service-/Quelldienstfälle mit echter isolierter Dateidatenbank, 25 Event-Publishfälle einschließlich wirklicher EventSync-/Meta-Importlogik gegen isolierte Page-/Hook-Fixtures, 23 Browser-UI-Fälle sowie 21 Serviceworker-Routing-/Payloadfälle. Keine externen Provideraufrufe, Live-Geräteabos oder Testmitteilungen. Regressionsprüfungen für Finanzbuchungen, Rechnungspostfach, anhanglose Mails, Ehrenamtsformulare und bestehenden Chat-Push ergänzen diese Prüfung. Native WebPush-Signatur/Verschlüsselung wurde mit lokal injiziertem Transport geprüft; unter Windows musste dafür der vorhandene OpenSSL-Konfigurationspfad gesetzt werden.

Betriebsgrenzen: Tatsächlicher iPhone-/Android-Empfang bleibt am Gerät zu bestätigen. Der geplante Worker verarbeitet Hinweise regelmäßig, ohne einen Webseitenbesuch zu benötigen; dieser Test startete keine produktive Worker-/Mailrunde. Bankhinweise setzen tatsächlich gespeicherte Zahlungen voraus; ein produktiver FinTS-/Enable-Banking-Import fehlt noch. Der Dateidatenbank-only Versand erkennt allgemeine Template-/Elternrechteänderungen einer Veranstaltung ohne Speichern am Termin nicht erneut; Event-Unpublish, Löschen, Absage und Datumsverschiebung werden am Termin abgefangen. Auch hier enthält der Hinweis nur eine generische Mitteilung zum öffentlichen Programm.


## Protokolle, zentrale Geräteverwaltung und Versammlungen

Geräteaktivierung und Themenauswahl liegen gemeinsam unter Einstellungen → Mein Account. Der Chat enthält nur noch einen Link dorthin. Der aktuelle Accountpfad wurde zusätzlich mit jeweils21 Browserfällen in Edge, Chromium und Desktop-WebKit geprüft. Die zugehörigen Plattform-/Push-APIs wurden kontrolliert emuliert, lokale Speichern-/Abmelden-Anfragen liefen gegen die wirkliche Anwendung. Reale Provider wurden nicht angesprochen.

Das Protokollthema wird nur Konten mit tatsächlichem Protokoll-Leserecht angeboten. Erst die erste Freigabe erzeugt Hinweise; Entwurf, spätere Korrektur und erneute Freigabe tun dies nicht. Zurückgezogene oder archivierte Protokolle werden vor der Zustellung verworfen. 17 zusätzliche Quellen-/Widerrufsprüfungen sowie79 bestehende Protokollprüfungen bestanden.

Der Kalender unterscheidet Mitgliederversammlung und Vorstandsversammlung. Aktive/passive aktuelle Mitglieder bilden die erste Zielgruppe; für die zweite zählen die vier bestätigten, aktiven und mit einer Person verbundenen Vereinsämter. Ein Superuser ohne Amt oder Presseaufgabe ist kein automatischer Teilnehmer. Die Auswahl bietet einen Alle-Mitglieder-Knopf, Suche und sichtbare Anzahl. Beim Umschalten auf Vorstand werden die aktuellen Amtsinhaber vorausgewählt. GEMA erscheint bei diesen formalen Versammlungen nicht mehr inline. 17 Browserprüfungen umfassen Speichern/erneutes Öffnen, falsche Zielgruppen, Vorstands-Vorauswahl und eine visuell geprüfte 390-Pixel-Ansicht ohne verdeckte Inhalte.

E-Mail, Push oder beide Wege werden in einer eigenen Einladungsvorschau ausgewählt und über einen ausdrücklichen Knopf beauftragt. Der Termin-Speicherknopf löst keinen Versand aus. Die Vorschau nennt unterschiedliche Personen-, E-Mail- und Geräteanzahlen. Personen ohne Mail bleiben als Teilnehmer und für die Briefausgabe auswählbar. Explizite Empfänger einer Einladung werden der Teilnehmerliste hinzugefügt; bestehende Rückmeldungen bleiben erhalten. Gemeinsame Familienadressen erhalten eine Mail mit den ausdrücklich ausgewählten Namen.

Die neue sechste Tabelle calendar_invitation_targets speichert die genehmigten Kontakt-IDs und den zugehörigen Anrede-/Adressstand je Auftrag. Vor dem Mailversand werden genau diese Personen, aktuelle Zielgruppe, Teilnehmerbindung, Adresse und Termininhalt erneut geprüft. Auch gezielte Einzelauswahl bei geteilter Mailadresse funktioniert. Alte formale Mailwarteschlangeneinträge ohne diese Empfängerzuordnung werden übersprungen und benötigen eine neue Vorschau. Inhaltliche Terminänderungen oder Absagen stoppen alte Einladungen; das bloße Hinzufügen weiterer Teilnehmer verwirft bereits vorgemerkte Einladungen anderer Mitglieder nicht. Der getrennte Inhaltsvergleich ist auch bei Push wirksam. Die fachlichen Werte sind als Hash gebunden, nicht als personenbezogener Inhalt im Push-Payload enthalten.

41 zusätzliche echte Service-/Dateidatenbank-Prüfungen und 21 bestehende Kalenderprüfungen bestanden. Dazu zählen Push ohne Mail, widerrufene Ämter, ausgetretene Mitglieder, unerlaubte Teilnehmer, doppelte Formulare, gemeinsame Mailadressen, Kanaländerung nach Vorschau, Inhaltsänderung sowie zwei nacheinander beauftragte Teilnehmergruppen. Alle Zustellungen wurden nur an injizierte lokale Testtransporte geleitet; keine produktiven Einladungen, Mails oder Push-Mitteilungen versendet.

## Live-Abnahme von Benachrichtigungen und Versammlungen

Release `78745ba3a61b0fa9cbd2b80dbc008dfe0392705d`: Alle 33 Zielpfade stimmen bytegenau mit den 30 committed Laufzeitquellen überein. Schema 67 ergänzt sechs Tabellen und einen Index. Bei der Migration wurden sämtliche bisherigen Datensätze der 116 bestehenden Tabellen unverändert nachgewiesen. Die 51 vorhandenen Website-Veranstaltungen wurden ohne Hinweise als Ausgangsbestand erfasst. Vor Freigabe der Oberfläche waren keine Themen aktiviert und keine neuen Einladungen oder Zustellungen vorgemerkt.

Die erste Veröffentlichung wurde nach einem lokalen DNS-Fehler geordnet zurückgenommen; die additive Migration und ihre kompatiblen Laufzeitdateien blieben erhalten. Nach erneuter Prüfung erfolgte ausschließlich eine Dateiveröffentlichung, ohne weitere Migration oder Wiederherstellung der Live-Datenbank. Der abschließende HTTPS-Aufruf lief vom Webhost mit Zertifikatsprüfung und gehaltener Versandwächtersperre. Der Prüflauf startete keinen produktiven Worker.

Angemeldete Liveprüfung: Mein Account zeigt genau einen Gerätebereich und die fünf für das aktuelle Administratorkonto verfügbaren Themen, alle ausgeschaltet. Eigene Dokument- und Beitragsmeldungen benötigen zusätzlich die persönliche Mitgliedszuordnung; sie werden diesem Konto nicht pauschal angeboten. Im Chat verbleibt ausschließlich der Link zu Mein Account. Bei einer neuen Mitgliederversammlung werden 30 aktuelle Mitglieder und keine reinen Kontakte angeboten; „Alle Mitglieder auswählen“ ergibt 30 von 30. Der Wechsel zu Vorstandsversammlung wählt genau die vier aktuellen Vorstandsmitglieder aus. Es wurde kein Termin gespeichert, keine Einstellung aktiviert und keine Nachricht verschickt.

Die Liveprüfung zeigte noch eine unveränderte Mitgliederversammlungsüberschrift nach dem Wechsel zum Vorstand. Die kleine Folgerevision passt die Überschrift neuer Formulare an die ausgewählte Art an; bestehende Termine behalten „Termin bearbeiten“ beziehungsweise „Termin ansehen“. Die Scriptversion wurde erhöht. Der erweiterte lokale Browserlauf bestand 24 von 24 Prüfungen einschließlich Hin-/Rückwechsel und unveränderter Bearbeitungsüberschrift.

Die Folgerevision `fa689aaf5215354471795b1d7061df5b82985ccc` ist ebenfalls veröffentlicht: zwei Laufzeitquellen, drei Zielpfade einschließlich öffentlicher JS-Kopie, jeweils bytegenau geprüft. Live lädt Scriptversion 2. Im ungespeicherten Formular steht nach dem Wechsel „Vorstandsversammlung ankündigen“ mit 4 von 4 Personen; zurück zu Mitgliederversammlung wechselt die Überschrift mit, und „Alle Mitglieder auswählen“ markiert 30 von 30. Keine Datenbankänderung durch diese Korrektur, keine Live-Speicherung des Testformulars und kein Versand. Der private Abnahmenachweis liegt unter `.local/manager-notifications-live-ui-verification.json`.

Auf Wunsch wird mit Scriptversion 3 zusätzlich das tatsächliche Titelfeld angepasst: Beim Wechsel der Art ersetzen „Mitgliederversammlung“ und „Vorstandsversammlung“ den vorherigen Standardtitel oder füllen ein leeres Feld. Ein individuell eingegebener Titel bleibt erhalten. Beim Wechsel zu einer allgemeinen Veranstaltung wird ein zuvor automatisch passender Versammlungstitel geleert, damit der Benutzer den tatsächlichen Titel vergibt. Gespeicherte Termine werden beim Öffnen nicht verändert; erst ein bewusster Artwechsel passt einen vorhandenen passenden Standardtitel an. Teilnehmerauswahl und Suche verändern das Titelfeld nicht. Der erweiterte lokale Browserlauf bestand 34 von 34 Prüfungen ohne Live-Speicherung oder Nachrichtenversand.
