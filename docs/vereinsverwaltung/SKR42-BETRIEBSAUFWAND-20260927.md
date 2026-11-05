# SKR42 – Personal- und Betriebsaufwand, 27.09.2026

Entwicklungsschritt innerhalb der noch offenen vollständigen Kontenabdeckung.
**Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

Die Zahlen dieses Dokuments beschreiben Revision 11. Nachfolgender Stand:
[Material, Waren und verbindliche Kontenbedeutung, Revision 12](SKR42-MATERIAL-WAREN-20260927.md).

## Implementiert

Registry-Revision 11 ergänzt für die jeweils unverändert geprüften DATEV-Jahre
2025 und 2026:

- 65 eindeutige Kontenzuordnungen: Lohn-/Gehaltsarten, Sozialabgaben,
  Altersversorgung für Gesellschafter-Geschäftsführer, Unterstützung,
  Mietleasing, Miete an Gesellschafter, weitere laufende Betriebskosten,
  als Aufwand gebuchte nicht abziehbare Vorsteuer, Wertberichtigungen,
  übliche Forderungsverluste und sonstige Betriebssteuern.
- Fünf Sammelkonten mit insgesamt 18 zulässigen Detailentscheidungen:
  60000, 61400, 61470, 61500 und 68370. Eine Kontonummer allein beweist
  weder die Vergütungsart noch die Steuerpflicht eines Lizenzempfängers.
- Dieselben erlaubten Ziele in Anzeige, Speicherung und Python-Export.
  Die konkrete Rückfrage steht unmittelbar bei der Auswahl; verständliche
  Bezeichnungen statt technischer Taxonomienamen.
- Geänderte Detailentscheidungen verwerfen bereits erzeugtes XML und die
  vorherige Freigabe. Das festgeschriebene Journal bleibt unverändert.

Die Zuordnung bestimmt ausschließlich die Position in Bilanz/GuV. Sie erteilt
keine steuerliche Abzugsberechtigung, erzeugt keine Lohnabrechnung, berechnet
keine Umsatzsteuer und ersetzt nicht die gesonderte steuerliche Überleitung.
So wird beispielsweise eine tatsächlich gebuchte nicht abziehbare Vorsteuer
als Aufwand ausgewiesen, nicht nochmals als Vorsteuerforderung.

Keine pauschale Auffangregel für unbekannte Konten und keine Ableitung aus der
ersten Kontoziffer. Nur im Jahreskatalog vorhandene Hauptkonten sind ergänzt;
unbestätigte Unterkonten werden nicht aus einer Nummernspanne erfunden.

## Kontenabdeckung nach diesem Schritt

Je Jahr 198 bedingungslose und 25 bedingte Kontenzuordnungen. Der vollständige
buchbare Katalog enthält weiterhin 1.689 Konten für 2025 bzw. 1.679 für 2026.
Die übrigen Konten bleiben zur individuellen Prüfung offen. Diese Zahlen sind
**keine Aussage, dass sämtliche Konten oder steuerlichen Sonderfälle abgedeckt
sind**. Insbesondere die allgemeine Anlagen-, Sphärenwechsel-, Zeitraum- und
Korrektur-Integration sowie Gesamtabnahme bleiben eigene Release-Bedingungen.

## Prüfung

- 464 Assertions für Kontenzuordnung, vollständige begrenzte Auswahllisten,
  konkrete Rückfragen, maskierte Beschriftungen, Erstattungen/Stornos und
  unveränderte Eingabebeträge in beiden Jahren.
- 14 zusätzliche MySQL-Workflowprüfungen, zusätzlich 61 wiederverwendete
  Basisprüfungen: Schreiben/Lesen, tatsächliche XML-Erzeugung, Rollen,
  Vorstandfreigabe, Freigabeentzug bei geänderter Zuordnung, unverändertes
  Journal. Der neue Testfall legt keinen Übermittlungsversuch an; vorhandene
  Transport-Basisprüfungen verwenden ausschließlich einen lokalen Simulator.
- 137 Python-Tests aus sieben Gruppen bestanden (Generator 61, Kapital 8,
  Gegenparteien 4, Umsatzsteuer 11, Gesamtverein/SGEB 32, Nebenrechnung 18,
  neue Betriebsaufwandsgruppe 3 mit parametrisierten Fällen).
- 171 native ERiC-Validierungen: 65 neue Konten × zwei Jahre, 18
  Detailentscheidungen × zwei Jahre, vier gemischte Soll-/Haben-Szenarien und
  ein Basisszenario. Ein zusätzlicher, absichtlich falscher Bilanzsaldo wurde
  mit fachlichen Fehlern abgewiesen. Nur Validierungsflag 2, **kein Versand**.
  Nachweis: `audit/ebilanz-operating-native-20260927.json`.
- Acht Testgruppen auf der dauerhaft autorisierten synthetischen MySQL-DB
  erfolgreich: Katalog, Jahreskatalog, Mapping, Betriebsaufwand,
  Betriebsaufwand-Abgabeworkflow, Kapital, Umsatzsteuer, Laufzeitbindung.
  Nachweis: `audit/ebilanz-mysql-host-20260918-a74610e67b74b519.json`.
  Quellpaket: `292a57d16dabe62d85270315208ec78cde673198b1fc09ec68e1e3f197493f7b`.
  Keine erneute Sicherung/Wiederherstellung; Produktivdatenbank nicht verbunden,
  geschützte Produktionsdateien unverändert, private Testdateien entfernt.
- Ein älterer Umsatzsteuer-Test hatte Konto 43340 noch fälschlich für 2025
  ausgeschlossen. Beide verifizierten Originalkataloge enthalten es auf Seite
  12 mit 7 % USt; der Test prüft jetzt diesen belegten Stand. Die Sperre für
  unbekannte Jahrgänge bleibt bestehen.

Oberfläche nur anhand der erzeugten HTML-Struktur geprüft, kein neuer
Desktop-/Handy-Sichttest und kein beobachteter Nutzertest. Die vollständige
Usability-Nachprüfung beginnt weiterhin erst nach der allgemeinen Veröffentlichung.

## Quellen

- [DATEV SKR42 Bilanz, Original-Kontenrahmen](https://www.datev.de/web/de/shop/produkt-details/12901).
- [Amtliche E-Bilanz-Taxonomien](https://www.esteuer.de/), hier die vorhandene
  verifizierte Taxonomie 6.9 einschließlich deutscher Bezeichnungen und
  Rechenbeziehungen. Es erfolgt keine ungeprüfte Umstellung auf eine neue Taxonomie.
- PDF- und normalisierte Katalog-Prüfsummen bleiben unverändert in
  `resources/ebilanz/skr42-6.9.json`; die neuen Erwartungswerte stehen separat
  in `tests/fixtures/ebilanz-operating-accounts.json`.
