# Zentrale Dokumentenablage

Stand: 5. September 2026. Nutzerauftrag: Ordnerpfade und sinnvolle Zuordnungen für eingehende Belege, erzeugte Dokumente, Spenden und Organisation. Lokal mit synthetischen Daten umgesetzt, ausschließlich für unseren Verein. Keine Veröffentlichung.

## Bedienung

**Orga → Dokumente** zeigt die Ordnerstruktur, vollständige Pfade, Suche, Dokumentarten, Archiv und Dateiversionen. **Datei hochladen** übernimmt ein Original und ordnet es einem oder mehreren Vorgängen zu. Ohne Vorgangszuordnung oder gewählten Ordner landet ein Upload im Eingang des jeweiligen Bereichs. Die Ansicht **Ohne Zuordnung** zeigt solche eingegangenen Dateien. Bereits automatisch abgelegte Bereichsexporte und Vorschauen benötigen keine einzelne Vorgangszuordnung.

**Vorgänge ohne Datei** zeigt bestehende Vorgänge, zu denen noch kein sichtbarer aktiver Anhang abgelegt ist. Die Vorgänge selbst und ihre Ordner existieren bereits; hier werden keine angeblich vorhandenen PDFs vorgetäuscht.

Belege, Sachspenden, Mitglieder/Kontakte, Anträge, Termine, Konten/Kassen, Gegenstände und Ausleihen besitzen eine Dokumentkarte mit Pfad, Dateien und direktem Einstieg zur Ablage. Die bisherige Inventar-Dateiansicht verwendet dieselben Dateien. Ein Wertnachweis kann beispielsweise sowohl mit der Sachspende als auch mit dem daraus übernommenen Gegenstand verknüpft sein.

## Standardpfade

| Inhalt | Automatischer Pfad |
|---|---|
| Geldspende | `/Spenden/Jahr/Geldspenden/Belegnummer/` |
| Sachspende | `/Spenden/Jahr/Sachspenden/Bezeichnung · Kennung/` |
| Einnahme, Ausgabe, Beitrag | `/Finanzen/Jahr/Einnahmen, Ausgaben oder Beiträge/Belegnummer/` |
| Konto / Handkasse | `/Finanzen/Bankkonten oder Kassen/Kontoname · Kennung/` |
| Mitglied / Kontakt | `/Mitglieder/Name · Kennung/` |
| Mitgliedsantrag | `/Mitglieder/Anträge/Jahr/Kennung/` |
| Inventargegenstand | `/Inventar/Gegenstände/Inventarnummer/` |
| Ausleihe | `/Inventar/Ausleihen/Jahr/Ausleihnummer/` |
| Termin | `/Orga/Termine/Jahr/Titel · Kennung/` |
| Bereichsexport | `/Bereich/Jahr/Exporte/` |
| Briefpapier-/Textvorschau | `/Einstellungen/Jahr/Vorschauen/` |
| Eingang ohne Zuordnung | `/Bereich/Jahr/Eingang - noch zuordnen/` |

Kennungen verhindern Verwechslungen gleichnamiger Vorgänge. Eigene Unterordner sind möglich. Ordner lassen sich umbenennen und innerhalb ihres Berechtigungsbereichs verschieben. Verknüpfungen verwenden feste IDs; vorhandene Pfade bleiben bei späteren Änderungen eines Kontaktnamens erhalten. Die Anzeige des zugehörigen Vorgangs aktualisiert sich. Ordnerpfade sind logische Pfade in der Anwendung, keine Freigaben im Windows-Dateisystem oder auf OneDrive.

## Originale und erzeugte Dokumente

- Ein Upload wird als Originaldatei mit SHA-256-Prüfsumme gespeichert. Weitere Dateiversionen erhalten eigene unveränderliche Originale. Frühere Versionen bleiben herunterladbar.
- Ein Dokument hat beliebig viele der aktuell maximal 30 zulässigen Vorgangszuordnungen, aber nur eine Originaldatei pro Version. Mehrere Zuordnungen kopieren die Datei nicht.
- **PDF-Nachweis erstellen & ablegen** speichert einen konsistent gelesenen Datenstand samt PDF. Beleg-/Spendenpositionen und Kontierung, Ausleihpositionen und Übergabeverlauf sowie Antragsangaben/Einwilligungsstand werden berücksichtigt. Spenden-PDFs sind ausdrücklich interne Nachweise; sie sind keine ausgestellten Zuwendungsbescheinigungen.
- Kassenbuch-, Finanz-, Kontakt- und Inventar-CSV sowie Briefpapier-/Text-PDF-Vorschauen werden vor dem Download automatisch gespeichert. Schlägt die Ablage fehl, wird kein unarchivierter Download ausgegeben. Die reine HTML-Vorschau erzeugt keine Datei.
- Erzeugte Dateien können nicht durch spätere Uploads oder eine andere Dokumentart ersetzt werden. Neue Datenstände ergeben neue Nachweise. Die Erzeugung aus einem Vorgang ändert dessen Geldbeträge nicht.
- Metadaten, Zuordnungen und Archivstatus benötigen eine Versionsprüfung und einen Änderungsgrund. Archivierung erhält Datei und Verlauf. Auch nach Löschung eines Termins bleiben Dateien und historische Verknüpfungen zugänglich für Berechtigte.
- Historische Browserdrucke, frühere Downloads vor dieser Erweiterung und extern erstellte Dateien werden nicht automatisch zurückgeholt. Solche Originale lassen sich hochladen. Die vorhandene Browserdruckansicht für Ausleihen bleibt zusätzlich verfügbar; der neue PDF-Ablauf speichert gezielt in der zentralen Ablage.

## Zugriff und Speicherung

Allgemeine Orga-Dokumente haben ein eigenes Rollenrecht. Finanz-/Spenden-, Mitglieder-, Inventar-/Ausleih- und Einstellungsdateien folgen den jeweiligen Bereichsrechten. Bei mehreren Bereichen werden **alle** benötigten Rechte geprüft. Eine Zuordnung zu einem Inventargegenstand gibt einen Finanzbeleg daher nicht allein mit Inventarrechten frei. Verschieben oder Entfernen einer Zuordnung reduziert den bereits gespeicherten Schutz nicht. Dies gilt auch für alte Dateiversionen und Anhangszähler. Eine gezielte Herabstufung/Freigabe an weitere Personengruppen ist nicht implementiert.

Die lokale Datenbank liegt außerhalb von `public/`. Jeder Download prüft Sitzung, Verein und Objektberechtigung. Nur PNG/JPEG können als Bild angezeigt werden; PDF, Office-Dateien, ZIP und Text werden als Downloads ausgeliefert. Keine öffentlichen Freigabelinks. Redaktionszugänge haben keinen Zugriff auf die Vereinsablage.

Schema 13 enthält Ordner, Vorgangsindex, Dokumentmetadaten, Dateiversionen, Zuordnungen, Idempotenzkennungen und Historie. Datenänderung und Audit werden atomar gespeichert. Vorhandene Inventardateien werden einmalig übernommen; die alten IDs bleiben gültig. Die bisherige Tabelle bleibt als Migrationsbestand erhalten und erhält keine neuen Uploads. SQL-Trigger schützen die gespeicherten Dateiversionen und Historieneinträge gegen reguläres Überschreiben/Löschen; dies ist kein extern abgesicherter Unveränderlichkeits- oder GoBD-Nachweis.

## Verifikation

- 73 neue PHP-Fachprüfungen: automatische Pfade, mehrere Quellen, Zugriffsrechte, alte Versionen, Typ-/Größenprüfung, Ordnerzyklen, Versionskonflikte, idempotente Wiederholungen, Audit-Rollback, gelöschte Quellen, Migrationsbestand und unveränderte Geldbewegungen.
- 87 HTTP-/PDF-Prüfungen: echte Uploads, Zuordnungen, Originaldownload, Archivierung, sieben tatsächlich vorhandene Vorgangsarten als PDF, Exportablage, CSRF und gesperrte Testrollen. Termin-PDF-Daten werden zusätzlich im PHP-Test geprüft; im lokalen Bestand besteht derzeit kein Termin.
- 7 Layoutprüfungen für lange Absätze und Tabellen, Seitenumbrüche, wiederholte Tabellenköpfe, erhaltene Schlusszeilen und ausbleibende leere Schlussseiten. PDF-Seiten mit Poppler gerendert und visuell geprüft.
- Bestehende Fach-/PDF-/HTTP-Prüfungen bestanden ebenfalls. Gesamt: **832 Prüfungen** (644 PHP, 24 PDF-Renderer, 164 HTTP einschließlich PDF-Endpunkten).
- Browser: Ordner unter Orga angelegt, Notiz/Mehrfachzuordnung gespeichert; Desktop- und schmale Ansichten kontrolliert. Bank und Kasse unverändert: 2.654,50 EUR und 171,35 EUR.

## Offene Grenzen

Uploads sind lokal auf **2 MB je Datei** begrenzt. PDF, PNG/JPEG, TXT/CSV/JSON und Office-/ZIP-Container werden angenommen; es gibt noch keinen Virenscanner oder vollständigen PDF-/Office-Inhaltsparser. Die WISO-Benchmarkgrenze von 20 MB/30 PDF-Seiten ist noch nicht umgesetzt. Mehrfachupload mit Fortschritt, eingebettete PDF-Vorschau, OCR/E-Rechnungserkennung, Mail-/Scannerimport, Volltextsuche im Dateiinhalt und direkter Versand fehlen.

Eigene Freigaben je Einzelordner, Mitglieder-Selbstzugriff, Lösch-/Aufbewahrungsfristen, extern manipulationsgeschützte Archivierung und produktiver Speicherbetrieb benötigen weitere Umsetzung. Bisher werden Dateien archiviert, nicht endgültig gelöscht. Fertige Rechnungen und amtliche ausgefüllte Zuwendungsbescheinigungen bleiben eigene offene Arbeitspakete. Deren spätere Ausgabe muss dieselbe zentrale Ablage verwenden.
