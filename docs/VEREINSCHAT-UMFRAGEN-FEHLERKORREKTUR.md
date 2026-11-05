# Eigene Chat-Umfragen nach einem Fehler korrigieren

Eine abgewiesene Umfrage bleibt geöffnet. Frage, sämtliche selbst geschriebenen
Antwortzeilen, optionales Ende in Europe/Berlin und die ursprüngliche
Sendeidentität bleiben erhalten. Doppelte Antworten oder ein ungültiger Termin
lassen sich unmittelbar korrigieren. Ein ungültiger Datumswert bleibt als Text
sichtbar und bearbeitbar. Es gibt keine vorgegebenen Antworten.

Eine abgewiesene Stimme behält die gewählte Antwort und die tatsächlich
abgesendete Stimmversion. Die Oberfläche übernimmt keine neuere Version in ein
altes Formular. „Aktualisieren“ öffnet bewusst den aktuellen Stand; mit
JavaScript schützt vorher die vorhandene Rückfrage ungespeicherte Änderungen.
Die gespeicherte Stimme wird dabei wieder angezeigt. Für eine bereits beendete
Umfrage zeigt die normale Fehleransicht die abgelehnte Auswahl ausdrücklich
als ungespeichert und bietet keine erneute Stimmabgabe an. Die native
JavaScript-Ansicht erhält nach einer Ablehnung die ursprüngliche Eingabe;
ein bewusstes Aktualisieren zeigt das inzwischen beendete Original.

Die laufende Aktualisierung ersetzt keinen korrigierten Umfrageeintrag mit
ungespeicherter Auswahl. Ältere Umfragen behalten ihren ursprünglichen
Nachrichtenausschnitt. Eine verlorene erfolgreiche Anlage- oder Stimmantwort
lässt sich mit derselben Anfragekennung wiederholen; bestehende Originale
werden dabei wiederverwendet. Rechte und Teilnahme werden weiterhin aktuell
geprüft. Gespeicherte Umfragen, Stimmen und ihre Versionen werden nicht migriert.

Lokal geprüft: 29 Fachverträge, acht Prüfungen tatsächlich gespeicherter
Browseroriginale und 103 Browserverträge in beiden Anwendungen, mit und ohne
JavaScript. 33 HTTP-Aktionen, 24 Ansichten bei 320/390/820 Pixeln und einfacher
beziehungsweise doppelter Schrift; echte angenommene COMMIT-Verluste für Anlage
und Stimme, verlorene HTTP-Antwort, genaue Wiederholung, Parallel-Stimmgruppen,
Vereinsgrenzen und Rollen. Acht betroffene native Suiten sowie die ursprünglichen
`chat-polls.php` und `chat-polls-race.php` bestehen. Es wurden keine externen
Nachrichten oder Gerätehinweise versendet.

Der Paketumzug prüft sämtliche ursprünglichen Frage-/Antwort-/Stimm-/Anfrage-
zeilen sowie eine alte Stimmversion mit ausgewählter Antwort. Das korrigierte
Formular und die abgewiesene alte Stimme verändern keine wiederhergestellte
Zeile. Installer, HTTP-Installer, verschlüsselte Verlagerung, Paketupgrade und
Fortsetzung: 231/52/209/144/27 Prüfungen, 90 Upgrade-Browserprüfungen sowie
drei echte Prozessabbrüche. Der erste zusätzliche Umzugsfall hatte eine falsch
zugeordnete synthetische Person; nach Korrektur ist die Wiederherstellung separat
geprüft, die vier übrigen Paketgruppen bestanden bereits im gemeinsamen Lauf.
Kein zweiter kompletter Paketgruppenlauf behauptet.
