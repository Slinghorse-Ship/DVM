# Teilabgang – isolierter Rechenkern, 20. September 2026

**Entwicklung, nicht live und noch nicht buchbar.** Dieser Schritt ergänzt die
rechnerische Grundlage für einen einzelnen belegten Teilabgang ohne Erlös.
Er schließt nicht den gesamten Teilabgangs-Workflow oder die Bilanzierung ab.
Produktiv bleibt Kulturhus unverändert bei EÜR; kein Serverkontakt, keine Datenbank,
keine Mail, kein Bankauftrag und keine ELSTER-Übermittlung in diesem Schritt.

## Berechenbarer Umfang

`AssetPartialDisposal::preview()` verarbeitet einen ursprünglichen linearen Plan
für Ausstattung (06300/06500) und genau **eine Teil-Ausmusterung**:

- Tatsächliche Teil-Anschaffungskosten und zugehöriger Teil-Restwert, getrennt vom
  Restbestand. Keine Gleichverteilung nach Stückzahl oder frei gewählten Prozenten.
- Eigener Belegnachweis für die Kostenaufteilung und eigener Nachweis des Abgangs,
  jeweils mit Dokument-ID, Dateirevision und SHA-256.
- Bestätigung gleicher bisheriger Nutzung/Nutzungsdauer innerhalb des ursprünglichen
  Plans und nachvollziehbare Begründung. Ohne diese Voraussetzungen kein Ergebnis.
- Der ursprüngliche Plan ist per Hash gebunden und wird nicht geändert.
- Optional separat bestätigte abweichende lineare Steuer-Nutzungsdauer für den
  Geschäftsbetrieb. Daraus werden Anfangs-, AfA-, Abgangs-, Ergebnis- und
  Schlusswertunterschied berechnet, **noch keine Überleitungsbuchungen**.

Das Modul prüft nur Struktur und Berechnung. Es kann nicht bestätigen, dass die
referenzierten Dokumente existieren, zum Verein/Gegenstand gehören, ihre Inhalte die
Teilkosten tatsächlich belegen oder der Benutzer zur Freigabe berechtigt ist.
Diese Prüfungen gehören zwingend in den noch fehlenden transaktionalen Quellendienst.

## Rechenkonvention und Cent-Erhalt

Der Originalplan bestimmt weiterhin die kumulierte lineare Abschreibung. Davon
wird der Anteil der nachgewiesenen abschreibbaren Teilkosten (Teilkosten minus
Teilrestwert) an der gesamten abschreibbaren Grundlage zugeordnet. Die Differenz
bleibt beim fortgeführten Teil. So ergeben beide Anteile stets exakt den bisherigen
Gesamtbetrag; getrennt neu berechnete Einzelpläne könnten alte Centbeträge verändern.

Bis einschließlich Abgangsmonat bleibt die Abschreibung des Gesamtgegenstands
berücksichtigt. Danach läuft nur der verbleibende Anteil weiter. Dies ist die
hier gewählte monatliche Rechenkonvention, keine Aussage über die Zulässigkeit jedes
denkbaren Teilabgangs. Vollständig abgeschlossene Vorjahre werden nicht neu bewertet.
Abgangskosten und darauf entfallene kumulierte Abschreibung werden nur im Abgangsjahr
ausgebucht; der verbliebene Restwert bleibt auch am Ende der Nutzungsdauer erhalten.

Die Verhältnisrechnung verwendet ausschließlich ganze Centbeträge und eine
überlaufsichere Ganzzahl-Division. Produkte zweier Beträge bis 100 Milliarden Cent
würden sonst die 64-Bit-Grenze überschreiten. Es werden keine Floats verwendet.

Die allgemeinen Ausgangspunkte sind Anschaffungskosten abzüglich Abschreibung
und Verteilung über die Nutzungsdauer:
[§ 253 HGB](https://www.gesetze-im-internet.de/hgb/__253.html),
[§ 7 EStG](https://www.gesetze-im-internet.de/estg/__7.html).
Diese Vorschriften bestätigen **nicht automatisch** die hier gewählte Aufteilung
eines konkreten Gegenstands. Wirtschaftsgutabgrenzung, Kostenbelege und steuerliche
Behandlung müssen für den zulässigen Anwendungsbereich vor Freigabe fachlich geprüft
werden. Teilabgang ist insbesondere nicht mit einer Teilwertabschreibung gleichzusetzen.

## Nachweis und Schutz gegen verfrühte Nutzung

- 6.941 PHP-Assertions bestanden, einschließlich zeitlicher Fortschreibung über
  die Nutzungsdauer, Restwerten, Ein-Cent-Teilen und der Betragsobergrenze. Das sind
  wiederholte mathematische Prüfungen, nicht 6.941 unterschiedliche Geschäftsfälle.
- Sechs neue Python-Testmethoden bestanden. Darin 600 deterministische Zufallsfälle
  gegen eine **separate Monatsrechnung mit rationalen Zahlen**, zusätzlich alle
  zwölf Abgangsmonate, Jahresgrenzen, Vorjahreskonstanz und ungültige Eingaben.
- Bestehende PHP-Suiten: Gegenkontennachweise (282 kombinierte Prüfungen), getrennte
  Steuer-Ausmusterungswerte (129 kombiniert), Bilanzrechnung (91) und Anlagenverzeichnis
  (23) erfolgreich. Gemeinsame Basisprüfungen sind nicht additiv als neue Fälle zu zählen.
- Python insgesamt 135 Testmethoden in acht Suiten erfolgreich: neuer Rechenkern,
  Generator, Abgänge, Steueranlagen, Verkauf, degressive AfA, Umsatzsteuer und Bridge.
- Syntax aller vier neuen PHP-Dateien geprüft.
- Der vorhandene Abgangsvalidator lehnt die neue Belegart weiterhin ab. Ein eigener
  negativer Generator-Test verhindert, dass eine Teilabgangs-Vorschau als vollständige
  Ausmusterung exportiert wird, auch mit neu berechneter Prüfsumme.
- Kein Eintrag in Bootstrap, Routen, Migrationsschema oder produktive Oberfläche.
  Rückgabe enthält ausdrücklich `postable=false` und `exportable=false`.

Maschinenlesbarer lokaler Prüfstand: `audit/asset-partial-disposal-20260920.json`.
Kein MySQL-, HTTP-, UI- oder amtlicher ERiC-Test für diesen neuen Rechenkern behauptet.

## Nächste nötige Integration

1. Kostenaufteilung anhand der tatsächlichen Anschaffungsquellen prüfen; Club-/Rollen-
   und Dokumentrechte, Dateirevisionen und unveränderte Originalpläne absichern.
2. Teilabgang als unveränderliches Ereignis mit Jahresstand/Quellenhistorie speichern.
   Bereits geschlossene Jahre schützen, doppelte oder überhöhte Teilabgänge abweisen.
3. Restbestand in Folgejahren korrekt weiterführen, inklusive späterem vollständigem
   Abgang. Der aktuelle Jahresdienst erlaubt noch keinen zweiten Abgang.
4. Quellenvorschau, Journal, konkrete SKR42-Gegenkonten, Abschluss-Snapshot,
   Steuerwertüberleitung und unabhängigen Exportvalidator gemeinsam erweitern.
5. Einfache beleggestützte Oberfläche, Berechtigungs-/MySQL-/HTTP-Tests und native
   ERiC-Prüfung abnehmen. Erst danach Freischaltung dieses abgegrenzten Falls erwägen.

Weiter ausgeschlossen: Teilverkauf, mehrere Teilabgänge, Schenkung/Entnahme,
Versicherungsentschädigung, neue oder geänderte Restnutzungsdauer, unterschiedliche
Anschaffungszeitpunkte, GWG/Sammelposten, degressive oder Sonderabschreibung,
Umbuchung und abweichende Anschaffungs-/Herstellungskosten in der Steuerbilanz.
