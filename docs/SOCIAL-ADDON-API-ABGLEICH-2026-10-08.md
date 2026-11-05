# Social-Erweiterung: Anbieter- und API-Abgleich

Arbeitsauftrag ergänzt am 8. Oktober 2026: Alle eigenen Instagram-/Facebook-Posts,
Reels und verfügbaren Stories sichtbar machen, Bilder bearbeiten, modernes
Social-Modul einschließlich möglicher Nachrichtenfunktionen. Keine simulierten
Erfolge und keine Ersatz-Mockups für nicht verfügbare Dienste.

## Aktuell gelesene Quellen

Die folgenden Seiten wurden am 8. Oktober tatsächlich per HTTPS abgerufen und
inhaltlich gelesen. Anbieterangaben belegen vermarktete Funktionen, keine
API-Freigabe für unser konkretes Konto.

| Quelle | Relevante Funktionen / Schlussfolgerung |
|---|---|
| [Buffer Publish](https://buffer.com/publish) | Ideenbibliothek, kanalübergreifende Entwürfe, Kalender, Planung und Teamarbeit. Unser Ablauf erhält sichtbare Originalfassungen und getrennte Kanalzustände. |
| [Metricool Planner](https://metricool.com/social-media-planner/) | Inhaltskalender, Bearbeitung, Vorschauen, Freigaben und Inbox. Übernehmen: übersichtliche Inhaltsbibliothek, Planung, Freigabe und Bearbeitung im Vereinsmanager. |
| [Hootsuite Publishing](https://www.hootsuite.com/platform/publishing) | Gemeinsamer Kalender, Listen, Freigaben, Bibliothek, Posteingang, Insights und bezahlte Inhalte. Unser Modul zeigt nur tatsächlich abgerufene Kennzahlen; Werbekosten werden nicht automatisch ausgelöst. |
| [Instagram Content Publishing](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing) | Professionelles Konto, gekoppelte Facebook-Seite und passende Berechtigungen erforderlich. Bild-Publishing unterstützt **JPEG**, keine beliebigen PNG-Bilder. Medien müssen öffentlich erreichbar sein. Asynchrone Container werden vor Veröffentlichung unabhängig geprüft. |
| [Instagram Media](https://developers.facebook.com/docs/instagram-api/reference/ig-user/media) | Bilder, Carousel, Stories und Reels. Story-Link-/Umfrage-/Standort-Sticker werden nicht über die Publishing-API zugesagt. Stories verfallen nach 24 Stunden, Container nach 24 Stunden. `alt_text` betrifft Bildposts, keine Reels/Stories. |
| [Facebook Reels Publishing](https://developers.facebook.com/docs/video-api/guides/reels-publishing/) | Echte Video-Datei, Upload-Sitzung, eigener Upload-Host und Abschluss. Bestehende Reels werden über `/{page-id}/video_reels` gelesen. Ein Bild ist kein Reel. |
| [Instagram Messaging](https://developers.facebook.com/docs/messenger-platform/instagram) | Separate Nachrichtenberechtigungen, App-Review und zulässige Antwortfenster. Kein unbegrenztes Anschreiben beliebiger Instagram-Konten. |

## Konten, eigene Veranstaltungen und Antwortprüfung (9. Oktober)

Unter **Website → Vereinsdaten & Verbindungen → Konten & Verbindungen** sind
Meta und StadtLand.Funk jederzeit bearbeitbar und trennbar. Geheimnisfelder sind
leer; beim gleichen Ziel behalten sie den verschlüsselten Zugang. Ein anderes
Meta-Ziel benötigt einen neuen Token. MySQL enthält authentifiziert verschlüsselte
Tokens und optional das wiederverwendbare StadtLand.Funk-Passwort; der private
Schlüssel liegt außerhalb des Webbereichs. Managerpasswörter bleiben Passwort-Hashes.
Es gibt keine angenommene 30-Tage-Frist: `expires_in` und `refresh_expires_in`
des Anbieters bestimmen die gespeicherten Fristen. Zuerst wird erneuert; nur bei
bestätigt abgelaufener Erneuerung erfolgt einmal eine Anmeldung mit gespeichertem
Passwort. Netzwerkfehler lösen keine Anmeldeversuche aus. Zusätzliche Login-Schritte
stoppen die Verbindung. Kontowechsel und Trennen verwerfen private Kanal-Caches,
pausieren dessen Abrufplan und verhindern die Nutzung früherer Kontobindungen.

**Social → Veranstaltungen** bietet zwei Quellen: veröffentlichte Websiteoriginale
für eigene/importierte Termine und eigene interne/externe Vereinskalendertermine.
Die Kalenderquelle verwendet die aktuellen Kalenderrechte unabhängig von den
Website-Rechten. Suche, kommende/archivierte Termine und Seitenaufteilung lesen
die ursprünglichen Kalenderdatensätze. Vor der Übernahme eines Kalendertermins
wird bestätigt, dass er für einen Social-Beitrag bestimmt ist. Titel, Termin,
Ort, Beschreibung und ursprünglicher Absagestatus werden als privater,
ungeprüfter Entwurf übernommen; Teilnehmer, Rückmeldungen, Berichtsnotizen und
private Managerlinks bleiben im Kalender. Die konkrete Originalfassung wird
gespeichert; spätere Terminänderungen oder Löschung ändern diesen Entwurf nicht.
Eine zwischenzeitlich geänderte Fassung muss neu ausgewählt werden. Freie
Vereinsbeiträge bleiben möglich. Vorbereitung erstellt keinen Veröffentlichungsjob.

Der Quellenweg besteht 51 MySQL-Prüfungen, darunter aktuelle Kalenderrechte,
vereinsfremde Originale, Originalschutz, veraltete Fassungen und eine unabhängig
parallel geänderte Kalenderfassung. 77 tatsächliche Social-Browserprüfungen
umfassen beide Quellen, die verpflichtende Kalenderbestätigung und den
bearbeitbaren Entwurf bei 1440/820/390 Pixeln. Alle acht bestehenden Kalender-
Regressionsgruppen einschließlich `event-workflows.php` bestehen unverändert.

**Social → Kommentare** führt verschlüsselte Kommentare, eingehende
Nachrichten und tatsächliche Reaktionsänderungen zur menschlichen Entscheidung
zusammen: prüfen, Antwort erforderlich oder erledigt. Unveränderte Abrufe behalten
diese Entscheidung, geänderte Kommentare öffnen sie erneut. Das Markieren sendet
keine Antwort. Persönliche eigene Veranstaltungen und ihre Kommentare werden vom
PHP-Adapter ohne Browser/Partner-Schlüssel gelesen; eigene Antworten und gelöschte
Kommentare zählen nicht als neue eingehende Kommentare. Meta-Abrufe können nach
einem eigenen API-Intervall rund um die Uhr erfolgen. StadtLand.Funk erhält in
jeder Stunde des Tageszeitraums einen neu zufällig gewählten Zeitpunkt; Vorgabe
08:00–22:00 Europe/Berlin. Der Worker läuft jede Minute, ruft den Dienst aber nur
zur tatsächlichen Fälligkeit auf. Verpasste Stunden erzeugen keine Abrufserie. Anbieterpausen und aktuelle Rechte haben Vorrang.
Der getrennte private Aufruf `--social-check` liest nur; er versendet und veröffentlicht
nichts. [Hosting-Zeitplan](NATIVE-HINTERGRUNDJOBS.md).

Die Gestaltung wurde am 9. Oktober mit erneut gelesenen
[Buffer Publish](https://buffer.com/publish),
[Buffer Engage](https://buffer.com/engage),
[Hootsuite Publishing](https://www.hootsuite.com/platform/publishing) und der
[offiziellen Instagram-Publishing-Dokumentation](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing/)
abgeglichen: gemeinsame Bibliothek, Kalender, Freigaben und Kommentarübersicht,
mit konkreten Kanalzuständen. Vermarktete Funktionen anderer Produkte sind keine
Bestätigung unserer Meta-Berechtigungen.

Die vier neuen MySQL-Suiten prüfen Kontoänderungen, PKCE/Token-Erneuerung,
Originalentwürfe, private Aktivität, Abruffenster, Anbieterpausen, Rechteentzug und
Kontowechsel ausschließlich mit synthetischen Verträgen. Der angemeldete Browser
prüft Originalauswahl, geheime leere Felder, private Rollenrechte, Speichern und
Layout bei 1440/820/390 Pixeln. Das ist keine Live-Abnahme neuer Provideraktionen.

Der Meta-Kommentarabgleich liest die ursprünglichen Kommentare und vollständig
paginierten Antwortstränge. Facebook verwendet die Seite als Autorbindung,
Instagram eine unabhängig gelesene und exakt gebundene Kontoidentität.
Eigene Kommentare und bekannte gebundene Antwortkennungen bleiben im privaten
Verlauf; die Antwortbedarfsprüfung enthält eingehende Fragen und Folgefragen.
Unveränderte Folgefragen behalten die menschliche Entscheidung, bearbeitete
Folgefragen öffnen sie erneut. Fehlgeschlagene Antwortseiten, überlappende IDs,
fremde Konten und aktueller Rechteentzug können den vorherigen vollständigen
verschlüsselten Stand nicht ersetzen. Der eigene Leseadapter `MetaComments`
verkleinert den bisherigen Social-Hub-Dienst. 50 Social-Hub-Vertragsprüfungen
und mindestens 46 tatsächliche Antwort-/Kommentarstrang-Browserprüfungen bei
drei Breiten belegen diesen Weg ausschließlich mit synthetischen Anbieter- und
Kontodaten; normale Abrufe veröffentlichen und antworten weiterhin nichts.

### Kommentarantworten mit exakter Freigabe

Unter **Kommentare → Gespräch öffnen → Entwurf speichern** wird ein privater,
verschlüsselter Entwurf gespeichert. **Antwortentwürfe** zeigt ursprünglichen
Kommentar, genauen gespeicherten Antworttext und Verlauf. Speichern versendet
nichts. Der ausdrückliche Versand benötigt aktuelle Social-Verwaltungs- und
Veröffentlichungsrechte. Vorher werden aktueller Originaltext, Kommentarstatus,
Vereins-/Kontobindung und Fassung erneut geprüft. Facebook verwendet
`/{comment-id}/comments`, Instagram `/{ig-comment-id}/replies`; ein unabhängiger
Abruf der tatsächlichen Antwort bestätigt Kennung und genauen Text.

Ein dauerhaft gespeicherter Versandbeginn verhindert doppelte Antworten bei
Neustart und gleichzeitiger Bedienung. Netzwerkverlust, 5xx und fehlende
unabhängige Bestätigung bleiben **unklar**; ein erneuter Versand ist gesperrt.
Mit vorhandener Anbieterkennung liest „Anbieterergebnis prüfen“ ausschließlich
die bestehende Antwort. Ohne Kennung muss die Originalunterhaltung extern
geklärt werden. Vollständige Graph-4xx-Fehler gelten als Ablehnung; erst bewusstes
Bearbeiten/Speichern macht den Entwurf wieder versandbereit. Bestätigte Antworten
schließen nur unveränderte Einträge der Antwortprüfung. Original- oder
Kontowechsel können keine ältere Antwortfassung umleiten.

Am 9. Oktober wurden die offiziellen
[Facebook-Kommentarverträge](https://developers.facebook.com/docs/graph-api/reference/object/comments/)
und [Instagram-Kommentarantworten](https://developers.facebook.com/docs/instagram-api/reference/ig-comment/replies/)
erneut tatsächlich gelesen (jeweils HTTP 200). Facebook benötigt Seitenmoderation
und `pages_manage_engagement`; Instagram passende Kommentar-/Konto-/Seitenrechte.
Verborgene Instagram-Kommentare und `can_comment=false` bei Facebook werden vor
dem Schreiben gestoppt. Instagram-Live-Kommentarantworten bleiben ein eigener
Nachrichtenweg. Anbieter-Vertragsdaten und Browserentwürfe sind synthetisch; der
neue Antwortweg wurde nicht auf einem echten Konto versendet oder als Live-
Freigabe für unser Konto ausgegeben. StadtLand.Funk-Kommentarantworten sind
inzwischen mit eigenem persönlichem Adapter und unabhängigem Ergebnisnachweis
ergänzt: [Bedienung und Grenzen](STADTLANDFUNK-KOMMENTARANTWORTEN.md).
Direkte private Meta-Nachrichtenantworten sind ergänzt: [Verträge und Grenzen](META-NACHRICHTENANTWORTEN.md). Moderation und Webhooks bleiben weitere Arbeitspakete.

Eigener Dienst `Communication/Replies`, eigene Ansicht `Web/Communication/Replies`;
privater Bestand `native_social_replies`. Die ausdrückliche Delivery-Schemaversion
3 ergänzt den Bestand beim bestehenden Installer-/Schemaweg. Keine automatische
Schemaveränderung beim normalen Webrequest. 50 Backendprüfungen und 37 reale
Browserprüfungen belegen Entwurf-/Originalschutz, parallelen Versand, Neustart,
Rechteentzug nach gespeichertem Versandbeginn, Unicode und drei Bildschirmbreiten.

### Bibliotheksprüfung

Der private Anbieterabgleich liest vollständige paginierte Antworten für Facebook-Beiträge/Reels und Instagram-Medien/Stories. Verein und aktuelle Rechte werden vor dem Speichern erneut geprüft. Kennzahlen werden nur nach unabhängiger Abfrage angezeigt; unbekannte Werte bleiben unbekannt. Kommentare und Nachrichten werden verschlüsselt gespeichert. Nachrichteneinsicht erfordert Verwaltungsrechte; aktuelle gebundene Gespräche haben einen ausdrücklich bestätigten direkten Antwortweg mit 24-Stunden-Fenster und unabhängiger Ergebnisprüfung. Live-Meta-Rechte sind separat abzunehmen. Vorschaubilder werden nur von begrenzten Meta-CDN-Zielen gelesen, geprüft, neu als PNG kodiert und über einen privaten Vereinsendpunkt ausgegeben.

Tatsächlich ausgeführt: 28 Backendprüfungen, 19 Browserprüfungen für Suche, echte Bildbytes, Filter, 24/5-Seitenaufteilung, drei Bildschirmbreiten und verweigerten Nachrichtenzugriff sowie 335 Navigationsprüfungen. Synthetische Anbieter-Vertragsdaten sind als Testdaten gekennzeichnet. Diese Prüfungen belegen keine Live-Meta-Berechtigungen.

## Umsetzungsumfang

- Redaktion: kanalbezogene Inhalte, lokale Originalbilder, Bildausschnitt,
  Vereinsgestaltung, QR-Ziel, exakte Freigaben und revisionsgebundener Verlauf.
- Bibliothek: native Entwürfe und tatsächlich importierte Beiträge/Reels/Stories
  mit Original-Link, sichtbarem Aktualitätsstand, Filter und Seitenaufteilung.
- Planung: Kalender und Liste aus gespeicherten Aufträgen; keine künstlichen
  Reichweiten-, Aktivitäts- oder Erfolgszahlen.
- Veröffentlichung: private Hintergrundaufträge, aktuelle Rechte und Kontoziele,
  unabhängige Containerprüfung, gespeicherte Anbieterkennungen und Abgleich
  unklarer Ergebnisse. Eigene und importierte Veranstaltungen können als Beiträge
  vorbereitet werden; ein Facebook-Event-Schreibweg ist damit nicht bestätigt.
- Community: vorhandene Kommentare und Nachrichten separat und geschützt;
  Antworten benötigen eine ausdrückliche Freigabe und ein zulässiges API-Fenster.
- Insights und Verbindungen: verfügbare Funktionen durch echte Konto-/API-Prüfung
  feststellen; nicht eingerichtete oder nicht freigegebene Funktionen benennen.
- Reels und Carousels benötigen einen eigenen, geprüften Medienpfad und passende
  Freigaben; ein einzelnes Bild wird nicht als Video oder Carousel ausgegeben.

Noch keine Live-Verbindung, App-Review, Hardware- oder Produktionsabnahme.
Die vorhandenen automatisierten Social-Prüfungen verwenden synthetische Bilder,
isoliertes MySQL und injizierte Antworten zum API-Vertrag. Sie versenden und
veröffentlichen nichts auf einem echten Konto.
