# Newsletter- und Presseausgaben finden

Die Redaktion zeigt alle eigenen Ausgaben in gezählten Seiten mit sechs
Einträgen. Titel/Betreff und Status sind durchsuchbar. Seitenwechsel behalten die
Suche im Link; erneutes Laden öffnet dieselbe Auswahl. Auch ältere Entwürfe über
den früheren 500er-Ausschnitt hinaus sind erreichbar. Die Übersicht bleibt
unabhängig von der gerade geöffneten Ausgabe einklappbar.

Die Abfrage wählt zuerst sechs Originalkennungen aus und liest nur deren Titel
und Stände. Große Inhaltsfelder werden nicht mehr für die ganze Liste geladen
oder in die MySQL-Sortierung übernommen. Das verhindert den tatsächlich bei
großen Ausgaben aufgetretenen Sortierspeicherfehler. Es wird kein Serverlimit
angehoben und keine ursprüngliche Ausgabe umgeschrieben.

Der Versionsverlauf einer geöffneten Ausgabe zeigt sechs Fassungen pro Seite.
Jede Fassung öffnet ihr tatsächlich gespeichertes Original mit ursprünglichem
Titel, formatiertem Text und allen typisierten Bausteinen. Die Versionskennung
steht in der Ansicht. Fehlende oder nicht passende Fassungen werden abgewiesen;
neuere Inhalte werden nicht an deren Stelle eingesetzt. Aktuelle Vereins- und
Modulrechte gelten bei jedem Aufruf.

Lesekonten sehen auch in der aktuellen Ausgabe alle Bausteine und Bilder.
Der private Bildzugang prüft die ausgewählte ursprüngliche Ausgabenfassung,
den eigenen Verein und den darin vorhandenen Bildbezug. Ein anderes Bild oder
eine Fassung ohne diesen Bezug wird abgewiesen. Damit ist keine allgemeine
Mediathekberechtigung verbunden. Spätere Änderungen der Ausgabe verändern den
Bildbezug einer früheren Fassung nicht. Entfernte physische Bilder werden nicht
rekonstruiert. Aktuelle Rollen gelten auch für bereits gespeicherte Bildlinks.

Original- und Bildansichten sind privat, ohne Cache und ohne Suchmaschinenfreigabe.
GET und HEAD schreiben nichts; HEAD enthält keine Bild- oder Originalbytes.
Die bestehenden Tabellen und gespeicherten Revisionen bleiben unverändert.
Gezählte Suche, Versionszugang und Bilder werden in beiden tatsächlichen
Controllern mit und ohne JavaScript, Touch/Tastatur, doppelter Schrift und bis
320 Pixel Breite geprüft. Fachprüfungen lesen über 500 große Ausgaben je Bereich
und einen ausgewählten Originaltext über 680 KB in einem echten nativen
16-MB-PHP-Prozess. Dieser Nachweis betrifft den nativen Leseweg und verlangt
keinen 16-MB-Betrieb des gesamten bestehenden Vereinsmanagers.
