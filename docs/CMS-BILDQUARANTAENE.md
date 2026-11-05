# Private Bildquarantäne und Dateiwiederaufnahme

`Cms/MediaQuarantineStore` erhält den vollständigen privaten Bildordner in einem
eigenen unveränderlichen Dateivorgang. Er berücksichtigt die bestehende
Vereinsablage, alle vier gespeicherten Varianten und zusätzliche Originaldateien
wie Sidecars. Die genau gesperrte Datenbankzeile, Dateigrößen und SHA-256-Hashes
bleiben im privaten Vorgangsplan erhalten. Dieser interne Baustein ersetzt keine
Rechte- oder Verwendungsprüfung; der Datenbankdienst muss ihn mit dem
[geschützten Bildvorgang](CMS-BILDLOESCHVORGANG.md) verbinden.

Die Ablage liegt unter `.removed` im privaten Medienspeicher, außerhalb des
Webroots. Neue Verzeichnisse haben Modus 0700, Vorgangsdateien 0600. Ein exklusiv
angelegter Plan wird vollständig geschrieben und synchronisiert; ein atomarer
Hardlink bestätigt ihn, ohne einen früheren Plan überschreiben zu können.
Ein vollständig vorbereiteter Plan kann nach einem Prozessabbruch erneut
bestätigt werden. Unvollständige Pläne bleiben für eine gezielte Wiederaufnahme
erhalten und werden nicht stillschweigend ersetzt.

Verschieben und Zurückholen verwenden denselben vollständigen Ordner und
prüfen alle Originalbytes. Wiederholungen prüfen den erreichten Zustand.
Ein belegtes Ziel, unterschiedliche Originalmetadaten, veränderte Bytes,
Verknüpfungen oder unzulässige Pfade blockieren den Vorgang; bestehende Dateien
bleiben erhalten. Ein erfolgreicher Plan bleibt auch nach dem Zurückholen
unverändert. Journal- und Dateianzahl sind begrenzt, bevor Dateien bewegt werden.

87 Fach-/Dateisystemverträge prüfen fünf neue eigene Testbilder, einen echten
unabhängigen PHP-Abbruch unmittelbar nach dem Verschieben vor Datenbankabschluss,
die anschließende bytegleiche Wiederherstellung, Zielkollisionen, beschädigte
eigene Testbytes sowie vollständige und unvollständige vorbereitete Pläne.
Alle vorherigen vollständigen Datenbankzeilen, Tabellenzahlen und außenliegenden
Originalbilder bleiben unverändert. Acht betroffene native Suiten bestehen.
125 native Suiten sind registriert; CMS bleibt 14 ohne neue DDL.

Dies ist die interne Dateigrundlage. Das tatsächliche Entfernen aus der
Mediathek, die Verbindung von Datenbank- und Dateiwiederaufnahme, eine begrenzte
Wiederherstellungsliste, Aufbewahrungsregeln und endgültiges Löschen folgen.
Es gibt noch keinen neuen Löschknopf und keine öffentliche Quarantäne-URL.
