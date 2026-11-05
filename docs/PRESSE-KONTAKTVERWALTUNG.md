# Pressekontakte verwalten

Presse → Empfänger zeigt sechs Kontakte pro Seite. Name, E-Mail und Medium sind
durchsuchbar; aktive und inaktive Kontakte lassen sich getrennt anzeigen.
Ein Kontakt wird oben geöffnet; die Übersicht bleibt einklappbar. Konten mit
Leserecht sehen die ursprünglichen Angaben ohne Bearbeitungs- oder Sammelaktionen.

Name, E-Mail, Medium und Aktivstatus werden am vorhandenen Kontakt bearbeitet.
Eine korrigierte E-Mail erzeugt keinen neuen Kontakt. Bei einer bereits vergebenen
Adresse oder einer inzwischen geänderten Version bleiben die Eingaben und die
ursprüngliche Formularversion erhalten. „Aktuellen Stand öffnen“ lädt ausdrücklich
die gegenwärtigen Angaben. Ein unvollständig übertragenes Formular ändert nichts.
Ein neuer Kontakt lässt sich nach einem Fehler mit erhaltenen Angaben korrigieren.

Checkbox-Auswahl bleibt beim Suchen, Seitenwechsel und Öffnen eines Kontakts
erhalten. „Ausgewählt“ zeigt diese Kontakte. „Auswahl aktivieren“ und „Auswahl
deaktivieren“ ändern höchstens 100 genau ausgewählte Kontakte gemeinsam.
Alle ursprünglich ausgewählten Versionen werden in einer Transaktion geprüft.
Ein Konflikt oder ein fremder Kontakt verändert keine der anderen Auswahlzeilen.
Bereits passende Status erzeugen keine neue Version oder einen neuen Zeitstempel.
Die eigene erfolgreiche Bearbeitung aktualisiert die dabei selbst geprüfte
Auswahlversion; später angezeigte Fremdänderungen werden nicht automatisch übernommen.

Aktuelle Presseschreibrechte gelten bei jedem Speichern. Namen, Adressen und
Medien bleiben bei einer gemeinsamen Statusänderung erhalten. Ein vorgemerkter
Presseversand prüft vor dem eigentlichen SMTP-Weg den aktuellen Kontakt und
stoppt nach Deaktivierung. Das ursprüngliche verschlüsselte Mail-, Empfänger-
und Freigabeoriginal bleibt erhalten. Es erfolgt kein automatischer Neuversand.

Die Umsetzung ergänzt keine Tabelle oder Modulversion und importiert keine
alten Kontakte. Fachprüfungen nutzen eigene MySQL-Datenbanken, zwei tatsächlich
gleichzeitige Prozesse und vorhandene private Versandoriginale. Beide Controller
werden mit echten Formularaktionen, Touch, Tastatur, doppelter Schrift und ohne
JavaScript geprüft; echte Nachrichten werden dabei nicht versandt.
