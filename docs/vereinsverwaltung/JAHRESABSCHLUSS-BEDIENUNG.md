# Jahresabschluss: geführte Bedienung

Stand: 15.09.2026. Zielgruppe sind ehrenamtliche Kassenwarte ohne vorausgesetzte Buchhaltungs- oder Computerkenntnisse. Die Oberfläche ersetzt keine fachliche Prüfung. Ein echter Nutzertest mit der Zielgruppe steht noch aus.

## Ablauf

- Gemeinsamer Einstieg **Jahresabschluss & Steuern**, zunächst nur Kalenderjahr und nächster Schritt.
- Der Assistent für Körperschaftsteuer/Gemeinnützigkeit 2025 zeigt **Vereinsangaben → Buchführung & Nachweise → Ergebnis prüfen**. Weitere Formulare und Jahresunterlagen bleiben erreichbar.
- Bereits gespeicherte Jahresentscheidungen werden nicht durch heutige Vereinseinstellungen überschrieben. Abweichungen sind sichtbar; angebotene Einstellungen werden nur auf ausdrücklichen Klick übernommen.
- „Weiß ich nicht – prüfen lassen“ darf als Zwischenstand gespeichert werden. Unbekannt ist weder Nein noch Null; fehlende Angaben blockieren weiterhin die endgültige Erklärung.
- Offene Punkte werden gruppiert und zur passenden Frage verlinkt. Die zugrunde liegenden Validierungen bleiben vollständig erhalten.
- Änderungen werden nicht automatisch gespeichert. Abgeleitete Dokumentaktionen werden bei ungespeicherten Änderungen gesperrt; bei Versionskonflikten bleiben Eingaben und ursprüngliche Versionsnummern erhalten.
- Fachdetails, zusätzliche Konten, manuelle Soll-/Haben-Buchungen und Archivstände sind eingeklappt. Hauptfelder und Schaltflächen sind größer und für schmale Displays angepasst.

## Bilanz und Abschreibungen

- Bei Abschlussart **Bilanz** erscheint AfA automatisch; bei EÜR kann sie weiterhin ausdrücklich zugeschaltet werden. Finanzberechtigungen bleiben erforderlich.
- Anfangsbestände werden als geprüfte Einzelbeträge eingegeben und intern nach Soll/Haben übersetzt. Leere Felder werden nicht als Null behandelt; fehlendes Vereinskapital wird nicht als Ausgleich erfunden.
- AfA verwendet nur eindeutig verknüpfte Original-Rechnungskosten als überprüfbaren Vorschlag, nicht Inventar-Schätzwerte. Mehrere Stücke, Sammelpreise oder unklare Vorsteuerbehandlung erfordern Klärung.
- Nutzungsdauer-Vorschläge gibt es nach ausdrücklicher Auswahl für Büromöbel (13 Jahre), Beschallungsanlagen (9 Jahre) und Foto-/Film-/Video-/Audiogeräte (7 Jahre): [BMF-AfA-Tabelle AV](https://www.bundesfinanzministerium.de/Content/DE/Standardartikel/Themen/Steuern/Weitere_Steuerthemen/Betriebspruefung/AfA-Tabellen/Ergaenzende-AfA-Tabellen/AfA-Tabelle_AV.html), Positionen 6.15, 6.14.5, 6.14.4. Keine automatische Typbestimmung aus Namen oder OCR. Abweichende Branchen, gebrauchte Gegenstände und andere Abschreibungsarten bleiben gesondert zu prüfen.
- Die fachliche Steuerüberleitung und die Freigabe einer vollständigen E-Bilanz sind nicht Bestandteil dieses Bedienungspatches.

## Produktionsfreigabe

Veröffentlicht als `20260915-tax-usability-9eb73a3f`, auf Basis von `20260915-tax-attachments-185c628b`. 24 ausdrücklich ausgewählte Dateien; 1.830 weitere Release-Dateien unverändert. Öffentliche CSS-/JS-Dateien werden unter inhaltsabhängigen Dateinamen ausgeliefert. Der Wechsel des Runtime-Verweises erfolgte atomar, mit Rückfallmöglichkeit.

Keine Datenbankmigration und keine Änderung an Vereinseinstellungen, Bank, OCR, ERiC-Konfiguration oder Abgaberechten. MySQL-Schema bleibt 91. Bereits separat entwickelte, noch nicht veröffentlichte Bilanz-Erweiterungen/Migrationen 92 und 93 wurden nicht mit ausgerollt. Das Live-Flag für Bilanzbuchungen bleibt ausgeschaltet. Für Kulturhus bleibt AfA gemäß bisheriger EÜR-Einstellung ausgeschaltet.

Sicherungen dieses Arbeitsschritts:

- Lokaler Code-Arbeitsstand: `.local/before-tax-usability-20260915-180748.tgz` (keine Live-Daten).
- Serverseitige, verifiziert verschlüsselte Sicherung der betroffenen bisherigen Dateien und Runtime: `/tmp/kulturhus-backups/20260915-tax-usability-9eb73a3f/before-usability.zip`.
- Prüf- und Releasezustand nur lokal: `.local/tax-usability-release-20260915/state.json`. Keine Zugangsdaten oder Datensätze in diesem Dokument.

## Nachweise

- 12 Finanz-/Steuer-/Bilanz-Regressionssuiten: 564 erfolgreiche Prüfungen mit isolierten synthetischen MySQL-Daten.
- 43 zusätzliche Usability-Prüfungen, einschließlich Jahresauswahl, unbekannter Angaben, Versionskonflikten, AfA-Rechnungsbezug, Mandantentrennung und Bilanz-Anfangsbeständen. Die zugrunde liegende Steuerassistent-Suite läuft dabei zusätzlich mit.
- Jahresangaben, Ablauf, Fragenführung, Wiederverwendung von Einstellungen, Zugriffsrechte, Einstellungen und Finanznavigation erneut geprüft. Veraltete Text-Erwartungen in Tests an die neue Beschriftung angepasst.
- Syntaxprüfung der sieben geänderten JavaScript-/Browser-Testdateien sowie der produktiven PHP-Patchdateien.
- Browserprüfung mit synthetischen Formularen am Desktop und bei 390 Pixel Breite: Schrittwechsel, gezielte Fehlerlinks, Rücklagenfelder/Zeilen/Arithmetik, AfA-Vorschlagsauswahl, Anfangsbestände und Layout.
- Kandidat und aktives Release: sechs Ansichtsprüfungen bei schreibgeschützter Datenbank und aktivierten PHP-Warnungsprüfungen; AfA-Zugriffssperre für alle 25 Inventargegenstände geprüft, ohne AfA live einzuschalten. Aktive AfA-Bedienung nur mit Demodaten getestet.
- HTTP-Auslieferung aller fünf neuen statischen Assets gegen SHA-256 geprüft. Live-Steuerübersicht und Assistent im angemeldeten Browser ohne Speichern kontrolliert.
- Fachliche Daten, Einstellungen und ERiC-Konfiguration vor/nach Veröffentlichung gleich; keine Mail, Zahlung oder Steuerabgabe durch diese Prüfungen.

Nicht gleichbedeutend mit einer Zusicherung, dass alle denkbaren Steuerfälle automatisch abgewickelt werden können. Unklare Fälle benötigen weiterhin eine Entscheidung anhand der tatsächlichen Unterlagen.
