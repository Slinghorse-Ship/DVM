# Gespeicherte Zustände und gemeinsames Konto

Prüfung und Korrekturen vom 7. September 2026, aufbauend auf `d129d32`.

## Bestätigte Probleme und Änderungen

| Bereich | Problem | Korrektur |
|---|---|---|
| Vorstandsamt | Das bestätigte Prüfhäkchen wurde nicht als gespeicherter Zustand geführt. | Eigene Prüfhistorie mit Amt, Version, Prüfperson und Zeitpunkt; sichtbares gespeichertes Häkchen. Unverändertes Speichern erhält den Vermerk und laufende Abstimmungen. |
| Gemeinsames Konto | Die Darstellung wirkte wie zwei getrennte Konten; eine manuelle Freigabe verlangte zwingend eine zusätzliche Rolle. | Website und Vereinsmanager verwenden denselben Zugang. Neue Zuordnung ohne Zusatzrolle verwendet Mitgliedsbasis; bestehende Rechte bleiben bei leerer Auswahl erhalten. |
| Mitgliedsprofil | Bereits vorhandene Kontoverknüpfungen ohne manuellen Anlageauftrag wurden nicht angezeigt. | Die Kontoverknüpfung und gespeicherte Managerfreigabe werden unabhängig vom Anlage-/Mailauftrag dargestellt. |
| Ehrenamtsbestätigung | Eine einzeilige Adresse mit Komma wurde vollständig als Straße übernommen. | Deutsche Anschriften mit PLZ werden in Straße/Hausnummer und PLZ/Ort aufgeteilt; nicht eindeutig erkennbare Angaben bleiben zur Ergänzung offen. |
| Ehrenamtsbestätigung | Eine frühere Vorschau konnte beim erneuten Öffnen aktuelle Profilangaben überdecken. | Ein frischer Seitenaufruf übernimmt das aktuelle Profil. Eine zwischenzeitliche Profiländerung macht eine ältere Vorschau ungültig. |
| Dokumente | Zuordnungen zu archivierten Personen oder nicht mehr aktiven Quellen fehlten im Bearbeitungsformular. | Bereits gespeicherte, weiterhin autorisierte Zuordnungen werden zur bewussten Beibehaltung angezeigt. Ein reines Umbenennen entfernt sie nicht. |
| Einstellungen | Nach einem Validierungsfehler konnten frühere Werte statt der gerade eingegebenen Schalter, Texte oder Zeilen erscheinen. | Die Fehleransicht erhält die passenden Eingaben und die ursprüngliche Konfliktversion; die Vorschau kennzeichnet ungültige Entwürfe. |
| Steuerkonten | Deaktivierung und Bearbeitungswerte wurden nach Fehlern falsch vorbelegt; gespeicherte inaktive Konten verschwanden aus dem Standardfilter. | Bearbeitung und Neuanlage bleiben getrennt. Die Erfolgsansicht zeigt gezielt das gerade gespeicherte Konto unabhängig vom Aktivstatus. |
| Newsletter | Bei Fehlern sprang die Zielgruppe zurück, während Text und Betreff erhalten blieben. | Die gewählte Zielgruppe bleibt zusammen mit dem passenden Entwurf erhalten. |
| Familie und Ausleihe | Fehlgeschlagene Änderungen verloren Eingaben, Rückgabemengen, Orte oder ursprüngliche Versionsangaben. | Nur das betroffene Formular erhält seine eigenen übermittelten Werte und Konfliktkennungen zurück. |

## Abgrenzung

Ein gespeicherter Zustand wird aus dem Bestand angezeigt. Die nach einem Fehler erhaltene Eingabe ist dagegen weiterhin ungespeichert; die Fehlermeldung und ursprüngliche Version bleiben erhalten. Prüfhäkchen für eine konkrete neue Handlung, beispielsweise das Ablegen einer PDF-Vorschau oder eine Superuser-Freigabe, sind keine pauschale dauerhafte Einwilligung.

Bestehende Mitglieder erhalten durch diese Veröffentlichung keine geänderten Konten oder Rollen. Die Grundrolle Mitglied ist bereits der Standard neuer Mitgliedskonten; reine Kontakte bleiben Kontakte. Zusätzliche Vorstands-, Verwaltungs- und Finanzrechte werden nicht pauschal überschrieben. Es werden keine Mails versandt.

## Datenmigration

Migration 042 ergänzt ausschließlich die Tabelle `admission_board_checks` mit unveränderbaren Prüfvermerken. Alte Vorstandsberechtigungen bleiben bestehen. Eine nicht dokumentierte frühere Prüfung wird nicht rückwirkend behauptet. Nach einer tatsächlichen neuen Bestätigung werden Datum und Prüfperson dauerhaft angezeigt.

## Prüfung

Gezielte Dienst-, Formular- und Browserprüfungen behandeln erfolgreiche Speicherung, erneutes Öffnen, widersprüchliche Versionen, Validierungsfehler, passende Objektzuordnung und mobile Darstellung. Das Ehrenamts-PDF wird mit erfundenen Angaben erzeugt, visuell geprüft und zusätzlich über alle neun kanonischen Formularfelder und Seiten-Widgets kontrolliert.

Weitere gelesene Pfade umfassen Kalender/RSVP, Inventar, Website-Felder, GEMA-Status und Dokumentstatus. Diese Prüfung ist kein Nachweis jeder denkbaren Kombination aller Formulare. Konkrete gefundene Fehler werden mit reproduzierbaren Regressionstests abgesichert.

Erfolgreiche Prüfungen:

- Amtsprüfung: 35 neue Prüfungen, 13 bestehende Aufnahme- und 33 Zugriffstests; Browser mit gespeicherter Prüfung und 390-Pixel-Ansicht.
- Gemeinsame Identität: 36 Prüfungen einschließlich Basisrolle, unveränderten Zusatzrechten, Profilzuordnung und Fehleranzeige; ergänzend 22 Kontoanlage- und 26 Provisionierungsprüfungen.
- Familien-/Ausleiheformulare: 11 neue Prüfungen; Newsletter-Zielgruppe: 4 neue Prüfungen.
- Einstellungen: 16 neue und 87 bestehende Prüfungen; Dokumentzuordnungen: 7 neue und 74 bestehende Prüfungen.
- GEMA: 27 Ansichts- und 22 Detailprüfungen, einschließlich gespeichertem Meldestatus, Setlist und Zahlungszuordnung. Keine Korrektur des GEMA-Laufzeitcodes notwendig.
- Ehrenamtsbestätigung: 20 neue Adress-/Zustandsprüfungen und 52 bestehende Prüfungen; tatsächliches Original-PDF visuell und über alle neun Formularfelder geprüft.
- Steuerkonten: lokales HTTP-Speichern und Neuladen eines deaktivierten synthetischen Kontos sowie anschließender Validierungsfehler; Anzeige und Datenbestand korrekt, mobil ohne Überlauf.

## Veröffentlichung und Liveprüfung

Am 7. September 2026 wurde Commit `aa48b07` auf Grundlage von `d129d32` veröffentlicht. Alle 25 Laufzeit-Zieldateien wurden nach dem Upload zurückgelesen und stimmen mit dem Commit überein. Die Datenbank verwendet Schema 42. Die Rückfallsicherung liegt unter `/tmp/kulturhus-verein/rollback-state-consistency-20260907`.

Die Migration wurde zunächst zweimal auf einer privaten Datenbankkopie geprüft. Auf dem Livebestand wurde unter Schreibsperre bestätigt, dass alle bisherigen Spalten und Zeilen erhalten blieben. Datenbankintegrität und Fremdschlüsselprüfung waren erfolgreich.

Nach der Veröffentlichung bestanden 32 öffentliche beziehungsweise zugriffsgeschützte HTTP-Prüfungen, sieben Handbuch-/Medienschutz-Prüfungen sowie zwölf Dokument-/Unterschriftsrouten- und Asset-Prüfungen. Eine angeforderte, unsignierte Ehrenamtsbestätigung wurde mit dem tatsächlichen Mitgliedsprofil live als PDF-Vorschau erzeugt und visuell geprüft: Straße/Hausnummer sowie PLZ/Ort stehen für Mitglied und Verein in den vorgesehenen Feldern. Dabei wurde kein Dokument gespeichert, keine Unterschrift verwendet und keine Nachricht versandt.

Die abschließende Liveansicht der Rollenverwaltung war mit der zu diesem Zeitpunkt verfügbaren Sitzung nicht zugänglich; die Anfrage führte zum eigenen Vereinsprofil. Die Funktionsnachweise zur Amtsprüfung und Rollenanzeige beruhen deshalb auf den oben genannten lokalen Dienst-, Formular- und Browserprüfungen. Reale Vorstandsbestätigungen und Kontorechte wurden nicht zu Testzwecken geändert.
