# iPhone: Personenauswahl bleibt beim Platzhalter

Die Adminauswahl bei der allgemeinen Mitgliedschaftsbestätigung wurde in WebKit mit iPhone-13-Toucheinstellungen reproduziert: Nach Antippen einer Person blieb der tatsächliche Formularwert leer und die Anzeige bei „Bitte Person auswählen“. Chromium übernahm denselben Tap. Es war ein Fehler des gemeinsamen Such-Auswahlfeldes, nicht der Speicherung der Mitgliedschaft.

## Ursache und Korrektur

Die Option verhinderte das Standardverhalten von `pointerdown`. WebKit erzeugte danach kein `click`; der nur dort registrierte Auswahlhandler lief deshalb nicht. Jetzt wird ausschließlich `mousedown` abgefangen, damit das Suchfeld bei der Mausauswahl fokussiert bleibt und Touch-Klicks weiterhin entstehen. Auswahl und Formularereignisse werden genau einmal ausgelöst. Die gleich aufgebauten Inventarkategorien und Rechnungspositionsvorschläge hatten denselben Fehler und erhalten dieselbe Korrektur.

Außerdem ersetzte die allgemeine Suchauswahl ihre Optionsknoten bei unabhängigen DOM-Änderungen, etwa einem neuen Statustext. Zwischen Drücken und Loslassen konnte dadurch das Klickziel verschwinden. Bei unverändertem Suchtext und Optionsbestand bleiben die Knoten jetzt bestehen. Tatsächliche Änderungen an Werten, Texten, Auswahl, verfügbaren Optionen oder Suchtext werden weiterhin dargestellt. Beim Schließen wird die Darstellung für das nächste Öffnen zurückgesetzt. Kein Auswählen bereits beim Berühren: Scrollen bleibt vom Bestätigen getrennt.

Die drei Skriptversionen in den betroffenen Ansichten sind erhöht. Nach Neuladen lädt auch eine bereits installierte Web-App die Korrektur; der bestehende Service Worker besitzt keinen Dateicache. Anmeldung, Rechte, Profilwerte und PDF-Inhalte werden durch diese Korrektur nicht verändert.

## Prüfung

- Neuer Regressionstest scheitert vor dem Fix reproduzierbar mit leerem Personenwert in WebKit.
- WebKit mit iPhone-Touch, Chromium mit Pixel-Touch sowie beide Browser mit Maus: Antippen/Anklicken, genau ein Änderungsereignis, tatsächlicher Formularwert, Suche, keine Treffer, Fokuswechsel, Tastatur und Zurücksetzen bestanden. Kategorien und Rechnungspositionsvorschläge ebenfalls per echtem Playwright-Tap/Click geprüft.
- Unabhängige DOM-Änderung zwischen Drücken und Loslassen verliert die Auswahl nicht mehr. Neu hinzugefügte Optionen bleiben auswählbar.
- Tatsächliche lokale Adminmaske mit 31 synthetischen Mitgliedern: Person suchen und antippen → Daten übernehmen → GET-Neuladen → richtige Person, Kontaktnummer, Tätigkeit und Kalenderjahr. In WebKit- und Chromium-Toucheinstellungen bestanden; keine Bescheinigung angelegt oder versandt.
- Bestehende Edge-Regressionsprüfung: 100er-Auswahllimit, exakte Suche/Enter, Erhalt einer bestätigten Auswahl bei keinem Treffer und Bereinigung der Pflichtfeldanzeige beim Deaktivieren bestanden. Betroffene PHP-Ansichten unter PHP 8.3 ohne Syntaxfehler.

Der frühere Bescheinigungstest verwendete nur zwei Mitglieder und damit noch eine native Auswahlliste. Die neue Fixture überschreitet bewusst die Schwelle zur Suchauswahl. Die WebKit-Prüfung ersetzt keine bereits erfolgte Bestätigung auf dem physischen Nutzer-iPhone. Der vorhandene Firefox-Testbrowser ließ sich auf diesem Rechner nicht starten; für ihn wird kein erfolgreicher Lauf behauptet.

Tests: `select-search-touch-browser.cjs`, `select-search-state-browser.cjs` und isolierte Fixture `support/volunteer-touch-fixture.php`. Veröffentlichung aus Git mit vorherigem Dateivergleich, privater Sicherung und vollständigem Rücklesen; Nachweis in `.local/touch-release-20260909-result.json`. Keine Migration, Bankaktion, Testmail oder Pushnachricht erforderlich.
