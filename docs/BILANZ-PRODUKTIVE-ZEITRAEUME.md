# Produktive interne Buchhaltung mit datierten Zeiträumen

Stand 19. Oktober 2026. Dieser Nachweis betrifft das interne Journal, seine
Abschlüsse und Belegunterlagen. Die eigene E-Bilanz und der amtliche
Steuertransport besitzen weiterhin ihre getrennten Freigaben.

## Einrichtung und Bedienung

Die vorhandenen serverseitigen Einstellungen müssen ausdrücklich gemeinsam
aktiv sein:

```text
VEREIN_MODE=production
VEREIN_BALANCE_WORKSPACE=1
VEREIN_BALANCE_PERIODS=1
```

Außerdem muss für den aktuellen Verein unter den Steuereinstellungen die
Bilanzierung ausgewählt sein. Bei EÜR entstehen keine Bilanzschreibrechte.
Die Finanzverwaltung öffnet den Jahresabschluss über Finanzen → Steuern →
Bilanz → Zeiträume. Finanzleserechte zeigen die Unterlagen; Finanzschreibrechte
erlauben Buchungen. Festschreiben und erneute Bestätigung benötigen zusätzlich
die aktuelle Vorstandsberechtigung. Anmeldung, Vereinszuordnung und Rechte
werden beim Zugriff und erneut im Speichervorgang geprüft.

Ein Zeitraum kann vollständige Monate oder ausdrücklich bestätigte interne
Teilmonate umfassen. Für angebrochene Monate unter „Neuen Zeitraum anlegen“
die zusätzliche Bestätigung zur internen Tagesaufteilung wählen. Ohne sie
bleiben die Eingaben zur Korrektur erhalten; es entsteht kein Zeitraum.
Anfangsbestände werden ausdrücklich geprüft. Vorhandene Belege lassen sich
quellengebunden übernehmen; zusätzliche Buchungen benötigen Datum, Bezeichnung
und Nachweis. Ein Storno ergänzt eine Gegenbuchung und erhält das Original.
Nach der gesonderten Abschlussprüfung entsteht ein unveränderlicher
Abschlussstand. Ein unmittelbar anschließender Zeitraum übernimmt dessen
bestätigte Endwerte, ohne alte Aufwendungen und Erträge erneut zu buchen.

Berichtigungen erhalten den ursprünglichen Abschluss und führen eine eigene
Bestätigung. Veränderte Vorperioden machen einen bereits übernommenen Vortrag
prüfbedürftig. Nach erneuter Prüfung lässt sich dieser Vortrag aktualisieren.
Entfällt die ursprüngliche Vorstandsberechtigung, bestätigt ein aktuell
berechtigter Vorstand den unveränderten Originalabschluss erneut.

## Erhaltung und Grenzen

Alle Ereignisse bleiben an Verein, ursprüngliche Revision und SHA-256 gebunden.
Gleichzeitige Änderungen werden über die bestehende Vereinssperre und die
aktuelle Revision abgesichert. Fehler rollen Definition, Journaleintrag und
Auditänderung gemeinsam zurück. Alte Formulare und wiederholte Anfragen erzeugen
keine zweite Buchung. Die Abschaltung eines der beiden Flags nimmt bestehende
Schreibrechte zurück; bereits vorhandene Unterlagen bleiben mit Leserecht
zugänglich.

Die bestätigte interne Regel verteilt die bereits gerundeten ursprünglichen
Monatsbeträge auf die tatsächlich betroffenen Kalendertage. Der erste
Anschaffungsmonat beginnt am wirklichen Anschaffungstag; seine Summe bleibt
der ursprüngliche Monatsbetrag. Schaltjahre und Restwerte werden berücksichtigt.
Beispiel: Bei 1.200,01 Euro und 60 Monaten verteilen sich die ursprünglichen
20,00 Euro des Anschaffungsmonats ab 18. September in 9,23 Euro bis zum
23. September und 10,77 Euro für den Restmonat. Die zwei bestätigten
Teilabschlüsse verändern weder Rechnung noch Anlage noch ursprünglichen Plan.

GWG bleibt eine Sofortabschreibung am Anschaffungstag. Degressive interne
Zeiträume führen exakte Bruchteile und einen bereits bestätigten Methodenwechsel
weiter; dieser wird nicht bei einem Nutzungswechsel neu gestartet. Ein belegter
vollständiger Abgang erhält den ursprünglichen ganzen Abgangsmonatsbetrag.
Komponentenabgänge sowie belegte Nutzungs-/Bereichswechsel verwenden die gleiche
Originalbasis. Die vorhandenen Grenzen zulässiger Wechsel werden nicht erweitert.

Die Regel wird unveränderlich in Definition, Vorschau und Originalnachweis
gebunden und an unmittelbar folgende Abschlüsse vererbt. Das gilt auch dann,
wenn ein Folgezeitraum wieder vollständige Monate umfasst. Alte unbestätigte
Teilmonatsoriginale bleiben lesbar und erhalten keine neue Regel automatisch.
Die bisherigen unmarkierten Vollmonatsverfahren behalten ihre Originalbeträge.

Diese interne Verteilung ist keine fachliche Bestätigung steuerlicher
Jahres-AfA, etwa bei einem Rumpfwirtschaftsjahr. Deshalb bieten solche Abschlüsse
keine Steuerübernahme an; auch ein direkt angeforderter E-Bilanz-Entwurf wird
vor der Anlage eines Entwurfs zurückgewiesen. Die steuerliche Qualifikation
dieser Fälle und die zusätzlichen Originalfamilien 72500/72550 bleiben offen.

Die ZIP-Unterlagen sind interne Arbeitsunterlagen. Dieser Ausbau aktiviert weder
die produktive eigene E-Bilanz noch einen ERiC-Versand. Die Freigaben,
Umgebungsbindung und amtliche Originalbibliotheksabnahme des Steuertransports
bleiben getrennt. Ältere Prüfungen mit synthetischen Daten und historische
Konten- oder Anlagenzählungen ersetzen diese Nachweise nicht.

## Prüfwege

`tests/balance-period-production.php` verwendet tatsächlich
`VEREIN_MODE=production` in einer neuen isolierten MySQL-Datenbank mit erfundenen
Vereinen und Belegen. Es prüft Buchung, Storno, Abschluss, Berechtigungsentzug,
erneute Bestätigung, Vortrag, Berichtigung und Belegübernahme. Zwei unabhängige
PHP/PDO-Prozesse konkurrieren um dieselbe Revision; genau eine Buchung darf
entstehen. Ein echter Datenbankfehler nach der Definitionseinfügung belegt das
vollständige Zurückrollen. EÜR, Flags, Rollen, Lücken, Überschneidungen und
historische Teilmonate werden mit unveränderten Originalzeilen abgeglichen.

`tests/native-balance-period-production.php` verwendet persönliche native
Anmeldungen und echte HTTP-Anfragen über die zwei tatsächlichen Finanzzugänge:
`ProductionSite::serve` mit Weiterleitung an `public/index.php` sowie den direkten
Aufruf von `public/index.php`. Der private Loopback-Adapter liefert ausschließlich
serverseitige Produktivkonfiguration; Anmeldung, sichere Sitzungscookies,
CSRF-Prüfung und Fachcontroller bleiben unverändert. Er prüft auch echte
Abmeldung und den Entzug der aktuellen Identität. Es handelt sich um lokale
HTTP-Vertragsprüfungen, keine physische Geräte-, TLS- oder Netzleistungsabnahme.

Die bestehenden acht Periodenregressionen prüfen ihre bisherigen Fachfälle
weiter. Ihre früheren pauschalen Produktivsperren sind durch die weiterhin
notwendige Prüfung des deaktivierten Periodenflags ersetzt; die gesonderten
Produktivsperren der E-Bilanz bleiben bestehen. Es werden keine Provideraktionen,
Mails, Zahlungen, Bankabrufe oder Steuerübermittlungen ausgelöst.

## Aktuelle Auslieferungsabnahme

Die zusätzliche Gruppe `native-balance-day-allocation` prüft die Originalrechnung,
quellengebundene Übernahme, exakten Centvortrag, Rollen-/Vereinsgrenzen und die
E-Bilanz-Sperre in tatsächlichem Produktivmodus mit isolierten erfundenen Daten.
Mit `DVM_BALANCE_DAY_BROWSER=1` prüft sie außerdem die tatsächlichen Formulare
über beide Dispatcher, mit und ohne JavaScript, sichere persönliche Sitzungen,
CSRF, Fehlerkorrektur, Wiederaufnahme, ZIP und zwölf Touch-/Schriftansichten.
Die verschlüsselte Wiederherstellung prüft die unveränderten Originalereignisse
und spielt beide Journale durch einen eigenen Prozess direkt aus der
wiederhergestellten Anwendung ab. Es entsteht kein allgemeiner P12-/P25-Abschluss.

Die getrennte Featurefassung ohne den noch offenen P12-Ausbau besteht 48
Fachbedingungen in tatsächlichem Produktionsmodus und 41 HTTP-Bedingungen
über beide beschriebenen Dispatcher. Die bisherigen sieben kleineren
Periodenregressionen bestehen im gemeinsamen Entwicklungsstand. Der umfangreiche
achte Anlagenverwendungsfall besteht in der getrennten Featurefassung unter
dem unveränderten 300-Sekunden-Limit (194,07 Sekunden, 74 MiB PHP-Spitze).
Ein vorheriger Lauf im gemeinsamen P12-Entwicklungsstand überschritt die Grenze
unter Parallellast. Derselbe Entwicklungsstand besteht den anschließenden
Einzellauf mit 179,51 Sekunden und 88 MiB PHP-Spitze bei unverändertem Limit.
Das externe Stackprofil erfasst vor allem ursprüngliche SQL-/Anlagenprüfungen;
daraus wird weder eine P12-Verlangsamung noch ein allgemeiner UI-Abschluss
abgeleitet. Beide Einzelprüfungen sind vollständig grün.
Zwei unveränderte E-Bilanz-/Transport-Sperrgruppen bestehen. Paketgeneration
2026101543, 299 registrierte Kerngruppen; es entsteht kein Release.
