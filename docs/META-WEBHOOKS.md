# Optionaler Meta-Empfang

Der Social Hub kann Facebook- und Instagram-Webhooks empfangen. Die Funktion ist
zunächst ausgeschaltet. Unter **Website → Design & Verbindungen → Meta-Webhooks**
lassen sich Empfang, App-Secret und eigener Verifikationstoken einstellen. Eine
bestehende Meta-Verbindung und Verwaltungsrechte sind erforderlich. Gespeicherte
Schlüssel bleiben verschlüsselt; leere Schlüsselfelder behalten sie beim selben
verbundenen Konto. Ein Kontowechsel braucht neue Schlüssel und eine neue Callback-
Adresse. Abschalten bleibt auch nach dem Trennen der Meta-Verbindung möglich.

Die angezeigte Callback-Adresse und der eigene Verifikationstoken müssen in der
Meta-App eingetragen und die benötigten Felder dort abonniert werden. Eine lokal
aktivierte Einrichtung bestätigt keine App-Freigabe, Berechtigung oder tatsächliche
Anbieterverfügbarkeit. Die Anzeige unterscheidet deshalb den Schalter, den letzten
signierten Eingang und den letzten bestätigten API-Abgleich. Ein Administrator
muss die echte Meta-App und ihre Berechtigungen separat einrichten und abnehmen.

GET-Verifikation prüft den eigenen Token. POST-Eingänge benötigen eine gültige
`X-Hub-Signature-256` über die unveränderten Originalbytes. Der Empfang ist auf ein
MiB, eigene verbundene Konten und begrenzte Ereignislisten beschränkt. Er erzeugt
verschlüsselte, vereinsgebundene Leseaufträge. Wiederholungen und andere JSON-
Schlüsselreihenfolgen erzeugen kein zweites Original. Eingänge erstellen keine
Antwort, Veröffentlichung oder Zahlung.

Der vorhandene private `native-delivery-worker.php --social-check` verarbeitet
diese Aufträge zusätzlich zum bisherigen Social-Abgleich. Er liest die aktuellen
Originale über Meta: persönliche Unterhaltungen, Kommentare und verfügbare
Beitragszähler. Facebook und Instagram sowie Nachricht, Kommentar und Reaktionen
bleiben getrennt. Benachrichtigungstext wird nicht als menschliche Chatnachricht
übernommen. Antworten laufen weiterhin über den vorhandenen ausdrücklich
ausgelösten Nachrichten-/Kommentarweg. Nicht öffentlich lesbare oder bei Meta noch
nicht sichtbare Originale bleiben offen; fehlende Messwerte werden nicht als
erfundene Nullen dargestellt.

Ein deaktivierter, gesperrter oder nicht mehr berechtigter Einrichter stoppt neue
Eingänge und Anbieterlesungen. Kontozuordnung und Rechte werden vor dem Lesen und
vor dem Abschluss erneut geprüft. Ein unterbrochener reiner Leseauftrag darf
wiederaufgenommen werden. Nach begrenzten erfolglosen Versuchen bleibt er zur
Prüfung stehen. Die eigene Eingangsübersicht zeigt sechs Metadaten je Seite,
direkte Seitenwahl und eine gezielte Wiederaufnahme; gespeicherte Schlüssel und
private Nachrichtentexte werden dort nicht ausgegeben.

90 Fachprüfungen und 126 tatsächliche Browserprüfungen bestehen. Die Browserprüfung
nutzt beide Controller mit und ohne JavaScript, 18 tatsächliche Formularaktionen
und 24 Handy-/Tablet-/200-Prozent-Schriftansichten. Reale anonyme HTTP-Callbacks
prüfen Signatur, Challenge, Größenlimit, doppelte Zustellung und Vereinsgrenzen;
weitere HTTP-Angriffe bestätigen weiterhin geschützte Verwaltungsformulare.
30 Anbieterlesungen verwenden ausschließlich einen eigenen synthetischen
Transport. Angenommener verlorener COMMIT und ein tatsächlich getöteter Worker
sind geprüft. Die verschlüsselte Wiederherstellung erhält Schlüssel, ursprüngliche
Aufträge, Kontobindung und Einstellungsverlauf und erkennt dieselbe Zustellung.

Kommunikation 15 ergänzt drei zunächst leere Tabellen. Chat 2, Workflows 2,
Auth 6, CMS 17, Delivery 11 und Identity 4 bleiben erhalten; Paketgeneration
2026101314, 184 registrierte native Suiten. Kein neuer vollständiger Lauf aller
184 Suiten oder reale Meta-App-/Anbieterabnahme wird behauptet.

```sh
DVM_META_WEBHOOK_BROWSER=1 bash tools/ci/local-native.sh --core native-meta-webhooks
bash tools/ci/local-native.sh --core native-social-hub native-social-message-replies native-meta-worker native-manager-settings native-public-router native-http-gates
```
