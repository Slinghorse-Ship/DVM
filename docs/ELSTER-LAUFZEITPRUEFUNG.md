# ERiC-Laufzeit tatsächlich prüfen

Die private Konfiguration und der tatsächliche Bibliotheksstart sind getrennte
Nachweise. Das [Programmpaket](ERIC-ORIGINALPAKETE.md) enthält einen festgelegten
Linux-x86_64-Stand. Eine neue Installation benötigt ihre eigene
[Einrichtung](vereinsverwaltung/ELSTER-ERIC-INTEGRATION.md) und muss die
[ERiC-Vertragsbedingungen](ERIC-LIZENZ.md) erfüllen.

## Lokaler Check

```sh
VEREIN_ERIC_RUNTIME=/PRIVAT/runtime.json php vereinsverwaltung/tools/check-eric-runtime.php
```

Der CLI-Check lädt über den vorhandenen Adapter die konfigurierte Bibliothek,
initialisiert ihre Plugins und ruft `EricVersion` auf. Puffer und native Laufzeit
werden anschließend beendet. Er verarbeitet kein Steuer-XML, öffnet kein
Zertifikat und startet keine Übermittlung. Private Konfiguration und Bibliotheken
werden nicht in die Ausgabe kopiert.

`runtime_verified:true` bestätigt den tatsächlichen Start für den gerade
geprüften Bestand. Fehlende Konfiguration, unpassende Plattform, fehlende
Abhängigkeiten oder eine geänderte Bibliothek bleiben Fehler. Pfade, Dateirechte,
Interpreter und Hosting-Prozessrechte im späteren Webkontext getrennt prüfen.

## Was damit nicht geprüft ist

Ein Bibliotheksstart ersetzt weder die Prüfung eines amtlichen Formulars noch
eine Testübermittlung oder Produktivfreigabe. Herstellerregistrierung,
Endnutzerinformationen, aktuelle Mindestversion und fachlicher Fallumfang bleiben
zusätzliche Voraussetzungen. Für E-Bilanz gelten die
[eigenen Jahrespakete und Grenzen](EBILANZ-UMGEBUNGEN.md).

Die [Testregeln](ERIC-TESTREGELN.md) erläutern die Trennung von Validierung und
Transport. Die ausführbaren Prüffälle stehen unter
`vereinsverwaltung/tests/native-eric-runtime.php`, `eric-bridge-test.py` und
`eric-no-transport-test.py`. Historische erfolgreiche Tests anderer Installationen
bestätigen keine heute geänderte Laufzeit.
