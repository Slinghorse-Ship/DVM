# Datierte Anlagenwechsel: Steuerüberleitung und XML – 28.09.2026

Nachfolgende Erweiterung: [Anlagenbezogene Steuerkorrekturen](ZEITRAUM-ANLAGEN-STEUERKORREKTUREN-20260928.md).
Die unten dokumentierte ursprüngliche kontenweite AfA-Korrektursperre wird dort
durch eine ausdrücklich bestätigte, auf den einzelnen Steuerbetrag begrenzte
Auswahl ergänzt. Die übrigen Methoden-/Profilgrenzen bleiben bestehen.

## Ergebnis und genauer Umfang

Die beleggebundenen Wechsel ganzer linearer Anlagen sind jetzt mit der
**Original-Gesamtbilanz einschließlich steuerlicher Nebenrechnung (SGEB)**
verbunden. Speicherung, Bestätigung, tatsächlicher Abschlusszeitraum,
Folgezeitraum, XML-Rekonstruktion und gesonderte Anlagenprüfung sind angebunden.
Das ist kein allgemeiner Bilanzrelease und keine Freischaltung der Echtabgabe.
Kulturhus bleibt EÜR; Produktionsdaten und Produktivdateien wurden nicht geändert.

Ausgangspunkt bleiben die bereits implementierten
[beleggebundenen Wechsel](ZEITRAUM-SPHAERENWECHSEL-20260928.md) und
[Bereichsbilanzen](ZEITRAUM-SPHAEREN-BEREICHSBILANZEN-20260928.md).

## Keine Vermischung von Originalbuchführung und Steuerwerten

- Gesamtbilanz, GuV und Brutto-Anlagenspiegel enthalten weiterhin die
  unveränderten Original-Buchwerte. Eine interne Übernahme ist weder ein neuer
  Kauf noch eine Zahlung oder ein zusätzlicher Ertrag des gesamten Vereins.
- Die gesonderte Überleitung weist pro Anlage steuerlichen Anfangswert,
  Anschaffungen, Einlagen, Entnahmen, Bewertungsergebnis, Abschreibung und
  Schlusswert aus. Bewegungen behalten Datum, ursprünglichen Bewertungsnachweis,
  Version, Prüfsumme und Begründung.
- Der steuerpflichtige Ergebnisunterschied ist genau einmal enthalten:
  Bewertungsergebnis beim Austritt **plus Buch-AfA im steuerpflichtigen Bereich
  minus Steuer-AfA in diesem Bereich**. Einlagen und Entnahmen sind kein Aufwand
  und kein Ertrag. Der Kapitalvergleich prüft dieselben Zahlen unabhängig.
- Gewöhnliche abweichende steuerliche Nutzungsdauern anderer Anlagen bleiben
  daneben erhalten. Auch bei gleichem Anlagen-/AfA-Konto dürfen beide Änderungen
  weder zusammenfallen noch doppelt gerechnet werden.
- Die ursprüngliche Buchwert-Abschreibung kann bereits beendet sein, während
  die gesonderte Steuerrechnung noch einen Wert enthält. Originale
  Anschaffungskosten verschwinden dadurch nicht aus dem Brutto-Anlagenspiegel.
- Beim Folgezeitraum wird der genaue steuerliche Schlusswert zum Anfangswert.
  Frühere Bewegungen und Gewinne werden nicht wiederholt. Bei nachgewiesener
  Buchwertrückkehr läuft die steuerliche Abschreibungsuhr ununterbrochen weiter.

Die fachliche Grundlage der bereits geprüften Fallunterscheidung ist
[§ 13 KStG](https://www.gesetze-im-internet.de/kstg_1977/__13.html), insbesondere
die Unterscheidung von Teilwert, steuerbegünstigter Buchwertfortführung und
Rückkehr. Das ist keine automatische rechtliche Beurteilung einer Verwendung;
die vorhandenen ausdrücklichen Nachweise und Bestätigungen bleiben erforderlich.
Die Ausgabe der unveränderten Gesamt-B/GuV mit gesonderter Ergebnisermittlung
orientiert sich an Abschnitt 4 m der
[amtlichen E-Bilanz-FAQ, Januar 2026](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
ERiC-Erfolg ersetzt weder diese Einordnung noch eine steuerliche Anerkennung.

## Unabhängige Kontrolle und Oberfläche

PHP berechnet und speichert die Steuerüberleitung. Der Python-XML-Generator
rechnet anschließend die gesamte monatliche Anlagen- und Sphärenhistorie erneut
aus den ursprünglichen Plänen, Bewertungsnachweisen und dem unmittelbaren
Vorabschluss. Eine neue Prüfsumme über manipulierte Ergebnisse reicht nicht.
Fehlender Vortrag, veränderte Nachweise, doppelte Gewinne und konkurrierende
Steuerpläne werden zurückgewiesen.

Die offiziellen XML-Daten enthalten das steuerliche Ergebnis und eine
nachvollziehbare SGEB-Fußnote mit Anlage, Datum, Bereichsbewegungen und
Buch-/Steuerwertvergleich. Es wird keine künstliche steuerpflichtige
Gesamtbilanz und kein erfundenes Steuerkonto angelegt.

In Steuerprüfung und Abgabeprüfung erscheint ein aufklappbarer Abschnitt
„Anlagen: Wechsel zwischen Vereinsbereichen“. Er zeigt unter anderem
Anschaffungen, Übernahmen, Herausnahmen, Abschreibung, Schlusswert und
Datum/Bezeichnung der beteiligten Bereiche. Die Werte sind nur lesbar; bereits
belegte Bewertungsangaben müssen nicht erneut eingegeben werden. Die Bestätigung
getrennter Buch- und Steuerwerte ist erforderlich, auch wenn die Differenz in
einem Einzelfall numerisch null ist. Rollen-, Mandanten- und Versionsprüfungen
bleiben wirksam.

## Während der Prüfung gefundene und behobene Fehler

1. Eine exakt stornierte frühere AfA wurde im unabhängigen Brutto-Umsatzvergleich
   noch als Anlagenzugang mitgezählt. Jetzt werden ausschließlich ausdrücklich
   verknüpfte, vollständige Umkehrbuchungen für diesen Vergleich neutralisiert.
   Originaljournal, Salden und Prüfsummen behalten sämtliche Buchungen.
   Fehlende, doppelte, vorgezogene und abweichende Stornos bleiben Fehler.
2. Ein gespeicherter Anlagenname enthält den systemeigenen Trenner `·`.
   ERiC lehnte ihn in der neuen XHTML-Fußnote mit 610001002 / Regel 170105080 ab.
   Ausschließlich in diesen ausgegebenen Texten wird der Trenner zu `-`;
   Originalname, Begründung und Nachweise werden nicht umgeschrieben.
3. Die neue Python-Schutzprüfung nutzte anfangs die falsche Signatur der
   bestehenden `require`-Funktion. Bestehende Nebenrechnungstests deckten dies
   auf; die Signatur wurde korrigiert und diese Regressionen erneut geprüft.

Fehler in der Testeinrichtung wurden getrennt korrigiert: Die synthetische
offene Lieferantenrechnung benötigt eine ausdrückliche Kontenzuordnung und die
gerenderte Accountansicht einen synthetischen Formularschutz. Dafür wurden keine
Produktivprüfungen abgeschaltet.

## Verbleibende Grenzen dieser Erweiterung

- Ganze lineare Anlagen auf 01350, 06300 oder 06500 und nachgewiesene monatliche
  Wechsel. Andere Methoden, GWG, frühere abweichende Steuer-Nutzungsdauer derselben
  wechselnden Anlage, taggenaue/anteilige Wechsel über verschiedene Sphären sowie
  Wechsel zusammen mit einem Abgang sind weiterhin gesondert zu entwickeln.
- Außerbilanzielle AfA-Korrekturen auf betroffenen Abschreibungskonten bleiben
  gesperrt: Der ursprüngliche Buchaufwand darf nicht fälschlich als tatsächlicher
  Steueraufwand verwendet werden. Die gezielte Zuordnung zur einzelnen
  steuerlichen AfA-Quelle fehlt noch. Die Schutzprüfung ist derzeit kontenbezogen.
- Die neue Überleitung gilt für Gesamt-B/GuV mit steuerlicher Nebenrechnung.
  Sie gibt weder separat bilanzierte noch kombinierte Betriebsprofile frei.
- Vollständige Kontenabdeckung, übrige Anlagen-/Steuersonderfälle, endgültiger
  Testversand/Rückmeldungen, Gesamtabnahme und Veröffentlichung bleiben offen.

Der aktuelle bereichsübergreifende Stand steht in der
[Release-Tabelle](ZEITRAUM-ABGABEABLAUF-20260928.md#verbleibende-release-bedingungen-nach-codeabgleich).
Die einmalige Gesamtmanager-Bedienprüfung wird erst nach dem vollständigen
Release ausgelöst. Die unten protokollierten automatisierten Prüfungen sind kein
beobachteter Nutzertest mit einem 65-jährigen Menschen.

## Prüfprotokoll

Der gezielte Webserver-Lauf verwendet ausschließlich die dauerhaft freigegebene
synthetische MySQL-Datenbank. Keine Produktivdatenbank, kein öffentlicher
Testendpunkt und keine zusätzliche Sicherung. Temporäre Serverdateien werden
anschließend entfernt; synthetische Daten bleiben für die weitere Arbeit bestehen.

Die vier Prüfgruppen umfassen reine Steuer-/Buchwertfälle, den gespeicherten
Sphären-Abgabeablauf, bisherige datierte Gesamtvereins-/Nebenrechnungsabläufe und
die Bindung an die Laufzeitdateien. In den bestehenden Workflowtests sind
Transportantworten simuliert. Der neue Sphärentest ruft keinen Versand auf.

Die **24 nativen ERiC-Prüffälle** bestehen mit Code 0 (Flag 2, ausschließlich
Validierung, ohne Zertifikat oder Transport): Eintritt, Austritt mit Gewinn oder
Verlust, Buchwert- und Teilwertrückkehr, spätere Rückkehr, Wechsel zwischen
steuerfreien Bereichen, mehrere Wechsel, bereits buchmäßig abgeschriebene
Anlagen, genau stornierte ursprüngliche AfA, Mischfall mit gewöhnlicher
Steuer-Nutzungsdauer sowie zwei unveränderte XML-Dateien aus dem gespeicherten
MySQL-Ablauf. Erst- und Folgezeiträume sind jeweils enthalten.

Native Nachweise (lokal, nicht Bestandteil des öffentlichen Quellcodes):
`audit/ebilanz-period-spheres-native-20260928-fixed.json`.
Der erste fehlgeschlagene Lauf bleibt getrennt unter
`audit/ebilanz-period-spheres-native-20260928.json` erhalten.

Abschließender Webserver-Nachweis:
`audit/ebilanz-mysql-host-20260918-f841f0b3b3bd865f.json`.
Quellpaket-SHA256:
`65b654ea97e9799d624733279eaec308884ec0f3fcb6c3804be45be13251dd61`.
Alle vier Testgruppen bestanden; keine PHP-Diagnosen. Enthalten sind:

- 207 reine Buchwert-/Steuer-/Vortrags-/Anzeigeprüfungen;
- 21 neue gespeicherte Abgabeprüfungen zusätzlich zu 39 Bereichs-, 44
  Sphärenquell- und 43 Rechnungs-/Zahlungsquellprüfungen;
- 32 bestehende datierte Gesamtvereins- plus 61 Nebenrechnungsprüfungen;
- 186 Laufzeit-Fingerprint-Prüfungen.

Außerdem bestanden alle 28 Python-E-Bilanz-Testprogramme (darunter neun neue
Sphären-Testmethoden mit mehreren Erst-/Folge- und Manipulationsfällen), die
Syntaxprüfung aller 553 PHP-Laufzeit-/Ansichts-/Migrationsdateien sowie die
bestehenden 80 Feldanzeige- und 16 Quellvorschauprüfungen in Node.
`production_database_connected=false`, `protected_files_unchanged=true`,
`temporary_files_removed=true`, `backup_created=false`.

Die vorherigen fehlgeschlagenen Integrationsläufe bleiben unter den Endungen
`fed778b80716dcd6`, `8182b2c34d1fd159` und `3348f262193a165c` der entsprechenden
lokalen `ebilanz-mysql-host-20260918-*.json`-Nachweise erhalten. Sie werden nicht
als bestandene Abnahme gezählt. Auch die abschließend erfolgreichen Prüfungen
sind **keine vollständige Release-Abnahme und kein Übermittlungsversuch**.
