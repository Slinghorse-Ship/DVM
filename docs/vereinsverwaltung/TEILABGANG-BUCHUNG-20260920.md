# Teilabgang: Buchung, Folgejahre und E-Bilanz

Entwicklungsstand 20.09.2026. Keine Veröffentlichung in der produktiven Anwendung.
Der reine Vorschauzustand der vorherigen Schritte ist für den unten abgegrenzten
Fall durch eine durchgängige Buchungs- und Exportstrecke ergänzt.

## Unterstützter Fall

Ein erstmaliger, belegter Teilabgang ohne Verkaufserlös oder Entschädigung bei
linear abgeschriebener Ausstattung (06300/06500). Die ursprünglichen Kosten des
entfernten Teils müssen belegt sein; eine geschätzte Quote oder Stückzahl genügt
nicht. Nutzung und Nutzungsdauer des verbleibenden Teils bleiben unverändert.

Die Oberfläche führt von Teilkosten und Nachweisen zur berechneten Bestätigung.
Während dieser Bestätigung sind die konkurrierenden Jahres- und Planformulare
sowie die überholten Jahreswerte ausgeblendet. Bei Restwert null im ursprünglichen
Plan wird keine weitere Restwerteingabe verlangt. Änderungen an den vorbereitenden
Angaben sperren die alte Buchungsbestätigung bis zur erneuten Berechnung.
Sie bietet ausschließlich zu diesem Gegenstand bzw. seiner Anschaffungsrechnung
gehörige Originalnachweise an. Jahreswerte, Teilabgang, Journalquellenbindung,
Quellenhistorie und Audit werden unter derselben Vereinssperre gemeinsam gebucht.
Fehlschläge rollen alle Buchungsänderungen zurück; der vorher gespeicherte
Prüfstand bleibt als unveränderliche Vorbereitung erhalten.

Folgejahre führen den verbleibenden Gegenstand fort. Auch vollständig
abgeschriebene Restbestände bleiben mit ihren Anschaffungskosten im Verzeichnis.
Nullwertabgänge erhalten einen Quellennachweis, aber keine erfundenen Geldbeträge.
Ein weiterer Abgang nach diesem Teilabgang wird ausdrücklich zurückgewiesen.

## Steuerwerte und Export

Die ursprünglichen Buchwerte bleiben unverändert. Eine fachlich bestätigte
abweichende steuerliche Nutzungsdauer wird separat berechnet und in Folgejahre
vorgetragen. Teilkosten, kumulierte Abschreibung, Restwertabgang und verbleibende
Buch-/Steuerwerte werden zwischen Journal, Anlagenverzeichnis und XBRL abgeglichen.

Der Python-Export prüft die Rechnung unabhängig mit exakten rationalen Zahlen.
Ein ursprünglicher Anlagenplan einschließlich Hash bleibt mit dem Teilabgang
verbunden. Amtliche Tabellen enthalten Bruttoabgang und abgegangene kumulierte
Abschreibung getrennt. Bereits gespeicherte vollständige Verkäufe/Ausmusterungen
behalten ihre bisherigen Quellenschnappschüsse.

## Bereits nachgewiesen

- PHP-Rechenkern: 6.941 Prüfungen.
- Unabhängiger monatlicher Vergleich: sechs Testmethoden, darunter 600 zufällig
  erzeugte reproduzierbare Fälle; PHP und Python-Export stimmen mit dem Oracle überein.
- Generator: zehn vollständige neue Fälle, plus gezielte Manipulationen an
  Beträgen, Teilkosten, Nachweisen, ursprünglichem Plan und Steuerwerten.
- Bestehende Generatorregression: 57 allgemeine und 24 separate Steueranlagen-Testmethoden.
- Native ERiC-Validierung: alle zehn neuen Fälle Code 0; manipulierte Gesamtsumme
  mit Code 610001002 zurückgewiesen. Nachweis:
  `audit/ebilanz-native-partial-20260920.json`.
- Webserver/CGI: dieselben zehn Fälle mit Code 0, ohne Zugriff auf eine Datenbank.
- Tatsächlicher **Testversand** des synthetischen steuerlichen Folgejahresfalls
  mit amtlichem Testzertifikat und Testkennzeichen: Code 0, Transferticket
  `eh2630cf79ttb41m0krb62xe3davexo1`. Technischer Eingang, keine steuerliche
  Veranlagung und keine Erklärung des Kulturhus.
  Nachweis: `audit/ebilanz-partial-webserver-20260920-fe4b5caa523df4fd.json`.
- Temporärer Web-Endpunkt und privates Testverzeichnis anschließend entfernt;
  Produktivdateien und private Produktionskonfiguration unverändert.

## Abschließende Integrationsprüfung

- Alle 14 MySQL-Testgruppen bestanden: Teilabgang einschließlich Nullwert,
  unveränderliche Prüfstände, Belegprüfung, Schema-Wiederherstellung, abweichende
  Steuerwerte, vollständige Verkäufe/Ausmusterungen, gemischte Abgänge und Vortrag.
  Insgesamt 1.194 ausgeführte Prüfungen im Gesamtlauf; wiederverwendete Grundfälle
  sind darin enthalten, nicht als jeweils neue fachliche Fälle zu verstehen.
  Nachweis: `audit/ebilanz-mysql-host-20260918-f118063be0bcf677.json`
  (Dateiname des bestehenden Operators, tatsächlich am 20.09. ausgeführt).
- Nach der Oberflächenvereinfachung erneut 58 Teilabgangsprüfungen, anschließend
  mit ergänzter ELSTER-Programmstandbindung erneut 58 Teilabgangs-, 90 kombinierte
  Steueranlagen- und 52 allgemeine Steuerabgabeprüfungen bestanden.
  Letzter Nachweis: `audit/ebilanz-mysql-host-20260918-ac530b82581abb6f.json`.
  Diese allgemeinen Abgabeprüfungen verwenden ausschließlich eine offline
  simulierte Gegenstelle, nicht einen weiteren ELSTER-Versand.
- Die separate Datenbank wurde vor jedem Lauf serverseitig verschlüsselt gesichert
  und danach mit identischen Tabellen, Daten und Triggern wiederhergestellt
  (150 Tabellen / 1.393 vorherige Datensätze). Keine Produktivdatenbankverbindung.
- Browserprüfung der tatsächlich aus der synthetischen MySQL-Fixture gerenderten
  Oberfläche: Desktop und 390px-Viewport (375px nutzbare Dokumentbreite), kein
  horizontaler Überlauf. Nachweise, editierbare Angaben, Pflichtbestätigung und
  Sperre nach Eingabeänderung kontrolliert. Keine Formularübermittlung, kein
  echter Benutzerdatensatz. Dies ersetzt keine produktive HTTP-Ende-zu-Ende-Abnahme.
- 21 zusätzliche UI-Ereignisprüfungen ohne Browser/Netzwerk; 840 PHP-Dateien im
  Gesamtsyntaxlauf fehlerfrei. Die danach hinzugefügte Fingerprint-Testdatei wurde
  separat ausgeführt.
- 34 relevante Programm-/Katalogdateien sind in die Laufzeitprüfsumme eingebunden,
  einschließlich aller neun lokalen Python-Importmodule der Generierungsstrecke.
  70 isolierte Prüfungen belegen, dass eine Änderung oder fehlende Datei die
  Prüfsumme ändert bzw. die Prüfung sperrt. Eine abschließende Ergänzung der
  separaten Anlagenverkaufsberechnung in dieser Dateiliste wurde mit diesen
  isolierten Prüfungen geprüft; sie verändert weder Rechnung noch XBRL-Inhalt.
- Lokales Entwicklungs-Installationsmanifest neu erzeugt: 2.054 Datei-Prüfsummen
  und statische PHP-/Python-Abhängigkeiten geprüft. Bereits benötigte
  Gutschein-Abhängigkeiten aufgenommen, ohne deren Programmcode zu ändern.
  Kein Installer-Paket, GitHub-Release oder Produktivdeployment veröffentlicht.

Frühere fehlgeschlagene Testläufe bleiben als Diagnose nachvollziehbar;
sie sind keine Abnahme des endgültigen Standes.

## Keine allgemeine Produktivfreigabe

Dieser Schritt vervollständigt **nicht** automatisch die gesamte Bilanzierung.
Insbesondere bleiben vollständige fachliche SKR42-Taxonomieabdeckung sowie weitere
Anlagen-/Steuerfälle (weitere Teilabgänge, Teilverkäufe, Umbuchungen, Schenkungen,
Entschädigungen, Sammelposten/Sonderabschreibungen und Änderungen bereits
vorgetragener Steuerpläne) eigenständige Entwicklungs- und Abnahmeaufgaben.
Der unterstützte E-Bilanz-Geltungsbereich bleibt der ausdrücklich getrennt
bilanzierte einzelne Geschäftsbetrieb, nicht beliebige Gesamtvereinsbilanzen.

Kulturhus bleibt produktiv EÜR; Bilanzschalter und produktive Schemafassung werden
durch diese Tests nicht geändert. Keine automatische Echtabgabe und kein
pauschales Freischalten durch einen erfolgreichen technischen Test.
