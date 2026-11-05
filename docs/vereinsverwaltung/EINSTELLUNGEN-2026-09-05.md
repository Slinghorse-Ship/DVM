> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Einstellungen, Briefpapier und Textbausteine

Stand: 5. September 2026. Lokale Umsetzung für Kulturhus Jaderberg e. V.; kein Deployment auf kulturhus.de.

## Vergleich mit MeinVerein

Die acht Einstellungsmenüs des angemeldeten Demo-Vereins 126490 wurden gelesen: [Vereinsdaten](https://web.meinverein.de/club/126490/settings/club-data), [Account](https://web.meinverein.de/club/126490/settings/account), [Rollen & Rechte](https://web.meinverein.de/club/126490/settings/roles-permissions), [Nummernkreis](https://web.meinverein.de/club/126490/settings/numeration), [Beiträge & Spenden](https://web.meinverein.de/club/126490/settings/club-contribution-rates), [Leistungen](https://web.meinverein.de/club/126490/settings/club-services), [Textbausteine](https://web.meinverein.de/club/126490/settings/text-templates) und [Briefpapier](https://web.meinverein.de/club/126490/settings/invoice-layout).

Zusätzlich geöffnet: Kopfzeileneditor, Beitragsdetails, neue Leistung, neuer Rollenentwurf, alle drei Textgruppen und Variablenmenü. In MeinVerein wurde nichts gespeichert. Mehrere Vereinsdaten-/Nummernkreisfelder waren in der Demo gesperrt; Struktur und angezeigte Werte waren lesbar. Es wurden keine Zugangsdaten oder Kontaktdaten aus dem Fremdsystem in die Testdaten übernommen.

## Lokal nutzbar

| Bereich | Umsetzung |
|---|---|
| Vereinsdaten | Name, Vereinsart, Kategorie, Register, Anschrift, Kontakt, Links und Sichtbarkeitsvorgaben; Briefanreden und Titel; Vereinsbankverbindung mit IBAN-Prüfung; Finanzamt, Bescheiddaten, Förderzweck, Steuer-/DATEV-Parameter; Absender-/SMTP-Vorbereitung; Abteilungs- und Zusatzfelddefinitionen. |
| Account | Persönlicher Anzeigename und Antwortadresse, Startansicht und Seitengröße für Kontakte. Einstellungen gehören dem jeweiligen lokalen Testzugang. |
| Rollen & Rechte | Eigene Rollen und Zuweisung an vorhandene Testzugänge. Mitglieder/Organisation und Finanzen jeweils sperren, lesen oder bearbeiten; gesonderte Berechtigung für Vereinseinstellungen. Änderungen gelten bei der nächsten Dienstoperation. Der letzte Einstellungsverwalter kann nicht entfernt werden. Redaktion bleibt unabhängig von Rollenzuweisungen gesperrt. |
| Nummernkreis | Einnahmen/Rechnungen, Ausgaben und Mitgliedschaften mit Präfix, Jahr/Jahr-Monat, Stellenzahl, Suffix und nächstem Wert. Tatsächliche atomare Nummernvergabe; unveränderte Altbelege, keine Rücksetzung und keine doppelte Belegnummer. Mitgliedsnummer automatisch, wenn beim Anlegen/bei Antragsannahme leer. Zähler laufen über den Jahreswechsel weiter. |
| Beiträge & Spenden | Beitrag/Spende anlegen, ändern, deaktivieren und suchen; feste Beträge oder Alters-/Dauerstaffeln, fünf Intervalle, Abrechnungszeitraum, Sphäre, Steuerkonto, MwSt., Tags und Verwendungszweck. Einzelzuweisungen mit Zeiträumen und Überschneidungsprüfung. Feste aktive Tarife können einen neuen Beleg vorbelegen. |
| Leistungen | Katalog mit Preis, Beschreibung, MwSt., Sphäre, Steuerkonto, Tags und Intervall; Aktivierung, zeitliche Zuweisungen und Vorbelegung neuer Belege. |
| Textbausteine | Acht Vorlagen: Rechnung, Erinnerung, Lastschriftvorankündigung jeweils für Beiträge und Rechnungen; Spenden und Sammelspenden. Betreff, Nachricht, Einleitung/Schluss, 28 geprüfte Variablen, Fett/Kursiv/Unterstrichen, Standardwiederherstellung, Musterauflösung sowie PDF-Vorschau. |
| Briefpapier | Ein Logo und Kopfzeilentext mit Position, Schriftgröße/-familie, Farbe und Formatierung; Absender aus Vereinsdaten übernehmbar; optionale Kontakt-/Lieferdatumfelder; drei formatierte Fußspalten; private Unterschriftsablage; skalierte A4-Livevorschau und PDF-Download. |

Die erste Einrichtung erkennt gespeicherte Tarife, Zuweisungen, Textbausteine, Unterschrift, Nummernkreise und Leistungen. Definitionsformulare für zusätzliche Felder/Abteilungen werden noch nicht als vollständig erledigter Profilablauf gewertet.

## Bewusst erkennbare Grenzen

- WISO-Kauf, Tarifwechsel, Lizenzabrechnung und Herstellerkonto sind keine Kulturhus-Dienste. Eine Vereinslöschung wurde nicht eingebaut.
- Produktive Benutzerregistrierung, Einladung, Passwortverwaltung und MFA fehlen. Die lokale Testrollenauswahl bleibt ausdrücklich eine Entwicklungsfunktion.
- Rechte sind für vorhandene Fachbereiche wirksam. Eine getrennte Löschstufe, einzelne Funktions-, Listen-, Datei-, Feld-, Abteilungs- und Kontorechte sowie die WISO-Hauptverwalterübergabe fehlen.
- Abteilungen und zusätzliche Felder können definiert werden; Zuordnung, Profilwerte und zusätzliche Listenspalten sind noch nicht angebunden. Kontakt-Sichtbarkeitsvorgaben werden erst bei Umsetzung des öffentlichen Vereinsprofils angewendet.
- SMTP-Server, Port, Benutzer, Absender und Antwortadresse sind vorbereitbar. Passwortablage, Verbindungstest, Warteschlange und Versand fehlen. Anhangsschalter merken eine spätere Versandvorgabe vor; es werden keine automatischen Nachrichten erzeugt.
- Tarifstaffeln sind validiert und ihre Grenzberechnung ist getestet. Profilbezogene Alters-/Mitgliedsjahresermittlung, Familienzahler, individuelle Rabatte, anteilige Abrechnung und Massen-/Beitragsläufe fehlen. Ein Tarif erzeugt beim Speichern keine Forderung und keinen Geldfluss.
- Steuerdaten sind gespeichert, lösen aber noch keine Steuerautomatik aus. Das gilt auch für den Kleinunternehmer- und Bescheinigungsverfahrensschalter. Ausgefüllte amtliche Zuwendungsbescheinigungen, rechtsverbindliche Rechnungen, E-Rechnungen und ihre Archivierung fehlen.
- Das Briefpapier unterstützt ein Logo und einen Textblock. Freies Ziehen mehrerer Elemente, beliebige Schriftarten und PDF-Hintergrundimport fehlen. Die Layoutvorschau verwendet einen Musterempfänger und feste Beispielpositionen. Sie ist als **ENTWURF** gekennzeichnet. Die gespeicherte Unterschrift wird nicht automatisch auf Entwürfe gesetzt.
- Die Banking-Entscheidung bleibt `nemiah/php-fints`. Die Vereins-IBAN im Briefpapier richtet keine Bankverbindung im technischen Sinn ein.

## Speicherung und Betrieb

Schema 10 ergänzt versionierte Einstellungsbereiche, vollständige Änderungsfassungen, private Bilddaten, Nummernzähler/-reservierungen, Kataloge/Zuweisungen sowie eigene Rollen. Belege erhalten eine dauerhaft gespeicherte Anzeigenummer; bestehende KH-Nummern bleiben erhalten. Finanzbeträge und Kontotransaktionen wurden nicht umgedeutet.

Änderung und Audit werden in einer Dateidatenbank-Transaktion gespeichert. Versionskonflikte, fremde Vereinsobjekte und unberechtigte Schreibzugriffe werden serverseitig abgewiesen. PNG/JPEG-Dateien liegen außerhalb des Webroots in der privaten Datenbank und sind nur über einen geprüften Zugriff abrufbar. Frühere Bildfassungen bleiben für die Historie erhalten. SVG ist nicht als Upload erlaubt.

PDF-Vorschauen werden über einen begrenzten Python-Unterprozess mit ReportLab 4.4.9 erstellt: JSON über stdin, PDF über eine zufällig benannte private Arbeitsdatei. Keine übergebenen Shell-Befehle, Dateipfade oder Netzwerkadressen. Die Arbeitsdateien werden nach dem Abruf entfernt. Kopf-/Fußüberlauf wird abgewiesen, lange Dokumenttexte laufen über mehrere Seiten mit wiederholtem Briefpapier. Die Laufzeit wird über `VEREIN_PYTHON`, `start-local.ps1 -Python` oder die ignorierte lokale Datei `.local/vereinsverwaltung/pdf-runtime.json` konfiguriert. Produktionsbetrieb des PDF-Helfers ist noch abzustimmen.

Während der Umstellung war die lokale Seite kurz nicht aufrufbar, weil der neue Rechtezugriff bereits Schema-10-Tabellen erwartete. `bin/migrate.php --synthetic` hat eine Sicherung erstellt und die Migration angewendet. Dashboard und sämtliche Einstellungsseiten wurden danach erneut erfolgreich aufgerufen. Der reguläre Start migriert weiterhin vor dem Serverstart.

## Prüfung

- 489 PHP-Prüfungen: bisherige 402 Prüfungen plus 87 für Einstellungen, Rechte, Nummern, Tarife, Zuweisungen, Bilder und Rollback.
- 17 PDF-Prüfungen: A4, Text/Eurozeichen, Bildintegration, drei Seiten mit wiederholtem Briefpapier, Kopf-/Fußüberlauf und getrenntes Spendenbegleitschreiben.
- 27 lokale HTTP-Prüfungen: alle Einstellungsseiten und Untergruppen, neue Formulare, PDF-Ausgabe über den Webcontroller, Downloadheader, CSRF und gesperrter Mitgliederzugriff.
- Browser: Kopfzeile geändert und gespeichert; fester synthetischer Beitrag mit Sphäre 1/Konto 40000 gespeichert und erfolgreich als Beleg vorbelegt, ohne einen neuen Beleg zu buchen; Spenden-Textvorschau mit aufgelösten Variablen; Briefpapier und Textbausteine bei tatsächlich gemessenen 360 Pixeln ohne horizontalen Seitenüberlauf. PDF gerendert und visuell kontrolliert.
- Neue Daten in der lokalen Demo: Briefpapier mit zwei Kopfzeilen sowie „Mitgliedsbeitrag · Testtarif“ über 120 EUR. Keine Änderung der Kontostände durch diese Einstellungstests.

Ein vollständiger Hersteller-Paritäts-, Steuer- oder Produktionsnachweis wird damit nicht behauptet.
