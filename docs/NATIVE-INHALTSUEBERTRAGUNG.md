# Native CMS-Inhalte übertragen

Die Websiteverwaltung bietet unter **Felder & Vorlagen → Inhalte übertragen**
einen nativen Export und Import des neuen CMS. Ein Paket kann eigene
Inhaltsmodelle, Seiten, Dateibezüge und optionale Sprachentwürfe auf einen anderen
lokalen CMS-Bestand übertragen. Das ist kein Altbestandimport, keine
Altbestandsmigration und kein Ersatz für ein Betriebsbackup.

## Bedienung und Umfang

1. Seiten suchen und auswählen oder **Modell und Bezüge herunterladen** wählen.
   Auch ein Modellexport nimmt die durch Feldregeln benötigten Seiten und Dateien
   auf. Gewählte Seiten werden um ihre benötigten Verknüpfungen erweitert;
   Sprachentwürfe können bewusst aufgenommen werden.
2. Das native ZIP-Paket im Ziel hochladen und **Paket prüfen** wählen. Die Prüfung
   schreibt noch keine Seiten, Felder, Vorlagen oder Zieldateien. Sie legt einen
   privaten, an Verein und Zugang gebundenen Prüfauftrag an.
3. Den Plan mit neuen Zieladressen und Kennungen prüfen. Kennung und Zieladressen
   lassen sich ändern und erneut prüfen. Jede Änderung erzeugt einen neuen
   gebundenen Planstand. Bei geändertem Zielbestand ist erneutes Prüfen nötig.
4. **Neue unveröffentlichte Entwürfe anlegen** ausdrücklich bestätigen und
   importieren. Erst dieser POST legt neue Inhalte an. Bestehende Zielseiten und
   Modelle werden nicht überschrieben; Veröffentlichung ist ein eigener späterer
   Redaktionsschritt.

Neue Seiten erhalten neue lokale IDs, genau ihre erste private Revision und keine
veröffentlichte Fassung. Sie erscheinen zunächst nicht automatisch im Menü.
Unterseiten, interne Richtextlinks, Feldregeln, Seiten-/Datei-/Bildbezüge,
Inhaltsabschnitte und wiederholbare Datensätze werden auf die geprüften lokalen
Kennungen umgebunden. Die Aufnahme aller nötigen Bezüge ist geschlossen: Ein
unbekannter Fremdbezug wird abgewiesen und nicht ungeprüft weitergereicht.

Passwortwerte und Passwortdigests verlassen den Quellbestand nicht. Erforderliche
Passwortfelder werden vor dem Import neu und lokal ausgefüllt. Nutzerkonten,
Sitzungen, Tokens, Anbieterzugänge, Freigaben, Bearbeiteridentitäten und
ausführbarer Erweiterungscode sind kein Paketinhalt. Es werden keine Mails,
Zahlungen, Bank-, Steuer- oder Social-Media-Aktionen ausgelöst.

## Originale, Sprachen und Rechte

Export liest die vollständigen berechtigten Originale; still gekürzte oder
teilweise ausgeblendete Originale ergeben keinen scheinbar vollständigen Export.
Verwaltungs- und Bearbeitungsrechte gelten zusätzlich zu Vorlagen-, Feld- und
Sprachrechten. Historisch strengere Feldrollen werden als endliche native
Rechtemengen mitgenommen, auch bei Unterfeldern und Sprachfassungen. Das Ziel
bewahrt deren kumulative Einschränkungen; ein Transfer lockert private Felder
nicht still auf die inzwischen weiter gefasste globale Definition.

Sprachentwürfe behalten ihre gesonderten Rechte und werden ebenfalls nur neu und
unveröffentlicht angelegt. Interne Links in Textinhalt, Richtextfeldern,
Startseiten-Herotext und Inhaltsabschnitten werden passend umgebunden; normale
Textfelder bleiben unverändert. Ein lokalisierter Startseitenaufbau benötigt im
Ziel eine ausdrücklich geprüfte Zuordnung auf die freie Adresse `start`.
Solange diese Zuordnung nicht möglich ist, bleibt der Import gesperrt und der
Auftrag kann angepasst oder abgebrochen werden.

Exportoriginale und Paketbytes bleiben unverändert. Allgemeine Dateien und PDFs
werden anhand ihrer tatsächlichen Formate geprüft und bytegleich importiert.
Bilder durchlaufen im Ziel die reguläre native Bildprüfung und sichere
Neukodierung. Dabei entstehen neue Bildfassungen; Quellpaket-Hash,
Quellbild-Hash und Zielbild-Hash stehen im privaten Auftragsnachweis.
Bytegleichheit von Quell- und Zielbild wird ausdrücklich nicht vorausgesetzt.
Private Auftragsablage und tatsächlicher Medien-/Dateispeicher dürfen auf
verschiedenen Laufwerken liegen; die Zieldownloads verwenden dieselben
Assetpfade wie die übrige Anwendung.

## Grenzen und Wiederaufnahme

Ein Paket enthält höchstens 100 Seiten, insgesamt 200 Felder und Vorlagen,
100 Assetbezüge und 1.000 Sprachentwürfe. Die JSON-Datei ist auf 16 MiB, die
entpackte Gesamtgröße einschließlich Manifest auf 128 MiB und das ZIP auf
256 MiB begrenzt. Zusätzlich gelten geschlossene Archivnamen, Eintrags- und
Entpackgrenzen sowie Budgets für JSON-Strukturen, 2.000 aufgelöste Feldzuordnungen,
2.000 Datensätze, 20.000 Feldwerte und 64 lokale Passwortangaben. Größere
Bestände werden in bewusst gewählten Teilpaketen übertragen. Fremde
ZIP-Verzeichnisstrukturen, ZIP64 und ausführbare Pakete werden nicht akzeptiert.

Vor dem Anlegen werden Paketbytes, Planversion, Zielbestand und aktuelle Rechte
erneut geprüft. Seiten- und Modellschritte laufen in einer gemeinsamen
Transaktion; Fehler ergeben keinen halben Inhaltsbestand. Dateianlagen gehören
zum selben Uploadauftrag und werden bei einem fehlgeschlagenen Import
zurückgenommen. Wiederholtes Absenden desselben abgeschlossenen Planstands legt
keine zweiten Seiten an. Ein alter oder zwischenzeitlich veränderter Stand
überschreibt keine Originale.

Offene Aufträge bleiben in **Eigene Aufträge** erreichbar und können bewusst
abgebrochen werden. Nach Abschluss oder Abbruch lassen sich ausschließlich die
privaten Paketkopien entfernen; importierte Zielinhalte und Quelldateien bleiben
erhalten. Suchen, Planprüfung, Korrektur, Import, Abbruch und Bereinigung
funktionieren auch ohne JavaScript. Treffer und Planseiten sind in Zehnergruppen
aufgeteilt; Handyansicht, Tastatur und Zoom sind tatsächlich geprüft.

## Abnahme und Anschlussgrenze

Die eingefrorene P23-Fassung besitzt drei getrennte Schlussnachweise:

| Prüfung | Ergebnis | Protokoll und SHA-256 |
| --- | --- | --- |
| Fachverträge und angrenzende Frontendbearbeitung | 45 Paket-, 79 Transfer- und 47 Frontendbedingungen, alle grün | `/tmp/kulturhus-cms-transfer-final-domain.log` · `64938dcf91dc7bd1ae2971796f8f28fd24ccf364cc0457a1eb81d99d237e4997` |
| Tatsächlicher integrierter Manager, JS und NoJS | 26 Ansichten, 676 Browserbedingungen, zwei echte Downloads und zwei neue Importe | `/tmp/kulturhus-cms-transfer-integrated-final.log` · `32cedbfaf8f9e82f77bb83043b3390f682a18cdb2d7a527a4f2ec2bfdd5d5f25` |
| Tatsächlicher eigenständiger nativer Router, JS und NoJS | 26 Ansichten, 676 Browserbedingungen, zwei echte Downloads und zwei neue Importe | `/tmp/kulturhus-cms-transfer-native-final.log` · `b00a57d063423c2fa37e762ca0fa8b1e43f2e07064607203363b2d6e88d744e2` |

Beide Appprüfungen vergleichen jeweils 421 ursprüngliche Tabellen sowie Schlüssel
und alte Medien exakt. Sie benötigen keine externen Aktionen. Die Paketprüfung
besteht zusätzlich unter 64 MiB PHP-Speichergrenze. Ein unabhängiger Review hat
acht belegte Randfälle nachgeprüft; deren Korrekturen sind im Schlussstand
enthalten.

Die spätere Zusammensetzung mit registrierten lokalen Feldtypen/Inputfields
(P02/P04) und Frontendrenderern (P08) erhält eine eigene Folgeabnahme.
Deren überprüfte Providerbindungen gehören noch nicht zum eingefrorenen
P23-Paketformat. Die Anschlussprüfung muss solche Bindungen ausdrücklich
ablehnen oder über einen bewussten geprüften Formatvertrag übernehmen; sie darf
sie weder still entfernen noch Normalisierungen unbemerkt erneut ausführen.
Die vorhandenen portablen nativen URL-Regeln bleiben Teil des Paketvertrags.

## Getrennte kuratierte Auslieferung

Die unveränderliche Exportfassung auf dem abgeschlossenen API-/Routing-/Datei-/Kontenstand besteht zehn Fachgruppen mit zusammen 831 Bedingungen: Paket, Transfer, Feldrechte, Frontendbearbeitung, Querybudget, API, Formulargruppen, Queries, Dateien und Sprachen. Beide echten Apps bestehen jeweils separat 676 Browserbedingungen in 26 Ansichten mit und ohne JavaScript, zwei ZIP-Downloads und zwei neuen bestätigten Importen. Jeweils 417 ursprüngliche Tabellen, Schlüssel und Medien bleiben exakt; neue eigene Transferdaten werden gesondert geprüft. Keine externe Aktion wird ausgelöst.

- `kulturhus-transfer-isolated-domain.log`, SHA-256 `ff964591b3b1e5bad37583bb4c22acf7665e8104d624c20ea75071779a7663c1`.
- `kulturhus-transfer-isolated-integrated-browser.log`, SHA-256 `6c7934c9bf1f2b5d02a674ac081cdcee5c8bfe31550ebcf920eec2b186d5e287`.
- `kulturhus-transfer-isolated-native-browser.log`, SHA-256 `16722525c44bedabb5d24a8fe2bee9f547ee20fde4d0fdb713cfc2f486fbe201`.

## Anschluss an registrierte Typen und Frontendrenderer

P-02/P-04/P-08 sind jetzt ausdrücklich angeschlossen: Feld-/Inputfield-/Rendererbindungen sind lokaler überprüfter Hostcode und verlassen den Quellbestand nicht als scheinbar portables natives Modell. Export verweigert solche nonnull Bindungen vor dem Wegschneiden von Eigenschaften, auch in tatsächlichen verschachtelten Originalen und Seiten-/Vorlagensnapshots. Native aktuelle Modelle bleiben exportierbar; historische Seiten und bereits erzeugte Paketbytes bleiben exakt und werden nicht nachnormalisiert. Der kuratierte Nachweis besteht 38 tatsächliche API-/Export- und 79 Transferbedingungen, ohne Teilziel oder externe Aktion. [Vertrag](NATIVE-API-MODELLE.md).
