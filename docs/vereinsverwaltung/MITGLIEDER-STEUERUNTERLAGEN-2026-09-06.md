# Mitgliederzugang, alte Nummern und Steuerunterlagen – 06.09.2026

## Mitgliederdaten und Zuordnung

Im Profil kann eine optionale alte Mitgliedsnummer zusätzlich zur aktuellen Nummer gespeichert werden. Führende Nullen bleiben erhalten; die Mitgliedersuche berücksichtigt beide Nummern. Änderungen benötigen Schreibrecht und die aktuelle Profilversion. Andere Aufrufer, die nur Kontaktdaten ändern, erhalten die alte Nummer unverändert.

Die Zahlungszuordnung berücksichtigt Rechnungsnummern sowie aktuelle und alte Mitgliedsnummern. Ohne solche Treffer wird der vollständige Mitgliedsname im Verwendungszweck oder beim Zahlungspartner geprüft. Beim Zahlungspartner werden Groß-/Kleinschreibung, Satzzeichen und Namensreihenfolge normalisiert. Keine unscharfe Suche und kein Nachname allein. Automatisch wird nur genau ein vollständig offener Beitrag mit exakt passendem Betrag zugeordnet. Namensgleiche Mitglieder, doppelte Nummern und mehrere offene Jahresbeiträge bleiben manuell; auch Mitglieder ohne offene Beiträge werden zur Mehrdeutigkeitsprüfung berücksichtigt. Ausdrücklich aufgehobene Zuordnungen bleiben aufgehoben. Es wird kein Bankabruf ausgelöst.

## Körperschaftsteuer

Finanzen → Körperschaftsteuer & ELSTER erzeugt ein echtes ZIP mit vorhandenen Jahresauswertungen, Zahlungsjournalen, Geldkonten, Tätigkeitsbericht und ausgewählten Finanz-PDFs. Ein bis drei Jahre sind auswählbar. Finanzrechte gelten auch für Downloads; Dateien aus anderen Berechtigungsbereichen werden nicht übernommen. Das Paket ist eine Arbeitsunterlage, keine ausgefüllte oder übermittelte Steuererklärung. Direkte ERiC-Anbindung ist nicht implementiert; Entwicklerpaket und Laufzeitprüfung fehlen noch. Freistellungsbescheid muss weiterhin hinterlegt und geprüft werden.

## Prüfung


Migrationen 033 und 034 ergänzen die Kontoaufträge und die optionale alte Mitgliedsnummer. Ein vollständiger live durchgespielter neuer Mitgliedsantrag einschließlich Rechnung und Zugangsmail ist damit noch nicht belegt.

## Live-Verifikation

Am 06.09.2026 aus Commit `0809b60` veröffentlicht: 25 Laufzeitdateien gegen den vorherigen Live-Stand `ff3320e` geprüft, staged PHP-Syntaxprüfung und bytegleiche Rückleseprüfung. Privater Datenbanksnapshot vor Migration 32 → 34. Anschließend Schema 34, neues Profilfeld, gültige Fremdschlüssel, leere Kontoauftragswarteschlange und sechs reale Steuerpaketbestandteile schreibgeschützt geprüft. ZIP-Erweiterung über Server-CLI vorhanden. 32 öffentliche/Anmelde-/Gastzugriffs-/Dateischutz-HTTP-Prüfungen erfolgreich. Kein Bankabruf, keine neue Live-Kontoanlage und kein Mailversand durch die Veröffentlichung. Alte Nummern müssen aus Vereinsunterlagen eingetragen werden; bestehende Zahlungen wurden dabei nicht rückwirkend umgebucht.
