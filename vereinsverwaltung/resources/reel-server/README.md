# Lokaler Videoencoder für Entwürfe

Der separate FFmpeg-Prozess erzeugt aus einer lokal gestalteten Karte ein echtes
H.264-MP4 mit 1080 × 1920 Pixeln, 30 Bildern/s und sechs Sekunden Länge.
Die festen Vorlagen sind Standbild, sanfter Zoom, Schwenk und Einblenden.
Zoom und Schwenk halten die letzten zwei Sekunden ruhig. Die Bildfläche bleibt
innerhalb einer gepolsterten Karte; Bildnachweise und QR-Ränder bleiben erhalten.

Die Veranstaltungsautomatik verwendet die gemeinsame Medienvorgabe Bild,
animiertes Video oder beides. Ihre Animationsvorlagen sind Zoom, Schwenk und
Einblenden. Sie speichert bearbeitbare Entwürfe und deren Originaldateien.
Die Videovorlage eines gespeicherten Reel-Entwurfs kann erneut gewählt werden.

`linux-amd64/ffmpeg` ist statisch für Linux x86-64 gebaut. Netzwerkunterstützung
ist beim Kompilieren abgeschaltet. Nur lokale Dateien und Pipes sind aktiviert;
PHP startet die feste Argumentliste ohne Shell. Benutzer wählen eine benannte
Vorlage. Codec-Ausdrücke, Befehle und Dateipfade sind keine Formularfelder.
Ein Prozess nutzt einen Encoder-Thread und endet spätestens nach 120 Sekunden.
Das Original wird erst nach vollständiger MP4-Prüfung gespeichert. Dateigröße,
Speicherquote, Inhaltsfassung, Vereinsdaten und Bildrechte werden dabei geprüft.

## Quellcode und Lizenzen

FFmpeg 8.0 mit libx264 wird als separates Programm unter GPL-2.0-or-later
mitgeliefert. Die Lizenztexte liegen in diesem Verzeichnis. Der vollständige
FFmpeg-Quellcode liegt unter `../reel-aac/ffmpeg-8.0-source.tar.xz`; der zugehörige
x264-Quellcode liegt in `x264-source.tar.xz`. `provenance.json` enthält Commit,
Prüfsummen, Architektur und Buildoptionen. zlib und die C-Systembibliothek werden
statisch verwendet. Ihre vollständigen Debian-Quellen einschließlich Paketpatches
und Quelldeskriptoren liegen unter `library-sources/`, die Lizenzhinweise unter
`LICENSE-zlib` und `LICENSE-glibc`. Die glibc steht unter LGPL-2.1-or-later;
die vollständigen Programmquellen erlauben den Neubau mit einer geänderten
C-Bibliothek. `dpkg-source -x library-sources/glibc_2.41-12+deb13u4.dsc` bzw.
`dpkg-source -x library-sources/zlib_1.3.dfsg+really1.3.1-1.dsc` entpackt die
jeweiligen Originale samt Debian-Bauanweisungen. Der Browser-AAC-Encoder hat seinen eigenen Build und seine
eigenen Lizenznachweise unter `../reel-aac/`.

Zum Neubau werden Linux x86-64, Python 3.12, GCC, make, pkg-config sowie statische
C- und zlib-Entwicklungsbibliotheken benötigt. `python3 build.py` baut aus den
beigefügten Quellen ohne Netzwerkzugriff. Das Ergebnis liegt im angezeigten
privaten Buildordner. Die neue Binärdatei und ihre Provenienz müssen gemeinsam
übernommen und mit den tatsächlichen Videoverträgen geprüft werden.

Der vorliegende Build verwendet Debian GCC 14.2.0-19, glibc
2.41-12+deb13u4 und zlib 1:1.3.dfsg+really1.3.1-1+b1. Zum Austausch einer
Bibliothek zuerst deren statische Entwicklungsdateien bauen und diese über
Compiler-/Linkerpfade für `build.py` bereitstellen. Die Lizenz erlaubt die
Änderung und den Neubau des separaten Encoders.
