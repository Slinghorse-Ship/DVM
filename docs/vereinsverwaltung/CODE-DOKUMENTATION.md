# DVM – Code und Betrieb

Stand: 1. November 2026. Die Anwendung verbindet Website, persönliche Konten
und Vereinsverwaltung. MySQL ist die gemeinsame Datenbank. Der öffentliche
Webroot enthält ausschließlich die vorgesehenen HTTP-Einstiege und Assets;
Anwendung, Konfiguration, Schlüssel und Originaldateien bleiben privat.

## Zuständigkeiten

| Pfad | Aufgabe |
| --- | --- |
| `vereinsverwaltung/native/` | Anmeldung, Website, Kommunikation, Medien und Systemverwaltung |
| `vereinsverwaltung/src/` | Mitglieder, Finanzen, Kalender, Dokumente und Inventar |
| `vereinsverwaltung/native/Integration/` | Verbindung der nativen Konten und Oberflächen mit den Fachdiensten |
| `vereinsverwaltung/views/` | Fachansichten und gemeinsame Hülle |
| `vereinsverwaltung/native/Web/` | Native Oberflächen und lokale Assets |
| `vereinsverwaltung/bin/` | Private CLI-Einstiege für Hintergrundaufgaben |
| `vereinsverwaltung/tests/` | Synthetische Fach-, SQL-, HTTP- und Browserprüfungen |
| `distribution/` | Geprüfter Offlinebestand, Installer und Laufzeitpakete |

Die [Projektstruktur](../PROJEKTSTRUKTUR.md) und
[Architektur](../ARCHITECTURE.md) beschreiben die aktuellen Modulgrenzen.
Interne bestehende Kennungen sind keine zusätzliche Laufzeitabhängigkeit und
werden durch eine Dokumentationsbereinigung nicht umgeschrieben.

## Identität und Originalschutz

Native persönliche Konten werden an den eigenen Vereinsakteur gebunden.
Jeder Fachdienst prüft aktuelle Rechte und die konkrete Objektzuordnung.
Ein sichtbarer Menüpunkt oder eine Administrationsrolle ersetzt keine
Finanzberechtigung. Geschützte Downloads prüfen ihre Rechte erneut.

Schreibvorgänge erhalten ursprünglichen Verein, Objekt, Fassung und
Vorgangskennung. Veraltete Formulare dürfen inzwischen geänderte Originale
nicht ersetzen. Transaktion, Revision und Audit gehören zusammen. Nach
unbestätigtem Speichern wird das Ergebnis geprüft, bevor ein Auftrag erneut
bewusst freigegeben wird. Finanzoriginale und frühere Ausgaben bleiben erhalten.

## Installation und automatische Aufgaben

CLI- und Browser-Installer initialisieren ausschließlich eine neue eigene
leere MySQL-Datenbank. Der Browser prüft die Paketdateien schrittweise und
verwendet HTTPS, Einrichtungsschlüssel und CSRF. Nach Abschluss ist der
Assistent gesperrt. Normale Anfragen führen keine Schemaänderungen aus.
[Webspace-Installation](../WEBSPACE-INSTALLATION.md),
[Paketupgrade](../PAKET-UPGRADE.md),
[Sicherung und Wiederherstellung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

Der Installer liefert getrennte Zeitmuster und kopierbare Aufrufe für cPanel
und Plesk. Die private Hilfe kann sie später erneut erzeugen. Der Hoster führt
die Aufgaben auch bei geschlossenem Browser aus. Versand, Veröffentlichung,
Bankabruf, Systemaufgaben und Statusprüfung bleiben getrennte Modi mit ihren
eigenen Freigaben. [Hosting-Zeitpläne](../HOSTING-ZEITPLAENE.md).

## Prüfen und weiterarbeiten

```sh
bash tools/cloud/bootstrap.sh --check
bash tools/ci/local-native.sh --core native-hosting-jobs native-original-jobs
python3 distribution/build-source-manifest.py --check
```

Der lokale Runner verwendet eigene synthetische Datenbanken und verändert
keine vorhandenen Vereinsdatenbanken. Betroffene Fachwege direkt prüfen;
Installer-, Upgrade- und Wiederherstellungsrunden bei Änderungen an diesen
Wegen oder zur Abschlussabnahme ausführen. Während dieser Prüfungen bleiben
Quellen und Inventar unverändert. Echte Zustellung, Zahlung, Veröffentlichung
und Steuerübertragung sind keine beiläufigen Entwicklungstests.

Die Bedienhilfe wird mit ihren Quellen unter
`docs/vereinsverwaltung/handbuch/` und den erzeugten Ressourcen gepflegt.
Lizenztexte und notwendige Quellen der ausgelieferten Bibliotheken bleiben im
Paket; [Lizenzinventar](../native-dependency-inventory.md).
