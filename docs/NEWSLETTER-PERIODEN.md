# Newsletterperioden im gemeinsamen Manager

Unter **Newsletter → Planung & Verlauf** kann ein Kalenderplan eingerichtet
werden. Januar, April, Juli und Oktober bereiten eine Quartalsausgabe vor, die
anderen Monate eine Monatsausgabe. Standard sind sieben Vorbereitungstage vor
Monatsbeginn um 10 Uhr und ein Versandfenster vom Monatsersten um 10 Uhr bis
zum Ende des dritten Tages. Vorbereitungstage (1–21) und Versandstunde (0–23)
sind einstellbar. Alle dargestellten Uhrzeiten gelten für Europe/Berlin.

Der private Worker erstellt einen neuen Entwurf aus den veröffentlichten
Originalfassungen der Veranstaltungen. Ein nachträglich geänderter unveröffentlichter
Entwurf, eine Absage und ein Termin außerhalb der Periode gehören nicht hinein.
Ohne passende Termine entsteht keine leere Ausgabe. Späte Läufe eröffnen kein
neues bereits abgelaufenes Versandfenster. Vorbereitung und Kalenderansicht
verschicken keine Nachricht; Vorschau, Freigabe und Einplanung bleiben die
vorhandenen ausdrücklichen Schritte.

Gesamtplan und einzelne Periode haben getrennte Versionen und Pausen. Eine
Periodenpause kann bereits vor der Vorbereitung gesetzt werden. Sie hält auch
bereits eingeplante Aufträge an und erhält deren originale Fassung. Nach dem
Fortsetzen gelten wieder aktuelle Rechte, Einwilligungen und Versandfenster.
Abgelaufene Fenster werden als abgebrochen mit eigenem Grund festgehalten.
Unklare SMTP-Ausgänge bleiben unklar und werden nicht automatisch erneut gesendet.

Erst ein bestätigter erfolgreicher Versand einer regulären Quartalsausgabe zählt
als Ankündigung. Der Nachweis enthält die damalige Veranstaltungs-ID, Revision,
Beginn, URL-Pfad und den ursprünglichen Versandauftrag. Nur tatsächlich in der
freigegebenen Ausgabe enthaltene Bausteine werden berücksichtigt. Nachfolgende
Monatsausgaben lassen dieselben angekündigten Veranstaltungstermine aus; neue
Termine und Veranstaltungen mit geändertem Beginn bleiben berücksichtigungsfähig.
Ein Testversand, ein bloßer Entwurf oder ein unklarer Transport ist kein solcher
Nachweis.

Vorbereitung, Beginn und Ende des Versandfensters erscheinen auch im gemeinsamen
Kalender. Eine vorhandene Ausgabe öffnet ihre originale Redaktion; sonst führt
der Kalender zur Planung. Änderungen und Pausen wirken sofort. Die Projektion
erzeugt weder Termine noch Zustellaufträge und gibt keine Empfänger, Tokens oder
verschlüsselten Mailinhalte aus. Nach Speichern einer Planung bleibt der Manager
in derselben Planungsansicht.

Bestehende separate Monats-/Quartalspläne behalten ihre gespeicherten Schlüssel.
Sie sind sichtbar und pausierbar; der neue Kalenderplan ersetzt sie nicht heimlich.
Gleichzeitig aktive Kalender- und getrennte Pläne werden verhindert, damit nicht
zwei Periodenausgaben aus denselben Quellen vorbereitet werden.

Nachweise: 44 native MySQL-Prüfungen mit veröffentlichten CMS-Originalen,
synthetischem Transport und zwei unabhängigen gleichzeitigen Workern; 16 echte
Kalender-/Rechteprüfungen; vorhandene Zustellungs-, Erinnerungs-, Kalender- und
Produktionscontroller-Regressionsprüfungen; angemeldeter Browser bei 1440/820/390
Pixeln mit Pausen-Rundlauf, Versionskonflikt, Tastatur, Rollenabweisung und
Originalverweisen. Die automatische DOI-Erinnerung und manuelle erneute
Bestätigung sind unterschiedliche Regeln; die historischen manuellen
48-Stunden-/Massenaktionen sind damit noch nicht vollständig ersetzt.
