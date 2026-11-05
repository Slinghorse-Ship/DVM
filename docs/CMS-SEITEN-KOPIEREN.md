# Gespeicherte Inhalte als neuen Entwurf kopieren

In den Editoren für Seiten, Aktuelles, Veranstaltungen und Fotoalben steht
berechtigten Redakteuren **Als neuen Entwurf kopieren** zur Verfügung.

1. Ungespeicherte Änderungen vorher sichern.
2. **Zuletzt gespeicherter Entwurf** oder **Veröffentlichte Fassung** wählen.
   Die zweite Auswahl steht bei einer tatsächlich veröffentlichten Quelle bereit,
   auch bei einem ausschließlich im RSS-Feed veröffentlichten Beitrag.
3. Einen Titel und eine eigene freie Adresse eingeben. Beiträge verwenden
   `aktuelles/eigener-name`, Alben `galerie/eigener-name`.
4. **Unveröffentlichten Entwurf anlegen** öffnet die Kopie im passenden Editor.
   Inhalte, Termine und Angaben prüfen und bewusst weiter bearbeiten.

Mit **Unterseiten mitkopieren** wird die gesamte gewählte gespeicherte Hierarchie
bis zu 500 Seiten kopiert. Die Wurzeladresse darf dabei höchstens 120 Zeichen
enthalten. Jede Unterseite erhält eine eigene Adresse mit ihrer ursprünglichen
Kennung als eindeutiger Endung; Beiträge und Alben bleiben in ihren jeweiligen
Adressbereichen. Der Zusammenhang der Seiten wird durch die Elternzuordnung
erhalten und hängt nicht vom Adresspfad ab.

Bei **Zuletzt gespeicherter Entwurf** werden die aktuellen gespeicherten
Elternzuordnungen verwendet. Bei **Veröffentlichte Fassung** gelten die tatsächlichen
Elternzuordnungen und Inhalte der veröffentlichten Originalrevisionen, einschließlich
reiner RSS-Fassungen. Nur privat angelegte Unterseiten gehören nicht zu dieser
zweiten Auswahl. Nachträglich privat verschobene Seiten bleiben Bestandteil der
veröffentlichten Originalhierarchie.

Typisierte Seitenverweise innerhalb des Teilbaums zeigen anschließend auf die neuen
Kopien, einschließlich Vorwärts-, Rück- und Selbstverweisen. Äußere Seitenverweise,
Bilder, Originaladressen und gewöhnlicher Text bleiben erhalten. Die Kopie der
Wurzelseite behält ihre äußere Elternzuordnung. Ein Fehler in irgendeiner Unterseite
nimmt die gesamte Anlage und ihre neuen Revisionen zurück; es gibt keinen
abgeschnittenen oder teilweise angelegten Baum.

Alle Inhaltseditoren zeigen gespeicherte **Unterseiten** mit direktem Weg in den
passenden Seiten-, Beitrags-, Veranstaltungs- oder Albumeditor. Die Liste liest
25 Unterseiten pro MySQL-Seite, zählt denselben Bestand und erhält beim Blättern
die konkrete Elternseite. Archivierte, private und veröffentlichte Seiten zeigen
ihren jeweiligen gespeicherten Zustand.

Die Einzelkopie erhält eine neue Kennung und Versionshistorie. Veröffentlichungen,
Freigaben, Ankündigungs-/Versandpläne, Importbindungen und bestehende öffentliche
Termine werden durch Kopieren nicht angelegt oder übernommen. Beiträge benötigen
ihre eigene neue redaktionelle Prüfung. Die Kopie steht zunächst außerhalb des
Menüs; die bisherige Elternseite bleibt ihre Zuordnung.

HTML, zusätzliche typisierte Felder, SEO-Angaben, Bilder, Bildreihenfolge,
Beschriftungen und Schlagwörter bleiben erhalten. Bild-/Seitenreferenzen zeigen
weiter auf die vorhandenen Originale. Das Bearbeiten der Bildbeschriftung einer
Kopie verändert die Beschriftung des ursprünglichen Albums nicht. Die tatsächlichen
Bilddateien werden durch Kopieren nicht ersetzt.

Für Teilbäume erfolgen Anlage und Umhängung in zwei Durchgängen derselben atomaren
Transaktion. Erst werden alle neuen Kennungen mit den normalen Prüfungen angelegt,
dann die internen Verweise auf bereits bestehende neue Kennungen gesetzt. Seiten
mit geänderter Zuordnung erhalten dafür eine zweite private Revision. Die erste
Kopierfassung bleibt in ihrer neuen Historie nachvollziehbar; Originalrevisionen
werden dabei nicht ersetzt oder umgeschrieben.

Eine inzwischen geänderte Quelle, alte geänderte Inhaltsdefinitionen, belegte oder
historisch geschützte Adressen und entzogene Rechte stoppen die Anlage. Bei einem
Formularfehler erscheint eine konkrete Meldung mit HTTP 422; das Kopierformular
bleibt mit den eingegebenen Werten zum Korrigieren offen. Zwei gleichzeitige
Aufrufe für dieselbe neue Adresse erzeugen genau eine Kopie.

`Cms/PageCopies` führt Quelle, Modell- und Adressprüfung sowie Anlage unter
derselben Vereinstransaktion aus. `Web/Website/PageCopyPanel` liefert das gemeinsame
Bootstrap-6-Formular; der bestehende Manager bindet aktuelle Rechte und CSRF und
öffnet eigene Vorlagen weiterhin im generischen Seiteneditor.
`native-page-copies.php` prüft 47 MySQL-/Controllerfälle einschließlich echter
gleichzeitiger Prozesse, äußerer Transaktionsrücknahme und Original-/Bildschutz.
Der Browser prüft 24 wirkliche Entwurf-/Veröffentlichungskopien für alle vier
Inhaltstypen bei 1440/820/390 Pixeln, Konfliktkorrektur und Rollen.

`Cms/CopyInput` teilt die geschlossene Eingabeprüfung. `TreeCopySources` liest nur
den gewählten Teilbaum, `TreeCopyAddresses` begrenzt eindeutige Adressen und
`TreeCopies` verbindet die vorhandene Seitenanlage unter demselben Vereins-/
Modellmutex. `Web/Website/PageChildrenPanel` liefert allen Editoren dieselbe
begrenzte Hierarchienavigation. Eine neue Datenbankschemaversion ist nicht nötig.

`native-tree-copies.php` prüft 74 echte MySQL-/Controllerfälle einschließlich einer
vollständigen 500-Seiten-Kopie, abgewiesenem 501-Seiten-Baum, gemischten Vorlagen,
Originalpixeln, tatsächlicher Konkurrenz, RSS-/Selbstverweisen, Modellkonflikten
und vollständiger Rücknahme. Der Browser prüft 165 tatsächliche Abläufe mit sechs
Teilbaumkopien und 27 neuen Seiten bei 1440/820/390 Pixeln, richtigen Editoren,
Tastatur-/Checkboxbedienung, erhaltenen Originalbytes, Konfliktkorrektur, Rollen
und Navigation durch einen Bestand mit 55 Unterseiten. `--verify` des Cloud-
Startskripts bereitet dafür ausschließlich eigene synthetische Daten vor.

Die direkte private Verschiebe-/Sortierbedienung für alle vier Inhaltseditoren
ist unter [Seiten anordnen](CMS-SEITEN-ANORDNEN.md) beschrieben. Größere
wiederaufnehmbare Stapelabläufe und referenzsicherer Papierkorb bleiben eigene
Arbeitspakete. Gespeicherte HTML-Links werden nicht anhand ihres Textes automatisch
umgeschrieben.
