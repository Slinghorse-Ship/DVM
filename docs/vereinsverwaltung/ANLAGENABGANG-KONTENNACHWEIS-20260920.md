# Anlagenabgänge mit gleichen SKR42-Nummern – 20. September 2026

Fortsetzung der getrennten Buch-/Steuerwerte. **Entwicklung, nicht live veröffentlicht.**
Kulturhus bleibt EÜR. Keine produktiven Buchungen, Originalbelege, Bankaufträge oder
Steuerabgaben werden durch diese Arbeiten verändert oder ausgelöst.

## Geschlossene Lücke

Eine Ausmusterung ohne Erlös und ein Anlagenverkauf mit Verlust können beide auf
68950 abgebildet werden, aber unterschiedliche interne Gegenkonten haben. Die
bisherige Steuerwerte-Berechnung verlangte genau ein Konto je SKR42-Nummer und
blockierte diesen regulären Mischfall.

- Neue Abgangsquellen speichern ihre konkreten Gegenkonten im unveränderlichen
  Quellennachweis (`disposal_accounts`). Beim Verkauf werden beide benötigten
  Gewinn-/Verlust-Kontenpaare festgehalten; bei Ausmusterung das Restwertkonto.
- Das Anlagenverzeichnis übernimmt diesen Nachweis beim Jahresabschluss. Die
  Konten werden weder aus Namen noch aus aktuellen Salden geraten.
- Steuerliche Gegenbuchungen verwenden die belegten Konten, auch bei Buchwert null.
  Kontonummer, interner Kontoschlüssel und Kontengruppe werden gemeinsam geprüft.
- PHP und Python gleichen die Bewegungen **pro internem Konto** mit den belegten
  Abgängen ab. Eine insgesamt passende Summe auf 68950 reicht nicht: Verschiebungen
  zwischen Verkaufs- und Ausmusterungskonto werden abgewiesen.
- Alte Quellen ohne diesen Zusatz behalten ihren bisherigen Inhalt. Eindeutige
  ältere Einzelkonto-Fälle bleiben möglich. Mehrdeutige alte Abgänge bleiben
  gesperrt und müssen anhand der Originalquellen geklärt werden; keine automatische
  Neuzuordnung oder nachträgliche Änderung festgeschriebener Abschlüsse.
- Keine neue Datenbankmigration; der Zusatz liegt in der vorhandenen versionierten
  JSON-Ablage. Das ursprüngliche Journal und die ausgegebene Rechnung bleiben erhalten.

## Lokale Prüfung

- `balance-disposal-accounts.php`: 282 kombinierte Prüfungen, einschließlich 224
  vorhandener Steueranlagen-/Verkaufsprüfungen. Sieben Mischfälle: Gewinn-/Verlust-
  Wechsel, Gewinn, Verlust, Buchwert null, kürzere steuerliche Nutzungsdauer,
  Cent-Rundung mit Restwert und Folgejahr. 58 zusätzliche Prüfungen.
- Manipulierte, fehlende, leere, unvollständige oder typfalsche Kontennachweise sowie
  Vertauschung mit einem anderen Konto derselben SKR42-Nummer werden abgewiesen.
- Python: 128 Testmethoden in den Generator-, Abgangs-, AfA-, Umsatzsteuer-, Verkaufs-,
  Steueranlagen- und Workflow-Bridge-Suiten bestanden. Darin 24 Steueranlagenmethoden
  mit Unterfällen und 31 unterstützten Anlagen-Fixtures.
- 54 PHP-Ansichts- und 266 Browserprüfungen bei 390/1280 Pixeln bestanden. Beide
  Gegenstände bleiben getrennte Formulare mit eigenem Namen und Gegenstandsschlüssel.
  Nur synthetische HTML-Fixtures, Browsernetzwerk blockiert, keine Übermittlung.
- Bestehende PHP-Rechenkern-, Jahresvortrags-, Anlagenverzeichnis-, Kontierungs-,
  Umsatzsteuer- und Altkontierungsprüfungen erfolgreich.
- Sieben Mischfall-XBRL-Dateien: native ERiC-Prüfung jeweils **Code 0**; manipulierte
  Bilanzsumme erwartungsgemäß 610001002. Audit:
  `audit/ebilanz-mixed-disposals-native-20260920.json`. Nur Prüfflag 2, kein Zertifikat,
  kein Versand. Im ersten Prüflauf trat beim anschließenden Negativtest ohne Anlagen
  eine nicht initialisierte Jahresvariable auf; behoben und alle Suiten wiederholt.

## Serverprüfung und Grenzen

Die komplette abgegrenzte Bilanz-MySQL-Regression einschließlich zweier neuer
Mischabläufe ist erfolgreich. Nachweis:
`audit/ebilanz-mysql-host-20260918-b17395cea860a110.json`.

- PHP 8.3.33 / MySQL 8.4.11: alle zehn ausgewählten Suiten bestanden (Schemavergleich,
  Steueranlagen, Ausmusterung und Verkauf jeweils mit/ohne Buchwert, beide Mischfälle,
  Vorjahresvortrag und Ausgangsrechnungs-/Verkaufsablauf).
- Gemischter Ablauf: 105 kombinierte Prüfungen mit Restbuchwert, 106 mit Buchwert null.
  Diese enthalten gemeinsame Basisprüfungen und sind nicht als einzigartige Fälle
  additiv. Echte Anwendungsdienste erzeugen zwei Anschaffungen, deren Abschreibungen,
  Verkaufsrechnung bzw. Ausmusterungsbeleg und die beleggebundenen Kontennachweise.
- Quellen-Prüfsummen bleiben nach Übernahme identisch. Im festgeschriebenen
  Anlagenverzeichnis stehen zwei unterschiedliche interne Konten mit Nummer 68950.
  Steuerpläne, Überleitung, Original-PDF-Erhalt, XML-Erzeugung, Vorstandsfreigabe,
  Offline-Protokoll und Folgejahr ohne erneute Abgänge geprüft.
- Ausschließlich die freigegebene separate Testdatenbank genutzt. Ursprüngliche
  150 Tabellen / 1.393 Datensätze vorher verschlüsselt gesichert und anschließend
  inklusive Strukturen und Triggern identisch wiederhergestellt. Temporärer
  Testcode entfernt; geschützte Produktivdateien unverändert. Originaldaten und
  Sicherungsschlüssel wurden nicht auf den PC übertragen.

Der Testtransport bleibt offline. `sent_test` in diesen Tests ist keine echte
ELSTER-Übermittlung. Die native Prüfung belegt keine materielle Steuerrichtigkeit.

Weiter offen: Teilabgänge, Umbuchungen, weitere Bewertungs-/Steuer-Sonderfälle,
vollständige Kontenabdeckung, Mehrbetrieb/Gesamtverein und vollständige
Installations-/HTTP-/Versandabnahme. Die hier ergänzte Kontenbindung löst insbesondere
nicht die nachträgliche Rekonstruktion alter mehrdeutiger Abgänge.
