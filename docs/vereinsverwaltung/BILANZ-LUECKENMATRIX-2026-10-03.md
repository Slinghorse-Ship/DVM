# Allgemeine Vereinsbilanz: Code- und Nachweisabgleich

**Aktuelle Fortschreibung:** Der normale Kostenanlagen-XML-Pfad für `02150`,
`02350` und `09200` besteht jetzt mit 162 nativen Fällen und zwei tatsächlich aus
MySQL erzeugten XML-Dateien; einschließlich Jahres-/Zeitraumabschluss und
gemischten Anlagen. [Umsetzung und Grenzen](KOSTENANLAGEN-XML-2026-10-03.md),
[Nachweise](KOSTENANLAGEN-XML-2026-10-03.json). Die nachfolgende historische
Ausgangsanalyse ist insoweit überholt. Allgemeine Bilanz-/Übermittlungs- und
vollständige UI-Abnahme bleiben offen; `09000`, gemeinsame Kostenaufteilung,
zusammengesetzte Ausgabe und weitere Bewertungs-/Korrekturfamilien sind damit
nicht freigegeben.

Gemeinsame Kosten-Nutzung und zusammengesetzte Gesamtvereinsausgabe sind
inzwischen für dieselben drei Zwecke ergänzt: 81 vollständige Ausgaben mit
vier Vereinsbereichen, zwei Geschäftsbetrieben und unveränderten Originalwerten
bestehen die native lokale XML-Prüfung. Hinzu kommen 657 Cent- und
Vortragsprüfungen sowie vollständige Bewertungsfelder in Anzeige und CSV.
Der gespeicherte MySQL-Nutzungsnachweis und die erneute Gesamtregression sind
noch offen. [Aktueller begrenzter Stand](GESAMTVEREIN-KOSTENNUTZUNG-2026-10-03.md).
Die übrigen allgemeinen Bewertungs-, Übermittlungs- und UI-Grenzen gelten weiter.

Stand 03.10.2026. Ausgangspunkt `83454dd` / Fachstand `4ebc9d4` im Worktree
`eb9a/Kulturhus`, Branch `layoutauswahl`. Diese Matrix unterscheidet vorhandene
Funktionen, konkrete Grenzen und noch ausstehende Gesamtnachweise. Sie ist keine
fachliche Vollständigkeitserklärung und keine Releasefreigabe. Kulturhus bleibt EÜR.

## Fachliche Fallfamilien

| Fallfamilie | Vorhandene Umsetzung und zugehörige Prüfprogramme | Belegte Grenze / noch erforderliche Abnahme |
| --- | --- | --- |
| Doppelte Buchführung, Quellen und Korrekturen | `BalanceLedger`, `BalanceCalculation`, `BalanceSources`, `BalanceSourceHistory`; `balance-calculation.php`, `balance-ledger.php`, `balance-corrections.php` | Bestehende Quellenbindung und unveränderliche Historie weiterverwenden. Ein grüner Test dieser Fälle belegt keine vollständige Bewertung aller Konten. |
| Steuerliche Ergebnisüberleitung und Sachentscheidungen | `BalanceTaxReconciliation`, `BalanceTaxReconciliations`, `BalanceTaxValues`, `BalanceTaxMapping`; `balance-tax-reconciliations.php`, `ebilanz-operating-r16-test.py`, `ebilanz-trade-test.py` | Bestätigte Hinzurechnungen/Abzüge, getrennte Steuerwerte und bedingte Zuordnungen sind vorhanden. Ein Kontenziel berechnet weder automatisch den nicht abziehbaren Betrag noch eine Steuerschuld. Geschäftspartner, Bewertung und besondere steuerliche Tatbestände benötigen ihre tatsächlichen Nachweise. |
| Umsatzsteuer, Forderungen, Schulden und Abgrenzungen | Geprüfte Zweckregeln der Revisionen 16–19, `BalanceTaxMapping`, `ebilanz_working_capital.py`; `ebilanz-vat-test.py`, `ebilanz-working-capital-r18-test.py`, `ebilanz-financial-debt-r19-test.py`, `ebilanz-builder-test.py` | Nettoumsätze, abgestimmte Umsatzsteuerkonten, Gegenparteien, Anzahlungen, Laufzeit-Zusatzangaben und Abgrenzungsfälle sind implementiert. Ungeklärte Umsatzsteuerkonten und unzulässige Gegensalden dürfen nicht durch eine manuelle Ersatzzuordnung verschwinden. Die allgemeine Bewertungsabdeckung sämtlicher Vorräte/Rückstellungen ist aus Kontenzielen allein nicht nachgewiesen. |
| Mehrere Bereiche und Geschäftsbetriebe | `BalanceSegments`, `BalancePeriodAreas`, `BalanceInternalClearing`, `BalanceCombinedClubValues`; `balance-segments.php`, `balance-period-areas.php`, `ebilanz-combined-club-test.py` | Gesamtverein, steuerlicher Teil und interne Verrechnungen sind implementiert. Keine Ersatzfreigabe eines einzelnen Geschäftsbetriebs als allgemeiner Release. |
| Anlagenarten und Abschreibung | `AssetTypes`, `AssetBooks`, `BalanceAssetSchedule`, `BalancePeriodAssets`, `AssetValuation`; `ebilanz-asset-types-test.py`, `asset-gwg.php`, `asset-degressive.php`, `asset-cost-valuation.php`, `balance-period-cost-valuation.php` | Software, bewegliche Anlagen und GWG sowie ein belegter Kostenbewertungsweg für vier Grundstücks-/Wertpapierkonten sind umgesetzt. Getrennte Buch-/Steuerwerte, wiederholte Abgänge ohne Erlös und Kalender-Perioden-Vortrag bestehen gezielte Prüfungen. XML und gemeinsame Nutzung dieses neuen Wegs bleiben offen. Gebäude, weitere Finanzanlagen, Firmenwert und Sammelposten sind nicht abgedeckt. [Nachweis und Grenzen](KOSTENANLAGEN-2026-10-03.md). Lineare Originalpläne benötigen weiterhin 12–1.200 Monate in vollen Nutzungsjahren. |
| Anlagenabgänge und getrennte Steuerwerte | `AssetDisposal`, `AssetPartialDisposal`, `AssetSale`, `BalanceTaxAssetValues`, `BalancePeriodTaxAssetValues`; `ebilanz-disposal-test.py`, `ebilanz-partial-assets-test.py`, `ebilanz-sale-test.py`, `ebilanz-tax-assets-test.py` | Ausmusterung, Verkauf, Softwareende, geprüfter Teilabgang, Vortrag und abweichende Steuer-Nutzungsdauer existieren. Ein weiterer Abgang nach bestätigtem Teilabgang wird in `AssetBooks::recordYear()` noch abgewiesen. Das ist keine vollständige allgemeine Bewertungslogik. |
| Gemischte Nutzung und echte Sphärenwechsel | `BalanceAssetAllocation`, `BalancePeriodSphereChange`, `BalancePeriodSphereTax`; `ebilanz-shared-assets-test.py`, `ebilanz-period-spheres-test.py` | Gemeinsame Nutzung ist von einem echten Wechsel zu unterscheiden. Echte Wechsel unterstützen die über `AssetTypes::codes('linear')` freigegebenen linearen Anlagen, nicht mehr nur die drei früheren Konten. Wechsel nur zum Monatsbeginn; degressive/GWG-Wechsel, Wechsel zusammen mit Abgang und ungeprüfte Kombinationen separater Steuerpläne bleiben offen. |
| Tatsächliche Wirtschaftsjahre und Kurzzeiträume | `BalancePeriod`, `BalancePeriodLedger`, `BalancePeriodDepreciation`; `ebilanz-period-test.py`, `ebilanz-period-asset-math-test.py`, `balance-period-asset-carry.php` | Kalenderbrücken und lückenlose Folgezeiträume existieren. `BalancePeriod::fullMonths()` sperrt angebrochene Abschreibungsmonate. Die Abschreibungsaufteilung für solche Grenzen ist noch zu entwickeln; Entfernen der Sperre allein reicht nicht. Datierte Buchungsfunktionen bleiben auf die synthetische Umgebung beschränkt. |
| Gesamtverein und steuerliche Nebenrechnung | `BalancePeriodWholeFiling`, `BalancePeriodCombinedValues`, `BalancePeriodClubFiling`, `BalanceCalendarClubSource`; `ebilanz-period-whole-test.py`, `ebilanz-period-whole-calendar-test.py`, `ebilanz-period-combined-test.py`, `ebilanz-period-subsidiary-test.py` | Getrennte Original-/Steuerwerte und Gesamt-/Teilbilanz sind vorhanden. Die fachliche Abdeckung aller eingesetzten Konten und Anlagen sowie der durchgängige Abgabe-/Rückmeldeweg bleiben erforderlich. |
| Abschluss- und Abgabekorrekturen | `BalancePeriodCorrectionWorkflow`, `BalanceClosingCorrections`, `BalanceFilingRevision`; `balance-closing-corrections.php`, `balance-period-corrections.php`, `balance-filing-revision.php`, `ebilanz-workflow-bridge-test.py` | Entwurf, ausdrückliche Bestätigung, unveränderte Originale und Entwertung ersetzter Vorträge sind implementiert. Simulierte Übermittlungsprotokolle sind kein amtlicher End-to-End-Nachweis. |
| Kontenzwecke und jährliche DATEV-Ausgaben | `datev_years.py`, `datev_statements.py`, `CatalogImports`, versiegelte Ausgaben `2025-r19` / `2026-r19`; `datev-years-test.py`, `datev-statements-test.py`, `datev-web-test.py`, `catalog-imports.php` | 320 Zwecke; 2025: 1.177 von 1.689 Konten mit Regel, 512 offen. 2026: 1.184 von 1.679 mit Regel, 495 offen. Je 265 bedingte Regeln benötigen Sachangaben. Ein gedruckter Bilanzposten ersetzt keine XML-/Bewertungsregel. |
| Taxonomie-/ERiC-Jahrespakete | `taxonomy_sources.py`, `TaxonomySources`, Quellen 6.7/6.8/6.9/6.10 mit amtlichen Beginnjahren; `ebilanz_release.py`, `taxonomy_purposes.py`, Bindung in Jahresvergleich, Generator und nativer Brücke; `ebilanz-taxonomy-sources-test.py`, `ebilanz-future-year-test.py`, `catalog-taxonomies.php` | 6.9 / 2025–2026 für lokale Prüfung. 6.10 / 2027 als Kontenvergleichsvorbereitung: 314 unveränderte Zwecke, sechs zurückgestellt; tatsächliche 2027-PDF fehlt. 6.7/6.8 sind verfügbare Quellen ohne abgenommenen Ausgabeadapter. Keine produktive Abgabefreigabe. Weitere Adapter-, Regel-, ERiC- und Gesamtabnahme erforderlich. Geänderte Paketstände entwerten alte Jahresvergleiche. |
| Übermittlung und Veröffentlichung | Bestehende Testumgebungs-, Rollen-, Zertifikats-, Freigabe- und Protokollprüfungen in `TaxFilings`, `TaxEricAdapter`, `eric-bridge.py` | Vollständige sichere Testübermittlung einschließlich passender Rückmeldungen und allgemeine Releaseabnahme fehlen. Kein echter Versand und keine Live-Aktivierung aus Teilnachweisen. Vollständige UI-Prüfung erst nach bestandenem vollständigem Release. |

Die Prüfprogrammnamen bezeichnen die vorhandenen Fälle und nicht automatisch
deren aktuellen Erfolgsstatus. Der eingefrorene Serverlauf und die neuen lokalen
Prüfungen haben unterschiedliche Quellstände; ihre Ergebnisse dürfen nicht zu
einem erfundenen gemeinsamen Komplettlauf zusammengezählt werden.

Für die Ergänzung aller vier Taxonomiequellen bestehen 14 gezielte lokale
Programme und vier getrennt geprüfte MySQL-Programme. Siehe
[Jahrespflege 2027](ZUKUNFTSJAHRE-2027-2026-10-03.md) und
[Nachweisdatei](TAXONOMIEN-6.7-6.10-2026-10-03.json).
Die allgemeinen Anlagen-, Bewertungs- und Übermittlungslücken bleiben offen.

Der erneute quellengebundene Abgleich ist unter
`audit/balance-unmapped-families-20261003.json` gespeichert: Jahresausgabe,
Originalquelle, unveränderte Kontentexte, Nummern und Seiten der noch ungebundenen
Quellzeilen. Er bestätigt 512/495 offene Konten und die obigen bedingten Regeln.
Die Gruppierung ist eine Arbeitsliste, keine daraus abgeleitete Steuerregel.
Beispielsweise müssen die Quellzeilen für unbebaute Grundstücke, Grundstücke
mit Substanzverzehr und Gebäude ausdrücklich unterschieden
bleiben; ein allgemeines Sammelkonto implementiert diese Sachverhalte nicht.

## Auswertung des vorherigen Serverlaufs

Der Lauf `d725ab22c6bc07ed` ist vollständig beendet: 162 von 163 Programmen
bestanden. `balance-vat-mapping.php` enthält im eingefrorenen Stand die veraltete
Erwartung, dass die inzwischen in Revision 16 geprüften Zwecke für `40000`,
`40450` und `42900` ungeklärt bleiben. Der Test wurde anhand der Originalquellen
und des Revisionsnachweises auf die konkreten Mitgliedsbeitrags-, Spenden- und
0-%-Kategorien angepasst. Ungeklärte Konten bleiben gesperrt; aus keinem dieser
drei Zwecke wird ein inländischer Umsatzsteuersatz abgeleitet. Die 64 lokalen
Prüfungen bestehen. Die Anwendungsregeln wurden dafür nicht geändert.

Der ursprüngliche Gesamtbericht bleibt wahrheitsgemäß `passed=false` und wird
nicht nachträglich umgeschrieben. Geschützte Produktivdateien sind unverändert;
seine temporäre Testkopie ist entfernt. Die neue Paketbindung und der korrigierte
Test werden anschließend gezielt auf einem eigenen Quellstand geprüft. Es gab
keinen parallelen Datenbanklauf. Der Nachlauf `0a73a3ceb614155d` besteht mit
allen sieben ausgewählten Programmen, einschließlich des korrigierten Tests
und der gespeicherten Jahrespflege. **Das ist weiterhin keine allgemeine Bilanzabnahme.**
Maßgeblich sind die getrennten Berichte und Quellhashes in der
[Nachweiszusammenfassung](JAHRESPAKETE-2026-10-03.json).

## Reihenfolge der weiteren Entwicklung

1. Bei weiteren Änderungen Quellstände und Abnahmen getrennt nachweisen.
   Der bisherige Gesamtserverlauf und der gezielte Nachlauf sind ausgewertet.
2. Jahresquelle, Kontenzweck, Sachentscheidung, amtliche XBRL-Quelle und
   installierten Prüfer bei weiteren Änderungen getrennt nachvollziehbar halten.
3. Offene Anlagen-/Bewertungs- und Kombinationsfälle als vollständige Abläufe
   ergänzen: Originalbeleg, Buch-/Steuerwerte, Zeitraum, Vortrag, Sphäre,
   Korrektur, XML und Rollen. Keine Kontonummern einzeln jährlich nachprogrammieren.
4. Restliche Kontenzweck-Familien mit Originalquellen prüfen; keine pauschalen
   Zuordnungen und keine erfundenen Nutzungsdauern, Laufzeiten oder Bewertungen.
5. Erst nach vollständiger Fach-, Übermittlungs- und Gesamtprüfung veröffentlichen.
   Danach alle vorgesehenen Rollen und Menüpunkte auf Desktop/Handy systematisch
   prüfen. Ein solcher technischer UI-Test ist kein echter Nutzertest.

## Nächster Anlagenpfad: Bewertung ohne vorhandenen Abschreibungsplan

Die Quellliste enthält unter anderem `02150` (unbebaute Grundstücke), `02350`
(Grundstückswerte eigener bebauter Grundstücke) und die getrennten Gebäude- und
Finanzanlagenfamilien. Vor einer Zuordnung ist die tatsächliche Bewertungslogik
zu prüfen. Der aktuelle technische Engpass liegt nicht nur in fehlenden Kontenzielen:

- `AssetBooks::plan()` und `schedule()` kennen ausschließlich lineare,
  degressive und GWG-Pläne. Eine künstliche Nutzungsdauer wäre kein geeigneter
  Ersatz für eine fehlende Bewertungsart.
- `AssetTypes` beschränkt die Fähigkeiten auf Software, bewegliche Anlagen und
  GWG. Neue Fähigkeiten sollten hier an gemeinsame Zwecke gebunden werden,
  nicht als jährlich neu gepflegte Nummernlisten in mehreren Modulen.
- `BalancePeriodDepreciation::proposal()` verlangt derzeit auch dann ein
  geprüftes Abschreibungskonto, wenn der Periodenaufwand null beträgt.
  Ein zusätzlicher Bilanzposten würde diesen gespeicherten Ablauf nicht lösen.
- `BalancePeriodAssets` und `ebilanz_period_asset_math.py` müssen Buchwerte,
  Zugänge, Abgänge und Vorträge weiterhin unabhängig gleich rekonstruieren.
  Steuerwerte, gemeinsame Nutzung und Korrekturen müssen dieselbe neue
  Bewertungsart ausdrücklich unterstützen.
- Der vollständige Anlagenspiegel und der Abgabeweg müssen die ursprünglichen
  Anschaffungskosten und Veränderungen erhalten. Eine direkte Nettozuordnung
  unter Umgehung des Anlagenverzeichnisses wäre keine vollständige Umsetzung.

Diese Punkte sind aus den bestehenden Eintrittsprüfungen abgeleitete
Entwicklungsanforderungen, noch keine implementierten oder abgenommenen Fälle.

Für die Handelsbilanz trennt [§ 253 HGB](https://www.gesetze-im-internet.de/hgb/__253.html)
planmäßige Abschreibung, außerplanmäßige Wertminderung und Wertaufholung.
[§ 6 Abs. 1 Nr. 2 EStG](https://www.gesetze-im-internet.de/estg/__6.html)
regelt unter anderem die steuerliche Bewertung von Grund und Boden und
Beteiligungen. Amtliche Gesetzestexte abgerufen am 03.10.2026; vor einer
Jahresregel ist zusätzlich ihre für das betreffende Jahr geltende Fassung
zu binden. Eine Bewertungsart mit dauerhaft null Aufwand wäre deshalb allein
keine vollständige Grundstücks- oder Finanzanlagenfunktion.

Die nächste Umsetzung benötigt eigene, beleggebundene Bewertungsereignisse
für Buch- und Steuerwerte: ursprüngliche Kosten erhalten, Veränderungen getrennt
ausweisen, Wertaufholung begrenzen, Folgezeiträume abstimmen und eine geänderte
Bewertung als Korrektur mit neuer Prüfung führen. Konkrete Fallnachweise müssen
auch Erwerb ohne Abschreibung, Wertminderung, spätere Wertaufholung, vollständigen
und teilweisen Abgang sowie unveränderten Vortrag prüfen. Die bereits vorhandenen
Verkaufs- und Sphärenfunktionen dürfen neue Anlagenarten erst übernehmen, wenn
deren bisherige Voraussetzungen fachlich und rechnerisch dafür geprüft sind.
