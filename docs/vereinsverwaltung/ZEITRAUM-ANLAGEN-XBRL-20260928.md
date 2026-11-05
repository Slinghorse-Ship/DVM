# Datierte Anlagenwerte im E-Bilanz-Generator

Stand 28.09.2026: implementierter und lokal geprüfter Generatorbaustein,
**keine allgemeine Bilanzfreigabe, keine Veröffentlichung und keine Übermittlung**.
Kulturhus bleibt EÜR. Produktive Daten, Konfiguration und Freigaben unverändert.

## Was dieser Schritt schließt

Die bisherige Beschränkung des datierten Einzelgeschäftsbetriebs-Generators auf
Abschlüsse ohne Anlagen ist aufgehoben. Er rekonstruiert nun aus dem unveränderten
Anlagenplan und den Originalnachweisen:

- Lineare AfA, GWG, degressive AfA einschließlich bestätigtem Wechsel zu linear.
- Vollständige Ausmusterung, belegten Teilabgang, endgültiges Software-Lizenzende
  und Anlagenverkauf; keine erfundenen Abgänge aus einem bloßen Nullsaldo.
- Gesondert bestätigte steuerliche lineare Nutzungsdauer. Auch ein Wechsel vom
  handelsrechtlichen Veräußerungsgewinn zum steuerlichen Verlust erzeugt die
  passenden getrennten Steuerkonten; Originalverkauf und Buchabschluss bleiben
  unverändert.
- Vollständigen Brutto-Anlagenspiegel mit ursprünglichen Anschaffungskosten,
  kumulierter AfA, Zugängen, Kosten-/AfA-/Restwertabgängen und Schlusswerten.
- Tatsächliche volle Abschlussmonate und unveränderte Vortragskette in einen
  kurzen Folgezeitraum, auch bei zwei Abschlüssen mit demselben Endjahr.
- Vollständig abgeschriebene, noch vorhandene Gegenstände bleiben mit ihren
  Anschaffungskosten sichtbar. Bereits ausgemusterte Gegenstände bleiben in der
  Nachweiskette, ohne Aufwand oder Abgang im Folgezeitraum erneut zu buchen.

`ebilanz_period_asset_math.py` rechnet die Monatswerte unabhängig vom PHP-Kern
nach. `ebilanz_period_assets.py` vergleicht Original- und Steuerwerte, sämtliche
erzeugten Überleitungszeilen und Kontenbewegungen. Erst anschließend werden die
Spalten in die vorhandene amtliche Taxonomie eingeordnet und deren Summenregeln
geprüft. Ein neu berechneter Hash allein beseitigt widersprüchliche Beträge nicht.

Die native Prüfung deckte einen zusätzlichen konkreten Fehler auf: ELSTER weist
das Zeichen U+00B7 in der Kontenbeschreibung zurück. Nur bei der XML-Ausgabe wird
dieser optische Trenner zu `-`; gespeicherte Namen, Kontonummern, Originalwerte,
Quell-Hashes und Freigaben werden nicht geändert. Die synthetische Grundfixture
verwendet für Vereinskapital jetzt korrekt 25000 statt des Rücklagenkontos 20000.

## Nachweise

- 20 neue PHP-Prüfungen der deterministischen Datensatzgrenze für zehn Varianten.
- 6 neue Python-Testmethoden mit zehn positiven Datensatzvarianten sowie
  absichtlich verfälschten Plänen, Kosten, Teilkosten, Steuerwerten,
  Verkaufsnachweisen, fehlenden Vorträgen und fehlender Steuerbestätigung.
- 3 weitere Python-Testmethoden: 585 PHP/Python-Vergleiche über 13 aufeinander
  folgende Zeiträume, verschiedene Kosten-/Restwert-/Laufzeitgrenzen und Methoden;
  zusätzlich 117 getrennte steuerliche Teilabgangsberechnungen. Unbekannte
  Bewegungsarten und angebrochene Monate werden nicht stillschweigend ignoriert.
- Bestehende 12 Zeitraum-Grenzprüfungen, 41 Steuerwertprüfungen, 103 allgemeine
  Zeitraumprüfungen, 52 degressive und 883 Abgangsprüfungen erneut bestanden.
- Native ERiC-Validierung `Bilanz_6_9`, **ausschließlich Flags 2**: alle 14 Fälle
  Ergebniscode 0. Vier bisherige datierte Fälle ohne Anlagen, vier gemischte
  Anlagen-/Steuerfälle und sechs GWG-/degressive-/Nullbuchwertfälle, jeweils mit
  echtem Erst-/Folgezeitraum. Bericht:
  `audit/ebilanz-period-assets-native-20260928.json`.
- Die 22 bisherigen nativen Kalenderjahr-Fälle erneut Ergebniscode 0;
  absichtlich verfälschter Gesamtbetrag korrekt mit `610001002` zurückgewiesen.
  Bericht: `audit/ebilanz-calendar-after-period-assets-20260928.json`.
- Gemeinsamer Generator-Regressionslauf: alle 22 Python-Testgruppen erfolgreich,
  einschließlich Kalender-/Gesamtvereins-/Nebenrechnungs-, Kapital-, gemeinsamer
  Anlagen-, Steuer-, Umsatzsteuer- und Kontenzuordnungsfälle. Die fünf betroffenen
  PHP-Dateien und neun Python-Dateien waren syntaxfehlerfrei; `git diff --check`
  ohne Befund. Der anschließend ergänzte negative Degressions-Vortragstest
  bestand ebenfalls zusammen mit dem vollständigen 585er-Rechenraster.

Reproduzierbare Einstiegspunkte:

```text
php vereinsverwaltung/tests/balance-period-filing-assets.php
python vereinsverwaltung/tests/ebilanz-period-assets-test.py
python vereinsverwaltung/tests/ebilanz-period-asset-math-test.py
python vereinsverwaltung/tests/ebilanz-period-native.py --config PRIVATE_RUNTIME_JSON
```

Die Python-Tests benötigen `VEREIN_FIXTURE_PHP` als absoluten Pfad zur lokalen
PHP-Laufzeit. Native Validierung benötigt die vorhandene lizenzierte private
ERiC-Laufzeit; sie wird nicht mitverteilt. Keine Zertifikatsnutzung, keine
Netzwerk-/Versandfunktion in diesen Tests. Ein erfolgreicher Prüfcode ist kein
ELSTER-Eingangs- oder Verarbeitungsnachweis.

## Verbleibende Grenze – nicht als erledigt markieren

Dieser Baustein bleibt ein datierter Einzelgeschäftsbetriebs-Generator, kein
vollständiger allgemeiner Vereinsrelease. Insbesondere noch erforderlich:

1. Datierte Gesamtvereins-/Mehrbetriebs-/Nebenrechnungsausgabe einschließlich
   vollständiger Anlagenaufteilung und eigenem Abgleich beider Ausgabeteile.
2. Echte steuerliche Sphärenwechsel mit dem fachlich passenden Einlage-/Entnahme-
   bzw. Überführungsansatz. Monatliche Nutzungsänderung in derselben Sphäre ist
   kein Ersatz dafür.
3. Vollständige Konten- und weitere Anlagen-/Steuersonderfallabdeckung.
4. Gespeicherte datierte Abgabeidentität, Berechtigungen und Freigabeoberfläche,
   Korrektur-/Versand-/Rückmeldekette sowie abschließende Gesamtabnahme.

Die Schutzgrenze `BalanceTaxFiling::calendarSourceOnly` bleibt unverändert.
Keine neue Route, keine Datenbankmigration, keine Produktivfreischaltung.
Die nachgelagerte Gesamt-Usabilityprüfung beginnt deshalb noch nicht.

Ein direkter Start eines älteren MySQL-Tests mit lokalem PHP wurde durch dessen
Isolationsschutz abgewiesen. Docker ist aktuell nicht erreichbar; die
Datenbankintegration wurde in diesem Schritt deshalb **nicht erneut abgenommen**.
Es wurde weder auf die Produktivdatenbank noch ersatzweise auf deren Zugang
ausgewichen. Die oben beschriebenen Generatorprüfungen benötigen keine Datenbank.
