# Mitgliedsbestätigung und Bankfreigabe

Stand: 9. September 2026. Ergänzungen zum aktuellen Vereinsmanager; noch kein Nachweis einer echten produktiven Bankverbindung.

## Mitgliedschaft und Mitarbeit

Neben der unveränderten Verwendung des Landkreisformulars für die Hygienebelehrung gibt es eine eigene Vereinsbestätigung unter Vereinsportal → Sicher helfen und Meine Belege.

- Status, Mitgliedsnummer, Eintritt und Datumsgenauigkeit werden aus der aktuellen Mitgliedschaft gelesen. Keine frei übermittelbare Auswahl „aktiv/passiv“.
- Aktive Mitglieder: Jahresbestätigung mit anpassbarem Vorschlag zur Mithilfe bei Veranstaltungen/Vereinsaktivitäten, tatsächlichen Aufgaben und optionalem Umfang. Der Nutzer prüft den Vorschlag vor Ablage; das aktuelle Kalenderjahr und der Stand zum Ausstellungsdatum stehen im PDF. Frühere Jahresdokumente bleiben unverändert.
- Passive Mitglieder: reine Bestätigung der passiven Mitgliedschaft für das aktuelle Kalenderjahr. Tätigkeitsdaten werden auch bei gefälschtem POST serverseitig entfernt und vom Renderer nicht ausgegeben.
- Keine aktuellen Bescheinigungen für reine Kontakte, bereits ausgetretene oder erst künftig beginnende Mitgliedschaften. Die Vorstandsauswahl und die Erstellung verwenden denselben lokalen Tageswert.
- Mitglieder können nur für ihr eigenes Profil vorbereiten. Berechtigte Vorstände können Personen des eigenen Vereins wählen. Unterschrift weiterhin nur für ersten Vorstand/Superuser; andere Ausgaben sind deutlich als Vorlage gekennzeichnet.
- Die Vorschau bindet Profilversion, Mitgliedschaftsversion/-status/-beginn, Angaben und Unterschrift. Änderungen invalidieren Vorschauabruf und Speicherung; erneute Prüfung innerhalb der Schreibtransaktion. Wiederholschutz und atomare Dokument-/Personen-/Portalzuordnung bleiben erhalten. Keine automatische E-Mail; vorhandene freiwillige Dokumenthinweise gelten weiterhin.
- Die allgemeine Bestätigung belegt weder eine Zahlung noch eine steuerliche Zuwendung.

Prüfung: 24 neue Dienst-/Rechte-/Status-/Speicherprüfungen, bestehende Hygiene-Selbstbedienung55 und Anschrift/Zustand20 erfolgreich. Echter lokaler Browserablauf: eigenes aktives Mitglied → PDF-Vorschau → Ablage → Download; passives Mitglied durch Vorstand auf390px ohne Tätigkeitsfelder. Alle Browseranfragen auf lokale synthetische Umgebung begrenzt. Aktive, passive und signierte Beispiel-PDFs sowie zweiseitiger Langtext gerendert und sichtbar geprüft. Keine echten Mitgliederdaten in Tests oder Hilfebildern.

## Enable-Banking-Freigabe

Der tatsächliche API-Aufruf vom Webspace bestätigt eine aktive SANDBOX-Anwendung. Die zwei bereits gespeicherten Mock-Konten hatten abgelaufene Freigaben; neue Kontoabrufe lieferten401. Der Nutzer verlangt Änderungen über CLI. Die anschließende reine CLI-Prüfung bestätigt einen funktionierenden Anwendungsschlüssel, aber einen abgelaufenen separaten Verwaltungstoken ohne Refresh-Token. Deshalb sind Mock-Kontenverwaltung, erneuerter Kontozugriff und vollständiger tatsächlicher API-Seitenabruf noch nicht nachgewiesen. Keine erneute Browseranmeldung angefordert. Einzelheiten im [CLI-Prüfbericht](ENABLEBANKING-CLI-SANDBOX-PRUEFUNG-2026-09-09.md).

Zusätzlich konnte eine UI-Ursache reproduziert werden: Chromium blockiert bei `form-action 'self'` die externe Weiterleitung eines POST-Formulars. Die Korrektur beendet den POST auf derselben Herkunft und startet auf der anschließenden HTML-Seite eine normale Navigation zur bereits geprüften Bank-URL. Akteur, Umgebung und kurze Ablaufzeit sind sitzungsgebunden; keine Lockerung der CSP. Ohne JavaScript bleibt ein ausdrücklicher Weiter-Link vorhanden. Abgelaufene Verbindungen sind als solche gekennzeichnet;401/403 bei Kontoabrufen erklären die nötige erneute Freigabe.

Prüfung: Browser reproduziert ursprüngliche CSP-Sperre und bestätigt korrigierten Ablauf.21 AIS-Client-Prüfungen und37 Bankimportprüfungen erfolgreich, darunter Sandbox-Sperre, Mehrfachkonten, Seitenwechsel, verschlüsselte Sitzungsablage, Wiederholungsschutz, Bestands-/Altdaten-Erhalt, manuelle Vorzahlungen, Konflikte und Rücknahme. Weitere bestehende Zuordnungstests für Rechnungs-/Mitgliedsnummern und Lieferantenreferenzen erneut ausgeführt. Diese lokalen Prüfungen ersetzen keine Bankabnahme.

Sandboxwerte werden weiterhin niemals in die reale Vereinsbuchhaltung importiert. Weder echte Überweisung/Lastschrift noch Testmail an Mitglieder oder Vorstand wurde ausgeführt. Die auf Nutzerwunsch vertagte Schedulerprüfung wurde nicht gestartet.
