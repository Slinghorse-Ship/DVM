# Automatische Veranstaltungsentwürfe

Unter **Social → Planung → Automatische Veranstaltungsentwürfe** wird eine
verbindliche gemeinsame Medienvorgabe gewählt:

- **Bild:** gestalteter Beitrag und Story als Bild.
- **Animiertes Video:** Reel mit der gewählten Animationsvorlage.
- **Beides:** Beitragsbild, Storybild und Reel.

Die Automatik übernimmt Titel, Termin, Ort, Beschreibung und Motiv aus der
veröffentlichten Veranstaltungsfassung. Das gilt auch für über Meta eingelesene
Veranstaltungen. Als Standard sind ein Jahr Vorschau, die redaktionelle
Bildgestaltung und die Farbwelt Wald eingestellt. Die Vorlage, Farbwelt,
Entwurfssprache und Animationsvorlage sind änderbar.

Vorbereitet werden bearbeitbare Entwürfe mit ihren tatsächlichen Bild- bzw.
Videodateien. Beiträge und Reels verwenden die bestehende Freigabe. Für die
Veranstaltungsstorys gibt es unter **Story-Folge** eine eigene Automatikregel.
Die reine Entwurfsvorbereitung veröffentlicht weiterhin nichts.

## Ein Bild als Video

Die sechs Sekunden langen Videos haben 1080 × 1920 Pixel und 30 Bilder/s.
Die vorgegebenen Animationen sind sanfter Zoom, Schwenk und Einblenden. Zoom
und Schwenk halten die letzten zwei Sekunden ruhig; die gepolsterte Gestaltung
bewahrt Bildnachweise und QR-Ränder. Der lokale Renderer ergänzt keine Tonspur.
Ein gespeicherter Reel-Entwurf bietet zusätzlich eine Standbildvorlage und die
vorhandene freie Videobearbeitung mit eigenem Originalton.

**Video aus der Gestaltung erstellen** speichert eine neue Entwurfsfassung und
das zugehörige Originalvideo. Das eigene Ergebnisfenster erhält ungespeicherte
Texte im ursprünglichen Editor. **Gespeichertes Video prüfen** liest den
ursprünglichen Speichervorgang. Eine unveränderte Wiederholung verwendet den
vorhandenen Inhalts- und Videonachweis.

## Story-Zeitpunkte

Optional entstehen zusätzliche Story-Entwürfe 14, 7, 3 und 1 Tag vor dem Termin
sowie am Veranstaltungstag. Grundlage sind die Kalendertage der Veranstaltung,
für Kulturhus Europe/Berlin. Ist der erste Vorbereitungstag bereits einer dieser
Tage, entsteht dafür genau ein Story-Entwurf. Verstrichene Erinnerungstage werden
nicht gesammelt nachgeholt. Nach dem Veranstaltungsbeginn entstehen keine neuen
Vorbereitungen.

## Story-Folge gestalten und automatisch veröffentlichen

**Instagram- und Facebook-Storys automatisch ohne einzelne Freigabe veröffentlichen**
aktiviert die Regel für diese Veranstaltungsfolge. Dafür ist Veröffentlichungsrecht
erforderlich. Zum fälligen Termin prüft der eigene Story-Job die aktuelle Quelle,
das Bild und die Berechtigungen, gibt die Fassung intern frei und stellt nur die
zugehörigen Story-Aufträge bereit. Der erste neue Entwurf ist sofort fällig;
Erinnerungen folgen um 10 Uhr in der Zeitzone der Veranstaltung. Bereits früher
erzeugte Erstankündigungen und verpasste Tage werden nicht nachgeholt. Zukünftige
Erinnerungen bleiben bis zum vorgesehenen Tag bearbeitbare Entwürfe. Beiträge,
Reels und manuelle Storys sind von dieser Regel nicht erfasst. Link-Sticker
benötigen weiterhin die manuelle App-Übergabe.

**Erstes gestaltetes Bild als Vorlage für die weiteren Storys verwenden** übernimmt
Motiv, Ebenen, Schriften, Farben und Gestaltung aus dem ersten Bild derselben
Veranstaltung. Das erste Bild im **Bildstudio** gestalten und **Bildfassung speichern**
wählen. Der Browser speichert gleichzeitig die vollständigen Tagesvarianten.
Eigene Änderungen an einem weiteren Bild bleiben erhalten. Eine ältere frei
bearbeitete Vorlage gegebenenfalls einmal im aktuellen Bildstudio speichern;
sie wird nicht durch eine einfachere Gestaltung ersetzt.

**Tageshinweis anzeigen** schaltet den Zusatz wie „noch 7 Tage“, „morgen“ oder
„heute“ ein oder aus. Das funktioniert auch ohne gemeinsame Bildvorlage. Die
verwalteten Ebenen `reminder-background` und `reminder-label` bestimmen die
Position und Gestaltung des Hinweises. Die übrigen Bildteile bleiben unverändert.
Alle Pixeldateien liegen im privaten Dateispeicher; SQL enthält nur Metadaten
und Prüfsummen. Für das Speichern werden bis zu acht PNG-Dateien mit jeweils
höchstens 5 MiB übertragen; mindestens 64 MiB Formularlimit und acht erlaubte
Dateiuploads vorsehen. Die bereitgestellte Hostingkonfiguration erlaubt mehr.

Der zusätzliche minütliche Aufruf lautet
`native-delivery-worker.php --config=…/private/worker.php --stories --send --limit=3`.
Er verarbeitet ausschließlich Aufträge dieser Story-Regel. Der allgemeine
Meta-Veröffentlichungsjob muss dafür nicht aktiviert werden. Das Ausschalten der
Regel oder entzogene Rechte stoppen noch nicht veröffentlichte Aufträge. Der
Verlauf zeigt bestätigte Veröffentlichungen pro Plattform; eine lokale Planung
allein ist noch kein bestätigter API-Versand.

## Hintergrundlauf und Wiederaufnahme

Die Standardaufgabe `native-delivery-worker.php --config=…/private/worker.php`
führt die aktivierte Vorbereitung minütlich aus. Der Installer liefert den
konkreten Plesk-/cPanel-Aufruf. Ein Lauf bearbeitet höchstens drei Fassungen je
Verein. **Entwürfe jetzt vorbereiten** führt denselben begrenzten, lokalen
Vorbereitungsschritt aus und eignet sich auch für die getrennte Demo.

Eine Kombination aus Veranstaltung, Format und Erinnerungszeitpunkt erzeugt
höchstens einen Entwurf. Nach einer Unterbrechung wird derselbe Entwurf
fortgesetzt; bereits gespeicherte Originalmedien bleiben erhalten. Manuelle
Änderungen und zurückgezogene Veranstaltungsfassungen benötigen eine erneute
redaktionelle Prüfung. Eine fehlerhafte Gestaltung kann in der Planung erneut
vorgemerkt oder im Editor bearbeitet werden. Vor jedem Lauf werden das aktuelle
Konto, die Vereinszuordnung und die Berechtigungen des Planverantwortlichen
geprüft.

## Laufzeit und Quellcode

Der mitgelieferte statische Linux-x86-64-Encoder arbeitet ohne Netzwerkprotokolle.
Er benötigt PHP mit GD/FreeType und `proc_open`. Binärdatei, Lizenztexte, komplette
Quellen, Buildskript und Prüfsummen stehen unter
[reel-server](../vereinsverwaltung/resources/reel-server/README.md).
Nach einer Wiederherstellung erhält ausschließlich die anhand der Paketprüfsumme
bestätigte private Encoderdatei ihr Ausführungsrecht zurück. Fremde oder
veränderte Dateien werden abgewiesen.
Andere Plattformen können die vorhandene Browser-Videobearbeitung verwenden;
die automatische Videovorbereitung verlangt den passenden lokalen Encoder.

Die reguläre Installation aktualisiert das Kommunikationsmodul auf Version 19.
Die gemeinsame Fachschemafassung bleibt 135. Originale, private Schlüssel,
freigegebene Inhalte und frühere Veröffentlichungsnachweise bleiben erhalten.
