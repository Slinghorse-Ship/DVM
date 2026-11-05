> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Kontenabdeckung: noch keine vollständige SKR42-Freigabe

## Aktueller Stand R93 · tatsächliches Zielmenü geprüft · 07.10.2026

Die öffentliche Kontenauswahl liefert jetzt tatsächlich vier Detailpositionen für 72500 und sieben für 72550. Das bisherige allgemeine Zielmenü enthielt nur die zwei Inlands-/Auslands-Beteiligungspositionen; deshalb stellt das separate Finanzanlagenmodul die übrigen amtlichen Optionen ausdrücklich mit verständlichen Bezeichnungen bereit. Der XML-Generator kennt dieselben sieben Positionen. Ein neuer Test prüft auch ein leeres allgemeines Zielmenü und die Übereinstimmung mit den vom Generator unterstützten Positionen. Damit sind technische XML-Auswahl und Beschriftung umgesetzt; **die steuerliche Originalfallfreigabe ist weiterhin offen**, und aktive ungeprüfte Beträge bleiben gesperrt.

Vier neue Testgruppen, der Abhängigkeitstest und 548 Fingerprint-Kontrollen bestehen. Das vorhandene 2025-Test-XML besteht nach der Übernahme erneut ERiC-Code 0 bei unveränderter XML-Prüfsumme. Keine Buchungsänderung oder Übermittlung; 2026 bleibt offen. „Erste Schritte in der Vereinsverwaltung“ ist ausgeblendet. Nachweise: `audit/skr42-r93-accepted-20261007.json`, `audit/xml-r93-current-2025-native-20261007.json`, `audit/financial-reserve-xml-r93-final-20261007.json`.

## Aktueller Stand R92 · XML-Detailauswahl und Exportprüfung · 07.10.2026

Die manuelle XML-Auswahl für 72500 ist auf Beteiligungsabschreibungen (Inland, Ausland, andere Zuordnung) und den Auffangposten beschränkt. Für 72550 kommen passende Ausleihungen an Gesellschafter oder nahestehende Personen hinzu. Andere betriebliche Aufwendungen lassen sich diesen Konten nicht mehr zuordnen. Die sieben angebotenen Detailpositionen wurden in den amtlichen Metadaten 6.7–6.10 auf nichtabstrakte Dauerposition und Körperschaftsverwendung geprüft; im laufenden 2025/2026-Export bleibt Version 6.9 maßgeblich. Diese Auswahl betrifft die technische Kontendarstellung, keine steuerliche Zulässigkeit.

Zusätzlich verhindern PHP und der Python-XML-Pfad jetzt, dass aktive ungeprüfte Beträge über eine freie manuelle Position die Originalfallprüfung umgehen. Zuvor konnte der Python-Generator freie Zuordnungen mit den Automatikvorschlägen vereinigen; die beiden Konten hatten dort keine eigene Quellensperre. Unbenutzte Konten gelten deshalb weiterhin nicht als qualifizierte Originalfälle. Es wurden **keine Katalogregeln und keine zusätzlichen Kontenfreigaben** erzeugt; die bisherige Abdeckung mit zwei offenen Konten je Jahr bleibt unverändert.

Neue Modul-/Paritätsprüfungen und beide Abhängigkeitstests bestehen (266 erfasste Fingerprint-Dateien, 548 Fingerprint-Kontrollen). Sechs Laufzeitdateien wurden ohne Neustart in die private MySQL-Testanwendung übernommen; alle 45 Tabellen blieben beim Übernehmen unverändert. Das vorhandene 2025-XML besteht anschließend erneut ERiC-Code 0 mit gleicher XML-Prüfsumme; nur der Prüfdatensatz wurde aktualisiert. Keine Übermittlung. Die Dashboard-Karte „Erste Schritte in der Vereinsverwaltung“ ist ebenfalls ausgeblendet und per HTTP-Abruf geprüft.

Der Nutzer kennt den konkreten Finanzanlagen-/Rücklagenfall nicht; damit liegt weiterhin kein belegter Originalfall vor. Die Software kann die technische Auswahl beschränken, aber weder eine konkrete steuerliche Übertragung noch unveränderte Anschaffungskosten und Buchungen aus unbekannten Tatsachen bestätigen. § 6b Absatz 10 schließt eigene Neubildungen durch Körperschaften aus; eine behauptete übernommene Rücklage benötigt die getrennte ursprüngliche Quellenkette. Die Körperschafts-Reinvestition, aktive Zuordnung 72500/72550, offene 2026-Zuordnungen, Original 2027 und native 6.10 bleiben unqualifiziert.

Primärquelle: https://www.gesetze-im-internet.de/estg/__6b.html. Technische Nachweise: `audit/skr42-r92-accepted-20261007.json`, `audit/xml-r92-current-2025-native-20261007.json`, `audit/financial-reserve-xml-r92-final-20261007.json` und `vereinsverwaltung/tests/financial-reserve-xml-test.py`. Die Quellenentwurfs-Erfassung R91 bleibt erhalten.

## Aktueller Stand R91 · Quellenaufnahme umgesetzt · 07.10.2026

Die Erfassung übernommener Finanzanlagenrücklagen ist jetzt in der laufenden privaten MySQL-Testanwendung eingebaut. Rechenregeln (`FinancialReserveCalculation`), Dokument-/Zugriffsprüfung (`FinancialReserveIntake`), versionierte Schreibaktion und Formular sind getrennt. Der vollständige Kalenderabschluss, das Originalkonto 72500/72550, ursprünglicher Rücklagenbildner, Originalgewinnjahr und unveränderte Frist werden mit vier getrennten aktuellen Finanzbelegen verbunden. Es handelt sich ausdrücklich um einen **Quellenentwurf**, keine steuerliche oder XML-Freigabe.

Das Speichern hebt bestehende XML-Prüfung/Freigabe auf. Eine offene Quellenaufnahme sperrt die öffentliche XML-Erzeugung, auch nach Speichern des normalen Steuerformulars. Begründetes Zurücknehmen erhält den früheren Quellenentwurf in der Historie. Buchungsjournal und Anlagenwerte werden nicht geändert. Keine produktive Verwendung und keine Übermittlung.

88 Kontrollen an zwei isolierten MySQL-Jahresfällen, 42 Rechenfälle und vier bestehende Regressionstestprogramme bestehen. Sieben Laufzeitdateien wurden ohne Neustart übernommen; alle 45 Tabellen blieben dabei unverändert. Danach besteht das bisherige 2025-Test-XML erneut ERiC-Code 0 mit unveränderter XML-Prüfsumme; allein der Prüfdatensatz wurde aktualisiert. Quellenprüfung und Code-Review sind dokumentiert; vollständiger UI-Rundgang bleibt offen.

**Die Katalogabdeckung bleibt 1681/1683 (2025) und 1677/1679 (2026). 72500/72550 sind weiterhin nicht als aktive XML-Kontenfälle qualifiziert.** Das reale 2026 bleibt offen mit drei offenen aktiven Zuordnungen. Original 2027 und native 6.10 fehlen weiterhin. R91 qualifiziert nur die Quellenaufnahme; die gesamte Bilanz ist noch nicht 100 % abgeschlossen.

Nachweise: `audit/skr42-r91-accepted-20261007.json`, `audit/financial-reserve-intake-r91-mysql-20261007.json`, `audit/financial-reserve-intake-r91-final-20261007.json`, `audit/xml-r91-current-2025-native-20261007.json` und `audit/r91-intake/README.md`. Frühere R89-/R90-Nachweise bleiben unverändert als historische Stände erhalten.

## Aktueller Stand R89 · 07.10.2026

77690 ist für einen abgegrenzten Originalfall in der laufenden privaten MySQL-Testanwendung angebunden: vollständiger anfänglicher Kalenderabschluss 2025/2026 ohne Anlagen, Vorjahreskette oder weitere Ergebnisverwendung; bereits gespeicherte, unabhängig bestätigte steuerliche Aufwands-/Rückstellungswerte und ursprünglicher Ergebnisvortrag 29700. Drei getrennte Originaldokumente belegen die Kapitaldarstellung, die unabhängig geprüften Steuerwerte und die interne Ergebnisumbuchung. Die Steuerbewertung wird nicht aus Kontonamen abgeleitet. Andere Anlagen, Kapitaldarstellungen, Vorjahresänderungen, Ergebnisverwendungen oder Fortschreibungen benötigen eine eigene Abnahme.

Die Ergebnisdifferenz wird aus den Originalbuchungen und tatsächlichen Steuerbuchungen berechnet. Die daraus erzeugte Kapitalumbuchung verändert weder den Steuergewinn noch den Betriebsvermögensvergleich. Der XML-Ausweis erhält das ursprüngliche Kapital und das handelsrechtliche Jahresergebnis; der laufende steuerliche Mehr-/Mindergewinn steht getrennt unter `bs.eqLiab.equity.netIncome.taxBalanceGenerally.transferDiffTaxAccounts`. Die amtlichen Quellen 6.7–6.10 unterscheiden diesen erfolgswirksamen Betrag von erfolgsneutralen laufenden Änderungen und Änderungen früherer Jahre. Die bisherigen Klasse-9-Kataloge und Ausschlüsse bleiben unverändert; ein fiktives Konto 92970 wird nicht erzeugt.

Zwölf öffentlich gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre und beide Vorzeichen bestehen ERiC-Code 0 ohne Übermittlung. Sie enthalten 204 Quellenprüfungen, darunter 120 negative Bedingungen. Weitere 164 PHP-/Python-Mutationen werden übereinstimmend abgelehnt. Zwei zusätzliche MySQL-Fälle bestehen zusammen 38 Prüfungen einschließlich tatsächlichem PHP-Formular und wiederholtem Speichern ohne doppelte Buchungen. Alle zwölf XML-Prüfsummen bleiben nach Formular-/Aktionsanbindung unverändert; 15 gezielte Testprogramme bestehen. Vier native Originalfälle sind als Repository-Fixtures erhalten. Rechenregeln, Dokument-/MySQL-Prüfung, versionierte Aktion und Darstellung liegen in getrennten Modulen.

Aktuelle Katalogabdeckung: **1681/1683 für 2025 und 1677/1679 für 2026; zwei Konten je Jahr offen: 72500 und 72550**. Automatisch/bedingt: 1043/638 beziehungsweise 1040/637. Alte Zweckversionen, Originalkataloge, Vergleichsklassifikation und versiegelte Jahresstände bleiben erhalten. Die Zukunftsprojektion 6.10 enthält 686 geeignete und 21 zurückgehaltene Zwecke; Originalquelle 2027 und native 6.10-Abnahme fehlen weiterhin. Deshalb ist die gesamte Bilanz-/XML-Aufgabe noch nicht zu 100 Prozent abgeschlossen.

19 geprüfte Laufzeitdateien wurden ohne Neustart in die private Anwendung übernommen; dabei blieben alle 45 MySQL-Tabellen unverändert. Der vorhandene synthetische 2025-Export besteht anschließend erneut ERiC-Code 0 mit unveränderter XML-Prüfsumme; nur sein Prüfdatensatz wurde aktualisiert. Das tatsächliche 2026 bleibt offen. Der lokale Quellenschutz wurde gegen den richtigen R88-Vorgängerstand geprüft; eine zunächst aus dem älteren Helfer geerbte R87-Referenz wurde ausschließlich im neuen Schutzabschnitt berichtigt.

Nachweise: `audit/skr42-r89-accepted-20261007.json`, `audit/skr42-r89-original-tax-equalization-native-batch1-20261007.json`, `audit/skr42-r89-public-source-contract-stage4-20261007.json`, `audit/skr42-r89-form-guards-stage1-20261007.json`, `audit/skr42-r89-final-xml-parity-20261007.json`, `audit/skr42-r89-targeted-reviewed-stage1-20261007.json`, `audit/xml-r89-private-runtime-20261007.json`, `audit/xml-r89-current-2025-native-20261007.json` und `audit/xml-r89-source-guard-20261007.json`. Die getrennte Vorbereitung der amtlichen Kapitalpositionen steht in `audit/r89-source-stage/official-capital-graph.json`.


## Aktueller Stand R88 · 07.10.2026

62490 ist für einen ausdrücklich abgegrenzten Originalfall nach §6b Absatz3 angebunden: unverbrauchte Rücklage aus dem unmittelbaren Vorjahr, Übertragung auf ein im Abschlussjahr erworbenes einzelnes Wohngebäude, ursprünglich1925-2022 fertiggestellt, mit unabhängig bestätigter regulärer Steuer-AfA von2%. Der ursprüngliche steuerpflichtige Veräußerungsgewinn aus Grund und Boden oder Gebäude, sechsjährige ununterbrochene Inlandsbindung, wirksame Rücklagenbildung, bisherige Verwendung und Restbetrag benötigen unabhängige fachliche Prüfung und drei getrennte Originalnachweise. Diese Angaben werden nicht aus Kontonamen abgeleitet.

Quellenvertrag, aktuelle Originaldokumente, versioniertes öffentliches Speichern des Steuerentwurfs, Werteprüfung und XML-Anlagenspiegel sind verbunden. Tatsächlicher steuerlicher Rücklagenvortrag samt Kapitaldifferenz, Rücklagenauflösung49270, Anschaffungskostenkürzung62490 und Korrektur der regulären Steuer-AfA62210 werden vollständig abgestimmt. Alle fünf Bewegungsfelder von sechs betroffenen Konten sowie die übrigen Konten müssen den ursprünglichen Buchwerten und dem bestätigten Überleitungsfall entsprechen. Die Brutto-Anlagenbewegung nutzt getrennt `accDepr.DeprPeriod.EStG6b_3`; der ursprüngliche Buchplan, die Anschaffungsbuchung und das Anlagenjahresregister bleiben erhalten.

Zwölf öffentlich gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre und zwei Beträge bestehen ERiC-Code0 ohne Übermittlung. Die erste native Testvorbereitung stoppte vor einem ERiC-Fall wegen einer fälschlich geerbten §7g-Zielauswahl im Testhelfer; diese wurde korrigiert, der fehlgeschlagene Nachweis bleibt erhalten. Nach dem Code-Review sind alle zwölf akzeptierten XML-Prüfsummen unverändert. Zwei öffentliche MySQL-Guardfälle bestehen118 Prüfungen, darunter114 negative Bedingungen: Originalwahl, Gewinn/Restbetrag, Haltedauer, Rechte/Version, alle Steuerfelder, beide Buchungsphasen und Änderungen aller drei Originaldokumente. Das PHP-Formular wurde tatsächlich gerendert. Zusätzlich bestehen14 gezielte Testprogramme; vier native Originalfälle sind als Repository-Fixtures aufbewahrt. Der ursprüngliche Quellenvertrag wurde separat mit zwölf MySQL-Abschlüssen und68 übereinstimmenden PHP-/Python-Ablehnungen entwickelt.

Aktuelle Katalogabdeckung: **1680/1683 für2025 und1676/1679 für2026; drei Konten je Jahr offen:72500,72550,77690**. Automatisch/bedingt:1043/637 beziehungsweise1040/636. Bestehende Zweckversionen, Originalkataloge, Vergleichsklassifikation und frühere versiegelte Jahresstände sind unverändert. Die neue Zukunftsprojektion6.10 enthält685 geeignete und21 zurückgehaltene Zwecke; Original 2027 und native 6.10-Abnahme fehlen weiterhin. Andere Ersatzgüter, unmittelbare §6b-Absatz1-Übertragung, längere Rücklagenhistorie, gemischte Anlagen, weitere Wahlen, andere Zeiträume und Folgejahre benötigen eine eigenständige Abnahme.

Die geprüfte Codeübernahme in die laufende private Anwendung verändert keine der45 MySQL-Tabellen. Das vorhandene synthetische2025-XML besteht anschließend erneut ERiC-Code0 mit identischem Inhalt; nur sein Prüfdatensatz wird aktualisiert. Das tatsächliche2026 bleibt offen. Keine Übermittlung, Neuinitialisierung oder Änderung ursprünglicher Finanzbuchungen.

Nachweise: `audit/skr42-r88-accepted-20261007.json`, `audit/skr42-r88-original-basis-7g-stored-native-reviewed-batch2-20261007.json` (Inhalt:§6b-Fälle), `audit/skr42-r88-source-guards-reviewed-stage2-20261007.json`, `audit/skr42-r88-final-xml-parity-20261007.json`, `audit/skr42-r88-targeted-reviewed-stage1-20261007.json`, `audit/xml-r88-private-runtime-20261007.json`, `audit/xml-r88-current-2025-native-20261007.json`, `audit/xml-r88-source-guard-20261007.json`. Primärquelle: https://www.gesetze-im-internet.de/estg/__6b.html.

Fachliche Regeln, MySQL-Zugriff, Aktionen und Darstellung bleiben getrennte
Verantwortlichkeiten; ein Gesamtarchitektur-Review folgt daraus nicht automatisch.


## Code-Review R87 · 07.10.2026

Die unabhängige PHP-Prüfung der ursprünglichen §-7g-Absatz2-Kürzung verlangt jetzt dieselbe vollständige Quellenbindung wie das bereits bestehende Python-XML-Gate: ursprünglicher festgeschriebener Gesamtabschluss, unveränderte Wahl, Original-IAB und vollständiger neu berechneter Steuerwertnachweis. Eine isolierte Wertprüfung nahm zuvor insbesondere fehlende Gesamtabschlussquelle oder manipulierte abgeleitete Werte an. Dieser Fehler wurde zuerst mit einem Regressionstest am unveränderten Stand reproduziert, anschließend korrigiert. Der öffentliche Abschluss-/XML-Weg hatte bereits weitere Quellenprüfungen; die Änderung schließt die Lücke in der separat aufrufbaren PHP-Wertprüfung.

24 erneut öffentlich gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH,62430/62440,2025/2026 und zwei Beträge bestehen die native ERiC-Prüfung mit Code0. Alle24 XML-Prüfsummen sind exakt identisch mit den bereits abgenommenen R83-Dateien; ursprüngliche Buchungen und Anlagenjahresregister bleiben unverändert. Vier MySQL-Guardfälle umfassen168 Prüfungen, darunter148 negative Bedingungen;48 neue Bedingungen betreffen die unabhängige Quellenwiederholung. Der neue Repositorytest enthält zusätzlich vier gültige Originalfälle und dieselben48 Ablehnungen. Alle14 gezielten Testprogramme bestehen mit unveränderten endgültigen Quellen.

Für2027 ist die Taxonomie6.10 bereits amtlich veröffentlicht und lokal vorbereitet. Die amtliche Seite esteuer.de kündigt native ERiC-Testfälle jedoch erst für November2026 und Echtfälle für Mai2027 an. Stand07.10.2026 ist die Zukunftsprojektion deshalb keine native oder produktive Abnahme. Primärquelle: https://www.esteuer.de/.

Nur eine Laufzeitdatei wird in die laufende private Anwendung übernommen. Dabei bleiben alle 45 MySQL-Tabellen unverändert. Das vorhandene synthetische2025-XML besteht erneut ERiC-Code0; ausschließlich sein Prüfdatensatz wird aktualisiert.2026 bleibt offen. Die Zuordnung selbst bleibt auf R86: **1679/1683 für2025 und1675/1679 für2026; je vier Konten offen**. Keine zusätzliche Kontenfreigabe oder100-Prozent-Aussage durch diese Korrektur.

Nachweise: `audit/skr42-r87-accepted-20261007.json`, `audit/skr42-r87-original-basis-7g-stored-native-reviewed-batch1-20261007.json`, `audit/skr42-r87-source-guards-20261007.json`, `audit/skr42-r87-targeted-reviewed-stage1-20261007.json`, `audit/xml-r87-private-runtime-20261007.json`, `audit/xml-r87-current-2025-native-20261007.json`, `audit/xml-r87-source-guard-20261007.json`.

### Nächster Quellenvertrag:62490 und Finanzanlagen-Abgrenzung

Für62490 sind der ursprüngliche steuerliche Veräußerungsgewinn und eine konkrete begünstigte Ersatzinvestition zu verknüpfen. §6b Absatz2 definiert den Gewinn aus Verkaufspreis abzüglich Verkaufskosten und steuerlichem Buchwert. Absatz4 verlangt insbesondere die sechsjährige ununterbrochene Zugehörigkeit des veräußerten Gegenstands zum Anlagevermögen einer inländischen Betriebsstätte und nachvollziehbare Buchführung. Direkte Übertragung und Rücklagenauflösung benötigen getrennte Quellenverträge; die verminderte AfA-Basis ist nach Absatz6 abzuleiten. Eine Kontenwahl oder eine bloß deklarierte Rücklage ersetzt diese Nachweise nicht.

§6b Absatz10 schließt Körperschaften, Personenvereinigungen und Vermögensmassen aus. Diese Abgrenzung ist bei72500/72550 ausdrücklich zu beachten.72550 nennt im Originalkatalog zusätzlich §8b Absatz3 KStG; deshalb wird aus dem Ausschluss eines einzelnen Steuerfalls keine pauschale Kontensperre oder vollständige Zuordnung abgeleitet.77690 benötigt weiterhin einen eigenen Quellenvertrag für steuerliche Ausgleichsposten. Primärquelle: https://www.gesetze-im-internet.de/estg/__6b.html; Originalbezeichnungen sind unverändert an DATEV-Jahresquellen2025/2026 gebunden.


## Aktueller Stand R86 · 07.10.2026

62400 ist als Sammelkonto mit ausdrücklich bestätigter Original-Rechtsgrundlage angebunden. Der erste qualifizierte Fall ist eine eigene steuerliche Wahl nach §7g Absatz5 für einen im Abschlussjahr angeschafften beweglichen Einzelgegenstand ohne Fahrzeug. Die Kontobezeichnung ersetzt keinen Rechtsgrundlagennachweis. Der neue Quellstand2 verlangt exakt `EStG7g5`; fehlende, andere und fehlerhaft typisierte Rechtsgrundlagen werden verworfen. Die bisherigen Quellstände1 für62410/62420 bleiben erhalten.

Originalanschaffung, maßgeblicher Vorjahresgewinn, belegte inländische betriebliche Nutzung und Nutzungsbindung bis Ende des Folgejahrs werden mit drei unveränderten Originaldokumenten verbunden. Gesonderter Sonderbetrag bis zur bestätigten40-Prozent-Grenze, tatsächliche steuerliche Überleitungsbuchung, unveränderte ordentliche Buch-AfA und getrennte Brutto-Anlagenbewegung stimmen überein. Andere Sondergesetze, Fahrzeuge auf dem Sammelkonto, weitere Steuerwahlen, gemischte Anlagen, Zeiträume, Abgänge und Fortschreibung benötigen eigene Abnahme. Die fachlichen Anspruchsvoraussetzungen bleiben unabhängig belegte Entscheidungen.

Aktuelle Katalogabdeckung: **1679/1683 für2025 (4 offen), 1675/1679 für2026 (4 offen)**. Automatisch/bedingt:1043/636 beziehungsweise1040/635. Die Originalkataloge, bisherigen Zweckversionen und versiegelten Jahresstände bleiben unverändert. Die Zukunftsprojektion6.10 enthält684 geeignete und21 zurückgehaltene Zwecke; Originalquelle und native Paketabnahme2027 sind weiter erforderlich.

Zwölf gespeicherte öffentliche MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre und zwei Beträge bestehen ERiC mit Code0.88 Prüfungen in zwei MySQL-Fixtures umfassen78 negative Bedingungen: fehlende oder fremde Rechtsgrundlage, ungültige Gewinn-/Betragsgrenzen, fehlende Nutzungsbestätigung, Rechte/Versionsstände, fehlende tatsächliche Steuerbuchung, Änderungen aller drei Originaldokumente, aller fünf Steuerbewegungsfelder der drei betroffenen Konten sowie der gespeicherten Rechtsgrundlage. Das PHP-Formular wurde mit dem ausdrücklichen Rechtsgrundlagenfeld gerendert.

Das Code-Review ergänzt die unabhängige PHP-Wiederholung der Originalquellprüfung entsprechend dem bereits bestehenden Python-XML-Gate. Alle zwölf neuen nativen XML-Prüfsummen bleiben danach unverändert. Zusätzlich bestehen vier neu gespeicherte öffentliche MySQL-Fälle für62410/62420, jeweils2025/2026, mit unverändertem altem Quellstand und exakt denselben bisherigen XMLs. Alle13 gezielten Testprogramme einschließlich vorheriger Original7g/7b-, Basis-, Substanz-, Pensions- und außergewöhnlicher AfA-Regressionen bestehen. Die geerbte Revisionsbeschriftung81 des aktuellen Zuordnungspakets ist auf86 berichtigt; seine übrigen Inhalte bleiben dabei identisch.

Nachweise: `audit/skr42-r86-accepted-20261007.json`, `audit/skr42-r86-original-generic-special-stored-native-reviewed-batch1-20261007.json`, `audit/skr42-r86-source-guards-20261007.json`, `audit/skr42-r86-legacy-public-mysql-20261007.json`, `audit/skr42-r86-final-xml-parity-20261007.json`, `audit/skr42-r86-targeted-reviewed-stage1-20261007.json`. Primärquelle: https://www.gesetze-im-internet.de/estg/__7g.html; die originalen DATEV-Jahresquellen bleiben an den Jahreskatalog gebunden.

Die Übernahme in die laufende private Anwendung verändert keine der45 MySQL-Tabellen. Das bestehende synthetische2025-XML wird anschließend erneut mit ERiC-Code0 und unverändertem XML-Inhalt bestätigt; nur dessen Prüfdatensatz in `tax_filings` wird aktualisiert. Das tatsächliche2026 bleibt offen. Keine Übermittlung, Neuinitialisierung oder Änderung ursprünglicher Finanzbuchungen.

Vier Konten je Jahr bleiben offen:62490,72500,72550,77690. UI, Dokumente und Installer folgen nach XML-Abnahme; Navigation ohne vollständigen Seitenrefresh bleibt vorgemerkt.

## Historischer Stand R85 · 07.10.2026

62450 ist für eine belegte ursprüngliche Sonderabschreibung nach §7b mit separater regulärer steuerlicher Gebäude-AfA eingebaut. Der qualifizierte Ablauf betrifft ein neu erworbenes deutsches Mietgebäude im Fertigstellungsjahr2025 oder2026 und im neuen Bauantragszeitraum. Originale Gebäudekosten ohne Bodenanteil, ausschließlich begünstigte Fläche, QNG/Effizienzhaus40, dauerhafte entgeltliche Wohnraumvermietung, zehnjährige Bindung und Rückgängigmachungspflichten sind gesondert bestätigt und mit sechs unveränderten Originalnachweisen verbunden. Ein unabhängig fachlich ermittelter Beihilfewert samt Erklärung anderer Beihilfen wird übernommen; es wird kein Steuersatz geschätzt und keine künftige Rechtskonformität behauptet.

Die Kostenobergrenze5200EUR/m² ist von der Sonder-AfA-Bemessungsgrenze4000EUR/m² getrennt. Beträge werden in Cent, Flächen in Hundertstel Quadratmeter geprüft. Der gewählte Sonderbetrag ist auf5% der begünstigten Bemessungsgrundlage begrenzt. Die reguläre steuerliche Gebäude-AfA3% wird für das Erwerbsjahr monatsanteilig berechnet; die zusätzliche Sonderabschreibung hat diese Monatskürzung nicht. Der ursprüngliche unabhängige Buchplan bleibt unverändert. Tatsächliche getrennte Überleitungsbuchungen und alle fünf Bewegungsfelder werden mit dem XML-Anlagenspiegel abgestimmt.

Aktuelle Katalogabdeckung: **1678/1683 für2025 (5 offen), 1674/1679 für2026 (5 offen)**. Automatisch/bedingt:1043/635 beziehungsweise1040/634. Die ursprünglichen Jahreskataloge, bisherigen Zwecke, historischen versiegelten Jahresstände und R82-Vergleichskonten bleiben unverändert. Die Zukunftsprojektion6.10 enthält683 konzeptionell geeignete und21 zurückgehaltene Zwecke; daraus folgt keine Freigabe des Originaljahrs2027.

Zwölf gespeicherte öffentliche MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre und zwei gewählte Sonderbeträge bestehen ERiC mit Code0.122 Prüfungen in zwei MySQL-Fixtures umfassen112 negative Bedingungen: fehlende Bestätigungen oder Originalbelege, ungültige Datums-/Flächen-/Betragsgrenzen, Rechte und Versionsstände, fehlende tatsächliche Steuerüberleitung, geänderte Bytes aller sechs Originaldokumente, geänderte Steuerbewegungsfelder und konkurrierende Steuer-/Zeitraum-/Teilsphärenquellen. Das PHP-Formular mit den getrennten Eingaben wurde ebenfalls gerendert. Alle zwölf gezielten Testprogramme bestehen. Die zwölf neuen nativen XML-Prüfsummen und die eingebundenen vorherigen XML-Regressionen bleiben exakt erhalten. Der erste native Durchlauf stoppte vor einem nativen Fall wegen der erforderlichen expliziten Zuordnung des regulären62210-Kontos; der dokumentierte zweite Durchlauf bestand vollständig.

Qualifizierter Umfang: anfänglicher vollständiger Kalenderabschluss mit einem ursprünglichen neuen Gebäude, rein begünstigter Wohnfläche ohne Nebenflächen-/BGF- oder Mischnutzungsaufteilung und regulärem De-minimis-Fall. Alter Bauantragszeitraum, DAWI, andere Förderung derselben Kosten, weitere Anlagen, degressive steuerliche Gebäude-AfA, andere Steuerwahlen, Verkauf und Fortschreibung benötigen eine gesonderte Abnahme. Die Beurteilung der Originalnachweise und der Miet-/Beihilfebindung bleibt eine unabhängige fachliche Entscheidung.

Nachweise: `audit/skr42-r85-accepted-20261007.json`, `audit/skr42-r85-original-special-7b-stored-native-reviewed-batch2-20261007.json`, `audit/skr42-r85-source-guards-20261007.json`, `audit/skr42-r85-final-xml-parity-20261007.json`, `audit/skr42-r85-targeted-reviewed-stage1-20261007.json`. Primärquellen: BMF-Anwendungsschreiben vom21.05.2025 https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Einkommensteuer/2025-05-21-anwendungsschreiben-7b-estg-neu.pdf?__blob=publicationFile&v=5 und EStH2025 §7b / §7.

Die Übernahme in die laufende private Anwendung lässt alle 45 MySQL-Tabellen unverändert. Der vorhandene synthetische Abschluss2025 besteht anschließend ERiC mit Code0 und unveränderter XML-Prüfsumme; nur sein Prüfdatensatz in `tax_filings` wird aktualisiert. Das tatsächliche2026 bleibt offen. Die beendete lokale Verbindung wurde datenbewahrend wieder aufgenommen; die45 Tabellen stimmen zuvor exakt mit dem letzten bestätigten Stand überein. Keine Neuinitialisierung und keine Übermittlung.

Fünf Katalogsachverhalte und Originalquelle/native Paketabnahme2027 bleiben offen. UI, Dokumente und Installer folgen nach XML-Abnahme; Navigation ohne vollständigen Seitenrefresh bleibt vorgemerkt.

## Historischer Stand R84 · 07.10.2026

02250 ist mit einem eigenen ursprünglichen Mengenplan für entgeltlich angeschaffte unbebaute Grundstücke mit Substanzverzehr eingebaut. Ursprüngliche Anschaffungskosten, nicht verbrauchbarer Restwert, belegte Gesamtmenge, Mengeneinheit und tatsächlicher Jahresverbrauch sind mit Originalnachweisen verbunden. Die unabhängig bestätigte identische Buch- und Steuerabschreibung wird aus dem verbrauchbaren Kostenteil und der tatsächlichen Jahresmenge berechnet. Der tatsächliche Jahresimport auf62200 bleibt vom Bruttozugang getrennt; Schlusswert und Anlagenspiegel erhalten den Restwert. Eine gewöhnliche Nutzungsdauer oder ein Kostenplan ohne Substanzverbrauch ersetzt diesen Nachweis nicht.

Aktuelle Katalogabdeckung: **1677/1683 für 2025 (6 offen), 1673/1679 für 2026 (6 offen)**. Automatisch/bedingt:1043/634 beziehungsweise1040/633. Die ursprünglichen Jahreskataloge, bisherigen Zwecke, versiegelten Jahresstände und die sechs Vergleichskonten aus R82 bleiben erhalten.

Zwölf gespeicherte öffentliche MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre sowie teilweisen und vollständigen Verbrauch bestehen ERiC mit Code0.78 Prüfungen in zwei tatsächlichen MySQL-Fällen umfassen70 negative Bedingungen: ungültige Mengen und Restwerte, unvollständige fachliche Bestätigungen, Rechte und Versionsstände, doppelte oder unqualifizierte spätere Jahresbestätigung sowie Änderungen an Originalbelegen, Plan, Jahresregister, Rechnung, Verknüpfung und Quellhistorie. Alle elf gezielten Testprogramme bestanden; die zwölf neuen XML-Prüfsummen bleiben nach Code-Review exakt erhalten, ebenso die eingebundenen bisherigen XML-Regressionen.

Qualifizierter Umfang: ein ursprüngliches entgeltlich erworbenes unbebautes Grundstück, anfänglicher vollständiger Kalenderabschluss2025/2026, unveränderte Gesamtmenge und identische unabhängig bestätigte Buch-/Steuerwerte. Gebäude, geschenkte oder lediglich eingelegte Bodenschätze, abweichende Steuerwerte, geänderte Mengenschätzung, gemischte Anlagen, Abgänge und Fortschreibung benötigen gesonderte Abnahme. Die Bestätigung des steuerlichen Ansatzes bleibt eine belegte fachliche Entscheidung.

Nachweise: `audit/skr42-r84-accepted-20261007.json`, `audit/skr42-r84-original-substance-stored-native-reviewed-batch2-20261007.json`, `audit/skr42-r84-source-guards-20261007.json`, `audit/skr42-r84-final-xml-parity-20261007.json`. Gesetzesquelle: https://www.gesetze-im-internet.de/estg/__7.html; Originalansatzhinweise: https://esth.bundesfinanzministerium.de/esth/2025/A-Einkommensteuergesetz/II-Einkommen-2-24b/3-Gewinn-4-7i/Paragraf-7/inhalt.html.

Die Übernahme in die laufende private Anwendung lässt alle 45 MySQL-Tabellen unverändert. Der vorhandene synthetische Abschluss2025 besteht anschließend ERiC mit Code0 und unveränderter XML-Prüfsumme; nur sein Prüfdatensatz in `tax_filings` wird aktualisiert. Das tatsächliche Jahr2026 bleibt offen. Kein Neustart, keine Neuinitialisierung und keine Übermittlung.

Sechs Katalogsachverhalte und die Originalquelle/native Paketabnahme2027 bleiben offen. UI, Dokumente und Installer folgen nach XML-Abnahme. Die Navigation ohne vollständigen Seitenrefresh bleibt projektweit vorgemerkt.

## Historischer Stand R83 · 07.10.2026

62430 und 62440 sind für die getrennt gewählte Anschaffungskostenkürzung nach § 7g Absatz 2 eingebaut. Der ursprüngliche IAB, sein verbleibender Betrag, die unabhängig bestätigte außerbilanzielle Hinzurechnung sowie Nutzungs- und Wahlnachweise sind mit ihren Originalbelegrevisionen verbunden. Der unveränderte Buchplan bleibt erhalten. Die reguläre steuerliche Abschreibung wird aus der gekürzten Bemessungsgrundlage neu berechnet; Kürzung und Rücknahme der ursprünglichen regulären Abschreibung müssen als tatsächliche Steuerüberleitung vorliegen. Brutto-Anlagenbewegungen, Aufwand und Schlusswerte werden gemeinsam abgestimmt. Die außerbilanzielle Hinzurechnung gehört zur separaten steuerlichen Gewinnermittlung und wird hier weder als GuV-Buchung erfunden noch automatisch an ELSTER übermittelt.

Aktuelle Katalogabdeckung: **1676/1683 für 2025 (7 offen), 1672/1679 für 2026 (7 offen)**. Automatisch/bedingt: 1043/633 beziehungsweise 1040/632. Die sechs ursprünglichen Vorjahresvergleichskonten aus R82 bleiben für aktuelle Buchungen ausgeschlossen; Originalkataloge, bisherige Zwecke und versiegelte Zuordnungsstände bleiben erhalten.

24 gespeicherte MySQL-Fälle mit EV, Stiftung und gGmbH, beiden Konten, beiden Jahren und zwei Kürzungsbeträgen sind nativ mit ERiC-Code 0 geprüft. 100 negative Bedingungen in vier MySQL-Fällen sichern Originalbeträge, Jahresgrenzen, Berechtigungen, Versionsstände, Belegänderungen und fehlende Überleitungen; insgesamt 120 Prüfungen einschließlich positiver Zustands- und Formularprüfungen. Zehn gezielte Testprogramme bestanden, alle 24 nativen XML-Prüfsummen bleiben nach Code-Review identisch. Gültiger qualifizierter Umfang: anfänglicher vollständiger Kalenderabschluss 2025/2026, ein ursprünglicher linearer beweglicher Gegenstand oder ein Fahrzeug ohne andere Steuerwahl, Abgang oder Fortschreibung. Kombinationen und Folgejahre sind weitere Voraussetzungen.

Nachweise: `audit/skr42-r83-accepted-20261007.json`, `audit/skr42-r83-original-basis-7g-stored-native-reviewed-batch2-20261007.json`, `audit/skr42-r83-source-guards-20261007.json`, `audit/skr42-r83-final-xml-parity-20261007.json`. Gesetzesquelle: https://www.gesetze-im-internet.de/estg/__7g.html.

Die Übernahme in die laufende private Anwendung lässt alle 45 Tabellen unverändert. Der vorhandene synthetische Abschluss 2025 wurde anschließend mit ERiC-Code 0 und unveränderter XML-Prüfsumme erneut geprüft; ausschließlich sein Prüfdatensatz in `tax_filings` wurde aktualisiert. Das tatsächliche Jahr 2026 bleibt offen. Kein Neustart, keine Neuinitialisierung, keine Übermittlung.

Sieben Katalogsachverhalte und die Originalquelle/native Paketabnahme 2027 sind weiterhin offen. UI, Dokumente und Installer folgen nach XML-Abnahme. Die Navigation ohne vollständigen Seitenrefresh bleibt projektweit vorgemerkt.

## Historischer Stand R82 · 07.10.2026

Der Original-DATEV-Katalog2025 enthält sechs Konten ausschließlich für den Vorjahresvergleich:41850,74600,74640,75600,75610 und75630. Fußnote11 auf Seite31 des quellgeprüften PDFs ist als eigener Nachweis gebunden. Die ursprünglichen Jahreskataloge und alle historischen versiegelten Zuordnungsstände bleiben unverändert. Die geprüfte Revision entfernt genau die bisherige aktuelle41850-Zuordnung; die übrigen fünf Konten erhalten keine erfundene XML-Regel.

Aktuelle Katalogabdeckung: **1674/1683 für2025 (9 offen),1670/1679 für2026 (9 offen)**. Automatisch/bedingt:1043/631 beziehungsweise1040/630. Sechs Vergleichskonten sind aus der aktuellen Buchungsauswahl ausgeschlossen. Neue Kalender- und Zeitraum-Buchungen sowie manuelle aktuelle XML-Zuordnungen auf diesen Konten werden abgewiesen. Historische Buchungen bleiben lesbar und können anhand ihrer ursprünglichen Zeilen nachvollziehbar storniert werden.

44 Prüfungen mit tatsächlichen MySQL-Journalen sichern Auswahl, Buchungssperren, historische Lesbarkeit und unveränderte Originalzeilen beziehungsweise Ereignisse beim Storno. PHP und Python prüfen den Originalnachweis auch nach Aufwärmen ihrer Caches. Alle24 nativ akzeptierten Sonderabschreibungs-XMLs aus R81 bleiben bytegleich. Die alten Zweckdefinitionen und die6.10-Projektion bleiben unverändert.

Nachweise: `audit/skr42-r82-accepted-20261007.json`, `audit/skr42-r82-source-guards-20261007.json`, `audit/skr42-r82-period-source-guards-20261007.json`, `audit/skr42-r82-final-xml-parity-reviewed-20261007.json`. Originalquelle: https://help-center.apps.datev.de/api/amr/knowledge-common/v1/entities/st45264272267_de.pdf. Die neun verbleibenden Sachverhalte und die2027-Paketfreigabe sind weiterhin offen. UI, Dokumente und Installer folgen nach XML-Abnahme; die Navigation ohne vollständigen Seitenrefresh ist weiterhin projektweit vorgemerkt.

## Historischer Stand R81 · 07.10.2026

62410 und 62420 sind als eigene steuerliche Sonderabschreibung nach §7g Absatz5 eingebaut. Originalanschaffung und reguläre Buchabschreibung bleiben unverändert. Drei Originalnachweise verbinden Vorjahresgewinn, Nutzungsvoraussetzungen und gewählten Zusatzbetrag mit dem Entwurf. Die tatsächliche zusätzliche Steuerbuchung und der geänderte Restbuchwert müssen separat erfasst sein; der Nachweis erzeugt keine Buchung.

Katalogabdeckung: **1675/1689 für2025 (14 offen),1670/1679 für2026 (9 offen)**. 24 gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH bestehen die native XML-Prüfung mit ERiC-Code0;56 negative MySQL-Prüfungen sichern Nachweise und tatsächliche Steuerbuchungen. Nach dem Code Review bleiben alle24 XML-Prüfsummen exakt erhalten. Qualifiziert ist der anfängliche vollständige Kalenderabschluss2025/2026 mit einem im Jahr angeschafften beweglichen Originalgegenstand oder Fahrzeug und unverändertem linearem Buchplan. Weitere Steuerwahlen, gemischte Anlagen, Teilzuordnung, Abgänge und Vortrag benötigen eigene Abnahme.

Die fünf noch offenen Übergangskonten2025 und das bisher zugeordnete41850 sind laut Original-DATEV-Fußnote11 ausschließlich für den Vorjahresvergleich vorhanden. Die Korrektur von Katalogzählung und Buchungssperren ist noch nicht installiert; die oben genannten14/9 bleiben der aktuelle Laufzeitstand. Originalquelle: https://help-center.apps.datev.de/api/amr/knowledge-common/v1/entities/st45264272267_de.pdf, Seite31. Es wird keine100-Prozent-Freigabe behauptet.

Nachweise: `audit/skr42-r81-accepted-20261007.json`, `audit/skr42-r81-source-guards-20261007.json`, `audit/skr42-r81-final-xml-parity-20261007.json`. UI, Dokumentennavigation und Installer folgen nach der XML-Abnahme. Menüs, Untermenüs, Tabs und Ordner sollen projektweit ohne vollständiges Neuladen navigieren.2027 benötigt weiterhin Originaljahresquelle und native Paketfreigabe.

## Historischer Stand R80 · 07.10.2026

Acht Original-Pensionskonten sind mit einem eigenen Nachweis, der vorhandenen Steuerüberleitung und dem XML-Ausweis verbunden. Die Originalbuchungen bleiben unverändert. HGB verrechnet jedes belegte Paar einzeln; die Steuerbilanz verwendet unabhängig bestätigte Bruttowerte und tatsächliche Wertänderungen. Unterstützter Vermögensfall: geschütztes, ausschließlich zweckgebundenes Bankguthaben im anfänglichen vollständigen Kalenderabschluss 2025/2026.

Katalogabdeckung: **1673/1689 für 2025 (16 offen), 1668/1679 für 2026 (11 offen)**. Das sind bedingte Kontenregeln, keine allgemeine Freigabe jedes Sachverhalts. 54 gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH bestehen die native Prüfung mit ERiC-Code 0. Im Code Review wurden unvollständig begonnene Vereinbarungen und fehlerhafte Dokumentauswahlen gesperrt; alle54 XML-Prüfsummen bleiben bytegleich. Nachweise und verbleibende Konten: `XML-RESTPUNKTE-2026-10-07.md`, `audit/skr42-r80-accepted-20261007.json`.

Die Gesamtaufgabe bleibt offen. 2027 benötigt weiterhin einen Original-Jahreskatalog und einen nativ qualifizierten Ausgabeadapter. UI, Dokumentennavigation und Installer folgen nach der XML-Abnahme; die vorgemerkte Navigation ohne vollständiges Neuladen gilt projektweit. Die folgenden Revisionsstände sind historisch.

## Revision 77: Originale außerplanmäßige Abschreibung / AfaA · XML zuerst

62320, 62330 und 62660 sind für 2025/2026 als bedingte Originalregeln eingebaut.
Regelmäßige Abschreibung und zusätzliche AfaA bleiben getrennte Buchungen und
getrennte Brutto-Bewegungen des XML-Anlagenverzeichnisses. Beispiel der erfundenen
MySQL-Originalfälle: 120 Euro Anschaffung, 22 Euro regulär, 40 Euro zusätzlich,
58 Euro Restbuchwert. Buch- und Steuerbetrag werden jeweils anhand des Originals
bestätigt; gleiche Beträge werden nicht allein aus der Kontonummer abgeleitet.
Die Abnahme umfasst vollständige steuerpflichtige Kalenderabschlüsse mit
Anschaffung im jeweiligen Jahr, unverändertem linearem Einzelplan und gleichen,
unabhängig bestätigten Buch-/Steuerbeträgen. Andere Zeiträume, Teilzuordnung,
gemischte Anlagenarten, Abgänge und Folgejahresvortrag bleiben gesondert zu prüfen.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 620 | 26 |
| 2026 | 1679 | 1040 | 618 | 21 |

18 gespeicherte Originalfälle auf nativem MySQL für Verein, Stiftung und gGmbH
bestehen die native ELSTER-Prüfung. Sie durchlaufen Anschaffung, Jahresregister,
Import und öffentliche Steuervorbereitung. Sieben veränderte Originalquellen
werden beim erneuten Lesen ohne weitere Datenänderung abgewiesen. PHP und Python
stimmen centgenau überein; zwölf bisher qualifizierte Leasing-XMLs bleiben bytegleich.
Im Code Review wurden AfaA-Felder ohne Gegenkonto vor allen anderen
Anlagenmethoden gesperrt. Die Katalogüberleitung 6.10 ist wieder reproduzierbar;
das ist keine native 2027-Freigabe.

Auch die laufende private Anwendung besteht 18 native XML-Prüfungen. Alle
45 Geschäftstabellen bleiben unverändert; der vorhandene MySQL-Bestand wurde
nicht neu initialisiert. Die tatsächliche Oberfläche zeigt 1663/1689 (98,46 %)
und 1658/1679 (98,75 %). Die getrennten 16 offenen Entscheidungen des
Übungsjournals bleiben unbestätigt. **XML ist noch nicht vollständig abgeschlossen.**
UI-Feintuning, Dokumentennavigation und Installer bleiben nach der neuesten
Priorisierung zurückgestellt. [Genaue XML-Restliste](XML-RESTPUNKTE-2026-10-07.md).
Nachweise: `audit/skr42-r77-accepted-20261007.json`,
`audit/skr42-r77-private-rpc-20261007.json` und
`audit/skr42-r77-ui-readback-20261007.json`.

Die XML-Erzeugung für einen geprüften Abschluss und die vollständige Abdeckung
des SKR42 sind unterschiedliche Nachweise. Der frühere synthetische Abschluss
und die vollständige UI-Prüfung belegen keine Zuordnung aller Katalogkonten.

## Revision 76: Belegtes wirtschaftliches Eigentum bei Kaufleasing · nur MySQL

Abnahme am 07.10.2026; die am 06.10. begonnenen Nachweisdateien behalten ihren Namen.

62500 erhält eine bedingte Originalregel für regelmäßige lineare Abschreibung
eines einzeln belegten beweglichen Wirtschaftsguts. Die Zurechnung für HGB
und Steuer muss jeweils beim Verein liegen und anhand des ursprünglichen
Vertrags ausdrücklich bestätigt werden. Erwerb, Leasingverbindlichkeit,
Raten und Zinsen sind getrennt zu belegen; die Kontonummer begründet keine Zurechnung.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 617 | 29 |
| 2026 | 1679 | 1040 | 615 | 24 |

Zwölf gespeicherte native MySQL-Originalfälle bestehen für Verein, Stiftung
und gGmbH, je Fahrzeug/anderer beweglicher Gegenstand in beiden Jahren.
Sie verwenden den öffentlichen Anschaffungs-, Jahresimport- und Steuer-
Speicherweg. Die sieben aktuellen Änderungen an Vertragsdatei, Zurechnung,
Jahresregister, Quellimport, Quellhistorie, Rechnung und Beleglink werden abgewiesen.
Im Code Review wurde ein ungeprüfter Weg zu abweichenden Steuer-Nutzungsdauern
bei Kaufleasing gefunden und vor der Übernahme in PHP und Python gesperrt.
Die beiden ursprünglichen Gegenfälle und die unveränderten Originalwerte
für 2025/2026 sind als Regression dauerhaft enthalten.

Vierzehn Vertragsgrenzen, vier neue MySQL-Ansichten, 804 datenbankfreie
Ansichtsprüfungen und sieben gezielte Prüfprogramme bestehen auf dem finalen Stand.
Die neue Vertragsansicht ist zusätzlich auf 390 × 844 Pixel geprüft: Belegauswahl
und drei unbestätigte Checkboxen sind erreichbar und lesbar.
Der Anlagenartenvergleich prüft den seit Revision 73 getrennten Sammelpostenweg
gegen dessen eigene Originalverträge; 06750 bleibt für normale lineare Abschreibung gesperrt.

In der laufenden privaten Anwendung bestehen zwölf weitere native XML-Prüfungen
bei identischen PHP-Originalwerten und unveränderten 45 Geschäftstabellen.
Nach Ende der lokalen Prüfanwendung wurde der vorhandene private Quellbaum
mit den bestehenden MySQL-Daten wieder aufgenommen. Alle 45 Geschäftstabellen
stimmen mit dem letzten Snapshot überein; keine Daten wurden neu initialisiert.
Nachweis: `audit/manager-colocated-resume-20261007.json`.

Ältere XML-Nachweise werden nicht als Vollwiederholung auf Revision 76 ausgegeben.
Die Qualifikation gilt für vollständige steuerpflichtige Kalenderabschlüsse
2025/2026 mit ursprünglicher Anschaffung im jeweiligen Jahr und gleichen
Buch-/Steuerwerten. Teilzuordnung, andere Zeiträume, Abgänge, Sonderabschreibung
und Folgejahresvortrag bleiben außerhalb dieser Qualifikation.

Nachweise: `audit/skr42-r76-final-original-mysql-reviewed-qualification-20261007.json`,
`audit/skr42-r76-private-rpc-20261006.json`, `audit/skr42-r76-ui-readback-20261006.json`.
Fachquelle: [BMF Leasingerlasse](https://ao.bundesfinanzministerium.de/esth/2023/C-Anhaenge/Anhang-21/I/inhalt.html).

**29 Originalkonten für 2025 und 24 für 2026 bleiben offen.**
Die 16 ungeprüften Konten des tatsächlichen Übungsjournals sind separat sichtbar.
Originaljahr 2027 und native 6.10 bleiben unqualifiziert.

## Revision 75: Einzeln bestätigtes unbebautes Grundstück · nur MySQL

Das originale Sammelkonto 02000 erhält eine bedingte Regel ausschließlich für
ein anhand des ursprünglichen Vertrags einzeln bestätigtes unbebautes Grundstück.
Anschaffungsbeleg, Anlagenplan, Originalimporte, bestätigte Jahresbewertung und
vollständige Buchungen müssen übereinstimmen. HGB und Steuerwerte bleiben
unabhängig. Bebaute Grundstücke, Rechte und Substanzverzehr sind nicht freigegeben.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 616 | 30 |
| 2026 | 1679 | 1040 | 614 | 25 |

Die aktuelle Qualifikation verwendet ausschließlich MySQL: zwölf gespeicherte
native Originalfälle für Verein, Stiftung und gGmbH in beiden Jahren bestehen;
alle sieben Änderungen an Originalquellen werden beim erneuten Lesen abgewiesen.
Sechs Quellenprüfungen und vier neue Ansichten laufen auf MySQL. Dazu bestehen
804 datenbankfreie Ansichtsprüfungen und vier gezielte Prüfprogramme. Ältere
Dateidatenbank-Nachweise bleiben als Historie erhalten und zählen nicht zur Freigabe.
Die 612 früheren XML aus Revision 74 wurden nicht erneut auf Revision 75 geprüft.

In der laufenden privaten Anwendung bestehen zwölf weitere native XML-Prüfungen
bei identischen PHP-Originalwerten. Die 45 Geschäftstabellen bleiben unverändert.
Der Weg gilt für vollständige steuerpflichtige Kalenderabschlüsse 2025/2026
mit ursprünglicher Anschaffung im jeweiligen Jahr. Andere Zeiträume, Teilzuordnung
und Folgejahresvorträge benötigen weiterhin gesonderte Quellenqualifikation.

Nachweise: `audit/skr42-r75-final-original-mysql-qualification-20261006.json`,
`audit/skr42-r75-private-rpc-20261006.json`,
`audit/skr42-r75-ui-readback-20261006.json`.

**30 Originalkonten für 2025 und 25 für 2026 bleiben offen.**
Die 16 ungeprüften Konten des tatsächlichen Übungsjournals sind separat sichtbar.
Originaljahr 2027 und native 6.10 bleiben unqualifiziert.

## Revision 74: Ursprünglicher Rückdeckungsanspruch ohne Saldierung

13800 und 13820 erhalten bedingte Originalregeln für 2025/2026. Der Weg
verlangt eine ausdrücklich belegte langfristige Rückdeckungsforderung ohne
Saldierungsvoraussetzung, den unveränderten Anschaffungsbeleg, vollständige
Originaljournale und unabhängige HGB-/Steuerbewertungen. Die Verpflichtung
selbst und saldierungspflichtiges Deckungsvermögen werden hier nicht freigegeben.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 615 | 31 |
| 2026 | 1679 | 1040 | 613 | 26 |

48 gespeicherte native Originalfälle bestehen für Verein, Stiftung und gGmbH,
je beide Konten und Jahre mit unveränderter Bewertung sowie unabhängig
belegter HGB-Wertminderung bei abweichendem Steuerwert. Dateidatenbank und isoliertes
MySQL werden geprüft. Alle 14 Änderungen an Vertragsbytes, aktuellem Plan,
Jahreswerten, Originalimport, Quellhistorie, Anschaffungsrechnung und Beleglink
werden beim erneuten Lesen des gespeicherten Steuerentwurfs abgewiesen.

Sechs Vertrags-/Quellenprüfungen, acht neue Quellansichten, 804 bestehende
Ansichtsprüfungen und zwei Laufzeitprüfungen bestehen. Elf vollständige
Regressionstestprogramme und 612 byteidentische frühere XML wurden auf dem
eingefrorenen Regressionskandidaten geprüft; die anschließenden Änderungen
betreffen Quell-Neulesen, den Schlüssel des neuen Profils und die Oberfläche
und sind separat dokumentiert. Sie werden nicht als Vollwiederholung ausgegeben.
24 erneute PHP-Originalwertprüfungen und native XML-Aufrufe in der laufenden
privaten Anwendung bestehen. Die 45 Geschäftstabellen bleiben unverändert.

Die Qualifikation gilt für vollständige steuerpflichtige Kalenderabschlüsse
mit ursprünglicher Anschaffung im jeweiligen Jahr. Andere Anlagenarten,
Saldierung, andere Perioden, Teilzuordnung oder Folgejahresvortrag brauchen
eine eigene Quellenprüfung. Keine automatische §-8b-Korrektur wird unterstellt.

Nachweise: `audit/skr42-r74-accepted-20261006.json`,
`audit/skr42-r74-private-rpc-20261006.json`,
`audit/skr42-r74-ui-readback-20261006.json`.

**31 Originalkonten für 2025 und 26 für 2026 bleiben offen.**
Die 16 ungeprüften Konten des tatsächlichen Übungsjournals sind separat sichtbar.
Originaljahr 2027 und native 6.10 bleiben unqualifiziert.

## Revision 73: Ursprüngliche Sammelpostenwahl und Einzelregister

06750 und 62640 erhalten bedingte Regeln für die originalen Kalenderjahre
2025 und 2026. Vollständige Anschaffungen, ursprüngliche einheitliche Jahreswahl,
separates Steuerregister, unabhängiges HGB-Einzelregister und beide vollständigen
Originaljournale werden erneut geprüft. Netto, Umsatzsteuer und tatsächlich
abziehbare Vorsteuer bleiben getrennt. Die Einzelgegenstände bleiben erhalten;
nur ihr steuerlicher Ausweis wird zum ursprünglichen Sammelposten zusammengefasst.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 613 | 33 |
| 2026 | 1679 | 1040 | 611 | 28 |

22 Quellen-/Vertragsprüfungen und 24 positive native XML-Fälle bestehen:
je drei Rechtsformen, beide Bildungsjahre und der tatsächliche Jahresvortrag,
mit gespeicherten Originalquellen auf Dateidatenbank und isoliertem MySQL.
42 veränderte Vorjahresbelege/Freigaben/Empfangsnachweise werden atomar abgewiesen.
Acht Quellansichten, zwei öffentliche Formularwege, 804 bestehende Anzeigeprüfungen,
elf vollständige Regressionstestprogramme und 612 byteidentische frühere XML bestehen.
Neun erneute PHP-Quellprüfungen und native Aufrufe in der laufenden privaten
Anwendung bestehen. Geschäftstabellen bleiben unverändert.

Die Qualifikation gilt für einen vollständigen steuerpflichtigen Kalenderabschluss
mit einer Jahreskohorte, Anschaffung am 1. Januar, unabhängig belegter
HGB-Nutzungsdauer von 60 Monaten, gleichen HGB-/Steuerwerten und ohne Abgänge.
Andere Nutzungsdauern, gemischte Kohorten, Wertdifferenzen, Teilzuordnungen und
Perioden benötigen eine eigenständige Überleitung. Bestätigungen werden nicht
vorausgewählt; unbekannte Steuerwerte bleiben unbekannt. Empfangsbelege in den
isolierten Vortragsprüfungen sind ausdrücklich synthetisch und kein Nachweis
einer tatsächlichen Übermittlung.

Nachweise: `audit/skr42-r73-accepted-20261006.json`,
`audit/skr42-r73-private-rpc-20261006.json`,
`audit/skr42-r73-ui-readback-20261006.json`.

**33 Originalkonten für 2025 und 28 für 2026 bleiben ohne XML-Regel;
der Gesamtauftrag ist unvollständig.** Originaljahr 2027, native 6.10 und
weitere bisher begrenzte Sonderfälle bleiben offen.

## Revision 72: Selbst geschaffene immaterielle Werte mit getrennten Originalnachweisen

01440, 01450, 01460, 01470, 01480, 48250, 62010 und 62110 erhalten bedingte,
beleggebundene Jahresregeln. Ursprungskosten, tatsächlich bestätigte HGB-Register
und vollständige Journale bleiben getrennt von der unabhängig belegten
steuerlichen Nichtaktivierung. Ein unbekannter Steuerwert bleibt unbekannt.
HGB-Bruttospiegel, Abschreibungen, Wertminderungen, Wertaufholungen und vollständige
Abgänge werden aus den Originalbewegungen erneut berechnet. Fertige Werte und
Entwicklungsbestände erscheinen als nicht addierte Davon-Angaben.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 611 | 35 |
| 2026 | 1679 | 1040 | 609 | 30 |

44 Quellen-/Dienstprüfungen, 75 positive native XML-Fälle, 15 tatsächliche
MySQL-Vorjahres-/Formularfälle mit fünf atomaren Zurückweisungen bestehen.
Die 30 MySQL-XML stimmen bytegenau mit den nativen Ausgaben überein.
612 frühere repräsentative XML bleiben byteidentisch. Elf vollständige
Regressionstestprogramme und 804 Anzeigeprüfungen bestehen. Die laufende private
Anwendung besteht 30 native Aufrufe mit identisch erneut geprüften PHP-Werten
und die 804 Anzeigeprüfungen. Geschäftstabellen bleiben unverändert.

Die Qualifikation umfasst einen vollständigen Originalgegenstand im jährlichen
Gesamtabschluss 2025/2026 beziehungsweise dessen vollständig freigegebene
Zuordnung zu den steuerpflichtigen Betrieben und gemeinsamen HGB-/Steuerausweis.
Teilzuordnungen, mehrere Projekte, gemeinsame Gegenkonten, gemischte Anlagen und
Periodenausweise benötigen eigene Adapter und erhalten keine pauschale Freigabe.
Originale Vorjahresabgaben und Freigaben werden geprüft; ausdrücklich synthetische
Empfangsbelege in isolierten Tests behaupten keine tatsächliche Übermittlung.

Nachweise: `audit/skr42-r72-accepted-20261006.json`,
`audit/skr42-r72-private-rpc-20261006.json`,
`audit/skr42-r72-ui-readback-20261006.json`.

**35 Originalkonten für 2025 und 30 für 2026 bleiben ohne XML-Regel;
der Gesamtauftrag ist unvollständig.** Die Vorbereitung 6.10 umfasst 658 geeignete
und 21 zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe
bleiben offen. Frühere unveränderliche Kontenzwecke und Jahresquellen bestehen.

## Revision 71: Originaler Kapitalansatz für Personengesellschaftsanteile

08030, 08050, 08400 und 08600 erhalten bedingte, quellengestützte XML-Regeln.
Auch 08000, 08090 und 08200 können ausdrücklich belegte Personengesellschafts-
anteile über den getrennten Kapitalansatz führen. Ihre bisherigen Regeln für
Kapitalgesellschaftsanteile bleiben erhalten. Originaler HGB-Plan, tatsächliche
Belegbytes, geschlossene Buchungsjournale und ein unabhängig belegter steuerlicher
Anlagenspiegel werden erneut geprüft. Die Oberfläche erfasst HGB-Bewegungen und
steuerliche Originalspalten getrennt; fehlende Werte bleiben unbekannt.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 603 | 43 |
| 2026 | 1679 | 1040 | 601 | 38 |

54 Quellen-/Dienstprüfungen, 168 positive native XML-Fälle, ein erkannter
Ein-Cent-Fehler und 70 Prüfungen des Anlagenregisters auf MySQL 8.4 bestehen.
Die Fälle umfassen 84 tatsächliche gespeicherte Originalquellen, 42 tatsächliche
Formularspeicherungen und 21 tatsächliche Vorjahresübernahmen; deren ausdrücklich
synthetische Empfangsbelege prüfen Bindungsregeln und behaupten keine Übermittlung.
Acht beschädigte Vorjahresquellen werden ohne Änderung zurückgewiesen.
612 bisherige repräsentative XML bleiben byteidentisch. Elf vollständige
Regressionstestprogramme, 804 Anzeigeprüfungen sowie 42 native Aufrufe mit den
installierten PHP-Originalwerten bestehen. Geschäftstabellen bleiben unverändert.

Der qualifizierte Ansatz ist auf positive ursprüngliche Kapitalwerte und
vollständige Jahresjournale der steuerpflichtigen Gesamtkörperschaft 2025/2026
begrenzt. Bereichsaufteilungen, Teilperioden, Verbundberichte, negative Ansätze,
Mehrzeilenbuchungen und Kapitalberichtigungen benötigen weitere eigene Adapter.
Diese Fälle erhalten durch die neue Kontenregel keine pauschale Exportfreigabe.

Nachweise: `audit/skr42-r71-accepted-20261006.json`,
`audit/skr42-r71-private-rpc-20261006.json`,
`audit/skr42-r71-ui-readback-20261006.json`.

**43 Originalkonten für 2025 und 38 für 2026 bleiben ohne XML-Regel; der
Gesamtauftrag ist unvollständig.** Die Vorbereitung 6.10 umfasst 650 geeignete
und 21 zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe
bleiben offen. Selbst geschaffene immaterielle Werte werden separat bearbeitet.

## Revision 70: Sammelkonten mit ausdrücklich bestätigten Kapitalgesellschaftsanteilen

08000, 08090 und 08200 erhalten einen beleggebundenen Bewertungsweg für
ausdrücklich bestätigte Kapitalgesellschaftsanteile. Originaler Anschaffungsplan,
Buch- und Steuerwertminderungen, Teilabgänge, Zuschreibungen und Folgejahresabgänge
bleiben vollständig erhalten. 08090 führt zusätzlich die nicht additive
Davon-Angabe der Anteile am herrschenden Unternehmen; bestehende 08080-Werte
bleiben enthalten. Der Emittentensitz begründet keine ausländische Betriebsstätte.
Personengesellschaftsanteile können diesen Kapitalgesellschaftsvertrag nicht
verwenden. Deren eigenständiger steuerlicher Kapitalansatz bleibt gesondert offen.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 599 | 47 |
| 2026 | 1679 | 1040 | 597 | 42 |

180 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird
erkannt. 612 frühere repräsentative XML bleiben byteidentisch. Elf vollständige
gezielte Testprogramme und 804 Anzeigeprüfungen mit 60 Zuordnungszeilen und
60 Buch-/Steuerzeilen bestehen. Die laufende private Anwendung besteht 36 native
RPC-Fälle und dieselben Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen
bleiben unverändert. Keine vollständige historische Wiederholung der nativen
Prüfreihen wird behauptet.

Nachweise: `audit/skr42-r70-accepted-20261006.json`,
`audit/skr42-r70-private-rpc-20261006.json`,
`audit/skr42-r70-ui-readback-20261006.json`.

**47 Originalkonten für 2025 und 42 für 2026 bleiben ohne XML-Regel. Der
Gesamtauftrag ist unvollständig.** Zusätzliche noch nicht unterstützte
Personengesellschaftsfälle auf Sammelkonten sind damit nicht erledigt.
Die Vorbereitung 6.10 umfasst 646 geeignete und 21 zurückgestellte Zwecke.
Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 69: Typisch stille Beteiligungen mit getrenntem Buch- und Steuerausweis

08300 erhält zwei ausdrücklich bestätigte handelsrechtliche Vertragsarten:
Beteiligung oder Ausleihung. Steuerlich wird die unabhängig bestätigte
langfristige Kapitalforderung ausgewiesen. Die vollständige ursprüngliche
Kostenbewertung wird vor der getrennten Darstellung geprüft. Anschaffung,
Wertminderung, Teilabgang, Zuschreibung und Folgejahresabgang sowie eigenständig
bestätigte steuerliche Beträge bleiben erhalten. Unterschiedliche Vertragsarten
benötigen getrennte Originalkonten; eine künstliche Kontenaufteilung unterbleibt.
Keine Mitunternehmerstellung, Steuerfreiheit oder Abzugsquote wird abgeleitet.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 596 | 50 |
| 2026 | 1679 | 1040 | 594 | 45 |

60 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird
erkannt. 552 frühere repräsentative XML bleiben byteidentisch. Elf vollständige
gezielte Testprogramme und 284 Anzeigeprüfungen mit 20 Zuordnungszeilen und
20 Buch-/Steuerzeilen bestehen. Die laufende private Anwendung besteht zwölf
native RPC-Fälle und dieselben Anzeigeprüfungen. Alle 45 geschützten
Geschäftstabellen bleiben unverändert. Keine vollständige historische
Wiederholung der nativen Prüfreihen wird behauptet.

Nachweise: `audit/skr42-r69-accepted-20261006.json`,
`audit/skr42-r69-private-rpc-20261006.json`,
`audit/skr42-r69-ui-readback-20261006.json`.

**50 Originalkonten für 2025 und 45 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 643 geeignete und 21
zurückgestellte Zwecke. Die neue Zuordnung bleibt wegen der geänderten amtlichen
Handelsbilanzposition in 6.10 zurückgestellt. Originaljahr 2027 und dessen
native Freigabe bleiben offen.

## Revision 68: Tatsächliche Gewinn- und Verlustanteile aus Beteiligungen

70030, 70040, 70080 und 72080 erhalten getrennte handelsrechtliche und
steuerliche Positionen. Bestätigte Originalwerte werden beibehalten; die
Handelsbilanz zeigt den tatsächlichen Beteiligungserfolg und die gesonderte
Davon-Angabe für verbundene Unternehmen. Die Steuerbilanz enthält den
unabhängig bestätigten Mitunternehmeranteil. Beteiligungsbestände und
Kapitalkonten bleiben zusätzlich vollständig nachweispflichtig. Keine
Mitunternehmerstellung, Steuerfreiheit, Gewerbesteuerkürzung, Verlustabzugsfähigkeit
oder Quote wird aus der Kontonummer abgeleitet.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 595 | 51 |
| 2026 | 1679 | 1040 | 593 | 46 |

100 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird
erkannt. 514 frühere repräsentative XML bleiben byteidentisch. Elf vollständige
gezielte Testprogramme und 264 Anzeigeprüfungen mit acht Zuordnungszeilen und
48 Buch-/Steuerzeilen bestehen. Im Testkatalog wurde ausschließlich der fehlende
Eintrag des neuen Testprogramms ergänzt. Die ursprüngliche fehlgeschlagene
Inventarprüfung bleibt dokumentiert; unveränderte Laufzeitquellen sowie alle
100 erneut rekonstruierten Original-XML-Hashes sichern die fortgeltenden nativen
Nachweise. Der vollständige Inventartest besteht nach dieser Korrektur.
Die laufende private Anwendung besteht 20 native RPC-Fälle und die Anzeigeprüfungen.
Alle 45 geschützten Geschäftstabellen bleiben unverändert.

Nachweise: `audit/skr42-r68-accepted-20261006.json`,
`audit/skr42-r68-test-inventory-repair-20261006.json`,
`audit/skr42-r68-private-rpc-20261006.json`,
`audit/skr42-r68-ui-readback-20261006.json`.

**51 Originalkonten für 2025 und 46 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 643 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 67: Atypisch stille Gewinnabführung und Verlustausgleich

73980 erhält für beide Originaljahre eine vorzeichenabhängige Handelsbilanzregel.
Tatsächliche Gewinnabführung und Verlustausgleich werden getrennt ausgewiesen.
Die steuerliche Behandlung verlangt eine unabhängig bestätigte Überleitung
auf tatsächliche Kapitalkonten einschließlich bestätigter Kapitalbewegungen.
Originalsalden, Soll-/Habenbewegungen und Jahresüberträge bleiben erhalten.
Aus dem Konto werden weder Beteiligtenstatus noch Steuerquoten abgeleitet.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 591 | 55 |
| 2026 | 1679 | 1040 | 589 | 50 |

34 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird
erkannt. 663 frühere repräsentative XML bleiben byteidentisch. Elf vollständige
gezielte Testprogramme und 142 Anzeigeprüfungen mit sechs Zuordnungszeilen und
16 tatsächlichen Buch-/Steuerzeilen bestehen. Die laufende private Anwendung
besteht zehn native RPC-Fälle und dieselben Anzeigeprüfungen. Alle 45
geschützten Geschäftstabellen bleiben unverändert.

Nachweise: `audit/skr42-r67-accepted-20261006.json`,
`audit/skr42-r67-private-rpc-20261006.json`,
`audit/skr42-r67-ui-readback-20261006.json`.

**55 Originalkonten für 2025 und 50 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 639 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 66: Originale Sonderposten und Investitionszulagen

29840, 29860 und 29870 sind für beide Originaljahre ergänzt. Belegte
Sonderposten mit Rücklageanteil bleiben in der erfolgsneutralen Passivgruppe;
ihre tatsächlichen Altbestände dürfen fortgeführt oder aufgelöst werden.
Eine neue Zuführung wird daraus nicht abgeleitet. Investitionszulagen benötigen
ihre tatsächlich bestätigte Behandlung aus Bewilligung und Verwendung.
Originalbuchungen, Auflösungen, unabhängige Steuerumbuchungen und Überträge
bleiben erhalten. Keine Steuerfreiheit, Förderberechtigung, Sonderabschreibung
oder Abzugsquote wird aus der Kontonummer abgeleitet; kein Bestand wird ersetzt.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 590 | 56 |
| 2026 | 1679 | 1040 | 588 | 51 |

99 Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird erkannt.
1076 frühere repräsentative XML bleiben byteidentisch. Elf vollständige gezielte
Testprogramme und 336 Anzeigeprüfungen mit 12 Zuordnungszeilen und 48 tatsächlichen
Buch-/Steuerzeilen bestehen. Die laufende private Anwendung besteht zusätzlich
27 repräsentative native RPC-Fälle und dieselben Anzeigeprüfungen. Alle 45
geschützten Geschäftstabellen bleiben unverändert.

Nachweise: `audit/skr42-r66-accepted-20261006.json`,
`audit/skr42-r66-private-rpc-20261006.json`,
`audit/skr42-r66-ui-readback-20261006.json`.

**56 Originalkonten für 2025 und 51 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 638 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 65: Tatsächliche Anzahlungsverrechnung und Bereichsausweis

14970 und 36950 sind für beide Originaljahre ergänzt. Ihre tatsächlichen
Soll-/Habensalden, Gegenkonten und Originalbuchungen bleiben erhalten.
Die Wahl zwischen Forderung, geleisteter Anzahlung und Verbindlichkeit
erfordert die belegte Art und Richtung. Anlagenanzahlungen und die Nettierung
empfangener Anzahlungen benötigen echte gesonderte Abschlussumbuchungen.
Steuerwerte entstehen ausschließlich aus der unabhängig bestätigten Überleitung.

Bei datierten Bereichsübersichten werden nun die bereits registrierten
richtungsgerechten Darstellungen auch für Bereichssummen verwendet. Die
ursprünglichen Kontenzeilen und Originalbuchungen ändern sich dadurch nicht.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 587 | 59 |
| 2026 | 1679 | 1040 | 585 | 54 |

252 Fälle bestehen die amtliche Prüfung: 236 neue Anzahlungsfälle und 16
Fälle der vorhandenen Verrechnungskonten 13700 und 36300. Ein Ein-Cent-Fehler
wird erkannt. 824 frühere repräsentative XML werden byteidentisch rekonstruiert.
14 vollständige gezielte Testprogramme und 488 Anzeigeprüfungen mit 12
Zuordnungszeilen und 32 tatsächlichen Buch-/Steuerzeilen bestehen. Die laufende
private Anwendung besteht zusätzlich 72 repräsentative native RPC-Fälle und
dieselben Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen bleiben unverändert.

Nachweise: `audit/skr42-r65-accepted-20261006.json`,
`audit/skr42-r65-private-rpc-20261006.json`,
`audit/skr42-r65-ui-readback-20261006.json`.

**59 Originalkonten für 2025 und 54 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 635 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 64: Vertragliches Genussrechtskapital und atypisch stille Schulden

29790 (Genussrechtskapital mit Eigenkapitalcharakter) sowie 35300, 35310,
35320 und 35330 (Verbindlichkeiten gegenüber atypisch stillen Gesellschaftern)
sind für beide Originaljahre ergänzt. Die ausschließlich handelsrechtlichen
Originalpositionen und ihre belegten Laufzeitangaben bleiben erhalten.
Der steuerliche Ausweis wird ausschließlich aus tatsächlich bestätigten
Eröffnungs- und laufenden Steuerumbuchungen auf eigenständigen Konten und
vollständig bestätigten Kapitalbewegungen rekonstruiert. Kein Steuerwert
wird durch null ersetzt; keine Eigen-/Fremdkapitaleinordnung oder steuerliche
Abzugsquote wird aus der Kontonummer abgeleitet.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 585 | 61 |
| 2026 | 1679 | 1040 | 583 | 56 |

80 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler
wird erkannt. 744 frühere repräsentative XML werden byteidentisch rekonstruiert.
Zehn vollständige gezielte Testprogramme und 984 Anzeigeprüfungen in 80 echten
Buch-/Steuerkontenzeilen sowie beiden Jahreskatalogen bestehen. Die laufende
private Anwendung besteht zusätzlich 40 repräsentative native RPC-Fälle und
dieselben Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.

Nachweise: `audit/skr42-r64-accepted-20261006.json`,
`audit/skr42-r64-private-rpc-20261006.json`,
`audit/skr42-r64-ui-readback-20261006.json`.

**61 Originalkonten für 2025 und 56 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 633 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 63: Originales Gegenkonto verbundener Finanzanlagen

72070 (Abschreibungen auf Finanzanlagen verbundener Unternehmen) ist für
beide Originaljahre ergänzt. Das Originalkonto bleibt nur an tatsächlich
belegten Wertminderungsereignissen der Anlagen 08040, 08080, 08100 und 08140
mit eindeutigem Profil gebunden. Buch- und Steuerwerte, Dauerhaftigkeit,
steuerliche Wahlentscheidung und spätere Wertaufholung bleiben getrennt
an den vollständigen ursprünglichen Anlagenplänen erhalten. Keine
steuerliche Abzugsquote wird aus Konto oder Profil abgeleitet.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 580 | 66 |
| 2026 | 1679 | 1040 | 578 | 61 |

224 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler
wird erkannt. 520 frühere repräsentative XML werden byteidentisch rekonstruiert.
Zehn vollständige gezielte Testprogramme und 704 Anzeigeprüfungen in 56 echten
Kontenzeilen, beiden Jahreskatalogen und der ursprünglichen Ereigniseingabe
bestehen. Die laufende private Anwendung besteht zusätzlich 112 repräsentative
native RPC-Fälle und dieselben Anzeigeprüfungen. Alle 45 geschützten
Geschäftstabellen sind unverändert.

Nachweise: `audit/skr42-r63-accepted-20261006.json`,
`audit/skr42-r63-private-rpc-20261006.json`,
`audit/skr42-r63-ui-readback-20261006.json`.

**66 Originalkonten für 2025 und 61 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 628 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 62: Tatsächlich abgeschlossene technische Originalkonten

14980 (Kostenstellenüberleitung) und 39500 (vorläufige pauschale
BWA-Abschreibung) sind für beide Originaljahre ergänzt. Die tatsächlichen
Eröffnungs- und Abschlussbestände müssen im Buch- und Steuerjournal sowie
in jedem Vereinsbereich ausgeglichen sein; tatsächliche Soll- und Haben-
Umsätze bleiben unverändert und gleich. Unfertige Überleitungen und spätere,
außerhalb des Abschlusszeitraums liegende Gegenbuchungen werden abgewiesen.
Kein ursprünglicher Bestand wird durch null ersetzt. Die endgültige
Abschreibung bleibt an ihren vollständigen echten Anlagenplänen gebunden.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 579 | 67 |
| 2026 | 1679 | 1040 | 577 | 62 |

32 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler
wird erkannt. 488 frühere repräsentative XML werden byteidentisch rekonstruiert.
Zehn vollständige gezielte Testprogramme und 552 Anzeigeprüfungen in 48 echten
Kontenzeilen und beiden Jahreskatalogen bestehen. Die laufende private
Anwendung besteht zusätzlich alle 32 nativen RPC-Fälle und dieselben
Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.

Nachweise: `audit/skr42-r62-accepted-20261006.json`,
`audit/skr42-r62-private-rpc-20261006.json`,
`audit/skr42-r62-ui-readback-20261006.json`.

**67 Originalkonten für 2025 und 62 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 627 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 61: Originale Debitoren- und Kreditorenaufteilung

12580, 12590, 32490, 33490 und 35990 sind für beide Originaljahre ergänzt.
Die unveränderten Gegenbeträge mindern die ausdrücklich bestätigte Originalposition
des tatsächlichen Grundkontos 12000, 32100, 33000 oder 35700. Grundbestand und
zugehörige aufgegliederte Originalbestände müssen die Gegenbeträge einschließlich
Eröffnung getrennt im Buch- und Steuerbestand sowie in jedem Vereinsbereich decken.
Falsche Vorzeichen, ungedeckte Beträge und fremde Grundpositionen werden abgewiesen.
Es werden weder echte Bestände auf null gesetzt noch neue Schulden oder Ansprüche
aus den Gegenkonten erfunden. Die neuen Rechenmodule einschließlich der vorherigen
Originalabzugs- und Abschreibungshelfer sind Teil der XML-Freigabeidentität;
geänderte oder fehlende Quellen machen alte Freigaben ungültig.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 577 | 69 |
| 2026 | 1679 | 1040 | 575 | 64 |

176 neue Originalfälle über alle begrenzten Zielvarianten bestehen die amtliche
Prüfung; ein Ein-Cent-Fehler wird erkannt. 384 frühere repräsentative XML bleiben
byteidentisch. Elf vollständige erfolgreiche Programme behalten identische
getestete Quellen; der explizite Suitekatalog wurde nach einem Ordnungsfehler
sortiert und dessen vollständiges zwölftes Programm erneut ausgeführt. Die
nativen Nachweise bleiben nur bei identischen Rechenquellen, Daten, Fixtures
und ausführbaren Tests gültig; keine zusätzlichen nativen Aufrufe oder erneute
XML-Rekonstruktion wegen der bloßen Suite-Reihenfolge werden behauptet.
2664 Anzeigeprüfungen betreffen 264 echte Kontenzeilen und beide Jahreskataloge.
Die laufende private Anwendung besteht zusätzlich 80 repräsentative native
RPC-Prüfungen über alle fünf Konten, beide Jahre, Eröffnungszustände, Kalender-
und Zeitraumabschlüsse sowie beide Berichtsumfänge an der ersten bestätigten
Grundposition. Alle 45 geschützten Geschäftstabellen sind unverändert.
Die Browseranzeige bestätigt die erhöhte vollständige Katalogabdeckung.

Nachweise: `audit/skr42-r61-accepted-20261006.json`,
`audit/skr42-r61-private-rpc-20261006.json`,
`audit/skr42-r61-ui-readback-20261006.json`.

**69 Originalkonten für 2025 und 64 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 625 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 60: Originale Fahrzeug- und aktivierte GWG-Abschreibung

62220 und 62620 sind für beide Originaljahre ergänzt. Ein optionales, ausdrücklich
gewähltes Originalkonto bleibt im einzelnen Anlagenplan erhalten. 62220 erfordert
05200, 05250, 05400 oder 05600; 62620 erfordert ein regulär linear abgeschriebenes,
aktiviertes Wirtschaftsgut auf 06700. Historische Pläne ohne dieses Feld behalten
ihre geprüften Gegenkonten. Mischbestände werden auf 62200, 62220, 62600 und 62620
einzeln abgestimmt; Buch- und Steuer-Nutzungsdauern bleiben getrennt. Unzulässige
Anlagenarten, Sofortabschreibungen und manipulierte Gegenkonten werden abgewiesen.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 572 | 74 |
| 2026 | 1679 | 1040 | 570 | 69 |

96 neue Originalfälle bestehen die amtliche Prüfung; ein Ein-Cent-Fehler wird
erkannt. Zwei veraltete historische Testannahmen wurden nach ihren separat
geprüften Revisionen aktualisiert und beide Programme erneut vollständig
ausgeführt. Neun weitere erfolgreiche Programme bleiben bei unveränderten
getesteten Quellen gültig. Die nativen Nachweise wurden nach byteidentischer
XML-Rekonstruktion übernommen; keine erneuten nativen Aufrufe behauptet.
384 frühere repräsentative XML bleiben byteidentisch. 1724 Anzeigeprüfungen
betreffen 168 echte Kontenzeilen, die Originalkontenauswahl und den Jahreskatalog.
Die laufende private Anwendung besteht zusätzlich 96 native RPC-Prüfungen und
dieselben Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.
Die neue Browseranzeige zeigt die tatsächliche vollständige Katalogabdeckung
einschließlich der offenen Originalkonten. Demojournal-Zuordnung und amtliche
XML-Freigabe werden ausdrücklich getrennt angezeigt.

Nachweise: `audit/skr42-r60-accepted-20261006.json`,
`audit/skr42-r60-private-rpc-20261006.json`,
`audit/skr42-r60-ui-readback-20261006.json`.

**74 Originalkonten für 2025 und 69 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 620 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 59: Erhaltene Anzahlungen und verrechnete Stoffkosten

11900 und 58600 sind für beide Originaljahre ergänzt. Anzahlungen behalten
ihren tatsächlichen Habensaldo im Journal und werden in der XML-Position
für den offenen Vorratsabzug vorzeichenrichtig dargestellt. Die zugehörigen
Vorräte müssen einschließlich Eröffnung gedeckt sein. Verrechnete Stoffkosten
benötigen Originalgrundkosten auf 50000–50999 und dieselbe ausdrücklich
gewählte Material-/Waren- und Umsatzsteuerposition. Ungedeckte Gegenbeträge,
abweichende Positionswahl und ungedeckte einzelne Vereinsbereiche werden abgewiesen.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 570 | 76 |
| 2026 | 1679 | 1040 | 568 | 71 |

120 neue Jahres-/Zeitraumfälle bestehen die amtliche Prüfung; ein absichtlicher
Ein-Cent-Fehler wird erkannt. Nach Korrektur einer veralteten Anzeigezählung
werden diese nativen Ergebnisse nur bei unveränderten Laufzeitquellen und
byteidentisch rekonstruiertem XML übernommen. 384 frühere repräsentative XML
bleiben byteidentisch. Acht vollständige gezielte Programme bestehen; fünf
erfolgreiche Methoden der Gegenkontenprüfung werden bei unveränderten Quellen
übernommen, deren korrigierte Anzeigemethode wurde erneut vollständig ausgeführt.
1800 Anzeigeprüfungen betreffen 180 echte PHP-Komponentenzeilen. Die laufende
private Anwendung besteht zusätzlich 120 native RPC-Prüfungen und dieselben
Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.
Die echte Browserprüfung bestätigt die weiterhin ausgeglichene Demo-Bilanz;
die neuen Konten sind in diesem unveränderten Demojournal nicht bebucht.

Nachweise: `audit/skr42-r59-accepted-20261006.json`,
`audit/skr42-r59-private-rpc-20261006.json`,
`audit/skr42-r59-ui-readback-20261006.json`.

**76 Originalkonten für 2025 und 71 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 618 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 58: Finanzmittelfonds und handelsrechtliche Rückstellungen

Die Originalkonten 30920, 48430 und 68830 sind für beide Jahre ergänzt;
30980 ist nur im Originaljahr 2025 buchbar. Finanzmittelfonds werden anhand
der tatsächlichen Bewertung ausdrücklich als Kurs-/Währungsänderung oder
andere Bewertungsänderung zugeordnet. Für nicht abgesicherte Drohverluste
und beibehaltene Alt-Aufwandsrückstellungen bleiben die Handelswerte erhalten;
die Steuerwerte erfordern vollständig gebuchte und bestätigte Überleitungen.
Neue Zuführungen zum Altbestand sind ausgeschlossen.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 568 | 78 |
| 2026 | 1679 | 1040 | 566 | 73 |

84 originale Jahres-/Zeitraumfälle mit getrennten Buch-/Steuerwerten bestehen
die amtliche Prüfung; ein absichtlicher Ein-Cent-Fehler wird erkannt. Nach der
Korrektur reiner negativer Testannahmen werden diese nativen Ergebnisse nur
bei identischen Laufzeitquellen und byteidentisch rekonstruiertem XML übernommen.
300 frühere repräsentative XML bleiben byteidentisch. Sechs gezielte Programme
bestehen; fünf unveränderte Programme wurden nach Quellenvergleich übernommen,
das korrigierte Negativtestprogramm vollständig erneut ausgeführt.
1068 Anzeigeprüfungen betreffen 126 echte PHP-Komponentenzeilen. Die laufende
private Anwendung besteht zusätzlich 84 native RPC-Prüfungen und dieselben
Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.
Die echte Browserprüfung bestätigt die weiterhin ausgeglichene Demo-Bilanz;
die neuen Konten sind in diesem unveränderten Demojournal nicht bebucht.
Ein interaktiver Speichervorgang für diese neuen Konten wird nicht behauptet.

Nachweise: `audit/skr42-r58-accepted-20261006.json`,
`audit/skr42-r58-private-rpc-20261006.json`,
`audit/skr42-r58-ui-readback-20261006.json`.

**78 Originalkonten für 2025 und 73 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 616 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 57: Darlehen an Gesellschafter und nahestehende Personen

Die Originalkonten 09600, 09610 und 09700 sind zusätzlich eingebaut. Sie behalten
Schuldner-Rechtsform, Sitz, Beteiligung des Darlehensgebers am Schuldner und die
ursprünglichen Bewertungsgegenkonten. Natürliche Personen werden gesondert von
Personengesellschaften und Kapitalgesellschaften bestätigt. Gesellschafterkonten
setzen eine ausdrücklich bestätigte Rechtsform der berichtenden Gesellschaft
voraus; Vereinsmitglieder werden nicht als GmbH-Gesellschafter behandelt.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 564 | 82 |
| 2026 | 1679 | 1040 | 563 | 76 |

216 Darlehens- und HGBM-Fälle bestehen die amtliche Prüfung: 113 zuvor geprüfte
Fälle wurden bei identischen Quellen und byteidentisch rekonstruiertem XML
übernommen; 103 weitere positive Aufrufe wurden ergänzt. Ein absichtlicher
Ein-Cent-Fehler wird erkannt. 148 frühere Beteiligungs-/Darlehensfälle und 80
eigene-Anteile-Fälle bleiben byteidentisch. Sieben vollständige gezielte
Prüfprogramme und 729 Anzeigeprüfungen bestehen. In der laufenden privaten
Anwendung bestehen 72 zusätzliche native Aufrufe und dieselben 729
Anzeigeprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.
Die echte Browserprüfung bestätigt die neuen Schuldnerarten, kompatible
Auswahl und das Zurücksetzen unpassender Profile. Es wurde nichts gespeichert
oder abgegeben.

Nachweise: `audit/skr42-r57-accepted-20261006.json`,
`audit/skr42-r57-private-rpc-20261006.json`,
`audit/skr42-r57-ui-readback-20261006.json`.

**82 Originalkonten für 2025 und 76 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 612 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revision 56: Eigene Anteile und bestätigte Rechtsformen

Originalkonto 29610 ergänzt den belegten Unterschiedsbetrag beim Erwerb eigener
Anteile. Nennbetrag und freie Rücklagen bleiben getrennt; die zusätzliche Angabe
der Mehrerwerbskosten wird nicht doppelt zur Bilanzsumme addiert. Erwerb über und
unter Nennwert, Anfangsbestand und Rückgabe bestehen die Prüfung. Negative
Erwerbspreise, fehlender Nennbetrag und unzureichende freie Rücklagen werden
zurückgewiesen. Rechtsformen werden ausdrücklich ausgewählt; Gemeinnützigkeit
bleibt eine separate Angabe.

| Originaljahr | Buchbar | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1043 | 561 | 85 |
| 2026 | 1679 | 1040 | 560 | 79 |

80 eigene-Anteile-Fälle und 16 Rechtsform-Fälle bestehen die amtliche Prüfung.
148 repräsentative vorherige XML-Fälle bleiben byteidentisch. Fünf vollständige
gezielte Prüfprogramme und 182 Anzeigeprüfungen bestehen. In der laufenden
privaten Anwendung bestehen 48 zusätzliche native Aufrufe und dieselben 182
Anzeigeprüfungen; alle 45 geschützten Geschäftstabellen sind unverändert.
Der Browser bestätigt die Auswahl für Verein und gGmbH bei einem unveränderten
gespeicherten Testabschluss. Es wurde weder gespeichert noch abgegeben.

Nachweise: `audit/skr42-r56-accepted-20261006.json`,
`audit/skr42-r56-private-rpc-20261006.json`,
`audit/skr42-r56-ui-readback-20261006.json`.

**85 Originalkonten für 2025 und 79 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 609 geeignete und 20
zurückgestellte Zwecke. Originaljahr 2027 und dessen native Freigabe bleiben offen.

## Revisionen 50–55: Beteiligungen, Wertpapiere und Darlehen

Zwölf zusätzliche Originalkonten je Jahr sind eingebaut. Die Regeln unterscheiden
belegte Beteiligungsarten, Wertpapierarten und Darlehensnehmer. Im Ausland
ansässige Emittenten werden in der passenden Instrumentenposition ausgewiesen.
Die Taxonomiepositionen für nicht zuordenbare ausländische Betriebsstätten werden
nicht als Ersatz für den Sitz des Emittenten benutzt. Fonds erhalten ihre eigenen
Ertrags-, Abgangs- und Abschreibungspositionen.

Bei Darlehen bleiben Rechtsform des Schuldners und der belegte Beteiligungsumfang
getrennt. Die 25-Prozent-Grenze führt zu den passenden Darlehenspositionen;
steuerliche Abzugsbeschränkungen werden nicht automatisch unterstellt.
Vorübergehende Wertminderungen werden über das ursprüngliche Konto 72010 gebucht.
Grundstücksgleiche Rechte ohne Gebäude bleiben nicht planmäßig abgeschrieben.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen |
| --- | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1603 | 1043 | 560 | 86 |
| 2026 | 1679 | 1599 | 1040 | 559 | 80 |

528 neue Darlehens- und HGB-Fälle, 672 korrigierte Beteiligungs- und Wertpapierfälle
sowie 48 Fälle zu Grundstücksrechten bestehen die amtliche Prüfung.
Unter dem endgültigen Stand sind720 zuvor geprüfte betroffene XML-Fälle und 148
repräsentative Fälle byteidentisch rekonstruiert. Die früheren 4261 XML-Fälle
wurden unter Revision 49 geprüft; eine vollständige neue Wiederholung dieser
historischen Gesamtheit wird für Revision 55 nicht behauptet.

Zwölf vollständige gezielte Prüfprogramme, 6867 Anzeigeprüfungen und 111
Vortragsprüfungen bestehen. In der bestehenden privaten Anwendung bestehen136
zusätzliche native XML-Aufrufe sowie dieselben6867 Anzeige- und 111
Vortragsprüfungen. Alle 45 geschützten Geschäftstabellen sind unverändert.

Die Browserprüfung deckte ein blockiertes Inline-Skript im Anlagenformular auf.
Eine separate Skriptdatei und sicher maskierte Formulardaten beheben den
Kontowechsel.640 zusätzliche Formularprüfungen und die echte Browserprüfung
bestätigen die Auswahl, den Erhalt kompatibler Profile und das Zurücksetzen
unpassender Profile. Die Sicherheitsrichtlinie bleibt erhalten.

Nachweise: `audit/skr42-r50-r55-accepted-20261006.json`,
`audit/skr42-r50-r55-private-rpc-20261006.json`,
`audit/skr42-r55-ui-csp-private-runtime-20261006.json`,
`audit/skr42-r50-r55-ui-readback-20261006.json`.

**86 Originalkonten für 2025 und 80 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 enthält608 geeignete und 20
zurückgestellte Zwecke. Das Originaljahr 2027 und dessen native Freigabe bleiben
offen. Eigene Anteile 29610 und explizite Rechtsformen sind isoliert vorbereitet;
deren Einbau und abschließende UI-Prüfung stehen noch aus.

## Revisionen 48–49: Steuerfreie Anlagenverkäufe und Ergebnisverwendung

34 zusätzliche Originalkonten je Jahr sind eingebaut. Vier Verkaufskonten
trennen anhand des ursprünglichen Belegs Ausfuhrlieferungen und innergemeinschaftliche
Lieferungen. Die bestätigte Umsatzsteuerart bleibt bei Bewertung, Abgang,
Jahresabschluss und Abschlusszeiträumen erhalten.

30 Konten bilden die Ergebnisverwendung von Körperschaften und Stiftungen ab.
Das verdiente Jahresergebnis und seine Verwendung werden getrennt berechnet.
Ursprüngliche Gegenkonten, Rücklagen, Vortrag, Storno und geprüfte Steuerwerte
werden unabhängig rekonstruiert. Aktive körperschaftliche Ergebnisverwendung
benötigt die Gesamtbilanz. Die amtlichen Summenebenen stammen aus dem jeweiligen
veröffentlichten Taxonomienetz.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1591 | 1043 | 548 | 98 | 94.20 % |
| 2026 | 1679 | 1587 | 1040 | 547 | 92 | 94.52 % |

328 neue positive XML-Fälle und zwei tatsächliche Centfehler sind qualifiziert.
Der endgültige Stand rekonstruiert alle 4261 positiven XML-Fälle byteidentisch.
Die Nachweise kennzeichnen die wiederverwendeten SDK-Ergebnisse nach drei
gezielten Quellkorrekturen. 16 gezielte Prüfpositionen, 25487 PHP-Anzeigeprüfungen
und 111 Vortragsprüfungen bestehen.

38 Laufzeitdateien sind in der bestehenden privaten Anwendung eingebaut.
Dort bestehen acht zusätzliche native XML-Fälle, 3039 neue Anzeigeprüfungen
und 111 Vortragsprüfungen. Alle 45 geschützten Geschäftstabellen bleiben
unverändert. Die frisch abgerufene Bilanz und der gespeicherte Prüfstatus
sind korrekt; keine Erklärung wurde freigegeben oder übermittelt.

Nachweise: `audit/skr42-r48-r49-accepted-20261005.json`,
`audit/skr42-r48-r49-private-runtime-20261005.json`,
`audit/skr42-r48-r49-private-rpc-20261005.json`,
`audit/skr42-r48-r49-ui-readback-20261005.json`.

**98 Originalkonten für 2025 und 92 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 enthält 583 geeignete und 20
zurückgestellte Zwecke, darunter 14 abweichende körperschaftliche
Ergebnisverwendungen. Originaljahr 2027 und dessen native Freigabe bleiben offen.
Vier Beteiligungskonten sind in einem isolierten Entwurf in Vorbereitung.

## Revisionen 45–47: Darlehen, immaterielle Anzahlungen und Rücklagen

14 zusätzliche Originalkonten je Jahr sind eingebaut: 08130/08150/08830 für
bestätigte Darlehen gegen nicht-kapitalgesellschaftliche Unternehmen,
01700/01790 und das zugehörige Wertminderungskonto 62100 für immaterielle
Anzahlungen sowie 29620/29630/29640/29650/29660/29670/29680/29690 für
ursprüngliche Rücklagen. Die Anschaffungsart und tatsächlichen Gegenkonten
bleiben über Bewertung, Abgang und Vortrag erhalten. Immaterielle Anzahlungen
werden vor Betriebsbereitschaft nicht planmäßig abgeschrieben.

Rücklagen bleiben vom verdienten Jahresergebnis getrennt. Die beiden
Gegenkonten 29680/29690 dürfen innerhalb des bestätigten, insgesamt nicht
negativen Rücklagenbestands gegenläufig sein; dadurch entsteht keine
allgemeine Freigabe negativer Kapitalposten. Das Konto 29610 für eigene
Anteile benötigt weiterhin seine gesonderte Anschaffungs- und Kapitalprüfung.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1557 | 1043 | 514 | 132 | 92.18 % |
| 2026 | 1679 | 1553 | 1040 | 513 | 126 | 92.50 % |

248 neue positive native XML-Fälle sind qualifiziert; drei tatsächliche
Centfehler werden abgewiesen. Die Revision-46-Nachweise kennzeichnen die
byteidentisch wiederverwendeten SDK-Ergebnisse und die neu ausgeführten
Methoden. 27 gezielte Prüfpositionen bestehen. Alle 3933 früheren und neuen
positiven XML-Fälle sind im endgültigen Quellstand byteidentisch rekonstruiert.
22448 PHP-Anzeigeprüfungen und 111 Vortragsprüfungen bestehen.

In der bestehenden privaten Anwendung wurden 29 Laufzeitdateien übernommen.
Dort bestehen acht zusätzliche native XML-Fälle, 1248 neue Anzeigeprüfungen
und 111 Vortragsprüfungen. Die 45 geschützten Geschäftstabellen bleiben
unverändert. Der frische Browserabruf zeigt die unveränderte synthetische
Bilanz und den gespeicherten Prüfstatus. Keine Erklärung wurde freigegeben
oder übermittelt.

Nachweise: `audit/skr42-r45-r47-transfer-20261005.json`,
`audit/skr42-r45-r47-live-xml-20261005.json`,
`audit/skr42-r45-r47-accepted-20261005.json`,
`audit/skr42-r45-r47-private-runtime-20261005.json`,
`audit/skr42-r45-r47-private-rpc-20261005.json`,
`audit/skr42-r45-r47-ui-readback-20261005.json`.

**132 Originalkonten für 2025 und 126 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 enthält 563 Zwecke und sechs
zurückgestellte. Originaljahr 2027 und dessen native Freigabe bleiben offen.
Vier steuerfreie Anlagenverkaufskonten sind isoliert qualifiziert. Die
Ergebnisverwendung mit zusätzlichen amtlichen Summenebenen wird weiter geprüft.

## Revisionen 43–44: Typische stille Beteiligung und Bauanlagen-Sammelkonto

Je Originaljahr sind zwei zusätzliche Konten eingebaut: 73990 benötigt den
bestätigten Vertrag der typischen stillen Beteiligung. Gewinnanteil und
Verlustausgleich behalten ihre tatsächlichen Soll-/Habenbeträge; eine
gewerbesteuerliche Hinzurechnung erfolgt nicht automatisch. 07000 benötigt
im ursprünglichen Anschaffungsplan die genaue Art: Sachanlagen-Anzahlung,
Gebäude im Bau oder andere Sachanlage im Bau. Die Auswahl bleibt über einzelne
Bewertungen, steuerliche Unterschiede, Abgänge und Vortrag unverändert erhalten.
Noch nicht betriebsbereite Anlagen erhalten keine erfundene Nutzungsdauer.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1543 | 1043 | 500 | 146 | 91.36 % |
| 2026 | 1679 | 1539 | 1040 | 499 | 140 | 91.66 % |

80 neue positive XML-Fälle sind qualifiziert, zwei tatsächliche Centfehler werden
abgewiesen. Die optionale Formularauswahl wurde nach den ersten Prüfungen auf
Radiobuttons korrigiert: Ein zusätzliches Pflicht-Auswahlfeld hätte bestehende
Anlagenpläne blockiert. Der PHP-Formulartest ist frisch ausgeführt; alle 72
Bauanlagen-XML-Fälle wurden byteidentisch rekonstruiert. Die v2-Nachweise benennen
die tatsächlich wiederverwendeten SDK- und unveränderten Methodenergebnisse.
17 gezielte Prüfpositionen der beiden Quellstände bestehen. Alle 3605 früheren
und 80 neuen XML-Fälle sind gegen den endgültigen Quellstand byteidentisch.

21200 PHP-Anzeigeprüfungen und 111 Vortragsprüfungen bestehen. In der bestehenden
privaten Anwendung sind 20 Laufzeitdateien übernommen; dort bestehen acht
zusätzliche tatsächliche native XML-Fälle, 528 neue Anzeigeprüfungen und 111
Vortragsprüfungen. Die 45 geschützten Geschäftstabellen bleiben unverändert.
Der frische Browserabruf zeigt die unveränderte synthetische Bilanz und den
bereits gespeicherten Prüfstatus; es wurde keine Abgabe oder Freigabe ausgelöst.

Nachweise: `audit/skr42-r43-r44-transfer-20261005.json`,
`audit/skr42-r43-r44-live-xml-20261005.json`,
`audit/skr42-r43-r44-accepted-20261005.json`,
`audit/skr42-r43-r44-private-runtime-20261005.json`,
`audit/skr42-r43-r44-private-rpc-20261005.json`,
`audit/skr42-r43-r44-ui-readback-20261005.json`.

**146 Originalkonten für 2025 und 140 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 enthält 550 Zwecke und sechs
zurückgestellte. Originaljahr 2027 und dessen native Freigabe bleiben offen.
Drei Darlehenskonten gegen nicht-kapitalgesellschaftliche Unternehmen und
immaterielle Anzahlungen werden getrennt weiter qualifiziert.

## Revisionen 39–42: Wertberichtigungen, latente Steuern, Anlagenabgänge und Ausleihungen

Vier aufeinander aufbauende, getrennt qualifizierte Quellstände werden gemeinsam
in die laufende private Anwendung übernommen. Je Originaljahr sind 28 zusätzliche
Konten verarbeitet: vier Wertberichtigungen auf verbundene oder beteiligte
Unternehmen, fünf ursprüngliche HGB-Konten für latente Steuern, 15 Erlös-,
Restbuchwert- und Zuschreibungskonten sowie vier langfristige Ausleihungen.

Die Wertberichtigung bleibt an Schuldnergruppe und Restlaufzeit gebunden. Latente
Steuern bleiben in der vollständigen HGB-Bilanz erhalten; der steuerpflichtige
Teil benötigt die tatsächlich bestätigte ausgeglichene Ausbuchung. Anlagenabgänge
behalten getrennte Soll- und Habenbeträge und verlangen die genaue Anlagenart.
Langfristige Ausleihungen benötigen ursprüngliche Anschaffung, einzelne belegte
Bewertungen und Gegenbuchungen, steuerliche Abweichungen und unveränderten Vortrag.
Es werden weder Nutzungsdauern noch Steuerbefreiungen aus Kontonummern abgeleitet.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1541 | 1043 | 498 | 148 | 91.24 % |
| 2026 | 1679 | 1537 | 1040 | 497 | 142 | 91.54 % |

412 positive XML-Fälle der neuen Quellstände sind qualifiziert, einschließlich
32 zuvor tatsächlich erfolgreichen und anschließend byteidentisch rekonstruierten
SDK-Fällen aus Revision 42. Vier gezielte Centfehler werden abgewiesen. Alle
3193 früheren und 412 neuen XML-Fälle sind byteidentisch rekonstruiert.
29 gezielte Prüfpositionen der vier Quellstände bestehen. Die v2-Nachweise
kennzeichnen Testkorrekturen und tatsächlich wiederverwendete unveränderte
Methoden- beziehungsweise SDK-Nachweise; ein vollständiger historischer
Programmdurchlauf gegen den letzten Stand wird nicht behauptet.

20672 PHP-Ansichtsprüfungen und 111 Vortragsprüfungen bestehen. 29 Laufzeitdateien
sind in die vorhandene private Anwendung eingebaut. Dort bestehen acht tatsächliche
native XML-Fälle, 4032 neue Ansichtsprüfungen und 111 Vortragsprüfungen. Die 45
geschützten Geschäftstabellen bleiben unverändert. Der frische Browserabruf ist
in `audit/skr42-r39-r42-ui-readback-20261005.json` mit dem tatsächlich sichtbaren
Status dokumentiert; gespeicherte frühere Prüfungen sind keine neue Freigabe.

Nachweise: `audit/skr42-r39-r42-transfer-20261005.json`,
`audit/skr42-r39-r42-live-xml-20261005.json`,
`audit/skr42-r39-r42-accepted-20261005.json`,
`audit/skr42-r39-r42-private-runtime-20261005.json`,
`audit/skr42-r39-r42-private-rpc-20261005.json`,
`audit/skr42-r39-r42-ui-readback-20261005.json`.

**148 Originalkonten für 2025 und 142 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Vier ursprünglich vorbereitete steuerfreie Sachanlagenabgänge
bleiben außerhalb der qualifizierten Revision 41. Die Vorbereitung 6.10 umfasst
548 Zwecke und sechs zurückgestellte. Originaljahr 2027 und dessen native
Freigabe bleiben offen. Frühere Stände folgen unten.

## Revision 38: Außenanlagen und Einbauten auf eigenen und fremden Grundstücken

Die Originalkonten 02800/02850/02900/03100/03150/03200 sowie
03900/03950/03980/06800 werden mit bestätigter unbeweglicher Anlagenart verarbeitet.
Die belegte lineare Nutzungsdauer und gegebenenfalls bestätigte steuerliche
Abweichung bleiben im ursprünglichen Anlagenverzeichnis erhalten. Abschreibungen
verwenden 62200; eine Gebäudeabschreibung 62210 wird nicht aus der gemeinsamen
Bilanzposition abgeleitet. GWG und degressive Abschreibung sind ausgeschlossen.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1513 | 1039 | 474 | 176 | 89.58 % |
| 2026 | 1679 | 1509 | 1036 | 473 | 170 | 89.87 % |

80 neue tatsächliche native XML-Fälle bestehen; ein Centfehler wird abgewiesen.
Alle 3113 früheren und 80 neuen XML-Fälle sind byteidentisch rekonstruiert.
Acht gezielte Prüfpositionen qualifizieren die betroffenen Programme. Die v2-
Nachweise kennzeichnen die Teständerung und die tatsächlich wiederverwendeten
unveränderten SDK-Nachweise. Zwei ergänzende native vollständige HGB-Bilanzen
bestätigen eigene und fremde Grundstücke sowie getrennte steuerliche Nutzungsdauer.

16638 PHP-Ansichtsprüfungen und 111 Vortragsprüfungen bestehen. 15 Laufzeitdateien
sind in die vorhandene private Anwendung eingebaut. Dort bestehen acht native
XML-Fälle und 960 neue Ansichtsprüfungen. Alle 45 geschützten Geschäftstabellen
sind unverändert; der frische UI-Abruf bestätigt Testbilanz und gespeicherten Status.

Nachweise: `audit/skr42-r38-accepted-20261005.json`,
`audit/skr42-r38-prototype-native-final-v2-20261005.json`,
`audit/skr42-r38-prototype-targeted-final-v2-20261005.json`,
`audit/skr42-r38-02800-combined-supplement-20261005.json`,
`audit/skr42-r38-03900-combined-supplement-20261005.json`,
`audit/skr42-r38-live-xml-20261005.json`,
`audit/skr42-r38-private-runtime-20261005.json`,
`audit/skr42-r38-private-rpc-20261005.json`,
`audit/skr42-r38-ui-readback-20261005.json`.

**176 Originalkonten für 2025 und 170 für 2026 bleiben offen. Der Gesamtauftrag
ist unvollständig.** Die Vorbereitung 6.10 umfasst 523 Zwecke und sechs
zurückgestellte. Originaljahr 2027 und dessen native Freigabe bleiben offen.
Frühere Stände folgen unten.

## Revision 37: Entgeltlich erworbener Geschäfts- oder Firmenwert

Die Originalkonten 01500 und 62050 werden mit dem unveränderten Anlagen- und
Buchungsnachweis verarbeitet. Die Handelsbilanz verwendet die belegte Nutzungsdauer;
die Steuerbilanz verlangt 180 Monate und gegebenenfalls eine bestätigte Abweichung.
Die ursprünglichen Bruttobewegungen, Jahres-/Zeitraumabschlüsse und Folgejahre
bleiben erhalten. GWG, degressive Abschreibung und Restwerte sind ausgeschlossen.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1503 | 1039 | 464 | 186 | 88.99 % |
| 2026 | 1679 | 1499 | 1036 | 463 | 180 | 89.28 % |

18 neue tatsächliche native XML-Fälle bestehen, ein Centfehler wird abgewiesen.
Alle 3095 früheren und 18 neuen XML-Fälle werden byteidentisch rekonstruiert.
Neun gezielte Prüfpositionen qualifizieren die betroffenen Programme und die
zusätzlich ausgeführte historische Methode. Die Teständerungen und tatsächlichen
wiederverwendeten Methoden-/SDK-Nachweise sind in den v3-Nachweisen offengelegt.
Eine ergänzende native Prüfung der vollständigen HGB-Vereinsbilanz mit verknüpftem
steuerpflichtigem Teil besteht ebenfalls: ursprüngliche Handelsnutzungsdauer
120 Monate, steuerliche Nutzungsdauer 180 Monate, andere Sphären separat.

15678 tatsächliche PHP-Ansichtsprüfungen und 111 frühere Vortragsprüfungen bestehen.
24 Laufzeitdateien sind in der vorhandenen privaten Anwendung eingebaut; dort
bestehen acht repräsentative native XML-Fälle und 108 neue Ansichtsprüfungen.
Alle 45 geschützten Geschäftstabellen sind unverändert. Der frische UI-Abruf
bestätigt die vorhandene Testbilanz und den gespeicherten Prüfstatus.

Nachweise: `audit/skr42-r37-accepted-20261005.json`,
`audit/skr42-r37-prototype-native-final-v3-20261005.json`,
`audit/skr42-r37-prototype-targeted-final-v3-20261005.json`,
`audit/skr42-r37-goodwill-combined-supplement-20261005.json`,
`audit/skr42-r37-live-xml-20261005.json`,
`audit/skr42-r37-private-runtime-20261005.json`,
`audit/skr42-r37-private-rpc-20261005.json`,
`audit/skr42-r37-ui-readback-20261005.json`.

**186 Originalkonten für 2025 und 180 für 2026 sind weiterhin offen. Der
Gesamtauftrag ist unvollständig.** Die 6.10-Vorbereitung umfasst 521 Zwecke und
sechs zurückgestellte. Originaljahr 2027 und dessen native Freigabe bleiben offen.
Frühere Stände folgen unten.

## Revision 36: Bankunterpositionen, Lohnzuschüsse und ausländische Aufwendungen

60750 und 63060 haben beleggebundene XML-Regeln. Lohnzuschüsse werden mit dem
ursprünglichen Lohnaufwand abgestimmt; die ausländische Aufwandsart und eine
mögliche Umsatzsteuerforderung bleiben getrennte Entscheidungen. Neun bestehende
Bankkonten sind auf die amtliche Unterposition berichtigt. Bekannte Laufzeiten
bleiben erhalten; für allgemeine Darlehenskonten wird keine Laufzeit erfunden.
Die Korrektur benötigt die exakten ursprünglichen Quellen und alten/neuen Zwecke.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1501 | 1038 | 463 | 188 | 88.87 % |
| 2026 | 1679 | 1497 | 1035 | 462 | 182 | 89.16 % |

392 neue tatsächliche amtliche XML-Prüfungen bestehen, ein Centfehler wird
abgewiesen. Alle 2703 zuvor qualifizierten XML-Fälle und die 392 neuen werden
byteidentisch rekonstruiert. 22 gezielte Prüfpositionen qualifizieren die betroffenen
Programme und historischen Methoden. Unveränderte tatsächlich bestandene Methoden
werden mit ihrem ursprünglichen Nachweis übernommen; unterbrochene Methoden und
die berichtigte Ansichtsanzahl sind gesondert ausgeführt. Alle ursprünglichen
Fehl- und Teilnachweise bleiben erhalten. Die nativen Prüfungen wurden wegen der
anschließenden reinen Teständerungen nicht wiederholt; deren Gleichwertigkeit und
die unveränderten Laufzeitquellen sind gesondert dokumentiert.

15570 tatsächliche PHP-Ansichtsprüfungen und 111 frühere Vortragsregressionen
bestehen. 13 Laufzeitdateien sind in der bestehenden privaten Anwendung eingebaut.
Dort bestehen acht repräsentative vollständige XML-Fälle für beide Jahre,
Jahres-/Zeitraumabschlüsse und Einzelbetrieb/Gesamtkörperschaft sowie 654
PHP-Ansichtsprüfungen. Die 45 geschützten Geschäftstabellen sind unverändert.
Der frische Browserabruf zeigt die vorhandene Testbilanz und den bereits
vorliegenden Prüfstatus; er ist keine neue steuerliche Freigabe oder Abgabe.

Nachweise: `audit/skr42-r36-accepted-20261005.json`,
`audit/skr42-r36-prototype-native-final-v2-20261005.json`,
`audit/skr42-r36-prototype-targeted-final-v3-20261005.json`,
`audit/skr42-r36-live-xml-20261005.json`,
`audit/skr42-r36-private-runtime-20261005.json`,
`audit/skr42-r36-private-rpc-20261005.json`,
`audit/skr42-r36-ui-readback-20261005.json`.

**188 Originalkonten für 2025 und 182 für 2026 sind weiterhin offen. Der
Gesamtauftrag bleibt unvollständig.** Die Taxonomievorbereitung 6.10 enthält 519
unveränderte Zwecke und sechs zurückgestellte. Originaljahr 2027 und dessen
native Freigabe fehlen weiterhin. Frühere Stände folgen unten.

## Revision 35: ursprüngliche Verrechnungssalden und Finanzdisposition

Neun Originalkonten je Jahresstand sind ergänzt. 13700/13720/13740/13750,
14950, 18950, 36300 und 37900 benötigen eine belegte Forderungs- oder
Verbindlichkeitsart. Die ursprünglichen Soll- und Habensalden bleiben erhalten;
die Bilanz und der XML-Kontennachweis zeigen die belegte Seite mit einem positiven
Betrag. Auch steuerliche Wechsel der Bilanzseite und Anfangssalden sind geprüft.
18900 verlangt die bestätigte Art der kurzfristigen Finanzdisposition.
Gewöhnliche negative Aktiv- oder Verbindlichkeitssalden bleiben gesperrt.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1499 | 1047 | 452 | 190 | 88.75 % |
| 2026 | 1679 | 1495 | 1044 | 451 | 184 | 89.04 % |

713 neue tatsächliche amtliche Prüfungen bestehen, einschließlich 33 originaler
Jahresvorträge. Ein absichtlicher Centfehler wird abgewiesen. Alle 1990 bisherigen
positiven XML-Fälle und die 713 neuen werden byteidentisch rekonstruiert. Zwölf
gezielte Prüfungen, 14918 tatsächliche PHP-Ansichtsprüfungen und die 111 bisherigen
Vortragsregressionen bestehen. Einzelne XML-Kontensalden werden gesondert mit ihren
ursprünglichen Buchungsquellen verglichen; Eingaben bleiben unverändert.

19 Laufzeitdateien sind in der bestehenden privaten Testanwendung installiert.
376 native Prüfungen über den Anwendungskanal, 1090 Ansichtsprüfungen und die 111
Vortragsregressionen bestehen dort. Die 45 geschützten Geschäftstabellen sind
unverändert. Die lesende Browserprüfung bestätigt die bestehende Testbilanz und
den gespeicherten Prüfstatus. Eine neue fachliche Freigabe oder Abgabe wurde nicht
ausgeführt; der sichtbare Prüfstatus gehört zum vorhandenen Testentwurf.

Nachweise: `audit/skr42-r35-accepted-20261005.json`,
`audit/skr42-r35-prototype-native-final-v3-20261005.json`,
`audit/skr42-r35-prototype-targeted-final-v3-20261005.json`,
`audit/skr42-r35-live-xml-20261005.json`,
`audit/skr42-r35-private-runtime-20261005.json`,
`audit/skr42-r35-private-rpc-20261005.json`,
`audit/skr42-r35-ui-readback-20261005.json`.

Der vollständige Audit bleibt Exit 2: **190 Originalkonten für 2025 und 184 für
2026 sind offen. Der Gesamtauftrag ist nicht abgeschlossen.** Die 6.10-Vorbereitung
enthält 511 unveränderte Zwecke und sechs zurückgestellte. Der Originalkontenplan
2027 und die native Freigabe fehlen weiterhin. Frühere Stände folgen unten.

## Revision 34: Schecks, besondere Abgrenzungen und Umsatzsteuerverbindlichkeiten

Elf Originalkonten für 2025 und acht für 2026 sind ergänzt: Schecks (15500),
aufwandswirksame Zölle/Verbrauchsteuern auf Vorräte (19200), aufwandswirksame
Umsatzsteuer auf Anzahlungen (19300), Gewinnverfügung des stillen Gesellschafters
(36200), nicht fällige Umsatzsteuer (38200/38210/38260/38300/38360), historische
aufgeschobene Einfuhrumsatzsteuer (38710) und der historische Kleinunternehmerumsatz
(41850). 15500, 38710 und 41850 sind 2026 nicht mehr buchbar und werden dort nicht
erfunden. Ein bestätigter Gewinnanspruch und die historische Kleinunternehmerregelung
bleiben ausdrücklich prüfpflichtig; steuerliche Voraussetzungen werden nicht aus
Kontonamen berechnet.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1490 | 1047 | 443 | 199 | 88.22 % |
| 2026 | 1679 | 1486 | 1044 | 442 | 193 | 88.51 % |

156 tatsächliche neue amtliche Jahres- und Zeitraumprüfungen bestehen.
Ein absichtlich um einen Cent beschädigter Kontrollfall wird amtlich abgewiesen.
Alle 1834 bisherigen positiven XML-Fälle und die 156 neuen werden byteidentisch
neu erzeugt. Zwölf gezielte Prüfungen und 13828 tatsächliche PHP-Ansichtsprüfungen
bestehen. Die unveränderte Originalvortragslogik aus Revision 33 besteht erneut
in 111 Prüfungen; ein zusätzlicher kontenspezifischer r34-Vortragslauf wird nicht
behauptet. Anfangsbestände, Stornierungen und unbewegte Originalkonten sind in den
neuen Jahres- und Zeitraumfällen enthalten. Negative gewöhnliche Aktiv- und
Verbindlichkeitssalden bleiben ausdrücklich gesperrt.

Acht Laufzeitdateien sind in der bestehenden privaten Testanwendung installiert.
96 neue native Prüfungen über den Anwendungskanal, 392 tatsächliche Ansichtsprüfungen
und die 111 Vortragsregressionen bestehen dort ebenfalls. Die 45 geschützten
Geschäftstabellen bleiben unverändert. Die lesende Browserprüfung bestätigt die
bestehende Bilanz und den gespeicherten, noch nicht abgegebenen Testentwurf; eine
neue fachliche Freigabe oder Übermittlung wurde nicht ausgeführt.

Nachweise: `audit/skr42-r34-accepted-20261005.json`,
`audit/skr42-r34-prototype-native-final-20261005.json`,
`audit/skr42-r34-prototype-targeted-final-20261005.json`,
`audit/skr42-r34-live-xml-20261005.json`,
`audit/skr42-r34-private-runtime-20261005.json`,
`audit/skr42-r34-private-rpc-20261005.json`,
`audit/skr42-r34-ui-readback-20261005.json`.

Der vollständige Audit `audit/balance-mapping-r34-20261005.json` bleibt Exit 2:
**199 Originalkonten für 2025 und 193 für 2026 bleiben offen. Der Gesamtauftrag
ist nicht abgeschlossen.** Die 6.10-Vorbereitung enthält 502 unveränderte Zwecke
und sechs zurückgestellte; Originalkontenplan 2027 und native Freigabe fehlen
weiterhin. Die folgenden Abschnitte dokumentieren frühere Stände.

## Revision 33: Forderungswertberichtigungen und kalkulatorische Gegenbuchungen

Je 20 weitere Originalkonten sind umgesetzt: 12460/12470/12480/12490 mindern
die zugehörigen gewöhnlichen Forderungen; 69700–69890 sowie 69900/69920/69940/69990
werden als vollständig ausgeglichene kalkulatorische Kosten und Gegenbuchungen
behandelt. Alle neuen Regeln verlangen eine bestätigte Zuordnung.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1479 | 1038 | 441 | 210 | 87,57 % |
| 2026 | 1679 | 1478 | 1037 | 441 | 201 | 88,03 % |

Negative Aktivwerte sind ausschließlich für diese vier quellengebundenen
Wertberichtigungskonten erlaubt. Ursprüngliche Forderungen derselben belegten
Restlaufzeit und ausgewählten Schuldnergruppe müssen sowohl am Anfang als auch
am Ende den Korrekturbetrag decken. Die zugehörigen Restlaufzeitangaben werden
ebenfalls gemindert. Nicht aufgeschlüsselte Forderungen ersetzen keinen
Restlaufzeitnachweis; überhöhte, positive oder sachfremde Korrekturen bleiben gesperrt.
Die Wertberichtigungen gegen verbundene oder beteiligte Unternehmen sind weiterhin
offen; die Aufteilung zwischen Lieferforderungen und sonstigen Forderungen ist
noch gesondert anzubinden.

Kalkulatorische Kosten und Gegenbuchungen gleichen sich innerhalb der jeweils
zugehörigen Kostenfamilie und Sphäre aus. Unpaarige Buchungen, Verrechnung zwischen
Sphären und ein zusätzlicher steuerlicher Aufwand werden abgewiesen. Die gleiche
Kontrolle gilt für ursprüngliche Buchwerte und steuerliche Wertänderungen.
Unbebuchte Konten im eingerichteten Kontenplan benötigen keine künstliche Nullzuordnung.

496 endgültige native XML-Fälle bestehen, einschließlich Kalenderjahren,
eigenen Abschlusszeiträumen, Gegenbuchungen, Eröffnungswerten, Stornos und
unbebuchten Konten in beiden Profilen. Ein absichtlicher Cent-Rechenfehler wird
amtlich abgewiesen. Alle 1338 bisherigen nativen Fälle und die 496 neuen sind
byteidentisch rekonstruiert. Zwölf gezielte Prüfungen, 13436 tatsächliche
PHP-Ansichtsprüfungen und 111 zusätzliche Originalvortragsprüfungen bestehen.
Der Vortrag 2025 → 2026 erhält negative Korrekturen und getrennte Steuerwerte,
ohne im Folgejahr erneuten Aufwand zu erzeugen.

Der erste Entwurf bestand 480 native Prüfungen. Der fehlende sortierte
Testverzeichniseintrag und der danach zusätzlich entdeckte Nullkontenrandfall
bleiben mit ihren ursprünglichen Quellen und Fehlernachweisen erhalten.
Nach der Reparatur wurden alle endgültigen 496 nativen Fälle und sämtliche
zwölf gezielten Prüfungen erneut ausgeführt. Ein vollständiger Lauf aller
historischen Programme wird nicht behauptet.

17 Laufzeitdateien sind in der bestehenden privaten Testanwendung installiert.
Dort bestehen weitere 160 native Prüfungen über den Anwendungskanal,
1008 tatsächliche Ansichtsprüfungen und dieselben 111 Vortragsprüfungen.
Die 45 geschützten Geschäftstabellen bleiben unverändert. Die lesende UI-Kontrolle
wird im zugehörigen Nachweis festgehalten; vorhandene gespeicherte Prüfergebnisse
werden dabei nicht als neu erzeugte Abgabe bestätigt.

Nachweise: `audit/skr42-r33-accepted-20261005.json`,
`audit/skr42-r33-prototype-native-final-20261005.json`,
`audit/skr42-r33-prototype-targeted-final-20261005.json`,
`audit/skr42-r33-live-xml-20261005.json`,
`audit/skr42-r33-inactive-rejection-20261005.json`,
`audit/skr42-r33-candidate-edge-repair-20261005.json`,
`audit/skr42-r33-private-runtime-20261005.json`,
`audit/skr42-r33-private-rpc-20261005.json`,
`audit/skr42-r33-ui-readback-20261005.json`.

Der vollständige Audit `audit/balance-mapping-r33-20261005.json` bleibt Exit 2:
**210 beziehungsweise 201 Originalkonten bleiben offen. Der Gesamtauftrag ist
nicht abgeschlossen.** Die 6.10-Vorbereitung erhält 496 unveränderte Zwecke und
sechs zurückgestellte; Originalkontenplan 2027 und native Freigabe bleiben erforderlich.
Die folgenden Abschnitte dokumentieren frühere Stände.

## Revision 32: zusätzliche Originalkonten und vollständige XML-Auswahlprüfung

31 zusätzliche Konten für 2025 und 22 für 2026 haben geprüfte Zuordnungen:
Geldspielautomaten, Provisionen/Lizenzen/Patente, Innenumsätze, Umsatzsteuervergütungen,
umsatzbezogene Steuern, aktivierte Eigenleistungen und Fremdkapitalzinsen,
betriebsfremde Erträge und Aufwendungen, Abgänge und Zuschreibungen des Umlaufvermögens,
Beteiligungs- und Veräußerungskosten, tatsächliche Kosten nach § 64 AO,
Abschreibungen des Umlaufvermögens, Nebenkosten eigener Anteile und Abzinsungen.
Der tatsächliche Originalplan bestimmt die Auswahl; entfallene Konten werden
2026 nicht künstlich wieder eingeführt. Je 14 neue Konten benötigen bestätigte
Sachverhalte. Keine Steuerbefreiung oder Abziehbarkeit wird aus dem Kontonamen berechnet.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1459 | 1038 | 421 | 230 | 86,38 % |
| 2026 | 1679 | 1458 | 1037 | 421 | 221 | 86,84 % |

Provisionen/Lizenzen/Patente erhalten den summenwirksamen Umsatzsteuerausweis und
die amtliche, nicht summenwirksame Ertragsart. Abzinsungen gehen direkt in die
dafür vorgesehenen summenwirksamen Unterpositionen ein. Umsatzbezogene Steuern
haben das amtliche Abzugszeichen. Alle angebotenen Auswahlen, Gegenbuchungen,
Stornos und beide Berichtsprofile sind geprüft: 462 neue native positive XML-Fälle,
ein erwarteter Cent-Rechenfehler, 1338 byteidentische XML-Rekonstruktionen und
zwölf gezielte Prüfungen. 12428 tatsächliche PHP-Ansichtsprüfungen bestehen.
Die amtliche Prüfung wurde nach der reinen Ergänzung des Testverzeichnisses anhand
aller 462 byteidentischen Dateien wiederverwendet; die tatsächlich ausgeführte
native Prüfung und die ursprünglichen Fehlernachweise bleiben separat erhalten.
Ein vollständiger Lauf sämtlicher historischer Tests wird nicht behauptet.

Neun Laufzeitdateien sind in die bestehende private Testanwendung übernommen.
Dort bestehen 1268 zusätzliche PHP-Ansichtsprüfungen und 150 native Prüfungen
über den Anwendungskanal. Die 45 geschützten Geschäftstabellen bleiben unverändert.
Die lesende Browserprüfung bestätigt Bilanz, GuV und den geprüften Entwurf ohne Abgabe.
Die 16 offenen individuellen Stammkonten dieses Demoabschlusses sind von der
gesamten Originalkontenabdeckung zu unterscheiden. Die 6.10-Vorbereitung enthält
485 unveränderte Zwecke und sechs zurückgestellte; die native Freigabe für 2027 fehlt.

Nachweise: `audit/skr42-r32-accepted-20261005.json`,
`audit/skr42-r32-prototype-native-20261005.json`,
`audit/skr42-r32-prototype-native-final-20261005.json`,
`audit/skr42-r32-prototype-targeted-final-20261005.json`,
`audit/skr42-r32-live-xml-20261005.json`,
`audit/skr42-r32-private-runtime-20261005.json`,
`audit/skr42-r32-private-rpc-20261005.json`,
`audit/skr42-r32-ui-readback-20261005.json`.

Der vollständige Audit `audit/balance-mapping-r32-20261005.json` bleibt Exit 2:
**230 beziehungsweise 221 Originalkonten bleiben offen. Der Gesamtauftrag ist
nicht abgeschlossen.** Kostenanlagen-Gegenkonten ohne Originalbewertungsnachweis
werden weiterhin abgelehnt; insbesondere wurde 72070 nicht freigegeben.
Die folgenden Abschnitte dokumentieren frühere Stände.

## Revision 31: Gebäude und bestätigte Gebäudeabschreibung

17 weitere Originalkonten je Jahr sind implementiert: 02300, 02400, 02410,
02430, 02440, 02500, 02600, 02700, 03000, 03050, 03300, 03400, 03500,
03600, 03700, 03800 und 62210. Gebäude auf eigenen und fremden Grundstücken
haben eigene Ausweispositionen und die Anlagenart `building`. Grundstückswerte
bleiben getrennt. Die Gebäudeabschreibung wird ausschließlich aus dem vollständigen
Anlagennachweis übernommen. Es wird keine Nutzungsdauer aus der Kontonummer
abgeleitet. Nicht implementierte Gebäudeabgänge und Sondermethoden bleiben gesperrt.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1428 | 1021 | 407 | 261 | 84,55 % |
| 2026 | 1679 | 1436 | 1029 | 407 | 243 | 85,53 % |

Die tatsächlichen PHP-Buchungen prüfen Anschaffung, Bruttoanlagenwerte,
Abschreibung, Restwert, vollständige Abschreibung, Sphärenaufteilung, bestätigte
abweichende Steuer-Nutzungsdauer und Fortführung nach 2026. 128 zusätzliche XML-Fälle
bestehen die native ERiC-Prüfung. Eine manipulierte Cent-Summe wird abgelehnt.
876 XML-Dateien sind nach Übernahme byteidentisch: 128 neue und alle 748 zuletzt
qualifizierten positiven Fälle. Neun Testprogramme und zwei ausgewählte Regressionen,
11160 tatsächliche PHP-Ansichtsprüfungen und 320 weitere PHP-Funktionsprüfungen bestehen.
Es wird kein vollständiger Lauf sämtlicher historischer Tests behauptet. Nachweise:
`audit/skr42-r31-accepted-20261005.json`,
`audit/skr42-r31-prototype-native-20261005.json`,
`audit/skr42-r31-prototype-targeted-20261005.json`,
`audit/skr42-r31-live-xml-20261005.json`.

20 Laufzeitdateien sind in der bestehenden privaten Testanwendung eingebaut.
Dort bestehen dieselben 320 PHP-Funktionsprüfungen und 96 weitere native
Prüfungen über den Anwendungskanal. Die 45 geschützten Geschäftstabellen bleiben
unverändert; die Wiederanlaufprüfung besteht. Die lesende Browserkontrolle
bestätigt die unveränderte Bilanzsumme von 1200 EUR, das Jahresergebnis von
200 EUR und den geprüften Entwurf ohne Abgabe. Die 16 offenen individuellen
Stammkonten in diesem Demoabschluss sind von der gesamten Originalkontenabdeckung
zu unterscheiden. Nachweise: `audit/skr42-r31-private-runtime-20261005.json`,
`audit/skr42-r31-private-rpc-20261005.json`,
`audit/skr42-r31-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 458 unveränderte Zwecke und sechs zurückgestellte.
Eine native 2027-Freigabe besteht weiterhin nicht. Der vollständige Kontenaudit
steht in `audit/balance-mapping-r31-20261005.json` und bleibt Exit 2.
**261 beziehungsweise 243 Originalkonten bleiben offen. Der Gesamtauftrag ist
nicht abgeschlossen.** Die folgenden Abschnitte dokumentieren frühere Stände.

## KOST1-Sphärenkostenstellen

Die private Testanwendung unterstützt in Belegpositionen und Ausgangsrechnungen
alle 41 ursprünglichen Sphärenkostenstellen: 1 und 11–19 (ideeller Bereich),
2 und 21–29 (Vermögensverwaltung), 3 und 31–39 (Zweckbetrieb), 4 und 41–49
(wirtschaftlicher Geschäftsbetrieb) sowie Sammelposten 9. Der DATEV-Export
behält den exakten KOST1-Wert. Die Jahresauswertung und abgeleitete
Bilanzbuchungen verwenden die Hauptsphäre; ursprüngliche Belegpositionen und
Rechnungssnapshots werden nicht umgeschrieben. 9 bleibt fachlich zu prüfen.
Buchwertpläne und manuell erfasste Bilanzzeilen führen weiterhin die Hauptsphäre.

936 Prüfungen tatsächlicher PHP-Methoden, sechs gezielte Regressionstestprogramme,
82 normalisierte Positionen für 2025/2026, 41 Ausgangsrechnungspositionen und
164 Export- sowie Auswertungszeilen bestehen. Alle 27 geänderten Laufzeitdateien
sind in der privaten Testanwendung eingebaut. Dort bestehen dieselben 936
PHP-Prüfungen, vier native XML-Prüfungen und zwölf byteidentische XML-Vergleiche.
Die 45 geschützten Geschäftstabellen bleiben unverändert. Der Browser bestätigt
alle 41 Auswahlwerte und die bisherigen Bilanzsummen. Nachweise:
`audit/kost1-accepted-20261005.json`, `audit/kost1-private-runtime-20261005.json`,
`audit/kost1-private-rpc-20261005.json`, `audit/kost1-ui-readback-20261005.json`.

Beim Abschluss dieser Kostenstellenfunktion blieb die Kontenabdeckung unverändert:
Damals waren 278 beziehungsweise 260 Originalkonten offen. Es besteht keine vollständige 2027-Freigabe.

## Revision 30: Beteiligungserträge, Wertpapierabschreibungen und Steuerreserven

Sieben weitere Originalkonten je Jahr: 70000, 70090, 72100, 72140, 72170,
76460 und 76480. Die tatsächliche Beteiligungsart, Steuerneutralität oder
Steuerart ist ausdrücklich anhand des Sachverhalts auszuwählen. Beteiligungen
an Personengesellschaften sind davon nicht umfasst. Die Auflösung einer
Steuerreserve behält das richtige Vorzeichen auch bei Erstattungen.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1411 | 1005 | 406 | 278 | 83,54 % |
| 2026 | 1679 | 1419 | 1013 | 406 | 260 | 84,51 % |

516 neue positive Fälle bestehen die native ERiC-Prüfung; zwei manipulierte
Cent-Summen werden abgelehnt. 748 XML-Dateien sind nach Übernahme byteidentisch:
516 neue und 232 bisher geprüfte Fälle. Sieben Testprogramme und zwei gezielte
Regressionen sowie 10472 tatsächliche PHP-Ansichtsprüfungen bestehen. Es wird
kein vollständiger Wiederholungslauf des historischen 1620-Fälle-Satzes behauptet.
Belege: `audit/skr42-r30-accepted-20261005.json`,
`audit/skr42-r30-prototype-native-20261005.json`,
`audit/skr42-r30-prototype-targeted-20261005.json`,
`audit/skr42-r30-live-xml-20261005.json`, `audit/skr42-r30-extra-20261005.json`.

Elf geprüfte Laufzeitdateien sind in der privaten Testanwendung eingebaut.
168 zusätzliche native Prüfungen über deren Anwendungskanal bestehen. Die
45 geschützten Geschäftstabellen bleiben unverändert; die Wiederanlaufprüfung
besteht. Der Browser bestätigt 1200 EUR Bilanzsumme, 200 EUR Jahresergebnis,
16 offene Stammkonten und den geprüften Entwurf ohne Abgabe. Belege:
`audit/skr42-r30-private-runtime-20261005.json`,
`audit/skr42-r30-private-rpc-20261005.json`,
`audit/skr42-r30-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 455 unveränderte Zwecke und sechs zurückgestellte.
Eine native 2027-Freigabe besteht nicht. Der Kontenaudit steht in
`audit/balance-mapping-r30-20261005.json` und bleibt Exit 2.
**278 beziehungsweise 260 Originalkonten bleiben offen. Der Gesamtauftrag ist
nicht abgeschlossen.** Die folgenden Abschnitte dokumentieren frühere Stände.

## Revision 29: ausländische Steuern und stille Verbindlichkeiten

Zehn zusätzliche Originalkonten je Jahr: 36500, 36510, 36520, 36530,
38800, 38810, 38820, 38830, 38840 und 38850. Bei stillen Verbindlichkeiten
ist die Vertragsart ausdrücklich auszuwählen; atypische Beteiligungen werden
nicht in eine steuerlich unzulässige oder pauschale Ersatzposition exportiert.
Die Originalkonten liefern die Laufzeitangaben. Ausländische Umsatzsteuer
wird getrennt ausgewiesen; bei allgemeinen Zahlungen an andere Länder bleibt
die tatsächlich abgerechnete Steuerart anhand des Bescheids zu bestätigen.
Gegenläufige Salden erfordern weiterhin getrennte, abgestimmte Gegenbuchungen.

| Originaljahr | Buchbar | XML-Regel | Automatisch | Bedingt | Offen | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1404 | 1005 | 399 | 285 | 83,13 % |
| 2026 | 1679 | 1412 | 1013 | 399 | 267 | 84,10 % |

104 neue positive XML-Dateien bestehen die native ERiC-Prüfung; zwei absichtlich
veränderte Cent-Summen werden erwartungsgemäß abgelehnt. Nach Übernahme sind
diese 104 Dateien und ein gezielter Rückgriff auf 128 bisher native geprüfte
Anlagenfälle byteidentisch. Es wird kein vollständiger erneuter Lauf der
bisherigen 1620 Fälle behauptet. Fünf gezielte Testprogramme und zwei
zusätzlich ausgewählte Regressionen bestehen. 476 zusätzliche Prüfungen der
tatsächlichen PHP-Kontenauswahl und alle 9926 bisherigen Ansichtsprüfungen
bestehen. Belege: `audit/skr42-r29-accepted-20261005.json`,
`audit/skr42-r29-prototype-native-20261005.json`,
`audit/skr42-r29-prototype-targeted-20261005.json`,
`audit/skr42-r29-live-xml-20261005.json` und
`audit/skr42-r29-extra-20261005.json`.

Acht geprüfte Laufzeitdateien sind in der privaten Testanwendung eingebaut.
48 zusätzliche native Prüfungen über deren Anwendungskanal bestehen,
einschließlich sämtlicher neuer Auswahlmöglichkeiten in beiden Originaljahren
und in Einzel- und Gesamtvereinsprofilen. 45 geschützte Geschäftstabellen
bleiben unverändert; die Wiederanlauf-Quellenprüfung besteht. Die lesende
Browserkontrolle bestätigt die bisherigen Summen (1200 EUR Bilanzsumme,
200 EUR Jahresergebnis), den geprüften Entwurf ohne Abgabe und 16 offene
Stammkonten. Belege: `audit/skr42-r29-private-runtime-20261005.json`,
`audit/skr42-r29-private-rpc-20261005.json` und
`audit/skr42-r29-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 448 unveränderte Zwecke und sechs zurückgestellte.
Eine native 2027-Freigabe besteht nicht. Der aktuelle Kontenaudit steht in
`audit/balance-mapping-r29-20261005.json` und bleibt Exit 2.
**285 beziehungsweise 267 Originalkonten bleiben offen. Der Gesamtauftrag ist
nicht abgeschlossen.** Die folgenden Abschnitte dokumentieren frühere Stände.

## Revision 28: Anzahlungen und Sachanlagen im Bau

13 weitere Originalkonten je Jahresplan sind mit einem nachweispflichtigen
Kostenanlagenweg ergänzt: 07050, 07100, 07200, 07250, 07350, 07400, 07500,
07550, 07650, 07700, 07800, 07850 und 07950. Die drei amtlichen Ausweisarten
trennen Sachanlagenanzahlungen, Gebäude im Bau und andere Sachanlagen im Bau.
Einzelgegenstand, Anschaffungsbeleg, Kostenbewertung, Buch-/Steuerwerte,
Bruttobewegungen und Vortrag bleiben prüfpflichtig. Betriebsbereitschaft,
Fertigstellung, Verrechnung und Nutzungsdauer werden nicht angenommen.
Das allgemeine Mischkonto 07000 bleibt ohne einen eigenen Typnachweis offen.

| Originaljahr | Buchbare Konten | Implementierte XML-Regel | Automatisch | Bedingte Regel | Noch ohne Regel | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1394 | 1000 | 394 | 295 | 82,53 % |
| 2026 | 1679 | 1402 | 1008 | 394 | 277 | 83,50 % |

676 positive XML-Dateien und zwei erwartete Cent-Summenfehler bestehen die
tatsächliche native Kandidatenprüfung. Nach abgegrenzter Übernahme werden
diese 676 Dateien und 938 bereits qualifizierte Dateien byteidentisch gebaut.
Sechs zusätzliche native Abschlüsse enthalten alle 13 neuen Konten gleichzeitig
und prüfen Einzel- und Gesamtbilanz mit unveränderten Werten, Wertminderung und
mehreren Bewertungs-/Abgangsereignissen. Die gezielte Regression umfasst 24
Programme: 21 bestehen unverändert; drei historische Annahmen über die vier
Kostenarten beziehungsweise 81 ursprünglichen Fälle scheitern an den neuen
Arten/Fallzahlen. Die negativen Originalberichte bleiben erhalten. Die drei
betroffenen Methoden werden auf die ausdrücklich quellgeprüfte Erweiterung
angepasst und bestehen erneut, einschließlich je 419 ursprünglicher Aufteilungs-
und Gesamtvereinsfälle. Eine vierte Methode schützt die eigene versiegelte
r28-Ausgabe gegen spätere Ergänzungen. Es wird kein neuer vollständiger Lauf
aller 63 Programme behauptet. Belege:
`audit/skr42-r28-accepted-20261005.json`,
`audit/skr42-r28-history-repair-20261005.json`,
`audit/skr42-r28-live-xml-20261005.json` und
`audit/skr42-r28-mixed-native-final-20261005.json`.

Zwei Auswahlbeschriftungen nennen jetzt Sachanlagen statt nur Grundstücke;
Ausweispositionen und Berechnung bleiben dabei unverändert. 1000 zusätzliche
Kontenauswahlprüfungen der tatsächlichen PHP-Ansichten und alle 9926 bisherigen
Ansichtsprüfungen bestehen. 18 qualifizierte Laufzeitdateien sind in der privaten
Testanwendung installiert; der Kontenaudit ist dort exakt identisch. 52 weitere
XML-Prüfungen über deren Anwendungskanal bestehen. Geschäftstabellen bleiben
unverändert, und die Wiederanlauf-Quellenprüfung besteht. Die lesende Browser-
Kontrolle bestätigt weiterhin 1200 EUR Bilanzsumme, 200 EUR Jahresergebnis,
den geprüften Entwurf ohne Abgabe und 16 offene Stammkonten-Zuordnungen.
Die Kostenanlagenauswahl zeigt die neue Art für Anzahlungen und Sachanlagen
im Bau. Belege: `audit/skr42-r28-extra-20261005.json`,
`audit/skr42-r28-label-amendment-20261005.json`,
`audit/skr42-r28-private-runtime-20261005.json`,
`audit/skr42-r28-private-rpc-20261005.json` und
`audit/skr42-r28-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 442 unveränderte Zwecke und sechs zurückgestellte.
Eine native 2027-Freigabe besteht nicht. Der aktuelle Kontenaudit steht in
`audit/balance-mapping-r28-20261005.json` und bleibt Exit 2.
**Die vollständige SKR42-Abdeckung und der Gesamtauftrag bleiben offen.**
Die folgenden Abschnitte dokumentieren frühere qualifizierte Stände.

## Revision 27: erworbene Rechte und allgemeine Anlagenkonten

Die Konten 01000, 01100, 01200, 01300, 01400, 04000 und 05000 sind für beide
Originaljahre ergänzt. 01000, 04000 und 05000 benötigen eine ausdrückliche Auswahl
der tatsächlichen Anlagenart. Alle Einzelgegenstände, Nutzungsdauern, Buch-/
Steuerwerte und Bruttobewegungen bleiben nachweispflichtig. Erwerbsrechte erhalten
die vorhandene lineare Abschreibung 62000; aus dem Kontonamen wird keine Laufzeit,
Sofortabschreibung, Ausmusterung oder steuerliche Wahlentscheidung abgeleitet.

| Originaljahr | Buchbare Konten | Implementierte XML-Regel | Automatisch | Bedingte Regel | Noch ohne Regel | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1381 | 1000 | 381 | 308 | 81,76 % |
| 2026 | 1679 | 1389 | 1008 | 381 | 290 | 82,73 % |

418 positive XML-Dateien und zwei erwartete Cent-Summenfehler bestehen die
tatsächliche native Prüfung der isolierten Kandidatenfassung. Nach exakt
abgegrenzter Übernahme werden alle 418 positiven Dateien und alle 520 zuvor
amtlich geprüften Anlagenfälle byteidentisch rekonstruiert. Zwei zusätzliche
Abschlüsse mit allen sieben neuen Anlagenarten gleichzeitig bestehen die native
Prüfung in der aktiven Codefassung. Die Prüfgrenzen bleiben getrennt belegt;
es wird kein erneuter Lauf sämtlicher früherer nativer XML-Fälle behauptet.

Der vollständige lokale Lauf umfasst 62 Programme: 61 bestehen; der historische
r26-Test zählt spätere Ergänzungen mit und scheitert an seiner Zählannahme.
Seine unveränderten Methoden bestehen im Originalbericht. Die geänderte Methode
prüft anschließend die exakt neun additionsgeschützten r26-Bindungen anhand der
versiegelten r26-Ausgabe und zusätzlich deren unveränderten Erhalt im aktuellen
Stand. Der negative Originalbericht bleibt erhalten. Auch die ursprünglichen
20 Anlagenarten werden weiterhin anhand ihrer eigenen versiegelten Ausgabe
geprüft. Belege: `audit/skr42-r27-accepted-20261005.json`,
`audit/skr42-r27-live-xml-20261005.json`,
`audit/skr42-r27-mixed-native-20261005.json` und
`audit/skr42-r27-r26-history-repair-20261005.json`.

16 qualifizierte Laufzeitdateien sind in die private Testanwendung übernommen.
Ihr Kontenaudit stimmt exakt mit dem lokalen Audit überein. 76 zusätzliche
XML-Prüfungen über den Anwendungskanal bestehen; Geschäftstabellen und bestehende
Abschlüsse bleiben unverändert. Die Quellenprüfung für den Wiederanlauf besteht.
Die lesende Browserprüfung bestätigt weiterhin die Bilanzsumme von 1200 EUR,
200 EUR Jahresergebnis, den geprüften Entwurf ohne Abgabe und 16 offene
Stammkonten-Zuordnungen. Belege:
`audit/skr42-r27-private-runtime-20261005.json`,
`audit/skr42-r27-private-rpc-20261005.json` und
`audit/skr42-r27-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 439 unveränderte Zwecke und sechs zurückgestellte.
Eine native 2027-Freigabe besteht nicht. Der vollständige aktuelle Kontenaudit
steht in `audit/balance-mapping-r27-20261005.json` und bleibt Exit 2.
**Die vollständige SKR42-Abdeckung und der Gesamtauftrag sind weiterhin offen.**
Die folgenden Abschnitte dokumentieren frühere qualifizierte Stände.

## Revisionen 24–26: qualifizierter aktueller Stand

Der gesamte SKR42 bleibt der bestätigte Arbeitsumfang. 71 weitere Konten für
2025 und 72 für 2026 sind quellengebunden eingebaut: operative Sonderfälle,
Stiftungs- und Gesellschaftskapital, gebundene Rücklagen sowie die vorhandenen
nachweispflichtigen Kostenwert-Anlagen und ihre Gegenkonten.

| Originaljahr | Buchbare Konten | Implementierte XML-Regel | Automatisch | Bedingte Regel | Noch ohne Regel | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1374 | 996 | 378 | 315 | 81,35 % |
| 2026 | 1679 | 1382 | 1004 | 378 | 297 | 82,31 % |

Alle früheren Jahresbindungen bleiben erhalten. Ausstehende Kapitalbeiträge
werden mit den amtlichen negativen Rechengewichten als Kapitalabzug dargestellt.
Sachverhaltsauswahlen und Anlagenbewertungen benötigen ihre konkreten Nachweise;
auch ein Nullsaldo kann diese Voraussetzungen nicht umgehen. Amtliche allgemeine
Gültigkeitsgrenzen werden zusätzlich zu den Wirtschaftsjahresgrenzen geprüft.
Eine bereits abgelaufene KStG-Zuführung bleibt nach dem tatsächlichen nativen
Fehler gesperrt; der negative Originalnachweis ist erhalten.

1200 neue positive XML-Dateien bestehen die tatsächliche native 6.9-Prüfung.
2706 weitere sind byteidentisch zu zuvor nativ geprüften Dateien; zusammen sind
3906 positive Fälle und sechs erwartete Cent-Summenfehler nachgewiesen.
Die 61 Offlineprogramme umfassen 60 bestandene Programme im ursprünglichen Lauf
und einen ausschließlich wegen falscher Inventarsortierung fehlgeschlagenen
Inventartest. Die Sortierung wurde separat korrigiert und erneut geprüft;
Programmmenge und Laufzeitquellen blieben dabei unverändert. 9926 Prüfungen der
tatsächlichen PHP-Kontenansichten bestehen. Belege:
`audit/skr42-r26-accepted-20261005.json`,
`audit/skr42-r26-native-final-20261005.json`,
`audit/skr42-r26-offline-final-20261005.json` und
`audit/skr42-r26-inventory-repair-20261005.json`.

21 qualifizierte Dateien sind in die bestehende private Testanwendung übernommen.
Der dortige Kontenaudit stimmt exakt überein. 22 weitere XML-Prüfungen über den
Anwendungskanal bestehen; alle 45 Geschäftstabellen bleiben unverändert. Auch die
Quellenprüfung für den Wiederanlauf besteht. Die lesende Browserkontrolle zeigt
1200 EUR Aktiva und Passiva, 200 EUR Ergebnis, den vorhandenen amtlich geprüften
Entwurf ohne Abgabe und weiterhin 16 offene Stammkonten-Zuordnungen. Belege:
`audit/skr42-r26-private-runtime-20261005.json`,
`audit/skr42-r26-private-rpc-20261005.json` und
`audit/skr42-r26-ui-readback-20261005.json`.

Die 6.10-Vorbereitung enthält 432 quellengleiche Zwecke und sechs zurückgestellte;
eine native 6.10- oder 2027-Freigabe besteht weiterhin nicht. Der aktuelle
Kontenaudit `audit/balance-mapping-r26-20261005.json` enthält alle offenen Konten
und meldet weiterhin Exit 2. **Die vollständige SKR42-Abdeckung und der
Gesamtauftrag sind noch nicht erreicht.** Die nachfolgenden Abschnitte halten
früher qualifizierte Stände fest.

## Revisionen 21–23: gesamter SKR42 als bestätigter Arbeitsumfang

Der Benutzer hat den gesamten SKR42 einschließlich Stiftungen und gGmbHs als
Umfang bestätigt. Eine erneute Einschränkung auf typische Vereinskonten ist
nicht erforderlich. **Der Gesamtauftrag ist weiterhin nicht vollständig erfüllt.**

| Originaljahr | Buchbare Konten | Implementierte XML-Regel | Automatisch | Bedingte Regel | Noch ohne Regel | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1303 | 948 | 355 | 386 | 77,15 % |
| 2026 | 1679 | 1310 | 955 | 355 | 369 | 78,02 % |

107 weitere Konten pro Originaljahr sind quellengebunden ergänzt: 36 Finanz-
und Zinskonten, 32 weitere operative Konten sowie 39 Umsatzsteuerkonten.
Eine bedingte Regel kann eine Sachverhaltsauswahl, einen Bewertungsnachweis oder
einen nachgewiesenen Abschluss des Verrechnungskontos voraussetzen. Sie ist
keine automatische fachliche Bestätigung und wird nicht als solche angezeigt.
Alle alten Zweckdefinitionen und Jahresbindungen bleiben erhalten.

Die Nachweise stehen in `resources/accounting/reviews/financial-income-r21.json`,
`operating-r22.json` und `vat-r23.json` sowie in den versiegelten Jahresrevisionen
2025/2026-r21 bis r23. Verbundene Unternehmen werden zusätzlich als nicht
summenwirksame Angaben ausgewiesen. Dagegen sind die Abzinsungspositionen nach
den tatsächlichen amtlichen Rechenbeziehungen reguläre Gewinnbestandteile.
Die steuerlich gesperrte Beteiligungsposition `earnings.partnerships` wurde nicht
freigegeben. Steuerliche Überleitungen werden nicht aus Kontobezeichnungen erraten.

Vorsteuer- und Umsatzsteuer-Verrechnungskonten werden nur bei exakt bestätigtem
Nullsaldo zugelassen. Synthetische Originalbuchungen belegen das Umbuchen auf die
verbleibende Forderung oder Verbindlichkeit einschließlich umgekehrter Salden.
Die erst später abziehbare Vorsteuer 14820 bleibt dagegen eine Forderung und
wird nicht durch eine Nullsaldo-Anforderung verdeckt.

Die native Prüfung umfasst 1944 positive XML-Fälle mit ERiC-Code 0 und sechs
gezielt um einen Cent verfälschte Summen mit dem erwarteten fachlichen Fehler
610001002. Beide Originaljahre, beide Berichtsprofile, alle neuen Auswahlziele,
negative GuV-Salden, Stornos und gemischte Konten jeder Revision werden geprüft.
Der unveränderte Ausgangsstand durchläuft außerdem 58 Offlineprogramme:
57 bestehen, eines findet zwei ungenutzte, nur im Python-Generator freigeschaltete
Ziele ohne entsprechendes Angebot in PHP. Dieser negative Originalbericht bleibt
erhalten; die beiden überschüssigen Freigaben werden entfernt.

Die zusätzliche Prüfung der tatsächlichen PHP-Ansichten findet zwei Fehler:
Auswahlkonten durften aus ihrem vorausgewählten Ziel keinen festen Umsatzsteuersatz
ableiten; Hinweise zum noch offenen Umsatzsteuerabschluss waren ausgeblendet.
Beides ist korrigiert. Zehn gezielte Python-/PHP-Programme einschließlich 6234
Prüfungen der neuen Kontenansichten bestehen auf dem endgültigen Stand. Alle 1944
zuvor nativ geprüften XML-Dateien werden danach byteidentisch rekonstruiert.
Der Nachweis ist somit eine unveränderte breite Ausgangsprüfung plus eine exakt
abgegrenzte Fünf-Dateien-Korrektur mit Nachprüfung, kein behaupteter zweiter
vollständiger Lauf aller Programme.

Belege: `audit/skr42-r21-r23-offline-20261005.json`,
`audit/skr42-r21-r23-native-20261005.json`,
`audit/skr42-r21-r23-integration-fix-20261005.json` und
`audit/skr42-r21-r23-accepted-20261005.json`.
17 qualifizierte Laufzeitdateien sind in die bestehende private Testanwendung
übernommen. Ihr Kontenaudit stimmt exakt überein; zwölf zusätzliche XML-Dateien
bestehen den tatsächlichen privaten Anwendungskanal. Die Geschäftsdaten bleiben
vor und nach Übernahme und Prüfung unverändert; auch die Quellenprüfung für
einen Wiederanlauf besteht. Belege: `audit/skr42-r23-private-runtime-20261005.json`
und `audit/skr42-r23-private-rpc-20261005.json`.

Die lesende Browsernachprüfung zeigt weiterhin 1200 EUR Aktiva und Passiva,
200 EUR Ergebnis und den bestehenden Entwurf als „Geprüft · noch nicht abgegeben“.
Die 16 offenen Stammkonten-Zuordnungen bleiben sichtbar. Diese Zuordnungsebene
ist von den Regeln des Jahreskatalogs und den ausdrücklichen Zuordnungen im
Steuerentwurf zu unterscheiden; auch hier wird keine Vollständigkeit behauptet.

Die 6.10-Katalogvorbereitung enthält jetzt 365 quellengleiche Zwecke; sechs
abweichende Zwecke bleiben zurückgestellt. Das ist keine native Freigabe für
2027. Die amtliche Veröffentlichung nennt für 6.10 erst November 2026 als
voraussichtlichen Testtermin und Mai 2027 für Echtfälle
([eSteuer](https://www.esteuer.de/)).

Die vollständigen verbleibenden Listen stehen in
`audit/balance-mapping-r23-20261005.json`; die Gliederung nach tatsächlich
gedruckten Abschlusspositionen in `audit/skr42-remaining-r23-grouped-20261005.json`.
Offen sind insbesondere weitere Anlagen und Bewertungen, Gegen- und
Verrechnungskonten, Ergebnisverwendung, rechtsformspezifische und ausschließlich
handelsrechtliche Fälle. Sie werden nicht auf pauschale Sonstiges-Positionen
umgebucht. Der Kontenaudit meldet weiterhin Exit 2 und `mapping_complete: false`.
Keine Produktivfreigabe, kein Finanzamtsversand; Kulturhus bleibt EÜR.

Die folgenden Abschnitte dokumentieren den zuvor qualifizierten Stand.

## Revision 20

| Originaljahr | Buchbare Konten | Mit implementierter XML-Regel | Bedingte Regel | Noch ohne Regel | Abdeckung |
| --- | ---: | ---: | ---: | ---: | ---: |
| 2025 | 1689 | 1196 | 273 | 493 | 70,81 % |
| 2026 | 1679 | 1203 | 273 | 476 | 71,65 % |

Der Nenner umfasst weiterhin sämtliche buchbaren Konten der beiden Original-
Kataloge. Bedingte Regeln benötigen den tatsächlichen Sachverhalt und sind keine
automatische Bestätigung seiner steuerlichen Behandlung. Fehlende Konten werden
nicht über Kontonummern, Freitext oder eine pauschale Sonstiges-Position zugeordnet.
Der Prüfbericht `audit/balance-mapping-r20-20261005.json` hält die vollständigen
offenen Listen fest. `tools/balance-mapping-audit.php` meldet weiterhin Exit 2 und
`mapping_complete: false`.

Neu hinzugekommen sind je Originaljahr diese 19 Konten:

* Wertpapiere: 15000, 15040, 15100, 15250, 15300.
* Pensionen und Steuerrückstellungen: 30000, 30050, 30100, 30110, 30150, 30200.
* Weitere Rückstellungen: 30740, 30750, 30760, 30790, 30850, 30950, 30990.
* Damnum/Disagio: 19400.

Die ursprünglichen Kontenbedeutungen, Signaturen und Quellen sind in
`resources/accounting/reviews/balance-positions-r20.json` dokumentiert.
Die beiden neuen Jahresrevisionen ergänzen die unveränderten alten Bindungen.
Bei 15000 müssen die Beteiligungsart und gegebenenfalls eine nicht aufteilbare
Sammelposition ausdrücklich gewählt werden. Ein gleichzeitiger Ausweis dieser
Sammelposition und ihrer Unterpositionen wird gegen Doppelzählung gesperrt.

## Prüfung und Grenzen

Die Prüfung verwendet echte PHP-Buchungsberechnungen mit erfundenen Belegen,
beide Originaljahre, Einzelgeschäftsbetrieb und vollständigen steuerpflichtigen
Verein. Sie umfasst positive Buchungen, Stornos, fehlende Detailauswahlen,
sämtliche freigegebenen Auswahlmöglichkeiten und einen gemeinsamen Abschluss
mit allen neuen Konten. Negative Bilanzsalden werden weiterhin zur getrennten
Darstellung zurückgewiesen. Vorhandene Jahresrevisionen bleiben unverändert.

272 gültige XML-Dateien bestehen die native ERiC-Prüfung mit Code 0 und Flag 2.
Bei zwei zusätzlichen Gegenproben wird ausschließlich die Bilanzsumme um einen
Cent verändert; beide werden mit dem fachlichen Code 610001002 zurückgewiesen.
Der zuerst fehlerhafte Test serialisierte das ganze XML neu und scheiterte vor
der vorgesehenen Rechenprüfung. Sein negativer Originalbericht bleibt erhalten;
die Korrektur und die beiden tatsächlich ausgeführten Gegenproben stehen separat
in `audit/balance-positions-r20-native-negative-repair-20261005.json`.
Die gültigen XML-Nachweise stehen in
`audit/balance-positions-r20-native-final-20261005.json`. Der abschließende
Gesamtlauf auf dem korrigierten Quellstand wird separat als
`audit/balance-positions-r20-native-accepted-20261005.json` geführt.

958 reine PHP-Prüfungen kontrollieren die tatsächliche Kontenauswahl und deren
Formularansicht, inklusive zulässiger Ziele, lesbarer Beschriftungen und
HTML-Escaping. Die vollständige Offline-Regression unter
`audit/balance-positions-r20-offline-20261005.json` umfasst 55 Programme:
54 bestehen, eines findet die noch fehlende Aktualisierung der 6.10-Überleitung.
Dieser Originalbericht bleibt negativ. Der genaue Drei-Dateien-Nachtrag und
acht gezielte Nachprüfungen werden unter
`audit/balance-positions-r20-offline-accepted-20261005.json` zusammengeführt;
nur ein abgeschlossener Bericht mit `passed: true` gilt als bestanden.

Die 6.10-Vorbereitung erhält dadurch 14 weitere unveränderte Kontenzwecke:
328 sind überleitbar, sechs bereits zuvor zurückgestellte Zwecke bleiben
zurückgestellt. Alle bisherigen Projektionsnachweise und die 6.9-Laufzeitregeln
bleiben inhaltlich unverändert. Die native 2027-Freigabe bleibt gesperrt.

Die fehlenden Familien umfassen unter anderem Anlagenbewertung, Wertberichtigungen,
Verrechnungskonten, Ergebnisverwendung sowie ausschließlich handelsrechtliche
Positionen. Für letztere ist eine steuerlich gesperrte Taxonomieposition kein
gültiges XML-Ziel. Die angelegte Arbeitsuntersuchung zu Finanzbeteiligungen wurde
deshalb nicht als weitere Kontenrevision freigegeben.

Diese Revision allein ist keine vollständige Vereinsbilanz-Freigabe und keine
Produktivfreigabe. Acht geprüfte Dateien wurden in die laufende private Testkopie
übernommen. Der dort ausgeführte Kontenaudit stimmt vollständig mit dem lokalen
Bericht überein. Vier zusätzliche XML-Dateien bestehen den tatsächlichen privaten
Anwendungskanal mit Code 0; die Buchungsdaten bleiben vor und nach der Übernahme
und diesem Nachtest unverändert. Auch die Quellenprüfung des Wiederanlaufs besteht.
Nachweise: `audit/balance-positions-r20-private-runtime-20261005.json` und
`audit/balance-positions-r20-private-rpc-20261005.json`.
Das echte Kulturhus bleibt EÜR; es erfolgten kein Finanzamtsversand,
kein Zertifikatszugriff und keine Änderung produktiver Buchungsdaten.

Bei der lesenden Browserprüfung zeigt der synthetische Abschluss weiterhin
1.200 EUR Aktiva und Passiva sowie 200 EUR Ergebnis. Seine Kontenansicht zeigt
16 offene Stammzuordnungen, darunter unbenutzte allgemeine Konten. Die schon
geprüfte XML-Datei verwendet dagegen ausdrücklich gewählte Zuordnungen im
separaten Steuerentwurf. Auch diese verschiedenen Zuordnungsebenen dürfen nicht
zu einer pauschalen Aussage „alle Konten vollständig zugeordnet“ vermischt werden.
