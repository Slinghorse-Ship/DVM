# Maßnahmenfristen aus Originalbeurteilungen

Jede Zeile eines Gefährdungsregisters kann zusätzlich eine **Maßnahmenfrist**
und **Erledigt am** enthalten. Beide Angaben sind freiwillige, streng geprüfte
Kalendertage. Sie werden mit der Gefährdung, den unveränderten Maßnahmen,
Verantwortlichkeit und Wirksamkeitsprüfung in der Dokumentfassung gespeichert.
Die bisherigen acht Spalten bleiben gültig; vorhandene Originale bekommen
keine erfundenen Datumswerte und benötigen keine Datenbankmigration.

Der gemeinsame Kalender unter **Dokumenten- & HSE-Fristen** zeigt die
Maßnahmenfrist, solange kein gültiges Erledigungsdatum eingetragen ist. Eine
Dokumentfreigabe erledigt keine Maßnahme. Ein Erledigungsdatum ist eine
ausdrückliche Dokumentangabe; DVM beurteilt die Wirksamkeit nicht automatisch.
Unabhängige Dokumentprüf- und Klärungsfristen bleiben erhalten. Freitext, auch
ein darin genanntes Datum, wird nicht als Termin interpretiert.

Gleiche Tage eines Dokuments werden gemeinsam angezeigt und nennen Register
und Zeilennummer. Der Kalender öffnet die konkrete Originalrevision. Änderungen
und Erledigungen wirken sofort, ohne Terminkopien oder neue Erinnerungsjobs.
Alle ganztägigen Fristen gelten in Europe/Berlin. Der Zugriff verwendet die
aktuellen Dokumentrechte und die für das Original gespeicherte Vorlagenfassung.
Gefährdungstexte, Maßnahmentexte und Personennamen gelangen nicht in die
Kalenderprojektion. Historische Freigaben, Anlagen, Unterschriften und Belege
bleiben unverändert.

Die Datumsfelder funktionieren auch ohne JavaScript. Im interaktiven Editor
bleiben sie beim Umordnen mit ihrer ursprünglichen Zeile verbunden; neue Zeilen
beginnen mit leeren Datumsfeldern. Druckvorschau und HTML-/JSON-/PDF-Quelle
zeigen die gespeicherten Daten neben den Maßnahmen. Bei einem Versionskonflikt
bleiben vorgeschlagenes Datum und ursprüngliche Formularversion für die
Korrektur sichtbar; es entsteht keine Teilfassung.

Prüfung: 62 tatsächliche MySQL-/Formular-/Export-/Kalenderverträge sowie
68 angemeldete Browserprüfungen bei 1440/820/390 Pixeln. Der Browser speichert
drei eigene Entwurfsrevisionen, weist eine konkurrierende Speicherung ab und
prüft Touch, Tastatur, Zeilenwechsel, dynamische Vorschau, Erledigung,
JavaScript-freies Speichern, 200 % Zoom und tatsächliche Mitgliedsrechte.
Anschließende Originalbyteprüfung erhält übrige Beurteilungen, Anlagen,
Unterschriften, persönliche Dokumente und die frühere eigene Freigabe.
Die vorhandene HSE-Dokumentprüfung mit tatsächlicher PDF-Erzeugung und
persönlicher Unterschrift besteht mit 30 Kontrollen; der bestehende Kalender
mit über 500 Originalen bleibt vollständig. Alle 77 nativen Suiten einschließlich
Neuinstallation und verschlüsselter Recovery sowie die Offline-Inventar- und
Paketprüfungen bestehen.

`tools/cloud/bootstrap.sh --verify` reproduziert die Browserprüfung mit eigenen
synthetischen Entwürfen. Die beiden HSE-Dienstsuiten laufen in der nativen CI.
Weitere Maßnahmenverwaltung, Zustellungen und die vollständigen Fachabläufe
bleiben im Umsetzungsregister.
