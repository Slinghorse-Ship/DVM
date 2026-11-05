# DVM-Dokumentation

DVM verwaltet einen gemeinnützigen Verein und seine Website. Die
[Projektbeschreibung](../README.md) erklärt Funktionen, Voraussetzungen und Grenzen.

## Installation und Betrieb

| Aufgabe | Anleitung |
|---|---|
| PHP-Webspace einrichten | [Installation mit Browser-Assistent](WEBSPACE-INSTALLATION.md) |
| Docker verwenden | [Image, eigene Installation und Updates](../docker/dvm/README.md) |
| OCR, KoSIT oder ERiC ergänzen | [Zusatzlaufzeiten vorbereiten und verbinden](ZUSATZLAUFZEITEN.md) |
| Eigene Zugänge und Registrierungen | [SMTP, FinTS, ELSTER, Meta und weitere Anbieter](VERBINDUNGEN-UND-ZUGANGSDATEN.md) |
| Hintergrundaufgaben einrichten | [Plesk/cPanel und private Zeitpläne](HOSTING-ZEITPLAENE.md) |
| DVM aktualisieren | [Paketupgrade](PAKET-UPGRADE.md) |
| Daten sichern und wiederherstellen | [Betreiberverfahren](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md) |
| Lizenzbedingungen verstehen | [Gemeinnützige Nutzung und Drittanbieter](LIZENZ.md), [ERiC-Bedingungen](ERIC-LIZENZ.md) |

## Bedienung

Die Hilfe im Vereinsmanager öffnet die passende Aufgabenbeschreibung innerhalb
der Anwendung. Das [Handbuch](../vereinsverwaltung/resources/handbook.html) beschreibt
Mitglieder, Finanzen, Dokumente, Kommunikation, Website und Einstellungen.

| Bereich | Weiterführende Anleitung |
|---|---|
| Website | [Vorlagen und Vorschau](CMS-WEBSITE-VORLAGEN.md), [Inhalte und Startseite](CMS-STARTSEITE.md) |
| Newsletter und Social | [Veranstaltungsentwürfe und Medienwahl](SOCIAL-VERANSTALTUNGSENTWUERFE.md), [Bildquellen](SOCIAL-BILDQUELLEN.md) |
| Gemeinsame Postfächer | [Nachrichten und Mailverläufe](SOCIAL-GESPRAECHSLISTE.md) |
| Vereinstermine | [Kalenderquellen](KALENDER-QUELLEN.md) |
| Jahresabschluss und E-Bilanz | [Unterstützte Umgebungen und Grenzen](EBILANZ-UMGEBUNGEN.md) |

## Technische Referenz

[Architektur](ARCHITECTURE.md), [Projektstruktur](PROJEKTSTRUKTUR.md),
[Cloud-Entwicklungsumgebung](CLOUD-NEUSTART-AUS-GIT.md),
[lokale Prüfungen](LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md) und
[Paketprüfung](../distribution/ABNAHME.md).

Detaillierte Fachverträge und datierte Prüfnachweise bleiben als technische
Referenz erhalten. Ein früherer Einzeltest bestätigt nicht die Einrichtung einer
neuen Installation; dafür gelten die aktuellen Installationsanleitungen und die
auf dem eigenen Hosting ausgeführten Prüfungen.
