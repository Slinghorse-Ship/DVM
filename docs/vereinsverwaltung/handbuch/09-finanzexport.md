## 9. Finanzexport und Auswertungen

Für Exporte genügt ein entsprechend berechtigter Finanz-Lesezugang. Entscheide vor dem Download, was benötigt wird: die Belegliste, Originalbelege, eine Auswertung tatsächlicher Zahlungen oder ein DATEV-Zahlungsstapel. Diese Dateien beantworten unterschiedliche Fragen und ersetzen einander nicht.

### Zeitraum prüfen und die richtige Exportdatei wählen

**Menüweg:** Finanzen → Auswertungen & Steuer → Export & DATEV. **Voraussetzung:** Finanz-Leserecht.

1. Trage unter **1. Zeitraum auswählen** bei **Belege von** und **Belege bis** den gewünschten Zeitraum ein. Hier zählt das Belegdatum, nicht der Zahlungstag.
2. Klicke auf **Zeitraum prüfen**. Unter **2. Hinweise prüfen und berichtigen** erscheinen offene Kontierungen und Zahlungszuordnungen. Jeder Hinweis hat einen Link zum betroffenen Beleg oder zu dessen Positionen.
3. Fehlen Sphäre und Steuerkonto, öffne **Kontierung dieser Rechnung prüfen**. Kläre den tatsächlichen Vereinsbereich und die passende Kontoart. Eine Rechnung ohne Zahlung kann richtig sein: Lege keine erfundene Zahlung an, nur um den Hinweis zu beseitigen.
4. Kehre nach einer Korrektur zur Exportseite zurück, wähle den Zeitraum wieder und prüfe erneut. Ein fehlerfreies Ergebnis dieser ersten Kontrolle ist noch keine steuerliche Abnahme und keine DATEV-Prüfung. Originaldateien und Freigaben sind zusätzlich zu prüfen.
5. Unter **3. Passendes Paket herunterladen** ist der gewählte Zeitraum vorbelegt. Prüfe **Belegtyp** und **Status**. Mit **CSV herunterladen** erhältst du die Belegliste, mit **Belegpaket als ZIP herunterladen** zusätzlich die zugänglichen Originaldateien.
6. Öffne den Download. Im ZIP prüfst du insbesondere **LIESMICH.txt**, Liste, Originaldateien und Zuordnungsverzeichnis. Fehlende oder geschützte Dateien werden vermerkt; ein solches Paket ist nicht automatisch vollständig.
7. Für eine Excel-Auswertung tatsächlicher Zahlungen benutze **Excel-Auswertungen und Buchungsjournal öffnen**. Für einen DATEV-Zahlungsstapel folgt auf derselben Seite eine eigene Vorschau mit Zahlungszeitraum und abgestimmten DATEV-Angaben.

**Fehler/Korrektur:** Bei zu vielen Belegen den Zeitraum eingrenzen. Bei fehlender Originaldatei den betreffenden Beleg und dessen Dokumentzuordnung prüfen. Nach Änderungen das Paket erneut erzeugen. Ein bereits in DATEV gebuchter Zeitraum darf nicht nochmals importiert werden.

Ungültige Datumswerte bleiben als ganze korrigierbare Eingabe sichtbar. Mehrteilige Angaben öffnen keine andere Belegauswahl; lies bei Bedarf **Ursprünglich übermittelte Angaben lesen** und verwende **Exportformular ohne bisherige Angaben neu öffnen**. Nach einem fehlgeschlagenen Download werden dessen ursprüngliche Datumsfelder und Auswahlwerte weiter angezeigt. **Zeitraum prüfen** erhält dabei die bisherige Auswahl für Belegtyp und Status. Der CSV-Download legt seine tatsächlich ausgegebenen Bytes als geschütztes Exportdokument ab; CSV und Belegpaket protokollieren die Ausgabe. Die ursprünglichen Belege und Zahlungen bleiben erhalten.

:::bild finanz-export

### Einnahmen und Ausgaben für ein Quartal auswerten

**Menüweg:** Finanzen → Auswertungen & Steuer → Auswertungen oder Export & DATEV → **Excel-Auswertungen und Buchungsjournal öffnen**. **Voraussetzung:** Finanz-Leserecht.

1. Wähle im Filter **Einnahmen & Ausgaben**.
2. Öffne bei schmaler Ansicht gegebenenfalls **Filter**. Stelle unter **Zeitraum** **Start** und **Ende** ein oder wähle zuerst das Jahr und dann beispielsweise **Q2**.
3. Klicke **Erstellen**.
4. Wähle für die Aufteilung **Woche**, **Monat** oder **Jahr**. Über **Tabelle** und **Grafisch** wechselst du die Darstellung.
5. Aktiviere bei Bedarf **Mit Vorjahr vergleichen**. Prüfe die vergleichbaren Zeiträume und die ausgewiesenen Veränderungen.
6. Klicke **XLSX herunterladen**, um die Auswertung zu speichern. Über das Drucksymbol **Auswertung drucken** öffnest du die Druckansicht beziehungsweise den Browserdruckdialog.
7. Soll die Tabelle mehr Platz bekommen, klappe die Filter über **Einklappen** ein; später öffnest du sie wieder über **Filter**.

**Ergebnis:** Die Auswertung zeigt gebuchte Geldflüsse des gewählten Zeitraums, einschließlich ihrer Zuordnung. Eine noch unbezahlte Rechnung ist keine tatsächliche Einnahme oder Ausgabe dieses Zahlungsberichts.

**Fehler/Korrektur:** Eine fehlende Zahlung zuerst in **Bank & Kasse** und am Beleg prüfen. Eine Rechnung nicht erneut erfassen, nur weil sie in der Zahlungsauswertung fehlt. Bei Vorjahreswert 0 EUR kann keine sinnvolle prozentuale Veränderung berechnet werden; dann wird ein Strich angezeigt.

### Tabellenanzeige durchsuchen, ohne den Export zu verändern

**Menüweg:** Finanzen → Auswertungen & Steuer → Auswertungen → Tabellenansicht. **Voraussetzung:** Ein Bericht wurde erstellt.

1. Klicke das Suchsymbol **Tabelle durchsuchen**.
2. Gib im Feld **Durchsuchen** einen Suchbegriff ein und klicke **Suchen**.
3. Lies den Status unter der Suche. Er erklärt, dass Ergebnis und Export den gesamten Zeitraum betreffen.
4. Klicke **Zurücksetzen**, um wieder alle Berichtszeilen anzuzeigen.
5. Über **Spalten anpassen** kannst du verfügbare Anzeigespalten auswählen und mit **Speichern** übernehmen. **Abbrechen** verwirft diese Dialogänderung.

**Ergebnis:** Suche und Spaltenauswahl erleichtern das Lesen der aktuellen Ansicht.

**Fehler/Korrektur:** Eine reine Tabellensuche begrenzt nicht den Zeitraum im XLSX-Export. Soll nur ein bestimmter Monat exportiert werden, ändere **Start** und **Ende** im Berichtsfilter und klicke **Erstellen**. Nicht davon ausgehen, dass versteckte Spalten oder gefilterte Bildschirmtreffer den vollständigen Export ersetzen.

### Das Buchungsjournal prüfen

**Menüweg:** Finanzen → Auswertungen & Steuer → Auswertungen. **Voraussetzung:** Finanz-Leserecht.

1. Wähle **Buchungsjournal (DATEV)** im Auswertungsfeld.
2. Stelle **Start** und **Ende** ein und klicke **Erstellen**.
3. Prüfe Zahlungsdatum, Betrag, Transaktions-/Belegnummer und vorhandene Kontoangaben.
4. Verwende **Spalten anpassen**, wenn du zusätzliche Belegdetails sehen möchtest.
5. Klicke **XLSX herunterladen** oder **Auswertung drucken**.

**Ergebnis:** Du erhältst ein lesbares Zahlungsjournal zur Prüfung. Der Excel-Journalbericht ist nicht mit dem gesonderten DATEV-Buchungsstapel gleichzusetzen.

**Fehler/Korrektur:** Fehlende Kontierung wird am betreffenden Beleg unter **Positionen & Kontierung** geklärt. Fachliche Unklarheiten nicht durch beliebige Kontonummern ersetzen.

### Einen DATEV-Zahlungsstapel vorbereiten und prüfen

**Menüweg:** Finanzen → Auswertungen & Steuer → Export & DATEV → **Für die Buchhaltung: DATEV-Zahlungsstapel**. **Voraussetzung:** Finanz-Leserecht; abgestimmte DATEV-Daten und Kontierung.

1. Lies die Beschreibung des Exportumfangs: zugeordnete Zahlungen einschließlich Teilzahlungen und Gegenbuchungen, DATEV-Buchungsstapel Version 13, SKR42 und fünfstellige Sachkonten. Jahreskataloge sind für **2025 und 2026** hinterlegt. Offene Rechnungen, Anfangsbestände und Sachspenden gehören nicht zu diesem Zahlungsstapel.
2. Trage **Zahlungen von**, **Zahlungen bis**, **DATEV-Beraternummer** und **DATEV-Mandantennummer** ein. Ein Stapel darf nur ein Kalenderjahr umfassen; Kontierung und Katalogjahr müssen dazu passen. Der oben ausdrücklich gewählte Zeitraum wird vorgeschlagen. Prüfe ihn erneut, denn hier zählt das Zahlungsdatum. Für 2025 und 2026 sind die geprüften Schlüssel für gewöhnliche Inlandsfälle mit 7 und 19 Prozent hinterlegt. Steuerliche Sonderfälle bleiben gesperrt.
3. Ergänze für die aufgeführten Geldkonten die jeweilige **SKR42-Geldkontonummer**. Verwende die mit der Buchhaltung abgestimmte fünfstellige Nummer.
4. Klicke **DATEV-Vorschau erstellen**.
5. Prüfe Zeitraum, Saldo und Buchungszeilen. Die Tabelle zeigt **Datum**, **Beleg**, **Betrag**, **Geldkonto**, **Gegenkonto**, **BU**, **KOST1** und **Text**.
6. Bei **Download noch gesperrt** lies jeden Prüfpunkt. Verlinkte Belegnummern führen zur Stelle, an der beispielsweise Kontierungen zu korrigieren sind. Andere Punkte können Kontonummern oder Exportangaben betreffen.
7. Nach der Korrektur erstelle die Vorschau erneut. Der Export lässt fehlerhafte Buchungen nicht stillschweigend weg.
8. Wenn die technische Prüfung bestanden ist, klicke **DATEV-Buchungsstapel herunterladen**.

**Ergebnis:** Eine Datei für den manuellen Import in DATEV Rechnungswesen wird heruntergeladen. Es findet keine automatische Übermittlung oder Festschreibung statt. Die tatsächliche Importabnahme in DATEV ist noch gesondert vorzunehmen.

**Fehler/Korrektur:** Eine leere Vorschau bedeutet, dass für den Zeitraum keine exportierbaren Zahlungen vorliegen. Prüfe Zeitraum und Zuordnungen unter **Bank & Kasse**. Wurde seit der Vorschau eine Zahlung geändert, erstelle die Vorschau neu. Den gleichen Zeitraum nicht mehrfach importieren. Sind Rechnungen bereits in DATEV als Aufwand oder Ertrag gebucht, diesen Zahlungsstapel nicht ungeprüft zusätzlich importieren. Originalbelege kommen separat aus dem ZIP-Belegpaket.

Nach einer fehlerhaften Eingabe erscheint die frühere Vorschau nicht als Ergebnis der neuen Angaben. Korrigiere die ganzen Werte und fordere **DATEV-Vorschau erstellen** erneut an. Mehrteilige Angaben benötigen **DATEV-Formular mit neuen Angaben öffnen**. Passt ein ausdrücklich gewählter Zeitraum nicht zur gespeicherten Vorschau, erstelle eine neue Vorschau oder verwende **Gespeicherte DATEV-Vorschau ausdrücklich öffnen** für den tatsächlich früheren Zeitraum.

:::bild finanz-datev

### Eine Erstattung zu einer eigenen Ausgangsrechnung exportieren

**Beispiel:** Der Verein hat 119 EUR für eine Leistung erhalten. Später werden 59,50 EUR gutgeschrieben und tatsächlich zurückgezahlt.

1. Öffne die **Originalrechnung** unter **Finanzen → Belege & Spenden → Ausgangsrechnungen**. Öffne **Rechnung korrigieren: Storno oder Gutschrift** und wähle **Gutschrift für bezahlten Anteil**. Gib Betrag, Datum und Grund an und klicke **Korrekturdokument ausgeben**. Das Dokument allein ist noch keine Bankzahlung.
2. Wenn die Erstattung auf dem Konto erfolgt ist, ordne diesen vorhandenen Umsatz der Gutschrift zu. Eine bereits importierte Überweisung nicht nochmals als manuelle Zahlung erfassen. Erfolgt die Erstattung in Teilen, ordne jede tatsächliche Teilzahlung nur einmal zu.
3. Prüfe unter **Positionen & Kontierung** an der Originalrechnung den Vereinsbereich, das Erlöskonto und die Umsatzsteuer. Für einen gewöhnlichen Inlandsumsatz sind die unterstützten Umsatzsteuerschlüssel **3 oder 101 bei 19 Prozent**, **2 oder 102 bei 7 Prozent** vorgesehen. Verwende sie nur, wenn diese Steuerbehandlung auf die ursprüngliche Leistung zutrifft.
4. An der Gutschrift müssen Erlöskonto und Vereinsbereich zu der Originalposition passen. Ein eigener Rückzahlungsbeleg mindert den Erlös; er ist keine neue Lieferantenrechnung. Deshalb dort keinen Vorsteuerschlüssel und keinen Vorsteuerabzug auswählen.
5. Öffne **Export & DATEV**, wähle den Zeitraum der tatsächlichen Erstattung und erstelle die Vorschau. Bei der Zeile steht **Gutschrift zu …** mit der Originalnummer. Das Beispiel weist 59,50 EUR Rückzahlung, 50 EUR Erlösminderung netto und 9,50 EUR Umsatzsteuerkorrektur aus.
6. Lies eventuelle Prüfpunkte. Fehlt der Schlüssel an der Originalrechnung, führt **Hier berichtigen / prüfen** direkt zu deren Kontierung. Danach erstelle die Vorschau erneut. Erst ohne Fehler kann der ganze Stapel heruntergeladen werden.

**Ergebnis:** Exportiert wird die tatsächliche Rückzahlung einschließlich ihrer Umsatzsteuerkorrektur. Offene Gutschriften ohne Erstattung bleiben außerhalb dieses Zahlungsstapels. Die Originalrechnung, das Gutschrift-PDF und der Kontoumsatz werden beim Export nicht verändert.

**Fehler/Korrektur:** Bei einem Rundungshinweis Original und Gutschrift mit der Buchhaltung prüfen. Besonders kleine Teilbeträge können eine Centdifferenz zwischen Dokument und DATEV-Steuerberechnung erzeugen. Dafür keine weitere Zahlung oder eine zweite Gutschrift zum bloßen Ausgleich anlegen. Ausland, Reverse Charge, Automatikkonten und andere nicht unterstützte Fälle werden nicht automatisch exportiert. Eine Originalrechnung von 2025 mit Erstattung 2026 ist möglich, wenn beide Belege nach ihrem jeweiligen geprüften Jahreskatalog kontiert sind.

### Eingegangene Rechnungen finden und hochladen

**Menüweg:** Finanzen → Rechnungseingang. Zum Lesen genügt Finanz-Leserecht; für Upload und Abruf benötigst du Finanz-Bearbeitungsrecht.

1. Öffne **Noch zu prüfen**, **Zugeordnet** oder **Archiviert**. Die Zahl am Reiter zeigt, wie viele Rechnungen in diesem Bearbeitungsstand vorliegen.
2. Gib bei **Rechnung suchen** einen Teil des Titels, Dateinamens oder E-Mail-Absenders ein. Du kannst mehrere Wörter kombinieren. Wähle bei Bedarf das **Belegjahr** und klicke **Suchen**. Die Suche berücksichtigt auch Rechnungen auf späteren Seiten.
3. Pro Seite werden höchstens zwölf Rechnungen angezeigt, die neuesten Belegdaten zuerst. Mit **Weiter** und **Zurück** wechselst du die Seite. **Suche zurücksetzen** entfernt Suchtext und Jahresfilter.
4. Klicke bei der passenden Rechnung **Prüfen und zuordnen** beziehungsweise **Rechnung öffnen**. **Zurück zur Rechnungsliste** führt anschließend zur bisherigen Suche zurück.
5. Für eine neue Datei klicke oben **Rechnung hochladen**. Der Abschnitt **Rechnungsdateien hochladen** öffnet sich. Wähle die Dateien oder ziehe sie hinein, prüfe Titel und Datum je Datei und bestätige anschließend die Ablage im Rechnungseingang. Eine einzelne Datei öffnet sich danach zur Prüfung; mehrere erscheinen in der Liste.
6. Mit **Neue E-Mails abrufen** holst du neue Nachrichten aus dem eingerichteten Rechnungspostfach. Ein Abruf versendet keine Nachricht und bestätigt keine Zahlung. Automatische Abrufe werden in den E-Mail-Einstellungen eingerichtet.

**Ergebnis:** Du findest den richtigen Beleg ohne eine endlose Liste. Eine neu hochgeladene Datei ist noch keine gebuchte Ausgabe; die Prüfung folgt am Beleg.

### E-Mails ohne Rechnungsanhang lesen

1. Öffne **Finanzen → Rechnungseingang** und klappe unten **E-Mails ohne Beleg** auf. Nach **Neue E-Mails abrufen** erscheinen dort auch neue Nachrichten ohne übernommene Rechnungsdatei.
2. Wähle **Noch zu lesen** oder **Abgelegt**. Über **E-Mail suchen** kannst du nach Betreff oder Absender suchen. Zehn Nachrichten werden pro Seite angezeigt; auch ältere Nachrichten bleiben über **Weiter** und die Suche erreichbar.
3. Klicke bei der gewünschten Nachricht **E-Mail lesen**. Absender, Datum und Nachrichtentext werden angezeigt. HTML-E-Mails werden in einfachen Text umgewandelt; Bilder, Skripte und fremde Inhalte werden nicht geladen.
4. Lies den Inhalt. Die Nachricht ist zunächst Korrespondenz und erzeugt keine Rechnung, Ausgabe, Freigabe oder Zahlung. Enthält der Text wichtige Rechnungsangaben, prüfe sie im vorhandenen Finanzablauf.
5. Bei Bedarf klicke **Original-E-Mail herunterladen (.eml)**. Das ist die unveränderte Originalnachricht. Bei sehr großen Nachrichten zeigt die Vorschau nur einen begrenzten Ausschnitt.
6. Ist die Nachricht erledigt oder unerwünscht, gib einen Grund ein und klicke **E-Mail ablegen**. Sie verschwindet aus **Noch zu lesen**; im Originalpostfach wird nichts gelöscht. Unter **Abgelegt** kannst du sie mit einem Grund wiederherstellen. Dafür ist Finanz-Bearbeitungsrecht erforderlich.

Eine Nachricht ohne übernommenen Beleg wird nicht durch eine künstliche PDF-Datei ersetzt. Bereits vor Einführung dieser Funktion übersprungene Nachrichten werden nicht automatisch rückwirkend neu eingelesen. Beschädigte oder zu große Nachrichten bleiben zur manuellen Prüfung im Postfach.

### Eine eingegangene Rechnung Schritt für Schritt erfassen

1. Öffnen Sie **Finanzen → Rechnungseingang** und bei der Datei **Prüfen und zuordnen**. Ist die Ausgabe schon erfasst, ordnen Sie das Original diesem Beleg zu.
2. Bei einer neuen Ausgabe beginnt das Formular mit **1. Wofür ist die Rechnung?**. Wählen Sie den **Rechnungszweck**. Bei einer Veranstaltung suchen Sie zusätzlich den Termin. Die vorgeschlagene Kostenart können Sie unter **Kostenart prüfen oder ändern** anpassen.
3. Die **Kleine Hilfe: Sphäre, Steuerkonto und BU** erklärt die Zuordnung direkt auf derselben Seite. Bei gemischter Verwendung wählen Sie **Mehrere Zwecke / gemischte Rechnung** und prüfen jede Position getrennt.
4. Unter **2. Angaben und Positionen prüfen** vergleichen Sie Lieferant, Rechnungsnummer, Datum, Fälligkeit und Bruttogesamtbetrag mit dem Original. Vorhandene Firmen suchen Sie im Kontaktfeld; fehlende können Sie direkt anlegen. PDF- und Bildrechnungen lassen sich auslesen, XML-Angaben werden strukturiert übernommen. Alle Vorschläge bleiben zu prüfen.
5. Prüfen Sie die erkannten Positionen samt Netto-/Bruttobasis, Steuern und Gesamtsumme. Gegenstände können direkt der passenden Position zugeordnet werden. Eine bereits bekannte Sphäre können Sie speichern, während das noch ungeklärte Steuerkonto offen bleibt. Vor dem steuerlichen Export muss auch das Konto geklärt sein. Die Rechnung wird einmal erfasst, auch wenn mehrere Gegenstände daran hängen.
6. Klicken Sie erst am Ende auf **Rechnung speichern**. Ausgabe und Original werden gemeinsam verknüpft. Danach folgen Rechnungsfreigabe und tatsächliche Zahlungszuordnung als eigene Schritte. Es wird nichts überwiesen.
7. Neue Gegenstände können Sie anschließend aus den gespeicherten Rechnungspositionen anlegen. Bereits vorhandene Anschaffungsbelege und dokumentierte Werte bleiben erhalten.

### Umsatzsteuer auf der Rechnung und Vorsteuerabzug unterscheiden

1. Übernimm den Steuersatz und Steuerbetrag so, wie sie korrekt auf der Originalrechnung stehen. Eine Rechnung mit 19 Prozent Umsatzsteuer wird nicht zu einer Rechnung mit null Prozent, weil der Verein keinen Vorsteuerabzug hat.
2. Prüfe bei der Ausgabe **Vorsteuerabzug für diese Ausgabe**. Bei **Kein Vorsteuerabzug – Bruttobetrag bleibt Kosten/Wert** bleibt der gesamte Bruttobetrag maßgeblich. Der BU-Schlüssel bleibt leer. Ein Vorsteuerschlüssel wie 9 würde einen anderen Vorgang ausdrücken.
3. Ist ein Vorsteuerabzug für genau diese Ausgabe geklärt, wähle die entsprechende Option und den passenden unterstützten BU-Schlüssel. Bei teilweise abziehbarer Vorsteuer muss die Ausgabe sachgerecht aufgeteilt und geprüft werden.
4. **Noch klären** lässt die Entscheidung sichtbar offen. Eine steuerhaltige Ausgabe mit ungeklärtem Vorsteuerabzug wird nicht als fertiger DATEV-Datensatz ausgegeben. Bearbeite den Hinweis in der steuerlichen Nacharbeitsliste.
5. Unter **Einstellungen → Steuereinstellungen & DATEV** kann eine Voreinstellung für neue Ausgaben hinterlegt werden. Sie ändert keine bereits gespeicherten Positionen. Gemeinnützigkeit oder eine Kleinunternehmerauswahl allein setzen den Vorsteuerabzug nicht automatisch.

Ein vorhandener Jahreskontenplan und ein vollständig unterstützter DATEV-Export sind unterschiedliche Voraussetzungen. Das Rechnungsjahr wird aus dem Originaldatum abgeleitet. Für ein Jahr ohne hinterlegten Originalkontenplan bleiben die Steuerkonten offen; ein neuerer Katalog wird nicht stillschweigend als alter Jahresstand ausgegeben. Der Export nennt seinen unterstützten Zeitraum und noch offene Prüfpunkte.

### Lineare Abschreibung und Buchwerte dokumentieren

Für interne Abschlüsse mit angebrochenen Monaten unter **Finanzen → Steuern →
Bilanz → Zeiträume → Neuen Zeitraum anlegen** die genauen Daten und den Nachweis
eingeben. Nach fachlicher Prüfung auch die zusätzliche **interne Tagesaufteilung**
bestätigen. Anfangsbestände ausdrücklich prüfen und anschließend die tatsächlichen
Beleg-/Anlagenvorschauen übernehmen. Die Aufteilung erhält die ursprünglichen
Monatsbeträge centgenau; ein unmittelbarer Folgeabschluss übernimmt Regel und
bestätigte Endwerte. Fehler lassen die Eingaben zur Korrektur stehen.

Nach der Abschlussprüfung bleiben die **Buchungsunterlagen** als ZIP zugänglich.
Eine interne Tagesaufteilung bestätigt keine steuerliche Jahres-AfA. Dafür
bietet der Manager keinen E-Bilanz-Entwurf an; auch vollständige Folgemonate
heben diese fachliche Grenze nicht automatisch auf. Alte unbestätigte
Teilmonatsoriginale bleiben unverändert lesbar. Einzelheiten und Rechenbeispiel:
[Interne datierte Abschlüsse](../../BILANZ-PRODUKTIVE-ZEITRAEUME.md).

**Menüweg:** Finanzen → Auswertungen & Steuer → **Abschreibung & Buchwerte**. Finanz-Leserecht genügt für vorhandene Werte; Pläne und Jahresstände benötigen Finanz-Bearbeitungsrecht.

1. Wähle den eigenen Gegenstand und klicke **Gegenstand öffnen**. Ein Inventarschätzwert wird nicht automatisch zur steuerlichen Bemessungsgrundlage.
2. Erfasse im **Abschreibungsplan** die fachlich geprüfte **Bemessungsgrundlage in EUR**, das tatsächliche Anschaffungsdatum, die **Nutzungsdauer** und den **Verbleibenden Restwert in EUR**. Nicht abziehbare Umsatzsteuer gehört zur Grundlage; abziehbare Vorsteuer nicht. Kläre diese Einordnung am Original.
3. Wähle den Vereinsbereich und gegebenenfalls ein geprüftes **Anlagekonto** des Anschaffungsjahres. Ohne verfügbaren geprüften Jahreskontenplan bleibt dieses Feld offen. Erläutere unter **Warum sind Bemessungsgrundlage und Nutzungsdauer richtig?** die tatsächliche Grundlage.
4. Speichere den Plan und prüfe die Vorschau. Wähle bei **Jahr ansehen** das gewünschte Jahr und klicke **Jahresvorschau anzeigen**. Angezeigt werden Anfangsbuchwert, lineare Abschreibung für die berücksichtigten Monate und Endbuchwert.
5. Für ein abgeschlossenes Kalenderjahr kannst du **Jahresstand … feststellen** wählen. Beginne beim Anschaffungsjahr und gehe lückenlos vor. Öffne den gespeicherten Jahresstand danach erneut zur Kontrolle.

**Ergebnis:** Die lineare Rechnung beginnt im Anschaffungsmonat und verwendet die ausdrücklich festgelegte Nutzungsdauer. Festgestellte Jahresstände enthalten den damaligen Plan unveränderlich. Weder die Vorschau noch das Feststellen erzeugen einen zweiten Kauf oder Kontoumsatz.

**Ursprüngliche Auswahl korrigieren:** Ein ungültiges oder nicht vollständig vierstelliges Jahr öffnet keinen Plan für ein anderes Jahr. Prüfe unter **Ursprüngliche Anlagenauswahl prüfen** die vollständige Gegenstandskennung und das ursprünglich angeforderte Jahr. Korrigiere beide Angaben und wähle **Gegenstand und Jahr prüfen**. Das liest den Plan; es bestätigt keinen Jahresstand. **Ursprüngliche Auswahl erneut prüfen** wiederholt genau die ursprüngliche Anfrage. Mehrteilige oder nicht unterstützte Angaben bleiben unter **Ursprünglich übermittelte Angaben lesen** sichtbar; öffne dafür bewusst **Gegenstandsauswahl ohne bisherige Angaben öffnen**. Dort wird kein Gegenstand automatisch gewählt. Ein zuvor gültig gewähltes Jahr bleibt bei der anschließenden Gegenstandsauswahl erhalten. Nach einem abgewiesenen Speichern bleiben die übermittelten Angaben lesbar; prüfe sie und öffne den ursprünglichen Plan erneut.

**Fehler/Korrektur:** Solange kein Jahresstand festgestellt ist, kannst du den Plan mit Begründung bearbeiten. Nach dem Feststellen sind rückwirkende Planänderungen gesperrt; einen fachlichen Korrekturfall gesondert klären, nicht denselben Gegenstand nochmals anlegen. GWG-/Sofortabschreibung, Sammelposten, degressive und Sonderabschreibung sind keine Varianten dieses linearen Ablaufs und werden nicht automatisch berechnet. Ein dokumentierter Buchwert ist nicht automatisch eine fertige DATEV-Abschreibungsbuchung oder steuerliche Anerkennung.
