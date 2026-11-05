# Entwicklung am DVM

DVM ist eine gemeinsame PHP-/MySQL-Anwendung. Einstieg und Installation stehen
in [README.md](README.md), Modulgrenzen in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

- Benutzeränderungen erhalten. Vor Änderungen Arbeitsbaum und Git-Zweig prüfen.
- Fachregeln, Datenzugriff, Aktionen und Darstellung getrennt halten. Bestehende
  Rechte, Originalnachweise, Versionsprüfungen und finanzielle Zuordnungen erhalten.
- Zugangsdaten, private Schlüssel, Betriebsdaten und interne Arbeitsprotokolle
  bleiben außerhalb Git. Betriebsbackups richten sich nach dem Betreiberplan.
- Nur synthetische Daten für lokale Prüfungen verwenden. Keine echten Mails,
  Bankaufträge, Veröffentlichungen oder Steuerübermittlungen zu Testzwecken.
- Änderungen einschließlich tatsächlicher Bedienung, Fehlerwege und passender
  Tests abschließen. Prüfumfang und Grenzen konkret benennen.
- Für diese Zusammenarbeit nur `main` auf GitHub verwenden. Keine zusätzlichen
  Remote-Arbeitszweige oder GitHub-Actions-Läufe anlegen.
- Den Git-Verlauf nicht umschreiben. Eine öffentliche Repositoryfreigabe setzt
  eine gesonderte Prüfung historischer Geheimnisse und privater Dateien voraus.
- Nach Quelländerungen `distribution/source-files.json` neu erzeugen und prüfen;
  neue Dateien vorher in den Git-Index aufnehmen. Installer-/Recovery-Prüfungen
  verwenden einen unveränderten Quellstand.

Lokale Einrichtung und gezielte Prüfungen:

```sh
bash tools/cloud/bootstrap.sh --setup
bash tools/ci/local-native.sh --core NAME-DER-SUITE
python3 distribution/build-source-manifest.py --check
```

Weitere Prüfwege: [lokale Prüfungen](docs/LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md).
Ein vollständiger Lauf verwendet die registrierten Browservarianten ohne geerbte
Diagnosekürzungen. Laufende Shell-Prüfskripte während eines Prüflaufs nicht ändern.
