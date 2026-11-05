# Vereinfachte Finanznavigation

Eine gemeinsame Definition in `src/FinanceNavigation.php` liefert fünf Haupteinstiege für Seitenleiste und Finanzansichten. Nur die Unterseiten der geöffneten Gruppe werden zusätzlich gezeigt. Vorhandene URLs bleiben erhalten; weder Buchungsregeln noch Daten werden verändert.

- Bank & Kasse: Konten, Kassen und Umsätze.
- Rechnungseingang: eingegangene Rechnungen und Begleitmails.
- Beiträge: Beitragsabrechnung, Beitragsmails und Lastschrift-Information.
- Belege & Spenden: Belege, Geldspenden, Sachspenden und Bescheinigungen.
- Auswertungen & Steuer: Auswertungen, Export/DATEV, Körperschaftsteuer, Steuerkonten, Buchungsschlüssel und Freistellungsdaten.

`visible(WorkspaceAccess)` filtert die bestehenden Routenberechtigungen. `active()` erkennt auch direkte Aufrufe von Körperschaftsteuer, Beitragsmails und alten Spendenrouten. Die Finanzansicht bindet die Leiste vor ihren frühen Rückgaben ein. Der Settings-Einstieg wird vom zentralen Layout ergänzt.

Prüfung: finance-navigation.php 35 automatisierte Route-/HTML-Prüfungen, Exit0. Lokaler Browser: neun Finanz-Unterseiten bei1280/390px, jeweils genau eine Hauptnavigation und eine aktive Hauptgruppe, kein Seitenüberlauf. Die synthetische Bankseite zeigt erwartungsgemäß „Diese Bankumgebung ist noch nicht eingerichtet“; keine Bankanmeldung oder Bankaktion geprüft. Übrige acht Seiten ohne Fehlerhinweis. Keine Liveaktionen, Mails oder Datenänderungen.
