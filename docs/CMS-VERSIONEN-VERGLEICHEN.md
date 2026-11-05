# Gespeicherte Inhaltsversionen vergleichen

Eine Seite, einen Beitrag, eine Veranstaltung oder ein Album öffnen. Unter
**Gespeicherte Versionen** zwei Fassungen wählen und **Versionen vergleichen**
ausführen. Die Anzeige stellt die geänderten Angaben nebeneinander. Ohne Auswahl
stehen normalerweise die veröffentlichte und die aktuelle Fassung bereit, sonst
die vorherige und die aktuelle Version.

Titel, Text, Veranstaltungsangaben, Bildreihenfolge, Album-Beschriftungen und freie
Felder verwenden die tatsächlich gespeicherten Originale. Auch die damaligen
Felddefinitionen und der damalige Vorlagenaufbau einschließlich Formulargruppen
werden angezeigt. Eine spätere Typ-, Namen- oder Vorlagenänderung schreibt die
historische Anzeige nicht um. Große Dezimalwerte behalten sämtliche gespeicherten
Stellen; „Nein“, eine leere Angabe und ein damals nicht vorhandenes Feld sind
unterscheidbar. Formatierte Texte werden für die Anzeige bereinigt.

Die Historie zeigt jeweils 25 Vorgänge mit Version, Aktion, Datum in UTC und
zugehörigem Konto. **Ältere Versionen** und **Neuere Versionen** erhalten den
geöffneten Inhalt und die gewählte Vergleichspaarung. Bereits gewählte ältere
Versionen bleiben auswählbar, auch wenn sie außerhalb dieser Historienseite liegen.
Vergleichen und Blättern speichern nichts; die öffentliche Fassung bleibt erhalten.
Die Formularnavigation funktioniert in beiden Redaktionszugängen auch ohne
JavaScript. Leseberechtigte sehen Historie und Vergleich ohne Änderungsaktionen.

In den Seiten-, Beitrags-, Veranstaltungs- und Albumabläufen kann eine Fassung
ausdrücklich **Als Entwurf geladen** werden. Das erzeugt eine neue private Revision
und verwendet die bestehenden Bearbeitungsrechte, CSRF- und Versionsprüfungen.
Der bestehende Restore prüft die Werte gegen das aktuelle Datenmodell; ein
inzwischen inkompatibles Modell muss zuerst redaktionell geklärt werden. Das
öffentliche Original wird dadurch nicht ersetzt. Eine Albumfassung stellt Titel,
Beschreibung, Bildreihenfolge, Beschriftungen und Schlagwörter wieder her und
verwendet die vorhandenen Originaldateien. Weder die öffentliche Albumseite noch
die Bildbytes werden geändert. Eine andere Inhaltsart oder eine inzwischen
inkompatible Albumvorlage wird atomar abgewiesen.

## Gemeinsame Module und Nachweise

`Cms/PageVersions` liest die gezählte Historie als begrenzte Metadatenseite und für
einen Vergleich höchstens zwei unveränderbare Inhalts-Snapshots. Seiten und
Revisionen müssen zum aktuell berechtigten Verein gehören. `VersionChanges`
vergleicht die ursprünglichen Typen, Werte, Reihenfolgen und Definitionen ohne
Umwandlung anhand des heutigen Datenmodells.

`Web/Website/PageVersionsPanel` und `VersionValues` werden von allen vier
Inhaltseditoren verwendet. Referenzen lösen nur eigene aktuelle Seiten-/Bildmetadaten
auf und zeigen weiterhin die ursprüngliche Kennung; dies ist kein historischer
Dateibyte-Restore. Eigenes CSS ergänzt das gemeinsame lokale Bootstrap 6 Alpha 1.

56 MySQL-/Darstellungsverträge prüfen historische Definitionen, genaue Werte,
fehlende Felder, fremde Originale, aktuelle Rechte, tatsächliche private
Wiederherstellung und erhaltene öffentliche Originale. Ein separater PHP-Prozess
mit einem 16-MB-Limit zeigt eine Historie mit über 20 MB alten Inhalten und benötigt
2 MB Spitzenspeicher; er lädt 25 Metadaten statt aller Textkörper.

268 tatsächliche Browser-/HTTP-Verträge prüfen beide Redaktionszugänge bei
1440/820/390 Pixeln mit allen vier Inhaltseditoren, Historienblättern,
Originalvergleich, JavaScript-freiem Formular und genau zwei ausdrücklich
veranlassten eigenen privaten Wiederherstellungen. Öffentliche Seiten-, Beitrags-,
Termin- und Albumbytes sowie die ursprünglichen Bildbytes bleiben gleich. Die
eigenen Fixtures und Browser sind in `tools/cloud/bootstrap.sh --verify` enthalten.

`Cms/AlbumVersions` ergänzt die private Album-Wiederherstellung mit atomarer
Vereins-/Versions-/Inhaltsartprüfung vor und nach der bestehenden Restore-Logik.
42 zusätzliche MySQL-/Managerverträge prüfen genaue Albumwerte, acht tatsächliche
Bilddateien/Varianten, falsche Kennungen/Versionen/CSRF, fremde Originale,
Rechteentzug und den Rollback bei inzwischen geänderter Vorlagenart. 104
tatsächliche Browser-/HTTP-Verträge prüfen beide Redaktionszugänge bei drei
Breiten sowie ohne JavaScript mit acht eigenen privaten Wiederherstellungen;
öffentliche Originale und alle acht ursprünglichen Bildvarianten bleiben gleich.

Versionsvergleich, Wiederherstellung und Aufbewahrung verwenden die
dokumentierten Modulrechte und Originalnachweise. Ein Vergleich allein ersetzt
keinen ausdrücklich bestätigten Wiederherstellungsvorgang.
