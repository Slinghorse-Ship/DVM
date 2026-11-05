# XML-Restpunkte nach Revision R93 · 07.10.2026

## Aktueller Stand: aktive XML-Zuordnungen vollständig gespeichert

Die laufende private MySQL-Anwendung enthält jetzt für 2026 **16 von 16
aktiven Konten** eine geprüfte XML-Zuordnung: **null aktive Konten offen**.
Auch nach Zahlung oder Storno ausgeglichene Konten mit Jahresumsätzen sind
enthalten. Die folgenden älteren Abschnitte dokumentieren frühere Zwischenstände.

| Gegenstand | Verifizierter Stand nach XML-Ergänzung |
|---|---|
| 2025 | Festgeschrieben, Bilanzversion 5; Steuerentwurf Version 19 erneut mit ERiC-Code 0 geprüft; XML-Prüfsumme unverändert, null Übermittlungsversuche |
| Laufendes Jahr 2026 | Offen, Bilanzversion 14; 16 gespeicherte aktuelle XML-Zuordnungen, null offene aktive Konten; zehn Quellenübernahmen erhalten |
| Speicherung | Eigenes Modul `BalanceMappingReview`; MySQL-Schema 110, aktuelle Prüfung und unveränderliche Versionshistorie |
| Änderungen am Buchstand | Die Prüfung wird ungültig; neue Quellen, Konten oder Abschreibungen erfordern erneute Bestätigung |
| XML-Jahresendtest 2026 | Isolierte synthetische Kopie mit Testdatum 15.01.2027, übernommener GWG-Jahresabschreibung 299 Euro, 17 Zuordnungen und elf Quellen; Taxonomie 6.9, 355 Fakten, ERiC-Code 0 |
| Testabschluss nach Abschreibung | Ergebnis −42,90 Euro; Aktiva und Passiva jeweils 1.397,10 Euro |
| Übermittlung | Keine; weder Test- noch Produktivversand ausgeführt |

Das erzeugte Test-XML liegt unter
`output/xml/vereinsbilanz-2026-synthetischer-jahresendtest.xml`, SHA-256
`89ad8e227b3b5e56d623cf58ed8db7e8ab3f86d412e90f8783bacbdde748a36e`.
Es belegt die technische Erzeugung und native ERiC-Prüfung des vollständigen
synthetischen Jahresendfalls. Es ist keine festgeschriebene laufende Bilanz und
kein zur Übermittlung freigegebener Steuerentwurf.

Die echten Kalenderjahresgrenzen bleiben erhalten. Der normale Export eines
2026-Steuerentwurfs verlangt außerdem den bestätigten steuerlichen Vortrag aus
2025; für 2025 liegt keine Übermittlungsbestätigung vor. Diese Voraussetzung
wurde weder umgangen noch durch eine erfundene Empfangsbestätigung erfüllt.
Die unbebuchten Sonderkonten 72500/72550 bleiben mangels belegten Sachverhalts
unqualifiziert; die vollständige Katalogabdeckung bleibt 1681/1683 für 2025
und 1677/1679 für 2026. Das betrifft nicht die jetzt vollständige Zuordnung
der tatsächlich bebuchten Konten des Demofalls.

Nachweise: `audit/xml-completion-20261007/live-final.json`,
`native-2026.json`, `ui.json` und `REVIEW.md`; geprüfte Quellstände und private
Bereitstellung in `audit/xml-mapping-qualified-20261007.json`,
`audit/xml-mapping-private-runtime-20261007.json` und
`audit/xml-mapping-source-guard-20261007.json`. Die UI zeigt die gespeicherten
Zuordnungen; Öffnen des Prüfbereichs lädt die Seite nicht neu. Die 17 neuen
MySQL-Prüfungen, 44 XML-Übersichtsprüfungen und 550 Laufzeitfingerprint-Prüfungen
sind erfolgreich abgeschlossen.

## Autorisierte Korrektur des laufenden Demo-Testfalls 2026

Der Nutzer hat die widersprüchlichen Bestände ausdrücklich als korrigierbare
Demodaten bestätigt („Demodaten zu konsistentem Testfall korrigieren“). Die
Korrektur ist in der laufenden privaten MySQL-Anwendung gespeichert. Sie ist
keine Bestätigung echter Belege, einer Steuerbegünstigung oder der Sonderfälle
72500/72550. Die nachfolgenden älteren Abschnitte beschreiben den vorherigen Stand.

| Gegenstand | Nach der Demokorrektur |
|---|---|
| 2025 | Unverändert festgeschrieben, Version 5; XML Version 18 weiterhin aktuell geprüft, null Übermittlungsversuche |
| 2026 | Offen, Version 14; fünf Rechnungsquellen und fünf Geldbewegungen übernommen, Quellenabgleich ohne Fehler |
| Anfangsbestände | Bank korrigiert von 2.500 auf 1.020 Euro; Kasse 180 Euro; der Bilanzvortrag von 1.200 Euro bleibt erhalten und wird durch eine zusätzliche Buchung aufgeteilt |
| Pilotbuchung | Unverbundener fiktiver 10-Euro-Aufwand durch nachvollziehbares Storno zurückgenommen, Original bleibt erhalten |
| Mitgliedsbeitrag | Fiktive historische Jahresforderung über 60 Euro samt Mitglied und Demotarif dokumentiert; heutiger echter Standardtarif nicht verändert |
| Belege | Jahresbezogene Positionen/Sphären kontiert; fehlende Testbelege eindeutig fiktiv ergänzt; Eintritt von 125 Euro vollständig zugeordnet |
| Podest | Fiktiven Bestand von vier auf ein Stück über eine Zählkorrektur berichtigt; Anschaffungsbeleg und geprüfter GWG-Demoplan über 299 Euro verknüpft |
| Bank / Kasse | 1.256,00 / 141,10 Euro; beide Journalsalden stimmen mit den erfassten Bewegungen überein |
| Offene Raummiete | 240 Euro bleiben als unbezahlte Verbindlichkeit erhalten |
| Aktiva / Passiva | Jeweils 1.696,10 Euro vor Übernahme der Jahresabschreibung; Ergebnis aktuell 256,10 Euro |
| Restpunkte | Elf aktive XML-Zuordnungen, Jahresabschreibung 299 Euro und Abschlussprüfung offen; kein XML 2026 erzeugt |

Die elf Zuordnungen umfassen die bisherigen drei Sammelkonten, zwei Geldkonten,
drei Lieferanten-Verbindlichkeiten, zwei Forderungen und die Steuerart des
Eintrittserlöses. Ein Nullsaldo nach Zahlung oder Storno hebt die Zuordnungspflicht
bei vorhandenen Anfangsbeständen oder Umsätzen nicht auf. Die Quellenübernahme
erklärt daher den Anstieg gegenüber den vorherigen drei Sammelkonten.

Die Anwendung verhindert die Festschreibung des laufenden Kalenderjahres und
die Feststellung des jährlichen Abschreibungsstandes vor Jahresende. Beide
Grenzen bleiben unverändert; ein fertiger Jahresabschluss oder 100-Prozent-Status
wird nicht behauptet. Die Katalogabdeckung 1681/1683 bzw. 1677/1679 bleibt ebenfalls
unverändert: Demodaten ersetzen die fehlende Qualifikation von 72500/72550 nicht.

Die Reparatur wurde zunächst durch die Fach-APIs auf einer isolierten MySQL-Kopie
erstellt. Der daraus abgeleitete, begrenzte Änderungsbestand wurde in einer
einzigen Transaktion angewandt: vollständiger Vorher-Abgleich, Vereinssperre,
Schema-/Zeilenprüfung, unveränderte Fremdschlüsselprüfung und privater binärer
Wiederherstellungspunkt vor dem Schreiben. Bei Konflikten erfolgt Rollback.
Generierte Inventarereignisnummern werden samt Bewegungsreferenz neu zugeordnet,
damit andere Vereine keine Nummernkollision erleiden.

Code-Review und sechs MySQL-Kontrollen sind in
`audit/demo2026-repair-20261007/proof.json` dokumentiert: Quellen-/Historien-Replay,
beide Kalenderjahresgrenzen, Zurückweisung veralteter oder wiederholter Reparatur,
unveränderte Tabellen bei Fehlern, freigegebene Transaktion und gültige
Fremdschlüssel. Die nachfolgende reine Abfrage und die PDF-Downloads verändern
keine Daten. Der dreiseitige PDF-Arbeitsstand wurde vollständig gerendert und
visuell geprüft. Es wurden keine Laufzeitdateien geändert oder produktive Daten
ersetzt; die einmaligen Reparaturskripte sind keine öffentlich erreichbaren
Anwendungsaktionen.

## Erneuter Abschlussabgleich nach „Schließe Bilanz ab“

Die private MySQL-Anwendung wurde erneut ausschließlich lesend geprüft. Alle
45 überwachten Tabellen blieben unverändert. Beide Bilanz-PDFs wurden aus der
Anwendung heruntergeladen, vollständig gerendert und visuell geprüft; Tabellen,
Beschriftung und Seitenzahlen sind lesbar.

| Gegenstand | Tatsächlicher Stand |
|---|---|
| Testabschluss 2025 | Festgeschrieben, Version 5; null offene aktive XML-Zuordnungen |
| Test-XML 2025 | Gespeicherte ERiC-Prüfung Code 0 weiterhin aktuell, Steuerentwurf Version 18; unveränderte XML-Prüfsumme, keine Übermittlung |
| PDF 2025 | Zwei Seiten, ausdrücklich synthetisch; Aktiva/Passiva jeweils 1.200 Euro, Jahresergebnis 200 Euro |
| Testabschluss 2026 | Offen, Version 2; Buchführung, Bewertung und Jahresabgrenzung unbestätigt; keine Steuerabgabe |
| Aktive XML-Zuordnungen 2026 | `cash` (Bank und Kasse, 1.190 Euro), `equity` (Vereinskapital/Ergebnisvortrag, 1.200 Euro), `expense` (Aufwendungen, 10 Euro); alle ohne SKR42-Code |
| Quellenabgleich 2026 | Das PDF nennt unter anderem nicht übernommene Beiträge, Bühnenpodest, Förderung, Büromaterial, Eintritt und offene Raummiete; Vereinskonto und Barkasse stimmen nicht mit den erfassten Geldbewegungen überein |
| PDF 2026 | Drei Seiten, ausdrücklich synthetischer Arbeitsstand mit offenen Prüfungen |
| 72500/72550 | In beiden laufenden Testjournalen nicht vorhanden; kein belegter Originalanlagen-/Rücklagenfall für eine aktive steuerliche Freigabe |
| Gesamter SKR42-Katalog | Unverändert 1681/1683 für 2025 und 1677/1679 für 2026; die beiden Sonderkonten bleiben fachlich unqualifiziert |
| Echte Vereinsbilanz / 2027 | Mit diesen ausschließlich synthetischen Daten nicht abgenommen; kein Originalabschluss 2027 nachgewiesen |

Die offenen Arbeiten beschränken sich damit nicht auf „3 % XML“. Aus generischen
Kontobezeichnungen lässt sich keine konkrete Detailzuordnung ableiten. Fehlende
Quellen und Bank-/Kassenabgleiche dürfen durch eine Festschreibung nicht
übersprungen werden. Der erfolgreiche 2025-Test ist keine echte Vereinsbilanz.

§ 6b EStG und § 8b KStG wurden erneut an den amtlichen Quellen geprüft. Die
Kontonummer ersetzt keine Prüfung des ursprünglichen Steuerpflichtigen, der Anlage
und des tatsächlichen Buchungssachverhalts:
https://www.gesetze-im-internet.de/estg/__6b.html und
https://www.gesetze-im-internet.de/kstg_1977/__8b.html.

Nachweis: `audit/balance-current-review-20261007/readback.json`; beide PDFs und
der PDF-Prüfnachweis liegen im selben Verzeichnis. Keine erneute native
Validierung, Jahresfestschreibung, Buchungs-, Bewertungs- oder Zuordnungsänderung.

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

## Aktueller MySQL-Abgleich und fehlender Originalfall

Der erneute ausschließlich lesende Abgleich der laufenden privaten Testanwendung
bestätigt den gespeicherten Stand: 2025 ist geschlossen, hat vier gespeicherte
Zuordnungen, zwölf unbenutzte Konten und **null offene aktive Zuordnungen**.
Das bereits nativ geprüfte XML gehört weiterhin zum aktuellen Abschluss und
Programmstand. Es wurde dabei keine neue native Prüfung ausgeführt. 2026 bleibt
offen, enthält drei offene aktive Zuordnungen und keine Steuerabgabe.
Alle 45 MySQL-Tabellen sind vor/nach dem Lesen identisch; keine Buchung,
Steuerbewertung, Jahresfestschreibung oder Übermittlung wurde vorgenommen.
Nachweis: `audit/xml-r90-readonly-current-20261007.json`.

Diese Angaben beziehen sich auf **synthetische Testdaten**, nicht auf eine
fachliche Freigabe der echten Vereinsbilanz. Die zwei fehlenden Katalogregeln
72500/72550 sind von den aktiven Zuordnungen eines konkreten Abschlusses zu
unterscheiden. Der aktuelle 2025-Testabschluss verwendet die beiden Konten nicht.

Für eine tatsächliche Bebuchung wird der ursprüngliche Sachverhalt benötigt:
welches Konto, welches Jahr, welcher Steuerpflichtige/Rechtsform, welche
Finanzanlage, welcher ursprüngliche Gewinn beziehungsweise welche Rücklage und
welche belegte Rechtsgrundlage. Die dafür gestartete Rückfrage unterscheidet
„nur im vollständigen Kontenrahmen benötigt“ von „tatsächlich bebucht“.
Eine Nullbuchung, eine allgemeine XML-Auffangposition oder ein mathematisch
gültiger Einzelunternehmerfall ersetzt diesen Originalfall nicht. 72550 wird
wegen seiner zusätzlichen Rechtsgrundlagen nicht pauschal als unanwendbar
entfernt. Bis zur Klärung bleiben beide Katalogregeln unqualifiziert und die
Gesamtaufgabe unvollständig; der angefragte 100-Prozent-Stand ist nicht erreicht.

## Quellenarbeit R90 · noch keine Laufzeitfreigabe

### Ergänzter Quellenweg: fortgeführte Rücklage aus einer Einbringung

§23 Absatz1 in Verbindung mit §12 Absatz3 UmwStG und der amtliche
Umwandlungssteuererlass (Randnummer23.06) unterscheiden die eigene neue
Rücklagenbildung von der Fortführung bestehender steuerfreier Rücklagen bei
einer Buchwerteinbringung. Die bisherige R90-Rechnung für den ursprünglichen
natürlichen Einzelunternehmer wird deshalb nicht als pauschale Entscheidung
über sämtliche Rechtsnachfolgefälle benutzt. Die amtliche Aussage zur
Fortführung allein beweist aber noch nicht, dass eine übernehmende Körperschaft
den konkreten §6b-Absatz10-Anteilsabzug vornehmen darf.

Ein zusätzlicher, nicht aktivierter Quellenentwurf hält die ursprüngliche
Gewinn-/Rücklagenkette fest: ursprünglicher Steuerpflichtiger und Gewinnjahr,
unveränderte Jahresgrenze, verfügbare Rücklage, Buchwerteinbringung eines ganzen
Betriebs, steuerlicher Übertragungsstichtag, spätere Anteilskosten und vier
getrennte Dokumentreferenzen für Bildung, Einbringung, Fortführung und Wahl.
Ein neuer Fristbeginn wird ausdrücklich abgelehnt. Teilübertragungen,
Zwischen-/gemeine Werte und eine unterstellte Übernahme durch Verein/Stiftung
sind in diesem Entwurf nicht abgedeckt.

Zwei Rechenfälle für2025/2026 und32 negative Quellenkettenbedingungen bestehen
drei Testmethoden. Originales Gewinnjahr2024 und dessen Anteilserwerbsfrist bis
31.12.2026 bleiben bei der Einbringung unverändert. Der Entwurf nennt ausdrücklich
die noch ungeprüften Dokumentbytes/Rechte, fehlende Bindung an das vollständige
Originaljournal und fehlende konkrete Körperschaftsteuer-/§8b-/XML-Abnahme.
Er ist eine zusätzliche Grundlage für die fachliche Prüfung, keine Freigabe
der Konten72500/72550. Alle1908 abgenommenen R89-Quelldateien und die erste
R90-Quellenevidenz bleiben unverändert. Keine Datenbank- oder Laufzeitänderung.

Nachweis: `audit/skr42-r90-succession-source-stage-20261007.json`,
`audit/r90-source-stage/reserve_succession.py` und
`audit/r90-source-stage/test_reserve_succession.py`. Primärquellen:
[§23 UmwStG](https://www.gesetze-im-internet.de/umwstg_2006/__23.html),
[§12 UmwStG](https://www.gesetze-im-internet.de/umwstg_2006/__12.html),
[Amtlicher Umwandlungssteuererlass, Randnummer23.06](https://usth.bundesfinanzministerium.de/esth/2025/B-Anhaenge/Anhang-28/inhalt.html).

Die letzten beiden Konten 72500/72550 sind in den unveränderten Originalquellen
2025 und 2026 als **SB** markiert. Sie dürfen nicht als allgemeine
handelsrechtliche Wertminderung freigegeben werden. Der DATEV-E-Bilanz-Kontenrahmen
2025 für SKR04 weist den entsprechenden §6b-Fall der Auffangposition „nicht
zuordenbar“ zu. Das ist ein ergänzender Quellenhinweis, keine automatische
SKR42-Zuordnung. Die amtlichen Taxonomien 6.7–6.10 führen hierfür
`is.netIncome.regular.fin.netParticipation.amortFinanc.other`; ihre Erläuterung
erlaubt die Auffangposition nur, soweit eine detaillierte Zuordnung nicht möglich
ist. Ob dieser Ausweis für den Originalfall notwendig ist, bleibt zu belegen.

§6b Absatz10 Satz1 schließt Körperschaften, Personenvereinigungen und
Vermögensmassen aus. Ein eigener Anteilserwerb von Verein, Stiftung oder gGmbH
ist deshalb kein gültiger positiver Test für diese Vorschrift. Dies ist kein
pauschaler Ausschluss des gesamten Kontos 72550: dessen zusätzliche
§3-Nummer40-/§8b-Absatz3-Sachverhalte und etwaige ursprüngliche Sonderfälle
benötigen einen getrennten Quellenabgleich.

Die aktuelle Grenze von zwei Millionen Euro darf nicht rückwirkend für die
Kalenderjahre 2025/2026 verwendet werden. §52 Absatz14 Satz7 knüpft an ein nach
dem 10.02.2026 **beginnendes** Gewinn-Wirtschaftsjahr an. Für Kalenderjahre bis
einschließlich 2026 gilt in diesem abgegrenzten Anteilssachverhalt die bisherige
500.000-Euro-Grenze; die neue Grenze betrifft hier erstmals 2027. Maßgeblich ist
das Gewinnjahr, nicht das spätere Jahr des Ersatzkaufs. Bei Anteilen beträgt die
Reinvestitionsfrist zwei Folgejahre; die vierjährige Gebäudefrist wird nicht
übernommen. Die Kürzung der Anteilskosten umfasst nach §6b Absatz10 Sätzen3/5/6
auch den steuerfreien Gewinnanteil und ist keine planmäßige Abschreibung.

Ein isolierter, **nicht aktivierter** Rechenentwurf prüft ursprünglichen Gewinn,
gebildete/verfügbare Rücklage, Anteilskosten, Frist, Centtypen, sechsjährige
Bindung und Rechtsformgrenze. Fünf Testmethoden einschließlich 36 gezielter
Ablehnungen bestehen. Alle 1908 qualifizierten R89-Quelldateien wurden unverändert
verifiziert. Originalquellen, Zwecke, versiegelte Jahre, laufende Anwendung und
MySQL wurden nicht verändert. Die Abdeckung bleibt bei zwei offenen Konten je
Jahr; der Entwurf erzeugt keine XML und besitzt keine native Abnahme.

Der Entwurf besitzt jetzt auch eine unabhängige PHP-Rechnung. Drei gültige
Rechenfälle und dieselben 36 gezielten Ablehnungen stimmen zwischen PHP und
Python exakt überein, einschließlich Centbeträgen und Ergebnisstruktur. Diese
positiven Fälle betreffen einen abgegrenzten natürlichen Einzelunternehmer,
keinen eigenen §6b-Absatz10-Fall von Verein, Stiftung oder gGmbH. Sie qualifizieren
deshalb weder die Vereinskonten noch die öffentliche Speicherung oder XML.
R89 und die erste R90-Quellenevidenz sind unverändert verifiziert. Nachweis:
`audit/skr42-r90-source-parity-20261007.json`.

Für die Freigabe fehlen insbesondere ein gültiger Originalfall im tatsächlich
unterstützten Rechtsformumfang, Dokument-/Journalbindung, eine unabhängige
PHP-Prüfung des vollständigen gespeicherten Quellennachweises,
belegte Reserveauflösung, steuerlicher Beteiligungsausweis und eine eigene
Anlagenspiegelprüfung. Der vorhandene Kostenanlagenadapter behandelt
Wertminderungen als außerplanmäßige Abschreibung; er darf nicht unverändert als
§6b-Adapter benutzt werden. Erst danach folgen öffentliche MySQL-Speicherung,
native XML-Prüfung und Code-Review des vollständigen Weges. Eine erfolgreiche
isolierte Berechnung schließt diese offenen Punkte nicht.

Nachweis: `audit/skr42-r90-source-stage-20261007.json` und
`audit/r90-source-stage/`. Primärquellen:
[§6b EStG](https://www.gesetze-im-internet.de/estg/__6b.html),
[§52 EStG](https://www.gesetze-im-internet.de/estg/__52.html),
[DATEV E-Bilanz SKR04 2025](https://apps.datev.de/help-center/api/knowledge-help/v1/content/entities/st12294634379_de.pdf?docId=0907811&save=False).

Steuern, Dokumente und Website sind eigene Fachmodule. Gemeinsame technische
Dienste ersetzen ihre jeweiligen Fachregeln und Prüfungen nicht.

## Aktueller Stand R89 · 07.10.2026

77690 ist für einen abgegrenzten Originalfall in der laufenden privaten MySQL-Testanwendung angebunden: vollständiger anfänglicher Kalenderabschluss 2025/2026 ohne Anlagen, Vorjahreskette oder weitere Ergebnisverwendung; bereits gespeicherte, unabhängig bestätigte steuerliche Aufwands-/Rückstellungswerte und ursprünglicher Ergebnisvortrag 29700. Drei getrennte Originaldokumente belegen die Kapitaldarstellung, die unabhängig geprüften Steuerwerte und die interne Ergebnisumbuchung. Die Steuerbewertung wird nicht aus Kontonamen abgeleitet. Andere Anlagen, Kapitaldarstellungen, Vorjahresänderungen, Ergebnisverwendungen oder Fortschreibungen benötigen eine eigene Abnahme.

Die Ergebnisdifferenz wird aus den Originalbuchungen und tatsächlichen Steuerbuchungen berechnet. Die daraus erzeugte Kapitalumbuchung verändert weder den Steuergewinn noch den Betriebsvermögensvergleich. Der XML-Ausweis erhält das ursprüngliche Kapital und das handelsrechtliche Jahresergebnis; der laufende steuerliche Mehr-/Mindergewinn steht getrennt unter `bs.eqLiab.equity.netIncome.taxBalanceGenerally.transferDiffTaxAccounts`. Die amtlichen Quellen 6.7–6.10 unterscheiden diesen erfolgswirksamen Betrag von erfolgsneutralen laufenden Änderungen und Änderungen früherer Jahre. Die bisherigen Klasse-9-Kataloge und Ausschlüsse bleiben unverändert; ein fiktives Konto 92970 wird nicht erzeugt.

Zwölf öffentlich gespeicherte MySQL-Fälle für Verein, Stiftung und gGmbH, beide Jahre und beide Vorzeichen bestehen ERiC-Code 0 ohne Übermittlung. Sie enthalten 204 Quellenprüfungen, darunter 120 negative Bedingungen. Weitere 164 PHP-/Python-Mutationen werden übereinstimmend abgelehnt. Zwei zusätzliche MySQL-Fälle bestehen zusammen 38 Prüfungen einschließlich tatsächlichem PHP-Formular und wiederholtem Speichern ohne doppelte Buchungen. Alle zwölf XML-Prüfsummen bleiben nach Formular-/Aktionsanbindung unverändert; 15 gezielte Testprogramme bestehen. Vier native Originalfälle sind als Repository-Fixtures erhalten. Rechenregeln, Dokument-/MySQL-Prüfung, versionierte Aktion und Darstellung liegen in getrennten Modulen.

Aktuelle Katalogabdeckung: **1681/1683 für 2025 und 1677/1679 für 2026; zwei Konten je Jahr offen: 72500 und 72550**. Automatisch/bedingt: 1043/638 beziehungsweise 1040/637. Alte Zweckversionen, Originalkataloge, Vergleichsklassifikation und versiegelte Jahresstände bleiben erhalten. Die Zukunftsprojektion 6.10 enthält 686 geeignete und 21 zurückgehaltene Zwecke; Originalquelle 2027 und native 6.10-Abnahme fehlen weiterhin. Deshalb ist die gesamte Bilanz-/XML-Aufgabe noch nicht zu 100 Prozent abgeschlossen.

19 geprüfte Laufzeitdateien wurden ohne Neustart in die private Anwendung übernommen; dabei blieben alle 45 MySQL-Tabellen unverändert. Der vorhandene synthetische 2025-Export besteht anschließend erneut ERiC-Code 0 mit unveränderter XML-Prüfsumme; nur sein Prüfdatensatz wurde aktualisiert. Das tatsächliche 2026 bleibt offen. Der lokale Quellenschutz wurde gegen den richtigen R88-Vorgängerstand geprüft; eine zunächst aus dem älteren Helfer geerbte R87-Referenz wurde ausschließlich im neuen Schutzabschnitt berichtigt.

Nachweise: `audit/skr42-r89-accepted-20261007.json`, `audit/skr42-r89-original-tax-equalization-native-batch1-20261007.json`, `audit/skr42-r89-public-source-contract-stage4-20261007.json`, `audit/skr42-r89-form-guards-stage1-20261007.json`, `audit/skr42-r89-final-xml-parity-20261007.json`, `audit/skr42-r89-targeted-reviewed-stage1-20261007.json`, `audit/xml-r89-private-runtime-20261007.json`, `audit/xml-r89-current-2025-native-20261007.json` und `audit/xml-r89-source-guard-20261007.json`. Die getrennte Vorbereitung der amtlichen Kapitalpositionen steht in `audit/r89-source-stage/official-capital-graph.json`.


## Historischer R89-Entwurf vor der Abnahme von 77690 · 07.10.2026

Zum Zeitpunkt dieser vorbereitenden Quellenstufe blieb die aktivierte und qualifizierte Anwendung auf R88. Die neue Quellenstufe ist ausdrücklich **keine zusätzliche Kontenabnahme**: weiterhin drei offene Konten je Jahr (72500, 72550, 77690). Der isolierte Berechnungsentwurf liegt in `audit/r89-source-stage/tax_equalization.py`; er speichert keine Buchungen, aktiviert keine Zuordnung und erzeugt keine neue XML-Datei. Seine Berechnung gibt stets `activation_ready=false` und die noch fehlenden Abnahmen zurück.

Die amtlichen ursprünglichen Taxonomiequellen 6.7, 6.8, 6.9 und 6.10 wurden für den steuerlichen Ausgleichsposten getrennt ausgewertet. Für den erfolgswirksamen laufenden Mehr-/Mindergewinn ist `bs.eqLiab.equity.netIncome.taxBalanceGenerally.transferDiffTaxAccounts` vorgesehen. `changePresentYear` beschreibt dagegen erfolgsneutrale Änderungen; `capAdjust` betrifft Anpassungen früherer Wirtschaftsjahre. Diese Positionen dürfen nicht allein anhand der Kontobezeichnung ausgetauscht werden. Quellenprüfsummen, Referenzen und Rechenbeziehungen sind in `audit/r89-source-stage/official-capital-graph.json` festgehalten. Die Quellenprüfung ersetzt insbesondere keine native Abnahme der Version 6.10.

Der Entwurf prüft den ursprünglichen vollständigen Abschluss und die steuerlichen Buchungsquellen mit dem bestehenden qualifizierten Exporter. Erst danach berechnet er die laufende steuerliche Ergebnisdifferenz und die vorgeschlagene Gegenwirkung in der Ergebnisverwendung. Steuerliche GuV und Betriebsvermögensvergleich behalten den tatsächlichen Steuergewinn; ursprüngliche Eröffnungsanpassungen werden getrennt ausgewiesen. Zwanzig aufbewahrte Originalfälle beider Jahre mit positiven und negativen Ergebnisdifferenzen bestehen vier Testgruppen einschließlich Ablehnung bearbeiteter Ergebnisse, geänderter steuerlicher Buchungsbeträge, Quellenbindung und abweichender Abschlussbereiche. Die ursprünglichen XML-Prüfsummen und alle 1897 versiegelten R88-Dateien bleiben unverändert. Nachweis: `audit/skr42-r89-source-stage-20261007.json`.

Vor einer Aktivierung fehlen weiterhin: bestätigte ursprüngliche Kapitaldarstellung und Ergebnisverwendungsnachweis, ausdrückliche Behandlung des bisherigen Klasse-9-Kontos 92970, Verbindung mit den tatsächlich gespeicherten steuerlichen Gegenbuchungen, öffentlicher versionierter MySQL-Speicherpfad mit Rechte-/Dokumentenprüfungen und native ERiC-Fälle mit genau diesen Ausgleichsposten. Der Entwurf wird nicht als fertige XML-Verknüpfung gezählt. Dokumente/UI/Installer bleiben nachgelagert; die vereinbarte modulare Trennung gilt auch für diesen Quellenvertrag.

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

| Jahr | Aktuell buchbar | Mit XML-Regel | Offen |
| --- | ---: | ---: | ---: |
|2025|1683|1679|4|
|2026|1679|1675|4|

## Originaljahr 2025

| Konto | Originalbezeichnung |
| --- | --- |
| 62490 | Abzugsbetrag nach § 6b EStG |
| 72500 | Abschreibungen auf Finanzanlagen auf Grund § 6b EStG-Rücklage |
| 72550 | Abschreibungen auf Finanzanlagen auf Grund § 6b EStG-Rücklage, § 3 Nr. 40 EStG bzw. § 8b Abs. 3 KStG |
| 77690 | Änderung steuerlicher Ausgleichsposten (Körperschaften) |

## Originaljahr 2026

| Konto | Originalbezeichnung |
| --- | --- |
| 62490 | Abzugsbetrag nach § 6b EStG |
| 72500 | Abschreibungen auf Finanzanlagen auf Grund § 6b EStG-Rücklage |
| 72550 | Abschreibungen auf Finanzanlagen auf Grund § 6b EStG-Rücklage, § 3 Nr. 40 EStG bzw. § 8b Abs. 3 KStG |
| 77690 | Änderung steuerlicher Ausgleichsposten (Körperschaften) |

## Getrennte Voraussetzungen

- Korrektur der früheren Angabe „16 offene Kontenentscheidungen“: Die Übersicht ignorierte vier bereits gespeicherte Zuordnungen und zählte zwölf unbenutzte Konten mit. R78 verknüpft den festgeschriebenen Abschluss mit genau seinem Entwurf. Für das tatsächliche Übungsjournal 2025 sind nun null aktive Konten offen; der vorhandene Entwurf wurde mit aktuellem Programmstand nativ geprüft (ERiC Code 0), der Browserdownload stimmt bytegenau überein. Dies bestätigt ausschließlich den vorhandenen synthetischen Abschluss, nicht die fehlenden Katalogregeln.
- Das tatsächliche Kalenderjahr 2026 ist weiterhin offen. Drei aktive Journal-Konten haben noch keinen gespeicherten XML-Entwurf; dreizehn Konten sind unbenutzt. Kein vorzeitiger Jahresabschluss und keine Übermittlung wurden ausgeführt.
- Für 2027 fehlen Originaljahresquelle und native Abnahme des Taxonomie-/Adapterpakets 6.10. Veröffentlichung und Katalogüberleitung sind allein keine Abnahme.
- R82 korrigiert die sechs originalen Vorjahresvergleichskonten2025 mit Jahres-/Quellbindung und Buchungssperren. Sie sind keine offenen aktuellen XML-Sachverhalte. Historische Quellkataloge und versiegelte Zuordnungen bleiben erhalten.
- Weitere offene technische Gruppen: Substanzverzehr, steuerliche Sonderabschreibungen und Ausgleichsposten. Je Gruppe sind Originalquelle, Betragsregeln, Brutto-Anlagenbewegungen beziehungsweise Überleitungsangaben und native XML-Fälle erforderlich. Pensionsverrechnung ist mit R80 für den oben beschriebenen Bankguthabenfall eingebaut; ihre übrigen Vermögensarten und Fortschreibungen bleiben gesonderte Voraussetzungen.

Nachweise: `audit/balance-mapping-r77-20261007.json`, `audit/skr42-r77-accepted-20261007.json`, `audit/skr42-r77-private-rpc-20261007.json`, `audit/skr42-r77-ui-readback-20261007.json`.

R78-Nachweise: `audit/xml-r78-qualified-20261007.json`, `audit/xml-r78-current-2025-native-20261007.json`, `audit/xml-r78-ui-download-20261007.json`, `audit/xml-r78-source-guard-20261007.json`. Die MySQL-Prüfung umfasst 44 Prüfungen einschließlich Leserechten, Mandantentrennung, Quellbindung, Konten mit Nullsaldo trotz Umsatz, mehrdeutigen Entwürfen und unveränderten Datenbanktabellen. Beim erneuten nativen Prüfen änderte sich ausschließlich `tax_filings`; Buchungen, Anlagen und alle anderen Tabellen blieben unverändert.

R80-Laufzeitnachweise: `audit/xml-r80-private-runtime-20261007.json`, `audit/xml-r80-current-2025-native-20261007.json`, `audit/xml-r80-source-guard-20261007.json`. Alle45 Tabellen bleiben bei der Codeübernahme unverändert; die erneute native Prüfung des vorhandenen Abschlusses2025 ändert ausschließlich den Prüfdatensatz in `tax_filings`. XML-Prüfsumme bleibt `fe5d8c098530dd2d836787d6f9a3679d87aa2c3542c15ed715db39c9ec36ea95`. Kein Neustart, keine Neuinitialisierung und keine Übermittlung.

R81-Laufzeitnachweise: `audit/xml-r81-private-runtime-20261007.json`, `audit/xml-r81-current-2025-native-20261007.json`, `audit/xml-r81-source-guard-20261007.json`. Übernahme der19 Laufzeitdateien lässt alle 45 Tabellen unverändert; native Prüfung des vorhandenen Übungsabschlusses2025 aktualisiert ausschließlich `tax_filings`. Kein Neustart, keine Neuinitialisierung und keine Übermittlung.

R82-Laufzeitnachweise: `audit/xml-r82-private-runtime-20261007.json`, `audit/xml-r82-current-2025-native-20261007.json`, `audit/xml-r82-source-guard-20261007.json`.13 Laufzeitdateien übernommen; alle 45 Tabellen unverändert. Native Prüfung des vorhandenen Übungsabschlusses2025: ERiC-Code0, identische XML-Prüfsumme, ausschließlich `tax_filings` aktualisiert. Kein Neustart, keine Neuinitialisierung, keine Übermittlung.

R83-Laufzeitnachweise: `audit/xml-r83-private-runtime-20261007.json`, `audit/xml-r83-current-2025-native-20261007.json`, `audit/xml-r83-source-guard-20261007.json`.

R84-Laufzeitnachweise: `audit/xml-r84-private-runtime-20261007.json`, `audit/xml-r84-current-2025-native-20261007.json`, `audit/xml-r84-source-guard-20261007.json`.

R85-Laufzeitnachweise: `audit/xml-r85-private-runtime-20261007.json`, `audit/xml-r85-current-2025-native-20261007.json`, `audit/xml-r85-source-guard-20261007.json`.

R86-Laufzeitnachweise: `audit/xml-r86-private-runtime-20261007.json`, `audit/xml-r86-current-2025-native-20261007.json`, `audit/xml-r86-source-guard-20261007.json`.
