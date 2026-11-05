# Interne Mittelübertragung zwischen Vereinsbereichen

Entwicklungsstand 20.09.2026. **Kein Produktivrelease und keine vollständige
allgemeine Vereinsbilanz-Abnahme. Kulturhus bleibt EÜR.**

## Umgesetzt

Ein bereits vollständig zugeordneter Originalbeleg kann einen Bereich belasten,
während ein anderer Bereich die Zahlung trägt. Für eine **ausdrücklich bestätigte
endgültige Mittelübertragung** lassen sich die verbleibenden Bereichsdifferenzen
jetzt beleggebunden auf einem vorhandenen Kapitalkonto ausweisen.

- Keine Änderung an Bankumsätzen, Originalbuchungen, Gewinn oder Aufwand/Ertrag.
- Keine Umdeutung in eine Forderung oder Schuld gegenüber Dritten.
- Kein automatischer Ausgleich: vollständige Originalzuordnung, Begründung,
  ausdrückliche Bestätigung und anschließende Vorstandsfreigabe sind erforderlich.
- Exakte positive/negative ganze Centbeträge; jeder Betrag muss der zugehörigen
  Originaldifferenz entsprechen. Keine pauschale Jahresdifferenz und kein
  erfundener Eröffnungsbestand. Gegenläufige Beträge heben sich exakt auf.
- Historienformat 3 erhält Quelle, Konto, betroffene Bereiche und Begründung.
  Formate 1 und 2 bleiben bei unverändertem Inhalt identisch rekonstruierbar.
- Rollen-/Vereinsprüfung, EÜR-Sperre, Versionsprüfung, Transaktion und Audit.
  Änderungen entziehen Freigaben; Aufhebung erfordert eine Begründung und bewahrt
  alle alten Versionen. Geänderte Zuordnungen können keine veraltete Übertragung
  unbemerkt übernehmen.
- Konten- und Bereichsnachweis einschließlich Export
  `bereiche/mitteluebertragungen.csv`; keine Übertragung dieser internen
  Begründungen an ELSTER.
- Innerhalb sämtlicher steuerpflichtiger Betriebe werden interne Bewegungen
  eliminiert. Bereichsübergreifende Kapitalbewegungen bleiben im zutreffenden
  Teilabschluss erhalten. Die Abstimmung zum Gesamtverein weist die Eliminierung
  ausdrücklich aus, statt negative Soll-/Haben-Umsätze als übrige Bereiche
  darzustellen. PHP und Python rechnen Quelle, Aufteilung und Summen unabhängig.
- Folgende Jahre übernehmen das je Bereich vorhandene Kapital ohne erneuten
  Ertrag. Gegenbuchungen bleiben nachvollziehbar.
- Oberfläche: „Ein Bereich bezahlt für einen anderen“, Beträge je gebendem und
  empfangendem Bereich, begründete Aufhebung. Ein einziges vorhandenes Kapitalkonto
  wird ohne zusätzliche Auswahl verwendet; mehrere Konten bleiben eine bewusste
  Auswahl. Ungültige Eingaben bleiben escaped samt ursprünglicher Version erhalten.

## Abnahme

- 81 reine PHP-Prüfungen zu Aufteilung, Eliminierung, Gegenbuchung, Folgejahr,
  Quellschutz und ungültigen Eingaben.
- Vier unabhängige Python-Testmethoden, darunter zwölf Kombinationen aus drei
  Abschlussprofilen und vier Übertragungsfällen; manipulierte Beträge, Typen,
  Konten, Quellen und Schemawechsel werden abgewiesen.
- Amtliche ERiC-Validierung: jeweils 17 Fälle in Einzel-SGEB und kombiniertem
  Gesamtabschluss mit Code 0. Berichte
  `audit/ebilanz-internal-transfers-sgeb-native-20260920.json` und
  `audit/ebilanz-internal-transfers-combined-native-20260920.json`.
  Der kombinierte Lauf wurde nach Ergänzung der Eliminierungsspalte wiederholt.
  **Nur Validierung, kein Versand.** Das Gesamtvereinsprofil ist zusätzlich im
  unabhängigen Generator geprüft, nicht in einem gesonderten nativen Lauf.
- Abschließender isolierter MySQL-Test:
  `audit/ebilanz-mysql-host-20260918-92ed55e59e4ef049.json`.
  Alle sechs Programme erfolgreich, ohne PHP-Diagnosen. Der neue Workflow umfasst
  202 Prüfungen (171 bestehende plus 31 zusätzliche); die 81 reinen
  Übertragungsprüfungen laufen zusätzlich. Weitere Regressionen: Kapitalvortrag,
  Bereichsaufteilung, kombinierte Anlagen und gemischte Anlagenabgänge.
- Die freigegebene zweite Datenbank wurde serverseitig verschlüsselt gesichert,
  die Sicherung verifiziert und der ursprüngliche Stand identisch wiederhergestellt.
  Keine Verbindung zur produktiven Datenbank; geschützte Live-Dateien unverändert,
  privater Prüfbereich entfernt. Nur synthetische Formularansichten auf dem PC.
  Der erste ebenfalls erfolgreiche Lauf
  `audit/ebilanz-mysql-host-20260918-f3efaa6f0d771050.json` lag vor der
  Eliminierungsspalte und den zusätzlichen Controller-/Formularprüfungen.
- 879 PHP-Dateien in `src`, `views` und `tests` ohne Syntaxfehler; anschließend
  geänderte Dateien nochmals einzeln geprüft. Neun Python-Testgruppen bestanden.
  43 Laufzeitdateien und zwölf Python-Abhängigkeiten im Freigabe-Fingerprint.
- Tatsächliches synthetisches PHP/MySQL-Rendering mit den Anwendungs-Styles im
  Browser geprüft: 1280-Pixel-Desktop und 390-Pixel-Handy, kein horizontaler
  Seitenüberlauf und keine verschachtelten Formulare. Eingabefeld etwa 51 Pixel,
  Schaltfläche etwa 50 Pixel hoch; Bestätigung mit vollständig umbrechendem Text.
  Keine Browserwarnungen/-fehler, kein Browser-POST. Eigene Prüftabs und eigener
  Vorschauprozess geschlossen, Größenoverride zurückgesetzt.
  Dies ist eine technische Sichtprüfung der Teilmaske, keine Gesamtprüfung des
  Managers und kein beobachteter Test mit einer unerfahrenen Person.

Fachlicher Rahmen zur getrennten Darstellung von Gesamtverein und steuerlichem
Teilabschluss: [amtliche E-Bilanz-FAQ, Januar 2026, Abschnitt m)](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
Eine technische Annahme bestätigt weder die steuerliche Zulässigkeit einer
Mittelverwendung noch eine steuerliche Anerkennung.

## Nicht als erledigt verbuchen

Diese Funktion ist **kein allgemeiner Darlehens-/Verrechnungsmechanismus**.
Zeitweilige, rückzahlbare Verrechnungen, gemeinsam genutzte Anlagen mit
bereichsbezogener Abschreibung und Steuerwerten, die restliche Konten- und
Sonderfallabdeckung, abweichende Wirtschaftsjahre und Korrekturabschlüsse bleiben
offen. Anschließend ist die Gesamtabnahme des endgültigen Releasepakets nötig.
Die Produktionsfreigabe und die automatische Nachprüfung werden dadurch nicht
ausgelöst. Kein Produktionscode, EÜR-Schalter oder echte Vereinsdaten geändert.
