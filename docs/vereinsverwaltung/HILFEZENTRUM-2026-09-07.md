# Kulturhus Hilfezentrum – Implementierung und Prüfung

## Ergebnis und Umfang

Das frühere Überblickshandbuch wurde durch 20 ausführliche Kapitel mit 177 Aufgaben ersetzt. Die Anleitungen decken die 57 Routen der neun Hauptmenügruppen ab. Sie beschreiben Menüweg, Voraussetzungen, konkrete Felder und Schaltflächen, Wirkung, Ergebnis und Korrekturen. Das Ziel ist eine erstmalige Benutzung ohne vorausgesetzte Programmkenntnisse. Der Abgleich gegen Navigation und Quellen ist in `handbuch/menu-coverage.json` dokumentiert; er ist keine pauschale fachliche Abnahme aller beschriebenen Funktionen.

29 echte, zugeschnittene Programmaufnahmen haben nummerierte Rahmen und zugehörige Erläuterungen direkt bei den Arbeitsschritten. Aufnahmen zeigen leere Felder oder erfundene Beispiele. Personen aus früheren Beispielen und echte öffentliche Veranstaltungsnamen/-bilder werden nicht übernommen. Die öffentlichen Programmzähler werden für die betreffende Aufnahme durch feste Beispielzahlen ersetzt. Die neue Bilder-Positivliste enthält keine der alten Überblicksabbildungen.

Neun tatsächlich erzeugte MP4-Videoanleitungen enthalten rund 14 Minuten deutschsprachige Erklärungen: Orientierung, Mitgliederanlage, Mitgliederimport, Dokument-Sammelupload, Newsletter, Galerie, GEMA, Nachweise und Finanzexport. Es sind Whiteboard-Szenen mit markierten echten Programmausschnitten, keine vorgetäuschte interaktive Fernbedienung und keine Aufzeichnung echter Vereinsvorgänge. Die computergesprochene Stimme wird in der Oberfläche benannt. Deutsche WebVTT-Untertitel, Kapitelknöpfe und vollständige Textalternativen sind vorhanden. Ein Video führt keine Aktion im Nutzerkonto aus.

## Bedienung im Programm

Der vorhandene Menüpunkt **Handbuch** führt zum Hilfezentrum. Suche, Inhaltsverzeichnis, Videoanleitungen und häufige Fragen stehen am Anfang. Links wie `?view=handbook#kapitel-18` bleiben erhalten. Ein Video kann auch direkt mit `#video-newsletter` geöffnet werden; geschlossene Elternabschnitte werden automatisch geöffnet. Das Hilfezentrum wird über **Handbuch** unten im Menü geöffnet. Der zusätzliche Link „Schrittanleitung zu dieser Seite“ auf den Arbeitsseiten wurde auf Nutzerwunsch entfernt.

Videos laden erst bei Bedarf. Kapitelknöpfe springen im vorhandenen Video; beim Start eines anderen Videos oder Schließen des Abschnitts wird die laufende Wiedergabe angehalten. Bilder lassen sich separat in Originalgröße öffnen. Die Druckansicht enthält die ausführlichen Anleitungen und Bilder, ohne Videoplayer und Navigationsflächen.

## Quellen und Generierung

- `docs/vereinsverwaltung/handbuch/01-*.md` bis `20-*.md`: redaktionelle Einzelkapitel; daraus wird auch `docs/vereinsverwaltung/HANDBUCH.md` erzeugt.
- `vereinsverwaltung/tools/build-handbook.py`: sicher begrenzter Markdown-Parser mit HTML-Escaping, festen Kapitel-/Aufgabenankern und inline gesetzten `:::bild`-Verweisen. Generiert `resources/handbook.html`, `handbook-images.json` und `handbook-routes.json`.
- `handbuch/figures.json`: maßgebliches Bilderverzeichnis mit Legenden, Aufnahmekontext, Prüfdatum und SHA-256. Ein verändertes Bild ohne erneute dokumentierte Prüfung verhindert den Build. `figures-root.json`, `figures-members.json` und `figures-extra.json` sind Zwischenstände aus der Aufnahme, nicht die Freigabequelle.
- `vereinsverwaltung/tools/handbook-capture.cjs`: echte lokale Browserausschnitte mit nur vorübergehend eingeblendeten DOM-Markierungen. Die Bilddatei wird nicht nachträglich retuschiert. Sichtbare Texte und Bilder werden für die lokale Prüfung protokolliert; bekannte Echtdaten und nicht freigegebene Fotos brechen die Aufnahme ab. Diese Prüfhilfe ersetzt nicht die Sichtkontrolle sämtlicher sichtbarer Inhalte.
- `handbuch/tutorials.json`: eigene Videodrehbücher. `tutorial-speech.ps1` erzeugt die deutsche Stimme lokal über die installierte Windows-Stimme. `tutorial-frames.cjs` gestaltet die originalen Whiteboard-Szenen. `build-tutorials.py` kodiert H.264/AAC, ergänzt Untertitelzeiten und Kapitel und schreibt die Medienverzeichnisse. Abhängigkeit: `imageio-ffmpeg`; Aufnahme: Playwright/Edge. Keine Vereinsdaten werden an einen Sprachdienst übertragen.
- Arbeitsdateien und Prüfaufnahmen liegen in `.local/tutorial-build` beziehungsweise `.local/handbook-capture-audit` und werden nicht veröffentlicht. Die lokalen Newsletter-/Albumbeispiele wurden nur als unveröffentlichte Testentwürfe erzeugt und anschließend entfernt beziehungsweise in den lokalen Papierkorb gelegt. Dokumente wurden lediglich vorübergehend übertragen und ohne endgültige Ablage wieder entfernt. Kein echter Versand, Bankauftrag oder GEMA-Antrag wurde ausgelöst.

## Zugriff und Auslieferung

Die Inhalte bleiben im geschützten Vereinsmanager. Die vorhandene Identitäts- und Arbeitsplatzprüfung läuft vor der Auslieferung. Bilddateien werden ausschließlich über die Bilder-Positivliste bedient. `HandbookMedia.php` liefert ausschließlich die ausdrücklich aufgeführten MP4-/VTT-/Posterdateien aus dem privaten Ressourcenverzeichnis; Dateinamen werden zusätzlich auf das erlaubte Schema geprüft. Es gibt keinen freien Dateipfadparameter.

Videos unterstützen einzelne HTTP-Bytebereiche, damit Start und Sprünge auch ohne vollständigen Download funktionieren. Ungültige beziehungsweise außerhalb der Datei liegende Bereiche werden mit 416 abgewiesen. Die Ausgabe erfolgt stückweise; die PHP-Sitzung wird vor dem Stream freigegeben. Die CSP erlaubt Medien nur für die Handbuchansicht und nur vom eigenen Ursprung. Die normalen Vereinsrechte werden nicht erweitert.

Der persönliche Inhalt anderer Arbeitsbereiche wird nicht in die Hilfe übernommen. Ein Mitglied kann die allgemeinen Anleitungen lesen, erhält dadurch aber keine zusätzlichen Mitglieds-, Finanz-, Redaktions- oder Vorstandsrechte.

## Prüfung vor Veröffentlichung

- Alle 57 dokumentierten Menürouten sind konkreten vorhandenen Kapitelaufgaben zugeordnet; zusätzlich sind Profil- und Konto-Detailrouten geprüft. `tests/handbook-routes.php`: 59 Zuordnungen.
- `tests/handbook-media.php`: 13 Bytebereichsfälle einschließlich offenen und Suffixbereichen sowie ungültigen/zu großen Werten.
- `tests/handbook-browser.cjs`: 20 Kapitel, 177 Aufgaben, 29 authentifizierte Bildantworten, eindeutige Anker, Suche mit/ohne Treffer, neun abspielbare und ansteuerbare Videos, Untertitel, 206/416-Antworten, Dateipfad-/Altbildablehnung, Desktop, 390-Pixel-Ansicht und normale Mitgliedsrolle.
- Sämtliche Videos vollständig mit FFmpeg dekodiert; Bild- und nicht stumme Audiospuren vorhanden. Gesamtdauer 843,9 Sekunden. Alle Bildausschnitte und Szenen auf Überlauf geprüft; die markierten Ausschnitte zusätzlich visuell durchgesehen. Diese Prüfung ersetzt keinen Verständlichkeitstest durch einen unabhängigen Erstnutzer.
- Der irreführende Hilfetext zur Aufnahme wurde präzisiert: Eine Aufnahme kann je nach Beitragspflicht Rechnung und vorgemerkte Mail erzeugen. Die Ablauflogik selbst wurde nicht geändert.

## Wartung

Die A4-Druckfassung wurde mit 124 Seiten erzeugt und auf leere Seiten geprüft. Stichproben der gerenderten Seiten einschließlich Bildausschnitt und nummerierter Legende wurden visuell kontrolliert; die Legendenabstände wurden dabei verbessert.

Bei einer geänderten Maske zuerst die zugehörige Textanleitung aktualisieren, dann betroffene Bilder mit ausschließlich erfundenen Daten neu aufnehmen und visuell prüfen. Erst danach SHA-256/Prüfvermerk in der maßgeblichen Bilderliste erneuern. Geänderte Videodrehbücher oder Bilder erfordern eine neue Mediengenerierung und erneute Wiedergabeprüfung. Bankzugang, FinTS, tatsächlicher DATEV-Import und direkte ELSTER-Übermittlung werden durch eine Anleitung oder ein Beispielvideo nicht als extern freigegeben dargestellt.

## Veröffentlichung am 7. September 2026

Implementierungsstand `8b95dd9` wurde live veröffentlicht. Alle 72 Laufzeitziele wurden nach der Übertragung bytegenau mit den lokalen Dateien verglichen. Vorhandene Dateien wurden unter `/tmp/kulturhus-verein/rollback-handbook-videos-20260907` gesichert; PHP-Dateien wurden vor dem Austausch auf Syntax geprüft.

Nach dem Upload bestanden 32 Prüfungen der öffentlichen Seiten, Gastzugriffe und privaten Pfade. Fünf zusätzliche Gastprüfungen für Hilfezentrum, Video, Untertitel, Poster und Hilfebild führten korrekt zur Anmeldung. Die live ausgelieferten CSS- und JavaScript-Dateien entsprechen exakt dem geprüften Stand. Wiedergabe, Suche, Kapitel, Rollenansicht und mobile Bedienung wurden authentifiziert lokal geprüft; ein zusätzlicher authentifizierter Live-Browsertest wurde nicht durchgeführt.
