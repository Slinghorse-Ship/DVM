# Dokumentlöschung und hybride Ausgangsrechnungen

Stand: 28.09.2026. Diese Änderung ist vom noch nicht freigegebenen Bilanzrelease getrennt.

## Dokumente

- Leere, nicht vorgangsgebundene Ordner lassen sich löschen. Unterordner,
  Dateien einschließlich Archiv/Papierkorb und gebundene Systemordner verhindern dies.
- Unzugeordnete Eingangsrechnungen lassen sich in den Papierkorb verschieben und
  wiederherstellen. Endgültiges Löschen ist auch direkt und im Archiv möglich.
- Endgültiges Löschen benötigt die ausdrückliche Bestätigung „Fehlimport oder
  Duplikat – kein benötigter Originalbeleg“ und den eigenen Bestätigungsdialog.
- Bereits verwendete, erzeugte oder verknüpfte Originale bleiben geschützt.
  Historische Zuordnungen und zusätzliche Datenbankreferenzen werden mitgeprüft.
- Endgültiges Löschen entfernt alle Dateiversionen und entbehrliche technische
  Metadaten. Eine gemeinsam benötigte Originalmail bleibt erhalten. Das echte
  E-Mail-Postfach wird nicht verändert. Opaque Anforderungsschlüssel und ein minimaler
  Löschnachweis verhindern erneuten Import desselben gelöschten Anhangs.
- Native MySQL-Originalschutzregeln bleiben aktiv. Migration 105 ersetzt nur fünf
  DELETE-Sperren durch dokument-, versions- und verbindungsgebundene Ausnahmen.
  Die neue Sperre wird vor Entfernung der alten installiert. UPDATE-Sperren bleiben
  unverändert. Berechtigungen verschwinden innerhalb derselben Transaktion; bei
  Fehlern werden Original, Metadaten und Berechtigung gemeinsam zurückgerollt.
- Die Installation ist operatorgeführt, nie aus einem normalen Seitenaufruf.

## Ausgangsrechnungen

Neue Standard-Ausgangsrechnungen werden als ein einziges PDF/A-3b mit eingebetteter
XRechnung-CII erzeugt. KoSIT prüft das XML; Mustang/veraPDF prüfen das hybride
Dokument. Fehler verhindern Ausstellung und Nummernverbrauch. Archiv, Download
und E-Mail verwenden dieselben gespeicherten Bytes; XML ist zusätzlich separat
herunterladbar. Bestehende Originale werden nicht neu erzeugt oder überschrieben.

Für diesen Standard sind deutsche Verkäufer-/Empfängeranschriften ausdrücklich
zu bestätigen; Kontaktangaben und Käuferreferenz sind erforderlich. Ausland und
andere nicht unterstützte Rechnungsfälle werden nicht mit erfundenen Angaben
ausgestellt. Eine technische Validierung ist keine materielle Steuerprüfung.

Gutschrift und Storno erhalten eigene validierte Originale mit Bezug zur
ursprünglichen Rechnung. Historische einfache PDF-Rechnungen behalten ihren
bisherigen Korrekturpfad.

Die Rechnungs-PDF-Laufzeit benötigt ReportLab 4.4.9. Der alte Live-Stand 3.6.12
erzeugte bei fettem Eurozeichen einen PDF/A-Schriftbreitenfehler. Dafür gibt es
eine separate private Rechnungs-Laufzeit, ohne andere PDF-Arten umzustellen.
Serifen-Kopfzeilen verwenden eingebettete Liberation Serif 2.1.5 (OFL 1.1).

## Nachweise

- `audit/ebilanz-mysql-host-20260918-58e8bbb02118bb37.json`: alle elf Gruppen
  erfolgreich, darunter 90 Lösch- und 74 Dokumentprüfungen, 11 Lösch-/Mail- und
  44 Postfachprüfungen, 54 Rechnungsablaufprüfungen. Ausschließlich synthetische
  MySQL-Daten und Fake-Mailtransport; Produktion unverändert.
- `audit/ebilanz-mysql-host-20260918-073d33feb5acfb08.json`: native Konformität
  der sieben PDF-Varianten (28 Prüfungen), Sicherheit der Rechnungsausstellung,
  Bilanzübernahme und DATEV-Korrekturen erfolgreich. Zwei damals fehlgeschlagene
  Anschlussprüfungen sind im späteren Nachweis behoben; dieser frühere Gesamtlauf
  darf nicht als vollständig erfolgreich dargestellt werden.
- 27 Browserprüfungen des eigenen Bestätigungsdialogs erfolgreich. Desktop und
  390-Pixel-Ansicht visuell geprüft: kein Browser-Messagebox-Dialog, Abbrechen
  zuerst fokussiert, keine echte Löschung. Kein echter Nutzertest.
- Tatsächlich erzeugte Rechnungs-, Storno- und Gutschrift-PDFs visuell geprüft.

Keine Bankaufträge, echten Mails oder Steuerabgaben. Kulturhus bleibt EÜR.

## Veröffentlichung

Am 28.09.2026 getrennt veröffentlicht: 59 Code-, Schrift- und Konfigurationsdateien.
Die noch unfertigen Bilanzänderungen wurden nicht mit veröffentlicht. Laufzeit-
Konfiguration, Hauptwebsite, Worker, allgemeiner Datenbanktreiber und bestehende
Bilanzsperre blieben unverändert. Nur die Dokument-Löschregeln wurden separat
installiert; keine globale Schemafortschreibung und keine Geschäftsdatenlöschung.

Die tatsächlich veröffentlichten Klassen bestanden anschließend erneut alle 28
nativen Konformitätsprüfungen auf dem Server mit der privaten Rechnungs-Laufzeit.
Keine Datenbankverbindung bei dieser PDF-Prüfung. Dokumentenansicht, Ordner,
Archiv und neue Rechnungsmaske wurden angemeldet und ausschließlich lesend geprüft;
keine Konsolenfehler in der Rechnungsansicht. Reale Rechnungen wurden nicht erzeugt.

Zusammengefasster Nachweis mit Hashes der lokalen Prüfprotokolle:
[RELEASE-DOKUMENTE-20260928.json](RELEASE-DOKUMENTE-20260928.json).
Das belegt keinen vollständigen Bilanzrelease und keinen echten Nutzertest.
