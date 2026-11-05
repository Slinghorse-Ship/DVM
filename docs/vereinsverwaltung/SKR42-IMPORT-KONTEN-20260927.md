# SKR42-Quellimport und zusätzliche Jahreszuordnungen

Fortgeschrieben am 01.10.2026. Entwicklung, keine Produktivveröffentlichung.

## Ergänzung: gedruckte Bilanz-/GuV-Zuordnung vollständig lesen

Der frühere Import lieferte Kontonummer und Beschreibung, ließ aber die linke,
gerahmte Spalte mit Bilanz-/GuV-Posten weg. `datev_table.statement_cells()` liest
nun die zusammenhängenden Rahmen beider Seiten und die vollständigen darin
gedruckten Beschriftungen. `statement_at()` ordnet anhand der Kontenzeile zu;
Leerstellen bleiben leer. HB/SB werden auch bei getrennten PDF-Buchstaben
zusammengesetzt. Statistikseiten werden als solche markiert. Ein unklarer
Spaltenkopf oder widersprüchliche Rahmen stoppen den Import.

Neue Bilanzimporte haben `statement_schema: 1`; jede Quellzeile enthält
`statement` mit Art, Originalbeschriftung, Kennzeichen und PDF-Koordinaten.
Alle alten Konten, Bereiche und Beschreibungen der Originale 2025/2026 bleiben
unverändert. `maintain-datev-skr42.py enrich` erzeugt aus einem neu gelesenen
identischen Original lediglich eine quellengebundene Ergänzung. Beispiel mit
einem zuvor neu eingelesenen Katalog:

```text
python vereinsverwaltung/tools/maintain-datev-skr42.py enrich --candidate audit/skr42-reimport/skr42-2026.json --output-dir audit/skr42-source-positions
```

`positions.json` ist ein Kandidat für `resources/accounting/statements/2026.json`,
keine aktive Installation. Ein anderes Original oder veränderte Kontentexte
werden abgewiesen. Diese Ergänzung ist nur für historische Kataloge nötig;
neue Jahresimporte führen die Felder von Anfang an mit.

2025: 1.688 von 1.689 buchbaren Konten haben einen gedruckten Posten. 2026:
1.677 von 1.679. Die restlichen Quellfelder sind tatsächlich leer; es fehlen
keine ausgelesenen Konten. `verify` und die Jahres-Prüfpakete unterscheiden
`not_printed` ausdrücklich von `not_extracted`.

`statement-groups.json` ordnet wiederkehrende gedruckte Posten zentral einem
Buchhaltungsbereich zu. `DatevStatementCatalog` prüft Quellbindung und Form;
`BalanceTaxMapping::chartChoices()` übernimmt den Bereich, sofern keine
präzisere bestehende Regel vorliegt. Kein Kontonummernpräfix als fachliche Regel,
keine Änderung von Originalbuchungen und keine automatische XML-Freigabe.
2026 sind dadurch 1.479 statt 959 Konten ohne manuelle Bereichswahl anlegbar.
Dies schließt **nicht** die bisher offenen E-Bilanz-Zielregeln.

## Bestätigter Importfehler und Korrektur

Der alte Ausschnitt der Kontentabelle endete bei y=756. Die unveränderte,
hashgebundene DATEV-Originaldatei 2025 enthält Kontenzeilen bis ungefähr y=807.
Dadurch fehlten 33 direkte Quellzeilen und nach Bereichserweiterung 39
Kontoeinträge. Außerdem schnitt der rechte Rand Teile langer Beschreibungen ab.

`import-datev-catalog.py` verwendet jetzt die tatsächlichen Tabellenbegrenzungen.
`datev_table.py` prüft vor dem Zuschneiden unabhängig anhand der Wortkoordinaten,
ob die gedruckten fünfstelligen Kontonummern/Bereichsenden im Ergebnis vorkommen.
Bei geändertem Tabellenaufbau oder verlorenen Nummern bricht der Import ab.
Abgebrochene Wörter werden zusammengesetzt, Bindestriche vor „und“, „oder“ und
„sowie“ bleiben erhalten. Neue Jahrgänge werden dadurch nicht automatisch aktiviert.

Beide Kataloge wurden aus denselben unveränderten Quelldateien neu erzeugt.
Die Seiten 12, 20 und 21 des Originals 2025 wurden zusätzlich bildlich kontrolliert.
Die PDF-Prüfung war entscheidend: Die fehlenden Konten waren ein Importfehler,
kein Unterschied zwischen den Jahresausgaben.

| Katalog | Vorher | Jetzt | Quellzeilen jetzt |
| --- | ---: | ---: | ---: |
| 2025 | 2.675 | 2.714 | 1.585 |
| 2026 | 2.727 | 2.727 | 1.556 |

Kein vorher vorhandenes Konto entfernt. Reservierungen und Mehrdeutigkeiten,
insbesondere 01430, 47340 und der auffällige Bereich bei 47370, bleiben gesperrt.
Die 13 nicht als Konten verwendeten Textzeilen 2025 bleiben zur Nachprüfung
gespeichert: Überschriften und sechsstellige Debitoren-/Kreditorenbereiche.
Sie werden nicht zu erfundenen fünfstelligen Sachkonten umgedeutet.

## Fachliche Zuordnung

Registry-Revision 10 unterscheidet Porto/Telefon/Internet von allgemeinen
Verwaltungskosten. Neu aufgenommen sind geprüfte Konten für Versand/Verpackung,
Verkaufsprovisionen, laufende Fahrzeugkosten, bewegliche Mietsachen und
Verwahrentgelte. Die im Original 2025 vorhandenen Konten 43340, 63200, 63250 und
65400 können jetzt auch diesem Jahrgang zugeordnet werden.

Die Ziele sind aus den Erläuterungen der amtlichen Taxonomie 6.9 abgeleitet,
nicht aus einem Kontonummernpräfix. Fahrzeug-Leasing wird zum Beispiel nicht
pauschal als laufender Fahrzeugaufwand behandelt. Vereinsbeiträge sind keine
Verkaufsprovisionen. Ungeklärte sonstige Konten bleiben prüfpflichtig.

Stand Revision 10: je Jahr 133 bedingungslose und 20 bedingte geprüfte Kontenzuordnungen.
Fortsetzung mit Revision 11: [Personal- und Betriebsaufwand](SKR42-BETRIEBSAUFWAND-20260927.md).
Dem stehen 1.689 grundsätzlich buchbare Katalogkonten 2025 und 1.679 im Jahr
2026 gegenüber. **Das ist ausdrücklich noch keine vollständige automatische
Konten-/Sonderfallabdeckung.** Ein auswählbares Konto ist nicht gleichbedeutend
mit einer freigegebenen automatischen Steuerbehandlung.

## Nachweise

- 11 synthetische Import-Geometrieprüfungen, 40 Jahreskatalog- und 87 Mappingprüfungen.
- 42 geänderte/neue Kontenfälle in beiden Jahrgängen und ein Basisszenario nativ
  mit ERiC erfolgreich validiert; ein absichtlich veränderter Bilanzsaldo abgewiesen.
  Ausschließlich Validierungsflag 2, keine Zertifikate, keine Übermittlung.
  Lokaler Nachweis: `audit/ebilanz-account-coverage-native-20260927.json`.
- Separate MySQL-Integration: `audit/ebilanz-mysql-host-20260918-fe2b492bf68fca8c.json`,
  Quellpaket `18e2f9fa8dcd941b2400c1077d7921c8a24bdb56a2cd5cd83b68e24f5ade722a`.
  Katalogspeicherung (37), Jahreskatalog (40), Mapping (87), Abgabeworkflow (61)
  bestanden. Der Transport im Workflow ist simuliert, kein ELSTER-Testversand.
- Einen veralteten Test korrigiert, der 43340 für 2025 fälschlich als unbekannt
  behandelte. Zusätzlich darf ein neu versiegeltes Datenpaket keine Buch- oder
  Steuerbilanz eines anderen Abschlussjahrs unter einer neuen Jahreszahl ausgeben.

Die dauerhaft freigegebene synthetische MySQL-Datenbank wurde ohne zusätzliche
Sicherung/Wiederherstellung verwendet. Produktivdatenbank nicht angesprochen;
geschützte Produktionsdateien unverändert, private Testdateien anschließend entfernt.
Ausführliche lokale Betriebsberichte bleiben absichtlich außerhalb des Quellpakets.

## Referenzen

- [DATEV SKR42 Bilanz](https://www.datev.de/web/de/shop/produkt-details/12901).
- [Amtliche Taxonomien und Jahresgültigkeit](https://www.esteuer.de/).
- Originalherkunft und PDF-SHA-256 je Jahr bleiben im jeweiligen Katalog erhalten;
  die normalisierten JSON-Prüfsummen sind in der Zuordnungsregistry neu gebunden.

Kulturhus bleibt EÜR. Keine Gesamtabnahme, keine allgemeine Veröffentlichung.

## Zentrale Jahrespflege, 01.10.2026

`resources/accounting/purposes.json` trennt den stabilen Zweck von Kontonummer,
Jahr und Taxonomie. `resources/accounting/years/<Jahr>.json` bindet jede
Kontonummer an eine konkrete Zweckversion sowie an Original- und Kataloghash.
Die vorhandene `resources/ebilanz/skr42-6.9.json` bleibt das kompatible
Laufzeitformat; sie ist aus diesen Definitionen reproduzierbar. PHP und
XML-Generator müssen nicht pro Jahreskonto einzeln geändert werden.

Regeln der Fortschreibung:

- Exakt unveränderte, bereits geprüfte Bedeutungen können übernommen werden.
  Seitenwechsel, Leerraum, Groß-/Kleinschreibung, PDF-Ligaturen, weiche
  Trennzeichen und typografische Bindestriche lösen keine Einzelprüfung aus.
  Originaltexte bleiben erhalten. Keine unscharfe Rechtschreibkorrektur von
  Nummern, Steuersätzen, Paragraphen oder Wörtern wie „mit“/„ohne“.
- Neue Nummern erben nur dann, wenn sie derselben unveränderten gedruckten
  Quellzeile/-spanne angehören und sämtliche bisher buchbaren Mitglieder dieser
  Gruppe bereits denselben geprüften Zweck haben. Zusätzlich ist die Übernahme
  bei exakt gleichem Zwecktext, Kontenfunktion, übergeordnetem Begriff,
  Bilanz-/GuV-Posten und HB/SB erlaubt. Damit benötigen neue Nummern und
  Bereichserweiterungen keine neue Einzelregel. Unterschiedliche geprüfte
  Vorgängerzwecke sperren diese Übernahme. Keine Präfix-, Namensähnlichkeits-
  oder freie Nummernbereichszuordnung.
- Änderungen an Name, Kontenfunktion, Quellspanne oder übergeordnetem Begriff
  werden erkannt; ebenso Änderungen am gedruckten Abschlussposten oder HB/SB.
  Nur bedeutungsgleiche Erweiterungen nach obiger Regel werden übernommen;
  andere Änderungen müssen geprüft werden. Identische Gruppen werden gemeinsam vorgelegt.
  Eine neue Zweckversion ist ebenfalls prüfpflichtig, auch bei gleichem Kontonamen.
- Entfallene, reservierte und mehrdeutige Konten werden nicht übernommen.
  Frühere Kataloge und Bindungen bleiben unverändert. Nachfolger werden mit
  Begründung dokumentiert; das Werkzeug bucht keine historischen Vorgänge um.
- Fußnoten gehören zur Prüfung. Neue Importkandidaten enthalten die Texte der
  Anhangseiten. Fehlen alte Anhangstexte, verweist die Prüfansicht auf das Original.
  Ändert eine Fußnote die steuerliche Bedeutung, zuerst eine neue Zweckversion
  anlegen und den Vergleich erneut erstellen, nicht einfach alle Haken setzen.
- Vorhandene Grundzuordnungslücken bleiben ausdrücklich offen. Keine automatische
  steuerliche Ergänzung allein durch den jährlichen Quellimport. Eine Lücke darf
  nur dann automatisch denselben Zweck erhalten, wenn ein bereits geprüfter,
  exakt bedeutungsgleicher Quellfall nach obiger Regel existiert.

### Ablauf für die zentrale Softwarepflege

Nicht vom einzelnen Kassenwart auszuführen. Die folgenden Beispiele werden aus
dem Repository-Verzeichnis aufgerufen; `python` steht für die Python-Laufzeit
mit pdfplumber/openpyxl. Alle Ausgaben sind neue lokale Prüfverzeichnisse.
Es werden keine Datenbanken, Bankzugänge oder Steuerübermittlungen angesprochen.

```text
python vereinsverwaltung/tools/maintain-datev-skr42.py verify
python vereinsverwaltung/tools/check-datev-skr42.py --output audit/skr42-source-check.json --download-dir audit/skr42-sources
python vereinsverwaltung/tools/maintain-datev-skr42.py prepare --from-year 2026 --manifest audit/skr42-source-check.json --output-dir audit/skr42-next-review
```

Ist das Original unverändert, endet `prepare` ohne PDF-Neuimport mit
`up_to_date`. Bei einem neuen Jahr wird `review.html` erzeugt. Für bisher nicht
verifizierte PDFs muss die Anzahl der Anhangseiten einmal zentral anhand des
Originals geprüft und mit `--appendix-pages <Anzahl>` angegeben werden.
Ein verändertes PDF im gleichen, bereits verwendeten Jahr wird nicht automatisch
als neuer Jahresstand behandelt oder über den historischen Katalog geschrieben.

Alternativ lässt sich ein vorhandener Importkandidat direkt vergleichen:

```text
python vereinsverwaltung/tools/maintain-datev-skr42.py compare --from-year 2025 --candidate vereinsverwaltung/data/skr42-2026.json --output-dir audit/skr42-example-review
```

In `review.html` entscheidet die zuständige prüfende Person über die geänderten
Gruppen, ergänzt Begründungen und kann Nachfolgen dokumentieren. Anschließend
lädt sie die Prüfdatei herunter. Nach Prüfung der Originale und Zielversion:

```text
python vereinsverwaltung/tools/maintain-datev-skr42.py approve --plan audit/skr42-example-review/comparison.json --review kontenplan-pruefung.json --candidate audit/skr42-example-review/catalog.json --output-dir audit/skr42-reviewed-package
```

`approve` berechnet den Vergleich erneut. Quellenänderungen, nachträglich
veränderte Zwecke, veraltete Prüfdateien, fehlende Entscheidungen, unbekannte
Ziele und widersprüchliche Kontenklassen werden abgewiesen. Die JSON-Prüfsummen
dienen der Inhaltsbindung, nicht als Identitätsnachweis oder digitale Unterschrift;
die berechtigte fachliche Prüfung ist über den normalen Code-Review-/Releaseprozess
sicherzustellen.

Ein Paket enthält Jahresbindung, Katalog, Zwecke, erzeugte Registry, Vergleich
und Prüfdatei. Das Manifest wird zuletzt geschrieben und enthält die Hashes aller
gelieferten Dateien. Unvollständige Pakete heißen `incomplete_candidate` und
nennen `resolve_unmapped_accounts` als offenen Schritt. Auch vollständige Pakete
haben `production_authorized: false`: fachliche Regression, Jahres-/Taxonomieabnahme
und ausdrückliche Programmveröffentlichung bleiben erforderlich. Es gibt keinen
Web-Upload und keinen automatischen Austausch aktiver Produktionsdateien.

### Historie und Gültigkeit erhalten

Bestehende Zweckrevisionen und Jahresbindungen nicht überschreiben. Für eine
fachliche Erweiterung eine neue Zweckversion anlegen, betroffene Jahresbindung
gezielt prüfen und ein neues Freigabepaket erzeugen. Eine überarbeitete
Taxonomie wird einmal zentral angebunden, nicht je Kontonummer neu programmiert.
Der jetzige Adapter bleibt auf Taxonomie 6.9 und die geprüften Jahre 2025/2026
begrenzt. Ein PDF für 2027 aktiviert weder neue Abgabejahre noch neue Steuerregeln.
BU-Schlüssel, Sphärenlogik und Steuersonderfälle sind keine Nebenwirkung dieses
Katalogimports und benötigen weiterhin ihre eigenen geprüften Regeln.

EÜR- und Bilanz-Quellen bleiben getrennt. Der hier erzeugte Zweck-/E-Bilanzstand
gehört zur Bilanzvariante; er stellt keinen Verein automatisch auf Bilanz um.
Kulturhus bleibt ausdrücklich EÜR.

### Ergänzende Jahresrevisionen und Automatik für die Bedienung

`years/active.json` wählt ausdrücklich eine Kette ergänzender, versiegelter
Jahresstände (`2025-r14.json`, `2026-r14.json`). Jede Revision bindet den
vorangegangenen Stand; alte Zuordnungen, Quellen und Zweckdefinitionen müssen
unverändert enthalten sein. Eine zusätzlich abgelegte Datei aktiviert sich
nicht selbst. Ein Quellenwechsel oder eine Änderung vorhandener Bedeutungen
darf diesen rein ergänzenden Weg nicht benutzen.

Die Buchungsoberfläche und der XML-Generator verwenden Kontonummer und
Originaljahr, nicht den vom Benutzer eingegebenen Kontonamen. Ein Tippfehler
in diesem Namen verändert daher keine automatische Zuordnung. Für eindeutig
geprüfte Konten zeigt die vorhandene Oberfläche die automatische Zuordnung
ohne zusätzliche Steuerauswahl. Die Jahrespflege ist Aufgabe der zentralen
Softwarepflege, nicht des Mitarbeiters im Verein.

Unbestimmte Sammelkonten enthalten dagegen nicht zwangsläufig die erforderlichen
Angaben: Beispielsweise kann Material für den eigenen Verbrauch oder Ware
zum Weiterverkauf gebucht sein; auch die steuerliche Behandlung kann sich
unterscheiden. Revision 14 erweitert auch deren erlaubte Ziele, stellt aber
**noch keinen automatisch aus Belegen abgeleiteten Entscheidungsdialog** bereit.
Diese Fälle dürfen nicht durch Namensähnlichkeit oder einen pauschalen
„Sonstiges“-Wert als geklärt dargestellt werden.

Die bildliche Kontrolle der Originalseiten 11, 13, 16 und 18 des 2026-PDFs
bestätigt auf Seite 13 einen Widerspruch in der Quelle: „4737 0 bis 4733 9“
ist tatsächlich absteigend gedruckt; außerdem überschneidet sich 47340 mit
einem reservierten Bereich. Der Widerspruch liegt in der Quelle, nicht an der
Bedienung; der Nummernbereich wird nicht geraten. Konto 47340 bleibt bis
zur zentralen Klärung gesperrt. Das Original-PDF und die vorhandenen
Jahreskataloge werden nicht verändert.

### Verifikation

- `tests/datev-statements-test.py`: 41 Fälle einschließlich Rahmengeometrie,
  Leerstellen, HB/SB, vollständiger Quellenabdeckung beider Originaljahrgänge,
  unveränderter Historie, neuer Nummern und erweiterter Bereiche, widersprüchlicher
  Vorgänger sowie veränderter Abschlussposten bei gleichem Kontonamen.
- `tests/datev-statements.php`: 41 reine PHP-Prüfungen zur Bereichsableitung,
  typografischen Normalisierung, Quellbindung und fehlenden XML-Freigabe.
- `tests/balance-chart.php`: elf MySQL-Prüfungen einschließlich serverseitiger
  Bereichsübernahme beim Anlegen. Der Client kann den Quellbereich nicht ändern.
- `tests/tax-runtime-fingerprint.php`: 222 lokale Prüfungen einschließlich der
  neuen Quellergänzungen; Änderung oder Fehlen verhindert veraltete Freigaben.
- `tests/datev-years-test.py`: 21 Fälle einschließlich vollständigem Vergleich
  der bisherigen Laufzeitregeln, Jahreswechsel, Entfernung/Reservierung,
  Zweckrevision, Nachfolgen, unverändertem Altjahr, Typografieabgleich,
  ergänzenden Revisionsketten und abgesichertem CLI-Ablauf.
- `tests/datev-sources-test.py`: zwölf Fälle für Produktauswahl statt Fernbetreuung,
  offizielle Quellen, HTML-Downloadumleitung, Größen-/Dateigrenzen, unveränderliche
  Kandidaten, Anhangprüfung und unveränderten Jahresstand.
- `tests/tax-accounts-year-view.php`: zwölf reine Rendering-/Berechtigungsprüfungen.
- Originaldownload 2026, vollständiger Importvergleich und Browserprüfung lokal:
  `.local/skr42-source-download-20261001.json`,
  `.local/skr42-annual-import-20261001/` und `.local/skr42-annual-browser-20261001/`.
  Der Browser-Prüfstand enthält nur synthetische Entscheidungen, keine fachliche
  Freigabe. Alle aktiven Kataloge und Regeln blieben unverändert.
