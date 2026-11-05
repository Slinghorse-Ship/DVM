# StadtLand.Funk als zusätzlicher Veranstaltungskanal

Auftrag: Veranstaltungen mit Foto, Ort und Details über StadtLand.Funk bereitstellen; gegebenenfalls Kommentare lesen und beantworten. Der Nutzer bevorzugt einen direkten API-Zugang wegen Datenverbrauch und Laufzeitkosten des Browserworkers. Am 9. Oktober 2026 wurde auf ausdrücklichen Nutzerauftrag eine tatsächliche Kontoanmeldung geprüft. Zugangsdaten und Tokens gehören in sichere Umgebungseinstellungen beziehungsweise verschlüsselte Vereinskontoeinstellungen und bleiben außerhalb Git.

Die Web-App lädt einen React-Native-Web/Expo-Client. Der öffentliche Client verweist auf `login.digitale-doerfer.de` sowie Dienste unter `prod.digitale-doerfer.de`. Das beweist einen technischen Backendzugang der Web-App, aber keinen dokumentierten API-Vertrag für unsere Integration.

## Abgleich mit der neuen Webversion, 12. Oktober 2026

Die vom Nutzer bestätigte Adresse bleibt
[www.stadtlandfunk.app/login](https://www.stadtlandfunk.app/login).
Nach anfänglichen Verbindungszeitüberschreitungen antworteten die Webadresse und
die OIDC-Metadaten mit HTTP 200. Der frisch mit PKCE S256, eigener State und den
nativen Parametern abgerufene Login liefert weiterhin `kc-form-login` und die
Formularaktion `/realms/DD/login-actions/authenticate`. Der unangemeldete
API-Aufruf `/app/onboardingStatus` liefert HTTP 401, keine Kontodaten.

Geprüfter aktueller öffentlicher Client:
`/_expo/static/js/web/index-232d50067c14391d9653bde072070885.js`,
7.565.796 Bytes, SHA-256
`1444d4f96029e372c9e952b067cb343f7fde10968adbbe349c79e18775a894f7`.
Der geladene Fremdcode wurde nur gelesen, nicht ausgeführt oder ins Paket kopiert.

| Vertrag der aktuellen Web-App | Abgleich mit DVM |
|---|---|
| Realm `DD`, Client `stadtlandfunk`, Rücksprung `https://www.stadtlandfunk.app/`, Authorization Code/PKCE, Scopes `openid profile email offline_access` | Unverändert; OIDC S256 und das aktuelle Loginformular öffentlich bestätigt |
| API `https://prod.digitale-doerfer.de`, Header `appVariantIdentifier: de.fhg.iese.dd.dorffunk.slf`, Konto-Bearer | Unverändert; keine Umstellung auf den Namen der Mobil-App |
| Eigene Termine `GET /grapevine/post/own`, `type`, `page`, `count`, `content` | Aktueller Client verwendet **`page.number`, `page.size`, `page.totalElements`, `page.totalPages`**; nativer Adapter und lesender Veröffentlichungsabgleich entsprechend angepasst |
| Onboarding `ownPerson`, `homeGeoArea`, `homeGeoAreaSelectionStatus`, `onboardingRequired` | Verwendete Felder unverändert |
| Kommentare `GET /grapevine/comment?postId=…`, Liste von Kommentaren | Verwendete Felder und Antwortform unverändert |
| Foto `POST /media/temp`, Multipart `file`; Antwort `id`, `mediaItem`, `expirationTime` | Verwendeter Vertrag unverändert |
| Termin `POST /grapevine/event/happeningCreateRequest` mit Text/Titel, Millisekundenzeiten, `temporaryMediaItemIds`, `customLocationDefinition`, `hiddenForOtherHomeAreas` | Verwendete Felder unverändert; Antwort weiter `post`/`postId` |
| Antwort `POST /grapevine/comment/event/commentCreateRequest`, `postId`, `replyToCommentId`, `text`; Rückgabe `comment`/`commentId` | Verwendeter Vertrag unverändert |
| Originalverweis `/post-detail?id=…` | Unverändert |

Der gemeinsame PHP-Leser akzeptiert das aktuelle PagedModel und das vorher
beobachtete Spring-Page-Format. Neue Metadaten müssen ganzzahlig sein, zur genau
angeforderten Seite und Seitengröße 50 passen und die Anzahl aller gelieferten
Originale bestätigen. Maximal vier Seiten/200 Termine; widersprüchliche,
unvollständige, doppelte oder zwischen Seiten veränderte Angaben stoppen den
Abgleich. Ein vorhandenes ungültiges `page` fällt nicht still auf alte Angaben
zurück. Bei einem abgebrochenen Anlageversuch bleibt die Wiederaufnahme rein
lesend; kein zweiter Veröffentlichungsversuch.

Fünf betroffene lokale MySQL-/HTTP-Vertragssuiten bestehen mit 893 Prüfungen:
44 Konten, 146 Aktivität, 251 Antworten, 362 Veröffentlichung und 90 Planung.
Darunter zwei vollständige neue Seiten mit 51 ursprünglichen Terminen,
ursprüngliche Kommentar-/Jobdaten bei fehlerhaften Antworten und alle bisherigen
Antwort-/Veröffentlichungsschutzfälle mit dem aktuellen Format.

Der Nutzer stellte anschließend einen Zugang für die aktuelle Prüfung bereit.
Der tatsächliche native PHP-Login, Codeaustausch, Kontoabruf, Token-Refresh und
erneute Prüfung derselben Identität bestanden. Eigene Veranstaltungen und
Kommentare lieferten HTTP 200. Die echte Antwort ist **PagedModel** mit einer
Seite und einem eigenen Termin; der neue native Seitenleser akzeptiert sie.
Alle folgenden Ergebnisse stammen aus dieser angemeldeten Leseprüfung:

| Liveprüfung der aktuellen Version | Ergebnis |
|---|---|
| PKCE-Loginformular / Formularanmeldung / Codeaustausch | HTTP 200 / 302 / 200 |
| Konto vor und nach Token-Erneuerung | HTTP 200, unveränderte Konto-/Heimatgebietszuordnung |
| Refresh | HTTP 200 |
| Eigener Veranstaltungskatalog | HTTP 200, `page.number=0`, `size=50`, `totalElements=1`, `totalPages=1` |
| Reaktionsdaten des Termins | `likeCount=0`, `liked` vorhanden; `commentCount=0`, `commentsAllowed=true` |
| Kommentare dieses eigenen Originals | HTTP 200, leere Liste |
| Nur die neue Prüfsitzung schließen | HTTP 204 |

Die bereitgestellten Zugangsdaten und Konto-Tokens wurden nur im Speicher des
Prüfprozesses verwendet, nicht in Git, Laufzeitkonfiguration oder Vereinsdatenbank
gespeichert. Anbieteraufrufe schrieben ausschließlich die neue Anmeldung,
Token-Erneuerung und deren Logout. Der vorhandene Termin und seine Reaktionen
bleiben unverändert. Neue Veröffentlichungen und Antwortversand wurden nicht
ausgeführt; Live-Schreibabnahme, Bearbeitung und Anbieterbedingungen bleiben offen.

StadtLand.Funk liefert für diesen persönlichen Termin Likes, keine getrennten
Emoji-Reaktionsarten. `liked` bezeichnet den eigenen Like-Status, keine zweite
Zahl. Der Social Hub übernimmt `likeCount` und `commentCount`; Änderungen der
Like-Zahl werden als Reaktionsaktivität behandelt, unveränderte Zahlen erhalten
die frühere menschliche Entscheidung. Facebook liest die Reaktionssumme,
Instagram Like-/Kommentarzahlen; der Meta-Insights-Weg kann mit den tatsächlich
freigegebenen Rechten zusätzlich Facebook-Reaktionsarten und weitere Kennzahlen
abfragen. Diese Meta-Verbindung wurde hier nicht live geprüft.

Der geprüfte Ablauf beginnt auf [https://www.stadtlandfunk.app/login](https://www.stadtlandfunk.app/login). Die App öffnet den zentralen Digitale-Dörfer-Login **in einem separaten Fenster**. Sie verwendet im Realm `DD` den OpenID-Connect Authorization Code Flow, Client `stadtlandfunk`, PKCE `S256` und eine frisch erzeugte State. Nach Absenden des tatsächlichen Loginformulars gab der Token-Endpunkt HTTP 200 mit Zugangs- und Refresh-Token zurück. Das ursprüngliche App-Fenster öffnete `/tabs/start`. Der Scope der Antwort war `openid offline_access email profile add-audience`. Eine frühere URL mit fester State und festem Code-Challenge darf nicht als wiederverwendbare Anmelde-URL dienen.

Die Zugangsdaten wurden am 9. Oktober bestätigt. Bei dieser ersten Loginprüfung wurde kein Beitrag erstellt, kein Gruppenbeitritt ausgeführt und kein Profil geändert. Der Browserkontext wurde geschlossen, seine Sitzung nicht exportiert; der private Prüfnachweis enthält keine Zugangsdaten oder Tokenwerte. Die nachfolgenden, ausdrücklich beauftragten HTTP-Veröffentlichungen stehen unten. Native Kontoverwaltung, PHP-Leseadapter und originalgebundene Veröffentlichungswarteschlange sind inzwischen implementiert und lokal geprüft.

## Direkter Zugriff ohne laufenden Browser

Der öffentliche Web-Client vom 9. Oktober 2026 enthält einen generierten HTTP-API-Client für `https://prod.digitale-doerfer.de`. Folgende Verträge wurden zunächst ohne schreibende Aufrufe aus diesem tatsächlich geladenen Client gelesen. Der persönliche Veröffentlichungsweg wurde anschließend auf Nutzerauftrag ausgeführt:

| Zweck | Beobachtete Schnittstelle | Zugang und Inhalt |
|---|---|---|
| Veranstaltung einer Nachrichtenquelle anlegen/aktualisieren | `POST /grapevine/event/happeningPublishRequest` | Header `apiKey`; `externalId`, `newsURL`, `authorName`, `title`, `text`, `startTime`, `endTime`, `imageURLs`, `venue`, `venueLocationDefinition`, `geoAreaIds`, `organizationId` und weitere optionale Felder. Antwort ordnet `externalId` einer `postId` zu. |
| Persönliche Veranstaltung anlegen | `POST /grapevine/event/happeningCreateRequest` | Konto-Token; Titel, Text, Beginn/Ende, Ortsdefinition und temporäre Medien-IDs. |
| Foto für einen Kontobeitrag hochladen | `POST /media/temp` | Konto-Token; Multipart-Feld `file`, Antwort mit temporärer Medien-ID. |
| Kommentare eines Beitrags lesen | `GET /grapevine/comment?postId=…` | Konto-/API-Berechtigung; die konkrete Berechtigung einer Nachrichtenquelle ist noch nicht abgenommen. |
| Kommentar oder Antwort schreiben | `POST /grapevine/comment/event/commentCreateRequest` | Konto-/API-Berechtigung; `postId`, `text`, bei Antwort `replyToCommentId`, optional Medien-/Dokument-IDs. |

Der Nutzer hat keinen kostenpflichtigen API-/Nachrichtenquellen-Zugang und möchte diesen nicht voraussetzen. Die Nachrichtenquellen-Schnittstelle ist deshalb nur ein dokumentierter möglicher Zusatzweg, keine Voraussetzung des geplanten DVM-Kanals. Ein normales Benutzerpasswort ist kein Nachrichtenquellen-Schlüssel. Die Methode „CreateOrUpdateByNewsSource“ ist im Client vorhanden; Wiederholung und tatsächliche Aktualisierung wären gesondert abzunehmen.

Für den benutzerbasierten Weg bietet der Loginserver Authorization Code/PKCE und Refresh-Tokens. Die tatsächliche Kontoanmeldung hat einen persönlichen Access-Token und Refresh-Token geliefert. Einen allgemein gültigen oder unbegrenzt gültigen Konto-Token gibt es nicht. Konto-Token gelten für ihre Identität, Audience, Laufzeit und aktuellen Rechte. Persönlicher Kontozugriff, Token-Erneuerung, Fotoupload, Veranstaltungsanlage, Auslesen, Löschung und Sitzungslogout wurden tatsächlich bestätigt. Veranstaltungsbearbeitung, Kommentarantworten und die erlaubte dauerhafte Nutzung außerhalb der App bleiben zu prüfen.

## Tatsächlich geprüfter persönlicher HTTP-Zugang

Am 9. Oktober 2026 wurde der persönliche Weg mit ausdrücklichem Nutzerauftrag
ohne gestarteten Browser und ohne Partner-API-Schlüssel ausgeführt. Die Anmeldung
verwendet dieselben veröffentlichten OIDC-Parameter wie die App: Client
`stadtlandfunk`, registrierte App-Rücksprungadresse, frische State, eigenen
PKCE-Verifier mit S256, Cookie-erhaltener Aufruf des zentralen Loginformulars und
anschließender Codeaustausch. Das ist kein allgemeiner Token und kein Password
Grant. Der direkte Kontozugriff und der anschließende Refresh-Token-Austausch
lieferten HTTP 200. API-Aufrufe verwenden `Authorization: Bearer …` und den
öffentlichen App-Identifikator `appVariantIdentifier: de.fhg.iese.dd.dorffunk.slf`.
Dieser Identifikator ist kein Konto-Token oder kostenpflichtiger API-Schlüssel.

Auf ausdrücklichen Auftrag wurde **Red House Sound - Pop, Indie, Jazz und Folk**
mit unverändertem Originaltitel, ohne sichtbare Testkennzeichnung, dem
[ursprünglichen Termin](https://www.kulturhus.de/veranstaltungen/red-house-sound---pop--indie--jazz-und-folk-1999665882484/)
und dessen Foto temporär veröffentlicht und anschließend wieder gelöscht.
Beginn/Ende waren 14. November 2026, 20:00–22:00 Uhr Europe/Berlin; Ort Ev.
Gemeindezentrum Jaderberg, Kastanienallee 2, 26349 Jade. Es wurde keine bestehende
Veranstaltung verändert oder gelöscht.

| Ausgeführte Prüfung | Ergebnis |
|---|---|
| Originalfoto hochladen | HTTP 201; 155.742 Bytes JPEG, temporäre Medien-ID |
| Persönliche Veranstaltung anlegen | HTTP 200, neue eigene Original-ID |
| Neuen Beitrag unabhängig auslesen | HTTP 200; gleicher Titel/Text, Beginn/Ende, ein Bild, vollständige Anschrift, Veranstaltungsort und Kontoziel Jaderberg |
| Kommentare des eigenen Beitrags lesen | HTTP 200, leere Kommentarliste; `commentsAllowed=true` |
| Neu angelegten Beitrag löschen | HTTP 200; anschließender Einzelabruf HTTP 404, eigene Terminliste enthält ihn nicht mehr |
| Nur die neue Prüfsitzung schließen | OIDC-Logout HTTP 204; private Konto-Tokens und einmalige Zugangsdaten entfernt |

Der direkte persönliche Weg ist damit für Anmeldung, Token-Erneuerung,
Veranstaltungsanlage mit Foto/Ort/Details, Auslesen und Löschung technisch
bestätigt. Antworten auf Kommentare wurden nicht veröffentlicht und bleiben
eine eigene Abnahme. Der private Nachweis enthält die konkrete neue Kennung und
die Originalhashes; Zugangsdaten, Konto-Tokens und interne Konto-/Medienkennungen
stehen nicht in Git. Dieser Einzelversuch ist noch keine fertige DVM-Anbindung
oder eine Bestätigung der Anbieterbedingungen.

### Auf erneuten Auftrag online belassener Termin

Der Nutzer beauftragte anschließend ausdrücklich die erneute Veröffentlichung
derselben Veranstaltung **ohne anschließende Löschung**, um sie selbst online
anzusehen. Am 9. Oktober 2026 wurde sie mit erneut geladenen Originalangaben
einmal veröffentlicht und bleibt online:
[Red House Sound auf StadtLand.Funk](https://www.stadtlandfunk.app/post-detail?id=c09ccae7-38ef-4317-9e11-b688b92dedde).

Veröffentlichen und unabhängiger Einzelabruf lieferten HTTP 200. Titel, Text,
Foto, Beginn/Ende und Zielgebiet Jaderberg stimmen mit dem Original überein;
auch die eigene Veranstaltungsliste und die regionale Liste liefern den Beitrag
aus. Die regionale Kontrolle verwendet den tatsächlichen Beitragsfeed ohne
einen fälschlich als Veranstaltungsdatum angenommenen Zeitfilter. Anschließend
wurde ausschließlich die neue Anmeldesitzung beendet (HTTP 204) und deren
private Tokens entfernt; der Termin wurde nicht gelöscht. Für den App-Link kann
eine Anmeldung nötig sein. Weitere Veröffentlichungen oder Antworten wurden
nicht beauftragt; diesen bestehenden Termin nicht automatisch erneut anlegen
oder löschen.

Ein eigener freigegebener OAuth-Client könnte zusätzlich eine interaktive DVM-Kontoverbindung ermöglichen. Der vorhandene App-Client hat die Rücksprungadresse der StadtLand.Funk-App; eine DVM-Rücksprungadresse wurde nicht bestätigt. Die Realm-Metadaten nennen auch den Password Grant, belegen aber nicht dessen Freischaltung für diesen Client. Der oben geprüfte browserlose Erstlogin verwendet stattdessen Authorization Code/PKCE und das vorhandene Loginformular. Zusätzliche Anmeldeschritte müssen eine Verbindung stoppen und ausdrücklich nachgeholt werden.

Die API-Schemaseiten `/v3/api-docs` und `/v2/api-docs` antworten ohne Anmeldung mit HTTP 401. Ein allgemein zugänglicher dokumentierter Partnervertrag ist damit noch nicht nachgewiesen. Der Betreiber [Smartes Land](https://smartesland.de/) beschreibt die gemeinsame Plattform und nennt im [offiziellen Organisationshinweis](https://ticket.digitale-doerfer.de/help/de-de/44-stadtland-funk/149-wie-kann-meine-organisation-in-stadtland-funk-angezeigt-werden) die Kontaktstelle für die Zuordnung einer Organisation. Die Anfrage nach API-/Nachrichtenquellen-Zugang und Kommentarrechten wurde nicht an Dritte versendet.

Die native Kontoverwaltung und der persönliche PHP-Leseadapter sind vorhanden:
unter Website-Verbindungen anmelden, Zugang ändern oder trennen. MySQL enthält
verschlüsselte Tokens und optional das wiederverwendbare Passwort, keine
Passwort-Hashes für diesen Anbieterzugang. DVM-Userpasswörter bleiben gehasht.
Tokenfristen stammen konkret aus `expires_in` und `refresh_expires_in`; keine
pauschale 30-Tage-Annahme. Erst Refresh, dann ausschließlich bei bestätigtem
Ablauf einmalige erneute Anmeldung; Netzwerkfehler und zusätzliche Login-Schritte
lösen keine weitere Anmeldung aus. Persönliches Konto, Heimatgebiet, Verein,
aktuelle Rechte und die konkrete Kontoverbindung werden erneut geprüft.
Die Verbindung allein bestätigt noch keine native Veröffentlichungswarteschlange.

Eigene persönliche Veranstaltungen und Kommentare werden ohne Browser gelesen
und im gemeinsamen Social Hub angezeigt. Private Kommentare sind verschlüsselt;
eigene Antworten und gelöschte Kommentare zählen nicht als neue Rückfrage.
Zwei Tagesfenster morgens/abends und optional mittags verwenden wechselnde Zeiten
in Europe/Berlin. Verpasste Fenster werden nicht nachgeholt. Anbieterpausen gehen
vor. Meta hat einen davon unabhängigen offiziellen API-Prüfabstand.
[Bedienung, Konten und Quellen](SOCIAL-ADDON-API-ABGLEICH-2026-10-08.md),
[privater Hosting-Aufruf](NATIVE-HINTERGRUNDJOBS.md).

Die 44 Konto-, 47 Aktivitäts-/Plan- und 58 persönlichen Leseprüfungen verwenden
isoliertes MySQL und synthetische HTTP-Verträge. 54 Browserprüfungen bestätigen
geheimnisfreie Formulare, Kontoänderung/Trennen, private Rechte, eigene
Veranstaltungsentwürfe und drei Bildschirmbreiten. Neue Anbieteraktionen wurden
hierbei nicht live ausgeführt; der ausdrücklich online belassene Termin bleibt
unverändert. Ein dauerhafter Playwright-Worker ist keine Voraussetzung.

Vor Veröffentlichung wird ein dauerhafter Versuch gespeichert. Ein Timeout nach dem Absenden führt zu „Ergebnis unklar“, nicht zur erneuten Veröffentlichung. Erst ein unabhängig lesbarer Beitrag mit passendem Kontoziel, Inhalt und Medien bestätigt Erfolg. Fehlende oder widerrufene Rechte stoppen den Job. Konto-Tokens und API-Schlüssel gelangen nicht in öffentliche Assets oder Git.

Originalgebundene Veröffentlichungswarteschlange, Wiederaufnahme-/Duplikatschutz und persönliche Kommentarantworten sind inzwischen nativ implementiert und lokal geprüft. Die neue Webversion ist am 12. Oktober angemeldet lesend geprüft. Offen bleiben echter Antwortversand, erneute Live-Schreibabnahme, Bearbeitung und dauerhafte Anbieterbedingungen. Kein kostenpflichtiger Partnerzugang und kein dauerhafter Browser als Voraussetzung. Die erste ausdrücklich beauftragte Originalveröffentlichung wurde vollständig zurückgenommen; die zweite bleibt nach neuer ausdrücklicher Weisung online. Die gemeinsame Social-Oberfläche verbindet Facebook, Instagram und StadtLand.Funk mit den je Kanal tatsächlich verfügbaren Aktionen.
