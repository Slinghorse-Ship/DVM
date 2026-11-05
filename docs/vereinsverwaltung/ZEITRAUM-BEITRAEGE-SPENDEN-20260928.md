# Mitgliedsbeiträge und Geldspenden in tatsächlichen Abschlusszeiträumen

Stand 28.09.2026. Entwicklung; **kein allgemeiner Bilanzrelease**. Kulturhus bleibt EÜR.

## Verbundener Ablauf

- Bereits festgesetzte echte Jahres-/Familienbeiträge verwenden ihren damaligen
  Beitragssatz, das Beitragsjahr, den Zahler und die tatsächlich erfassten Mitglieder.
  Eine Familienforderung wird nicht je Person vervielfacht. Eine spätere Tarifänderung
  ändert die alte Forderung nicht. Ein Lieferanten-PDF wird dafür nicht verlangt.
- Belegjahr, Beitragsjahr und Ende des Wirtschaftsjahrs bleiben getrennt. Ein im
  Dezember versandter Beitrag für das Folgejahr wird nicht nochmals im Versandjahr
  vorgeschlagen. Das tatsächliche Entstehungsdatum der Forderung wird geprüft;
  es muss im Beitragsjahr und im ausgewählten Abschlusszeitraum liegen.
- Das Erlöskonto 40000 und der ideelle Bereich werden aus dem nachgewiesenen echten
  Beitrag abgeleitet. Zahlungseingänge gleichen nur die Forderung aus. Teil- und
  Restzahlungen funktionieren auch über den nächsten Rumpfzeitraum hinweg.
- Freie Geldspenden werden aus den tatsächlich zugeordneten Zahlungseingängen
  übernommen, nicht aus unbezahlten Spendenzusagen. Sie bilden keine künstliche
  Forderung. Gemischte Zahlungen mit Beiträgen sowie mehrere Zuordnungszeilen
  derselben Spende werden centgenau und ohne doppelte Ertragsbuchung aufgeteilt.
- Die Frage nach besonderer Zweckbindung oder Gegenleistung erscheint vor der
  Übernahme. Nur ausdrücklich bestätigte freie Geldspenden erhalten Konto 40400.
  Unklare, zweckgebundene, steuerpflichtige oder zurückgezahlte Fälle werden nicht
  durch diese Ableitung freigegeben. Sach-/Aufwandsspenden bleiben gesonderte Fälle.
- Der Originalkatalog bleibt dokumentiert. Für Konto 40400 wird die geprüfte reine
  Schreibabweichung 2025/2026 (Leerzeichen um den Schrägstrich) berücksichtigt, ohne
  die Kataloge, Originalpositionen oder frühere Belege umzuschreiben.

Aufwand/Ertrag und Zahlung dürfen bei Bilanzierung nicht gleichgesetzt werden.
Zeitliche Abgrenzungen bleiben gesondert zu beurteilen; daraus folgt keine pauschale
automatische Verteilung aller Mitgliedsbeiträge. Grundlage dieser Trennung:
[§ 252 HGB](https://www.gesetze-im-internet.de/hgb/__252.html) und
[§ 250 HGB](https://www.gesetze-im-internet.de/hgb/__250.html).

## Änderungen, Oberfläche und Nachweise

Änderungen an einer Spende entwerten sämtliche betroffenen Teilzahlungsnachweise.
Solange auch nur eine Übernahme ungeprüft ist, bleibt der Abschluss gesperrt.
Berichtigungen offener Zeiträume behalten Original und Gegenbuchung. Nach einer
Festschreibung werden geänderte Originale beanstandet und nicht automatisch ersetzt.
Spätere unveränderte Teilzahlungen ändern den früheren Abschluss dagegen nicht.

Die normale Eingabe fragt „Beitragsforderung entstanden am“ statt nach einer
Lieferung. Geldspenden verwenden vollständig lesbare Ja-/Nein-Auswahlfelder; eine
veraltete Antwort lässt sich ausdrücklich zurücksetzen. Eine geänderte Antwort
entwertet wie jede geänderte Belegauswahl die alte Vorschau. Technische Kontierung
bleibt unter der Buchungsansicht eingeklappt. Export-Arbeitsunterlagen erhalten
Originalbezug, Beitragsfestsetzung und beide Katalogbezüge.

## Prüfung

Die Regression `balance-period-noninvoice.php` prüft zwei aneinandergrenzende
Wirtschaftsjahre, alte und vorab ausgestellte Beiträge, Familien, gemischte
Zahlungseingänge, Teilzahlungen, Änderungen, veraltete Vorschauen, Fremdvereine,
Berechtigungen, echte gerenderte Formulare, Export und unveränderte Originaldaten.
Sie wird gemeinsam mit den bisherigen Kalender-Beitrags-/Spenden-, Rechnungs-,
Perioden-, Korrektur-, Anlagenverkaufs- und Dokumententests ausgeführt.

Der genaue abgeschlossene Prüfstand steht in der begleitenden JSON-Datei. Tests
umfassen zehn bestandene Gruppen, davon 64 neue Zeitraum-Beitrags-/Spendenprüfungen.
Die synthetische gerenderte Maske wurde auf Desktop und bei 390 Pixel Breite geprüft:
vollständige Antwortbeschriftungen, kein horizontaler Überlauf und ausgeblendete
alte Übernahmeaktion nach Antwortänderung. Kein echter Nutzer- oder Gerätetest.
Tests
schreiben ausschließlich synthetische Daten in die freigegebene zweite MySQL-Datenbank.
Keine produktive Datenbankverbindung und keine echten Mails, Bankaufträge oder
Steuerübermittlungen. Die neue Funktion bleibt bis zur Gesamtabnahme produktiv gesperrt.

## Weiterhin keine allgemeine Freigabe

Diese Anbindung schließt nicht alle anderen offenen Bilanzteile: weitere
Konten-/Steuer- und Anlagen-Sonderfälle, Sphärenwechsel, allgemeine Bankbewegungen
ohne Belegzuordnung, Zeitraum-Anbindung von Bereichsverrechnung/gemeinsamen Anlagen
und steuerlicher Überleitung, vollständige amtliche Zeitraum-/Korrekturausgabe sowie
Gesamtabnahme und Veröffentlichung bleiben erforderlich. Die Nachprüfung des ganzen
Vereinsmanagers startet erst nach dem vollständigen Release.
