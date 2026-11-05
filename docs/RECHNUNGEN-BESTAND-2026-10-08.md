# Rechnungen, Bankzuordnung und Zahlungs-QR

Ergänzung zum Nutzerauftrag vom 8. Oktober 2026. Bestand des gemeinsamen Checkouts `eb9a/Kulturhus`, Quellcommit 8589636. Die vorhandenen Rechnungsfunktionen bleiben Bestandteil des nativen Vereinsmanagers und sollen nicht neu oder doppelt aufgebaut werden.

## Im aktuellen Code belegt

| Gewünschte Funktion | Vorhandene Implementierung | Grenze / nächste Abnahme |
|---|---|---|
| Rechnung anlegen und anpassen | `src/OutgoingInvoices.php`: `save`, `review`, `issue`, Positionen, gespeicherte Vereins-/Steuer-/Bankangaben und Versionsschutz | Entwurf änderbar. Ausgestellte Rechnung nicht überschreiben; Korrektur über Storno/Gutschrift. Änderungen zwischen Prüfung und Ausstellung erzwingen erneute Prüfung. |
| Rechnung versenden | `OutgoingInvoices::queue/dispatch`, `outgoing_invoice_deliveries`, archiviertes Dokument mit SHA-256, Empfänger aus der ausgestellten Fassung | Versand hängt am eingerichteten Transport/Worker. Unklares Transportergebnis wird nicht automatisch wiederholt. Die neue CMS-Laufzeit braucht den funktionsgleichen eigenen Worker ohne zusätzliche CMS-Laufzeit-Pflicht. Kein echter Versand in dieser Bestandsaufnahme. |
| Original, Download und Mail identisch | `src/IssuedInvoiceFile.php`, `Documents` und `OutgoingInvoices` | Exakte validierte Bytes als Original speichern; späteres Rendern darf die versandte Fassung nicht austauschen. |
| Elektronische PDF-Rechnung | `IssuedInvoiceFile::build`: PDF/A-3b mit eingebetteter XRechnung-CII, KoSIT- und Hybridprüfung. `CiiInvoice`, `HybridEInvoice`, `OutgoingEInvoice`, `tools/build-hybrid-invoice.py` | Nicht nur ein optisch normales PDF. Benötigt eingerichtete private Python-/Java-/KoSIT-/Mustang-/veraPDF-Laufzeiten. Unterstützte Länder-/Rechnungsfälle und Pflichtfelder beachten. Aktueller Nachweis ist insbesondere `DOKUMENTE-RECHNUNGEN-20260928.md`; der Bericht vom 9. September beschreibt einen älteren, engeren Entwicklungsstand. |
| Zahlung mit Rechnung verbinden | `Finance`, `PaymentMatching`, `AutomaticMatching`, `Finance::importTransaction` | Der Import ruft `autoMatchLocked` auf. Zuordnung verändert nicht nochmals den Geldfluss. Vorhandene Zuordnung, Storno, Richtung und Restbetrag werden berücksichtigt. |
| Automatisch zuordnen | `AutomaticMatching::autoMatchLocked`: eindeutige Belegnummer im Verwendungszweck, Betrag und Richtung; zusätzlich bestimmte Mitglieds-/Lieferantenfälle | Nur eindeutige passende offene Beträge werden automatisch zugeordnet. Mehrdeutige Namen/Belegnummern, Teilzahlungen oder mehrere mögliche Forderungen bleiben zur Klärung. Manuell aufgehobene Zuordnung wird nicht automatisch wiederhergestellt. Kein Bankabruf durch den Matching-Dienst selbst. |
| Bankkonto wählen | `OutgoingInvoices::issuer/validateBank` und Finanzkonten-/Bankkonfiguration | Rechnungs-IBAN, internes Finanzkonto und Zuordnung zum echten Konto als End-to-End-Fall prüfen. Eine IBAN im Briefpapier allein ist kein Bankabruf. |
| Überweisungs-QR auf Rechnung | Vorhandene QR-Bibliothek wird von `EventVoucherQr` für Veranstaltungsgutscheine verwendet. `tools/render-document.py` setzt den Überweisungszweck als Text. | **Kein eingebauter EPC-/GiroCode im geprüften Ausgangsrechnungs- und Beitragsrenderer gefunden.** Die vorhandene QR-Bibliothek ist ein wiederverwendbarer Baustein, aber kein Beleg für einen fertigen Zahlungs-QR. |

## Abnahme des Zahlungs-QR

Als neue beziehungsweise noch nicht nachgewiesene Funktion führen: Empfängername, IBAN, gegebenenfalls BIC, EUR-Betrag und Rechnungsnummer aus der **gleichen eingefrorenen Rechnungsfassung** in einen EPC-/SEPA-QR übernehmen. Eingaben entsprechend dem aktuellen EPC-Format validieren; nicht einfach den Gutschein-QR oder eine Webadresse verwenden. QR nach Erzeugung unabhängig decodieren und sämtliche Zahlungsdaten gegen PDF und eingebettete XML vergleichen. Anschließend mit einer unterstützenden Banking-App prüfen, ohne Zahlung auszulösen. Ausdruck, kleine Displays und Fehlerfälle einschließen.

Ein Zahlungs-QR bestätigt keine Zahlung. Bezahlt wird eine Rechnung erst durch die zugeordnete echte Kontotransaktion beziehungsweise den bestehenden dokumentierten Zahlungsvorgang. Bereits ausgestellte Originale werden durch eine spätere QR-Erweiterung nicht rückwirkend verändert.

## Pflichtablauf für den Erhalt beim CMS-Wechsel

Entwurf mit Positionen und gewünschtem Konto → anpassen → prüfen → ausstellen → Original-PDF/XML validieren und archivieren → Versandvorschau/Bestätigung → Testtransport mit exakt gleichem Anhang → synthetischen Kontoumsatz importieren → eindeutige automatische Zuordnung → bezahlten/offenen Rest anzeigen → Mehrdeutigkeit, Teilzahlung, doppelte Einlieferung und Storno testen.

Vorhandene Tests: `tests/finance-workflows.php`, `outgoing-invoice-safety.php`, `matching.php`, `supplier-payment-matching.php`, `outgoing-einvoice.php`, `outgoing-einvoice-extended.php`, `hybrid-einvoice.php`, `hybrid-einvoice-reuse.php` sowie Python-Tests. Diese Rechnungssuiten wurden in dieser Bestandsaufnahme **nicht erneut vollständig ausgeführt**; die beschriebenen Fähigkeiten sind durch Quellprüfung und frühere Berichte belegt. Der jüngste Navigationslauf prüfte Finanzformular-Initialisierung, nicht den vollständigen Rechnungsversand oder die Rechnungsvalidierung.
