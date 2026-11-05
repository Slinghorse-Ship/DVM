# Datierte Anlagen-Sphärenwechsel – 28.09.2026

Fortsetzung: [Bereichsbilanzen und interne Anlagen-/Kapitalbewegungen](ZEITRAUM-SPHAEREN-BEREICHSBILANZEN-20260928.md).
Die dort beschriebene Buchwert-Aufteilung ersetzt inzwischen die unten genannte
Sperre des Bereichsadapters. Die amtlichen Steueradapter und der XML-Generator
bleiben für Sphärenwechsel bis zur vollständigen Steuerüberleitung gesperrt.

## Umfang dieses Entwicklungsschritts

Ein tatsächlicher steuerlicher Bereichswechsel ist jetzt ein eigener Nachweis
innerhalb der datierten Anlagenquelle. Er ist weder eine Inventar-Umbenennung
noch eine neue Anschaffung oder die bisherige Nutzungsaufteilung innerhalb
derselben Sphäre. **Noch kein allgemeiner Bilanzrelease und nicht veröffentlicht.**
Kulturhus bleibt EÜR. Der datierte Schreibweg bleibt auf die synthetische
Entwicklungsumgebung begrenzt.

Implementiert sind:

- Unveränderter Anschaffungsbeleg, Buchplan und gesamte Buch-AfA. Die gebuchten
  AfA-Aufwendungen verteilen sich auf die tatsächlich genutzten Sphären. Soll und
  Haben sowie das Gesamtvereinsergebnis bleiben ausgeglichen.
- Ein zeitlich getrennter Steuerplan: erster steuerpflichtiger Zugang mit
  nachgewiesenem Wert und Restnutzungsdauer; Ausscheiden mit geprüftem Buchwert
  oder anderem Wertansatz; Rückkehr nach Buchwertübertragung mit fortgerechneter
  hypothetischer Steuer-AfA. Zwischenzeitliche steuerfreie Monate werden bei der
  Rückkehr nicht übersprungen. Ein Wertunterschied wird nicht als neuer Kauf gebucht.
- Beleggebundene Chronologie mit Datum, Zielbereich, Bewertungsfall, Begründung,
  Dokument-ID, Revision und tatsächlicher Prüfsumme der Datei. Der Beleg muss zum
  selben Verein und Gegenstand gehören und als aktiver Finanzbeleg zugänglich sein.
- Getrennte Anfangswerte, ursprüngliche Zugänge, Einlagen, Entnahmen,
  Bewertungsgewinne/-verluste, steuerliche AfA und Endwerte. Der Steuerwert wird
  centgenau über diese Bewegungen abgestimmt.
- Berichtigung des offenen Zeitraums durch Storno und Ersatzbuchungen. Bereits
  gebuchte Originale und die Quellenzugehörigkeit werden nicht geändert. Der
  Folgeabschluss übernimmt den geprüften Nachweis und wiederholt keine Bewegung.
- Optionale Eingabe „Gegenstand künftig anders nutzen“, Bearbeitung mehrerer
  Wechsel und Vergleich der Buch-/Steuerwerte. Neue Nutzungsdauer und Restwert
  erscheinen nur beim neuen Wertansatz. Die fachliche Beurteilung wird nicht aus
  einer Bereichsbezeichnung geraten.
- Arbeitsunterlagen `anlagen-bereichswechsel.csv` und `anlagen-bereichswerte.csv`
  zusätzlich zum vollständigen Anlagen- und Buchungsverlauf. Das sind keine
  amtlichen ELSTER-Datensätze.

## Nachgewiesene Grenzen

Der aktuelle Bewegungsweg unterstützt ganze lineare Anlagen auf den bereits
geprüften Konten 01350, 06300 und 06500 und Wechsel zu Monatsbeginn. Er setzt vor
dem ersten Wechsel übereinstimmende Buch-/Steuerpläne voraus. Das ist ausdrücklich
zu bestätigen; bekannte abweichende Pläne aus vorhandenen Steuerprüfungen oder
Abgabeentwürfen blockieren diesen Weg. Die Prüfung ist vereinsbezogen; spätere
Zeiträume ersetzen keine früheren Nachweise.

Folgende Fälle sind damit **noch nicht fertig**:

1. Vor dem ersten Wechsel abweichende Steuerpläne, degressive/GWG-Pläne,
   anteilige oder taggenaue Wechsel und Wechsel zusammen mit Anlagenabgängen.
2. Aufteilung der bewegten Anlagen auf einzelne Betriebe mit steuerlichem
   Kapitalabgleich und deren amtlichem Brutto-Anlagenspiegel.
3. Unabhängige Rekonstruktion der vollständigen Sphärenbewegung im XML-Generator
   sowie vollständiger Abgabeablauf und geprüfte Übermittlungsrückmeldungen.
4. Die übrige allgemeine Konten-/Sonderfallabdeckung und Gesamtabnahme.

Die bestehenden Steuer- und Bereichsadapter sowie der Python-Generator lehnen
Anlagen mit einem solchen Wechselnachweis deshalb ausdrücklich ab. Sie dürfen
ihn nicht beim Zusammenfassen der Werte verlieren und eine gewöhnliche Anlage
mit unverändertem Bereich vortäuschen. Es wurden keine Produktionsschalter oder
Versandberechtigungen geöffnet.

### Nächste Integrationsstellen

Die Sperren nicht einfach entfernen. Für den nächsten Entwicklungsschritt:

- `BalancePeriodAssetAllocation::build()` muss Öffnungs-, Bewegungs- und
  Schlussanteile anhand der nachgewiesenen Bereichsfolge aufteilen. Eine
  geänderte Schluss-Sphäre darf den Originalplan und die ursprünglichen
  Anschaffungskosten nicht ersetzen. Gemeinsam genutzte Anlagen brauchen eine
  ausdrückliche zeitliche Zuordnung, die insgesamt wieder exakt den ganzen
  Gegenstand ergibt.
- `BalancePeriodTaxAssetValues::prepare()` muss den getrennten Steuerverlauf
  zusammen mit der Bereichsaufteilung übernehmen. Einlagen/Entnahmen sind
  Kapitalbewegungen; Wertgewinne/-verluste und AfA sind davon getrennt. Eine
  reine Änderung des Endbuchwerts gegen das AfA-Konto wäre falsch.
- `ebilanz_period_assets.py` muss die Originalbelege, frühere Steuerpläne und
  Wechsel unabhängig rekonstruieren, nicht nur PHP-Endwerte akzeptieren. Danach
  müssen Kapitalvergleich und Brutto-Anlagenspiegel dieselben datierten
  Bewegungen ausweisen. Der native Test muss die tatsächlich gespeicherten
  Workflow-XMLs prüfen, einschließlich Korrektur und Folgezeitraum.

## Fachliche Grundlage und Abgrenzung

Die Rechenzweige unterscheiden den allgemeinen Wertansatz und die Sonderregel
für begünstigte Verwendung einschließlich späterer Rückkehr. Grundlage:
[Amtliches KStH, § 13 KStG mit R 13.4](https://ksth.bundesfinanzministerium.de/ksth/2022/A-Koerperschaftsteuergesetz/II-Einkommen/1-Allgemeine-Vorschriften/Paragraf-13/inhalt.html).
Dies ist die zugängliche Ausgabe 2022, keine Behauptung einer vollständig
abgenommenen Rechtsstandsprüfung für sämtliche Abschlussjahre. Ob die Regel im
Einzelfall greift, muss der hinterlegte fachliche Nachweis klären. Mittelbindung,
Umsatzsteuer/Vorsteuerberichtigung und vorherige Nutzung bleiben eigene Prüfungen;
ein angekreuztes Feld ersetzt weder eine Bewertung noch einen Beleg.

## Prüfverfahren

- `balance-period-sphere-change.php`: reine Cent-Rechnung, fünf Bewertungszweige,
  mehrfache Wechsel, Rückkehr nach steuerfreien Monaten, Rumpf-/Schaltjahr,
  unveränderter Buchplan, Restwerte, abgewiesene ungültige oder manipulierte Historie.
- `balance-period-sphere-workflow.php`: synthetische Originalrechnung, Anlage und
  Nachweis → Vorschau → Übernahme/Berichtigung → Festschreibung → kurzer
  Folgezeitraum; Rechte, Mandantentrennung, inkompatible Steuerpläne, Belegschutz,
  Formularfehler, lesbare Arbeitsunterlagen und weiterhin geschlossene Exportwege.
- `balance-period-spheres-ui.test.cjs` und `balance-period-sources-ui.test.cjs`:
  relevante Felder, keine doppelten Handler, unveränderte Eingabewerte und Sperre
  einer veralteten Vorschau nach Änderungen. Das sind automatisierte Prüfungen,
  kein beobachteter Nutzertest auf Desktop oder Handy.
- `ebilanz-period-assets-test.py`: ein Wechselnachweis darf auch bei neu
  berechneter Paket-Prüfsumme nicht aus dem amtlichen Anlagenweg verschwinden.

Der erste MySQL-Lauf erkannte einen Variablennamenskonflikt im neuen UI-Test.
Der Test rendert die Ansicht jetzt in einem eigenen Funktionsbereich. Die
Produktivdatenbank wurde nicht verbunden; der Test erzeugte keine Sicherung
und verwendete ausschließlich die dauerhaft freigegebene synthetische Datenbank.

## Abschließende Nachweise dieses Schritts

Der Wiederholungslauf war vollständig erfolgreich:

- 8 MySQL-Testgruppen: Sphärenrechnung, neuer gespeicherter Ablauf, bisherige
  Abschreibung, gemeinsame Bereichsnutzung, datierte Nutzungswechsel,
  Kalender-Anlagenvortrag bis zum Gesamtvereins-Workflow, datierter
  Gesamtvereins-Workflow und Laufzeit-Fingerprint.
- 106 neue arithmetische Prüfungen und 44 neue MySQL-Ablaufprüfungen; letzterer
  Test verwendet zusätzlich die 43 bestehenden Rechnungs-/Zahlungsprüfungen.
- 27 vorhandene Python-E-Bilanz-Testprogramme erfolgreich, einschließlich des
  zusätzlichen Negativfalls für einen unzulässig verlorenen Wechselnachweis.
- 80 Feldanzeige-Prüfungen und 16 bestehende Vorschau-Prüfungen in Node;
  549 PHP-Dateien aus Laufzeit, Ansichten und Migrationen ohne Syntaxfehler;
  180 Laufzeit-Fingerprint-Prüfungen bestanden.
- Keine echte Mail, Banktransaktion oder Steuerübermittlung. Die üblichen
  Workflow-Regressionen generierten XML und verwendeten simulierte
  Übermittlungsantworten. Kein neuer nativer ERiC-Versandtest für Sphärenwechsel.

Lokaler Betriebsnachweis (bewusst nicht im Quellcode-Repository):
`audit/ebilanz-mysql-host-20260918-13278b00c7a5f6ef.json`.
Quellpaket-SHA256:
`50498c0cb82d4ef810d9ac15d522b55d8e52fa19aeca77510c33119d64047e0f`.
Der Lauf bestätigt `production_database_connected=false`, unveränderte geprüfte
Produktivdateien, entfernte temporäre Testdateien und `backup_created=false`.
Die dauerhaft synthetische Testdatenbank blieb für weitere Arbeit verfügbar.

Der vorangegangene fehlgeschlagene UI-Test bleibt getrennt nachvollziehbar unter
`audit/ebilanz-mysql-host-20260918-526552a11c8ef93b.json`; er wird nicht als Abnahme
gezählt. Die oben genannten Release-Grenzen bestehen trotz erfolgreicher Tests fort.
