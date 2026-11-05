# DVM in der Cloud entwickeln

Diese Anleitung richtet sich an Entwickler. Für eine Vereinsinstallation gelten
[PHP-Webspace](WEBSPACE-INSTALLATION.md) oder [Docker](../docker/dvm/README.md).
Der Quellcode und die ausführbaren Werkzeuge liegen im Standardzweig `main`.

## Voraussetzungen

Die Cloud-Basis stellt Bash, Python3, Docker mit erreichbarem lokalem Daemon,
Node/npm, curl und Git bereit. Paketdownloads benötigen die in der Umgebung
freigegebenen offiziellen Quellen und vorhandene CA-Vertrauensketten.
Netzwerkproxy und eingerichtete Zugangsdaten verwenden; TLS-Prüfungen bleiben an.
Geheimniswerte werden in den Umgebungseinstellungen hinterlegt, nicht in Git.

## Erstmalig vorbereiten

Die vorhandene Cloud-Einrichtung erwartet technisch den Ordner
`/workspace/Kulturhus`; der Repositoryname bleibt DVM. In einer frischen
Arbeitsumgebung DVM in diesen Ordner klonen, keine bestehende Installation
überschreiben:

```sh
git clone https://github.com/Slinghorse-Ship/DVM.git /workspace/Kulturhus
cd /workspace/Kulturhus
bash tools/cloud/bootstrap.sh --setup
bash tools/cloud/bootstrap.sh --check
```

`--setup` richtet die Werkzeuge und eigene synthetische lokale Bestände ein.
Vorhandene eigene Fixtures und Originale werden erhalten. Der Einstieg ist im
[Bootstrap-Skript](../tools/cloud/bootstrap.sh) nachvollziehbar. Kein zusätzlicher
Git-Worktree und keine zweite Verwaltungsoberfläche sind dafür erforderlich.

## Vorhandene Umgebung wieder aufnehmen

```sh
bash tools/cloud/bootstrap.sh --start
bash tools/cloud/bootstrap.sh --check
```

`--start` verwendet die vorhandene Installation, ohne erneute Befüllung,
Schemaarbeit oder neue Schlüssel. Ein Cloud-Snapshot erhält Dateien, aber nicht
zwingend laufende Prozesse. Eine fehlende oder veraltete Werkzeugeinrichtung ist
vor einem gezielten erneuten `--setup` zu diagnostizieren.

Der Laufzeitcheck bestätigt Werkzeuge, lokale Datenbank und erwarteten
Manager-Einstieg. Er ist keine Freigabe realer Anbieteraktionen, Steuerabgaben,
Geräte oder produktiver Hostingkonfigurationen. Keine Loopback-Previewlinks als
öffentlich erreichbare Website anbieten.

## Änderungen prüfen

Für eine betroffene registrierte Kerngruppe:

```sh
bash tools/ci/local-native.sh --core NAME-DER-SUITE
```

Der vollständige registrierte Prüfweg ist `bash tools/ci/local-native.sh --all`
beziehungsweise `bootstrap.sh --verify`. Er wird gezielt vor einer entsprechenden
Abnahme verwendet; für eine begrenzte Änderung zuerst die passenden Gruppen
prüfen. [Prüfwege und Grenzen](LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md).

Nach Quelländerungen neue geprüfte Dateien in Git aufnehmen und das
Paketinventar aktualisieren:

```sh
python3 distribution/build-source-manifest.py
python3 distribution/build-source-manifest.py --check
```

Private Entwicklungsbestände, Betriebszugänge und temporäre Prüfergebnisse bleiben
außerhalb des Git-Baums. Reale Vereinsdaten gehören nicht in synthetische Tests.
Historische Einzelprüfungen ersetzen keinen neuen Lauf für den aktuellen Stand.
