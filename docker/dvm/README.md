# DVM mit Docker betreiben

Dieses optionale Paket verwendet PHP 8.4 mit Apache und einen getrennten MySQL-8.4-Dienst. Es installiert dieselbe Anwendung wie das [Webspace-Paket](../../distribution/README.md), keine Demo und keine Vereinsdaten. Docker Engine mit Compose sowie für einen eigenen Build PHP ab 8.3 mit den Installer-Erweiterungen werden vorausgesetzt.

Für den eigenen DVM-Code gelten [LICENSE](../../LICENSE) und die [Lizenzerläuterung](../../docs/LIZENZ.md). Drittanbieter behalten ihre jeweiligen Lizenzen und Rechte; diese Einschränkung gilt nicht pauschal für das Containerimage. Lizenztexte bleiben im Anwendungspaket und in den Systempaketen erhalten. Die Node-Lizenz liegt zusätzlich unter `/usr/share/doc/nodejs/LICENSE`, der verwendete IMAP-Quelltext samt Lizenz unter `/usr/share/doc/php-imap/`.

## Image bauen

Im vollständigen, geprüften Checkout:

```sh
sh docker/dvm/build.sh dvm:local
```

Der Builder prüft das bestehende Quellmanifest und die mitgelieferten Bibliotheken. Nur das verifizierte Programm und öffentliche Einrichtungsdateien gelangen in den Buildkontext. Schlüssel aus einem Upload-Paket werden weder kopiert noch ins Image eingebaut. Alternativ kann ein bereits vorbereitetes Webspace-Paket mit `--bundle /absoluter/pfad/zum/paket` gelesen werden. `DVM_PHP` wählt bei Bedarf den PHP-CLI-Pfad.

Der Build lädt offizielle PHP-/Node-Basisimages, Debian-Pakete und die mit SHA-256 geprüfte IMAP-Erweiterung. Die mitgelieferten pypdf- und ReportLab-Pakete werden mit geprüften Hashes ohne Netzwerk installiert; ReportLab 4.4.9 enthält die von den Dokumentvorlagen benötigten Vera-Schriften. Für reproduzierbare Basisimages können `DVM_PHP_IMAGE` und `DVM_NODE_IMAGE` auf geprüfte Digests gesetzt werden. In einer verwalteten Codex-Umgebung verwendet der Builder die vorhandene Proxykonfiguration und einen nur während des Builds gemounteten CA-Bestand; Zertifikatsprüfung bleibt aktiv.

Ein zum Release geliefertes Imagearchiv lässt sich ohne Containerregistry laden:

```sh
docker load --input kulturhus-dvm-2026.11.05-1862-linux-amd64.tar.gz
export DVM_IMAGE=kulturhus-dvm:2026.11.05-1862
```

Das Archiv enthält die Laufzeit und das Standardpaket ohne das gesonderte OCR-/KoSIT-Zusatzpaket. Sein Dateiumfang unterscheidet sich deshalb vom vollständigen OCR-Webspacepaket.

## Eigene Installation starten

Ein dauerhaftes privates Verzeichnis außerhalb des Repositorys anlegen. Passwörter einmal erzeugen und erhalten:

```sh
umask 077
mkdir -p /ABSOLUTER/PRIVATER/PFAD/dvm-secrets
openssl rand -hex 32 > /ABSOLUTER/PRIVATER/PFAD/dvm-secrets/db-password
openssl rand -hex 32 > /ABSOLUTER/PRIVATER/PFAD/dvm-secrets/db-root-password
export DVM_SECRETS_DIR=/ABSOLUTER/PRIVATER/PFAD/dvm-secrets
export DVM_IMAGE="${DVM_IMAGE:-dvm:local}"
docker compose -f docker/dvm/compose.yaml up -d
docker compose -f docker/dvm/compose.yaml exec app dvm setup-key
```

Diese Passworterzeugung nur vor der ersten Einrichtung durchführen. Die Ausgabe des letzten Befehls ist der neue private Einrichtungsschlüssel dieser Installation. Er erscheint nicht im Containerlog. Nach abgeschlossener Einrichtung ist die Schlüsselausgabe gesperrt.

Apache ist standardmäßig ausschließlich unter `127.0.0.1:8080` erreichbar; MySQL hat keinen veröffentlichten Port. `DVM_HTTP_PORT` kann den lokalen HTTP-Port ändern. Der HTTP-Aufruf eignet sich zur Diagnose, die Einrichtung verlangt weiterhin HTTPS.

Für den Betrieb eine eigene HTTPS-Domain über einen Reverse-Proxy auf diesen lokalen Port führen. Der Proxy muss den ursprünglichen `Host` erhalten und `X-Forwarded-Proto` selbst auf `https` setzen. DVM vertraut diesem Header erst, wenn `DVM_TRUSTED_PROXIES` ausdrücklich die tatsächlich am Apache ankommende Proxy-IP beziehungsweise ein enges CIDR enthält. Bei einem Proxy auf dem Docker-Host ist dies häufig die Gateway-IP des Compose-Netzwerks; mit `docker network inspect` prüfen. Beispiel für genau einen zuvor geprüften Proxy: `export DVM_TRUSTED_PROXIES=172.20.0.1/32`. Danach den App-Dienst mit `docker compose ... up -d` neu erstellen. Keine pauschalen Netze und keine öffentlich erreichbaren HTTP-Ports freigeben. Ohne diese Einstellung werden auch mitgesendete HTTPS-Header ignoriert.

`https://DEINE-DOMAIN/setup/` öffnen. Einrichtungsschlüssel, Vereinsdaten und eigenes Administratorkonto eintragen. Datenbankserver ist beim mitgelieferten Compose-Paket `db`, Port `3306`, Datenbank und Nutzer jeweils `dvm`; das Datenbankpasswort steht in der privaten Datei `db-password`. Der vorhandene Installer prüft eine leere eigene Datenbank und führt die nötigen Schritte aus. Er bleibt nach Abschluss gesperrt. Anschließend unter `/login/` anmelden und den öffentlichen Setupordner entfernen:

```sh
docker compose -f docker/dvm/compose.yaml exec app rm -rf /var/lib/dvm/web/setup
```

Das eigene MySQL startet mit `log_bin_trust_function_creators=1`, damit der auf seine Datenbank begrenzte DVM-Nutzer die Schutztrigger für unveränderliche Originale anlegen kann. Der Anwendungsnutzer erhält keine globalen `SUPER`-Rechte. Bei einem eigenen externen MySQL-Server sind entsprechende Triggerrechte und bei aktivem Binärlog die passende Freigabe durch dessen Betreiber erforderlich.

## Persistenz und Aktualisierung

Das Volume `dvm` enthält die vollständige Installation unter `/var/lib/dvm`: Programm, öffentliche Einstiegsdateien, private Konfiguration, Medien, Sitzungen, Schlüssel und Installationsnachweis. PDF-Arbeitsdateien können auch unter dem privaten Programmverzeichnis entstehen. Das getrennte Volume `mysql` enthält die Datenbank. Neustarts übernehmen beide unverändert und führen keine Datenbankinitialisierung aus. Unbekannte nichtleere Volumes werden nicht neu befüllt. Pro Installation nur einen App-Container auf dieses Volume schreiben lassen.

Ein Imagewechsel aktualisiert die PHP-/Systemlaufzeit und den Paketvorrat für **neue** Installationen. Er überschreibt keine bestehende Anwendung im Volume. Der Start meldet unterschiedliche Paketquellen; `dvm status` zeigt Image- und Anwendungsfassung:

```sh
docker compose -f docker/dvm/compose.yaml exec app dvm status
```

Die Anwendung wird mit dem bestehenden [Paketupgrade-Assistenten](../../docs/PAKET-UPGRADE.md) aktualisiert. Das Upgrade-Paket außerhalb des laufenden Volumes aus dem gewünschten geprüften Quellstand erstellen:

```sh
php distribution/upgrade-native.php --build=/ABSOLUTER/NEUER/PFAD/dvm-upgrade
task_app=$(docker compose -f docker/dvm/compose.yaml ps -q app)
docker cp /ABSOLUTER/NEUER/PFAD/dvm-upgrade/private/upgrade-package "$task_app:/var/lib/dvm/private/"
docker cp /ABSOLUTER/NEUER/PFAD/dvm-upgrade/web/upgrade "$task_app:/var/lib/dvm/web/"
docker compose -f docker/dvm/compose.yaml exec --user root app chown -R www-data:www-data /var/lib/dvm/private/upgrade-package /var/lib/dvm/web/upgrade
```

Auf der eigenen HTTPS-Domain `/upgrade/` öffnen und den lokalen Schlüssel aus `UPGRADE-KEY.txt` eingeben. Originalschutz, Wartungssperre und Wiederaufnahme kommen aus dem vorhandenen Upgradeweg. Danach die dort beschriebenen temporären Upgradeverzeichnisse entfernen. Einen Runtimewechsel gesondert mit dem passenden Image durchführen und anschließend `dvm status` sowie die eigenen Funktionen prüfen. Ein älteres Image setzt eine neuere Anwendung nicht zurück. Für eine Rückkehr den dokumentierten Upgrade-/Wiederherstellungsweg verwenden.

Beide Volumes und die private Passwortablage gemeinsam nach dem eigenen Betreiberplan sichern. `docker compose down` erhält Volumes; `down -v` löscht Betriebsdaten und gehört nicht zum Updateablauf.

## Laufzeitumfang

Enthalten sind die erforderlichen PHP-Erweiterungen einschließlich IMAP, Node 22 für lokale Fotoaufbereitung sowie Python mit ReportLab, Pillow und pypdf für normale PDF-Ausgaben. Der absolute Pythonpfad wird im Image gesetzt. Der mitgelieferte Reel-Encoder benötigt Linux amd64; andere Architekturen sind dafür nicht abgenommen. OCR, KoSIT und ERiC sind gesonderte optionale Einrichtungen. Der [gemeinsame Vorbereitungsbefehl](../../docs/ZUSATZLAUFZEITEN.md) entpackt die gewählten Programme aus dem vollständigen Quellpaket. Private Konfiguration und Funktionsprüfung am endgültigen Containerpfad folgen getrennt. Das Standardimage enthält keine dieser drei Zusatzlaufzeiten.

Es startet kein Cron-Dienst. Mailkonten, Bank, Social-Dienste und Steuerzugänge werden nicht eingerichtet oder angesprochen. Nach der eigenen Einrichtung gewünschte Hintergrundaufgaben ausdrücklich entsprechend der [Zeitplananleitung](../../docs/HOSTING-ZEITPLAENE.md) einrichten, etwa über einen Hostzeitplan mit `docker compose exec -T app php ...`. PHPs einfacher `mail()`-Aufruf ist deaktiviert; konfigurierte SMTP-Wege bleiben nutzbar.

## Isolierte Prüfung

```sh
python3 docker/dvm/test.py --image dvm:local
```

Der Test verwendet eigene eindeutig benannte Container, ein internes Netzwerk und neue Volumes. Er prüft den echten HTTPS-geschützten Einrichtungsablauf mit synthetischem MySQL, Neustart-/Schlüsselerhalt, getrennte Installationsschlüssel und unerreichbare private Dateien. Keine vorhandenen Container oder Datenbanken werden ersetzt. Seine eigenen Ressourcen werden anschließend entfernt; Anbieter-, Mail- und Bankaktionen bleiben aus.
