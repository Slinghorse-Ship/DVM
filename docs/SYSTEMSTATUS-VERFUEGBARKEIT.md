# Gemeinsamer Systemstatus und Verfügbarkeit

Unter **Einstellungen → Systemstatus** stehen 16 Dienste in fünf kurzen Gruppen.
Jede Gruppe zeigt höchstens fünf Einträge. Mit JavaScript aktualisiert eine
Prüfung nur ihren Eintrag; andere ungespeicherte Einstellungen bleiben erhalten.
Ohne JavaScript öffnet die Einzelprüfung ihr Ergebnis in einem neuen Tab ohne
Zugriff auf das Ursprungsfenster. Die dortige ungespeicherte Auswahl bleibt
erhalten. Der Verlauf zeigt 20 Nachweise je Seite. Einrichtung und öffentliche
Freigabe bleiben zunächst eingeklappt.

## Sprache und ursprüngliche Einstellungen

Die interne Seite folgt der persönlichen deutschen oder englischen
Oberflächensprache. Das gilt auch für Prüfzustände, Gründe, Verlauf,
Hosting-Voraussetzungen, dynamische Prüfrückmeldungen und Fehlerseiten.
Maschinelle Dienstkennungen, gespeicherte Nachweise und private Konfiguration
bleiben unverändert. Die obere **Hilfe** öffnet die passende Aufgabenanleitung
in einem neuen Tab; sie führt keine Prüfung oder Speicherung aus.

Ein ungültiger Speicherversuch erhält die ursprüngliche Auswahl und den
ursprünglich geöffneten Einstellungsstand. Eine zwischenzeitliche Änderung
wird mit HTTP409 abgewiesen; ein SQL-Abbruch oder nicht sicher prüfbares
Ergebnis erhält HTTP503. Diese Antworten bestätigen keine erneute Speicherung.
Bei veraltetem Stand, ungültigen versteckten Angaben oder unklarem Ergebnis
bleibt **Speichern** gesperrt. **Aktuelle Status-Einstellungen öffnen** liest
den gespeicherten Stand ausdrücklich neu und öffnet die Einstellungsauswahl.
Vergleiche ihn vor einem neuen Speichervorgang. Der Link führt auch nach einem
weiteren Konflikt eine echte neue Leseanfrage aus.

Nicht unterstützte vollständige Angaben bleiben als lesbare ursprüngliche
Eingabe erhalten. Ist der private Statusspeicher unzugänglich, werden nur die
bereits übermittelten Einstellungswerte ohne CSRF, Zugangsdaten oder einen
neuen Schreibknopf gezeigt. Die Antwort behauptet keinen Erfolg. Ist eine
Speicherung bereits angenommen und nur ihre Antwort verloren gegangen, zeigt
das bewusste neue Öffnen die tatsächlich gespeicherten Werte; die alte
Vorgangsversion kann sie nicht nochmals überschreiben.

## Was ein Zustand belegt

„Nicht eingerichtet“, „Deaktiviert“, „Nicht geprüft“, „Eingeschränkt“, „Nicht
erreichbar“, „Anbieterstörung“, „Anwendungsfehler“ und „Nachweis veraltet“ sind
getrennt. Grün setzt eine tatsächlich bestätigte Prüfung voraus. Test-/Sandbox-
Zahlungskonten sind ausdrücklich als Test gekennzeichnet. Jeder Nachweis gehört
zum aktuellen Verein und zur aktuellen privaten Verbindung. Geänderte Zugänge,
Konten oder deaktivierte Dienste übernehmen kein altes Grün. Veraltete Nachweise
werden nach 15 Minuten als solche angezeigt; ein erneutes Lesen vorhandener
Bank-/Zeitplannachweise verlängert deren tatsächliches Alter nicht.

| Dienst | Ausschließlich verwendeter Prüfweg |
| --- | --- |
| DVM, MySQL | Vorhandene Schemaversionen lesen, `SELECT 1`; keine Installation im Webaufruf |
| Privater Speicher | Eigene temporäre Datei schreiben und bytegleich lesen, ausschließlich diese Datei entfernen; Platz prüfen |
| Warteschlangen | Eigene überfällige Aufträge lesen; keine Verarbeitung starten |
| Geplante Dienste | Tatsächlichen letzten eigenen Worker-Lauf lesen |
| Meta | Aktuelle Facebook-Seiten-ID und gegebenenfalls verknüpfte Instagram-ID lesend bestätigen |
| StadtLand.Funk | Bestehende Anmeldung und aktuellen Heimatbereich lesend bestätigen; keinen Token erneuern |
| Eventbrite | Aktuelle Organisation lesend bestätigen |
| SumUp | Aktuelles Händlerprofil lesend bestätigen |
| Stripe | Aktuelles Konto und Zahlungsfreischaltung lesen |
| PayPal | Authentifizieren und Händlerzuordnung mit lesendem Bericht bestätigen; OAuth allein gibt kein Grün |
| Mailversand | Aktive konfigurierte Versandkanäle per TLS/SMTP anmelden und abmelden; keine Empfänger oder Nachrichten übertragen |
| Mailempfang | Aktive konfigurierte Postfächer per TLS/IMAP schreibgeschützt öffnen und schließen; keine Nachrichten abrufen oder markieren |
| Push | Lokale private VAPID-Einrichtung und Bibliothek prüfen; keine Benachrichtigung versenden |
| ELSTER / ERiC | Tatsächliche lokale Bibliothek starten und ihre Version lesen; kein Zertifikat, XML oder Steuerauftrag verwenden |
| FinTS | Letzte tatsächliche abgeschlossene Bankabrufe der aktuellen Zuordnungen lesen; keine Banksitzung, TAN oder Umsatzübernahme starten |

FinTS und geplante Dienste sind als **Vorhandener Nachweis**, Push/ERiC als
**Lokale Prüfung** gekennzeichnet. Diese Zustände behaupten keine neu hergestellte
Bankverbindung oder tatsächlich zugestellte Push-Nachricht. PayPal-Berichte können
zusätzliche Anbieterrechte benötigen; fehlende Freigaben bleiben eingeschränkt.
Die Original-ERiC-Bibliothek fehlt derzeit in dieser Cloud; Einrichtung allein
ersetzt ihre [offene Laufzeitabnahme](ELSTER-LAUFZEITPRUEFUNG.md) nicht.

Einzelprüfungen haben begrenzte Laufzeiten und einen 30-Sekunden-Abstand.
Gleichzeitige Prüfungen desselben Dienstes werden verriegelt. Fehler enthalten
nur feste Zustandsgründe, keine Anbieterantworten, Zugangsdaten oder Finanzwerte.
Der Verlauf zählt bestätigte Prüfungen der letzten 24 Stunden gegenüber tatsächlich
gemessenen Prüfungen. Er erfindet keine prozentuale Dauerverfügbarkeit zwischen
Messpunkten.

## Rechte, öffentlicher Status und Zeitplan

Die interne Seite erfordert das aktuelle Einstellungsrecht; Finanzdienste
zusätzlich Finanzleserecht. Postfachdetails und deren Prüfungen bleiben der
Superverwaltung vorbehalten. Native Kontosperren und aktuelle Sitzungsrechte
werden beachtet. Weder öffentliche Besucher noch gewöhnliche Mitglieder oder
Redaktionskonten können private Prüfungen starten.

Der Pfad `/status` einschließlich Unterpfaden ist für neue CMS-Inhalte und alte
Weiterleitungen reserviert. Die öffentliche Seite ist zunächst nicht freigegeben. Eine berechtigte
Person wählt ausdrücklich die sichtbaren Dienste und aktiviert die Freigabe.
Öffentlich erscheinen ausschließlich Dienstname, allgemeiner Zustand und
Prüfzeitpunkt. Konten, Zugangsdaten, Gründe, Laufzeiten und Finanzdaten bleiben
privat. Widerruf oder Sperre der verantwortlichen Person entzieht die Freigabe.
Ein öffentlicher Abruf startet keine Prüfung; POST ist dort nicht erlaubt.

Die öffentliche Sprache folgt der Website-Konfiguration, unabhängig von der
persönlichen Managersprache und den Browser-Voreinstellungen. `/status` nutzt
die Quellsprache; beispielsweise wählt `/status?lang=en` ausdrücklich das
aktivierte Englisch. Die Sprachlinks enthalten nur aktivierte Website-Sprachen.
Installierte deutsche/englische Texte, genehmigte feste Textanpassungen und die
konfigurierten Rückfälle werden verwendet. Rückfalltexte tragen ihre tatsächliche
Inhaltssprache. Es wird keine neue übersetzte CMS-Adresse belegt: Eine vorhandene
Seite unter `/en/status` behält ihren ursprünglichen Eigentümer.

Doppelte, strukturierte oder ungültige Sprachangaben werden mit HTTP400,
deaktivierte/unbekannte Sprachen mit HTTP404 verweigert, jeweils ohne Dienste.
Der Rücklink verwendet die aktuell veröffentlichte übersetzte Startseite oder
ihren verfügbaren Originalrückfall. Fehlende Freigabe und gesperrte verantwortliche
Konten bleiben HTTP404; nicht sicher prüfbare private Konfiguration bleibt HTTP503
mit allgemeinem Text. Der öffentliche Statushandler liefert auch Fehler mit
`no-store` und `noindex, nofollow`, ohne Formulare oder Skripte. Er startet selbst
keine Sitzung. GET und HEAD führen keine Prüfung, Anmeldung, Nachricht oder
Fachänderung aus.


„Automatisch prüfen“ ist separat und standardmäßig ausgeschaltet. Der Hosting-
Zeitplan ruft alle fünf Minuten ausschließlich den Statusworker auf:

```sh
php /ABS/DVM/app/vereinsverwaltung/bin/service-status-worker.php --config /ABS/DVM/private/worker.php
```

Dieser nutzt die vorhandene private Konfiguration (0600), die aktuellen Rechte
der zuletzt verantwortlichen Person und eine eigene Vereinssperre. Pausierte
Dienste werden ausgelassen. Fehlende Anmeldung/Freigabe wird nicht wiederholt
automatisch angefragt; eine bewusste manuelle Prüfung bleibt möglich. Wartungs-,
Upgrade- und Wiederherstellungssperren verhindern Arbeit. Versand-, Zahlungs-
oder Veröffentlichungsoptionen werden abgewiesen. Ohne CLI-Zeitplan funktionieren
weiterhin manuelle Prüfungen im Manager.

## Lokale Abnahme

Die vier registrierten Gruppen `native-service-status`,
`native-service-status-providers`, `native-service-status-interface` und
`native-public-status-language` verwenden eigene synthetische MySQL-Datenbanken.
Domainprüfungen decken Verein, Rechte, geänderte Verbindungen, Versionkonflikte,
Alter, begrenzte Historie und unveränderte ursprüngliche Geschäftsdaten ab.
Produktionsadapter werden mit eingespeisten lesenden Anbieterantworten geprüft;
FinTS verwendet eigene private Dateien und tatsächliche gespeicherte Laufdaten.
Es erfolgt keine reale Anbieter-, Mail-, Zahlungs- oder Steueraktion.

```sh
DVM_SERVICE_STATUS_BROWSER=1 bash tools/ci/local-native.sh --core native-service-status native-service-status-providers
```

Die privaten Sprach-/Fehlerwege und die öffentliche Sprachwahl werden gezielt
mit ihren eigenen befüllten Prüfungen abgenommen:

```sh
DVM_STATUS_INTERFACE_BROWSER=1 bash tools/ci/local-native.sh --core native-service-status-interface
DVM_PUBLIC_STATUS_BROWSER=1 bash tools/ci/local-native.sh --core native-public-status-language
```


Der Browserweg prüft den tatsächlichen geschützten Produktionscontroller in fünf
Gruppen, sechs Handy-/Tablet-/Desktopgrößen, zwei Schriftgrößen sowie mit und
ohne JavaScript. Er prüft tatsächliche Formulare, einzelne HTTP-POSTs, erhaltene
ungespeicherte Auswahl, öffentliche Freigabe/Rücknahme, Kontosperre und den
tatsächlichen privaten CLI-Zeitplan einschließlich Wartungssperren. Die genaue
abgeschlossene Abnahme wird im Umsetzungsregister festgehalten.

Abgeschlossen am 15. Oktober 2026: **120 tatsächliche Ansichten, 6.169 Bedingungen**,
5 tatsächliche geschützte HTTP-POSTs, keine JavaScriptfehler und keine externen
Browseranfragen. Alle Gruppen bestehen auch mit geöffneten Einstellungen und
mindestens 44 Pixel großen Bedienzielen. 382 ursprüngliche Geschäftstabellen, beide Schlüssel und
das ursprüngliche Medium bleiben bytegleich. Der tatsächliche private CLI-Worker
besteht einschließlich unbekanntem Versandmodus, Pause, beiden Wartungsmarkern
und aktiver exklusiver Wartungssperre. Anonyme Freigabe und der Entzug durch
Sperre der verantwortlichen Person sind über tatsächliche HTTP-Anfragen geprüft.

47 Domain- und 60 Anbieterbedingungen bestehen. Ein gemeinsamer Lauf der elf
betroffenen Kerngruppen ist grün; zusätzlich bestehen vier CMS-/Pfadgruppen und
vier bestehende Mail-/Rechnungspostfachgruppen. Neuinstallation startet mit neun
Modulen und ohne öffentliche oder automatische Aktivierung. Der tatsächliche
Upgradeweg akzeptiert das neue Statusmodul bei einer alten vollständigen
Acht-Modul-Installation und verweigert fehlende bisherige Pflichtmodule.

SHA-256 des vollständigen Status-Browserlogs:
`99276cf83ab3ae0d944578b81a54cfb343b40d5d56d54d73fd1912f229cd62bc`.
SHA-256 des gemeinsamen Elf-Gruppen-Logs:
`8bfc6d3fb34e0f08ed2630dddee57eff957baa3e8689e20b3b256761fd048f04`.
Die vollständige unveränderte 250-Abnahme auf `c8d014f` bleibt ein gesonderter
Nachweis; die zwei neuen Statusgruppen werden nicht rückwirkend hineingezählt.
