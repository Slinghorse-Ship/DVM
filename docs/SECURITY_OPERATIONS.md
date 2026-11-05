# DVM: Sicherheit und Betrieb

Persönliche Konten, aktuelle Vereinsrechte, starke Passwörter, MFA und begrenzte
Anmeldeversuche verwenden. HTTPS, CSRF, sichere Sessions, geprüfte Dateitypen und
serverseitige Rechte-/Originalprüfungen gehören zum Betrieb. Die Admin-Adresse
ersetzt keine Anmeldung oder Berechtigung.

`app/` und `private/` liegen außerhalb des öffentlichen Webroots. Private
Konfigurationen und Schlüssel bleiben für den Installationsbenutzer geschützt.
Geheimnisse weder in URLs noch in Cron-Befehlen, Versionsverwaltung oder
öffentlichen Protokollen ablegen. Die ausgelieferte CSP beibehalten. PHP und die
noch verwendeten Abhängigkeiten anhand der tatsächlichen Paketstände warten.

## Mitgelieferte PHP-Bibliotheken

Das vollständige Installationspaket enthält die gesperrten Bibliotheksversionen
und ihre Original-Lizenzen. `symfony/mime` ist auf **7.4.22** aktualisiert und
enthält die [Herstellerkorrektur für CVE-2026-45070](https://symfony.com/cve-2026-45070).
Ungültige MIME-Parameternamen werden vor der Headerausgabe zurückgewiesen.
Die native Regression prüft alle drei öffentlichen Eingabewege sowie erhaltene
Anhänge und frühere gespeicherte MIME-Originale ohne Mailversand.

Sicherheitskorrekturen gehören in das vollständige geprüfte DVM-Paket.
Kunden müssen keine zusätzlichen Bibliotheken herunterladen. Die gesonderte
Betriebs- und Sicherheitsabnahme bleibt im Release-Register nachvollziehbar.

## Hintergrundbetrieb

Die benötigten getrennten [Hosting-Zeitpläne](HOSTING-ZEITPLAENE.md) in cPanel,
Plesk oder dem entsprechenden Hostingpanel einrichten. Die Installer-Ausgabe
enthält die eigenen Pfade. Ein einzelner allgemeiner Aufruf aktiviert nicht
sämtliche Fachmodi. Aktuelle Rechte, Anbieterbindungen und ausdrückliche
Freigaben bleiben maßgeblich. Unklare Zustellungen nicht automatisch wiederholen.

Nach Einrichtung einen bewussten Testlauf und einen regulären Lauf ohne
geöffneten Manager beobachten. Rückgabe, letzte Erfolge und die einzelnen
Fachergebnisse prüfen. Betriebsmetadaten und ursprüngliche Belege getrennt
aufbewahren. Zeitplaneinrichtung allein ist keine Zustellbestätigung.

## Sicherung und Wiederherstellung

MySQL, private Dokumente und Medien, Konfigurationen und ursprüngliche Schlüssel
in den vereinbarten Sicherungsplan aufnehmen. Archive verschlüsselt und räumlich
getrennt aufbewahren; den Wiederherstellungsschlüssel separat schützen.
Sicherungsintervalle und Aufbewahrung auf den Vereinsbetrieb abstimmen und die
Wiederherstellung in einem getrennten Ziel tatsächlich prüfen.

[Sicherung und Wiederherstellung](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md)
beschreibt das Verfahren. Installer, Updates und Aufbewahrung benötigen passende
Prüfungen am auszuliefernden Paket und getrennte Betriebsnachweise.
