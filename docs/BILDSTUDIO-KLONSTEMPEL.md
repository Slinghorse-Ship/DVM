# Manueller Klonstempel im lokalen Bildstudio

Einen gespeicherten Social-Beitrag öffnen und im Bildstudio eine Fotoebene wählen.
Unter **Bearbeiten → Retusche → Klonstempel** einen runden Bereich aus demselben
Originalfoto an eine andere Stelle kopieren. Damit lassen sich kleine störende
Bildstellen mit vorhandenen eigenen Bildpixeln überdecken.

Quelle und Ziel verwenden horizontale und vertikale Prozentwerte des ganzen
Fotos, bevor Zuschnitt, Zoom, Spiegelung oder Drehung angewendet werden. Der Radius
beträgt 1–25 Prozent der kürzeren Bildseite. Beide Mittelpunkte müssen mindestens
einen eingegebenen Radius in Prozent vom Rand entfernt liegen. **Klonstempel
anwenden** übernimmt den gewählten Bereich in die aktuelle Gestaltung.

**Randweichheit** bestimmt den Übergang zum umgebenden Foto: 0 % erzeugt einen
harten Rand, 100 % einen Verlauf vom Mittelpunkt bis zum Rand. Neue Stempel
beginnen mit 50 %. Alte gespeicherte Stempel behalten ihren harten Rand und
ihre ursprünglichen Dokumentfelder. Die Werte des letzten angewendeten
Stempels werden für den nächsten Stempel übernommen.

Alternativ lassen sich Quelle und Striche unmittelbar im Foto wählen:
[Direkte Retusche mit Finger, Stift, Maus oder Tastatur](BILDSTUDIO-DIREKTRETUSCHE.md).

Jeder Stempel liest die unveränderten Quellpixel nach Belichtung, Gamma,
Temperatur, Farbton und Schärfe. Frühere Stempel verändern die Quelle weiterer
Stempel nicht. Anschließend gelten die normalen Foto- und Ebeneneinstellungen.
Bis zu 64 Stempel je Foto und 256 je Bildfassung sind möglich.

**Rückgängig** und **Wiederherstellen** verwenden den gemeinsamen Gestaltungsverlauf.
**Alle Klonstempel dieses Fotos entfernen** stellt das Foto ohne Stempel dar;
auch diese Aktion lässt sich zurücknehmen. Ungültige Eingaben lassen die
Gestaltung unverändert und zeigen einen konkreten Hinweis.
Der Hinweis erhält Tastaturfokus. Noch nicht angewendete Werkzeugeingaben
blockieren weder den Export noch das Speichern der bereits gültigen Gestaltung.

Änderungen bleiben bis **Bildfassung speichern** auf dem eigenen Gerät. Speichern
legt eine neue private PNG-/JPEG-Bildfassung mit dem bearbeitbaren Dokument an.
Die alte Freigabe behält ihre genaue Bilddatei; die neue Fassung benötigt eine
eigene bewusste Freigabe. Originalfoto, frühere Bildfassungen, Social-Inhalt,
Links und Veröffentlichungsjobs bleiben erhalten. Erneutes Öffnen lädt die
Stempel aus der gespeicherten Fassung. Keine KI und kein externer Bilddienst.

Beschriftete lokale Bootstrap-6-Eingaben und Schaltflächen tragen die Bedienung.
Der Canvas-Editor benötigt JavaScript. Beide Redaktionszugänge verwenden ihre
jeweiligen privaten Foto- und QR-Routen; Bildvorschauen sind leseberechtigt.

33 MySQL-/Dienst-/Darstellungsverträge prüfen Grenzen, originale Dokumentform,
private Speicherung, Freigaben, Rechte und unveränderte Fotovarianten. 125 tatsächliche Browserprüfungen mit sechs neuen privaten Bildfassungen
kontrollieren bei 1440/820/390 Pixeln beide Redaktionszugänge,
tatsächliche geklonte Vorschau-/Download-/Speicherpixel, Umgebungspixel,
Rückgängig/Wiederherstellen, Fehler und erneutes Öffnen. Ein tatsächlicher
laufender PNG-Export benötigt vor einem Seitenwechsel eine bewusste Verwerfung;
der verlassene Editor gibt Puffer frei und lädt später keine Datei herunter. Ein zusätzlicher
tatsächlicher Pixelvergleich bestätigt unveränderte Quellen bei mehreren Stempeln.

Weiche Ränder sind zusätzlich mit 125 Fach-/MySQL-/Dateiverträgen und 74 echten
Browserprüfungen in einer neuen isolierten Datenbank belegt; mit tatsächlichem
Speichern sind es 127 Fachverträge. Geprüft werden alte exakte harte Pixel,
weiche Übergänge, unveränderte Außenpixel und unabhängige Quellen mehrerer
Stempel. Undo/Redo, ungültige noch nicht angewendete Eingaben, tatsächlicher
PNG-Export, neue private Speicherung und erneutes Öffnen sind enthalten.
Bei 320/390/820 Pixeln und 200 % Zoom bleiben alle sechs Eingaben und beide
Schaltflächen lesbar und mindestens 44 Pixel groß. Der weiche Rand verwendet
einen kleinen temporären Ausschnitt; der unveränderte Quellpuffer wird einmal
pro Foto angelegt und alle temporären Pixelpuffer werden danach freigegeben.
Originalfoto, frühere genaue Freigabe, Veröffentlichung, sämtliche früheren
Datenbankzeilen/Gesamtzahlen, Dateien, Rechte und verschlüsselte Nachweise
bleiben unverändert; nur eine neue private Bildfassung entsteht. Zwölf
betroffene native Suiten und der bestehende PNG-/JPEG-/WebP-Exportbrowser
einschließlich 38 Großansichts-/Mobilprüfungen bestehen.

Dies ergänzt den manuellen Klonstempel. Weitere Retuschierwerkzeuge,
Video-/Tonexport und vollständige
Editor-Funktionsparität bleiben getrennte Arbeitspunkte.
