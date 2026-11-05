# Gespeicherte Fotos bearbeiten

Unter **Website → Galerie → Alle Bilder** ein Bild öffnen und **Foto bearbeiten**
wählen. Der gemeinsame lokale Editor bietet Zuschnitt, Drehen, Spiegeln,
Ausrichten, Farben, Filter, Schärfe, Zeichnen, Text, Formen, Verdeckung und
Klonstempel. **Übernehmen** speichert eine neue private Bildfassung.
**Neue Fassung öffnen** führt zu deren Bildangaben.
Weichzeichnen, Verpixeln und manuelle Rote-Augen-Korrektur gehören ebenfalls
zum gemeinsamen lokalen Editor.

Das Ausgangsbild bleibt geöffnet. Ungespeicherte Angaben werden erhalten;
bestehende Alben, Veröffentlichungen, Entwürfe und Freigaben behalten ihre
bisherigen Bildverweise. Die neue Fassung kann anschließend bewusst ausgewählt
werden. **Abbrechen** erzeugt kein Bild. Eine nicht bestätigte Speicherung wird
nicht automatisch wiederholt.

**Speicherung prüfen** fragt unbestätigte neue Fassungen ausschließlich lesend
über ihre vorher zufällig gewählte genaue Kennung ab. Eine tatsächlich
gespeicherte eigene Fassung erhält ihren ursprünglichen privaten Öffnen-Link;
der Editor und ungespeicherte Bildangaben bleiben erhalten. Eine nicht mögliche
oder noch nicht bestätigte Prüfung bleibt erneut ausführbar. Es gibt keinen
erneuten Upload. **Prüfung beenden** beendet die lokale Prüfung und löscht kein
gespeichertes Foto; eine laufende Prüfung wird abgebrochen.
Aktuelle Vereins-/Nutzer-/Leserechte und Bearbeitungsrechte bleiben verbindlich.

Jede bearbeitete Fassung trägt eine eigene ID und in ihrer Originalvariante
`derived_from` mit der ursprünglichen Bild-ID und SHA-256-Prüfsumme. Die
Verwendungsansicht des Ausgangsbilds zeigt diese genaue neue Fassung. Alle vier
Bildvarianten werden neu gespeichert und vollständig auf die optionale
Fotospeichergrenze angerechnet. Dafür ist kein Schema-Upgrade erforderlich.

Die bisherige gespeicherte Bildbeschreibung wird übernommen. Rechtefreigaben
und private Einwilligungsnachweise werden für eine neue Bearbeitung nicht
erfunden. Die globale optionale Verwendungsprüfung bleibt maßgeblich; das
Speichern der Bearbeitung verlangt keine vorgelagerte Rechtebestätigung.
Ein Bild wird durch die Bearbeitung nicht veröffentlicht.

27 isolierte Bearbeitungs- und 44 gemeinsame Upload-/Status-/Originalverträge
sowie 81 tatsächliche
Browserverträge bestehen. In beiden Anwendungen werden bei 390/320/820 Pixeln
acht neue gedrehte Fotos tatsächlich gespeichert und mit unabhängigem GD-
Abmessungs-/Farbvergleich geprüft. Abbruch, fehlgeschlagener Quelldownload,
zwei nach tatsächlicher HTTP-Annahme verlorene Bestätigungen, rein lesender
Wiederfund, fehlgeschlagene und erneut erfolgreiche Statusabfrage,
44-Pixel-Öffnen-Links, erhaltene ungespeicherte Formulare,
gleiche private/öffentliche Originalbytes und 200 % Zoom sind geprüft. Jede vorherige
Datenbankzeile und Bilddatei bleibt vollständig unverändert. Kein externer
Bilddienst, Mail- oder Veröffentlichungsaufruf.

Prüfweg: `tests/support/media-edit-app-fixture.php prepare` mit beiden eigenen
synthetischen Laufzeitkonfigurationen, `tests/media-edit-app-browser.mjs`, danach
jeweils `media-edit-app-fixture.php check`. Eine abgelaufene private Testsitzung
vor der Baseline erneuern. Die endgültige Löschung, Aufbewahrung, weitere Quoten
und die übrige Funktions-/Geräteabnahme bleiben offene Registerarbeit.
