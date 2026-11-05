# Gespeicherte Social-Bilder

Die Redaktion zeigt sechs kleine Vorschauen pro Seite. „Gespeicherte Bilder“
öffnet den Verlauf; nächste, vorherige und direkt gewählte Seiten erreichen
sämtliche ursprünglichen Bildfassungen. Die Seitenwahl führt zum Bildbereich,
auch ohne JavaScript. Ein Klick aufs Bild oder „Bild herunterladen“ öffnet das
genaue private Original in voller Auflösung. Die Vorschau ist nur 320 Pixel
breit; sie ersetzt keine gespeicherte Datei.

Der Verlauf zählt alle ursprünglichen Datensätze. Wird zwischen zwei Abrufen
eine Bildfassung ergänzt, aktualisiert er die erste Seite und zeigt einen kurzen
Hinweis. Neue Bilder erhalten den tatsächlichen Datenbankzeitpunkt mit sechs
Nachkommastellen. Früher gespeicherte Zeiten, Metadaten und Pixel werden nicht
nachträglich geändert. Bei alten zeitgleichen Bildern bleibt deren bisherige
ID-Reihenfolge erhalten.

Das Bildstudio lädt höchstens die zehn zuletzt gespeicherten editierbaren Folien.
Die Freigabe prüft die neueste passende Bildfassung mit aktuellen Datenbanklesungen,
auch innerhalb einer älteren Repeatable-Read-Transaktion. Die Veröffentlichung
holt ausschließlich die genauen freigegebenen Original-IDs; ein älteres
freigegebenes Bild kann daher auch weit hinter der ersten Verlaufsseite liegen.
Die SQL-Sortierung verarbeitet kleine Metadaten statt vollständiger Dokumente.

Alle Abrufe bleiben an aktuelle Vereins- und Leserechte gebunden. Reine Leser
können frühere Bilder öffnen und herunterladen. Sie erhalten keine Erzeugungs-
oder Speicheraktion. Die private Vorschau unterstützt GET und HEAD, lehnt POST
ab und wird nicht zwischengespeichert. Vollständige Consent-Nachweise werden
weder im Verlauf noch in Bildstudio-Daten ausgegeben.

Die Bereichsauswahl von Social, Newsletter und Presse verwendet ein einzelnes
aufklappbares natives Menü. Nachrichten und Kommentare behalten ihre eigene
Bezeichnung und ihre erlaubten Ziele. Touch-, Tastatur-, Zoom- und JavaScript-freie
Bedienung werden auf beiden Redaktionswegen geprüft.

Der Fachtest liest über 38 MiB alter Bildmetadaten in einem tatsächlichen
16-MiB-PHP-Prozess, prüft sämtliche 131 früheren PNG-/JPEG-Originale und eine auf
einer zweiten Datenbankverbindung gespeicherte neue Bildfassung. Browserprüfungen
verwenden ausschließlich neue eigene synthetische Testverläufe. Es findet kein
Anbietertransport, keine Veröffentlichung und kein Inhaltsimport statt.
