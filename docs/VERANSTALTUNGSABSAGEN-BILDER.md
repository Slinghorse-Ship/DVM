# Ursprüngliche Bilder öffentlicher Veranstaltungsabsagen

Eine bestätigte öffentliche Veranstaltungsabsage zeigt weiter das Bild aus der
früheren tatsächlichen Veröffentlichung. Eine danach privat bearbeitete Fassung
und ihr Ersatzbild werden dadurch nicht veröffentlicht.

Die gewöhnliche Veröffentlichung wird bei einer externen Absage zurückgezogen.
Deshalb genügt ihr aktueller Seitenzeiger nicht zum Bildabruf. Der Medienzugriff
prüft jetzt zusätzlich den vorhandenen Absagehinweis über denselben nativen
Veranstaltungskatalog wie die öffentliche Terminseite. Dabei gelten die aktuellen
Vereins-, Seiten-, Vorlagen- und Feldrechte sowie die vorhandene Medienfreigabe.
Ein ausdrücklich übergebener Seitenbezug bleibt an genau diese Seite gebunden.
Es werden höchstens 25 Kennungen gleichzeitig gelesen, keine vollständigen
großen Originalgruppen in den Speicher geladen und keine Daten umgeschrieben.

Wird die öffentliche Seitenfreigabe entzogen, ist auch das bisherige Absagebild
sofort gesperrt. Eine bewusste Wiederfreigabe verwendet dieselbe frühere
Veröffentlichung und dieselben Pixel. Das Offline-Nehmen der Absage schließt
den Bildabruf vollständig. Ursprüngliche Bilddateien und Revisionen bleiben erhalten.

## Geprüfter Umfang vom 18. Oktober 2026

Die ursprüngliche Katalogprüfung auf `4a51dd40` scheiterte am fehlenden öffentlichen
Absagebild. Die korrigierte Funktion besteht 70 Katalogbedingungen und elf
angrenzende unveränderte Fachgruppen. Sechs tatsächliche anonyme native
HTTP-Anfragen prüfen ursprüngliche PNG-Bytes, ein privates Ersatzbild, HEAD mit
ursprünglichem Typ und Länge, Entzug/Wiederfreigabe und anschließendes Offline.
Die Lesewege erhalten alle ursprünglichen Tabellen und die frühere vollständige
Veröffentlichungszeile. Die Nachbargruppen umfassen Feeds, Veranstaltungen,
Seiten-/Feldrechte, Bildkanäle/-angaben/-credits und redaktionelle Freigabe.

Der zusätzliche HTTP-Prüfweg verwendet den vorhandenen nativen Router und dessen
eigene Vereinsbindung. Er setzt keine Produktionszugänge ein. Es gibt keine
externen API-, Mail-, Zahlungs- oder Veröffentlichungsaktionen. Dies ist die
gezielte K-03-Korrektur; die gemeinsame K-Abnahme bleibt unter K-35 offen.
[Konkrete Quellfassungen und Ergebnisse](pruefbelege/K03-ABSAGEBILDER-2026-10-18.json).
