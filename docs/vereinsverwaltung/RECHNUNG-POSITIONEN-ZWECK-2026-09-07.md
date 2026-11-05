> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Rechnung: Firma, vollständige Positionen und Zuordnung

Stand: lokale Implementierung und isolierte Tests am 07.09.2026; keine Livebuchung oder Mail durch diesen Prüfauftrag.

## Bedienung

- Eine suchbare Firmenauswahl ersetzt das doppelte Namensfeld. Neue Firmen entstehen im bestehenden Inline-Dialog; erkannte Angaben bleiben überprüfbare Vorschläge. Ein exakter eindeutiger Namensfund wählt den bestehenden Kontakt. Ohne Kontaktleserecht bleibt ein einzelner Rechnungssteller-Freitext möglich.
- **Geprüfte Vorschläge gemeinsam übernehmen** übernimmt den sichtbaren Vorschlagssatz bewusst in den Entwurf. Die tatsächliche Ausgabe entsteht erst mit **Rechnung speichern**. Fremdwährungen und Gutschriften werden weiterhin nicht als positive EUR-Ausgabe vorbelegt.
- Die zweistufige Zweckauswahl enthält Inventar, GEMA, Künstler/Band, andere Veranstaltungsausgaben, Versicherung und Allgemeines. Aktuelle Website-Termine kommen aus der bestehenden Registry/WebsiteCalendar-Brücke.
- Eigene Kategorien für Musikrechte, Künstlerhonorare, Inventar und Porto/Versand sind additive Finanzkategorien. Sie vergeben keine Steuerkonten oder Sphären.
- Jede Position bietet Bruttobetrag, Steuersatz, Menge, Einheit und einen optionalen Einzelpreisrechner mit ausdrücklicher Netto-/Bruttobasis. Mehrere Gegenstände je Position bleiben möglich. Versand ist eine eigene Position mit dem Steuersatz des Originals.

## Speicherung und Rechte

Migration 058 ergänzt `finance_event_assignments` und `invoice_position_details`. Beide Tabellen sind mandantengebunden und verhindern UPDATE/DELETE durch Trigger. Sie sind zusätzliche Nachweise, kein zweites Buchungsjournal.

Die Terminzuordnung wird innerhalb der bestehenden Rechnungstransaktion anhand einer zugriffsgeprüften Registry-Veranstaltung erstellt. Titel, Datum und Ort kommen nicht aus POST-Feldern. `DocumentCatalog` verwendet den gespeicherten Snapshot für `website:`-Vorgänge und erzeugt den üblichen Terminordner. Dieselbe Originaldatei ist mit Ausgabe und Termin/Gegenständen verknüpft; es werden keine Kalenderkopien oder Dateikopien erzeugt. Organisation-Schreibrecht bleibt für Termin-Dokumentzuordnungen erforderlich. Wiederholte Speicherung verändert die bereits verknüpfte Rechnung nicht.

Positionsdetails enthalten den bestätigten Erfassungsstand: Menge, Einheit, Einzelpreis und Basis, Netto/Brutto, erklärten Rechenunterschied und einen als Entwurfshinweis gekennzeichneten Auslesetext. Das Original bleibt zusätzlich unverändert erhalten. Die Details werden im Belegpaket exportiert und im Rechnungsfreigabehash berücksichtigt.

## Rundung

Die vorhandenen Buchungspositionen bleiben bruttoführend. Eine abweichende ausgewiesene Gesamtsteuer darf nur nach zusätzlicher ausdrücklicher Bestätigung verteilt werden: einheitlicher positiver Steuersatz, höchstens ein Cent je Zeile und begrenzte Gesamtdifferenz. Die Auswahl der betroffenen Zeilen erfolgt anhand des Rundungsrests; keine Ersatz-/Rabattposition wird erzeugt. Ursprüngliche und zugeordnete Steuerwerte werden je Zeile dokumentiert. Eine reine spätere Kontierungsänderung erhält eine bestehende Rundungsverteilung, solange Brutto und Steuersatz unverändert bleiben. Gemischte Steuersätze oder größere Differenzen werden nicht geraten.

## Texterkennung und Grenzen

Erweitert wurden allgemeine Briefkopf-Trennzeilen, Rechnung-Nr.-Schreibweisen, Netto/Brutto-Summen, Steuerangaben mit Nettobasis in Klammern, Steuertabellen und mehrzeilige PDF-Positionsblöcke. Eine Nettobasis wird nicht mehr als Steuerbetrag gelesen. Mehrere gültige IBAN bleiben ohne willkürliche Auswahl. Alle Ausleseergebnisse bleiben prüfpflichtig; unterstützte Layoutmuster sind keine universelle Rechnungs- oder Echtheitsprüfung. Private Belege wurden nicht in Testdateien, Screenshots oder diese Dokumentation übernommen.

## Prüfbelege

| Suite | Ergebnis | Umfang / Grenze |
|---|---:|---|
| invoice-event-assignment.php | 19 bestanden, Exit 0 | Website-Termin, Ordner, GEMA, Tenant, Replay, immutable Snapshot; synthetische Calendar-Closure |
| invoice-recognition-workflow.php | 31 bestanden, Exit 0 | Firma/Bank, Preise, Mehrfachinventar, Details, Export, Freigabehash, Rundung; In-Memory-Dateidatenbank |
| accounting.php | 56 bestanden, Exit 0 | bestehende Buchungsregeln und Kontierung |
| datev-export.php | 14 bestanden, Exit 0 | Kontierung weiterhin ausdrücklich, unklassifizierte Daten blockieren Export |
| tax-preparation.php | 19 bestanden, Exit 0 | vorhandenes Steuer-Arbeitspaket |
| invoice-approvals.php | 47 bestanden, Exit 0 | Freigabe, Historie und Originalrechte |
| finance-bundle.php | 11 bestanden, Exit 0 | Originale, Prüfsummen, Tenant |
| invoice-inbox-guided.php | 21 bestanden, Exit 0 | bestehender Erfassungsablauf |
| invoice-inbox-items.php | 14 bestanden, Exit 0 | mehrere Gegenstände ohne Doppelbeleg |
| invoice-supplier-contacts.php | 13 bestanden, Exit 0 | Kontakte, Snapshot, Rechte |
| invoice-supplier-create.php | 15 bestanden, Exit 0 | Inline-Anlage und Replay |
| invoice-recognition-parser.py | Exit 0 | ausschließlich künstliche Textbeispiele; zusätzlich echte native PDF-Browserläufe |

Lokaler Browser `127.0.0.1:8091`, ausschließlich synthetische Daten:

1. Native PDF-Erkennung → gemeinsamer Vorschlagsknopf → Firmenanlage → zwei Positionen und Versand → drei Gegenstandszuordnungen → Netto-Einzelpreisrechner → Künstler-Termin → absichtlicher Versionsfehler mit erhaltenem Entwurf → Speicherung. Rücklesen: genau ein Beleg, drei Buchungspositionen, 249,90 € brutto und 39,90 € Steuer.
2. Neu erzeugtes PDF mit drei mehrzeiligen Artikelblöcken → Firma, Mengen und Einzelpreise → zwei Inventarlinks → ausdrücklich bestätigte Steuerverteilung → Speichern und vollständige Details erneut öffnen. Originalwerte 1.035,50 € brutto und 165,33 € Steuer bleiben exakt erhalten.

Beide Abläufe wurden bei 390 px ohne horizontalen Überlauf geprüft. Private Browserartefakte: `.local/invoice-bulk-ui.cjs`, `.local/invoice-full-ui.cjs`, `.local/invoice-full-rounding-mobile.png`. Keine Live-, Mail- oder Bankaktionen. Die Veröffentlichung und der Gesamtregressionslauf erfolgen durch den Hauptauftrag.
