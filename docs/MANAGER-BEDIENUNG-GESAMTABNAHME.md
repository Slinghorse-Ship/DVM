# Vollständige Manager-Bedienprüfung

Die neue portable Gruppe `native-manager-controls` verwendet eigene bestätigte
persönliche Konten für Verwaltung, Mitglied und Redaktion. Sie benötigt keine
bestehenden Entwicklungskonten und verändert weder Entwicklungsdaten noch deren
Schlüssel. Der normale Kernlauf prüft neun tatsächliche geschützte HTTP-Anfragen.

```sh
DVM_MANAGER_CONTROLS_BROWSER=1 bash tools/ci/local-native.sh --core native-manager-controls
```

Der vollständige Browserlauf verwendet sechs Ansichten (1440×1000, 820×1180,
390×844, 320×720, 844×390 und 1180×820), normale und verdoppelte Schrift sowie
alle drei persönlichen Rollen jeweils mit und ohne JavaScript. Die Routen stammen
aus dem tatsächlichen rollenabhängigen Menü. JavaScript-Wechsel nutzen die echte
Manager-Navigation und prüfen Controllerantwort, erhaltenes Dokument und den
Lebenszyklus der eingebundenen Module. Ohne JavaScript werden die normalen
vollständigen Seiten angefordert.

Geprüft werden vorhandene Bootstrap-Steuerelemente, beschriftete Eingaben,
Touchflächen und Seitenbreite. Hinzu kommen tatsächlich geöffnete Profilbereiche,
ein befülltes Profil bei 320 Pixeln und echtem zweifachem CSS-Zoom, Kalenderauswahl,
Textwerkzeuge, Eingabedialog, Escape sowie das mobile Menü nach Seitenwechseln.
Die zweite Browsergruppe prüft die Bootstrap-Navigation bei vier Breiten.
Sämtliche ursprünglichen Geschäftstabellen und beide persönlichen Schlüssel
müssen nach dem Lauf bytegleich sein; Sitzungsverwaltung bleibt ausdrücklich
getrennt. Externe Aufrufe und geschäftliche POSTs sind im Browser verboten.

## Aktueller vollständiger Menüabgleich – Generation 2026101797

Die Auswahl verwendet sämtliche eigenen Verwaltungslinks unter `#navigation`.
Der aktuelle isolierte Bestand liefert 96 Verwaltungs-, 13 Mitglieder- und
30 Redaktionsrouten. Die frühere `.subnav`-Auswahl erfasste nur 34 der heutigen
96 Verwaltungslinks. Bestanden sind **3336 Ansichten/24207 Bedingungen**:
alle 139 Routen, sechs Größen, normale/doppelte Schrift und beide
JavaScript-Modi. Je echter geladener Route werden die zwölf Größen-/
Schriftkombinationen durch tatsächliche Größenänderung geprüft; 3336 sind
Darstellungen und keine HTTP-Anfragen. Zusätzlich bestehen **436 frisch
geöffnete Bootstrap-Navigationsansichten** bei vier Breiten.
Kein `FAST`, `PROFILE_ONLY` oder `TRANSITION_ONLY`, kein fehlgeschlagenes Audit,
Geschäfts-POST, externer Abruf oder Skriptfehler. Alle 469 vollständigen
geschützten Tabellen und zwei Schlüssel bleiben exakt.

Der Vereinsname bleibt auf dem Telefon vollständig lesbar; Aktionen an
Überschriften umbrechen. Gemeinsame Control-Klassen werden bei allgemeinen
Einstellungen, Kalender, Bank-Mitgliedersuche, Newsletterplanung, Steuerangaben,
System, Postfach und Wallet schon serverseitig ausgegeben. Die vorhandene lokale
Spendenformular-CSS ist explizit registriert, damit die echte Manager-Navigation
diese Seite öffnen kann. Die Asset- und Änderungsschutzregeln bleiben bestehen.
96 tatsächliche Verwaltungsantworten enthalten keine nicht registrierten Assets
oder ausführbaren Inline-Skripte.

Die laufende Anwendung bestätigt den Kopf in 16 Original-/16 synthetischen
Langnamenansichten sowie sechs echte DE/EN-Aufrufe mit 24 weiteren Darstellungen
und den korrigierten Spendenwechsel. Die ursprüngliche Sprache ist restauriert;
467 ganze geschützte Tabellen und vier Schlüssel sind exakt erhalten.
Zusätzliche Bank-/Ausweis-/Steuer-/System-/Postfach-/Planungs-/Menügruppen bestehen.
Ein erster Bankbrowserlauf war wegen eines noch geschlossenen Folgeformulars rot;
der getrennte vollständige Wiederholungslauf wartet ausdrücklich auf dessen
Anhängen und tatsächliche Öffnung. Keine erzwungene Bedienung oder Bankänderung.

Die nachfolgenden älteren Zahlen gehören zu ihren damaligen Quellenständen.
Die aktuelle gemeinsame Fach-/Sprach-/Fehler- und Geräteabnahme sowie P-12/P-25
bleiben im Umsetzungsregister offen. Diese Menüprüfung ersetzt sie nicht.

Prüflog-SHA-256:
`f0ff9d89e830e376d8bd691f0efdc9764fcf03445816d6eb92cf3c900c0fcc2d`.

## Behobene Bedienfehler

Formularfelder und Kontrollkästchen erhalten ihre Bootstrap-Klassen bereits im
serverseitigen HTML. Das schließt Chatfilter, Newsletter-/Presseauswahl,
Videometadaten/-editor, Facebook-Korrektur, Website-PDFs, Abrechnung und
Besprechungsfilter sowie versteckte Such-/Kameraschaltflächen ein.

- Das befüllte Mitgliedsprofil passt die Überschrift an die tatsächliche
  Containerbreite an. Aktionen verdrängen den Namen bei schmaler Zoomansicht
  nicht mehr auf eine senkrechte Buchstabenfolge; Dokumentaktionen umbrechen.
- Feldbedingungen, Feldgruppen und Chat initialisieren synchron vor der Erfassung
  des unveränderten Formularstands. Bloßes Wechseln erzeugt keine falsche
  Verwerfen-Warnung. Tatsächliche menschliche Änderungen bleiben geschützt.
- Der automatisch erneuerte Chat-Lesecursor ist ausdrücklich als verborgenes
  Navigationsmetadatum markiert. Er zählt nicht als menschliche Eingabe; sichtbare
  Felder bleiben vollständig in der Änderungserkennung.
- Der Bank-Verknüpfungslink ist kürzer und umbrechbar. Kassenbuch und Kontoumsätze
  besitzen eigenständig benannte, tastaturfokussierbare Scrollbereiche.
- Lange Wörter in der GEMA-Übersicht und in freien Listen umbrechen bei 320 Pixeln mit
  vergrößerter Schrift.

Der gezielte echte Wechsel Feldverwaltung → Chat → Feldverwaltung, anschließende
menschliche Änderung, Abbruch der Navigation, unveränderte Eingabe und Wiederkehr
zum Original besteht 29 Browserbedingungen sowie neun HTTP-Prüfungen.
Alle 382 ursprünglichen Tabellen und beide Schlüssel bleiben unverändert.
Log-SHA-256: `7389bafce95453ccb923b7e7c8254f3c786f5f8dc432b0f5a5290cae7118b61c`.

## Umfang des Gesamtnachweises

Die vollständige Menüprüfung besteht 1.296 Ansichten und 8.527 Bedingungen:
33 Verwaltungs-, neun Mitglieds- und zwölf Redaktionsseiten jeweils in allen
24 Kombinationen. Der zusätzliche Navigationstest besteht 168 Ansichten für
Verwaltung und Mitglied bei vier Breiten. Die tatsächliche Sichtbarkeit des
Menüknopfs bestimmt die Bedienung; auch 820 und 320 Pixel verwenden ihre echte
ausklappbare Navigation. Die ursprünglichen 382 Geschäftstabellen und beide
Schlüssel sind nach beiden Browsergruppen bytegleich erhalten. Keine
JavaScriptfehler, externen Browseraufrufe oder geschäftlichen Browser-POSTs.
Der vollständige Lauf endet mit `suites:1`, `failed:[]`.
Log-SHA-256: `d8bfe946e64dd3612deb3f799f20dd14ef534874ee0dc7b6f29d5489db6379a2`.
Matrix-SHA-256: `7fac0558f9a96f5a60780aa540cb632993d05d8859501b0faf28f87c429d82a4`.
Die Matrix wurde unabhängig auf jede Rolle, Route, Größe, Schrift und beide
JavaScript-Modi abgeglichen; keine fehlende oder doppelte Kombination. Die Modi `FAST`, `PROFILE_ONLY` und
`TRANSITION_ONLY` dienen ausschließlich der gezielten Diagnose; ihre Ergebnisse
sind kein Nachweis für die vollständige Matrix. K-35 ist damit als Software abgenommen. Apps und abschließende gemeinsame
Statusseite bleiben gesondert offen. Physische Geräte und echte Kundenanbieter
haben ihre eigene Betriebsabnahme.

## Vollständiger Fachbrowserabgleich und getrennte Korrekturen

Der unveränderte Commit `12e3265` führte alle 106 vorgesehenen Browserziele aus:
vier allgemeine Bild-/Editorprüfungen, 101 registrierte Fachbrowserkombinationen
und die echte Webspace-Browserinstallation. 103 Ziele bestanden unmittelbar.
Drei veraltete Testannahmen wurden gezielt korrigiert: JavaScript-freie Navigation
bei der Preiskalkulation abwarten, das Website-Menü vor „Aktuelles“ öffnen und die
gespeicherte Videokarte vor ihrer Freigabe öffnen. Die Originalbedingungen und
fachlichen Ablehnungsprüfungen bleiben erhalten. Die Video-Touchprüfung sieht
nun tatsächlich die geöffneten Bedienflächen statt ausschließlich deren Summary.

Alle drei korrigierten Gruppen bestehen gemeinsam mit `suites:3`, `failed:[]`,
38 Preis-, 128 Leseansicht- und 180 Video-Fachbedingungen sowie den tatsächlichen
Browserabläufen. Die Preiskalkulation besitzt 59 Browserbedingungen und zwei bewusste eigene
Formularaktionen; die Leseansicht 324 Browserbedingungen zusätzlich zu ihren
128 Fachbedingungen, die Videofreigabe
28 mit genau drei bewussten eigenen Formularaktionen. Keine Live-Zustellung.
SHA-256 des gemeinsamen Korrekturlaufs: `c72ab8d62acda7ddfd1b7836240ce488db92001670b6fabaacea9d4996533cfb`.

Die unveränderte 106-Ziele-Zusammenfassung hat SHA-256
`57d54be9a740b107708a951fe742baf4cbc856bf2affb4196a6901fc638f59b9`.
Sie bleibt als ursprünglicher Lauf mit drei Fehlschlägen erhalten; die getrennten
grünen Korrekturen werden nicht rückwirkend als Bestandteil dieses Laufs gezählt.
Die Webspace-Browserinstallation besteht 56 Bedingungen einschließlich Anmeldung.

## Nachprüfungen der serverseitigen Steuerelemente

Elf ergänzende Fachgruppen bestehen nach Ergänzung der Bootstrap-Klassen.
Die vorhandenen Prüfungen behalten ihre vollständigen Auswahl-, Freigabe-,
Rückkehr-, Originaldatei- und Datenbankbedingungen bei.

- Vier Kommunikations-/Mediengruppen: Facebook-Beitragskorrektur,
  Newsletterkontakte, Presseempfänger und Videometadaten. Ergebnis `suites:4`,
  `failed:[]`; Log-SHA-256
  `61e4e08a6a132b75a4106ecae4624d74ece65f6595a434bb3f14f8e672f6dc5a`.
- Fünf ergänzende Gruppen: Kalenderpersonen, Tarifabrechnung, Tickets/Gutscheine/
  Kasse, Reel-Studio und Website-PDFs. Ergebnis `suites:5`, `failed:[]`; Log-SHA-256
  `ccedbd2a9c47058cd5cb2b620c7a8339e47fcc3f181501a9f4453eb2bc1ebb08`.
- Gemeinsame Kontakt- und Kalenderempfängerauswahl: Ergebnis `suites:2`,
  `failed:[]`; Log-SHA-256
  `0f19822ae568c2e62d1ff2c75cd4c2d8826a1a83477b854b5a89c12baf15bd22`.

Alle Läufe verwenden eigene isolierte Prüfbestände und keine Live-Zustellung.

Die sechs befüllten Vereinschatgruppen bestehen nach den Initialisierungsänderungen
zusätzlich gemeinsam. Antworten, Anhänge, Umfragen und Gerätehinweise bleiben
originalerhaltend verbunden. Log-SHA-256:
`0e91d8610cf684803f790978ffc7800359eedadcd8daff545136a7a1b928a62f`.

Neuinstallation 231, Paketupgrade 146 plus 90 Browserbedingungen und unterbrochene
Wiederherstellung 27 einschließlich drei echter Prozessabbrüche bestanden.
Der Webspace-Browsertest besteht 56 Bedingungen über den normalen lokalen Runner;
sein Modul-/Chromium-Fallback berücksichtigt nun auch dessen Standardvariablen.
