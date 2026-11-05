> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Verknüpfungen: Umsetzung

## Zahlungszuordnung

Neu erfasste Umsätze werden innerhalb derselben Datenbanktransaktion anhand
vollständiger Rechnungsnummern geprüft. Gibt es keine Rechnungsnummer, kann
eine bekannte Mitgliedsnummer genau eine passende offene Beitragsforderung
identifizieren. Familienangehörige verweisen dabei auf dieselbe Forderung.
Betrag und Zahlungsrichtung müssen passen; es wird weder nach Namen geraten
noch anhand eines bloß gleichen Betrags zugeordnet.

Mehrere Referenzen, widersprüchliche Mitglieds-/Rechnungsnummern, mehrere offene
Jahre, Teilbeträge und bereits korrigierte Zuordnungen bleiben manuell.
„Bank & Kasse“ bietet den Filter „Ungeklärte Umsätze“ und einen ausdrücklich
auszulösenden Abgleich vorhandener Umsätze. Das Aufheben einer Zuordnung
ändert keinen Kontostand und wird nicht automatisch rückgängig gemacht.
Der automatische Suchumfang ist auf jeweils 10.000 Belege/Mitgliedschaften
begrenzt; größere Bestände bleiben zur manuellen Zuordnung offen.

## Konten und Spenden

Die Rechnungsbankverbindung kann einem eigenen Finanzkonto zugeordnet werden;
die Auswahl ist auf Bank-/Offlinekonten desselben Vereins begrenzt. Das
verknüpfte Konto zeigt die Rechnungs-IBAN, und neue Rechnungen speichern seine
Kennung. Ohne Zuordnung bleibt eine neue Beitragsrechnung ein Entwurf.
Bestehende ausgestellte Rechnungen werden nicht verändert.

Geld- und Sachspenden können in ihrer Detailansicht ausdrücklich einem Kontakt
zugeordnet werden. Dies erfordert Finanz-Schreibrechte und Zugriff auf den
gewählten Kontakt. Migration 031 ergänzt die Zuordnungstabelle, ohne
vorhandene Spendernamen oder Buchungen umzudeuten. Die Zuordnung erhöht die
Belegversion und wird protokolliert; der ursprüngliche Belegtext bleibt erhalten.

Mit Finanzbelegen verbundene Zuwendungsentwürfe verwenden nur tatsächlich
zugeordnete Zahlungseingänge. Betrag und Datum sind serverseitig an diese
Zahlungen gebunden. Bei verschiedenen Zahlungstagen kann eine einzelne
Zuordnung ausgewählt werden. Der PDF-Snapshot enthält deren IDs. Vor der
Ablage werden die Quelldaten nach dem Rendern erneut geprüft. Die Ausgabe
bleibt ein gekennzeichneter Entwurf; eine rechtsgültige automatische
Bescheinigung wird dadurch nicht behauptet. Unverbundene manuelle Entwürfe
bleiben ausdrücklich ohne Zahlungsnachweis und ohne erfundene Quellen möglich.

## E-Mail

Vereinsmails und Newsletter verwenden die gespeicherten Einstellungen für
Absendername, Absenderadresse und Antwortadresse. Die vorhandene
SMTP-Verbindung und ihr Passwort bleiben in der geschützten
Serverkonfiguration. Die Maske enthält keine wirkungslosen SMTP-Eingabefelder
mehr und zeigt, ob die Serverkonfiguration geladen ist. Leere optionale
Einstellungen lassen die bisherige Servervorgabe bestehen.

Aufnahme-Beitragsrechnungen, Jahresrechnungen und Erinnerungen verwenden die
Beitrags-Mailtextbausteine. Der aktuelle offene Betrag und die Rechnungsnummer
werden eingesetzt. Die Bankangaben stammen aus der zugehörigen Rechnung,
damit eine spätere Bankänderung alte ausgestellte Rechnungen nicht umdeutet.
Rechnungen enthalten ihr PDF; bei Erinnerungen gilt die gespeicherte
Anhangsoption. Willkommensmails verweisen auf `/login/`.

Newsletter-Einwilligungen werden nicht ergänzt oder verändert. Mitgliedschaft
allein erzeugt weiterhin kein Newsletter-Abonnement.

## Bewusst noch nicht aktiviert

FinTS-Registrierungsnummer und Zugang liegen nicht vor. Es gibt keinen
Bankabruf und keine Zahlungsübermittlung. Auf Nutzerwunsch wird die spätere
Bankanbindung zunächst ausschließlich lesend eingerichtet. SEPA-Lastschriften
bleiben deaktiviert und werden erst später gesondert erprobt.

Für die tatsächliche Einrichtung fehlen weiterhin bestätigte Vereinsanschrift,
Kontoinhaber, IBAN sowie Kontoname und Anfangsbestand mit Stichtag. Es wurden
keine Beispielwerte als Live-Daten angelegt und keine neuen Konten erraten.

## Prüfung

30 neue Integrationsprüfungen und 7 Prüfungen der gemeinsamen Mailkonfiguration
bestanden. Zusätzlich bestehende Suites für Grundlagen (72), Finanzen (60),
Matching (53), Kontierung (56), Einstellungen (87), Finanz-Leseansichten (45),
Beitragssätze (27), Aufnahmeversand (37), Beitragsläufe (45), Spendenentwürfe
(75), Antragsbestätigung (18), Kontoanlage (26) und Newsletter-Anbindung (29).
Insgesamt 667 gezielte Prüfungen. Datenbanken waren isoliert, echte
Mailtransporte abgefangen. Kein Bankzugriff und kein echter Testversand.

## Live-Veröffentlichung

Commit `209403a` wurde am 6. September veröffentlicht. 27 betroffene Dateien
wurden vorab gegen den vorherigen Git-Stand geprüft, gesichert, mit PHP
syntaktisch geprüft und nach Aktivierung bytegenau zurückgelesen.
Migration 031 wurde nach einem privaten Dateidatenbank-Snapshot angewendet;
bestehende Buchungen wurden nicht neu zugeordnet. Sicherung:
`/tmp/kulturhus-verein/rollback-linkages-209403a/`.

32 öffentliche/Gast-/Privatpfad-Prüfungen bestehen. Die Live-Datenbank meldet
Schema 31 und keine Fremdschlüsselverletzungen. Der beobachtete Workerlauf
vom 08:31:21 MESZ meldet den Vereinslauf als erfolgreich und den
Newsletterversand als inaktiv mangels Aufträgen. Keine Live-Testbuchungen,
kein Testversand, kein Bankzugriff. Die angemeldeten neuen Eingabemasken
wurden nicht erneut mit einer echten Benutzersitzung im Browser bedient.

## Erster echter Mailtest am 6. September

Auf ausdrücklichen Nutzerauftrag wurde um 08:44 Uhr MESZ eine einzelne
Testnachricht an die vom Nutzer angegebene Outlook-Adresse über den aktiven
Produktionsadapter `MembershipMailTransport` verschickt. Der Transport kehrte
erfolgreich zurück; das belegt die SMTP-Annahme, noch nicht die Zustellung im
Empfängerpostfach. Betreff: „Kulturhus: Mailtest der Betriebsprüfung vom 06.09.2026“.
Ein privater Versandmarker verhindert versehentliches Wiederholen.

Für diese Adresse wurden vorab keine Kontakte, Mitgliedsanträge oder
Bestätigungsanfragen gefunden. Es wurde keine Mitgliedschaft, Rechnung oder
Newsletter-Einwilligung angelegt. Der vollständige Ablauf bleibt offen:
Antragsdaten (Name, Geburtsdatum, Anschrift), vollständige Vereins- und
Rechnungsbankdaten sowie eine Empfangsbestätigung fehlen weiterhin.
Der Test prüft den echten Transport, nicht die formularseitige Versandkette,
Rechnungsanhänge oder die Bearbeitung durch den Vorstand.

## Nachtrag: Empfang und Bescheinigungen

Der Nutzer hat den tatsächlichen Eingang der Outlook-Testmail bestätigt.
Der vollständige Aufnahme-/Rechnungslauf ist damit noch nicht abgeschlossen.
Vereinsanschrift und Registerdaten sind inzwischen aus dem Live-Impressum
hinterlegt. Die neue Ausstellung von Einzel-Zuwendungsbescheinigungen zur
eigenhändigen Unterschrift ist unter
[Spendenbescheinigungen](SPENDENBESCHEINIGUNGEN-2026-09-06.md) dokumentiert.
Die frühere Beschränkung auf Entwürfe wurde technisch erweitert; die echte
Ausstellung benötigt weiterhin den aktuellen Steuerbescheid und geprüfte
Ausstellungsberechtigung.
