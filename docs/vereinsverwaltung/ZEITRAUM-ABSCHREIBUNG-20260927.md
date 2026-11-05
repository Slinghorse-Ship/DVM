# Abschreibungen in tatsächlichen Abschlusszeiträumen

Stand 27.09.2026. Entwicklungsstand, keine allgemeine Bilanzveröffentlichung.

## Verbundener Ablauf

Migration 104 erweitert die unveränderlichen Quellenbindungen um Abschreibungen.
Der Nachweis gehört zu **Gegenstand und Zeitraum-ID**, nicht nur zu einer Jahreszahl.
Anschaffungsrechnung und Originalplan werden vor jeder Übernahme erneut geprüft.
Bereits im selben Zeitraum gebuchte Abschreibungen können nicht doppelt übernommen
werden. Bestätigte Originalpläne dürfen nicht nachträglich überschrieben werden;
überlappende Kalenderjahres-Abschreibungsnachweise werden abgewiesen.

Lineare Pläne und bestätigte GWG verwenden den bestehenden Rechenkern für volle
Monate. Anschaffungskosten, Zugänge, kumulierte Abschreibung und Restbuchwerte sind
getrennt. Der Folgezeitraum muss an seinen bestätigten Vorgänger anschließen und
dessen Endwerte exakt übernehmen. Die bisherige kumulative Cent-Rundung bleibt
erhalten. Eine vollständig abgeschriebene Anlage wird ohne erfundene Nullbuchung
bestätigt und bleibt mit ihren ursprünglichen Kosten im Anlagenverzeichnis.

Die Bestätigung erzeugt dieselbe unveränderliche Buchungs-/Quellenhistorie wie
die Rechnungsübernahme. Sie ist erst nach Ende des tatsächlichen Zeitraums möglich.
Anlagekonto und Aufwandskonto kommen aus dem geprüften Plan und bestehenden
Zuordnungen. Der Abschluss gleicht die Anlagenwerte mit den Buchungskonten ab;
ein nicht bestätigter Stand oder eine Cent-Abweichung verhindert die Festschreibung.
Quellenänderungen sperren auch abhängige Folgezeiträume.

Die Oberfläche zeigt Buchwert am Anfang, Abschreibung im Zeitraum und Buchwert
am Ende. Technische Buchungsdetails bleiben aufklappbar. Die Bezeichnung des
Gegenstands wird im bestätigten Nachweis aufbewahrt: eine spätere Inventar-
Umbenennung verändert keine frühere Buchung oder ihren Prüfwert.
Die Arbeitsunterlagen enthalten `anlagenverzeichnis.csv` und
`anlagenverzeichnis.json` mit dem tatsächlichen Zeitraum. Kein amtlicher XBRL-Export.

## Prüfungen

- 28 neue synthetische MySQL-/Anlagen-/Oberflächenprüfungen; die zusätzlich
  eingebundenen 43 Rechnungs-/Zahlungsprüfungen sind keine neuen Anlagenfälle.
- Zehnmonatiger Erstzeitraum, sechsmonatiger Folgezeitraum, zwei Abschlüsse mit
  Endjahr 2026, mehrere Anlagen auf einem Konto, GWG, Null-Restwerte, Original-
  planänderung, doppelte Bestätigung, fremder Verein, Bank-/Anlagensaldo, Export
  und unveränderte Nachweise bei späterer Inventar-Umbenennung.
- Bestehende Zeitraum-, Rechnungsquellen-, GWG-, degressive Kalenderjahres- und
  Laufzeitbindungsprüfungen werden mit ausgeführt. Der degressive Kalenderjahrestest
  bedeutet **nicht**, dass degressive Abschreibung in neuen Zeiträumen fertig ist.
- Abschließender Lauf: `audit/ebilanz-mysql-host-20260918-8a5dc592e184b52d.json`,
  Quellpaket `a6031870a886627ad2f408984c68d029662e92c48931ce6043c19a41f4625fde`.
  Alle acht Gruppen erfolgreich, keine PHP-Diagnosen. Geschützte Produktivdateien
  unverändert, keine produktive Datenbankverbindung; temporäre Serverdateien entfernt.
- Alle neun neuen/geänderten PHP-Dateien syntaxgeprüft. Migration 104 wird
  einschließlich wiederholter Migration in der synthetischen Datenbank geprüft.
- Technische Browserprüfung der synthetischen PHP-Ansicht: Desktop und tatsächlich
  390 Pixel Inhaltsbreite, kein horizontaler Überlauf, 17-Pixel-Bedienelemente,
  Auswahl rund 50 Pixel und Knöpfe rund 51 Pixel hoch, Buchungsdetails aufklappbar,
  keine verschachtelten Formulare oder protokollierten Browserwarnungen/-fehler.
  Die Screenshot-Funktion war nicht verfügbar. Keine vollständige visuelle
  Abnahme und kein beobachteter Nutzertest. Größenänderung zurückgesetzt, eigener
  Prüftab geschlossen und ausschließlich eigener Vorschauprozess beendet.

## Verbleibende Grenzen

Kalenderjahres-Anlagenstände werden noch nicht automatisch in den neuen Ablauf
überführt. Degressive Entscheidungen, Abgänge, Sphären-/Nutzungswechsel, unterjährige
Teilmonate und berichtigte Original-Anlagenpläne benötigen ihre noch fehlende
Zeitraumanbindung. Sie werden nicht durch eine lineare Ersatzrechnung verdeckt.
Bereichsverrechnung, steuerliche Überleitung, Korrekturabschlüsse und amtlicher
Export müssen ebenfalls vollständig an die neue Zeitraum-ID angebunden werden.

Die übrigen Aufträge bleiben offen: vollständige Konten-/Sonderfallabdeckung,
allgemeine Gesamtabnahme, Veröffentlichung und danach Manager-Gesamtprüfung.
Kulturhus bleibt EÜR. Keine Produktivmigration, echte Mail, Bankanweisung oder
Steuerübermittlung. Die zweite Datenbank wird dauerhaft synthetisch verwendet;
keine wiederholte Sicherung/Rücksicherung je Testlauf.
