# Finanzabläufe: Umsetzung und Prüfstand vom 8. September 2026

Dieser Bericht beschreibt den lokalen Entwicklungsstand. Die Tests verwenden ausschließlich synthetische Vereine, Empfänger und Beträge. Es wurde dafür weder eine echte Rechnung ausgegeben noch eine E-Mail versandt oder eine Bankbewegung ausgelöst. Veröffentlichung und Betriebsprüfung werden getrennt dokumentiert.

## Ausgangsrechnungen

Unter **Finanzen → Belege & Spenden → Ausgangsrechnungen** ist der Ablauf in vier sichtbare Schritte gegliedert:

1. Empfänger, Leistung, einzelne Positionen, Mengen, Einheiten, Brutto-Einheitspreise, Umsatzsteuer und Termine erfassen.
2. Den Entwurf speichern und das vollständige PDF prüfen. Der Entwurf hat noch keinen Finanzbeleg und keine Rechnungsnummer.
3. Die geprüfte Rechnung ausdrücklich ausgeben. Nummer, Finanzbeleg, Original-PDF, Empfänger- und Vereinsdaten werden gemeinsam gespeichert. Zwischen Prüfung und Ausgabe geänderte Vereinsdaten verlangen eine erneute Prüfung.
4. Falls gewünscht, einen gesonderten Mailauftrag für den gespeicherten Empfänger erteilen. Die Ausgabe allein versendet nichts.

Eine ausgegebene Rechnung lässt sich nicht über die allgemeine Belegerfassung nachträglich in Preis, Text oder Umsatzsteuer verändern. Kontierung und tatsächliche Zahlungszuordnung bleiben möglich. Versionsprüfung, Mandantenbindung, Schreibrechte und Schutz gegen doppelte Ausgabe gelten auch serverseitig.

Die Rechnungsbankverbindung wird aus den Vereinsdaten übernommen und muss einem aktiven Finanzkonto entsprechen. Eine widersprüchliche Kontenauswahl wird vor Ausgabe abgefangen. Die Oberfläche nutzt größere Felder, zusammengehörige Abschnitte und konkrete Hauptaktionen. Weitere Positionen lassen sich hinzufügen und entfernen; das verbleibende Formular bleibt erhalten.

## Storno, Gutschrift und Versandnachweis

- Eine vollständig unbezahlte Rechnung kann mit eigenem Korrekturdokument und eigener Nummer storniert werden. Das Original bleibt erhalten. Ein noch wartender Versandauftrag des Originals wird beendet.
- Für bereits bezahlte Beträge ist eine getrennte Gutschrift mit Grund, Datum, Originalverweis und eigener Nummer möglich. Vorherige Gutschriften werden berücksichtigt; die Summe kann den tatsächlich zugeordneten Zahlungseingang nicht übersteigen.
- Gutschriften erzeugen eine offene Erstattungsverpflichtung. Die Software erfindet dafür keine Bankzahlung. Die tatsächliche Erstattung wird anschließend separat zugeordnet.
- Teilbeträge werden centgenau auf die ursprünglichen Positionen verteilt. Auch viele Ein-Cent-Positionen und mehrere aufeinanderfolgende Gutschriften behalten exakt die ursprünglichen Summen.
- Versandaufträge binden Empfänger und PDF-Hash. Der Worker prüft Rechte, Rechnungsstand und tatsächliche PDF-Bytes vor dem Versand erneut. Ein unsicheres SMTP-Ergebnis wird als unklar festgehalten und nicht automatisch wiederholt.

**Grenze:** Der automatische DATEV-Export einer umsatzsteuerpflichtigen Erlösgutschrift ist gesperrt. Die Erstattung darf nicht als normale Ausgabe mit abziehbarer Vorsteuer exportiert werden. Die Erlös- und Umsatzsteuerkorrektur braucht hierfür noch einen eigenen Exportvertrag. Eine teilweise Minderung einer noch unbezahlten Rechnung und ein elektronisches CreditNote-Format sind in diesem Ablauf ebenfalls nicht ergänzt.

## XRechnung und ZUGFeRD

Der vorhandene UBL-XRechnung-Export unterstützt zusätzlich tatsächliche Mengen und Einheiten, teilweise sowie vollständig bezahlte Rechnungen und begründete Steuerbefreiung. Zugeordnete Zahlungen werden als `PrepaidAmount` und der verbleibende Betrag als `PayableAmount` ausgegeben. Die Preisbasis bezieht sich auf die tatsächliche Menge, wodurch keine wiederkehrenden Rundungsfehler bei Netto-Einzelpreisen entstehen. Bei Steuerbefreiung wird die Kategorie E mit einer ausdrücklichen Rechtsgrundlage verwendet; allein „0 %“ reicht nicht.

Vier neue Varianten wurden durch den echten KoSIT-Validator mit XRechnung-Konfiguration 3.0.2, Stand 31. August 2026, akzeptiert: mehrere Einheiten, Teilzahlung, Vollzahlung und Steuerbefreiung. Das ist eine technische Formatvalidierung, keine Feststellung der steuerlichen Zulässigkeit des konkreten Geschäftsvorfalls.

**ZUGFeRD ist nicht neu implementiert.** Das aktuelle ZUGFeRD/Factur-X-Format benötigt CII-XML und einen gültigen PDF/A-3-Container. Eine in ein beliebiges PDF eingebettete UBL-Datei wäre kein korrektes ZUGFeRD. Im geprüften lokalen Bestand liegt keine vollständig getestete PDF/A-3-Erzeugungs- und Validierungskette vor.

Primärquellen:

- [Peppol: Vorausgezahlter Betrag](https://docs.peppol.eu/poacc/billing/3.0/syntax/ubl-invoice/cac-LegalMonetaryTotal/cbc-PrepaidAmount/)
- [Peppol: Rechtsgrund bei Steuerbefreiung](https://docs.peppol.eu/poacc/billing/3.0/rules/ubl-tc434/BR-E-10/)
- [Peppol: Preis und Mengenbasis](https://docs.peppol.eu/poacc/billing/3.0/syntax/ubl-invoice/cac-InvoiceLine/cac-Price/)
- [FeRD: ZUGFeRD/Factur-X](https://www.ferd-net.de/en/standards/zugferd/factur-x)
- [FeRD: technische Fragen](https://www.ferd-net.de/faqs/technisches-zur-e-rechnung)

## Konten und DATEV 2025

Finanzkonten besitzen jetzt dauerhaft gespeicherte Einstellungen für Aktivität und Steuerkonto. Ein deaktiviertes Konto ist für neue Buchungen beziehungsweise Zahlungen nicht auswählbar; historische Vorgänge, Salden und Exporte bleiben verfügbar. Ein gespeichertes Steuerkonto ist die Voreinstellung für den DATEV-Export.

Die Steuerschlüssel für 2025 wurden aus der tatsächlichen offiziellen Excel-Datei eingelesen, nicht aus dem 2026er Katalog rückdatiert. Quelle ist das DATEV-Dokument 0907054, dessen öffentlich verlinkte Datei am 8. September 2026 über den Browser heruntergeladen wurde.

- [DATEV: Verfügbare Steuerschlüssel 2025](https://wissensplattform.apps.datev.de/help/document/0907054)
- [Offizielle Excel-Datei](https://wissensplattform.apps.datev.de/api/amr/knowledge-common/v1/entities/st81064837314243723_de.xlsx)
- Größe: 39.517 Bytes; SHA-256: `b6a71ea6156c55d801b404d182deb89efe36240883afe9ab3f7571611a382fd7`.
- Import: `vereinsverwaltung/tools/import-datev-keys-2025.py`; Daten: `vereinsverwaltung/data/datev-keys-2025.json` mit Herkunft und ursprünglicher Tabellenzeile.
- 289 Schlüssel sind als Nachschlagebestand enthalten. Automatisch exportierbar sind ausschließlich die acht konkret geprüften Inlandsregeln 2, 3, 8, 9, 101, 102, 401 und 402. Andere Regeln werden durch ihre bloße Existenz im Katalog nicht automatisch ausführbar.
- Noch nicht belegte Jahrgänge bleiben geschlossen. Die acht unterstützten 2025er Regeln wurden mit echten lokalen Belegen, Zahlungen und DATEV-CSV geprüft.

## Buchwerte und Abschreibung

Unter **Finanzen → Auswertungen & Steuer → Abschreibung & Buchwerte** können für eigene Sachanlagen Bemessungsgrundlage, Anschaffungsdatum, Nutzungsdauer, Restwert, Vereinsbereich und optionales Anlagekonto dokumentiert werden. Ein geschätzter Inventarwert wird nicht als steuerliche Bemessungsgrundlage übernommen.

Die Vorschau berechnet lineare Abschreibung ab dem Anschaffungsmonat, einschließlich dieses Monats. Die Centverteilung erhält den gesamten Abschreibungsbetrag und den festgelegten Restwert. Abgeschlossene Jahre können in zeitlicher Reihenfolge festgestellt werden. Planversion, Jahresanfangs-/Endwert, Abschreibung, Begründung, Bearbeiter und Zeitpunkt bleiben nachvollziehbar. Festgestellte Jahre sind nicht nachträglich überschreibbar; ein zugrunde liegender festgestellter Plan wird nicht still verändert.

Unterstützt wird die lineare Methode. GWG/Sofortabschreibung, Sammelposten, degressive oder Sonderabschreibung sowie nachträgliche Änderung eines bereits festgestellten Plans benötigen weitere fachliche Abläufe. Es erfolgt keine automatische Bankbuchung oder Abgabe an ELSTER.

Primärquellen: [§ 7 EStG](https://www.gesetze-im-internet.de/estg/__7.html), [§ 6 EStG](https://www.gesetze-im-internet.de/estg/__6.html).

## Integration und Datenhaltung

Migration 069 legt sieben zusätzliche Tabellen an: Kontoeinstellungen, Ausgangsrechnungen, Rechnungshistorie, Versandaufträge, Abschreibungspläne, Planhistorie und festgestellte Jahresstände. Sie ersetzt keine vorhandenen Konten, Dokumente oder Finanzdaten.

`FinanceWorkflowActions::handle()` wird erst nach der bestehenden authentifizierten CSRF-Prüfung aufgerufen. `OutgoingInvoices::dispatch()` wird ausschließlich über den bestehenden kontrollierten Worker aufgerufen; Oberflächenaktionen leeren keine Queue. `Finance::createBankTransactionLocked()` ist für den Bankimport innerhalb einer bereits offenen Transaktion vorgesehen und prüft Schreibrecht sowie aktives Konto vor Einfügen und Zuordnung.

## Verifikation

Die Anzahlen sind nicht zu einer Gesamtsumme zu addieren: Erweiterungssuiten führen teilweise die gemeinsame Basissuite mit aus.

| Test | Ergebnis |
|---|---:|
| `finance-workflows.php` | 33 Prüfungen bestanden |
| `outgoing-invoice-safety.php` einschließlich Basis | 47 bestanden |
| `outgoing-einvoice-extended.php` einschließlich Basis und vier echten KoSIT-Läufen | 44 bestanden |
| bestehende `outgoing-einvoice.php` | 20 bestanden |
| `datev-2025.php` | 13 bestanden |
| `catalog-years.php` | 31 bestanden |
| bestehende `datev-export.php` | 15 bestanden |
| bestehende `accounting.php` | 55 bestanden |
| echte Browser-/HTTP-Speicherwege `finance-workflows-browser.cjs` | 12 bestanden |
| bestehende `pdf-preview.py` / `letter-documents.php` | 17 / 13 bestanden |

Die Browserprüfung erfasst neue Rechnung, Hinzufügen/Entfernen von Positionen, Entwurf, erneutes Laden der Menge, PDF-Prüfung, Ausgabe, ausdrückliche Versandaktion ohne deren Ausführung, mobile Darstellung, gespeicherten Abschreibungsplan und Kontodeaktivierung. Die abschließenden Desktop-/Mobilbilder liegen privat unter `.local/finance-workflow-5cb09e5a4e/`. Rechnung und Gutschrift wurden zusätzlich als PDF gerendert und visuell geprüft; die privaten Prüfdateien enthalten ausschließlich synthetische Geschäftsdaten.

Ein unabhängiger begrenzter Code-Review fand zunächst zwei konkrete Fehler bei Centverteilung und wiederholtem Korrektur-POST. Beide sind behoben und durch Regressionen abgedeckt. Der Nachreview meldete im gelesenen Normalpfad keine weiteren konkreten Befunde; dies ersetzt keine unabhängige vollständige Neuausführung aller Tests oder eine allgemeine Sicherheitszertifizierung.
