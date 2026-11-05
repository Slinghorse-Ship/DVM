# Anlagennutzung und Bereichsvortrag in tatsächlichen Abschlusszeiträumen

Stand: 28.09.2026. Entwicklung, noch kein allgemeiner Bilanzrelease.
Kulturhus bleibt EÜR; keine produktive Datenänderung oder Veröffentlichung.

## Anbindung

- Ein bestätigter Kalenderabschluss kann einschließlich seiner tatsächlich
  bestätigten Bereichsaufteilung als Vorzeitraum verwendet werden. Eine leere
  Vorschussfreigabe ist nicht zusätzlich nötig, wenn keine Vorschüsse existieren.
  Existiert eine Verrechnungsprüfung, darf eine ältere einfache Bereichsfreigabe
  sie nicht umgehen.
- Korrigierte Kalenderabschlüsse entwerten abhängige Bereichsprüfungen.
  Die ausdrückliche Neuabstimmung behält Originalabschluss, Originalfreigaben
  und alte Anfangsbestände im unveränderlichen Verlauf.
- Die Oberfläche übernimmt vorhandene Bereiche und Betriebe des Vorzeitraums.
  Abweichende und kurze Zeiträume mit identischem Endjahr bleiben getrennt.
- Gemeinsam verwendete Anlagen behalten ihren Originalplan. Anteile sind eine
  interne Nutzungsauswertung, keine neuen Gegenstände und keine neuen GWG.
  Abschreibung wird kumuliert aufgeteilt; vorhandene Restcent werden nicht in
  späteren Zeiträumen neu verteilt.
- Die gespeicherte Aufteilung bindet Anlagenstand, Nutzungsmaßstab, tatsächliche
  Journalzeilen, Vorstand und vollständigen Vorzeitraum. Fehlende oder abweichende
  Werte verhindern die Freigabe. Ergebnisabgleich und Arbeitsunterlagen enthalten
  denselben Nachweis, einschließlich Anschaffungskosten, Abschreibung, Abgängen
  und Restbuchwerten je Bereich.
- Bei einem einzelnen passenden Bereich wird die Nutzung vorgeschlagen. Geteilte
  Nutzung bekommt eine eingeklappte Eingabe mit benannten Vereinsbereichen und
  nachvollziehbarem Nutzungsmaßstab. Leseberechtigte erhalten keine Schreibaktion.
- Verkäufe verwenden die im Original gespeicherten Gegenkonten. Ein zweites Konto
  mit derselben SKR42-Nummer darf keine fremde Kontenzuordnung erzwingen.

## Grenzen

Die ursprüngliche Nutzungsauswertung gilt für unveränderte Anteile innerhalb
derselben steuerlichen Sphäre. Die anschließende Erweiterung
[Datierte Nutzungswechsel](ZEITRAUM-NUTZUNGSWECHSEL-20260928.md) ergänzt belegte
Änderungen zu Monatsbeginn. Echte steuerliche Sphärenwechsel, taggenaue Änderungen
und weitere steuerliche Anlagenfälle bleiben gesondert zu entwickeln. Sie werden
nicht durch Änderung alter Freigaben oder Buchwerte vorgetäuscht.

Ein Anlagen-/Ergebnisnachweis ist keine steuerliche Überleitung, keine
Übermittlungsfreigabe und kein amtlicher Anlagenspiegel. Die eigenständigen
Release-Sperren bleiben aktiv. Die anschließende
[steuerliche Nebenrechnung für tatsächliche Zeiträume](ZEITRAUM-STEUERWERTE-20260928.md)
ist gesondert dokumentiert. Vollständige Konten-/Sonderfallabdeckung,
amtliche Ausgabe und Gesamtabnahme bleiben eigenständige Release-Voraussetzungen.

## Prüfung

Rechenprüfungen und echte PHP-/MySQL-Serviceabläufe verwenden ausschließlich
synthetische Daten in der dafür freigegebenen Testdatenbank. Originale,
Quellenkorrekturen, Verkäufe, Teilabgänge, kurze Folgeperioden und Kalenderübergang
werden getrennt von der Produktion geprüft. Echte Mails, Bankaufträge oder
Steuerabgaben werden nicht ausgelöst.

Zwölf gemeinsame Testgruppen bestanden im Lauf
`audit/ebilanz-mysql-host-20260918-625911fe38322b1b.json`:
95 Anlagenaufteilungs-, 109 Abgangs-, 160 Verkaufs-, 165 Kalenderanlagen-,
56 Bereichs- und 34 Bereichsvortragsprüfungen sowie die bestehenden
Rechen-/Schemaschutzgruppen. Wiederverwendete Ausgangsprüfungen sind in den
jeweiligen Testausgaben gesondert ausgewiesen; keine Addition zu vermeintlich
unabhängigen Geschäftsfällen. Quellpaket:
`99c6060856c39001d525e91e816dd325e6afe7941f701f7f7fe4554d6d913aa8`.
Dieser Lauf hatte nach der erfolgreichen Suite einen falschen Dateinamen beim
Abruf der UI-Testseite; er ist deshalb kein vollständiger Oberflächen-Nachweis.

Nach Korrektur des Abrufs bestand der gemeinsame Nachlauf
`audit/ebilanz-mysql-host-20260918-9e1229dc9d3668ce.json` mit sieben Gruppen,
darunter 96 Anlagenaufteilungsprüfungen einschließlich lesbarer Einzahl der
Nutzungsangabe. Beide tatsächlichen PHP-Testseiten wurden abgerufen. Die
geschützten Produktivdateien blieben unverändert; private Testdateien wurden entfernt.
Der historische Dateinamen-Präfix bezeichnet nicht das heutige Testdatum.

Die technische Sichtprüfung der synthetischen Seiten umfasste Desktop und
Handy, Belegsuche mit Tastaturauswahl, Nutzungsfelder, aufgeklappte Abschnitte
und Betragseingabe. Erster geprüfter Stand: 1265×900 und 390×844 ohne horizontalen
Überlauf. Nach den reinen Beschriftungskorrekturen sind Auswahl und sichtbare
Feldtexte nochmals per Browser-DOM geprüft. Die erneute Bildschirmaufnahme war
technisch nicht verfügbar; sie wird nicht als zusätzlicher Screenshot-Nachweis
behauptet. Es wurde keine Browser-Formularbuchung abgesendet.
Rollen-/POST-Prüfungen liefen separat gegen PHP/MySQL. Kein echter Nutzertest,
keine fachliche Gesamtabnahme und keine vorgezogene Manager-Gesamtprüfung.
