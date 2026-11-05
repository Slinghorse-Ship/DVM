# Persönliche Verwaltungsansichten

Unter **Mein Konto → Verwaltungsansicht** stehen drei mitgelieferte Ansichten
zur Auswahl: **Studio** mit seitlicher Navigation, **Fokus** mit Navigation
oberhalb des Inhalts auf großen Bildschirmen und **Kompakt** mit schmalerer
Navigation und mehr Platz für Listen. Die mobile Navigation und große
Bedienflächen bleiben bei allen Ansichten erhalten. Farbmodus und Abstände
sind getrennt wählbar. Website-Designs und menschliche Inhalte behalten ihre
eigene Gestaltung.

Die Auswahl wird bewusst gespeichert. Ein aktueller Versionskonflikt behält
die eigene Auswahl und die ursprüngliche Formularversion; **Aktuellen Stand
öffnen** lädt die inzwischen gespeicherte Ansicht ausdrücklich. Der Server
wiederholt fehlgeschlagene Änderungen nicht automatisch. Das Formular
funktioniert auch ohne JavaScript.

## Eine persönliche Einstellungsquelle

Ein an die Vereinsverwaltung gebundenes Konto verwendet ausschließlich die
bestehende versionierte `account:<actor>`-Einstellung mit deren ursprünglichem
Verlauf und Änderungsnachweis. Derselbe Zugang liest diese Einstellung in beiden
Webversionen. Ein Ansichtswechsel ändert nur `admin_view`, `admin_color` und
`admin_density`. Anzeigename, Antwortadresse, Startansicht, Listengröße und
weitere gespeicherte Profilangaben werden nicht neu normalisiert. Ältere
Profilformulare erhalten bereits gespeicherte Ansichtsangaben.

Ein tatsächlich ungebundenes natives Konto verwendet seine eigene
Versionspräferenz. Sobald eine bestätigte Vereinsbindung besteht, gilt die
bestehende Kontoeinstellung; ein altes ungebundenes Formular kann diese nicht
unbemerkt überschreiben. Fehlende Angaben erhalten beim Lesen feste
Standardwerte. Der Leseweg erzeugt keine neuen Datenbankzeilen.

Die additive Wartung `appearance` Version 1 ist für eigene ungebundene native
Präferenzen vorgesehen. Webaufrufe führen keine Schemaänderung aus. Zulässig
sind feste Ansicht-, Farb- und Abstandswerte; ausführbares CSS, fremde
Dateipfade oder andere Zielkonten lassen sich nicht als Auswahl einschleusen.

## Gemeinsame Bedienung und Bildverarbeitung

Die vorhandenen lokalen Bootstrap-Steuerelemente, Dialoge und
Formularhilfen bleiben maßgeblich. Formularfehler gehören zum betroffenen Feld,
erhalten eine zugängliche Beschreibung und öffnen einen geschlossenen
Einstellungsbereich für die Korrektur. Serverseitige Versionen, CSRF und
Berechtigungen bleiben auch bei Navigation ohne vollständiges Neuladen
verbindlich. Bildschirmtastatur, Tastaturfokus und reduzierte Bewegung müssen
in der tatsächlichen Zwei-App-Abnahme geprüft werden.

Die Bildfunktionen verwenden die nativen GD-/Fileinfo-Dienste, lokale
HEIF-/RAW-Decoder sowie Canvas im Browser. Vorhanden sind Größenvarianten,
Alpha, acht JPEG-Ausrichtungen, Ausschnitt, Drehen/Spiegeln, Filter, Tonwerte,
Markierungen, Text, Ebenen, Kopierstempel, Retusche, Rückgängig/Wiederherstellen
und Originalvergleich. Bewusst gespeicherte Bearbeitungen erhalten neue
Fassungen; ursprüngliche Bilder werden dabei nicht überschrieben. Dafür wird
keine alte Bild- oder JavaScript-Bibliothek kopiert.

## Abnahmestand

Die kuratierte Zwei-App-Abnahme ist abgeschlossen. Der frische
Fachlauf besteht 49 Ansichts-, 187 Kontobereichs- und 217 Sitzungsbedingungen
sowie neun tatsächliche geschützte Menü-HTTP-Anfragen. Geprüft sind unter
anderem zwei unabhängige parallele Erstspeicherungen mit genau einem
angenommenen Versionsstand, vollständige Rücknahme bei spätem Verlaufsfehler,
unveränderte fremde Profile und 424 ursprüngliche Geschäftstabellen. Ein alter
nativer Zugang darf nach einem Vereinswechsel des ursprünglichen Kontos
weder das neue persönliche Profil noch eine alte Kontozuordnung verwenden.

Die endungslose DNG-Erkennung und ihre tatsächliche lokale Umwandlung sind
mit 35 Bedingungen geprüft; 121 Kameraausrichtungs- und 27
originalgebundene Bildbearbeitungsbedingungen bestehen ebenfalls. Die
Erkennung liest höchstens 4.096 Dateibytes und erkennt einen passenden
DNG-Versionsmarker in einem begrenzten TIFF-Verzeichnis. Eine echte
128-MiB-Datei lässt sich im gesonderten PHP-Prozess mit 12 MiB Speichergrenze
prüfen; ursprüngliche Dateien und vorherige Bildfassungen bleiben bytegleich.

Die tatsächliche Browserprüfung besteht 3.328 Bedingungen in 96 Ansichten
beider Anwendungen mit und ohne JavaScript. Alle drei Layouts, drei Farbmodi
und zwei Abstandswerte sind mit 320/390/768/1440 Pixeln, 16/32-Pixel-Schrift,
Tastatur, reduzierter Bewegung, echter Formularspeicherung, veraltetem Stand,
CSRF-Fehler und frischen Wiederherstellungs-/Verwerfen-GETs geprüft.
Auch die geschützten API- und Systemseiten lesen die aktuelle eigene Auswahl.
429 ursprüngliche Tabellen, drei Schlüssel, vier Mediendateien und die
vorhandenen Kontoverläufe bleiben exakt; externe Anfragen und JavaScriptfehler: null.

P-24 ist damit als Softwarevertrag implementiert. Die getrennte Übersetzung
sämtlicher Fachoberflächen sowie physischer Geräte- und externer Anbieterbetrieb
sind mit dieser Abnahme nicht nachgewiesen.
