# DVM nach der Installation betreiben

## Ersteinrichtung abschließen

1. Mit dem eigenen Administratorkonto anmelden und Vereinsdaten, Briefpapier,
   Rollen und persönliche Zugänge prüfen.
2. Benötigte [Verbindungen](VERBINDUNGEN-UND-ZUGANGSDATEN.md) mit eigenen
   Zugangsdaten einrichten. Für öffentliche Anmeldeformulare zunächst den
   Mailversand und den Bestätigungsablauf mit einer eigenen Testadresse prüfen.
3. Gewünschte [Hosting-Zeitpläne](HOSTING-ZEITPLAENE.md) eintragen und ihre
   erfolgreichen Läufe beobachten. Ein geöffneter Kalender startet keinen Job.
4. Öffentliche Inhalte und ihre Vorschau kontrollieren; erst danach veröffentlichen
   und gegebenenfalls die Suchmaschinenindexierung freigeben.

Die [Bedienübersicht](BEDIENUNG.md) erklärt die wichtigsten Arbeitsbereiche.

## Zugänge und privater Speicher

`app/` und `private/` bleiben außerhalb des öffentlichen Webroots. Nur `web/`
wird vom Webserver ausgeliefert. Datenbankzugang, Mailpasswörter, API-Token,
Bankzustand und Steuerzertifikate gehören in die private Konfiguration.
Dateirechte und Eigentümer müssen zum Benutzer des PHP-Prozesses und der Jobs passen.

Einrichtungsschlüssel nach Abschluss lokal geschützt aufbewahren und den
öffentlichen Setupordner entsprechend der Installationsanleitung entfernen.
Keine Zugangsdaten in URLs, Git, Screenshots oder öffentliche Fehlermeldungen
übernehmen. Persönliche Konten statt gemeinsam verwendeter Administratorzugänge
verwenden und nicht benötigte Rechte entziehen.

## Hintergrundaufgaben und Versand

Worker haben getrennte Modi für interne Vorbereitung, Mail, Veröffentlichung,
Postfächer, Bankabruf und weitere eingerichtete Anbieter. Nur die benötigten
[Aufrufe und Zeitmuster](HOSTING-ZEITPLAENE.md) übernehmen. Die private
Worker-Konfiguration enthält die Zuordnung zum eigenen Verein und zu berechtigten
Verantwortlichen; deren Auswahl erzeugt keine zusätzliche Berechtigung.

Jährliche Beitragsmails werden ausdrücklich zum Versand ausgewählt. Eine
abgeschlossene technische Ausführung ersetzt weder eine einzelne Zustellbestätigung
noch eine Bankzuordnung. Bei unklarem Versand zuerst das Versandpostfach beziehungsweise
den Anbieter prüfen, bevor derselbe Vorgang erneut versendet wird.

## Systemstatus und Zusatzprogramme

Unter **Einstellungen → Systemstatus** die benötigten Verbindungen und Laufzeiten
kontrollieren. Die automatische Prüfung benötigt ihre Aktivierung und den
Statusworker beim Hoster. Die Website-PHP-Version und die PHP-CLI der Jobs
müssen beide die Voraussetzungen erfüllen.

[OCR, XRechnung/KoSIT und ERiC](ZUSATZLAUFZEITEN.md) werden bei Bedarf ergänzt.
Node.js ab 22 unterstützt die serverseitige Fotoaufbereitung; es ersetzt nicht
den PHP-Webserver. Bei Plesk die PHP-Website nicht pauschal als Node-Webanwendung
konfigurieren. Eine Browserbearbeitung benötigt nicht für jeden Bildvorgang Node.
Private Programme müssen vom tatsächlichen PHP-/Worker-Benutzer erreichbar sein.

## Updates

Ein geprüftes Upgrade-Paket aus dem gewünschten Quellstand erzeugen und den
[Paketupgrade-Assistenten](PAKET-UPGRADE.md) verwenden. Eine vorhandene
Datenbank nicht mit dem Neuinstallationsassistenten überschreiben.
Anwendungsupdate und Austausch eines Dockerimages sind getrennte Schritte.
Nach einem Update Anmeldung, zentrale Aufgaben und benötigte Verbindungen prüfen.

## Sicherung und Wiederherstellung

Sicherung und Aufbewahrung folgen dem eigenen Betreiberplan, etwa dem
Sicherungsverfahren des Hosters. Datenbank, private Dateien und ihre
Verschlüsselungsschlüssel müssen zusammen wiederherstellbar sein. Eine Kopie
der Datenbank allein ersetzt keine Sicherung der Medien oder Schlüssel.

Die optionale [DVM-Sicherung und Wiederherstellung](NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md)
beschreibt den kontrollierten nativen Weg. Archiv und Schlüssel getrennt privat
aufbewahren und eine Wiederherstellung mit geeigneten eigenen Testbeständen prüfen.
Es wird kein zusätzlicher Hosting-Backupauftrag allein durch Lesen dieser Anleitung angelegt.

## Demo und Testbetrieb

Eine neue reguläre Installation enthält keine Demomitglieder oder fremden Daten.
Tests mit eigenen synthetischen Beständen und getrennten Zugängen durchführen;
echten Mailversand, Zahlungen, Veröffentlichungen und Steuertransporte dabei
ausgeschaltet lassen. Die [Entwicklungsanleitung](CLOUD-NEUSTART-AUS-GIT.md)
beschreibt den isolierten Prüfweg.
