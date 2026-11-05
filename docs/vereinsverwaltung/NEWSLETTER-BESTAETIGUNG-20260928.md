# Newsletter: Bestätigung erneut anfordern – 28.09.2026

## Funktionsumfang

Unter Website → Newsletter sowie Empfänger & Kontakte gibt es den Bereich
„Anmeldung nochmal bestätigen“. Der aufklappbare Versandknopf nennt die Zahl
der erreichbaren unbestätigten Anmeldungen. Ein zusätzliches, nicht vorbelegtes
Bestätigungskästchen ist Pflicht. Die Aktion verwendet den bestehenden
geschützten Website-Schreibzugriff, Formularschutz und Newsletter-Mailkanal.

Der Knopf plant individuelle Bestätigungsmails ein; der bestehende
Newsletter-Hintergrundlauf verarbeitet höchstens 20 Erinnerungen pro Lauf.
Die Veröffentlichung selbst plant und sendet keine Mails.

Die Mail verwendet das vorhandene Kulturhus-Design mit K-Bild und rechtlichen
Footerlinks. Sowohl HTML als auch Klartext erklären ausdrücklich:

> Öffnen Sie den Link und klicken Sie anschließend auf der Website auf
> „Newsletter bestätigen“. Erst dann ist Ihre Anmeldung abgeschlossen.

Der gleiche Hinweis steht jetzt auch in der normalen Erstanmeldung.
Die Erinnerungsmail hat die Schaltfläche „Jetzt nochmal bestätigen“.

## Prüfung und Veröffentlichung

Lokal erfolgreich:

- 46 neue synthetische Prüfungen (Auswahl, Berechtigungen, Wiederholung,
  Ablaufzeit, Widerruf, Ungewissheit, Hintergrundlimit, Text und Darstellung).
- 23 bestehende Newsletter-Datenschutz-/Anmeldeprüfungen.
- 11 bestehende Formularzustandsprüfungen.
- 37 bestehende Anrede-/Gestaltungsprüfungen.
- 6 bestehende Versandkanalprüfungen.
- PHP-Syntaxprüfung aller sechs veröffentlichten Dateien.

Auf dem Live-Webserver erfolgreich, ausschließlich mit Fake-Speicher und
abgefangenem Mailtransport: 46 Erinnerungs- und 23 Anmeldeprüfungen mit dem
tatsächlich veröffentlichten PHP-Code und Bestätigungstemplate. Autoload
der neuen Klasse geprüft. Kein Aufruf des produktiven Workers zum Testen.

Gezielt sechs Newsletter-Dateien in der vorhandenen Veröffentlichung
`20260920-event-vouchers-0808e04b` bzw. unter `httpdocs/site` ausgetauscht.
Live-Quelldateien zuvor gegen die geprüfte Basis verglichen; geschützte
Runtime-, Datenbank-, Bilanz- und Einstiegspunktdateien unverändert.
Nachweis: `audit/newsletter-reminders-20260928.json`.
Rücknahmefähige Sicherung nur der ersetzten Quelltexte, keine Datenbankkopie.

Lesende Live-Sichtprüfung im angemeldeten Administrator-Browser:
11 unbestätigte Anmeldungen, Versandbereich aufklappbar, Zweischritt-Hinweis,
leeres Pflichtkästchen und Senden-Knopf sichtbar. Kästchen nicht angekreuzt,
Formular nicht abgesendet. Keine echten Kontakte zu Testzwecken geändert.

Nicht geprüft: tatsächliche SMTP-Zustellung einer Erinnerungsmail an reale
Empfänger; absichtlich kein Sammelversand. Die synthetischen Tests ersetzen
keinen echten Nutzertest. Dies ist ausschließlich ein Newsletter-Update und
kein Bilanz-Gesamtrelease.
