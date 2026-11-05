# Bereichsaufteilung und interne Zahlungen in tatsächlichen Abschlusszeiträumen

Stand 28.09.2026. **Entwicklungsstand, kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

## Umsetzung

Migration 107 ergänzt vereinsgebundene, unveränderliche Bereichsprüfungen. Die Kennung
enthält Beginn und Ende, nicht nur das Kalenderjahr. Zwei Rumpf-/Wirtschaftsjahre mit
demselben Endjahr haben getrennte Originale, Aufteilungen und Bestätigungen.

`BalancePeriodAreas` verbindet den tatsächlichen Zeitraumabschluss mit der bestehenden
Bereichsrechnung. Enthalten sind:

- mehrere Betriebe in derselben steuerlichen Sphäre, eindeutige Vorschläge aus den
  Originalzeilen, centgenaue Teilaufteilungen und getrennte Anfangsbestände;
- beleggebundene interne Vorschüsse, Teilrückzahlungen und endgültige Mittelübertragungen;
- kein neues Entgelt, kein erneuter Aufwand und keine erfundene externe Forderung
  durch eine interne Zahlung; auf Gesamtvereinsebene heben sich die internen Beträge auf;
- Fälligkeit bezogen auf das tatsächliche Abschlussdatum, nicht pauschal auf den 31. Dezember;
- Übernahme offener Vorschüsse und Bereichskapital aus dem exakt bestätigten Vorzeitraum;
- getrennte Vorbereitung durch die Finanzrolle und Bestätigung durch berechtigten Vorstand;
- neue Abstimmung nach Korrektur oder neuer Vorstandsprüfung des Vorgängers. Alte
  Aufteilungen, Belege, Journalzeilen und Bestätigungen bleiben unverändert erhalten;
- Schutz vor veralteten Formularen, wiederholten POSTs, fremden Vereinen und manuell
  veränderten vorgetragenen Bereichsbeständen. Eine erfundene Vortragsreferenz wird
  auch mit neu berechnetem Prüfsummenfeld verworfen.

Unvollständige interne Verrechnungen können als Entwurf vorbereitet werden, beispielsweise
zwei Teilvorschüsse derselben Originalzahlung. Die Bestätigung bleibt gesperrt, bis die
gesamte Zahlung centgenau zugeordnet und abgeglichen ist. Ein zusätzlich benannter Bereich
oder eine Korrekturbuchung löscht vorhandene Vorschussangaben nicht stillschweigend.

## Oberfläche und Arbeitsunterlagen

Aufruf nach dem bestätigten Zeitraumabschluss: **Vereinsbereiche und interne Zahlungen
prüfen**. Drei Abschnitte: **Bereiche festlegen**, **Belege zuordnen**, **Aufteilung bestätigen**.
Ein normaler Beleg kann vollständig einem Bereich zugeordnet werden. Teilbeträge und
interne Zahlungsfälle liegen in aufklappbaren Abschnitten. Nach Fehlern bleiben eingegebene
Beträge und Prüfvermerke erhalten; die alte Versionskennung wird nicht still aktualisiert.

Arbeitsunterlagen enthalten `vereinsbereiche.csv`, die vollständige Prüfhistorie sowie
einen lesbaren und maschinenlesbaren Ergebnisabgleich. Dieser trennt Buchführungsergebnisse
des Gesamtvereins, sämtlicher steuerpflichtiger Betriebe und der anderen Bereiche.
Vorschüsse/Rückzahlungen werden separat ausgewiesen, nicht als Gewinn behandelt.
Vorzeitraum und Originalbuchungszeilen bleiben im Nachweis nachvollziehbar.

**Der Ergebnisabgleich ist keine Steuererklärung.** Steuerwerte sind damit nicht bestätigt.
Eine versehentliche Weitergabe datierter Ergebnisse an die bisher kalendergebundene
steuerliche Nebenrechnung wird ausdrücklich gesperrt.

## Prüfung

Die isolierte MySQL-Regression deckt einen Zeitraum Juli 2025 bis Juni 2026 und einen
zweiten Zeitraum Juli bis Dezember 2026 ab. Sie prüft unter anderem gemischte Vorschüsse,
Teilrückzahlungen, Abschlusskorrekturen, berichtigte Anfangsbestände, Rollen, unveränderliche
Historie, Export, wiederholte und fehlerhafte Controller-Anfragen und echte gerenderte Masken.
Zusätzlich laufen die bestehenden Kalender-, Bereichs-, Verrechnungs- und Korrekturtests.
Der genaue abschließende Lauf ist in der begleitenden JSON-Datei dokumentiert.

Tests verwenden ausschließlich die freigegebene synthetische MySQL-Datenbank. Kein Versand,
keine Bankaufträge, keine Steuerabgaben und keine Produktivdatenbankverbindung.

## Noch offene Freigabevoraussetzungen

Diese Erweiterung schließt die datierte Bereichs-/Vorschussanbindung, nicht die allgemeine
Bilanzentwicklung ab. Insbesondere bleiben offen:

- datierte Nutzungsaufteilung gemeinsam genutzter Anlagen und Sphären-/Nutzungswechsel;
- Übergang einer bestehenden Kalender-Bereichsaufteilung in den datierten Bereichsdialog;
- durchgängige datierte steuerliche Überleitung einschließlich Anlagen- und weiterer Sonderfälle;
- vollständige verifizierte Kontenabdeckung, amtliche Zeitraum-/Korrekturausgabe und
  vollständige Abnahme einschließlich Übermittlung und späterer Rückmeldungen;
- Veröffentlichung und erst danach die gesondert beauftragte Gesamtprüfung des Vereinsmanagers.

Bei vorhandenen Anlagen wird die neue Bereichsbestätigung bis zum zugehörigen Nutzungsnachweis
gesperrt. Die produktiven Schreibsperren für die noch nicht abgenommene Zeitraumfunktion
bleiben bestehen. Kein EÜR-Verein wird umgestellt.
