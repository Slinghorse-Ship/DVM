# Angemeldete Live-Bedienprüfung am 08.09.2026

Die zuvor wegen abgelaufener Anmeldung offenen Prüfungen wurden mit der wieder aktiven Anmeldung ausgeführt. Die Steuererklärung 2025 ist nach Angabe des Vorstands noch nicht abgegeben; der Verein besteht bereits seit längerem.

## Tatsächlich ausgeführte Bedienung

- Steuerjahr 2025 gewählt, Buchführung ausdrücklich als unvollständig beschrieben und unbekannte Jahreswerte leer gelassen. Vorschau erzeugt, vorhandenen Freistellungsbescheid und die eingegangene Roll-up-Rechnung über die Nachweissuche ausgewählt, Vorschau aktualisiert und genau einen echten Arbeitsstand gespeichert.
- Der Speichern-Knopf wurde nach Änderung der Anlagen gesperrt, bis eine neue Vorschau vorlag. Tatsächlicher ZIP-Download im Browser und Wiederaufnahme des gespeicherten Standes erfolgreich. Zeitraum, Notiz, unvollständiger Prüfstand, leere unbekannte Werte und beide Anlagen blieben erhalten.
- Unabhängiger lesender Vorher-/Nachher-Abgleich: Genau ein neues Steuer-Arbeitsdokument, gültiger ZIP-Inhalt, übereinstimmender Snapshot und Anlagenhashes. Finanzbuchungen, Inventar und sämtliche ursprünglichen Dokumentdateien unverändert. Prüfhelfer anschließend entfernt.
- Rechnungseingang mit der vorhandenen Roll-up-Rechnung geöffnet. Rechnungsjahr 2025, 39,33 EUR netto, 7,47 EUR Umsatzsteuer und 46,80 EUR brutto werden passend dargestellt; Positionssumme stimmt. Der Hinweis auf PayPal bestätigt keine Zahlung.
- Der Hinweis „Zahlbar bis“ führt direkt zum sichtbaren Datumsfeld. Nach einer Änderung der Positionsbeschreibung wird die zuvor gesetzte Prüfung wieder aufgehoben. Offene Sphäre und Steuerkonto werden als nachzuarbeiten beschrieben und nicht pauschal zu Pflichtfehlern gemacht. Entwurfsänderungen verworfen; keine Rechnung gespeichert und keine Firma angelegt.
- Lieferantendialog visuell geprüft: Name, Anschrift, E-Mail und Telefon aus der Erkennung stehen beim ersten Öffnen im Formular. Die automatisierte AX-/DOM-Ansicht gab E-Mail und Telefon leer wieder; der Screenshot widerlegte diesen vermeintlichen Erstöffnungsfehler. Der unabhängig reproduzierte Fehler beim erneuten Öffnen nach aktualisierter Erkennung ist mit der unten genannten Veröffentlichung behoben.
- Superuser-Bankroute einschließlich Mitgliedsauswahl und Passwortbestätigung sichtbar. Bei einem Mitglied ohne hinterlegte Daten wird ein leeres Formular angeboten; SEPA bleibt deaktiviert. Keine vollständigen Bankdaten freigegeben, keine Authentifizierungsnachricht und kein Schreibvorgang ausgelöst. Die vollständigen Schreib-/Passwort-/optionalen Zwei-Faktor-Abläufe sind durch die bestehenden synthetischen Tests abgedeckt, nicht durch Änderungen an echten Bankdaten.
- Steuerformular, Rechnungseingang, Bankansicht und Handbuch bei 390 Pixeln ohne horizontale Seitenüberbreite geprüft. PDF-Vorschau tatsächlich geladen und vergrößert. Handbuchkapitel 19 sowie seine drei Abbildungen mit Hinweisen erreichbar und geladen. Keine Browser-JavaScriptfehler in den geprüften Ansichten. Temporäre Bildschirmbreite zurückgesetzt.

## Bei der Prüfung gefundene Lücke

Die Nacharbeitsliste für 2025 meldete keine Aufgaben, obwohl eine noch nicht erfasste Rechnung von 2025 im Eingang lag. Die bisherige Liste berücksichtigte nur gespeicherte Finanzbelege und Kontoumsätze. Die Ergänzung nimmt offene Eingangsrechnungen auf; unbekannte Rechnungsjahre werden gesondert angezeigt. Die Liste verwendet nur einen aktuellen, an Revision und Originalhash gebundenen Feldcache und startet keine Auslese. Direkte XML-Detailprüfungen erzeugen keinen solchen Cache; ohne ihn wird kein Rechnungsjahr behauptet.

Der Lieferantendialog aktualisiert beim Wiederöffnen nun unberührte Erkennungsvorschläge und entfernt zurückgenommene Vorschläge. Eigene Änderungen und bewusst geleerte Felder bleiben erhalten. Ein anderer Firmenname übernimmt keine Kontaktdaten des vorherigen Lieferanten. Auch eine einzelne allgemeine Bankverbindung wird bei Klarna/PayPal nicht automatisch ausgewählt; eine bewusste Auswahl bleibt möglich.

Lokale Nachweise: 42 neue Eingangslistenprüfungen und 19 bisherige Queue-Prüfungen; echte lokale HTTP-Oberfläche mit synthetischem Finanzleser bei 1280 und 390 Pixeln; neun Lieferantendialog-Browsergruppen mit synthetischen Erkennungsdaten in der vollständigen Anwendung. Zusätzlich 18 Anlage- und 13 Verknüpfungsprüfungen für Lieferanten. Handbuch neu gebaut, 59 Hilferouten und 13 Medienprüfungen bestanden.

## Veröffentlichung und Live-Nachprüfung


Die echte Roll-up-Rechnung erscheint jetzt mit Datum 22.07.2025 und 46,80 EUR unter Nacharbeiten 2025. Ein älterer Beleg ohne aktuellen Erkennungsstand bleibt korrekt in „Rechnungsjahr noch prüfen“. Wechsel auf 2024 und Suche nach dem Lieferanten liefern die beiden vorhandenen Rechnungen dieses Jahres; Suche nach Rollup in 2025 liefert genau den passenden Vorgang. Der direkte Prüf-Link öffnet dessen echtes Rechnungsformular. Die neue Liste wurde bei 1280 und 390 Pixeln geprüft, ohne Seitenüberbreite oder JavaScriptfehler.

Die veröffentlichte Seite lädt `invoice-recognition.js?v=14` und `invoice-supplier.js?v=7`. Beide öffentlichen Dateien entsprechen dem Commit auch beim HTTP-Abruf. Die Bildkontrolle des neuen Lieferantendialogs bestätigt die Kontaktdaten und den Hinweis zur getrennten PayPal-Zahlungsanweisung. Das überarbeitete Handbuch enthält die neuen Wege und die korrekte Abschnittsbezeichnung. Startseite, Login, Galerie und Programmarchiv antworten erfolgreich.

Nach Veröffentlichung und Browserprüfung wurde der unveränderte Finanz-, Inventar- und Originaldatenbestand erneut gegen die Ausgangssicherung geprüft. Weiterhin genau ein zusätzliches Steuer-Arbeitsdokument; Snapshot, ZIP, Quellenhashes und Wiederaufnahme bestanden. Private Belege: `.local/authenticated-live-review-deployment-result.json`, `.local/authenticated-live-review-public-checks.json`, `.local/tax-live-workflow/`. Temporäre Prüfendpunkte entfernt; Bildschirmbreite zurückgesetzt.

## Grenzen dieses Nachweises

Es gab keine Testmail, Zahlung, Buchung oder Steuerübermittlung. Der gespeicherte Arbeitsstand ist ausdrücklich unvollständig und ersetzt keine abgegebene Steuererklärung. Vorbereitungsunterlagen sind keine automatische Körperschaftsteuerberechnung und kein ERiC-Übermittlungsdatensatz. Dieser gezielte Live-Nachweis ist keine pauschale Freigabe aller Funktionen der Anwendung.
