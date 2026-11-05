# E-Bilanz: Vorbereitung, Prüfung und Abgabe

Ein neuer E-Bilanz-Entwurf bindet seinen Verein, Abschluss, Abgabeumfang und seine
Umgebung. Die Umgebung lässt sich anschließend nicht wechseln. Alte Testentwürfe,
XML, Freigaben und Übermittlungsnachweise werden nicht umgeschrieben.

Im vorhandenen isolierten Testbetrieb bleibt die Testumgebung mit ausschließlich
erfundenen Daten verfügbar. Im ausdrücklich aktivierten Produktivbetrieb mit
Bilanzarbeitsplatz wird ein neuer Entwurf als Produktion angelegt. Eine
Produktionsinstallation erhält dadurch keinen zusätzlichen synthetischen Testweg.
Der Methodenstandard für neue Entwürfe bleibt `test`; eine Produktivabgabe muss
`production` ausdrücklich angeben.

## Bedienung

Der Einstieg liegt beim festgeschriebenen Jahres- oder Zeitraumabschluss, bei der
bestätigten Betriebsaufteilung oder bei der geprüften steuerlichen Nebenrechnung.
Nach Auswahl der Abgabeumgebung werden Vereinsangaben, tatsächliche Rechtsform,
Kontenzuordnung und Steuerwerte geprüft und gespeichert.

1. **XML lokal vorbereiten** erzeugt ein XML-Original und seine Prüfsumme. Dieser
   Vorgang benötigt keinen ERiC-SDK, kein Zertifikat und keine Netzwerkverbindung.
   Der Entwurf bleibt in Bearbeitung; eine frühere Prüfung oder Freigabe entfällt.
2. **Mit ELSTER prüfen** startet die separat eingerichtete originale native
   Laufzeit. Bei Produktion muss deren tatsächlicher Versionsnachweis zum
   gebundenen Jahrespaket passen. Eine Konfigurationsoption allein genügt nicht.
3. Der berechtigte Vorstand gibt genau den geprüften Stand frei. Änderungen an
   Daten, Abschluss, Software, Laufzeit oder Berechtigung erfordern eine neue
   Prüfung und Freigabe.
4. Ein ausdrücklich bestätigter Versand ist ein eigener Vorgang. Produktion und
   Test verwenden unterschiedliche Bestätigungen und Zertifikate. Ein begonnener
   Vorgang ohne sichere Antwort bleibt gesperrt. Ein technischer Eingang ist kein
   Steuerbescheid.

XML-Vorbereitung kann ohne native Laufzeit bedient werden. Die Oberfläche zeigt
dann die noch fehlende amtliche Prüfung. Ein GET startet weder SDK noch Versand.
Vorgänger, Vortrag und Korrekturabgaben müssen derselben Umgebung angehören.

## Private Konfiguration nur zur lokalen Vorbereitung

`VEREIN_ERIC_RUNTIME` verweist auf eine private JSON-Datei außerhalb des Webroots.
Unter Unix benötigt sie Dateirechte `0600`. Ein minimaler Linux-Aufbau zur lokalen
Produktionsvorbereitung ist beispielsweise:

```json
{
  "version": 1,
  "manufacturer_id": "EIGENE_FUENFSTELLIGE_ID",
  "command": ["/usr/bin/python3", "-B"],
  "balance_production_preparation": true,
  "profiles": {}
}
```

Die Hersteller-ID muss durch die eigene echte fünfstellige ID ersetzt werden.
Der Server ergänzt den mitgelieferten Bridgepfad und die private Konfiguration.
Dieses Beispiel richtet ausdrücklich keine amtliche Prüfung oder Übermittlung
ein. Für die synthetische Testvorbereitung gilt der vorhandene separate Schalter
`balance_test_enabled`.

Zur nativen Prüfung kommen die in
[ELSTER-ERIC-INTEGRATION.md](ZUSATZLAUFZEITEN.md#eric-einrichten)
beschriebenen originalen Bibliotheken, Kataloge und privaten Arbeitsverzeichnisse
hinzu. Produktionsprüfung erfordert außerdem `balance_production_enabled` und ein
passendes Bilanzprofil. Der Prozess liest die originale native ERiC-Version und
prüft die freigegebenen Versionspaare des Jahrespakets; Konfiguration oder
synthetische Testantworten ersetzen diesen Nachweis nicht.

## Freigabegrenze

Die mitgelieferten Pakete für Taxonomie 6.9 ermöglichen lokale Vorbereitung und
separat abgesicherte native Prüfung für die unterstützten Jahre 2025/2026. Ihr
`production_authorized` bleibt `false`. Das Paket für 2027 ist dadurch nicht für
Prüfung oder Abgabe freigegeben. Produktiver Versand benötigt eine gesonderte
versionsgebundene Original-SDK- und Transportabnahme; ein Schalter in der privaten
Konfiguration kann diese Grenze nicht aufheben.

Die automatisierten Produktionszustände werden ausschließlich mit explizit
intern injizierten synthetischen Testabhängigkeiten geprüft. Es gibt dafür keinen
HTTP-Parameter oder Umgebungsvariablen-Schalter. Weder ein solcher Test noch
lokale XML-Erzeugung belegt einen erfolgreichen amtlichen Versand.

## Interne Zwischenabschlüsse

Interne Tages- oder Monatsaufteilungen bestätigen keine steuerliche Jahres-AfA.
Für nicht fachlich freigegebene unterjährige Fälle erzeugt der Manager keinen
E-Bilanz-Entwurf; vollständige Folgemonate heben diese Grenze nicht automatisch auf.
Vorhandene unbestätigte Teilmonatsstände bleiben erhalten. Der Menüweg
**Finanzen → Auswertungen & Steuer → Abschreibung & Buchwerte** zeigt die
vorhandenen Werte; Änderungen benötigen das passende Finanzrecht.

## Fristen erfasster Finanzanlagenrücklagen

Ein gespeicherter Quellenentwurf zeigt die ursprünglichen Fristen getrennt:
Für Ersatzanteile gilt grundsätzlich das Ende des zweiten Folgejahres, für
Ersatzgebäude das Ende des vierten Folgejahres. Eine dann noch vorhandene
Ursprungsrücklage ist grundsätzlich am Ende dieses vierten Folgejahres
aufzulösen. Das Ende der Anteilsfrist erzwingt daher keine vorzeitige
Auflösung der gesamten Restreserve.

Die angezeigten ursprünglichen Rechenansätze sind keine bestätigten Steuer-
oder Jahresendwerte. Eine vollständige Jahresrechnung benötigt sämtliche
Bewegungen. Der Gewinnzuschlag richtet sich nach dem tatsächlich nicht
steuerbefreiten aufgelösten Anteil und den vollen Bestandsjahren; ein fehlender
Steueranteil wird nicht durch einen pauschalen Wert ersetzt.

Diese Übersicht verändert weder gespeicherte Quellen noch Buchungen. Die
Anwendbarkeitsprüfung grenzt einen neu erfassten Anteilskauf einer GmbH nach
dem steuerlichen Einbringungsstichtag gesondert ab: § 6b Absatz 10 Satz 1
eröffnet Körperschaften keine neue Übertragung auf solche Ersatzanteile.
§ 8b Absatz 2 Satz 5 KStG betrifft bereits steuerwirksam vorgenommene Abzüge;
er schafft kein neues Übertragungsrecht. Bei taggleichen Angaben wird keine
Reihenfolge unterstellt. Bereits vor der Einbringung rechtmäßig vorgenommene
Abzüge und andere historische Sonderfälle benötigen eine getrennte Prüfung.
Die aktive Exportfreigabe von 72500/72550 folgt aus dieser Übersicht nicht.

Amtliche Grundlagen: [§ 6b EStG](https://www.gesetze-im-internet.de/estg/__6b.html),
[§ 8b KStG](https://www.gesetze-im-internet.de/kstg_1977/__8b.html) und die
[Gesetzesbegründung, Bundestagsdrucksache 14/6882, Seite 33](https://dserver.bundestag.de/btd/14/068/1406882.pdf).

## Private Identität bei Originalprüfungen

Die eingecheckten Originalbelege enthalten keine Betreiber-Herstellerkennung.
Ihre ursprünglichen XML- und Metadatenprüfsummen bleiben unverändert. Für die
bytegenaue Wiederholung wird `DVM_ORIGINAL_MANUFACTURER_ID` ausschließlich in
der privaten Testumgebung gesetzt. Ohne diese Angabe werden die betroffenen
Original-Wiederholungen ausdrücklich ausgelassen; es entsteht kein neuer
Abnahmenachweis. Synthetische Tests verwenden eigene Testkennungen.
