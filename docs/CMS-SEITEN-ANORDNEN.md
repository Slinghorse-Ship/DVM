# Seiten verschieben und einordnen

Alle vier Inhaltseditoren bieten berechtigten Redakteuren **Seite verschieben
und einordnen**. Das gilt auch für Seiten mit eigenen Vorlagen und im gemeinsamen
Vereinsmanager. Ungespeicherte Inhalte vorher sichern.

1. Neue übergeordnete Seite im gemeinsamen Seitenverzeichnis auswählen.
   Die Suche erreicht alle Treffer in 25er-Seiten. **Oberste Ebene** löst die
   bisherige Elternzuordnung.
2. Eine Reihenfolge von −100000 bis 100000 angeben. Kleinere Zahlen stehen
   zuerst; gleiche Positionen folgen nach Titel und Kennung.
3. **Anordnung als Entwurf speichern** verwenden. Der passende Editor bleibt
   geöffnet; die Änderung gehört zur neuen privaten Inhaltsrevision.

Die Unterseitenliste verwendet dieselbe numerische Reihenfolge und zeigt die
gespeicherte Position. Sie zählt den vollständigen Bestand und lädt weiterhin
höchstens 25 Inhalte pro Seite. Zum Ändern einer Unterseite deren Editor öffnen.
Die Position ist auch der vorhandene `nav_order`; ausdrücklich gespeicherte
Menüeinstellungen haben weiterhin Vorrang.

Nur die Elternzuordnung und Position des gewählten Originals ändern sich.
Kennungen, Adressen, Inhalte, Medien, Verweise und die Revisionen der Unterseiten
bleiben erhalten. Historische Originalrevisionen werden nicht umgeschrieben.
Öffentliche Seiten, Menü und Cachegeneration bleiben durch diese private
Änderung erhalten. Die neue Fassung muss bewusst mit dem vorhandenen
Veröffentlichungsweg veröffentlicht werden; dessen Elternprüfung gilt weiter.

Die aktuelle Version und Website-Bearbeitungsrechte werden in derselben
Vereinstransaktion wie die Seiten-/Modelländerungen geprüft. Ein veraltetes
Formular kann neuere Arbeit nicht überschreiben; nach einem Konflikt bleibt
sein ursprünglicher Versionsstand erhalten. Seite neu laden und Anordnung erneut
bewusst wählen. Fremde oder archivierte Zielseiten, Selbst-/Nachfolgerzuordnung
und mehr als 100 Ebenen im gesamten Teilbaum werden abgewiesen. Archivierte
Seiten zuerst ausdrücklich zurück in die Bearbeitung holen. Eine inzwischen
geänderte Feld-/Vorlagendefinition verlangt Prüfung im Inhaltseditor.

Ein verschobener Teilbaum ist auf 500 Seiten begrenzt; ein größerer Baum wird
vollständig abgewiesen. Eine reine Positionsänderung ohne Elternwechsel bleibt
auch bei mehr Unterseiten möglich. Die Tiefenprüfung liest nur Kennungen und
Elternmetadaten, keine Inhalte aller Nachfolger. Ein fehlgeschlagener oder
gleichzeitiger Vorgang hinterlässt keine teilweise geänderte Hierarchie.

`Cms/PageHierarchy` verwendet die bestehende Seitenanlage und den gemeinsamen
Vereinsmutex. `Web/Website/PageHierarchyPanel` liefert ein gemeinsames
Bootstrap-6-Formular mit nativen Select-/Zahlencontrols und ohne JavaScript-Pflicht.
Eine Datenbankschemaänderung ist nicht erforderlich.

`native-page-hierarchy.php` besteht 46 wirkliche MySQL-, Rechte-, Original-,
Controller-/Renderingprüfungen, Teilbaum-/Tiefengrenzen, Transaktionsrücknahme
und konkurrierende Prozesse. `native-page-hierarchy-browser.mjs` besteht
215 Browserchecks aller vier Inhaltstypen in beiden Redaktionszugängen bei
1440/820/390 Pixeln, mit 28 echten privaten Speicherungen, konkurrierenden Tabs,
Konfliktkorrektur, nativen Formularen ohne JavaScript, Tastatur und Rollen.
Die nachfolgende Fixtureprüfung vergleicht gespeicherte Originalwerte,
Unterseitenrevisionen, veröffentlichte Projektionen, Cachegeneration und genaue
Fotobytes. Alle Aktionen sind lokal und synthetisch. `bootstrap.sh --verify`
bereitet die eigenen Quellen vor und führt Browser- sowie Originalprüfung aus.
Bestehende Such-, Kopier-, Navigations- und Versionsprüfungen bestehen zusätzlich.
Größere wiederaufnehmbare Stapel-/Papierkorbabläufe bleiben im Register offen.
