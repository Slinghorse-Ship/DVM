# Laufende Verrechnungen: Berechnung, Speicherung und Prüfung

Stand 27.09.2026. **Entwicklung, keine Produktivfreigabe. Kulturhus bleibt EÜR.**

## Jetzt implementiert

Ergänzt am 27.09.2026: [Ergebnisabgleich aus Originalbuchungen](ERGEBNISABGLEICH-20260927.md),
einschließlich Freigabebindung, drei Jahren und Jahresabschluss-Export. Er trennt
die Ergebnisse aller steuerpflichtigen Betriebe von den übrigen Bereichen und
weist interne Grenzsalden ohne Gewinnwirkung aus. Steuerliche Wertanpassungen
und die Anbindung an die amtliche Übermittlung bleiben gesondert offen.

`BalanceInternalClearing` rechnet zeitweilig vorgestreckte Beträge zwischen
Bereichen desselben Vereins getrennt von endgültigen Mittelübertragungen ab.
Der Rechenkern bleibt ohne Buchungswirkung. Der getrennte Service
`BalanceClearings` speichert und prüft seine beleggebundenen Arbeitsnachweise.

- Grundlage ist ein festgeschriebener Gesamtabschluss. Seine Buchungsrechnung
  wird rekonstruiert, nicht nur der Hash verglichen. Laufende Originalzeilen
  müssen vollständig und centgenau zugeordnet sein. Die Eröffnungsaufteilung
  ist dafür nicht erforderlich: gegenläufige interne Kapitalvorträge dürfen
  nicht erneut auf positive Originalzeilen verteilt werden.
- Vorschüsse nennen zahlenden und begünstigten Bereich, Originalbeleg, Betrag,
  vereinbartes Rückzahlungsdatum und Nachweis. Mehrere zahlende und begünstigte
  Bereiche in einem Beleg sind möglich. Ihre Beziehungen werden ausdrücklich
  angegeben, nicht aus Beträgen geraten.
- Jede verwendete Buchung muss mit ihren Verrechnungen exakt abgestimmt sein.
  Der Zahlende benötigt einen zugeordneten Geldabgang. Eine unbezahlte
  Lieferantenverbindlichkeit ist kein Vorschuss.
- Teilrückzahlungen verweisen auf einen bestehenden Vorschuss und erfordern
  eine Bank-/Kassenumbuchung in umgekehrter Richtung. Neue Ausgabe, Stornierung,
  Zahlung an einen anderen Bereich und Überzahlung werden abgewiesen.
- Rückzahlung vor Vorschuss, Selbsttilgung durch denselben Beleg, künstliche
  Verrechnungskreise, wiederverwendete historische Bewegungs-/Belegkennungen
  und veränderte Ursprungsnachweise werden abgewiesen.
- Der Vortrag entsteht ausschließlich aus dem vollständigen Vorjahresnachweis
  desselben Vereins. Journalverknüpfung, Eröffnungsbilanz, Kontenidentität und
  Bereichssphären werden geprüft. Keine frei eingegebenen Anfangsbeträge.
  Ein leeres Vorjahr kann ausdrücklich nachgewiesen werden.
- Ausgeglichene Fälle bleiben mit Ursprung und Kennung erhalten. Offene Beträge,
  Teilrückzahlungen und überschrittene Termine beziehen sich auf das jeweilige
  Jahresende; keine Abhängigkeit vom heutigen Datum.
- `BalanceInternalClearingExport` liefert Entwurfsnachweise mit offenen Beträgen,
  Bewegungen und vollständigem JSON-Quellnachweis. Ganze Cent, entschärfte
  Tabellenformeln, erneut geprüfte Berechnung vor jedem Export.

Der Rechenkern verändert **keine** Bankumsätze, Originalbuchungen, Gegenstände,
Einnahmen, Ausgaben oder E-Bilanz-Werte. Es entstehen keine Forderungen oder
Verbindlichkeiten gegenüber Dritten. Das Ergebnis hat `tax_export_ready=false`.
Bestehende endgültige Mittelübertragungen bleiben unverändert und werden nicht
automatisch in rückzahlbare Vorschüsse umgedeutet.

Ein geplantes Rückzahlungsdatum ist keine automatisch berechnete steuerliche
Frist. Mittelverwendung und Verlustfinanzierung bedürfen gesonderter Prüfung;
allein ein Rückzahlungsvermerk bestätigt nicht ihre Zulässigkeit. Grundlagen:
[§ 55 AO](https://www.gesetze-im-internet.de/ao_1977/__55.html) und
[AEAO zu § 55](https://stberh.bundesfinanzministerium.de/ao/2025/Abgabenordnung/Zweiter-Teil/Dritter-Abschnitt/Paragraf-55/ae-55.html).

## Speicher- und Bedienablauf ergänzt

- MySQL-Migration 99: vereins-/jahresgebundene Revisionskette mit nativen
  UPDATE-/DELETE-Sperren. Jede Änderung erzeugt einen neuen Stand samt Audit
  innerhalb derselben Transaktion; gesperrtes Vereinsobjekt verhindert
  konkurrierende Änderungen. Alte Nachweise bleiben erhalten.
- Finanzleserecht zum Lesen, Finanzschreibrecht zum Vorbereiten. Nur ein
  berechtigter Vorstand bestätigt die Prüfung ausdrücklich. EÜR und nicht
  freigeschaltete Bilanz-Arbeitsbereiche dürfen die Funktion nicht verwenden.
- Quellen werden aus dem autorisierten, festgeschriebenen Journal und der
  gespeicherten Bereichsaufteilung rekonstruiert. Ein hochgeladenes oder nur
  selbst gehashtes JSON ersetzt diese Kontrolle nicht.
- Eigene Revision und Aufteilungsrevision sind im Formular gebunden. Geänderte
  Aufteilung, geänderter Vorjahresnachweis oder entzogene Vorstandsrechte machen
  die aktuelle Bestätigung ungültig. Geänderte Vorträge darf nur der Vorstand
  neu übernehmen; danach ist erneut zu prüfen.
- Derselbe Beleg darf nicht zugleich endgültige Mittelübertragung und
  rückzahlbarer Vorschuss sein. Originaljournal und Bankdaten bleiben unverändert.
- In der Bereichsaufteilung führt „Vorschüsse und Rückzahlungen prüfen“ zu
  offenen Beträgen, einzelnen Vorschüssen/Rückzahlungen und separater Prüfung.
  Neue Rückzahlungen können im selben Formular einen neu eingetragenen Vorschuss
  auswählen. Fehlerhafte Eingaben und veraltete Formularversionen bleiben erhalten.
- Unvollständig übertragene Formulare werden abgewiesen. Gespeicherte Zeilen
  dürfen nicht durch bloßes Weglassen verschwinden; Aufhebung ist ausdrücklich.
- Der Jahresabschluss-Download enthält offene Beträge, Bewegungen, Quellnachweis
  und Prüfverlauf, an den Paket-Hash gebunden. Ein geprüfter Verrechnungsnachweis
  ist ausdrücklich keine Freigabe einer Bilanz oder Steuerabgabe.
- Alle vier E-Bilanz-Quellprofile beachten den neuen Nachweis. Ein ungeprüfter,
  veralteter oder nicht leerer Nachweis sperrt den steuerlichen Abschluss, bis
  die nachstehende Teilbilanz-/Steuerdarstellung fertiggestellt ist. Der
  Übermittlungsfingerprint umfasst Service, Rechenkern und Export.

## Ergänzt: vollständiger interner Bereichsabgleich und nichtleerer Vortrag

`BalanceClearingStatement` stimmt jede Originalbuchung und jeden Anfangsbestand
je Bereich mit den nachgewiesenen Vorschüssen ab. Interne Ansprüche und interne
Rückzahlungsverpflichtungen bleiben neben den Originalkonten; sie sind weder
neue Außenforderungen noch zusätzliche Schulden des Gesamtvereins. Der gesamte
Kontennachweis einschließlich Soll-/Haben-Umsätzen stimmt mit dem unveränderten
Vereinsjournal überein. Gegenläufige endgültige Kapitalbewegungen werden dabei
gesondert eliminiert, nicht ein zweites Mal gezählt.

- Bestätigung nur bei vollständigem Abgleich. Ein leeres Verrechnungsformular
  kann nicht mehr trotz ungeklärter Buchungsdifferenzen als geprüft bestätigt werden.
- Vortrag offener Beträge über mehrere Jahre, Teil- und vollständige Rückzahlungen,
  getrennte Ergebnisvorträge und gegenläufiges Bereichskapital. Es werden nur
  Originalkonten vorgetragen; offene Vorschüsse behalten ihren eigenen Quellnachweis.
- Neue Vortragsbindung (Revisionspayload Schema 2) enthält sowohl die unveränderte
  Aufteilungsrevision als auch die exakt geprüfte Verrechnungsrevision. Schema-1-
  Nachweise bleiben rekonstruierbar; keine Bestandsumschreibung und keine neue DDL.
- Bereits eine Änderung des Vorjahres-Verrechnungsnachweises macht die
  Folgejahresbindung ungültig, auch ohne geänderte Buchungsaufteilung. Der Vorstand
  muss die neue Quelle ausdrücklich übernehmen und das Folgejahr erneut prüfen.
  Dies wirkt über mehrere Jahre; keine stillschweigende Aktualisierung.
- Die bestehenden Aufteilungs-/Steuerfreigaben bleiben unverändert streng.
  Ein geprüfter interner Abgleich ist keine Freigabe einer E-Bilanz. Alle vier
  Steuerprofile bleiben für Nachweise mit Verrechnungsfällen gesperrt.
- Gemeinsam genutzte lineare Anlagen, GWG und degressive Anlagen behalten
  ihre Originalpläne und Nutzungsanteile. Der Abgleich ersetzt keine fehlenden
  Nutzungsnachweise und behebt keine abweichenden Anlagenwerte durch Geldbewegungen.
- Oberfläche trennt interne Vorschüsse von Schulden gegenüber Dritten. Bereits
  abgeglichene interne Differenzen werden nicht zugleich als ungeklärt angezeigt.
  Der Jahresabschluss-Download enthält zusätzlich `verrechnungen/bereichsabgleich.json`.

## Weiterhin offen – keine vollständige Bilanzfreigabe

- Begründete steuerliche Darstellung des abgestimmten Bereichsabgleichs ohne
  Umdeutung in endgültige Kapitalübertragung oder externe Forderung. Der
  nichtleere interne Vortrag ist implementiert und isoliert geprüft; damit
  sind weder die steuerliche Projektion noch ihre Übermittlung fertiggestellt.
- Korrekturen, Erlass, Verzicht, Zinsen und überjährige Altbestände ohne
  vollständige Belegkette.
- Unabhängige Prüfung der endgültigen E-Bilanz-Einbindung, isolierte MySQL-
  und Webserver-Gesamtabnahme, anschließend Gesamtfreigabe. Die nachfolgend
  dokumentierten Tests des Arbeitsnachweises ersetzen diese Gesamtabnahme nicht.
  Kein neuer amtlicher Testversand in diesem Schritt.

## Prüfungen des Rechenkerns vom 20.09.2026

- `balance-internal-clearing.php`: **2.536 Assertions**, darunter systematische
  Cent-/Teilrückzahlungsschleifen; nicht 2.536 verschiedene Steuerfälle.
  Mehrere Geldgeber/Empfänger, Folgejahr, Leerjahr, Gegenrichtung, manipulierte
  Nachweise, Grenzwerte, Export und Formelentschärfung sind enthalten.
- `balance-internal-clearing-test.py`: vier unabhängige Python-Testmethoden.
  Originaljournal, Richtungen, Fallbeträge, Bewegungen und Folgejahr werden aus
  den PHP-Fixtures neu berechnet; neu gehashte manipulierte Ergebnisse abgewiesen.
- Bestehende PHP-Regressionen: 6.450 Assertions für endgültige Mittelübertragungen,
  Kapitalvortrag, Teilbilanzen, Arbeitsnachweise, SGEB, kombinierten Abschluss,
  gemeinsam genutzte Anlagen und Grundrechnung bestanden.
- Bestehende unabhängige Python-Mittelübertragung: vier Testmethoden bestanden.
  45 Übermittlungs-Fingerprint-Dateien/13 Python-Abhängigkeiten und die
  92 Fingerprint-Assertions unverändert bestanden.
- Syntaxprüfung aller 889 PHP-Dateien unter Quellcode, Ansichten und Tests
  bestanden; Installationsinventur mit 2.264 Dateien und Abhängigkeiten geprüft.

Die damaligen Rechenkerntests ausschließlich mit synthetischen Daten, ohne Datenbank oder Netzwerk.
Keine Produktionsdatei, Konfiguration, echte Mail, Bankanweisung oder Steuerabgabe
verändert. Die Gesamt-Usability-Prüfung bleibt an das vollständige Release gebunden.

## Integrationsprüfung vom 27.09.2026

Privater Lauf `audit/ebilanz-mysql-host-20260918-40b8ef040e48333b.json`
(historischer Dateipräfix, tatsächlicher Lauf 27.09.2026): alle sechs Testgruppen
bestanden, keine PHP-Warnungen. MySQL 8.4.11 / PHP 8.3.33 auf der autorisierten
separaten Datenbank; keine Verbindung zur Produktivdatenbank.

- `balance-clearings.php`: **70 Assertions** für Quellen-/Vereinsgrenzen,
  Rollen, unveränderliche Historie, konkurrierende Versionen, Vorstandsprüfung,
  Wegfall der Rechte, geänderte Aufteilung, leeren Vortrag und Neuabstimmung,
  Steuerprofil-Sperren, Jahresabschluss-Paket, Controller und tatsächliches PHP-View.
  Unvollständige Formularübertragung und stilles Weglassen gespeicherter Zeilen
  werden abgewiesen; fehlerhafte Eingaben bleiben erhalten und maskiert.
- `balance-internal-clearing.php`: 2.536 Assertions.
- Bestehende Regressionen: endgültige Mittelübertragungen einschließlich
  kombiniertem Steuerablauf 202, Bereichsaufteilung 61, E-Bilanz-Workflow 62,
  MySQL-Schema-/Trigger-Wiederherstellung 7 Assertions.
- Vorheriger Inhalt der separaten Datenbank: **150 Tabellen / 1.393 Datensätze**.
  Zuerst verschlüsselt gesichert und verifiziert; anschließend vollständig
  identisch wiederhergestellt, einschließlich Tabellendefinitionen und Triggern.
  Drei geschützte Live-Dateien unverändert; privates Testverzeichnis nach
  erfolgreichem Wiederherstellungsnachweis entfernt. Keine Echtdaten heruntergeladen.
- Die beiden vorherigen Läufe brachen im neuen Test ab: zuerst fehlender
  Klassenimport, danach falscher Test-Modus beim Wiederherstellungswächter.
  Beide Testfehler korrigiert; beide Male war die äußere Wiederherstellung
  erfolgreich. Die Schutzprüfung wurde nicht abgeschwächt.
- Lokal: unabhängige Python-Gegenrechnung (vier Testmethoden), 98 Assertions
  zum Übermittlungsfingerprint mit 48 Dateien / 13 Python-Abhängigkeiten,
  893 PHP-Syntaxprüfungen und JavaScript-Syntaxprüfung bestanden.
  Installationsinventur einschließlich Migration 99 und neuer Laufzeitdateien
  geprüft (2.270 Dateien).

Browserprüfung nur der lokal gerenderten synthetischen Maske: zusätzliche
Vorschuss- und Rückzahlungszeilen, automatische Auswahl eines noch ungespeicherten
Vorschusses, Erhalt der Auswahl bei Betragsänderung und keine Konsolenfehler.
Beim schmalen Layout (gemessene 390 CSS-Pixel) werden Tabellen zu beschrifteten
Karten; kein horizontales Überlaufen des Dokuments. Eingaben und Schaltflächen
verwenden die bestehenden großen Bedienflächen. Größenoverride wieder entfernt.
Die Screenshot-Erfassung war unzuverlässig; eine abschließende visuelle Abnahme
auf echten Mobilgeräten ist damit **nicht** nachgewiesen. Kein echter Nutzertest.

Der Steuertransport der Regressionen war ausschließlich simuliert. Keine neue
ERiC-Validierung oder Testübermittlung, keine echte Mail, Bankanweisung oder
Steuerabgabe. Kein Deployment, keine Veröffentlichung, keine Manager-Gesamtprüfung.

## Ergänzende Prüfungen: Bereichsabgleich und Mehrjahresvortrag

Privater Nachweis `audit/ebilanz-mysql-host-20260918-e92381fa88bc7597.json`
(tatsächlicher Lauf 27.09.2026): acht Gruppen bestanden, keine PHP-Diagnostik.
MySQL 8.4.11 / PHP 8.3.33; ausschließlich die genehmigte sekundäre Datenbank.

- `balance-clearing-statement.php`: 28.502 Assertions, überwiegend systematische
  Cent-/Kontenprüfungen, **nicht** ebenso viele verschiedene Steuerfälle.
  335 Rückzahlungsstände, Originalkonten, offene Vorträge, Verlustvortrag,
  manipulierte Quellen, drei Jahre, unveränderte Anlagen und Aufhebung interner
  Beträge in der Gesamtbilanz.
- `balance-clearing-statement-test.py`: fünf unabhängige Testmethoden. Python
  rechnet Originalzeilen und Quellaufteilungen selbst nach; keine Übernahme der
  fertigen PHP-Bereichsrechnung. Neu gehashte Kontenänderungen, künstlich erhöhte
  interne Bruttobeträge und Umdeutung in Außenverbindlichkeiten werden erkannt.
- `balance-clearing-carry.php`: 37 MySQL-Assertions für nichtleeren Vortrag,
  Teilrückzahlung, Rollen, Staleness beider Quellrevisionen, explizite Neuabstimmung
  über drei Jahre, unveränderte Journalzeilen und verschlüsselte Wiederherstellung.
- `balance-clearings.php`: 72 Assertions im genannten Lauf. Weitere bestehende
  Gruppen: Rechenkern 2.536, endgültige Mittelübertragungen 202, Aufteilung 61,
  E-Bilanz-Workflow 62, Schema-/Trigger-Wiederherstellung 7.
- Separate Datenbank vor und nach dem Lauf: 150 Tabellen, 1.393 Datensätze,
  einschließlich Definitionen und Triggern identisch. Vorher verschlüsselt
  gesichert, anschließend wiederhergestellt. Privater Testordner entfernt;
  geschützte Live-Dateien unverändert; keine Echtdaten lokal gespeichert.
- Der vorausgehende Lauf `...9173f058fe9a7cc5.json` scheiterte im neuen Test
  am fehlenden Import von `BalanceTaxFiling`, nicht an einer echten Steuerabgabe.
  Import korrigiert, danach erneuter vollständiger Lauf. Auch beim fehlgeschlagenen
  Lauf wurde die sekundäre Datenbank identisch wiederhergestellt.

Kein neuer amtlicher Testversand, keine Produktivfreigabe und kein echter Nutzertest.
Die spätere Gesamtprüfung bleibt bis zum vollständigen Bilanzrelease ausgesetzt.

Abschließender Wiederholungslauf nach den Anzeigeanpassungen:
`audit/ebilanz-mysql-host-20260918-6b7ea07a273d29fe.json`. Wieder alle acht Gruppen
bestanden, keine PHP-Warnungen; `balance-clearings.php` jetzt **75 Assertions**
einschließlich tatsächlichem Aufteilungs-View, Link zum geprüften Bereichsabgleich
und Trennung interner/externer Beträge. Die übrigen oben genannten Gruppenzahlen
unverändert. Erneut identische Wiederherstellung der separaten Datenbank und
unveränderte geschützte Live-Dateien; Testordner entfernt.

Lokal zusätzlich: 898 PHP-Dateien ohne Syntaxfehler, 100 Assertions zum
Übermittlungsfingerprint (49 Dateien / 13 Python-Abhängigkeiten), fünf unabhängige
Python-Abgleichstests, vier bestehende Python-Verrechnungstests, 5.740 bestehende
Anlagenaufteilungs-Assertions und 219 Kapitalvortrags-Assertions bestanden.
Installationsinventur mit 2.279 Quell-/Installationsdateien und statischen
Abhängigkeiten geprüft. Die ergänzten UI-Prüfungen rendern synthetische PHP-Views;
keine erneute visuelle Mobilgeräteprüfung und keine Manager-Gesamtabnahme.
