# Bankabruf und geprüfte FinTS-Zahlungen

Stand 9. September 2026. FinTS liest Bankdaten und kann freigegebene Überweisungen sowie CORE-Lastschriftaufträge über den geschützten Bankdialog einreichen. Der getrennte Auftragsablauf erstellt weiterhin eine SEPA-Datei als Fallback. Eine Bankannahme wird erst nach TAN/App-Freigabe protokolliert; die Rechnung beziehungsweise der Beitrag wird erst durch den Umsatzabgleich als bezahlt behandelt.

## Installation und Bereitstellung

`nemiah/php-fints` 4.1.0 ist durch `composer.lock` auf Commit `845c8cc973310c11ae2441e88e118c505d1b59f1` fixiert. `vendor/` bleibt ignoriert und wird aus dem Lockfile installiert. PHP 8.3+ mit curl, mbstring, openssl, DOM und XMLReader ist nötig.

```sh
cd banking
composer install --no-dev --no-plugins --no-scripts
composer check-platform-reqs --no-dev
php bin/check.php
```

Der Installationscheck öffnet keine Bankverbindung. `ready` bestätigt nur die Laufzeit. Registrierung und Bankannahme werden damit nicht geprüft. Liegt die vorhandene private Composer-Laufzeit an anderer Stelle, kann der Betreiber `VEREIN_FINTS_AUTOLOAD=/absoluter/privater/pfad/vendor/autoload.php` setzen; Version und Commit werden weiterhin exakt geprüft.

Mit dem privaten Release bereitstellen: `CamtReader.php`, `FinTsReadEngine.php`, `configure-fints.php`, `schemas/pain.008.001.08.xsd`, Anleitung sowie die aus dem Lockfile installierte Laufzeit oder den geprüften privaten Autoload-Pfad. Relativ erwarteter Ort: `<Release>/banking/`; die Schemadatei liegt immer dort. Zusätzlich erforderlich: Migration068, Bank-/SEPA-Services und Controller, Views und `public/bank-workflows.{js,css}`. Migrationen und Abhängigkeiten vor konsumierenden Views bereitstellen. Keine Schlüssel ins öffentliche Webverzeichnis kopieren. Der Adapter unterstützt neben dem Umsatzabruf auch geprüfte `SendSEPATransfer`- und `SendSEPADirectDebit`-Aufträge. Ein Schreibauftrag wird ausschließlich aus einem freigegebenen Serverauftrag erzeugt, zeigt Payee-Prüfung und TAN/App-Freigabe an und wird erst danach als bei der Bank eingereicht protokolliert.

## Private FinTS-Konfiguration

`EnableBanking::root()` bestimmt das Banking-Verzeichnis: produktiv neben dem privaten Vereinsdatenverzeichnis (`…/kulturhus-banking`), lokal `.local/enable-banking`. Dort liegen `fints.json` (0600) und `fints-state.key` (32 zufällige Bytes,0600); Verzeichnis0700. Bestehende Konfigurationen und Schlüssel bleiben beim Veröffentlichen erhalten.

Bei Hosting mit SSH-Chroot müssen Website und Cron dieselben vorhandenen Dateien
finden. Private Laufzeitpfade daher vom Verzeichnis der Laufzeitdatei (`__DIR__`)
ableiten, wenn sich der absolute Pfad zwischen SSH und Web-PHP unterscheidet.
Ein festes `/tmp/...` aus dem SSH-Zugang ist dort kein gültiger Webserverpfad.
Nicht ersatzweise neue Schlüssel erzeugen oder Bankdaten ins Webverzeichnis kopieren.
CLI-Prüfung allein genügt nicht: auch das Bank-Anmeldeformular auf der Website
lesend prüfen, ohne einen Bankabruf auszulösen.

Die Produktregistrierung für KulturhusConnect liegt vor: `DEINE_FINTS_PRODUKT_ID` (25 Zeichen). Der Betreiber übergibt `url`, `bank_code`, `product_id`, `product_version`, `club_id` als JSON über stdin an `php banking/configure-fints.php /privates/banking-verzeichnis`. `product_id` wird unverändert ausschließlich als `HKVVB/Produktbezeichnung` gesendet; der Konfigurationshelfer lehnt jede andere Länge ab. Der Helfer ersetzt keine vorhandenen Dateien. URL und BLZ müssen dem bestätigten Bankendpunkt entsprechen; keine Bankendpunkte raten. Keine PIN oder TAN in die Konfiguration schreiben.

Im Finanzbereich folgen Anmeldung, TAN-Verfahren, gegebenenfalls Gerät, Freigabe/TAN, Konto und Zeitraum. Unterstützt wird der tatsächliche CAMT-Abruf HKCAZ der fixierten Bibliothek. Banken ohne HKCAZ bleiben nicht unterstützt; kein MT940-Fallback wird vorgetäuscht. TAN-/decoupled-/Poll-Fortsetzungen respektieren Bankwartezeiten. Vor jedem externen Aufruf wird die Geräte-/Akteur-gebundene Versionsnummer verbraucht. Eine unklare Antwort kann nicht dieselbe TAN nochmals senden. Native Dialogzustände sind authentifiziert verschlüsselt, höchstens15Minuten verwendbar und werden nicht ins Browserformular übertragen. Abschluss entfernt die PIN aus dem Dialog. Optional kann der berechtigte Finanznutzer den Zugang ausdrücklich dauerhaft merken; dann liegen Kennung und PIN in einer getrennten, verschlüsselten Datei außerhalb des Webverzeichnisses. TANs, Freigabecodes und Dialogzustände werden auch dann nicht gespeichert. Der gespeicherte Zugang ist jederzeit löschbar. Abbrechen oder erneuter Zugriff nach Ablauf entfernt nur die temporäre Dialogdatei. Temporäre Dialogdateien und gespeicherte Zugangsdaten nicht aus Backups wiederherstellen.

## Dauerhafte Bankumsätze

### Automatischer Abruf

Für jedes verbundene Produktionskonto kann der berechtigte Finanznutzer im
Vereinsmanager den Abruf aktivieren. Der Netcup-Scheduler ruft dafür den
lokalen Worker auf; der Worker prüft je Konto alle fünf Minuten, ob ein neuer
Abruf fällig ist:

Die Kontoeinstellung **FinTS-Umsätze lesen** ist dabei verbindlich. Wird sie
deaktiviert, legt der Worker nur einen nachvollziehbaren übersprungenen Lauf
mit Hinweis an und kontaktiert die Bank nicht. So kann der Abruf je Vereinskonto
gesperrt werden, ohne die vorhandenen Umsätze oder Zuordnungen zu löschen.

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/native-delivery-worker.php --config=/ABS/DVM/private/worker.php --fints
```

Pro Konto und Zeitfenster wird ein Abruf höchstens einmal ausgeführt, ohne
doppelte Buchungen zu erzeugen. Ein erfolgreicher Erstabruf liest den von
der Bank gelieferten historischen Zeitraum (ab 01.01.2000, soweit die Bank ihn
bereitstellt), spätere Abrufe gleichen ein überlappendes 89-Tage-Fenster ab.

Benötigt die Bank eine PIN-, TAN- oder Banking-App-Freigabe, wird der Lauf mit
„Freigabe nötig“ protokolliert. Der Vereinsmanager zeigt dann die passende
Anleitung für PIN-Eingabe oder App-Bestätigung und bietet den manuellen Abruf
an. PIN, TAN und Freigabecodes werden nie gespeichert oder vom Worker umgangen.

Nur `PRODUCTION` darf Kontomappings und Finanzbuchungen erstellen. Sandbox bleibt separat. Konten werden durch die aktuelle Anbieterfreigabe belegt und anhand normalisierter IBAN, ansonsten des primären stabilen `identification_hash` identifiziert. Alternative Hashes können unscharf sein und reichen nicht aus. Sitzungs-UIDs sind keine dauerhafte Kontenidentität.

Alle Seiten eines Abrufs werden zunächst eingelesen (maximal100Seiten/10.000Zeilen). Erst nach vollständigem Abruf werden Buchungen, Herkunft und Rechnungszuordnung gemeinsam gespeichert. Umsatzidentität: `entry_reference` je Konto; `transaction_id` darf laut Enable Banking wechseln. Fehlende ältere Feedzeilen löschen niemals gespeicherte Umsätze. Vormerkungen verändern kein Geld. EUR-Umsätze ohne verlässliche Referenz, vor dem Anfangsbestand oder mit unklaren Daten bleiben zur Prüfung. Identische referenzlose Zeilen bleiben getrennte Prüfkandidaten, keine automatischen Buchungen.

Manuelle Zahlungen werden nicht allein nach Betrag still zusammengelegt. Mögliche Kollisionen (Konto,Richtung,Betrag und ±7Buchungstage) können ausdrücklich mit einer vorhandenen Zahlung verknüpft oder als tatsächlich zusätzliche Zahlung gebucht werden. Ein automatisch eindeutiger manueller Treffer braucht außerdem exakt denselben Tag,Namen,Text und die stabile Bankreferenz im Text. Fehlerhafte manuelle Angaben außerhalb dieser Merkmale benötigen weiterhin manuelle Prüfung. Herkunft `bank`/`bank_reconciled` wird aus `bank_transaction_sources` abgeleitet; die ältere technische Datenbankquelle bleibt erhalten.

Geänderte stabile Bankreferenzen überschreiben keine Finanzwerte. Expliziter Konfliktabschluss erhält den bisherigen Stand, Begründung und bestätigten Beobachtungshash. Dieselbe Abweichung bleibt erledigt, eine neue wird wieder geöffnet. Originalantworten,Abrufzeiten,Importläufe und Entscheidungen bleiben in der privaten Vereinsdatenbank. Tokens,PINs,TANs gehören nicht in diese Beobachtungen.

## SEPA-CORE und Rücklastschrift

Unterstützt: deutsche Vereins-/Mitgliedskonten,EUR,CORE,vollständig offene Beitragsrechnungen mit eindeutigem Zahler und aktuellem persönlichem elektronischen Mandat. Der bestätigte Kassenwart mit Finanz-Schreibrecht prüft Auswahl, bereits erfolgte Vorabinformation und Einzugstag (mindestens14Tage Abstand), bereitet den Auftrag vor und gibt ihn gesondert frei. Keine Rechnung in zwei aktiven Aufträgen. Mandats-/Bank-/Rechnungsänderungen machen die alte Vorbereitung unbrauchbar. Der verschlüsselte Snapshot bindet aktuelle Versionen. Export prüft die Freigabe erneut. Wiederholte Downloads behalten Nachricht-ID/End-to-End-IDs. Nach Export keine stille erneute Einzugsfreigabe.

`pain.008.001.08` wird vor Speicherung gegen das unveränderte ISO-XSD geprüft. Tatsächliche Herkunft und SHA-256: [schemas/SOURCES.md](schemas/SOURCES.md). XSD allein belegt keine Bankannahme oder zusätzliche Bankspezifika. Die Datei muss ausdrücklich im Onlinebanking hochgeladen und bestätigt werden. Einreichungsnummer kann hier dokumentiert werden. Weder Export noch diese Dokumentation bestätigt eine Zahlung.

Eine tatsächliche negative Rücklastschrift wird dem Auftrag zugeordnet. Bei genau einer vollständigen ungeteilten positiven Beitragszahlung auf demselben Konto werden die zwei vorhandenen Bewegungen verknüpft; die Forderung wird wieder geöffnet. Keine neue Geldbewegung. Geteilte,mehrdeutige oder fehlende Originalzuordnung bleibt als „Zuordnung noch klären“ sichtbar. Gebühren getrennt dokumentieren. Kein automatisches erneutes Einziehen.

Die konkrete PHP-CLI-Auswahl und Aufrufe liefert der Installer. In der privaten
Konfiguration den eigenen `fints_actor` mit aktuellen Finanzrechten wählen.
[Hosting-Zeitpläne](../docs/HOSTING-ZEITPLAENE.md).

## Prüfungen und Grenzen

`tests/bank-import.php`: historische/repetitive Abrufe,fehlende Zeilen,Vormerkungen,Mehrfachzeilen,Kollision,Quellenlabel,Konfliktabschluss,Tenant/ACL,Pagingfehler und Transaktionsrollback. `tests/fints-dialog.php`: kontrollierter Adapter ohne Netzwerk,verschlüsselte Zustände,Gerätebindung,TAN-Replay,unklare Antworten,echte CAMT-Analyse,XXE/Ressourcenlimits. `tests/sepa-orders.php`: Mandate,Vorschau,Freigabe,Export,XSD,Unveränderlichkeit,Rücklastschrift. `tests/bank-workflow-browser.cjs`: echte lokale Formular-POSTs/Reloads/Download mit einer historischen isolierten Testdatenbank,Desktop/Mobil und Suchauswahl.

Offen bis Zugang/Registrierung vorliegt: echter Produktionsabruf einschließlich Bank-TAN,bankeigene CAMT-Besonderheiten,tatsächliche CORE-Dateiannahme,echter Einzug und echte Rücklastschrift. Diese externen Vorgänge wurden nicht ausgeführt. Kein automatischer Zahlungsauftrag oder Sandbox-Finanzimport.

Primärquellen: [Enable Banking API](https://enablebanking.com/docs/api/reference/), [fixierte phpFinTS-Version](https://github.com/nemiah/phpFinTS/tree/845c8cc973310c11ae2441e88e118c505d1b59f1), [EPC2025CORE-C2PSP](https://www.europeanpaymentscouncil.eu/document-library/implementation-guidelines/sepa-direct-debit-core-customer-psp-implementation-1).
