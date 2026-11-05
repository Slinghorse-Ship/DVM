# Menü: Fehler und konkurrierende Änderungen

Der Menüeditor in beiden Verwaltungsansichten bindet das Formular an die aktuelle
Person, den Verein und den ursprünglichen Menüstand. Veröffentlichungsrechte und
die Rechte jeder betroffenen Seite werden bei jeder Aktion erneut geprüft.
Weiterhin lesbare Einträge ohne eigenes Veröffentlichungsrecht stehen getrennt
unter „Nur Ansicht“. Sie werden nicht mitgesendet; ihre vorhandenen Werte bleiben
auch beim Speichern anderer Einträge erhalten.
Eine Änderung der automatischen Programmbeschriftung prüft zusätzlich die Rechte
der tatsächlich veröffentlichten Programmseite. Ein unveränderter Wert blockiert
keine anderen Einträge. Eine deaktivierte Checkbox erhält diesen Wert im Formular.

Bei einem ungültigen Namen oder einer ungültigen Position bleiben die eigenen
Eingaben erhalten. Eine abgelehnte Speicherung verändert weder das gespeicherte
Menü noch seine Versionshistorie oder die veröffentlichten Seiten.

Hat eine andere Person das Menü inzwischen geändert, bleibt die ursprüngliche
Versionsnummer des eigenen Vorschlags erhalten. Der aktuell gespeicherte Stand
wird getrennt angezeigt. „Aktuellen Stand vergleichen“ lädt diesen Vergleich,
ohne den Vorschlag zu speichern oder seine Versionsnummer zu erhöhen.

„Auf aktuellen Stand übernehmen“ übernimmt ausdrücklich die angezeigte aktuelle
Versionsnummer als Grundlage des eigenen Vorschlags. Dieser Schritt speichert
kein Menü. Ein inzwischen erneut geänderter Stand wird als Konflikt abgelehnt.
Erst „Menü speichern“ führt den normalen, atomaren Versionsvergleich und die
Speicherung aus. Bis dahin bleibt die Warnung vor ungespeicherten Änderungen aktiv.

Ohne JavaScript funktionieren dieselben Formularaktionen und die Positionsfelder.
Mit JavaScript ändern die Pfeile in beiden Anwendungen die tatsächliche
Reihenfolge und die übermittelten Positionswerte. Namen und andere menschliche
Originaltexte werden ausschließlich als escaped Werte ausgegeben.

Prüfungen: `native-navigation.php`, `native-navigation-form-state.php` und die
über `DVM_NAVIGATION_BROWSER=1` aktivierte echte Zwei-App-Prüfung
`native-navigation-browser.mjs`. Der Browser verwendet ausschließlich eine neue
synthetische Datenbank und echte lokale Sitzungen; er löst keine externen Aktionen aus.
Mit `DVM_NAVIGATION_HTTP_ONLY=1` läuft derselbe Zwei-App-Vorlauf ohne Browser.

Abnahmestand 16. Oktober 2026: 40 reine Formular-, 81 Fach-, 54 tatsächliche
HTTP- und 229 Browserbedingungen in den vier Varianten beider Zugänge mit/ohne
JavaScript bestehen. 14 bewusste eigene Testspeicherungen ändern ausschließlich
ihre Fixturemenüs; 425 ursprüngliche Tabellen, fremde Menüs und Schlüssel bleiben
exakt erhalten. Externe Aktionen: 0. Die gemeinsame gesamte K-Abnahme folgt.
