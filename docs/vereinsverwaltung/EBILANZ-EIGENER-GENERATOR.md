# Eigene E-Bilanz-Erzeugung – abgegrenzter Entwicklungsschritt

Stand 15.09.2026. **Nur Entwicklung/Test, keine Produktivfreigabe.**
Die Live-Installation, ihre Datenbank, Zertifikate und Schalter bleiben unverändert.
Der Entwurf ist lokal im bestehenden, berechtigungsgeprüften Bilanzbereich integriert.
Ein ausdrücklich freigeschalteter **synthetischer Testablauf** verbindet nun
Entwurf, Steuerwerte, Kontenzuordnung, Vorstandsfreigabe und den vorhandenen Sender.
Produktive E-Bilanz-Abgaben bleiben unabhängig von allgemeinen ELSTER-Schaltern gesperrt.

## Was tatsächlich funktioniert

`BalanceTaxValues::build()` nimmt einen zuvor geprüften, festgeschriebenen
`BalanceLedger::report()` entgegen. Es berechnet das Journal erneut und vergleicht
das Ergebnis exakt. Eine explizite Steuerwertprüfung ist an Jahr und Abschluss-Hash
gebunden. Anfangs-Wertunterschiede und laufende Wertunterschiede werden getrennt,
jeweils mit eindeutiger Nummer, Begründung und ausgeglichenem Soll/Haben erfasst.
Das Handelsjournal wird nicht verändert. Die Gegenüberstellung weist je Konto
Handelswert, Steuerwert und Differenz aus. Anfangsdifferenzen verändern nicht
stillschweigend den Gewinn des laufenden Jahres.

`ebilanz_builder.py` erzeugt daraus selbst die XML-Hülle, GCD-Stammdaten,
dimensionale Bilanz-/GuV-Fakten, Pflicht-NIL-Angaben, Oberpositionen und
Kontennachweise. Es liest **keine amtliche Muster-XML** als Vorlage und übernimmt
keine Musterbeträge. Die Summennetze und Pflichtfeld-Metadaten werden mit
`compile-ebalance-taxonomy.py` aus dem veröffentlichten amtlichen XBRL-Paket
abgeleitet; jede gelesene Quelldatei ist mit SHA-256 dokumentiert.

Amtliche Quelle: https://www.esteuer.de/ und das dort verlinkte
[Taxonomie-6.9-Paket](https://www.esteuer.de/download/taxonomie_20250618/german-gaap-taxonomy-v6.9-2025-04-01-xbrl.zip).
Zusätzlich verwendet: die lokal lizenzierte ERiC-44.3.6.0-Dokumentation zur
E-Bilanz 6.9, insbesondere Kontext-, Pflichtfeld-, Summen- und Kontennachweisregeln.
ERiC-Software, Entwicklerpakete und Zugangsdaten gehören nicht zum Quellpaket.

Weiterer Prüfmaßstab: [amtliche technische Leitfäden](https://www.esteuer.de/download/taxonomie/Technische_Leitfaeden_2026.zip),
Version 6.9 vom 10.06.2025, B.14.12 und B.20. Besonders wichtig: Bei der
Geschäftsbetriebsdarstellung entfallen einige native Anlagenprüfungen. Deshalb
prüft der eigene Generator die Bewegungsrechnungen und die Abstimmung zur Bilanz
und GuV selbst. Native Annahme allein ist für diesen Fall kein ausreichender Nachweis.

## Sehr bewusst begrenztes Profil

- Kalenderjahr 2025 oder 2026, Taxonomie 6.9, AO-Steuerwerte, Gesamtkostenverfahren.
- Eine Körperschaft mit **einem separat bilanzierten wirtschaftlichen
  Geschäftsbetrieb**, Übermittlungsoption Geschäftsbetriebstabelle (SGEB).
- Bestätigte Abgrenzung des gesamten Bestandsjournals, nicht nur ein GuV-Filter
  „Sphäre 4“. Jede andere Quellsphäre wird abgewiesen, selbst wenn Buchungen und
  Gegenbuchungen zusammen null ergeben. Eine vollständige Vereinsbilanz darf
  nicht durch Setzen des Profils in eine Geschäftsbetriebsbilanz umbenannt werden.
- Steuerbegünstigung wird ausdrücklich im Profil angegeben; ein steuerbegünstigter
  Verein wird nicht mehr als nicht steuerbegünstigte Körperschaft vorbelegt.
- Ausgehend von 19 exakten SKR42-Konten aus den geprüften Ausgaben 2025/2026
  erweitert Revision 3 die Liste auf **62 Konten für 2025 und 64 für 2026**
  (vollständige Nummern und Zielpositionen: `resources/ebilanz/skr42-6.9.json`).
  Die drei Abschreibungskonten benötigen zusätzlich die passende, abgestimmte
  Anlagenaufstellung. Bei mehreren Einzelkonten mit derselben Abschreibungskontonummer
  wird keine automatische Aufteilung erfunden.
- `BalanceTaxMapping` und `ebilanz_mapping.py` verwenden dieselbe Regeldatei.
  Quellen-PDF-Hash und LF-normalisierter Katalog-Hash binden die Regeln an das
  konkrete Jahr. Kein Ersatz eines fehlenden Jahres durch das aktuelle Jahr.
  Jedes andere Katalogkonto hat einen sichtbaren offenen Prüfstatus. Das ist
  **kein vollständiges SKR42-Mapping**; unbekannte Konten werden nicht auf
  Sammelpositionen umgebucht. Kontenbezeichnungen und Anfangsziffern sind keine Regeln.
- Nicht automatisch zuordenbare Konten benötigen eine explizite fachliche Zuordnung
  aus den bereits technisch geprüften Fällen (u.a. Ergebnisvortrag und Umsätze).
  Das Profil wird
  nicht automatisch aus „kein Vorsteuerabzug“ abgeleitet: Letzteres bedeutet nicht
  automatisch Kleinunternehmerregelung. Andere Umsatzsteuerfälle sind hier gesperrt.
- Eigene, rechnungsgebundene Ausstattung, Büroeinrichtung und erworbene Software
  mit linearem Monatsplan sowie ausdrücklich geprüfte GWG-Sofortabschreibungen
  sind im Brutto-Anlagenspiegel unterstützt. Abgänge,
  Umbuchungen, andere Anlagenklassen, Sonderabschreibungen und abweichende
  steuerliche Anlagenwerte bleiben gesperrt. Andere Taxonomie-Jahre ebenfalls.

## Brutto-Anlagenspiegel

Der beim Jahresabschluss aufbewahrte Anlagenstand wird Bestandteil des gehashten
Steuerwerte-Pakets. Die Verarbeitung verlangt bei vorhandenen Gegenständen eine
ausdrückliche Bestätigung des Geschäftsbereichs und gleicher Handels-/Steuerwerte.
Ein fehlender Anlagenstand mit null Euro Nettosaldo ist kein Nachweis fehlender
Gegenstände: Ohne ausdrückliche Bestätigung, dass auch keine vollständig
abgeschriebenen Gegenstände vorhanden sind, wird die Erzeugung gesperrt.

`ebilanz_assets.py` berechnet den Monatsplan centgenau erneut und vergleicht
Bruttokosten, Zugänge, Abschreibungen und Buchwerte je Einzelgegenstand, je
Anlagenkonto und insgesamt mit dem Handels- und Steuerjournal. Auch gegenläufige
Steuerkorrekturen oder Anlagenbewegungen mit Nettosaldo null werden nicht übergangen.
Die Abschreibung muss zudem zum zugehörigen GuV-Posten passen.

Die amtliche Zeilenhierarchie, Bewegungsgleichungen und Dimensionskonzepte werden
aus dem XBRL-Paket kompiliert. Der Export enthält Steuerbilanzwerte (`dim_taxBal`)
in der Bruttotabelle (`dim_changes.nt.ass.gross`) und kündigt den Bestandteil BAL an.
Der Anlagenspiegel enthält nur den **einen** bestätigten Geschäftsbetrieb; er wird
nicht als Vermögensübersicht des gesamten Vereins ausgegeben. Die Tabellen haben
keine frei ergänzte Geschäftsbereichsdimension. Mehrere Betriebe bleiben gesperrt.

Das JSON wird an die ursprünglichen Werte und an das Mapping/Profil im erzeugten
XML gebunden. Hashes beweisen Datenkonsistenz, **keine Identität oder Berechtigung**.
`BalanceTaxFiling` prüft nun Rollen, Verein, Abschluss-Hash und Steuerwerte erneut
vor Validierung, Freigabe und Versand. Eine Änderung oder neue Validierung entfernt
die Vorstandsfreigabe. Sie ist zusätzlich an die XML und die Laufzeitdateien gebunden;
entzogene Vorstands-/Finanzberechtigungen machen eine vorhandene Freigabe ungültig.

## Ausgeführte native Prüfung

`tests/ebilanz-native.py --config <private-runtime.json>` berechnet synthetische
Journalwerte im echten PHP-Rechenkern, mit Docker-Netzwerk `none`. Anschließend
prüft es die **eigenen generierten** XBRL-Daten in der nativen Prüfbibliothek
`Bilanz_6_9`. Die Testfunktion erzwingt ausschließlich `ERIC_VALIDIERE` (Flags 2).
Keine Zertifikate, keine PIN, kein `ERIC_SENDE`.

Bestanden mit Code 0:

| Fall | Handelsgewinn | Steuerbilanzgewinn |
| --- | ---: | ---: |
| Dokumentierte Wertänderung | 800,00 € | 850,00 € |
| Keine Wertänderung | 800,00 € | 800,00 € |
| Verlust | −400,00 € | −400,00 € |
| Nullergebnis | 0,00 € | 0,00 € |
| Centgenauigkeit | 799,99 € | 849,99 € |
| Geschäftsjahr 2026 | 800,00 € | 850,00 € |

Diese sechs Grundfälle enthalten jeweils 521 Bilanz-/GuV-Fakten und sechs Kontennachweise.
Zusätzlich bestanden: mehrere Bankkonten, Dezember-Zugang, Vorjahresanlage,
vollständig abgeschriebene Anlage, Restwert, mehrere Anlagen auf einer Position,
Software, gemischte Anlagenarten, Cent-Anschaffung, Büroeinrichtung, alle 19
Automatik-Konten sowie die nicht steuerbegünstigte Körperschaft: insgesamt 18
positive native Testfälle. Je nach Fall entstehen 112 oder 196 Anlagen-Fakten.
Die nativen Tests lassen die Automatik arbeiten; explizit bleiben nur die
nicht automatisch zuordenbaren synthetischen Konten. Doppelte Kontext-IDs bei
mehreren Konten derselben Bilanzposition sind behoben und geprüft.
Eine nachträglich um einen Cent veränderte Bilanzsumme wird mit Code 610001002
zurückgewiesen. Der Test prüft einen synthetischen Bewertungsunterschied, nicht
die rechtliche Zulässigkeit einer bestimmten echten Steuerkorrektur.

Weitere Gegenprüfungen stehen in `balance-tax-values.php` (31) und
`ebilanz-builder-test.py` (51 Testmethoden) sowie `balance-tax-mapping.php` (25).
Die Automatik wird zusätzlich zwischen PHP und Python verglichen. Beide Jahreskataloge
werden vollständig durchlaufen: jeder Eintrag ist zugeordnet oder ausdrücklich offen.
Bestehende Bilanz-/Steuertests bleiben
unverändert anwendbar. Der Generator verändert keinerlei Originaldatei oder Datenbank.

Regression dieser Fortsetzung: alle 708 bisherigen Bilanz-/Steuerprüfungen erneut
bestanden. Die 9 Softwarehinweis- und 88 Einstellungsprüfungen stammen aus der
vorangegangenen Abnahme; in diesem Schritt wurden deren Dateien nicht verändert.
Alle sechs betroffenen PHP-Dateien sind syntaxgeprüft, alle sechs Python-Dateien
kompilieren. Der Neuaufbau der Taxonomiemetadaten reproduziert den SHA-256
`8e1d57b7e70c4b1eb48d98f2cd5efc6f0c602eca5832be6f9bd1dd308fbfbfb5`.

Beim Paketcheck fehlten im bisherigen lokalen Quellmanifest bereits vorhandene
Bilanz-/Steuer-Abhängigkeiten. Sie sind jetzt ausdrücklich aufgenommen, einschließlich
der vorhandenen Migrationen 87–93, Vorlagen und ERiC-Brückenskripte (nicht der
lizenzierten ERiC-Bibliothek). `distribution/tests/source-dependencies.py` prüft
1.953 Dateihashes und die statischen PHP-Abhängigkeiten. Frühere Manifestpfade
bleiben erhalten. Diese Quellpaketpflege installiert keine Migration und ändert
keinen produktiven Schalter; eine Neuinstallations-/Deployment-Abnahme steht aus.

## Was weiterhin fehlt


**Code 0 bei lokaler Validierung ist keine Abgabe, keine Empfangsbestätigung des
Finanzamts und keine Garantie einer materiell zutreffenden Steuererklärung.**
Die Produktion und beide GitHub-Veröffentlichungen wurden in diesem Schritt
nicht aktualisiert. Keine Änderung des produktiven Bilanzschalters.

## Freigabeablauf und tatsächlicher Testversand – 15.09.2026

Voraussetzungen für den neuen Ablauf: `VEREIN_BALANCE_WORKSPACE=1`,
`VEREIN_TAX_AUTO_TEST=synthetic-only`, Modus ungleich `production`, Vereinsabschlussart
Bilanz, Finanz-Schreibrecht und ein festgeschriebenes Jahr. Der private ERiC-Adapter
benötigt zusätzlich `balance_test_enabled: true` und ein Testprofil für `Bilanz_6_9`.
Keine dieser Einstellungen wurde produktiv gesetzt. Neue Testentwürfe übernehmen
keine echten Steuer-/Adressdaten. Die Oberfläche bietet geprüfte Kontenausnahmen,
getrennte Anfangs-/Jahres-Wertänderungen, lesbare Zahlen, Vorstandsfreigabe und
Zertifikateingabe. Unbekannte Konten und nicht unterstützte Anlagenfälle bleiben blockiert.

`balance-generate` erzeugt nur XML, ohne native Bibliothek, Zertifikat oder Netzwerk.
Die anschließende amtliche Prüfung bindet XML, Steuerwerte, Mapping, Profil und
Runtime-Dateien. Der vorhandene Sender schreibt seinen Versuch vor dem Netzaufruf
fest; gleiche Inhalte können auch mit neuer Entwurfs-ID nicht erneut gesendet werden.
Unklarer Ausgang bleibt gesperrt. Ein belegter Fehler vor Transport erlaubt nur
einen ausdrücklich neu geprüften und freigegebenen Versuch. Keine PIN-Speicherung.
Ohne ERiC-PDF steht die bestätigte Serverantwort als XML-Abgabeprotokoll bereit.

Realer Test: `tests/ebilanz-transport-native.py` erzeugte einen eigenen synthetischen
Datensatz mit Ausstattung und Software (zwei Gegenstände, 196 Anlagen-Fakten),
prüfte ihn nativ und sendete ihn einmal mit dem offiziellen Organisations-Testzertifikat
und Testmerker `700000004`. Ergebnis: Validierung **0**, Versand **0**, `sent=true`,
Transferticket **eh25856yxn68dy3jh2618oe9gexxpgp6**. Die technische Rückantwort wurde
gespeichert und ausgewertet. Nachweis: `audit/ebilanz-workflow-native-20260915.json`.
Das ist eine technische Annahmebestätigung, kein Steuerbescheid und kein Nachweis
der Zulässigkeit beliebiger Kontierungs- oder Bewertungsentscheidungen.

Der native Test ist getrennt von der UI-/MySQL-Abnahme: 54 MySQL-Workflowprüfungen
mit realem Generator und simulierter nativer Transportantwort; 8 Python-Prüfungen
der Generator-/Transportgrenzen; 42 Desktop-/Mobil-Browserprüfungen ohne Netzverkehr.
Die bereits vorhandenen 708 Bilanz-/Steuerprüfungen, 51 Generator-Testmethoden,
31 Steuerwerte- und 25 Mappingprüfungen bestehen weiterhin. Der neue Testablauf
verwendet die vorhandenen Tabellen; keine neue Migration. GitHub und Live bleiben
unverändert, das lokale Quellmanifest wird einschließlich Abhängigkeiten aktualisiert.

## Fortsetzung: Kontenfälle, echte HTTP-Abnahme und Rückmeldestatus

Stand 15.09.2026: Revision 2 der geprüften SKR42-Zuordnung enthält **27 exakte
Kontonummern** für 2025 und 2026. Hinzugekommen sind Bank 18300–18500,
aktive Rechnungsabgrenzung 19000, sonstige Rückstellungen 30700, sonstige
Verbindlichkeiten 35000, passive Rechnungsabgrenzung 39000 und Rechts-/Beratungskosten
68250. Die Zuordnung sagt nicht aus, dass eine konkrete Rückstellung steuerlich
zulässig ist: deren Ansatz und Bewertung müssen gesondert geprüft werden.
Keine erste-Ziffer-Heuristik und kein Sammelkonto für beliebige unbekannte Fälle.

Vier weitere eigene Journale bestehen die native ERiC-Prüfung mit Code 0:
Kontenfälle 2025, Kontenfälle 2026, Auflösung der Anfangsabgrenzung und dokumentierte
steuerliche Minderung einer Rückstellung. Eine manipulierte Bilanzsumme wird
weiterhin abgewiesen. Nachweis: `audit/ebilanz-accruals-native-20260915.json`.

Auf dem **tatsächlichen Webserver** (PHP **8.3.33**, SAPI **cgi-fcgi**) wurden über
HTTPS der aktuelle `TaxEricAdapter`, die Python-Brücke und die lizenzierte native
ERiC-44.3.6.0-Bibliothek aufgerufen. Alle vier neuen Fälle und der gemischte
Anlagenfall bestanden mit Code 0. Dieser Anlagenfall wurde genau einmal mit
amtlichem Testzertifikat und Testmerker 700000004 übermittelt:

- Versandcode **0**, `sent=true`, zwei Gegenstände, 196 Anlagen-Fakten.
- Transferticket **eh25813w3hjcsk6h9zorp332t1rb8xcg**.
- XML SHA-256 `21a69a5f3e84ceb4790b4277e8d148c22d7fc7bfff2f6245a9f3f865c840dce9`.
- Antwort SHA-256 `0ff3fd3708038321aa12d4b5d0b0781fb9d9a2f009fc3af819abc3fa398f74bb`.
- Nachweis: `audit/ebilanz-webserver-20260915.json`.

Es wurden ausschließlich erfundene Daten verwendet. Kein Produktions-Bootstrap,
keine Datenbankverbindung, keine echte Steuererklärung. Der vorübergehende,
zusätzlich per geheimem Header geschützte Testendpunkt wies unberechtigte Aufrufe
ab. Testendpunkt und privates Testverzeichnis wurden anschließend entfernt;
SHA-256-Kontrollen bestätigen unveränderte Website-/Manager-Einstiege, Release-
Konfiguration und produktive ERiC-Konfiguration. Die Echtabgabe bleibt gesperrt.
Der erste Durchlauf validierte bereits erfolgreich, stoppte aber vor jedem Versand,
weil das Testskript nur `fpm-fcgi` statt zusätzlich `cgi-fcgi` akzeptierte.
Nach Korrektur dieser Testannahme erfolgte der hier dokumentierte einzige Versand.

### Spätere Rückmeldungen: keine erfundene Erfolgsbestätigung

Amtliche Quelle: **EBilanzRueckmeldung-SSb-V1.1.pdf**, 30.10.2025, Abschnitt 2,
aus ERiC 44.3.6.0 / Dokumentation / Datenarten / ElsterDatenabholung /
DatenartBereitstellung / EBilanzRueckmeldung / Dokumentation.

Diese Datenart liefert nach einer Änderung durch die Finanzverwaltung geänderte
Steuerwerte zurück, bei unveränderter Übernahme gerade **keine** Rückmeldung.
Voraussetzung ist unter anderem DIVA-Stufe-2-Einwilligung. Erklärungen mit
steuerlicher Gewinnermittlung für wirtschaftliche Geschäftsbetriebe/BgA sind
ausdrücklich ausgeschlossen. Unser Generator erzeugt genau diese SGEB-Darstellung.
Die Rückgabe enthält außerdem keine Kontensalden, Anlagenspiegel oder Anlagenlisten.

`BalanceTaxFeedback` prüft die hashgebundene XML-Darstellung, trennt technischen
Eingang von steuerlicher Entscheidung und zeigt den späteren Abruf deshalb als
nicht anwendbar. Unbekannte Profile werden nicht automatisch als abrufbar eingestuft.
Keine fehlende Rückmeldung wird zu „steuerlich akzeptiert“. Der PDF-Anhangdienst
`AnhangRueckmeldung` ist eine andere Datenart und bleibt davon getrennt.

`TaxFilings::attempts()` bestätigt einen technischen Eingang nur bei passendem
Auftrags-/Antwort-XML-Hash, Umgebung, Versandstatus, Antwortcode und Transferticket.
Die Oberfläche zeigt bei fehlender oder widersprüchlicher Bestätigung keinen Erfolg.
Prüfungen: 62 MySQL-Workflowchecks, 17 Rückmelderegeln, 42 Desktop-/Mobilchecks,
51 Generator-Testmethoden, 8 Brückentests, 25 Katalogprüfungen sowie erneut die
708 bisherigen Bilanz-/Steuerprüfungen. Keine Schemaänderung. Lokale Quellpakete
werden aktualisiert; keine Veröffentlichung auf GitHub oder Freischaltung auf Live.

## Isolierte MySQL-/HTTP-Gesamtkette und Wiederherstellung

15.09.2026, 22:20 Uhr: Mit ausdrücklicher Zustimmung wurde die bereits belegte
Zweitdatenbank zuerst verschlüsselt gesichert, anschließend für einen synthetischen
Test neu aufgebaut und danach vollständig wiederhergestellt. Keine Verbindung zur
Produktivdatenbank. Vor und nach dem Test stimmten Dateninhalte, Tabellenschemata
und Trigger überein: **150 Tabellen, 1.393 Datensätze**. Die Sicherung wurde direkt
verschlüsselt geschrieben und authentifiziert. Eine temporäre Klartextdatei gab es
nur für die Wiederherstellung im privaten Serververzeichnis; sie wurde entfernt.
Weder Datenbankinhalte noch Sicherungsschlüssel wurden auf den PC heruntergeladen.

Der zusätzliche zufällige HTTPS-Endpunkt nutzte echte `BalanceActions.php` und
`TaxFilingActions.php`, die tatsächlichen Ansichten, einen isolierten Cookie-/CSRF-
Sitzungsspeicher und ausschließlich synthetische Rollen. Geprüft wurden:

- Fehlender Endpunktzugang und fehlendes CSRF-Token abgewiesen.
- Mitglied ohne Schreibrecht sowie fremder Verein abgewiesen.
- Konteneinrichtung mit exakt geprüftem SKR42; manipulierte Bereichsauswahl wirkungslos.
- Anfangsbestände, Ertrag, Aufwand, aktive Abgrenzung und Rückstellung über HTTP.
- Abschluss nur mit Vorstandsrecht, steuerliche Wertänderung mit erneuter Prüfung.
- Tatsächlich erzeugte XML durch native ERiC-44.3.6.0-Prüfung bestätigt.
- Versand vor Freigabe ohne Versandversuch abgewiesen; danach tatsächlicher
  Multipart-Upload des amtlichen Testzertifikats und genau eine Testübermittlung.
- Antwort an XML-Hash/Umgebung/Ticket gebunden; Protokoll abrufbar; Doppelversand
  abgewiesen; geschlossenes Bilanzjournal unverändert.

Ergebnis: **sent_test**, Validierung **0**, Ticket
`eh258775s7ua9r6gf7z8cic7a4vbhqqv`, XML SHA-256
`6f7a91784432a54a5f51d5d43a40def438405020f8999f447149406443d05ddb`.
Dieser konkrete Gesamtkettentest enthält keine Gegenstände (521 XBRL-Fakten).
Der Anlagen-Test mit 196 Anlagen-Fakten bleibt der separat oben dokumentierte
native Webserver-Test. Es wird nicht behauptet, dass hier zusätzlich Anlagenabgänge
oder Sonderabschreibungen durch den gesamten HTTP-Ablauf geprüft wurden.

Ein erster Aufbau scheiterte an einer im Testpaket fehlenden PHP-Migrationsdatei;
ein weiterer an einer bereits belegten synthetischen Kontokennung. Beide wurden
vor jedem Versand beendet und die Zweitdatenbank jeweils identisch wiederhergestellt.
Die Testpaketfehler wurden behoben. Keine echten Steuerabgaben, E-Mails oder Bankaufträge.

Zusätzlich **42** Prüfungen der tatsächlich vom Webserver gerenderten Ansichten
bei 1280/390px, Netzwerk dabei gesperrt, Screenshots visuell kontrolliert. Lokal:
708 bestehende Prüfungen erneut bestanden, 8 Konteneinrichtungs-, 30 Mapping-,
62 E-Bilanz-Ablauf- und 17 Rückmeldestatusprüfungen bestanden.
Nachweis: `audit/ebilanz-http-mysql-20260915.json`.

Testendpunkt und privates Staging entfernt; produktive Einstiegspunkte, Runtime-
und ERiC-Konfiguration per Hash unverändert. Website und Manager jeweils HTTP 200.
Die neue Kontenauswahl ist lokal implementiert, nicht produktiv veröffentlicht.
Die fachlichen Begrenzungen in Punkten 1–5 bestehen weiterhin; dieser technische
Nachweis allein erteilt keine allgemeine Echtabgabe- oder Sonderfallfreigabe.

## Fortsetzung: Konten, GWG und steuerlicher Vortrag (Revision 3)

Diese Fortsetzung verändert ausschließlich lokalen Quellcode und synthetische Tests.
Keine Veröffentlichung, Datenbankmigration oder Steuerübermittlung. Die vorherige
serverseitige Abnahme gilt nicht automatisch für diese neue Revision.

- Exakte Jahresregeln für Vorräte, sonstiges Umlaufvermögen, weitere Banken,
  Bankverbindlichkeiten, Werbung, Öffentlichkeitsarbeit, Miete/Pacht, Energie,
  Wartung und Fortbildung ergänzt. Beide Implementierungen weisen reservierte,
  widersprüchliche und doppelte Regelkonten zurück.
- 63200 und 63250 sind nur 2026 automatisch verfügbar, da diese Einträge im
  geprüften 2025-Import fehlen. Der Originalkatalog wird nicht stillschweigend
  ergänzt. Keine automatische Ersatzkontierung auf ein beliebiges Sonstiges-Konto.
- GWG nach [§ 6 Abs. 2 EStG](https://www.gesetze-im-internet.de/estg/__6.html):
  explizite Wahl, beweglicher eigenständig nutzbarer Einzelgegenstand, Nettogrenze
  800 Euro; enthaltene und abziehbare Umsatzsteuer getrennt nachgewiesen.
  Ein Bestand von mehreren Stücken kann nicht als ein GWG gespeichert werden.
  Die unterstützte Kostenprüfung umfasst enthaltene Umsatzsteuer bis 19 Prozent;
  andere Steuerkonstellationen erfordern gesonderte Behandlung.
- Der Verzicht auf einen Sammelposten im Anschaffungsjahr wird ausdrücklich
  bestätigt und im unveränderlichen Plan/Jahresstand gespeichert. Es wird kein
  Sammelposten automatisch aufgelöst. Andere Buchführungssysteme kann die Anwendung
  nicht auf dort ausgeübte Wahlrechte prüfen.
- Einzelne Rechnung, Netto-/Steuerbetrag und Plan müssen übereinstimmen. Der volle
  Betrag wird im Anschaffungsjahr abgeschrieben (06700/62600), nicht erneut im
  Folgejahr. Bruttokosten und kumulierte Abschreibung bleiben im Anlagenverzeichnis.
- Steuerliche Anfangsdifferenzen werden aus einem ausdrücklich ausgewählten,
  bestätigten Vorjahresauftrag desselben Vereins, derselben Steuernummer und
  desselben Geschäftsbetriebs übernommen. Der Handelsvortrag muss exakt passen.
  Die frühere Gewinndifferenz geht ins Eigenkapital, nicht nochmals in den Gewinn.
  Kopplung an Abschluss- und Steuerwertehash, historische Freigabe und echten
  technischen Eingangsbeleg; Doppelübernahme, entfernte Zeilen und geänderte Quellen
  sind gesperrt. Der Folgeentwurf muss danach erneut geprüft werden.
- Der automatische Vortrag ist derzeit 2025→2026 geprüft. Bei älteren externen
  Abschlüssen bleiben Anfangswertkorrekturen mit Nachweisen manuell zu prüfen.
  Ein Vorstandswechsel hebt eine bereits übermittelte historische Freigabe nicht
  auf; neue Übermittlungen benötigen weiterhin aktuelle Berechtigungen.

Prüfungen: `asset-gwg.php`, `balance-tax-carry.php`, `balance-tax-values.php`,
57 Generator-Testmethoden sowie 20 GWG-Browserprüfungen (1280/390px, kein Netzwerk).
Der native Prüflauf `ebilanz-native.py --reviewed-codes` validiert jede
unbedingte Kontenregel pro zulässigem Jahr separat sowie GWG- und Vortragsfälle.
Ergebnis: **117 gültige Fälle mit Code 0**, eine gezielt falsche Bilanzsumme
mit Code 610001002 abgewiesen; kein Versand. Zusätzlich bestanden 83 kombinierte
Quellen-/GWG- und 78 kombinierte E-Bilanz-Ablauf-/Vortragsprüfungen auf MySQL.
Nachweis: `audit/ebilanz-reviewed-coverage-20260915.json`. Ausschließlich Flags 2,
keine Zertifikate und kein Versand. Ein absichtlich beschädigter Summenwert muss
weiterhin abgewiesen werden. Die 708 bisherigen Bilanz-/Steuerchecks bestehen.

**Weiterhin nicht vollständig:** allgemeine SKR42-Abdeckung, Anlagenabgänge und
Umbuchungen, Sammelposten, degressive/Sonderabschreibung, steuerlich abweichende
Anlagenwerte sowie formularübergreifende Sonderfälle. Diese Fälle bleiben gesperrt
und werden nicht durch Code 0 einer anderen Testkonstellation freigegeben.

## Weitere Entwicklung am 15.09.2026: Kontenzugang und degressive AfA

Lokal umgesetzt, **noch nicht live veröffentlicht**. Keine Produktivdatenbank,
Zertifikate oder echten Übermittlungen verwendet.

- Buchbare Jahreskonten und automatische E-Bilanz-Regeln sind jetzt getrennt:
  1.666 Konten für 2025 bzw. 1.679 für 2026 sind im Bilanzjournal auswählbar.
  Reservierte, mehrdeutige und statistische Vortragskonten bleiben ausgeschlossen.
  Bei Konten ohne geprüfte Regel muss die Buchhaltung den Bilanzbereich ausdrücklich
  auswählen. Diese Auswahl erteilt **keine** automatische E-Bilanz-Zuordnung;
  die bisher geprüften 62/64 Regelkonten sind unverändert.
- Der Generator weist auch manuell zugeordnete, unbekannte/reservierte Konten und
  Widersprüche zu einer bereits geprüften Kontenart ab. Jahreskatalog und seine
  Quellenprüfsummen bleiben verbindlich.
- Degressive AfA nach [§ 7 Abs. 2 und 3 EStG](https://www.gesetze-im-internet.de/estg/__7.html)
  für ausdrücklich bestätigte bewegliche Ausstattung (06300/06500), Anschaffung
  Juli 2025 bis Ende 2026, Restwert null und identische Buch-/Steuerwerte. Satz in
  ganzzahligen Basispunkten, höchstens 30 Prozent und das Dreifache des linearen
  Satzes; zeitanteiliges Anschaffungsjahr. Optional ausdrücklich gewählter Wechsel
  auf die höhere lineare Jahresabschreibung über die Restnutzungsdauer.
  Keine stillschweigende Wahl, kein Rückwechsel, keine Gebäude-/Software- oder
  Sonderabschreibungsableitung. Handelsrechtliche Eignung muss bestätigt sein.
- Rechnung, Plan, Jahresstand, Bilanzquelle und amtlicher Brutto-Anlagenspiegel
  verwenden denselben unveränderlichen Nachweis. PHP berechnet, Python prüft
  unabhängig nach. Eine künftige gesetzliche Regel/Edition wird nicht freigeschaltet.
- Planbearbeitung und Jahresabschluss werden mit derselben Vereinssperre wie die
  Bilanzübernahme serialisiert. Zwei konkurrierende Plan-/Jahresänderungen können
  nicht gemeinsam einen widersprüchlichen Stand erzeugen. Beschädigte gespeicherte
  Methoden/Pläne werden nicht ersatzweise linear gerechnet.
- Die Freigabe-Prüfsumme umfasst die neuen Berechnungsmodule. Fehlende Dateien
  sperren die Prüfung; Python-Importabhängigkeiten werden auch im
  Installationsmanifest geprüft. Kein öffentlich sichtbarer Entwicklungsbericht.

Nachweise dieses Schritts:

- `audit/ebilanz-degressive-native-20260915.json`: fünf eigene Anlagenfälle mit
  nativem ERiC-Code 0, manipulierte Bilanzsumme zurückgewiesen; Flags 2, **kein Versand**.
- `asset-degressive.php`: 82 kombinierte Quellen-/Degressivprüfungen auf MySQL,
  davon 25 neue Prüfungen; `asset-plan-concurrency.php`: drei konkurrierende
  Schreibkonstellationen, jeweils nur ein erfolgreicher Vorgang.
- `ebilanz-degressive-test.py`: zehn zusätzliche Generator-Testmethoden;
  die 57 bisherigen Generator-Testmethoden bestanden erneut.
- `asset-gwg-browser.cjs`: 32 Prüfungen für GWG und degressive Eingabefelder bei
  1280/390 px, keine Netzwerkzugriffe oder Formularübermittlungen.
- Bestehende Bilanz-, Quellen-, GWG-, Steuerwert-, Vortrags-, Freigabe-,
  Rückmeldungs- und MySQL-Wiederherstellungsprüfungen weiter erfolgreich.

### Noch keine vollständige Freigabe

Die größere Kontenauswahl ist **keine vollständige fachliche Kontenabdeckung**.
Noch fehlen insbesondere weitere fachlich geprüfte Taxonomiezuordnungen,
Anlagenabgänge/Umbuchungen, Sammelposten, Sonderabschreibungen, getrennte steuerliche
Anlagenwerte und weitere formularübergreifende Sonderfälle. Das Generatorprofil
bleibt ein gesondert bilanzierter Geschäftsbetrieb im Kalenderjahr 2025/2026,
nicht beliebige Vereinsbilanzen oder mehrere Geschäftsbetriebe.

Die vollständige Veröffentlichung wurde vom Auftraggeber erst **nach Fertigstellung**
verlangt. Deshalb keine Änderung der Live-Schalter, keine produktive Echtabgabe
und kein unvollständiger Stand als fertige Veröffentlichung. Vor einer späteren
Freigabe sind die verbleibenden Fälle, der dann vollständige Stand in der
isolierten HTTP-/MySQL-Webserverprobe und die verschlüsselte Rückfallsicherung
erneut nachzuweisen. Frühere Testtickets gelten nicht als Abnahme neuer Funktionen.
