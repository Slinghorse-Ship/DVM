# Ausgeblendete Meta-Kommentare im Social Hub erkennen

Der bestehende Abgleich liest Facebooks `is_hidden` beziehungsweise Instagrams
`hidden`. Diese Angabe gelangt jetzt auch in das verschlüsselte Kommentaroriginal
und dessen Fingerprint. Wechsel zwischen sichtbar und ausgeblendet erhalten
einen eigenen ursprünglichen Versionsstand. Vorige vollständige Zeilen und ihre
verschlüsselten Texte bleiben in der bestehenden privaten Originalhistorie.
Wiederholter unveränderter Abgleich erzeugt keine neue Fassung.

„Kommentar ausgeblendet“ erscheint direkt im zugehörigen Gespräch. Für Instagram
werden Senden und ein neues leeres Antwortformular ausgeblendet, da der bisherige
unabhängige Anbietercheck solche Antworten bereits ablehnt. Die aktuelle
Serverprüfung bleibt verbindlich. Facebook darf weiterhin eine menschliche
Antwort anbieten: Sein eigener Anbietercheck prüft `can_comment` unabhängig.
Private Nachrichten, StadtLand.Funk und Reaktionen erhalten keine erfundene
Moderationsangabe. Historische Datensätze ohne diese Angabe werden nicht beim
Lesen umgeschrieben. Bereits eingegebener Text im bestehenden Fehlerweg bleibt
erhalten; eine Moderationsanzeige erzeugt keine Antwort.

Nichtboolesche gelieferte Werte werden vor dem Speichern abgewiesen; auch ein
explizites `null` wird nicht zu „sichtbar“ umgewandelt. Fehlt das Feld vollständig,
behält die ältere Normalisierung ihren bisherigen Standard. Eine unbekannte
Anbieterantwort wird nicht als neue erfolgreiche Moderationsaktion dargestellt.

24 Fach- und eine ganze UI-Originalprüfung sowie 81 Browserprüfungen bestehen.
Der vollständige kanonische Abgleich verwendet 24 synthetische reine Leseaufrufe;
Facebook- und Instagram-Originale durchlaufen sichtbar/ausgeblendet/sichtbar.
Frühere komplette Datenzeilen sind nach Rücklesen exakt, unabhängig von der
JSON-Schlüsselreihenfolge. Falsche Typen verändern keine ursprüngliche Zeile.
Beide tatsächlichen Oberflächen mit und ohne JavaScript, 48 Ansichten bei
320/390/820 Pixeln mit einfacher/doppelter Schrift; keine POSTs.

Sieben betroffene native Gruppen sind zusammengenommen grün: neue
Kommentarstatusprüfung, Social Hub, Aufmerksamkeit, Kommentarantworten,
Originalhistorie, Meta-Webhooks und direkte menschliche Antworten. Der neue
Archivvergleich wurde nach Korrektur seiner Sortierannahme separat erneut
geprüft. Bestehendes tatsächliches Antworten besteht zusätzlich mit 164
Browserprüfungen, 32 Formularaktionen und 28 ausschließlich synthetischen
Meta-Schreibaufrufen. Keine Live-Kommentarmoderation, Nachricht oder Zahlung.

Aktives Aus- und Einblenden beim Anbieter ist inzwischen als eigenes geprüftes
[Moderationsfeature](META-KOMMENTAR-MODERATION.md) ergänzt mit ausdrücklich ausgewähltem Kommentar, aktuellen Rechten,
ursprünglicher Verbindung, unabhängigem Ergebnisnachweis und sicherer
Abbruchbehandlung. Diese Anzeige behauptet keine solche Schreibaktion oder
reale App-/Anbieterabnahme. Die öffentlich gelesene
[Facebook-Kommentarreferenz](https://developers.facebook.com/docs/graph-api/reference/comment/)
führt `is_hidden` als booleschen Seitenkommentarwert; der tatsächliche
Instagram-Lesevertrag verwendet den bereits vorhandenen Adapter.
