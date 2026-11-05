# Mitgliederoberfläche und Rollen: Prüfung vom 8. September 2026

Die Mitglieder- und Kontaktbearbeitung zeigt persönliche Angaben zuerst. Weitere Angaben, Mitgliedschaft mit Familie und Beitrag, Bankverbindung, Website-Zugang, Inventar und Dokumente sind als große aufklappbare Abschnitte gegliedert. Speichern bleibt jeweils dem betreffenden Formular zugeordnet. Das Einklappen deaktiviert keine Felder und entfernt keine Angaben aus der Speicherung. Listenaktionen sind mit sichtbaren Texten beschriftet.

## Behobener Rollenfehler

Die Website-Auswahllisten waren bearbeitbar, während „Eigene Bereichsfreigaben“ ausgeschaltet war. In diesem Zustand wurden die gewählten Website-Level beim Speichern ignoriert. Der Live-Änderungsverlauf bestätigte erfolgreiche Rollenspeicherungen mit leerer individueller Website-Matrix; es lag kein allgemeiner Datenbankausfall vor.

Eine Änderung einer Website-Auswahl aktiviert die eigenen Freigaben jetzt sichtbar. Bewusstes Ausschalten stellt die abgeleiteten Voreinstellungen auch in den Auswahlfeldern wieder her. Ohne JavaScript weist der Server widersprüchliche Eingaben zurück, statt Erfolg zu melden. Gespeicherte Rollen, wirksame Rechte, Kontozuordnungen und native Website-Berechtigungen bleiben getrennt geprüft. Technische Administration wird nicht durch eine Rollenänderung vergeben.

## Nachweise

- `tests/member-profile-browser.cjs`: echte lokale Formular-POSTs, erneute GETs und Datenbankvergleich für Mitglieder, Kontakte, Firmen, Neuanlage, Zusatzfelder, Eintritt, Familie und Beitragszahler; außerdem Pflichtfeldfehler, veraltete Formulare, eigene Leserechte und Mobilansicht.
- `tests/role-editor-http-browser.cjs`: 14 Prüfungen mit echtem Speichern/Neuladen, Rollenvergleich, Zuordnung zum synthetischen Konto, Rechteentzug, Rückkehr zu Voreinstellungen, Versionskonflikt, Betrieb ohne JavaScript und 390px-Breite.
- `tests/role-editor-save.php`, `tests/role-editor-form-state.php` und `tests/role-editor-controls-browser.cjs`: serverseitige Matrixprüfung, Erhalt zurückgewiesener Eingaben, Mandantengrenzen und Schaltverhalten.
- Bestehende Prüfungen für Einstellungen, Website-Bereichsrechte, native Website-Rechte, Mitgliedschaftskonflikte, Ausleihformulare, Zusatzfelder und Handbuchrouten bestehen weiterhin.
- Desktop- und Mobilansichten wurden zusätzlich anhand synthetischer Screenshots geprüft.

Alle schreibenden Browserprüfungen verwenden einen eigens erzeugten synthetischen Verein auf dem lokalen Port 8091. Die Helfer akzeptieren keine Produktivdatenbank und kontrollieren die aktive Arbeitskopie. Es wurden keine echten Mitglieder oder Live-Rollen verändert und keine Mails versendet. Die Live-Prüfung erfolgt lesend; vorhandene Rollenwünsche werden nicht aus fehlenden gespeicherten Daten erraten.

## Inventar

Bereits separat veröffentlicht und live geprüft: Umlagerungen bieten nur aktive Ausgangsorte mit frei verfügbarem Bestand an; reservierte, ausgeliehene oder gesperrte Mengen sind keine frei umlagerbare Menge. „Zubehör / Packliste“ ist ausgeblendet. Historische Daten bleiben erhalten.
