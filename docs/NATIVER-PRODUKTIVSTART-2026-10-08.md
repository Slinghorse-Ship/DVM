# Native Anwendung starten

Die aktuelle Installation verwendet die native Anmeldung, persönliche Konten
und die eigenen Vereinsrechte. Produktionszugriff erfordert die eingerichtete
HTTPS-/Hostbindung und die private Konfiguration. Fehlende oder unzulässige
Konfiguration bleibt gesperrt.

Für eine neue Installation gilt die [Webspace-Anleitung](WEBSPACE-INSTALLATION.md).
Bestehende Installationen verwenden den [Paketupgrade-Weg](PAKET-UPGRADE.md).
Die [Hosting-Zeitpläne](HOSTING-ZEITPLAENE.md) erklären automatische Aufgaben
auf cPanel/Plesk und deren tatsächlichen Laufnachweis.

In einer bereits eingerichteten Entwicklungsumgebung setzt
`bash tools/cloud/resume-existing.sh` die vorhandene Anwendung fort.
Dieser Weg initialisiert keine Datenbank und erzeugt keine neuen Schlüssel.
[Cloud-Neustart](CLOUD-NEUSTART-AUS-GIT.md).

Ein lokaler Anmeldungstest bestätigt keine externe Anbieteranbindung oder
produktive Zustellung.
