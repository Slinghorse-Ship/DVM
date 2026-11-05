# Übungsumgebung für den Vereinsmanager

Die Übungsumgebung ist eine getrennte Installation mit ausschließlich erfundenen Daten. Sie eignet sich für den Kassenwart und neue Vorstandsmitglieder, um Abläufe sicher zu üben.

## Start unter Windows

1. Im Projektordner PowerShell öffnen.
2. `vereinsverwaltung\start-demo.ps1` ausführen.
3. Im Browser `http://127.0.0.1:8093/` öffnen.
4. Unten links **Testzugang** öffnen und die gewünschte Rolle auswählen:
   - **Superuser · Vereinsverwaltung**: alle lokalen Bereiche zum Durchspielen.
   - **Kassenwart**: Finanzen, Belege und Bankumsätze mit eingeschränkten Rechten.
   - **Mitglied · eigenes Profil**: persönliche Ansicht eines Mitglieds.
   - **Redaktion · Website**: Website-Entwürfe und redaktionelle Bereiche.

## Start unter macOS/Linux

`sh vereinsverwaltung/start-demo.sh`

Der Port kann als erstes Argument geändert werden, zum Beispiel `sh vereinsverwaltung/start-demo.sh 8094`.

## Was ist enthalten?

Die frische Demodatenbank enthält erfundene Mitglieder (aktiv und passiv), eine Organisation, ein Vereinskonto, eine Barkasse, bezahlte und offene Belege, einen ungeklärten Bankumsatz, eine verknüpfte Rechnung, eine E-Rechnung, Mengeninventar mit Packungsinhalt, zwei Veranstaltungstermine, eine GEMA-Übersicht, ein Protokoll, einen Chat, einen Fristennachweis und ein steuerliches Beispielkonto. Damit lassen sich die wichtigsten Wege durchspielen: Beleg prüfen, Zahlung zuordnen, Rechnung und Inventar verknüpfen, Termin/GEMA ansehen, Dokument ablegen und Rollen vergleichen.

## Bank und TAN üben

Im Menü **Finanzen → Bank** steht in der Demo die **Demo-Bank (Simulation)** bereit. Für die Übung können Sie beliebige erfundene Kennungen wie `demo` und `demo` eingeben; echte Bankdaten gehören hier nicht hinein. Anschließend das Verfahren auswählen und die angezeigte TAN `123456` verwenden. Danach können die fiktiven Umsätze gelesen und wie echte Bankzeilen geprüft oder zugeordnet werden. Bei einer vorbereiteten Überweisung oder SEPA-Lastschrift zeigt derselbe Ablauf eine weitere Demo-Freigabe; auch dort ist `123456` der einzige gültige Übungscode. Die Meldung bestätigt nur den Übungsauftrag.

## Sicherheitsgrenzen

Die Demo verwendet die Datei frühere Integrationsdateien, niemals die Vereinsdatenbank. Es werden keine echten E-Mails versendet, keine Newsletter zugestellt, keine FinTS- oder Enable-Banking-Verbindung aufgebaut und kein Geld bewegt. Die Bank- und TAN-Schritte sind vollständig simuliert; ein Demo-Zahlungsauftrag wird nur als angenommen protokolliert. Es wird keine echte SEPA-Datei eingereicht und keine Website veröffentlicht. Bankumsätze und Zahlungen in der Demo sind ausdrücklich fiktiv.

## Demo zurücksetzen

Server beenden und anschließend aus dem Projektordner ausführen:

`php vereinsverwaltung/bin/reset-demo.php --demo` (unter Windows mit der gebündelten PHP-Laufzeit aus `.local/php-8.5.10/php.exe`)

Beim nächsten Start wird die Übungsdatenbank neu erzeugt. Die Kulturhus-Datenbank bleibt dabei unverändert.
