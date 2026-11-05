# Bilanzmodul

Dieser Ordner enthält die bisherigen `Balance*.php`-Fachdienste einschließlich
Perioden, Sphären, Quellen, Bewertungen, Korrekturen, Abschlüssen, Prüfständen
und Exporten. Die öffentlichen Klassennamen `Kulturhus\Verein\Balance…` bleiben
für bestehende Aufrufer erhalten. Die beiden bestehenden Download-Controller
liegen ebenfalls hier.

`src/bootstrap.php` und `src/autoload.php` sind die Einstiegspunkte.
`SourceFiles` löst dynamisch angefragte Quellpfade anhand der Modulstruktur auf.
Direkte Aufrufer, Ressourcenpfade, UI-Ansichten, Werkzeuge und Tests sind zusammen
auf die neuen Dateipfade umgestellt. `TaxFilings::runtimeHash()` berücksichtigt
die tatsächlichen neuen Quellpfade.

Steuer-, Anlagen-, Bank- und allgemeine Finanzklassen außerhalb dieser Gruppe
werden schrittweise in weitere Fachordner überführt. Die fachlichen
Bilanz-/Original-/Jahres-/Sphärenregeln werden durch diese Dateiordnung nicht
zusammengelegt. Die bestehende Prüfgruppe läuft mit:

```sh
bash tools/tests/run-mysql.sh balance-ledger.php balance-clearings.php balance-period-areas.php
```

Die Linux-Prüfung verwendet ihren eigenen lokalen Testdienst auf Port 13372.
Der bestehende native Manager und seine Entwicklungstabellen auf 13371 werden
dabei nicht verwendet. Ergebnisse beruhen auf frischen synthetischen Datenbanken.
