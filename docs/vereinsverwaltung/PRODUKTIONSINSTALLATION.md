# Native Produktionsinstallation


Für PHP-Webspace den vorbereiteten Upload und HTTPS-Assistenten aus
[WEBSPACE-INSTALLATION](../WEBSPACE-INSTALLATION.md) verwenden.
Für die private CLI ist `distribution/install-native.php --config-stdin`
der vollständige aktuelle Weg. JSON ausschließlich über stdin übergeben:
`target`, `dsn`, `username`, `password`, `club_id`, `club_name`, `hosts`,
`admin_email`, `admin_password`. Optional wählt `scheduler_php` den PHP-CLI-Pfad
für die nach Abschluss angezeigte Hostinganleitung. Mit `--resume` denselben begonnenen
Installationsvorgang fortsetzen. Den Domain-Webroot auf `<target>/web` setzen.
Zugangsdaten, private Originale und Schlüssel bleiben außerhalb Git.


Für bestehende Installationen den gesonderten
[Paketupgrade-Weg](../PAKET-UPGRADE.md) verwenden. Die neue CLI-Installation
speichert dafür ebenfalls ein exaktes installierbares Programminventar.
Nicht die Neuinstallation über eine gefüllte Datenbank starten.

Nach Abschluss die [Hosting-Zeitpläne](../HOSTING-ZEITPLAENE.md) mit den konkreten
Aufrufen für cPanel/Plesk einrichten und tatsächliche erfolgreiche Läufe prüfen.
Die private CLI-Hilfe kann diese Anleitung später erneut erzeugen.
