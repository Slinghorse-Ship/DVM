> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Steuer-Vorbereitung: tatsächlich umgesetzter Stand

Stand 08.09.2026: implementiert, lokal geprüft und als Commit `81efe4f2f8ceedd4e048ae0215f9b6622a214c48` veröffentlicht. Der folgende Release-Nachweis unterscheidet die lokale Bedienprüfung von der lesenden Live-Serverprüfung.

## Bedienung und Datenbasis

Unter Finanzen → Körperschaftsteuer & ELSTER werden ein bis drei Kalenderjahre vorbereitet. Die Oberfläche führt durch Zeitraum, ergänzende Jahresangaben und vollständige Vorschau. Ein gespeicherter Stand kann angezeigt und als Grundlage eines neuen Entwurfs verwendet werden. Dabei bleiben bisherige Pakete unverändert; die nächste Vorschau verwendet aktuelle Buchungen und aktuelle Versionen ausgewählter Nachweise.

Die Jahresauswertung beruht auf erfassten Zahlungen und deren Zuordnungen. Sie zeigt Einnahmen, Ausgaben und Ergebnis nach Sphären sowie Steuerkonten mit tatsächlicher Kontenplanbasis. Teilzahlungen und Gegenbuchungen fließen über den Jahresreport ein. Ungeklärte Zuordnungen bleiben sichtbar. Keine vorhandenen Zahlungen bedeutet nicht automatisch, dass das Jahr ohne Geschäftsvorfälle war.

Gesondert ergänzbar sind Buchführungsstand, Forderungen, Verbindlichkeiten, geprüfte Sachvermögenswerte, sonstiges Vermögen, Rücklagenentwicklung, Mittelverwendung und begründete Korrekturen pro Sphäre. Leere Beträge bleiben unbekannt; nur ausdrücklich eingegebene Nullwerte bedeuten null Euro. Bei offenen Vermögenswerten wird keine vollständige Vermögenssumme behauptet. Rücklagenrechnungen prüfen Zahlen, nicht die steuerliche Zulässigkeit. Korrekturzeilen verändern weder Bankumsätze noch Buchungen.

Sieben Fragen zur Vereinsarbeit verwenden Ja, Nein oder Noch zu klären. Fehlende erforderliche Erläuterungen erscheinen als offene Punkte; Arbeitsstände können trotzdem gespeichert werden. Grundlage ist die öffentlich gelesene ELSTER-Anleitung 2025. Siehe [Quellen und jährliche Zuordnungsgrenzen](STEUER-VORBEREITUNG-QUELLEN-2026-09-08.md).

## Nacharbeiten für Kassenwart und Kassenprüfung

Die Unteransicht `?view=finance&tab=tax&tax_review=1&year=2025` erzeugt eine aktuelle Liste aus den gespeicherten Daten. Ein Beleg wird auch ohne Zahlung nach seinem Belegdatum geprüft. Zahlungsprobleme erscheinen nach Zahlungsdatum. Ein Vorgang wird innerhalb der Liste zusammengefasst; beide Zeitbezüge werden bei Bedarf genannt. Die Beträge sind deshalb keine zusätzliche Jahresrechnung und dürfen nicht aufsummiert werden.

Geprüft werden fehlende Zahlungszuordnung, fehlende oder unbekannte Sphäre, technischer Sammelbereich 9, fehlendes Steuerkonto sowie unbekannte Kontenplanbasis. Ein leerer BU-Schlüssel löst allein keinen Punkt aus. Positionsbezogene Ursachen führen zum vorhandenen Beleg; unzugeordnete Umsätze führen zur vorhandenen Kontoumsatzansicht. Jahr und Suchtext sind wählbar. Eine Korrektur entfernt den abgeleiteten Hinweis beim nächsten Anzeigen. Es gibt keinen Schalter, der einen ungeklärten Vorgang nur optisch erledigt.

Finanzleser können die Liste und vorhandene Details prüfen; Korrekturen bleiben an die bestehenden Schreibrechte gebunden. Mandantengrenzen gelten für alle drei Datenquellen: aktive offene Eingangsrechnungen, erfasste Belege und Kontoumsätze. Eine leere Liste belegt weder Vollständigkeit noch steuerliche Zulässigkeit. Nicht importierte Belege oder Zahlungen kann diese Datenprüfung nicht erkennen.

Die isolierte Suite `tests/tax-review-queue.php` prüft 19 Fälle einschließlich unbezahlter Belege, Jahresgrenzen, Deduplikation, tatsächlicher Korrektur, optionalem BU, beschädigter Katalogbasis, Mandantentrennung, Leserechten und escaped HTML. Kein Test verändert Produktivdaten.

Die tatsächlich integrierte lokale Ansicht wurde mit synthetischem Finanzleser bei 1280 und 390 Pixeln geprüft: Jahr bleibt beim Suchen erhalten, Suchzustand wird wiedergegeben, keine JavaScriptfehler oder horizontale Überbreite; Eingabe und Aktionsknöpfe erreichen mindestens 44 Pixel Höhe. Diese Browserprüfung ist keine Produktivprüfung.

## Vorschau und Speicherung

Die Vorschau enthält Jahreszahlen, ergänzte Angaben, Fragen, Tätigkeitsbericht, übernommene Vereins-/Steuerdaten und ausgewählte Nachweise. Das Paket enthält eine lesbare HTML-Übersicht, CSV-Journale und Kontenlisten, JSON-Datenstände, Tätigkeitsbericht und Erklärung der Grenzen. Bis zu zehn aktive hochgeladene Finanz-PDFs mit insgesamt maximal zehn MB können beigefügt werden. Dokumentversion und Dateihash werden dokumentiert.

`TaxPreparationFlow` hält höchstens drei actor-gebundene Vorschauquittungen für zwei Stunden in der privaten Session. Dort liegen Token, Hashes und Vorgangskennung; keine PDF-Dateien, Jahresnotizen oder Tätigkeitsberichttexte. Beim Speichern werden die Eingaben erneut gebunden und die aktuellen Quelldaten erneut aufgebaut. Ein anderer Hash verlangt eine neue Vorschau. Das gespeicherte Paket enthält den tatsächlich geprüften Datenstand.

Nach erfolgreicher Speicherung liefert eine identische Wiederholung derselben gültigen Quittung denselben Dokumentstand, auch wenn danach Buchungen hinzugekommen sind. Geänderte Formulareingaben werden nicht als identischer Vorgang behandelt. Gespeicherte Datenstände haben einen prüfbaren Snapshot-Hash. Die Dokumentablage schützt erzeugte Versionen; Änderungen erzeugen einen neuen Vorbereitungsstand, keine Überschreibung.

## Rechte und Grenzen

Service und Dokumentzugriff prüfen Finanz-Leserecht und Vereinszugehörigkeit. Der Controller nutzt die vorhandene Anmeldung und CSRF-Prüfung. Fremde Vereinsdokumente sowie nicht zugängliche Anlagen können nicht beigefügt oder als gespeicherte Vorbereitung gelesen werden. Finanz-Leserecht erlaubt hier die Erstellung einer geschützten Arbeitsunterlage; es erteilt keine Buchungs- oder Steuerabgabevollmacht.

Es gibt keine Körperschaftsteuerberechnung, keinen XML-/ERiC-Datensatz und keine Übermittlung an das Finanzamt. Die Arbeitsrechnung ist kein automatisch festgestelltes steuerliches Einkommen. Grenzwerte aus der Anleitung 2025 werden nicht als Regeln für 2026 eingesetzt. Abweichende Wirtschaftsjahre sind als zusätzliche Abgrenzung zu prüfen; die Auswertung selbst bleibt kalenderjährlich. Das laufende Jahr wird nicht als abgeschlossen behandelt.

## Konkrete Prüfung

- `tests/tax-preparation-flow.php`: 23 erfolgreiche Prüfungen mit isolierter Dateidatenbank-Speicherdatenbank. Actorbindung, Ablauf, drei Quittungen, keine großen Sessioninhalte, Formular-/Hash-/Datenänderung, Anlagenrevision, exakter gespeicherter Snapshot, unveränderter ZIP-Inhalt, Wiederholung ohne Duplikat, spätere Notizen, Fremdverein, fehlende Finanzrechte und manipulierte Snapshotdaten.
- `tests/tax-preparation.php`: 20 erfolgreiche Prüfungen. Relevante Paketbestandteile statt einer starren Dateianzahl, Centbeträge einschließlich numerischer negativer Werte, Formelneutralisierung in CSV, geschützte Archivierung, Eingabegrenzen, keine rekursiven Exportanlagen und Fremdschlüsselintegrität.
- `tests/tax-declaration-guide.php`: 18 erfolgreiche Prüfungen für Fragen, offene Angaben, Eingabegrenzen, Normalisierung und sichere HTML-Ausgabe.

Gezielter Code-Review von Service, Vorschauquittungen, Controller und Ausgabe: keine zusätzliche konkret bestätigte Berechtigungs- oder Snapshotregression gefunden. Der Downloadparameter wurde während der Integration separat von `file` auf die vorhandene Route `document_file` korrigiert; Veröffentlichung und vollständiger Browserdurchlauf werden vom Releaseverantwortlichen geprüft. Kein Test führte einen echten Versand, eine Steuerübermittlung oder Produktivdatenänderung aus.

Weitere erfolgreiche lokale Prüfungen: 66 Jahresreport-, 73 Jahresangaben- und 18 Leitfragen-Prüfungen; insgesamt mit Paket, Flow und Nacharbeitsliste 219 PHP-Prüfungen. Der vollständige lokale Steuer-Browserdurchlauf bestand sieben Prüfgruppen mit echten POSTs, ZIP-Download und Wiederaufnahme. Die Rechnungsfortschrittsanzeige bestand zwölf Browserprüfungen. Desktop (1280 px) und Mobil (390 px) wurden visuell geprüft. Der Handbuch-Build enthält 20 Kapitel und 248 Aufgaben.

## Verifizierte Veröffentlichung am 08.09.2026

- Git-Basis a03c923; veröffentlichter Code 81efe4f2f8ceedd4e048ae0215f9b6622a214c48. Vorabvergleich aller bisherigen Serverdateien mit der Basis bestanden. Alle 34 veröffentlichten privaten und öffentlichen Ziele entsprechen anschließend den Commit-Dateien.
- Keine Migration; Schema 62, Datenbankintegrität und Fremdschlüsselprüfung bestanden. Dateien und Dateidatenbank wurden vor dem Austausch privat gesichert. Bestehender früheren CMS-Loader und Laufzeitkonfiguration wurden nach dem Wartungsfenster wiederhergestellt.
- Tatsächliche Webruntime: PHP 8.3.33, SAPI cgi-fcgi. Der ursprüngliche Prüfhelfer erwartete zu eng fpm-fcgi und wurde vor dem erfolgreichen vollständigen Lauf korrigiert; kein Produktfehler war Ursache dieses ersten Prüfstopps.
- Lesende Auswertung der echten Jahre 2025 und 2026: Journal, Zahlungsrichtungen, Sphären- und Kontensummen stimmen überein; gespeicherte Katalogjahre bleiben erhalten. Nacharbeitsliste lesbar, unbekannte manuelle Werte und offene Antworten bleiben offen. Dateidatenbank query_only war aktiv und total_changes unverändert.
- Alle acht betroffenen öffentlichen CSS-/JavaScript-Dateien per HTTP gegen den Commit geprüft. Startseite, Login, Galerie und Programmarchiv antworteten erfolgreich ohne Fehlerseite.
- Lokale vollständige Bedienprüfung einschließlich Speicherung, ZIP und Wiederaufnahme mit synthetischen Testkonten bestanden. Die zunächst wegen abgelaufener Anmeldung offene authentifizierte Live-Prüfung wurde anschließend nachgeholt; Ablauf und getrennte Nachweise stehen im folgenden Nachtrag.
- Keine Testmail, Zahlung, Steuerübermittlung oder fachliche Veränderung realer Vereinsbuchungen ausgelöst. Schutzmaßnahmen und Berechtigungen wurden für diesen Release nicht gelockert.

Private Ausführungsbelege liegen lokal unter .local/tax-working-papers-deployment-result.json und .local/tax-working-papers-live-verification.json. Die Sicherung liegt außerhalb des Webroots unter /tmp/kulturhus-verein/rollback-tax-working-papers-20260908. Die vorübergehenden geschützten Prüfdateien wurden nach jedem Serveraufruf entfernt.

## Authentifizierter Live-Ablauf: Arbeitsstand 2025

Am 8. September wurde über die echte Oberfläche genau ein Arbeitsstand für 2025–2025 gespeichert. Er kennzeichnet die Buchführung ausdrücklich als unvollständig und die Erklärung als noch nicht abgegeben. Unbekannte Vermögensbeträge wurden nicht durch Null ersetzt. Zwei PDF-Unterlagen wurden bewusst ausgewählt: eine Rechnung des Jahres 2025 und der vorhandene Freistellungsnachweis. Die Auswahl behauptet keine abgeschlossene steuerliche Prüfung.

Ein separater lesender Prüfhelfer sicherte vor der Bedienung die vorhandenen Paket-IDs sowie Hashes sämtlicher vereinsbezogener Finanz-, Kontierungs- und Inventartabellen und aller nicht generierten Originaldateien. Nach der Speicherung wurde genau ein zusätzliches Paket festgestellt. Finanz-/Inventardaten und Originalrevisionen samt Bytehashes blieben unverändert. Schema 62 und Dateidatenbank `query_only` wurden geprüft; der Prüfhelfer selbst verursachte keine Datenbankänderung.

Das gespeicherte ZIP wurde privat heruntergeladen und geöffnet. Sein `datenstand.json` stimmt mit dem durch `TaxPreparation::saved()` geprüften Snapshot überein. Alle im Snapshot verzeichneten Paketdateihashes und die beiden PDF-Quellhashes stimmen. Unvollständigkeitsstatus, unbekannte Beträge, Notiz, Leitfragen und Tätigkeitsbericht bleiben beim serverseitigen Wiederaufnehmen erhalten.

Die anschließende echte Browser-Wiederaufnahme bestätigte zusätzlich beide ausgewählten Anlagen-Checkboxen, den Unvollständigkeitsstatus, den Notiztext und die weiterhin leeren unbekannten Beträge. Steuerformular und Fortschrittsanzeige wurden live bei 390 Pixeln ohne horizontale Überbreite geprüft. Die Erstellung speichert Arbeitsunterlagen; sie erzeugt keine Finanzbuchung und übermittelt nichts an ELSTER.

Private Ausführungsbelege und das ZIP liegen ausschließlich unter `.local/tax-live-workflow/`. Geschützte temporäre Prüfendpunkte wurden wieder entfernt. Die Erweiterung der Nacharbeitsliste ist separat im folgenden Nachtrag und in der Live-Bedienprüfung nachgewiesen.

## Ergänzung der Nacharbeitsliste: Rechnungseingang

Die Nacharbeitsliste berücksichtigt zusätzlich aktive offene Eingangsrechnungen. Ein zur aktuellen Datei, Revision und Parser-Version gebundenes erkanntes Rechnungsdatum ordnet sie dem Rechnungsjahr zu. Die Aktion führt direkt zur Prüfung und Erfassung im Rechnungseingang. Ohne verlässliches Datum erscheint die Datei bei jeder Jahreswahl in einer gesonderten Gruppe mit ungeklärtem Rechnungsjahr; das Dokumentdatum wird nur als Anhaltspunkt genannt. Erst die tatsächliche Zuordnung beziehungsweise der reguläre Abschluss im Eingang entfernt den offenen Vorgang.

Die Liste startet keine Erkennung und verändert keine Daten. Insbesondere bleibt eine XML-Rechnung ohne passenden gespeicherten Erkennungsstand zunächst im Bereich mit unbekanntem Rechnungsjahr, auch wenn ihr Original strukturierte Datumsangaben enthält. Die Ergänzung wurde nach 42 Eingangslisten- und 19 bisherigen Queue-Prüfungen sowie lokalen HTTP-Browserprüfungen bei 1280 und 390 Pixeln als Runtime-Commit b0db451 veröffentlicht. Live bestätigt: Roll-up-Rechnung im Jahr 2025, gesonderte unbekannte Jahre, Suche und Jahreswechsel sowie direkter Weg zum passenden Original. Siehe [Live-Bedienprüfung und Veröffentlichungsnachweis](LIVE-BEDIENPRUEFUNG-2026-09-08.md).
