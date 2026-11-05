# Bedienkorrekturen: Inventar, Chat und Textvorschau

Stand: 8. September 2026. Aufbauend auf `b8d0775`.

- Die beiden Arten der Gegenstandserfassung erklären sich direkt mit sichtbaren Beispielen: vier Bierzeltbänke als Mengenbestand oder getrennte Beamer. Die ausgewählte Art ist markiert; vorhandene Einträge werden nicht umgestellt.
- „Zuständiger Bereich / Abteilung“ entfällt als Eingabefeld. Bei späterem Speichern bleibt ein bereits vorhandener Abteilungswert erhalten; keine Datenmigration.
- Im Entfernen-Bereich eines Mengenartikels lassen sich Anzahl, Lagerort, freier beziehungsweise gesperrter Bestand und Grund angeben. Die erforderliche Auswahl einzelner Stücke ist geöffnet. Ein Abgang von einem aus vier Stücken lässt drei im aktiven Eintrag. Die vollständige Archivierung bleibt ein getrennter Vorgang.
- Die Teilentfernung verwendet die vorhandene transaktionale Bestands- und Stückwertführung mit Versions- und Wiederholungsschutz. Reservierte und ausgegebene Mengen werden nicht abgezogen; gesperrte Bestände benötigen die passende Bestandsart. Bestehende Rechnungen, Originaldateien und Verknüpfungen bleiben erhalten.
- Der Chat zeigt eine suchbare Unterhaltungsliste und getrennte Nachrichten mit Absender und Zeit. Die Eingabe ist groß; zusätzliche Teilnehmer-, Anhang- und Pushfunktionen sind gegliedert. Nachrichtentexte gehen bei Fehlern oder lesender Aktualisierung nicht verloren. Gesendet wird ausdrücklich über den Knopf.
- Die Textvorschau wechselt Dokumenttyp und Überschrift und zeigt ungespeicherte Eingaben. Erinnerungs- und Spendenmuster verwenden den bearbeitbaren Nachrichtentext. Die Seite erklärt den Unterschied zwischen Nachrichtenvorschau und den PDF-Feldern Einleitung/Schlusstext. Ein Musterdownload speichert keine Vorlage und versendet nichts.

## Nachweise

- 28 betroffene PHP-Suiten bestehen, einschließlich Inventar-, Chat-, Einstellungs-, Vorschau- und Briefprüfungen.
- 15 Inventar-Browserprüfungen mit echten synthetischen Speichervorgängen und Datenbankvergleich; zusätzlich beide Erfassungsarten bei 1280 und 390 Pixeln geprüft.
- 24 Backendprüfungen und neun Browser-/HTTP-Prüfungen der Teilentfernung. Die verstärkte Browser-Fixture enthält eine echte synthetische Rechnung samt Originalbeleg. Nach dem Abgang bleiben IDs, Verknüpfungen und heruntergeladene Originalbytes identisch.
- 15 Chat-Browserprüfungen sowie vier Nachprüfungen der mobilen Darstellung und Klickflächen. Vorhandene neun Rechte-/Bearbeitungsprüfungen bestehen.
- 45 Textvorschau-Browser-/HTTP-Prüfungen über acht Vorlagenkombinationen mit tatsächlichen PDF-Downloads und extrahiertem Inhalt; gespeicherte Vorlagen bleiben unverändert. Zusätzlich 17 PDF-Layout-/Inhaltsprüfungen und 13 Brief-/Dokumentkontextprüfungen.
- Syntax der 24 geänderten PHP-/JS-/Python-Dateien geprüft. Handbuch mit aktualisierten Kapiteln 15, 16 und 19 gebaut; 13 Medien- und 59 Hilferoutenprüfungen bestehen.

Alle schreibenden Tests verwenden ausschließlich getrennte lokale Testvereine und erfundene Personen. Kein echter Chatbeitrag, keine Mail, kein Pushversand und keine Bankaktion wurden für die Tests ausgelöst. Die breite Prüfung und ihre nativen Integrationsgrenzen sind weiterhin im [vorherigen Prüfbericht](INVENTAR-UI-GESAMTPRUEFUNG-2026-09-08.md) dokumentiert. Die [WISO-/Veröffentlichungsprüfung](PRODUKT-VEROEFFENTLICHUNG-WISO-PRUEFUNG-2026-09-08.md) ist eine gesonderte Herkunfts- und Verteilungsbewertung.

## Veröffentlichung

Commit `1699337a4ad5f1d043b1dda6001db0a57bbb91f8` wurde live veröffentlicht. Alle 25 Ziel-Dateien wurden vorher gegen den bisherigen Git-Stand abgeglichen, gesichert und nach dem Upload bytegenau gegen den Commit geprüft. PHP und der Python-PDF-Renderer bestanden zusätzlich die Syntaxprüfung auf dem Hosting. Es gab keine Datenbankmigration und keine Bestandsänderung.

Die Livekontrolle bestätigt beide sichtbaren Inventarbeispiele und das entfernte Abteilungsfeld. Beim Bühnenpodest ist die Teilentfernung mit „1 von 4 entfernen; 3 bleiben in diesem Bestand“ und geöffneter Stückauswahl erreichbar; sie wurde nicht ausgeführt. Die Textvorschau zeigt beim Wechsel zur Erinnerung den richtigen Titel und einen ungespeicherten Mustertext mit aufgelöstem Platzhalter. Anschließend wurde der Entwurf verworfen; kein Textbaustein gespeichert. Die neue Chatansicht lädt ohne Fehler; im geprüften Livekonto bestehen keine Unterhaltungen, daher erfolgte dort keine Nachrichtenprüfung. Senden, Bearbeiten und Anhänge sind durch die lokalen synthetischen Prüfungen abgedeckt.

Private Evidenz: `.local/manager-chat-texts-inventory-deploy-result.json`. Rückfallkopie: `/tmp/kulturhus-verein/rollback-manager-chat-texts-inventory-1699337a4ad5`.
