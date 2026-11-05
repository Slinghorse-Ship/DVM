# Dokumente, Unterschrift und Ehrenamtsbestätigung

Stand: 7. September 2026. Aufbauend auf `20ae849`, keine neue Datenbankmigration.

## Verhalten

- Dokumentordner starten eingeklappt. Zwei Schaltflächen öffnen oder schließen alle Zweige; der aktuelle Ordnerpfad bleibt sichtbar.
- Ein in den Steuereinstellungen hinterlegter Freistellungsbescheid erscheint als automatisch verwendeter Nachweis. Er wird nicht länger fälschlich als unzugeordnet gezählt. Gespeicherte Steuerangaben und das Originaldokument sind miteinander verknüpft.
- Unter „Mein Account“ kann der erste Vorstand eine Unterschrift als PNG/JPG hochladen oder mit Maus, Stift oder Finger zeichnen. Vorschau und Bestätigung gehen dem Speichern voraus. Superuser können diese Einrichtung ebenfalls verwalten.
- Mitglieder öffnen die Ehrenamtsbestätigung mit ihren eigenen Angaben. Der berechtigte Vorstand kann eine Person auswählen. Name, Anschrift, Geburtsdatum und Vereinsangaben werden soweit vorhanden übernommen; fehlende Angaben bleiben sichtbar zu ergänzen.
- Die vollständige PDF-Vorschau zeigt das Originalformular vor der Ablage. Erster Vorstand und Superuser können die hinterlegte Unterschrift ausdrücklich einfügen. Formularkorrekturen ändern nicht automatisch den Mitgliedsdatensatz. Es erfolgt keine Übermittlung an den Landkreis.

## Technische Grenzen

Signaturen werden als privates Bild gespeichert. Upload und Zeichnung verwenden die bestehende Upload-Vormerkung; deren Abschluss allein ändert noch nicht die gespeicherte Unterschrift. Der Server prüft Bildinhalt, Größe, Berechtigung und Revisionsstand erneut. Mitgliedern wird kein Zugriff auf die Unterschriftenverwaltung gegeben.

Vorschauen sind an Benutzer und Sitzung gebunden und verfallen. Die spätere Ablage prüft die Vorschau nochmals und protokolliert das Dokument mit persönlicher Freigabe. Wiederholung erzeugt keine zusätzliche Ablage. Änderungen nach einer Vorschau erfordern eine neue Vorschau.

Die Dokumentverwendung wird aus der vorhandenen Steuereinstellung abgeleitet. Es gibt keine Datenmigration und keine Änderung des Originalbescheids oder ausgestellter Zuwendungsbescheinigungen.

## Prüfung

- 52 Dienstprüfungen zur Unterschrift: Rollen, Vereinsgrenzen, PNG/JPG, explizite Prüfung, konkurrierende Änderung, Erhalt des Briefpapiers und Protokollierung.
- 18 neue Prüfungen der Steuerbescheid-Verknüpfung; bestehende Dokument- und Zuwendungsbescheinigungstests erfolgreich.
- Lokale Browserprüfungen für Ordnersteuerung, automatische Verwendung, Unterschriften-Upload und Zeichnung einschließlich mobiler Darstellung.
- PDF- und Berechtigungsprüfung der Ehrenamtsbestätigung mit ausschließlich erfundenen Angaben und einer Testmarkierung.
- 52 neue und 3 vorhandene Prüfungen der Ehrenamtsbestätigung erfolgreich. Im Browser vollständige PDF-Seite, Ablehnung fremder Sitzungen, Sperre veralteter Vorschauen, alte Links und 390-Pixel-Ansicht geprüft.
- Handbuch neu gebaut und im Browser geprüft: 20 Kapitel, 185 Aufgaben, 32 bebilderte Anleitungen und 9 abspielbare Videos; private Medien, Suche und mobile Ansicht erfolgreich.
- Syntaxprüfung aller geänderten PHP- und JavaScript-Dateien erfolgreich.

## Veröffentlichung abgeschlossen


Anschließend waren 32 öffentliche, Gast- und geschützte HTTP-Prüfungen, 7 Hilfe-/Medienprüfungen und 11 Prüfungen der neuen geschützten Routen und öffentlichen Assets erfolgreich. Der ergänzende Abgleich des bisherigen Gesamt-Releases bestätigte 182 Dateiziele; neun Untertiteldateien unterschieden sich ausschließlich in den Zeilenenden.

Im angemeldeten Live-Browser geprüft: Uploadbereich und Zeichenfläche unter „Mein Account“, vorausgefüllte Namen über den bisherigen Personenlink, automatische Verwendung des Original-Freistellungsbescheids, Hin- und Rücklink zu den Steuereinstellungen sowie Ein- und Ausklappen des Ordnerbaums. Dabei wurde keine echte Unterschrift hinterlegt, keine Mitgliederbestätigung abgelegt und nichts extern eingereicht.

Private Rückfallkopie: `/tmp/kulturhus-verein/rollback-documents-signature-20260907`.
