# Datierte Gesamtbilanz und ursprünglicher Kalenderabschluss – 28.09.2026

Die datierte gemeinsame Ausgabe enthält die unveränderte Gesamt-Bilanz/GuV
und die steuerliche Teilbilanz aller steuerpflichtigen Geschäftsbetriebe.
Zusätzlich kann deren ursprünglicher bestätigter Kalenderabschluss in tatsächliche
Folgezeiträume übernommen werden. Dies ist **kein vollständiger Bilanzrelease**.
Kulturhus bleibt EÜR. Keine Veröffentlichung, Echtabgabe, E-Mail oder Bankaktion.

## Umsetzung

- Gesamtverein und steuerlicher Teil stammen aus derselben freigegebenen
  Originalaufteilung. Beliebige Teilmengen der steuerpflichtigen Betriebe,
  steuerlich veränderte Gesamtbuchwerte und voneinander abweichende Quellen
  werden zurückgewiesen. Originalanlagen bleiben einmalig erhalten.
- Die gemeinsame Ausgabe verwendet getrennte XBRL-Kontexte und die tatsächlichen
  Zeiträume. Buch- und Steuerabschreibung bleiben getrennt. Eine steuerliche
  Korrektur verändert nicht die ursprüngliche Gesamt-Bilanz oder GuV.
- Kalender-Vorträge behalten ihre originalen Hashes, Bereichsfreigaben, Anlagen,
  Steuerpläne und Übermittlungsnachweise. Alte Abschlüsse werden nicht nachträglich
  als datierte Abschlüsse neu berechnet oder neu signiert.
- Ein kurzer Folgezeitraum verwendet die letzten eindeutigen bestätigten Werte.
  Anschaffungskosten, bereits verbrauchte Abschreibung und abgegangene Gegenstände
  werden nicht nochmals als neue Anschaffung oder laufender Aufwand behandelt.
- Eine Vorjahreskorrektur sperrt die Verwendung des ersetzten Protokolls für
  weitere Abgaben. Die historischen Ansichten, Original-XML und Freigaben bleiben
  unverändert. Ein neuer abhängiger Korrekturentwurf übernimmt die korrigierten
  steuerlichen Anfangswerte und verweist auf sein ursprüngliches Protokoll.
- Unpassende Vorabschlüsse werden vor dem Kopieren ihrer profilspezifischen
  Angaben zurückgewiesen. Ein abgewiesener Versuch verändert weder Identität
  noch Versionsnummer des Entwurfs.
- Die Eingabe bietet bei bestätigter Bereichsaufteilung die gemeinsame Ausgabe
  an. Die reine Teilbilanz bleibt eine nachgeordnete Auswahl. Darstellung und
  Anlagenansicht sprechen bei abweichenden Wirtschaftsjahren vom „Zeitraum“;
  Anschaffungskosten werden nicht durch Restbuchwerte ersetzt.
- PHP und Python rekonstruieren die Original-, Bewertungs- und Vortragsketten
  unabhängig. Auch der Kalenderadapter prüft Verein, Bereichsprüfung und
  vollständige ursprüngliche Vorjahreskette. Die Laufzeit-Fingerprints erfassen
  die neuen Adapter.
- Die Korrektursuche prüft Nachweise nacheinander, statt alle dekodierten
  Abschlussketten gleichzeitig im Arbeitsspeicher
  zu halten. Der Protokollabgleich lädt nur XML, Prüfsumme und Status; die
  Mandanten-, Freigabe- und Originalprüfungen bleiben unverändert.
- Abschlussliste und Vortragsauswahl lesen kleine Metadaten direkt aus MySQL.
  Der vollständige Originalnachweis wird erst für einen passenden Kandidaten
  geladen und geprüft. Die Metadaten sind ausdrücklich keine Freigabe.
  Fehlerhafte Datumsangaben werden nicht in einen vermeintlichen Kalenderabschluss
  umgedeutet. Die Oberfläche zeigt stattdessen „Zeitraum prüfen“.

## Gezielte Prüfungen

- `balance-period-combined-values.php`: 262 Prüfungen über sieben Varianten,
  darunter Buch-/Steuerwerte, Folgezeitraum, Storno, interne Vorschüsse,
  Kontenabgleich und veränderte Nachweise.
- `balance-period-club-calendar-values.php`: 56 Prüfungen über acht Varianten
  für reine Teilbilanz und gemeinsame Ausgabe, jeweils mit getrennten
  Steuerwerten und Folgezeitraum.
- `balance-period-combined-view.php`: 16 reine Anzeigeprüfungen für Kalender-
  und tatsächliche Zeiträume, Original-Anschaffungswerte, Zurückweisen geänderter
  Originale und HTML-Escaping. Kein echter Nutzertest und keine vollständige
  Desktop-/Handy-Sichtprüfung.
- `ebilanz-period-combined-test.py` und `ebilanz-period-club-calendar-test.py`:
  unabhängige Neuberechnung, XML-Kontexte, Anlagenfußnoten, ursprüngliche
  Kalendernachweise und gezielte Manipulations-/Profilprüfungen.
- `balance-period-combined-workflow.php` und `balance-period-combined-assets.php`:
  gespeicherte synthetische Originalquellen, Rollen, Freigaben, Korrekturen,
  geänderte Anlagennutzung, Steuerpläne und Folgezeiträume.
- `balance-period-club-calendar-assets.php`: sechs tatsächlich angelegte
  synthetische Rechnungen/Anlagen einschließlich linearer und degressiver AfA,
  GWG, Teilabgang, Ausmusterung und Softwareende; Kalenderabschluss, zwei
  Kurzzeiträume und anschließende steuerliche Vorjahreskorrektur für beide Profile.
- `tax-filing-list-memory.php`: 48 synthetische Entwürfe mit insgesamt mehr als
  24 MiB Quelldaten; begrenzter zusätzlicher Listenspeicher, genaue Zeiträume,
  Kalenderkompatibilität, beschädigte Metadaten, Rollen und Vereinsisolierung.
  Ein Aufruf zum Öffnen muss weiterhin die vollständigen Originaldaten liefern.

MySQL-Abläufe laufen ausschließlich in der dauerhaft freigegebenen synthetischen
Zweitdatenbank. Übermittlungsantworten dieser Tests sind simuliert. Native
ERiC-Prüfung mit Flag 2 ist ebenfalls **kein Übermittlungsversuch**, keine spätere
Verarbeitungsrückmeldung und keine steuerliche Anerkennung.

## Abnahmenachweise

Stand 30.09.2026:

- Alle 32 Python-E-Bilanz-Regressionsprogramme erfolgreich; PHP und Python
  rechnen die Kalender-/Zeitraumübergänge unabhängig nach.
- 561 PHP-Laufzeit-/Ansichts-/Migrationsdateien ohne Syntaxfehler.
- 27 native ERiC-Fälle mit Code 0, ausschließlich Flag 2. Darunter sechs
  unveränderte XML-Dateien aus dem gespeicherten Kalender-/Anlagenablauf:
  Teilbilanz und kombinierte Ausgabe, jeweils Erst-/Folgezeitraum und
  abhängige Kalenderkorrektur. Keine Übermittlung, kein Zertifikat/PIN.
- Der vollständige gespeicherte Kalender-/Anlagen-Korrekturtest beendet
  215 Prüfungen zuzüglich 24 wiederverwendeter Einzelbetriebsprüfungen ohne
  PHP-Diagnosen. Spitzenverbrauch: 97.374.208 Byte (rund 93 MiB), unterhalb
  der unveränderten 128-MiB-Grenze. Quellenpaket:
  `caf9cc66a07871a5e353c3598d90662df4f75642a77d1fd96937145828d0017bb`.
  Die SSH-Verbindung brach während dieses langen Tests ab; die vollständige
  Erfolgszeile, leeres Fehlerlog und sechs XML-Dateien wurden danach aus dem
  privaten Testverzeichnis gelesen. Der äußere Gesamtlauf wurde dadurch
  **nicht vollständig beendet** und wird nicht als vollständig bestanden gezählt.
  Die verbleibenden Gruppen wurden im nachstehenden Lauf gesondert wiederholt.
- Abschließender Serverlauf mit zwölf Prüfgruppen vollständig bestanden,
  ohne PHP-Diagnosen. Quellenpaket:
  `6d330c7d24d52151e0cf420dfb1222359809fad448a9954eabb8be3f583b260c`.
  Darunter 76 Listen-/Speicherprüfungen, 46 gespeicherte gemeinsame
  Abschlussprüfungen, 61 datierte Abgabeprüfungen, 27 Kalender-Gesamtvereinsprüfungen
  zuzüglich 28 wiederverwendeter Kalenderprüfungen, Korrektur-/Steuerregressionen
  sowie 206 Fingerprintprüfungen. Die vier neu erzeugten gemeinsamen Workflow-XML
  wurden anschließend nochmals mit nativem ERiC geprüft (alle 27 Fälle Code 0).
- Der Listentest verwendet 25.174.896 Byte synthetische Quelldaten. Die Liste
  benötigte keine zusätzliche PHP-Heap-Seite; das ist nicht gleichbedeutend mit
  null tatsächlichem Speicherverbrauch. Die geprüfte Obergrenze beträgt 8 MiB
  zusätzlichen Spitzenspeicher. Vollständige Originaldaten bleiben beim Öffnen
  verfügbar; beschädigte Datumsangaben bleiben sichtbar und gesperrt.
- Produktive Datenbank nicht verbunden, geschützte Produktions-Einstiegdateien
  unverändert, temporäre Dateien dieses abschließenden Serverlaufs entfernt.
  Die freigegebene synthetische Datenbank bleibt bestehen. Keine neue reguläre
  Sicherung, keine Veröffentlichung, keine EÜR-Umstellung.

Nachweise unter `audit/` (nicht öffentlicher Quellcode):
`ebilanz-mysql-host-20260918-7fcc8905d5aeb841.json`,
`ebilanz-mysql-host-20260918-391eee2dfc14d02e.json` und
`ebilanz-combined-calendar-native-20260928.json`.
Die beiden Anlagen-XML-Dateien ohne Kalenderübergang stammen aus dem früheren
bestandenen Testlauf `31bed366fade74f1`; ihre konkreten XML-Prüfsummen sind
im nativen Protokoll enthalten. Die vorstehenden Nachweise sind keine
pauschale Gesamtabnahme oder Freigabe zur Veröffentlichung.

Die beiden zunächst fehlgeschlagenen Listenläufe
`ebilanz-mysql-host-20260918-f82c5c66153863a1.json` und
`ebilanz-mysql-host-20260918-1f819babfe249180.json` bleiben als fehlgeschlagen
erhalten. Die gezielte reine SQL-Probe auf MySQL 8.4.11 zeigte: JSON-Pfadprüfungen
liefern innerhalb von JSON-Objekten einen booleschen Wert; ein an eine binäre
Abfragevariable gebundener Typname benötigt ausdrückliche UTF-8-Konvertierung.
Die Metadatenabfrage berücksichtigt beide Typen und überträgt einzelne,
typgeprüfte Datumsfelder. Ungültige oder unvollständige ursprüngliche Perioden
bleiben gesperrt.

## Verbleibender allgemeiner Release-Umfang

Die Einschränkungen für steuerliche Sphärenwechsel in den Teilbilanzprofilen
bleiben bestehen. Die ursprüngliche Gesamtbilanz mit steuerlicher Nebenrechnung
ist dafür ein eigener bereits implementierter Weg, kein pauschaler Ersatz für
weitere Methoden, frühere abweichende Steuerpläne, Abgänge oder anteilige Wechsel.
Auch vollständige Konten-/Sonderfallabdeckung, der echte synthetische
Webserver-Übermittlungstest mit zutreffender Rückmeldungsbewertung sowie
Gesamtabnahme und Veröffentlichung bleiben erforderlich.
