# Automatische EÜR-Vorbereitung: geprüfter Teilumfang

Entwicklungsstand vom 15.09.2026. Lokal mit synthetischen Daten geprüft, nicht
auf Kulturhus.de ausgerollt. Keine echte Steuererklärung abgegeben.

## Ablauf

Finanzen → Steuer-Vorbereitung → **Für unseren Verein automatisch vorbereiten**
(`?view=finance&tab=tax&tax_auto=1`).

1. Abgabejahr und angeforderten Prüfungszeitraum wählen (ein bis drei Kalenderjahre).
2. Jahreszahlungen, Sphären, Konten, Geldbestände und Tätigkeitsbericht werden
   aus den vorhandenen Daten ermittelt. Ein zum Abgabejahr passendes vorhandenes
   Unterlagenpaket liefert die bisherigen Jahresantworten; bestehende Archive
   bleiben unverändert. Gespeicherte Vereinsdaten werden vorgeschlagen.
3. Offene Angaben ergänzen, speichern und neu berechnen. Bereits ausgefüllte
   Vereinsfragen bleiben unter „Vorbelegte Angaben prüfen oder ändern“ verfügbar.
4. Jahresunterlagen als Paket ablegen oder nach Klärung aller Blocker KSt 1 / Gem
   vorbelegen. Danach gelten weiterhin native Prüfung, vollständige Vorschau,
   explizite Freigabe und die getrennten Test-/Produktivgrenzen.

Die Formularübernahme ist **keine vollständige automatische Steuererklärung**.
Das KSt/Gem-2025-Profil unterstützt keine direkten PDF-Anhänge. Tätigkeitsbericht,
Jahresaufstellungen und angeforderte Nachweise müssen über das gesonderte
Nachreichungsverfahren eingereicht werden. Das Ablegen eines ZIP-Pakets zählt
nicht als Einreichung. Weitere nötige Verfahren stehen ausdrücklich daneben.

## Tatsächlich implementierte amtliche Zuordnung

- Exakt `KSt_30_2025`, an den überprüften Schema-Hash gebunden.
- Bestätigter eingetragener, vollumfänglich steuerbefreiter Kulturverein,
  kalenderjährliche EÜR, kein individueller Lohnsteuerabzug / Minijob im einfachen
  Fall, ausdrücklich bestätigte Behandlung ohne Vorsteuerabzug.
- Vereinsname, Anschrift/Absender, kanonische 13-stellige Steuernummer und
  Finanzamtskennung, Sitz, Geschäftsleitung, Rechtsform, Steuerbefreiung,
  Prüfungszeitraum, Kulturzweck mit/ohne abziehbare Mitgliedsbeiträge,
  Satzungs-/Beitragsnachweisstatus, Gesamteinnahmen je Prüfungsjahr,
  verneinte ausgewählte Sondertatbestände.
- Herkunft pro Formularfeld, genaue Centwerte und Ganz-Euro-Vorschläge bleiben
  im Entwurf nachvollziehbar. Ganz-Euro-Vorschläge werden erst nach ausdrücklicher
  Bestätigung übernommen; das ist keine behauptete universelle Rundungsregel.
- Grenzvergleich für den Gem-2025-Zweig berücksichtigt Zweckbetrieb und
  wirtschaftlichen Geschäftsbetrieb zusammen, mit **unrunden Centbeträgen**.
  Diese Verzweigung ist nicht mit einer allgemeinen Steuerpflichtentscheidung
  gleichzusetzen. Über 45.000 Euro erfolgt keine einfache Formularübernahme.

## Bewusst nicht automatisch entschieden

Keine Übernahme der 2025-Regeln für 2026 oder spätere Jahre. Keine automatische
GK/ZVE-, EÜR/AVEÜR-, Umsatzsteuer-, Gewerbesteuer- oder E-Bilanz-Feldberechnung.
Ein Zahlungsüberschuss ist nicht automatisch ein steuerlicher Gewinn.

Private Vorleistungen benötigen ein belegtes ursprüngliches Zahlungsdatum und
eine abgestimmte Jahreszuordnung; `requested_on` und Erstattungsdatum sind dafür
kein Ersatz. Betroffene Rechnungen sind im Assistenten direkt verlinkt. Solche
Fälle blockieren derzeit die einfache Übernahme – die erforderliche ergänzende
Erfassungs-/Zuordnungslogik ist noch nicht implementiert. Sachspenden, andere
nicht eindeutig zuordenbare Geldbewegungen, andere/mehrere Satzungszwecke,
Rücklagen und besondere Zuwendungen brauchen ebenfalls gesonderte Bearbeitung.

Forderungen, Verbindlichkeiten und Sachvermögen werden nicht aus fehlenden
Datensätzen als null erfunden. Bestätigte Abschreibungsjahre werden angezeigt,
aber nicht als vollständiges Inventarvermögen ausgegeben. Ungeklärte steuerliche
Korrekturen und unvollständige Kontierungen blockieren die Übernahme.

## Daten- und Abgabeschutz

Migration `089_tax_auto.sql` ergänzt ausschließlich `tax_auto_cases`.
Versionsgeprüfte Speicherung des Entwurfs und des bestehenden Steuerprofils
erfolgt atomar. Finanzleser können lesen, aber weder speichern noch neue Pakete
ablegen; fremde Vereine haben keinen Zugriff.

Formulare sind an Quellenhash, Fallversion und Nutzdatenhash gebunden. Ein Cent
Änderung, andere Stammdaten, Nachweisversionen oder geänderte Steuerantworten
erfordern eine neue Vorschau. Vor Validierung und beim transaktionalen Claim
einer Abgabe wird die Quelle erneut geprüft. Neue Fassungen ersetzen keine
alten Prüfprotokolle; überholte Entwürfe können nicht gesendet werden.
Schreibgeschützte Formularwerte werden nur über den Assistenten neu erzeugt.

Ein Doppelklick öffnet denselben passenden Entwurf. Unklare oder bereits
gesendete Aufträge werden nicht überschrieben. Testkopien aus Quellen sind nur
mit `VEREIN_TAX_AUTO_TEST=synthetic-only`, außerhalb von `VEREIN_MODE=production`
und zusätzlicher Bestätigung der erfundenen Testdaten möglich. Normale Umgebungen
erzeugen nur Produktionsentwürfe; das öffnet **nicht** die Produktivübermittlung.

## Verifikation und Live-Rollout

- `tests/tax-auto-mapping.php`: reine Zuordnung, Grenzen, unbekannte Werte.
- `tests/tax-auto-preparation.php`: isolierte MySQL-Datenbank, Rollen,
  Versionskonflikte, Quellenänderung, keine Sendung, archivierte Unterlagen,
  Sonderfälle, Wiederherstellung einschließlich neuer Tabelle.
- `tests/tax-auto-browser.cjs`: synthetische HTML-Fixture, Desktop/Mobil,
  Beschriftung, dynamische Jahresangaben; sämtliche Netzwerkanfragen gesperrt.
- `tests/tax-auto-native.py --php <php> --runtime <private-runtime.json>` mit
  `VEREIN_ERIC_TEST=synthetic-only`: echte amtliche ERiC-Validierung der
  automatisch gemappten Testdaten. 29 Felder, Rückgabecode 0, keine Prüfmeldungen,
  kein Versand. SDK/Katalog/Lizenzen bleiben außerhalb des Repositorys.

Am 15. September 2026 wurden die Migrationen 087/088/089 ergänzend auf dem
Server installiert. Die Vorbereitung und native Prüfung sind online; echte
Abgaben bleiben gesperrt. PHP-FPM erzeugt mit synthetischen KSt- und EÜR-Beispielen
gültige Vorschau-PDFs. Der Browser-Aufruf mit den vorhandenen Daten ist geprüft.
Details: [Live-Abnahme](ELSTER-LIVE-ROLLOUT-20260915.md).

Alte gespeicherte Nachweisverweise werden bei Nichtverfügbarkeit nicht gelöscht:
Der Assistent öffnet trotzdem, zeigt die betroffenen Auswahlen und verhindert
einen fertigen Entwurf bzw. ein unvollständiges neues Unterlagenpaket.
Neun zusätzliche Regressionstests decken diesen Bestandsfall ab (58 insgesamt).

Vor einer echten Abgabe weiterhin Quellenlücken und besondere Fälle klären,
erforderliche Nachreichung abschließen und die Gesamtvorschau fachlich freigeben.
Der Live-Rollout beinhaltet keinen GitHub-Push und keine Steuerübermittlung.

Amtliche Grundlagen: [ELSTER für Vereine](https://www.elster.de/elsterweb/infoseite/vereine),
[jahresgebundene KSt-Hilfe 2025](https://www.elster.de/eportal/helpGlobal?themaGlobal=help_kst_2025)
und der privat installierte lizenzierte ERiC-44.3.6.0-Formularstand.
