# Erneute Newsletter-Bestätigung im eigenen Manager

Unter Newsletter → Empfänger gibt es eine aufklappbare Sammelaktion und eine
Aktion je unbestätigter Anmeldung. Die Sammelaktion nennt die erreichbare Anzahl
und umfasst den ganzen Verein, unabhängig von Suche und Kontaktseite. Das
Pflichtkästchen ist immer leer. Beide Wege planen individuelle private Jobs;
sie bestätigen keine Einwilligung und lösen im Web keinen Versand aus.

Die vorhandene Newsletter-Verwaltungsberechtigung gilt unverändert. Im
integrierten Manager erhalten Newsletter-Schreibberechtigte diese Fähigkeit;
ein Mitglied kann weder die Empfänger noch die Aktion öffnen. Ein eigenständiges
natives Konto benötigt ausdrücklich `newsletter/manage`.

## Fachregeln und Wiederaufnahme

- Nur vorhandene unbestätigte Anmeldungen mit Adresse, Themen, ursprünglichem
  Datum, Einwilligungsnachweis und nicht verbrauchtem DOI-Antrag werden ausgewählt.
  Der alte Link darf abgelaufen sein. Bestätigte, abgemeldete und gesperrte
  Adressen werden ausgeschlossen.
- Bereits laufende ursprüngliche Bestätigungsmails und erneute Anfragen werden
  nicht verdoppelt. Unklare erneute Zustellungen bleiben unabhängig von der
  Wartezeit gesperrt. Die automatische Ein-Tages-Erinnerung verwendet weiterhin
  ihren eigenen ausdrücklich aktivierten Plan und reiht keinen zweiten Auftrag
  neben eine manuelle Erneuerung ein.
- Vorschau und Auftrag binden die konkreten ursprünglichen Angaben, Themen und
  Fassung. Geänderte Auswahl benötigt eine neue Vorschau. Eine Auftragskennung
  bindet Verein, Benutzer, Autoritätsquelle, Einzel-/Sammelauswahl und Vorschau;
  wiederholtes Absenden gibt exakt dieselben Jobkennungen zurück.
- Der bestehende private Newsletter-Worker verarbeitet höchstens 20 manuelle
  Erneuerungen pro Lauf, auch wenn sein Gesamtlimit höher liegt. Der restliche
  Bestand bleibt eingeplant. Nur der ausdrücklich aktivierte Versand verwendet
  den gemeinsamen Newsletter-Mailkanal.
- Erst bei Verarbeitung entsteht ein neuer zufälliger Bestätigungslink mit
  vollen 48 Stunden Laufzeit. Name, Themen, Anmeldedatum, Einwilligungsnachweis,
  Status und Fassung des Originals bleiben erhalten. Die neue Ausgabe erhält
  einen eigenen Nachweis und einen dauerhaften Zustellverlauf.
- Aktuelle Autorität, Kontofreigabe, Verein, unveränderte Anmeldung und Sperren
  werden vor Vorbereitung und vor Transport erneut geprüft. Der verwendbare
  Link und die Versuchsmarkierung werden vor SMTP festgeschrieben. So bleibt
  ein möglicherweise empfangener Link auch bei einem unklaren Transport gültig.
  Solche Aufträge werden nicht automatisch wiederholt.
- Nach einer Verarbeitung beginnt die 48-Stunden-Wartezeit für weitere manuelle
  Anfragen. Fehler vor abgeschlossener Vorbereitung erlauben eine neue explizite
  Anfrage. Ein dauerhaft als laufend markierter unterbrochener Auftrag wird
  ebenfalls nicht wiederholt; sein Betriebsergebnis muss zuerst geklärt werden.
- GET und HEAD zeigen nur das Bestätigungsformular. Erst POST mit gültigem
  Formularschutz bestätigt einmalig. Widerruf stoppt ausstehende Jobs und
  entfernt Adresse und private Links aus ihren verschlüsselten Nachrichten.

Erst- und erneute Bestätigungsmail verwenden dieselbe Zweischritt-Erklärung,
die gemeinsamen Vereinsangaben, das geprüfte lokale Vereinslogo beziehungsweise
das K-Zeichen sowie Impressum-/Datenschutzlinks und einen individuellen
Abmeldelink. HTML und Klartext erklären denselben Vorgang. MIME-Prüfung und
Logoaufbereitung sind eigene Dienste; das Logo benötigt keinen externen Abruf.

## Dateien und Nachweise

`native/Communication/Confirmations/` enthält Auswahl, Erneuerungsauftrag,
Worker-Vorbereitung und Mailinhalt. Die Oberfläche liegt in
`native/Web/Communication/ConfirmationPanel.php`, gemeinsame MIME-Erzeugung in
`native/Delivery/MailMessage.php`. Der vorhandene Outbox-Worker behält die
Transportzustände und seine Wiederholungssperre. Kommunikationsschema 4 ergänzt
Auftragsbindung und Verlauf ohne Änderung ursprünglicher Abonnentendatensätze.

65 native Prüfungen auf frischem synthetischem MySQL bestehen, einschließlich
zweier gleichzeitiger Prozesse, veralteter Auswahl, Rechteentzug, Widerruf,
unklarer SMTP-Annahme, separat festgeschriebenem Token, verspäteter Verarbeitung,
20er-Limit, Originalerhalt und echten MIME-Bytes mit lokalem Logo. Bestehende
Erinnerungs-, Zustell-, Mail-, Perioden- und Navigationssuiten bestehen ebenfalls.
Der angemeldete Browser prüft Pflichtbestätigung, Auftragswiederholung, Verlauf,
Rollen, Tastatur und 1440/820/390 Pixel. Externe Browseranfragen sind blockiert;
kein realer Mailtransport wurde ausgelöst.

Dieser Nachweis
belegt den implementierten nativen Ablauf und keinen Versand an echte Kontakte.
