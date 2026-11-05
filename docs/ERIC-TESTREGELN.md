# ERiC-Tests: amtliche Vorgaben und tatsächlicher Prüfweg

Am 18. Oktober 2026 wurde das originale **ERiC-Entwicklerhandbuch 44.3.6.0** aus dem
signierten SDK gelesen. Maßgeblich sind die gedruckten Handbuchseiten154/155,
170–173 und460, insbesondere Kapitel 6.2.1, 6.5.3, 6.5.4, 6.5.5 und 10.1.1.
Originaldatei im SDK: `Dokumentation/ERiC-Entwicklerhandbuch.pdf`.
Das Handbuch wird separat über den eigenen ELSTER-Entwicklerzugang bezogen;
DVM verteilt seine vollständigen Dokumentationsarchive nicht.
[Programmarchiv und Einrichtung](ERIC-ORIGINALPAKETE.md).

## Was das Handbuch vorgibt

- Die **eigene produktbezogene Hersteller-ID gilt sowohl für Echtfälle als auch
  für Testfälle**. Eine fremde oder alte Beispiel-Hersteller-ID ist keine sichere
  Testkonfiguration. Die im Handbuch genannte frühere Test-ID ist gesperrt.
- Nur künstliche Testfälle verwenden. Bei einer später ausdrücklich freigegebenen
  Testübermittlung muss der zur Datenart passende `Testmerker` im ELSTER-XML stehen.
  Ein Testfinanzamt ersetzt diesen Testmerker ausdrücklich nicht.
- `700000004` bezeichnet bei den dafür vorgesehenen Datenarten einen Testfall,
  der beim ELSTER-Annahmeserver ausgesteuert und gelöscht wird. `700000001`
  betrifft die Aussteuerung beim Rechenzentrum des Bundeslands. Andere Verfahren
  haben eigene Vorgaben; kein beliebiger universeller Testwert.
- Eine Testübermittlung ist weiterhin ein externer Versand. Die bloße Bezeichnung
  einer Umgebung als „test“ oder ein erfolgreicher Bibliotheksstart verhindern
  keinen Versand, wenn die Anwendung `ERIC_SENDE` verwendet.

## Lokale Abnahme in dieser Entwicklung

Der bisher ausgeführte Originaltest lädt die Bibliothek, initialisiert Plugins
und ruft ausschließlich `EricVersion` auf. Er verarbeitet **kein Steuer-XML**,
öffnet **kein Zertifikat** und verwendet **kein `ERIC_SENDE`**. Der tatsächliche
Produktadapter bestätigt den Bibliotheksstart und die unveränderten privaten
Konfigurations-/Bibliotheks-/Katalogbytes. Die Hersteller-ID ist privat korrekt
eingetragen, wurde aber für diesen Versionsaufruf nicht an ELSTER übermittelt.

Die 15 erzeugten Formularstände sind lokale Metadaten aus amtlichen XSDs. Das
Erzeugen dieses Katalogs füllt keine Steuererklärung mit Echtdaten und übermittelt
nichts. Alle 15 Einträge haben `transport_enabled:false`; die private
Konfiguration hat `production_enabled:false` und keine freigegebenen
Produktivversionen. Echte Kontakte, Steuererklärungen, Zertifikate oder PINs wurden
für diese Abnahme nicht verwendet.

Für spätere ausschließlich lokale Formularprüfungen sieht das Handbuch
`ERIC_VALIDIERE` vor; bei einer PDF-Vorschau zusätzlich `ERIC_DRUCKE` mit
Vorschauparameter. Die entsprechenden Adapterflags sind 2 beziehungsweise 2|32.
Das Versandflag 4 wird dabei nicht gesetzt. Solche fachlichen Prüfungen brauchen
zusätzlich nachweislich künstliche Eingabedaten und den richtigen Formularstand.

Der vorhandene Versandadapter fordert außerdem exakte XML-Hersteller-/Formular-
zuordnung, den passenden Testmerker, ausdrückliche Umgebungsbestätigung,
bestätigte synthetische Daten bei Tests und eine eigene Verfahrensfreigabe.
Produktivübermittlung erfordert zusätzliche Betreiber- und Versionsfreigaben.
Die Entwicklungsabnahme löst weder Test- noch Produktivversand aus. Eine
zukünftige Versandabnahme ist davon getrennt und aktuell nicht beauftragt.

## Erzwungene Entwicklungssperre

`VEREIN_ERIC_DISABLE_TRANSPORT=1` sperrt im tatsächlichen Python-Adapter `send`,
`feedback-fetch` und `feedback-ack` vor Bibliotheks-, Zertifikats- oder XML-
verarbeitung. Der PHP-Adapter übernimmt diese ausschließlich serverseitige
Variable ausdrücklich in seine Prozessumgebung. Formulare können sie nicht
abschalten. Sowohl der gemeinsame lokale Prüfeinstieg als auch der native
CI-Runner setzen sie unabhängig voneinander. Die private Laufzeit kann zudem
`transport_disabled:true` erzwingen. Neu im Manager angelegte ELSTER-
Konfigurationen erhalten diese Sperre und keine Produktivfreigabe.

Zwölf tatsächliche Unterprozesse prüfen alle drei Netzwerkaktionen in beiden
Umgebungen und mit beiden Sperren. Dabei sind die übrigen Übermittlungsfreigaben
absichtlich geöffnet; die verweigerte Netzwerkaktion gewinnt bereits vor dem
Laden einer nativen Bibliothek. Der vorhandene PHP-Laufzeitvertrag prüft außerdem
die echte Weitergabe dieser Sperre an den Python-Unterprozess. Lokale
Versionsprüfung bleibt damit weiterhin möglich. Keine Test- oder Echtabgabe
wird zur Prüfung dieser Sperre ausgelöst.
