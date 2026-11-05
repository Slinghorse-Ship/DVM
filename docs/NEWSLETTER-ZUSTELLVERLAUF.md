# Vollständiger Newsletter-Zustellverlauf

Social & Newsletter → Newsletter-Verlauf zeigt alle ursprünglichen Zustellaufträge.
Die Übersicht ist gezählt und zeigt sechs Zustellungen pro Seite. Status und
Mailart lassen sich auswählen: Newsletterausgabe, Testausgabe, Anmeldung,
Bestätigungserinnerung und erneute Bestätigung. Ein Original-Direktlink öffnet
seinen tatsächlichen Abschnitt, auch hinter mehreren Hundert späteren Aufträgen.

„Zustellung öffnen“ zeigt Empfänger, gespeicherten Betreff, tatsächlichen Stand,
Planungstag, Versandversuch, Abschluss und gegebenenfalls die Zustellkennung.
Der ausgewählte Auftrag steht oben; die Übersicht und Versandplanung sind
einklappbar. „Gespeicherter Mailinhalt“ öffnet den damaligen Text. Öffnen,
Filtern und Seitenwechsel versenden keine Mail und ändern keine Originale.
Die Oberflächenbeschriftungen, Zustände und festen Fehlergründe folgen Deutsch
oder Englisch. Gespeicherte Betreffzeilen, ursprüngliche Titel und Mailtexte
bleiben wörtlich. Der Verlauf behält beim Filtern und Blättern den ausgewählten
Ursprungsmonat; die Uhrzeiten beziehen sich auf Europe/Berlin.

English: **Newsletter history** shows the original deliveries. Filter by status
and message type, then use **Open delivery** to inspect the exact original.
Paging and filtering preserve the origin month and send no message. The saved
subject, original edition title and message text remain unchanged.

Neue Ausgaben und Tests binden die genaue eingefrorene Newsletterfassung mit
ID, ursprünglicher Fassung und SHA-256. Eine spätere Bearbeitung ersetzt diesen
Bezug nicht. Abgelaufene öffentliche Testlinks sperren den privaten Nachweis
nicht. Veränderte oder fehlende ursprüngliche Snapshots werden ausdrücklich
abgewiesen; aus der gegenwärtigen Ausgabe wird kein Ersatzoriginal hergestellt.
Ältere Testmails ohne Fassungsbezug bleiben als gespeicherte Mail lesbar.

Persönliche Bestätigungs-, Abmelde- und Newsletterlinks im Text sind beim Lesen
ersetzt. Der tatsächliche private Zustellauftrag bleibt unverändert. Eine
öffentliche Abmeldung löscht bereits entfernte Empfänger- und Maildaten auch
für diese Ansicht; alte Ausgaben stellen sie nicht wieder her. Änderungen der
Zustelldaten während des Abrufs und aktuelle Rechteänderungen werden vor der
Rückgabe geprüft. Es gelten die aktuellen Newsletter-Leserechte des Vereins.

Eine erneute Bestätigung hat vor der eigentlichen Worker-Vorbereitung noch
keinen Mailinhalt. Dieser wird nicht vorweggenommen. Ein alter unterbrochener
SMTP-Vorgang wird nach zehn Minuten als Ergebnis offen markiert; Newsletter,
Tests, Anmeldungen und Erinnerungen werden nicht automatisch erneut verschickt.
Das bestehende Verhalten für Presse bleibt erhalten. Originale verschlüsselte
Aufträge und eingefrorene Fassungen bleiben bei der Gesamtwiederherstellung
erhalten. Für eine Testausgabe mit 300 Zeichen Titel ist der zusätzliche
Testpräfix auch in der echten MIME-Aufbereitung zugelassen.

Die Ansicht lädt höchstens einen verschlüsselten Mailinhalt gleichzeitig.
Ein separater nativer PHP-Prozess mit 16 MB liest sechs große Originale und
eine vollständige ausgewählte Mail mit mehr als 700 KB Text. Daraus folgt
keine Zusage für den Speicherbedarf des gesamten Vereinsmanagers.

Die lokale Abnahme nutzt eigene synthetische Datenbanken, echte beide
Controller, tatsächliche Newsletter-/Bestätigungsaufträge und einen echten
Worker-Prozessabbruch. Zustellstände stammen aus diesen privaten Aufträgen;
ein Live-SMTP-Nachweis wird durch die lokale Prüfung nicht behauptet.
