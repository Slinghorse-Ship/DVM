# Familienantrag mit und ohne JavaScript

Der öffentliche Mitgliedsantrag enthält eine native aufklappbare Gruppe
„Familienangaben“. Ohne JavaScript lässt sie sich mit Touch und Tastatur öffnen.
Familienbeitragszahler können weitere Angehörige nennen; Familienmitglieder
geben die Person an, die ihren Beitrag zahlt. Die Feldbeschriftungen erklären,
welche Angabe zur gewählten Mitgliedschaft gehört.

Mit JavaScript öffnet die Mitgliedschaftsauswahl die Gruppe automatisch und
aktiviert die passenden Felder. Der Beitragszahler ist dann nur für ein
Familienmitglied ein Pflichtfeld. Ein Wechsel zwischen Einzel- und
Familienmitgliedschaft verwirft eingegebene Familienangaben nicht.

Nach dem Absenden führt der native Formularanker unmittelbar zur Fehlermeldung
oder Eingangsbestätigung. Eine Fehlermeldung bekommt den Tastaturfokus. Die
gesamte Startseite muss dafür nicht erneut durchscrollt werden. Bei Fehlern bleiben
persönliche Angaben, Familienzeilen, Beitragszahler und gewählte Mitgliedschaft
erhalten; die Familiengruppe bleibt offen. Nach veralteten Bedingungen muss die
Zustimmung zu den aktuellen Hinweisen erneut bewusst gesetzt werden.

Ein korrigierter Antrag wartet weiterhin auf die persönliche E-Mail-Bestätigung.
Er legt noch keine Mitgliedschaft an und gibt keine zusätzliche Newsletter-
Einwilligung. Aufnahmeentscheidung, Beitragsvorgaben und bestehende Nachweise
bleiben eigene Fachschritte. Der Entwicklungsweg versendet keine realen Mails.

## Nachweise

`native-membership-family` prüft die tatsächliche veröffentlichte HTTP-Route
`PublicSite` mit dem bestehenden `MembershipApplications`-Fachprozess in einer
frischen isolierten MySQL-Datenbank. Drei neue Anträge für Einzelmitglied,
Familienbeitragszahler und Familienmitglied; veraltete Bedingungen, falsche
Sitzung, fehlender Beitragszahler und idempotente Wiederholung. Die reguläre
Fachprüfung besteht mit 263 Verträgen.

Mit `DVM_MEMBERSHIP_BROWSER=1` führt derselbe Runner zusätzlich
`membership-family-browser.mjs` aus: 67 tatsächliche Browserverträge mit und ohne
JavaScript, vier neue Anträge und drei serverseitige Korrekturversuche. Geöffnete
Familienfelder passen bei 320/390/820 Pixeln und 200 % Zoom. Touchfelder sind
mindestens 44 Pixel hoch und haben mindestens 16 Pixel Schriftgröße. Native
Validierung, sichtbare Fehler, Tastaturfokus, erhaltene Eingaben und sofort
sichtbare Bestätigung werden an der tatsächlichen Route geprüft.

Der anschließende Datenbankvergleich umfasst 275 Verträge: Alle früheren Zeilen,
Konten, Einstellungen, Seiten, Revisionen und Veröffentlichungen bleiben bytegleich.
Alle Tabellen und Gesamtzeilenzahlen bleiben erhalten, mit genau sieben neuen
eigenen Bestätigungsanfragen als einziger Ergänzung. Die tatsächlich übertragenen
Unicode-Familienzeilen und Beitragszahler stimmen exakt mit den gespeicherten
Anfragen überein. Jeder Antrag hat seinen eigenen unveränderten Bestätigungstoken
mit 48 Stunden Frist. Ursprüngliche Mediendateien bleiben unverändert.

Die Browsergruppe läuft im GitHub-Workflow nach der Playwright-Einrichtung.
Der normale CI-Lauf enthält jetzt 117 native Suiten. Fünf betroffene native
Suiten sowie die 174 lesenden Vorlagen-/Formularverträge an beiden bestehenden
Anwendungen bestehen erneut. Die bestehenden Anwendungen behalten dabei alle
ursprünglichen Zeilen, Gesamtzeilenzahlen und Dateien. Ihre Antragsfreigabe wird
für die Prüfung nicht geändert.

## Weiterarbeit

Weitere Beitrags-, Zustell-, Vorstands-, Rollen- und Wiederaufnahmevarianten
stehen in beiden Registern. Diese Formularumsetzung ist keine vollständige
Mitglieder- oder Produktabnahme. Apps für iPhone/iPad und Android-Smartphones/
-Tablets bleiben die abschließende Phase.
