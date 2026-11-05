# Lokale Softwareabnahme ohne GitHub-Quote

Die GitHub-Workflows werden ausschließlich manuell gestartet. Die lokale
Prüfstrecke benötigt weder GitHub-CLI noch Actions und funktioniert unabhängig
von einem gehosteten Prüfkontingent. Für Änderungen zuerst passende Gruppen
verwenden; vollständige Abnahmen besitzen einen eigenen Lauf und Ergebnisordner.

## Vorhandene Umgebung weiterverwenden

```sh
bash tools/cloud/resume-existing.sh
```

Der Helfer prüft und übernimmt die vorhandenen vorbereiteten Datenbank- und
Anwendungsdienste. Er befüllt keine Bestände neu und ersetzt keine Schlüssel.
Unbekannte belegte Ports werden nicht übernommen. Für eine wirklich neue Umgebung
gilt die [Einrichtung aus Git](CLOUD-NEUSTART-AUS-GIT.md). Die lokale Prüfstrecke
benötigt Linux/POSIX, Git, Python 3.11+, Bash sowie die dort vorbereiteten
PHP-/MySQL-/Node-/Chromium- und benötigten Dokument-/Medienlaufzeiten.

## Fortsetzbarer vollständiger Prüflauf

Zuerst neue Quellen und Dokumentation prüfen, das Inventar erneuern und den Stand
committen. Der Lauf verlangt einen unveränderten Checkout:

```sh
python3 distribution/build-source-manifest.py
python3 tools/ci/local-release.py --list
python3 tools/ci/local-release.py --artifacts /ABS/NEUER-PRUEFLAUF
```

Der Ergebnisordner muss außerhalb des Checkouts liegen. Ohne `--phase` werden alle
registrierten Phasen ausgewählt. Die Reihenfolge ist festgelegt:

| Phase | Umfang |
| --- | --- |
| `preflight` | Browserregistrierung, Auswahlverträge, Fortsetzungs-/Abbruchschutz, Quellinventar, PHP-Syntax, Laufzeitwächter sowie erzeugte App-Sprachressourcen. |
| `core` | Alle nativen Fachgruppen außer den sechs abschließenden Installations-/Wiederherstellungsgruppen. |
| `components` | Vier eigenständige echte Bild-, Fotoimport- und Editorbrowser sowie die native JavaScript-Komponente mit ausdrücklich simuliertem Gerätetransport. |
| `calendar` | Acht Kalender-/Termin-/Dokumentgruppen in eigenen Datenbanken. |
| `browsers` | Alle registrierten Fachbrowser einschließlich ihrer getrennten Aufrufvarianten. |
| `package` | Inventar, Paketverträge, ERiC-Materialisierung und vollständige Auslieferungsbytes. |
| `installation` | Die sechs Installations-/Wiederherstellungsgruppen sowie Upgrade- und Webspace-Installerbrowser. |

Die Namen werden aus den bestehenden Registern gelesen. Eine neue native
Browsergruppe ohne Eintrag im vollständigen Aufrufplan bricht die Planung ab.
Die tatsächlich registrierten Gruppen und Schrittzahlen stehen im ausgegebenen
Plan. Zahlen oder Ergebnisse älterer Commits gelten nicht als aktueller vollständiger
Nachweis. Der abschließende Installerbrowser wird gesondert ausgewählt.

`core` startet keine der sechs abschließenden Installationsgruppen. Sie werden
zur endgültigen Abnahme gesammelt in `installation` ausgeführt. Ein bereits
bestandener Schritt wird bei unveränderter Quelle und gültigem Originalprotokoll
nicht erneut ausgeführt. Geerbte Kurzdiagnosen und überspringende Backup-Flags
werden entfernt; reale Mail-, Website- und Steuertransporte sind gesperrt.

## Unterbrechung und Wiederaufnahme

```sh
python3 tools/ci/local-release.py --artifacts /ABS/PRUEFLAUF --resume
```

Commit, Git-Baum, Phasenauswahl und vollständiger Plan müssen exakt übereinstimmen.
Jeder bestandene Schritt benötigt sein unverändertes SHA-256-geprüftes Protokoll.
Bei nativen Gruppen sind zusätzlich die tatsächliche erfolgreiche Suite und ihre
abgeschlossene Bereinigung erforderlich; ein bloßer Prozessstatus null genügt
nicht. Fehlende oder veränderte Nachweise werden erneut geprüft. Die alten
Protokolle bleiben erhalten; jeder Versuch bekommt einen neuen Namen.

Ein noch laufender alter Prüfprozess verhindert einen doppelten Neustart.
Strg+C und Zeitlimit beenden ausschließlich die vom Prüflauf selbst gestartete
Prozessgruppe. Der betroffene Schritt bleibt unvollständig. Ein Neustart verwendet
keine angeblich erfolgreichen Zwischenstände dieses Schritts. Nach einer
Quelländerung einen neuen Ergebnisordner verwenden; alte erfolgreiche Ergebnisse
gehören weiterhin zu ihrem alten Commit.

`state.json` speichert die einzelnen Versuche, `result.json` den aktuellen
Gesamtstand. Ein Lauf mit Fehlern oder offenen Schritten heißt `incomplete` und
liefert einen Fehlerstatus. `passed` setzt alle ausgewählten Schritte voraus.
Bei einer eingeschränkten Phasenauswahl steht `all_phases_selected` auf `false`.

## Gezielte Prüfung während der Umsetzung

```sh
python3 tools/ci/local-release.py --phase preflight --phase components --artifacts /ABS/NEUE-TEILPRUEFUNG
bash tools/ci/local-native.sh --core native-member-excel native-social-month
bash tools/ci/local-native.sh --calendar
```

Phasen lassen sich mehrfach mit `--phase` wählen. `--keep-going` prüft nach einem
fehlgeschlagenen Schritt weitere unabhängige Schritte; der Gesamtstatus bleibt
unvollständig. Temporäre Testbestände und Browserbilder liegen im privaten Ergebnisordner.
Chromiums kurzlebige Systemdateien erhalten einen eigenen kurzen Pfad, damit
auch lange Ergebnisordner keine Unix-Socket-Grenze überschreiten. Die
eigenen synthetischen PDO-Verbindungen und die Erstellung/Löschung der Testschemata
schreiben keine wiederholten Prüfdaten in die MySQL-Binärprotokolle. Diese
Einstellung gilt nur für die jeweilige isolierte Testverbindung; Produktions-
verbindungen und der globale Datenbankserver werden nicht umkonfiguriert.

Das Zeitlimit gilt je Schritt und ist mit `--timeout SEKUNDEN`
wählbar. Einzelne `local-native.sh --core`-Aufrufe bleiben für kurze Diagnosen
verfügbar; solche Teilergebnisse sind kein vollständiger Gesamtlauf.

## Aussagegrenze

Der Prüflauf bewertet die lokale Software und ihre synthetischen Verträge. Er
ändert keine Anbieterkennungen, verarbeitet keine echten Zahlungen und erteilt
keine steuerliche oder physische Gerätefreigabe. Die tatsächlich nachgewiesenen
iPhone-/iPad-Xcode-Ergebnisse bleiben in der [App-Dokumentation](BEDIENUNG.md)
erhalten. Android-Gerätebedienung benötigt eine arbeitsfähige eigene Laufzeit;
fehlendes KVM oder eine abgestürzte System-WebView ist kein bestandener Test.

Die qualifizierten Originalfälle für 72500/72550 und das steuerliche
Teilmonatsverfahren müssen gesondert nachgewiesen bleiben. Ein lokaler grüner
Prüflauf entfernt diese Sperren nicht.

## Zu wenig freier Speicher

Vor jedem neuen Prüfschritt müssen auf dem Quell-Dateisystem mindestens 128 MiB
und am Nachweisverzeichnis mindestens 512 MiB frei sein. Andernfalls hält der
Runner auch bei `--keep-going` an, setzt `environment_blocked` im Ergebnis und
lässt den nächsten Schritt ausstehend. Bereits vollständige Nachweise bleiben
erhalten. Nach kontrollierter Entlastung denselben Aufruf mit `--resume` starten.
Die Mindestwerte sind eine Startprüfung; große Container-/Buildkopien können
zusätzlich Platz benötigen. Bei Docker mit VFS-Treiber wird für jeden neuen
Container ein vollständiges Basisimage kopiert; die angezeigte kleine
Schreibschicht ist dafür keine ausreichende Platzabschätzung. Vorhandene Dienste,
SDKs, Geräte, Originaldaten und Schlüssel nicht zur Entlastung löschen.
