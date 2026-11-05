# Kontobearbeitung und Geschäftspartnersuche

Stand 28.09.2026. Eng begrenzte Bankoberflächen- und Serviceänderung.

## Bedienung

- Bei einem ausgewählten Konto führt „Konto bearbeiten“ direkt zur Bezeichnung,
  Aktivierung, Onlinebanking-Berechtigung und Buchhaltungseinstellung.
- Ein noch nicht verwendetes Konto kann seinen Typ, Anfangssaldo und Bestandsdatum
  ändern. Löschen erfordert den aktuellen Bearbeitungsstand und den exakten Namen.
- Umsätze, Belege, Bankzuordnung, Aufträge, Kassenabschlüsse, Inventar- oder
  Einstellungsverweise schützen Typ, Anfangsbestand und Löschung. Benutzte Konten
  können umbenannt und deaktiviert werden; bisherige Umsätze bleiben erhalten.
- Der alte Abschnitt „Testkonto und seine Umsätze löschen“ entfällt. Auch der
  bisherige serverseitige Löschweg darf keine Umsätze mehr entfernen.
- „Buchhaltungskonto (SKR42)“ steht unter dem aufklappbaren Bereich „Buchhaltung“.
  Es ist keine IBAN und keine FinTS-Zugangskennung.
- „Bankzugang einrichten oder ändern“ öffnet die erneute Anmeldung für Bank,
  Benutzername und PIN. Die Kontoauswahl bleibt bei den Rückleitungen erhalten.
- „Name / Geschäftspartner“ bietet beim Tippen Mitglieder und Kontakte des
  eigenen Vereins an. Freier Text bleibt möglich. Es werden nur Namen angeboten,
  keine E-Mail-Adressen oder weiteren privaten Daten. Die Auswahl übernimmt einen
  Namen und erzeugt keine automatische Mitglieds-/Belegzuordnung.
- Der veraltete Hinweis „Onlinebanking ist noch nicht freigeschaltet“ entfällt.

## Saldo und Schutz

Die angezeigte Summe bleibt Anfangsbestand plus erfasste Umsätze. Eine
Kontoumbenennung oder neue FinTS-Verknüpfung ersetzt den Anfangsbestand nicht
durch einen vermeintlichen Bankstand. Kein echter Bankabruf durch diese Änderung.
Kontoänderungen werden protokolliert und gegen überholte Formulare abgesichert.

## Nachweise

42 neue Prüfungen in `vereinsverwaltung/tests/bank-account-edit.php` erfolgreich,
einschließlich Mandanten-/Rollengrenzen, Transaktionsschutz, veralteter Formulare,
Rücknahme bei ungültigen Eingaben, Suchvorschlägen und gerenderter PHP-Oberfläche.
Letzter Lauf: `audit/ebilanz-mysql-host-20260918-26ffff26ff871da4.json`.
Nur synthetische Daten in der dauerhaft freigegebenen MySQL-Testdatenbank.

Bestehende Finanz-Leseansichten, FinTS-Dialog, planmäßiger Abruf und Finanzabläufe
bestanden im Lauf `audit/ebilanz-mysql-host-20260918-eb9b658861c979ed.json`.
Der neue Kontotest scheiterte dort noch an einem inzwischen korrigierten
synthetischen Kontakt-Testdatensatz; der oben genannte abschließende Lauf ist grün.
Kein echter Versand und keine Bankanweisung. Desktop- und schmale Handyansicht
des synthetischen Kontoeditors technisch im Browser geprüft; kein echter Nutzertest.

Die Veröffentlichung betrifft ausschließlich acht Bankdateien. Unfertige
Bilanz-/Anlagenaktionen aus dem Entwicklungszweig werden ausdrücklich nicht
übertragen. Konfiguration, Datenbank, Worker und Laufzeitverweis bleiben unverändert.
Veröffentlichungsnachweis: `audit/bank-account-edit-20260928.json`.

Nach der Veröffentlichung lesend im angemeldeten Browser geprüft: Suchfeld mit
33 Namensvorschlägen (31 Mitglieder, zwei Kontakte), sichtbare Kontobearbeitung,
editierbarer Anfangsbestand des unbenutzten Kontos und entfernter alter Hinweis.
Kein Produktivformular abgesendet. Der FinTS-Link meldete zunächst fehlende
Registrierung/Bankendpunkt-Konfiguration. Die anschließende Untersuchung fand
vollständige vorhandene Konfiguration und Bibliothek, aber einen falschen privaten
Pfad für Web-PHP: ein festes `/tmp/...` funktioniert im SSH-Chroot, nicht im
Webprozess außerhalb dieses Chroots.

Genau eine Zeile der privaten Laufzeit wurde auf einen mit `__DIR__` verankerten
Pfad geändert. CLI-Ziel, Konfiguration und vorhandener Schlüssel bleiben identisch;
keine neuen Schlüssel oder Zugangsdaten, keine Datenbankverbindung durch die
Korrektur. Syntax und fixierte Bibliotheksversion geprüft, Produkt-ID-Format und
Vereinsbindung gültig. Nachweis: `audit/fints-root-fix-20260928.json`.

Anschließend zeigt der angemeldete Browser live die vorhandene Volksbank-Auswahl,
Benutzername, Onlinebanking-PIN und „Mit Bank verbinden“, ohne den früheren Fehler.
Keine Anmeldung abgesendet. Bankannahme, tatsächlicher Umsatzabruf und Zahlungen
sind damit weiterhin nicht nachgewiesen. Kein FinTS-Aufruf durch diese Abnahme.
