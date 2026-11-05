# Anlagenverkauf bei getrennten Buch- und Steuerwerten – 20. September 2026

Nachfolgende Ergänzung: [Beleggebundene Gegenkonten](ANLAGENABGANG-KONTENNACHWEIS-20260920.md)
für neue Verkaufs-/Ausmusterungsquellen. Die unten genannte Sperre mehrdeutiger
Konten gilt weiterhin für ältere Abgänge ohne diesen konkreten Kontennachweis.

Entwicklungsstand, **nicht produktiv veröffentlicht**. Kulturhus bleibt bei EÜR;
die separate Bilanzfunktion und E-Bilanz-Echtabgabe werden hier nicht freigeschaltet.
Es wurden keine produktiven Buchungen, Mitgliedsdaten oder Originalbelege geändert.

## Ergänzter Umfang

Vollständiger, belegter Verkauf eines einzelnen Gegenstands der Konten 06300/06500
bei ausdrücklich fachlich bestätigter abweichender **linearer Nutzungsdauer**.
Anschaffungskosten, Restwert, Anschaffungszeitpunkt, Verkaufspreis und Umsatzsteuer
bleiben gleich. Kalenderjahre 2025/2026, separat bilanzierter Geschäftsbetrieb
(Bereich 4), Inland, 19 % oder im Original begründete Steuerbefreiung.

- Buch- und Steuer-AfA bis einschließlich Verkaufsmonat; getrennte Restwerte und
  Verkaufsergebnisse. Ein Buchgewinn kann steuerlich ein Verlust sein und umgekehrt.
- Die steuerliche Überleitung kehrt die Buch-Kontierung des Verkaufs nur im
  Steuerwertejournal um und setzt die steuerliche Gewinn-/Verlustkontierung ein.
  Erlöse und Restwerte werden nicht doppelt gezählt. Originaljournal, Rechnung,
  Umsatzsteuer und ausgegebene PDF bleiben unverändert.
- Die vorhandene PHP-Berechnung ist jetzt eingebunden. Der Python-Generator prüft
  die Beträge, Konten, Vorzeichen, Herkunft und jede erzeugte Überleitungszeile
  unabhängig nach, bevor er XBRL und Anlagenspiegel erzeugt.
- Neu übernommene Verkaufsquellen reservieren die benötigten Gewinn-/Verlustkonten
  vor der Festschreibung. Auch bei Buchwert null bleibt ein steuerlicher Restwert
  verarbeitbar, ohne eine fiktive Buch-Abschreibung zu erzeugen.
  Bereits gebundene Quellen ohne Reservierungsmarker behalten den alten Kontensatz.
- Bestätigte Steuerpläne werden im Folgejahr fortgeführt; nach vollständigem Abgang
  entsteht weder ein neuer Anlagenbestand noch eine wiederholte Abschreibung.
- Die vorhandene Ansicht zeigt „Verkauft“, Buch-/Steuer-Restwert und beide
  Verkaufsergebnisse. Fachliche Bestätigung, Änderungsschutz, Rechte und erneute
  Freigabe nach Änderungen bleiben erforderlich.
- Keine zusätzliche Datenbankmigration für diese Ergänzung. Die vorhandene
  Verkaufszuordnung aus Migration 094 wird weiterhin benötigt.

Fehlende oder mehrdeutige Gegenkonten im eingefrorenen Kontenplan bleiben ein
Prüfhindernis. Insbesondere werden mehrere Konten mit derselben SKR42-Nummer
(beispielsweise getrennte 68950-Konten für Verkauf und Ausmusterung) nicht geraten
oder nachträglich zu einem Konto verschmolzen.

## Lokale Nachweise

- `balance-tax-sale-values.php`: 224 kombinierte Prüfungen, davon 69 bestehende
  Steueranlagen-Basisprüfungen. Zehn Verkaufsszenarien: Gewinn/Verlust-Wechsel in
  beide Richtungen, gleiche Klassifizierung, Buchwert/Steuerwert null, Cent-Rundung
  mit Restwert, Verkauf zum steuerlichen Buchwert, belegte 0 % und Folgejahr.
- `balance-tax-scrap-values.php`: 129 kombinierte Prüfungen unverändert erfolgreich.
  Überlappende Basisprüfungen nicht als zusätzliche einzigartige Fälle zählen.
- `ebilanz-tax-assets-test.py`: 22 Testmethoden mit Unterfällen, 24 unterstützte
  Anlagen-Fixtures; manipulierte Verkaufsnachweise, Beträge, Kontierung, Zeilen,
  Bool-/Integer-Verwechslung, Fremdkorrektur und Wiederbelebung werden abgewiesen.
- Bestehende Python-Suiten: 57 Generator-, je 10 Ausmusterungs-/degressive-/USt-,
  9 Verkaufs- und 8 Workflow-Bridge-Testmethoden erfolgreich.
- Bestehende reine PHP-Prüfungen für Bilanzrechnung, Überleitung, Anlagenverzeichnis,
  Kontenzuordnung und Umsatzsteuer erfolgreich.
- 48 PHP-Ansichtsprüfungen und 234 Browserprüfungen bei 390/1280 Pixeln erfolgreich.
  Screenshots geprüft: keine horizontale Überbreite; Pflichtfelder, Bestätigung,
  Formularwerte und Verkaufsanzeige geprüft. Nur erzeugte synthetische HTML-Fixtures,
  sämtlicher Browser-Netzwerkverkehr blockiert, kein Formular versendet.
- Zehn eigene XBRL-Dateien mit nativer ERiC-Bibliothek: **Code 0**. Manipulierte
  Bilanzsumme: erwarteter Code 610001002. Nachweis:
  `audit/ebilanz-tax-sale-native-20260920.json`. Ausschließlich Prüfflag 2,
  kein Zertifikat, kein Versand. Code 0 belegt technische Validierung, keine
  materielle steuerliche Richtigkeit oder Anerkennung durch das Finanzamt.

## Serverprüfung

Erster Lauf: `audit/ebilanz-mysql-host-20260918-63213da1bb2b81fc.json`.
Sechs bestehende Suiten erfolgreich. Beide neuen Verkaufsabläufe wurden korrekt
wegen der fehlenden expliziten Forderungskonto-Zuordnung im synthetischen Testfall
abgewiesen. Nur die Testdaten wurden ergänzt, nicht die Prüfung abgeschwächt.
Der fehlgeschlagene Lauf bleibt als solcher dokumentiert.

Die separate, freigegebene Datenbank wurde vorher serverseitig verschlüsselt
gesichert und danach identisch wiederhergestellt: 150 Tabellen, 1.393 Datensätze,
Strukturen und Trigger. Geschützte Produktivdateien unverändert; temporärer Code
entfernt. Keine Datenbankkopie oder Sicherungsschlüssel auf den PC übertragen.

Erfolgreiche Wiederholung:
`audit/ebilanz-mysql-host-20260918-2581c0005bd524e6.json`.

- PHP 8.3.33 / MySQL 8.4.11: vier Schemavergleichsprüfungen sowie 101 kombinierte
  Prüfungen mit Restbuchwert und 102 mit Buchwert null erfolgreich. Beide enthalten
  dieselben 62 Basisprüfungen; die Zahlen sind nicht als einzigartige Fälle additiv.
- Tatsächliche Ausgangsrechnung und unveränderte Original-PDF, festgestellte
  Abschreibung/Verkauf, reservierte Konten, unveränderliche Quellenbindung,
  Jahresabschluss, Steuerplan aus dem Vorjahr, Berechtigungen, Entwurfsänderung,
  Vorstandsfreigabe, echter XML-Generator und Offline-Übermittlungsprotokoll geprüft.
- Im Test wechselt der Buchgewinn zum steuerlichen Verlust. Der geschlossene
  Buchabschluss wird nicht umgeschrieben; im Folgejahr keine erneute Abschreibung.
- Erneut 150 Tabellen / 1.393 Datensätze, Strukturen und Trigger identisch
  wiederhergestellt. Geschützte Produktivdateien unverändert, privater temporärer
  Testcode entfernt; verschlüsselte Sicherung bleibt auf dem Server.

Der Abgabeablauf verwendet einen **Offline-Transportersatz**. Ein dortiger
`sent_test`-Status ist kein echter Versand an ELSTER und keine Behördenrückmeldung.

## Weiter offen

Dies schließt nicht die gesamte Bilanzentwicklung ab. Weiter offen sind unter
anderem vollständige Kontenabdeckung, Teilabgänge/Umbuchungen, Entnahmen/Schenkungen,
Versicherungsersatz, andere Bewertungsgrundlagen, Änderungen fortgeführter Steuerpläne,
Sammelposten/Sonder-AfA, mehrere Geschäftsbetriebe/Gesamtvereinsbilanz sowie abweichende
Wirtschaftsjahre. Für mehrdeutige Abgangskonten fehlt noch die beleggebundene Auswahl.

Vor einer Produktivfreigabe sind die komplette HTTP-/Berechtigungs-/Installations-
und Wiederherstellungskette sowie ein tatsächlicher synthetischer Testversand
mit passend gebundenem Übermittlungsprotokoll abzunehmen. Die hier erfolgte native
Validierung und der Offline-Ablauftest ersetzen diesen Versand nicht.
