# App-Freigabe für Kontodaten

Stand: 5. September 2026. Nutzerentscheidung: App-Freigabe für lesenden FinTS-Abruf. Noch keine eigene FinTS-Produktregistrierung vorhanden.

## Lokal umgesetzt

Unter Finanzen → Bank & Kasse ist ein ausdrücklich synthetischer Ablauf für Volksbank Jade-Weser (VR SecureGo plus) und DKB (DKB-App) verfügbar. Start, Warten, simulierte Freigabe, Ablehnung, Abbruch und Ablauf nach fünf Minuten werden unterschieden. Testumsätze bleiben getrennt von Buchhaltung und Kontobeständen. Finanzrechte werden bei jeder Aktion geprüft; Rollenwechsel entfernt den Testvorgang. CSRF-Prüfung, Vorgangskennung und Version verhindern fremde oder veraltete Aktionen. Es werden keine Zugangsdaten erfasst, Bankaufrufe ausgeführt oder App-Benachrichtigungen versendet.

Start wie in `vereinsverwaltung/README.md`; Test: `php vereinsverwaltung/tests/bank-preview.php`.

## Nächste Voraussetzungen für Echtbetrieb

1. Produktverantwortlichen und Kontaktdaten für die eigene FinTS-Anwendung festlegen, Registrierung vorbereiten und mit Nutzerfreigabe einreichen. Keine fremde Produkt-ID übernehmen.
2. Getrennten Banking-Betrieb gemäß BANK-01 bis BANK-07 in `BANKING-FINTS.md` nachweisen; bestehendes CMS-Hosting ist dafür noch nicht abgenommen.
3. Bibliotheksversion mit Lockfile festlegen, echten lesenden Adapter und authentisiert verschlüsselten Dialogspeicher implementieren. Das vorliegende Modul ist kein solcher Adapter.
4. App-Verfahren aus den Bankparametern ermitteln. Nicht jedes Verfahren in SecureGo plus ist zwingend eine dezentrale Bestätigung: Falls die Bank eine TAN verlangt, dies klar anzeigen und nicht eine App-Bestätigung vortäuschen.
5. Freigabe kann beim Login, bei der Kontenliste und beim Umsatzabruf nötig sein. Den jeweiligen Bankauftrag fortsetzen; bankseitiges Polling-Intervall und maximale Versuche einhalten. Nur positive Bankantwort gilt als Freigabe, niemals ein Browserklick.
6. Banktests erst mit eingerichtetem berechtigtem Zugang und Nutzerfreigabe. Keine Überweisung oder Lastschrift Bestandteil dieses Auftrags.

## Weitere Anbieter

- Nutzerentscheidung: Enable Banking und GoCardless werden nicht eingeplant. Für N26 ist damit noch kein kostenloser automatischer Abruf bestätigt. Kein Rückgriff auf undokumentierte Login-Schnittstellen als angeblich stabile Lösung.
- PayPal: separater direkter REST-Reporting-Adapter ist zu prüfen. Live-App, Client-ID/Secret und Freischaltung der Kontotransaktionssuche fehlen. Noch keine Zusage über Verfügbarkeit oder Kosten für das konkrete Vereinskonto.
- Beide Anbieter bleiben in dieser FinTS-Vorschau unverbunden.

Es wurde kein Konto eingerichtet, keine Registrierung eingereicht und kein externer Dienst veröffentlicht.

Validierung: 35 Prüfungen des Testdialogs und 60 bestehende Finanzprüfungen bestanden; PHP-Syntax geprüft. Browserdurchlauf: Start → Warten → simulierte Freigabe → zwei Beispielumsätze, ohne Veränderung der vorhandenen Kontobestände. Echte Bank-/App-Kompatibilität ist nicht getestet.
