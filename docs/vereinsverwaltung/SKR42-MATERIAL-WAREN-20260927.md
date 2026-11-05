# SKR42 – Material, Waren und verbindliche Kontenbedeutung

Stand 27.09.2026. Fortsetzung der Kontenabdeckung nach
[Personal- und Betriebsaufwand](SKR42-BETRIEBSAUFWAND-20260927.md).
**Entwicklung, kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

## Implementiert

Registry-Revision 12 ergänzt 64 eindeutige Hauptkonten aus den geprüften
DATEV-Jahreskatalogen 2025 und 2026: Material-/Wareneinkauf zum ermäßigten und
allgemeinen Steuersatz, Einkauf ohne Vorsteuerabzug sowie innergemeinschaftlicher
Erwerb. Drei Sammelkonten (51000, 51900, 52000) erhalten jeweils fünf begrenzte
Detailentscheidungen. Gemischte Fälle müssen getrennt zugeordnet werden; ein
Sammelkonto allein beweist keine Steuerbehandlung.

Die Zuordnung ändert nur die Ausweisposition bereits gebuchter Beträge. Sie
berechnet keine Erwerbsteuer und gewährt keinen Vorsteuerabzug. Die allgemeinen
Steuer- und Vereinsbereichsprüfungen bleiben unverändert. Noch ungeprüfte
Sonderfälle, etwa Einkauf nach Durchschnittssätzen, werden nicht geraten.

Ein zusätzlich gefundener Fehler ist behoben: Ein bereits eindeutig bestimmtes
SKR42-Konto durfte bisher innerhalb seiner Kontengruppe auf fachlich unpassende
Positionen umgestellt werden. Anzeige, serverseitige Speicherung und Python-Export
lassen jetzt nur noch das genaue Ziel beziehungsweise ausdrücklich hinterlegte
Alternativen zu. Dies gilt auch für ältere Kontenregeln, nicht nur für neue Kosten.

Für eindeutig zugeordnete Konten entfällt dadurch die unnötige Auswahlliste.
Eine früher gespeicherte, nicht mehr passende Zuordnung wird sichtbar beanstandet
und muss neu bestätigt werden; sie wird weder still weiterverwendet noch heimlich
überschrieben. Buchungen bleiben unverändert. Die neue Registry-Revision macht
vorherige Export-/Freigabenachweise über die vorhandene Laufzeitbindung ungültig.

## Stand der Abdeckung

Je Jahr 262 bedingungslose und 28 bedingte Regeln auf Kontoebene, insgesamt 290
Hauptkonten. Das ist noch keine vollständige Abdeckung der 1.689 buchbaren Konten
für 2025 beziehungsweise 1.679 für 2026. Keine neue Bereichs-/Anlagen-/Zeitraum-
Freigabe und kein amtlicher Export für abweichende Zeiträume durch diesen Schritt.

## Abnahme dieses Entwicklungsschritts

- 972 PHP-Assertions: beide Jahre, automatische Ziele, alle Detailoptionen,
  Rückzahlungen/Stornos, unveränderte Beträge, begrenzte Auswahl, klare Rückfrage,
  HTML-Maskierung und sichtbare Beanstandung alter widersprüchlicher Zuordnungen.
- Acht isolierte MySQL-Testgruppen erfolgreich. Die neue Workflowgruppe enthält
  22 zusätzliche Prüfungen plus 61 wiederverwendete Basisprüfungen: falsche
  automatische und manuelle Ziele werden abgewiesen, echte XML-Erzeugung aus
  geschlossenem synthetischem Journal, Rollenprüfung, Vorstandfreigabe und
  Freigabeentzug nach Änderung. Die Zusatzgruppe legt keinen Versandversuch an;
  die Basisgruppe verwendet nur einen lokalen Transportsimulator.
  Nachweis: `audit/ebilanz-mysql-host-20260918-b7b620cac7bfff4d.json`;
  Quellpaket `e7e0e882ec953f2d03529f3b002909c8243924e1b05e978c40d394ccdd927168`.
  Keine produktive Datenbankverbindung; geschützte Produktivdateien unverändert;
  private Testdateien entfernt. Keine wiederholte Sicherung/Rücksicherung.
- Vier geänderte PHP-Dateien syntaxgeprüft, Patch auf Formatfehler geprüft.
- 294 Python-Testmethoden in 20 Gruppen bestanden. Darunter die vier neuen
  parametrisierten Betriebsaufwands-/Materialprüfungen sowie Generator,
  Kapital, Gegenparteien, Umsatzsteuer, Gesamtverein/SGEB, Nebenrechnung,
  Verkauf, Abgang, gemeinsame Anlagen, gemeinsame Abgänge, Steuerwerte,
  degressive Abschreibung, Teilabgang, Softwareende, vollständiger Verein,
  steuerpflichtige Vereinsbetriebe, Kapitalvortrag, interne Verrechnung und
  abgesicherte Erzeugungs-/Versandschnittstelle. Keine Übermittlung.
- 329 native ERiC-Validierungen nach der abschließenden Änderung bestanden:
  alle 129 neuen Betriebs-/Materialaufwandskonten aus Revision 11 und 12 sowie
  alle 33 Detailentscheidungen jeweils für 2025 und 2026, vier gemischte
  Soll-/Haben-Szenarien und eine Basisrechnung. Ein zusätzlich absichtlich
  veränderter Bilanzsaldo wurde wie erwartet abgewiesen. Ausschließlich Flag 2,
  keine Zertifikate, keine echte oder Test-Übermittlung.
  Nachweis: `audit/ebilanz-operating-material-final-native-20260927.json`.

Oberfläche hier nur anhand erzeugter HTML-Struktur geprüft, kein beobachteter
Nutzertest. Die Manager-Gesamtprüfung bleibt an den vollständigen Release gebunden.

## Quellen

- [DATEV SKR42 Bilanz](https://www.datev.de/web/de/shop/produkt-details/12901),
  getrennte unverändert gepinnte Originalkataloge für 2025 und 2026.
- [Amtliche E-Bilanz-Taxonomien](https://www.esteuer.de/), vorhandene geprüfte
  Taxonomie 6.9 einschließlich deutscher Bezeichnungen und Rechenbeziehungen.
- Separate Erwartungswerte in `tests/fixtures/ebilanz-operating-accounts.json`;
  keine freie Ableitung aus Kontonummern oder unscharfen Kontenbezeichnungen.
