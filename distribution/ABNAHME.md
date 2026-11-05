# Abnahme der nativen Auslieferung

Der aktuelle Installer verwendet das eigene CMS, native persönliche Konten und
eine leere MySQL-Datenbank. Ein Altimport ist nicht beauftragt. Bibliotheken und Laufzeitpakete bleiben offline verfügbar.

Die reproduzierbaren synthetischen Prüfungen umfassen:

```sh
python3 distribution/build-source-manifest.py --check
python3 distribution/test-source-manifest.py
python3 distribution/test-package-store.py
php distribution/install.php --verify
KH_NATIVE_CI=1 KH_NATIVE_CI_PASSWORD=synthetic-ci-only php vereinsverwaltung/tests/ci-native.php native-installer native-webspace-installer native-installer-integrations native-recovery
```

Der native Runner benötigt den isolierten MySQL-Testdienst auf localhost:13371.
`DVM_INSTALLER_BROWSER=1` aktiviert die tatsächliche Browserinstallation.
`DVM_INSTALLER_OCR_PROOF=1` aktiviert den synthetischen OCR-Nachweis der
installierten Laufzeit. Der Cloud-Helfer richtet diese Abhängigkeiten ein;
[Neustart und Prüfungen](../docs/CLOUD-NEUSTART-AUS-GIT.md).
Die Tests legen ausschließlich eigene Testdatenbanken und private temporäre
Installationsziele an. Sie weisen Neuinstallation, Wiederaufnahme, native
Anmeldung, Webspace-Zugriff ohne Shellfunktionen und verschlüsselte Recovery nach.

Offline-Inventar, Paketbytes, Installation, Aktualisierung und Wiederherstellung
werden am auszuliefernden Quellstand geprüft. Den
[Webspace-Weg](../docs/WEBSPACE-INSTALLATION.md) und die dortigen Hostinggrenzen beachten.

## Zusatzlaufzeiten

```sh
python3 distribution/tests/runtime-tools.py
python3 vereinsverwaltung/tests/materialize-eric-sdk-test.py
```

`DVM_PHP` wählt für den ersten Test bei Bedarf den geprüften PHP-CLI-Pfad.
Die Tests prüfen private Zielpfade, Verknüpfungen, Überschreibschutz, Paketbindung
und Konfiguration ohne Programmausführung. Eine echte Laufzeitabnahme ergänzt
den Vorbereitungsbefehl mit gewählten Komponenten, vollständiger Dateihashprüfung
und tatsächlichen lokalen Programmaufrufen. KoSIT braucht eine synthetische
positive Rechnung und eine gezielt ungültige Gegenprobe; ein Java-Versionsaufruf
allein genügt nicht. ERiC-Bibliotheksstart, Formularprüfung und Übermittlung
bleiben getrennte Nachweise. OCR muss die Sandboxanforderungen tatsächlich
erfüllen; ein gesperrter Landlock-Aufruf wird nicht durch ungeschützte Ausführung
ersetzt. [Einrichtungsweg](../docs/ZUSATZLAUFZEITEN.md).
