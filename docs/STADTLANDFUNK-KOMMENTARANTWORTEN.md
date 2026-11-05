# StadtLand.Funk-Kommentarantworten

Unter **Social → Kommentare → Gespräch öffnen** kann eine
eingehende Frage zu einem eigenen abgeglichenen StadtLand.Funk-Termin beantwortet
werden. Der private Entwurf enthält den unveränderten ursprünglichen Kommentar
und den genauen gespeicherten Antworttext. Speichern versendet nichts.

Der Termin muss mit dem aktuellen persönlichen Konto und Heimatgebiet abgeglichen
sein. Ältere Abgleiche ohne Kontobindung oder ursprüngliche Personenkennung
müssen erneut gelesen werden. Wechsel oder Trennen des Zugangs leitet keinen
alten Entwurf auf das neue Konto um. Der Verbindungsverantwortliche benötigt
weiterhin aktuelle Verwaltungsrechte und ein aktives bestätigtes Konto.

**Entwurf senden → Senden** erfordert ein bewusst gesetztes Pflichtkästchen und
aktuelle Social-Verwaltungs-/Veröffentlichungsrechte. Unmittelbar vorher liest
DVM die vollständige Kommentarliste des ursprünglichen Termins erneut und prüft
Kennung, Person, genauen Text und Moderationsstatus. Ein geänderter, gelöschter,
gesperrter oder nicht eindeutig gelesener Kommentar wird nicht beantwortet.
Eigene Antworten gelten nicht als neue eingehende Fragen.

Der beobachtete persönliche App-Vertrag verwendet
`POST /grapevine/comment/event/commentCreateRequest` mit `postId`,
`replyToCommentId` und `text`. Der öffentliche Webclient von
[StadtLand.Funk](https://www.stadtlandfunk.app/) wurde am 10. Oktober 2026 dafür
gelesen. DVM verwendet keinen Partner-Schlüssel und keinen laufenden Browser.
Anbieterzugang und Bedingungen müssen für das jeweilige echte Konto gelten.

Vor dem Schreiben wird der Versandbeginn dauerhaft gespeichert. Ein zweiter
Sender oder Neustart wiederholt die Antwort nicht. Danach liest DVM unabhängig
die Kommentarliste: Nur die genaue zurückgegebene Kennung mit ursprünglichem
Termin, eigener Person, adressierter ursprünglicher Person und exaktem Text gilt
als bestätigt. Erst diese Bestätigung erledigt den unveränderten Prüfeintrag.

Netzwerkverlust, unlesbare Antworten, Serverfehler, Drosselung und fehlende
unabhängige Bestätigung bleiben **Anbieterergebnis unklar**. Sie werden nicht
automatisch wiederholt oder als Erfolg ausgegeben. Mit vorhandener Kennung liest
**Ergebnis prüfen** nur die bestehende Antwort; ohne Kennung muss die
Originalunterhaltung beim Anbieter geklärt werden. Nur ein vollständig lesbarer
bekannter Clientfehler gilt als Ablehnung; bewusstes erneutes Bearbeiten/Speichern
bereitet dann eine neue geprüfte Fassung vor.

Anbieterregeln liegen in `Communication/MetaReplyProvider` und
`Communication/PersonalReplyProvider`, der gemeinsame dauerhafte Ablauf in
`Communication/Replies`. Es gibt keine zweite Warteschlange und keine automatische
Zustellung. Ursprüngliche Kommentare und Antworten bleiben authentifiziert
verschlüsselt. Alte bestätigte Antwortfassungen werden nicht überschrieben.

251 tatsächliche MySQL-/API-Vertragsprüfungen einschließlich paralleler Sender,
Rechteentzug nach gespeichertem Versandbeginn, Kontowechsel während Abruf,
UTF-8-Grenzen und unklarer Ergebnisse bestehen. Die bestehenden Meta-, persönlichen
Lese-, Konto- und Abrufplansuiten bestehen ebenfalls. 47 tatsächliche Browserprüfungen
bei 1440/820/390 Pixeln decken Touch, Tastatur, 200 % Zoom, vier private
Entwurfsspeicherungen, JavaScript-freie Bedienung, konkurrierende Fassungen,
fehlende Versandbestätigung und verweigerten Mitgliederzugriff ab. Anschließend
werden übrige Originale, Freigaben, Bilder, Kontobindungen und Jobs verglichen.

Die Anbieterantworten sind in diesen Prüfungen synthetisch. Es wurde keine echte
Kommentarantwort versendet. Der bereits online belassene Termin wird nicht erneut
angelegt oder gelöscht. Die Veröffentlichungswarteschlange für neue Termine,
Terminbearbeitung und weitere Medien-/Nachrichtenwege bleiben im Register.
