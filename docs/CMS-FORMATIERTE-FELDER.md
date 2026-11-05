# Formatierte individuelle Seitenfelder

Unter **Website → Felder & Vorlagen** können berechtigte Redakteure ein Feld
mit dem Typ **Formatierter Text** anlegen und einer Vorlage zuordnen. Diese
Felder verwenden denselben Editor wie Seiten und Kommunikation, einschließlich
Formatierung, sicheren Links, Farben, Emoji und Tabellen. Die Werkzeugleisten
und Modell-/Seitenaktionen verwenden das lokale Bootstrap 6.0.0-alpha.1.

1. Einen eindeutigen Feldschlüssel und eine verständliche Bezeichnung vergeben.
2. Optional Pflichtfeld und Mindest-/Höchstlänge einstellen. Gezählt werden
   sichtbare Unicode-Textzeichen einschließlich Absatz-/Tabellentrennern,
   keine HTML-Tags. Nur Formatierung oder Leerraum füllt kein Pflichtfeld.
3. Das Feld in der gewünschten Vorlage auswählen und die Vorlage speichern.
4. Eine Seite dieser Vorlage öffnen, die Angaben bearbeiten und den Entwurf
   speichern. Veröffentlichung bleibt eine ausdrückliche eigene Aktion.

Die native Anwendung bereinigt das HTML erneut beim Speichern und bei der
öffentlichen Ausgabe. Skripte, Ereignisattribute, externe Bilder und unsichere
Links werden nicht übernommen. Zusätzlich wird der sichtbare Text für die
gebundenen `custom.<feldschlüssel>`-Selektoren abgeleitet. So findet eine Suche
auch Text über Inline-Formatierungen hinweg und sucht keine HTML-Tags.
Übergebener Suchtextindex kann diesen abgeleiteten Bestand nicht ersetzen.

Feld-/Vorlagendefinitionen und Werte bleiben in der jeweiligen Seitenrevision
gespeichert. Änderungen an Definitionen müssen bewusst in einen neuen Entwurf
übernommen werden; alte veröffentlichte Fassungen und ihre Cachegeneration
ändern sich dadurch nicht. Auch Wiederherstellung und Rechteentzug verwenden
die vorhandenen nativen Versions-/Berechtigungsprüfungen. Eine neue Datenbank-
Schemaversion ist für diesen zusätzlichen deklarativen Typ nicht erforderlich.

`native-cms-richtext-fields.php` prüft 26 echte MySQL-Verträge zu Validierung,
Textsuche, Versionen, öffentlichen Originalen und Rechten. Die bestehenden
RichText-/Adapterprüfungen laufen ebenfalls im nativen CI-Runner.
`native-richtext-fields-browser.mjs` prüft 52 tatsächliche Abläufe bei
1440/820/390 Pixeln, einschließlich Formularfehlern, Tabellen-/Undo-/Farbauswahl,
Speichern in MySQL und unveränderter öffentlicher Fassung. Das Cloud-Startskript
`--verify` richtet dafür ausschließlich eigene synthetische Inhalte ein.

Dieses Paket ergänzt den zwölften Feldtyp. Verschachtelte strukturierte Felder,
Repeater/PageTable, Frontend-Bearbeitung und der vollständige historische
Feld-/Medienimport bleiben eigene Punkte im Funktionsabgleich.
