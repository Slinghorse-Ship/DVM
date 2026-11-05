# Datierte gemeinsame Teilbilanz – 28.09.2026

Dieser Stand verbindet die gemeinsame steuerliche Teilbilanz **aller**
steuerpflichtigen Geschäftsbetriebe eines Vereins mit tatsächlichen Zeiträumen,
Originalbuchungen, Anlagen, Speicherung und Freigabe. Er ist kein allgemeiner
Bilanzrelease. Kulturhus bleibt EÜR; keine Veröffentlichung oder Echtabgabe.

## Umgesetzter Umfang

- Die Quelle ist die vollständig bestätigte Aufteilung des Originaljournals.
  Eine beliebige Auswahl einzelner steuerpflichtiger Betriebe ist nicht möglich.
  Anfangsbestände und beide Seiten jeder zugehörigen Buchung werden übernommen.
  Die vollständige ursprüngliche Vereinsbilanz bleibt als Nachweis erhalten.
- Einnahmen steuerfreier Bereiche werden nicht Teil des steuerpflichtigen
  Ergebnisses. Endgültige interne Mittelübertragungen bleiben Kapitalbewegungen,
  nicht erfundene Erträge. Vorschüsse zwischen den gemeinsam enthaltenen
  Geschäftsbetrieben werden nicht zu Forderungen gegen Außenstehende.
- Gemeinsam genutzte Anlagen werden genau einmal mit ursprünglicher Anschaffung,
  ursprünglichem Buchplan und vollständigem Brutto-Anlagenspiegel übernommen.
  Die Nutzungsanteile sind Nachweise, keine neuen Gegenstände. Datiert geänderte
  Nutzung innerhalb derselben Sphäre wird im Gesamtbetrag eliminiert.
- Ein gesondert bestätigter steuerlicher Anlagenplan bleibt von der Buchführung
  getrennt. Sein Vortrag in einen kurzen Folgezeitraum wiederholt weder
  Anschaffung noch laufenden Ertrag oder Aufwand. Voll abgeschriebene Gegenstände,
  GWG und degressive AfA bleiben an die ursprünglichen Zeiträume gebunden.
- Storno und ursprüngliche Buchung bleiben ausdrücklich verknüpft. Ohne diesen
  Verweis könnten Brutto-Anschaffungen trotz gleicher Nettosumme zu hoch erscheinen.
  Original und Storno müssen dieselbe Teilbilanz mit genau umgekehrten Werten
  betreffen; eine stillschweigende neue Zuordnung wird nicht akzeptiert.
- Entwürfe speichern exakte Daten, Quellen- und Bereichsfreigaben. Ein kürzerer
  Folgezeitraum mit gleichem Endjahr ist nicht dieselbe Abgabeverpflichtung.
  Der Vortrag verwendet nur die letzte eindeutig bestätigte Abgabe.
- Korrekturen erhalten Originalwerte, XML, Freigaben und Protokolle. Die historische
  Ansicht liest ihren ursprünglichen Bereichsstand. Für eine „sonstige Korrektur“
  werden sowohl ursprüngliche Gesamtbuchwerte als auch ausgegebene Bilanzfakten
  geprüft. Ein geänderter ursprünglicher Abschluss braucht erneut eine
  Bereichsfreigabe und eine passende Korrekturart.
- Die zusätzliche Teilbilanz-Aktion liegt bei der bestätigten Bereichsaufteilung,
  nicht als weiterer normaler Schritt auf der Abschlussübersicht. Leseberechtigte
  erhalten keine Schreibaktion. Die Maske zeigt alle enthaltenen Geschäftsbetriebe,
  den tatsächlichen Zeitraum und den erforderlichen Vorabschluss.
- PHP und der Python-Generator rekonstruieren die Buch-/Steuerwerte unabhängig.
  Der Laufzeitnachweis enthält die neuen Adapter. Eine Quellenänderung nach
  Prüfung kann nicht mit der alten Freigabe versendet werden.

## Prüfverfahren

- `balance-period-club-values.php`: 63 reine Prüfungen von Originalbindung,
  Vollständigkeit, Anlagen-/Steuerwerten, exaktem Vortrag, Storno, internen
  Vorschüssen und abgewiesenen Änderungen bzw. unpassenden Profilen.
- `ebilanz-period-club-test.py`: fünf Testmethoden mit mehreren Positiv- und
  Manipulationsfällen; echte XML-Erzeugung einschließlich unabhängigem Nachrechnen.
- `balance-period-club-workflow.php`: gespeicherter MySQL-Ablauf mit zwei
  steuerpflichtigen und einem steuerfreien Bereich, endgültiger Mittelübertragung,
  Rollen, veralteten Formularen, Korrekturen, kurzem Folgejahr, Ansichten und
  unverändertem ursprünglichem Journal. Transportantworten ausschließlich simuliert.
- `balance-period-club-assets.php`: synthetische Originalrechnungen, lineare und
  degressive AfA, GWG, vollständig abgeschriebene Anlage, gemeinsame Nutzung,
  datierter interner Nutzungswechsel, gesonderter Steuerplan und echter kurzer
  Folgezeitraum bis in Speicherung, XML-Erzeugung, Freigabe und Ansicht.
- Bestehende Einzelbetriebs-, Gesamtvereins-, Nebenrechnungs-, Sphärenwechsel-
  und Laufzeitnachweis-Prüfungen schützen die bereits vorhandenen Wege.

Native ERiC-Validierung mit Flag 2 ist kein Testversand und keine steuerliche
Anerkennung. Die dauerhafte synthetische MySQL-Datenbank wird ohne neue Sicherung
oder Rücksicherung verwendet. Keine produktive Datenbank und keine echten
Mitgliedsdaten, E-Mails, Zahlungen oder Steuerabgaben werden dafür verwendet.

## Nachgewiesener Endstand

Geprüftes Webserver-Quellenpaket:
`de2198fe6250248c89df78a99343885c600338d8ad25688bdcaf63cc008eb34a`.

- Alle vier abschließenden Testgruppen erfolgreich: 63 reine Werteprüfungen,
  42 gespeicherte Ablauf-/Rollen-/Ansichtsprüfungen, 16 zusätzliche Anlagen-
  Ablaufprüfungen zuzüglich der ursprünglichen Rechnungs-/AfA-/Aufteilungskette
  mit 37 Nutzungswechsel- und 96 Bereichsprüfungen sowie 198 Laufzeitnachweisprüfungen.
- Die bestehenden Einzelbetriebs-, vollständigen Vereins- und steuerlichen
  Sphärenwechselabläufe bestanden im unmittelbar vorherigen breiteren Lauf.
  Der gezielte Endlauf wiederholt sämtliche vom korrigierten neuen Adapter
  betroffenen Gruppen, nicht erneut die unveränderten übrigen Wege.
- Alle 30 E-Bilanz-Python-Regressionsprogramme erfolgreich. Acht gezielte reine
  PHP-Rechenprogramme erfolgreich, darunter 1.266 Nutzungswechsel- und 207
  Sphären-/Steuerwertprüfungen. Syntaxprüfung aller 558 PHP-Dateien in Laufzeit,
  Ansichten, öffentlichem Einstieg und Migrationen ohne Fehler.
- Alle 13 XML-Fälle bestehen zusätzlich natives ERiC mit Code 0: sieben reine
  Fälle und sechs unveränderte XML-Dateien aus dem gespeicherten Webserver-Ablauf
  (Erstabgabe, Steuerwertkorrektur, kurzer Folgezeitraum, ursprüngliche
  Buchungskorrektur sowie Anlagen-Erst-/Folgeabschluss). Nur Flag 2, kein Zertifikat,
  kein wirklicher Übermittlungsversuch und keine behauptete Finanzamtsannahme.
- Die Gegenprüfung deckte den fehlenden Stornobezug in der Projektion sowie die
  anschließend zu strenge Behandlung ausdrücklich leerer Stornoverweise in
  gespeicherten Daten auf. Beide Fälle sind korrigiert und werden jetzt durch
  Positiv-/Negativfälle und den gesamten gespeicherten Ablauf geprüft.
- Zwei frühere Fehler lagen in neuen Tests: falscher Name eines bereits bestehenden
  Wertefelds und eine beim direkten Einbinden der Ansicht überschriebene lokale
  Testvariable. Die Testdarstellung läuft nun im eigenen Funktionsrahmen. Dafür
  wurden keine produktiven Prüfungen gelockert.
- Gerenderte Ansichten und serverseitige Rollen wurden geprüft; kein vollständiger
  Desktop-/Handy-Nutzertest. Die allgemeine Nachprüfung bleibt bis zum Gesamt-Release
  zurückgestellt.
- Keine produktive Datenbank verbunden; die geprüften produktiven Einstiegdateien
  blieben unverändert. Temporäre Serverdateien wurden entfernt, die freigegebene
  synthetische Datenbank bleibt ohne zusätzliche Sicherung/Rücksicherung bestehen.

Lokale, nicht veröffentlichte Nachweise:
`audit/ebilanz-mysql-host-20260918-a5d79141398ddef1.json` (erfolgreicher Endlauf),
`audit/ebilanz-mysql-host-20260918-ab8ed7e7b8740e2d.json` (vorheriger breiter Lauf
mit dem anschließend behobenen leeren Stornoverweis) und
`audit/ebilanz-club-period-native-20260928.json` (13 native XML-Prüfungen).
Die historische Datumskennung des MySQL-Prüfläufers benennt nicht das Ausführungsdatum;
diese Nachweise entstanden am 28.09.2026.

## Verbleibende Grenzen

- Der ältere **Kalender-Vorabschluss dieses Aufteilungsprofils** benötigt noch
  einen eigenen überprüften Adapter. Andere bereits unterstützte Kalenderprofile
  machen dessen ursprüngliche Bereichsfreigabe nicht austauschbar.
- Die kombinierte datierte Ausgabe aus vollständiger Vereins-B/GuV und
  steuerlicher Teilbilanz bleibt separat anzubinden. Die schon angeschlossene
  Gesamtbilanz mit **steuerlicher Nebenrechnung** ist ein anderer Übermittlungsweg.
- Vorschüsse über die Grenze steuerpflichtig/steuerfrei und echte steuerliche
  Anlagen-Sphärenwechsel werden hier nicht in erfundene Kapital- oder
  Außenforderungen umgerechnet. Für diese Fälle bleibt der bereits vorhandene,
  geprüfte Nebenrechnungsweg maßgeblich; dessen eigene dokumentierte Grenzen
  gelten weiterhin. Auch ausgeglichene grenzüberschreitende Vorschüsse werden
  in diesem neuen Teilbilanzprofil vorsorglich nicht übernommen.
- Nicht unterstützte Anlagen-/Steuersonderfälle, vollständige Kontenabdeckung,
  endgültiger tatsächlicher Testversand einschließlich Rückmeldungen und
  Gesamtabnahme bleiben Release-Voraussetzungen. Keine vorgezogene Gesamtprüfung
  des Vereinsmanagers und keine Änderung der bestehenden Nachprüfungsautomation.

Die unterschiedliche Behandlung der Übermittlungsprofile folgt der Abgrenzung
in Abschnitt 4m der [amtlichen E-Bilanz-FAQ, Version 2026-01](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
Der Adapter entscheidet nicht selbst über Bilanzierungspflicht oder die fachliche
Zulässigkeit des vom Verein gewählten Verfahrens.
