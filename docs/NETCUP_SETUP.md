# DVM auf netcup-Webhosting einrichten

Vor der Einrichtung prüfen, ob der konkrete Tarif PHP ab 8.3, die benötigten
Erweiterungen, MySQL ab8.0.16, HTTPS, privaten Dateispeicher und zeitgesteuerte
PHP-CLI-Aufrufe unterstützt. Der Hostingname allein bestätigt diese Funktionen
nicht. Details stehen in der [Webspace-Anleitung](WEBSPACE-INSTALLATION.md).

1. Eine eigene leere MySQL-Datenbank mit eigenem Benutzer anlegen.
2. Das geprüfte DVM-Upload-Paket per SFTP/FTP hochladen. `app/` und `private/`
   liegen neben dem öffentlichen Domainverzeichnis; nur den Inhalt von `web/`
   öffentlich ausliefern. Private Verzeichnis- und Dateirechte erhalten.
3. HTTPS aktivieren und `https://DEINE-DOMAIN/setup/` öffnen. Einrichtungsschlüssel,
   Vereinsdaten, Datenbankzugang und persönliches Administratorkonto eintragen.
4. Unter **Automatische Aufgaben im Hosting einrichten** den bestätigten
   PHP-CLI-Pfad des Tarifs eintragen. Die Website- und CLI-Version getrennt prüfen.
5. Die Einrichtung abschließen und die angezeigte Hostinganleitung herunterladen.
   Anschließend `setup/` und die lokale `SETUP-KEY.txt` entfernen.
6. Im Webhosting-Control-Panel die benötigten geplanten Aufgaben mit den
   generierten Aufrufen und Zeitmustern eintragen. Mail, Veröffentlichung, Bank,
   Postfach, System und Status bleiben getrennt wählbar.
7. Vereinsmail, Anbieter und Verantwortliche im Manager beziehungsweise in der
   privaten Worker-Konfiguration einrichten. Einen bewussten Testlauf und den
   folgenden regulären Lauf bei geschlossenem Manager beobachten.
8. Datensicherung und Aufbewahrung einrichten und eine getrennte Wiederherstellung
   prüfen. Bei bestehenden Installationen den Paketupgrade-Assistenten verwenden.

Die [Hosting-Zeitpläne](HOSTING-ZEITPLAENE.md) beschreiben die Aufrufe, cPanel/Plesk,
PHP-Pfade und tatsächlichen Laufnachweise. Ein aktives Symbol im Hosting oder ein
aktivierter Fachplan im Manager bestätigt allein keinen erfolgreichen Lauf.
