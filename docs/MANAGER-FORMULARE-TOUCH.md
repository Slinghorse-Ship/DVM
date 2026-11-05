# Gemeinsame Formulare und Touchbedienung

Die befüllten [Dokumentformulare](DOKUMENTFORMULARE-TOUCH.md) ergänzen diesen
Nachweis um Upload/Abbruch, Originalzuordnung und Metadaten ohne JavaScript.

Der Manager verwendet lokal Bootstrap **6.0.0-alpha.1**. Aktionen benutzen
`btn` mit `btn-solid`/`btn-outline` und `theme-primary`/`theme-secondary`/
`theme-danger`; Eingaben, Textbereiche und Selects verwenden `form-control`,
Checkboxen und Radios `check`, Schieberegler `form-range` und Tabellen `table`.
Die bisherigen Fachklassen bleiben für ihre eigenen Layouts und Bedienmodule
vorhanden. Die Managerfarben sind von der öffentlichen Websitevorlage getrennt.

## Aktueller vollständiger Menüabgleich – Generation 2026101797

Die Auswahl verwendet sämtliche eigenen Verwaltungslinks unter `#navigation`.
Der aktuelle isolierte Bestand liefert 96 Verwaltungs-, 13 Mitglieder- und
30 Redaktionsrouten. Die frühere `.subnav`-Auswahl erfasste nur 34 der heutigen
96 Verwaltungslinks. Bestanden sind **3336 Ansichten/24207 Bedingungen**:
alle 139 Routen, sechs Größen, normale/doppelte Schrift und beide
JavaScript-Modi. Je echter geladener Route werden die zwölf Größen-/
Schriftkombinationen durch tatsächliche Größenänderung geprüft; 3336 sind
Darstellungen und keine HTTP-Anfragen. Zusätzlich bestehen **436 frisch
geöffnete Bootstrap-Navigationsansichten** bei vier Breiten.
Kein `FAST`, `PROFILE_ONLY` oder `TRANSITION_ONLY`, kein fehlgeschlagenes Audit,
Geschäfts-POST, externer Abruf oder Skriptfehler. Alle 469 vollständigen
geschützten Tabellen und zwei Schlüssel bleiben exakt.

Der Vereinsname bleibt auf dem Telefon vollständig lesbar; Aktionen an
Überschriften umbrechen. Gemeinsame Control-Klassen werden bei allgemeinen
Einstellungen, Kalender, Bank-Mitgliedersuche, Newsletterplanung, Steuerangaben,
System, Postfach und Wallet schon serverseitig ausgegeben. Die vorhandene lokale
Spendenformular-CSS ist explizit registriert, damit die echte Manager-Navigation
diese Seite öffnen kann. Die Asset- und Änderungsschutzregeln bleiben bestehen.
96 tatsächliche Verwaltungsantworten enthalten keine nicht registrierten Assets
oder ausführbaren Inline-Skripte.

Die laufende Anwendung bestätigt den Kopf in 16 Original-/16 synthetischen
Langnamenansichten sowie sechs echte DE/EN-Aufrufe mit 24 weiteren Darstellungen
und den korrigierten Spendenwechsel. Die ursprüngliche Sprache ist restauriert;
467 ganze geschützte Tabellen und vier Schlüssel sind exakt erhalten.
Zusätzliche Bank-/Ausweis-/Steuer-/System-/Postfach-/Planungs-/Menügruppen bestehen.
Ein erster Bankbrowserlauf war wegen eines noch geschlossenen Folgeformulars rot;
der getrennte vollständige Wiederholungslauf wartet ausdrücklich auf dessen
Anhängen und tatsächliche Öffnung. Keine erzwungene Bedienung oder Bankänderung.

Die nachfolgenden älteren Zahlen gehören zu ihren damaligen Quellenständen.
Die aktuelle gemeinsame Fach-/Sprach-/Fehler- und Geräteabnahme sowie P-12/P-25
bleiben im Umsetzungsregister offen. Diese Menüprüfung ersetzt sie nicht.

Prüflog-SHA-256:
`f0ff9d89e830e376d8bd691f0efdc9764fcf03445816d6eb92cf3c900c0fcc2d`.

Die Änderung umfasst 288 bestehende Ansichts- und UI-Dateien. Namen, Werte,
Formularziele, CSRF-/Versionsfelder, Fachaktionen und Originaldatensätze bleiben
unverändert. Die serverseitigen Controls funktionieren auch ohne JavaScript.
Das kleine gemeinsame `manager-controls.js` ergänzt dieselben Klassen bei eigenen
Such-, Auswahl-, Upload- und Dialogknoten, die später erzeugt werden. Es setzt
keine Werte und löst keine Fachaktionen aus. Formatierte Originalinhalte und
bearbeitbare Textflächen sind von dieser Ergänzung ausgenommen.

Schaltflächen und Symboltasten erhalten mindestens **44 × 44 Pixel**.
Ausklappbereiche und Auswahloptionen behalten ebenfalls 44 Pixel Höhe.
Eingaben bleiben auf Smartphone und Tablet mindestens 16 Pixel groß. Checkboxen
und Radios verwenden native Glyphen ohne externe oder inline codierte Bildquellen;
ihre beschrifteten Zeilen bieten ein größeres Touchziel. Texteditorpaletten
passen ihre Emoji-Spalten an den verfügbaren Platz an und können bei Bedarf
innerhalb des geöffneten Menüs scrollen. Hinweise neben Checkboxen können auf
schmalen Panels umbrechen. Versteckte Bereiche bleiben versteckt.

Die tatsächliche Browserprüfung umfasst alle 32 Verwaltungs-, neun Mitglieder-
und fünf Redaktions-Menüseiten bei 1440/820/390 Pixeln sowie dieselben Menüs ohne
JavaScript bei 1440/390 Pixeln: **230 Seitenzustände und 1.252 Kontrollen**.
Sie prüft Controls, Feldbeschriftungen, sichtbare Touchtasten und Seitenbreite.
Zusätzlich werden befüllte Profilbereiche per Tastatur geöffnet, bei 200 % Zoom
geprüft, die tatsächliche Kalendersuche per Touch bedient und alle geöffneten
Texteditor-Menüs geprüft. Eigene dynamische Dialoge erhalten ursprüngliche Werte,
übernehmen eine lokale Eingabe oder werden per Escape abgebrochen. Ein echter
Touch-Menüwechsel erhält das Dokument und lädt das Controls-Modul nur einmal.
Es werden dabei keine Fachformulare gespeichert oder externe Dienste aufgerufen.

Zusätzlich bestehen alle 75 nativen Suiten einschließlich Neuinstallation,
entpackter OCR-Laufzeit und verschlüsselter Recovery, neun Kalender-/Finanzsuiten
sowie der tatsächliche Webspace-Installer im Browser. Das Offline-Quelleninventar
und die entpackten PHP-Pakete sind geprüft; übrige Originalprofile sind unverändert.

Die Entwicklungseinrichtung reproduziert diese Prüfung mit
`bash tools/cloud/bootstrap.sh --verify`. Zugangsdaten, Browser-Sitzungen und
Ergebnisse bleiben privat unter `.local/`. Diese gemeinsame Prüfung ersetzt
nicht die weiteren befüllten Fach-, Geräte-, Provider-, Fehler- und
Wiederaufnahmeabläufe aus dem Umsetzungsregister.
