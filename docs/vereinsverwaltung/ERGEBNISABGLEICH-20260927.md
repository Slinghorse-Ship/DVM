# Ergebnisabgleich bei internen Vorschüssen

Stand 27.09.2026. **Entwicklungsstand, keine vollständige Bilanzfreigabe. Kulturhus bleibt EÜR.**

Fortsetzung: Die getrennte [steuerliche Nebenrechnung](STEUERLICHE-NEBENRECHNUNG-20260927.md)
verwendet diesen Buchführungsnachweis inzwischen für geprüfte Wertunterschiede,
außerbilanzielle Korrekturen und Vorträge. Die hier beschriebene reine
Ergebnisabgleich-Datei bleibt ein Buchführungsnachweis, keine Steuerfreigabe.

## Implementiert

`BalanceClubIncomeReconciliation` verbindet das unveränderte Gesamtjournal mit
dem aktuell geprüften Bereichs- und Verrechnungsnachweis. Er ermittelt aus jeder
Original-GuV-Zeile das Buchführungsergebnis aller steuerpflichtigen Betriebe
zusammen sowie das Ergebnis der übrigen Vereinsbereiche. Es gibt keine freie
Auswahl einzelner steuerpflichtiger Betriebe und keine neuen Buchungen.

- Originalkonto, Originalbeleg, Datum, Soll/Haben und alle Beträge in ganzen Cent
  bleiben im Nachweis. Gutschriften behalten ihre Buchungsseite.
- Die Original-Sphäre bestimmt die Ergebniszuordnung, nicht das bezahlende Konto
  oder der Bereich, der Geld vorgestreckt hat.
- Offene Vorschüsse, Teilrückzahlungen und Rückzahlungen im Folgejahr wirken
  nicht auf den Gewinn. Interne Guthaben und Rückzahlungsverpflichtungen über
  die Grenze des steuerpflichtigen Bereichs werden getrennt ausgewiesen,
  ohne sie als Forderungen/Schulden gegenüber Dritten auszugeben.
- Verrechnungen innerhalb der steuerpflichtigen Betriebe und vollständig außerhalb
  davon werden gesondert nachgewiesen. Gegenläufige Beträge werden nicht saldiert.
- Originaljournal, Aufteilung, Vorstandsprüfung und alle benötigten Vorjahre
  sind gebunden. Der Bereichsvortrag wird erneut aus den Originalen rekonstruiert.
  Entzogene Vorstandsrechte, überholte Aufteilungen und Änderungen im Vorjahr
  sperren die Erstellung eines aktuell geprüften Ergebnisnachweises.
- Finanzleser können denselben Nachweis lesen; Mitglieder und andere Vereine
  erhalten keinen Zugriff. Der gesamte Vorgang ist lesend.
- Der Jahresabschluss-Download enthält `verrechnungen/ergebnisabgleich.json`
  mit den Originalzeilen und `verrechnungen/ergebnisabgleich.txt` als lesbare
  Zusammenfassung. Beide Dateien werden in den Paket-Hash aufgenommen.
  Entwürfe bekommen keinen als geprüft ausgegebenen Ergebnisnachweis.
- Der neue Rechenkern ist Teil des Übermittlungs-Laufzeitfingerprints. Eine
  spätere Änderung kann daher nicht unbemerkt unter einer alten Steuerfreigabe laufen.

## Fachliche Einordnung und Grenze

Die amtliche [E-Bilanz-FAQ, Stand Januar 2026, Abschnitt 4 m](https://www.esteuer.de/download/taxonomie/FAQ_Version_2026-01.pdf)
beschreibt neben der Gesamtbilanz/GuV unterschiedliche Übermittlungsformen für
steuerpflichtige Teilbereiche, darunter eine erläuterte Nebenrechnung. Daraus
folgt keine automatische steuerliche Zuordnung interner Vorschüsse zu einem
Fremdkapital- oder Eigenkapitalkonto. Die FAQ ist eine Orientierungshilfe, keine
verbindliche Einzelfallentscheidung.

Der neue Nachweis ist **nur das rekonstruierte Buchführungsergebnis vor
steuerlichen Anpassungen**, noch keine fertige steuerliche Nebenrechnung oder
XBRL-Übermittlung. Die Felder `tax_values_reviewed` und `tax_export_ready`
bleiben ausdrücklich `false`. Die vorhandene Sperre nichtleerer Verrechnungen
in allen vier E-Bilanz-Quellprofilen wurde nicht entfernt.

Noch nötig für diesen Teil: überprüfte Überleitung zu Steuerwerten, Einbindung
in die gewählte amtliche Darstellungsform, unabhängige Generatorprüfung und
native ERiC-/Webserver-Abnahme einschließlich des vorgesehenen Testversands.
Dies ist kein Ersatz für die weiteren offenen Anforderungen der allgemeinen
Vereinsbilanz (Anlagen-/Kontenfälle, Wirtschaftsjahre und Korrekturabschlüsse).

## Prüfungen

- 2.705 PHP-Prüfungen mit synthetischen Daten: alle Cent-Teilrückzahlungen von
  0 bis 334, drei Jahre, Gutschrift, fehlende/überholte Freigaben, geänderte
  Quell- und Vorjahresnachweise sowie alle vier Richtungen der Bereichsgrenze.
  Die Anzahl beschreibt Testassertionen, nicht die Zahl abgedeckter Steuerfälle.
- Sechs unabhängige Python-Tests rekonstruieren Zeilen, Kontensummen,
  Bereichsergebnisse und interne Grenzsalden. Selbst neu gehashte, veränderte
  Ergebnisse oder ausgelassene Betriebe werden erkannt.
- Bestehende unabhängige Nachweise für interne Verrechnungen und den
  Bereichsabgleich bleiben unverändert grün; 102 Laufzeitfingerprint-Prüfungen.
- Isolierter MySQL-8.4-/PHP-8.3-Lauf am 27.09.2026: neun Testgruppen vollständig
  bestanden, ohne PHP-Diagnosen. Darunter 87 Persistenzprüfungen, 41 Prüfungen
  des Drei-Jahres-Vortrags und 62 bestehende Steuerablauf-Prüfungen mit simuliertem
  Transport. Protokoll: `audit/ebilanz-mysql-host-20260918-fca93402d5469f29.json`
  (der historische Dateinamen-Präfix bezeichnet nicht das Durchführungsdatum).
  Quellpaket: `9d2d12206cf93104bf1c81e7e517eebd88883dd6de1d6c47b73ae175b9e8dfdb`.
- Die freigegebene zweite Datenbank wurde vorab serverseitig verschlüsselt
  gesichert und anschließend identisch wiederhergestellt: 150 Tabellen,
  1.393 Datensätze, Tabellendefinitionen und Trigger. Drei geschützte Live-Dateien
  unverändert; privates Testverzeichnis nach erfolgreicher Wiederherstellung
  entfernt. Keine Produktionsdatenbankverbindung, keine echten Mails,
  Bankaufträge oder Steuerabgaben; kein vorgezogener Manager-Gesamttest.
- Syntaxprüfung aller 1.009 PHP-Dateien unter `vereinsverwaltung` fehlerfrei.
  Quellinventar und statische Abhängigkeiten: 2.284 Dateien geprüft.
