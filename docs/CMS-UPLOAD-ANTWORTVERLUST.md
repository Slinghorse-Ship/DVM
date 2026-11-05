# Angenommene Uploads und Social-Bildfassungen erhalten

Eine verlorene Antwort der Datenbank bedeutet nicht, dass die Speicherung
gescheitert ist. Der frühere Bereinigungspfad konnte ein bereits angenommenes
PDF oder Foto löschen, obwohl sein Datensatz und die zugeordnete private Seite
bereits gespeichert waren. Das wurde mit tatsächlich ausgeführtem MySQL-Commit
und erst anschließend verlorener Bestätigung isoliert nachgewiesen und korrigiert.

Eigene PDF-/Foto-Uploads, gemeinsame Foto-/PDF-/Seitenvorgänge und die private
Fotowiederherstellung verwenden jetzt dieselbe bestätigte Transaktionsgrenze.
Dateien werden nur nach positiv bestätigtem Rollback bereinigt. Ein bereits
angenommener oder nicht eindeutig bestätigter Commit behält die ursprünglichen
PDF-Bytes und sämtliche Foto-Varianten. Die bestehenden lesenden Upload-/
Wiederherstellungsnachweise können das Ergebnis bestätigen; es wird weder
automatisch erneut gespeichert noch veröffentlicht.

Ein gemeinsamer Upload besitzt seine Datenbanktransaktion und seine neuen
Dateien. Teiluploads besitzen zusätzlich einen eigenen Savepoint und genau
die während dieses Teils registrierten Bereinigungen. Wird ein Teilfehler
bewusst abgefangen, entfernt sein bestätigter Rollback nur dessen neue Dateien.
Ein früherer erfolgreicher Teil bleibt erhalten. Ein nicht bestätigter
Teil-Rollback verhindert auch nach abgefangener Fehlermeldung den abschließenden
Commit des Gesamtvorgangs. Eine positive Gesamt-Rollbackantwort kann danach
seine neuen Dateien bereinigen. Ohne diese Bestätigung bleiben private Dateien
für die ausdrücklich lesende Inventur erhalten.

Die Überprüfung ist unabhängig von optionalen Rechts-/Metadatenregeln und
ändert keine Uploadzahl, Formatgrenze, Quote, Freigabe oder vorhandenen Inhalt.
Keine neue Schemaversion und keine Übernahme von Altbeständen.
Neue unbestätigte Dateien sind kein Beleg einer angenommenen Speicherung und
werden nicht eigenständig als bestehendes öffentliches Original ausgegeben.

41 Fach-/MySQL-Verträge prüfen vier tatsächlich angenommene Commits mit verlorener
Antwort: einzelne Fotodatei, PDF samt privater Seite, gemeinsamer Foto-/PDF-/
Seitenvorgang und private Fotowiederherstellung. Alle vier Varianten bleiben
bytegleich. Zusätzlich: zurückgewiesener Commit mit bestätigtem Rollback,
verlorene Rollbackantwort, abgefangener Teilfehler mit erhaltenem erfolgreichen
Vorgänger, unbestätigter und ausdrücklich falscher SQL-Rollbackwert. Alle
früheren vollständigen Zeilen, öffentlichen Originale, privaten Dateibytes,
Rechte, Quoten und Aufträge bleiben unverändert. Elf betroffene native Suiten
einschließlich tatsächlicher Produktionscontroller bestehen. Zusätzlich sind
211 CLI-Installer-, 52 tatsächliche HTTP-Installer- und 134 verschlüsselte
Wiederherstellungsverträge grün. Der bestehende Fotowiederherstellungsbrowser
besteht mit 72 tatsächlichen Bedienprüfungen und 171 Fachverträgen; keine
Originale, Schemata oder Anbieterzustände geändert.

Allgemeine sichere Behandlung anderer unzugeordneter Dateigruppen, weitere
Formate und übrige Umsetzungs-/Paritäts-/UI-Pakete bleiben eigene offene Arbeit.

## Erzeugte und lokal bearbeitete Social-Bilder

Auch die gemeinsame lokale Social-Erzeugung und das Bildstudio behalten
angenommene PNG-/JPEG-Originale und das genaue bearbeitbare Dokument nach
verlorener Commitantwort. Derselbe positiv bestätigte Rollback begrenzt ihre
Bereinigung auf ihre eigenen neuen Dateien. Ein fremder äußerer Vorgang wird
vor der Erzeugung abgewiesen; kein unbestätigter Abschluss entfernt Dateien.
Ohne bestätigten Rollback bleiben neue private Dateien für die lesende Inventur
liegen; sie sind kein Beleg einer angenommenen öffentlichen Veröffentlichung.

Die lesende Bildhistorie und bestehenden geschützten Originalabrufe bestätigen
die tatsächliche Kennung. Es wird nicht automatisch neu erzeugt, freigegeben
oder veröffentlicht. Frühere Quellen, Gestaltung, Freigaben, Inhalte, Quoten,
Nachweise, Originalzeilen und Dateien bleiben exakt erhalten.

Eine tatsächlich ausgelöste zweite Story-PNG-Schreibstörung hat außerdem einen
unabhängigen Fehler nachgewiesen: die frühere Bereinigung verwendete noch den
JPEG-Pfad der bereits gespeicherten ersten Folie. Beide Pfade sind jetzt vor
jedem Schreiben auf genau die neue Folie gebunden. Ein zweiter Schreibfehler
entfernt keine bereits angenommene erste Folie.

Die Fachprüfung umfasst inzwischen 64 Verträge mit sechs tatsächlich angenommenen
verlorenen Commits, bestätigt/unbestätigt zurückgesetzten Social-Ausgaben,
bewussten äußeren Transaktionsversuchen und dem zweiten Schreibfehler. Der
vorherige Foto-/PDF-Stand `9c43590` besteht vollständig mit allen 166 Suiten;
keine Behauptung eines zweiten Gesamtlaufs nach dieser gezielten Social-Korrektur.
Alle 15 betroffenen nativen Suiten einschließlich 98 tatsächlicher
Produktionscontrollerverträge bestehen nach dem endgültigen Fix.

31 tatsächliche Reparaturpinselbrowser prüfen Touch/Tastatur, Export und eine
neue private Bildfassung mit 162 Fachverträgen. Installer/HTTP/verschlüsselte
Wiederherstellung bestehen anschließend erneut mit 211/52/134 Verträgen.
