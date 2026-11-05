# Bilanzintegration

Aktuelle Ergänzung vom 16. Oktober 2026: Die ausdrücklich konfigurierte interne
Buchhaltung für datierte Vollmonatszeiträume ist im Produktivmodus freigegeben.
Bedienung, Prüfung und verbleibende Fachgrenzen stehen in
[Produktive interne Zeiträume](../BILANZ-PRODUKTIVE-ZEITRAEUME.md). Die folgenden
älteren Revisionsnachweise behalten ihren jeweils damaligen Umfang; sie sind
keine Freigabe des eigenen produktiven E-Bilanztransports.

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

## Historischer Stand R79 · 07.10.2026

62090 (erworbener Firmenwert) und 62310 (Gebäude-AfaA) ergänzen die einzeln belegten Original-Jahresabschreibungen für 2025/2026. Zwölf vollständige MySQL-Fälle für Verein, Stiftung und gGmbH bestehen die native XML-Prüfung mit ERiC-Code 0. Vierzehn veränderte Originalquellen werden abgewiesen. Der Code Review korrigiert die Verbindung zwischen Firmenwert-Jahresnachweis und steuerlichem 180-Monats-Plan und erhält die bisherige GWG-Zuordnung. Die bestehenden sechs AfaA-XMLs bleiben bytegleich.

Katalogabdeckung bei R79: **1665/1689 für 2025 (24 offen), 1660/1679 für 2026 (19 offen)**. Die Regeln gelten für anfängliche vollständige Kalenderabschlüsse mit getrennt bestätigten, gleichen Buch- und Steuerbeträgen. Abweichende Steuerwerte, gemischte Anlagen und Folgejahre sind damit nicht allgemein freigegeben. Der konkrete synthetische Abschluss 2025 hat weiterhin keine offenen aktiven Kontenzuordnungen. Das laufende Jahr 2026 wurde nicht geschlossen. Die Gesamtaufgabe ist nicht zu 100 Prozent erledigt.

Nachweise: `audit/skr42-r79-accepted-20261007.json`, `audit/skr42-r79-original-exceptional-stored-native-reviewed-batch3-20261007.json`, `audit/skr42-r79-source-guards-20261007.json`. Historische Revisionsstände unten sind keine aktuellen Restzahlen.

## XML-Verknüpfung R78 · 07.10.2026

Der vorhandene synthetische Jahresabschluss 2025 ist quellgebunden mit seinem gespeicherten XML-Entwurf verbunden: vier bestätigte Kontenzuordnungen, zwölf unbenutzte Konten, null offene aktive Konten. Native ERiC-Wiederholungsprüfung: Code 0; heruntergeladene XML-Prüfsumme unverändert. Die Anzeige prüft Quelle, Steuerwerte, XML-Prüfsumme, amtlichen Rückgabestatus und Programmstand. Ein zusätzlicher Lesemodus erlaubt dieselbe Quellprüfung mit reinen Leserechten; Schreiben, Freigeben und Senden behalten ihre bisherigen Berechtigungen.

Zum Zeitpunkt R78 fehlten 26/21 Original-Katalogregeln für 2025/2026; den aktuellen Stand nennt R86 oben. Keine Produktivabgabe, keine Übermittlung. Details und Nachweise in `XML-RESTPUNKTE-2026-10-07.md`.

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
und 1658/1679 (98,75 %). Die damalige Anzeige von 16 offenen Entscheidungen des
Übungsjournals wurde mit R78 korrigiert: vier gespeicherte Zuordnungen und zwölf unbenutzte Konten. **Die gesamte XML-Katalogabdeckung ist noch nicht abgeschlossen.**
UI-Feintuning, Dokumentennavigation und Installer bleiben nach der neuesten
Priorisierung zurückgestellt. [Genaue XML-Restliste](XML-RESTPUNKTE-2026-10-07.md).
Nachweise: `audit/skr42-r77-accepted-20261007.json`,
`audit/skr42-r77-private-rpc-20261007.json` und
`audit/skr42-r77-ui-readback-20261007.json`.

## Bilanz-PDF und Review – 07.10.2026

Die Kalenderjahresbilanz bietet jetzt „Bilanz, GuV und Anlagen als PDF“.
Der Export enthält Aktiva, Passiva, Jahresergebnis, alle vier Vereinsbereiche,
vollständige Kontensalden und das Anlagenverzeichnis mit Summen. Planmäßige AfA,
Wertminderung und Wertaufholung erscheinen getrennt auch in der Bildschirmansicht.
Ein offenes Jahr bleibt ausdrücklich ein Arbeitsstand; die PDF-Datei bestätigt
keine Steuerabgabe. Bilanzberechtigung, Verein und aktueller Quellenstand werden
innerhalb eines konsistenten, nur lesenden MySQL-Snapshots geprüft.

Das Review fand einen Fehler bei offenen Jahren: Der Download verglich zunächst
den Rohbericht ohne das in der Oberfläche ergänzte Anlagenverzeichnis. Der Export
liest jetzt dasselbe tatsächliche Verzeichnis. Eine Regression prüft den Hash
des sichtbaren Links gegen den vollständigen originalen MySQL-Bericht.
Sechs MySQL-Fälle (2025/2026, Verein/Stiftung/gGmbH), fünf PDF-Regressionen,
sechs Ansichten und zwölf bytegleiche bereits qualifizierte XML-Ausgaben bestehen.
Beide tatsächlichen Browserdownloads funktionieren; sämtliche 45 Geschäftstabellen
der privaten Anwendung sind unverändert. Die PDFs wurden vollständig gerendert
und visuell kontrolliert. Nachweise liegen unter `audit/balance-pdf-…-20261007.json`.

Dies ist eine Export-/Darstellungsergänzung nach Revision 76. Die Kontenabdeckung
bleibt **1660/1689 (98,28 %) für 2025 und 1655/1679 (98,57 %) für 2026**.
29 beziehungsweise 24 Originalkonten und die getrennten 16 Zuordnungsentscheidungen
des Übungsjournals sind weiterhin offen. Eine vollständige Bilanz-/XML-Freigabe
wird dadurch nicht behauptet. Kulturhus bleibt produktiv EÜR.

## Kontenabdeckung – 05.10.2026

Die eingebauten Revisionen bis 76 ergänzen einzeln belegtes Kaufleasing
auf Konto 62500. Wirtschaftliches Eigentum wird für HGB und Steuer getrennt
anhand des Originals bestätigt. Sämtliche neuen Speicherprüfungen laufen auf
MySQL. 1660 beziehungsweise 1655 Originalkonten besitzen eine XML-Regel;
**29 beziehungsweise 24 bleiben offen.** Zwölf gespeicherte native Originalfälle,
sieben Quellenänderungen, vierzehn Vertragsgrenzen, vier neue MySQL-Ansichten,
804 datenbankfreie Ansichtsprüfungen und sieben gezielte Prüfprogramme bestehen.
Die laufende private Anwendung besteht zwölf weitere native XML-Prüfungen
bei identischen PHP-Originalwerten und unveränderten Geschäftsdaten.
Originaljahr 2027 und native Freigabe 6.10 bleiben offen.
Einzelheiten: [Kontenabdeckung](BILANZ-KONTENABDECKUNG-2026-10-05.md).

Die KOST1-Erfassung unterstützt die optionalen Unterkostenstellen 11–19, 21–29,
31–39 und 41–49. Beleg und DATEV-Export behalten den exakten Kostenstellenwert;
Jahresauswertung und abgeleitete Bilanzbuchungen summieren nach Hauptsphäre.
Sammelposten 9 bleibt fachlich zu klären. Die Funktion ist in der privaten
Testanwendung mit 936 PHP-Prüfungen, sechs gezielten Regressionstestprogrammen,
vier weiteren nativen XML-Prüfungen und lesender Browserkontrolle nachgewiesen.

## Taxonomien 6.7–6.10 und Jahrespflege 2027 – 03.10.2026

Die amtlichen XBRL-Quellen 6.7, 6.8, 6.9 und 6.10 sind nun getrennt mit
Prüfsummen hinterlegt und in der Jahresübersicht sichtbar. Für Beginnjahr 2025
ist 6.8 regulär, 6.9 optional; die bestehenden Regeln bleiben auf 6.9. 6.10 ist
optional für Beginnjahr 2026 und regulär ab 2027. Ein Quellenimport ist keine
Ausgabe- oder Abgabefreigabe; die lokale native Prüfung bleibt auf 6.9 begrenzt.

Für den Kontenvergleich 2026 → 2027 gibt es ein eigenes Vorbereitungspaket mit
6.10. Eine quellengebundene Überleitung erhält 314 unveränderte Zwecke; sechs
amtlich geänderte Zwecke bleiben zur Prüfung zurückgestellt. Die Definitionen
aller 320 historischen Zwecke sowie bestehende Jahreskataloge bleiben unverändert.
Eine echte 2027-DATEV-PDF fehlt noch. Synthetische Prozessfälle ersetzen diese
Quelle nicht; die tatsächliche 2026-PDF wird als angebliche 2027-PDF abgewiesen.

14 gezielte lokale Programme und vier Programme im isolierten MySQL-Nachlauf
bestehen auf den getrennt nachgewiesenen Quellständen. Geschützte Produktivdateien
sind unverändert; kein Versand. **Die allgemeine Vereinsbilanz bleibt in
Entwicklung, Kulturhus bleibt EÜR. Die vollständige UI-Prüfung folgt der
Gesamtabnahme.** Ablauf und Grenzen:
[Jahrespflege und Taxonomien](ZUKUNFTSJAHRE-2027-2026-10-03.md),
[Quell- und Prüfnachweis](TAXONOMIEN-6.7-6.10-2026-10-03.json).
Die folgenden Abschnitte halten die vorherigen Entwicklungsstände fest.

## Jahres-/Taxonomie-/ERiC-Paketbindung – 03.10.2026

**Entwicklungsstand, keine allgemeine Bilanzfreigabe. Kulturhus bleibt EÜR.**
Die Fortsetzung im Worktree `eb9a` erweitert die vorhandene Jahrespflege:
Kontenvergleiche und Kandidatenpakete binden nun ein explizites geprüftes
Jahres-/Taxonomie-/Adapterpaket. Der XML-Generator verwendet dieselbe Grenze;
die native Brücke gleicht API und Bilanz-Prüfmodul mit dem geprüften ERiC-Stand ab.
Neue Jahre oder eine neue DATEV-PDF allein erweitern die Freigabe nicht.
**Weiterhin nur Taxonomie 6.9 und 2025/2026.**

Details und gezielte Nachweise: [Jahrespakete](JAHRESPAKETE-2026-10-03.md).
Der neue [Code- und Nachweisabgleich](BILANZ-LUECKENMATRIX-2026-10-03.md) trennt
vorhandene Anlagen-/Sphären-/Zeitraum-/Korrekturfunktionen von ihren konkreten
Grenzen. Die noch fehlenden Anlagen- und Bewertungsfamilien bleiben offen;
die Kontenabdeckung der Revision 19 ändert sich dadurch nicht.

45 Offlineprogramme bestanden auf unverändertem Anwendungscode; die native
Paketprüfung ist unter Windows und Linux geprüft. Der vorher gestartete
Serverlauf `d725ab22c6bc07ed` endete mit 162 bestandenen Programmen und einer
veralteten Umsatzsteuer-Testannahme. Nach deren belegter Korrektur bestand
der gesonderte Nachlauf `0a73a3ceb614155d` alle sieben ausgewählten Programme,
einschließlich gespeicherter Jahrespflege und tatsächlicher PDF-Prozesskette.
Quellstände und Berichte bleiben getrennt; der erste Bericht wird nicht
nachträglich als bestanden bezeichnet. Geschützte Produktivdateien unverändert,
temporäre Testkopien entfernt. Keine allgemeine Bilanzfreigabe und kein Beginn
der abschließenden Gesamt-UI-Prüfung.

## Anleihen und Verbindlichkeiten gegenüber Unternehmen – 03.10.2026, Revision 19

**Entwicklungsstand, noch keine vollständige Bilanzfreigabe. Kulturhus bleibt
EÜR. Keine produktive Konfigurationsänderung, keine echte Steuerabgabe.**

26 weitere Originalkonten je Jahr sind implementiert: nicht wandelbare und
wandelbare Anleihen, sonstige Schuldtitel/Bewilligungen sowie Verbindlichkeiten
gegenüber verbundenen Unternehmen und Beteiligungsunternehmen. Die Seiten 8
der Original-DATEV-PDFs für 2025 und 2026 wurden bildlich geprüft, insbesondere
die im alten Textauszug verrutschten Zeilen zu 34030/34200. Originalkataloge
und historische Zweckdefinitionen wurden nicht umgeschrieben.

10 Konten erhalten ein eindeutiges Ziel. 16 Konten benötigen eine Sachangabe
zum Gesellschafterbezug; eine freie Kontobeschreibung ist dafür kein Nachweis.
Restlaufzeit, Wandelbarkeit und Lieferungs-/Leistungsbezug werden zusätzlich
ausgegeben, ohne die Schulden erneut zur Bilanzsumme zu addieren. Ein
allgemeines Konto ohne Laufzeitangabe erhält keine erfundene Laufzeit.
Widersprüchliche Laufzeitangaben sowie unvollständige verschachtelte
Zusatzangaben werden abgewiesen. Gegensalden, Clearingkonten, rückgängig
gemachte Bewilligungen und atypisch stille Beteiligungen erhalten durch
diese Erweiterung keine pauschale Ersatzzuordnung.

| Jahr | Buchbare Konten | Mit XML-Regel | Davon bedingt | Ohne XML-Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 1.177 | 265 | 512 |
| 2026 | 1.679 | 1.184 | 265 | 495 |

320 versionierte Kontenzwecke; die neuen Ausgaben `2025-r19.json` und
`2026-r19.json` ergänzen Revision 18. Alle 26 neuen Konten behalten beim
Jahresvergleich 2025 → 2026 automatisch denselben geprüften Zweck.
Geänderte Bedeutung oder noch ungeprüfte künftige Jahre werden nicht
still freigeschaltet. Quellbindung:
`resources/accounting/reviews/financial-debt-r19.json`.

**Abgeschlossen:** 11 neue Python-Prüfmethoden, 1.502 PHP-Prüfungen für
Zuordnung/Ansicht, 712 native ERiC-Validierungen (beide Jahre, Einzelbetrieb
und Gesamtverein, jede angebotene Sachentscheidung, normale und stornierte
Salden). Die native Validierung verwendete ausschließlich Flag 2, keinen
Schlüssel, kein Zertifikat und keinen Transport. Originaljournal und
Bilanzsummen bleiben unverändert. Nachweis:
`audit/ebilanz-financial-debt-r19-native.json`.

Der gezielte Serverlauf
`audit/ebilanz-mysql-host-20260918-28828aaaf2e89a96.json` besteht mit neun
Prüfprogrammen. Enthalten sind 140 neue gespeicherte Finanzschuld-Prüfungen
und 164 Prüfungen der vorherigen Forderungs-/Verbindlichkeitsfälle, jeweils
zusätzlich zur Basissuite. Steuerwertkorrekturen aktualisieren nur die
betroffenen Zusatzangaben; die alte Freigabe wird ungültig. Getestet sind
außerdem Leserechte, Vorstandsfreigabe, exakte Jahres-/Zeitraumbindung und
die zuvor korrigierten Kapital-/Aufwandsprüfungen. Nur die dauerhaft
freigegebene synthetische MySQL-Datenbank wurde benutzt; geschützte
Produktionsdateien sind unverändert, die temporäre Testkopie wurde entfernt.

**Noch laufend:** Der separate Gesamtserverlauf enthält jetzt 163 Programme
und speichert jeden abgeschlossenen Test einzeln. Seine Zwischenergebnisse
sind keine Gesamtabnahme. Die Python-Inventur enthält 44 Programme. Die
vollständige Konten-/Anlagen-/Sonderfallabdeckung, insbesondere
Grundstücke/Gebäude/Finanzanlagen/Sammelposten und Teilmonats-Abschreibungen,
sowie die vollständige Übermittlungs-/Gesamtabnahme bleiben offen. Keine
pauschale Freigabe und keine vorgezogene Gesamt-UI-Abnahme.

## Forderungen, Anzahlungen und Restlaufzeiten – 01.10.2026, Revision 18

**Entwicklungsstand, kein vollständiger Bilanzrelease. Kulturhus bleibt EÜR;
Produktionsdaten und produktive Konfiguration wurden nicht verändert.**

79 weitere originale Konten je Jahr sind an geprüfte Kontenzwecke gebunden.
Darunter sind laufende Bau-/Arbeitsaufträge, geleistete und erhaltene
Anzahlungen, Kunden- und Lieferantenrechnungen, Forderungen gegen verbundene
Unternehmen beziehungsweise Beteiligungsunternehmen, weitere Forderungen und
Steuerverbindlichkeiten. 56 Ergänzungen haben ein eindeutiges Ziel; 23 benötigen
eine Sachangabe, beispielsweise die tatsächliche Steuerart oder den
Gesellschafterbezug. Unterschiedliche Sachverhalte werden nicht pauschal unter
„Sonstiges“ versteckt. Insgesamt bestehen 301 versionierte Kontenzwecke.

| Jahr | Buchbare Konten | Mit XML-Regel | Davon bedingt | Ohne XML-Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 1.151 | 249 | 538 |
| 2026 | 1.679 | 1.158 | 249 | 521 |

Die DATEV-Angaben zur Restlaufzeit sowie zum Lieferungs-/Leistungsbezug werden
als zusätzliche „davon“-Angaben in das amtliche XML übernommen. Sie stammen
aus der gebundenen Original-Kontenzeile einschließlich ihrer übergeordneten
Beschreibung, nicht aus einer unscharfen Namenssuche. Sie verändern weder die
Bilanzsumme noch die Buchung und erzeugen keine doppelten Kontennachweise.
Korrekturen berücksichtigen den geprüften steuerlichen Schlusswert, nicht die
Summe der unterjährigen Bewegungen. Unzulässige Gegensalden bleiben gesperrt;
eine Forderung wird nicht unbemerkt als negative Forderung exportiert.

Original-PDF 2026 Seiten 2–4 und 8–9 bildlich geprüft. Beide Jahresquellen,
Kontenzeilen und Zielregeln sind in
`resources/accounting/reviews/working-capital-r18.json` gebunden. Neue versiegelte
Jahresausgaben `2025-r18.json` und `2026-r18.json` ergänzen die vorherigen
Ausgaben, ohne eine alte Regeldefinition, Buchung oder Freigabe umzuschreiben.
Der Zusatzangaben-Code ist Bestandteil des Laufzeit-Fingerabdrucks einer
Steuerfreigabe.

**Korrigierter Abdeckungsbericht:** Ein aus der gedruckten DATEV-Spalte bekannter
Bilanzbereich ist nicht automatisch eine vorhandene XML-Regel. Der Prüfbericht
`tools/balance-mapping-audit.php` unterscheidet dies jetzt ausdrücklich. Ein
Gebäudekonto mit bekannter Bilanzrubrik, aber fehlender steuerlicher Umsetzung
zählt damit weiterhin als offen. Die oben genannten Werte zählen tatsächliche
Regeln, nicht lediglich lesbare Rubriken.

**Gezielte Nachweise:** 504 native ERiC-Prüfungen decken die neuen Zwecke für
2025/2026, Einzelbetrieb/Gesamtverein, normale und ausgeglichene Salden sowie
jede angebotene Sachentscheidung ab. Es wird ausschließlich lokal mit Flag 2
validiert, ohne Zertifikat oder Transport. Nachweis:
`audit/ebilanz-working-capital-r18-native.json`.

Der abschließende gezielte MySQL-Serverlauf
`audit/ebilanz-mysql-host-20260918-583cbd6b6a5a5f93.json` besteht mit sieben
Programmen. Dazu gehören 3.602 neue Konten-/Ansichts-/Abdeckungsprüfungen und
104 neue Prüfungen mit tatsächlich gespeicherten Buchungen, gemischten
Bilanzkonten, Steuerwertkorrektur, Rollenprüfung und Vorstandsfreigabe. Die
neuen Abgaben werden nicht versendet; die eingebundene Basissuite verwendet
ausschließlich einen lokalen Transportersatz. Weitere Prüfungen betreffen die
bisherigen Kontenregeln, datierte Abgaben/Korrekturen und 230
Laufzeit-Fingerabdruckprüfungen. Nur die dauerhaft freigegebene synthetische
MySQL-Testdatenbank wurde verwendet; geschützte Produktionsdateien blieben
unverändert, temporäre Serverdateien wurden entfernt. Der vorausgehende Lauf
hatte eine veraltete Testannahme über noch nicht unterstützte Konten; dieser
Test wurde auf tatsächlich weiterhin offene Konten umgestellt und wiederholt.

Alle 43 inventarisierten lokalen Bilanz-Prüfprogramme bestehen. Während dieser
Serie wurde ausschließlich ein ungenutztes Python-Ziel aus der erlaubten Liste
entfernt; keine Jahresregel verwendete es. Diese einzige Abweichung ist anhand
der ursprünglichen und abschließenden Dateiprüfsummen nachgewiesen. Die acht
Brückenprüfungen wurden anschließend erneut ausgeführt; native Prüfung und
gezielter MySQL-Lauf verwenden bereits den abschließenden Stand. Für alle 79
neuen Konten übernimmt der Jahresvergleich 2025 → 2026 denselben geprüften
Kontenzweck automatisch. Zusammengeführter Nachweis:
`audit/working-capital-r18-final-acceptance.json`. Die Testinventur umfasst nun
161 PHP-Einstiegspunkte und 43 lokale Python-Programme. Daraus folgt noch keine
bestandene Gesamtabnahme aller Serverprogramme oder aller fachlichen Fälle.

Die allgemeine Bilanzfreigabe ist damit **nicht** erteilt. Weitere
Kontenzuordnungen, Gebäude/Grundstücke/Finanzanlagen/Sammelposten,
Anlagen-/Steuersonderfälle, Teilmonats-Zeiträume und die vollständige
Übermittlungs-/Gesamtabnahme bleiben offen. Die nachgelagerte Gesamt-UI-Prüfung
wird nicht als bereits erfolgt dargestellt.

## Jahresimport und private DATEV-Bibliotheken – 01.10.2026

**Die Bibliotheken sind auf dem Webspace installiert. Der neue Jahresimport
ist entwickelt und gezielt geprüft, aber noch nicht als Oberfläche
veröffentlicht. Kein vollständiger Bilanzrelease; Kulturhus bleibt EÜR.**

Unter Einstellungen → Steuerkontenliste → „Kontenplan aktualisieren“ ist eine
zentrale Jahresprüfung vorbereitet: Original von DATEV holen, Änderungen zum
Vorjahr vergleichen und ein quellengebundenes Prüfpaket erstellen. MySQL hält
Original, Prüfschritte, Versionen und Dateiprüfsummen getrennt je Verein fest.
Wiederholte Aufrufe legen nicht versehentlich denselben Auftrag erneut an;
veraltete Formulare, fremde Vereine und zwischenzeitlich entzogene Rechte
werden abgewiesen. Nur die zentrale Administration darf importieren. Ein
Kassenwart muss weder einzelne Kontonummern pflegen noch PDF-Dateien bearbeiten.

Unveränderte geprüfte Kontenzwecke werden übernommen; Änderungen und bestehende
Lücken bleiben erkennbar. Die fachliche Prüfung erfolgt auf einer
herunterladbaren Vergleichsseite, deren Prüfdatei anschließend zurückgegeben
wird. Das Paket aktiviert noch keine Regeln und überschreibt keine alten
Jahresausgaben. Unbekannte Taxonomien und unvollständige Zuordnungen verhindern
eine Freigabe. Bei noch nicht veröffentlichtem Folgejahr bleibt ein erneuter
Quellabruf möglich; die Ausgabe 2026 wird nicht als 2027 ausgegeben.

**Serverinstallation:** `pdfplumber` 0.11.9 und `openpyxl` 3.1.5 mit ihren
Abhängigkeiten liegen dauerhaft außerhalb des Webroots im privaten Verzeichnis
`/tmp/kulturhus-verein/catalog-runtime/20261001-8448b00e8a7f`. Der eigene Launcher
`python-private` ist über `VEREIN_SKR42_PYTHON` in der bestehenden privaten
Laufzeitkonfiguration hinterlegt. Die vorherigen Konfigurationsbytes wurden
nicht umgeschrieben, sondern nur diese Einstellung ergänzt. Website, Cron,
OCR-, Rechnungs-PDF- und ELSTER-Bibliotheken wurden nicht ersetzt.

Für eine neue Installation nennt `vereinsverwaltung/requirements-catalog.txt`
die direkten Abhängigkeiten. `requirements-catalog-linux311.lock` bindet alle
zehn verwendeten Linux-CPython-3.11-Pakete an SHA-256-Prüfsummen; der Lock ist
nicht für beliebige Plattformen geeignet. Die Pakete werden in einer eigenen
privaten Umgebung installiert, nicht in die OCR-/ELSTER-Umgebung. Der Launcher
muss sowohl aus dem Web-PHP als auch aus der CLI erreichbar sein. Auf diesem
Webspace berücksichtigt er den unterschiedlichen Pfadpräfix innerhalb bzw.
außerhalb der SSH-Umgebung. Das Installationsmanifest bleibt privat neben dem
Launcher erhalten; der genaue lokale Nachweis ist
`.local/catalog-runtime-install.json`. Keine Zugangsdaten gehören in diese
Dateien. Eine Deinstallation darf erst nach Änderung dieser expliziten
Laufzeitreferenz erfolgen.

**Behobene Integrationsfehler:** Leere JSON-Objekte in der Prüfdatei bleiben
Objekte und werden beim PHP-/Python-Übergang nicht zu Listen. Der PDF-Unterprozess
benutzt auch auf Netcup den privaten Launcher, statt das dort allein nicht
startfähige nackte Python-Programm aufzurufen. Beide Fälle haben eigene
Regressionstests.

**Nachweise:** Der abschließende Serverlauf
`audit/ebilanz-mysql-host-20260918-af2890140d8b59f4.json` besteht mit sechs
Programmen: 71 Import-/Rollen-/MySQL-/Ansichtsprüfungen, neun echte
PHP→Python→Original-PDF-Prüfungen, 40 Jahreskatalog-, 41 Tabellenbeschriftungs-,
3.066 Kontenzuordnungs- und 228 Freigabe-Fingerabdruckprüfungen. Der PDF-Test
verwendet die permanente Serverinstallation und bestätigt insbesondere Konto
06300, lange Beschreibungen in der rechten Spalte, Originalbytes und Quellen-
hinweise. Die Originaltabelle wurde zusätzlich visuell gegengeprüft. Der echte
DATEV-Quellabruf auf dem Server meldet am 01.10.2026 für 2027 korrekt „noch nicht
veröffentlicht“, angebotene Ausgabe 2026. Produktionsdatenbank und Versand
wurden nicht verwendet; die geschützten Produktionsdateien blieben während der
Tests unverändert. Nur synthetische Daten in der bestehenden Testdatenbank.

Lokal bestehen zusätzlich 13 Webimport-, 21 Jahresvergleich-, 41
Tabellenbeschriftungs-, zwölf Quellen- und elf Tabellen-Offlineprüfungen sowie
sieben DOM-Prüfungen. Die Inventur umfasst nun 159 PHP-Einstiegspunkte und 42
Offlineprogramme; das ist keine neue Gesamtabnahme aller Programme. Die neue
isolierte Importansicht wird separat auf schmalem und breitem Bildschirm
geprüft; daraus folgt weder ein echter Nutzertest noch die Gesamt-UI-Abnahme.

Die Abdeckungszahlen und die noch offenen fachlichen Bilanzfälle aus Revision
17 sind durch diesen Import nicht erledigt. Eine vollständige Bilanzfreigabe
und die anschließende Prüfung aller Menüpunkte stehen weiterhin aus.

## Wiederverwendbare Anlagenarten – 01.10.2026, Revision 17

**Entwicklungsstand, kein vollständiger Bilanzrelease. Kulturhus bleibt EÜR.**
Diese Zahlen ersetzen die Abdeckungszahlen der Revision 16. Frühere Nachweise
und Jahresausgaben bleiben unverändert erhalten.

Weitere 18 originale SKR42-Konten je Jahr sind geprüft zugeordnet: technische
Anlagen, Maschinen und maschinengebundene Werkzeuge, Betriebsvorrichtungen,
Pkw, Lkw, Anhänger und weitere Transportmittel sowie Betriebs- und
Geschäftsausstattung. Neun neue Kontenzwecke und ein bereits vorhandener Zweck
decken diese Ergänzung ab; insgesamt bestehen jetzt 262 versionierte Zwecke.

| Jahr | Buchbare Konten | Mit Regel | Davon bedingt | Ohne XML-Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 1.072 | 226 | 617 |
| 2026 | 1.679 | 1.079 | 226 | 600 |

Die neue zentrale Anlagenarten-Tabelle bindet die Berechnung an den geprüften
amtlichen Kontenzweck. Die Kontonummern werden aus den Jahreszuordnungen
abgeleitet. Eine unveränderte, bereits geprüfte Bedeutung muss daher im nächsten
Jahr nicht erneut in zahlreichen einzelnen Nummernlisten programmiert werden.
Ein Bedeutungswechsel derselben Nummer wird nicht stillschweigend übernommen.
Quellprüfung: `resources/accounting/reviews/assets-r17.json`; Original-PDF 2026
Seiten 1/2 bildlich gegengeprüft, Jahresquellen und Taxonomiebeschriftungen gebunden.

Die 20 unterstützten beweglichen Anlagenkonten verwenden nun dieselbe
Fähigkeitsprüfung für Jahres- und Zeitraumrechnung, gemeinsame Nutzung,
Abgänge, getrennte steuerliche Nutzungsdauer und die angebotenen linearen
Sphärenwechsel. Software und GWG bleiben eigene Anlagenarten. Nutzungsdauer,
steuerliches Wahlrecht und bewegliche Eigenschaft werden nicht aus der
Kontobezeichnung erfunden. Gebäude, Finanzanlagen und Sammelposten können
auch durch manuelles Umbenennen des XML-Ziels nicht als Ausstattung ausgegeben
werden. Die Eingabeseite verwendet dieselben Fähigkeiten für vollständige
Abgänge und Teilabgänge; Rollen- und EÜR-Grenzen bleiben erhalten.

**Nachweise für diesen Umfang:** 520 neue native ERiC-Prüffälle bestehen mit
Code 0: 200 Jahres-, 120 datierte und 200 Gesamtvereinsfälle. Sie verwenden
tatsächlich berechnete Anschaffungskosten, Abschreibungen, Restwerte,
Verkäufe, Teilabgänge und getrennte Steuerwerte. Erzwungenes Prüfflag 2,
kein Zertifikat und kein Transport. Die endgültige Wiederholung bestätigt
unveränderte Quellen und byte-identische XML-Ausgaben gegenüber dem ersten
520er-Lauf: `audit/ebilanz-assets-r17-native-final.json`.

Alle 41 Offlineprogramme sind ohne verbleibenden Programmfehler abgeschlossen.
Während dieses Laufs wurde ausschließlich die zusätzliche Ablehnung einer
manuell falsch zugeordneten Anlagenart ergänzt. Diese genaue Quelldifferenz
ist nachgewiesen; Ablehnungs- und Schnittstellentests wurden danach wiederholt,
ebenso alle 520 nativen XML-Fälle. Es wird deshalb nicht behauptet, alle
41 Programme seien nochmals vollständig auf unverändertem Endstand gelaufen.
Zusammenführung: `audit/assets-r17-final-acceptance.json`.

Die sieben gezielten MySQL-Serverprogramme bestehen im Lauf
`audit/ebilanz-mysql-host-20260918-48c2dfc68f2d6eda.json`. Zwei neue Programme
führen Rechnung, Original-Anlagenplan, Journal, getrennten Steuerplan,
XML-Prüfung, Vorstandsgenehmigung und Folgejahr mit Pkw bzw. Maschine durch.
Weitere Prüfungen betreffen Zeiträume, Sphärenwechsel, Kontenzuordnung und
Freigabe-Fingerabdruck. Geschützte Produktionsdateien blieben unverändert,
temporäre Serverdateien wurden entfernt; ausschließlich synthetische Daten
in der dauerhaft verfügbaren zweiten MySQL-Datenbank. Ein Transport wird in
diesen Abläufen nur simuliert. Der alte Docker-Prüfweg ist auf diesem PC
weiterhin nicht ausführbar und wird nicht als bestandene Prüfung gezählt.

Die ergänzende Prüfung der tatsächlichen Anlagen-Eingabeseite besteht mit
125 Prüfungen für alle 20 beweglichen Konten, Vorstand/Schreibrecht/Leserecht,
Software, GWG, Fremdverein und EÜR. Der anfängliche Test-GWG hatte noch keinen
Inventarbestand; die Anwendung hat ihn korrekt abgewiesen. Die Testdaten
enthalten jetzt den erforderlichen einzelnen Gegenstand, unveränderte
Produktionsvalidierung. Wiederholung:
`audit/ebilanz-mysql-host-20260918-650429ae6b639718.json`.
Im vorherigen Lauf `audit/ebilanz-mysql-host-20260918-89711316717dd1dd.json`
bestanden bereits die 58 Teilabgangs-Buchungsprüfungen und erneut der vollständige
Pkw-Steuerplanablauf. Zusammen sind neun verschiedene Serverprogramme ohne
verbleibenden Fehler nachgewiesen. Keine Browser-/Mobil- oder echte Nutzertest-
Abnahme wird daraus abgeleitet. Die Testinventur umfasst 158 PHP-Einstiegspunkte
und 41 Offlineprogramme; 21 geänderte/neue PHP-Dateien bestehen die Syntaxprüfung.
Das Installationsmanifest enthält alle elf neuen Quelldateien; 2.607
Dateiprüfsummen und die statischen Quellabhängigkeiten sind geprüft.

**Die allgemeine Freigabe bleibt aus folgenden Gründen offen:** weitere
Kontenzuordnungen, Gebäude/Grundstücke/Finanzanlagen/Sammelposten und andere
Anlagenarten; zusätzliche Kombinationen aus Sphärenwechsel, Abgang und
abweichender steuerlicher Bewertung; Teilmonats-Zeiträume; vollständige
fachliche Sonderfall- und Übermittlungs-/Gesamtabnahme. Die Kontenzahlen
bezeichnen den gesamten buchbaren SKR42, nicht den Bedarf eines einzelnen
Vereins. Kein produktiver Bilanzrelease, keine echte Steuerabgabe und keine
vorgezogene Gesamt-UI-Prüfung.

## Quellengebundene Kontenzuordnung – 01.10.2026, Revision 16

**Entwicklungsstand, kein vollständiger Bilanzrelease. Kulturhus bleibt EÜR.**
Die folgenden Zahlen ersetzen die älteren Abdeckungszahlen für den aktuellen
Arbeitsstand. Die historischen Abschnitte bleiben als damalige Nachweise erhalten.

102 weitere originale SKR42-Konten je Jahr sind mit 45 wiederverwendbaren
Kontenzwecken verbunden. Dazu gehören Mitgliedsbeiträge, Aufnahmegebühren,
Spenden, Mieterlöse, Sachbezüge, Bestandsänderungen, weitere Betriebskosten und
Ertragsteuern. 83 Zuordnungen sind fest; sechs werden aus dem gebuchten Saldo
automatisch aufgelöst. Nur 13 benötigen eine zusätzliche Sachangabe, etwa zur
Anrechenbarkeit einer Steuer oder zum tatsächlichen Empfänger einer Zahlung.
Die Anwendung erfindet dabei keine steuerliche Abziehbarkeit.

| Jahr | Buchbare Konten | Mit Regel | Davon an zusätzliche Nachweise oder Bedingungen gebunden | Ohne XML-Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 1.054 | 226 | 635 |
| 2026 | 1.679 | 1.061 | 226 | 618 |

Die 226 bedingten Regeln sind **nicht** 226 manuelle Entscheidungen: Dazu
gehören auch automatisch geprüfte Salden, Anlagenabstimmungen und der
Umsatzsteuerabschluss. Die Katalogzahlen umfassen den gesamten buchbaren
SKR42, nicht nur die bei einem einzelnen Verein tatsächlich verwendeten Konten.
Die Zuordnungstabelle enthält jetzt 253 versionierte Kontenzwecke. Original-PDFs,
Kataloge und frühere Jahresbindungen bleiben unverändert. Revision 16 wird
additiv an die bisherigen Jahresrevisionen angehängt; beim Jahresvergleich
können diese neuen Bedeutungen über denselben quellengebundenen Mechanismus
übernommen werden. Keine jährliche Neuerfassung unveränderter Konten.

Bestandsminderungen und Steuererstattungen behalten im Journal ihren richtigen
Saldo. Für die amtlichen XML-Felder wird das Vorzeichen entsprechend deren
Rechenbeziehung umgesetzt. Bei gemischten Bestandsänderungskonten wählt die
Automatik Zu- oder Abnahme aus dem Saldo. Eine manuelle gegenteilige Zuordnung
wird abgewiesen. Das Speichern prüft die tatsächlich steuerlich korrigierten
Werte, nicht die ursprünglichen Buchwerte oder einen Kontenplan ohne Beträge.
Die Gesamtvereinsdarstellung verwendet weiterhin ihre eigenen unveränderten
Buchwerte, getrennt vom steuerlichen Teil.

Die vollständige Quellenprüfung wird innerhalb eines Aufrufs nicht mehr für
jede Tabellenzeile wiederholt. Der kleine Zwischenspeicher bindet die Prüfung
an die exakten Quelleninhalte. Salden und Benutzerentscheidungen werden nicht
zwischengespeichert. Gegenprüfungen für geänderte Regeln und Katalogbytes
verhindern die Wiederverwendung eines alten Prüfergebnisses.

Die 636 neuen Varianten (2025/2026, Einzelbetrieb/Gesamtverein, positiver und
negativer Saldo/Gegenbuchung sowie alle angebotenen Zusatzentscheidungen)
bestehen die native ERiC-Plausibilitätsprüfung mit Code 0. Erzwungenes
Prüfflag 2, kein Zertifikat und kein Transport. Die geprüften Quelldateien
blieben während des Laufs identisch. Nachweis:
`audit/ebilanz-operating-r16-native-final.json` (vorheriger vollständiger Lauf:
`audit/ebilanz-operating-r16-native.json`). Alle 636 XML-Ausgaben der
Wiederholung sind byte-identisch zum vorherigen Lauf. 4.242 gezielte PHP-Prüfungen der
Zuordnung und Darstellung sowie drei gezielte Cache-/Jahresregelprüfungen
bestehen ebenfalls. Der vollständige aktuelle Testbestand umfasst 155
PHP-Einstiegspunkte und 40 Offline-Pythonprogramme; seine Auflistung ist
kein fachlicher Abnahmestempel.

Die elf gezielten Serverprogramme bestehen: zehn im Lauf
`audit/ebilanz-mysql-host-20260918-a4670ae7049f6ae9.json`, der neue gemischte
Korrekturfall in der Wiederholung
`audit/ebilanz-mysql-host-20260918-1cd55db71fb6d67a.json` mit 44 neuen und
61 wiederverwendeten Basisprüfungen. Zwei anfängliche Fehler des neuen
Testfalls betrafen fehlende Pflichtangaben der synthetischen Korrektur und
die noch laufende Jahresgrenze 2026. Der Test enthält jetzt vollständige
Angaben und eine nur für diesen Testprozess fixierte Uhr; Produktionssperren
werden nicht gelockert. Produktionsdateien blieben unverändert, temporäre
Serverdateien wurden entfernt.

Der breite Lauf aller 40 Offlineprogramme und die gezielten Wiederholungen
sind abgeschlossen. Fünf Programme fanden überholte Testzuordnungen: Das
allgemeine Kostenkonto 63000 wurde manuell als Verwaltungskosten übersteuert;
eine Verkaufsfixture verwendete irrtümlich das Mitgliedsbeitragskonto 40000
anstelle 41840. Die Quellfälle sind korrigiert, die Ablehnung widersprüchlicher
Zuordnungen bleibt bestehen und wird zusätzlich geprüft. Alle zwölf
Zeitraumprogramme bestehen in der Wiederholung. Ein sechster Befund war ein
ungenutztes Python-Exportziel, das nicht zur PHP-Auswahl gehörte. Es ist entfernt;
die acht Prüfungen der Schnittstelle einschließlich der vollständigen
Zielgleichheit bestehen erneut. Nach dieser reinen Einschränkung wurde nicht
noch einmal der gesamte 40-Programme-Lauf gestartet; die native Wiederholung
prüft dagegen alle 636 neuen XML-Fälle erneut und bestätigt unveränderte Ausgabe.

Der zusammengeführte Nachweis umfasst alle 40 Programme ohne verbleibenden
Fehler und hält diesen genauen Prüfungsumfang fest:
`audit/operating-r16-final-acceptance.json`. Einzelberichte:
`audit/operating-r16-regressions-20261001.json` und
`audit/operating-r16-period-recheck-20261001.json`. Zusätzlich bestehen 63
datierte Gesamtbetriebs-, 20 Anlagen-Grenz- und 41 steuerliche Wertprüfungen
lokal sowie 222 Laufzeitfingerabdruckprüfungen. Sieben geänderte/neue PHP-Dateien
bestehen die Syntaxprüfung. Das Installationsmanifest enthält die sechs neuen
Quell-/Testdateien; 2.596 Datei-Prüfsummen und statische Abhängigkeiten sind geprüft.

**Weiterhin offen:** Konten ohne XML-Regel, die unten aufgeführten weiteren
Anlagen-/Steuersonderfälle und die durchgängige Übermittlungs-/Gesamtabnahme.
Technisch erfolgreiche Teilprüfungen ersetzen diese nicht. Keine Veröffentlichung,
keine Änderung an EÜR-Einstellungen, Produktionsdateien oder echten Buchungen,
kein echter Mail-/Bank-/Steuerversand und keine vorgezogene Gesamt-UI-Abnahme.

## Vollständige PDF-Spalten und wiederverwendbare Jahreszuordnung – 01.10.2026

Der Importfehler ist behoben: Neben Nummer, Kontenfunktion und Beschreibung
werden jetzt auch die **gerahmten Bilanz-/GuV-Posten** und die zugehörigen
HB-/SB-Kennzeichen ausgewertet. Mehrzeilige Beschriftungen werden über ihre
tatsächlichen PDF-Rahmen den Konten zugeordnet, nicht über das nächstgelegene
Wort. Leere Zwischenräume werden nicht mit dem vorherigen Posten aufgefüllt.
Die unterschiedlich skalierten Originale 2025 und 2026 sind vollständig eingelesen.

Die bisherigen Jahreskataloge bleiben byte-identisch. Quellengebundene Ergänzungen
unter `resources/accounting/statements/` liefern die bisher ausgelassenen
Spalten nach; neue Importe enthalten sie direkt. Die bildliche PDF-Kontrolle
umfasst 2025 Seite 1 sowie 2026 Seiten 1, 6, 9, 14 und 20. Die PDF-Prüfung hat
insbesondere die bisher übergangenen Rahmen und getrennt gezeichneten HB-/SB-
Buchstaben aufgedeckt.

| Jahr | Buchbare Konten | Gedruckter Abschlussposten gelesen | Automatischer Buchhaltungsbereich einschließlich bestehender Regeln |
| --- | ---: | ---: | ---: |
| 2025 | 1.689 | 1.688 | 1.487 |
| 2026 | 1.679 | 1.677 | 1.479 |

Bei 39500 fehlt der Posten tatsächlich im Original, bei 27400 zusätzlich in
2026. Das sind keine überlesenen Beschriftungen. Mehrdeutige Quellkonten bleiben
unverändert gesperrt. Beispielsweise wird 48330 jetzt aus dem ausdrücklich
gedruckten Posten **Umsatzerlöse** als Erlöskonto erkannt, nicht aufgrund von
„Nebenerlöse“ pauschal als sonstiger betrieblicher Ertrag eingeordnet.

Eine zentrale Postentabelle ersetzt weitere einzelne Kontonummernlisten für
den Buchhaltungsbereich. Der Server übernimmt diesen Bereich beim Anlegen des
Kontos. Präzisere bisher geprüfte Regeln haben Vorrang. Die automatische
Bereichszuordnung 2026 steigt damit von 959 auf 1.479 Konten; 200 benötigen
weiterhin eine genaue Einordnung. Das ist **nicht** die E-Bilanz-XML-Abdeckung.

Beim Jahreswechsel können auch neue Nummern und erweiterte Bereiche einen
bereits geprüften Kontenzweck übernehmen, wenn Beschreibung, Kontenfunktion,
übergeordneter Begriff, Abschlussposten und Abschlusskennzeichen übereinstimmen.
Widersprüchliche Vorgänger werden nicht übernommen. Unbedeutende Typografie
und die geprüfte Schreibvariante „nahe stehend“/„nahestehend“ lösen keine
Steuerentscheidung aus. Originaltexte, Steuersätze, Negationen und Fußnoten
bleiben erhalten. Änderungen am Abschlussposten werden auch bei unverändertem
Kontonamen erkannt und in der zentralen Prüfansicht gezeigt.

Der vollständige Vergleich 2025 → neu eingelesenes 2026 ergibt 1.609 unveränderte,
62 geänderte und acht neue buchbare Konten sowie 18 entfallene/gesperrte Konten.
930 bereits geprüfte Zuordnungen können übernommen werden; Änderungen werden
in 61 Gruppen vorgelegt. Die vorhandenen 679 Grundzuordnungslücken dieses
Vorjahresvergleichs sind getrennt ausgewiesen. Das ist ein Prüfpaket, keine
Aktivierung einer neuen Jahresausgabe. Anleitung:
[SKR42-Import und Jahrespflege](SKR42-IMPORT-KONTEN-20260927.md).

**Abgrenzung:** Das Auslesen der gedruckten Posten und die automatische
Buchhaltungsgruppe sind umgesetzt. Die amtlichen XML-Zielregeln bleiben auf
Revision 15; dort bestehen weiterhin die unten dokumentierten 720/737 Lücken.
Zum Beispiel darf ein automatisch als Anlage erkanntes Gebäude nicht ohne
fertige Anlagen-/Taxonomieanbindung als abgabefertig gelten. Keine Änderung
von EÜR-Einstellungen, Buchungen, Live-Daten oder Produktionsdateien. Kein
ELSTER-Transport und keine vorgezogene Gesamt-UI-Abnahme.

Die gezielten Serverprüfungen bestehen: 41 Postenprüfungen, elf Kontenanlage-
prüfungen, 3.066 Katalogprüfungen, 506 zusätzliche Katalog-Abgabeprüfungen,
1.406 Betriebsaufwandsprüfungen, 210 Laufzeitfingerabdruckprüfungen und 88
Mappingprüfungen. Der erste Lauf fand eine veraltete Testannahme „Gebäude hat
keinen Buchhaltungsbereich“; diese prüft jetzt den PDF-abgeleiteten Bereich
**und** die weiterhin fehlende XML-Freigabe. Die gezielte Serverwiederholung
besteht ohne PHP-Diagnostik. Nachweise:
`audit/ebilanz-mysql-host-20260918-8b3ece72989bc9d8.json` und
`audit/ebilanz-mysql-host-20260918-1f5530c5ef2af8e0.json`.
Nur die dauerhaft freigegebene synthetische MySQL-Datenbank wurde verwendet;
Produktionsdateien blieben unverändert, temporäre Serverdateien sind entfernt.

Alle 39 Offline-Pythonprogramme bestehen; während dieses Laufs bleiben die
geprüften Quelldateien identisch. Enthalten sind 41 PDF-/Jahresfortschreibungsfälle,
die vorhandenen 21 Jahrespflegefälle und die vollständigen bestehenden
Bilanz-/Anlagen-/XML-Regressionen. Nachweis:
`audit/datev-layout-regressions-20261001.json`. Anschließend wurde ausschließlich
die Bindung von Steuerfreigaben an den Programmstand um die neue PHP-Klasse,
Postenregeln und zwei Jahresergänzungen erweitert. Deren Änderung oder Fehlen
macht eine alte Freigabe ungültig; 222 gezielte lokale Fingerabdruckprüfungen
bestehen. Sieben geänderte/neue PHP-Dateien bestehen die Syntaxprüfung.
Das Installationsmanifest enthält sämtliche neuen Quelldateien; die statische
Abhängigkeits- und Prüfsummenkontrolle umfasst 2.590 Dateien. Kein neuer
vollständiger Server- oder Übermittlungsabnahmestempel aus diesen Teilprüfungen.

## Konten- und Sonderfallabnahme – 30.09.–01.10.2026, Revision 15

**Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.** Dieser Abschnitt
dokumentiert die XML-Abdeckung; die obige PDF-Erweiterung ändert sie nicht.
Gemäß dem aktuellen Auftrag beginnt die erneute Prüfung des gesamten
Vereinsmanagers erst bei nachgewiesener vollständiger Releasefähigkeit.
Diese Voraussetzung fehlt weiterhin; die Gesamtprüfung wurde nicht vorgezogen.

72 weitere exakte SKR42-Quellkonten je Jahr sind angebunden: davon 65 mit fester
Zuordnung und sieben mit einer erforderlichen Sachentscheidung. Ergänzt sind
insbesondere Zuschüsse, Versicherungsentschädigungen, Wertberichtigungs- und
Rückstellungsauflösungen, Währungsdifferenzen, weitere Betriebskosten und Zinsen.
Für Reisekosten wird nach Beschäftigten oder Ehrenamtlichen gefragt. Allgemeine
Bewirtungsbeträge benötigen eine bereits geklärte Aufteilung; die Darstellung
erfindet keinen abziehbaren Prozentsatz. Zinsen werden bei uneindeutiger Quelle
nach ihrer Herkunft unterschieden. Kontonamen dürfen Schreibfehler enthalten,
die Zuordnung hängt vom geprüften Originalkonto und Jahr ab.

| Jahr | Buchbare Konten | Feste Automatik | Zusatzprüfung nötig | Ohne feste Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 745 | 207 | 737 |
| 2026 | 1.679 | 752 | 207 | 720 |

208 versionierte Kontenzwecke und die Revisionskette Ausgangsstand → 14 → 15
bleiben reproduzierbar. Die bisherigen Jahresbindungen und Original-PDFs werden
nicht überschrieben. Die Original-PDF-Seiten 14, 15, 21, 24 und 25 von 2026 sind
bildlich geprüft; die verwendeten Kontobedeutungen sind zusätzlich mit 2025
verglichen. Beispiel einer bewusst nicht geratenen Zuordnung: 48330 steht im
Original unter Umsatzerlösen, nicht unter sonstigen betrieblichen Erträgen.
Es bleibt außerhalb dieser Erweiterung. Keine Freigabe ganzer Kontenbereiche
aufgrund einer ähnlich klingenden Bezeichnung.

Die genaue Darstellung steuerlich nicht abziehbarer Aufwendungen ersetzt
**nicht** deren außerbilanzielle Hinzurechnung. Originalbeträge und Journale
bleiben unverändert; die steuerliche Überleitung ist weiterhin ein eigener
geprüfter Nachweis. Gleiches gilt für Fördermittel und steuerfreie Erträge.

Die 492 Varianten der neuen Zuordnung (2025/2026, Einzelbetrieb/Gesamtverein,
Buchung/Gegenbuchung/Nullsaldo sowie alle angebotenen Zusatzentscheidungen)
bestehen die lokale native ERiC-Prüfung mit Code 0. Erzwungenes Prüfflag 2, kein
Zertifikat, kein Transport. Die geprüften Quelldateien blieben während des Laufs
identisch. Nachweis: `audit/ebilanz-operating-r15-native.json`.

Der vollständige technische Prüfbestand ist in `tests/balance-suite.json`
ausdrücklich aufgeführt: 152 PHP-Einstiegspunkte und 38 Offline-Pythonprogramme.
`tests/balance-suite-test.py` erkennt neu hinzugefügte oder entfernte Tests,
die sonst beim Wiederverwenden älterer Prüfläufe übersehen würden. Die Liste
ist kein Abnahmestempel. Der abschließende Abgleich bestätigt für alle 152
unterschiedlichen PHP-Einstiegspunkte einen bestandenen Prüflauf ohne
PHP-Diagnostik. Das ist die vorhandene technische Regression, nicht der
Nachweis vollständiger fachlicher Konten- und Sonderfallabdeckung.

Alle 38 Offline-Programme bestehen auf dem korrigierten Laufzeitstand erneut;
die Quelldateien sind während des Laufs unverändert geblieben. Nachweis:
`audit/ebilanz-r15-regressions.json`. Die Syntaxprüfung der 1.085 PHP-Dateien
in Anwendung, Ansichten, öffentlichem Einstieg und Tests besteht ebenfalls.

Der Servernachweis setzt sich aus drei ausdrücklich abgeglichenen Läufen
zusammen, nicht aus einer einzigen unveränderten Release-Abnahme:

- Im ersten Lauf bestanden 72 von 131 Programmen. Nach dem Timeout der
  separaten Test-Eigentümerverbindung verweigerte der Schutz 57 Programme
  vor Datenbankänderungen; zwei weitere meldeten veraltete Testerwartungen.
  Der Testleiter prüft und erhält die bestehende Eigentümerverbindung nun
  auch während langer Einzeltests. Er verbindet sich bei Verlust nicht
  stillschweigend neu und übernimmt keine fremde Testsitzung.
- Der ergänzende Lauf umfasste 86 Programme einschließlich der bisher
  ausgelassenen Einstiegspunkte und gezielter Wiederholungen. 81 bestanden;
  eine weitere veraltete Entwurfserwartung und vier unvollständige
  Ausgangsrechnungs-Fixtures wurden korrigiert.
- Alle sieben gezielten Nachprüfungen bestanden danach. Bei den Verkäufen
  werden echte synthetische PDF/XML-Originale samt KoSIT-Prüfung erzeugt;
  kein Mitgliederversand und keine echte Steuerübermittlung. Auch die
  unveränderten Originaldateien nach steuerlicher Neubewertung sind geprüft.

Nachweise: `audit/ebilanz-mysql-host-20260918-5e82d1b9ef689771.json`,
`audit/ebilanz-mysql-host-20260918-5eeb2b0eab3cf07c.json` und
`audit/ebilanz-mysql-host-20260918-e1d15e31eb424492.json`. Der zusammengeführte
Nachweis `audit/ebilanz-r15-evidence.json` bestätigt 152 PHP-Programme,
38 Offline-Programme und 492 native Plausibilitätsfälle; er setzt ausdrücklich
`release_ready=false` und `full_manager_ui_audit_started=false`.
Die erste Serverbasis liegt vor der unten beschriebenen Entfernung des
ungenutzten Python-Ziels. Alle Offline-/nativen Fälle und die betroffenen
PHP-Schnittstellen wurden auf dem korrigierten Laufzeitstand wiederholt.
Die späteren Korrekturen betreffen ausschließlich die Tests. Geschützte
Produktivdateien blieben in allen drei Läufen identisch, eine produktive
Datenbankverbindung wurde nicht verwendet. Die privaten Testverzeichnisse
sind entfernt; die dauerhaft freigegebene synthetische Datenbank bleibt bestehen.

Der erste Volltest fand eine ältere Abweichung zwischen PHP-Kontenauswahl und
Python-Generator: Ein zusätzliches Erlösschmälerungsziel für § 4 Nr. 12 UStG
war nur im Generator zugelassen, ohne geprüfte Quellenbindung. Diese verwaiste
Freigabe ist entfernt, nicht durch eine erfundene Kontoregel ergänzt. Der
Schnittstellentest prüft wieder die exakte Übereinstimmung beider Zielmengen.
Drei historische Verrechnungs-/Nebenrechnungstests erwarteten noch, dass selbst ein Entwurf
für den gesamten nicht steuerbegünstigten Verein gesperrt wird. Sie prüfen
nun den unveränderten Gesamtabschluss und die weiterhin fehlende Abgabefreigabe;
Teilabschlüsse und steuerbegünstigte Profile dürfen die Verrechnungsprüfung
unverändert nicht umgehen. Keine Abschwächung der Produktivsperren.

Die Verkaufstest-Fixtures enthalten jetzt die ausdrücklich bestätigte
Inlandsansässigkeit, Rechnungsstelle und vollständige Vereinskontaktdaten
für das bereits verpflichtende PDF/XML-Original. Die Anwendungsvorgaben
wurden nicht gelockert; die Tests prüfen zusätzlich das validierte Original
und die unveränderte XML-Prüfsumme.

### Konkrete verbleibende Freigabegrenzen

- Die obigen 720/737 Konten ohne Regel und 207 bedingten Zuordnungen sind
  kein vollständig automatisierter Kontenplan. Nicht unterstützte Konten
  bleiben gesperrt statt auf einen Sammelposten umgebucht zu werden.
- Der durchgängige Anlagenspiegel unterstützt derzeit Betriebsausstattung,
  Büroausstattung, erworbene Software und sofort abgeschriebene GWG. Das ist
  keine vollständige Unterstützung von Gebäuden, Finanzanlagen, Sammelposten,
  Firmenwerten und sämtlichen weiteren Anlagenarten.
- Echte Bereichswechsel unterstützen derzeit lineare Pläne auf 01350/06300/
  06500. Der kombinierte Fall Bereichswechsel plus Abgang sowie degressive und
  GWG-Wechsel werden ausdrücklich abgewiesen. Separate Steuerpläne dürfen
  nicht stillschweigend mit einem Bereichswechsel vermischt werden.
- Datierte Abschlüsse, Folgezeiträume und Korrekturen haben technische
  Integrationen und Tests; `BalancePeriodLedger::enabled()` lässt ihre
  Buchungsfunktion weiterhin nur in der synthetischen Umgebung zu. Abschreibungen
  für angebrochene Abschlussmonate benötigen noch eine gesonderte Aufteilung;
  `BalancePeriod::fullMonths()` weist sie derzeit ausdrücklich ab.
- Vollständige fachliche Konten-/Anlagen-/Steuerfallabdeckung sowie die
  durchgängige Übermittlungs- und Gesamtabnahme sind nicht nachgewiesen.
  Eine lokale ERiC-Prüfung und simulierte Rückmeldungen ersetzen diese nicht.

Keine Produktionsänderung, echte E-Mail, Bankbeauftragung oder Steuerabgabe
durch diesen Prüflauf. Schreibende Datenbanktests verwenden ausschließlich
synthetische Daten auf der dauerhaft freigegebenen zweiten MySQL-Datenbank.

## Rechnungsgebundene Einkaufszuordnung – Entwicklung nach Revision 14

Die Bilanzübernahme leitet allgemeine Einkaufspositionen jetzt aus den bereits
gespeicherten Originalangaben ab. 51000 (Material), 52000 (Waren) und 59000
(Fremdleistungen) benötigen keine erneute Angabe zur Verwendung. Nur bei
50000 bleibt die Sachfrage: Material zum Verbrauchen/Verarbeiten oder Ware
zum unveränderten Weiterverkauf? Große Auswahlflächen enthalten zusätzlich
„Noch unklar“; damit bleibt die Übernahme gesperrt statt zu raten.

Geklärter Vorsteuerabzug, Originalsteuersatz und ursprünglicher Buchungsschlüssel
werden übernommen. Für 2025/2026 sind die neun genauen Zielkonten 51100, 51300,
51290, 53000, 54000, 53490, 59080, 59060 und 59090 gegen den jeweiligen Katalog
und die bestehende E-Bilanz-Regel geprüft. Auch die gleichbedeutenden aktuellen
BU-Schlüssel 401/402 werden berücksichtigt. Kein Vorsteuerabzug bedeutet
Bruttokosten; abzugsfähige Steuer wird weiterhin genau einmal separat gebucht.
Andere Konten, unbekannte Nummern, Sondersteuersätze, widersprüchliche Steuer-
angaben und ungeklärte Abzugsberechtigung werden nicht stillschweigend umgedeutet.

Die Ableitung betrifft ausschließlich die Bilanzbuchung. Originalpositionen,
Belege und bestehende Journale bleiben unverändert. Bei neuen passenden
Rechnungen ist sie vorbelegt; alte Quellen ohne diese Option behalten ihre
bisherigen Daten und Prüfsummen. Eine nachträgliche Anwendung geht über die
vorhandene ausdrückliche Korrektur mit Gegenbuchung und Historie. Die Antworten
überstehen Vorschau, Fehleranzeige, Speicherung und erneutes Öffnen. Geänderte
Antworten können keine ältere Buchungsbestätigung weiterverwenden.

Kalenderabschluss und datierter Abschluss verwenden denselben Ableitungsweg.
Beim jahresübergreifenden Zeitraum werden Originaljahr und Abschlussjahr
getrennt geprüft; private Vorleistungen bleiben Verbindlichkeiten gegenüber
dem tatsächlichen Mitglied. Die neue Ableitung ist an die technische
Übermittlungsfreigabe gebunden: Änderungen invalidieren bisherige Prüfnachweise.

Prüfungen: `balance-invoice-classification.php`,
`balance-invoice-purchase-sources.php`, `balance-period-purchase-sources.php`
sowie bestehende Korrektur-, Nicht-Rechnungs-, Vorsteuer- und Laufzeitprüfungen.
Nur synthetische Daten in der dauerhaft freigegebenen zweiten MySQL-Datenbank;
keine Live-Verbindung, Mails, Bankaufträge oder Steuerübermittlungen.

Abnahme dieses Entwicklungsschritts: alle acht PHP-Prüfläufe auf dem Webserver
mit MySQL 8.4 ohne Warnungen bestanden, darunter 122 reine Zuordnungsfälle und
die vollständigen Kalender-/Zeitraum-Übernahme- und Korrekturwege. Nachweis:
`audit/ebilanz-mysql-host-20260918-c89351d39368a33b.json` (historischer
Dateinamenspräfix des Prüfläufers). Geschützte Produktivdateien blieben identisch;
die produktive Datenbank wurde nicht verbunden. Die bestehenden vier erweiterten
XML-Testgruppen bestehen erneut. Hinzu kommen 62 Kalender- und 16 Zeitraum-
Oberflächenprüfungen gegen veraltete Bestätigungen.
Die synthetisch gerenderte Sachabfrage ist am Desktop und bei tatsächlichen
390 CSS-Pixeln kontrolliert: 16-Pixel-Auswahltext, mindestens 48 Pixel hohe
Auswahlflächen, umbrochene Texte ohne horizontales Scrollen, gesperrte Bestätigung
nach Änderung. Das ist kein echter Nutzertest und keine Gesamtprüfung aller Menüs.

**Grenze:** Dies automatisiert diese allgemeinen Einkaufsfälle, nicht sämtliche
offenen Konten oder steuerlichen Sonderfälle. Die Katalogabdeckung aus Revision
14 bleibt unverändert. Weitere Sachabfragen, vollständige Grundzuordnung und
die abschließende allgemeine Gesamtabnahme bleiben offen. Kein allgemeiner
Bilanzrelease; Kulturhus bleibt EÜR und die Produktivseite unverändert.

## Automatische Kontenzuordnung: Revision 14, Entwicklung

Die laufende Zuordnung verwendet geprüfte Kontonummer, Originaljahr und
Kontenbedeutung statt des frei eingegebenen Kontonamens. Absichtlich falsch
geschriebene Namen sind mitgeprüft. Für feste Regeln ist keine zusätzliche
Steuerkontenauswahl nötig. Bei der zentralen Jahrespflege werden harmlose
PDF-Typografieunterschiede vereinheitlicht; Ziffern, Steuersätze, Verneinungen
und Bedeutungswechsel werden nicht unscharf „korrigiert“.

Ergänzt sind genaue Quellfamilien für Material-/Wareneinkauf, Fremdleistungen,
Bestandsveränderungen, Umsätze und Erlösschmälerungen. Letztere benötigen im
XML das zur amtlichen Summenformel passende Vorzeichen; die Originalbuchungen
und Steuerbeträge werden dafür nicht verändert. Im Quellenvergleich und in
der Taxonomie zugelassene Sonderkategorien sind keine Zusage, dass sämtliche
zugehörigen Steuerfälle oder Geschäftsvorfälle vollständig automatisiert sind.

| Jahr | Buchbare Konten | Feste Automatik | Zusatzprüfung nötig | Ohne feste Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 680 | 200 | 809 |
| 2026 | 1.679 | 687 | 200 | 792 |

Gegenüber Revision 13 sind 358 beziehungsweise 365 Konten ergänzt; davon
186 beziehungsweise 193 mit fester Automatik, jeweils 172 mit Zusatzangaben.
Die 522 bisherigen Regeln pro Jahr bleiben semantisch identisch. Es bestehen
172 versionierte Kontenzwecke. `years/active.json` bindet ausschließlich
ergänzende Revisionen an die unveränderten Ausgangsstände und Originalquellen.
Ein neu abgelegtes Paket aktiviert sich nicht selbst. Kontenplan,
Jahreswechsel, Taxonomie und fachliche Freigabe sind weiterhin getrennt.

Original-PDF 2026, Seiten 11, 13, 16 und 18, textlich und bildlich geprüft.
Der absteigende Bereich „4737 0 bis 4733 9“ auf Seite 13 ist tatsächlich in
der Quelle enthalten. Die Überschneidung mit reservierten Nummern wird nicht
durch geratenes Umnummerieren behoben; 47340 bleibt gesperrt. Quellenfehler
gehören zur zentralen Pflege, nicht zum Mitarbeiter im Verein.

Bereits bestanden: 21 Jahrespflegeprüfungen, vier neue XML-Testgruppen mit
624 Kombinationen aus Jahr, Berichtsprofil, Quellgruppe und Buchung/Storno/
Nullsaldo sowie Einzelprüfungen der erlaubten Zusatzentscheidungen;
8.122 neue PHP-Katalog-/Zuordnungs-/Renderingprüfungen. Bestehende PHP-Prüfungen
mit 1.310 Betriebs-, 3.066 Bilanzkatalog-, 208 Laufzeitbindungs- und 40
Jahreskatalogfällen bestehen ebenfalls. Die neue Vorzeichen-/Zieldefinition
ist in den Prüfstand der Übermittlung eingebunden: Änderungen entziehen die
bisherige technische Freigabe.

Alle 624 Varianten bestehen zusätzlich die native ERiC-Validierung mit Code 0,
erzwungenem Prüfflag 2 und ohne Zertifikat oder Versand. Die bedingten Fälle
verwenden dabei jeweils eine zulässige Beispielentscheidung; alle erlaubten
Zusatzentscheidungen werden gesondert im XML-Generator geprüft. Das ist kein
Nachweis einer vollständigen fachlichen Sonderfallabdeckung. 14 vorhandene
Python-Testprogramme (einschließlich Bestandskonten, Umsatzsteuer, Verkäufen,
Gesamtverein, Nebenrechnung, Zeiträumen, Sphären und PDF-Import) bestehen mit
dem erweiterten Regelstand. Lokale Nachweise ohne Vereinsdaten:
`audit/ebilanz-operating-r14-native.json` und
`audit/ebilanz-operating-r14-regressions.json`.
Der Installations-Quellbestand ist neu erzeugt; 2.569 Dateihashes und statische
Abhängigkeiten sind geprüft. Private Laufzeiten, Zertifikate und Auditdateien
sind nicht in diesen Quellbestand aufgenommen. Keine Installations-/Webserver-
Gesamtabnahme dieses Schritts und kein echter Nutzertest.

**Zum damaligen Stand offen:** vollständige Grundzuordnung, automatisch aus
Originalbelegen abgeleitete Behandlung unbestimmter Sammelkonten, verständliche
Sachabfragen und allgemeine Gesamtabnahme. Der oben dokumentierte nächste
Entwicklungsschritt deckt davon allgemeine Einkaufspositionen ab.
Es gibt noch keinen vollständigen „Mitarbeiter muss nichts steuerlich zuordnen“-
Ablauf für alle Sonderfälle. Keine Produktionsänderung, keine Abgabe und
kein allgemeiner Bilanzrelease. Kulturhus bleibt ausdrücklich EÜR.

## Jahrespflege SKR42: Umsetzung 01.10.2026

Die zentrale Jahrespflege ist jetzt von der einzelnen Kontonummer getrennt:
122 versionierte Kontenzwecke, unveränderliche Jahresbindungen 2025/2026,
Quellvergleich, lokale Prüfansicht und reproduzierbare Erzeugung eines
Zuordnungspakets. Sämtliche 522 bisher geprüften Regeln je Jahr werden daraus
semantisch unverändert erzeugt; die aktive Registry und Jahreskataloge wurden
nicht ersetzt. Änderungen an einer Zweckdefinition benötigen eine neue Version.

Der echte Vergleich 2025→2026 ergibt 1.611 unveränderte, 60 geänderte, acht neue
und 18 entfallene/gesperrte buchbare Katalogeinträge. 519 bestehende Zuordnungen
sind übernehmbar. Die Änderungen werden in 59 Prüfgruppen zusammengefasst;
1.092 unveränderte, bisher unzugeordnete Konten erscheinen separat. Diese
Aufteilung behebt nicht die bereits bekannten Grundzuordnungslücken.

Der Ablauf lädt DATEV-Quellen getrennt nach EÜR/Bilanz, erkennt den echten
Dateilink hinter der Downloadseite, importiert ausschließlich Kandidaten und
überschreibt weder aktive Daten noch frühere Prüfdateien. Die Prüfung enthält
auch Funktionen, Quellbereiche, übergeordnete Begriffe und verfügbare Fußnoten.
Nachfolger entfallener Konten sind Hinweise, keine automatischen Umbuchungen.
Geänderte Quellen im bereits verwendeten Jahr benötigen eine gesonderte
Revisionsprüfung. Ein unverändertes Original meldet `up_to_date`, ohne erneut
PDF-Import oder neue Dateien auszulösen.

Nachweise: 18 Jahrespflege- und zwölf Quell-/Importtests, elf vorhandene
Geometrieprüfungen, 12 isolierte Oberflächen-, 40 Jahreskatalog-, 3.066
Kontenzuordnungs- und 206 Laufzeitbindungsprüfungen bestanden.
Die vier XML-Katalogtests mit 120 synthetischen Kontenvarianten bestehen ebenfalls.
Offizielles 2026-PDF erneut geladen/importiert: Konten, Bereiche und Quellzeilen stimmen
vollständig mit dem unveränderten bisherigen Katalog überein. Seiten 1, 31
und 32 bildlich kontrolliert. Zentrale Prüfansicht im Browser bei Desktop-
und 390-px-Handybreite geprüft, Pflichtfelder und echte heruntergeladene
Prüfdatei mit 59 synthetischen Entscheidungen kontrolliert. Kein Nutzertest.

Betriebsablauf und Grenzen: [SKR42-Import und Jahrespflege](SKR42-IMPORT-KONTEN-20260927.md).
**Keine Produktivveröffentlichung, keine automatische fachliche Freigabe und
kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.** Die vollständige
Grundzuordnung und die übrigen Gesamtabnahme-Voraussetzungen bleiben offen.

## Kontenzuordnung: geprüfter Stand 30.09.2026, Revision 13

232 zusätzliche exakte SKR42-Konten sind für 2025 und 2026 an die E-Bilanz
angebunden: 143 weitere Konten aus den originalen Vorratsbereichen sowie 89
Forderungs-, Rückstellungs- und Verbindlichkeitskonten. Jahreskatalog und
Original-PDF-Prüfsummen bleiben unverändert. Keine pauschale Zuordnung nach
Kontenklasse oder freiem Kontonamen. Die drei Forderungskonten 13370/13380/13390
bleiben offen: Die zunächst untersuchte Taxonomieposition für stille Gesellschafter
ist für das verwendete Körperschaftsprofil nicht zulässig.

Eine Bilanz mit ausschließlich Bestandsbewegungen und tatsächlichem Nullergebnis
wird nun korrekt mit Jahresergebnis null ausgegeben. Die vorgelagerten Konten-,
Quell- und Summenprüfungen bleiben bestehen; auch ein ausgeglichenes Konto mit
unterjährigen Bewegungen muss zugeordnet sein.

Der vollständige Katalogabgleich ergibt **noch keine vollständige Kontenzuordnung**:

| Jahr | Buchbare Katalogkonten | Feste automatische Regel | Regel mit Zusatzprüfung | Ohne feste Regel |
| --- | ---: | ---: | ---: | ---: |
| 2025 | 1.689 | 494 | 28 | 1.167 |
| 2026 | 1.679 | 494 | 28 | 1.157 |

`vereinsverwaltung/tools/balance-mapping-audit.php` erstellt ausschließlich lesend
die genaue Jahresliste aller noch offenen Konten und der bedingten Regeln.
Solange Konten fehlen, liefert das Werkzeug Exitcode 2. Auch vollständige
Regelabdeckung allein wäre keine Produktivfreigabe der übrigen Bilanzfunktionen.
Konten mit Zusatzprüfung und die unterstützten Anlagenarten sind nicht mit
vollständiger Sonderfallabdeckung gleichzusetzen. Allgemeine Vorratssummen dürfen
weiterhin nicht gleichzeitig mit ihren Unterpositionen belegt werden; solche
gemischten Zuordnungen werden gesperrt statt doppelt gezählt.

Nachweise dieses Schritts:

- 3.066 neue PHP-Prüfungen für Katalog, Vorschau, Jahresbindung und Kontenart.
- Alle 232 zusätzlichen Konten in beiden Jahren mit Bestand und mit vollständig
  ausgeglichenen Bewegungen durch den echten XML-Generator geprüft: 120 Varianten.
- Dieselben 120 synthetischen Varianten bestehen native ERiC-Validierung, Code 0,
  ausschließlich Prüfflag 2. Kein Testversand, keine echte Abgabe.
- Zehn Prüfgruppen auf dem Webserver und der permanent synthetischen Datenbank
  bestanden. Darunter 506 neue Prüfungen der gespeicherten Konten-/Abgabekette
  sowie 61 wiederverwendete Prüfungen mit strikt lokalem Transport-Ersatz.
  Getrennte Rollen, Freigabeentzug nach Änderung und unveränderte Originaljournale
  sind geprüft. Die zukünftige Jahresabschlusszeit existiert nur im Testprozess.
- Zusätzliche Negativtests verhindern, dass das Nullergebnis fehlende Konten oder
  widersprüchliche Summen verdeckt. Die maschinenlesbare Abdeckung ist mitgeprüft.
- Alle 33 vorhandenen und neuen E-Bilanz-Python-Testprogramme bestanden; die zwei
  zuletzt ergänzten Negativ-/Abdeckungsprüfungen sind zusätzlich separat bestanden.
  Alle vier neuen PHP-Dateien bestehen die Syntaxprüfung.

Lokale Nachweise (nicht Teil eines Backups mit Vereinsdaten):
`audit/ebilanz-balance-catalog-native-20260930.json` und
`audit/ebilanz-mysql-host-20260918-f7654a9392031adb.json` sowie
`audit/ebilanz-catalog-regressions-20260930.json`.
Geprüftes Server-Testpaket: SHA-256
`15972c89d099970385c0bdc3db0f78ea0205952ed141a4f7ba74268fea411932`.
Geschützte Produktivdateien unverändert; keine Verbindung zur produktiven
Datenbank. Eigene private Testdateien auf dem Server nach Abschluss entfernt.

**Nicht veröffentlicht. Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**
Vollständige Konten-/Anlagen-/Sonderfallabdeckung sowie die durchgängige
Übermittlungs- und Gesamtabnahme sind weiterhin Release-Voraussetzungen.

Fortsetzung 30.09.2026: [Datierte gemeinsame Gesamt-/Teilbilanz und ursprünglicher Kalenderabschluss](ZEITRAUM-GESAMTBILANZ-KALENDER-20260928.md).
Unveränderte Gesamt-B/GuV, sämtliche steuerpflichtigen Geschäftsbetriebe,
ursprüngliche Kalender-Vorabschlüsse, datierte Folgezeiträume und abhängige
Vorjahreskorrekturen sind verbunden. Unpassende Vorabschlüsse verändern keinen
Entwurf. Der Speicherfehler im vollständigen Anlagen-Korrekturablauf ist behoben;
historische Freigaben und Originaljournale bleiben unverändert.
Die ergänzende Serverabnahme der speichersparenden Abschlusslisten und elf
weiterer Prüfgruppen ist bestanden; 27 native ERiC-Validierungen erfolgreich.
Das ist ausdrücklich noch kein tatsächlicher Testversand.
Weitere Sphären-/Anlagen-/Steuersonderfälle, vollständige Kontenzuordnung,
tatsächlicher Testversand/Rückmeldungen und Gesamtabnahme bleiben erforderlich.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Datierte gemeinsame Teilbilanz aller steuerpflichtigen Betriebe](ZEITRAUM-BETRIEBSAUFTEILUNG-20260928.md).
Originale Bereichsaufteilung, beide Buchungsseiten, Anlagen, interne Nutzung,
gesonderte Steuerwerte, genaue Folgezeiträume und gespeicherte Korrekturen sind
verbunden. Stornos behalten ihren ursprünglichen Buchungsbezug. Der Kalender-
Vorabschluss dieses Profils und die kombinierte datierte Ausgabe mit Gesamt-B/GuV
sowie vollständige Sonderfall-/Konten- und Übermittlungsabnahme bleiben offen.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Gespeicherter Zeitraumabschluss eines gesonderten Geschäftsbetriebs](ZEITRAUM-BETRIEBSABSCHLUSS-20260928.md).
Datierter Einzelbetrieb, exakter Kalender-/Zeitraumvortrag, Anlagen-/Steuerpläne,
Korrekturen, Rollen, historische Freigaben und Oberfläche sind verbunden. Ein leerer
Folgezeitraum kann keinen gemischten Vorabschluss in einen Einzelbetrieb umdeuten.
Die vollständige allgemeine Konten-/Sonderfall- und Übermittlungsabnahme bleibt offen.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Anlagenbezogene Steuerkorrekturen nach Sphärenwechsel](ZEITRAUM-ANLAGEN-STEUERKORREKTUREN-20260928.md).
Tatsächliche steuerliche AfA ist für Hinzurechnungen pro Gegenstand auswählbar,
einschließlich anderer Anlagen auf demselben Konto. Die bisherige pauschale
Kontensperre bleibt nur für doppelte Korrekturen über ursprüngliche Buchungszeilen.
Originalwerte und historische Prüfstände werden nicht überschrieben.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Steuerüberleitung und XML der datierten Anlagenwechsel](ZEITRAUM-SPHAEREN-STEUERXML-20260928.md).
Belegte lineare Wechsel erreichen jetzt die steuerliche Nebenrechnung und deren
amtliche XML-Ausgabe, einschließlich unabhängigem Nachrechnen, Erst-/Folgeabschluss
und getrennter Anlagenprüfung. Original-Gesamtbilanz und Brutto-Anlagenspiegel
bleiben unverändert. 24 native ERiC-Validierungen bestanden, ohne Versand.
Andere Methoden/Anlagensonderfälle, übrige Betriebsprofile, vollständige
Kontenabdeckung, abschließender Testversand und Gesamtabnahme bleiben offen.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Bereichsbilanzen nach Anlagen-Sphärenwechseln](ZEITRAUM-SPHAEREN-BEREICHSBILANZEN-20260928.md).
Belegte Wechsel sind an Bereichsvermögen, interne Kapitalgegenbuchungen,
gemeinsame Nutzung innerhalb der jeweiligen Sphäre, Freigabe, genaue Vorträge und
Arbeitsunterlagen angebunden. Das ist die Buchwertseite; amtliche Steuerwert- und
Kapitalüberleitung, XML-Rekonstruktion und die übrige Gesamtabnahme bleiben offen.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Datierte Anlagen-Sphärenwechsel](ZEITRAUM-SPHAERENWECHSEL-20260928.md).
Tatsächliche Wechsel ganzer linearer Anlagen sind an Belegnachweis, Originalplan,
getrennte Buch-/Steuerrechnung, Berichtigung und Folgezeitraum angebunden.
Amtliche Kapital-/Anlagenüberleitung dieser Wechsel, zusätzliche Methoden und
Sonderfälle sowie durchgängige Übermittlungs-/Gesamtabnahme fehlen weiterhin.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Gespeicherter datierter Abgabeablauf](ZEITRAUM-ABGABEABLAUF-20260928.md).
Gesamtbilanz mit steuerlicher Nebenrechnung ist an tatsächliche Zeiträume,
unveränderliche Freigaben, Korrekturabgaben, historische Protokolle und die
Oberfläche angebunden. Datierte Anlagenzuordnung und Anzeige wurden korrigiert.
Auch der nachgewiesene Kalender-Vorabschluss ist an diesen datierten Exportweg
angebunden; Buchwerte, steuerliche Anfangsunterschiede und Anlagen bleiben getrennt.
Auch die datierte Bilanz des nicht steuerbegünstigten Gesamtvereins ist mit
Speicherung, Kapitalprüfung, Freigabe, Korrektur und datiertem Vortrag verbunden.
Auch dessen bestätigter Kalender-Vorabschluss mit Anlagen und getrennten
Steuerplänen kann jetzt ohne neue Anschaffung oder doppelte Erfolgsrechnung
übernommen werden. Die vollständige Körperschaft darf geprüfte steuerliche
Anlagenwerte in allen ursprünglichen Bereichen fortführen; das ist kein
steuerlicher Sphärenwechsel und erweitert keine steuerbegünstigte Teilbilanz.
Ersetzte Abgaben dürfen keine neuen steuerlichen Anfangswerte liefern.
Die dortige Tabelle ersetzt die älteren offenen Listen als aktueller Codeabgleich.
**Noch kein allgemeiner Bilanzrelease; nicht veröffentlicht. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Datierte Gesamtbilanz und steuerliche Nebenrechnung](ZEITRAUM-GESAMTVEREIN-XBRL-20260928.md).
Vollständige Original-Buchwerte, Kapitalvergleich des nicht steuerbegünstigten
Vereins und gesonderte Nebenrechnung des steuerbegünstigten Vereins werden mit
tatsächlichen Zeiträumen, Anlagen und Vorträgen unabhängig nachgerechnet.
24 datierte und 22 bisherige Kalenderfälle bestanden die native ERiC-Prüfung,
ohne Versand. Gespeicherter datierter Abgabeablauf, übrige Profil-/Vortragsbrücken,
echte Sphärenwechsel, vollständige Sonderfallabdeckung und Gesamtabnahme bleiben
Release-Voraussetzungen. **Nicht veröffentlicht; Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Datierte Anlagenwerte im E-Bilanz-Generator](ZEITRAUM-ANLAGEN-XBRL-20260928.md).
Originalpläne, AfA, Abgänge/Verkäufe, steuerliche Nutzungsdauer und der Brutto-
Anlagenspiegel werden unabhängig für tatsächliche Zeiträume nachgerechnet.
585 Rechenstände und 14 native ERiC-Prüffälle bestanden, ohne Übermittlung.
Datierte Gesamtvereinsprofile, echte Sphärenwechsel, vollständige Sonderfallabdeckung
und die vollständige Abgabe-/Rückmeldeabnahme bleiben offen.
**Nicht veröffentlicht; Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Tatsächliche Abschlusszeiträume im E-Bilanz-Generator](ZEITRAUM-EBILANZ-GENERATOR-20260928.md).
Originaldaten, Steuerbewertung, Anfangswerte und tatsächlicher Vorzeitraum werden
unabhängig rekonstruiert; vier datierte Fälle sind nativ validiert, ohne Versand.
Die neue datierte Ausgabe ist bisher auf separate Geschäftsbetriebe ohne Anlagen
begrenzt. Datierte Anlagen-/Gesamtvereinsprofile, gespeicherte Abgabe mit Rückmeldungen
und die weiteren allgemeinen Release-Voraussetzungen bleiben offen.
**Nicht veröffentlicht; Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Datierte Änderungen der Anlagennutzung](ZEITRAUM-NUTZUNGSWECHSEL-20260928.md).
Monatliche Nutzungswechsel innerhalb derselben steuerlichen Sphäre behalten
Originalwerte, weisen interne Anlagenumbuchungen separat aus und führen die
letzten Anteile in den Folgezeitraum fort. Eindeutig gebundene Abschreibungsanteile
werden automatisch übernommen. Neue Bestätigung, Historie und Arbeitsunterlagen
sind angebunden. Steuerliche Sphärenwechsel, taggenaue Änderungen und die übrigen
allgemeinen Release-Voraussetzungen bleiben gesondert offen. **Nicht veröffentlicht;
Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Anlagennutzung und Bereichsvortrag](ZEITRAUM-ANLAGENNUTZUNG-20260928.md)
und [steuerliche Überleitung für tatsächliche Abschlusszeiträume](ZEITRAUM-STEUERWERTE-20260928.md).
Originalanlagen, gemeinsame Nutzung innerhalb derselben Sphäre, Abgänge/Verkäufe,
Kalenderübergang, abweichende Steuerwerte, kurze Folgezeiträume und getrennte
Freigaben sind an die tatsächlichen Zeiträume angebunden. Migration 108 ergänzt
unveränderliche Steuerprüfungen; alte Buchungen und Originalfreigaben bleiben bestehen.
Die geprüften Nachweise und die Grenzen stehen in den beiden Detaildokumenten.
Echte steuerliche Sphärenwechsel, vollständige Konten-/Sonderfallabdeckung,
amtliche datierte Ausgabe mit Rückmeldungen sowie Gesamtabnahme und Veröffentlichung
sind weiterhin Release-Voraussetzungen. **Kein allgemeiner Bilanzrelease;
Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Bereichsaufteilung, interne Zahlungen und Ergebnisabgleich in tatsächlichen Zeiträumen](ZEITRAUM-BEREICHE-20260928.md).
Mehrere Betriebe, Vorschüsse, Teilrückzahlungen, Kapitalvortrag und korrigierte Abschlüsse
sind mit getrennten Zeitraumprüfungen, Vorstand, Oberfläche und Arbeitsunterlagen verbunden.
Gemeinsame Anlagen/Sphärenwechsel, datierte steuerliche Überleitung, vollständige
Konten-/Sonderfallabdeckung, amtliche Ausgabe und Gesamtabnahme bleiben offen.
**Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Mitgliedsbeiträge und Geldspenden in tatsächlichen Zeiträumen](ZEITRAUM-BEITRAEGE-SPENDEN-20260928.md).
Echte Einzel-/Familienforderungen, getrennte Beitrags-/Katalogjahre, freie Geldspenden,
gemischte Zahlungen, Teilzahlungen und Folgezeitraum sind im Zeitraumjournal angebunden.
Keine doppelte Ertragsbuchung; geänderte Spenden entwerten betroffene Teilnachweise.
Weitere Sonderfälle, Bereichs-/Anlagen-/Steuerintegration und allgemeiner Release
bleiben offen. **Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Anlagenverkäufe, alte offene Rechnungen und Eingabe](ZEITRAUM-ANLAGENVERKAEUFE-20260928.md).
Verkäufe sind mit dem tatsächlichen Zeitraum, unveränderten ausgestellten Originalen,
Gewinn-/Verlustkonten, Zahlungen, Journal und Folgezeiträumen verbunden. Offene
Kalenderrechnungen einschließlich privater Erstattungen und historische Anlagenverkäufe
werden nachweisgebunden übernommen, ohne alten Aufwand oder Erlös erneut zu buchen.
Die Maske trennt normale Belegprüfung von erweiterten Soll-/Haben-Buchungen.
Elf gemeinsame Testgruppen, 974 PHP-Syntaxprüfungen und 16 JavaScript-Prüfungen
bestanden; synthetische Desktop-/Handybreiten mit Suchauswahl und Vorschauwechsel geprüft.
Weitere Anlagen-/Steuersonderfälle, Sphärenwechsel, vollständige Bereichs-/Steuerintegration,
amtlicher Zeitraumexport und Gesamtabnahme bleiben offen. **Kein allgemeiner Bilanzrelease;
Kulturhus bleibt EÜR.** Die untenstehenden älteren Zwischenstände bleiben als Verlauf erhalten.

Fortsetzung 28.09.2026: [Anlagenabgänge und historische Anlagenvorträge](ZEITRAUM-ANLAGENABGAENGE-20260928.md).
Voll-/Teilabgänge ohne Erlös und endgültiges Software-Lizenzende sind mit tatsächlichen
Wirtschaftsjahren, Journal, Folgezeiträumen, Belegprüfung und Arbeitsunterlagen verbunden.
Festgeschriebene Kalender-Anlagenstände werden nachweisgebunden übernommen;
historische Originale bleiben unverändert. Acht Regressionsgruppen bestanden.
Periodisierte Verkäufe, weitere Anlagen-Sonderfälle, Sphärenwechsel,
Bereichs-/Steuerüberleitung, vollständige Kontenabdeckung, amtlicher Zeitraumexport
und Gesamtabnahme bleiben offen. **Kein vollständiger Bilanzrelease. Kulturhus bleibt EÜR.**

Fortsetzung 28.09.2026: [Degressive Abschreibung in tatsächlichen Wirtschaftsjahren](ZEITRAUM-DEGRESSIV-20260928.md).
52 neue Rechenprüfungen und 103 Zeitraumprüfungen sowie 33 MySQL-Anlagenprüfungen
und 43 Quellprüfungen bestanden. Der Übergang korrigierter Kalenderabschlüsse
in datierte Folgezeiträume ist ebenfalls geprüft. Periodisierte Anlagenabgänge,
Sphärenwechsel, Bereichs-/Steuerüberleitung, vollständiger amtlicher Zeitraumexport
und Gesamtabnahme bleiben offen. Kein allgemeiner Bilanzrelease, Kulturhus EÜR.

Die getrennte Veröffentlichung der [Dokumentlöschung und hybriden Ausgangsrechnungen](DOKUMENTE-RECHNUNGEN-20260928.md)
ist ausdrücklich kein Bilanzrelease und löst die nachgelagerte Gesamtprüfung nicht aus.

Fortsetzung 28.09.2026: [Korrekturabschlüsse für tatsächliche Zeiträume](ZEITRAUM-KORREKTUREN-20260928.md).
Unveränderliche Korrekturen, separate Vorstandsbestätigung und berichtigte
Anfangsbestände offener/geschlossener Folgezeiträume; 51 neue und 75 bestehende
Prüfungen erfolgreich. Noch kein vollständiger periodisierter Steuerexport,
keine allgemeine Konten-/Anlagensonderfallabdeckung oder Bilanzveröffentlichung.
Kulturhus bleibt EÜR.

Fortsetzung 27.09.2026: [SKR42 – Material, Waren und verbindliche Kontenbedeutung](SKR42-MATERIAL-WAREN-20260927.md).
Weitere 64 eindeutige Konten und drei Sammelkonten mit begrenzten Detailoptionen.
Unpassende manuelle Umdeutungen bekannter Konten sind gesperrt; eindeutige Ziele
benötigen keine Auswahlliste mehr. Kein allgemeiner Bilanzrelease, Kulturhus EÜR.

Fortsetzung 27.09.2026: [SKR42 – Personal- und Betriebsaufwand](SKR42-BETRIEBSAUFWAND-20260927.md).
65 zusätzliche eindeutige Kontenzuordnungen und fünf begrenzt auswählbare
Sammelkonten; 171 native Validierungen ohne Versand und acht MySQL-Testgruppen
bestanden. Vollständige Konten-/Sonderfallabdeckung und allgemeiner Release bleiben
offen. Kulturhus unverändert EÜR.

Fortsetzung 27.09.2026: [Zeitraumgebundene Abschreibungen und Anlagenabgleich](ZEITRAUM-ABSCHREIBUNG-20260927.md).
Migration 104 bindet lineare Pläne und bestätigte GWG an tatsächliche Zeiträume,
Folgeperioden, Journal, Oberfläche und Anlagen-Arbeitsunterlagen. 28 neue Prüfungen;
weiterhin keine allgemeine Konten-/Anlagensonderfallabdeckung, vollständige
periodisierte Korrektur-/Steuerintegration oder Veröffentlichung. Kulturhus bleibt EÜR.

Fortsetzung 27.09.2026: [Rechnungs- und Zahlungsübernahme in Abschlusszeiträumen](ZEITRAUM-BELEGUEBERNAHME-20260927.md).
Migration 103, nachvollziehbare Quellenberichtigung, private Erstattung und
periodenübergreifender Zahlungsausgleich; 43 neue Prüfungen und fünf bestehende
Gruppen erfolgreich. Abschreibungsintegration und weitere Release-Voraussetzungen
bleiben offen. Keine Veröffentlichung; Kulturhus bleibt EÜR.

Fortsetzung 27.09.2026: [Abschlusszeiträume in Speicherung, Oberfläche und Arbeitsunterlagen](ABSCHLUSSZEITRAEUME-20260927.md).
Migration 102 ergänzt unveränderliche Zeitraumjournale, getrennte Abschlüsse mit
demselben Endjahr, Vorträge und ZIP-Arbeitsunterlagen. 75 neue Prüfungen und fünf
bestehende Testgruppen erfolgreich. Noch kein amtlicher periodisierter Steuerexport,
keine vollständige Konten-/Anlagensonderfallabdeckung oder Anlagen-Sphärenwechsel.
Keine Veröffentlichung; Kulturhus bleibt EÜR.

Fortsetzung 27.09.2026: [SKR42-Quellimport und Kontenzuordnungen](SKR42-IMPORT-KONTEN-20260927.md).
39 im Original 2025 vorhandene, bisher abgeschnittene Konteneinträge wiederhergestellt;
zusätzliche geprüfte Zuordnungen und 43 erfolgreiche native Validierungen, kein Versand.
Vollständige automatische Konten-/Sonderfallabdeckung ist weiterhin nicht erreicht.

Fortsetzung 27.09.2026: [Explizite Abschlusszeiträume](ABSCHLUSSZEITRAEUME-20260927.md).
Rechenkern für abweichende und kurze Zeiträume mit linearer/GWG-Abschreibung,
103 synthetische Prüfungen. Periodisierte Speicherung, Bereichs-/Anlagenintegration,
Oberfläche und Steuerexport noch offen. Kein allgemeiner Bilanzrelease.

Fortsetzung 27.09.2026: [Korrekturabschlüsse, Vorjahresabgleich und Prüfverlauf](KORREKTURABSCHLUESSE-20260927.md).
Originalabschlüsse bleiben unverändert, Korrekturen werden separat geprüft;
geschlossene und offene Folgejahre sowie bestehende Bereichs-/Steuernachweise
werden neu abgestimmt. 229 Assertions auf der isolierten MySQL-Datenbank bestanden.
Ergänzt sind Abgabearten und die Bindung korrigierter E-Bilanzen an Original und
Übermittlungsquittung; 25 native Validierungen bestanden, kein Versand. Der
abschließende Korrektur-Testversand, Anlagenkorrekturen und die übrigen allgemeinen
Release-Voraussetzungen bleiben offen; keine Veröffentlichung.

Fortsetzung 27.09.2026: [gemeinsam genutzte Anlagen mit Voll-/Teilabgang](GEMEINSAME-ANLAGEN-ABGAENGE-20260927.md).
Originalnachweise, interne Anteile, amtliche Ausgabe und gespeicherte Folgejahre
isoliert geprüft; keine allgemeine Freigabe oder Veröffentlichung.

Fortsetzung 27.09.2026: [amtliche Ausgabe und Freigabeablauf für die steuerliche Nebenrechnung](EBILANZ-NEBENRECHNUNG-20260927.md).
Gesamtbilanz/GuV mit SGEB-Ergebnis und gebundener Erläuterung, unabhängig
berechnet und in sieben Fällen nativ geprüft. Kein allgemeiner Bilanzrelease.

Fortsetzung 27.09.2026: [steuerliche Nebenrechnung, Prüfverlauf und Oberfläche](STEUERLICHE-NEBENRECHNUNG-20260927.md).
Die freigegebene Testdatenbank wird seitdem dauerhaft synthetisch genutzt, ohne
wiederholte Sicherung/Wiederherstellung je Entwicklungslauf. Kein allgemeiner Bilanzrelease.

Verbindlicher Zielumfang vom 20.09.2026: **allgemeine Vereinsbilanz vollständig**, keine
Ein-Betrieb-Erstfreigabe. Neue Mehrbetriebsaufteilung, Prüfungen und noch offene
Release-Voraussetzungen: [Vereinsbilanz mit mehreren Betrieben](VEREINSBILANZ-MEHRERE-BETRIEBE-20260920.md).

## Verbindliche Nachprüfung nach der Veröffentlichung

Auftrag vom 20.09.2026: Erst die Bilanzierung fertigstellen und nach bestandener
Abnahme veröffentlichen. Anschließend den **gesamten Vereinsmanager mit allen
Menüpunkten** auf Gestaltung und Bedienbarkeit für einen 65-jährigen Menschen
ohne Buchhaltungs- und Computer-Vorkenntnisse prüfen. Ein erfolgreicher einzelner
ELSTER-Test oder ein Teil-Release erfüllt diese Startbedingung nicht.

**Kulturhus bleibt auf EÜR (Einnahmenüberschussrechnung).** Weder Veröffentlichung,
Migration noch die Nachprüfung dürfen den Verein automatisch auf Bilanzierung
umstellen. EÜR-Einstellung vor und nach dem Release nachweisen; Bilanzfunktionen
nur in einem getrennten synthetischen Bilanz-Testverein prüfen.

Die anschließende Prüfung umfasst:

- Sämtliche erreichbaren Menüpunkte, Unterseiten und Dialoge; verständliche
  Navigation ohne tote, doppelte oder für die jeweilige Rolle unpassende Wege.
- Einheitliche Gestaltung, gut lesbare Schrift und Kontraste, klare Schaltflächen,
  ausreichende Klickflächen, Tastaturbedienung sowie Desktop- und Handyansicht.
- Alltagssprache statt ungeklärter Buchhaltungsbegriffe; notwendige Hilfe am
  passenden Feld, keine internen Entwicklungs-/Testtexte oder redundanten Erklärungen.
- Vollständige typische Abläufe: Mitglieder, Beiträge, Rechnungen, Zahlungen,
  Zuordnungen, Inventar, Veranstaltungen, Gutscheine, Dokumente, Kommunikation,
  Websiteverwaltung, Einstellungen und Jahresabschluss.
- Klarer nächster Schritt und Rückmeldung; keine doppelten Bestätigungen,
  verlorenen Eingaben, unerklärlichen Seitensprünge oder unnötigen Pflichtfelder.
- Unterschiede zwischen Admin, Kassenwart, Vorstand und Mitglied; erlaubte
  Aktionen eindeutig zeigen und nicht erlaubte Zugriffe serverseitig verhindern.
- Produktiv nur lesend prüfen. Schreibende Abläufe einschließlich Rollenwechsel,
  Versand und Buchung ausschließlich mit synthetischen Daten in isolierter Umgebung;
  keine echten Mails, Bankaufträge oder Steuerabgaben durch den Test.

Ergebnis: priorisierte Befunde je Seite/Ablauf, konkret beobachtetes Problem,
vorgeschlagene Verbesserung und Kennzeichnung nicht überprüfbarer Schritte.
Eine technische/heuristische Prüfung ersetzt keinen beobachteten Test mit einem
tatsächlichen unerfahrenen Nutzer; solche Erkenntnisse nicht erfinden.

## Entwicklungsverlauf

Ergänzung 27.09.2026: [Beleggebundener Ergebnisabgleich](ERGEBNISABGLEICH-20260927.md).
Ergebnisse von Gesamtverein und allen steuerpflichtigen Betrieben aus den
Originalzeilen, ohne Gewinnwirkung interner Vorschüsse/Rückzahlungen, mit
Freigabe- und Vorjahresbindung sowie maschinenlesbarem und lesbarem Export.
Noch keine steuerliche Überleitung dieser Nachweise und keine allgemeine Freigabe.

Ergänzung 27.09.2026: [Laufende Verrechnungsnachweise speichern und prüfen](BEREICHS-VERRECHNUNG-20260920.md).
MySQL-Revisionsverlauf, Rollenprüfung, Arbeitsoberfläche und Jahresabschluss-
Unterlagen angebunden. Interner Bereichsabgleich und nichtleerer Vortrag über
drei Jahre einschließlich Teilrückzahlung und expliziter Neuabstimmung sind
inzwischen isoliert geprüft. Die steuerliche Darstellung nichtleerer Verrechnungen
und die vollständige Freigabe bleiben offen. Kulturhus bleibt EÜR; keine
vorgezogene Manager-Gesamtprüfung.

Ergänzung 20.09.2026: [Gemeinsam genutzte Anlagen](GEMEINSAME-ANLAGEN-20260920.md).
Interne Nutzungsaufteilung innerhalb derselben steuerlichen Sphäre, gebunden
an Originalanlage und Journal, mit Folgejahr, Freigabe und unabhängiger
Generatorprüfung. Auch bestätigte GWG und degressive Originalpläne werden
übernommen und pro Jahr erneut gegengeprüft. Keine Verdoppelung von Anlagen
oder Abschreibung, keine Aufteilung von GWG-Grenzen auf mehrere Teilgegenstände.
Bereichsübergreifende Nutzung, Nutzungswechsel und Abgänge bleiben offen;
keine vollständige Abnahme, Veröffentlichung oder vorgezogene Manager-Gesamtprüfung.

Ergänzung 20.09.2026: [Endgültige interne Mittelübertragungen](BEREICHS-MITTELUEBERTRAGUNG-20260920.md).
Belegbezogener Ausgleich zwischen Bereichen mit unverändertem Gesamtjournal,
separater Eliminierung, Historie und Vorstandsfreigabe. Zeitweilige Verrechnungen,
gemeinsame Anlagen und die übrigen offenen Gesamtanforderungen bleiben offen;
keine Veröffentlichung oder vorgezogene Manager-Gesamtprüfung.

Neuester Entwicklungsschritt 20.09.2026: [Gekaufte Softwarelizenz vollständig beenden](SOFTWARE-LIZENZEN-20260920.md).
Eigener Nachweistyp, Konten 62000/68960, getrennte Buch-/Steuerwerte, Folgejahre,
Oberfläche und unabhängiger Export ergänzt. 16 synthetische Fälle im Webserver
amtlich validiert, ohne Versand oder Zugriff auf Produktivdaten. Integrationsabnahme
siehe verlinkten Bericht. Keine allgemeine Produktivfreigabe; Kulturhus bleibt EÜR.

Vorheriger Entwicklungsschritt 20.09.2026: [Teilabgang buchen, Folgejahre und E-Bilanz](TEILABGANG-BUCHUNG-20260920.md).
Atomare Buchung, verbleibende Abschreibung, getrennte Steuerwerte und Oberfläche
integriert. Zehn neue Fälle lokal und im Webserver amtlich validiert; ein
synthetischer Testversand mit Transferticket erfolgreich. Alle 14 MySQL-Testgruppen
einschließlich Rücksicherung bestanden; anschließend vereinfachte Oberfläche und
ELSTER-Programmstandbindung nachgeprüft. Keine Produktivfreigabe.

Vorheriger Entwicklungsschritt 20.09.2026: [Gespeicherte Teilabgangs-Prüfstände und Folgejahresvorschau](TEILABGANG-SPEICHERUNG-20260920.md).
81 neue Prüfungen bestanden, einschließlich unveränderlichem Verlauf, Fehler-Rollback
und verschlüsselter Wiederherstellung. Schemaschritt 97 nur in der Entwicklung;
noch keine wirksame Abgangsbuchung oder öffentliche Oberfläche, nicht veröffentlicht.

Vorheriger Entwicklungsschritt 20.09.2026: [Belegprüfung für Teilabgänge](TEILABGANG-BELEGPRUEFUNG-20260920.md).
133 neue Prüfungen auf der separaten MySQL-Datenbank bestanden, ebenso die
bestehenden Verkaufs-/Abgangsregressionen. Keine Datenänderung durch die Vorschau;
keine Veröffentlichung, Buchungs- oder Exportfreigabe für Teilabgänge.

Vorheriger Entwicklungsschritt 20.09.2026: [Isolierter Rechenkern für Teilabgänge](TEILABGANG-RECHENKERN-20260920.md).
Centgenaue Berechnung mit getrennten Steuerwerten geprüft; noch keine Buchungs-,
Oberflächen- oder Exportintegration und keine Produktivfreigabe.

Weitere Fortsetzung 20.09.2026: [Beleggebundene Gegenkonten für gemischte Anlagenabgänge](ANLAGENABGANG-KONTENNACHWEIS-20260920.md).
Verkauf und Ausmusterung mit derselben SKR42-Nummer bleiben intern getrennt.

Fortsetzung vom 20.09.2026: [Anlagenverkauf mit getrennten Buch-/Steuerwerten](STEUERLICHER-ANLAGENVERKAUF-20260920.md)
einschließlich unabhängiger Generatorprüfung und negativer Tests. Weiterhin
Entwicklung, keine allgemeine Produktiv- oder E-Bilanz-Echtfreigabe.

Fortsetzung vom 16.09.2026: beleggebundene vollständige Ausmusterung sowie weitere
Erlös-/Umsatzsteuer-Zuordnungen implementiert und separat geprüft. Aktuelle Nachweise
und weiterhin bestehende Grenzen: [Anlagenabgang](ANLAGENABGANG-ABNAHME-20260916.md)
und [Erlöse/Umsatzsteuer](ERLOESE-UST-ABNAHME-20260916.md). Noch kein Live-Deployment
und keine allgemeine E-Bilanz-Echtfreigabe. Die folgenden Abschnitte dokumentieren
die jeweiligen früheren Entwicklungsschritte.

Produktionsschutz: Kulturhus bleibt EÜR. Entwicklung ausschließlich mit synthetischen
MySQL-Daten. Keine Steuerabgabe, keine Bankaufträge, keine Umstellung der Live-Datenbank.

## Umsetzung und Abnahmereihenfolge

1. Beleggebundene Journalübernahme: Rechnungen, tatsächlich zugeordnete Bankumsätze,
   private Vorleistungen und festgestellte Abschreibungen. Einmalige Quellenbindung,
   unveränderter Quellennachweis, Jahres- und Vereinsgrenzen, Prüfung vor Festschreibung.
2. Jahresbezogener SKR42 **Bilanz**-Katalog mit überprüfter fachlicher Kontenzuordnung.
   Die nachgeprüften vorhandenen Originale 2025/2026 sind bereits Artikel 12901
   (Bilanz), nicht Artikel 12902 (EÜR). Katalog-SHA256 und Original-PDF stimmen
   überein. Die zusätzlich nötige Zuordnung zu E-Bilanz-Taxonomiepositionen ist
   davon unabhängig und noch nicht vollständig implementiert.
3. Steuerliche Überleitung, Kontennachweise, Anlagenspiegel und Anlagenverzeichnis.
4. Amtliche XBRL-Erzeugung und ERiC-Prüfung, anschließend ausschließlich synthetische
   Übermittlung einschließlich Rückmeldungen. Erst danach gesonderte Produktivfreigabe.
5. Weitere Formularautomatik nur für durch Daten und geprüfte Regeln belegte Fälle;
   fehlende tatsächliche oder steuerliche Angaben werden nicht als „nein“ erfunden.

## Amtliche Versionsprüfung

Quelle: https://www.esteuer.de/ (abgerufen 15.09.2026).
Taxonomie 6.8 grundsätzlich für 2025, 6.9 grundsätzlich für 2026; 6.9 kann auch für
2025 verwendet werden. Taxonomie 6.10 wurde veröffentlicht, deren Testübermittlung
ist jedoch erst für November 2026 und Echtübermittlung für Mai 2027 angekündigt.
Der vorhandene ERiC 44 enthält Prüfbibliotheken bis 6.9. Eine bloß veröffentlichte
neue Taxonomie darf deshalb nicht automatisch als versandfähig aktiviert werden.

Eine berechnete Bilanz oder ein intern gebuchtes SKR42-Quellkonto ist keine
Bestätigung einer fachlich vollständigen oder versandfähigen E-Bilanz.

## Abnahme dieses Entwicklungsschrittes

- Implementiert: Quellenbindung, Steueraufteilung, Teilzahlungen, Vorleistung,
  Anschaffung/Abschreibung, Bankabstimmung, Ergebnis-Arbeitsrechnung und Nachweise.
- Gegenprüfungen: fremder Verein, fehlende Berechtigung, fehlendes Original,
  geänderte Vorschau/Datum, doppelte Übernahme, veränderte Journalzeilen,
  Quellenänderung, parallele Importe, Wiederherstellung nach MySQL-Backup.
- Native lokale ERiC-Prüfung: `Bilanz_6_9`, zwei amtliche gültige Beispiele RC 0,
  zwei amtliche Fehlerbeispiele RC 610001002. Eigene Hersteller-ID verwendet;
  ausschließlich `ERIC_VALIDIERE`, keine Zertifikate und kein Versand.
- Oberfläche mit synthetischen Belegen auf Desktop und 390px Mobilbreite geprüft.
- Migrationen 092/093 sind **nicht auf Produktion ausgeführt**, Bilanzschalter bleibt aus.
  Live bleibt Schema 91/EÜR. Dieser Stand wurde weder auf den Webserver noch auf
  die GitHub-Repositories veröffentlicht. Vor einer späteren Freigabe erneut
  verschlüsselt sichern und die additive Migration gemeinsam mit dem Code testen.

**Nicht abgeschlossen:** die Punkte unter „Noch zu implementieren und zu prüfen“
in [BILANZ-RECHENKERN.md](BILANZ-RECHENKERN.md), insbesondere eigene vollständige
XBRL-Erzeugung, E-Bilanz-Versand/Rückmeldungen, über die Standardfälle im offenen
Jahr hinausgehende Quellenkorrekturen und weitere automatische Steuerformulare.
Es wird keine Gesamtfertigstellung behauptet.

## Fortsetzung: Quellenkorrekturen (Schema 93, nur lokal)

Implementiert: Vorschau mit abhängigen Belegen, aufbewahrte Originalfassungen,
Storno/Neubuchung und verkettete Quellenhistorie. Abschreibungs-Jahresstand wird
zusätzlich mit dem gespeicherten Anschaffungsplan verglichen. Erläuterungen und
verbleibende Grenzen stehen in BILANZ-RECHENKERN.md.

Abnahme: 564 ausgeführte Prüfungen in zwölf Testgruppen, darunter 45 neue
Korrekturprüfungen. Getestet sind insbesondere Vorsteuer, Sphärenwechsel,
private Vorleistung mit Erstattung, Abschreibung, inzwischen umgezogene
Zahlungszuordnung, Stale-Preview, zwei parallele Bearbeiter, vollständiger
Rollback beim Fehler einer Folgebuchung, beschädigte Historie und MySQL-Restore.
Alle Tests nutzten ausschließlich kurzlebige synthetische MySQL-Datenbanken.

Zusätzlich fünf Renderprüfungen der neuen Oberfläche und Browserprüfung der
Pflichtfelder/aufklappbaren Kontierungsgegenüberstellung. Kein horizontaler
Überlauf bei gemessenen 390 CSS-Pixeln, auch mit aufgeklapptem Vergleich.
Desktop-Screenshot geprüft; mobile Screenshotaufnahme war im Browserwerkzeug
nicht verfügbar, mobile Kontrolle deshalb über DOM-Maße und Bedienung.
Das ist weiterhin keine vollständige HTTP-Ende-zu-Ende-Abnahme.

Nächste fachliche Implementierung: eigenes jahresbezogenes XBRL-Mapping und
Erzeugung aus dem geprüften Journal einschließlich Anlagenspiegel und
steuerlicher Überleitung. Die früheren nativen ERiC-Beispieltests belegen
**nicht**, dass diese eigene Erzeugung bereits fertig ist. Kein Live-Deployment,
keine Änderung von Bank-/ELSTER-Zugang und keine Übermittlung in dieser Fortsetzung.

## Fortsetzung: weitere Quellen und Anlagen-Nachweis

15.09.2026: Jahresbeiträge (einschließlich Familien), tatsächlich eingegangene
freie Geldspenden, deren zusammenhängende Korrekturen und das beleggebundene
Anlagenverzeichnis sind hinzugekommen. Die tatsächlichen Freigabegrenzen stehen
im Abschnitt „Beiträge, Geldspenden und Anlagenverzeichnis“ des Rechenkern-Dokuments.

Insbesondere verhindert die Schlussprüfung nun eine Festschreibung ohne die
fällige Jahresabschreibung, auch wenn der entsprechende Jahresdatensatz noch
nicht angelegt wurde. Anlagenkosten und kumulierte Abschreibungen werden getrennt
nachgewiesen, mit dem Bilanzsaldo verglichen und im Abschluss unveränderlich
aufbewahrt. Das Steuerpaket enthält den Nachweis als JSON/CSV und lesbare Tabelle.

Ergebnis der erneuten Abnahme: 708 Prüfungen in 15 Testgruppen bestanden, keine
PHP-Syntaxfehler in den 13 betroffenen PHP-Dateien. Ausschließlich synthetische
MySQL-Daten. Keine zusätzliche Migration erforderlich; Schema bleibt lokal 93.
Produktivstand und beide GitHub-Veröffentlichungen wurden nicht verändert.

Zu diesem damaligen Zwischenstand noch offen waren eigene XBRL-Erzeugung und fachliche Zuordnung,
vollständiger amtlicher Anlagenspiegel, Handels-/Steuerbilanz-Überleitung,
Sonderfälle und die zugehörige ERiC-/HTTP-/Versandabnahme. Die reine Validierung
amtlicher Musterdateien darf weiterhin nicht als eigene E-Bilanz-Abnahme gelten.

## Fortsetzung: eigene XBRL-Erzeugung und getrennte Steuerwerte

Jetzt besteht erstmals die **eigene**, aus synthetischen Journalwerten erzeugte
E-Bilanz die native ERiC-Prüfung: sechs positive Fälle einschließlich Verlust,
Nullergebnis, Centbeträgen und 2026; ein manipuliertes Summenfeld wird abgewiesen.
Einzelheiten, Testbefehle und die engen fachlichen Grenzen sind in
[EBILANZ-EIGENER-GENERATOR.md](EBILANZ-EIGENER-GENERATOR.md) dokumentiert.

Das ist noch keine vollständige E-Bilanz-Funktion für Endnutzer: Der abgegrenzte
Geschäftsbetriebs-Generator, die dokumentierte Steuerwertrechnung und lokale
Validierung sind implementiert. Vollständiges Mapping, alle Überleitungsfälle,
amtlicher Anlagenspiegel, Oberflächenintegration und Versandabnahme bleiben offen.
Keine Änderung an Live, Datenbestand, produktiven Schaltern oder Zertifikaten.

## Fortsetzung: Anlagen-XBRL und exakte Jahreskonten

Der Generator enthält jetzt den Brutto-Anlagenspiegel für beleggebundene,
linear abgeschriebene Ausstattung, Büroeinrichtung und erworbene Software.
19 exakte SKR42-Konten werden jahresbezogen zugeordnet; alle übrigen bleiben
explizit prüfpflichtig. Eine kompakte Vorschau ist lokal in den Bilanzbereich
integriert. Keine neue Migration, kein neuer Versandweg, kein Live-Deployment.

18 eigene synthetische Datensätze bestehen die native ERiC-Prüfung, einschließlich
Anlagenbewegungen und aller neuen Kontenzuordnungen. 51 Generator-Testmethoden,
25 Mappingprüfungen, 31 Steuerwertprüfungen und die 708 bestehenden Prüfungen
decken den Stand ab. Einzelheiten und Grenzen: EBILANZ-EIGENER-GENERATOR.md.
Vollständiges Mapping, weitere Anlagen-/Überleitungsfälle, vollständige
Oberflächen-Freigabekette und Versand samt Rückmeldungen sind weiterhin offen.

## Fortsetzung: begrenzter Testablauf und bestätigter Versand

`BalanceTaxFiling` verbindet das festgeschriebene Journal mit gespeicherter
Kontenzuordnung und Steuerwertprüfung, nativer Validierung und einer exakt daran
gebundenen Vorstandsfreigabe. Änderungen und entzogene Berechtigungen machen die
Freigabe ungültig. Es wird der bestehende Sender mit Vorab-Festschreibung,
Inhalts-Doppelversandsperre und unklarem/gesperrtem Rückmeldestatus verwendet.
Das Formular bietet Steuerwertänderungen für die Buchhaltung getrennt vom
unveränderten Handelsjournal; das normale Finanzformular kann den Generator nicht umgehen.

Ein eigener synthetischer Anlagenfall wurde vom Entwicklungs-PC tatsächlich an
ELSTER gesendet: Prüfergebnis 0, Versand 0, bestätigtes Transferticket. Das ist
keine Freigabe des produktiven Webservers und keine vollständige materielle
Steuerprüfung. Spätere Fach-/Anlagenrückmeldungen sowie die vollständige
Konten- und Sonderfallabdeckung bleiben offen. Details und Belege siehe
EBILANZ-EIGENER-GENERATOR.md. Keine Live-Änderung oder echte Vereinsabgabe.

## Fortsetzung: Webserver-Testversand bestanden

27 statt 19 exakte Jahreskonten; hinzugekommen sind weitere Bankkonten,
Rechnungsabgrenzungen, sonstige Rückstellungen/Verbindlichkeiten und Beratungskosten.
Vier neue synthetische Konten-/Bewertungsfälle bestehen die amtliche Validierung.
Der aktuelle Adapter wurde außerdem über HTTPS und PHP 8.3.33/FastCGI auf dem
Live-Webserver geprüft. Ein eigener synthetischer Anlagenfall wurde erfolgreich
an ELSTER-Test gesendet; Empfang mit Code 0 und Transferticket bestätigt.
Temporäre Testdateien entfernt, geschützte Produktionsdateien unverändert,
keine Verbindung zur produktiven Datenbank. Nachweise und genaue Grenzen:
EBILANZ-EIGENER-GENERATOR.md und audit/ebilanz-webserver-20260915.json.

Die amtliche EBilanzRueckmeldung liefert nur bestimmte geänderte Steuerwerte,
keine allgemeine Verarbeitungsbestätigung. Unser SGEB-/Geschäftsbetriebsprofil
ist davon ausdrücklich ausgeschlossen. Deshalb kein pauschaler Wartezustand
und keine Schlussfolgerung „keine Meldung = akzeptiert“. Die Oberfläche trennt
technischen Eingang, nicht anwendbaren Folgeabruf und gesonderten Steuerbescheid.

Offen bleiben insbesondere alle übrigen Kontenzuordnungen, Anlagenabgänge und
Umbuchungen, besondere/steuerlich abweichende Abschreibungen, mehrere
Geschäftsbetriebe sowie die Abnahme der gesamten Benutzer-/MySQL-Kette auf einer
isolierten Webserver-Testinstallation. Die Echtabgabe ist weiterhin gesperrt.

## Fortsetzung 18. September 2026: Anlagenverkauf in Entwicklung

Ausgangsrechnungsadapter und vollständiger Einzelanlagenverkauf erweitert.
Neun synthetische Verkaufsfälle bestehen die native ERiC-Validierung, einschließlich
Gewinn/Verlust, GWG, degressiver AfA und Folgejahr. Diese neun Fälle bestehen inzwischen
auch die isolierte PHP/FastCGI-Prüfung auf dem Webserver, ohne Versand oder Datenbank.
Wegen des lokalen Docker-Startfehlers werden ergänzende MySQL-Prüfungen auf der
ausdrücklich freigegebenen separaten Testdatenbank ausgeführt, mit verschlüsselter
Sicherung und anschließender vollständiger Wiederherstellung. Bedien-/Gesamtabnahme
und weitere Sonderfälle bleiben offen. Keine Veröffentlichung.
Verbindlicher aktueller Stand und verbleibende Grenzen:
[ANLAGENVERKAUF-STAND-20260918.md](ANLAGENVERKAUF-STAND-20260918.md).

## Fortsetzung 18. September 2026: getrennte steuerliche Anlagenwerte

Abweichende, fachlich bestätigte lineare Nutzungsdauer mit eigenem Steuer-Anlagenspiegel,
automatischer ausgeglichener Wertüberleitung und unverändertem Buchabschluss umgesetzt.
Einbezogen sind Vorjahresvortrag, erneute Prüfung/Freigabe und eine gesonderte Oberfläche
für die Buchhaltung. Neue Quellen verwenden getrennte SKR42-Abschreibungskonten;
alte Quellbindungen bleiben unverändert. Umfang, Prüfungen und verbleibende Grenzen:
[STEUERLICHE-ANLAGENWERTE-20260918.md](STEUERLICHE-ANLAGENWERTE-20260918.md).
Keine Produktivveröffentlichung oder Freischaltung der Echtabgabe.

## Fortsetzung 18. September 2026: Ausmusterung mit getrennten Steuerwerten

Die bestätigte abweichende lineare Nutzungsdauer kann nun auch bei vollständiger
Ausmusterung ohne Erlös fortgeführt werden. Abschreibung, Restwertabgang und Jahresvortrag
werden getrennt abgestimmt; abgeschlossene Buchdaten bleiben unverändert. Nicht mit
einem Verkauf oder einer Schenkung gleichzusetzen. Abgegrenzter Umfang und Prüfungen:
[STEUERLICHE-AUSMUSTERUNG-20260918.md](STEUERLICHE-AUSMUSTERUNG-20260918.md).
Keine Produktivveröffentlichung oder Freischaltung der Echtabgabe.
