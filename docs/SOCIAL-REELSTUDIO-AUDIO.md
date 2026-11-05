# Originalton im Reelstudio

Ein eigenes Video mit Ton wählen und **Originalton erhalten** eingeschaltet
lassen. Auf Geräten ohne nativen AAC-Encoder lädt das Reelstudio jetzt einen
lokalen AAC-Baustein nach. **Videofassung speichern** erzeugt und speichert ein
wirkliches Video mit dem ursprünglichen Ton. **MP4 herunterladen** verwendet
denselben Tonweg. Ein tonloses Video erhält keine erfundene Audiospur.

Beim Auswählen wird zunächst nur die kleine Erweiterung geladen. Der
Audio-Worker und sein Codec starten erst während der tatsächlichen Verarbeitung.
Abbrechen erhält die ausgewählte Quelle und Toneinstellung, beendet den Worker
und legt keine Dateifassung an. Danach kann dieselbe Auswahl erneut verarbeitet
werden. Ein fehlender oder unbrauchbarer Codec entfernt den Ton nicht automatisch;
die ausdrückliche Tonabwahl bleibt eine bewusste separate Entscheidung.

WebCodecs mit tatsächlicher H.264-Verarbeitung, dekodierbares Originalmaterial
und modernes WebAssembly bleiben Geräteanforderungen. Die Erweiterung arbeitet
lokal auf dem Gerät, ohne KI, Server-FFmpeg oder externe Codecabrufe. Aktuelle
Rechte, genaue Fassungen, Dateien, Freigaben und optionale Speichergrenzen werden
beim nativen Upload unverändert geprüft. Eine neue gespeicherte Datei ist keine
Veröffentlichung; die genaue Reelfreigabe und Anbieterjobs bleiben getrennt.

Der AAC-Worker verwendet eine feste gleichursprüngliche Adresse. Nur seine
Antwort erlaubt WASM-Ausführung; die übrige Oberfläche behält ihre strenge
Scriptregel. Der Worker kann weder Netzwerkzugriffe noch weitere Worker öffnen.
Beide Redaktionseinstiege verwenden denselben Dateidienst und Decoderbaustein.

Die Erweiterung basiert auf Mediabunny AAC 1.61.3 (MPL-2.0) und einem eigenen
Build des AAC-Encoders von FFmpeg 8.0 (LGPL-2.1-or-later). GPL/nonfree-Code,
weitere Encoder, Threads, Programme und Dateisystemunterstützung sind deaktiviert.
18 MiB initialer und höchstens 64 MiB WASM-Speicher begrenzen den Audio-Worker.
Vollständige entsprechende Quellen, Lizenztexte und feste Hashes sind im Paket;
der Compiler ist nur für Entwicklung erforderlich.
[Quellen und reproduzierbarer Build](../vereinsverwaltung/resources/reel-aac/README.md).
Ein unabhängiger Neuaufbau aus diesen Quellarchiven und einem eigenen leeren
Compiler-Cache erzeugt alle drei Runtime-Dateien bytegleich.

17 echte Browserprüfungen erzwingen fehlendes natives AAC, kodieren und
speichern den tatsächlichen Originalton, brechen einen gestarteten AAC-Worker
ab und prüfen dessen Ende nach Abbruch und erfolgreicher Speicherung. Das
Original und die gespeicherte Datei werden unabhängig dekodiert: beide haben
48-kHz-Stereoton und vergleichbare hörbare Signalstärke. Genau ein eigener
Multipart-POST speichert genau eine neue Fassung; kein Live-Anbietertransport.
18 Quell-/Lizenz-/Dateiverträge und 162 ursprüngliche Fachverträge bestehen,
mit tatsächlichem Browser 164 (zusammen 180 beziehungsweise 182).

Die frühere kompakte Video-/Abbruch-/Downloadprüfung besteht weiterhin mit
29 Browser- und 171 Fach-/Originalverträgen; ein bewusst gesperrter Erweiterungs-
abruf prüft dabei weiterhin den erhaltenen ausdrücklichen Tonabwahlweg.
13 betroffene native Suiten und 89 genuine Produktionscontroller-Verträge
bestehen einschließlich aller vier tatsächlichen Codec-Dateirouten.
Kommunikation bleibt 8, CMS 17, 144 native Suiten registriert; der lokale
Browserrunner enthält 20 isolierte Appgruppen. Weitere Videoformate, CMS-
Videobausteine, Carousels und echte Hersteller-/Geräteabnahmen bleiben offen.

Der ausgelieferte Stand besteht mit 149 tatsächlichen CLI-Installer-/Upgrade-,
52 HTTP-/Browser-Installer- und 79 verschlüsselten Wiederherstellungsverträgen.
Auch der reproduzierbare Buildhelfer ist im auslieferbaren Quellenpaket enthalten;
keine früheren Video-, Regel-, Nachweis-, Schlüssel- oder Datenbankoriginale
ersetzt. Kein vollständiger neuer 144-Suiten-Lauf wird damit behauptet.

Der spätere [Mehrkanalton-Zwischenstand](SOCIAL-REELSTUDIO-MEHRKANAL.md) ergänzt
die richtige 5.1-Mischung und erweitert die ursprünglichen 17 Audiobrowser-
prüfungen auf 21; die vorherigen Zahlen beschreiben diesen früheren Stand.
