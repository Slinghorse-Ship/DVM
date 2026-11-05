# DVM: Architektur

DVM ist ein Vereinsmanager mit integrierter Website. Produktname, Vereinsdaten,
Domain und Konten sind getrennt. MySQL speichert die gemeinsamen Fachoriginale;
private Schlüssel und Dateien liegen außerhalb des öffentlichen Webroots.

## Anwendung und Daten

`vereinsverwaltung/native/` enthält Anmeldung, Website, Kommunikation, Medien und
Systemverwaltung. `vereinsverwaltung/src/` enthält Mitglieder-, Finanz-, Kalender-,
Dokumenten- und Inventardienste. `native/Integration/ManagerBridge` verbindet die
Konten mit den vorhandenen Vereinsakteuren und ihren aktuellen Rechten.

| Verzeichnis | Aufgabe |
|---|---|
| `vereinsverwaltung/native/` | Anmeldung, CMS, Kommunikation, Anbieterintegration und gemeinsame Managerhülle |
| `vereinsverwaltung/src/` | Fachmodule für Mitglieder, Finanzen, Dokumente, Inventar und Termine |
| `vereinsverwaltung/views/`, `public/` | Ansichten und lokale Browserressourcen |
| `vereinsverwaltung/bin/`, `tools/` | Private Worker sowie Dokument-, Steuer- und Medienwerkzeuge |
| `vereinsverwaltung/resources/` | Vorlagen, Kataloge, Schriften und lizenzierte Laufzeitressourcen |
| `banking/` | FinTS-Adapter und dessen gesperrte Abhängigkeiten |
| `distribution/` | Installer, Upgrade, Wiederherstellung, Paketmanifeste und Offline-Pakete |
| `docker/dvm/` | Docker-Build und Compose für die eigene Installation |
| `native-app/` | Native Mobilanwendungen und Gerätebrücken |
| `tools/ci/`, `vereinsverwaltung/tests/` | Lokale Software-, Browser- und Paketprüfungen |

Native Konten, persönliche Rollen, MFA, Einladungen und Passwort-Reset verwenden
keine zweite Verwaltungsoberfläche. Originalbelege, verschlüsselte Bankdaten,
Einwilligungen und ausgegebene Dokumente behalten ihre ursprünglichen Zuordnungen.
Bestehende interne Kennungen werden bei einer Text- oder Produktbereinigung
nicht umgeschrieben. Neue Installationen erhalten neue eigene Inhalte.

## Oberfläche und Veröffentlichung

`native/Integration/ProductionSite` verbindet Website, Konto und Manager.
Öffentliche Inhalte stammen aus freigegebenen Fassungen. Bearbeitung, Freigabe,
zeitliche Planung und tatsächliche Veröffentlichung bleiben getrennte Schritte.
Der Browser lädt Bootstrap6 sowie die benötigten eigenen Skripte und Dateien
von der eigenen Installation. Aktive PHP-, Bild-, PDF-, Video-, Bank- und
Anbieterbibliotheken stehen mit ihren Lizenznachweisen im geprüften Paket.

## Private Hintergrundaufgaben

`native/Integration/Jobs` verarbeitet vorhandene Warteschlangen mit Original-,
Versions- und Rechtebindung. Mail, Website, Social, Bank, Postfach, Presse und
weitere Modi haben getrennte private Aufrufe. Systemaufgaben und Verbindungsstatus
besitzen eigene Worker. Ein Seitenaufruf startet keinen Hosting-Zeitplan.

Der Installer erzeugt eine Anleitung mit den konkreten Installationspfaden und
Zeitmustern. cPanel-Cron-Jobs oder Plesk-Aufgaben werden anschließend beim Hoster
eingetragen und anhand echter erfolgreicher Läufe geprüft.
[Hosting-Zeitpläne](HOSTING-ZEITPLAENE.md),
[Fachjobs und Freigaben](HOSTING-ZEITPLAENE.md).

## Installation und Betrieb

CLI- und Browser-Installer teilen dieselbe Initialisierung. Der vorbereitete
Upload enthält die benötigten PHP-Bibliotheken. Der HTTPS-Assistent prüft den
Dateibestand in begrenzten Schritten und verwendet eine leere eigene
MySQL-Datenbank. Einrichtungsschlüssel, CSRF und der abschließende Nachweis
begrenzen den Vorgang; nach Abschluss ist der Assistent gesperrt.

Normale Anfragen führen keine Schemamigration aus. Bestehende Installationen
verwenden den [Paketupgrade-Assistenten](PAKET-UPGRADE.md). Private Schlüssel,
Geschäftsdaten und freigegebene Originale bleiben geschützt.
[Webspace-Installation](WEBSPACE-INSTALLATION.md),
[Sicherung und Wiederherstellung](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

## Erweiterungen und Schnittstellen

Fachregeln, Datenzugriff, Formularaktionen und Darstellung bleiben getrennt.
Die [native API](NATIVE-API.md) nutzt benannte Ressourcen und vorhandene
Fachdienste mit aktuellen Rechten. Eigene Erweiterungen benötigen einen
entsprechend geprüften Adapter; eine API-Anfrage darf keine beliebige PHP-Datei,
SQL-Anweisung oder frei benannte Methode ausführen.

## Prüfgrenzen

Fachmodule besitzen eigene dokumentierte Verträge und Tests. Tatsächliche
Anbieter-/Gerätetests, qualifizierte Finanzsonderfälle und Auslieferungsprüfungen
bleiben gesonderte Nachweise; lokale Softwareprüfungen ersetzen sie nicht.
