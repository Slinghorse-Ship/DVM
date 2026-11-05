# Bildstudio mit begrenzter Vorschau und Werkzeugbereichen

Das Social-Bildstudio zeigt die Zeichenfläche und einen begrenzten Werkzeugbereich.
Die Ebenenauswahl bleibt oberhalb der Werkzeuge erreichbar. Drei Reiter trennen
**Hinzufügen**, **Bearbeiten** und **Ausgabe**; Pfeiltasten sowie Home/End wechseln
die Reiter. Das gewählte Foto oder eine hinzugefügte Ebene öffnet ihre Bearbeitung.
Position/Größe, Fotoausschnitt, Licht/Farbe und Retusche sind einzeln aufklappbar.
Geöffnete Gruppen bleiben beim Rückgängig/Wiederherstellen geöffnet.

**Großansicht** öffnet dieselbe Gestaltung in einem nativen modalen Fenster.
Die Vorschau, Ebenen, Werkzeuge und bewusste Speicheraktion bleiben innerhalb des
Fensters. Kurzes Querformat und 200 % Zoom verwenden eine angepasste Anordnung.
Bei vergrößerter Schrift erhält der Arbeitsbereich einen eigenen Scrollbereich;
Werkzeuge und Originalprüfung können die Zeichenfläche nicht zusammendrücken.
Bedienziele und Formulare bleiben mindestens 44 Pixel hoch; Felder verwenden
mindestens 16-Pixel-Schrift und folgen der gewählten Schriftvergrößerung.
**Schließen** und Escape behalten sämtliche ungespeicherten
Gestaltungswerte und stellen den Fokus zur ursprünglichen Schaltfläche zurück.
Erst **Bildfassung speichern** erzeugt ein neues privates Bild über das
CSRF-/Rechte-/Fassungsformular. Frühere Bilder werden nicht überschrieben.

Speichern behält die geöffnete Gestaltung und ungespeicherte Beitragstexte im
selben Fenster. Bei einer unbestätigten Serverantwort bleiben genau die
ursprünglichen Bildbytes, Ebenen und die Vorgangskennung für
**Ursprüngliche Bildfassung erneut speichern** erhalten. Ein bewusster erneuter
Versuch legt dasselbe angenommene Bild nicht nochmals an. Änderungen nach dem
ersten Versuch bleiben als eigener ungespeicherter Entwurf erhalten.

**Gespeicherte Bildfassung prüfen** liest ausschließlich den eigenen Vorgang.
**Gespeicherte Bildfassung öffnen** öffnet dessen unverändertes privates PNG in
einem getrennten Fenster. Wird kein gespeicherter Vorgang gefunden, bleibt die
Gestaltung erhalten; anschließend kann sie bewusst korrigiert und gespeichert
werden. Die Kennung bleibt bis zur bestätigten Annahme gebunden. Erst eine
weitere bewusste Speicherung nach bestätigter Annahme erzeugt eine neue Fassung.
Keiner dieser Schritte gibt einen Beitrag frei oder veröffentlicht ihn.

Beim Verlassen des Arbeitsbereichs werden verschobene modale Studioinstanzen
entsorgt: Fenster, Scrollsperre, Zeichenfläche, Bildcache und alte Ereignisse bleiben
nicht über dem nächsten Arbeitsbereich liegen. Ungespeicherte Gestaltungen und
unbestätigte Speichervorgänge werden dabei als offene Bearbeitung berücksichtigt.

Die Bedienelemente und Fehlermeldungen folgen der persönlichen Auswahl Deutsch
oder Englisch. Gespeicherte Ebenentexte und Bildbeschreibungen bleiben wörtlich.
Ohne JavaScript oder verfügbare Zeichenfläche verweist die Oberfläche auf die
tatsächlichen Bildvorlagen im Beitragsformular; der getrennte Foto-Upload bleibt
erreichbar.

English: **Save image version** keeps the current canvas and unsaved post text.
After an unconfirmed response, **Retry original image save** uses the same exact
image, document and request identifier. **Check saved image version** only reads
the original request; **Open saved image version** opens its immutable private
PNG in a separate window. Later local edits remain unsaved until a conscious
new save. Saving or checking an image never approves or publishes a post.

Unter **Neues eigenes Foto in die Galerie laden** lassen sich eigene Bilder mit
einer optionalen Bildbeschreibung hinzufügen. Mit JavaScript bleibt die aktuelle
Gestaltung geöffnet. Das bestätigte Foto erscheint einmal in der vorhandenen
Fotoauswahl; erst die bewusste Auswahl fügt es als neue Ebene hinzu. Beitragstext
und bisherige Ebenen werden durch den Upload nicht gespeichert oder verändert.

Bei **Fotoupload nicht bestätigt** bleiben die ausgewählte Datei, Beschreibung
und Vorgangskennung erhalten. **Ursprünglichen Fotoupload erneut versuchen**
verwendet denselben ursprünglichen Upload. **Gespeichertes Foto prüfen** liest
den eigenen Nachweis, ohne erneut hochzuladen. Ein bestätigtes Foto kann mit
**Gespeichertes Foto öffnen** in einem getrennten Fenster angesehen werden.
Auch nach einem fehlgeschlagenen zweiten Upload prüft die Schaltfläche weiterhin
dessen Kennung und zeigt nicht versehentlich das zuvor gespeicherte Foto an.

Ohne JavaScript öffnet der Foto-Upload ein eigenes Fenster. Der ursprüngliche
Beitrag mit seinen ungespeicherten Angaben bleibt darin unberührt. Bei einem
Fehler bleiben Bildbeschreibung und Kennung im Uploadfenster erhalten; die
Datei muss erneut bewusst ausgewählt werden. **Gespeichertes Foto prüfen**
benötigt keine Dateiauswahl. Nach erfolgreichem Upload kann das Fenster geschlossen
werden. **Beitrag mit aktuellem Bilderbestand öffnen** öffnet bei Bedarf einen
weiteren Beitragsbereich, ohne das ursprüngliche Fenster zu ersetzen.

English: **Upload your own photo** adds a photo to the existing picker without
changing the post or canvas. After an unconfirmed upload, **Retry original photo
upload** retains the exact original request; **Check saved photo** only reads its
receipt. Without JavaScript the upload opens a separate window. A rejected upload
keeps its description and identifier and explicitly asks you to select the file
again. The original unsaved post stays open.

Die bestehenden PNG/JPEG/WebP-Exporte, eigene Quellenangaben, serverseitigen
Bildfassungen, Foto-Klonstempel und die ursprüngliche Gestaltungsdatenhaltung
bleiben dieselben. Die normale HTML-Ansicht und serverseitige Bilderzeugung sind
weiter vorhanden. Diese Oberfläche enthält keine externe Bildverarbeitung oder
zusätzliche Editorbibliothek.

`image-export-browser.mjs` prüft die tatsächliche PHP-Oberfläche und lokale
Exporte, 320/390/820 Pixel, kurzes Querformat, Tastatur, 200 % Zoom und Bereinigung.
`native-image-studio-interface.php` mit `image-studio-interface-browser.mjs`
prüft beide tatsächlichen Anwendungen in DE/EN, JavaScript/NoJS und mit
vergrößerter Schrift. `native-canvas-save-requests.php` mit
`canvas-save-requests-browser.mjs` prüft tatsächliche SQL-Abbrüche, verlorene
Serverantworten, parallele Speicherungen, reine Originalprüfung und erhaltene
ungespeicherte Beitragstexte. Alle Wege verwenden eigene synthetische Datenbanken.
`native-social-photo-upload.php` mit `social-photo-upload-browser.mjs` prüft eigene
Foto-Uploads, tatsächliche Schreibfehler, unterdrückte Bestätigungen nach wirklich
angenommenen Uploads, sichere Wiederholung und die getrennten HTML-Fenster.
`native-photo-clones-browser.mjs` prüft weiterhin tatsächliche Kopierpixel,
Undo/Redo, unveränderte frühere Aufnahmen und ursprüngliche Inhaltsfreigaben.
Die vollständige übrige Manager-/Website-/Apple-Geräteabnahme und benötigten
Registerfunktionen bleiben gesondert abzuarbeiten.
