# Nicht zugeordnete Videodateien

Unter **Social → Beitrag öffnen → Videofassungen → Videospeicher → Nicht zugeordnete
Videodateien** ausdrücklich **Speicher prüfen** wählen. Die geschlossene Ansicht
prüft keine Dateien. Die ausdrückliche Prüfung öffnet mit und ohne JavaScript
ein eigenes Fenster. Titel, formatierter Beitragstext und dessen ursprüngliche
Speicherkennung bleiben im offenen Editor erhalten. Die Prüfung, das Blättern
und erneute Prüfen verwenden ausschließlich lesende Abrufe. Die Bedienung ist
auf Deutsch und Englisch verfügbar. Dateizeiten zeigen Europe/Berlin bei
unverändertem maschinenlesbarem UTC-Zeitpunkt.

Sechs Dateien je Seite; **Weitere Dateien** und **Erste
Dateien** führen durch die eigenen verbleibenden Originale.

**Datei prüfen** untersucht genau die ausgewählte private MP4-Datei. Ein
bestätigtes Reel zeigt Dauer, Abmessungen und vorhandenen Originalton. Teilweise
gespeicherte oder ungeeignete Dateien bleiben sichtbar, ohne als vollständige
Videos bestätigt zu werden. Wiedergabe, Download und Schreibaktionen werden in
dieser Beobachtungsansicht nicht angeboten. Bei einem Fehler behält
**Ursprüngliche Dateiauswahl erneut prüfen** genau Datei und Seite. Eine frühere
positive Prüfung wird nicht für eine andere oder nicht mehr verfügbare Datei
angezeigt. **Erste Dateien** beginnt bewusst eine neue Auswahl.

Die Inventur liest ausschließlich den eigenen privaten Videospeicher. Bereits
registrierte Quellen, auch bei einem unerwarteten Bezug zu einem anderen Verein,
bleiben ausgeschlossen. Junge Uploads erhalten eine Stunde Schonzeit. Der
bestehende Video-Papierkorb wird nicht durchsucht. Verknüpfte Dateien, unbekannte
Namen, fremde Verzeichnisse und nicht vollständig lesbare Einträge werden nicht
als sichere Dateien ausgegeben; die Ansicht meldet eine unvollständige Prüfung.

Die Beobachtung ist keine Lösch- oder Wiederherstellungsfreigabe. Quoten, Inhalte,
Freigaben, Originalzeilen, verschlüsselte Nachweise und Anbieteraufträge bleiben
unverändert. Bewusste originalerhaltende Wiederherstellung ist inzwischen in beiden
Anwendungen ergänzt; [Bedienung](SOCIAL-VIDEOS-WIEDERHERSTELLEN.md). Sichere
endgültige Behandlung unbekannter Dateien bleibt eigene offene Arbeit. Ein unbekanntes
Upload-Commit wird nicht als gescheitert angenommen.

Technisch liest `VideoStorageInventory` aktuelle Daten ohne ältere
Repeatable-Read-Transaktion und ohne Upload-blockierende Bereichssperren.
64er-ID-Abfragen und höchstens sieben Kandidaten begrenzen die Beobachtung;
maximal 50.000 Verzeichniseinträge je Abruf. Cursor und Dateinamen akzeptieren nur
die festen nativen Quellkennungen. Absolute Speicherpfade und Datei-Capabilities
werden nicht ausgegeben. Die ausgewählte Profilprüfung ist auf 100 MiB und den
bereits verwendeten AVC-/AAC-Reel-Vertrag begrenzt. Hash, Dateistand, aktuelle
Zuordnung und heutige Verwaltungsrechte werden erneut geprüft.

Es gibt keine neue Schemaversion und keine Web-DDL. Der Dienst verwendet die
bestehenden Quell-/Videotabellen; eine Installation ohne Videomodul verlangt
die Vervollständigung über den Installer. Das vollständige Erstinstallationspaket
enthält alle nötigen Tabellen automatisch; es gibt keine gesonderten
Migrationsdownloads.

Die ursprünglichen Fach-, Datei-, Rechte- und Browsernachweise bleiben erhalten.
Aktuelle Prüfungen der erweiterten Bedienung stehen im verbindlichen
Umsetzungsregister. Diese Bedienungsänderung benötigt keine erneute
Installations-, Upgrade- oder Wiederherstellungsrunde.
