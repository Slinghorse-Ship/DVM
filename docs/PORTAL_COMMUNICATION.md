# Mitgliederportal und Kommunikation

Stand: 06.09.2026. Datenbankschema 40.

- Eigene ausgestellte Beitragsrechnungen: persönliche Empfänger-ID, keine Zuordnung über E-Mail. Weitere Dokumente werden vom Vorstand pro Person und unveränderlicher Dateiversion freigegeben. Sperrung, Archivierung und Widerruf werden beim Abruf geprüft.
- Chat: Teilnehmerzugriff, Einzel- und Gruppenunterhaltungen, geschützte PDF/Bild-Anhänge bis 2 MB, Verlauf mit älteren Nachrichten, Lesemarken und alle 15 Sekunden aktualisierte Ungelesen-Anzeige. Nachrichten werden nach Betätigung von Aktualisieren angezeigt. Optionaler standardisierter Browser-Push über den jeweiligen Browseranbieter. Geräte werden ausdrücklich aktiviert und können einzeln oder gemeinsam abgemeldet werden. Nur ein allgemeiner verschlüsselter Hinweis wird versendet, keine Chat-Inhalte oder Namen. Gelesene Nachrichten und ausgeschiedene Teilnehmer werden vor Zustellung ausgeschlossen.
- Sendungen: Vorstandsentwürfe, bis 250 ausgewählte Empfänger, gemeinsamer Gruß und eine Mail pro geteilter Adresse, ausdrückliche Versandbestätigung, Postausgang mit gesendeten und unklaren Ergebnissen. Der bestehende Website-Worker verarbeitet bis 20 ausstehende Nachrichten pro Lauf. Keine automatische Wiederholung unklarer Zustellung. Serienbriefe verwenden gespeicherte Empfängeranschriften und werden als Sammel-PDF archiviert. Bis zu zehn Organisationsanhänge mit insgesamt 5 MB werden in genau der ausgewählten Revision versendet; Rechte, Status und Prüfsumme werden bei Vorschau und Versand geprüft. Personenbezogene Mitglieder-/Finanzdokumente bleiben ausgeschlossen. Standardfelder NAME, ANREDE, EMAIL, ADRESSE und MITGLIEDSNUMMER sowie bis zu 30 freie FELD=Wert-Definitionen werden sicher als Text ersetzt. Fehlende Variablen blockieren den Versand; bei gemeinsamer Adresse werden die Werte zusammengeführt.
- Geburtstage und Jubiläen lösen niemals Mailaufträge aus.
- Ehrenamt: offizielles ausfüllbares Landkreisformular, Vereinsdaten aus Einstellungen, Person/Anschrift/Geburtsdatum prüfen. Getrennte Vor- und Nachnamen werden vor Erstellung eingegeben. Die hinterlegte Bildunterschrift wird nur auf ausdrücklichen Auftrag des ersten Vorstands oder Superusers eingefügt. Dies ist keine qualifizierte elektronische Signatur. Kein automatischer Versand an das Gesundheitsamt.

Tests: bestehende Grundlagen-, Dokument-, Fristen-, Steuer- und Einladungstests; neue isolierte Tests für persönliche Freigabe, Chat-Rechte, Dubletten, Mailversandzustände und echte PDF-Erzeugung. Browserprüfung auf Desktop und 390 px Breite. Es wurden keine echten Empfänger angeschrieben.

## Push-Betrieb

Die getrennte Composer-Laufzeit liegt unter `vereinsverwaltung/push-runtime` (festgeschriebene Versionen in composer.lock). VAPID-Schlüssel liegen ausschließlich neben der privaten Vereinsdatenbank in `push-vapid.json` mit Modus 0600. Diese Datei bei einer Wiederherstellung mit sichern; nicht ohne Grund rotieren. Ein eigener CLI-Lauf wird nach einer neuen Nachricht gestartet; der bestehende Website-Worker dient zusätzlich als Rückfallebene. Beide prüfen aktive Identität und Chatteilnahme erneut. Providerfehler führen nicht zu unkontrollierten Wiederholungen. Keine Geburtstags- oder Jubiläumspushs.

Unterstützte Endpoint-Domains: Google FCM, Mozilla Push Services, Apple Push und Windows Notifications. Andere Hosts, Zugangsdaten in URLs, explizite Ports und Weiterleitungen werden nicht zugelassen.

Technische Grundlagen: [Web-Push PHP](https://github.com/web-push-libs/web-push-php), [Apple: Push für Home-Screen-Web-Apps](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/).

Validiert: Berechtigungen und exakte Mailanhänge, gemeinsame Serienfelder, fehlende Werte, echte PDF-Erzeugung, Web-Push-Signierung/Verschlüsselung mit isoliertem HTTP-Transport, Gerätewechsel, Widerruf und bereits gelesene Nachrichten. Die tatsächliche Zustellung auf einem persönlichen Gerät erfordert dessen Browser-Einwilligung und ist nicht durch die lokalen Transporttests belegt.
