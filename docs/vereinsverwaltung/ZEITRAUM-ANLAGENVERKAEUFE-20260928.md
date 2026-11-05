# Anlagenverkäufe, alte offene Rechnungen und verständlichere Eingabe

Entwicklungsstand 28.09.2026. **Kein allgemeiner Bilanzrelease. Kulturhus bleibt EÜR.**

## Umgesetzter Ablauf

1. Einen vollständigen Verkauf beweglicher Ausstattung mit genau einer bereits
   ausgestellten inländischen Verkaufsrechnung verknüpfen. Unterstützt sind die
   bereits geprüften 19-%-Fälle und belegte Umsätze ohne Umsatzsteuer. Teilverkäufe,
   andere Steuersätze und weitere Sonderfälle werden dadurch nicht freigegeben.
2. Abschreibung und Restbuchwert aus dem tatsächlichen Abschlusszeitraum berechnen.
   Bei degressiver Abschreibung wird nicht auf eine abweichende Kalenderrechnung
   zurückgegriffen. Anschaffungskosten und bisherige Abschreibungen werden getrennt
   ausgebucht; das Ergebnis ist der Nettoerlös abzüglich des Restbuchwerts.
3. Passende SKR42-Erlös-/Buchwertkonten aus Gewinn oder Verlust ableiten. Die
   Originalrechnung, ihre PDF/XML-Datei und ihre ursprünglichen Positionen bleiben
   unverändert. Die abgeleitete Buchungszuordnung steht im beleggebundenen Journal.
4. Verkaufsrechnung übernehmen; eine spätere zugeordnete Zahlung gleicht nur die
   Forderung aus. Sie erzeugt keinen zweiten Erlös. Folgezeiträume übernehmen den
   verkauften Gegenstand mit null, ohne erneute Abschreibung oder neuen Verkauf.

Migration 106 ergänzt eine unveränderliche, vereinsgebundene Zuordnung zwischen
Gegenstand, Verkaufsrechnung und ursprünglichem Zeitraumereignis. Ein Verkauf kann
nicht stillschweigend entfernt oder für einen zweiten Gegenstand verwendet werden.
Berichtigungen des offenen Zeitraums erhalten alle früheren Buchungen und verlangen
eine erneute Prüfung der betroffenen Rechnung und Zahlung.

## Übergang aus vorhandenen Kalenderabschlüssen

- Offene Eingangs-/Ausgangsrechnungen und Erstattungen an privat vorleistende
  Mitglieder können nach einem bestätigten Kalenderabschluss in den tatsächlichen
  Folgezeiträumen bezahlt werden. Teilzahlungen und Restzahlungen mindern ausschließlich
  die vorgetragenen Forderungen bzw. Verbindlichkeiten; alte Aufwendungen und Erträge
  werden nicht nochmals gebucht.
- Auch bereits im Kalenderabschluss verkaufte Anlagen werden nach Prüfung ihrer
  Originalnachweise weitergeführt. Die alten Verkaufswerte bleiben als Herkunftsnachweis
  erhalten, nicht als neuer Verkauf des Folgezeitraums.
- Originalrechnung, Anlagenplan, Abschreibungs-/Verkaufsnachweis, Buchungsverlauf,
  bestätigter Abschluss und aufeinanderfolgende Zeiträume müssen zusammenpassen.
  Veränderte Belege oder nicht mehr gültige Freigaben blockieren die Übernahme.
  Historische Kalenderdatensätze werden nicht umgeschrieben.

## Bedienung

Die Eingabe trennt **Zeitraum auswählen – Belege prüfen – Abschluss bestätigen**.
Rechnungen, Zahlungen und Anlagen werden über die vorhandene Suchauswahl gefunden.
Kontokennungen sowie zusätzliche Soll-/Haben-Buchungen stehen zusammengeklappt unter
„Erweiterte Buchungen“, nicht mitten im normalen Belegablauf. Nach einer geänderten
Auswahl oder Datums-/Abgangsangabe wird die alte Vorschau ausgeblendet und ihre
Bestätigung gesperrt, bis die Angaben neu geprüft wurden.

Verkaufsrechnung, Datum, Restbuchwert, Umsatzsteuer und Gewinn/Verlust sind gemeinsam
sichtbar. Spezielle Felder für Teilabgang oder Software erscheinen nur beim passenden
Vorgang. Die ursprünglichen Nachweise und alle Prüfungen bleiben serverseitig zwingend;
eine vereinfachte Oberfläche ersetzt keine fachliche Prüfung unklarer Sachverhalte.

Desktop-/Handyprüfung dieser synthetischen Maske ist eine technische und heuristische
Prüfung, **kein Test mit einem tatsächlich unerfahrenen 65-jährigen Menschen**. Die
verbindliche Nachprüfung des gesamten Vereinsmanagers beginnt weiterhin erst nach dem
vollständigen Bilanzrelease.

## Arbeitsunterlagen und Prüfungen

Die Arbeitsunterlagen enthalten das Anlagenverzeichnis, die Originalbezüge und für
Verkäufe des jeweiligen Zeitraums `anlagenverkaeufe.csv` mit Brutto, Steuer, Netto,
Restbuchwert, Ergebnis und SKR42-Konten. Historische Verkäufe werden nicht nochmals
als aktuelle Verkäufe exportiert. Diese Unterlagen sind **keine amtliche E-Bilanz**.

Reproduzierbare Nachweise und Quellprüfsumme stehen in
`ZEITRAUM-ANLAGENVERKAEUFE-20260928.json`. Die Tests decken insbesondere ab:

- Gewinn-/Verlustfälle mit und ohne Umsatzsteuer, lineare/degressive Abschreibung,
  Rumpf-/abweichende Jahre, spätere Rechnungsstellung bei früherer Lieferung;
- Kalender- und Zeitraumvorträge, private Erstattung, Teil-/Restzahlung, historische
  Verkäufe, zwei aufeinanderfolgende Rumpfjahre und unveränderte Originaldaten;
- Rollen, Vereinsgrenzen, veraltete Bestätigungen, beschädigte Metadaten,
  unveränderliche Zuordnungen, Dokumentenschutz und wiederholbare MySQL-Migration;
- bestehende Anlagenabgänge, Korrekturabschlüsse, Kalenderverkäufe, Schema-Restore
  und Steuer-Laufzeitprüfsummen; einige Gruppen verwenden dieselben Basistests;
- Ausblenden veralteter Vorschauen und Rücknahme ihrer Bestätigungsmöglichkeit.

Der abschließende gemeinsame Lauf bestand alle elf Testgruppen ohne PHP-Diagnostik.
Zusätzlich: 974 PHP-Dateien ohne Syntaxfehler und 16 JavaScript-Prüfungen bestanden.
Die tatsächlich gerenderte synthetische Maske wurde bei 1265 × 900 und 390 × 844
Pixeln geprüft: Suchauswahl, mehrzeilige Suchtreffer, bedingte Abgangsfelder und
Ausblenden veralteter Vorschauen funktionieren; kein seitlicher Seitenüberlauf.
Das ist keine Abnahme auf einem echten Mobilgerät und kein echter Nutzertest.

Schreibende Tests ausschließlich in der dauerhaft freigegebenen synthetischen
MySQL-Datenbank. Kein Zugriff auf die produktive Vereinsdatenbank, kein Versand von
Mails, Bankaufträgen oder Steuererklärungen. Keine neue Sicherungs-/Wiederherstellungsrunde
als Voraussetzung für gewöhnliche Testläufe; nur die ausdrücklich dafür vorgesehenen
Schema-/Sicherungsregressionen prüfen die Wiederherstellung synthetischer Daten.

## Verbleibende Voraussetzungen für den allgemeinen Release

1. Weitere Anlagenfälle: wiederholte Teilabgänge, Schenkung/Entnahme, Wertminderungen,
   Anlagen-Sphärenwechsel und die Korrektur festgeschriebener Anlagenwerte mit revidierten
   Nachweisen. Die neue Berichtigung offener Quellen ist davon zu unterscheiden.
2. Durchgängige Zeitraum-Anbindung gemeinsam genutzter Anlagen, Bereichsverrechnung,
   Mehrbetriebsaufteilung und steuerlicher Überleitung. Vorhandene Kalenderfunktionen
   allein erfüllen diese Voraussetzung nicht. Auch die eigenen Zeitraumadapter für
   Beiträge, Spenden und nicht rechnungsgebundene Bankumsätze sind noch gesondert
   zu integrieren; die neue Rechnungs-/Zahlungsbrücke behauptet diese Abdeckung nicht.
3. Vollständige geprüfte Konten-/Steuersonderfallabdeckung sowie amtliche E-Bilanz-
   Erzeugung für sämtliche unterstützten Abschlusszeiträume und Korrekturfassungen.
4. Abschließende Gesamtabnahme einschließlich der Übermittlungs-/Verarbeitungsrückmeldungen
   und Veröffentlichung. Die Produktionssperre der neuen Zeitraumfunktionen bleibt bis
   dahin bestehen; EÜR wird nicht automatisch auf Bilanzierung umgestellt.
