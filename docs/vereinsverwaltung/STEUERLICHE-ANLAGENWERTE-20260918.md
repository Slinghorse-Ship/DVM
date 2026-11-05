# Getrennte steuerliche Anlagenwerte – Entwicklung 18. September 2026

Fortsetzung am 20.09.2026: Der zuvor offene vollständige Verkauf bei abweichender
linearer Nutzungsdauer wurde ergänzt. Aktuelle Prüfungen und Grenzen stehen in
[STEUERLICHER-ANLAGENVERKAUF-20260920.md](STEUERLICHER-ANLAGENVERKAUF-20260920.md).
Die folgenden Zahlen und offenen Punkte beschreiben den Stand vom 18. September.

Nicht produktiv veröffentlicht. Die E-Bilanz-Echtabgabe bleibt gesperrt.
Kein Zugriff auf die produktive Datenbank, keine echte Erklärung, keine Bank- oder Mailaktion.

Ergänzung im anschließenden Entwicklungsschritt: Vollständige Ausmusterung ohne Erlös
bei abweichenden linearen Steuerwerten. Die folgenden Zahlen dokumentieren den vorherigen
Teilstand; neue Prüfungen und Grenzen stehen in
[STEUERLICHE-AUSMUSTERUNG-20260918.md](STEUERLICHE-AUSMUSTERUNG-20260918.md).

## Abgegrenzter Funktionsumfang

- Eine ausdrücklich fachlich bestätigte, abweichende **lineare Nutzungsdauer** für
  weiterhin vorhandene Ausstattung (06300/06500) oder erworbene Software (01350).
  Gleiche Anschaffungskosten, gleicher Restwert und Anschaffungszeitpunkt; nur die
  Dauer unterscheidet sich. Gesondert bilanzierter Geschäftsbetrieb, Bereich 4,
  Kalenderjahre 2025/2026. Keine automatische steuerliche Wahlrechtsentscheidung.
- Der festgeschriebene Buchabschluss und ursprüngliche Anlagenplan bleiben unverändert.
  Steuerliches Anlagenverzeichnis, Anfangsunterschied, Jahresabschreibung und steuerliches
  Ergebnis werden separat berechnet. Die Überleitung enthält ausgeglichene Buchungszeilen
  und Herkunfts-Prüfsummen. Python prüft die PHP-Berechnung unabhängig erneut.
- Bei bestätigtem Vorjahresvortrag werden vorhandene Steuerpläne unverändert übernommen;
  der Anfangsunterschied wird nicht doppelt erfasst. Vorjahrespläne können nicht durch
  Formularauslassungen oder manuelle Korrekturen entfernt oder geändert werden.
- Aufklappbarer Bereich „Für die Buchhaltung: abweichende Abschreibung“ mit Gegenstand,
  Jahren, Grund/Nachweis und ausdrücklicher Bestätigung. Ein eindeutig vorhandenes
  Abschreibungskonto wird vorausgewählt. Bei Mehrdeutigkeit ist die Auswahl erforderlich.
  Buch- und Steuerwerte stehen nebeneinander. Nicht betroffene Nutzer bleiben beim
  bisherigen Ablauf. Änderungen löschen XML-Prüfung/Freigabe und verlangen erneute Prüfung.
- Neue beleggebundene Abschreibungsübernahmen trennen Software (62000) und Ausstattung
  (62200); GWG bleibt 62600. Bereits gespeicherte Quellen ohne den neuen Kontierungsmarker
  behalten exakt ihr allgemeines Konto und ihre Quell-Prüfsumme. Keine Umschreibung alter
  abgeschlossener Journale. Solche alten allgemeinen Konten sind nicht automatisch als
  eindeutig zugeordnete Konten des neuen Steuerplan-Assistenten verwendbar.
- Die vorhandene MySQL-JSON-Ablage wird verwendet; keine neue Datenbankmigration.

Die lineare Verteilung und monatliche Kürzung im Anschaffungsjahr orientieren sich an
[§ 7 Absatz 1 EStG](https://www.gesetze-im-internet.de/estg/__7.html). Die geeignete
Nutzungsdauer und die Zulässigkeit einer Abweichung muss die Buchhaltung fachlich belegen.
Der Assistent bestätigt dies nicht selbst und ersetzt keine fachliche Jahresabschlussprüfung.

## Prüfungen

- 68 reine PHP-Prüfungen der Steuerpläne einschließlich negativer Eingaben,
  Unveränderlichkeit, Cent-Rundung, übernommenem Anfangswert und doppelten Korrekturen.
- 14 neue Python-Testmethoden mit Unterfällen, zusätzlich 96 vorhandene Generator-/
  Verkaufs-/Abgangs-/AfA-/Umsatzsteuer-Testmethoden und 8 Bridge-Prüfungen.
- 7 synthetische Fälle bestehen die native ERiC-Validierung mit Code 0:
  langsamer/schneller, Altanlage, Software, Cent-Rundung/Restwert, handelsrechtlich
  bereits abgeschriebene Anlage und Folgejahr. Verfälschte Bilanzsumme wird abgewiesen.
  `audit/ebilanz-tax-assets-native-final-20260918.json`; nur Prüfflag 2, kein Versand.
- 15 Kontierungs-/Bestandskompatibilitätsprüfungen; 21 PHP-Ansichtsprüfungen;
  88 Browserprüfungen bei 390/1280 Pixeln mit blockiertem Netzwerk, ohne Formularversand.
  Darstellung und Pflichtfelder des neuen Teilformulars geprüft, keine komplette
  HTTP-End-to-End-Abnahme der Anwendung.
- Weitere bestehende PHP-Prüfungen für Steuerwerte, Anlagenverzeichnis,
  Zuordnungen, Rückmeldungsstatus und Abgangsbezeichnungen erfolgreich.

Die separate, ausdrücklich freigegebene MySQL-Datenbank wird vor jedem Serverlauf
verschlüsselt gesichert und anschließend auf identische Daten, Strukturen und Trigger
wiederhergestellt. Originaldaten und Schlüssel bleiben auf dem Server. Testläufe und
Fehler bleiben in den Audit-Protokollen erhalten; ein fehlgeschlagener Lauf wird nicht
nachträglich als Erfolg dargestellt. Die Tests verwenden einen Offline-Transportersatz;
ein gemeldeter `sent_test`-Status aus dieser Suite ist **kein** tatsächlicher ELSTER-Versand.

### Abschließender Serverlauf

`audit/ebilanz-mysql-host-20260918-8cde3ec6b88fa1fb.json` ist vollständig erfolgreich:

- PHP 8.3.33 / MySQL 8.4.11: 90 kombinierte Abgabe-/Steueranlagen-Prüfungen,
  einschließlich realem Rechnungs- und Anlagenquellenadapter, tatsächlichem XML-Generator,
  Berechtigungen, Entwurfsänderung, Vorstandsfreigabe, Vorjahresplan und Restore.
- 78 kombinierte Prüfungen des vorhandenen Abgabe-/Vorjahresablaufs.
  Beide Zahlen enthalten dieselben 62 Basisprüfungen; nicht additiv als einzigartige
  Fälle zählen.
- 140 kombinierte Rechnungs-/Verkaufsprüfungen nach der geänderten AfA-Kontierung
  sowie vier Schemavergleichsprüfungen erfolgreich.
- Ursprüngliche 150 Tabellen / 1.393 Datensätze inklusive Strukturen und Triggern
  nach dem Lauf identisch wiederhergestellt. Temporärer Testcode entfernt,
  verschlüsselte Sicherung auf dem Server erhalten; geschützte Produktivdateien unverändert.

Frühere fehlgeschlagene Läufe bleiben dokumentiert: zunächst fehlende explizite Python-
Laufzeit im Offline-Testadapter, dann das allgemeine AfA-Konto im echten Quellenpfad,
zuletzt eine im synthetischen Test noch fehlende ausdrückliche Lieferantenkonto-Zuordnung.
Der letzte Punkt wurde nur im Test ergänzt, nicht durch eine pauschale Laufzeit-Zuordnung
unbekannter Konten kaschiert. Der AfA-Quellenpfad wurde bestandskompatibel korrigiert.

Die danach ergänzte Unterdrückung des Teilformulars bei ausschließlich ungeeigneten
Anlagen ist lokal mit den 21 PHP-/88 Browserprüfungen geprüft. Diese reine Anzeigeänderung
ist nicht Bestandteil des hochgeladenen Server-Quellarchivs dieses Laufes.

## Weiterhin nicht abgedeckt

Andere Bewertungsgrundlagen, Nutzungsdaueränderungen während eines fortgeführten
Steuerplans, Teilabgänge/Umbuchungen, besondere Abschreibungen, Sammelposten, Verkauf
mit unterschiedlichen Buch-/Steuerwerten und steuerliche Wahlrechte werden nicht
aus Restwerten geraten. Ebenso offen: vollständige SKR42-/Sonderfallabdeckung,
mehrere Geschäftsbetriebe bzw. Gesamtvereinsbilanz und abweichende Wirtschaftsjahre.

Vor einer Produktivfreigabe sind die noch offenen fachlichen Fälle und die gesamte
Installations-/HTTP-/Berechtigungs-/Wiederherstellungskette abzunehmen. ERiC-Code 0
belegt technische Validierung, nicht materielle Steuerrichtigkeit oder einen Steuerbescheid.
