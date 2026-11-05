# Steuerliche Nebenrechnung aus dem Bereichsabgleich

Stand 27.09.2026. Entwicklung, **kein allgemeiner Bilanzrelease**. Kulturhus bleibt EÜR.

Nachfolgende Integration: [amtliche SGEB-Ausgabe und Entwurfsfreigabe](EBILANZ-NEBENRECHNUNG-20260927.md).
Die unten dokumentierte ursprüngliche Lücke der amtlichen Darstellung ist für
diesen Nebenrechnungsumfang inzwischen mit eigenem Exportprofil geschlossen;
die allgemeinen Release-Voraussetzungen bleiben offen.

## Umgesetzt

- Alle steuerpflichtigen Betriebe aus dem aktuell geprüften Bereichs-/Verrechnungsnachweis;
  keine Auswahl einzelner Betriebe, keine erfundenen Forderungen oder Schulden.
- Ausgeglichene steuerliche Wertüberleitung mit den realen Gegenkonten des
  Gesamtjournals als Rechenbasis. Diese wird **nicht** als Bilanz eines vollständig
  steuerpflichtigen Vereins deklariert. Nur GuV-Korrekturen der Sphäre 4 sind erlaubt.
- Bestätigte abweichende lineare Nutzungsdauern aus beleggebundenen Anlagenplänen.
  Pauschale manuelle Anlagen-/AfA-Buchungen dürfen diese Nachweise nicht umgehen.
  Steuerfreie Gegenstände werden nicht umgewidmet.
- Außerbilanzielle Hinzurechnungen nicht abziehbarer Ausgaben und Kürzungen
  steuerfreier Einnahmen sind von den Steuerbuchwerten getrennt. Original-/Steuerwertzeile,
  Begründung, geprüfte Rechtsgrundlage und ausdrückliche Bestätigung sind erforderlich.
  Keine doppelte Verwendung über den Quellbetrag hinaus; Gutschriften behalten ihr Vorzeichen.
- Unveränderter Vortrag steuerlicher Vorjahresunterschiede ohne erneute Gewinnerfassung.
  Außerbilanzielle Korrekturen erzeugen keine Bilanzsalden und werden nicht fortgeschrieben.
- MySQL-Migration 100: unveränderbare Entwurfs-/Prüfhistorie, UPDATE-/DELETE-Trigger,
  Vereinsgrenzen, Finanzrechte, Vorstandsprüfung, Quellen- und Vorjahresbindung.
  Geänderte Quellen, entzogenes Vorstandsrecht und revidierte Vorjahre entwerten Freigaben.
- Oberfläche: Bereichsabgleich → **Steuerwerte prüfen**. Ergebnisübersicht, Berechnung,
  Korrekturen, Aufhebung, abweichende Nutzungsdauer und Vorstandsprüfung.
  Buchhaltungsdetails eingeklappt; Finanzleser haben keine Schreibformulare.
  Fehlerhafte Eingaben behalten ihre ursprünglichen Versionsbindungen.
- Jahresabschluss-Download mit Nachweis, Zusammenfassung und Prüfverlauf unter
  `steuerliche-nebenrechnung/`, gebunden an die Dateihashes. Entwürfe sind gekennzeichnet.
- Rechenkern und Prüfservice sind im ELSTER-Laufzeitfingerprint enthalten.

## Prüfstand und Grenzen

51 synthetische PHP-Rechenprüfungen und sechs unabhängige Python-Tests:
Originalkonten/-zeilen, Teilrückzahlungen, Bewertungsunterschiede, drei Jahre,
Gutschriften, Anlagenpläne, doppelte Korrekturen und neu gehashte falsche Ergebnisse.

MySQL/Webserver: Rollen, EÜR-Sperre, unveränderbare Historie, zweijähriger Vortrag,
Quellenänderung, Vorstandsrechte, Transaktionsrücknahme, Jahresabschluss-Dateien
und echte Formulare/Controller. Abschließender Nachweis dieses Teilbausteins:
`audit/ebilanz-mysql-host-20260918-555b2d89ffaee8c8.json` – 51 Rechenprüfungen und
58 Persistenz-/Oberflächenprüfungen bestanden (0,53 bzw. 17,20 Sekunden).
Quellpaket: `bd3a021d672e43b18b3f967e68759d2400073f6c7fb9ac6e0cc2c8b70117f0d1`.
Keine neue Sicherung und keine Rückspielung in diesem Lauf. Keine echte Mail, Bankanweisung oder
Steuerübermittlung; produktive Dateien unverändert. Der historische Dateinamen-Präfix
ist nicht das Testdatum. Kein echter Nutzertest und keine Manager-Gesamtabnahme.

Zusätzlich: alle 1.016 PHP-Dateien ohne Syntaxfehler; 38 bestehende Steuerwert-/Vortragsprüfungen,
2.705 bestehende Ergebnisabgleich-Prüfungen und 106 Laufzeitfingerprint-Prüfungen bestanden.
Quellmanifest und statische Abhängigkeiten: 2.294 Dateien geprüft.

Nicht enthalten: sämtliche steuerlichen Sonderfälle, festzusetzende Steuer,
Verlustabzüge oder Freibeträge. Die amtlichen SGEB-Positionen samt Erläuterung,
unabhängiger Generatorprüfung und nativer ERiC-Abnahme sind noch nicht angeschlossen.
`tax_export_ready` bleibt false; vorhandene Übermittlungssperren bleiben erhalten.
Weitere Releaseblocker: vollständige Konten-/Anlagenfälle, abweichende Wirtschaftsjahre,
Korrekturabschlüsse und Gesamtabnahme.

Fachliche Grundlage für die Nebenrechnungsvariante:
[amtliche E-Bilanz-FAQ Januar 2026, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf).
Keine verbindliche Einzelfallentscheidung; Rechtsgrund und steuerliche Behandlung
bleiben ausdrücklich zu prüfen.

## Testbetrieb ab 27.09.2026

Der Nutzer stellt `k15753_migrate` dauerhaft für synthetische Tests bereit.
**Keine wiederholte Sicherung und Rückspielung vor/nach jedem Entwicklungslauf.**
Die zuvor verifiziert gesicherte Originalfassung bleibt serverseitig erhalten.
Der private Host-Runner nutzt die dedizierte Testdatenbank direkt und lässt
synthetische Daten dort liegen. Datenbank-/Benutzerprüfung, exklusiver Test-Lock,
gesperrte Mail-/ELSTER-Ausgänge und Produktionstrennung bleiben erhalten.
Gezielte Backup-Funktionstests sind ein eigener Prüfgegenstand, keine Voraussetzung
für jeden Funktionstest. Schneller Lauf: `.local/ebalance-host-suite.py --tax-reconciliation`.
