> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Bankdaten in Kontakt- und Mitgliederprofilen

Nachtrag: Der anschließend ausdrücklich freigegebene Superuser-Zugriff ist in [BANKDATEN-SUPERUSER-2026-09-08.md](BANKDATEN-SUPERUSER-2026-09-08.md) dokumentiert. Die folgenden Rechtebeschreibungen zeigen den Stand dieser ersten Veröffentlichung.

## Anlass und Bedienung

Lieferantenbankdaten wurden bisher bei der Firmenanlage aus dem Rechnungseingang gespeichert, konnten im normalen Firmenprofil aber nicht gepflegt werden. Der neue Profilbereich „Bankverbindung des Lieferanten“ zeigt IBAN/BIC mit Finanz- und Kontaktleserecht. Mit beiden Schreibrechten können Nutzer die aktuelle Verbindung speichern oder getrennt entfernen. Gespeicherte Rechnungen führen direkt zum Bereich. Die Lastschriftseite erklärt die beiden Ablageorte.

Mitgliederprofile enthalten jetzt „Bankverbindung & SEPA“ mit maskiertem Status und direktem Link zum geschützten Formular. Vollständige Werte werden erst durch die neue Aktion `reveal` nach Website-Passwort und gegebenenfalls dem bereits aktivierten zweiten Faktor in einer direkten POST-Antwort vorausgefüllt. Das Speichern verlangt eine neue, an die geänderten Werte gebundene Bestätigung.

## Datenspeicherung und Rechte

- Firmen: `supplier_bank_details`, aktuelle IBAN/BIC im Klartext in der privaten Dateidatenbank. Die Pflege verlangt Kontakt- und Finanzschreibrecht, Organisation und aktuelle Kontaktversion. Keine automatische Zahlung und keine Änderung an historischen Rechnungssnapshots.
- Mitglieder: `member_bank_records` und `member_bank_history`, AES-256-GCM mit getrenntem Schlüssel außerhalb des Webbereichs. Zugriff ausschließlich für das betroffene aktuelle Mitglied oder den aktuell bestätigten Kassenwart mit Finanzschreibrecht. Admin- und Vorstandsrechte allein erweitern diesen Zugriff nicht.
- Anzeige und Änderungen werden mit Akteur, Objekt, Version und Zeit protokolliert; Banknummern stehen nicht im Audit.
- Die Anzeige-Freigabe ist einmalig an Person, Aktion, Version, Sitzung und leere Anzeige-Nutzlast gebunden. Sie kann nicht als Schreibfreigabe verwendet werden. Keine vollständigen Werte in GET oder unverschlüsseltem Sitzungszustand.
- Die freigegebenen Formularfelder werden nach fünf Minuten und bei Seitenwechsel geleert. Der Browser-Zurück-Cache wird ebenfalls berücksichtigt. Ungespeicherte Eingaben gehen dabei verloren.

## Migration und Prüfung

Migration 062 erweitert ausschließlich die erlaubten Aktionen der Tabelle `member_bank_grants`. Die Tabelle wird innerhalb der bestehenden Migrationstransaktion rekonstruiert; alle Spalten einschließlich verbrauchter Freigaben, Primärschlüssel und Fremdschlüssel bleiben erhalten. Verschlüsselte Bankdaten, Historie und SEPA-Konfiguration werden nicht verändert.

Lieferantentests: 33 isolierte Backendprüfungen und sieben echte lokale Browserabläufe für Speichern, Ändern, Entfernen, Berechtigungen, veraltete parallele Formulare, Direktlink aus der Rechnung und 390-Pixel-Ansicht. Historische Rechnungen, Originale und Kontaktanzahl bleiben unverändert. Zusätzlich 13 bestehende Lieferanten-Zuordnungsprüfungen grün.

Mitgliederprüfung: 131 Einzelprüfungen (Vault 40, StepUp 19, Controller 13, Einstellungen 12, Anzeige/Migration 38, Browser 9) und ein vollständiger Controllerablauf. Die Passwort-/Code-Abläufe verwenden ausschließlich synthetische Daten und einen Test-Mailsink. Kein Versand an Mitglieder oder Vorstand, keine echte Bankaktion. Auch veraltete Mitgliederformulare behalten ihre ursprüngliche Version und überschreiben bei wiederholtem Speichern keine neueren Angaben.

Handbuch um die beiden Pflegeabläufe erweitert; 20 Kapitel, 242 Aufgaben und 35 bebilderte Anleitungen. 59 Hilferouten und 13 Medienprüfungen bestanden.

## Live-Veröffentlichung

Am 8. September 2026 veröffentlicht: Runtime-Commit `861b20d595de9ce54ed4a146bf42c98134d8759d`, Ausgangsstand `d00965b`. Vorab stimmten sämtliche betroffenen Live-Dateien mit der Git-Ausgangsversion überein. 16 Ziel-Dateien einschließlich öffentlicher Assetkopie nach Veröffentlichung bytegenau geprüft.

Vor der Umstellung wurden die bestehenden Dateien und eine Dateidatenbank-Sicherung unter `/tmp/kulturhus-verein/rollback-bank-details-20260908` abgelegt. Das globale Runtime-Gate blockierte neue HTTP-/CLI-Arbeit, eine 55-Sekunden-Wartephase und Worker-Lockprüfung ließen laufende Arbeit abklingen. Dies ist eine begrenzte Ablaufprüfung; die Hostingumgebung stellt keine vollständige FPM-Prozessübersicht bereit. Migration 61 → 62 erfolgreich; Zeilenhashes sämtlicher bestehender Banktabellen unverändert. Die vorbereitete Rücknahme arbeitet nur vor Wiederaufnahme und verweigert eine Freischaltung bei neuen Anzeige-Freigaben oder abweichenden Bankzeilen. Sie wurde isoliert getestet, live nicht benötigt.

Lesende Prüfung über tatsächliches PHP-FPM 8.3.33: Schema 62, Integrität `ok`, keine Fremdschlüsselfehler, neue Anzeige-API und Grant-CHECK geladen. 14 öffentliche Skript-/Style-Dateien entsprechen Git. Startseite, Anmeldung, Galerie und Programmarchiv antworten mit HTTP 200.

Angemeldete Live-Oberfläche geprüft: Lastschriftseite zeigt die Bankdatenhilfe; bestehender Firmenkontakt zeigt IBAN/BIC mit Speicher- und Entfernen-Knopf; Mitgliederprofil erklärt beim Administratorkonto korrekt den eingeschränkten Zugriff. Es wurden keine realen Bankdaten geändert, keine vollständigen Mitgliederbankdaten angezeigt und keine Bestätigungsmails ausgelöst. Eigentliche Schreib- und Passwort-/Code-Abläufe wurden ausschließlich lokal mit synthetischen Daten getestet.
