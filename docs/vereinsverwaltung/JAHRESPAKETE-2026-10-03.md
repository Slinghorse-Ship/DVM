# Geprüfte Jahrespakete für Kontenvergleich und E-Bilanz

Entwicklungsstand vom 03.10.2026; keine vollständige Bilanzfreigabe. Kulturhus
bleibt EÜR. Produktivkonfiguration, Buchungen und historische Jahresausgaben
werden durch diese Erweiterung nicht geändert.

## Warum ein jährliches PDF nicht genügt

Die amtliche Veröffentlichung unterscheidet XBRL-Taxonomie und ERiC-Verfügbarkeit.
Taxonomie 6.10 ist am 03.10.2026 veröffentlicht. Testübermittlungen sind laut
Veröffentlichung voraussichtlich ab dem ERiC-Release November 2026, Echtfälle ab
Mai 2027 möglich. Das ist keine Aussage zur Unterstützung in dieser Anwendung.
Quelle: [Amtliche Taxonomien und Veröffentlichungshinweise](https://www.esteuer.de/),
abgerufen am 03.10.2026. Die Verbindlichkeit des Datensatzes ergibt sich aus
den amtlichen XBRL-Paketen, nicht aus der DATEV-PDF oder einer Tabellenansicht.

Die bestehenden, versionierten Kontenzwecke bleiben erhalten. Ein unveränderter
Zweck kann im Folgejahr anhand des geprüften Quellkontexts wiederverwendet werden.
Sachentscheidungen und neue Steuerregeln werden nicht aus Textähnlichkeit erzeugt.
Normale Kassenwarte beantworten konkrete Sachfragen; XML-Ziele pflegt die
Softwareadministration.

Ein erneuter Vergleich der quellengebundenen Originalausgaben 2025 → 2026 mit
dem aktiven Vorjahresstand übernimmt **1.154 Kontenzuordnungen automatisch**.
70 Konten stehen in 61 fachlichen Änderungsgruppen; 455 schon vorher ungeklärte
Zuordnungen bleiben offen, 18 Vorjahreskonten entfallen. Das ist das Ergebnis
dieses Vergleichs, nicht die Abdeckung der bereits bestehenden Ausgabe 2026-r19.
Es werden keine Jahresregeln oder Buchungen aktiviert. Der versiegelte Vergleich
liegt in `audit/annual-package-real-comparison-20261003.json`.

## Umsetzung

`resources/ebilanz/releases.json` enthält explizit ausgewählte Pakete mit:

- Paketkennung, tatsächlich implementiertem Adapter und erlaubten Jahren;
- amtlicher Taxonomieversion, Datum, Quelladresse und normalisierter Prüfsumme;
- ERiC-Datenart und getrennt geprüften Produktversionen der API und des
  Bilanz-Prüfmoduls;
- ausdrücklich begrenztem Status `catalog_and_local_validation` und
  `production_authorized=false`.

`ebilanz_release.py` prüft diese Bindungen. Die implementierten Adapterfähigkeiten
begrenzen die JSON-Auswahl zusätzlich: Ein Eintrag für 2027 in einer Jahresliste
implementiert keine Regeln für 2027. Fehlende, doppelte, beschädigte und
widersprüchliche Pakete werden zurückgewiesen. Die gegenwärtige Grenze bleibt
**Taxonomie 6.9 / 2025–2026**. Der vorhandene Generator bleibt der Adapter für
6.9; ein künftiger Adapter benötigt weiterhin Entwicklung und Prüfung.

Der Jahresvergleich bindet den Paketstand in seiner versiegelten Vergleichsdatei.
Die bestätigte Jahresausgabe und der Paketmanifest übernehmen diesen Nachweis.
Die Freigabe berechnet den Vergleich neu. Änderungen am Paket entwerten die
ältere Prüfdatei, auch wenn Kontennummern und DATEV-PDF unverändert sind.

Eine gespeicherte Prüfung kann über „Vergleich aktualisieren“ erneut mit dem
aktuellen Programmstand verglichen werden, auch wenn bereits ein Kandidatenpaket
vorliegt. Verwendet wird das unveränderte gespeicherte Original; ein erneuter
Download ist nicht nötig. Das bisherige Paket wird nicht mehr als aktuelles
Paket angeboten. Alte Dateien, Versionen und Auditereignisse bleiben gespeichert.
Der neue Vergleich verlangt eine neue Prüfdatei und ausdrückliche Bestätigung;
alte Formulare scheitern am Versionsabgleich.

Der XML-Generator verlangt dasselbe Paket. Die native ERiC-Brücke prüft vor
`EricBearbeiteVorgang` zusätzlich das tatsächliche Abschlussjahr, die XBRL-
Schema-Verweise und die lokal gemeldeten API-/Bilanz-Prüfmodulversionen. Ein
abweichender oder neuerer Prüfer gilt nicht automatisch als geprüft. Der
ausgelesene lokale Stand lautet für beide Komponenten **44.3.4.0**; die
Versionsnummer eines daneben liegenden Dokumentationsordners ist kein Nachweis
für die tatsächlich geladene Bibliothek.

Paketdatei und Prüfcode gehören zum Laufzeit-Fingerabdruck der Steuerfreigabe.
Änderungen machen eine alte Freigabe ungültig. Bestehende Testumgebungs-, Rollen-,
Zertifikats- und Übermittlungssperren gelten weiterhin.

## Pflege einer neuen Version

Die Administration erstellt weiterhin einen isolierten Jahresvergleich und
prüft geänderte Quellfamilien. Die Softwarepflege prüft zusätzlich das getrennt
veröffentlichte XBRL-Paket und dessen Änderungen, erweitert nötigenfalls den
Adapter und die Zweckregeln und prüft das tatsächlich verwendete ERiC-Paket.
Erst ein anschließend geprüfter Programmstand erhält eine neue Paketkennung.
Alte DATEV-Quellen, Zweckrevisionen, Originalbuchungen und Abgaben bleiben erhalten.

Die derzeitige Oberfläche liefert weiterhin Prüfdateien und Kandidatenpakete.
Eine vollständig durchgängige administrative Aktivierung neuer Programme ist
damit noch nicht implementiert. Auch das native Prüfpaket ersetzt weder die
offene Fachabdeckung noch Testübermittlung, Rückmeldungen oder Gesamtfreigabe.

## Prüfungen und verbleibende Abnahme

Elf neue Offline-Testmethoden prüfen die gemeinsamen Jahresgrenzen, manipulierte
Quellen, fehlende/doppelte Pakete, ungeprüfte SDK-Wechsel, Paketwechsel zwischen
Vergleich und Bestätigung sowie die Sperre vor nativer Validierung/Transport.
Die Paketprüfung erfolgt pro Vorgang, nicht für jede einzelne Kontonummer erneut.
Eine Änderung während des Vergleichs verwirft das Ergebnis. Eine synthetische
Familie aus 500 Konten benötigt höchstens vier Paketprüfungen.
236 PHP-Fingerprint-Prüfungen bestehen; die geänderten PHP-Laufzeitdateien
und das erweiterte Administrations-Testprogramm sind syntaktisch geprüft.
Zusätzlich bestehen neun Prüfungen der tatsächlichen PHP/Python/PDF-Kette mit
dem unveränderten öffentlichen DATEV-Original 2026: vollständige Extraktion,
Hinweise, Jahresvergleich, bestätigter unvollständiger Kandidat und Bereinigung
des privaten Arbeitsordners. Dieser Test benötigt keine Datenbank und führt
keine Übermittlung aus. Bericht: `audit/annual-package-php-process-20261003.json`.
Der MySQL-Test `catalog-imports.php` besteht mit 84 Service- und Ansichtsprüfungen:
erneuter Vergleich,
unveränderte Historie, gesperrte alte Pakete, Versionskonflikte und neue
Bestätigung sind geprüft. Drei native Positivfälle (2025, 2026 und gemischte
Anlagen) bestehen; eine absichtlich verfälschte Bilanzsumme wird von ERiC abgewiesen.
Dabei werden nur synthetische Daten und Flag 2 verwendet, ohne Zertifikat oder
Übermittlung. Bericht: `audit/annual-package-native-20261003.json`.

Weitere 26 native Fälle prüfen tatsächliche Zeiträume, Anlagen, Buch-/Steuerwerte,
Gesamtverein, Nebenrechnung und Kalenderbrücken. Fünf native Korrekturstände
prüfen die kombinierte Gesamt-/Steuerbilanz. Alle 31 Fälle bestehen mit Code 0,
Flag 2 und ohne Transport. Berichte: `audit/annual-package-period-native-20261003.json`
und `audit/annual-package-combined-native-20261003.json`. Zusammen sind das
34 native Positivfälle und die erwartete Abweisung einer verfälschten Bilanzsumme.

Eine zusätzliche Prüfung auf dem Linux-Host bestätigt die neue Brücke mit dem
originalen Linux-SDK: drei Positivfälle (2025, 2026, gemischte Anlagen), die
erwartete Abweisung einer verfälschten Summe und die Sperre für 2027 vor dem
nativen Prüfvorgang. Beide Komponenten melden auch dort **44.3.4.0**.
Kein Datenbankzugriff, Zertifikat oder Transport; die geschützten Produktivdateien
sind unverändert, das eigens angelegte private Prüfverzeichnis ist entfernt.
Bericht: `audit/annual-package-linux-20261003.json`. Ein erster unvollständiger
Lauf scheiterte im Negativfall des Prüftreibers und ist getrennt aufbewahrt;
der vollständige Nachlauf ist bestanden. Der Anwendungscode wurde dafür nicht
geändert.

Die vollständige lokale Inventur mit 45 Offlineprogrammen ist bestanden,
ohne Änderungen der geprüften Quelldateien während des Laufs. Bericht:
`audit/annual-package-offline-20261003.json`. Die mitgeführte
[Nachweiszusammenfassung](JAHRESPAKETE-2026-10-03.json) enthält Quellhashes,
Berichtsprüfsummen und die getrennten Prüfstände.
Der vorherige Serverlauf `d725ab22c6bc07ed` ist beendet: 162 von 163 Programmen
bestanden, eine veraltete Erwartung in `balance-vat-mapping.php` schlug fehl.
Der Test behandelte drei bereits in Revision 16 geprüfte Zwecke noch als
unbekannt. Die Korrektur prüft ihre konkreten Ziele und weiterhin die fehlende
Berechtigung, daraus einen inländischen Umsatzsteuersatz abzuleiten. Die
Anwendungsregeln wurden dafür nicht geändert. Der ursprüngliche Bericht bleibt
`passed=false`.

Erst nach Ende und Bereinigung des Vorgängers wurde der eigene Nachlauf
`0a73a3ceb614155d` gestartet. **Alle sieben Programme bestanden:** der korrigierte
Umsatzsteuer-Test (64 Prüfungen), gespeicherte Administration (84), tatsächliche
PDF-Prozesskette (9), Jahreskatalog (40), Abschlussrubriken (41), Kontenzuordnung
(3.066) und Laufzeit-Fingerabdruck (236). Bericht:
`audit/ebilanz-mysql-host-20260918-0a73a3ceb614155d.json`.
Geschützte Produktivdateien blieben in beiden Läufen unverändert; die jeweiligen
temporären Testkopien sind entfernt. Nur die gesonderte synthetische Datenbank
wurde verwendet, ohne neue Vollsicherung und ohne Übermittlung.

Diese getrennten Nachweise sind kein erfundener erneuter 163-Programme-Lauf
auf dem neuen Stand und keine allgemeine Bilanzfreigabe. Der Anwendungscode
ist seit dem bestandenen 45-Programme-Lauf unverändert; danach wurde nur die
belegte veraltete PHP-Testannahme berichtigt und gezielt erneut geprüft.
