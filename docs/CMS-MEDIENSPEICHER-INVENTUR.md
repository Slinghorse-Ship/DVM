# Lesende Inventur nicht zugeordneter privater Medien

`Cms/MediaStorageInventory` beobachtet ausschließlich den aktuell berechtigten
eigenen privaten Vereinsspeicher. Eine Beobachtung liefert höchstens sechs
kleine Einträge mit Dateizahl, Umfang, Änderungszeit und bis zu sechs ursprünglichen
Dateinamen. Fortsetzung erfolgt über die letzte ursprüngliche Kennung.

Ordner und Inhalte, die innerhalb der letzten Stunde geändert wurden, bleiben
außen vor. Normale Uploads werden weder gebremst noch übernommen. Kleine
aktuelle Autocommit-Kennungsabfragen verwenden keine Upload blockierenden
Bereichssperren und laden keine kompletten Bilddaten oder privaten Nachweise.
Bereits global reservierte Kennungen gelten auch bei fremdem Verein nicht als
eigene verwaiste Datei.

Unbekannte Einträge, Verknüpfungen, unlesbare Ordner und überschrittene Grenzen
werden erhalten. Verknüpfungsziele werden nicht gelesen. Maximal 50.000
Speichereinträge und 1000 Knoten je ausgewähltem Ordner; zusätzliche oder
unvollständig prüfbare Bereiche machen die Beobachtung ausdrücklich
unvollständig. Aktuelle Rechte, Aktivität und Verein werden vor Ausgabe erneut
geprüft; eine alte übergeordnete Datenbanksicht wird nicht übernommen.

Die Inventur erzeugt keine Tabellen, Ordner, Dateien, Marker, Mediendatensätze,
Versionen oder Quotenfreigaben. Nicht zugeordnet bedeutet ausschließlich, dass
die aktuelle Mediathek keine entsprechende Kennung besitzt. Es ist keine
Aussage über globale Nichtverwendung und keine Löschfreigabe. Es gibt keine
automatische Übernahme aus anderen Beständen.

80 Fach-/MySQL-/Dateiverträge bestätigen einen tatsächlichen abgebrochenen
nativen Upload mit vier erhaltenen ursprünglichen Varianten, vollständige
begrenzte Fortsetzung und aktuelle Rollen-/Vereinswechsel. Der echte 16-MiB-
Prozess benötigt 2 MiB trotz 516 Bildern und über 24 MiB unveränderlicher
ursprünglicher Metadaten. Alle vorherigen vollständigen Zeilen, Primärkennungen,
Gesamtzahlen, Veröffentlichungen, Bilddateien, verwaisten Originale, privaten
Nachweise, Schlüssel, Pläne und Verknüpfungen bleiben unverändert.

23 betroffene native Suiten bestehen. CMS bleibt 16 ohne neue DDL; 131 Suiten
sind registriert. Die mobile Anbindung folgt im nächsten Zwischenstand;
bewusste Behandlung nicht zugeordneter Dateien, weitere Medienquoten, Video
und übrige Register bleiben offen.

Die [mobile Bedienung](CMS-MEDIENSPEICHER-BEDIENUNG.md) ist jetzt angebunden.
`has_thumbnail` signalisiert ausschließlich ein lesbares kleines ursprüngliches
`thumb.png`, `thumb.jpg` oder `thumb.webp` mit passendem MIME-Typ, maximal 1 MiB
und 512 × 512 Pixeln. Die lesende Headerprüfung rastert keine Originalbilder.
Der gesonderte private Vorschaudienst prüft Ordner, Alter, globale Kennung und
aktuelle Rechte erneut; er stellt ausschließlich diese kleine Vorschau bereit.
