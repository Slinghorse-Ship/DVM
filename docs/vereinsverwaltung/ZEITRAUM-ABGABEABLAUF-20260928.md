# Datierter Abgabeablauf – 28.09.2026

Dieser Stand verbindet den bereits geprüften Generator für Gesamtbilanz und
steuerliche Nebenrechnung mit Speicherung, Oberfläche, Prüfung, Vorstandsfreigabe,
Korrekturart und Übermittlungsprotokoll. Er ist **kein vollständiger Bilanzrelease**.
Kulturhus bleibt EÜR. Die produktiven Schalter bleiben unverändert geschlossen.

## Implementierte Verbindung

- Ein Entwurf übernimmt ausschließlich den aktuell geprüften Zeitraum einschließlich
  unveränderlicher Bereichs-, Steuer- und Vortragsnachweise. Beginn und Ende werden
  gespeichert und geprüft, nicht aus der Jahreszahl geraten.
- `createPeriod()` ist vom Kalenderweg getrennt. Entfernte oder geänderte Datums-
  oder Prüfstandsangaben können nicht auf den alten Kalenderweg ausweichen.
- Originalbilanz, GuV und Anlagen bleiben unverändert. Steuerliche Wertänderungen
  stammen aus der bestätigten Nebenrechnung; eine zweite parallele Werterfassung
  ist nicht möglich.
- Zwei Abschlüsse mit demselben Endjahr bleiben getrennte Abgabeverpflichtungen.
  Ein ausdrücklich datierter Kalenderzeitraum erzeugt dagegen keine neue Identität
  neben derselben früheren Kalenderabgabe.
- Eine Korrektur verweist auf das letzte eindeutig bestätigte Protokoll desselben
  Zeitraums. Alte XML-Dateien, Freigaben und Protokolle bleiben erhalten. Bereits
  abgegebene Ansichten zeigen nach einer Korrektur weiter ihre ursprünglichen Zahlen.
- Fehlende Vorstandsfreigaben, geänderte Quellen, unklare Übermittlungen, veraltete
  Formularversionen und fremde Vereine bleiben gesperrt. Eine fehlende Antwort löst
  keinen automatischen zweiten Sendeversuch aus.
- Übersicht, Bilanzansicht und Rücksprung zur Steuerprüfung verwenden die echten
  Datumsgrenzen. Datierte Anlagen benötigen weder alte flache Jahresfelder noch
  eine erfundene Belegnummer. Kontenzuordnung und Verkaufsvorschau verwenden die
  Bewegungen des jeweiligen Zeitraums, nicht nochmals die Verkäufe des Vorzeitraums.
- Ein bestätigter Kalender-Vorabschluss kann jetzt in die datierte Originalbilanz
  mit Nebenrechnung übergehen. Sein ursprünglicher Prüfstand und Hash bleiben
  erhalten; der Adapter rekonstruiert nur den benötigten Anlagenvortrag. PHP und
  Python rechnen Buch- und Steuerpläne unabhängig nach. Alte Wertunterschiede
  gelangen in die Anfangswerte, nicht erneut in das laufende Ergebnis.
- Zusätzlich ist die datierte vollständige Bilanz des **nicht steuerbegünstigten**
  Vereins mit dem Abgabeablauf verbunden. Kapitalbewegungen bleiben von Erträgen
  getrennt; unbekannte Kapitalbewegungen sind bearbeitbare Entwürfe, keine Freigabe.
  Originaljournal, Anlagen und sämtliche Bereiche werden unverändert übernommen.
- Dessen kurzer Folgezeitraum übernimmt die letzte eindeutige datierte Abgabe,
  auch bei gleichem Endjahr. Ersetzte Abgaben erscheinen nicht als Vortragswahl.
  Die Übernahme bindet Identität, Steuerwerte, Anlagenpläne und Protokoll. Fehlender
  Vortrag erzeugt keine scheinbar vollständige Null-Vorschau.
- Auch der Kalender-Vorabschluss dieses **nicht steuerbegünstigten** Profils ist
  angebunden: Die bestätigte Originalbewertung behält ihren Hash und ihre echten
  Datumsgrenzen. Anlagen- und Steuerpläne werden unabhängig rekonstruiert. Gewinne,
  Kapitalzuführungen und Steuerwertunterschiede des Vorjahres gehen nur in die
  Anfangswerte ein, nicht nochmals in das laufende Ergebnis.
- Bei einer vollständig steuerpflichtigen Körperschaft können bestätigte
  abweichende Steuer-Nutzungsdauern auch Anlagen mit anderen ursprünglichen
  Bereichskennzeichnungen betreffen. Diese ausdrückliche Gesamtvereinsausgabe
  ist weder ein Sphärenwechsel noch eine Freigabe solcher Pläne für steuerbegünstigte
  Teilbereiche. Dort bleibt die bisherige Begrenzung erhalten.

## Prüfverfahren

- `balance-period-filing-workflow.php`: persistierter MySQL-Ablauf einschließlich
  Erstabgabe, Berichtigung, kurzem Folgezeitraum im selben Jahr, unverändertem
  Protokoll, historischen Ansichten, Rollen und unbekanntem Übermittlungsergebnis.
- `balance-period-filing-workflow-assets.php`: echte synthetische Rechnungen,
  Anlagenpläne, gemeinsame Nutzung, datierter Nutzungswechsel innerhalb einer
  Sphäre und Folgezeitraum bis in Generator, Freigabe und gerenderte Ansicht.
- `balance-period-tax-mapping.php`: 66 Rechen- und Anzeigeprüfungen für datierte
  AfA, Software, Verkäufe, Ausmusterung und nicht wiederholte Folgeperiodenbewegungen.
- `balance-period-filing-calendar.php` und `ebilanz-period-calendar-test.py`:
  unveränderte Kalenderbelege, abweichende Steuer-Nutzungsdauer, tatsächliche
  Vorjahresdaten und zurückgewiesene Veränderungen am Vortragsnachweis.
- `balance-period-filing-calendar-workflow.php`: Kalenderabschluss → gespeicherte
  datierte Erklärung → getrennte Freigabe → simuliertes Protokoll; spätere
  Kalenderkorrekturen sperren neue Entwürfe, ohne historische Protokolle zu ändern.
- `balance-period-whole-workflow.php`: vollständiger nicht steuerbegünstigter
  Verein, Kapitalvergleich, Korrektur, tatsächlicher Folgezeitraum, zuletzt
  bestätigte Steuerwerte statt ersetzter Erklärung, Historie nach Journaländerung,
  getrennte Rollen und abgeschaltete Zeitraum-/Produktionsumgebung.
- `balance-period-whole-assets.php` und `balance-period-whole-values.php`: datierte
  Anlagen im gespeicherten Gesamtvereinsablauf; getrennte Steuerwerte und
  ursprüngliche Metadaten in der Zuordnungsanzeige, lesbare Anlagenpläne,
  Quellenbindung und Kapitalvortrag.
- `balance-period-whole-calendar-workflow.php`: gespeicherter Kalender-Gesamtverein
  mit ursprünglichem Prüfstand, datiertem Vortrag, Freigabe, Korrektur und unveränderter
  Historie. Der Prüfweg für separate Geschäftsbetriebe wird nicht aufgeweicht.
- `balance-period-whole-calendar-assets.php`: sechs echte synthetische
  Rechnung-/Anlagenketten durch Kalenderabschluss und zwei kurze Folgezeiträume,
  einschließlich abweichender Steuer-Nutzungsdauer außerhalb der ursprünglichen
  Sphäre 4, unveränderter Altpläne und gesperrter Löschung übernommener Steuerpläne.
- `balance-period-whole-calendar.php` und `ebilanz-period-whole-calendar-test.py`:
  voneinander unabhängige PHP-/Python-Rechnung für Originalwerte, Steuerwerte,
  Kapitalvergleich, Anlagenanzeige und manipulierte Vortragsnachweise. Historische
  Kalenderpläne enthielten keine Textnotiz des Anschaffungsplans; beim Übergang
  werden deren vollständige Bewertungsfelder verglichen. Datierte Folgepläne
  bleiben weiterhin insgesamt identisch gebunden.
- Bestehende Kalender-, Korrektur-, Nebenrechnungs-, Gesamtvereins-, Mapping-,
  Berechtigungs- und Fingerprintprüfungen werden mit ausgeführt.
- `ebilanz-workflow-native.py` prüft die **unveränderten XML-Dateien aus dem
  gespeicherten Webserver-Testablauf** mit nativem ERiC, ausschließlich Flag 2.
  Ein Testzertifikat oder eine Übermittlung wird dabei nicht benutzt.

Die MySQL-Prüfung nutzt ausschließlich die dauerhaft freigegebene synthetische
Zweitdatenbank. Transportantworten im PHP-Ablauftest sind simuliert. Native
Validierung und simulierter Transport sind ausdrücklich kein echter ELSTER-
Testversand und keine fachliche oder produktive Gesamtabnahme.

## Durchgeführte Prüfungen

Stand 28.09.2026, Quellenpaket
`2c1bcd6a3c52a9d66c70577f70f74f58e446a09efbd326710cf9d54b02d02065`:

- Alle 15 MySQL-Testgruppen auf dem Webserver erfolgreich. Darunter 61 Prüfungen
  des datierten Ablaufs, 28 des Kalenderübergangs einschließlich Vorprüfungen,
  12 des Anlagenablaufs zuzüglich 37/96 zugrunde liegender Nutzungs-/Bereichsprüfungen,
  66 Kontenzuordnungsprüfungen und die bestehenden Kalender-/Freigaberegressionen.
- Alle 26 Python-Regressionsprogramme erfolgreich. Die Abhängigkeitsprüfung erfasst
  86 Laufzeitdateien einschließlich 18 Python-Modulen; 174 Fingerprintprüfungen
  und 17 Rückmeldungsklassifikationsprüfungen erfolgreich.
- 26 datierte Generatorfälle mit nativem ERiC erfolgreich (Code 0).
- Zusätzlich alle sechs unveränderten XML-Dateien aus dem gespeicherten MySQL-
  Ablauf mit nativem ERiC erfolgreich: Erstabgabe, Korrektur, kurzer Folgezeitraum,
  Anlagen-Erstabgabe und -Folgezeitraum sowie Kalenderübergang. Nur Validierung,
  **kein tatsächlicher Versand**.
- Gerenderte datierte Ansichten mit Anlagen und Freigaben geprüft; ergänzende
  begrenzte Browser-Sichtprüfung. Kein vollständiger Handy-/Rollen-/Nutzertest.
- Keine Verbindung zur produktiven Datenbank, kein öffentlicher Testendpunkt,
  geprüfte produktive Einstiegdateien unverändert. Temporäre Serverdateien entfernt;
  synthetische Testdatenbank wie vereinbart behalten. Kulturhus nicht umgestellt.

Lokale, nicht veröffentlichte Nachweise unter `audit/`:
`ebilanz-mysql-host-20260918-ff637c7d2bb330e3.json`,
`ebilanz-period-native-calendar-20260928.json` und
`ebilanz-period-workflow-native-20260928.json`.

### Ergänzende Abnahme des datierten nicht steuerbegünstigten Gesamtvereins

Webserver-Quellenpaket
`3e4295ee0a5296e5fe46cf6514275b4129b75fdcb2468b3fb6c426bee6115a42`:

- Sechs abschließende Testgruppen erfolgreich, darunter 32 neue persistierte
  Gesamtvereinsprüfungen zuzüglich 61 Nebenrechnungsprüfungen, neun neue
  Gesamtvereins-Anlagenprüfungen zuzüglich der bestehenden Anlagen-/Nutzungskette,
  29 reine Rechen-/Darstellungsprüfungen, 28 Kalenderübergangsprüfungen,
  117 bestehende Vereins-/Bilanzprüfungen und 176 Fingerprintprüfungen.
- Im vorherigen breiteren Lauf bestanden 15 von 16 Gruppen. Der einzelne Fehler
  betraf die Anlagenanzeige: Die Steuerpläne enthielten keine ursprünglichen
  Kontenmetadaten. Diese werden nun mit dem nachgewiesenen Steuer-Rechenstand
  verbunden; der abschließende Anlagenablauf und die lokalen Negativprüfungen
  bestätigen die Korrektur. Keine Buchwerte wurden dafür überschrieben.
- Alle 26 Python-Regressionsprogramme erneut erfolgreich. Fingerprint umfasst
  jetzt 87 Laufzeitdateien und weiterhin 18 Python-Module.
- Alle **elf** tatsächlichen XML-Dateien aus dem gespeicherten MySQL-Ablauf
  bestanden nativ ERiC (Code 0, ausschließlich Flag 2, kein Versand). Die fünf
  zusätzlichen Fälle sind vollständige Erstabgabe, Korrektur, kurzer Folgezeitraum
  sowie Gesamtverein mit Anlagen in Erst- und Folgezeitraum.
- Anschaffungskosten in der Anzeige werden aus dem unveränderten Originalplan
  genommen, nicht aus dem nach einem Abgang verminderten Endbestand. Diese letzte
  reine Anzeigeanpassung wurde nach dem Webserver-Paket lokal auf Syntax geprüft.
- Produktive Datenbank nicht verbunden, produktive Einstiegdateien unverändert,
  temporäre Serverdateien entfernt. Keine neue reguläre Sicherung der dauerhaft
  freigegebenen synthetischen Testdatenbank. Keine EÜR-Umstellung.

Nachweise: `audit/ebilanz-mysql-host-20260918-b99211f0ac6bce3e.json` und
`audit/ebilanz-period-workflow-native-whole-20260928.json`.
Der vorherige Lauf einschließlich des gefundenen und anschließend behobenen
Anzeigefehlers bleibt unter
`audit/ebilanz-mysql-host-20260918-ff717151ff0675af.json` nachvollziehbar.

Auch diese ergänzende Abnahme ist weder ein echter Testversand noch eine
Gesamtabnahme aller steuerlichen Sonderfälle oder ein vollständiger Nutzertest.

### Kalender-Vorabschluss des nicht steuerbegünstigten Gesamtvereins

Abschließendes Webserver-Quellenpaket
`796560c6510e1cf0c1c023ccbd4e8639fc5b9ba8a092b5d6b11fb3569184f17a`:

- Alle acht MySQL-Testgruppen erfolgreich: 27 neue gespeicherte Kalender-/
  Korrekturprüfungen zuzüglich 28 bestehender Prüfungen, 18 neue Anlagenprüfungen
  zuzüglich 22 Anlagenvortrags- und 43 Quellprüfungen, 52 reine Rechen-/
  Anzeigeprüfungen sowie die bestehenden datierten, Kalender-, Gesamtvereins-
  und Fingerprint-Regressionsgruppen.
- Alle 27 Python-Regressionsprogramme erfolgreich; darunter unabhängige
  Kalenderrekonstruktion und zehn erneut versiegelte manipulierte Vortragsfälle.
- Alle 547 PHP-Dateien in Laufzeit, Ansichten, öffentlichem Einstieg und
  Migrationen ohne Syntaxfehler. Das ist keine Aussage über eine vollständige
  fachliche Prüfung jeder Laufzeitfunktion.
- Vier unveränderte XML-Dateien aus dem gespeicherten Webserver-Ablauf bestehen
  die native ERiC-Prüfung mit Code 0: Kalenderübergang, Korrektur sowie
  Anlagenübergang und dessen kurzer Folgezeitraum. Ausschließlich Flag 2,
  kein Zertifikat und kein Übermittlungsversuch.
- Im vorherigen Anlagenlauf fiel eine Abweichung bei historischen Plan-Textnotizen
  auf. Der reine Negativ-/Positivtest reproduziert sie jetzt; die vollständigen
  finanziellen Planfelder bleiben gebunden. Zusätzlich wurde der Offline-
  Testadapter korrigiert: Er reicht originale JSON-Bytes weiter, statt leere
  Mapping-Objekte versehentlich in Listen umzuwandeln. Die Produktivschnittstelle
  benötigte für diesen Testadapterfehler keine Änderung.
- Produktive Datenbank nicht verbunden, produktive Einstiegdateien unverändert,
  temporäre Serverdateien entfernt. Die dauerhaft freigegebene synthetische
  Datenbank bleibt bestehen, ohne zusätzliche Sicherung oder Rücksicherung.
  Kulturhus bleibt EÜR; keine Veröffentlichung und keine Echtabgabe-Freischaltung.

Nachweise: `audit/ebilanz-mysql-host-20260918-41774592cf5e5d10.json` und
`audit/ebilanz-period-workflow-native-whole-calendar-20260928.json`.
Der vorherige fehlgeschlagene Anlagenlauf bleibt unter
`audit/ebilanz-mysql-host-20260918-735d8896d7aacf58.json` erhalten.
Diese gezielte Abnahme ersetzt weiterhin nicht die offenen Profil-, Sphärenwechsel-,
Sonderfall-, Übermittlungs- und Gesamtprüfungen.

## Verbleibende Release-Bedingungen nach Codeabgleich

| Bereich | Tatsächliche Grenze |
| --- | --- |
| Datierter Gesamtverein | Originalbilanz mit steuerlicher Nebenrechnung sowie nicht steuerbegünstigter Gesamtverein einschließlich ihrer Kalender-Vorabschlüsse und datierten Folgeabschlüsse an den Abgabeablauf angebunden. Auch [ein tatsächlich separat geführtes Betriebsjournal](ZEITRAUM-BETRIEBSABSCHLUSS-20260928.md) ist einschließlich Kalender-/Zeitraumvortrag, Anlagen, Korrektur und Freigabe angeschlossen. Die [gemeinsame datierte Teilbilanz aller steuerpflichtigen Betriebe](ZEITRAUM-BETRIEBSAUFTEILUNG-20260928.md) ist einschließlich ursprünglicher Anlagen, geänderter interner Nutzung, getrennter Steuerpläne, Korrekturen und datiertem Vortrag angeschlossen. Neu sind auch [der ursprüngliche Kalender-Vorabschluss und die kombinierte datierte Ausgabe mit Gesamt-B/GuV](ZEITRAUM-GESAMTBILANZ-KALENDER-20260928.md) einschließlich tatsächlicher Anlagen und abhängiger Vorjahreskorrekturen verbunden. Die Einzelheiten und getrennten Abnahmenachweise stehen dort. Das schließt die in den folgenden Zeilen genannten allgemeinen Release-Bedingungen nicht ein. |
| Echte steuerliche Sphärenwechsel | [Beleggebundene Wechsel ganzer linearer Anlagen](ZEITRAUM-SPHAERENWECHSEL-20260928.md), [Bereichsvermögen und interne Kapitalgegenbuchungen](ZEITRAUM-SPHAEREN-BEREICHSBILANZEN-20260928.md), [Steuerüberleitung und unabhängige XML-Rekonstruktion](ZEITRAUM-SPHAEREN-STEUERXML-20260928.md) sowie [anlagenbezogene außerbilanzielle AfA-Korrekturen](ZEITRAUM-ANLAGEN-STEUERKORREKTUREN-20260928.md) für Original-Gesamt-B/GuV mit Nebenrechnung sind angebunden. Andere Methoden, frühere abweichende Steuerpläne derselben Anlage, Wechsel mit Abgängen, anteilige/taggenaue Wechsel und die Übernahme in andere Betriebsprofile bleiben offen und gesperrt. Native Validierung ersetzt keinen Testversand. |
| Konten-/Sonderfallabdeckung | Regelstand 12 enthält für 2025 und 2026 jeweils 290 ausdrücklich geprüfte Kontencodes: 262 ohne Zusatzbedingung, 28 mit Detail-/Abstimmungsbedingung. Die angebotenen buchbaren Jahreskonten sind deutlich zahlreicher (2025: 1.689; 2026: 1.679). Vollständiger Katalog ist nicht vollständige automatische Taxonomiezuordnung. Weitere Anlagen-/Steuersonderfälle sind gesondert zu entwickeln und zu prüfen. |
| Übermittlungsabnahme | Endgültigen Gesamtstand mit den zutreffenden Profilen tatsächlich synthetisch vom Webserver testen; technische Eingangsnachweise und gegebenenfalls vorgesehene spätere Rückmeldungen getrennt bewerten. Keine Rückmeldung als stillschweigende steuerliche Anerkennung auslegen. |
| Veröffentlichung und Gesamtprüfung | Erst nach vollständiger Abnahme veröffentlichen. Anschließend die vereinbarte einmalige Desktop-/Handy-/Rollenprüfung des gesamten Managers; nicht durch einen Teilstand auslösen. |

Die genannten offenen Grenzen werden nicht durch Umbenennen des Releases,
Sperrenabschaltung, manuelle XML-Einspeisung oder erratene Kontenzuordnung ersetzt.
