# Website-Manifest und Touch-Symbole

Jede öffentliche Website bindet ihr eigenes `/site.webmanifest`, ein SVG-Symbol
und ein Apple-Touch-Symbol ein. Der aktuelle Vereinsname und die bewusst gewählte
Website-Vorlage bestimmen Namen, Initiale und Farbe. Klar, Magazin, Aktiv, Natur
und Kulturhus verwenden jeweils ihre eigene Vorlagenfarbe. PNG-Symbole mit
180, 192 und 512 Pixeln werden lokal erzeugt; die Initiale liegt innerhalb der
für maskierbare Symbole benötigten Sicherheitsfläche.

Eine gespeicherte Website-Verknüpfung beginnt auf der öffentlichen Startseite.
Die Kennung bleibt bei einer Namens- oder Designänderung gleich. Die nächste
Abfrage erhält die aktuellen Angaben mit neuem ETag. Die öffentliche gemeinsame
Sicherheitsrichtlinie erlaubt Manifestabrufe ausschließlich vom eigenen Ursprung.
Die bestehende statische Kulturhus-Iconadresse bleibt erreichbar.

Die Ausgabe enthält ausschließlich öffentliche Namen, Farben und lokale Pfade.
Private Anschrift, Mail-/Anbieterangaben, Tokens, Hostheader und Queryparameter
werden nicht in das Manifest übernommen. GET und HEAD sind lesend; POST wird
abgewiesen. HEAD liefert keinen Inhalt, unveränderte Originalvalidatoren liefern
304. Auch ein bereits bekanntes Symbol unterliegt den aktuellen öffentlichen
Website-Rechten. Die Ausgaben verwenden keine private Anmeldesitzung.

`native-website-manifest` prüft tatsächliche HTTP-Antworten aller fünf Vorlagen:
88 Verträge einschließlich dekodierter PNG-Abmessungen/-Farben, Unicode-SVG,
originaler HTTP-Validatoren, aktueller Namensänderung und Rechteentzug. Ganze
frühere Datenbanktabellen bleiben während der Abrufe bytegleich. Mit
`DVM_WEBSITE_MANIFEST_BROWSER=1` kommen 28 tatsächliche Chromium-Verträge bei
390/820 Pixeln mit und ohne JavaScript hinzu. Die Browser lesen das vom Renderer
entdeckte Manifest über das Browserprotokoll und laden beide Original-PNGs.
Es entstehen keine externen Anfragen oder Veröffentlichungsaktionen.

Vier betroffene Prüfgruppen bestehen gemeinsam. Die frisch installierte echte
Webspace-Fassung besteht 54 Prüfungen mit deaktivierten Shellfunktionen und
beschränkten Dateipfaden, einschließlich Manifest und PNG aus der paketierten
Schrift. Der echte Paketupgrade-/Abbruch-/Fortsetzungsweg besteht 146 Prüfungen.
Auslieferungsinventar und vollständiges Offlinepaket sind geprüft.

K-24 erhält damit den zuvor fehlenden Manifest-/Iconweg. Die übrige vollständige
Layout-/Bedienabnahme bleibt eigenständig offen. iPhone-/iPad- und Android-Apps
folgen weiterhin erst nach den übrigen vereinbarten Fach- und UI-Aufgaben.
Keine Schemaänderung; Generation 2026101512, 239 registrierte native Suiten.
