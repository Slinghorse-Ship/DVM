# Tätigkeitsberichte

Stand: 1. November 2026. Zwei getrennte Berichtsarten unter Organisation → Tätigkeitsberichte.

- Finanzamt: Tätigkeit im Prüfungszeitraum und tatsächliche Verwirklichung der
  Satzungszwecke. Vorbelegt werden der hinterlegte Förderungszweck und durchgeführte
  Kalender-/Website-Tätigkeiten ohne Dopplungen. Zweckverwirklichung, Zielgruppen
  und fehlende Maßnahmen sind sachlich anhand der tatsächlichen Arbeit zu ergänzen;
  der Generator erfindet keine Aktivitäten oder steuerlichen Bewertungen.
- Mitgliederversammlung: Rückblick, Vereinsentwicklung sowie optional Dank und
  Ausblick. Kein automatisch eingemischter Kassenbericht, keine Steuerdaten und
  keine automatische Entlastung oder Beschlussfassung.

§ 63 AO verlangt eine entsprechende tatsächliche Geschäftsführung und deren
Nachweis durch ordnungsmäßige Einnahmen-/Ausgabenaufzeichnungen. Der Tätigkeitsbericht
ersetzt diese Aufzeichnungen nicht. Die Anwendungstrennung ist eine organisatorische
Entscheidung, keine Behauptung zweier zwingend vorgeschriebener amtlicher Formulare.
Grundlage: https://www.gesetze-im-internet.de/ao_1977/__63.html

## Den Bericht bearbeiten

1. Wähle **Finanzamt** oder **Mitgliederversammlung** und einen Zeitraum von
   einem bis drei Kalenderjahren. Ergänze tatsächliche Tätigkeiten und lege den
   Bericht ausdrücklich zur Prüfung vor. Die beiden Berichtsarten bleiben getrennt.
2. Der aktuelle 1. Vorstand öffnet den Bericht und **Bericht bearbeiten**.
   Das Textfeld unterstützt Absätze und Zeilenumbrüche auch ohne JavaScript.
   Speichere den Text, bevor du seine PDF-Vorschau öffnest.
3. Die anschließende Leseansicht zeigt den gespeicherten Text. Vergleiche bei
   Bedarf die ermittelten Tätigkeiten. Ihr Übernehmen ersetzt den gespeicherten
   Text und braucht deshalb eine eigene Bestätigung.
4. Öffne **Gespeicherten Bericht als PDF ansehen**. Wähle deine hinterlegte
   Unterschrift nur, wenn du sie für genau diesen Bericht verwenden möchtest.
5. Kehre zum Bericht zurück, bestätige die vollständige PDF-Prüfung und wähle
   **Bericht freigeben**. Danach liefert **Freigegebenes PDF herunterladen** die
   gespeicherte PDF-Datei.

Die Übersicht zeigt zwölf Berichte pro Seite. Suche nach dem Berichtszeitraum,
filtere nach dem Status und wechsle die Seiten bewusst. Auch ältere Berichte
bleiben erreichbar. Die Hilfe öffnet sich in einem eigenen Fenster und erhält
ungespeicherte Angaben. Feste Beschriftungen folgen der persönlichen Sprache;
dein Berichtstext wird nicht übersetzt.

Bei einem Eingabefehler bleiben der vollständige eigene Text und seine
ursprüngliche Auswahl zur Korrektur erhalten. Bei geändertem Berichtsstand oder
unbestätigter Speicherung ist der alte Auftrag gesperrt; der Text bleibt
kopierbar. Prüfe zunächst das Ergebnis zum ursprünglichen Bericht oder Zeitraum.
Eine erneute Ausführung braucht eine eigene Bestätigung. Ein inzwischen
geänderter Bericht wird dabei nicht durch den alten Auftrag überschrieben.

## Prüfung und Freigabe

Berichtsart und Zeitraum bilden getrennte Entwürfe. GET-Aufrufe schreiben keine
Berichte. Speichern der automatischen Steuer-Vorbereitung legt den Finanzamtsbericht
dem 1. Vorstand vor. Aus der manuellen Steuervorschau kann er ausdrücklich zur
Prüfung vorgelegt werden. Auch beide Berichte direkt vorzubereiten ist möglich.

Nur der eindeutig zugeordnete aktive **1. Vorstand** darf den vorgelegten Text ändern
und freigeben. Ein Superuser ohne dieses Amt ersetzt ihn nicht. Dafür erhält der
Vorstand keinen allgemeinen Finanzzugriff. Finanz-Leser sehen Finanzamtsberichte;
Vorstand/Organisations-Dokumentberechtigte sehen Versammlungsentwürfe. Gewöhnliche
Mitglieder erhalten weder Entwurfszugriff noch eine automatische Veröffentlichung.

Der Vorstand öffnet das gespeicherte PDF und bestätigt dessen vollständige Prüfung.
Eine zusätzliche, standardmäßig nicht gesetzte Auswahl erlaubt die Verwendung
seiner hinterlegten Unterschrift ausschließlich für genau diese Berichtsfassung.
Ohne diese Auswahl bleibt eine Zeile zum handschriftlichen Unterschreiben.
Eine Abbildung ist keine qualifizierte elektronische Signatur und keine ELSTER-Abgabe.

Die Freigabe bindet Berichtsart, Textversion, Quellenstand, persönlichen Vorstand,
Vorstandszuordnung und ggf. Bildversion. Geänderte Quellen/Unterschrift oder ein
Amtswechsel zwischen Vorschau und Freigabe verlangen eine neue Prüfung. Spätere
Textänderungen heben die aktuelle Freigabe auf; alte Fassungen/PDFs bleiben historisch
erhalten. Eine bereits korrekt erteilte Freigabe bleibt als historischer Vorgang
bei einem späteren Amtswechsel erhalten, ohne künftige Unterschriftsnutzung zu erlauben.

## Export und Betrieb

Nur der aktuell freigegebene Finanzamtsbericht gelangt als PDF automatisch ins neue
Steuerpaket. Nicht freigegebene Fassungen werden dort ausschließlich als gekennzeichneter
Textentwurf geführt. Die Mitgliederversammlungsfassung wird nicht automatisch angehängt.
Alte archivierte Steuerpakete werden nicht umgeschrieben. Versand ist ein eigener Vorgang.

Schema 90 fügt ausschließlich `activity_report_reviews` und
`activity_report_revisions` hinzu. Beide sind mandantengebunden und werden vom
verschlüsselten MySQL-Backup erfasst. Die wiederhergestellten PDF-/JSON-Daten wurden
mit synthetischen MySQL-Daten bytegenau verglichen.

Historischer Nachweis vom 15. September 2026:

Tests: `activity-report-review.php` (59), `tax-auto-preparation.php` (58),
`tax-preparation.php` (20), `tax-preparation-flow.php` (23),
`tax-certificate-settings.php` (128), `manager-navigation.php` (274).
Mehrseitige synthetische PDF-Fassungen beider Berichtsarten visuell geprüft.

Live aktiviert am 15.09.2026: `20260915-activity-reports-6d38df5f`, Schema 90.
Vorherige Datenbank und betroffene Quelldateien verschlüsselt gesichert.
1816 andere Release-Dateien unverändert; die überprüften Bestandszahlen ebenfalls.
Beide Ansichten und der Finanzamtsentwurf mit echten Website-Veranstaltungen wurden
lesend im Browser geprüft. Beide PDF-Arten zusätzlich unter PHP-FPM 8.3.33 mit
ausschließlich synthetischem Inhalt erzeugt. Kein echter Bericht wurde angelegt,
freigegeben, unterschrieben oder versendet. Der kurzlebige PDF-Prüfendpunkt wurde entfernt.
