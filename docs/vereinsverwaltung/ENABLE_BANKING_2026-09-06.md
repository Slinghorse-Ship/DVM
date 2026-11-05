# Enable Banking – Produktiv und Sandbox

Am 06.09.2026 wurden per CLI zwei getrennte Anwendungen registriert. RSA-Schlüssel (4096 Bit), Zertifikate und Konfiguration liegen außerhalb des Webroots in `/tmp/kulturhus-banking`, Verzeichnis 0700, Dateien 0600. Der kurzlebige Registrierungstoken gehört nicht in die Anwendung oder Git. API-JWTs werden serverseitig für fünf Minuten signiert.

## Bedienung

Finanzen → Bank & Kasse → Bankverbindung: Produktiv oder Sandbox / Mock auswählen und „Umgebung anzeigen“. Produktion ist Standard. Die Sandbox ersetzt in dieser Ansicht die echte Kontenliste, damit Testdaten und Buchhaltung sichtbar getrennt bleiben.

Produktiv ist registriert, aber noch inaktiv. Eigene Konten zunächst bei Enable Banking verknüpfen, danach im Vereinsmanager die Bank auswählen und freigeben. Der Kontozugang der Volksbank Jade-Weser folgt laut Nutzer in den nächsten Tagen. Für die Aktivierung ist die Freigabe durch den Kontoberechtigten nötig. Mehrere Konten werden pro Freigabe gespeichert und einzeln angezeigt; weitere Bankfreigaben sind möglich.

Sandbox ist aktiv. Der authentifizierte API-Abruf vom Hosting liefert 686 deutsche Bankeinträge einschließlich Volksbank Jade-Weser und Mock ASPSP. Das ist kein Nachweis produktiver Verfügbarkeit eines konkreten Kontos. Mock ASPSP benötigt gegebenenfalls eigene Testdaten beim Anbieter. Alternativ stehen Banksandboxes mit dokumentierten Testzugängen zur Verfügung.

## Stand und Grenzen

- Lesende API: Anwendungsstatus, Banken, Freigabe, Konten, Salden und Umsatzseiten.
- Finanz-Schreibberechtigung für Verbindungsverwaltung und Anzeige; Benutzerbindung der Freigabe, Ablauf nach 30 Minuten, Einmalverwendung des Zustandswertes.
- Verbindungen und Freigaben AES-256-GCM verschlüsselt, getrennt nach Benutzer und Umgebung.
- Rückkehr von der Bank nutzt eine Zwischenseite zum Fortsetzen unter den vorhandenen Strict-Anmeldecookies.
- Keine Zahlungsschnittstelle. FinTS-ID und spätere SEPA-Erprobung bleiben offen.
- **Noch keine Übernahme von Enable-Banking-Umsätzen in die Finanzbuchungen.** Die lesende Bankanzeige ist vorbereitet. Zuordnung zum vorhandenen Finanzkonto, dauerhafte Umsatz-Deduplizierung und Import müssen anschließend ergänzt werden. Es wird kein Importerfolg behauptet.
- Nutzerfreigabe: Nach Aktivierung der Produktivverbindung dürfen echte Umsätze übernommen und zugeordnet werden. Der Kontozugang fehlt derzeit. Die Sandbox ist ausschließlich Anzeige/Test; ein Import in die echte Buchhaltung ist ausdrücklich ausgeschlossen, auch bei späteren Erweiterungen.
- Der Sandbox-Freigabeablauf wurde anschließend per CLI vollständig durchlaufen: Datenfreigabe, Mock-Kontoauswahl, Rückleitung, Code-Austausch, verschlüsselte Speicherung und Salden-/Umsatzabruf. Verbunden für den vorhandenen Website-Account `admin`: Kulturhus Sandbox Hauptkonto (12 Testumsätze) und Kulturhus Sandbox Rücklage (2 Testumsätze). Beide sind im authentifizierten Browser sichtbar. Es wurden keine Finanzbuchungen angelegt. Produktive Kontofreigabe und echte Umsätze bleiben ungeprüft.
- Lokales Docker-Netz hat kein externes DNS. Die UI wurde dort mit abgefangenem Netzfehler getestet; echte signierte API-Aufrufe wurden über PHP auf dem Hosting geprüft. Die Netzisolation wurde beibehalten.

## Prüfung

`vereinsverwaltung/tests/enable-banking.php`: 14 Prüfungen für mehrere Konten, Benutzer-/Umgebungstrennung, Replay-Schutz, Verschlüsselung, unbekannte Konten, Redirect-Prüfung und Folgeseiten. Lokaler Browser: Sandbox auswählbar, echte Kontenliste ausgeblendet, verständlicher Netzfehler, kein Überlauf bei 390 Pixeln Breite. Hosting-PHP: Produktiv `active=false`, Sandbox `active=true`, Bankenliste erfolgreich abgerufen.

Die anschließende Browserprüfung deckte einen SSH-/Webserver-Pfadunterschied auf. Private Pfade werden nun aus der tatsächlich konfigurierten Vereinsdatenbank abgeleitet, nicht aus dem SSH-Chroot übernommen. Zusätzlich wurde der tatsächlich vom Anbieter gelieferte Sandbox-Redirect `tilisy-sandbox.enablebanking.com` in die umgebungsabhängige Hostprüfung aufgenommen. Nach Veröffentlichung liefert auch die authentifizierte Webansicht die Bankenliste und verbundenen Mock-Konten. Der Produktiveinstieg verweist ausdrücklich auf die Aktivierung durch eigene Konten; „Testumgebung / Mock-Konten öffnen“ ist direkt erreichbar.

## Anbieterquellen

- https://enablebanking.com/docs/api/reference/
- https://enablebanking.com/docs/api/linked-accounts/
- https://enablebanking.com/docs/api/sandbox/

Produktiv und Sandbox können laut Anbieter nicht ineinander umgewandelt werden. Eigene Konten können im eingeschränkten Produktivbetrieb nach Verknüpfung getestet werden; Kontofreigabe über die API bleibt zusätzlich nötig.
