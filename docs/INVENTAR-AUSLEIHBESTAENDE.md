# Kurze Bestandsauswahl für Ausleihen

Das Reservierungsformular lädt sechs ausleihbare Bestände pro Seite statt
sämtlicher Lagerbestände in jede Position. Suchbegriff, Inventarnummer,
Seriennummer und Lagerort werden als wörtlicher Text durchsucht. Die Zahl
oben umfasst alle Treffer. Neue Suche beginnt auf Seite eins.

Blättern und Suchen bereiten nur die Auswahl vor: Kontakt, Vereinbarung,
Termine, Mengen und alle ausgewählten Positionen bleiben erhalten. Jeder
Positionsauswahl stehen sechs aktuelle Treffer und höchstens die eigene
bisherige Auswahl zur Verfügung. Auch außerhalb der aktuellen Suchseite
bleibt diese mit ihrem ursprünglichen Bestandsversionswert erhalten. Ein
ursprünglicher eigener Gegenstand und sein Lagerort bleiben erkennbar;
unbekannte oder fremde Referenzen geben keine fremden Angaben preis.
Es erfolgt keine automatische Aktualisierung einer ausgewählten Version.

Verbrauchsmaterial, Handkassen, gelöschte oder inaktive Gegenstände und
inaktive Lagerorte erscheinen nicht als neue Ausleihbestände. Nur tatsächlich
freie Mengen werden angeboten. Die bestehende endgültige Reservierung prüft
weiterhin Bestand und Version, einschließlich paralleler Veränderungen.

Aktuelle Ausleih-Schreibrechte und gleiche Vereinszuordnung werden erneut
geprüft. Zählung und sechs Zeilen entstehen aus einem eigenen konsistenten
MySQL-Lesestand; bestehende Transaktionen bleiben erhalten. Alle Such- und
Seitenaktionen funktionieren ohne JavaScript und erzeugen keine Reservierung.
Keine Schemaänderung und keine externen Vorgänge.

Abnahme: 34 Fach-/Originalprüfungen einschließlich 19 Bewegungsverträgen;
103 tatsächliche Bestände auf 18 Seiten, jeder genau einmal. 66 tatsächliche
Browserprüfungen, zwölf Handy-/Tablet-Schriftansichten bei 320, 390 und 820
Pixeln mit normaler und doppelter Schrift, mit und ohne JavaScript. Zwei
vorbereitete Positionen bleiben bei allen Seiten und wörtlicher Unicode-/
Sonderzeichensuche erhalten. 37 tatsächliche Navigations-POSTs ohne Änderung
ursprünglicher Bestände, Ausleihen, Bewegungen oder Finanzzeilen.

Der tatsächliche Mehrpositionsablauf besteht erneut mit 38 Browserprüfungen
und neun POSTs, darunter Fehlerkorrektur und zwei wirkliche HTTP-Wiederholungen.
Nur die zwei bewusst bestätigten Ausleihen und vier ursprünglichen Positionen
entstehen. Drei ursprüngliche Inventar-/Ausleihinhalt-/Suchgruppen und der
vollständige Ausleihkatalog mit 43 Fachverträgen bestehen ebenfalls.
