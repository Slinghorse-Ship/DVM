# Anlagenabgänge und historische Anlagenvorträge in Abschlusszeiträumen

Entwicklungsstand 28.09.2026. **Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

## Implementierter Umfang

- Vollständige Ausmusterung beweglicher Ausstattung ohne Erlös, ein belegter
  Teilabgang linear abgeschriebener Ausstattung und das bestätigte endgültige
  Ende einer gekauften Softwarelizenz ohne Entschädigung.
- Berechnung nach dem tatsächlichen Wirtschaftsjahr, einschließlich Rumpfjahren
  und Übergang über den Jahreswechsel. Anschaffungskosten, kumulierte Abschreibung
  und Restbuchwert des Abgangs bleiben getrennt; die Kosten werden nicht nochmals
  als voller Aufwand gebucht.
- Fortführung der bestehenden linearen, bestätigten GWG- und degressiven Pläne.
  Der Teilabgang benötigt belegte ursprüngliche Komponentenkosten und unveränderte
  Nutzungsdauer; keine geschätzte Aufteilung anhand einer Stückzahl.
- Bestätigung über die bestehende Belegübernahme, Berichtigung im offenen Zeitraum
  mit erhaltenen Originalbuchungen, centgenauer Anlagen-/Journalabgleich und
  Übernahme in die folgenden Zeiträume ohne erneuten Abgang.
- Übernahme festgeschriebener Kalender-Anlagenstände in datierte Folgezeiträume.
  Originalrechnung, Anlagenplan, frühere Abschreibungen, deren Buchungsverlauf und
  Abschluss-Prüfsumme müssen zusammenpassen. Auch bereits ausgemusterte Anlagen,
  beendete Software und der verbliebene Teil nach Teilabgang sind berücksichtigt.
  Die früheren Kalenderbuchungen und Anlagenstände werden nicht geändert.
- Oberfläche mit getrennten Angaben für Software und Teilabgang, passenden
  Dokumenten statt einer ungefilterten Gesamtliste und getrenntem Ausweis von
  Abschreibung, Restbuchwert des Abgangs und verbleibendem Buchwert.
- CSV/JSON-Arbeitsunterlagen enthalten Zu- und Abgänge sowie die Herkunft des
  Vortrags. Sie werden ausdrücklich **nicht** als amtlicher E-Bilanz-Datensatz
  oder fertige Steuererklärung ausgegeben.

## Schutz bestehender Daten

Die Vorschau schreibt nichts. Bei der Übernahme werden Berechtigung, Verein,
Zeitraum, ursprünglicher Plan, Belegrevision, Dateiprüfsumme und tatsächliche
Dateibytes nochmals geprüft. Dokumentmetadaten und Dateien werden während des
Speichervorgangs gesperrt. Leserechte reichen zum Prüfen, aber nicht zum Buchen.
Unpassende, fremde oder nachträglich geänderte Nachweise werden zurückgewiesen.
Bestehende Kalender- und Zeitraum-Sperren sowie die Produktionssperre bleiben aktiv.

Alle schreibenden Tests verwenden ausschließlich synthetische Daten in der
dauerhaft freigegebenen Testdatenbank. Keine Produktivänderung, keine echten
Mails, Bankaufträge oder Steuerübermittlungen. Für die wiederverwendbare Testdatenbank
werden keine zusätzlichen Sicherungs-/Wiederherstellungsrunden durchgeführt.

## Prüfungen

Die nachvollziehbaren Testläufe und Quellprüfsummen werden in
`ZEITRAUM-ANLAGENABGAENGE-20260928.json` festgehalten.
Die zugrunde liegenden Testdateien liegen in `vereinsverwaltung/tests`:

| Testdatei | Inhalt |
| --- | --- |
| `balance-period-disposal.php` | Abgangsmonate, Rundung, Folgezeiträume, GWG, Software, degressive Vollabgänge und Kalendervergleich der Teilkosten |
| `balance-period-disposal-ledger.php` | Originalbelege, Rollen, Mandantentrennung, Doppelsperre, Berichtigung, Festschreibung, Folgejahr, Formulardaten und Export |
| `balance-period-asset-carry.php` | Unveränderte Kalender-Originale, festgeschriebener Vortrag, vollständige/teilweise Abgänge und zwei aufeinanderfolgende Rumpfjahre |
| `balance-period-depreciation.php` | Bestehende Rechnungs-/Zahlungsübernahme und Anlagenabstimmung |
| `balance-period-degressive.php` | Degressive Periodenrechnung und Methodenwechsel |
| `balance-period-corrections.php` | Bestehende Korrekturabschlüsse und Vortragsberichtigungen |
| `asset-partial-disposal.php` | Bisherige kalenderbezogene Teilabgangsrechnung |
| `asset-sale.php` | Bisherige ausgestellte Rechnungen und kalenderbezogene Anlagenverkäufe |

Die Regressionsgruppen verwenden teilweise dieselben Basistests. Ihre Zahlen sind
nicht als Anzahl voneinander unabhängiger neuer Fachfälle zu addieren.

## Noch nicht vollständig umgesetzt oder freigegeben

1. Periodisierte Anlagenverkäufe mit zugehöriger Ausgangsrechnung, weitere
   wiederholte Teilabgänge, Schenkung/Entnahme, Wertminderung und andere
   Anlagen-Sonderfälle. Historische Verkäufe sind beim neuen Vortrag noch gesperrt.
2. Anlagen-Sphärenwechsel und periodisierte gemeinsame Nutzung, Bereichsverrechnung
   und steuerliche Überleitung. Vorhandene Kalenderfunktionen ersetzen diese
   durchgängige Integration nicht.
3. Vollständige Konten- und Steuersonderfallabdeckung sowie amtlicher Export für
   abweichende Wirtschaftsjahre und Rumpfjahre.
4. Korrektur festgeschriebener Anlagenwerte mit revidierten Originalnachweisen,
   vollständige Gesamtabnahme einschließlich Übermittlungsrückmeldungen und
   Veröffentlichung. Die neue Berichtigung im **offenen** Zeitraum ist davon zu
   unterscheiden.

Die gewünschte umfassende Nachprüfung des gesamten Vereinsmanagers bleibt bis
zur nachgewiesenen vollständigen Veröffentlichung zurückgestellt. Eine technische
Desktop-/Handyprüfung dieser neuen Maske ist kein echter Nutzertest und keine
Abnahme der gesamten Bilanzierung.
