# DVM – DeinVereinsManager: Anwendung

Der Vereinsmanager verwendet MySQL und verbindet bestehende Fachmodule mit
nativer Anmeldung, Website, Kommunikation und dem gemeinsamen Kalender.
Vereinsdaten sind konfigurierbar; Kulturhus ist keine feste Produktvorgabe.

- [Entwicklung und Einrichtung aus Git](../docs/CLOUD-NEUSTART-AUS-GIT.md)
- [PHP-Webspace mit MySQL einrichten](../docs/WEBSPACE-INSTALLATION.md)
- [Fachmodule](src/README.md), [Oberflächen](views/README.md), [Projektstruktur](../docs/PROJEKTSTRUKTUR.md)
- [Handbuch](../docs/vereinsverwaltung/HANDBUCH.md)

`native/` enthält die eigene modulare Anwendung, `src/` die bestehenden
Vereinsdienste. `native/Integration/` bindet ihre ursprünglichen Daten und Rechte
an den gemeinsamen HTTP-Controller. Tests und ausdrücklich synthetische Seeds
bleiben Entwicklungswerkzeuge; das Upload-Paket enthält keine Testbestände.

Neue Einrichtung verwendet `tools/cloud/bootstrap.sh` oder das vorbereitete
Webspace-Paket. Die Hosting-Zeitpläne für automatische Aufgaben beschreibt die
[Hostinganleitung](../docs/HOSTING-ZEITPLAENE.md).
