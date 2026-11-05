# Videoanleitungen: Aktualisierung vom 9. September 2026

Die Handbuchbibliothek enthält jetzt 13 deutsch gesprochene Videos mit Untertiteln, Kapitelmarken und nummerierten Ausschnitten der tatsächlich laufenden Oberfläche. Vier Videos wurden ergänzt, zwei vorhandene Anleitungen an die vereinfachte Oberfläche angepasst. Die sieben übrigen MP4-Dateien und Vorschaubilder bleiben bytegenau unverändert.

| Anleitung | Änderung | Inhalt |
| --- | --- | --- |
| Rechnung prüfen | Neu, fünf Szenen | Eingang finden, Rechnungszweck zuerst wählen, Original und erkannte Angaben vergleichen, Lieferant und Positionen prüfen, fehlende Angaben finden und speichern |
| Protokolle | Neu, fünf Szenen | Bestehenden Entwurf suchen, Vorlage verwenden, schreiben, als Entwurf fortsetzen und Veröffentlichung bewusst unterscheiden |
| Mein Account | Neu, fünf Szenen | Benachrichtigungsthemen, Speichern, gerätebezogene Browserfreigabe, Anmeldedauer und Abmelden |
| Chatumfragen | Neu, vier Szenen | Richtige Unterhaltung wählen, Frage und Antworten anlegen, Antwort speichern oder ändern, Ende und Ergebnis verstehen |
| Mitglied anlegen | Erneuert, vier Szenen | Hauptangaben zuerst, zusätzliche Angaben aufklappen, Zugangsmail bewusst wählen |
| Fristen & Nachweise | Erneuert, drei Szenen | Vorhandenen Nachweis suchen, Datei direkt zuordnen, Frist und Zuständigkeit, optionale Personen-/Veranstaltungszuordnung |

Die sechs erneuerten oder neuen Videos bestehen zusammen aus 26 Szenen. Große Zahlen beziehen sich jeweils auf den direkt daneben sichtbaren Ausschnitt. Die gesprochene Anleitung verwendet die Beschriftungen der aktuellen Oberfläche. Hinweise zum Speichern, zum Unterschied zwischen Entwurf und Veröffentlichung sowie zwischen Rechnung und tatsächlicher Zahlung sind Teil der Anleitung.

## Daten und Aufnahmen

Sieben neue nummerierte Ausschnitte stammen ausschließlich aus dem lokalen synthetischen Verein `tutorial-ui-*` unter `http://127.0.0.1:8091`. Rechnung und Lieferant sind erfunden: Mustertechnik GmbH, eine Adresse in Musterort und `example.invalid`. Das erzeugte Rechnungsdokument ist ausdrücklich als fiktiv gekennzeichnet. Der Chat enthält eine erfundene Aufbauumfrage. Kein Mitgliedsbestand der Livewebsite wurde verwendet.

Die Bildschirmaufnahmen erfolgen über die echte Seite. Der Aufnahmehelfer blockiert Nicht-GET-Anfragen und externe Ziele. Lediglich die erklärenden Rahmen und Zahlen werden für die Aufnahme ergänzt. Die Chataufnahme prüft zusätzlich, dass Frage und Speichern-Schaltfläche innerhalb des tatsächlich sichtbaren Scrollbereichs liegen. Finanzdetails werden mit einer breiteren Tabletansicht aufgenommen, damit die Beschriftungen im Video lesbar bleiben.


## Prüfung

- Alle 13 Videos: Metadaten, 1440 × 900 Bildformat, tatsächliches Starten und Springen zur zweiten Szene, deutsche Textspur, Kapitelverweise und HTTP-Teilabruf im Browser geprüft.
- Vier neue Videos: Handbuchansicht bei 390 Pixeln ohne horizontalen Seitenüberlauf; keine JavaScriptfehler.
- Ungültige Medienpfade und unzulässige Bytebereiche werden abgewiesen. Insgesamt 16 Browserprüfungen.
- Die sechs neuen/erneuerten Videos wurden vollständig mit FFmpeg decodiert; Ton vorhanden und nicht stumm. Untertitel entsprechen vollständig der Sprecherfassung, haben höchstens zwei Zeilen je Einblendung und liegen innerhalb der Szenen-/Videodauer. Einschließlich Erhalt der sieben unveränderten Videos ergeben sich 13 Artefaktprüfungen.
- Alle sieben neuen Rohaufnahmen und repräsentative fertig gesetzte Szenen wurden visuell geprüft. Die automatische Szenengeometrie prüft außerdem die Textkästen aller 26 neu erzeugten Szenen auf Überlauf.

Browserprüfung: Microsoft Edge/Chromium im lokalen Testbetrieb, einschließlich mobiler Ansichtsbreite. Eine tatsächliche Wiedergabe auf einem iPhone oder Androidgerät und Betriebssystem-Pushdialoge sind damit nicht nachgewiesen. Der synthetische Standalone-Login ist kein Test der produktiven Anmeldegrenze. Untertitel sind innerhalb der gesprochenen Szene nach Textlänge zeitlich verteilt, nicht Wort für Wort synchronisiert.

## Reproduzierbarkeit

Quelle: `handbuch/tutorials.json` und `handbuch/figures.json`. Kompilierte Medien und Manifeste: `vereinsverwaltung/resources/handbook*`.

1. `tests/support/tutorials-ui-fixture.php` erzeugt einen eigenen synthetischen Verein. Niemals eine Produktivdatenbank oder vorhandene Agenten-Testdaten als Ziel verwenden.
2. `tools/tutorial-capture-current.cjs` nimmt die vorgesehenen Ansichten auf. `KULTURHUS_TUTORIAL_FIXTURE` verweist auf die lokale Fixture-Datei.
3. `tools/tutorial-speech.ps1`, `tools/tutorial-frames.cjs` und `tools/build-tutorials.py` erzeugen Ton, Szenen und Videos. Über `KULTURHUS_TUTORIAL_IDS` werden ausschließlich geänderte IDs gewählt, beispielsweise `mitglied,nachweise,finanzaufnahme,protokolle,account,chatumfragen`.
4. Ein Teilbuild übernimmt andere vorhandene Videos nur, wenn ihre Renderquelle unverändert ist und alle Dateien vorhanden sind. Andernfalls bricht er ab, statt stillschweigend veraltete Medien weiterzuführen.
5. `tests/tutorial-artifacts.py` und `tests/tutorial-videos-browser.cjs` prüfen die fertigen Artefakte. Die lokalen Ergebnisberichte liegen unter `.local/tutorial-build/video-*-result.json`.

Die Ausgabe ist für den gemeinsamen Handbuchbuild und die Veröffentlichung vorbereitet. Diese Aufgabe selbst veröffentlicht oder versendet nichts.

## Ergänzende Bilder für die allgemeine Ehrenamtsbestätigung

Zusätzlich wurden die Figuren `ehrenamt-allgemein-aktiv` und `ehrenamt-allgemein-passiv` für Kapitel 12 aufgenommen, registriert und visuell geprüft. Im abschließenden Stand zeigen sie den tatsächlich aus dem synthetischen Mitgliedsprofil übernommenen Status und die automatische Zuordnung zum aktuellen Kalenderjahr. Aktive Mitglieder prüfen die vorgeschlagenen Aufgaben, bei passiven Mitgliedern entfallen die Tätigkeitsfelder. Auch hierfür wurde nichts abgeschickt oder abgelegt. Der reproduzierbare Aufnahmehelfer ist `tools/handbook-volunteer-general-capture.cjs`; seine eigene Fixture wird mit `tests/support/volunteer-general-fixture.php` erstellt.

## Vollständiger Handbuchbuild

Der abschließende Build übernimmt auch die ergänzte ZUGFeRD-Anleitung in Kapitel 07, Kapitel 09 und die automatische Jahresbestätigung in Kapitel 12: 20 Kapitel, 285 Aufgaben und 45 eingebundene Bildanleitungen; weitere Figuren werden als Videoausschnitte verwendet. Die beiden allgemeinen Ehrenamtsbilder stehen direkt in Kapitel 12. Neu erzeugt wurden `docs/vereinsverwaltung/HANDBUCH.md`, `resources/handbook.html` und `resources/handbook-images.json`; die erzeugte Routenliste bleibt inhaltlich unverändert. Dieser letzte Handbuchbuild erzeugt keine Videos neu.

`tests/handbook-browser.cjs` hat den fertigen Gesamtstand geprüft: alle 45 Bilder ausgeliefert und im Browser decodiert, alle 13 Videos abgespielt und angesprungen, Untertitel und Bytebereiche, Suche einschließlich leerem Ergebnis, sämtliche internen Anker und eindeutige IDs, Desktop-/Mobilansicht ohne Seitenüberlauf sowie Handbuchzugang mit der synthetischen Mitgliederrolle. Starre Bild-/Videozahlen wurden durch Erwartungen aus den Markdownquellen und Manifesten ersetzt; die bisherige Mindestabdeckung bleibt als zusätzliche Prüfung erhalten. Desktop- und Mobilübersicht wurden visuell geprüft. `tests/handbook-routes.php` besteht mit 59 Kontext-Routen. Der gezielte `git diff --check` bleibt ohne Fehler.
