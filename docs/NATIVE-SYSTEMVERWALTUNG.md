# Native Systemverwaltung

**Einstellungen → Systemverwaltung** bündelt lokale Aufgaben, Betriebsereignisse,
persönliche Hinweisbestätigungen und ursprüngliche Paket-/Datenbanknachweise.
Die eigenständige Redaktion verwendet denselben festen Einstieg
`/vereinsverwaltung/?view=settings&tab=system`. Persönliche Anmeldung,
Suspension, MFA und ursprüngliche Vereins-/Systemrechte bleiben vorgeschaltet.

## Persönliche Sprache und ursprüngliche Eingaben

Die vier Bereiche folgen der persönlichen deutschen oder englischen Sprache.
Zählungen werden entsprechend formatiert, Zeitpunkte in Berliner Ortszeit
angezeigt. Gespeicherte Zustände, Zeitwerte, Aufgaben-/Ereigniskennungen,
Modulnamen, Kontrollversionen und ursprüngliche Paketnachweise bleiben wörtlich
unverändert. Beide Verwaltungswege verwenden denselben Originalschutz sowie
lesbare Hell-/Dunkeldarstellung und mindestens 44 Pixel große Bedienelemente.

Eine abgewiesene Anfrage behält Aufgabe, ursprünglichen Stand, Aktivierung,
Intervall beziehungsweise den ausgewählten Hinweis. Unvollständige, strukturierte
oder unbekannte Originale erhalten kein neues Standardformular. Auch explizit
übermittelte Nullwerte werden nicht durch aktuelle Defaults ersetzt. Ein nicht
übermittelter Aktivierungshaken bedeutet bewusst Pausieren. Sämtliche
Mutationstasten bleiben auf Fehlerseiten gesperrt. Ein bereits angenommener,
aber unbestätigter Auftrag kann den gespeicherten Stand trotzdem geändert haben;
eine Wiederholung des alten Zeitplanstands meldet HTTP409 ohne zweite Änderung.
Eine erneute Bestätigung desselben eigenen Hinweises bleibt idempotent.

Die vollständige Ansichtsauswahl wird vor Änderungen geprüft. Ungültige
Parameter melden HTTP422 und bleiben einschließlich ursprünglichem POST lesbar;
CSRF und aktuelle Rechte werden mit HTTP403 abgewiesen. Ein leerer Server-CSRF
akzeptiert keinen leeren übermittelten Wert. Unbestätigte Ergebnisse und nicht
sicher lesbare Systemnachweise melden HTTP503. HEAD bleibt in Erfolgs-, Eingabe-,
Rechte- und Verfügbarkeitsfällen ohne Antworttext. Auch eine auf einem anderen
Managerreiter eingegangene Systemanfrage zeigt ihre ursprünglichen Angaben und
die Meldung in der persönlichen Sprache; sie erhält keinen Zugriff auf diesen
Bereich. Es gibt keine freie Aufgabe, keinen anfragegewählten Verein und keinen
anfragegewählten privaten Speicherpfad.

## Ausdrücklich aktivierbare lokale Aufgaben

Die drei erlaubten Aufgaben sind zunächst ausgeschaltet:

- **Abgelaufenen Websitecache bereinigen** verwendet die vorhandene native
  Cacheverwaltung. Unabgelaufene Werte und ursprüngliche Inhalts-/Dateidaten
  bleiben erhalten.
- **Vorhandene Betriebsnachweise prüfen** liest tatsächliche Modulstände,
  ursprüngliche Wartungs-/Aktualisierungsnachweise und prüfbedürftige eigene
  Hintergrundläufe. Sie startet oder wiederholt keinen fachlichen Vorgang.
- **Abgelaufene eigene Anmeldedaten bereinigen** verwendet die native
  vereinsgebundene Authentifizierungsretention. Pro Lauf werden insgesamt
  höchstens hundert technische Sitzungs-, Einmal- oder Challengezeilen nach
  einer Karenz von 90 Tagen bereinigt. Aktive Originale und Fachhistorie bleiben
  erhalten. Globale Anmeldesperren gehören ausdrücklich nicht zu dieser
  Vereinsaufgabe; deren private Instanzverwaltung bleibt ein eigener Hostweg.

Jeder Zeitplan wird mit seiner ursprünglichen Kontrollversion ausdrücklich
freigegeben. Intervalle sind 5, 15, 30, 60, 360 oder 1440 Minuten. **Beim nächsten
privaten Lauf prüfen** ändert nur die Fälligkeit. Ein Seitenaufruf startet keine
Aufgabe, richtet keinen Cronjob ein und schreibt keine Standardkonfiguration.
Eine Fälligkeit ist kein bestätigter Laufnachweis; Rückmeldungen und Abschlüsse
stehen unter **Lokale Läufe**.

Der private Host kann beispielsweise minütlich diesen ursprünglichen Worker
aufrufen:

```sh
php app/vereinsverwaltung/bin/native-system-worker.php --config /eigene/installation/private/worker.php
```

Verwendet wird die bereits vom nativen Installer erzeugte private
`worker.php` mit derselben Vereins-/MySQL-Zuordnung und ursprünglichen
Authentifizierungs-/Verwaltungsschlüsseln. Die Datei und ihr privates Verzeichnis
müssen dem ausführenden Benutzer gehören; symbolische Pfade und öffentliche
Leserechte werden abgewiesen. Eine konfigurierte Umgebung muss die feste eigene
`environment.json` verwenden. Zusätzliche Optionen, insbesondere Versand-,
Veröffentlichungs-, Bank-, Zahlungs- oder Anbieter-Modi, sind nicht erlaubt.

## Lauf, Pause und Wiederaufnahme

Ein privater Aufruf übernimmt höchstens zwei fällige lokale Aufgaben. Die
Datenbank übernimmt genau eine Lease pro Aufgabe; gespeichert wird ihr Hash.
Die Lease gilt 120 Sekunden und wird zwischen den Arbeitsschritten durch
Rückmeldungen verlängert. Aktuelle ursprüngliche Rechte und die persönliche
Kontoversion des Freigebenden werden erneut geprüft. Änderungen an der
Kontosicherheit oder Vereinsidentität können deshalb eine neue ausdrückliche
Zeitplanfreigabe erfordern.

Eine Pause entzieht dem aktiven Lauf seine weitere Freigabe. Ein unterbrochener
Prozess darf nach Ablauf seiner ursprünglichen Lease nicht weiterarbeiten. Der
nächste private Aufruf erfasst den alten Lauf als unterbrochen. Fehlgeschlagene
oder unterbrochene Versuche warten zunächst 60, dann 120 Sekunden; nach drei
Versuchen endet die automatische Wiederholung bis zur erneuten ausdrücklichen
Freigabe. Ausschließlich die drei wiederholbaren lokalen Aufgaben unterliegen
diesem Ablauf. Ein unklarer externer Versand-, Zahlungs- oder
Veröffentlichungsübergang wird hier nie erneut ausgeführt.

Die ursprüngliche private Wartungssperre wird vor dem Lesen der Workerdatei
geprüft. Installer-/Wiederherstellungswartung blockiert lokale Systemarbeit.
Ein nicht bestätigter Verwaltungsauftrag liefert 503 mit sicherem Text. Gültige
Vorschläge und die abgesendete Originalversion bleiben in einer gesperrten
Ansicht erhalten. **Aktuellen Stand neu öffnen** liest den gespeicherten Stand;
prüfe ihn und bestätige erst danach einen neuen Auftrag. Die Fehleransicht
enthält keine aktive Wiederholungstaste.

## Journal und persönliche Hinweise

Das Journal speichert ausschließlich registrierte Ereigniscodes mit begrenzten
Aufgaben-/Modulkennungen, Zuständen und Betriebszählungen. Freie Fehlertexte,
HTTP-Anfragen, Nachrichteninhalte, E-Mail-Adressen, Dateipfade und Secrets sind
keine erlaubten Protokolleingaben. Wiederholte Beobachtungen derselben Quelle
erzeugen einen eigenen eindeutigen Nachweis statt doppelter Hinweise.

Journal und Laufverlauf zeigen jeweils zehn Datensätze. Zustand, Schwere und
Aufgabe werden vor Zählung und Seitenauswahl gefiltert. Eine Hinweisbestätigung
gehört zur aktuellen Person; sie bestätigt keinen fachlichen Vorgang und
entfernt keinen Hinweis eines anderen Kontos. Fremde Vereinsdaten bleiben
ausgeschlossen.

Nach erfolgreich autorisierter lokaler Arbeit werden je höchstens hundert alte
Informationsereignisse beziehungsweise abgeschlossene oder pausierte Läufe
bereinigt. Aufbewahrt bleiben mindestens tausend alte geeignete Nachweise sowie
die vergangenen 90 Tage. Persönliche Bestätigungen lösen einen Betriebshinweis
nicht für andere Personen. Deshalb bleiben Betriebsnotizen und
fehlgeschlagene/unterbrochene Läufe erhalten; für sie wird kein pauschaler
physischer Speicherdeckel behauptet. Beide Listen bleiben auf zehn Einträge pro
Seite begrenzt. Ein später SQL-Fehler rollt den jeweiligen Bereinigungsblock
vollständig zurück.

## Paket und Datenbank

Die Ansicht zeigt die tatsächlich laufende Paketgeneration und jeden erwarteten
Modulstand mit ursprünglichem Anwendungszeitpunkt. Vorhandene
`schema_migrations`-Einträge bleiben ihre eigene begrenzte Historie. Die Ansicht
installiert oder repariert keine Schemata und führt keine DDL aus.

Eine Quellumgebung ohne ursprüngliche `installation.json` wird ausdrücklich so
angezeigt. Installierte Nachweise müssen zum festen eigenen Ziel, Verein und
privaten Pfad gehören. Der aktuelle Upgradezustand wird mit dem vorhandenen
kanonischen `Upgrade::readState` und dessen ursprünglichem Authentifizierungsschlüssel
MAC-geprüft. Interne Datenbank-/Server-/Schlüssel-/Dateiwerte gelangen nicht in
die Ausgabe.

Private ursprüngliche Installationsbelege aus `upgrade-operations` werden mit
einem festen Zehnercursor gelesen, einschließlich ihrer tatsächlichen SHA-256.
Sie belegen den damaligen Stand **vor** einem Aktualisierungsvorgang. Die Ansicht
erfindet aus einem solchen Beleg keinen erfolgreichen nachfolgenden Abschluss.
Ein aktueller MAC-geprüfter Abschluss wird separat angezeigt. Vorhandene
Installer-, Upgrade- und Wiederherstellungswege bleiben dafür maßgeblich.

## Prüfung

`native-system` verwendet eine frische synthetische MySQL-Datenbank und eigene
Schlüssel. Die Fachprüfung umfasst tatsächliche parallele PHP-Schreiber, einen
wirklich getöteten privaten Worker, Leaseablauf, aktuelle Rechte, begrenzte
Wiederholungen, SQL-Unterbrechung, erhaltene Korrekturwerte, persönliche
Hinweisbestätigung und den tatsächlichen privaten CLI. Synthetische
Installations-/MAC-Umschläge prüfen die geschlossene Darstellung und Ablehnung
veränderter oder fremder Belege; sie sind kein Nachweis eines ausgeführten
Paketupgrades. Dessen vollständige ursprüngliche Installer-/Upgradeprüfung
bleibt ein eigener Nachweis. Reale Provider und produktive Geschäftsdaten werden
in dieser Gruppe nicht angesprochen.

Die abschließende isolierte Abnahme auf `9ab41a8` besteht 78 Fach-/
Runnerbedingungen und 165 tatsächliche Browserbedingungen. Beide Anwendungen
wurden über ihren persönlichen Konto-Einstieg mit und ohne JavaScript geprüft:
48 Ansichten auf Handy, Tablet und Desktop einschließlich 32-Pixel-Schrift,
20 echte POSTs, Lesersperre, CSRF, später SQL-Fehler mit erhaltenen Vorschlägen,
bewusste Wiederholung, Versionskonflikt, Pause und persönliche Bestätigung.
Alle 423 ursprünglichen Geschäftstabellen und beide privaten Schlüssel blieben
exakt. Kein externer Aufruf wurde ausgeführt; sämtliche 6.887 Quelldateien des
physisch getrennten Prüfexports waren vor und nach dem Lauf bytegleich.
Die eigenständige Systemansicht lädt dieselbe lokale Bootstrap-Basis und hat
einen eigenen geprüften Rahmen für schmale Ansichten und vergrößerte Schrift.

## Aktuelle kuratierte Zusammensetzung

Auch der frische kuratierte Export auf dem abgeschlossenen registrierbaren CMS-
und Auth-7-Betriebsstand besteht die vollständigen beiden tatsächlichen Apps:
78 Fachbedingungen, 165 Browserbedingungen, 48 Ansichten, 20 tatsächliche POSTs;
423 ursprüngliche Tabellen und beide Schlüssel bleiben exakt. Der zusätzliche
kanonische Auth-Retentionsanschluss besteht 35 Bedingungen. Keine externen
Aktionen, keine JavaScriptfehler. Der vorangegangene ursprüngliche reale
Paketupgrade-/Rollback-/Restore-Nachweis des Auth-7-Pakets besteht zusätzlich
152 Bedingungen und 96 HTTP-Schritte; das neue Systemmodul bleibt im regulären
SchemaRegistry-Installations-/Upgradeweg. P-25-Gesamtprüfung bleibt offen.

- `kulturhus-system-isolated-browser.log`, SHA-256 `3e6a5705b04fa4ade2f6e347bcea54a57e15ddf06d02d65dbe7e7d0c57aafc43`.
