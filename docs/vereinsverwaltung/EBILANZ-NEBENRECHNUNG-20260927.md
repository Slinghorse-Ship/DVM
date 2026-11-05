# Gesamtbilanz mit steuerlicher Nebenrechnung

Entwicklungsstand 27.09.2026. Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.

## Verbundener Ablauf

Die vom Vorstand geprüfte steuerliche Nebenrechnung kann jetzt unverändert in
einen eigenen E-Bilanz-Testentwurf übernommen werden. Gesamtbilanz und Gesamt-GuV
zeigen weiterhin die Originalbuchwerte. Der SGEB-Bereich enthält das Ergebnis
sämtlicher steuerpflichtiger Betriebe samt XHTML-Fußnote mit Rechenweg,
Wertkorrekturen, Belegzeilen, bestätigten Rechtsgrundlagen und Anlagenplänen.
Temporäre interne Vorschüsse erzeugen weder weitere Einnahmen noch fingierte
Forderungen/Schulden einer externen Teilbilanz.

Grundlage ist die Nebenrechnungsvariante aus der
[amtlichen FAQ, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
Sie ist eine Orientierungshilfe, keine verbindliche Einzelfallentscheidung.
Die erzeugte Taxonomie-6.9-Ausgabe verwendet die amtlichen Positionen
`DeterminationOfTaxableIncomeSpec.forProfitOrganization.taxableIncome` und
`determinationOfTaxableIncome`, den steuerbegünstigten Körperschaftstyp sowie
`umbrellaBodyAdjustments`. Keine Gesamtvereins-Steuerbilanz wird vorgespiegelt.

Die gesonderte Übermittlungsart heißt intern
`tax_privileged_club_with_subsidiary`. Die vier bisherigen Profile behalten ihre
Sperren für nichtleere interne Verrechnungen. Keine Freigabe durch Abschalten
eines bisherigen Schutzes.

## Rechen- und Freigabenachweise

- PHP rekonstruiert vor jedem Zugriff den vollständigen Bereichs-, Verrechnungs-,
  Steuerwert- und Vorjahresnachweis einschließlich aktueller Rollenprüfung.
- Der Python-Generator berechnet unabhängig sämtliche ausgegebenen Beträge aus
  den Originalzeilen: Sphäre 4 vollständig, Wertunterschiede, außerbilanzielle
  Korrekturen, Verbrauch der belegten Korrekturbeträge und exakter Vorjahresvortrag.
  Die Anlagenpläne werden zusätzlich mit dem bestehenden unabhängigen
  Anlagen-/AfA-Rechenkern gegengeprüft.
- Der Entwurf bindet die genaue unveränderbare Steuerprüfungsrevision. Änderungen
  des Bereichsabgleichs, der Steuerwerte, des Vorjahres oder der Berechtigungen
  entwerten die aktuelle Entwurfs-/Versandfreigabe. Eine spätere Freigabe desselben
  Zahlenbetrags belebt einen alten Entwurf nicht wieder.
- Es gibt keinen zweiten Editor für dieselben Steuerkorrekturen oder den Vortrag
  im Erklärungsformular. Änderungen führen zurück zum steuerlichen Ergebnis.
- Die Ansicht enthält aufklappbar Gesamtbilanz, GuV, Kontennachweis, Anlagen und
  den Rechenweg der steuerlichen Nebenrechnung. Erst amtliche Prüfung, dann
  Vorstandsfreigabe des konkreten Entwurfs, danach ausdrücklich bestätigter Test.
- Historische Protokolle bleiben nach geänderter Quellenfreigabe lesbar.
  Doppelte identische Abgaben und blindes Wiederholen unklarer Abgaben bleiben
  gesperrt. Neue Rechenmodule sind im Laufzeitfingerprint enthalten.
- Testumgebung und EÜR-Sperre bleiben unverändert. Keine echten Daten aus den
  Vereinseinstellungen werden in synthetische Erklärungen kopiert.

## Prüfstand

- 18 unabhängige Python-Testmethoden mit sieben positiven Varianten und
  neu gehashten Manipulationen: Quellenbindung, Beträge, Vorjahre, Anlagen,
  Doppelkorrekturen, Rechtsgrundlage und falscher Körperschaftsumfang.
- Native ERiC-Prüfung aller sieben generierten Varianten: jeweils Code 0.
  Nachweis `audit/ebilanz-subsidiary-native-20260927.json`.
  Nur Prüfmodus (Flags 2), kein Zertifikat, kein Netzwerkversand.
- Erste erfolgreiche MySQL-Integration: 47 Workflowprüfungen, 51 vorhandene
  Rechenprüfungen und 58 vorhandene Persistenz-/Oberflächenprüfungen.
  Nachweis `audit/ebilanz-mysql-host-20260918-210c5af022138b9f.json`.
  Der historische Dateinamen-Präfix ist nicht das Testdatum.
- 32 bestehende kombinierte Python-Testmethoden und 27 bestehende kombinierte
  PHP-Prüfungen bestanden. 110 Fingerprint-Prüfungen sowie 54 Fingerprint-Dateien
  mit 14 Python-Abhängigkeiten geprüft.
- Ein vorheriger Diagnoselauf scheiterte am falschen temporären Verzeichnisnamen
  der Offline-Testvorrichtung. Dieser Testaufbau wurde berichtigt; kein
  Anwendungsschutz wurde dafür gelockert.

Abschließender Nachlauf nach Ergänzung der vollständigen Zahlenansicht:
`audit/ebilanz-mysql-host-20260918-9d2001165aa4730e.json` – 48 neue Workflow-,
51 Rechen-, 58 Persistenz-/Oberflächen- und 60 bisherige Übermittlungsprüfungen
bestanden. Auch nach Auslagerung des gemeinsamen Prüfen/Freigeben/Senden-Bereichs
funktionieren die vorhandenen Masken und Sperren. Alle 1.021 PHP-Dateien ohne
Syntaxfehler. Geschützte Produktivdateien unverändert, privater Testbereich entfernt.
Die Testdatenbank bleibt dauerhaft synthetisch. Keine Sicherung/Rückspielung je Lauf;
keine Produktivdatenbank, echten Mails, Bankaufträge oder Steuerabgaben.

## Noch keine allgemeine Veröffentlichung

Diese Integration deckt nicht sämtliche Anlagen-/Steuersonderfälle, alle SKR42-
Konten, abweichende Wirtschaftsjahre oder Korrekturabschlüsse ab. Abschließende
Gesamtabnahme und Veröffentlichung fehlen. Die nachgelagerte Gesamtprüfung des
Vereinsmanagers wird dadurch nicht vorgezogen. Keine Behauptung eines echten
Nutzertests oder einer fachlichen Freigabe aller steuerlichen Einzelfälle.
