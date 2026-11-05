# Reel im Vereinsmanager speichern

Im gespeicherten Social-Beitrag **Video bearbeiten** öffnen und ein eigenes
Video wählen. **Schnitt & Ausschnitt**, **Filter & Licht** und **Text im Video**
sind einzeln aufklappbar. **Videofassung speichern** verarbeitet das Video auf
dem Gerät und speichert die geprüfte MP4 als neue private Fassung.
**MP4 herunterladen** bleibt eine getrennte Aktion.

Nach erfolgreicher Speicherung zeigt der Bereich das genaue private Video.
Der Beitragstext, lokale Schnitt- und Texteinstellungen sowie das gewählte
Original bleiben geöffnet. Eine zusätzlich ausgewählte fertige MP4 wird beim
Speichern des Studioexports nicht überschrieben. Erst ein bewusst bestätigter
Ansichtswechsel verwirft lokale Bearbeitungen und gibt Decoder und
Objektadressen frei. Eine Fassung muss anschließend ausdrücklich zur
Reel-Freigabe ausgewählt werden; Speichern veröffentlicht nichts.

Bei **Speicherung nicht bestätigt** bleiben die tatsächliche Datei und der
ursprüngliche Speichervorgang im geöffneten Fenster erhalten.
**Gespeichertes Video prüfen** liest dessen Nachweis, ohne ein weiteres Video
anzulegen. **Ursprüngliche Videofassung erneut speichern** wiederholt genau
diesen Vorgang mit denselben Dateibytes. Auch eine bereits angenommene
Speicherung mit verlorener Antwort liefert dadurch ihr ursprüngliches Video
zurück. Eine spätere bewusste Speicherung erhält eine neue Vorgangskennung.
Eine negative Prüfung eines späteren Versuchs zeigt niemals das zuvor
angenommene Video als dessen Ergebnis.

Die Oberfläche und Fehlerhilfen folgen Deutsch/Englisch sowie eigenen
Sprachbeschriftungen. Menschliche Dateinamen, Beitragstexte und Texte im Video
bleiben wörtlich. Werkzeuge beginnen eingeklappt; Eingaben und Schaltflächen
bleiben auf schmalen Geräten und bei vergrößerter Schrift bedienbar.

Freie Bearbeitung benötigt JavaScript, WebCodecs und einen tatsächlich
verfügbaren H.264-Encoder. Originalton nutzt den nativen AAC-Encoder oder die
[lokale AAC-Erweiterung](SOCIAL-REELSTUDIO-AUDIO.md). Fehlender Toncodec entfernt
Originalton nicht automatisch. Ohne geeigneten Encoder oder ohne JavaScript
steht **Fertige MP4 direkt speichern** zur Verfügung. Der HTML-Speichervorgang
öffnet ein eigenes Fenster; Fehler und fehlende Dateien können dort mit
ursprünglicher Vorgangskennung korrigiert werden. Der ungespeicherte
Beitragsentwurf im Ausgangsfenster bleibt erhalten.

Die Speicherung verlangt aktuelle eigene Lese-/Bearbeitungsrechte, gültiges
Formular, genaue Inhalts- und Vereinsdatenversion, geprüfte Originalbytes und
die verfügbare Speicherquote. Neue MP4, Quellenzuordnung und Speichernachweis
werden gemeinsam angenommen. Nur ein bestätigter Datenbank-Rollback erlaubt
das Löschen der neuen Datei. Bei unbestätigtem Abschluss bleiben ihre Bytes
für die Prüfung erhalten. Frühere Quellen, Freigaben und Veröffentlichungen
werden nicht ersetzt. Die Erstinstallation enthält alle benötigten
Datenbankschritte; zusätzliche Migrationsdownloads sind nicht erforderlich.

Die automatischen Prüfungen verwenden eigene Datenbanken, Benutzer und
Dateien. Sie umfassen wirkliche MP4-Erzeugung, Abbruch, Download,
AVC-/AAC-Prüfung, echten Multipart-Upload, SQL-Abbruch, verlorene
Speicherantworten und wiederholte Originalprüfungen in beiden Zugängen.
Echte Anbieter-Veröffentlichungen und physische Geräteabnahmen bleiben
getrennte Release-Prüfungen.
