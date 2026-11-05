# Öffentliche Website-Navigation auf Handy und Tablet

Alle fünf Website-Vorlagen verwenden dieselbe zugängliche Navigation. Bei
schmalem Bildschirm öffnet **Menü** die vorhandenen veröffentlichten Ziele.
Die Menütaste und jeder Link haben mindestens 44 × 44 CSS-Pixel große Ziele.
**Escape** schließt das Menü und gibt den Tastaturfokus an die Menütaste zurück.

Ohne JavaScript stehen die Links direkt im Seitenkopf. Eine funktionslose
Menütaste wird dann nicht angezeigt. Auch bei 320 Pixeln und doppelter
Vergrößerung bleiben die eigentlichen Linkflächen innerhalb des Bildschirms.
Auf breiten Bildschirmen bleiben die Ziele unmittelbar sichtbar.

Die eigene Klasse `site-menu-toggle` verhindert die Kollision mit der
Bootstrap-6-Dropdownklasse `menu`, deren Transparenz und Skalierung zuvor auch
auf den öffentlichen Website-Schalter wirkten. Erst das erfolgreich geladene
öffentliche Skript aktiviert die einklappbare Darstellung. Die Vereinsbezeichnung,
vorhandenen Ziele und Inhalte kommen weiterhin aus den veröffentlichten eigenen
Originalen; die Navigation legt keine Beispielseiten an.

## Isolierter Nachweis

```sh
DVM_PUBLIC_MENU_BROWSER=1 bash tools/ci/local-native.sh --core native-website-layouts
```

72 tatsächliche Chromium-Verträge, zehn echte Touchbetätigungen, alle fünf
Vorlagen, 320 Pixel, 100/200 Prozent sowie mit/ohne JavaScript bestehen. Fünf
wirklich veröffentlichte Ziele prüfen die Linkflächen ohne seitliches Abschneiden.
Die ursprünglichen Seiten, Entwürfe, Revisionen, Vereinsdaten, Rechte, Kontakte,
privaten Schlüssel und übrigen Aufträge bleiben exakt erhalten. Die ursprüngliche
Vorlagenauswahl wird erhalten. Kein echter Anbietertransport.

SHA-256 des abgeschlossenen lokalen Logs:
`ac03f4ac193b736ba032e8c26023d897f2f551987238bd64d0b48a1a7a50e828`.

Acht zugehörige native Fachgruppen bestehen ebenfalls: Standardseiten,
Website-Layouts, Inhaltsabschnitte, Vorlagenauswahl, Website-Arbeitsbereich,
Veröffentlichung, öffentliches Manifest und HTTP-Grenzen. Paketgeneration
2026101515; keine neue Tabelle. Die übrige Manager-/Fachoberfläche, Apps und
abschließende gemeinsame Statusseite behalten ihre eigenen Aufgaben.

## Vollständiger Softwareabgleich K-24

| Bisherige Funktion | Verbindlicher eigener Nachweis |
|---|---|
| Kulturhus-Design und moderne Alternativen | Fünf tatsächliche verschiedene Layouts, private Originalvorschau, bewusste öffentliche Auswahl und Rückkehr zur ursprünglichen Vorlage |
| Kopf, Fußzeile, Handy-/Tabletmenü | Befüllte eigene Startseite mit fünf Navigationszielen, Fußzeilenlinks und zwei veröffentlichten Meldungen; Tastatur, Touch und JavaScript-freie Wege |
| Eigene lokale Assets | Gemeinsames festes Assetregister und installierter PHP-Webspace-Controller; keine zusätzliche CMS-Abhängigkeit |
| Manifest und Gerätesymbole | Vereinsname, Vorlagenfarbe und echte lokale Unicode-/PNG-Symbole; eigene [Manifestabnahme](WEBSITE-MANIFEST.md) |
| Redaktioneller Zugang | Persönlich angemeldete private Vorschau und aktueller eigener Managerlink, ohne private Folgeentwürfe öffentlich anzuzeigen |
| Konflikt und Wiederaufnahme | Alte Auswahl, unbekannte Vorlage und falsches CSRF zurückgewiesen; korrekturfähiges Formular auch ohne JavaScript |

Der separate vollständige Layoutlauf besteht 187 tatsächliche Browserverträge
und sieben ausdrückliche eigene Designwechsel: 1440/820/390/320 Pixel,
200 Prozent, Touch, Tastatur und JavaScript-freie Vorschau/Auswahl. SHA-256:
`2532a16c92cb0577305ee9f4e9021fdaddb0dc8dbb58df6b98c4158770b71bd8`.
Die unabhängige kurze Touch-/Menüabnahme mit ihren 72 Verträgen steht oben.
K-24 ist im vereinbarten Softwareumfang implementiert. Die vollständige übrige
Manager-/Fachoberfläche wird dadurch nicht als abgeschlossen ausgegeben.

Der isolierte Standardseitenlauf besteht zusätzlich 72 Browserverträge und neun
bewusste private Speicherungen bei vier Breiten. Einfügen, Sortieren, Entfernen,
Bilder/Credits, private Vorschau, Versionskonflikt und korrigierbare ungültige
Links ohne JavaScript bewahren die öffentlichen Originale. SHA-256:
`e48eb672af33e40b5ede60022826cf025b79dc40db11bcdedfdeb525012e2c2e`.
Die anschließend ergänzte kompakte Abschnittsbearbeitung und vollständige
Lesekontenvorschau besitzen eine eigene [aktuelle K-08-Abnahme](INHALTSSEITEN-GRUNDFUNKTIONEN-ABNAHME.md); der damalige reine Funktionslauf schloss sie noch nicht ab.
