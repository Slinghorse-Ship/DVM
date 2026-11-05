# Gemeinsames optionales Postfach

Im bestehenden Manager unter **Sendungen → Gemeinsames Postfach** können
aktuelle Vorstandsmitglieder und Superuser Vereinsnachrichten lesen, einer
aktuellen Vorstandsperson zuweisen, lokal ablegen und bewusst beantworten.
Allgemeine Mitglieds- oder Redaktionsrechte geben keinen Postfachzugriff.
Zuweisung ist eine Arbeitsaufteilung innerhalb des Vorstands, keine zusätzliche
Vertraulichkeitsgrenze zwischen Vorstandsmitgliedern.

## Einrichtung und Abruf

Das Postfach ist zunächst ausgeschaltet. Superuser hinterlegen Adresse,
IMAP-Server, Benutzername, Passwort, Ordner und Startdatum. Empfang benötigt die
PHP-IMAP-Erweiterung und TLS mit Zertifikatsprüfung auf Port 993. Ein leeres
Passwortfeld behält ausschließlich den unveränderten Zugang; ein anderer
Zugang benötigt ein neues Passwort. Zugang und Nachrichten werden mit dem
bestehenden privaten Schlüssel verschlüsselt. Schlüssel und Datenbank gehören
zusammen; ein Quellcode-Push sichert keine Postfachdaten.

**Nachrichten abrufen** liest ausdrücklich und ausschließlich lesend: keine
Gelesen-Markierungen, Löschungen, automatischen Antworten oder Rechnungsimporte.
Pro Abruf höchstens 25 Nachrichten und 40 MB, je Original höchstens 15 MB. Ein
nicht lesbares oder zu großes Original blockiert den weiteren Abruf bis zur
Prüfung im Originalpostfach; bei Fehler bleibt der Abrufstand unverändert.
Server-UID und UIDVALIDITY verhindern Doppelimporte auch bei wiederholter
Message-ID. Ein geänderter Zugang erhält eine neue Bindung; ältere Originale
bleiben lesbar, können aber nicht unter dem neuen Zugang beantwortet werden.

## Bearbeiten und Antworten

Die Liste zeigt sechs Nachrichten je Seite und filtert nach Ablage und
Zuständigkeit. Eingelesener Text wird als Text angezeigt; fremdes HTML und
Skripte werden nicht ausgeführt. Das vollständige MIME-Original einschließlich
Anhängen lässt sich unverändert als `.eml` herunterladen. Ein eigener
Anhangbetrachter gehört nicht zu diesem Ablauf.

Antworten verwenden das vorhandene Versandpostfach für Vereinsnachrichten;
dessen Absender muss der eingerichteten Empfangsadresse entsprechen. Zuerst
**Antwort speichern und prüfen**, danach die gespeicherte Fassung und genaue
Empfängeradresse bestätigen und **Gespeicherte Antwort senden**. Ohne eindeutig
lesbare Antwortadresse ist kein Versand möglich. Eigene Antworten enthalten
höchstens 20.000 Unicode-Zeichen. Die originale lokale Schreibweise der
Antwortadresse und die Eltern-Message-ID bleiben erhalten.

Veraltete Formulare überschreiben keine neueren Entscheidungen. Eigene
Korrekturen bleiben sichtbar; den aktuellen Stand ausdrücklich neu öffnen.
Nach bestätigter SMTP-Annahme steht „Vom Mailserver angenommen“; das ist keine
Zustellbestätigung. Bei unbekanntem Ausgang oder unterbrochenem Versand wird
nicht automatisch erneut gesendet. Nur ein nachweislich noch nicht begonnener
Versand kann bewusst fortgesetzt werden. Ablage löscht weder Original noch
Zuordnungsverlauf oder Antwortfassungen.

## Prüfung und Grenzen

Paketgeneration2026101565 installiert Lieferungsschema 14 mit drei zusätzlichen
Tabellen; kein Abruf erzeugt oder aktualisiert das Schema. Die native Gruppe
`native-general-mailbox` prüft isolierte synthetische Nachrichten, Rollen,
Verschlüsselung, reale konkurrierende PHP-Prozesse, COMMIT-Störungen, SIGKILL
und die tatsächlichen Managerformulare mit und ohne JavaScript. Aktivierung
der Browserstrecke: `DVM_GENERAL_MAILBOX_BROWSER=1` beim lokalen Core-Runner.
Die Wiederherstellungsgruppe prüft zusätzlich befüllte Postfachtabellen,
Originalanhänge, Zuweisung und ungesendete Antwort mit dem umgezogenen Schlüssel.

Reale Anbieterzugänge, Server-IMAP-Erweiterung, Abruf und SMTP-Annahme müssen
im jeweiligen Betrieb geprüft werden. Die Entwicklung löst keine echten
Mails aus. Weitere Fachbereiche, P-12/P-25, Geräteabnahmen und Finanzrestfälle
bleiben offene Aufgaben; dieses Feature belegt keinen Gesamtabschluss.

## Softwareabnahme vom 18. Oktober 2026

- 54 PHP-/Originalbedingungen einschließlich Bestandsschutz nach Browserbedienung;
  132 Browserbedingungen in 16 Ansichten, mit/ohne JavaScript, 320/390/820/1440 px,
  normale/doppelte Schrift. Keine externen Anfragen oder JavaScript-Fehler.
- Zusätzlich 12 tatsächliche Tastatur-/Touchbedingungen am bestehenden gemeinsamen
  Manager, mit/ohne JavaScript; keine geschäftlichen Änderungen.
- Vier benachbarte Mail-/Managergruppen grün; Installer 256, Webspace 57,
  befüllte Wiederherstellung 226 Prüfungen. Paketupgrade 152 Prüfungen mit 96
  tatsächlichen Browserschritten; unterbrochener Restore 29 Prüfungen mit drei
  echten Prozessabbrüchen. Keine echten Mails oder sonstigen externen Aktionen.
- Gemeinsame Laufzeit: 428 bestehende Geschäftstabellen und Originalschlüssel
  unverändert, ausschließlich drei leere Tabellen additiv ergänzt, kein Seed.
- Paket: sechs Inventar-, neun Archiv- und sechs SDK-Materialisierungsprüfungen;
  sämtliche 5206 Paketdateien verifiziert. 318 registrierte Kerngruppen bedeutet
  keinen neu ausgeführten vollständigen 318-Gruppen-Lauf.

Lokale ignorierte Protokolle stehen unter `.local/test-results/`.
Die abschließende Dokumentations-/Inventarfortschreibung ändert keine der
geprüften Anwendungs- oder Testquellen. SHA-256 der abgeschlossenen Läufe:

- Postfach mit Browser: `71cd090c06aab4848ede74d714422a68a3ba6301685c98dc2f346bb2e1a711f4`.
- Mail-/Manager-Nachbarn: `1501739a0026f76fa47890af65678892d79b6dec8d5a01240378d40d193a1253`.
- Installation/Upgrade/Restore: `83e9e69109c6e01cd136e089e7ec5f053c6264442aad1a9c409f3f625c758374`.
- Tastatur und Touch: `2e002f715bddfdb03ce1a1c0c200dca1102be5aedfd06ef260f5ef2ab10aa00a`.
- Bestehende Laufzeit: `39e08feb66d828022fbbcbb3abf295e32e8714ccac8896ed181d0e016125359c`.

## Bedienung und Originalschutz – 1. November 2026

Die Oberfläche folgt der eigenen Sprache Deutsch/Englisch. Nachrichtentexte,
Betreffzeilen und Antworten bleiben unverändert. Tastatur und Touch funktionieren
auch ohne JavaScript. Gespeicherte Antworten und neue Entwürfe bleiben an genau die
ausgewählte ursprüngliche Nachricht gebunden.

Bei Eingabefehlern bleiben vollständige eigene Angaben und die ursprüngliche
Entwurfskennung erhalten. Bei veralteter Fassung oder unbestätigtem Ausgang ist
dieser Entwurf gesperrt lesbar. Über den gesonderten Leselink den gespeicherten
Stand in einem neuen Fenster prüfen. Nicht blind erneut senden. Auch bei einem
Formular im falschen Managerbereich bleiben die übermittelten Angaben sichtbar.
Der aufklappbare Originalnachweis enthält keine Passwörter oder Sicherheitscodes;
ein neues Passwort gegebenenfalls erneut eingeben.

Nicht mehr verfügbare Ergebnisseiten zeigen keine anderen Nachrichten an ihrer
Stelle. Die bewusst ausgewählte Nachricht bleibt über ihre eigene Kennung
erreichbar. Der Originaldownload verwendet genau diese Nachricht.

Generation2026101802: 120 native und 265 Browserbedingungen in vier DE/EN-/JS-
Profilen, insgesamt64 Größen-/Schriftansichten; keine echten Mails. Die neue
englische Strecke schützt468 vollständige Tabellen und zwölf frühere vollständige
Antwortzeilen; nur zwei bewusst gespeicherte ungesendete Testantworten kommen hinzu.
Auf dem laufenden Manager bestehen16 reine Ansichten und64 Größen-/Schriftansichten
bei unveränderten467 vollständigen Tabellen und vier privaten Schlüsseln.
Die Angaben im Abschnitt vom18.Oktober bleiben historische Nachweise.
