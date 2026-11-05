# ELSTER/ERiC privat einrichten

Diese Anleitung gilt für eine eigene DVM-Installation. Herstellerregistrierung,
Steuerzertifikat und technische Laufzeit sind getrennte Voraussetzungen.
[Vertrag und Pflichten](../ERIC-LIZENZ.md),
[Programmpaket](../ERIC-ORIGINALPAKETE.md),
[gemeinsame Laufzeitvorbereitung](../ZUSATZLAUFZEITEN.md).

## Vorbereitung

1. Die für den eigenen Produktbetrieb gültige Herstellerregistrierung klären.
   ELSTER verwendet fünf Ziffern; eine FinTS-Produkt-ID ist eine andere Kennung.
2. Aktuelle freigegebene Mindestversion im eigenen ELSTER-Entwicklerzugang prüfen.
   Die enthaltene Version 44.3.6.0 ist ein festgelegter Paketstand, kein Updater.
3. Die Datenschutzinformationen und bestätigte Kenntnisnahme gemäß § 5 sowie die
   übrigen Betreiberpflichten vor ERiC-Nutzung organisatorisch einrichten.
4. Die Programmbibliotheken außerhalb des Webroots vorbereiten. Native Laufzeit,
   Python, Plugins und eventuell erforderliche Systembibliotheken müssen vom
   späteren PHP-Webprozess erreichbar und ausführbar sein.

## Formularkatalog

Die vollständige Entwicklerdokumentation mit den passenden amtlichen Schemata
selbst über ELSTER beziehen und privat aufbewahren. Der Compiler verarbeitet
diese lokalen Originale; DVM verteilt die Dokumentationsarchive nicht:

```sh
python3 vereinsverwaltung/tools/compile-eric-forms.py \
  --documentation /PRIVAT/ERiC/Dokumentation \
  --output /PRIVAT/eric/form-catalog.json --years 2025 \
  --periodic-years 2025 2026 --messages --registration
```

Das sind die vorhandenen Compileroptionen, keine Freigabe zusätzlicher Jahre.
Jeder Zeitraum benötigt seinen tatsächlichen passenden Schema- und Laufzeitstand.

## Private Konfiguration

Eine neue JSON-Datei mit 0600 anlegen und ihren absoluten Pfad als
`VEREIN_ERIC_RUNTIME` im PHP-Webprozess und in den betroffenen privaten Jobs setzen.
Erforderliche Angaben:

| Feld | Bedeutung |
|---|---|
| `version` | Konfigurationsformat 1 |
| `manufacturer_id` | Eigene gültige fünfstellige Hersteller-ID |
| `library` | Absoluter Pfad zu `lib/libericapi.so` |
| `scratch` | Privates beschreibbares Arbeitsverzeichnis mit 0700 |
| `catalog` | Absoluter Pfad zum selbst erzeugten Formularkatalog |
| `command` | Argumentliste aus absolutem Python-Interpreter und absoluter `eric-bridge.py` |
| `profiles` | Passende Katalogprofile ohne die umfangreichen `types` |
| `production_enabled` | Bei Ersteinrichtung `false` |
| `production_versions` | Bei Ersteinrichtung leere Liste |

Auch `transport_enabled` der Profile bleibt bis zur gesonderten Prüfung aus.
Bei einer Produktivinstallation gehört die Konfiguration zum eigenen Verein;
vorhandene Bindungen und Originalfelder erhalten. Änderungen in neuen privaten
Versionsverzeichnissen vorbereiten, keine aktive Bibliothek unter gleichem Pfad
überschreiben.

Die Hersteller-ID kann ein berechtigter technischer Verwalter unter
**Einstellungen → Steuern** hinterlegen. Ohne expliziten Laufzeitpfad verwendet
DVM `VEREIN_PRIVATE_ROOT/runtime-config/eric.json`. Eine neue Kennung installiert
keine Bibliothek und aktiviert keine Übermittlung.

Windows benötigt passende amtliche Windows-Bibliotheken und zusätzlich
`windows_process_command` mit dem absoluten Python-Interpreter. Das enthaltene
Linux-Paket ist dafür nicht geeignet.

## Prüfen und freigeben

```sh
VEREIN_ERIC_RUNTIME=/PRIVAT/eric.json php vereinsverwaltung/tools/check-eric-runtime.php
```

Dieser Check prüft Initialisierung und Version, ohne Steuer-XML, Zertifikat oder
Versand. Anschließend passende synthetische Formulare, PDF-Ausgaben und die
vereinbarte Testübermittlung getrennt abnehmen. Erst danach gegebenenfalls eine
Produktivfreigabe pro unterstützter Datenart erteilen. Historische Prüfungen
anderer Installationen ersetzen diese Schritte nicht.

Die [ERiC-Testregeln](../ERIC-TESTREGELN.md) beschreiben die Trennung von lokaler
Validierung und Übermittlung. Für [E-Bilanz](../EBILANZ-UMGEBUNGEN.md) gelten eigene
fachliche Grenzen. Arbeitsunterlagen und ein Transferticket bestätigen keine
steuerfachliche Anerkennung.

Zertifikate werden über den bestehenden Steuerbereich eingerichtet. Passwörter
bleiben getrennt; [Zertifikatsablage](ELSTER-ZERTIFIKATSABLAGE.md) und
[Belegnachreichung](ELSTER-PDF-BELEGE.md) haben eigene Voraussetzungen.
