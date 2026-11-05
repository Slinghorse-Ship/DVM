# Rechnungsoriginale mit vorhandenem Inventar zusammenführen

## Ablauf und Abgrenzung

`InventoryInvoiceIntegration` übernimmt einen ausdrücklich geprüften Plan aus Originalbelegen. Die Vorschau prüft Berechtigungen, Dokumentrevision und SHA-256 des tatsächlichen Originals, Position, Bruttowert, Rechnungsdatum, Lagerort und aktuelle Gegenstands- und Bestandsversionen. Erst die Anwendung mit Vorschauhash und Vorgangskennung schreibt gemeinsam in einer Datenbanktransaktion. Dieselbe Kennung liefert bei Wiederholung das vorhandene Ergebnis. Dieselbe Originalposition unter einer neuen Kennung wird zurückgewiesen.

Vorhandene Mengen werden abgeglichen und nicht hinzugezählt. Seriennummern und bestehende Stückidentitäten bleiben erhalten. Anschaffungsdatum und Wertnachweise stammen aus den Originalen; abweichende Stückpreise innerhalb einer Gruppe bleiben einzeln dokumentiert. Die ursprünglichen Dateien und deren Revisionen bleiben unverändert. Die Änderung eines bisher falschen Dokumentdatums steht im Verlauf.

Die Übernahme erstellt weder Ausgaben noch Kontoumsätze oder Zahlungsbestätigungen und versendet keine Mails. Historische Belege müssen vor einer späteren Buchung mit der bisherigen Buchhaltung abgeglichen werden. Beim anschließenden Bearbeiten einer Rechnung verwendet der Rechnungseingang die bereits verbundenen Gegenstände; ein erneuter Bestand entsteht dadurch nicht.

## Pakete mit unbekannten Einzelpreisen

Ein Rechnungsartikel kann mehrere getrennt ausleihbare Gruppen ergeben. Ein gemeinsamer Paketpreis wird als Originalwert einmal gespeichert. Die Einzelwerte bleiben unbekannt, solange das Original keine Aufteilung enthält. Die Gesamtübersicht berücksichtigt den gemeinsamen Wert genau einmal, wenn der gesamte zugehörige Bestand unverändert in der Auswahl enthalten ist. Teilbestände, archivierte Bestandteile oder unvollständig verteilte Einzelwerte werden ausdrücklich als ungeklärter Wertanteil dargestellt. Sobald geprüfte vollständige Einzelwerte vorliegen, zählen diese statt des gemeinsamen Paketpreises.

Die Anzeige ist ein dokumentierter Anschaffungswert, keine steuerliche Aussage über Buchwert, Abschreibung oder selbstständig nutzbare Wirtschaftsgüter.

## Originaljahr und Vorsteuer

Kontierungen werden nach dem ursprünglichen Belegjahr geprüft. Verifizierte Jahreskataloge sind für 2025 und 2026 vorhanden. Für 2019, 2022 und 2024 wird kein Katalog eines anderen Jahres als gültig ausgegeben; die Originaldaten bleiben erhalten und die Kontierung offen. Ein bereits gespeicherter Katalog-Snapshot wird bei einer Migration nicht rückwirkend umbeschriftet.

Der Katalog 2025 stammt aus dem DATEV-Original, Art.-Nr. 12901, Stand Januar 2025. Zwei öffentlich bereitgestellte Kopien sind bytegleich (SHA-256 `dcf8080446f4fc0ddb1d67409fdabc3689dab598ac669d2330322f895402e69d`):

- https://tsg-sulzbach.de/wp-content/uploads/fix/DATEV-SKR42.pdf
- https://hsb.vibss.de/fileadmin/Medienablage/Steuern/Buchfuehrung/Muster_Kontenrahmen_SKR_42.pdf

Quelle, Seiten und Bereiche stehen zusätzlich im Datenkatalog. Eine verifizierte historische BU-Schlüsseltabelle für 2025 liegt nicht vor. DATEV-Ausgaben ohne BU sind mit geprüftem Jahreskonto möglich; Fälle mit erforderlichem Steuer- oder Buchungsschlüssel bleiben gesperrt.

`InputTaxTreatment` unterscheidet ausdrücklich unbekannten, ausgeschlossenen und geklärten Vorsteuerabzug. Der ursprüngliche Umsatzsteuersatz und Steuerbetrag bleiben erhalten. Ohne Vorsteuerabzug bleibt der Bruttobetrag Kosten beziehungsweise Anschaffungswert; ein dazu widersprüchlicher BU-Schlüssel wird zurückgewiesen. Ein ungeklärter Abzug bei besteuerten Ausgaben erscheint in den Nacharbeiten und sperrt den betroffenen DATEV-Export. Eine Vereinsvoreinstellung gilt nur für neue Ausgaben. Teilweise abzugsfähige Ausgaben erfordern eine geprüfte Aufteilung.

## Datenbank und Prüfung

Migration 063 ergänzt die Vorsteuerentscheidung, 064 speichert Übernahmevorgänge und Originalpositionen einschließlich gemeinsamer Paketpreise, 065 erweitert die zulässigen Kontenplanjahre. Bestehende Spaltenwerte und Zeilen werden bei der Veröffentlichung an einer privaten Livekopie und während der atomaren Migration verglichen. Integrität und Fremdschlüssel werden geprüft.

Die Regressionstests decken Wiederholungen, geänderte Dokumente und Bestände, fehlende Rechte, Paket- und Teilwerte, spätere Rechnungszuordnung, Löschschutz sowie Jahreswechsel und DATEV-Sperren ab. Browserprüfungen ergänzen Desktop, Mobilansicht und Bedienung per Tastatur. Private Originalrechnungen, Zugänge, Importpläne und Datenbanksicherungen gehören nicht ins Git-Repository.
