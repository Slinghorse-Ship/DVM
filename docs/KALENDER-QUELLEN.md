# Ein gemeinsamer Kalender für Vereinsleben und Kommunikation

**Alles** verbindet geplante Social-Media-Beiträge, Newsletter, Geburtstage,
interne und externe Veranstaltungen, veröffentlichte Website-Veranstaltungen,
Vorstands-/Mitgliederversammlungen, Ausleihen, Dokumentnachweise und
Dokumenten-/HSE-Fristen. Die Filter zeigen dieselben Originalvorgänge in Tag,
Woche, Monat und Agenda. Einträge tragen eine lesbare Quellenbezeichnung und
eine eigene Farbe; ihre Bedeutung ist auch ohne Farberkennung sichtbar.

Social Media und Newsletter zeigen den Titel des ursprünglichen Beitrags und
öffnen dessen Redaktion. Das gilt für die lokale Planung und neu eingeplante
Transportaufträge. Mehrere Empfänger, Storyfolien oder Anbieterkanäle desselben
Originals zur selben Uhrzeit werden gebündelt; unterschiedliche Zustellstände
bleiben lesbar. Ändert sich die Redaktion später, zeigt die Planung ausdrücklich
die frühere Fassung an. Überlappende lokale und Transportplanung erscheint einmal.
Automatische Entwurfsvorschläge, Periodenvorbereitung und Versandfenster bleiben
eigene fachliche Planungstermine.

Die Originalzuordnung neuer Transportaufträge liegt separat unter
`native_delivery_sources`. Sie enthält nur Verein, Modul, Original-ID und Fassung.
Der Kalender liest keine verschlüsselten Mail-/Anbieterinhalte, Zugangsdaten
oder Empfängerlisten. Alte Aufträge ohne Zuordnung behalten eine wahrheitsgemäße
gruppierte Anzeige mit Link zum Verlauf; eine Titelzuordnung wird nicht erfunden.
Die historische Übernahme dieser Zuordnungen bleibt Bestandsmigration.

Geburtstage stammen aus vorhandenen Personenprofilen, öffnen dasselbe Profil
und folgen den aktuellen Profilrechten. Mitglieder sehen ihren eigenen
Geburtstag, Mitgliederverwalter beziehungsweise Leser ihre zugänglichen Profile.
Organisationen und archivierte Profile entfallen. Die Anzeige nennt keinen
Geburtsjahrgang, keine Anschrift und keine privaten Notizen. Die bestehende
Schaltjahrregel verwendet für den 29. Februar in anderen Jahren den 28. Februar.
Datumsbereiche über den Jahreswechsel berücksichtigen beide Jahresvorkommen.

Beim Erfassen eines Termins kann **Externe Veranstaltung** gewählt werden.
Der entsprechende Filter verbindet diese Originaltermine mit veröffentlichten
Website-Veranstaltungen. **Vereinstermine** enthält weiterhin interne Termine;
der bestehende Filter für veröffentlichte Veranstaltungen bleibt erhalten.
Die Kennzeichnung extern verändert keine Teilnehmer- oder Leserechte und
veröffentlicht keinen Termin. Vorhandene interne Termine werden nicht umklassifiziert.
Migration 112 erweitert ausschließlich die erlaubten Terminarten und erhält
die Originaldaten; die Wiederholung und der tatsächliche Vorgängerstand sind geprüft.

Alle Kalenderuhrzeiten und der heutige Tag gelten für Europe/Berlin. Kalenderlesen
erzeugt weder doppelte Termine noch Versand-/Veröffentlichungsaufträge. Der
authentifizierte Browser prüft sechs Quellen, Originalredaktionen, Mitgliedsrechte,
Tastatur und 1440/820/390 Pixel. Der Ablauf ist im wiederholbaren Git-Bootstrap.
Die neue MySQL-Suite prüft Originalzuordnungen, Zustellbündelung, fehlende Originale,
Schaltjahre, Versionswechsel, Vorgängerupgrade und unveränderte Quelldaten:

```sh
bash tools/tests/run-mysql.sh calendar-occasions-publications.php calendar-workflows.php shared-calendar-documents.php
bash tools/cloud/bootstrap.sh --verify
```
