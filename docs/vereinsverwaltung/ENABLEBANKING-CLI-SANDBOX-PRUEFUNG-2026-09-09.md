# Enable Banking: tatsächliche Sandbox-Prüfung per CLI

Prüfzeit: 9. September 2026, 08:25 Uhr MESZ. Ausschließlich lesende HTTP-Aufrufe von der lokalen Kommandozeile. Keine Browseranmeldung, kein CAPTCHA-Zugriff, keine E-Mail, keine Zahlung und keine Einträge in der Finanzdatenbank.

## Ergebnis

Die vorhandene RSA-Anmeldung der Sandbox-Anwendung funktioniert. Der tatsächliche Abruf der beiden vorhandenen Mockkonten ist mit den noch vorhandenen Kontofreigaben dagegen nicht mehr möglich. Die lokale Control-Panel-Zugangsdatei enthält ausschließlich einen abgelaufenen ID-Token; ein Refresh-Token oder eine andere vorhandene CLI-Anmeldung wurde in den gezielt geprüften Ablagen nicht gefunden.

| Tatsächlich ausgeführter Aufruf | Ergebnis |
| --- | --- |
| `GET https://api.enablebanking.com/application` mit frisch signiertem Anwendungs-JWT | HTTP 200; Umgebung ausdrücklich `SANDBOX`; Anwendung aktiv |
| `GET https://api.enablebanking.com/aspsps?country=DE` | HTTP 200; Mock ASPSP vorhanden; die angebotenen Methoden verwenden `REDIRECT` |
| `GET /accounts/{vorhandene Mockkonto-UID}/transactions` für das erste Konto | HTTP 401 |
| Derselbe Umsatzabruf für das zweite Konto | HTTP 401 |
| `GET https://enablebanking.com/api/v2/cp/mock-aspsp/accounts` mit dem lokal gespeicherten Control-Panel-Token | HTTP 401 |

Der Control-Panel-Token war am 6. September 2026 um 19:41:15 UTC abgelaufen. Seine Nutzdaten wurden ausschließlich im Prozess auf Ablauf und Vorhandensein eines Refresh-Tokens untersucht; der Token wird weder hier noch im Diagnosebericht ausgegeben. Die sieben geprüften CLI-Storepfade im Benutzerverzeichnis, aktuellen Projekt, lokalen Enable-Banking-Verzeichnissen und bisherigen OneDrive-Projekt enthalten kein `cp/users`-Verzeichnis. Die gezielte Suche nach bestehenden Refresh-Token-/CLI-Root-Verweisen im lokalen Arbeitsbereich fand ausschließlich den CLI-Quellcode.

## Zwei voneinander getrennte Freigaben

1. **Anwendung:** Mit vorhandenem privatem RSA-Schlüssel lassen sich weiterhin gültige Anwendungs-JWTs erzeugen. Das wurde durch HTTP 200 bestätigt. Ein neuer Anwendungsschlüssel oder eine erneute Appregistrierung ist hierfür nicht erforderlich.
2. **Control Panel und Kontofreigabe:** Die CLI erneuert ihren Control-Panel-ID-Token über `POST /api/token`, benötigt dafür aber einen vorhandenen `refreshToken`. Dieser fehlt. Selbst eine erneuerte Control-Panel-Anmeldung ist noch keine neue Kontofreigabe: Der dokumentierte Kontoablauf liefert nach `POST /auth` einen Redirect und benötigt anschließend dessen Autorisierungscode für `POST /sessions`.

Die öffentliche CLI beschreibt die Befehlsgruppe für Kontenautorisierung und Umsatzabruf weiterhin als geplante Funktion. Ihre vorhandene Anmeldefunktion versendet einen E-Mail-Link; sie wurde aufgrund des Auftrags nicht ausgeführt. Der reguläre API-Kontoablauf einschließlich Redirect und anschließendem Codeaustausch ist in der [offiziellen API-Referenz](https://enablebanking.com/docs/api/reference/) beschrieben. Die vorhandene Refresh-Implementierung und der Funktionsumfang sind im [offiziellen CLI-Repository](https://github.com/enablebanking/enablebanking-cli) nachvollziehbar.

Es fehlt damit konkret ein erneuerbarer CLI-Control-Panel-Anmeldestand (`idToken` und `refreshToken`) für administrative Mockdaten-Aufrufe sowie eine noch gültige Sandbox-Kontofreigabe beziehungsweise ein regulär erhaltener Autorisierungscode für den Kontoabruf. Ein ausschließlich mit RSA signierter Appaufruf ersetzt diese Benutzerfreigabe nicht. Ein dokumentierter rein serverseitiger Ersatz für den Mock-Redirect wurde nicht gefunden. Es wurde kein interner Endpunkt erraten und keine Zugangsprüfung umgangen.

## Was als echter Abruf noch offen bleibt

Die vorhandenen Einrichtungshelfer sehen zwei Mockkonten mit zwölf beziehungsweise zwei künstlichen Umsätzen vor. Dieser Testaufbau wurde in dieser Prüfung nicht neu angelegt oder verändert. Wegen HTTP 401 konnten die aktuellen Umsätze und der tatsächliche Seitenwechsel jetzt nicht erneut bestätigt werden.

Die [offizielle Sandbox-Dokumentation](https://enablebanking.com/docs/api/sandbox/) nennt für Mock ASPSP zehn Umsätze pro Antwort. Bei dem vorbereiteten Aufbau sind daher für das erste Konto zehn plus zwei Umsätze und für das zweite Konto zwei Umsätze zu erwarten. Diese erwarteten Zahlen dürfen bis zu einer gültigen Kontofreigabe nicht als bestandener Ende-zu-Ende-Test ausgewiesen werden.

Die lokale Diagnose liegt unter `.local/enable-cli-auth-sanitized.json`; der lesende Prüfhelfer unter `.local/enable-cli-auth-audit.py`. Beide enthalten keine privaten Schlüssel, Zugangstoken, vollständigen Kontokennungen oder produktiven Kontoumsätze. Es wurden keine Anwendungseinstellungen oder Produktivdateien geändert.
