## 4. Mitglieder aus Excel oder CSV übernehmen

Der Import ist für die Übernahme einer Mitgliederliste gedacht. Er zeigt vor dem Schreiben jede Zeile an, erkennt passende bestehende Kontakte und schützt vorhandene Mitgliedsnummern. Er ist kein pauschaler Massenänderungsweg für bereits gespeicherte Mitglieder.

### Eine selbsterklärende Excel-Vorlage laden

**Menüweg:** Mitglieder & Kontakte → Mitglieder → **Excel: Import & Export**. **Voraussetzung:** Schreibrecht für Mitglieder.

1. Klicke **Excel-Vorlage mit Anleitung herunterladen** und öffne **DVM-Mitglieder-Vorlage.xlsx**.
2. Lies das zuerst geöffnete Blatt **Anleitung**. Es erklärt jede Spalte, Pflichtangaben und Beispiele direkt in der Datei.
3. Trage die Personen ausschließlich im Blatt **Mitglieder** ein. **Name** und **Eintritt** sind mit einem Stern und farblich markiert. Beim Anwählen einer Zelle erscheint ein passender Hinweis. Den **Status** und **Familien-Beitragszahler (ja/nein)** kannst du aus Listen auswählen.
4. Verwende **Beispiel** nur zum Nachschlagen. Dieses Blatt und die Anleitung werden bei dieser Vorlage nicht importiert.
5. Speichere die ausgefüllte Datei als XLSX. Eine Datei kann bis zu 999 Personen neben der Überschrift enthalten.

**Ergebnis:** Du hast eine ausfüllbare Liste mit Erläuterungen. Die Mitgliedsnummer und Telefonnummer bleiben Text; führende Nullen gehen nicht verloren. Ohne bekannte Mitgliedsnummer das Feld leer lassen. Kein Eintrittsdatum erfinden: Ist nur das Jahr bekannt, reicht beispielsweise **2015**. Ein Geburtstag oder Austritt braucht ein vollständiges Datum.

### Die Datei vorbereiten
**Menüweg:** Excel-Datei auf deinem Gerät. Verwende möglichst die Vorlage aus dem Vereinsmanager.

1. Bearbeite eine Kopie und behalte das Original.
2. Jede Person erhält eine eigene Zeile, auch innerhalb einer Familie.
3. Bei eigenen XLSX- oder CSV-Dateien müssen die Spaltenüberschriften innerhalb der ersten 20 Zeilen stehen. Name oder Vorname und Nachname sind möglich.
4. Trenne die heutige **Mitgliedsnummer** und eine **Alte Mitgliedsnummer**. Formeln in zugeordneten Zellen zuerst als Werte speichern.
5. Geburtstag und Austritt als TT.MM.JJJJ oder JJJJ-MM-TT angeben. Beim Eintritt ist zusätzlich eine bloße Jahreszahl möglich.
6. Bei ehemaligen Mitgliedern den tatsächlichen Austritt eintragen. Gültige Statuswerte sind **aktiv**, **passiv** und **ehemalig**; ein leeres Statusfeld bedeutet aktiv.

**Ergebnis:** Die Liste ist für die Vorschau vorbereitet. Die gemeinsame Familien-E-Mail darf bei mehreren Personen stehen. Sie löst keine automatische Zusammenführung aus.

### Kontoverbindung, Jahresbeitrag und Familie ausfüllen

Die sieben zusätzlichen Spalten sind freiwillig. Leere Bank- und Familienfelder legen weder eine Bankverbindung noch eine Familie an.

| Spalte | So ausfüllen |
| --- | --- |
| **IBAN (Kontonummer)** | Vollständige IBAN eintragen. Die Prüfziffer wird geprüft. Eine alte Kontonummer ohne IBAN wird nicht umgerechnet. |
| **Kontoinhaber** | Bei einer Bankverbindung zusammen mit der IBAN erforderlich. |
| **BIC** | Falls vorhanden, den gültigen BIC ergänzen. |
| **Jahresbeitrag (EUR)** | Den aktuellen eingerichteten Einzel- oder Familienjahresbeitrag als Betrag, zum Beispiel `60,00`, ohne Eurozeichen und Tausendertrennzeichen eintragen. Leer verwendet den normalen Tarif; der Import richtet keinen individuellen Sonderbeitrag ein. Abweichende Beträge werden zur Klärung markiert. |
| **Familienkennung** | Für eine neue Familie bei allen zugehörigen Personen denselben selbst gewählten Wert, zum Beispiel `Familie-01`, verwenden. Namen oder gemeinsame E-Mail-Adressen allein ordnen keine Familie zu. |
| **Familienname / Bezeichnung** | Einen einheitlichen Namen für diese Familie angeben, zum Beispiel `Familie Müller`. |
| **Familien-Beitragszahler (ja/nein)** | In jeder neuen Familie genau eine Person mit **ja** markieren. Für Angehörige **nein** wählen. Beim Zahler gilt der Familienjahresbeitrag, bei den enthaltenen Angehörigen `0` oder leer. Es entsteht keine zusätzliche Angehörigenforderung. |

**Beispiel:** Anna und Mila bekommen beide die Familienkennung `Familie-01` und die Bezeichnung `Familie Müller`. Anna erhält **ja**, Mila **nein**. Bei Anna steht der eingerichtete Familienjahresbeitrag, bei Mila `0` oder nichts. Ein Einzelmitglied lässt die Familienfelder leer.

**Bestehende Familie:** Ein Mitgliederdatenexport enthält die eindeutige Kennung `ID:…`. Diese Kennung darf für eine ausdrücklich gewählte vorhandene Familie im eigenen Verein verwendet werden. Ihr Name und ihr Beitragszahler bleiben erhalten. Neue Zuordnungen bereits vorhandener Mitglieder sowie Änderungen vorhandener Bankverbindungen zuerst bewusst im Profil bearbeiten; Excel überschreibt sie nicht.

**Bankdaten:** Die Übernahme benötigt die aktuellen Bankverwaltungsrechte und ein eingerichtetes privates Bankdaten-Schlüsselsystem. Nach der Vorschau folgt die Bestätigung mit dem eigenen Konto-Passwort und gegebenenfalls dem eingerichteten zweiten Faktor. Bank-PIN und TAN gehören nicht in diese Datei oder dieses Formular. Die Kontoverbindung wird verschlüsselt gespeichert. Der Import erteilt kein SEPA-Mandat und löst keine Zahlung aus.

### Datei laden und das richtige Tabellenblatt auswählen
**Menüweg:** Mitglieder & Kontakte → Mitglieder → **Excel: Import & Export** → **2. Ausgefüllte Datei auswählen**.

1. Wähle unter **Mitgliederliste** deine XLSX- oder CSV-Datei bis 5 MB aus.
2. Klicke **Datei für Vorschau laden**. Das legt noch kein Mitglied an.
3. Prüfe unter **Diese Datei wird geprüft** den vollständigen Dateinamen. Jede Datei erhält ihre eigene Importkennung; eine zweite Datei in einem anderen Tab ersetzt die erste nicht.
4. Für eigene Arbeitsmappen gegebenenfalls **Tabellenblatt** und **Zeile mit Spaltenüberschriften** wählen und **Spalten anzeigen** klicken. Bei der DVM-Vorlage ist ausschließlich das Mitgliederblatt auswählbar.
5. Vergleiche Überschriften und die ersten drei Dateizeilen.

**Ergebnis:** Die ursprüngliche Datei ist eine Stunde zur Prüfung geöffnet. Bis zu vier Dateien können unabhängig vorbereitet werden. **Diese Vorbereitung verwerfen** entfernt nur die gewählte Vorbereitung, keine Mitglieder.

**Fehler/Korrektur:** Ein ungültiges Blatt wird nicht durch ein anderes ersetzt. Die übermittelten Angaben bleiben lesbar. Öffne bei Bedarf bewusst **Diese Datei mit den zuletzt geprüften Blattangaben öffnen**. Nach Ablauf die lokale Datei erneut auswählen.

### Die Spalten des Vereinsmanagers zuordnen
**Menüweg:** Dieselbe Datei → **3. Blatt und Spalten prüfen**.

1. Kontrolliere die vorgeschlagenen Zuordnungen. Die 17 Spalten der Vorlage werden automatisch erkannt. Bei mehreren passenden Überschriften bleibt das betreffende Feld offen.
2. Ordne **Vollständiger Name** oder getrennt **Vorname** und **Nachname** zu.
3. Prüfe Mitgliedsnummer, alte Nummer, Kontaktdaten, Geburtstag, Eintritt, Austritt und Status sowie die gewünschten Bank-, Beitrags- und Familienfelder. Eine Dateispalte darf nur einem Feld zugeordnet sein.
4. Nicht benötigte Felder auf **Nicht übernehmen** lassen.
5. **Eintritt nur für leere Zeilen ergänzen** nur ausfüllen, wenn diese Angabe tatsächlich für alle betroffenen Leerstellen stimmt. Auch hier ist ein bloßes Jahr zulässig.
6. Klicke **Vorschau und Dubletten prüfen**.

:::bild member-import

**Ergebnis:** Die ganze Datei wird geprüft, bevor Mitgliederdaten gespeichert werden. Ein Eintritt **2015** bleibt jahrgenau; daraus wird kein als bekannt behaupteter Tag. Ein ungültiger Wert **0** wird nicht durch einen Standardwert ersetzt.

### Die vollständige Vorschau lesen
**Menüweg:** Dieselbe Datei → **4. Vorschau und Dublettenprüfung**.

1. Vergleiche die Gesamtzahlen neuer, bereits vorhandener und zu klärender Mitgliedschaften.
2. Lies die Dateizeile, den unveränderten Namen, Mitgliedsnummern und Eintritt/Austritt jeder Karte.
3. Öffne **Alle Felder prüfen** für die übrigen Angaben.
4. Prüfe unter **Passende vorhandene Profile prüfen** die ursprünglichen Personen. Bereits vorhandene Mitglieder werden übersprungen; ein vorhandener Kontakt kann ausdrücklich als Mitglied geführt werden.
5. Gleiche E-Mail-Adressen werden als Hinweis gezeigt und führen keine Familienpersonen zusammen.
6. Mit **Vorschau eingrenzen** kannst du Konflikte oder vorhandene Mitglieder ansehen. **Vorherige Seite** und **Nächste Seite** zeigen jeweils höchstens 25 Zeilen. Die Gesamtprüfung umfasst weiterhin die ganze Datei.

**Ergebnis:** Du siehst, was übernommen werden soll. Das Filtern oder Wechseln einer Vorschauseite importiert nichts. Auf kleinen Bildschirmen stehen die vollständigen Angaben untereinander.

### Konflikte korrigieren und erst dann übernehmen
**Menüweg:** Importvorschau und deine lokale Dateikopie.

1. Kläre doppelte Namen oder Nummern innerhalb der Datei sowie widersprüchliche Zuordnungen zu vorhandenen Profilen.
2. Gehört eine Mitgliedsnummer zu einem anderen Namen, wird keine Nummer ersetzt. Prüfe das verlinkte ursprüngliche Profil und korrigiere die Datei.
3. Prüfe auch abweichende Geburtsdaten, fehlende Eintritte, falsche Austritte, IBAN-Prüfziffern, Beiträge und Familienzahler. Bei archivierten Profilen zuerst das geschützte Archiv prüfen.
4. Lade eine korrigierte Datei erneut oder erstelle nach geänderter Zuordnung eine neue Vorschau. Veränderte Bestandsdaten erfordern ebenfalls eine neue Prüfung.
5. Prüfe sämtliche Seiten einschließlich aufgelöster Familien, Zahler und Jahresbeiträge. Enthält die Datei neue Bankverbindungen, bestätige zuerst **Bankdatenübernahme bestätigen** mit dem eigenen Konto-Passwort und gegebenenfalls Sicherheitscode. Diese kurze Bestätigung gilt nur für genau diese Vorschau. Anschließend bestätige **Ich habe die gesamte Vorschau und die Dubletten geprüft …**.
6. Klicke **… Mitgliedschaften jetzt übernehmen**.

**Ergebnis:** Die geprüfte Datei wird zusammenhängend übernommen. Ein unklarer Datensatz sperrt die gesamte Übernahme. Ein Formular aus einem anderen Tab kann nicht unter der falschen Dateikennung ausgeführt werden.

### Nachkontrolle und wiederholter Import
**Menüweg:** **Import abgeschlossen** und anschließend die Mitgliederliste.

1. Prüfe Dateiname und Zahl der übernommenen Mitgliedschaften.
2. Öffne **Übernommene Profile kontrollieren** und vergleiche Nummern, Kontaktdaten, Eintrittsgenauigkeit und Status mit dem Original.
3. Bestehende Profile, Familien und Mitgliedsnummern bleiben erhalten. Prüfe die ausdrücklich importierte Familie und ihren Zahler im Profil. Neue Bankverbindungen sind dort geschützt erreichbar; ein Mandat wurde nicht erteilt.
4. Eine erneut geöffnete Bestätigung oder ausdrücklich wiederholte Bestätigung derselben unveränderten Übernahme erzeugt keinen zweiten Import. Eine abweichende Vorschau wird nicht als dieselbe Übernahme quittiert.
5. Beim erneuten Laden derselben Datei erkennt die Vorschau vorhandene Mitglieder. Es werden keine Mails versendet oder Zugangsmails vorgemerkt.

**Ergebnis:** Die Übernahme ist nachkontrolliert. Der Import ist kein stiller Massenänderungsweg: Bestehende Mitglieder werden nicht mit geänderten Excelwerten überschrieben. Ändere solche Daten bewusst im vorhandenen Profil.

**Unbestätigtes Ergebnis:** Bei einer unterbrochenen Antwort nicht einfach eine zweite Datei übernehmen. Die ursprüngliche Kennung und Meldung behalten, die Mitgliederliste und gegebenenfalls vorhandene Profile lesen und den tatsächlichen Speicherstand prüfen. Eine überholte Vorschau wird verweigert; eine erneute Datei durchläuft wieder die vollständige Dublettenprüfung. Die Sitzungsbestätigung ersetzt kein dauerhaftes Importarchiv.

### Mitgliedsdaten über alle Ergebnisseiten als Excel exportieren

**Menüweg:** Mitglieder & Kontakte → Mitglieder → **Excel: Import & Export** → **Mitglieder mit ihren Mitgliedsdaten als Excel exportieren**.

1. Wähle aktuelle Mitglieder, alle Mitglieder einschließlich ehemaliger oder nur ehemalige Mitglieder.
2. Grenze die Suche bei Bedarf ein. Pro Datei sind höchstens 999 Personen möglich; bei mehr Treffern getrennte Exporte erzeugen.
3. Klicke **Mitgliederdaten als Excel herunterladen**. Der Export erfasst sämtliche passenden Ergebnisseiten, nicht nur die gerade sichtbare Liste.
4. Öffne **DVM-Mitglieder.xlsx**. Sie enthält Name, Mitgliedsnummer, alte Nummer, E-Mail, Anschrift, Telefon, Geburtstag, Eintritt, Austritt, Status, aktuellen Jahresbeitrag und die ausdrückliche Familienkennung samt Bezeichnung und Zahler. Die drei Bankspalten bleiben beim normalen Export leer.
5. Prüfe führende Nullen und nur jahresgenau bekannte Eintritte. Das Mitgliederblatt kann wieder mit Vorschau eingelesen werden; vorhandene Mitglieder werden erkannt und nicht nochmals angelegt.

**Ergebnis:** Der aktuelle oder letzte Mitgliedschaftsstand ist exportiert. Archivierte Profile und fremde Vereine bleiben geschützt. Frühere Familien-, Beitrags-, Bank- und Mitgliedschaftsverläufe sowie Buchungen sind nicht vollständig enthalten; eine vollständige Sicherung bleibt ein eigener Vorgang. Der Download ändert keine Mitgliedsdaten und versendet keine Mail.

### Bankverbindungen bewusst in den Mitgliederdatenexport aufnehmen

**Voraussetzung:** Aktuelle Bankverwaltungsrechte und aktuelle Mitgliedschaften für die einzuschließenden gespeicherten Bankverbindungen.

1. Wähle Gruppe und Suche im Mitgliederdatenexport. Aktiviere **Bankverbindungen nach persönlicher Bestätigung einschließen** und fordere den Export an.
2. Prüfe die getrennte Exportauswahl mit den Mitgliedern und der Anzahl gespeicherter Bankverbindungen. Die vollständigen Kontodaten werden hier noch nicht angezeigt.
3. Bestätige **Bankexport bestätigen** mit dem eigenen Konto-Passwort und gegebenenfalls Sicherheitscode. Eine vorherige Importbestätigung gilt nicht für einen Export.
4. Klicke innerhalb der kurzen Bestätigungsfrist **Geprüfte Excel-Datei herunterladen**. **DVM-Mitglieder-mit-Bankdaten.xlsx** enthält die vollständigen gespeicherten IBAN, Kontoinhaber und BIC genau dieser Auswahl. Ohne gespeicherte Bankverbindung bleiben die Felder leer.
5. Verwahre die heruntergeladene Datei geschützt und schließe die Exportauswahl bewusst über **Diese Exportauswahl schließen**.

**Fehler/Korrektur:** Geänderte Personen, Tarife, Familien, Bankverbindungen oder Rechte machen eine frühere Auswahl ungültig. Schließe sie bewusst und prüfe eine neue Auswahl. Für eine Auswahl ohne gespeicherte Bankverbindungen ist keine Entschlüsselungsbestätigung erforderlich. Zugangsdaten, Mandate und Zahlungsvorgänge sind nicht Bestandteil dieser Datei. Der Download erzeugt einen internen Exportnachweis, verändert aber keine Bankverbindung und erteilt kein Mandat.

### Eine ausgewählte Mitglieder- oder Kontaktliste als Excel herunterladen

**Menüweg:** Mitglieder & Kontakte → gewünschte Mitglieder- oder Kontaktliste. **Voraussetzung:** Bearbeitungsrecht für Mitglieder und Kontakte.

1. Öffne die passende Liste und grenze sie bei Bedarf mit **Mitglied oder Kontakt suchen** beziehungsweise **Filter anzeigen** ein.
2. Klicke **Spalten auswählen**, wenn du beispielsweise E-Mail oder Anschrift im Export nicht benötigst. Abgewählte Kontaktdatenspalten werden auch nicht exportiert; der Name bleibt enthalten.
3. Markiere links an jeder gewünschten Zeile das Auswahlkästchen. Das Kästchen in der Kopfzeile wählt alle gerade angezeigten Profile aus. Es wählt nicht automatisch weitere Ergebnisseiten. Oben steht die Anzahl der ausgewählten Profile.
4. Klicke unter der Liste **Auswahl als Excel**. Solange nichts ausgewählt ist, bleibt dieser Knopf gesperrt.
5. Der Browser lädt **kulturhus-kontakte.xlsx** herunter. Öffne die Datei aus deinem Downloadordner. Sie enthält ausschließlich die ausgewählten Profile und die gewählten Kontaktdatenspalten. Telefonnummern, führende Nullen und mehrzeilige Anschriften bleiben als Text erhalten. Die Kopfzeile ist beim Scrollen fixiert; die kleinen Filterpfeile in Excel helfen beim Eingrenzen.
6. Benötigst du eine Textdatei für ein anderes Programm, klicke stattdessen **Als CSV**. Dafür wird dieselbe Auswahl verwendet.

**Ergebnis:** Die ausgewählte Liste wurde heruntergeladen und als interner Export in der Dokumentenablage protokolliert. Kein Profil wird verändert und es wird keine Nachricht verschickt. Pro Export sind höchstens 100 Profile möglich.

**Fehler/Korrektur:** Fehlen erwartete Profile, prüfe vor einem neuen Download die Suche, den Filter, die aktuelle Ergebnisseite und die Auswahlkästchen. Dieser Kontaktdatenexport ersetzt keine vollständige Sicherung aller Mitgliedschaften, Familien- und Beitragsdaten. Änderungen in der heruntergeladenen Excel-Datei ändern den Vereinsmanager nicht automatisch.
