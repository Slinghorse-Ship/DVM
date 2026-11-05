# Verknüpfungsprüfung vom 6. September 2026

**Nachtrag:** Die technischen Korrekturen und ihre Live-Veröffentlichung sind
in [Verknüpfungen: Umsetzung](VERKNUEPFUNGEN-UMSETZUNG-2026-09-06.md)
beschrieben. Der folgende Bericht dokumentiert den Stand vor diesen Änderungen.

Geprüfter Stand: `dc337f2`, nach Veröffentlichung der zentralen Mitgliedsbeiträge.
Auftrag: Datenwege zwischen Website, Anmeldung, Newsletter, Mitgliedern,
Beiträgen, Spenden und Finanzen prüfen. Ergebnis: Die vorhandene manuelle
Buchhaltung und Aufnahme sind verbunden; mehrere erwartete Automatiken und
Einstellungs-Verknüpfungen fehlen weiterhin.

## Live-Befund

17 zentrale PHP-Dateien auf Netcup stimmen mit dem Git-Stand überein.
Die private Vereinsdatenbank hat Schema 30; `foreign_key_check` meldet keine
Verletzungen. Es gibt dort noch keine Finanzkonten, Finanzbelege, Umsätze,
Beitragsrechnungen, Aufnahmeversand- oder Beitragsversandvorgänge. Für `bank`,
`club` und `mail` existieren keine gespeicherten Einstellungssätze. Die
Standardwerte liefern einen Vereinsnamen, aber keine vollständige Anschrift
und keine Bankverbindung für Rechnungen.

Der gelesene Worker-Status vom 06.09.2026, 08:07:47 MESZ meldet `verein_daily:
ok`, `newsletter_delivery: idle` und `newsletter_automation: not_due`.
Das belegt einen erfolgreichen beobachteten Lauf; es beweist weder dauerhafte
Verfügbarkeit noch die Zustellung einer konkreten E-Mail. Kein Worker wurde
für diese Prüfung manuell gestartet. Live wurden nur Quellen, Status und
aggregierte Datenbankwerte gelesen, keine Nachrichten oder Zahlungen ausgelöst.

## Bestätigte Lücken und Folgen

| Priorität | Lücke | Konkrete Folge | Quellbeleg |
|---|---|---|---|
| Hoch | Automatische Umsatzzuordnung fehlt | Es gibt `allocate` und manuelle Suchlisten, aber keine automatische Erkennung anhand einer Rechnungsreferenz. Ein vorhandener Umsatz bleibt bis zur manuellen Zuordnung offen. | `src/PaymentMatching.php:58-107`, `views/Finance/Accounting/transaction.php:8-15` |
| Hoch | Bankimport ist noch nicht angebunden | FinTS ist installiert, liefert aber noch keine Umsätze. Ohne Import oder manuelle Erfassung steht nichts zum Abgleich bereit. Die FinTS-Registrierung bleibt wie vereinbart ausstehend. | `banking/bin/check.php`, `src/Finance.php:7`, `src/PaymentMatching.php:58-80` |
| Hoch | Bankverbindung und Finanzkonto sind getrennt | `club_settings.bank` versorgt Rechnungen; `finance_accounts` hat nur Name, Art, Anfangsbestand und Eröffnungsdatum. Keine Verknüpfung der Rechnungs-IBAN mit einem Finanzkonto. Live fehlt beides. | `src/SettingsSchema.php:27`, `src/ContributionInvoices.php:40-47`, `migrations/003_finance.sql:5-9` |
| Mittel | Bearbeitbare Mailtexte sind nur teilweise angebunden | Das Rechnungs-PDF liest Beitrags-Textbausteine. E-Mail-Betreff und Mailtext der Aufnahmebeitragsrechnung und des Jahres-/Erinnerungslaufs stehen dagegen fest im Code. | `src/ContributionInvoices.php:49`, `src/MembershipWelcome.php:71-73`, `src/ContributionReminders.php:121-126` |
| Mittel | Spender ist kein eindeutig verknüpfter Kontakt | Geldspenden speichern `counterparty`, Sachspenden `donor` als Text. Die Zahlungszuordnung zum Beleg ist möglich, aber daraus entsteht keine belastbare Zuordnung zum Mitglieder-/Kontaktprofil. Gleiche Namen reichen nicht für eine automatische Personenverknüpfung. | `migrations/003_finance.sql:10-19,29-34`, `src/DonationForms.php:30` |
| Mittel | Zuwendungsentwurf nutzt den Beleg statt des Zahlungseingangs | Auch ein offener Geldspendenbeleg kann als Quelle dienen. Betrag und Datum werden aus Forderungsbetrag und Belegdatum vorbelegt, nicht aus dem tatsächlich zugeordneten Zahlungseingang. Die Ausgabe ist ausdrücklich als unterschriftsloser Entwurf markiert; eine abgeschlossene automatische Bescheinigung liegt nicht vor. | `src/DonationForms.php:16-31,64-72` |


## Nachweislich vorhandene Verknüpfungen

- **Umsatz → Beitrag/Spende/Beleg:** Manuelle Zuordnung mit Teilbeträgen,
  Aufteilung eines Umsatzes, Zahlung aus mehreren Konten, verbleibendem Rest,
  Schutz vor Überzuordnung und nachvollziehbarem Aufheben einer falschen
  Zuordnung. Die Zuordnung selbst bucht keinen zweiten Geldeingang.
- **Beitrag → Rechnung → Zahlung:** Aufnahme und Jahreslauf erzeugen
  Beitragsforderungen mit festem Zahler und Rechnungs-PDF. Die Rechnung liest
  die Vereinsbankverbindung. Fehlende Anschriften oder Bankdaten halten sie
  als Entwurf zurück. Zugeordnete Zahlungen beeinflussen den offenen Betrag
  und die Erinnerung; Familienangehörige erhalten keine doppelte Forderung.
- **Antrag → Newsletter:** Nur bestätigte Anträge mit ausdrücklicher
  Newsletter-Auswahl gelangen in die Newsletter-Warteschlange. Sie verwendet
  denselben `NewsletterService` und Empfängerbestand wie das öffentliche
  Newsletterformular. Bestehende bestätigte Abonnements bleiben bestehen;
  sonst wird ein separater Newsletter-Bestätigungslink angefordert.
- **Kontakt → Newsletterstatus:** Aktueller Abgleich anhand der E-Mail-Adresse,
  ohne Empfängerlisten zu kopieren oder Kontakte automatisch anzumelden.
  Geteilte Familienadressen werden kenntlich gemacht. Der Abgleich benötigt
  zusätzlich die zentrale `newsletter-lookup`-Berechtigung oder Superuser.
  Die Rechte eines gewöhnlichen Live-Mitgliederverwalters wurden nicht mit
  einer echten Sitzung geprüft.
- **Aufnahme → Mitglied → Login:** Die bestätigte Aufnahme verknüpft Kontakt,
  Mitgliedschaft und Begrüßungsversand. Die Kontoanlage erzeugt nur ein
  persönliches Mitgliedskonto. Existiert bereits ein Konto mit gleicher
  E-Mail, wird eine ausdrückliche Zuordnung verlangt; gesperrte Konten werden
  nicht automatisch reaktiviert.

## Prüfung und Grenzen

500 bestehende gezielte Prüfungen bestanden: Zahlungszuordnung 53, Finanzen
60, Finanzberichte 44, Finanz-Leseansichten 45, Aufnahmeversand 37,
Mailadapter 11, Identitätszuordnung 17, Antragsbestätigung 18,
Beitragsläufe 45, Mitgliedschaft/Newsletter 29, Kontoanlage 26 und
Zuwendungsentwürfe 75.

Ausgeführt mit dem aktuellen Quellcode in einem separaten temporären
Verzeichnis, frischen Testdatenbanken und abgefangenen Mail-/Kontodiensten.
Keine echte Nachricht, Bankanfrage oder Live-Testbuchung. Ein Testhelfer für
die Finanzansicht wurde um den Datenbankkontext ergänzt, den der echte
Dispatcher bereits bereitstellt. Produktcode wurde in dieser Prüfung nicht
verändert. Tests bestätigen die vorhandenen Funktionen, nicht die oben
fehlenden Integrationen. Es war kein vollständiger Website- oder
Sicherheitsaudit und kein Zustelltest mit echten Empfängern.

## Nächste Umsetzung

Zuerst Finanzkonto und Rechnungsbankverbindung eindeutig verbinden und die
noch fehlenden Vereins-/Rechnungsdaten übernehmen. Danach eine gemeinsame
Umsatzverarbeitung für manuelle Erfassung und späteren Bankimport: Nur bei
eindeutiger Rechnungsreferenz und passenden Betrags-/Richtungsprüfungen
automatisch zuordnen; alle übrigen Fälle in eine sichtbare Liste „Ungeklärt“.
Name oder Betrag allein sollten keine automatische Zuordnung auslösen.
Freie Spenden ohne vorhandenen Beleg benötigen eine bewusste Klassifizierung
und Kontaktzuordnung. Anschließend Versandkonfiguration und Mailtexte mit
den tatsächlich benutzten Diensten verbinden und Zuwendungsentwürfe an die
belegten Zahlungen koppeln.
