# Presseempfänger auswählen

In der Presseredaktion öffnet **Presseempfänger** die Auswahl. Pro Seite erscheinen
sechs aktive Kontakte. Suche nach Name, E-Mail oder Redaktion; `%`, `_` und `!`
werden als tatsächliche Zeichen gesucht. **Ausgewählt** zeigt ausschließlich die
bereits gewählten Kontakte. Ein Klick auf die Redaktion öffnet die genaue
E-Mail-Adresse. Jeweils ein Kontakt lässt sich aufklappen.

Die Auswahl bleibt beim Suchen, Blättern und Wechsel zwischen den beiden Listen
erhalten. Einen Kontakt durch Abwählen entfernen. Auch bereits ausgewählte,
inzwischen inaktive Kontakte bleiben in der Auswahl sichtbar und entfernbar.
Andere Vereine erscheinen weder in den Treffern noch in der Auswahl.

Suche und Seitenwechsel speichern keinen Entwurf und bereiten keinen Versand vor.
Titel, Einleitung, Text, Inhaltsbausteine und ursprüngliche Formularversion bleiben
erhalten. Mit JavaScript wird ausschließlich die Empfängerauswahl ersetzt; während
einer langsamen Suche kann der Text weiter bearbeitet werden. Ungespeicherte
Dateiauswahlen werden weder hochgeladen noch ersetzt. Eine unterbrochene Suche
zeigt einen Fehler und kann ausdrücklich erneut ausgelöst werden.

Ohne JavaScript sind Suche und Seitenwechsel ebenfalls möglich. Text lässt sich
im Textfeld bearbeiten. Auch ein noch leerer Titel verhindert die Suche nicht.
Erst **Entwurf speichern** legt die gewählten Empfänger und den Text ab. Eine
zurückgewiesene, veraltete Speicherung erhält den eigenen Text und die tatsächlich
eingereichte Version; sie übernimmt keine neuere Version zum Überschreiben.

Die vorhandene Höchstzahl bleibt 500 Empfänger. Bei der Planung und lokalen
Versandvorbereitung werden ausschließlich die gewählten IDs aktuell geprüft.
Deaktivierte Kontakte werden vor der Vorbereitung unterdrückt. Die bestehende
Freigabe-, Original- und Mailqueuebindung bleibt maßgeblich.

Technisch verwenden beide Manager `Communication/PressRecipientCatalogue` und
`Web/Communication/PressRecipientPicker`. Die Suche erfolgt als CSRF-geschützter,
rein lesender Formularaufruf. Der Suchvorgang speichert weder Formulardaten in
Sitzungen noch Entwurfsrevisionen. Die Aufrufe benötigen aktuelle
Presse-Bearbeitungsrechte. Ausgewählte IDs werden auf den eigenen Verein begrenzt.
SQL-Abfragen liefern sechs Metadatenzeilen; die Eignungsprüfung lädt höchstens die
500 ausgewählten IDs, keine vollständigen Verteiler oder Textoriginale.

`native-press-recipients` prüft echte MySQL-Daten mit 901 aktiven Kontakten,
500 ausgewählten Originalen, literaler Suche, aktueller Rechteentziehung,
gleichzeitiger Kontaktdeaktivierung in einer älteren Datenbanktransaktion und
veralteten Formularversionen. Der zugehörige Browserlauf prüft beide tatsächlichen
Controller bei 320/390/820 Pixeln, 200 % Schriftgröße, Touch und Tastatur, jeweils
mit und ohne JavaScript. Er prüft auch langsame/unterbrochene Suchantworten und
tatsächliche zurückgewiesene Speicherungen. Alle Originalkontakte und vorherigen
Entwürfe/Revisionen bleiben erhalten. Keine Mails, Anbieteraktionen, GitHub Actions
oder Veröffentlichung werden dabei ausgelöst.

Die weiteren Presse-Vorlagen, Anhang-/Kontaktvarianten, Massenaktionen und
vollständigen Original-/Zustellansichten bleiben in beiden Registern offen.
Keine Schemaänderung: CMS 17, Kommunikation 10, Delivery 11.
