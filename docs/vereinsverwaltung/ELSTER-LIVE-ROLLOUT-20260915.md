# ELSTER-Vorbereitung: Live-Abnahme vom 15. September 2026

**Aktueller Stand:** KSt/EÜR 2025 und nun auch [Belegnachreichung24 samt Anlagen-Rückmeldungen](ELSTER-PDF-BELEGE.md)
sind technisch freigegeben. Frühere Webserver-Testübermittlungen sind unten dokumentiert. Die folgenden
Abschnitte beschreiben zunächst den ursprünglichen Vorbereitungs-Rollout.

## Ergebnis und Grenzen

Die Vorbereitung ist unter `?view=finance&tab=tax&tax_auto=1` online.
Echte Abgabe und Testtransport bleiben abgeschaltet. Es wurden weder
Erklärungen angelegt noch Steuerdaten übermittelt. Der Bilanzjournal-Pilot
wurde nicht schreibend freigeschaltet. Fachliche Quellenlücken, besondere
Fälle, vollständige EÜR-Kennzahlen und E-Bilanz bleiben gemäß den zugehörigen
Implementierungsdokumenten offen.

Aktive Programmversion:
`/tmp/kulturhus-verein/releases/20260915-elster-preparation-11c0c80e-evidence`.
Private ERiC-44.3.6.0-Bibliotheken und Katalog außerhalb des Webroots;
getrennte Pfadkonfiguration für SSH/CLI und PHP-Webprozess. Der bestehende
OCR-Python-Launcher blieb unverändert. Originalpakete und Konfiguration
gehören nicht in Git oder das allgemeine Installationspaket.

## Sicherung und Erhaltung

- Sicherungsverzeichnis auf dem Server:
  `/tmp/kulturhus-backups/20260915-elster-preparation-11c0c80e`.
- `vereinsmanager.sql.enc` und unmittelbar vor der Migration
  `before-activation.sql.enc`: direkt verschlüsselter MySQL-Snapshot,
  vollständig authentifiziert, ohne unverschlüsselte SQL-Zwischendatei.
- `files-and-runtime.zip`: 1.809 Quelldateien/Konfigurationen, AES-256,
  nach Entschlüsselung gegen die Originalprüfsummen geprüft.
- `before-evidence-fix.zip`: verschlüsselte Absicherung der nachfolgenden
  Zwei-Dateien-Korrektur einschließlich vorheriger Laufzeitkonfiguration.
- Alle Sicherungen verwenden den vorhandenen privaten Sicherungsschlüssel.
  Kein neuer Schlüssel erzeugt und kein Schlüssel im Repository.
- Keine echten Datensätze, Dokumente oder SQL-Dumps auf den PC übertragen;
  nur Quellcode, aggregierte Zähler/Prüfsummen und synthetische Vorschauen.

Die ergänzenden Migrationen 087, 088 und 089 wurden unter bestehender
Worker-Sperre und MySQL-Schreib-/Schemasperren ausgeführt. Die kurzfristige
Manager-Sperre dauerte rund 0,9 Sekunden, öffentliche GET-Seiten blieben
freigegeben. Vor und nach der Migration stimmten die Inhaltsprüfsummen
aller 149 bestehenden Tabellen außer der gesondert geänderten
Migrationsmetadaten überein. Keine Geschäftsdatensätze geändert.

Kontrollzählung: 31 Mitgliedschaften, 25 Inventardatensätze, 5 Belege,
0 Kontoumsätze, 17 Dokumente. Inventardatensätze sind keine Aussage über
die Anzahl physischer Einzelgegenstände. Alle sechs neuen Tabellen sind
nach Abschluss leer. Schema 89, keine Wartungssperre mehr aktiv.

## Geprüft

- 31 explizit ausgewählte neue/geänderte Programmdateien; kein breites
  Überschreiben mit dem uncommitteten lokalen Arbeitsbaum.
- PHP-Lint unter 8.5 und unter der Websiteversion 8.3; Bootstrap erfolgreich.
- 1.799 übrige vorhandene Quelldateien gegen den vorherigen Release geprüft:
  keine unerwarteten Unterschiede. Hauptwebsite- und Manager-Einstieg sowie
  OCR-Launcher unverändert.
- Native ERiC-Prüfung und PDF-Erzeugung: KSt und EÜR, jeweils Code 0,
  über PHP-CLI 8.5.9 und PHP-Webprozess 8.3.33 (`cgi-fcgi`).
- Alle zehn synthetischen PDF-Seiten gerendert und visuell kontrolliert.
- Absichtlich beschädigtes XML wird zurückgewiesen. Test- und echte
  Sendeaufträge werden vor dem Transport blockiert; keine Zertifikate benutzt.
- Isolierte MySQL-Regressionsprüfungen: 47 Formular-/Freigabeprüfungen und
  zuletzt 58 Assistentenprüfungen. Keine realen Mails, Zahlungen oder Abgaben.
- Angemeldeter Browser: Jahresunterlagen, neuer Assistent, Formularauswahl,
  Bank & Kasse, Rechnungseingang, Beiträge, Belege, Auswertungen, Dashboard
  und öffentliche Website erreichbar; keine erkannten Absturzmeldungen.
- Keine Warnungen/Fehler in der geprüften Browserkonsole.

Der Live-Bestandsfall mit inzwischen nicht mehr auswählbaren Nachweisen
wurde nachgestellt und korrigiert: Die Auswahl bleibt erhalten, wird als
offener Punkt angezeigt und blockiert den Abschluss. Kein bestehendes
Unterlagenpaket und kein Beleg wurde verändert.

Temporäre authentifizierte Web-Prüfendpunkte, synthetische Server-XML-Fixtures
und die nicht mehr benötigte Laufzeit-Kandidatendatei wurden entfernt.
Es wurde kein Worker-Komplettlauf, Mailversand, Bankaufruf oder echter
ELSTER-Versand ausgelöst. Die Browserprüfung ist kein Volltest aller
Funktionen des Vereinsmanagers. Kein Git-Commit oder GitHub-Push im Rollout.

## Nachtrag: Tätigkeitsbericht – Überschrift

`ActivityReport.php` verwendet auf ausdrücklichen Wunsch für neue Vorschauen
und neu erstellte Berichte die Überschrift „Durchgeführte Veranstaltungen“
ohne den Zusatz „aus dem Website-Archiv“. Keine Änderung der Auswahl-,
Deduplizierungs- oder Berechtigungslogik; keine archivierten Berichte geändert.

- Sieben isolierte MySQL-Prüfungen in `tests/activity-report-wording.php`
  erfolgreich, einschließlich Doppelungen, abgesagten/jahresfremden Terminen,
  Quellenbelegen, Leseberechtigung und unveränderten Kalenderdaten.
- Vor Veröffentlichung ausschließlich diese Textänderung gegen den Live-Code
  abgeglichen, unter PHP 8.3 geprüft und atomar ersetzt.
- Vorherige Datei in `before-activity-wording.zip` im obigen privaten
  Sicherungsverzeichnis AES-256-verschlüsselt und durch Entschlüsselung geprüft.
- Neue Überschrift in der aufgeklappten Live-Vorschau im Browser bestätigt.
- Kein Datenbankzugriff im Bereitstellungsskript; Laufzeitkonfiguration und
  ELSTER-Übermittlungssperren unverändert. Keine Zertifikatsablage eingerichtet.

## Nachtrag: Nutzerseiten, Stammdaten und getesteter Versand

Aktiver Release: `20260915-elster-user-flow-185b0c1b`.
16 ausdrücklich ausgewählte Anwendungsdateien, kein Vollupload des lokalen
Arbeitsbaums. Entwicklungs-/Einrichtungsanzeigen, die veraltete Fortschrittsseite
und technische Feldpfade im Eingabeformular entfernt. Tatsächliche Prüfprobleme,
offene Geschäftsvorfälle und Übermittlungszustände bleiben sichtbar.
Neue Tätigkeitsberichte verwenden sachliche Überschriften; archivierte Berichte
und deren Quellen wurden nicht verändert.

`TaxClubData` übernimmt ausdrücklich gespeicherte Vereins- und Steuerdaten in
neue Produktiventwürfe. Bestehende Jahresantworten bleiben erhalten. Unbekannte
Beschäftigung, Jahresaufforderungen oder Umsatzsteuer-Sonderfälle werden nicht
verneint. Ein fehlender Vorsteuerabzug ist kein Beweis für Kleinunternehmerstatus.
Regionale Steuernummern werden nicht ohne sichere Umrechnung ersetzt.
EÜR 2025 erhält Vereinsname, Straße, PLZ und Ort in den amtlichen EÜR-Feldern.
Testentwürfe übernehmen auch bei vorhandenen Stammdaten keine echten Werte.

### Tatsächliche Testübermittlungen vom Live-Webprozess

HTTPS-Aufruf auf kulturhus.de → PHP 8.3.33 (`cgi-fcgi`) → PHP-Serializer /
ERiC-Adapter → private Linux-ERiC-44.3.6.0-Laufzeit. Ausschließlich amtliche
synthetische Beispieldaten und das amtliche Testzertifikat, keine DB-Schreibzugriffe.

| Datenart | Ergebnis | Test-Transferticket |
| --- | --- | --- |
| KSt_30_2025 | Code 0, Testabgabe und PDF | eh2586o16g2v6w6gjukyx9p3cq1642du |
| EUER_2025 | Code 0, Testabgabe und PDF | eh2580cy11i9j013qkm0d9ydy23qwm2m |
| Belegnachreichung_25 | Code 0, Testabgabe mit PDF-Anhang | eh2580fqe7x9mk6yrzgvkrogacy90z58 |

Der Anhang `Anlage-1.pdf` hat 33.900 Bytes und SHA-256
`6010ac90e305c941c9d7fc55814487adb84278994acd93cd6c4924e4e5642876`.
Test-Anlage sowie alle zwölf Seiten der drei amtlichen Protokolle gerendert und
visuell geprüft. Zusätzlich besteht der selbst erzeugte synthetische
KSt-/Gem-Kulturvereinsfall die native Prüfung im Webprozess des neuen Releases.
Dies ist eine technische Verfahrensabnahme, keine steuerfachliche Bestätigung
der echten Jahreszahlen oder sämtlicher Sonderfälle.

### Freigabegrenze

`production_enabled=true`, aber `production_versions` ausschließlich
`KSt_30_2025` und `EUER_2025`; auch `transport_enabled` nur für diese beiden.
Andere installierte Verfahren können vorbereitet, aber nicht produktiv
übermittelt werden. Belegnachreichung bleibt trotz erfolgreichem Transporttest
gesperrt: Das amtliche Anlagenprotokoll sieht spätere Ablehnungen vor; deren
Abruf-/Bearbeitungskette ist nicht implementiert. Ein Transferticket allein
belegt nicht die abschließende Annahme des Anhangs. Bis dahin Mein ELSTER nutzen.

Freigabe und Doppelsendeschutz unverändert: vollständiges Jahresprofil,
aktuelle XML/PDF-Prüfung, Berechtigung des Vorstands, ausdrücklich bestätigte
Kontrolle und eigenes Produktivzertifikat. Zertifikat/Passwort werden nicht
gespeichert. Es wurde keine echte Erklärung angelegt oder übermittelt.

### Sicherung und Prüfungen

- Server-Backup: `/tmp/kulturhus-backups/20260915-elster-user-flow-185b0c1b`.
  `before-update.zip` enthält 18 alte Quell-/Konfigurationsdateien mit AES-256;
  alle Einträge durch Entschlüsselung gegen die Ausgangshashes geprüft.
  `before-euer-prefill.zip` sichert die anschließende Stammdatenergänzung.
  Keine unverschlüsselten SQL-Sicherungen erzeugt.
- PHP-Lint 8.3/8.5, 52 Abgabe-/Freigabetests, 58 Assistententests,
  31 Mappingtests, 20 Stammdaten-/amtliche Schematests, 8 Tätigkeitsberichttests,
  20 Paket-, 23 Paketablauf-, 15 Arbeitsblatt-Mappingtests und 11 XML-Tests.
- 45 Mitgliedsantrags-, 268 Manager-Navigations-, 11 Einstellungs- und
  40 Finanznavigationstests. Isoliertes MySQL mit ausschließlich Testdaten;
  kein Mail-/Bankverkehr. Der Mitgliedsantragstest benötigt ausdrücklich
  `VEREIN_PYTHON=/usr/bin/python3` im Docker-Testcontainer.
- 1.815 andere Dateien unverändert. Website-/Manager-Einstiege und OCR-Launcher
  unverändert. 31 Mitgliedschaften, 25 Inventardatensätze, 5 Belege und
  17 Dokumente; keine neuen Steuerprofile, Erklärungen oder Abgabeversuche.
- Live-Browser: vorhandene Gemeinnützigkeitsangabe übernommen und eingeklappt,
  keine Entwicklungsstatusmeldungen, keine JSON-Metadaten in Stammdatentabellen,
  Jahresunterlagen und Formularauswahl ohne sichtbare Fehler/Konsolenfehler.
- Temporäre Testendpunkte, Testzertifikat, Fixtures und Kandidatendatei entfernt.
  Schlüssel und lizenzierte Originalpakete bleiben außerhalb von Git.

## Ergänzung: optionale Vereinszertifikatsablage

Live-Release: `/tmp/kulturhus-verein/releases/20260915-elster-certificate-e2ddf6b3`.

- Aktiver Runtime-Zeiger atomar umgestellt; ELSTER-Datenartfreigaben unverändert
  (KSt/EÜR 2025). Die vorhandene private ERiC-Konfiguration bleibt bestehen.
- Private Ablage ausdrücklich über `VEREIN_ELSTER_CERTIFICATE_ROOT` aus dem
  Runtime-Verzeichnis abgeleitet: `elster-certificates`, `0700`; Dateien `0600`.
  Passwortbasierte Zusatzverschlüsselung ohne dauerhaft gespeicherten Schlüssel.
- Acht vorherige Quell-/Runtime-Dateien in
  `/tmp/kulturhus-backups/20260915-elster-certificate-e2ddf6b3/before-certificate.zip`
  mit AES-256 gesichert und alle Einträge durch Entschlüsselung/hashvergleich geprüft.
  Archiv-SHA256: `c9d126a5d1edbd539878823a226ee1e768bb7d88b3bdc01e7cdc59f839fb0a8b`.
- BackupService separat atomar ergänzt: vollständige tägliche Recovery-ZIPs
  enthalten die verschlüsselten Zertifikatsdateien; nicht den PIN und keine Locks.
  Installer provisioniert das private Verzeichnis für neue Installationen.
- PHP-8.3-Lint der geänderten PHP-Dateien. 112 isolierte MySQL-/Ablage-/Abgabetests,
  18 Python-Tests, 66 Installertests und 58 Tests der Jahresvorbereitung erfolgreich.
- Offizielle synthetische Organisations-PFX: lokal und über HTTPS im PHP-Webprozess
  unter ERiC geprüft; falsches Passwort und dauerhafte Speicherung des
  Testzertifikats abgewiesen. Argon2id/XChaCha-Roundtrip im Webprozess erfolgreich.
- Keine Testabgabe, keine echte Abgabe, keine neuen Steuerdatensätze, keine
  Migration und keine Änderung der Geschäftsdaten. Vorher/nachher identisch:
  31 Mitgliedschaften, 25 Inventardatensätze, 5 Finanzbelege, 0 Kontoumsätze,
  17 Dokumente, 0 Steuererklärungen/Abgabeversuche.
- 1.825 andere Dateien des Releases hashidentisch. Temporäre Prüfendpunkte
  entfernt. Kein Zertifikat dauerhaft hinterlegt; der Verein lädt sein eigenes
  Organisationszertifikat über die neue Oberfläche hoch.
- Live-Browser: Zertifikatsseite erreichbar, keine Fehlerhinweise; Datei,
  Organisationsbestätigung und leeres Passwortfeld sind Pflichtfelder. Kein
  horizontaler Seitenüberlauf im geprüften Desktop-DOM. Bildschirmaufnahme
  nicht verfügbar; daher keine pixelgenaue oder mobile Sichtprüfung behauptet.

Bedienung, Schutzgrenzen, Aufbewahrung und Wiederherstellung:
[ELSTER-Zertifikatsablage](ELSTER-ZERTIFIKATSABLAGE.md).
