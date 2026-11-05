# Finanz-Vorauswahl und Steuerkonten

Unter **Einstellungen → Finanz-Vorauswahl & Kontenanzeige** die vorgeschlagenen
Sphären je Buchungskategorie und die Kontenanzeige getrennt für 2025 und 2026
bearbeiten. Finanzschreibrecht genügt; allgemeine Einstellungs- und
Mitgliederrechte werden dafür nicht benötigt. Finanzleserecht erlaubt die
aktuelle vollständige Prüfung ohne Änderungsfelder.

Die Vorauswahl begrenzt Vorschläge neuer Formulare. Sie aktiviert oder sperrt
kein Steuerkonto, verändert keine Buchung, Zahlung oder frühere Kontierung und
entfernt keine Konten aus dem vollständigen Kontenplan oder Export. Vorhandene
ausgewählte Konten bleiben auch dann in ihrem Formular erhalten, wenn die neue
Vorauswahl sie ausblendet. Gemischte Tätigkeiten erhalten eine offene Sphäre zur
Prüfung je Position.

## Vollständig ohne JavaScript auswählen

Im eigenen ungespeicherten Entwurf für jedes Jahr **Alle aktiven Steuerkonten
anzeigen** oder **Nur unsere ausgewählten Steuerkonten anzeigen** wählen.
Eine leere bewusste Auswahl bietet zunächst keine neuen Konten an; die
Kontierung kann weiter offen geprüft werden.

Unter **Konten suchen, auswählen oder entfernen** das gewünschte Jahr öffnen,
Kontonummer oder Bezeichnung suchen und direkt eine Sechserseite wählen.
**Konto hinzufügen**, **Konto entfernen**, Bereich hinzufügen oder entfernen
und Jahresauswahl leeren ändern nur diesen privaten Entwurf. Alle übrigen
Sphären, Jahre, Modi und vollständigen Kontonummern bleiben auch bei ungültigen
Angaben erhalten. Wer die Nummern schon kennt, kann die gesamte durch Komma
getrennte Auswahl direkt bearbeiten. Führende Nullen bleiben erhalten.

**Vollständige Finanz-Vorauswahl prüfen** zeigt alte gespeicherte Werte, ganze
gewünschte Eingaben, nach Bestätigung gespeicherte Werte und tatsächliche
Kontenangaben. Erst nach ausdrücklicher Bestätigung speichert **Geprüfte
Finanz-Vorauswahl bestätigen** eine neue Fassung. Suche und Prüfung speichern
keine Fachänderung. Ungültige ganze Eingaben und Seiten bleiben zur Korrektur
stehen und wählen keine Ersatzwerte oder Konten.

## Erste Bestätigung und Wiederaufnahme

Die erste persönliche Bestätigung erhält vollständige Eingaben einschließlich
der ursprünglichen Kontenreihenfolge, damalige Prüfung und Kontenmetadaten,
tatsächlich gespeichertes Ergebnis, Konto, Zeitpunkt und SHA-256.
**Original-JSON herunterladen** lädt exakt diese erste Datei. Eine spätere
Kontenumbenennung, neue Vorauswahl oder reine Ausblendung ersetzt sie nicht.

Bei verlorener Antwort zuerst **Ursprünglichen Finanz-Vorauswahlausgang lesend
prüfen**, bei Bedarf in einem neuen Tab. Eine schon angenommene Kennung erzeugt
keine weitere Fassung. Nach einem Konflikt **Aktuelle Finanz-Vorauswahl bewusst
prüfen** verwenden: Gewünschte Angaben bleiben erhalten, der aktuelle Stand
wird neu gelesen. Frühere ganze Vorschläge bleiben getrennt lesbar. Zwei Tabs
überschreiben keine spätere bestätigte Entscheidung. Einen sicher
ungespeicherten Entwurf bei Bedarf ausdrücklich verwerfen.

Eigene Bestätigungen und tatsächlich gespeicherte rohe Einstellungsfassungen
besitzen getrennte Suche beziehungsweise 25er-Seiten. Die rohe Fassung ist
bytegleich herunterladbar und beweist keine damalige vollständige Prüfung oder
Zustimmung. Aktuelle Finanzrechte sowie Konto- und Vereinsbindung gelten auch
für bekannte Kennungen und alte Tabs.

Isolierte Prüfungen verwenden echte Finanzoriginale, aktuelle Kontenänderungen,
SQL-Fehler nach ausgeführter Einstellungsspeicherung, verlorene tatsächliche
COMMIT-Bestätigungen und zwei unabhängige PHP-Prozesspaare. Ganze frühere
Geschäftszeilen und erste Originale bleiben erhalten. Diese Auswahlprüfung ist
keine abschließende SKR42-, EÜR-, Bilanz- oder externe DATEV-Abnahme.

# Frühere Quellenprüfung vom 7. September 2026

Die Konten fehlten in der kurzen Formularauswahl: Diese enthielt zuvor nur die Starterkonten. Der größere Auswahldialog kannte bereits den aktiven Katalog. Außerdem änderte eine Dialogauswahl den zugrunde liegenden Wert ohne Änderungsereignis; dadurch konnte die sichtbare Beschriftung des kombinierten Suchfelds veraltet bleiben.

Das normale Suchfeld enthält jetzt alle 1.802 aktiven Konten, auch in neu hinzugefügten Positionszeilen. Der Dialog aktualisiert Wert und sichtbare Beschriftung gemeinsam. Historische bereits ausgewählte Konten bleiben erhalten; gesperrte Konten werden nicht allgemein freigeschaltet. Die bestehende Erklärung von Sphäre, Steuerkonto und BU-Schlüssel bleibt direkt an den Positionen.

## Quellenabgleich

Das frisch heruntergeladene [offizielle DATEV-SKR42-PDF für 2026](https://download.datev-shop.de/X7xkGEfqdeYuynPtuXCf5YKaSZyEji6f/12021) ist mit der dokumentierten Katalogquelle byteidentisch: SHA-256 `643c9a0c764b5fa694e10c3a3239faafa8d28d193dba377f43a964c59f89fbe9`. Die 32 Seiten ergeben im vorhandenen Parser 1.556 Quelleinträge ohne ignorierte Einträge und 2.727 Haupt- und Einzelkonten. Davon sind 1.802 aktiv; reservierte oder widersprüchliche Quelleinträge bleiben gesperrt.

Eine unabhängige Prüfung der Kontonummern am Zeilenanfang fand keine belegte fehlende Einzelkontonummer. Fünf zunächst verdächtige Nummern waren lediglich Grenzen von im Text genannten Kontenbereichen. Es wurden deshalb keine Kontenbereiche pauschal aufgefüllt und keine Referenzdaten geändert.

DATEV bietet getrennte [Bilanz-](https://www.datev.de/web/de/shop/produkt-details/12901) und [EÜR-Ausgaben](https://www.datev.de/web/de/shop/produkt-details/datev-kontenrahmen-skr-42-vereine-stiftungen-ggmbh-4-abs-3-estg-12902). Dieser Abgleich bestätigt die hinterlegte Quelle; er ist kein Nachweis sämtlicher EÜR-Abschlusszuordnungen oder sämtlicher DATEV-Buchungsfunktionen. Vier steuerliche Sphären und der Sammelposten 9 bleiben unverändert.

## Tatsächlich geprüft

- `tests/tax-picker-complete.php`: vollständige aktive Auswahl in vorhandener und dynamischer Zeile, führende Null, gesperrte Konten, historische Auswahl und integrierte Kontierungshilfe.
- Lokaler Browser mit synthetischer Rolle: Nicht-Starterkonto 05200 direkt gesucht und gewählt; Dialogwahl 06500 aktualisiert den sichtbaren Wert; zusätzliche Position besitzt den vollständigen Katalog; bei 390 Pixeln kein horizontaler Überlauf. Keine Rechnung gespeichert.
- PHP-Syntax der Positionsansicht und JavaScript-Syntax von `app.js` geprüft.

Keine produktiven Kontierungen oder Stammdaten wurden für diese Prüfung verändert. Die Browserprüfung ist ein gezielter Regressionstest der Auswahl, keine Vollprüfung aller Kontierungs- und Exportfälle.
