# Inventarprüfung und Einordnung der Funktionsabdeckung · 08.09.2026

Diese Teilprüfung lief gegen den aktuellen lokalen Vereinsmanager auf Port 8091 mit einem neu erzeugten, ausschließlich fiktiven Verein. Sie ist keine Aussage, dass jeder denkbare Geschäftsfall und jede Liveintegration vollständig geprüft wurde. Der übergreifende PHP-Testlauf und die vollständige Desktop-/Mobil-Menüprüfung werden im Hauptprüfbericht getrennt zusammengeführt.

## Durchgeführte Änderungen und Nachweis

`inventory-flows-browser.cjs`: **15/15 bestanden**. Die Aktionen wurden im Browser ausgelöst, tatsächlich per HTTP gespeichert, erneut per GET geöffnet und gegen die lokale Datenbank gelesen. Ein vollständiger Ablauf führte von zwei reservierten Positionen über Ausgabe, neue Frist und Einsatzort zur Teilrückgabe an ein anderes Lager und abschließenden Rückgabe. Ein beschädigter Satz blieb gesperrt. Bestandszahlen, vollständige Sätze, Verlauf und abgeschlossener Status stimmten.

Die behobenen Fehler:

- Abgewiesene Ausgabe-, Storno-, Ortsänderungs- und Friständerungsformulare verloren zuvor ihre Eingaben. Die passende Aktion erhält jetzt Datum, Ort und Vermerk; geklappte Abschnitte bleiben beim Fehler offen. Jeder Fall wurde mit einem echten veralteten Versions-POST und unveränderter DB geprüft.
- Die Ausleihhistorie zeigte technische Benutzerkennungen. Sie verwendet jetzt dieselbe vereinsbezogene Namensauflösung wie der Bestandsverlauf.
- „Offene Ausleihen“ zählte nur ausgegebene Ausleihen und öffnete alle Vorgänge. Die Karte zählt jetzt Reservierungen plus Ausgaben und öffnet ausschließlich offene Vorgänge.
- Der Kategoriefilter behauptete, dort neue Kategorien mit einem Gegenstand zu speichern. Er erklärt jetzt nur Auswahl und Filterung; keine Treffer werden als fehlende Kategorie beschrieben. Anlagefelder behalten ihren eigenen Hinweis.
- „Mindestbestand 0“ wird nicht bei jedem Gegenstand wiederholt. Ein tatsächlich eingestellter Mindestbestand bleibt sichtbar.

Die Tabellen enthalten weiterhin alle Bestandswerte. Ein breiterer Umbau ihrer Spalten wurde in dieser Teilprüfung nicht vorgenommen. Alle geprüften Inventarlisten, die Reservierungsmaske und die laufende Ausleihe blieben bei 390px ohne horizontalen Seitenüberlauf; breite Tabellen scrollen innerhalb ihres Bereichs. Es traten keine JavaScriptfehler auf. Finanzbuchungen, Transaktionen, Dokumente und Beleglinks blieben unverändert. Es wurden keine Mails gesendet.

## Modulmatrix

„Testquellen vorhanden“ bedeutet nur, dass diese Tests im Repository vorhanden sind. Deren aktuelle Gesamtergebnisse gehören zum separaten Hauptlauf. Ein aufrufbares Menü oder eine erfolgreiche GET-Antwort beweist kein vollständiges Anlegen/Bearbeiten/Speichern eines Moduls.

| Menübereich / Funktion | In dieser Teilprüfung tatsächlich geprüft | Ergänzende Testquellen vorhanden; hier nicht erneut vollständig ausgeführt |
| --- | --- | --- |
| Übersicht und Menü | Inventarkarten mit passenden Zahlen und Zielen. Zusätzlich 69 existierende Menüziele per GET erfasst; vollständige Rollen-/Mobilprüfung durch den separaten Menütest. | `manager-navigation.php`, `manager-routes-browser.cjs`, `workspace-access.php`, `people-navigation.php`, `finance-navigation.php`, `settings-navigation.php` |
| Mitglieder und Kontakte | Nur fiktive Kontakte als Entleiher und bearbeitende Person genutzt; keine neue Mitglieder-CRUD-Prüfung in dieser Runde. | `member-profile-browser.cjs`, `member-overview.php`, `member-import.php`, `member-archive.php`, `membership-start.php`, `legacy-member-matching.php` |
| Familien, Beiträge, Anträge, Zugang | Kein kompletter Aufnahme-/Beitrags-/Zugangsmailablauf in dieser Teilprüfung. | `families-and-application-pdf.php`, `application-family-linking.php`, `applications.php`, `admission-votes.php`, `manual-member-accounts.php`, `contribution-rates.php`, `contribution-reminders.php` |
| Geburtstage / Jubiläen | Nur Menüaufruf; keine Benachrichtigung ausgelöst. | `member-overview.php`, `portal-communication.php` |
| Inventarlisten / Wo ist was? | Live-Suche, Kategorie, Lagerort, leere Treffer, Mengeninhalt, offene Ausleihen und 390px. | `inventory-search.php`, `inventory-categories.php`, `inventory-loan-contents.php` |
| Gegenstandsanlage / Stücke / Umlagerung | In dieser Runde nur Ausgangsfixture und Anzeige; Formular-Roundtrips werden vom parallel zuständigen Formularprüfer durchgeführt. | `inventory-fields-browser.cjs`, `inventory-transfer-locations-browser.cjs`, `inventory-individual-items.php`, `inventory-initial-stock.php`, `inventory-piece-correction.php`, `inventory-deletion.php` |
| Ausleihe / Rückgabe | Vollständiger echter Reservierung→Ausgabe→Frist/Ort→Teilrückgabe→Abschluss-Ablauf, Storno und vier Fehler-POSTs. | `inventory.php`, `member-loan-form-state.php`, `inventory-loan-contents.php` |
| Lagerorte / Materialübersicht | Lagerorte im Fixture und Rückgabe an anderen Ort; kein gesonderter Lagerort-CRUD- oder Materialrollenlauf. | `inventory.php`, `http-inventory.py` |
| Vereinsvermögen / Rechnungszuordnung | Sicherstellung, dass Ausleihvorgänge keine zusätzlichen Finanzbuchungen erzeugen. Kein kompletter Anschaffungs-/Wert-/Rechnungslauf. | `inventory-accounting.php`, `inventory-acquisition-lifecycle.php`, `inventory-invoice-integration.php`, `inventory-invoice-upload.php` |
| Rechnungseingang / Lieferant / OCR / E-Rechnung | Nur Menüdarstellung. Kein neuer Scan-, Mail-, XML- oder Lieferantenablauf. | `invoice-inbox-browser.cjs`, `invoice-supplier-browser.cjs`, `invoice-recognition-workflow.php`, `einvoice-validation.php`, `einvoice-embedded.php`, `invoice-inventory-inline-browser.cjs` |
| Belege / Beiträge / Freigabe / Zahlungen | Keine Buchung oder Freigabe durchgeführt. | `finance.php`, `invoice-approvals.php`, `invoice-payment-reports.php`, `contribution-receipt-workflow.php`, `supplier-payment-matching.php` |
| Bank / FinTS / Enable Banking / SEPA | Die lokale Bankintegration ist nicht konfiguriert. Keine reale Kontofreigabe, TAN, Synchronisation oder Zahlung geprüft. Ein eigener Bankportalaufruf wurde beim Inventar-Admin ohne Mitgliedschaft mit 403 abgewiesen. | `enable-banking.php`, `bank-preview.php`, `member-bank-vault.php`, `member-bank-superuser.php`, `supplier-bank.php`, `iban.php` |
| Spenden / Zuwendungen | Nur Menüdarstellung; kein neuer amtlicher Beleg erstellt oder versandt. | `donation-forms.php`, `donation-auto-drafts.php`, `donation-notice-types.php`, `donation-receipts.php`, `pdf-donation-forms.py` |
| Berichte / Export / Steuer / ELSTER | Nur Menüdarstellung; keine neue Steuerprüfung und keine ELSTER-Übermittlung. | `datev-export.php`, `finance-report-export.php`, `tax-preparation.php`, `tax-review-queue.php`, `tax-declaration-guide.php` |
| Dokumente / Fristen / Nachweise | Inventarablauf verändert vorhandene Dokumentdaten nicht. Kein Mehrfachupload oder Fristen-CRUD in dieser Runde. | `document-batch.php`, `document-checks.php`, `document-protection.php`, `document-link-form-state.php`, `live-uploads.php` |
| GEMA | Nur Menüdarstellung; keine Meldung, Rechnung oder Setlist erzeugt. | `gema-details.php`, `gema-setlist.php`, `gema-invoice-form-state.php`, `gema-help-view.php` |
| Freie Listen | Nur Menüdarstellung, keine eigenen Tabellen bearbeitet. | `free-tables.php` |
| Kalender / Protokolle | Nur Menüdarstellung, kein Termin oder Protokoll gespeichert. | `calendar-invitations.php`, `calendar-visible-range.php`, `event-workflows.php`, `minutes.php` |
| Chat / Rundmails / Briefe / Push | Nur Menüdarstellung, keine Nachricht oder Mail gesendet. | `chat-extensions.php`, `club-mailings.php`, `mail-fields-attachments.php`, `letter-documents.php`, `push-notifications.php` |
| Sicher helfen / Ehrenamtsbestätigung / Unterschrift | Nur Menüdarstellung, keine Unterschrift verarbeitet und keine Bestätigung erstellt. | `volunteer-form.php`, `volunteer-address.php`, `volunteer-self-service.php`, `board-signature.php` |
| Einstellungen / Rollen / SMTP / Vorlagen | Nur Menüdarstellung. Keine echten Zugangsdaten oder Rollen geändert. | `role-editor-http-browser.cjs`, `role-editor-save.php`, `settings-form-state.php`, `mail-channel-settings.php`, `purpose-mail-transport.php`, `member-bank-settings.php` |
| Handbuch | Kapitel15 an aktuelle Formulare angepasst; vier lokale synthetische Ausschnitte mit erklärten Markierungen und neuer Bildprüfung. | `handbook-media.php`, `handbook-routes.php`, `handbook-browser.cjs`; endgültiger Gesamtbuild durch Hauptprüfung |
| Betrieb / Wiederherstellung / Veröffentlichung | Keine Änderung des lokalen Servers, kein Deployment und keine Wiederherstellung in dieser Teilprüfung. | `recovery-backup.php`, `recovery-verify.php`, `production-runtime.php`, `local-runtime.php` |

Die parallele Hauptprüfung ergänzt insbesondere die gesamte Menüprüfung als Admin und Mitglied auf Desktop und Mobilgerät sowie die aktuellen Resultate der vorhandenen sicheren PHP-Suiten. Nicht verfügbare externe Registrierungen, reale Bankzugriffe und Live-Mailversand sind dadurch nicht automatisch nachgewiesen.
