# Verbindungen, API-Schlüssel und Herstellerkennungen

Für die **Grundinstallation** werden nur Domain, leere MySQL-Datenbank und ein
eigenes Administratorkonto benötigt. Mitglieder, Dokumente, Inventar, Kalender
und die Vereinswebsite benötigen keine Schlüssel externer Anbieter. Die
folgenden Zugänge werden erst für die jeweils gewählte Zusatzfunktion benötigt.

## Welche Angaben werden gebraucht?

| Funktion | Benötigte Angaben | Zuständig für Beschaffung und Einrichtung |
|---|---|---|
| E-Mails versenden | SMTP-Server, Port, Benutzername, Passwort beziehungsweise App-Passwort, Absender und gegebenenfalls Antwortadresse. | Verein oder Mailbetreuer beim eigenen Mailanbieter. |
| Gemeinsame Postfächer abrufen | IMAP-Server, Benutzername, Passwort beziehungsweise App-Passwort, Postfachordner und gewünschter Beginn des Abrufs. | Verein oder Mailbetreuer; zusätzliche SMTP-Zuordnung für Antworten. |
| FinTS-Bankverbindung | Bankendpunkt, BLZ, gültige **25-stellige Produktregistrierungs-ID** und Produktversion; anschließend persönlicher Bankzugang, PIN und TAN-Verfahren. | Softwareverantwortlicher für Produktregistrierung; Kontobevollmächtigter für den Bankzugang. |
| ELSTER/ERiC | Gültige **fünfstellige Hersteller-ID**, passende ERiC-Laufzeit und Jahres-/Formularpakete; für eine Abgabe eigenes Organisationszertifikat und dessen Passwort. | Softwareverantwortlicher für Herstellerregistrierung/Laufzeit; berechtigte Vereinsperson für Zertifikat und Abgabe. |
| Facebook und Instagram | Facebook-Seiten-ID, gegebenenfalls verbundene Instagram-Profikonto-ID, Graph-API-Version und berechtigter Zugangstoken. | Administrator der Meta-Seite und der zugehörigen Meta-App. |
| Meta-Webhooks | Zusätzlich App-Secret, eigener Verifikationstoken und die von DVM angezeigte Callback-Adresse. | Administrator der Meta-App; Empfang in DVM und Abonnements bei Meta einrichten. |
| Eventbrite | API-Token mit Zugriff auf die eigene Organisation und deren Organisations-ID. | Administrator des Eventbrite-Organisationskontos. |
| SumUp-Kartenzahlung | Händlerkennung, API-Token; für den angeschlossenen Kartenleser zusätzlich Affiliate-App-ID, Affiliate-Schlüssel und Leserkennung. | SumUp-Händler beziehungsweise technischer Betreuer mit passenden Anbieterrechten. |
| Stripe-Zahlungen | Konto-ID, Test-/Live-Modus, privater API-Schlüssel und für den Webhook-Empfang der zugehörige Signaturschlüssel. | Administrator des Stripe-Kontos. |
| PayPal-Zahlungen | Client-ID, Client-Secret, Händlerkennung und Sandbox-/Live-Modus. | Administrator des PayPal-Geschäftskontos und seiner Entwickler-App. |
| Browser-Push | Ein eigenes lokales VAPID-Schlüsselpaar und Kontaktadresse; anschließend ausdrückliche Geräteerlaubnis im Browser. | Technischer Betreiber erzeugt und schützt die Schlüssel; jede Person meldet ihre eigenen Geräte an. |
| Apple Wallet für Mitgliedsausweise | Apple-Team-ID, Pass Type ID und passendes Pass-Zertifikat als P12/PFX einschließlich privatem Signierschlüssel und Dateipasswort. | Apple-Developer-Kontoinhaber; Einrichtung durch den zentralen DVM-Superuser. |
| Google Wallet für Mitgliedsausweise | Wallet-Aussteller-ID, bereits angelegte Generic-Class-ID und Dienstkonto-JSON mit privatem Schlüssel; freigegebenes Ausstellerkonto und Klassenberechtigung. | Google-Wallet-/Cloud-Kontoinhaber; Einrichtung durch den zentralen DVM-Superuser. |

Eine API-Kontoverbindung allein bestätigt noch keine Anbieterfreigabe. Die
benötigten Rechte, erlaubten Aktionen, Laufzeiten und gegebenenfalls
Kosten richten sich nach dem jeweiligen Anbieter und dem eingerichteten Konto.

## FinTS-Produktkennung und ELSTER-Hersteller-ID unterscheiden

Diese beiden Kennungen gehören zu **unterschiedlichen Registrierungen**:

- **FinTS:** `product_id` enthält genau 25 Buchstaben/Ziffern;
  `product_version` höchstens fünf Zeichen. Die Kennung bezeichnet die
  registrierte Banking-Software. Sie ist weder Bank-Benutzername noch PIN/TAN.
- **ELSTER:** `manufacturer_id` enthält genau fünf Ziffern. Sie bezeichnet den
  registrierten Softwarehersteller und ist weder Steuernummer noch
  Zertifikatspasswort.

Der Softwareverantwortliche stellt die für den eingesetzten Produktbetrieb
gültigen Kennungen bereit oder beantragt seine eigene Registrierung. Vereine
verwenden dazu ihre **eigenen** Bankvollmachten und Steuerzertifikate. Kennungen
einer fremden Anwendung dürfen nicht ersatzweise übernommen werden.

Die Kennungen können berechtigte technische Verwalter unter den Bank- und
Steuereinstellungen des Managers bearbeiten. Die privaten Laufzeit- und
Bankendpunktdateien müssen zusätzlich eingerichtet sein. Das Speichern einer
Kennung aktiviert keinen Bankauftrag und keine Steuerabgabe.

Offizielle Informationen:
[FinTS-Produktregistrierung](https://www.fints.org/de/hersteller/faq-produktregistrierung),
[ELSTER für Softwarehersteller/ERiC](https://www.elster.de/elsterweb/entwickler/infoseite/eric).

## Mailzugänge

Im Manager die Postfächer und Versandzwecke unter den E-Mail-Einstellungen
zuordnen. Versand und Empfang haben jeweils eigene Angaben: **SMTP** versendet,
**IMAP** liest Nachrichten. Für ein gemeinsames Postfach müssen beide Wege zur
gewünschten Absenderadresse passen.

DVM verwendet in diesen Feldern Server-/Benutzer-/Passwortzugänge. Unterstützt
der Mailanbieter nur OAuth-Anmeldung, ist die Zugangseignung vorher mit dem
Anbieter zu klären. Ein erforderliches App-Passwort wird beim Mailanbieter
erstellt; das normale persönliche Passwort ist dafür nicht automatisch geeignet.
SMTP-Verbindungen verwenden die vom Anbieter bestätigten TLS-Einstellungen.

Für automatische Zustellung und Abruf die getrennten
[Hosting-Aufgaben](HOSTING-ZEITPLAENE.md) einrichten. Empfangsrechte und Zugriff
auf die gemeinsamen Postfächer werden im Manager vergeben.

## Meta: API-Zugang und optionaler Webhook-Empfang

Unter **Website → Design & Verbindungen → Konten & Verbindungen** werden
`page_id`, `instagram_id`, `api_version` und `token` eingerichtet. Für Instagram
wird das zum verwendeten API-Weg passende verbundene Profikonto benötigt.
Veröffentlichung, Nachrichten, Kommentare und Statistiken brauchen jeweils die
entsprechenden aktuellen Meta-Berechtigungen. Diese werden bei Meta für die App
und die beteiligten Konten beantragt beziehungsweise freigegeben.

Die **Meta-App-ID** wird in der Meta-Entwicklerverwaltung für die App und ihre
Freigaben benötigt; sie ist kein zusätzliches Eingabefeld des DVM-Kontozugangs.
Für optionale Webhooks trägt der technische Verwalter in DVM `app_secret` und
`verify_token` ein. Die angezeigte Callback-Adresse und denselben
Verifikationstoken bei Meta hinterlegen und die gewünschten Ereignisse
abonnieren. Ein API-Zugangstoken ersetzt diese Empfangseinrichtung nicht.
Webhooks melden Änderungen; der Social-Abgleich liest die berechtigten Originale.

[Meta: Graph API](https://developers.facebook.com/docs/graph-api/get-started/),
[Meta: Webhooks](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/),
[DVM-Empfang einrichten](BEDIENUNG.md#social-hub).

## Eventbrite und Zahlungsanbieter

Eventbrite verwendet im vorhandenen Verbindungsformular `organization` und
`token`. Für den eingebauten Terminabgleich wird kein Eventbrite-Webhook-Schlüssel
verlangt. Die Veranstaltungen und der Eventbrite-Ticketverkauf bleiben an das
eigene Organisationskonto gebunden.
[Offizielle Eventbrite-Authentifizierung](https://www.eventbrite.com/platform/docs/authentication).

Die optionalen Zahlungszugänge liegen ebenfalls unter **Konten & Verbindungen**:

- **SumUp:** `merchant_code`, `token`, bei Leserzahlungen `affiliate_app_id`,
  `affiliate_key` und `reader_id`; weitere Leser können benannt hinterlegt werden.
  [SumUp-Entwicklerdokumentation](https://developer.sumup.com/).
- **Stripe:** `account_id`, `mode`, `secret_key`, gegebenenfalls
  `webhook_secret`. Test- und Live-Schlüssel passend zum Modus verwenden.
  [Stripe-API-Schlüssel](https://docs.stripe.com/keys).
- **PayPal:** `client_id`, `client_secret`, `merchant_id`, `mode`.
  Sandbox- und Live-Konto passend zum Modus verwenden.
  [PayPal-REST-Zugänge](https://developer.paypal.com/api/rest/).

Die tatsächliche Anbieterfreigabe, unterstützte Zahlungsart und gegebenenfalls
der Kartenleser werden zusätzlich geprüft. API-Schlüssel nicht zwischen
unterschiedlichen Konten oder Test-/Live-Umgebungen mischen.

## Browser-Push und Mitgliedsausweise im Wallet

**Browser-Push:** VAPID-Schlüssel werden lokal mit der mitgelieferten
Web-Push-Bibliothek erzeugt und als private Betreiberkonfiguration aufbewahrt.
Die Laufzeit erwartet `publicKey`, `privateKey` und `subject`; letzteres ist eine
`mailto:`-Kontaktadresse oder eine HTTPS-Kontaktadresse. Die Grundinstallation erzeugt diese Konfiguration
nicht automatisch. Für diesen Browser-Push-Weg werden keine eigenen
APNs- oder FCM-Entwicklerschlüssel eingetragen. Die Browser melden ihre
Geräteabonnements nach ausdrücklicher Erlaubnis selbst an.
[Technischer Standard VAPID](https://www.rfc-editor.org/rfc/rfc8292),
[DVM-Geräteeinrichtung](BEDIENUNG.md#mail-und-newsletter).

**Wallet:** Unter **Einstellungen → Mitgliedsausweise & Wallet** richtet der
zentrale Superuser die gewünschten Anbieter ein. Der Pass enthält ausschließlich
den Mitgliedsausweis.

Für Apple müssen Pass Type ID, Team-ID und Zertifikat einschließlich privatem
Schlüssel zusammengehören. Ein Apple-Developer-Konto und ein gültiges
Wallet-Pass-Zertifikat werden benötigt.
[Apple: Pass-Zertifikat erstellen](https://developer.apple.com/help/account/certificates/create-a-wallet-pass-certificate/).

Für Google muss die Ausweisklasse außerhalb von DVM bereits angelegt sein.
Das Dienstkonto benötigt Zugriff auf diese Klasse; in DVM werden `issuer_id`,
`class_id` und die Dienstkontodatei hinterlegt. Die Verbindungsprüfung kontrolliert
den Klassenzugriff. Die Freigabe des Ausstellerkontos erfolgt zusätzlich bei
Google und wird im Manager bestätigt.
[Google Wallet: Voraussetzungen](https://developers.google.com/wallet/generic/getting-started/onboarding-guide).

Ein konfigurierter Schlüssel ist kein Nachweis, dass ein bestimmtes physisches
Gerät den Pass bereits angenommen oder eine Push-Nachricht angezeigt hat.

## Zugangsdaten sicher ablegen

Providerzugänge über die vorgesehenen Managerformulare eintragen. DVM speichert
sie verschlüsselt. Private Laufzeitdateien und Signierschlüssel gehören in das
private Installationsverzeichnis außerhalb des öffentlichen Webroots;
Zertifikatspasswörter werden entsprechend dem jeweiligen Ablauf gesondert
eingegeben. Das ELSTER-Zertifikatspasswort wird bei jeder Abgabe neu abgefragt.

Nur symbolische Platzhalter wie `<DEIN_API_TOKEN>`, `<DEINE_FINTS_PRODUKT_ID>`
oder `<DEINE_ELSTER_HERSTELLER_ID>` in Anleitungen verwenden. Die tatsächlichen
Werte gehören weder in Git, Container-Images, öffentliche Downloadpakete noch in
Befehlsargumente. Die verschlüsselten Betriebsschlüssel bei Updates erhalten.
