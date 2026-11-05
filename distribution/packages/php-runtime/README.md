# Offline-PHP-Abhängigkeiten für DVM

Vier Komponenten: die unveränderten versionsgebundenen Bibliotheken für
HTTP/Mail/Umgebungsdateien, FinTS und Web Push sowie Composer 2.10.3 als
Entwicklungswerkzeug. Eim früheren CMS-Core oder ein leeres fremdes CMS-Profil
wird nicht mehr ausgeliefert. Die eigenen Klassen laden über den DVM-Bootstrap;
der root-vendor-Autoload enthält keine Pfade in den alten Websitebaum.

`manifest.json` bindet Archive, sortierte Datei-/Modus-/Byte-/Hashlisten,
Composer-Locks, Plattformvorgaben und Lizenzen. `LICENSES` erhält alle erforderlichen
Bibliotheks- und Composer-Lizenztexte. Die Kundeninstallation benötigt weder
Composer-Aufruf noch Netzwerkzugriff. Die Archive enthalten keine Konfiguration,
Konten, Schlüssel oder Vereinsdaten.

Der Entwicklerhelfer `distribution/tools/build-php-packages.py` erstellt
reproduzierbare Archive aus separat geprüften `root-project`, `banking-project`
und `push-project` samt installiertem vendor-Bestand, den jeweiligen Locks,
Composer und dessen Lizenz. Vorhandene Pakete müssen den gesperrten Versionen
entsprechen. Originale Archive bzw. Anwendungsdaten werden nicht als Ersatz
für diese Abhängigkeiten verwendet.

Prüfung: `php distribution/install.php --verify` sowie die nativen Installer-,
Webspace- und Recovery-Suiten. `distribution/tests/php-package-smoke.php` prüft
jeden frisch ausgepackten Bibliotheksbestand ohne Versand oder andere Transporte.
