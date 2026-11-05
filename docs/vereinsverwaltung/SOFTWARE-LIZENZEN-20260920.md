# Softwarelizenz: vollständiger Abgang und E-Bilanz

Entwicklungsstand 20.09.2026. Nicht produktiv veröffentlicht. Kulturhus bleibt EÜR.

## Abgegrenzter Vorgang

Eine gekaufte, linear abgeschriebene Softwarelizenz auf SKR42 01350 endet
vollständig. Es bleiben keine Nutzungs- oder Übertragungsrechte und es gibt
keinen Erlös, keine Erstattung oder Entschädigung. Enddatum, Begründung und ein
aktiver Finanznachweis mit unveränderter Originaldatei müssen angegeben werden.
Die Bestätigung sämtlicher beendeter Rechte wird gesondert gespeichert und geprüft.

Bloße Nichtnutzung, eine schlechtere wirtschaftliche Bewertung, Verkauf,
Schenkung, Übertragung, laufende Software-Abos und selbst geschaffene Software
sind **nicht** dieser Vorgang. Dafür wird keine automatische Ausbuchung erzeugt.
Ein Ende weiterer Lizenzen wird niemals aus dem Beenden einer Lizenz abgeleitet.

## Durchgängige Umsetzung

- Die Oberfläche bietet bei erworbener Software „Softwarelizenz beendet“ und
  „Nein, vollständig beendet“, keine Geräteverschrottung
  oder nicht unterstützte Verkaufsoption. Datum, Originalnachweis und Grund
  werden erst beim gewählten Abgang benötigt; Restwert und Abschreibung werden
  anhand des Monats angezeigt. Serverprüfung auch ohne JavaScript.
- Expliziter Nachweistyp `software_end`, `rights_ended=true`, `complete=true`,
  `no_proceeds=true`. Keine stillschweigende Umdeutung eines alten `scrap`-Belegs.
- Jahresstand und Audit in einer Transaktion unter der Vereinssperre;
  unveränderter Anschaffungsplan. Jahresabschluss, Quellenbindung und Rollen
  gelten weiterhin. Ein wiederholter Abgang oder eine spätere Abschreibung
  desselben vollständig abgegangenen Wirtschaftsguts werden abgewiesen.
- Abschreibung bleibt auf 62000; der Restwert wird auf **68960** und nicht auf
  das Sachanlagenkonto 68950 gebucht. Die interne Identität des Gegenkontos
  bleibt mit dem Quellen-Snapshot verbunden. Auch bei Buchwert null wird das
  erforderliche Konto für einen eventuell noch positiven Steuerwert reserviert.
- Originaldateien werden bei Jahresfeststellung und Journalübernahme gegen
  ihre SHA256 geprüft, nicht nur anhand gespeicherter Metadaten.
- Bestätigte abweichende steuerliche Nutzungsdauern bleiben separat. Der
  steuerliche Restwert, seine Überleitung und die folgenden Nullbestände werden
  berechnet, ohne den handelsrechtlichen Abschluss umzuschreiben.
- XBRL: Abgang der Software-Anschaffungskosten und der kumulierten Abschreibung,
  Restwert in `is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.intan`.
  PHP und Python prüfen Rechnung, Nachweistyp und Konten unabhängig.
- Bestehende Sachanlagen-Snapshots behalten ihre Konten und Hashes. Keine
  Schemaänderung und keine Lockerung der Produktiv-/EÜR-Sperren.

## Fachliche Quellen und Grenze

Die Konten unterscheiden erworbene Software 01350, selbst geschaffene Software
01440 und die Abgänge immaterieller Werte 68960 von Sachanlagenabgängen 68950.
Grundlage sind die lokal bereits hashgebundenen DATEV-Bilanzkataloge 2025/2026;
die neue Regel ist Revision 7 des eigenen Taxonomie-Mappings, kein neuer
amtlicher Katalog. Quelle:
[DATEV SKR42 Bilanz, Stand Januar 2026](https://www.datev.de/web/de/shop/produkt-details/12901),
[DATEV Original-PDF](https://help-center.apps.datev.de/api/amr/knowledge-common/v1/entities/st64808011275_de.pdf).

Planmäßige Abschreibung, Wertminderung und ein tatsächlicher Abgang werden nicht
gleichgesetzt; [§ 253 HGB](https://www.gesetze-im-internet.de/hgb/__253.html)
ersetzt keinen Nachweis, dass sämtliche Lizenzrechte wirklich beendet sind.
Die Eingabe bleibt eine zu belegende tatsächliche Feststellung, keine
Rechtsprüfung eines Lizenzvertrags durch die Software.

## Technische Prüfungen

- 40 PHP-Prüfungen ohne Datenbank: Berechnung, Folgejahr, Konten, Pflichtnachweise,
  Typen, nicht unterstützte Anlagen und jahresbezogener Katalog.
- 80 JavaScript-Ereignisprüfungen: neue Softwarefelder, erforderliche Angaben,
  Zustandswechsel und bestehende Verkaufs-/Verschrottungsmasken.
- Sieben Python-Testmethoden mit 16 vollständigen Software-/Mischfällen und
  Manipulationen; die übrigen acht Generator-Testgruppen werden mitgeprüft.
  Mischfälle enthalten Geräteverkauf, Verschrottung und Lizenzende gleichzeitig,
  auch mit unterschiedlichen Steuerwerten, Centbeträgen und Folgejahr.
- Native ERiC-Validierung unter Windows: 15 Positivfälle Code 0; manipulierte
  Bilanzsumme in beiden Läufen mit 610001002 abgewiesen. Kein Versand.
  `audit/ebilanz-native-software-end-20260920.json` und
  `audit/ebilanz-native-software-mixed-20260920.json`.
- Webserver/PHP-CGI 8.3.33 → Adapter → Python → Linux-ERiC: alle **16 Fälle
  Code 0**, kein Datenbankzugriff, kein Versand. Anonymer Aufruf zurückgewiesen;
  temporärer Endpunkt und privater Prüfbereich entfernt, Produktionsdateien
  unverändert. Nachweis:
  `audit/ebilanz-software-webserver-20260920-729d686254aca5ac.json`.

- MySQL: `balance-software-end.php` **124 Prüfungen**, Nullbuchwertvariante
  **125 Prüfungen**, beide erfolgreich. Darunter Rollen-/Vereinsgrenzen,
  doppelte Abgänge, beschädigte Originaldateien, exakte steuerliche Restwerte,
  Folgejahre sowie wiederhergestellte Tabellen und Trigger. Übermittlung hier
  nur als Offline-Fixture, keine tatsächliche Steuerabgabe. Nachweis:
  `audit/ebilanz-mysql-host-20260918-0e6390b0f687e9df.json`.
- Neun bestehende MySQL-Regressionsgruppen bestanden im vorhergehenden Lauf
  `audit/ebilanz-mysql-host-20260918-e5c711459a0d0545.json` (707 Prüfungen,
  einschließlich wiederholter Basistests). Dieser gesamte frühere Lauf war
  **nicht** grün: Die beiden neuen Fixture-Gruppen scheiterten damals an der
  absichtlich getesteten Dateibeschädigung. Ihre Korrektur wurde anschließend
  mit den oben genannten 124/125 Prüfungen abgenommen.
- Die zuvor vorhandene separate Testdatenbank wurde nach jedem Lauf identisch
  wiederhergestellt (150 Tabellen, 1.393 Datensätze). Vorher verifiziertes,
  verschlüsseltes Backup. Kein Zugriff auf die produktive Vereinsdatenbank;
  geschützte Produktionsdateien unverändert; temporäre Prüfverzeichnisse entfernt.
- Alle neun Python-Generator-Testgruppen: **141 Testmethoden bestanden**.
  **844 PHP-Dateien** ohne Syntaxfehler; 70 Laufzeit-Fingerprint-Prüfungen
  bestanden. 34 Fingerprint-Dateien und neun Python-Abhängigkeiten geschlossen.
- Browserprüfung des tatsächlich gerenderten synthetischen Formulars mit
  Produktions-Styles: Desktop und 390-Pixel-Handyansicht ohne horizontalen
  Überlauf. Fortbestehende Lizenz hat keine versteckten Pflichtfelder;
  Monatswechsel aktualisiert Abschreibung/Restwert. Fehlende Rechtebestätigung
  bleibt ungültig. Kein Browser-POST und kein produktiver Datensatz.
  Ein zu langer Auswahltext wurde auf „Nein, vollständig beendet“ gekürzt;
  „Grund des Abgangs“ heißt bei Software „Warum endet die Lizenz?“.
  Nachweis: `audit/ebilanz-software-ui-20260920.json`.
- Entwicklungs-Quellmanifest einschließlich der neuen Dateien aktualisiert;
  2.060 Dateihashes und statische Quellabhängigkeiten geprüft. Kein Upload,
  kein Commit, kein neues Installations- oder Live-Release in diesem Schritt.

Die beiden frühen fehlgeschlagenen Fixture-Läufe bleiben als Diagnose erhalten,
nicht als Abnahme: zuerst fehlte eine Formatierhilfe im isolierten Render;
danach verhinderte der native Unveränderlichkeitstrigger die Simulation einer
beschädigten Originaldatei. Der korrigierte Fehlertest weist diesen Schutz zuerst
nach und nimmt ihn ausschließlich in der isolierten Testdatenbank zeitweise aus;
Originalbytes und identische Triggerdefinition werden im `finally` wiederhergestellt.
Produktions-Trigger werden niemals verändert. Die abschließende Textkürzung und
der realitätsgetreue Formularrahmen ändern weder Berechnung noch Datenbankzugriffe.

## Weiterhin keine allgemeine Freigabe

Die gesamte Bilanzierung ist damit nicht fertig. Vollständige SKR42-Abdeckung,
weitere Teilabgänge/Teilverkäufe, Transfers/Schenkungen/Entschädigungen,
Sammelposten und Sonderabschreibungen sowie Änderungen vorgetragener Steuerpläne
bleiben gesonderte Entwicklungs- und Abnahmeaufgaben. Ein positiver ERiC-Test
ersetzt weder diese Umsetzung noch eine fachliche Prüfung.

Der E-Bilanz-Geltungsbereich bleibt ein separat bilanzierter einzelner
Geschäftsbetrieb mit Kalenderjahr 2025/2026, nicht eine beliebige Gesamtvereinsbilanz.
Kein allgemeines Live-Release, kein echter Steuer-/Bank-/Mailversand durch diesen
Entwicklungsschritt. Die vollständige Bedienbarkeitsprüfung aller Menüs erfolgt
erst nach der tatsächlichen Gesamtfreigabe; Kulturhus wird nicht auf Bilanz umgestellt.
