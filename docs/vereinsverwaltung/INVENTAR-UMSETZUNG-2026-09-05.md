> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Inventar, Lager und Ausleihe: Umsetzung und Prüfung

Stand: 5. September 2026. Lokal mit synthetischen Daten umgesetzt; keine Veröffentlichung auf kulturhus.de.

Grundlage ist die Nutzeranforderung [INVENTAR-LAGER-AUSLEIHE.md](INVENTAR-LAGER-AUSLEIHE.md). Bestehende Kontakte, Mitgliedschaften, Finanzbelege, Sachspenden und Kassen werden verknüpft. Es gibt keinen Vereinswechsel. Das WISO-Webinar-Menü wurde ebenfalls entfernt; alte Einstiege führen zum Dashboard.

## Was jetzt bedienbar ist

| Anforderung | Umsetzung | Grenzen / Einordnung |
| --- | --- | --- |
| Gegenstände | Inventarnummer, Name, Kategorie, Beschreibung/Zubehör, Seriennummer, Einheit, Zustand, Zugangstag, Mindestbestand, Fotos und PDF-Nachweise. Einzelgeräte und gleichartige Mengen. | Ganze Einheiten; Einzelgeräte mit eigener Inventarnummer anlegen. Upload PNG/JPEG/PDF bis 2 MB, keine automatische Dokumenterkennung. |
| Eigentum | Vereinseigentum und fremde Leihgaben; Eigentümer aus vorhandenen Kontakten. | Fremde Leihgaben werden nicht zum eigenen Vermögen addiert. |
| Lagerorte | Vereinsräume und private Aufbewahrung, Profilzuordnung, Gebäude, Raum, Regal/Fach, Aktivierung/Archivierung. | Keine Privatadresse nötig. Bei belegtem Ort Aufbewahrer über neuen Ort und bestätigte Umlagerung ändern. |
| Verantwortung | Eigentümer, zuständige Person, Bereich/Abteilung, Stammlager und tatsächliche Aufbewahrung getrennt. | Bereich/Abteilung ist zunächst ein Textfeld; keine Abteilungsrechte oder automatische Mitgliederzuordnung. |
| Ausleihe | Bis 50 Positionen, Entleiher über Profil-ID, Zweck/Veranstaltung, Beginn/Frist, Reservierung, Ausgabe, Teilrückgaben, Schäden, Verlust, Abschluss, Storno vor Ausgabe. | Reservierungen binden den Bestand sofort, auch für künftige Termine. Zeitlich versetzte Wiederverwendung wird nicht automatisch geplant. |
| Verfügbarkeit | Verfügbar, reserviert, ausgegeben, gesperrt/Wartung, Freigabe/Reparatur, Aussonderung. Bestandskorrektur über tatsächliche Zählung. | Keine negativen Bestände; reservierte Mengen können nicht verbraucht oder umgelagert werden. |
| Wo ist was? | Mengen je tatsächlichem Lagerort oder Ausleihe, Aufbewahrer, Zuständigkeit, Stammlager, Frist, letzter bestätigter Zeitpunkt/Akteur. Filter nach Text/Einsatz, Person, Ort, Status. | Dokumentierter Standort, keine Ortung. Ausgabeort kann während einer laufenden Ausleihe erneut bestätigt werden. |
| Rückgabe | Tatsächlicher Rückgabeort je Position, einwandfreie/beschädigte/verlorene Mengen, Datum, Vermerk und Bearbeiter. | Beschädigte Mengen bleiben gesperrt. Verlust zählt nicht als zurückgegebener Bestand. |
| Finanzen | Vorhandene Ausgaben oder Sachspenden verknüpfen; Rückverweise aus diesen Belegen. Name, Datum und Beschreibung bei Anlage aus einem Beleg vorbelegen. Dokumentierter Stückwert mit Herkunft. | Keine zweite Kontotransaktion; Beleggesamtbetrag wird nicht ungeprüft als Stückwert übernommen. Stornierte Ausgaben können nicht neu zugeordnet werden. |
| Vereinsvermögen | Eigene Übersicht unter Finanzen: Stückwert × Gesamtmenge, Kategorie-, Zuständigkeits- und Standortauswahl, fehlende Bewertungen erkennbar. | Standortfilter wählt Gegenstände mit Bestand am Ort; Wert gilt für den ganzen Gegenstand über alle Orte. Keine Abschreibung, Steuerbuchwerte oder Vermischung mit Geldbeständen. |
| Handkassen | Ein Gegenstand je Kassenkonto, gemeinsame Standort-/Personenzuordnung, Link zum aktuellen Kassenbuch, Zählabgleich bei Erstzugang und Übergabe. Beteiligte Profil-IDs, Orte, Zeitpunkt, Akteur und bestätigter Betrag im Verlauf. | Übergabe braucht Inventar-Schreibrecht plus Finanz-Leserecht. Differenzen zuerst im Kassenbuch klären. Aufbewahrung verleiht keine Rechte. Keine fiktive Einnahme/Ausgabe. |
| Mitgliederprofile | Suchbare Auswahl mit Name und Mitgliedsnummer; Aufbewahrung, Verantwortung und Ausleihen im Profil. Zuordnung bleibt bei Namensänderung/Austritt bestehen. | Keine eigenen Personenstammdaten. Sonja/Klaus/Paul wurden nicht anhand ihrer Vornamen zugeordnet oder neu angelegt. |
| Rechte | Separate Rollenfelder für Inventar/Bestand und Ausleihen; jeweils gesperrt, lesen, bearbeiten. Eigene Ausleihen für Testmitglieder; Finanzwerte zusätzlich geschützt. | Ausleihrechte erlauben das Lesen des dafür nötigen Inventars und der Kontaktauswahl. Feinere Objekt-/Abteilungsrechte und produktive Anmeldung bleiben offen. |
| Verlauf / Ausgabe | Mengenjournal, Stammdaten-/Zuständigkeitsverlauf, CSV für Inventar, Lagerbestand, Ausleihen und letzte 1.000 Bewegungszeilen; druckbare Ausleihansicht. | PDF-Speicherung der Ausleihansicht über den Browserdruck. Keine digitale Signatur oder automatisch archivierte Vertrags-PDF. |
| Dashboard | Inventarzahl, Reservierungen, überfällige Ausleihen, Mindestbestände und Einstieg ins Vereinsvermögen. | Keine automatischen Erinnerungsmails. |

## Prüfung

Abschließender Lauf: **665 bestandene Prüfungen** (571 PHP, 17 PDF, 27 Einstellungen-HTTP, 50 Inventar-HTTP). Zusätzlich 82 PHP-Dateien ohne Syntaxfehler, JavaScript-Syntax geprüft, 181 Anforderungs-IDs vollständig, Dateidatenbank-Integrität `ok` und keine Fremdschlüsselverletzungen.

- 82 neue PHP-Prüfungen für das Inventar: Bestandsabgleich mit dem Journal, Reservierung, Ausgabe, Teilrückgabe, anderer Rückgabeort, Schäden, Reparatur, Verlust/Aussonderung, Umlagerung, Verbrauch, Mindestbestand, Namensänderung, Eigentum, Finanzverknüpfung und Kassenübergabe.
- Negative Prüfungen: Überbuchung, Überrückgabe, doppelte Ausgabe, veraltete Versionen, wiederholte Vorgangskennung, unzulässiger Status, fremde Profile/Orte/Dateien, Rollenüberschreitung, CSV-Formeln, aktive Bildformate und fehlgeschlagene mehrteilige Transaktionen.
- Fehler bei Audit-Speicherung rollen Bestände und Journal vollständig zurück. Journalzeilen können durch normale UPDATE-/DELETE-Anweisungen nicht überschrieben werden. Dies ist kein manipulationssicherer Betriebsnachweis gegenüber privilegiertem Datenbankzugriff.
- 50 HTTP-Prüfungen mit eigenem Test-Cookie: neue Ansichten/Formulare, vorhandene Testgegenstände, Beleg-/Kassen-Rückverweise, Profil, CSV, CSRF, Mitglied/Kassenwart/Redaktion und geschützte Werte.
- Browserdurchlauf: zwei Lagerorte, Gegenstand mit Mitgliederverantwortung, Zugang von zehn Bänken, Reservierung und Ausgabe von vier, Rückgabe von zwei an den privaten Lagerort. Danach sechs im Vereinslager, zwei privat aufbewahrt und zwei weiterhin im Saal ausgeliehen.
- Schmale Ansicht bei gemessenen 390 CSS-Pixeln ohne Seitenüberlauf; breite Tabellen scrollen innerhalb ihres Bereichs. Keine vollständige Screenreader-/Zoomabnahme.
- Bestehende Fachtests und Einstellungen-/PDF-Prüfungen bleiben Teil des abschließenden Prüflaufs. Ergebnisse werden nicht als vollständige Vereinsverwaltungs- oder WISO-Abnahme verstanden.

Die sichtbaren Beispiele heißen ausdrücklich „Testdaten“. Zusätzliche Beispiele sind Lesepult/Sachspende, Kabelbinder unter Mindestbestand und Handkasse. Der Kontostand bleibt 2.654,50 € auf dem Offlinekonto und 171,35 € in der Barkasse, zusammen 2.825,85 €. Die neue Inventarwertsumme beträgt 600,00 €; sie wird diesem Geldbestand nicht zugeschlagen.

## Datenmodell und Wiederherstellung

Migrationen 011/012 ergänzen Inventar, Lagerorte, Mengenstände, Ausleihpositionen, Journal, private Dateien und eigene Rechte. Beide wurden vor dem Anschluss der neuen Ansichten auf die lokale Datenbank angewendet. `bin/migrate.php --synthetic` sichert eine ältere Datenbank vor der Migration über `VACUUM INTO`; aktuelles Schema ist 12.

Alle Mengenänderungen erfolgen unter `BEGIN IMMEDIATE`. Vorgangskennungen verhindern doppelte Speicherung; Versionsprüfungen verhindern unbemerkte Überschreibung. Der Bestand wird vollständig aus den Journal-Deltas nachvollziehbar. Bestände und Ausleihen verwenden dieselben Gegenstands-, Orts- und Profil-IDs. Aussonderung erhält die Historie.


## Weiterhin fehlend im Gesamtprojekt

Inventar ist jetzt nutzbar. Noch offen sind insbesondere die allgemeine Dokumentenablage, freie Tabellen/Aufgaben, Sendungen/Chat, Mitglieder-Abteilungszuordnungen und Zusatzfeldwerte, automatische Beitragsläufe, fertige Rechnungen/E-Rechnungen, Zuwendungsbescheinigungen, DATEV/weitere Steuerautomatik, Live-Banking, produktive Anmeldung/MFA und Veröffentlichung. Die Banking-Entscheidung bleibt **nemiah/php-fints**.

Herstellerdienste und fremde Vereine gehören nicht zum lokalen Funktionsumfang.
