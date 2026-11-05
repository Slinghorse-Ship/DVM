# Website-Vorlagen im neuen CMS

Nutzerauftrag vom 10. Oktober 2026: neue Inhalte im eigenen CMS, **kein Import**
aus alten Websitebeständen. Das Kulturhus-Design wird einmalig als Vorlage erhalten.

Unter **Website → Design & Verbindungen** im gemeinsamen Manager bzw.
**Design & Vereinsdaten** im eigenständigen Redaktionszugang stehen fünf
Website-Vorlagen bereit:

| Vorlage | Gestaltung |
|---|---|
| Klar | Ruhiger Vereinsauftritt, Weißraum, klare Typografie, großzügige Karten |
| Magazin | Redaktioneller zweispaltiger Einstieg, Serifentypografie, gerade Linien |
| Aktiv | Blauer Einstieg, kontrastreiche Aktionen, kräftige Schrift und geometrische Flächen |
| Natur | Zentrierter Einstieg, sanfte Grüntöne und organische Formen |
| Kulturhus | Einmalig übernommenes ursprüngliches Plakatdesign und Vereinsmotiv |

**Ansehen** öffnet eine private Vorschau der veröffentlichten Startseite.
Ohne veröffentlichte Startseite erscheinen neutrale Beispieltexte ausschließlich
in der Vorschau; es werden keine Seiten angelegt. Die Vorschau benötigt aktuelle
Website-Leserechte, wird nicht gecacht und ist für Suchmaschinen gesperrt.

**Vorlage verwenden** wechselt die Gestaltung der öffentlichen Website. Dazu sind
Verwaltungs- und Veröffentlichungsrechte erforderlich. Der aktuelle Versionsstand
verhindert das Überschreiben eines zwischenzeitlichen Designwechsels. Es gibt einen
eigenen Designverlauf; derselbe Wechsel erzeugt keine zusätzliche Änderung.
Die Auswahl funktioniert auch ohne JavaScript. Die Websitegestaltung gilt ebenso
für Inhaltsseiten, Beiträge, Veranstaltungen, Galerie und Mitgliedsformular.

Neue Installationen starten mit **Klar**. Bestehende native Websites ohne eigene
Designauswahl behalten **Kulturhus**, bis eine andere Vorlage bewusst gewählt wird.
Eigene Inhalte, Revisionen, Veröffentlichungen, Bilder, Vereinsangaben sowie
Newsletter-/Social-Freigaben werden nicht umgeschrieben. Eine neue Installation
oder ein Release ist für einen Designwechsel nicht erforderlich.

Die additive CMS-Schemaversion **8** ergänzt `khcms_website_design` und dessen
Verlauf. Schemaänderungen erfolgen durch die ausdrückliche Installations-/CLI-
Schemaverwaltung; normale HTTP-Anfragen erzeugen keine Tabellen. Die Vorlagen
enthalten lokale Styles und Systemschriften, ohne externe Designanbieter.

Nachweis: 46 MySQL-/Dienstverträge und 250 tatsächliche Browser-/HTTP-Prüfungen
beider Zugänge mit 14 privaten synthetischen Designwechseln bestehen.

Prüfungen: `native-website-design` für Rechte, Versionskonflikte, private Vorschau,
tatsächliche gecachte öffentliche Ausgabe, Vereinsbegrenzung und Originalbytes;
`native-website-design-browser.mjs` für beide Redaktionszugänge bei 1440/820/390
Pixeln, 200-%-Zoom, Touch, Tastatur, JavaScript-freie Formulare und HTTP-Verträge.
Header-/Footerlinks, Menütaste und verlinkte Newsüberschriften aller fünf Vorlagen
haben mindestens 44 × 44 Pixel große Touchziele.
Der Browserlauf verwendet ausschließlich eigene synthetische Entwicklungskonten.
Die zugehörige Fixture prüft danach die unveränderten Seiten-, Revisions-,
Medien- und Vereinsdaten und die wiederhergestellte ursprüngliche Vorlage.

Die Vorlagen verwenden die gemeinsame Anwendung;
[Architektur](ARCHITECTURE.md) und [Installation](WEBSPACE-INSTALLATION.md)
beschreiben ihre technischen Voraussetzungen.

Aktuelle befüllte Vorlagen-/Menüabnahme und korrigierte Bootstrap-6-/JavaScript-freie Handynavigation: [Website-Navigation](WEBSITE-NAVIGATION.md).
