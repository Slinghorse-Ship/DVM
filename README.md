# DVM – DeinVereinsManager

DVM ist eine selbst gehostete Webanwendung für die Vereinsarbeit. Vorstand und
Helfer verwalten Mitglieder, Beiträge, Rechnungen, Termine, Dokumente und die
Vereinswebsite über einen gemeinsamen Zugang. Berechtigungen bestimmen, wer
welche Daten sehen, bearbeiten, freigeben oder versenden darf.

Die Anwendung läuft auf der eigenen Domain mit PHP und MySQL. Name, Logo,
Absender, Briefpapier und Website-Gestaltung werden für den jeweiligen Verein
eingerichtet. Die Bedienung erfolgt im Browser auf PC, Tablet oder Smartphone.

## Was der Vereinsmanager kann

| Bereich | Aufgaben im Vereinsalltag |
|---|---|
| Mitglieder und Kontakte | Einzel- und Familienmitgliedschaften, Mitgliedsanträge mit E-Mail-Bestätigung, eigene Zusatzfelder, Mitgliedsausweise, Geburtstage sowie Excel-Import und -Export. |
| Beiträge und Rechnungen | Tarife und Beitragsforderungen verwalten, Rechnungen prüfen und erstellen, private Vorleistungen erstatten, Belege zuordnen und Spendenbescheinigungen vorbereiten. Beitragsmails werden bewusst zum Versand ausgewählt. |
| Bank und Kasse | Bankumsätze abrufen und zuordnen, Handkassen führen und Überweisungen über FinTS bearbeiten. Normale und Echtzeitüberweisungen hängen von Bank und TAN-Verfahren ab. Der Zahlungsstand folgt den tatsächlich zugeordneten Umsätzen. |
| Veranstaltungen und Tickets | Öffentliche Veranstaltungen, Ticketangebote, Bestellungen, Gutscheine und Einlass verwalten. Pro Veranstaltung sind mehrere benannte Kassen mit eigenen Salden und Abschlüssen möglich. |
| Vereinskalender | Termine, Vorstandssitzungen, Mitgliederversammlungen und Veranstaltungen überblicken; Beteiligte einladen und Rückmeldungen verfolgen. Veröffentlichungspläne stehen im eigenen Social-Kalender. |
| Dokumente und Berichte | Dateien, Vorlagen, Briefpapier, Tätigkeitsberichte und Gefährdungsbeurteilungen mit Risikomatrix bearbeiten. Freigaben und frühere Fassungen bleiben nachvollziehbar. |
| Inventar und Ausleihe | Gegenstände, Standorte, Bestände, Reservierungen, Ausgaben und Rückgaben führen; Anschaffungen mit ihren Belegen verbinden. |
| E-Mails, Newsletter und Presse | Gemeinsame Postfächer mit HTML-Anzeige, Anhängen, Suche und Antwortverlauf nutzen. Newsletter und Pressemitteilungen mit Vorlagen, Bildern, Veranstaltungsbausteinen und direkter Vorschau gestalten. |
| Social Hub | Facebook- und Instagram-Inhalte vorbereiten, gestalten und planen; Beiträge, Storys und Reels im Kalender öffnen. Das Bildstudio bietet Fotos, Texte, Ebenen und Tageshinweise. Die Veröffentlichung verwendet die eingerichteten Konten und Freigaberegeln. |
| Vereinswebsite | Seiten, Aktuelles, Fotogalerien und Veranstaltungsprogramm im Manager pflegen. Vier allgemeine Website-Vorlagen und eine Livevorschau helfen bei der Gestaltung. |
| Zusammenarbeit | Rollen und Zugriffsrechte, Vereinschat, Anhänge, Umfragen und Benachrichtigungen für die gemeinsame Arbeit verwenden. |
| Buchhaltung und Jahresabschluss | SKR42, EÜR, Bilanzjournal, GuV, Anlagen, Abschlussunterlagen, DATEV-Exporte und E-Bilanz-Vorbereitung unterstützen. |

**Video ist experimentell.** Vorlagenanimationen und lokale Video-/Reelerstellung
sind vorhanden. Verfügbare Formate und Funktionen hängen von Browser,
Serverlaufzeit und Hostingrechten ab; vor dem Einsatz auf der eigenen
Installation prüfen.

**Grenzen der Bilanzfunktionen:** Die besonderen steuerlichen Fälle der
SKR42-Konten **72500** (Finanzanlagenabschreibung aufgrund einer §-6b-EStG-Rücklage)
und **72550** (zusätzlich mit § 3 Nr. 40 EStG beziehungsweise § 8b Abs. 3 KStG)
sind für den E-Bilanz-Export noch gesperrt. Eine erfasste Quelle oder manuelle
Kontenzuordnung hebt diese Sperre nicht auf. Eine neue Übertragung nach § 6b
Absatz 10 auf Ersatzanteile einer GmbH ist vom persönlichen Anwendungsbereich
nicht umfasst; bereits früher vorgenommene Abzüge sind gesondert zu behandeln.
Für Steuerdateien gelten außerdem
der unterstützte Fallumfang, das passende Jahrespaket und die gesonderte
Einrichtung des amtlichen Prüf- und Übermittlungswegs.

## Installation auf eigenem Webspace

Der normale Installationsweg ist ein Upload-Paket mit anschließendem
Browser-Assistenten. Ein Betreuer erstellt das Paket einmal aus dem Repository;
auf dem Webspace wird die Einrichtung im Browser durchgeführt.

### 1. Hosting vorbereiten

Benötigt werden:

- eine eigene HTTPS-Domain und **PHP ab 8.3**;
- eine **neue, leere MySQL-Datenbank ab 8.0.16** mit Rechten für Tabellen,
  Indizes und Trigger;
- PHP-Erweiterungen `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `gd`,
  `intl`, `dom`, `xmlreader`, `xmlwriter`, `zip`, `zlib` und `fileinfo`;
- privater Dateispeicher außerhalb des öffentlich erreichbaren Domainordners;
- für automatische Aufgaben einen PHP-Zeitplan beim Hoster, etwa in Plesk
  oder cPanel.

Die [Hosting-Anleitung](docs/WEBSPACE-INSTALLATION.md) beschreibt Webserver,
Dateirechte und die Prüfung der Voraussetzungen. MySQL ist die unterstützte
Datenbank; MariaDB gehört nicht zum geprüften Installationsweg.

### 2. Upload-Paket erstellen

Unter [Releases](https://github.com/Slinghorse-Ship/DVM/releases) beim gewünschten
Versionsstand **Source code (zip)** herunterladen und entpacken. Dieses automatisch
erzeugte GitHub-Archiv enthält den vollständigen Quellstand einschließlich der
mitgelieferten Laufzeitpakete. Auf einem Rechner mit PHP im entpackten Projektordner ausführen:

```sh
php distribution/build-webspace.php --target=/ABSOLUTER/PFAD/dvm-upload
```

Den Beispielpfad durch ein neues, leeres Ziel **außerhalb des Repositorys**
ersetzen; sein übergeordneter Ordner muss vorhanden sein. Das Ergebnis enthält
`app/`, `private/`, `web/` und `SETUP-KEY.txt`. Die benötigten PHP-Bibliotheken
sind enthalten. Der Hoster benötigt dafür weder Composer noch Docker oder SSH.

### 3. Hochladen und im Browser einrichten

1. `app/`, `private/` und `web/` als benachbarte Verzeichnisse hochladen.
2. Den öffentlichen Domainordner auf `web/` legen. Bei einem vorgegebenen
   Ordnernamen wie `httpdocs` kann `web/` entsprechend heißen; `app/` und
   `private/` bleiben außerhalb. `SETUP-KEY.txt` lokal aufbewahren.
3. `https://DEINE-DOMAIN/setup/` öffnen. Einrichtungsschlüssel aus
   `SETUP-KEY.txt`, Datenbankzugang, Vereinsdaten und Administratorkonto eintragen.
4. Die Prüfung und Einrichtung abschließen. Der Assistent richtet alle nötigen
   Datenbankstände automatisch ein. Danach unter `/login/` anmelden und
   `web/setup/` sowie die lokale Schlüsseldatei entfernen.
5. Im Manager Vereinsdaten, Rollen und benötigte Verbindungen einrichten.
   Die angezeigten [Hosting-Zeitpläne](docs/HOSTING-ZEITPLAENE.md) beim Hoster
   übernehmen und ihre tatsächlichen Läufe prüfen.

Eine neue Installation legt keine Demomitglieder oder fremden Betriebsdaten an.
Eine vorhandene DVM-Installation wird über den
[Paketupgrade-Assistenten](docs/PAKET-UPGRADE.md) aktualisiert.

### API-Schlüssel und Herstellerkennungen

Die Grundinstallation benötigt keine API-Schlüssel externer Anbieter. Für
Zusatzfunktionen werden eigene Zugänge eingerichtet:

| Zusatzfunktion | Benötigter Zugang |
|---|---|
| E-Mail und gemeinsame Postfächer | SMTP-/IMAP-Server, Benutzer und Passwort beziehungsweise App-Passwort. |
| FinTS-Banking | Registrierte 25-stellige Produkt-ID, Produktversion, Bankendpunkt/BLZ sowie eigener Bankzugang und TAN-Verfahren. |
| ELSTER/ERiC | Fünfstellige Hersteller-ID, passende private ERiC-/Jahrespakete und für die Abgabe eigenes Zertifikat mit Passwort. |
| Facebook/Instagram | Seiten-/Profikonto-IDs, API-Version und berechtigter Zugangstoken; optional App-Secret und Verifikationstoken für Webhooks. |
| Eventbrite | API-Token und Organisations-ID. |
| Karten-/Onlinezahlungen | Eigene SumUp-, Stripe- oder PayPal-Zugänge; je nach Anbieter zusätzlich Leser-/Webhook-Angaben. |
| Browser-Push | Lokales VAPID-Schlüsselpaar und persönliche Browsererlaubnis. |
| Wallet-Mitgliedsausweise | Eigene Apple-Pass-Zertifikate oder Google-Wallet-Aussteller-/Dienstkontodaten. |

**FinTS-Produkt-ID und ELSTER-Hersteller-ID sind unterschiedliche Kennungen.**
Wer sie beschafft, welche Felder gebraucht werden und wo die offiziellen
Registrierungen stehen, erklärt die
[Übersicht zu Verbindungen und Zugangsdaten](docs/VERBINDUNGEN-UND-ZUGANGSDATEN.md).
Tatsächliche Schlüssel und Passwörter bleiben außerhalb Git und des öffentlichen
Webbereichs.

### Zusatzfunktionen einrichten

E-Mail-Versand und gemeinsame Postfächer benötigen eigene Mailzugänge;
Facebook/Instagram und Eventbrite eigene passende Konten und Berechtigungen.
FinTS benötigt die eigene Registrierung sowie ein unterstütztes Bankkonto und
TAN-Verfahren. ELSTER/ERiC benötigt eine passende private Laufzeit,
Herstellerkonfiguration und das eigene Zertifikat.

PDF-Ausgabe, OCR, serverseitige Fotoaufbereitung und experimentelle Videos haben
zusätzliche Laufzeit- und Hostingvoraussetzungen. Das optionale OCR-/PDF-Paket
wird mit `--with-ocr` erstellt. KoSIT und ERiC werden getrennt privat eingerichtet.
Der [gemeinsame Vorbereitungsbefehl und die anschließende Einrichtung](docs/ZUSATZLAUFZEITEN.md)
beschreiben alle drei Gruppen. Die
[Installationsanleitung](docs/WEBSPACE-INSTALLATION.md#zusatzfunktionen) und der
Systemstatus zeigen die Voraussetzungen. Nur die tatsächlich benötigten Dienste
aktivieren und testen.

## Hilfe und technische Dokumentation

**Hilfe** oben rechts öffnet die passende Aufgabenhilfe innerhalb des
Vereinsmanagers und berücksichtigt die eigenen Rechte.

| Thema | Anleitung |
|---|---|
| Installation und Hosting | [PHP-Webspace einrichten](docs/WEBSPACE-INSTALLATION.md), [Docker-Betrieb](docker/dvm/README.md) |
| Paketaufbau und CLI-Einrichtung | [Installationspakete](distribution/README.md) |
| Automatische Aufgaben | [Zeitpläne für Plesk und cPanel](docs/HOSTING-ZEITPLAENE.md) |
| Updates | [Paketupgrade](docs/PAKET-UPGRADE.md) |
| Weitere Bedien- und Fachanleitungen | [Dokumentation](docs/README.md) |
| Mitglieder aus Excel übernehmen | [Vorlage mit Anleitung](docs/vereinsverwaltung/vorlagen/DVM-Mitglieder-Vorlage.xlsx) |
| Aufbau des Quellcodes | [Architektur](docs/ARCHITECTURE.md), [Projektstruktur](docs/PROJEKTSTRUKTUR.md) |

## Lizenz

Der eigene DVM-Code darf kostenlos für den eigenen anerkannt gemeinnützigen
Verein ohne Gewinnerzielungsabsicht genutzt werden. Beiträge, Spenden und
zweckgebundene Veranstaltungseinnahmen sind erlaubt. Gewinnorientierte oder
nicht gemeinnützige Vereine und kommerzielle Weiterverwertung benötigen vorher
eine gesonderte schriftliche Erlaubnis der Rechteinhaber. Drittanbieter behalten
ihre eigenen Lizenzbedingungen.

Maßgeblich sind die [Lizenzbedingungen](LICENSE) und die
[Erläuterung zur Nutzung](docs/LIZENZ.md).

## Entwicklung und lokale Prüfung

Für die dokumentierte Linux-Cloud-Entwicklungsumgebung unter
`/workspace/Kulturhus`:

```sh
bash tools/cloud/bootstrap.sh --setup
```

Bei bereits eingerichteter Umgebung verwenden `--start` und `--check` die
vorhandenen Dienste. Voraussetzungen und Prüfwege stehen in
[Entwicklungsstart](docs/CLOUD-NEUSTART-AUS-GIT.md) und
[lokalen Prüfungen](docs/LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md).
Entwicklungsdaten, Schlüssel und Zugangsdaten bleiben außerhalb des Repositorys.
