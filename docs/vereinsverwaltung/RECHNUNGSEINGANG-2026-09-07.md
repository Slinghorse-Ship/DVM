# Rechnungseingang und Begleit-E-Mail

Finanzen besitzt den Tab Rechnungseingang. Er zeigt leseberechtigte hochgeladene Eingangsbelege im Finanzbereich; keine neue Tabelle und keine automatische Buchung. Zustände werden aus Dokumentstatus und Finanzbelegverknüpfungen abgeleitet: offen, zugeordnet, archiviert. Mailherkunft kommt aus `invoice_mail_imports` und den unveränderten RFC822-Originalen.

`Documents::linkExpense` arbeitet entweder in eigener Transaktion oder im bestehenden `Finance::createEntry`-Callback. Dokument- und Ausgaberechte, Versionen, aktive Ausgabe und unverknüpftes Original werden geprüft. Ein Zuordnungsfehler rollt eine gerade angelegte Ausgabe zurück. Derselbe bereits vorhandene Link ist idempotent; ein anderer Finanzbeleg wird nicht stillschweigend überschrieben. Schutzbereiche wachsen um finance, Versionszähler und Dokumenthistorie dokumentieren die Änderung. Keine Freigabe oder Zahlung wird erzeugt.

Ausblenden/Wiederherstellen verwendet die vorhandene Dokumentarchivierung mit Versionsprüfung und Pflichtgrund. Zugeordnete Finanzoriginale sind im Eingang davon ausgeschlossen. Keine physische Löschung, keine IMAP-Mutation und kein Expunge. Anhänge werden einzeln behandelt, nicht pauschal je Nachricht.

`InvoiceMailView` liest die originale Nachricht nur nach Berechtigungsprüfung des Dokuments und aller anderen mit derselben Mail verknüpften Dokumente. Die SHA-256 wird geprüft. Auch der direkte EML-Download besitzt diese Prüfungen, liefert application/octet-stream mit attachment und dem vorhandenen nosniff/no-store. Ein vertraulicher zweiter Anhang sperrt die gesamte Maildarstellung und den gesamten Download. Es entsteht keine zweite Kopie des Originals.

Nurtextdarstellung: MIME-Header, Multipart, Base64 und Quoted-Printable; bevorzugt text/plain, ersatzweise HTML als reiner Text. Alle Ausgaben durchlaufen HTML-Escaping; kein HTML wird gerendert, keine externen Ressourcen geladen. Maximal die ersten 2 MB der Originalmail werden für die Vorschau verarbeitet, höchstens 64 Teile, Tiefe 10 und 100000 Textzeichen. Eine große Nachricht wird als begrenzte Vorschau gekennzeichnet; das vollständige EML bleibt herunterladbar. Kein vollständiger MIME-Client und keine Signaturprüfung.

## Nachweise

- `tests/invoice-inbox.php`: 22 Prüfungen, Exit 0. Atomare neue Ausgabe, bestehende Ausgabe, Versionen, Wiederholung, Rechte, Fremdverein, Archiv und Rücknahme, keine automatische Buchung.
- `tests/invoice-mail-view.php`: 33 kombinierte Prüfungen einschließlich der 22 oben, Exit 0. MIME/HTML, entitätscodierte Tags, exaktes EML nach Zuordnung, Member-/Fremdvereinszugriff und vertraulicher Schwesteranhang.
- `tests/invoice-inbox-browser.cjs`: echter lokaler POST für neue Ausgabe und vorhandene Zuordnung, anschließend Ausblenden/Reload/Wiederherstellen, anfangs leeres Betragsfeld, 1280/390 px ohne Überlauf, Exit 0.
- `tests/invoice-mail-view-browser.cjs`: Nachricht im Eingang und zugeordneten Finanzbeleg, keine Scriptausführung oder externen Bildabrufe, exakter EML-Download mit attachment/nosniff, mobile Ansicht, Exit 0.

Alle Daten waren synthetisch. Keine Liveänderung, echte Mailabholung, Mailzustellung oder Zahlung durch diesen Teilauftrag. Der manuelle Abruf verwendet ausschließlich InvoiceMailbox::fetch; dessen Transport und automatischer Zeitplan bleiben ein gesondert geprüftes System.

Die Archivvoraussetzung wird zusätzlich als Guard innerhalb der von Documents::update gehaltenen BEGIN-IMMEDIATE-Transaktion geprüft. Eigene Regression bestätigt die gehaltene Schreibtransaktion und unveränderten Zustand/Version bei Guard-Abbruch.

## Direkte Vorschau und Upload

Im Rechnungseingang wird das bestehende PDF.js-Canvas direkt eingebunden; JPG/PNG verwenden den vorhandenen geschützten Bildabruf. XML wird niemals als Browser-XML oder HTML eingebettet. EInvoiceFields liest begrenzt und mit exakten Namespaces Rechnungsnummer, Datum, Währung, Zahlbetrag und vorhandene Namen; Werte bleiben ausdrücklich unbestätigte Dateiangaben. Der KoSIT-Prüfstatus bleibt separat sichtbar.

Der vorhandene LiveUploads-Mehrfachtransport hat eine feste Rechnungseingangsvariante: Dateien werden bei Auswahl/Reinziehen sofort temporär übertragen; Titel und Datum bleiben pro Datei bearbeitbar. Der Bestätigungsdialog nennt nur den Rechnungseingang, keine allgemeinen Ordner/Vorgänge. DocumentActions erzwingt serverseitig finance/receipt/keine Verknüpfungen unabhängig von manipulierbaren Formularfeldern. PDF/XML/JPG/PNG bleiben durch die bestehenden Upload-/MIME-/Größenprüfungen geschützt. Erst das bestätigte Ablegen speichert die Originale; noch keine Buchung oder Freigabe.

Nachweise: einvoice-fields.php 33 kombinierte Prüfungen (26 bestehende +7 Feldextraktion), Exit0. invoice-inbox-preview-browser.cjs rendert tatsächliches PDF-Canvas, JPG und XML-Angaben direkt im Eingang, 1280/390px fehlerfrei. invoice-inbox-upload-browser.cjs bestätigt automatische Übertragung von zwei Dateien, getrennte Titel und Datumswerte, Bestätigung und Rückkehr zum Rechnungseingang. Manipulierte scope/kind-Hiddenfelder wurden serverseitig ignoriert; beide Dateien landeten weiterhin im Rechnungseingang. Exit0, keine Liveaktionen.

## Strukturierte XML-Positionen

EInvoiceFields::details liefert zusätzlich items und items_truncated. Die Positionen enthalten number, name, quantity, unit, unit_price, price_base_quantity, net_amount, tax_rate, tax_category sowie ambiguous. Zahlen bleiben unveränderte Dezimalstrings aus der Datei. Keine implizite Bruttoberechnung oder Buchung; Preisbasen, Rundung, Zu-/Abschläge und Steuersonderfälle müssen vor einer Übernahme bewertet werden. UBL Invoice/CreditNote und CII werden über exakte Namespace-Pfade gelesen. Fremde Namespaces werden ignoriert, wiederholte Positionsfelder als mehrdeutig markiert, mehr als200Positionen als begrenzte Vorschau angezeigt.

Zusätzlicher Test einvoice-line-items.php:41 kombinierte Prüfungen (33 oben +8 Positionsfälle), Exit0. Browser zeigt aufgeklappte XML-Positionen neben geschützter PDF-/Bildvorschau bei1280/390px ohne Überlauf. PDF-/Bildrechnungen haben weiterhin keine OCR-Positionsauslesung; vorhandene Strukturangaben werden nicht erfunden.


## Abschlussreview der geführten Erfassung

XML-Vorschläge verwenden ausschließlich den ausdrücklichen Bruttorechnungsbetrag (UBL TaxInclusiveAmount / CII GrandTotalAmount), niemals den nach Vorauszahlungen verbleibenden Zahlbetrag. Der Betragsvorschlag erfordert EUR und eindeutig Rechnung: UBL Invoice bzw. CII Header-TypeCode 380. CreditNote / CII381, unbekannte und mehrdeutige Arten bleiben ohne Ausgabe-Betragsvorschlag. Die Vorschau bleibt verfügbar. Wiederholte Headerwerte werden sichtbar getrennt, nicht zu einer Zahl verkettet.

Abschließend erneut ausgeführt: einvoice-line-items.php 52 kombinierte Prüfungen, Exit0, einschließlich positiver Gutschriftbeträge und Vorauszahlungen in UBL/CII. invoice-inbox-guided.php 21 Prüfungen, Exit0. Geprüft wurden Lieferant ohne Mitglied, atomare Originalverknüpfung, veralteter Dokumentstand mit Rollback, unbekannter Zweck, unberechtigtes Mitglied, EUR-/Brutto-/Belegart-Grenzen und Wiederholung. Ein im unabhängigen Review gefundener veränderter Replay-Zweck konnte den GEMA-Callback erneut auslösen; die korrigierte Wiederholung prüft weiterhin Rechte und Originalzustand, führt bei bereits vorhandener Verknüpfung aber keine weitere Zweckmutation aus. Diese Ergänzung wurde separat erneut getestet. Keine Liveaktionen, Bankaufträge oder Mails.
