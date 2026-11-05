# Separate öffentliche Manager-Demo

Die optionale Demo läuft auf derselben HTTPS-Domain unter `/demo`. Sie verwendet
normale Manager-Funktionen mit fiktiven gemeinsamen Beispieldaten. Öffentliche
Besucher wählen Mitgliederverwaltung, Finanzen, Redaktion oder Mitglied. Keine
dieser vier Rollen ist Superuser; im normalen Manager gibt es keinen Rollenwechsel.

Die Demo benötigt eine eigene leere MySQL-Datenbank, einen ausschließlich dafür
berechtigten Datenbankbenutzer und ein eigenes privates Verzeichnis außerhalb des
Webroots. Private Schlüssel, Dateien, Sitzungsspeicher und Betreiberpasswort bleiben
dort. Die Einrichtung prüft tatsächliche `SHOW GRANTS`, einschließlich der MySQL-
Besonderheiten bei Unterstrichen in Datenbanknamen. Globale Rechte und Rechte an
anderen Datenbanken werden abgelehnt. Unterstützt werden eigene Namen nach
`dvm_demo_<12 Hexzeichen>` oder der Hostingname `<Präfix>_dvmdemo`.

## Einrichtung

Private JSON-Eingabe (nur Platzhalter; eigene Werte ausschließlich privat ablegen):

```json
{
  "origin": "https://verein.example",
  "database": {
    "host": "mysql.internal.example",
    "port": 3306,
    "name": "hosting_dvmdemo",
    "username": "eigener_demobenutzer",
    "password": "NUR-PRIVAT-EINTRAGEN"
  },
  "operator_password": "EIGENES-PRIVATES-BETREIBERPASSWORT"
}
```

```sh
umask 077
php vereinsverwaltung/bin/manager-demo.php configure /privat/demo/demo.json < /privat/eingabe.json
php vereinsverwaltung/bin/manager-demo.php install /privat/demo/demo.json < /dev/null
php vereinsverwaltung/bin/manager-demo.php mount /privat/demo/demo.json /pfad/zum/webroot
php vereinsverwaltung/bin/manager-demo.php enable /privat/demo/demo.json
```

Bei eingeschaltetem MySQL-Binlog kann der Hoster für das erstmalige Anlegen von
Triggern einen gesonderten Einrichtungszugang benötigen. Die CLI akzeptiert dafür
bei `install` einmalig JSON mit `username` und `password` über STDIN. Dieser Zugang
wird nicht gespeichert und niemals vom Webbetrieb verwendet. Die Trigger erhalten
den eingeschränkten Demobenutzer als Definer. Keine globalen Serverkontrollen
ändern und dem Demobenutzer weder `SUPER` noch globale Rechte zuweisen. Alternativ
kann der Hoster die passende einmalige Schemavorbereitung übernehmen.

Die Installation journalisiert Identität, Baseline, Schlüssel, Konten und Beispiele.
Nach einer Unterbrechung denselben Aufruf mit derselben privaten Konfiguration
fortsetzen. Unbekannte Tabellen oder abweichende Strukturen werden erhalten und
abgewiesen. Wiederholung einer fertigen Installation setzt keine Daten zurück und
erzeugt keine neuen Schlüssel oder Konten. Auch von Besuchern bearbeitete Beispiele
bleiben erhalten. Es gibt keinen automatischen Rücksetzjob.

Der Mount legt nur den festen PHP-Einstieg und Rewrite-Regeln öffentlich ab. Der
Demo-Unterordner erhält `0755`, die privaten Verzeichnisse `0700` und private Dateien
`0600`. Im übergeordneten Apache-Webroot steht vor dem normalen Catch-all:

```apache
RewriteRule ^demo(?:/|$) - [L]
RewriteRule ^ index.php [END]
```

`[L]` lässt Apache die eigene `.htaccess` des Unterordners verarbeiten. Die normale
Anmeldung zeigt den Link „Demo ausprobieren“, wenn dieser gekennzeichnete Einstieg
im tatsächlichen Webroot vorhanden ist. Zusätzliche Demo-Cronjobs sind nicht nötig.

## Betrieb und Übungen

`/demo/operator` besitzt ein eigenes Betreiberpasswort, eine eigene Sitzung mit
CSRF-Prüfung, eine Anmeldebegrenzung und 15 Minuten Laufzeit. Der Betreiber kann die
Demo unabhängig von den öffentlichen Rollen schalten. CLI-Aktionen:

```sh
php vereinsverwaltung/bin/manager-demo.php status /privat/demo/demo.json
php vereinsverwaltung/bin/manager-demo.php disable /privat/demo/demo.json
php vereinsverwaltung/bin/manager-demo.php enable /privat/demo/demo.json
php vereinsverwaltung/bin/manager-demo.php operator-reset /privat/demo/demo.json < /privat/neues-passwort.json
```

Ein Reset erwartet `{"password":"EIGENES-NEUES-PRIVATES-PASSWORT"}` und entwertet
bisherige Betreiberanmeldungen. Ausschalten weist neue Aufrufe ab; bereits
begonnene Speichervorgänge können abschließen. Nach erneutem Einschalten wählen
Besucher ihre Rolle neu. Wiederholtes Einschalten allein entwertet keine Sitzung.

Jeder Besucher hat eine eigene Spracheinstellung. Die fiktiven Fachdatensätze
werden gemeinsam genutzt. Für ausdrücklich verlangte Passwortbestätigungen gilt
in der Demo `demo`; die Anwendung prüft damit erneut das private Konto der
aktuellen eingeschränkten Rolle. Rollenpasswörter selbst gelangen nicht zum Browser.

Die Demo-Bank im Bereich Bank & Kasse verwendet ausschließlich `demo / demo` und
liefert fiktive Umsätze. Abruf, Übernahmeprüfung und bewusste Aktivierung nutzen die
normalen Bankabläufe. Auch regulär vorbereitete und freigegebene Überweisungen und
Lastschriften lassen sich mit Empfängerprüfung, TAN `123456` oder simulierter
App-Bestätigung üben. Zahlungsantworten sind angenommen, abgelehnt oder unterbrochen.
Eine Unterbrechung bleibt ungeklärt und verhindert erneutes Senden, bis der Vorgang
anhand eines ausdrücklich fiktiven Nachweises geklärt wird. Eine Annahme allein
bucht keine Zahlung auf eine Rechnung. Fällige angenommene Mock-Aufträge erscheinen
als stabile fiktive Bankzeilen; künftige Lastschriften werden nicht vorzeitig als
gebucht ausgegeben.

Alle externen Mail-, Veröffentlichungs-, Bank-, Wallet-, Status- und ELSTER-
Transporte sind gesperrt. Anbieterzugänge und Installation sind für öffentliche
Rollen nicht zugänglich. Native Gerätezahlungen werden auf dem exakten Pfad
`/demo` ebenfalls vor der Geräteanweisung verweigert. Ein normaler Pfad wie
`/democracy` bleibt davon unberührt. Keine echten Zugangsdaten, Mitgliederdaten oder
Zahlungsnachweise in die öffentlichen Beispiele eingeben.

Links, Formulare, Redirects, Medien, Manifeste, Fetch/XHR und Worker bleiben im
Demopfad. Browser-Speicher und BroadcastChannels erhalten einen eigenen Namensraum.
Downloads bleiben Originalbytes; Fachtexte und Eingabewerte werden nicht pauschal
ersetzt. Ausweis-QR-Codes enthalten bereits im Bild den eigenen Demo-Prüfpfad.

## Reproduzierbare Prüfung

```sh
bash tools/ci/local-native.sh --core native-public-demo
DVM_PUBLIC_DEMO_BROWSER=1 bash tools/ci/local-native.sh --core native-public-demo
```

Die Prüfung erstellt und entfernt ausschließlich eigene synthetische Datenbanken
und Benutzer. Der Browserlauf verwendet einen eigenen Loopback-HTTPS-Server mit
kurzlebigem Testzertifikat und ausschließlich dessen SPKI-Vertrauen. Er benötigt
Chromium, Playwright und OpenSSL; die Cloud-Einrichtung stellt sie bereit. Das ist
keine Abnahme der späteren Hostinginstallation und kein Nachweis einer echten
Bankverbindung oder eines Gerätezahlungswegs.
