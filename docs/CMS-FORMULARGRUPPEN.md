# Angaben einer Seitenvorlage in Formulargruppen ordnen

Unter **Website → Felder & Vorlagen** eine Seitenvorlage öffnen. Mit
**Gruppe hinzufügen** einen Namen und bei Bedarf einen erklärenden Hinweis
eingeben. **Übergeordnete Gruppe** legt die Verschachtelung fest. Bis zu 20
Gruppen und drei Ebenen sind möglich. Eine Gruppe kann zunächst zugeklappt sein
und ihre kurzen Angaben in einer oder zwei Spalten anordnen. Auf schmalen
Bildschirmen erscheinen die Angaben untereinander; längere und formatierte Texte
verwenden die ganze Zeile.

Bei jeder zusätzlichen Angabe die gewünschte Gruppe wählen. Die Pfeile ändern
die Reihenfolge der Angaben beziehungsweise der Gruppen. Angaben ohne Gruppe
stehen vor den Gruppen. **Gruppe entfernen** verschiebt ihre Angaben und direkten
Untergruppen in die übergeordnete Gruppe; bei einer Hauptgruppe stehen ihre
Angaben anschließend ohne Gruppe. Alle bisherigen Werte und Verknüpfungen bleiben
erhalten. Eine abgewählte Angabe bleibt als Felddefinition bestehen, wird aber
aus dieser Seitenvorlage entfernt.

**Vorlagenänderung speichern** erzeugt eine neue Definitionsversion. Vor einer
weiteren Veröffentlichung die betroffenen Seitenentwürfe prüfen und bewusst
erneut speichern. Bereits veröffentlichte Originale behalten ihre bisherige
Fassung; auch die Bilddateien bleiben erhalten.

Im Seiteneditor öffnen native Pflichtfehler die betreffende Gruppe und ihre
übergeordneten Gruppen. Das gilt auch für formatierte Texte. Die
[Sicht- und Pflichtbedingungen](CMS-FELDBEDINGUNGEN.md) gelten innerhalb der
Gruppen: eine vollständig ausgeblendete Gruppe verschwindet; ein neu erforderliches
leeres Feld öffnet seine Gruppe. Zuklappen behält die Angaben im Formular und
ändert ihre serverseitige Prüfung nicht. Ohne JavaScript lassen sich die Gruppen
über ihre nativen Überschriften aufklappen, alle Angaben bleiben erreichbar.

## Gemeinsame Module und Nachweise

`Cms/FieldGroups` validiert die geschlossene, versionierte Vorlagenstruktur:
eindeutige eigene Kennungen, einmalige Zuordnung zur selben Vorlage, Eltern,
Kreise, Ebenen, Texte und die 20-Gruppen-Grenze. Die ursprünglichen typisierten
Feldwerte werden unter ihren bisherigen Kennungen gespeichert.

`Web/Fields/Controls` rendert die zwölf ursprünglichen Feldarten gemeinsam;
`Layout` ordnet diese Controls, `GroupForm` enthält die menschenlesbare Bearbeitung
und ihren Formularcompiler. Eigene `field-groups.js` und `field-groups.css` werden
von beiden Redaktionszugängen geladen. Die Anzeige verwendet das lokal
festgelegte Bootstrap 6 Alpha 1.

`native-field-groups.php` prüft 65 MySQL-/Formularverträge einschließlich historischer
Originale, atomarer Ablehnung, Versionen, Kreisen, Grenzen, fremden Feldern,
aktuellen Rechten und dem eigenständigen Verwaltungsrecht. 112 Browserverträge prüfen beide
Redaktionszugänge bei 1440/820/390 Pixeln, tatsächliche private Vorlagen- und
Inhaltsspeicherung, Reihenfolge, Gruppenentfernung, Pflichtfokus, RichText,
JavaScript-freie Bedienung und unveränderte Originalbytes. Die eigenen Fixtures
und Browser sind im aus Git rekonstruierbaren `bootstrap.sh --verify` enthalten.

Wiederholbare Datensätze, Mehrfach-/Relationsfelder, eigene Itemrechte und
-versionen sowie die zugehörige Abfrage-/Importparität bleiben weitere
Registerpunkte. Das vollständige CMS und alle Bedienzustände werden weiter
abgeglichen.
