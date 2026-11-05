# Veranstaltungswege, Tickets, Gutscheine und Kasse

Stand: 12. Oktober 2026. Vor den Apps umsetzen; die gemeinsame Statusseite folgt
anschließend ganz zum Schluss. Die native Umsetzung ist noch nicht vollständig.

## Auswahl im Manager

Unter **Design & Vereinsdaten → Veranstaltungen & Tickets** sind Quelle,
Ticketverkauf und Onlinezahlung getrennt auswählbar und später wechselbar.

| Gewünschter Ablauf | Veranstaltungsquelle | Ticketverkauf |
| --- | --- | --- |
| Eventbrite legt den Facebook-Termin extern an; unser Meta-Abgleich liest ihn | Facebook · Meta-Abgleich | Eventbrite |
| Facebook-Termin manuell anlegen; unser Meta-Abgleich liest ihn | Facebook · Meta-Abgleich | Eigenes Ticketsystem |
| Nur eigene Website-Termine | Eigene Website-Termine | Eigenes Ticketsystem oder kein Ticketverkauf |
| Bestehender direkter Anbieterweg | Eventbrite · direkter Abgleich | Eventbrite oder eigene Auswahl |

SumUp Online-Checkout, Stripe Checkout oder ausschließlich kostenlose Tickets
sind als Zahlungswahl hinterlegt. Gemeinsame Bestellungen von Tickets und Wertgutscheinen können
jetzt online bezahlt werden. Erst der passende serverseitige Zahlungsnachweis
gibt beide QR-Arten frei. Eine Kontoeinstellung bestätigt keine Verfügbarkeit.

Der bisherige direkte Eventbrite-Weg bleibt ohne neue Einstellung erhalten.
Aktivierung oder Änderung der automatischen Website-Veröffentlichung verlangt
aktuelle Veröffentlichungsrechte. Eigene, auf der Website öffentliche Termine
benötigen keine Facebook-Veranstaltung. Die Quellenauswahl veröffentlicht keine
internen Kalendereinträge und entfernt keine bestehenden Website-Termine.

## Facebook-Abgleich

**Veranstaltungen → Facebook · Meta-Abgleich → Facebook-Termine abgleichen**
ruft die Veranstaltungsseite des verbundenen Meta-Kontos ab. Der Dienst verwendet
nur GET-Anfragen gegen `graph.facebook.com`, die hinterlegte Graph-Version und
Facebook-Seiten-ID. Die Facebook-Anlage bleibt manuell oder bei Eventbrite.
Der konkrete Meta-Zugang muss den Lesezugriff auf die Page-Veranstaltungen
unterstützen; unzulässige/fehlende Antworten werden als Fehler behandelt. Eine
reale Meta-Kontoabnahme ist nicht Bestandteil der lokalen synthetischen Prüfung.

Die vollständige Antwort wird vor dem Import normalisiert. Bis zu 100 Seiten und
5000 Veranstaltungen sind erlaubt. Folgeanfragen verwenden den geprüften,
URL-kodierten Cursor am selben festen API-Endpunkt; `paging.next` wird nicht
geöffnet, gespeichert oder als Link gezeigt. Überlappende IDs, kaputte letzte
Datensätze oder unvollständige Seiten verhindern jeden Teilimport. Fehlende
Termine in einer Antwort werden niemals als Löschauftrag interpretiert.

Explizite Zeitoffsets bleiben im UTC-Anker erhalten. Fehlende Endzeit und
fehlender externer Ticketlink bleiben leer. Ort, Adresse, Titel, Beschreibung,
Absagestatus und vorhandener Ticketlink werden übernommen. Fotos werden in
diesem ersten Facebook-Weg nicht von entfernten Bildhosts abgerufen.

Neue/geänderte Termine bleiben ohne ausdrücklich aktivierte Automatik Entwürfe.
Mit Automatik verwendet der Import den regulären CMS-Veröffentlichungsweg und
prüft die aktuellen Rechte. Ein Fehler rollt den vollständigen Import zurück.
Lokale Inhaltsänderungen werden als Konflikt erhalten. Eine Absage zieht die
vorherige Veröffentlichung zurück und zeigt die vorhandene, unveränderte
veröffentlichte Textfassung als Absagenotiz. Wiederaufleben erzeugt keine
stillschweigende Veröffentlichung einer lokalen Konfliktfassung.

## Anbieterwechsel ohne doppelte Website-Termine

Bei einer vorhandenen Website-Veranstaltung den aufklappbaren Bereich
**Facebook-Veranstaltung zuordnen** öffnen, die Facebook-ID angeben und die
Übernahme bestätigen. Die Zuordnung prüft die aktuelle eigene Seitenfassung.
Der nächste passende Abgleich übernimmt die Facebook-Angaben in genau diesen
Termin; seine Website-ID und URL bleiben erhalten. Wiederholte gleiche Zuordnung
ersetzt weder Entwurf noch Quellennachweis. Andere Vereine und bereits anders
zugeordnete Facebook-IDs werden abgewiesen.

Frühere Eventbrite- und Facebook-IDs bleiben in `native_event_source_aliases`
mit dem Website-Termin verbunden. Beim Rückwechsel wird die vorhandene ID
aufgelöst. Lokal weiterbearbeitete Fassungen bleiben auch dabei Konflikte.
Ein geändertes Meta-Zielkonto verlangt eine bewusste neue Zuordnung. Kontowechsel,
Tokenfassung, Rollen und Quellenauswahl werden während des Abrufs sowie im
abschließenden, vereinsgesperrten Datenbankvorgang erneut geprüft.

## Hintergrundlauf

Der private bestehende Dienst unterstützt den getrennten Modus:

```sh
php vereinsverwaltung/bin/native-delivery-worker.php --config=/ABSOLUT/private/worker.php --events
```

Die private 0600-Konfiguration enthält `event_actor` mit einem aktuellen
Vereinszugang für Website-Lesen/-Bearbeiten und gegebenenfalls Veröffentlichen.
Er benötigt keine Freigabe der Beitragsautomatik. Der Modus wird separat zu
Mail, Meta-Veröffentlichungen, Zahlungen und sonstigen Diensten gestartet.
`KULTURHUS_DISABLE_WEBSITE=1` hält auch diesen Weg ohne Anbieterabfrage an.
Eigene Website-Termine als Quelle ergeben einen Lauf ohne externen Abruf.

Der Lauf hinterlegt ausschließlich Quelle und Ergebniszahlen im bestehenden
Hintergrundverlauf. Konflikte erscheinen als prüfbedürftig. Die Aufrufe können
zeitgesteuert durch den Betreiber wiederholt werden; jede Wiederholung behält
bestehende Veranstaltungs-IDs. Die Tests richten keinen echten Cronjob ein.

## Prüfung und nächste Schritte

49 Facebook-Fachverträge, 24 private Worker-Verträge und 47 Workflow-Verträge
bestehen in jeweils eigenen lokalen MySQL-Datenbanken. Die Browserprüfung
besteht 202 Verträge mit Touch und Tastatur, 320/390/820 Pixeln, 200 % Zoom,
mit/ohne JavaScript und sechs tatsächlichen Formular-POSTs durch die nativen
Manageraktionen. 57 zugehörige HTTP-/Datenbankverträge erhalten alle früheren
vollständigen Seiten-/Revisions-/Quellen-/Aliaszeilen und verschlüsselten Konten;
nur ein neuer privater Facebook-Termin wird angelegt. Keine externen
Browseranfragen oder echten Meta-Schreibaufrufe. Kein Nachweis echter Geräte.

Kontingent, gemeinsame Bestellung, QR-Ausgabe, mobiler Einlass,
bestätigte Onlinezahlung und Kassen-Stornierung sind jetzt implementiert.
Online-Erstattungsbedienung und weitere Abnahme bleiben offen.
Die offizielle SumUp-Spezifikation bestätigt `hosted_checkout.enabled`,
`hosted_checkout_url`, `checkout_reference`, `valid_until` und die Statuswerte
`PENDING`, `PAID`, `FAILED`, `EXPIRED`; der Händlerzugang muss Onlinezahlungen
unterstützen. Die vorhandene Terminalanbindung ist davon getrennt.
Quelle: [SumUp OpenAPI](https://github.com/sumup/sumup-openapi/blob/main/openapi.yaml),
abgerufen am 12. Oktober 2026. Stripe Checkout ist die zweite Zahlungswahl.
Tickets werden erst nach serverseitigem, eindeutig zu Bestellung/Händler/Betrag/
Währung passendem Zahlungsnachweis freigegeben. Der Browserrücksprung reicht
nicht als Zahlungsnachweis.

166 CLI-Installer- und 54 tatsächliche HTTP-Installerverträge bestehen lokal.
118 tatsächliche verschlüsselte Wiederherstellungsverträge erhalten alle
Ticket-/Gutschein-/Kassenzeilen, Restguthaben, Originalbelege, Erst-Einlass,
verschlüsselte Kundenzugänge und QR-Bildbytes. Der tatsächlich wiederhergestellte
Produktionscontroller zeigt Bestellung und QR ohne Manager-Anmeldung und weist
fremde Zugänge ab. Delivery-8-Upgrades erzeugen ausschließlich die fünf leeren
Ticketkomponenten und bewahren vorhandene Anbieter-/Antwortoriginale.

## Onlinezahlungs-Verträge

`Payments/TicketCheckout` bereitet ausschließlich gehostete SumUp-/Stripe-
Checkouts vor. Kartendaten bleiben beim Anbieter; der gemeinsame
Bestelldienst stellt nach bestätigtem Zahlungsnachweis Tickets und Gutscheine aus. SumUp wird über die Online-Checkout-API angesprochen; die bestehende
Terminalanbindung bleibt erhalten. Stripe-Zugänge werden im vorhandenen
verschlüsselten Kontenspeicher hinterlegt. Leer bleibende Schlüssel werden nur
bei identischem Händler und Modus erhalten; Test-/Livewechsel verlangt neue
Schlüssel. API-/Webhookschlüssel erscheinen niemals in Formularen oder Status.

Die anschließende feste GET-Prüfung bindet Händler, ursprüngliche Bestellung,
Centbetrag, Währung, Modus, Checkout und tatsächliche Online-Transaktion.
Stripe prüft zusätzlich den erfolgreichen PaymentIntent und vollständig
belasteten Charge. Erstattungen, Dispute oder widersprüchliche Ablaufzustände
bleiben zur Prüfung gesperrt. SumUps `FAILED` gibt kein Kontingent frei, weil ein
weiterer Versuch möglich bleibt. Ein unklarer Schreibaufruf wird nicht automatisch
wiederholt; SumUp kann lesend nach derselben ursprünglichen Referenz suchen.
Stripe verwendet den festen ursprünglichen Idempotenzschlüssel. Die dauerhafte
Bestellung/Wiederaufnahme wird als nächster Schritt mit diesen Diensten verbunden.

`Payments/StripeWebhook` prüft die exakten Rohbytes mit HMAC-SHA256, mehrere
rotierende v1-Signaturen und ein Zeitfenster von 300 Sekunden in beide Richtungen.
Auch eine gültige Nachricht ist nur Auslöser der unabhängigen API-Prüfung. Die
Bestell-/Webhook-Deduplizierung und QR-Ausgabe folgen im Bestelldienst.

`KULTURHUS_DISABLE_PAYMENTS=1` hält die neuen Onlinezahlungen unabhängig von
Mail an. Synthetische CI sperrt die tatsächlichen Online-HTTP-Transporte ebenfalls.
Die Prüfung verwendet ausschließlich ausdrücklich übergebene synthetische
API-Antworten: 162 Fach-/Anfrage-/Nachweis-/Schlüsselverträge und zehn betroffene
native Suiten einschließlich unveränderter Terminal-/Erstattungswege und 98
Produktionscontrollerverträge bestehen. 152 native Suiten registriert; keine
echte Zahlung oder native Ticketbestellung damit behauptet.

Stripe-Signaturvertrag: [offizielle PHP-Bibliothek](https://github.com/stripe/stripe-php/blob/master/lib/WebhookSignature.php),
am 12. Oktober 2026 geprüft. Der eigene HTTP-Adapter pinnt die unterstützte
Graph-unabhängige Stripe-Version `2025-09-30.clover`.

154 CLI-/52 HTTP-Installer- und 103 tatsächliche Recovery-Verträge bestehen.
Die verschlüsselte Wiederherstellung bewahrt auch die genaue Stripe-Kontozeile
und entschlüsselt Händler, Modus, API-/Webhookschlüssel mit dem ursprünglichen
privaten Schlüssel. Originalkonten, Terminalzahlungen und Quelldateien bleiben
erhalten; native Bestellungen und QR-Ausgabe folgen.

## Gemeinsamer Verkauf und QR-Bedienung

Bei einer Website-Veranstaltung **Tickets & Gutscheine** öffnen, Kontingent,
Preis, fachliche Kassen-Zuordnung und Verkaufsaktivierung hinterlegen. Der
Website-Ticketknopf führt zu einer kompakten Bestellung; dort lassen sich Tickets,
Wertgutscheine oder beides auswählen. Die Wertgutschein-Angebote kommen aus den
aktiven Artikeln der bestehenden Kasse. Preis und Anzahl werden auf dem Server
festgelegt, vorhandene Bestellungen behalten ihre ursprünglichen Preise.

Die Kundenansicht zeigt eine QR-Karte mit **Vorheriges/Nächstes**. Eintrittsticket
und Wertgutschein haben unterschiedliche, ausdrücklich beschriftete QR-Typen.
Ein Wertgutschein zeigt das aktuelle Restguthaben aus dem originalen Kassenbestand.
QR-Downloads sind an den zufälligen eigenen Bestellzugang gebunden, ohne Namen,
E-Mail, API-Zugang oder Zahlungsschlüssel im QR. Testzahlungen sind gekennzeichnet.

Im Manager führt **Tickets & Gutscheine** zur bestehenden Veranstaltungskasse.
Die Eintrittstaste gehört ausschließlich zur Veranstaltung der ausgewählten
Kasse. Der Warenkorb reserviert denselben Platz wie ein Online-Kauf. Nach Bar-,
Gutschein-/Bar- oder bestätigter SumUp-/PayPal-Kartenzahlung enthält der Originalbeleg
beide QR-Arten. Eintrittspreis und fachliche Zuordnung bleiben auf diesem Beleg
unverändert, auch wenn das Angebot später geändert wird.

**QR prüfen · Einlass / Gutschein** erkennt Eintritt, gekauften Wertgutschein
und persönlichen Vereinsvorteil. Prüfen/Scannen verändert keinen Bestand.
**Einlass bestätigen** entwertet ein bezahltes Ticket genau einmal. Wertgutscheine
werden in der Zahlungsaufteilung eines Verkaufs verwendet; Teilbetrag und
Restguthaben werden dort gebucht. Persönliche Vorteile benutzen ihre vorhandene
Artikelzuordnung und ursprünglichen Bewegungen. Zugewiesene Kassenhelfer benötigen
keine allgemeine Website-Bearbeitungsberechtigung.

Ein unbenutzter Kassenverkauf kann nachvollziehbar storniert werden. Ein benutztes
Ticket oder bereits ausgegebener Gutscheinwert verhindert ein vollständiges
Storno. Ein unklarer externer Erstattungsaufruf sperrt das Ticket und hält seinen
Platz bis zum lesenden Nachweis; er wird nicht automatisch erneut gesendet.
Kassenabschluss übernimmt ausschließlich den tatsächlich verbleibenden
Bargeldanteil einmal in die vorhandene Handkasse. Onlinezahlungen und
Gutscheinguthaben werden dabei nicht als zusätzliches Bargeld gezählt.

Native Stripe-Webhooks prüfen Rohdaten-Signatur und Zeitfenster; die Nachricht
liefert nur eine Kennung für die unabhängige Händler-/Checkout-/Intent-/Charge-
Prüfung. Verlorene SumUp-Erstellungsantworten werden nach der originalen Referenz
lesend gefunden; verlorene Stripe-Erstellungsantworten können durch einen
signierten, unabhängig geprüften Sitzungs-Hinweis zugeordnet werden. Unbekannte
Zahlungen werden nicht erneut erzeugt. Nie gestartete abgelaufene Reservierungen
werden in der Verfügbarkeit berücksichtigt, angefangene unklare Zahlungen bleiben
gehalten. Abendkassen-Verkauf endet beim hinterlegten Veranstaltungsende,
bei fehlendem Ende am Ende des ursprünglichen Veranstaltungstages.

Geprüft: 49 Fach-/Originalverträge, 98 tatsächliche Browserprüfungen, 48 zusätzliche
Ticketverträge einschließlich echter Prozess-Konkurrenz und Abbruchwiederaufnahme.
Reale Händlerfreigaben, Online-Erstattungsbedienung, Ticket-Mailversand und weitere
Last-/Gerätevarianten bleiben offen. Der Gesamtauftrag ist noch nicht zu 100 % fertig.

### Zahlungskorrekturen und Testcodes, 12. Oktober 2026

Der gemeinsame Bestelldienst verarbeitet zusätzlich signierte Stripe-Erstattungs-
und Dispute-Meldungen. Auch ohne Bestellmetadaten wird die eigene ursprüngliche
Belastung anhand des bereits verifizierten Nachweises gefunden. Falsche Signatur,
fremde Belastung, anderer Verein/Modus und veränderte Ereigniswiederholung werden
abgewiesen. Passende Korrekturen sperren Eintritt und Gutschein vor der lesenden
Anbieterprüfung. Bei Ausfall bleibt die ursprüngliche Sperre erhalten; Wiederaufnahme
sendet keine neue Zahlung oder Erstattung. Erst derselbe unabhängig bestätigte
Zahlungsnachweis kann dieselben Codes und das unveränderte Restguthaben freigeben.
Ein Kontowechsel ersetzt keinen ursprünglichen Zahlungs-/Signaturschlüssel.

Stripe-Testzahlungen erzeugen ausdrücklich Testtickets und Testgutscheine;
Einlass und Bezahlung realer Kassenverkäufe sind damit gesperrt. Bereits benutzte
Codes, erste Einlasszeit, Originalbelege und Teilguthaben bleiben erhalten.
Ein nach Ablauf bereits anderweitig vergebener Platz wird auch nach späterer
Zahlungskorrektur/Wiederaufnahme nicht erneut ausgestellt. Gemeinsame Checkouts
heißen beim Anbieter Tickets & Gutscheine; QR-Dekodierung ist für die mobile
Kamera auf rund acht Prüfungen pro Sekunde begrenzt.

26 neue Korrekturverträge, jetzt 54 weitere Ticketverträge und die bestehenden
49 Fach-/Original- sowie 98 tatsächlichen Browserverträge bestehen. Acht betroffene
Suiten einschließlich echter Produktionscontroller lokal geprüft; 155 native
Suiten registriert. Keine realen Anbieterzahlungen, Nachrichten oder Actions.
Online-Erstattungsbedienung, Ticket-Mailzustellung, weitere Konto-/Anbieter-/Last-
und Gerätevarianten sowie die übrigen Registeraufgaben bleiben offen.

### Ticket- und Gutscheinbestätigung per E-Mail, 12. Oktober 2026

Neue bestätigte Onlinebestellungen erzeugen gemeinsam mit ihren QR-Originalen
exakt einen dauerhaften Versandauftrag. Der private bestehende `--send`-Worker
verwendet den eigenen Zweck **Tickets und Wertgutscheine** (standardmäßig Vorstand)
und höchstens 20 Nachrichten pro Durchlauf. Gespeicherte ältere Postfachregeln
bleiben unverändert; der neue Zweck wird beim Lesen ergänzt. Keine nachträgliche
Massenmail für alte Bestellungen, kein Versand durch öffentliche Browseraufrufe.

Die Nachricht enthält den ursprünglichen privaten Bestelllink und keine statischen
QR-Anlagen. Die aktuelle Bestellung entscheidet weiterhin über Einlass und Guthaben.
Geänderte Vereinsdomain oder Zahlungssperre blockieren eine wartende Bestätigung.
Ein unklarer SMTP-Ausgang oder abgebrochener Sendeversuch wird nicht automatisch
wiederholt. Die Bestellseite zeigt wartend, versendet, gesperrt oder unklar an und
weist darauf hin, den persönlichen Link aufzubewahren. Testbestellungen sind
auch in der Mail ausdrücklich gekennzeichnet. Kassenverkäufe behalten ihren Belegweg.

25 Versandverträge, 30 gemeinsame Postfachverträge, 49 Ticket-/Gutschein-/Kassen-
verträge und 99 tatsächliche mobile Browserverträge bestehen lokal. Die bisherigen
54 Ticket-, 26 Zahlungskorrektur- und 98 Produktionscontrollerverträge bestehen
weiterhin. Keine echte SMTP-Nachricht, Anbieterzahlung oder GitHub Actions.
Delivery 10 ergänzt ausschließlich die leere Versandtabelle; 156 native Suiten
registriert. Online-Erstattungsbedienung, weitere Anbieter-/Gerätevarianten und
alle übrigen Registeraufgaben bleiben offen; Gesamtauftrag unter 100 %.

Auslieferung ebenfalls geprüft: 168 CLI-Installer-/Upgrade-, 54 tatsächliche
HTTP-Installer- und 119 verschlüsselte Wiederherstellungsverträge bestanden.
Die ursprüngliche Ticket-Mailwarteschlange bleibt bei tatsächlicher Relokation
vollständig erhalten. 3965 Quellen, 189 Installerdateien und 4191 Paketdateien
sind bytegeprüft. Kein neuer vollständiger 156-Suiten-Gesamtlauf behauptet.

### Online-Storno und Erstattung, 12. Oktober 2026

Gemeinsame Onlinebestellungen lassen sich im ursprünglichen Veranstaltungseintrag
vollständig stornieren. Die Liste zeigt zehn Bestellungen pro Seite; bezahlte
Bestellungen erfordern Stornogrund und ausdrückliche Bestätigung. Bereits genutzte
Tickets oder angebrochenes Wertguthaben verhindern die vollständige Erstattung.
Kostenlose und noch nicht gestartete Reservierungen werden ausschließlich lokal
storniert. Kassenverkäufe verwenden weiterhin ihren ursprünglichen Kassenweg.

Vor einem Anbieterschreibversuch wird der eigene Erstattungsauftrag dauerhaft
gespeichert. Der genaue ursprüngliche SumUp-/Stripe-Zahlungsnachweis wird erneut
geprüft; Eintritt und Guthaben bleiben während der Erstattung gesperrt und Plätze
gehalten. Eine verlorene Antwort erzeugt keinen zweiten Erstattungsversuch.
**Erstattung prüfen** liest ausschließlich den ursprünglichen Anbieterstand.
Erst eine unabhängig bestätigte vollständige Erstattung entwertet dieselben Codes
und gibt die Plätze frei; Kunden-, Zahlungs- und Belegoriginale bleiben erhalten.

Delivery 11 ergänzt die eigene Erstattungstabelle. 169 Installer-/Upgrade-,
54 tatsächliche HTTP-Installer- und 120 verschlüsselte Wiederherstellungsverträge
bestehen, einschließlich eines gespeicherten Stornos bei tatsächlicher Relokation.
Die Inventar- und Paketprüfung umfasst 3970 Quellen, 189 Installerdateien und
4196 bytegeprüfte Paketdateien. Keine realen Zahlungen, Mails oder Anbieteraktionen.
Weitere Anbieter-/Geräte-/Lastvarianten, die übrigen 14 Registerpakete, Apps und
abschließende Statusseite bleiben offen; Gesamtstand weiterhin unter 100 %.
