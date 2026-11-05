# Speichergrenze für CMS-Fotos

**Website → Fotogalerie → Alle Bilder** zeigt die Anzahl eigener Fotos und den
belegten Fotomediathek-Speicher. Gezählt werden die vier tatsächlich gespeicherten
Bildvarianten. Neue Installationen beginnen ohne künstliche Speichergrenze.

Mit Website-Verwaltungsrecht unter **Speichergrenze** eine ganze Zahl in MiB
speichern. **0** hebt die optionale Grenze auf; höchstens **102400 MiB** sind
zulässig. Lesende Personen sehen die Belegung, erhalten keine Einstellungsformulare.
Normale Bildredaktion darf die Verwaltungsgrenze nicht ändern.

Alle CMS-Fotouploads verwenden dieselbe Grenze, auch aus Alben und Bildgestaltung.
Zwei parallele Uploads können den letzten freien Platz nicht beide verbrauchen.
Die Prüfung zählt die aktuelle Belegung in der Uploadtransaktion; ein zuvor
geöffneter Datenbankstand erlaubt keinen zusätzlichen Upload. Bei Ablehnung
werden ausschließlich die neu erzeugten Dateien dieses Uploads entfernt.

Eine kleiner gesetzte Grenze erhält vorhandene Fotos, Originale, Bildrechte und
Verläufe. Sie blockiert weitere Uploads, solange die vorhandene Belegung die
Grenze überschreitet. Frühere Speicherregeln bleiben als unveränderliche
Revisionen erhalten und gehören zur verschlüsselten Wiederherstellung.

Konkurrierende Einstellungsformulare überschreiben einander nicht. Ein Konflikt
behält die eingegebene Grenze und ihre ursprüngliche Version. Nach einer
neuen, erfolgreichen Speicherung enthält das Formular sofort den aktuellen
Stand und lässt sich erneut verwenden. Das gilt auch ohne JavaScript.

CMS-Schema **11** ergänzt ausschließlich die zwei eigenen Tabellen für aktuelle
Fotomediathek-Regeln und deren Geschichte. Das Upgrade von CMS 10 ändert keine
Fotos, Bildmetadaten, Einwilligungsbelege oder Inhaltsoriginale.

Die Grenze gilt für die CMS-Fotomediathek. Zusätzliche gerenderte Social-/
Newsletter-Fassungen, Dokumente und die geplante Videoablage benötigen weiterhin
ihre eigenen Quoten. Temporäre Verarbeitungsdateien und allgemeine Hostergrenzen
sind getrennt. Referenzsichere globale Medienlöschung und Aufbewahrung bleiben
im Umsetzungsregister offen.
