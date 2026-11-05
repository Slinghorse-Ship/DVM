# Website-Kommentare

## Aktuelle Scroll- und Formularabnahme, 18. Oktober 2026

Der erneute vollständige Lauf reproduzierte den offenen Alias-Klickfehler auch
im nativen Zugang ohne JavaScript. Das geerbte weiche Seitenscrollen verhinderte
den stabilen nativen Klick nach Tastaturnavigation im Gespräch. Eine isolierte
Wiedergabe derselben ursprünglichen Fehlerseite mit registrierten Originalassets
grenzt die Ursache auf dieses Scrollverhalten ein. Die zusätzliche Gegenprobe
bei großer Schrift fand den mehrzeiligen feststehenden Websitekopf über dem
Formular. Beide Regeln sind ausschließlich auf Kommentarseiten begrenzt:
sofortiges Seitenscrollen und ein mitscrollender Websitekopf.

Der erweiterte echte Browserlauf öffnet den Aliasbereich in jeder der 48
Gesprächsansichten durch einen normalen Klick nach `Ende` im Gespräch und
stellt ihn per Tastatur wieder zurück. Keine erzwungenen Klicks, künstlichen
Wartezeiten, deaktivierten Aussagen oder unmittelbar gesetzten `open`-Attribute.
Alle **288 Ansichten und 4374 Bedingungen** bestehen, einschließlich der vier
Sende-/Antwort-/Moderations-/Korrektur-/Fehlerabläufe beider Anwendungen mit und
ohne JavaScript. 195 statische Routerassets, keine externen Anfragen oder
JavaScriptfehler; 423 ursprüngliche Geschäftstabellen, 272 frühere Kommentar-/
Historienzeilen, beide Schlüssel und Medien sind exakt erhalten.

Der gemeinsame gezielte Lauf besteht fünf Gruppen: `native-comments`,
`native-comment-fields`, `native-comments-ui`, `native-public-router` und
`native-public-aliases`. Die reine Fehlerseiten-Wiedergabe besteht zusätzlich
16 Klick-/Tastaturbedingungen bei 320/390/820/1440 Pixeln und 16/32-Pixel-Schrift.
Sie ersetzt den echten Anwendungs-/Datenbanklauf nicht. Das ursprüngliche
fehlgeschlagene Gesamtprotokoll bleibt als Fehlschlag erhalten.
Generation 2026101564; keine Schemaänderung und keine weitere Kerngruppe.
P-12 und die gesamte P-25-Abnahme bleiben eigenständige offene Aufgaben.

Protokoll `.local/test-results/comments-scroll-final.log`, SHA-256:
`8ec4fa09413a9b05a62b83bb8523d2275928350cb4c2cdc373daa2bc8305bfb8`.
Die geprüfte `comments.css` besitzt SHA-256
`b0d0c8e71d520b62c758725075de758f9b547a7d17929ec76f379553ce8629ec`;
der erweiterte Browser SHA-256
`ceab446681e48a35733ddecb7dd8853ba22d3997ad7e073bf5299fa227d087d5`.

Die tatsächliche Webspace-Installation mit deaktivierten Shellfunktionen besteht
57 Bedingungen einschließlich Anmeldung. Sechs Inventar-, neun Archiv- und sechs
SDK-Wiederherstellungsprüfungen sowie die vollständige Offline-Paketprüfung sind
grün. Installerprotokoll SHA-256:
`df711b002eb9ba6e8399274095ec52b1f3555ae5c13adb5866fcb84f2b942eb9`.
Paketprotokoll SHA-256:
`cb2bc2838a1ee084e6464b1510fa88c0f89b084693a7d5f1814d9c43023bc3cf`.
Die anschließend ergänzten Nachweistexte ändern keine Laufzeitquelle; Inventar
und vollständige Paketbytes werden vor dem Push erneut geprüft.

Wiederholbarer vollständiger Einstieg:

```sh
DVM_COMMENTS_BROWSER=1 bash tools/ci/local-native.sh --core native-comments native-comment-fields native-comments-ui native-public-router native-public-aliases
```

Die native Website besitzt eigene Gespräche zu veröffentlichten Seiten.
Kommentare sind zunächst geschlossen. Unter **Website → Seiten →
Website-Kommentare → Einstellungen** eine Seite wählen und ausdrücklich öffnen.
Neue Gespräche behalten zunächst die Prüfung vor der Veröffentlichung und
verlangen eine Anmeldung. Gastzugang und unmittelbare Veröffentlichung sind
getrennte, jederzeit änderbare Schalter. Das Modul sendet keine vorgegebenen
Antworten und erstellt keinen Kommentar ohne menschlich eingegebenen Text.

Eine Vorlage kann mehrere eigenständige Felder vom Typ **Kommentare** besitzen.
Nach der Seitenauswahl zeigt **Kommentarfeld** die aktuell erlaubten Felder;
jedes behält seine eigenen Öffnungs-, Gast- und Prüfregeln. Die Auswahl öffnet
kein anderes Gespräch. Aktuelle und auf der Originalseite gespeicherte
Feldrechte gelten gemeinsam, auch für Zähler, Freigabelisten und bekannte IDs.
Ein Kommentarfeld speichert in Seitenwerten ausschließlich `null`; menschliche
Texte bleiben im Gespräch und werden beim Kopieren einer Seite nicht dupliziert.

## Öffentliche Bedienung

Eine aktivierte Seite erhält einen gewöhnlichen Kommentarlink. Die persönliche
Gesprächsseite unter `/_comments` zeigt höchstens zwanzig veröffentlichte Texte,
standardmäßig die neueste Seite. Der Gesprächsausschnitt bleibt begrenzt;
ältere Seiten und lange einzelne Texte lassen sich gezielt öffnen. Antworten
zeigen den sichtbaren Bezug einschließlich Autoralias und kurzer Textvorschau,
auch wenn der ursprüngliche Kommentar auf einer älteren Seite steht.

Der Anzeigename ist ein Alias. Das Modul behauptet keine überprüfte öffentliche
Identität. Die optionale E-Mail-Adresse erscheint nicht im öffentlichen
Gespräch. Einfacher Text bleibt maskierter menschlicher Quelltext. Optionales
Markdown wird mit dem lokal gelieferten Parser formatiert und anschließend
erneut bereinigt; fremde Bilder und ausführbare Elemente gelangen nicht in die
Ausgabe. Ein zurückgehaltener Elternkommentar verbirgt auch dessen Antworten.

Die Kommentarformulare funktionieren ohne JavaScript. Jeder Formularaufruf
bindet Seite, Kommentarfeld, Antwortbezug und Vorgang an einen eigenen privaten
Sitzungskontext. Ein öffentliches Formular kann diese Ziele nicht durch
zusätzliche versteckte Eingaben ändern. CSRF und ein opaker Vorgangsschlüssel
schützen das Senden. Eine identische Wiederholung nach verloren gegangener
Antwort liefert denselben Kommentar. Ein anderer Text mit demselben bereits
angenommenen Schlüssel überschreibt das ursprüngliche Gespräch nicht.
Wird der ursprüngliche Antwortbezug während des Schreibens entzogen, bleibt
der eigene ungesendete Text privat erhalten. **Kommentare neu öffnen** lädt den
aktuellen Zusammenhang; eine Antwort wird nicht automatisch umgehängt.

Persönliche Formulare und eigene Eingangsbestätigungen liegen ausschließlich
auf dem mit `private, no-store` ausgelieferten Kommentarendpoint. Im normalen
Websitecache liegt nur der statische Link. Bereits deaktivierte, private,
zurückgezogene oder nicht mehr öffentlich berechtigte Originalseiten lassen
sich über eine bekannte Kommentar-ID nicht öffnen.

## Prüfen und korrigieren

Die Verwaltung zeigt zwanzig Kommentare je Seite, mit Status, Anzeigename,
zugehöriger Originalseite und Kommentarfeld. Freigabe, Zurückhalten, Ablehnen,
Spam und Ausblenden sind bewusste aktuelle Entscheidungen. Dafür werden die
aktuellen Website-Veröffentlichungsrechte und Seitenrechte geprüft.
Kommentareinstellungen benötigen zusätzlich die Website-Verwaltung.

Korrekturen behalten vorherige menschliche Texte als unveränderliche Fassungen.
Der Verlauf zeigt zehn Fassungen je Seite; eine gewählte ältere Fassung bleibt
schreibgeschützt. Bei zwischenzeitlicher Änderung erhalten Fehlerformulare den
eigenen ungespeicherten Text, Alias, das Textformat und den alten Versionsstand.
**Aktuellen Stand öffnen** lädt die inzwischen gespeicherte Fassung erst nach
dem ausdrücklichen Klick. Das Modul speichert eine fehlgeschlagene Korrektur
nicht automatisch erneut.

Anonyme und angemeldete Stimmen verwenden dieselben Moderationseinstellungen.
Ein angemeldetes Konto gehört zum tatsächlich aktuellen Verein; ein alter
Manageraktor oder die feste öffentliche Leseidentität gilt nicht als
angemeldeter Kommentarautor. Interne Kontozuordnung und Kontaktadresse werden
verschlüsselt gespeichert. Private Kontakte sind gesondert auf aktuelle
Verwaltungsrechte beschränkt. Ratenbegrenzung speichert Fingerabdrücke statt
offener Netzwerkadressen.

## Installation und Abnahme

Schema `comments` Version 1 ergänzt vier Tabellen ausschließlich im Wartungsweg.
Webaufrufe installieren kein Schema. Kommentartexte werden nicht als kopierte
Seitenfeldwerte übertragen. Eine endgültige Seitenlöschung verlangt zusätzlich
die ausdrückliche Bestätigung zum Entfernen ihrer Gespräche und Einstellungen.
Originalseite, zugehörige Kommentartexte, Fassungen und Einstellungen werden
atomar entfernt. Ein späterer Löschfehler stellt alle Originale vollständig
wieder her; fremde Seiten und deren Gespräche bleiben erhalten.

```sh
bash tools/ci/local-native.sh --core native-comments native-comment-fields native-comments-ui
DVM_COMMENTS_BROWSER=1 bash tools/ci/local-native.sh --core native-comments-ui
DVM_COMMENTS_BROWSER=1 DVM_COMMENTS_ACTIONS_ONLY=1 bash tools/ci/local-native.sh --core native-comments native-comment-fields native-comments-ui
```

Die Abnahme vom **16. Oktober 2026** besteht aus 60 Dienst-, 30 Feld-/Lösch-
und 27 UI-Fachbedingungen. Der frische vollständige Aktionslauf prüft alle vier
Wege beider tatsächlichen Anwendungen mit und ohne JavaScript: menschliches
Senden, identische Wiederholung, Antworten, Freigabe, Korrektur, Historie,
409/422/CSRF, Gast-/Direktschalter und mehrere getrennte Kommentarfelder.
**198 Browserbedingungen**, 184 tatsächlich ausgelieferte statische
Routerassets und die abschließenden Originalhashes sind grün. **416 ursprüngliche
Tabellen, 272 vorhandene Kommentar-/Historienzeilen, Schlüssel und Medien**
bleiben bytegleich; keine externen Anfragen oder JavaScriptfehler.

Die bereits bestandenen **288 Layoutansichten** prüfen beide Anwendungen,
JavaScript/NoJS, sechs Bildschirmgrößen von 320 bis 1536 Pixeln, einfache und
doppelte Schrift sowie Gespräch, Prüfliste, Detail, Historie, Einstellungen
und Seitenauswahl. Der damalige Gesamtlauf scheiterte erst im späteren
Aktionsabschnitt an einer geschlossenen SPA-Assetregistrierung. Die feste
Kommentar-CSS-Zuordnung und ein tatsächlich fehlender nativer Meta-CSS-Pfad
sind korrigiert; der neue Vier-Wege-Aktionslauf endet vollständig grün.
Der ältere Diagnose-Gesamtlauf wird nicht als grüner Gesamtnachweis gezählt.

20 relevante Kommentar-, Darstellungs-, Fixture- und Assetquellen sind während
des finalen Laufs unverändert und physisch gesichert. Nachweisdateien:
`/tmp/kulturhus-comments-release-proof.json`,
`/tmp/kulturhus-comments-proof-source-frozen.json` und
`/tmp/kulturhus-comments-feature-frozen.tar.gz`.
SHA256 des finalen Laufprotokolls
`/tmp/kulturhus-comments-final-recheck.log`:
`8fbf5ff30b3ddd4b4833c9e20a01719ddb61f4a2e231ef263861a212e886ac76`.
SHA256 des gesicherten Quellenarchivs:
`161813677f87d6fa98af13f8327c0db4d786236863b432b75f6651eeebd77feb`.

Die vollständige Sprachwahl für Kommentarlinks, Formularzusammenhang und
Oberflächentexte bleibt Teil des gesonderten P-12-Fachoberflächenausbaus.
Menschliche Kommentare und Autoraliasnamen werden dabei nicht übersetzt.

## Vollständige kuratierte Schlussabnahme

Auf dem abgeschlossenen API-/Routing-/Datei-/Konten-/Transferstand besteht der unveränderliche Export jetzt den vollständigen Browserlauf: **4.278 Bedingungen in 288 Ansichten**, beide echten Apps mit und ohne JavaScript, 320 bis 1536 Pixel und einfache/doppelte Schrift. Derselbe Lauf besteht anschließend alle vier tatsächlichen Sende-, Wiederholungs-, Antwort-, Moderations-, Korrektur-, Verlaufs- und Fehlerwege; er ist vollständig grün. 184 statische Routerassets werden tatsächlich geprüft. Alle 416 ursprünglichen Tabellen, 272 bereits vorhandenen Kommentar-/Historienzeilen, beide Schlüssel und Medien bleiben exakt; keine externen Aktionen und keine JavaScriptfehler. Das ersetzt den früheren nur teilweise grünen Diagnose-Gesamtlauf als aktuellen vollständigen Auslieferungsnachweis.

Zehn angrenzende Fachgruppen sind in derselben kuratierten Zusammensetzung grün. Ein anschließender enger Zwei-Gruppen-Nachweis ergänzt die ausdrückliche Ablehnung von Kommentarfeldern als Seitenselektor und Sortierwert: **32 Feld-/Löschbedingungen und 89 Querybedingungen**. Menschliche Gesprächsdaten bleiben ausschließlich im zugeordneten Gesprächsbereich. Die allgemeinen begrenzten Pager- und sicheren Plain-/Markdown-/Richtext-Helfer sind mit der vorhandenen streamenden öffentlichen RSS-Ausgabe aus autorisierten Originalen verbunden; es wird kein fremder Feed automatisch abgerufen.

- `kulturhus-comments-isolated-domain.log`, SHA-256 `417047c5e928175212235cff90a635e4a1bf671c5f269f4a442bd4098a8d82f1`.
- `kulturhus-comments-isolated-browser.log`, SHA-256 `4b16dc9e0922362d27c0cd1b462563191197bdf2deb6a47e09330267e0085ec4`.
- `kulturhus-comments-isolated-query-curated.log`, SHA-256 `a9dfe5a9e183bb56c8f7cead5976c16dca3045cdf4886e9ef1f8e32505d70013`.

## Darstellbare neue Quellen und unveränderte Alttexte

Vor INSERT und tatsächlicher Textkorrektur wird derselbe geschlossene Formatter
wie in der Anzeige geprüft. Die wirkliche Grenze von 2000 Markdown-Zeilenumbrüchen
wird damit bereits vor Persistenz durchgesetzt. Derselbe Text bleibt als
normaler Klartext zulässig. Vorhandene gültige Originale, ursprüngliche Replays
und unveränderte Korrekturen bleiben erhalten; ein nur abgeleiteter sicherer
Klartextadapter hält alte fehlerhafte Markdownfassungen in Liste, Elternbezug,
Moderation und schreibgeschütztem Verlauf lesbar. Der lange Text bleibt zuerst
eingeklappt. Kein Originaltext, privater Hash oder historische Version wird
repariert oder überschrieben.

Die aktuelle isolierte Paketabnahme auf dem abgeschlossenen System-/CMS-Stand
besteht 74 Kommentar- und 27 UI-Bedingungen einschließlich wirklichem gültigem
Randtext, abgewiesenem INSERT/UPDATE ohne Zeilenänderung, Legacy-Replay, Noop,
DOM-geprüfter tatsächlicher Klartextanzeige und exaktem vollständigem Originalhash.

- `kulturhus-comments-markdown-isolated-domain.log`, SHA-256 `bfc5f0f308971d9ebddb35ea4178b7eb75ab8e39206ff2719b82d51eef4336e5`.
