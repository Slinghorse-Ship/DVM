# Bild- und Videoeditor im Browser

DVM verwendet einen eigenen Editor mit lokalen Medien, Schriften und Vorlagen.
Foto-, Text-, Formen-, Logo- und QR-Ebenen werden lokal über Canvas gerendert.
Normale Formularfelder ergänzen die Zeichenfläche für Tastatur und Touch.
Originalbilder und frühere gespeicherte Fassungen bleiben erhalten.

| Aufgabe | Aktuelle Anleitung |
| --- | --- |
| Fotoausschnitt, Formen, Text und Export | [Fotoeditor](CMS-FOTOEDITOR.md) |
| Schmale Ansichten und Touch | [Bildstudio](SOCIAL-BILDSTUDIO-TOUCH.md) |
| Quellbereiche innerhalb eines Fotos kopieren | [Klonstempel](BILDSTUDIO-KLONSTEMPEL.md) |
| Lokale Bildkorrektur | [Reparaturpinsel](BILDSTUDIO-REPARATURPINSEL.md) |
| Reel und Ton | [Reelstudio-Audio](SOCIAL-REELSTUDIO-AUDIO.md) |
| Veröffentlichungsfähige Videos | [Meta-Reels](SOCIAL-META-REELS.md) |

Bearbeitung, Speicherung, Freigabe und tatsächliche Veröffentlichung sind
getrennte Schritte. Exportbytes und tatsächliches Format werden geprüft.
Fehlende Browser-Codecs dürfen keine vorgetäuschte erfolgreiche Ausgabe erzeugen.
Seitenwechsel und Abbruch müssen Decoder, Listener und lokale Datei-URLs
freigeben; verspätete Ergebnisse dürfen keinen neuen Auftrag auslösen.

Die aktive Videoverarbeitung verwendet Mediabunny mit lokal mitgelieferten
Quellen, Lizenztexten und Prüfsummen unter
`vereinsverwaltung/resources/mediabunny`. Die Bibliothek steht unter MPL-2.0.
Deren Lizenznachweise bleiben Bestandteil der Auslieferung.
[Abhängigkeitsinventar](native-dependency-inventory.md).

Ein lokaler Export bestätigt weder die externe Veröffentlichung noch eine
tatsächliche Smartphone-Abnahme.
