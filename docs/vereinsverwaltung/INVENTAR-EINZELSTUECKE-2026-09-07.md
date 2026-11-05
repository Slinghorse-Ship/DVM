# Einzelstücke und Sicher helfen – 7. September 2026

## Verhalten

Vier Bühnen können gemeinsam vorbereitet und als vier tatsächlich getrennte Gegenstände angelegt werden. Jede erhält ihre eigene Inventarnummer und bei Bedarf Seriennummer, Preis, Rechnung, Anschaffungsdatum und Lagerort. Drei Preise zu 100 Euro und ein Preis zu 200 Euro ergeben 500 Euro; es wird kein Durchschnitt gebildet. Eine bereits erfasste Rechnung kann mehreren Stücken zugeordnet werden. Originaldateien bleiben einmal bei der Rechnung in Finanzen und sind von den Gegenständen aus erreichbar.

Die Kontrollansicht zeigt alle Zuordnungen vor der Anlage. Bei einem Fehler wird kein Teil der Gruppe gespeichert. Spätere Änderungen erfolgen am jeweiligen Gegenstand. Vorhandene Mengenbestände bleiben unverändert; der bisherige Mengenweg bleibt auswählbar. Für Handkassen bleibt der separate, geprüfte Einstieg erhalten.

Unter „Sicher helfen“ sind das vorausfüllbare Originalformular für den Vorstand, die leere Landkreisvorlage sowie Online-Belehrung und Präsenzbuchung eindeutig benannt. Die Hinweise unterscheiden die Ausnahme für einmalige ehrenamtliche Hilfe bei den genannten Festen bis maximal drei Tagen im Jahr von einer erforderlichen Belehrung. Die Bescheinigung für Ehrenamtliche gilt für diese Tätigkeit; die Vereinsbestätigung ist kein Ersatz für die Belehrung. Grundlage: [Landkreis Wesermarsch](https://wesermarsch.de/services/gesundheit-pflege/infektionsschutz/), geprüft am 7. September 2026.

Kapitel 12 und 15 wurden erweitert. Das erzeugte Handbuch hat nun 20 Kapitel, 182 Aufgaben und 31 markierte echte Beispielausschnitte. Zwei neue Bilder erklären die Einzelstückzeile und den Kontrollbildschirm. Die neun bisherigen Videos bleiben nutzbar; darunter befindet sich kein Inventarvideo mit überholtem Anlageablauf.

## Prüfung

- 59 neue Fachprüfungen: Migration aus Schema 40, Erhalt aller Bestände und Zuordnungen, Rechnungsaufteilung, Wertberechnung ohne Mittelung, Leerwerte, Nummernkonflikte, Transaktionsrücknahme, Wiederholungsschutz, Rechte, Ausgabe, Rücknahme, Umlagerung und Aussonderung.
- Bestehende Fachtests: Inventar 83, Finanzen 60 und Dokumente 74 erfolgreich.
- Lokaler Browser: echte Anlage von vier fiktiven Gegenständen, Preisvorschau 500 Euro, getrennte Seriennummern, Rechnungsliste nachladen, unzulässige Preise abweisen, Kontrollansicht/zurück, Bearbeitung, Gruppenliste und 390-Pixel-Ansicht ohne Überlauf.
- Zusatzprüfung: Mengenbestand mit Menge 3; Rechnungsbezug bei Moduswechseln; fehlerhafter Zweierlauf ohne Teilanlage und mit erhaltenen Eingaben; spätere Seriennummerkorrektur; Inventar-Schreibrecht ohne Finanzrecht; manipulierter Preis-POST abgewiesen; Preisnachweis vor Bestätigung; gültiger, leerer und unbekannter Handkassenkontext.
- Handbuch im Browser: 20 Kapitel, 182 Aufgaben, 31 geschützte Bildantworten, eindeutige Sprungziele, Suche, neun abspielbare Videos mit Kapitelsprüngen/Untertiteln, Dateipfadabwehr, Desktop/Mobil und Mitgliedszugang.
- PHP-Syntax aller geänderten PHP-Dateien sowie JavaScript-Syntax geprüft. Docker war nicht verfügbar; lokale Tests liefen mit PHP 8.5.10 und einer separaten synthetischen Datenbank.

Es wurden keine echten Gegenstände, Rechnungen, Bankaufträge, Anmeldungen oder Mailvorgänge zu Testzwecken erzeugt. Die Live-Freigabe erfolgt nach privater Sicherung, Migrationsprobe am Live-Abzug, Prüfung aller bisherigen Datensätze und Rücklesen der ausgelieferten Dateien.

## Veröffentlichung bestätigt

Commit `20ae849` wurde auf dem vorhandenen Live-Release veröffentlicht. 21 Dateiziele wurden vollständig mit den Git-Inhalten verglichen. Die additive Migration auf Schema 41 lief zunächst zweimal an einer privaten Kopie der Live-Datenbank und anschließend unter Schreibsperre auf dem Original. Die bisherigen Spaltenwerte aller fachlichen Tabellen blieben identisch; Integrität und Fremdschlüssel waren fehlerfrei. Die private Sicherung liegt unter `/tmp/kulturhus-verein/rollback-inventory-individuals-20260907`.

32 öffentliche/geschützte HTTP-Prüfungen sowie sieben Prüfungen der Handbuchauslieferung bestanden. Die angemeldete Live-Oberfläche zeigte die Einzelstückanlage sowie die neuen Hygienehinweise und Links. Dabei wurden keine produktiven Testgegenstände angelegt. Weitere Benutzerkorrekturen zur Bedienung der Ehrenamtsbestätigung werden in einem nachfolgenden Änderungspaket umgesetzt.
