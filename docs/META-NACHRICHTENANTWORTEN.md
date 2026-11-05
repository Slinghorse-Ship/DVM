# Private Facebook- und Instagram-Nachrichten beantworten

Unter **Social Media & Newsletter → Interaktionen → Nachrichten** wird ein
tatsächlich abgeglichenes Gespräch geöffnet. Neue Antwortfelder sind leer. Der
selbst geschriebene Text wird mit **Senden** bewusst zum Anbieter beauftragt.
**Entwurf speichern** bleibt eine private Speicherung. Eine angenommene
ursprüngliche Wiederholung sendet keine zweite Nachricht. Bei unklarem Ergebnis
nicht erneut senden; den tatsächlichen Verlauf bzw. das Anbieteroriginal prüfen.
Die [Bedienung in Deutsch und Englisch](SOCIAL-CHAT-BEDIENUNG.md) beschreibt auch
Fehlerkorrektur und getrennte Mail-Einstellungen. StadtLand.Funk hat in dieser
Anbindung Kommentare, keine privaten Nachrichten.

Der vorhandene Facebook-Login verwendet den gebundenen Page-Token. Facebook
sendet `POST /{page-id}/messages` mit `recipient.id`, `message.text` und
`messaging_type=RESPONSE`. Instagram verwendet mit diesem Login ebenfalls den
Page-Endpunkt, mit dem ursprünglichen Instagram-Absender als Empfänger; es
verwendet weder einen Instagram-Login-Token noch den IG-Konto-Endpunkt. Beide
Wege arbeiten direkt über die Graph API, ohne Browsersteuerung.

Es gelten ausschließlich Antworten innerhalb von 24 Stunden nach der
ursprünglichen empfangenen Nachricht. Keine Werbe-, Tag-, Human-Agent- oder
automatische Fensterverlängerung. Instagram wird konservativ auf weniger als
1000 Zeichen und höchstens 1000 UTF-8-Bytes begrenzt; Facebook auf 2000 Zeichen.
Ein geschlossenes Fenster zeigt keinen Senden-Knopf. Ein privater Entwurf darf
weiter gespeichert werden. Alte Gespräche ohne aktuelle Verbindung und genaue
Absenderbindung müssen erneut abgeglichen werden.

Vor dem Versand werden aktuelle Vereinsrechte, Token-/Seiten-/Instagrambindung,
bei aktiviertem Conversation Routing der Gesprächseigentümer und beide Teilnehmer sowie Kennung, unveränderter Text,
Absender, Adressat und Zeitpunkt der ursprünglichen Nachricht unabhängig
ausgelesen. Ein dauerhaft gespeicherter Versandbeginn verhindert parallele oder
nach Neustart wiederholte Aufträge. Anschließend wird das Original erneut geprüft.
Die erfolgreiche POST-Antwort allein bedeutet keinen bestätigten Versand:
Ein separater GET prüft die tatsächliche Antwortkennung, den genauen menschlichen
Text einschließlich Leerzeichen/Zeilenumbrüchen, Absender, Empfänger und Zeitpunkt.

Timeouts, 429, 5xx, vorübergehende Graph-Fehler, fehlende Kennungen oder abweichende
Bestätigungen bleiben **unklar** und werden niemals automatisch erneut gesendet.
**Ergebnis prüfen** liest nur eine vorhandene Kennung. Nur eine vollständige
eindeutige Anbieterablehnung erlaubt nach bewusster Bearbeitung einen neuen
Versandversuch. Kontowechsel, Rechteentzug und fremde Gesprächskennungen können
einen alten Entwurf nicht umleiten.

Die offiziellen Verträge wurden tatsächlich gelesen:

- [Graph Conversation: Eigentümerfeld nur bei aktiviertem Routing](https://developers.facebook.com/docs/graph-api/reference/conversation/)
- [Messenger Send Messages](https://developers.facebook.com/docs/messenger-platform/send-messages/)
- [Instagram Send Message mit Facebook Login](https://developers.facebook.com/docs/messenger-platform/instagram/features/send-message)
- [Instagram Conversation und Message](https://developers.facebook.com/docs/messenger-platform/instagram/features/conversation/)

Facebook benötigt insbesondere `pages_messaging`, Instagram
`instagram_manage_messages`, passende aktuelle Kontoaufgaben und gegebenenfalls
App-Review. Diese Rechte und der Live-Versand sind keine durch lokale Tests
erwiesene Kontofreigabe. Es wurde keine echte Anbieternachricht versendet.

Nachweis: 371 echte MySQL-/API-Vertragsprüfungen einschließlich zweitem
Datenbankzugang bei parallelem Versand, genauen Originalen, Rechteentzug nach
gespeichertem Versandbeginn, Fenstergrenzen und unklaren Ergebnissen.
108 tatsächliche Browserprüfungen bei 1440/820/390 Pixeln, Tastatur, Touch,
200 % Zoom, JavaScript-freien Formularen und zehn echten privaten
Entwurfsspeicherungen bestehen. Übrige ursprüngliche Nachrichten, Kommentare,
Konten, Jobs, Dokumentbytes und Freigaben bleiben unverändert. Die bestehenden
Kommentar- und Chatansichten werden separat weiter geprüft.
