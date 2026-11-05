> Dieses Dokument enthält historische Prüfstände. Belege mit Dateidatenbanken
> gelten nur für die damalige Laufzeit und ersetzen keinen MySQL-Nachweis.
> Aktuelle Betriebswege:
> [Architektur](../ARCHITECTURE.md), [Installation](../WEBSPACE-INSTALLATION.md),
> [Sicherung](../NATIVE-SICHERUNG-WIEDERHERSTELLUNG.md).

# Steuerliche Überleitung für tatsächliche Abschlusszeiträume

Stand: 28.09.2026. Entwicklung mit synthetischen Daten; kein allgemeiner
Bilanzrelease und keine produktive Steuerabgabe. Kulturhus bleibt EÜR.

## Verbundener Ablauf

1. Der festgeschriebene Buchungsstand und die bestätigte Bereichsaufteilung
   bilden gemeinsam den Ausgangspunkt. Vorhandene Buchungen werden nicht
   ersetzt oder nachträglich auf steuerliche Werte umgeschrieben.
2. Steuerwerte verwenden Beginn **und** Ende des Wirtschaftsjahres. Ein kurzes
   Folgejahr und ein abweichendes Jahr mit demselben Endjahr erhalten eigene
   Prüfverläufe und können nicht versehentlich denselben Jahresdatensatz nutzen.
3. Abweichende lineare steuerliche Nutzungsdauern werden an Originalanlage,
   Originalkonto, Begründung und bestätigten Vorzeitraum gebunden. Voll- und
   Teilabgänge, endgültiges Software-Lizenzende und belegte Anlagenverkäufe
   berücksichtigen die unterschiedlichen Restbuchwerte.
   Diese abweichenden Pläne gelten für die bereits unterstützte Ausstattung
   und Software (01350, 06300, 06500), nicht automatisch für sämtliche
   Anlagekonten. Unveränderte bestätigte GWG-/degressive Originalpläne werden
   weitergeführt; zusätzliche steuerliche Sonderabschreibungen sind damit
   nicht freigegeben.
4. Wechselt ein Verkauf steuerlich von Gewinn zu Verlust oder umgekehrt,
   entstehen getrennte steuerliche Gegenkonten aus dem passenden Jahreskatalog.
   Originalrechnung, Vorsteuer/Umsatzsteuer und ursprüngliche Konten bleiben
   unverändert. Ein beliebiges zweites Konto mit gleicher SKR42-Nummer darf
   den ursprünglichen Nachweis nicht ersetzen.
5. Steuerliche Anfangsunterschiede werden aus dem ausdrücklich bestätigten
   Vorzeitraum übernommen. Sie werden nicht nochmals als laufender Ertrag oder
   Aufwand gezählt. Auch der Übergang eines bestätigten Kalenderabschlusses zu
   einem kurzen datierten Zeitraum behält dessen ursprüngliche Steuerpläne.
6. Hinzurechnungen und Kürzungen brauchen eine konkrete Buchungszeile,
   steuerliche Grundlage und Begründung. Steuerfreie Bereiche dürfen nicht
   über pauschale Steuerwertbuchungen verändert werden.
7. Finanz-Schreibberechtigte bereiten vor; die gesonderte Vorstandsfreigabe
   bestätigt genau den unveränderten Stand. Neue Korrekturen, geänderte
   Ausgangsfreigaben oder geänderte Vorwerte entwerten abhängige Freigaben.

## Speicherung und Oberfläche

Migration 108 ergänzt `balance_period_tax_reviews` mit Vereins-/Zeitraumbezug,
Versionskette und nativen MySQL-Sperren gegen UPDATE/DELETE. Neue Datensätze
werden zusammen mit dem Audit-Ereignis geschrieben. Veraltete Formularversionen,
abweichende Quellen, fremde Vereine und fehlende Berechtigungen führen zu keiner
Änderung. Es findet keine Dateidatenbank-Nutzung statt.

Die Steueransicht zeigt zunächst Zeitraum, Ergebnis und Prüfstatus. Fachliche
Korrekturen bleiben eingeklappt; nach einem Eingabefehler bleibt der betroffene
Abschnitt geöffnet, Eingaben werden escaped erhalten. Ein einzelner
Layout-Container, größere Ergebnisbeschriftungen und gestapelte Kontenwerte
auf schmalen Bildschirmen vermeiden unnötige Breite.

Arbeitsunterlagen enthalten die vollständige Nebenrechnung, eine lesbare
Zusammenfassung und den ursprünglichen Prüfverlauf. Ein Entwurf ist bezeichnet;
veraltete Quellen dürfen nicht als aktuelle Arbeitsunterlagen ausgegeben werden.
Die bestätigte Rechnung ist ausdrücklich **keine Übermittlungsfreigabe**.
Der Kalender-ELSTER-Weg kann nicht mit einem datierten Abschluss aufgerufen werden.

## Prüfung und verbleibender Umfang

Prüfungen umfassen reine Centberechnung, Original-/Steuerwertvergleich,
Kalenderübergang, gespeicherte tatsächliche Verkaufsrechnungen, kurze Folgejahre,
Quellenberichtigung, rollenabhängige Masken und echte POST-Controller. Auf dem
Webserver wird ausschließlich `k15753_migrate` mit synthetischen Daten verwendet.
Echte Mails, Zahlungen und Steuerabgaben sind gesperrt. Produktionskonfiguration
und beide Einstiegspunkte werden nur gehasht, nicht geändert.

Der gemeinsame Nachlauf
`audit/ebilanz-mysql-host-20260918-9e1229dc9d3668ce.json` bestand mit sieben Gruppen:
15 Lebensdauerprüfungen des Lese-Zwischenspeichers, 43 Zeitraum-Steuerprüfungen,
12 gespeicherte Kalender-/Zeitraumübergänge, 58 bisherige Steuerworkflowprüfungen,
96 Anlagenaufteilungsprüfungen, 11 Schema-/Wiederherstellungsprüfungen und
158 Fingerprint-Prüfungen. Quellpaket:
`41065bd23e69e39a7fcf50e53ceb5b65402d3566fa98a1963115649c639466bd`.
Die unveränderten Produktivdateien und die Entfernung des privaten Testbereichs
sind im Nachweis bestätigt. Der historische Dateinamen-Präfix ist nicht das Testdatum.

Die technische Sichtprüfung steht im
[Anlagennutzungs-Nachweis](ZEITRAUM-ANLAGENNUTZUNG-20260928.md#prüfung).
Zusätzlich zeigt die Steuerkorrektur-Auswahl Datum, Buchungsbezeichnung und
Zeile statt einer technischen Kennung. Beträge werden in ganzen Cent verarbeitet;
fehlerhafte Eingaben bleiben erhalten, ohne eine Freigabe zu erzeugen.

Ein früherer gemeinsamer Verkaufstest scheiterte am kumulativen PHP-Zeitlimit:
Die Erzeugung der hybriden Originalrechnung setzte es innerhalb des Testprozesses
erneut auf 150 Sekunden. Nur die folgende synthetische CLI-Testphase setzt es
wieder zurück. Kein Laufzeitlimit der produktiven Anwendung wurde verändert.

Die Messung zeigte daneben zu viele wiederholte Lese-/Rekonstruktionsaufrufe.
`BalanceReadSnapshot` bündelt verifizierte Lesemodelle ausschließlich innerhalb
eines ausdrücklichen Lesevorgangs auf derselben Datenbanktransaktion. Schlüssel
unterscheiden anfragenden Akteur, Zeitraum und Quellprüfmodus. Kein Cache über Anfragen
hinweg; vor jedem INSERT/Audit wird er verworfen. Berechtigungsentzug,
Quellenänderung und neue Vorgängerfreigaben dürfen nicht durch alte Ergebnisse
verdeckt werden. Eigene MySQL-Lesevorgänge verwenden ausdrücklich
`REPEATABLE READ` und `WITH CONSISTENT SNAPSHOT, READ ONLY`, ohne die allgemeine
Schreibsperre zu belegen. Eine bereits dem Aufrufer gehörende Transaktion bleibt
unverändert. Die Bereichsseite verwendet dieselbe anfragengebundene Strategie.

Die gemeinsame Prüfung der Seiten-/Export-Bündelung ist erfolgreich:
`audit/ebilanz-mysql-host-20260918-c351a9fe70e16266.json`, 15 Gruppen, alle ohne
PHP-Diagnostik bestanden. Quellpaket:
`1e8ce6cc82c874af10b13cba7f5a5b465a482ebfef90399d973231d26086932d`.
Sie enthält 45 Zeitraum-Steuerprüfungen, 12 gespeicherte Kalenderübergänge und
den vollständig erfolgreichen Verkaufskettenlauf: 6 zusätzliche Steuerprüfungen,
160 Bereichsprüfungen und 133 Original-Verkaufsprüfungen. Die bisherigen
Anlagenabgangs-, Kalenderanlagen-, Steuer-, Centverteilungs- und Schema-Tests
bestanden gemeinsam. Produktion unverändert, privater Testbereich entfernt.

Im umfangreichen Verkaufsfall wurden folgende einzelne Serviceaufrufe gemessen
(keine pauschale Zusicherung für beliebige Datenmengen oder komplette HTTP-POSTs):

| Aufruf im Folgezeitraum | Vor Bündelung | Nach Bündelung |
| --- | ---: | ---: |
| Entwurf vorbereiten | 17,706 s | 3,062 s |
| Entwurf speichern | 16,250 s | 3,240 s |
| Steuerfreigabe bestätigen | 10,552 s | 3,158 s |
| Prüfstand lesen | 10,457 s | 3,279 s |

Die vollständige neue Seiten-Vorbereitung einschließlich Vortragsprüfung benötigte
3,287 s, die reine rechnerische Gegenprüfung 0,093 s. Der erste Vergleichslauf
steht in `audit/ebilanz-mysql-host-20260918-9a8d2f73a531d6d8.json`; dieser ältere
Langlauf endete wegen des oben genannten kumulativen Testzeitlimits nicht erfolgreich.
Der abschließende native Lesetransaktions-Nachlauf bestand mit acht Gruppen:
`audit/ebilanz-mysql-host-20260918-75fa27e0316c87da.json`, Quellpaket
`56ae461090c90551ba2d74a06a257970ed63eaec4046e94f24b63d38c5f4cc61`.
Er enthält zusätzlich zwölf echte Zwei-Verbindungs-Prüfungen: ein paralleler
Schreiber kann speichern, der Leser sieht weiter seinen konsistenten Stand,
MySQL weist versehentliche Schreibversuche im Lesemodus ab, die nächste Anfrage
sieht die Änderung und die Session-Vorgaben bleiben unverändert. Alle 45
Steuer-, 12 Kalenderübergangs-, 58 bisherigen Steuerworkflow-, 96 Anlagen-,
11 Schema- und 158 Fingerprint-Prüfungen bestanden erneut. Der vorherige
Diagnoselauf `3e0ebbcef263ecb1` deckte gleichlautende Closure-Kennungen unter
PHP 8.3 auf; die Modelle verwenden jetzt explizite, getrennte Methodenkennungen.
Die Schutzprüfung wurde nicht abgeschaltet. Auch im letzten Lauf keine
Produktivverbindung, geschützte Dateien unverändert, private Testdateien entfernt.

Zusätzlich bestanden 294 Python-Testmethoden in 20 Gruppen des vorhandenen
E-Bilanz-Generators, ohne ERiC-Übermittlung. 1.106 PHP-Dateien waren syntaxfehlerfrei;
nach der letzten Änderung wurden alle 42 geänderten/neuen PHP-Dateien nochmals
geprüft, einschließlich des hinzugekommenen Zwei-Verbindungs-Tests. Die 16
JavaScript-Prüfungen der Zeitraum-Belegsuche bestanden ebenfalls.

Noch nicht damit abgedeckt: echte Anlagen-Sphären-/Nutzungswechsel, sämtliche
Anlagen- und Steuersonderfälle, vollständige Kontentaxonomie, amtliche datierte
E-Bilanz einschließlich Anlagen/Rückmeldungen sowie die Gesamtabnahme.
Die Veröffentlichungssperren bleiben erhalten; die nachgelagerte vollständige
Manager-Prüfung wird noch nicht ausgelöst.
