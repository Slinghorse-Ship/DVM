# Menschliche Antworten direkt senden

Im Social Hub sendet **Senden** den eingegebenen menschlichen Text direkt über
den vorhandenen Anbieteradapter. Ein vorheriges Speichern als Entwurf und ein
zusätzlicher Versanddialog sind dafür nicht erforderlich. **Entwurf speichern**
bleibt für tatsächlich bearbeitbare eigene Entwürfe verfügbar. Es gibt keine
vorgefertigte Antwort und keine Veröffentlichung beim Öffnen des Gesprächs.

Facebook- und Instagram-Nachrichten können im selben Gespräch nacheinander
gesendet werden, ohne dass zwischenzeitlich eine neue Nachricht eintreffen muss.
Das aktuelle 24-Stunden-Fenster, die ursprüngliche Verbindung, tatsächlich
bestätigte Gesprächsteilnehmer und die aktuellen Rechte gelten weiterhin.
Instagram-Nachrichten behalten die serverseitige Bytegrenze. Facebook-/Instagram-
Kommentare und öffentliche StadtLand.Funk-Kommentare verwenden ihre eigenen
Adapter und Originalbezüge. StadtLand.Funk erhält keinen privaten Nachrichtenkanal.
Art und Anbieter sind in der Gesprächsansicht weiterhin klar sichtbar.

Jeder bewusst gestartete menschliche Sendevorgang erhält eine eigene Kennung.
Eine exakte angenommene Wiederholung liefert das frühere Ergebnis, auch wenn die
Vorbereitungsbestätigung nach dem COMMIT verloren ging. Geänderte Texte oder
anderweitig verwendete Kennungen werden abgelehnt. Zwei tatsächlich gleichzeitige
PHP-Prozesse bewahren dieselbe Antwort und verursachen genau einen Anbieteraufruf.
Ein bereits gestarteter, unklarer oder abgelehnter Anbieteraufruf wird nicht
automatisch erneut ausgeführt. **Senden** bleibt eine ausdrückliche Nutzeraktion.

Texte bleiben authentifiziert verschlüsselt; auch die Vergleichswerte für
menschliche Eingaben verwenden den privaten Schlüssel. Ein reservierter eigener
Entwurf lässt sich nicht nachträglich als anderer menschlicher Sendevorgang
bearbeiten. Mailantworten und menschliche Antworten haben getrennte Zuordnungen;
die bestehenden Mailversand- und Originalverträge bleiben bestehen.

Angenommene menschliche Antworten erscheinen sofort im lokalen Verlauf mit
ihrem tatsächlichen Ergebnis. Beim späteren Anbieterabgleich wird dasselbe
Remote-Original nicht zusätzlich angezeigt. Private menschliche Antworten sind
vor diesem Abgleich über ihren vollständigen Text auffindbar; ein Suchtreffer
öffnet seine tatsächliche Nachrichtenseite. Kommentare und Nachrichten zeigen
sechs gezählte Gesprächszusammenfassungen pro Seite; Filter, Suche und direkte
Seitenwahl vermeiden lange Listen. Der eigentliche Verlauf bleibt begrenzt.

Fehler erhalten eingegebenen Text, ursprüngliche Kennung und Versionsbezug.
Korrektur und ausdrückliche neue Prüfung sind in beiden Oberflächen angebunden.
Nach einem angenommenen Senden ist das nächste Eingabefeld leer und erhält eine
neue Kennung. Stabile Fokus-/Scrollbedienung verhindert, dass ein erneuter Touch
nach einem Fehler auf den Formularrand fällt. Die Abläufe funktionieren mit
und ohne JavaScript, auf Telefon und Tablet sowie mit doppelter Schriftgröße.

Delivery 12 ergänzt ausschließlich über Installation/Paketupgrade die zunächst
leere Tabelle `native_social_human_sends`. Es werden keine früheren Entwürfe
automatisch gesendet. Bei alten Installationen bleibt der bisherige Entwurfsweg
verfügbar; normaler Aufruf führt keine Schemaänderung aus. Verschlüsselte
Gesamtwiederherstellung erhält ursprüngliche Kennungen, private Vergleichswerte,
Text, Anbieterergebnis und Antwortzuordnung; angenommene Wiederholungen dürfen
danach keine neue Anbieteraktion auslösen.

32 Fachprüfungen, zwei echte parallele PHP-Prozesse, angenommener COMMIT-Verlust
und direkte persönliche StadtLand.Funk-Kommentarverträge bestehen. 164 tatsächliche
Browserprüfungen nutzen beide Controller und beide Formularwege: 32 Formularaktionen,
16 genaue angenommene/geänderte HTTP-Wiederholungen, 24 mobile Schrift-/Ansichtsstände
und genau 28 synthetische Meta-Antworten. Drei zusätzliche UI-Datenbankprüfungen
belegen Anzahl, vollständige frühere Originalzeilen und erhaltene ursprüngliche
Fragebytes. Zwölf betroffene native Fach-/Rollen-/Routinggruppen bestehen. Dies
ist kein vollständiger neuer 188-Suiten-Lauf und keine reale Anbieterabnahme.

```sh
DVM_SOCIAL_HUMAN_SENDS_BROWSER=1 bash tools/ci/local-native.sh --core native-social-human-sends
bash tools/ci/local-native.sh --core native-installer native-webspace-installer native-recovery native-package-upgrade native-restore-resume
```
