# Gemeinsame Grundlage der öffentlichen Website

Alle fünf Vorlagen – Kulturhus, Klar, Magazin, Aktiv und Natur – laden dieselbe
lokale Bootstrap-Version `6.0.0-alpha.1` und die gemeinsamen Formularanpassungen.
Die Gestaltung jeder Vorlage bleibt über deren eigene nachfolgende Stylesheets
definiert. Auf öffentlichen Seiten werden nur die benötigten öffentlichen
Interaktionen geladen. Die Bootstrap-Dateien kommen aus dem ausgelieferten
Repository; ein CDN ist nicht erforderlich.

Seitenwahllisten in öffentlichen Galerien und privaten Albumansichten verwenden
die tatsächlich in dieser Version vorhandene Klasse `form-control`. Der native
Auswahlpfeil bleibt sichtbar. Eingabefelder haben mindestens 16 Pixel Schriftgröße
und 44 Pixel Höhe. Eine Bootstrap-5-Klasse gilt nicht als Nachweis, dass das
entsprechende Element von der eingebundenen Version unterstützt wird.

Das Kulturhus-Design bricht lange Namen, Überschriften und Texte bei schmalen
Ansichten um. Hero-Inhalte, Veranstaltungskarten, Beitragslinks und der
Datumsbereich passen auch bei 200 % Zoom in die Ansicht. Auf breiten Ansichten
bleibt die ursprüngliche mehrspaltige Veranstaltungsgestaltung erhalten. Das
öffentliche Beitrittsformular erhält dieselbe Hülle; Beiträge, Vorteile,
Formulargruppen und Vereinsname benötigen bei schmaler Ansicht kein seitliches
Scrollen. Die Vereinsvorgaben und fachlichen Aufnahmeentscheidungen ändern sich
durch diese Darstellung nicht.

## Tatsächliche Prüfung

`public-framework-app-browser.mjs` prüft 174 Verträge an den beiden laufenden
eigenen Anwendungen. Alle fünf authentifizierten Designvorschauen werden bei
320, 390 und 820 Pixeln mit 200 % Zoom geöffnet, jeweils mit und ohne JavaScript.
Die Vorschau ist rein lesend und wechselt keine gespeicherte Designentscheidung.
Mit JavaScript öffnet und schließt die Tastatur die tatsächliche Navigation.
Footerlinks behalten mindestens 44 Pixel große Ziele.

Das bereits veröffentlichte Beitrittsformular wird zusätzlich im integrierten
Manager geprüft: nativer Auswahlpfeil, Familienauswahl mit JavaScript,
erhaltene ungesendete Eingaben und lesbare Touchfelder. Im eigenständigen
synthetischen Bestand ist die Beitrittsseite nicht veröffentlicht; deren
Veröffentlichungsschranke wird für diesen Test nicht verändert. Der bestehende
isolierte Fachtest `native-membership` prüft 15 Aufnahmeverträge einschließlich
Formularfehlern, veralteter Konfiguration, Originalbeiträgen, bestätigter
Vorstandsvorlage und wiederholter Bestätigung. Er ist nun im CI-Runner registriert.

Die 131 tatsächlichen öffentlichen Galerieverträge bestehen erneut. Die
Seitenwahl wartet gezielt auf die neue Formularnavigation bis zum fertigen
Dokument; danach werden die ursprünglichen Album-/Foto-IDs und die dargestellte
Seitenzahl geprüft. Keine abgeschwächte Originalprüfung und kein erzwungener Klick.

Nach diesen Leseprüfungen müssen beide `public-albums-app-fixture.php check`-Läufe
bestehen. Sie vergleichen jede frühere Zeile, Revision, Veröffentlichung und Datei
sowie zusätzlich sämtliche Tabellen und Gesamtzeilenzahlen. Browserprüfungen
dürfen weder Zeilen ergänzen oder entfernen noch das Schema verändern. Gesicherte
eigene frühere Fixtures bleiben Teil des geschützten Bestands.

## Weiterarbeit

Die gemeinsame Framework-Grundlage und diese konkreten Ansichten sind geprüft.
Die native Familiengruppe und direkte Fehlerkorrektur sind im folgenden
[Familienformular-Zwischenstand](MITGLIEDSANTRAG-FAMILIENFORMULAR.md) ergänzt.
Weitere befüllte Formulare, Dialoge und Fach-/Wiederaufnahmevarianten müssen
entlang der beiden Register weiter bearbeitet werden. Daraus folgt keine vollständige UI- oder Produktabnahme.
Apps für iPhone/iPad und Android-Smartphones/-Tablets bleiben die abschließende
Phase nach der bestehenden Umsetzung.
