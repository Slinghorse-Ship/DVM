# Alle Sachspenden in kurzen Seiten finden

Spenden → Sachspenden zeigt sechs Originale pro gezählter Seite. Namen,
Bezeichnung, Beschreibung und Wertermittlung sind durchsuchbar; Ursprung und
Unterlagenvermerk lassen sich zusätzlich filtern. Prozentzeichen und Unterstriche
bleiben gewöhnliche Suchzeichen. Direkte Seitenwahl erreicht auch älteste Einträge
in Beständen jenseits der früheren 1.000er-Grenze.

Die Originalansicht erhält vollständige Beschreibung, Bewertung, Datum, Wert,
Ursprung, Nachweise und ursprüngliche Positionen. Schließen führt zur gewählten
Seite und Auswahl zurück. Neue Sachspenden behalten den Listenkontext; die
bestehenden Prüfungen und Korrekturformulare bleiben erhalten. Reine Finanzleser
können suchen und Originale lesen; die aktuelle Rolle und Vereinsgrenze werden
bei jedem Dienstzugriff geprüft. Die Bedienung funktioniert ohne JavaScript.

Der Controller lädt für Sachspenden keine ungenutzte gesamte Geldspendenliste.
MySQL zählt passende Originale und lädt genau sechs ganze Zeilen. Die ältere
`goods()`-Methode behält ihren bisherigen begrenzten Vertrag für andere Aufrufer;
die echte Sachspendenverwaltung verwendet `goodsPage()`. Keine Schemaänderung.

Lokale Prüfung: 25 Fach-/Original- und 91 tatsächliche Browserprüfungen,
1.003 ursprüngliche Sachspenden auf 168 Seiten sowie 1.001 zusätzliche tatsächliche
Geldspenden; jedes Sachspendenoriginal genau einmal. Ganze ursprüngliche Zeilen
und Buchungspositionen geschützt. Beide Rollen, mit und ohne JavaScript,
24 Handy-/Tablet-Schriftansichten, null POSTs. Der Browserlauf fand die fehlende
Stylesheetregistrierung für sichere Seitennavigation; nach Ergänzung ist die
gesamte Featuregruppe grün. Sechs ursprüngliche Gruppen für Finanzen,
Leserechte, Spendenquellen/-vorbereitung und Finanz-/Managernavigation grün.
Keine Bescheinigung ausgestellt, Mail versendet oder Zahlung ausgelöst.

Andere ältere vollständige Finanzlisten und die bisherige Inventar-Quellenauswahl
verwenden weiterhin ihre eigenen begrenzten Verträge. Diese verbleibenden
Grundabläufe sind gesondert weiter umzusetzen; daraus folgt keine vollständige
Finanz-/Inventar-/UI-Abnahme.
