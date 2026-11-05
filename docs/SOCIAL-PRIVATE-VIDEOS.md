# Private Videofassungen

Im gespeicherten Social-Beitrag unter **Videofassungen → Fertige MP4 direkt speichern**
eine eigene MP4-Datei wählen. Die private Speicherung legt eine neue genaue
Fassung an. Frühere Quellen, Dateien, Texte, Bilder, Freigaben und
Veröffentlichungen bleiben erhalten. Ein Speichern veröffentlicht kein Video
und ersetzt keine frühere Bildfreigabe.

Das gemeinsame Reelprofil verwendet 1080 × 1920 Pixel, H.264/AVC, 30 Bilder/s
und 3–90 Sekunden. Dateien dürfen bis zu 100 MiB groß sein. Vorhandener Ton
bleibt als AAC mit 48 kHz und zwei Kanälen erhalten; Dateien ohne Ton bleiben
ohne erfundenen oder automatisch hinzugefügten Ton. Das lokale Reelstudio
verarbeitet weiterhin auf dem eigenen Gerät und speichert die Ausgabe nun
auch direkt als neue private Fassung. [Direkter Speichervorgang](SOCIAL-REELSTUDIO-SPEICHERN.md).
Andere unterstützte Ausgangsformate dort in das gemeinsame MP4-Profil bringen.

**Gespeicherte Videos** öffnet den vollständigen Verlauf mit höchstens sechs
kleinen Dateikarten pro Seite. **Video ansehen** öffnet einen einzelnen Player;
**Download** liefert die genaue gespeicherte Datei. Videos werden nicht
automatisch abgespielt oder beim Öffnen des Verlaufs komplett geladen.
Dateien und Verlauf benötigen aktuelle eigene Social-Leserechte, der Upload
zusätzlich aktuelle Bearbeitungsrechte. Die gleichen privaten Wege gelten in
der nativen Redaktion und dem integrierten Produktionscontroller.

Der Server liest Container, H.264-Sequenzparameter, tatsächliche Bildgrenzen,
Zeiten, Bildanzahl, AAC-Konfiguration und Mediendatenbereiche aus der Datei.
Unvollständige Boxen, unbekannte Codecs, unzulässige Bildgrenzen, verschlüsselte
oder fragmentierte Formate, falsche Tonparameter, außerhalb liegende Daten und
überlappende Medienbereiche werden abgewiesen. Die Prüfung benötigt weder
FFmpeg noch Shellfunktionen auf dem PHP-Webspace. Die kleinen synthetischen
Prüfdateien wurden unabhängig mit FFmpeg erzeugt und mit ffprobe kontrolliert;
beides gehört nicht zu den Produktionsvoraussetzungen dieses Moduls.

Kopieren erfolgt begrenzt in eine ausschließlich neue private Datei. Inhalts-
und Vereinsdatenversion sowie aktuelle Person, Verein und Rechte werden vor
dem Datenbankabschluss erneut geprüft. Ein bestätigter Rollback entfernt nur die neue eigene Datei. Bei einem
unbestätigten Abschluss bleiben die neuen Bytes zur Prüfung erhalten;
frühere Quellen werden nicht ersetzt. Quellen und Fassungen haben
unveränderliche Kennungen, SHA-256-Beweise und relationale Bindungen. Direkte
Abrufe prüfen die ursprünglichen Bytes und unterstützen GET/HEAD und einzelne
Bytebereiche für die Videowiedergabe. Ungültige Bereiche liefern eine leere
416-Antwort; private Antworten verwenden `no-store` und `nosniff`.

MP4-Datei, Quellenzuordnung und persönlicher Speichernachweis werden in einer
Transaktion angenommen. **Gespeichertes Video prüfen** liest den ursprünglichen
Vorgang. Seine unveränderte Wiederholung legt auch nach einer verlorenen
Antwort keine zweite Fassung an. Dateiname, Datei und ungespeicherte
Beitragstexte bleiben bei Fehlern erhalten. Ohne JavaScript erfolgen Speicherung
und Fehlerkorrektur in einem eigenen Fenster. Einzelheiten stehen unter
[Reelstudio und Speicherwiederaufnahme](SOCIAL-REELSTUDIO-SPEICHERN.md).

Die vollständige Erstinstallation richtet den Videospeicher und die bestehenden
Speichernachweise automatisch ein. Lesende Aufrufe führen keine Schemaänderung
aus. Es werden keine früheren Medien übernommen. Aktuell bleiben das
Gesamtschema 135, Kommunikation 18 und CMS 18 unverändert.

Die isolierten Fach- und Browserprüfungen umfassen echte AVC-/AAC-Dateien,
Originalton, Datei-/Containerfehler, Versionen und Rechte, tatsächliche
Datenbankabbrüche, gleichzeitige PHP-Schreiber, Prozessabbruch nach COMMIT,
HTTP-Wiederholungen, private Dateirechte und vollständige frühere
Datenbankzeilen. Beide Zugänge werden in Deutsch/Englisch mit und ohne
JavaScript sowie bei Telefon-/Tabletbreiten und vergrößerter Schrift geprüft.
Frühere Originalbytes, Freigaben und private Schlüssel bleiben erhalten.

Die genaue Videofreigabe ist inzwischen separat umgesetzt; siehe
[SOCIAL-VIDEO-FREIGABE.md](SOCIAL-VIDEO-FREIGABE.md). Weitere Video-Verwaltungsoberflächen und die abschließende
Geräte-/Anbieterabnahme bleiben eigene Registerpunkte. Private Speicherung
ist kein abgeschlossener Social-/Video-Gesamtumfang.
