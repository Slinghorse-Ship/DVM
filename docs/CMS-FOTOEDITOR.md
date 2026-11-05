# Fotos vor dem Upload bearbeiten

Die gewöhnliche Dateiauswahl lädt Fotos weiterhin direkt über den bisherigen
Uploadweg. **Foto bearbeiten …** öffnet zusätzlich eine eigene optionale
Dateiauswahl und anschließend die Fotobearbeitung. Erst **Übernehmen** gibt die
bearbeitete Datei an das ursprüngliche Formular zurück. Automatische Uploadfelder
laden sie dann hoch; andere Formulare werden wie gewohnt gespeichert.
**Abbrechen** und Escape geben keine neue Datei weiter.

Die Vorschau bleibt sichtbar. Nur der gewählte Werkzeugbereich erscheint darunter
oder daneben; die Seite muss nicht durch eine lange Werkzeugliste gescrollt
werden. Die Abschlussaktionen bleiben am unteren Rand erreichbar. Ein kurzes
Querformat oder verkleinerter sichtbarer Bildschirm nutzt die kompakte Ansicht.

- **Zuschnitt:** Originalverhältnis, Quadrat, Quer- und Hochformat oder Story;
  Drehen, horizontales/vertikales Spiegeln, Ausschnitt und Zoom. Mit einem Finger
  verschieben und mit zwei Fingern vergrößern. Pfeiltasten verschieben den
  Ausschnitt; Shift vergrößert den Schritt.
- **Licht & Farbe:** Helligkeit, Kontrast, Sättigung, Temperatur und Schärfe. Die
  tatsächlichen Pixeloperationen benötigen keine Canvas-Filter-API.
- **Filter:** Original, Schwarzweiß, Warm, Kühl, Sepia und Lebendig.
- **Markieren:** Stift, Linie, Pfeil, Rechteck und selbst eingegebener Text.
  Unkenntlichmachen ersetzt den markierten Bereich in der Ausgabe durch deckende
  dunkle Pixel. Bis zu 100 Markierungen mit begrenzten Stiftpunkten.
- **Ausgabe:** kompatible JPEG-, PNG- oder WebP-Datei, eigene Breite und automatisches
  Seitenverhältnis; bis 8192 Pixel und 16 Megapixel. Die Uploadausgabe wird bei
  Bedarf in Qualität und Größe auf höchstens 1,7 MB optimiert. PNG behält seine
  Transparenz. Ein Formular, das kein WebP akzeptiert, bietet es hier nicht an.

**Rückgängig/Wiederherstellen** behält bis zu 50 Bearbeitungsschritte. **Original**
gedrückt halten vergleicht die Vorschau mit der unveränderten Quelle.
Werkzeugreiter lassen sich mit Pfeiltasten, Home und End bedienen; das native
modale Fenster hält die Tastaturbedienung im Editor. Beim Schließen wird die
ursprüngliche Seite wieder bedienbar. Kameravorbereitung kann sofort abgebrochen
werden; ein Ansichtswechsel verwirft die Bearbeitung.

[HEIC, HEIF und unterstützte RAW-Dateien](CMS-APPLE-KAMERAFOTOS.md) werden vor
Bearbeitung lokal vorbereitet. Sehr große gewöhnliche Bilder werden für die
Bearbeitung auf maximal 2400 Pixel reduziert. Eine Quellenaufnahme oberhalb
120 Megapixel wird abgelehnt. Der Decoder und die Bildbearbeitung verwenden
keinen externen Dienst. Die Auswahlquelle und vorherige gespeicherte Fotos,
Veröffentlichungen, private Nachweise und Datenbankeinträge bleiben erhalten.
Die neue Datei erhält den Namenszusatz `-bearbeitet`; Rechteangaben werden nicht
erfunden. Die [optionalen Bildprüfungen](CMS-BILDPRUEFUNG.md) bleiben unabhängig.

Freier Zuschnitt erlaubt unabhängige Breiten-/Höhenanteile. **Fein einstellen**
öffnet die Positionsregler und **Gerade ausrichten** (−45 bis +45 Grad). Die
Ausgabe enthält auch bei gedrehter/verschobener Aufnahme keine leeren Außenränder.
Der **Kopierstempel** übernimmt echte Quellpixel: Zuerst einen Quellpunkt wählen,
dann im Foto retuschieren. **Quellpunkt wählen** setzt die Quelle neu. Mit der
Tastatur bewegen Pfeile den Punkt, die Leertaste wählt die Quelle und Enter
retuschiert. Shift verschiebt in größeren Schritten. Der gewählte Quellpunkt
und die Tastaturposition sind in der Vorschau sichtbar; die Ausgabe enthält
keine Bedienmarkierungen. Rückgängig/Wiederherstellen gilt für jeden Strich.
Der Stempel kopiert die angepasste Aufnahme vor den eigenen Markierungen.

**Weichzeichnen**, **Verpixeln** und **Rote Augen** bearbeiten ausschließlich die
mit dem Finger, Maus oder Tastatur markierten Stellen. **Stärke** bestimmt die
Pinselgröße; **Retuscheintensität** bestimmt den Weichzeichnungsradius, die
Pixelblockgröße bzw. die Rotkorrektur. Pfeile bewegen den sichtbaren Punkt;
Enter oder Leertaste bearbeiten die Stelle, Shift vergrößert den Schritt.
Quellpunkt, Farbe und Text erscheinen nur bei den dafür passenden Werkzeugen.
Auch im integrierten Manager bleibt die Werkzeugwahl ein einziges natives Feld.

Die Werkzeuge arbeiten mit tatsächlichen lokalen Pixeln in der Strichreihenfolge.
Weichzeichnung und Pixelblöcke berücksichtigen transparente Quellpixel nach
ihrem Alphaanteil; alle drei Werkzeuge erhalten jedes ursprüngliche Alphapixel.
Die manuelle Rotkorrektur senkt ausschließlich die markierte rote Dominanz.
Unbemalte Bereiche bleiben unverändert. Weichzeichnen und Verpixeln sind
kosmetische Werkzeuge; **Unkenntlich machen** bleibt die deckende Ersetzung.
Schnelle Striche sind durchgehend, abgebrochene Touchgesten werden verworfen.

Die gesamte Retuschefläche wird vor der Berechnung auf höchstens 64 Millionen
verarbeitete Pixel pro Ausgabe begrenzt. Bei Überschreitung bleiben Striche,
Vorschau und Fehler erhalten; eine kleinere Ausgabebreite oder Undo erlaubt
die Korrektur. Fehlerhafte Ausgaben erzeugen keine Datei und keinen Upload.

## Prüfung und weiterer Ausbau

`photo-editor-browser.mjs` prüft echte Bildpixel, Ausgabedateien, mobile Ansicht,
Tastatur, Zweifinger-Gesten, deckende Unkenntlichmachung, eigene Texte, Undo,
HEIC/DNG, transparente PNGs und korrigierbare Ausgabe-/Abbruchfälle. Dabei ist
die Canvas-Filter-API absichtlich wirkungslos geschaltet. Die Actions und der
Einrichtungsprüflauf führen diese tatsächlichen Browserverträge aus.

`photo-retouch-app-browser.mjs` speichert sechs neue eigene Retusche-PNGs in
beiden tatsächlichen Anwendungen bei 320/390 Pixeln und 200 % Zoom. Der Helfer
`tests/support/photo-retouch-app-fixture.php prepare` erstellt ausschließlich
neue synthetische Quellen; `check` prüft danach gespeicherte Blur-, Block- und
Rotkorrekturpixel sowie sämtliche vorherigen Zeilen, Veröffentlichungen und
Dateien ohne Ausschluss alter Originale. Private Sitzungen vor der Baseline
erneuern. Rechte, Nachweise und Veröffentlichungen werden nicht erfunden.

`photo-editor-app-browser.mjs` prüft die tatsächlichen optionalen Dateiauswahlen
und vier finale Uploads in beiden Anwendungen. Der synthetische Helfer prüft
die gespeicherten gedrehten HEIC-Farbpixel und RAW-Graupixel sowie unveränderte
vorherige Datenbankzeilen und Bilddateien.

Die Fotobearbeitung ist eine eigene DVM-Funktion mit ausdrücklich gestarteter
Verarbeitung und Ausgabe.
Freier Zuschnitt, feines Ausrichten, Schärfe und Kopierretusche sind ergänzt.
Das [Social-Bildstudio](SOCIAL-BILDSTUDIO-TOUCH.md) hat inzwischen begrenzte
Werkzeugbereiche und eine Großansicht. Vollständige Apple-Geräte-/weitere
Fachabnahmen bleiben offen. Die Gesamtumsetzung beider Register ist noch nicht bei 100 %.
