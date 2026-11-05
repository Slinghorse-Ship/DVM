# Teilabgang: Belegprüfung separat abgenommen am 20.09.2026

Fortsetzung: [Gespeicherte Prüfstände und Folgejahresvorschau](TEILABGANG-SPEICHERUNG-20260920.md).
Die nachfolgenden Zahlen dokumentieren die ursprüngliche Belegprüfungs-Abnahme.

`AssetPartialDisposalReview.php` prüft jetzt eine erste, nachgewiesene
Teil-Ausmusterung ohne Erlös für einen linearen Ausstattungsgegenstand. Der Dienst
ist **nicht veröffentlicht**, nicht im Bootstrap und ohne öffentliche Route.
Er verändert weder Belege noch Anlagenpläne, Jahreswerte oder Buchungen.
`postable` und `exportable` bleiben ausdrücklich `false`.

## Geprüfter Umfang

- Finanz-Schreibrecht und erforderliche Dokument-/Inventarrechte; ein entsprechend
  berechtigter Kassenwart benötigt weder Superuser- noch Einstellungsverwaltung.
  Fremder Verein, reines Finanz-Leserecht, Redaktion und fehlende Nachweisrechte
  werden abgewiesen.
- Bilanzfunktion muss ausdrücklich freigeschaltet sein, Rechnungslegung Bilanz,
  Kalenderwirtschaftsjahr. EÜR und abweichende oder beschädigte Jahresangaben
  werden nicht stillschweigend umgedeutet.
- Genau eine Anschaffungsposition, unveränderter ursprünglicher Anlagenplan und
  tatsächlicher Quellen-/Journalverlauf einschließlich Korrekturhistorie.
- Verknüpfte aktive Originale für Ausmusterung und Teilkosten, genaue Dateiversionen
  und SHA256 über die tatsächlichen Bytes auf dem Server. Ein separater
  Teilkostenbeleg ersetzt die Prüfung des ursprünglichen Anschaffungsbelegs nicht.
- Lückenlose vorherige Anlagenjahre und geprüfte festgeschriebene Bilanzabschlüsse.
  Schon bestätigte Abgangsjahre, andere Abgänge und geänderte Prüfdigests stoppen
  den Vorgang. Eine fremde laufende Transaktion wird weder beendet noch verworfen.
- Vollständig abgeschriebene Gegenstände behalten ihre Anschaffungskostenhistorie.
  Jahre ohne AfA benötigen keinen erfundenen Null-Euro-Buchungssatz; bestätigte
  Jahreswerte und Abschlussnachweise bleiben erforderlich.

Die tatsächliche Höhe der Teilkosten muss weiterhin anhand der Originale fachlich
bestätigt werden. Die Prüfung kann die Beleginhalte nicht selbst als wahr bestätigen.
Sie erlaubt keine mengen- oder prozentbasierte Schätzung.

## Tests und Produktionsschutz

Abschließender Serverlauf:
`audit/ebilanz-mysql-host-20260918-c542749f81c10776.json`
(der historische Dateipräfix des Testoperators bleibt unverändert).

Auf PHP 8.3.33 / MySQL 8.4.11 bestanden:

- 133 Prüfungen des neuen Belegdienstes;
- 4 Prüfungen der Schema-Wiederherstellung;
- 105 bestehende Prüfungen gemischter Abgänge und getrennter Steuerwerte;
- 140 bestehende Rechnungs-/Anlagenverkaufsprüfungen.

Die separate Datenbank wurde vorher verschlüsselt gesichert und danach identisch
wiederhergestellt: 150 Tabellen / 1.393 Datensätze, einschließlich Definitionen und
Triggern. Temporäre Testdateien wurden entfernt. Keine Verbindung zur produktiven
Datenbank, keine Änderung der Live-Dateien, keine Mail, Bankaktion oder ELSTER-Abgabe.
Die Versandzustände bestehender Steuerfixtures sind ausschließlich Offline-Simulation.

Lokal erneut bestanden: 6.941 Teilabgangs-Rechenprüfungen, sechs Python-Testmethoden
mit 600 unabhängig berechneten Fällen, 282 Gegenkonten-, 129 Ausmusterungs-,
91 Bilanz- und 23 Anlagenverzeichnisprüfungen sowie PHP-Syntaxprüfungen.
Ein versehentlicher direkter Start des MySQL-Tests `balance-tax-mixed-disposals-zero.php`
ohne Testdatenbank wurde durch dessen Schutzprüfung abgebrochen und zählt nicht
als bestandener Test dieses Durchlaufs.

Die früheren Testaufbaufehler sind behoben: gültiges Fälligkeitsdatum,
Dokumentzuordnung und zulässige Statuswerte; bei künstlich beschädigten
Originaldateien wird der native Unveränderlichkeitstrigger nur in der ausdrücklich
freigegebenen synthetischen Datenbank kurzzeitig entfernt und im `finally` identisch
wiederhergestellt. Dessen Schutz wird vor der Manipulation zusätzlich geprüft.
Die Testprotokolle der Fehlversuche bleiben als Entwicklungshistorie erhalten.

## Noch keine vollständige Teilabgangsfunktion

Als Nächstes gemeinsam implementieren und testen: unveränderliche Ereignisablage,
atomare Jahres-/Folgebuchungen, Journal-/Steuerüberleitung, Exportvalidator und
Oberfläche. Auch weitere Abgangsarten und mehrere Teilabgänge sind nicht durch
diesen Prüfdienst abgedeckt. Bestehende Buchungsrouten lehnen `partial_scrap`
weiterhin ausdrücklich ab. Kein Nachweis für eine vollständige E-Bilanz oder eine
amtliche Verarbeitung; die Produktivfreigabe bleibt aus.
