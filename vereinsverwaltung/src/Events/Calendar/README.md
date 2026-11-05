# Gemeinsame Kalenderquellen

`../CalendarFeed.php` ist der öffentliche Einstieg. Er prüft Zeitraum, Filter und
das aktuelle Kalenderrecht, kombiniert die Quellen und sortiert die Metadaten.
Die Quellen behalten die Originaldatensätze und deren geschützte Öffnungswege.

| Datei | Verantwortung |
|---|---|
| `AppointmentSource.php` | Vereinstermine, Vorstand/Versammlungen und veröffentlichte Website-Veranstaltungen aus `Registry` |
| `AppointmentListing.php` | Begrenzte Suche/Archiv-/Seitenansicht der Originaltermine für Social-Entwürfe; verwendet denselben aktuellen Kalenderakteur aus `Registry`, ohne Teilnehmer oder Berichtsdaten |
| `BirthdaySource.php` | Jährliche Geburtstage zugänglicher Originalprofile einschließlich bestehender Schaltjahrregeln |
| `DocumentCheckSource.php` | Prüfung und Gültigkeitsende eines ursprünglichen Dokumentnachweises |
| `WorkflowSource.php` | Typisierte Prüf-/Entscheidungsfristen der festgelegten Dokumenten-/HSE-Vorlage, mit Originalfassung und aktuellen Modulrechten |
| `LoanSource.php` | Geplante Abholung reservierter Ausleihen und Rückgabefristen offener Ausleihen aus `Inventory` |
| `CommunicationSource.php` | Native Redaktionsplanung und gruppierte Zustellmetadaten; keine privaten Empfängerinhalte |
| `NewsletterPeriodSource.php` | Vorbereitung und Versandfenster aus derselben originalen Newsletterplanung |
| `PublicationSource.php` | Lokale Social-/Newsletterplanung und gebündelte Zustellstände mit nichtprivaten Originalverweisen |
| `Presentation.php` | Gemeinsame Quellenbezeichnungen für Liste, Agenda, Raster und Legende; heutiger Tag in Berlin |
| `Entry.php` | Gemeinsame Eintragsform und lokale Ganztagsdaten mit UTC-Zeitstempel |

Eine Verlängerung, vollständige Rückgabe oder Absage wirkt beim nächsten Lesen
direkt aus der Ausleihe. Abholung und Rückgabe am selben Tag ergeben einen
Eintrag mit beiden Bedeutungen. Namen und private Ausleihnotizen werden nicht
projiziert. Filter und Quelldienste prüfen aktuelle Rollen; ein Filter verleiht
keine Berechtigung.

Neue Quellen gehören hierher. Keine zweite Kalendertabelle, keine Kopie der
Originaldateien und keine Schreib-/Versandaktion im lesenden Feed anlegen.
Die Quellen sind über ihren tatsächlichen Namespace direkt autoloadbar; das
Kompatibilitätsinventar `module-files.json` bleibt für bestehende Klassennamen.

Prüfung in der Git-Entwicklungsumgebung:

```sh
bash tools/tests/run-mysql.sh calendar-workflows.php calendar-loans.php calendar-newsletter-periods.php shared-calendar-documents.php calendar-visible-range.php website-calendar.php calendar-invitations.php
bash tools/cloud/bootstrap.sh --verify
```

Der angemeldete Ausleih-Browserablauf ist zusätzlich in der wiederholbaren
Git-Prüfgruppe enthalten. Er verwendet ausschließlich eigene synthetische
Bestände und blockiert externe Browseranfragen.
