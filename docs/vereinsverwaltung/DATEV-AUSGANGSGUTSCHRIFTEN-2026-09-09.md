# DATEV: Rückzahlungen zu eigenen Ausgangsgutschriften

Stand: 9. September 2026. Betrifft den bestehenden DATEV-Zahlungsstapel (EXTF 700, Buchungsstapel 13, SKR42), keine Sollbuchhaltung und keine automatische Steuererklärung.

## Unterstützter Ablauf

Eine vom Verein ausgestellte Rechnung hat einen tatsächlichen Zahlungseingang. Die vorhandene Funktion `OutgoingInvoices::correct` erzeugt eine unveränderliche Teil- oder Vollgutschrift mit Bezug zur Originalrechnung und den ursprünglichen Positionen. Erst die separat zugeordnete tatsächliche Erstattung wird exportiert. Rechnungsausgabe, Gutschriftausgabe und Export erzeugen keine zusätzliche Zahlung.

Die Exportprojektion unterstützt Original- und Gutschriftbelege aus den geprüften Jahren 2025/2026, gewöhnliche Inlandsumsätze mit 7/19 Prozent und dokumentierte 0-Prozent-Fälle ohne BU. Ein Original aus 2025 kann 2026 erstattet werden. Originalkontierung und Gutschriftkontierung werden gegen ihre jeweiligen Jahreskataloge geprüft; die Gutschriftkontierung muss zugleich zum Exportjahr passen.

Am Original ist für einen steuerpflichtigen Fall ausdrücklich der passende unterstützte Inlands-Umsatzsteuerschlüssel hinterlegt: `2`/`102` für 7 Prozent oder `3`/`101` für 19 Prozent. Die Adresse ist kein Nachweis des Umsatzsteuerorts. Es wird kein Inlandsschlüssel aus der Postleitzahl, Gemeinnützigkeit oder einem freien Rechnungstext geraten.

## Fachliche Grundlage und DATEV-Abbildung

Bei einer vereinbarten Rückzahlung bereits entrichteten Entgelts richtet sich die Minderung nach dem tatsächlich zurückgezahlten Betrag und dem Zeitpunkt der Rückgewähr. Das ist die Grundlage für den begrenzten Zahlungsablauf hier. Andere Berichtigungstatbestände werden dadurch nicht implementiert. Quelle: [BMF UStAE 17.1 Absatz 2 Satz 3, amtliche Handausgabe 2024](https://usth.bundesfinanzministerium.de/usth/2024/A-Umsatzsteuergesetz/V-Besteuerung/Paragraf-17/ae-17-1.html).

Die Umsatzsteuerkorrektur folgt der ursprünglichen Leistung; eine eigene Erlösgutschrift ist keine Lieferantenrechnung mit neuer Vorsteuer. Unterschiedliche Steuersätze bleiben positionsweise getrennt. Grundlage: [§ 17 UStG, insbesondere Absätze 1 und 4](https://www.gesetze-im-internet.de/ustg_1980/__17.html).

Die folgenden Feldwerte sind unsere Umsetzung des dokumentierten DATEV-Formats für einen echten Geldrückfluss: positives Umsatzfeld, `H` bezogen auf das Geldkonto, ursprüngliches Erlöskonto als Gegenkonto, zugehöriger Umsatzsteuerschlüssel und unveränderte Sphäre als KOST1. Eine echte Gutschrift ist keine technische Generalumkehr einer Fehlbuchung. Der bestehende separate Gegenbuchungsablauf bleibt unverändert. Beispiel: 59,50 EUR Rückzahlung einer 19-Prozent-Leistung entsprechen 50 EUR Erlösminderung und 9,50 EUR Umsatzsteuerkorrektur. Quelle der Felddefinitionen: [DATEV Buchungsstapel](https://developer.datev.de/de/file-format/details/datev-format/format-description/booking-batch), Felder 1, 2, 7–9, 37 und 118; der öffentlich erreichbare [DATEV-Format-Datensatz](https://developer.datev.de/mediator/strapi/file-formats/slug/datev-format) wurde dazu ausgelesen.

Es werden die bereits im Repository nach Originalquellen getrennt hinterlegten Schlüsselstände verwendet: [DATEV 2025](https://wissensplattform.apps.datev.de/help/document/0907054), [DATEV 2026](https://wissensplattform.apps.datev.de/help/document/0907048). Keine neue Schlüsseldefinition oder umetikettierter Jahreskatalog.

## Datenvertrag und Schutz gegen Abweichungen

- `DatevOutgoingCredit` arbeitet nur lesend und vereinsbezogen. Finanz-Leserecht genügt; ein gewöhnliches Mitglied ist ausgeschlossen.
- Die Position ist durch Beleg-ID plus einsbasierte Ordnungszahl identifiziert. Der eingefrorene Gutschriftbeleg speichert seine nullbasierte `original_ordinal`. Gleiche Artikeltexte sind keine Identität.
- Betrag, Steuerbetrag, Steuersatz und Beschreibung müssen zum ausgegebenen Original und zur Gutschrift passen. Aktive Erlöskonten und Sphären beider aktuellen, begründet bearbeitbaren Kontierungen müssen übereinstimmen.
- Der gespeicherte Rückzahlungsbeleg bleibt Ausgabe ohne Vorsteuerabzug. Nur die Exportzeile erhält die Kennzeichnung als Erlösminderung und den geprüften Umsatzsteuerschlüssel des Originals. Finanzbestand, Original-PDF und Buchungen bleiben unverändert.
- Die vorhandene centgenaue Verteilung auf Positionen bleibt erhalten. Das Journal ergänzt nur Positionsidentität und kumulativ bezahlte Positionsbeträge vor/nach einer Zuordnung. Auch ein Monatsfilter kennt die vorangegangenen Teilzahlungen.
- Ein gemeinsamer Lesesnapshot umfasst Journal und Originalbezug. Der Vorschauhash umfasst auch den Originalbeleg und dessen aktuelle Kontierung. Ein später geänderter Originalschlüssel macht einen bereits angesehenen Erstattungs-Export ungültig.

## Bewusst gesperrte Fälle

Der vollständige Download bleibt gesperrt bei fehlendem oder abweichendem Originalbezug, widersprüchlichem Dokumentstand, fehlendem Inlands-Umsatzsteuerschlüssel, Vorsteuerschlüssel am eigenen Rückzahlungsbeleg, abweichender Sphäre/Kontierung, Automatikkonten, Nicht-Erlöskonten, nicht unterstützten Jahresständen oder steuerlichen Sonderfällen. Ausland und Reverse Charge werden nicht mit einem geratenen Schlüssel exportiert.

Bei Teilbeträgen kann die bestehende Gutschriftausgabe die ursprüngliche Steuer anteilig anders auf ganze Cent verteilen als eine erneute Brutto-Steuerberechnung. Ein konkreter synthetischer Fall ist eine Leistung von 0,06 EUR mit 19 Prozent und eine Teilgutschrift von 0,04 EUR. Das unveränderliche PDF wird nicht umgeschrieben. Auch eine volle Gutschrift von 0,06 EUR, deren Erstattung in zweimal 0,03 EUR geteilt ist, kann pro DATEV-Zeile anders runden als die Gesamtsteuer. Beide Fälle bekommen eine ausdrückliche Rundungssperre. Ein fachlicher Centausgleich wird nicht durch eine erfundene Kontozahlung ersetzt.

## Prüfung und verbleibende Abnahme

`vereinsverwaltung/tests/datev-outgoing-credit.php` verwendet ausschließlich neue synthetische In-Memory-Vereine. Die Tests durchlaufen echte Ausgabe, gespeicherte PDFs, Teil-/Vollgutschriften, Bankzuordnung und manuell dokumentierte Erstattungen. Geprüft werden beide Jahre und Steuersätze, alle vier Umsatzsteuerschlüssel, Teilzahlungen, gemischte gleich benannte Positionen, Folgegutschriften, Jahreswechsel, 0 Prozent, Vorzeichen und CSV-Felder, Idempotenz, unveränderte Dokumente, Rechte/Vereinstrennung, veraltete Vorschau sowie Sperren. Es entstehen keine Mailaufträge.

Zusätzlich werden DATEV-Basisexport, DATEV 2025, Finanzberichte und Vorsteuerbehandlung als Regression geprüft. Das Handbuchkapitel 9 und die DATEV-Vorschau erklären die Schritte und verlinken bei fehlendem Originalschlüssel direkt zur betroffenen Rechnungskontierung.

Eine tatsächliche Importabnahme mit DATEV Rechnungswesen bleibt offen. Der Export ist für eine abgestimmte Buchhaltung auf Zahlungsbasis; bereits in DATEV erfasste Rechnungen dürfen nicht zusätzlich mit diesem Stapel nochmals als Aufwand/Ertrag gebucht werden.
