# Aktueller Softwareabgleich der Website

Die vollständige unveränderte Regression am Stand `3a63e75` besteht mit allen
227 registrierten Gruppen. Die spätere Newsletter-Formularkorrektur `e60e84c`
besitzt eine eigene vollständige Abnahme ihrer fünf betroffenen Gruppen,
19 Originalprüfungen und 116 Browserprüfungen. Diese Nachweise werden getrennt
geführt; eine spätere Gruppe wird nicht nachträglich in den früheren Lauf gezählt.

K-05, K-06 und K-07 sind im vereinbarten Softwareumfang implementiert. Die dort
noch genannten offenen Bildlebenszyklen waren veraltete Zwischenstandstexte.
`Cms/MediaRemoval`, `MediaRemovalGuard`, `MediaRetention` und
`MediaQuarantineStore` enthalten ursprüngliches Entfernen, privates Zurückholen,
Mindestfristen, ausdrücklich bestätigtes endgültiges Löschen und unterbrochene
Fortsetzung. Vor jeder Änderung werden aktuelle eigene Rechte und vollständige
Verweise erneut geprüft; SQL-Transaktionen schließen neue gleichzeitige
Verwendungen während der Änderung aus. Historische Artikel-/Album-/Bildangaben
werden dadurch nicht auf eine neuere Fassung umgebunden.

Der aktuelle volle Lauf enthält Galerie, Bildangaben/-verwendungen,
Referenzprüfung, Archiv, Entfernen, Mindestaufbewahrung, Purge und ihre Bedienwege.
Die vorhandenen browsergestützten Detailnachweise gelten weiter:
[Bestätigtes Bildlöschen](CMS-BILDLOESCHEN-BEDIENUNG.md),
[Mindestaufbewahrung](CMS-BILDAUFBEWAHRUNG.md),
[Großansicht](GALERIE-GROSSANSICHT.md) und
[Albumwechsel](GALERIE-ALBUMWECHSEL.md).
Weitere unzugeordnete Dateigruppen und allgemeine Medien-/UI-Aufgaben bleiben
getrennt in K-27 und K-35 offen.

K-12 bezeichnet die implementierten technischen Impressums-/Datenschutzseiten,
Bearbeitung, ursprünglichen öffentlichen Adressen, Anker und Footerlinks. Neue
Kundentexte werden redaktionell eingegeben; deren rechtliche Inhaltsprüfung ist
keine fehlende CMS-Funktion. Es werden keine Kundenangaben oder Rechtsinhalte
erfunden. K-16 entfällt als versteckter allgemeiner Altcontainer
nach dem ausdrücklich festgelegten [Ablöseumfang](WEBSITE-ABNAHME.md).
Die tatsächlich benötigten RSS-/ICS-/JSON-Wege K-17 bis K-19 sind implementiert.

Damit stehen 16 der 34 benötigten K-Softwarebereiche auf implementiert,
18 auf teilweise; der zusätzliche K-16-Vergleichseintrag entfällt. Das ist kein
Prozentsatz des gesamten Auftrags: weitere Vereins-/Fach-/UI-Funktionen,
Newsletter-Speichervorgänge und PDF-Anlagen, Apps und abschließende gemeinsame
Statusseite bleiben im Auftrag. P-01 bis P-25 bleiben optionales technisches
Vergleichsinventar. Kein 100-%-Gesamtabschluss.
